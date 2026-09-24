<?php

/**
 * Minimal PayArc REST API v2 client.
 */
class CRM_Payarcjs_GatewayClient {

  public const SOFTWARE = 'CiviCRM PayArc Pay.js/0.1';

  /**
   * Amount authorized (then voided) to vault a replacement card.
   */
  public const CARD_VERIFICATION_AMOUNT = '1.00';

  private string $apiKey;

  private string $apiPin;

  private string $baseUrl;

  /**
   * Optional test transport: fn(string $method, string $url, array $headers, ?string $body): array.
   */
  private $transport;

  public function __construct(string $apiKey, string $apiPin, string $baseUrl, ?callable $transport = NULL) {
    $this->apiKey = trim($apiKey);
    $this->apiPin = $apiPin;
    $this->baseUrl = rtrim($baseUrl, '/');
    $this->transport = $transport;

    if ($this->apiKey === '' || $this->apiPin === '') {
      throw new InvalidArgumentException('PayArc API key and PIN are required.');
    }
    if (!str_starts_with($this->baseUrl, 'https://')) {
      throw new InvalidArgumentException('PayArc API URL must use HTTPS.');
    }
  }

  public function saleWithPaymentKey(string $paymentKey, string $amount, array $metadata = [], bool $saveCard = FALSE): array {
    $payload = $this->salePayload($amount, $metadata);
    $payload['payment_key'] = $this->validateToken($paymentKey);
    if ($saveCard) {
      $payload['save_card'] = TRUE;
    }
    return $this->request('POST', '/transactions', $payload);
  }

  public function saleWithCardReference(string $cardReference, string $amount, array $metadata = []): array {
    $payload = $this->salePayload($amount, $metadata);
    $payload['creditcard'] = [
      'number' => $this->validateToken($cardReference),
      'expiration' => '0000',
    ];
    return $this->request('POST', '/transactions', $payload);
  }

  /**
   * Vault a card from a Pay.js payment key without keeping a charge.
   *
   * PayArc's cc:save command rejects payment keys ("Invalid Card Number"),
   * and the sandbox rejects $0.00 authorizations, so the card is verified with
   * a small authorization that requests save_card and is voided immediately.
   * The returned array is the authorization response (with savedcard); if the
   * void failed, 'void_error' carries the reason and the hold expires on its
   * own per the merchant's authorization-expiry setting.
   */
  public function verifyAndSaveCardWithPaymentKey(string $paymentKey, array $metadata = []): array {
    $payload = [
      'command' => 'cc:authonly',
      'amount' => self::CARD_VERIFICATION_AMOUNT,
      'payment_key' => $this->validateToken($paymentKey),
      'save_card' => TRUE,
      'description' => 'Card verification',
      'software' => self::SOFTWARE,
    ];
    foreach (['clientip', 'custid'] as $key) {
      if (isset($metadata[$key]) && $metadata[$key] !== '') {
        $payload[$key] = $metadata[$key];
      }
    }
    $payload += $this->billingAddressPayload($metadata);
    $response = $this->request('POST', '/transactions', $payload);

    $refnum = trim((string) ($response['refnum'] ?? ''));
    if (($response['result_code'] ?? '') === 'A' && $refnum !== '') {
      try {
        $void = $this->void($refnum);
        if (($void['result_code'] ?? '') !== 'A') {
          $response['void_error'] = $this->errorMessage($void, 'PayArc did not void the verification authorization.');
        }
      }
      catch (CRM_Payarcjs_GatewayException $e) {
        $response['void_error'] = $e->getMessage();
      }
    }
    return $response;
  }

  /**
   * Void an unsettled transaction.
   *
   * @param string $reference
   *   PayArc refnum (digits) or transaction key.
   */
  public function void(string $reference): array {
    $payload = ['command' => 'void'] + $this->transactionReference($reference);
    return $this->request('POST', '/transactions', $payload);
  }

  /**
   * Refund a settled transaction in full or in part.
   *
   * PayArc refuses to void a settled transaction ("Issue refund instead") and
   * refuses quickrefund on an unsettled one, so callers should void unsettled
   * transactions and refund settled ones; see CRM_Core_Payment_Payarcjs::doRefund().
   *
   * @param string $reference
   *   PayArc refnum (digits) or transaction key.
   */
  public function refund(string $reference, ?string $amount = NULL): array {
    $payload = ['command' => 'refund'] + $this->transactionReference($reference);
    if ($amount !== NULL) {
      $payload['amount'] = $this->normalizeAmount($amount);
    }
    return $this->request('POST', '/transactions', $payload);
  }

  /**
   * Prove the API key and PIN work without moving money.
   *
   * Lists the most recent transaction, which PayArc answers with HTTP 401
   * ("Specified source key not found." or "API authentication failed") when
   * the key or PIN is wrong. Returns the decoded list response.
   */
  public function verifyCredentials(): array {
    return $this->request('GET', '/transactions?limit=1');
  }

  /**
   * Prove a Pay.js public key belongs to this account without moving money.
   *
   * Mints a single-use payment key for a Luhn-valid placeholder number, the
   * same call the Pay.js iframe makes. Nothing is charged and the key expires
   * unused. A wrong public key raises "Specified source key not found.".
   */
  public function verifyPublicKey(string $publicKey): array {
    $publicKey = trim($publicKey);
    if ($publicKey === '') {
      throw new InvalidArgumentException('A Pay.js public key is required.');
    }
    $url = $this->baseUrl . '/pub/payment_keys';
    $headers = [
      'Accept: application/json',
      'Content-Type: application/json',
      'Authorization: Basic ' . base64_encode($publicKey . ':s2//'),
      'User-Agent: CiviCRM-PayArc-PayJS/0.1',
    ];
    $body = json_encode(['creditcard' => ['number' => '4111111111111111', 'expiration' => '1229', 'cvc' => '123']], JSON_THROW_ON_ERROR);
    $result = $this->transport ? ($this->transport)('POST', $url, $headers, $body) : $this->curlRequest('POST', $url, $headers, $body);
    $status = (int) ($result['status'] ?? 0);
    $data = json_decode((string) ($result['body'] ?? ''), TRUE);
    $data = is_array($data) ? $data : [];
    if ($status < 200 || $status >= 300) {
      throw new CRM_Payarcjs_GatewayException($this->errorMessage($data, 'PayArc returned HTTP ' . $status . '.'), $status, $data);
    }
    return $data;
  }

  /**
   * Find a recent transaction by the orderid we sent with it.
   *
   * The transactions list endpoint ignores filter parameters (verified against
   * the sandbox), so this pages through the newest transactions and matches
   * orderid locally. Used to reconcile a charge whose response never arrived.
   *
   * @return array|null
   *   The transaction row (with 'key', 'result_code', 'trantype_code',
   *   'status_code', 'amount', 'creditcard'), or NULL when none of the newest
   *   $maxPages * 100 transactions carry that orderid.
   */
  public function findTransactionByOrderId(string $orderId, int $maxPages = 3): ?array {
    $orderId = trim($orderId);
    if ($orderId === '') {
      throw new InvalidArgumentException('An orderid is required.');
    }
    $pageSize = 100;
    for ($page = 0; $page < max(1, $maxPages); $page++) {
      $rows = $this->listTransactions($pageSize, $page * $pageSize);
      foreach ($rows as $row) {
        if (is_array($row) && (string) ($row['orderid'] ?? '') === $orderId) {
          return $row;
        }
      }
      if (count($rows) < $pageSize) {
        break;
      }
    }
    return NULL;
  }

  /**
   * One page of the account's transactions, newest first. The endpoint
   * accepts only limit/offset (no filters or date ranges).
   *
   * @return array[]
   */
  public function listTransactions(int $limit = 100, int $offset = 0): array {
    $limit = max(1, min(100, $limit));
    $list = $this->request('GET', '/transactions?limit=' . $limit . '&offset=' . max(0, $offset));
    $rows = is_array($list['data'] ?? NULL) ? $list['data'] : [];
    return array_values(array_filter($rows, 'is_array'));
  }

  /**
   * A PayArc customer record (created by other systems, e.g. a store); used
   * by the importer to find an email address when the sale carries none.
   */
  public function getCustomer(string $customerKey): array {
    $customerKey = trim($customerKey);
    if ($customerKey === '') {
      throw new InvalidArgumentException('A PayArc customer key is required.');
    }
    return $this->request('GET', '/customers/' . rawurlencode($customerKey));
  }

  public function getTransaction(string $transactionKey): array {
    $transactionKey = trim($transactionKey);
    if ($transactionKey === '') {
      throw new InvalidArgumentException('A PayArc transaction key is required.');
    }
    return $this->request('GET', '/transactions/' . rawurlencode($transactionKey));
  }

  private function salePayload(string $amount, array $metadata): array {
    $payload = [
      'command' => 'cc:sale',
      'amount' => $this->normalizeAmount($amount),
      'software' => self::SOFTWARE,
    ];

    // A top-level 'email' makes PayArc send its own customer receipt, and no
    // request-level flag suppresses it. CiviCRM sends the receipts, so the
    // donor's address travels only inside billing_address, which does not
    // trigger one.
    // 'custid' carries the CiviCRM contact ID so the PayArc console's
    // transaction search can be filtered by donor; no customer record is
    // created there, CiviCRM stays the record of donors and schedules.
    foreach (['invoice', 'orderid', 'description', 'clientip', 'currency', 'custid'] as $key) {
      if (isset($metadata[$key]) && $metadata[$key] !== '') {
        $payload[$key] = $metadata[$key];
      }
    }
    $payload += $this->billingAddressPayload($metadata);

    return $payload;
  }

  private function billingAddressPayload(array $metadata): array {
    if (empty($metadata['billing_address']) || !is_array($metadata['billing_address'])) {
      return [];
    }
    $address = array_filter(
      $metadata['billing_address'],
      static fn($value) => $value !== NULL && $value !== ''
    );
    return $address ? ['billing_address' => $address] : [];
  }

  /**
   * @return array{refnum: string}|array{trankey: string}
   */
  private function transactionReference(string $reference): array {
    $reference = trim($reference);
    if ($reference === '') {
      throw new InvalidArgumentException('A PayArc transaction reference is required.');
    }
    return ctype_digit($reference)
      ? ['refnum' => $reference]
      : ['trankey' => $this->validateToken($reference)];
  }

  private function normalizeAmount(string $amount): string {
    if (!is_numeric($amount) || (float) $amount < 0) {
      throw new InvalidArgumentException('Payment amount must be a non-negative number.');
    }
    return number_format((float) $amount, 2, '.', '');
  }

  private function validateToken(string $token): string {
    $token = trim($token);
    if ($token === '' || strlen($token) > 255 || preg_match('/[\x00-\x20]/', $token)) {
      throw new InvalidArgumentException('Invalid PayArc payment token.');
    }
    return $token;
  }

  private function request(string $method, string $path, ?array $payload = NULL): array {
    $url = $this->baseUrl . '/' . ltrim($path, '/');
    $body = $payload === NULL ? NULL : json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = [
      'Accept: application/json',
      'Content-Type: application/json',
      'Authorization: ' . $this->authorizationHeader(),
      'User-Agent: CiviCRM-PayArc-PayJS/0.1',
    ];

    if ($this->transport) {
      $result = ($this->transport)($method, $url, $headers, $body);
    }
    else {
      $result = $this->curlRequest($method, $url, $headers, $body);
    }

    $status = (int) ($result['status'] ?? 0);
    $responseBody = (string) ($result['body'] ?? '');
    $decoded = json_decode($responseBody, TRUE);
    $data = is_array($decoded) ? $decoded : [];

    if ($status >= 500 || $status === 0) {
      throw new CRM_Payarcjs_AmbiguousGatewayException(
        'PayArc did not return a conclusive response. Reconcile the transaction before retrying.',
        $status,
        $data
      );
    }
    if ($status < 200 || $status >= 300) {
      $message = $this->errorMessage($data, 'PayArc returned HTTP ' . $status . '.');
      throw new CRM_Payarcjs_GatewayException($message, $status, $data);
    }
    if (!is_array($decoded)) {
      throw new CRM_Payarcjs_AmbiguousGatewayException('PayArc returned an unreadable response.', $status);
    }

    return $data;
  }

  private function errorMessage(array $data, string $fallback): string {
    foreach (['error', 'message', 'result'] as $field) {
      if (is_string($data[$field] ?? NULL) && trim($data[$field]) !== '') {
        return trim($data[$field]);
      }
      if (is_array($data[$field] ?? NULL)) {
        foreach (['message', 'description', 'error'] as $nestedField) {
          if (is_string($data[$field][$nestedField] ?? NULL) && trim($data[$field][$nestedField]) !== '') {
            return trim($data[$field][$nestedField]);
          }
        }
      }
    }
    return $fallback;
  }

  private function authorizationHeader(): string {
    $seed = bin2hex(random_bytes(16));
    $hash = hash('sha256', $this->apiKey . $seed . $this->apiPin);
    $apiHash = 's2/' . $seed . '/' . $hash;
    return 'Basic ' . base64_encode($this->apiKey . ':' . $apiHash);
  }

  private function curlRequest(string $method, string $url, array $headers, ?string $body): array {
    if (!function_exists('curl_init')) {
      throw new CRM_Payarcjs_GatewayException('PHP cURL is required for PayArc payments.');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 35,
      CURLOPT_SSL_VERIFYPEER => TRUE,
      CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== NULL) {
      curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($curl);
    if ($responseBody === FALSE) {
      $message = curl_error($curl);
      throw new CRM_Payarcjs_AmbiguousGatewayException(
        'The connection to PayArc failed: ' . $message
      );
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    return ['status' => $status, 'body' => $responseBody];
  }

}
