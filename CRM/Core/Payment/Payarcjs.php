<?php

use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;
use CRM_Payarcjs_ExtensionUtil as E;

/**
 * CiviCRM payment processor for PayArc Pay.js.
 */
class CRM_Core_Payment_Payarcjs extends CRM_Core_Payment {

  use CRM_Core_Payment_MJWTrait;

  private const PAYJS_TOKEN_PREFIX = 'payjs:';

  /**
   * PayArc status codes for a card transaction that has not settled yet and
   * can therefore only be voided, not refunded.
   */
  private const UNSETTLED_STATUS_CODES = ['N', 'P'];

  public function __construct($mode, $paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
  }

  public function checkConfig() {
    $errors = [];
    if (trim((string) ($this->_paymentProcessor['user_name'] ?? '')) === '') {
      $errors[] = E::ts('The PayArc API key is required.');
    }
    if (trim((string) ($this->_paymentProcessor['password'] ?? '')) === '') {
      $errors[] = E::ts('The PayArc API PIN is required.');
    }
    if (trim((string) ($this->_paymentProcessor['signature'] ?? '')) === '') {
      $errors[] = E::ts('The Pay.js public key is required.');
    }
    return $errors ? implode('<br>', $errors) : NULL;
  }

  public function getPaymentTypeName() {
    return 'credit_card';
  }

  public function getPaymentTypeLabel() {
    return E::ts('Credit card');
  }

  /**
   * No QuickForm payment fields: the card is entered in the Pay.js iframe and
   * the browser script posts the resulting key as a plain 'payment_token'
   * request parameter. Declaring it as a hidden QuickForm field makes core's
   * BillingBlock.tpl emit notices, because hidden elements are not exposed to
   * the template as $form.payment_token.
   */
  public function getPaymentFormFields(): array {
    return [];
  }

  public function getPaymentFormFieldsMetadata(): array {
    return [];
  }

  /**
   * With a confirmation page the token is posted on the main page and the
   * charge happens one request later, so core's pre-approval mechanism
   * carries it across: doPreApproval() stores it, getPreApprovalDetails()
   * merges it back into the payment params.
   */
  protected function supportsPreApproval() {
    return TRUE;
  }

  public function doPreApproval(&$params) {
    $token = $this->paymentTokenFromRequest($params);
    if ($token === '') {
      throw new PaymentProcessorException(E::ts('No PayArc payment token was supplied. Please re-enter the card.'));
    }
    return ['pre_approval_parameters' => ['payment_token' => $token]];
  }

  public function getPreApprovalDetails($storedDetails) {
    return is_array($storedDetails) ? $storedDetails : [];
  }

  /**
   * The Pay.js key from the submitted params, the pre-approval parameters,
   * or the raw request (back-office and no-confirmation-page forms submit it
   * in the same request as the charge).
   */
  private function paymentTokenFromRequest(array $params): string {
    $token = trim((string) ($params['payment_token'] ?? $params['pre_approval_parameters']['payment_token'] ?? ''));
    if ($token === '') {
      $token = trim((string) CRM_Utils_Request::retrieve('payment_token', 'String', NULL, FALSE, '', 'POST'));
    }
    return $token;
  }

  public function buildForm(&$form) {
    $vars = [
      'id' => (int) $this->_paymentProcessor['id'],
      'publicKey' => trim((string) $this->_paymentProcessor['signature']),
      'payJsUrl' => $this->getPayJsUrl(),
      'formId' => (string) $form->getAttribute('id'),
      'billingAddressID' => (int) CRM_Core_BAO_LocationType::getBilling(),
      'applePay' => $this->applePayVars(),
    ];

    Civi::resources()->addVars(E::SHORT_NAME, $vars);
    $form->assign('payarcjsVars', $vars);

    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => $this->getPayJsUrl(),
      'weight' => 90,
    ]);
    CRM_Core_Region::instance('billing-block')->add([
      'styleUrl' => E::url('css/payarcjs.css'),
      'weight' => 95,
    ]);
    CRM_Core_Region::instance('billing-block')->add([
      'template' => E::path('templates/CRM/Payarcjs/Card.tpl'),
      'weight' => 96,
    ]);
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => E::url('js/payarcjs.js'),
      'weight' => 100,
    ]);

    $form->assign('isJsValidate', TRUE);
    return FALSE;
  }

  public function doPayment(&$params, $component = 'contribute') {
    $propertyBag = $this->beginDoPayment($params);
    if ((float) $propertyBag->getAmount() === 0.0) {
      return $this->setStatusPaymentCompleted([]);
    }

    $paymentToken = $this->resolvePaymentToken($propertyBag, (array) $params);
    if ($paymentToken === '') {
      throw new PaymentProcessorException(E::ts('No PayArc payment token was supplied. Please re-enter the card.'));
    }

    $isPayJsKey = str_starts_with($paymentToken, self::PAYJS_TOKEN_PREFIX);
    if ($isPayJsKey) {
      $paymentToken = substr($paymentToken, strlen(self::PAYJS_TOKEN_PREFIX));
    }

    $isRecurring = $propertyBag->getIsRecur();
    $metadata = $this->buildTransactionMetadata($propertyBag);

    try {
      if ($isPayJsKey) {
        $response = $this->getGatewayClient()->saleWithPaymentKey(
          $paymentToken,
          (string) $propertyBag->getAmount(),
          $metadata,
          $isRecurring
        );
      }
      else {
        $response = $this->getGatewayClient()->saleWithCardReference(
          $paymentToken,
          (string) $propertyBag->getAmount(),
          $metadata
        );
      }
    }
    catch (CRM_Payarcjs_AmbiguousGatewayException $e) {
      Civi::log(E::SHORT_NAME)->critical($e->getMessage(), [
        'contribution_id' => $propertyBag->getter('contributionID', TRUE),
        'invoice_id' => $propertyBag->getter('invoiceID', TRUE),
      ]);
      throw $this->ambiguousException($e->getMessage(), $e->getResponseData(), $e);
    }
    catch (CRM_Payarcjs_GatewayException|InvalidArgumentException $e) {
      throw $this->donorException($e->getMessage(), 'EXTERNAL_FAILURE', $e);
    }

    try {
      $this->assertApproved($response);
    }
    catch (PaymentProcessorException $e) {
      if ($e->getErrorCode() === 'PAYMENT_AMBIGUOUS') {
        Civi::log(E::SHORT_NAME)->critical($e->getErrorData()['payarcjs_gateway_message'] ?? $e->getMessage(), [
          'contribution_id' => $propertyBag->getter('contributionID', TRUE),
          'invoice_id' => $propertyBag->getter('invoiceID', TRUE),
          'response' => $response,
        ]);
      }
      throw $e;
    }

    if (empty($response['key']) && empty($response['refnum'])) {
      $detail = 'PayArc approved the payment but returned no transaction identifier.';
      Civi::log(E::SHORT_NAME)->critical($detail, [
        'contribution_id' => $propertyBag->getter('contributionID', TRUE),
        'invoice_id' => $propertyBag->getter('invoiceID', TRUE),
        'response' => $response,
      ]);
      throw $this->ambiguousException($detail, $response);
    }

    $result = [
      'trxn_id' => (string) ($response['key'] ?? $response['refnum'] ?? ''),
      'processor_id' => (string) ($response['refnum'] ?? ''),
    ];

    // Core's checkout reads card_type_id / pan_truncation back out of the
    // params it passed in, so set them there as well as on the result.
    $cardDetails = $this->cardDetailsForCivi($response);
    $result += $cardDetails;
    if (is_array($params)) {
      $params += $cardDetails;
    }

    if ($isRecurring && $isPayJsKey) {
      $savedCard = $response['savedcard'] ?? [];
      if (!empty($savedCard['key'])) {
        try {
          $paymentTokenID = $this->storeReusableToken($propertyBag, $savedCard);
          if ($paymentTokenID) {
            $result['payment_token_id'] = $paymentTokenID;
            $result['processor_id'] = (string) $savedCard['key'];
          }
          else {
            $this->markRecurringUnusable($propertyBag, 'The approved payment could not be linked to a CiviCRM contact token.');
          }
        }
        catch (Throwable $e) {
          $this->markRecurringUnusable($propertyBag, $e->getMessage(), $e);
        }
      }
      else {
        $this->markRecurringUnusable($propertyBag, 'PayArc approved the recurring payment but did not return savedcard.key.');
      }
    }

    return $this->setStatusPaymentCompleted($result);
  }

  public function supportsRefund() {
    return TRUE;
  }

  /**
   * Refund or void a payment.
   *
   * PayArc only voids transactions that have not settled and only refunds
   * ones that have, so the original transaction is looked up first. A full
   * reversal of an unsettled charge is a void; a settled charge is refunded in
   * full or in part. A partial reversal of an unsettled charge is refused
   * because PayArc would void the whole charge instead.
   */
  public function doRefund(&$params) {
    $transactionID = trim((string) ($params['trxn_id'] ?? $params['transaction_id'] ?? ''));
    if ($transactionID === '') {
      throw new PaymentProcessorException(E::ts('The original PayArc transaction ID is missing.'));
    }
    $amount = isset($params['amount']) ? number_format(abs((float) $params['amount']), 2, '.', '') : NULL;

    try {
      $client = $this->getGatewayClient();
      $original = $this->lookupTransaction($client, $transactionID);
      $statusCode = strtoupper((string) ($original['status_code'] ?? ''));
      $originalAmount = isset($original['amount']) && is_numeric($original['amount']) ? (float) $original['amount'] : NULL;
      $isFullAmount = $amount === NULL || ($originalAmount !== NULL && (float) $amount >= $originalAmount - 0.005);

      if ($statusCode === 'V') {
        throw new PaymentProcessorException(E::ts('This PayArc transaction has already been voided.'), 'EXTERNAL_FAILURE', $original);
      }
      if (in_array($statusCode, self::UNSETTLED_STATUS_CODES, TRUE)) {
        if (!$isFullAmount) {
          throw new PaymentProcessorException(
            E::ts('This payment has not settled at PayArc yet, so only the full amount can be reversed today. Refund the full amount now, or issue the partial refund after the batch settles (usually the next business day).'),
            'EXTERNAL_FAILURE',
            $original
          );
        }
        $response = $client->void($transactionID);
      }
      else {
        $response = $client->refund($transactionID, $amount);
      }

      $this->assertApproved($response);
      if (empty($response['key']) && empty($response['refnum'])) {
        throw new PaymentProcessorException(
          E::ts('PayArc approved the refund but returned no transaction identifier. Reconcile it before retrying.'),
          'PAYMENT_AMBIGUOUS',
          $response
        );
      }
    }
    catch (CRM_Payarcjs_AmbiguousGatewayException $e) {
      throw new PaymentProcessorException($e->getMessage(), 'PAYMENT_AMBIGUOUS', $e->getResponseData(), $e);
    }
    catch (CRM_Payarcjs_GatewayException|InvalidArgumentException $e) {
      throw new PaymentProcessorException($e->getMessage(), 'EXTERNAL_FAILURE', [], $e);
    }

    // Shape expected by core Payment.create and the mjwshared refund UI.
    return [
      'refund_trxn_id' => (string) ($response['key'] ?? $response['refnum'] ?? ''),
      'refund_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'),
      'refund_status' => 'Completed',
      'fee_amount' => 0,
    ];
  }

  /**
   * Fetch the original transaction so doRefund() can choose void or refund.
   * Returns an empty array when the lookup is not possible; the caller then
   * falls back to a plain refund, which PayArc documents as voiding an
   * unsettled transaction itself.
   */
  private function lookupTransaction(CRM_Payarcjs_GatewayClient $client, string $transactionID): array {
    try {
      return $client->getTransaction($transactionID);
    }
    catch (CRM_Payarcjs_GatewayException $e) {
      Civi::log(E::SHORT_NAME)->warning('Could not look up PayArc transaction {id} before refunding: {error}', [
        'id' => $transactionID,
        'error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  protected function supportsBackOffice() {
    return TRUE;
  }

  protected function supportsMultipleConcurrentPayments() {
    return FALSE;
  }

  protected function supportsFutureRecurStartDate() {
    return FALSE;
  }

  protected function supportsCancelRecurring() {
    return TRUE;
  }

  protected function supportsCancelRecurringNotifyOptional() {
    return FALSE;
  }

  public function doCancelRecurring(PropertyBag $propertyBag) {
    $propertyBag->setIsNotifyProcessorOnCancelRecur(FALSE);
    return ['message' => E::ts('Recurring contribution cancelled in CiviCRM.')];
  }

  /**
   * Process due CiviCRM-managed recurring contributions for this processor.
   *
   * Called by CiviCRM's Job.run_payment_cron API.
   */
  public function handlePaymentCron(): array {
    $processor = new CRM_Payarcjs_RecurringProcessor($this);
    return $processor->run();
  }

  /**
   * Staff may edit these schedule fields. CiviCRM owns the schedule, so no
   * call to PayArc is needed when they change.
   */
  public function getEditableRecurringScheduleFields() {
    return ['amount', 'installments', 'frequency_interval', 'frequency_unit', 'next_sched_contribution_date'];
  }

  public function getRecurringScheduleUpdateHelpText() {
    return E::ts('CiviCRM manages this recurring schedule. Changes apply from the next scheduled charge and nothing is sent to PayArc.');
  }

  public function supportsEditRecurringContribution() {
    return TRUE;
  }

  /**
   * Called by CRM_Contribute_Form_UpdateSubscription. Returning TRUE lets
   * core save the new schedule values itself.
   */
  public function changeSubscriptionAmount(&$message = '', $params = []) {
    $message = E::ts('Recurring schedule updated in CiviCRM.');
    return TRUE;
  }

  /**
   * Replace the stored card on a recurring contribution.
   *
   * Called by CRM_Contribute_Form_UpdateBilling with the submitted billing
   * fields plus payment_token from Pay.js. The new card is vaulted through a
   * $1.00 authorization that is voided at once (PayArc's cc:save does not
   * accept Pay.js keys), stored as a PaymentToken and linked to the series.
   * A series stopped by repeated declines is reactivated.
   */
  public function updateSubscriptionBillingInfo(&$message = '', $params = []) {
    try {
      $recurID = (int) ($params['contributionRecurID'] ?? 0);
      if (!$recurID) {
        throw new PaymentProcessorException(E::ts('The recurring contribution could not be identified.'));
      }
      $token = $this->paymentTokenFromRequest($params);
      if (!str_starts_with($token, self::PAYJS_TOKEN_PREFIX)) {
        throw new PaymentProcessorException(E::ts('No new card was supplied. Please re-enter the card details.'));
      }
      $paymentKey = substr($token, strlen(self::PAYJS_TOKEN_PREFIX));

      $recur = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recurID]);
      $email = (string) ($params['email'] ?? '');

      try {
        $response = $this->getGatewayClient()->verifyAndSaveCardWithPaymentKey($paymentKey, [
          'custid' => (string) ($recur['contact_id'] ?? ''),
          'clientip' => CRM_Utils_System::ipAddress(),
          'billing_address' => [
            'firstname' => (string) ($params['first_name'] ?? ''),
            'lastname' => (string) ($params['last_name'] ?? ''),
            'street' => (string) ($params['street_address'] ?? ''),
            'city' => (string) ($params['city'] ?? ''),
            'state' => $this->stateAbbreviation((string) ($params['state_province'] ?? '')),
            'postalcode' => (string) ($params['postal_code'] ?? ''),
            'country' => $this->alpha3Country((string) ($params['country'] ?? '')),
            'email' => $email,
          ],
        ]);
      }
      catch (CRM_Payarcjs_GatewayException|InvalidArgumentException $e) {
        throw $this->donorException($e->getMessage(), 'EXTERNAL_FAILURE', $e);
      }
      $this->assertApproved($response);

      $savedCard = $response['savedcard'] ?? [];
      if (empty($savedCard['key'])) {
        throw new PaymentProcessorException(E::ts('PayArc accepted the card but did not return a card reference.'));
      }
      $voidWarning = '';
      if (!empty($response['void_error'])) {
        Civi::log(E::SHORT_NAME)->warning('Card verification authorization {refnum} on recurring contribution {recur} was not voided: {error}', [
          'refnum' => $response['refnum'] ?? '',
          'recur' => $recurID,
          'error' => $response['void_error'],
        ]);
        $voidWarning = E::ts('Note for staff: the %1 verification authorization (PayArc ref %2) could not be voided (%3). It will expire on its own, or void it in the PayArc console.', [
          1 => CRM_Utils_Money::format(CRM_Payarcjs_GatewayClient::CARD_VERIFICATION_AMOUNT),
          2 => $response['refnum'] ?? '',
          3 => $response['void_error'],
        ]);
      }

      $bag = new PropertyBag();
      $bag->setContactID((int) $recur['contact_id']);
      $bag->setContributionRecurID($recurID);
      if ($email !== '') {
        $bag->setEmail($email);
      }
      if (!empty($params['first_name'])) {
        $bag->setFirstName((string) $params['first_name']);
      }
      if (!empty($params['last_name'])) {
        $bag->setLastName((string) $params['last_name']);
      }
      $paymentTokenID = $this->storeReusableToken($bag, $savedCard);
      if (!$paymentTokenID) {
        throw new PaymentProcessorException(E::ts('The new card could not be stored in CiviCRM.'));
      }

      $update = [
        'id' => $recurID,
        'failure_count' => 0,
        'failure_retry_date' => '',
      ];
      $statusName = CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recur['contribution_status_id']);
      if ($statusName === 'Failed') {
        $update['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'In Progress');
        $nextDate = (string) ($recur['next_sched_contribution_date'] ?? '');
        if ($nextDate === '' || strtotime($nextDate) < time()) {
          // Resume from now; missed installments are not charged retroactively.
          $update['next_sched_contribution_date'] = date('Y-m-d H:i:s');
        }
      }
      civicrm_api3('ContributionRecur', 'create', $update);

      $details = CRM_Payarcjs_CardDetails::fromResponse($response);
      $message = $details['last4']
        ? E::ts('Card ending in %1 will be used for future charges.', [1 => $details['last4']])
        : E::ts('The new card will be used for future charges.');
      if ($statusName === 'Failed') {
        $message .= ' ' . E::ts('The recurring contribution has been reactivated.');
      }
      if ($voidWarning !== '' && $this->isStaffSession()) {
        $message .= ' ' . $voidWarning;
      }
      return TRUE;
    }
    catch (PaymentProcessorException $e) {
      Civi::log(E::SHORT_NAME)->error('Updating the card on a recurring contribution failed.', [
        'contribution_recur_id' => $params['contributionRecurID'] ?? NULL,
        'exception' => $e,
      ]);
      // CRM_Contribute_Form_UpdateBilling displays a CRM_Core_Error to the user.
      return CRM_Core_Error::createError($e->getMessage());
    }
  }

  /**
   * Map the card in a PayArc response to CiviCRM's card_type_id and
   * pan_truncation fields. Returns an empty array if nothing usable came back.
   */
  public function cardDetailsForCivi(array $response): array {
    $details = CRM_Payarcjs_CardDetails::fromResponse($response);
    $out = [];
    if ($details['last4']) {
      $out['pan_truncation'] = $details['last4'];
    }
    if ($details['brand']) {
      $cardTypeID = CRM_Core_PseudoConstant::getKey('CRM_Financial_DAO_FinancialTrxn', 'card_type_id', $details['brand']);
      if ($cardTypeID) {
        $out['card_type_id'] = (int) $cardTypeID;
      }
    }
    return $out;
  }

  /**
   * Pay.js (v2, which carries the card entry and Apple Pay) must be loaded
   * from the same environment as the public key, so it follows the REST URL's
   * host: a processor pointed at sandbox.payarc.com gets the sandbox Pay.js
   * even when it is the "live" record (useful on a development site). Falls
   * back to the test flag when no URL is set.
   */
  private function getPayJsUrl(): string {
    $host = strtolower((string) parse_url((string) ($this->_paymentProcessor['url_site'] ?? ''), PHP_URL_HOST));
    $isSandbox = $host !== '' ? str_contains($host, 'sandbox') : !empty($this->_paymentProcessor['is_test']);
    return $isSandbox
      ? 'https://sandbox.payarc.com/js/v2/pay.js'
      : 'https://www.payarc.com/js/v2/pay.js';
  }

  /**
   * Apple Pay settings and the lookup tables the browser script needs to copy
   * the Apple billing contact into CiviCRM's billing fields.
   */
  private function applePayVars(): array {
    $enabled = (bool) Civi::settings()->get('payarcjs_apple_pay_enabled');
    if (!$enabled) {
      return ['enabled' => FALSE];
    }
    $config = CRM_Core_Config::singleton();
    $defaultCountryID = (int) ($config->defaultContactCountry ?? 0);
    $isoCodes = CRM_Core_PseudoConstant::countryIsoCode();
    $displayName = trim((string) Civi::settings()->get('payarcjs_apple_pay_display_name'));
    if ($displayName === '') {
      $displayName = (string) (CRM_Core_BAO_Domain::getDomain()->name ?? '');
    }
    // Apple returns the state as an abbreviation ("CA"); CiviCRM's select
    // shows names, so ship abbreviation => id for the site's default country
    // plus the two countries whose Wallet addresses carry abbreviations.
    $stateIds = [];
    $countryIDs = array_flip(array_map('strtoupper', $isoCodes));
    $wanted = array_unique(array_filter([$defaultCountryID, $countryIDs['US'] ?? 0, $countryIDs['CA'] ?? 0]));
    foreach ($wanted as $countryID) {
      $iso = strtoupper((string) ($isoCodes[$countryID] ?? ''));
      if ($iso === '') {
        continue;
      }
      foreach (CRM_Core_PseudoConstant::stateProvinceForCountry((int) $countryID, 'abbreviation') as $id => $abbreviation) {
        $stateIds[$iso][strtoupper((string) $abbreviation)] = (int) $id;
      }
    }
    return [
      'enabled' => TRUE,
      'displayName' => $displayName,
      'countryCode' => strtoupper((string) ($isoCodes[$defaultCountryID] ?? 'US')),
      'currencyCode' => strtoupper((string) ($config->defaultCurrency ?: 'USD')),
      'defaultCountryIso' => strtoupper((string) ($isoCodes[$defaultCountryID] ?? '')),
      'countryIds' => array_map('intval', $countryIDs),
      'stateIds' => $stateIds,
    ];
  }

  /**
   * Look a charge up by the orderid we sent (the contribution's invoice ID).
   * Used by the recurring job to reconcile an attempt whose response was lost.
   */
  public function findTransactionByOrderId(string $orderId): ?array {
    return $this->getGatewayClient()->findTransactionByOrderId($orderId);
  }

  /**
   * One page of the merchant account's transactions, newest first; used by
   * CRM_Payarcjs_TransactionImporter.
   */
  public function listGatewayTransactions(int $limit = 100, int $offset = 0): array {
    return $this->getGatewayClient()->listTransactions($limit, $offset);
  }

  public function getGatewayTransaction(string $transactionKey): array {
    return $this->getGatewayClient()->getTransaction($transactionKey);
  }

  public function getGatewayCustomer(string $customerKey): array {
    return $this->getGatewayClient()->getCustomer($customerKey);
  }

  private function getGatewayClient(): CRM_Payarcjs_GatewayClient {
    $url = trim((string) ($this->_paymentProcessor['url_site'] ?? ''));
    if ($url === '') {
      $url = !empty($this->_paymentProcessor['is_test'])
        ? 'https://sandbox.payarc.com/api/v2'
        : 'https://secure.payarc.com/api/v2';
    }

    return new CRM_Payarcjs_GatewayClient(
      (string) $this->_paymentProcessor['user_name'],
      (string) $this->_paymentProcessor['password'],
      $url
    );
  }

  private function resolvePaymentToken(PropertyBag $propertyBag, array $params): string {
    $token = $propertyBag->has('paymentToken') ? trim((string) $propertyBag->getPaymentToken()) : '';
    if ($token === '' && empty($params['payment_token_id'])) {
      $token = $this->paymentTokenFromRequest($params);
    }
    if ($token !== '') {
      if (!str_starts_with($token, self::PAYJS_TOKEN_PREFIX)) {
        throw new PaymentProcessorException(E::ts('The submitted PayArc payment token is not valid for browser checkout.'));
      }
      return $token;
    }

    $paymentTokenID = (int) ($params['payment_token_id'] ?? 0);
    if ($paymentTokenID) {
      try {
        $query = [
          'id' => $paymentTokenID,
          'payment_processor_id' => $this->getID(),
          'return' => 'token',
        ];
        $contactID = (int) ($this->getContactId($propertyBag) ?? 0);
        if ($contactID) {
          $query['contact_id'] = $contactID;
        }
        return (string) civicrm_api3('PaymentToken', 'getvalue', $query);
      }
      catch (CRM_Core_Exception $e) {
        throw new PaymentProcessorException(E::ts('The selected saved payment method is unavailable.'));
      }
    }

    return '';
  }

  private function buildTransactionMetadata(PropertyBag $propertyBag): array {
    $contributionID = (string) $propertyBag->getter('contributionID', TRUE, '');
    $invoiceID = (string) $propertyBag->getter('invoiceID', TRUE, '');
    $invoice = $contributionID !== '' ? substr($contributionID, -11) : substr($invoiceID, -11);

    // PayArc's REST API takes the ISO 4217 alphabetic code ("USD"); the
    // numeric code ("840") is rejected with "Invalid currency code".
    $currency = strtoupper(trim((string) $propertyBag->getter('currency', TRUE, '')));
    if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
      throw new PaymentProcessorException(E::ts('Currency %1 is not supported by this PayArc integration.', [1 => $currency]));
    }

    $contactID = (int) ($this->getContactId($propertyBag) ?? 0);

    return [
      'invoice' => $invoice,
      'orderid' => $invoiceID,
      'custid' => $contactID > 0 ? (string) $contactID : '',
      'description' => (string) $propertyBag->getter('description', TRUE, ''),
      'clientip' => CRM_Utils_System::ipAddress(),
      'currency' => $currency,
      'billing_address' => [
        'firstname' => (string) $propertyBag->getter('firstName', TRUE, ''),
        'lastname' => (string) $propertyBag->getter('lastName', TRUE, ''),
        'street' => (string) $propertyBag->getter('billingStreetAddress', TRUE, ''),
        'city' => (string) $propertyBag->getter('billingCity', TRUE, ''),
        'state' => (string) $propertyBag->getter('billingStateProvince', TRUE, ''),
        'postalcode' => (string) $propertyBag->getter('billingPostalCode', TRUE, ''),
        'country' => $this->alpha3Country((string) $propertyBag->getter('billingCountry', TRUE, '')),
        'phone' => (string) $propertyBag->getter('phone', TRUE, ''),
        'email' => (string) $propertyBag->getter('email', TRUE, ''),
      ],
    ];
  }

  /**
   * PayArc documents a three-letter country code for billing addresses.
   * Accepts ISO alpha-2, alpha-3, or a CiviCRM country name (which is what
   * CRM_Contribute_Form_UpdateBilling passes). Unknown values are omitted.
   */
  private function alpha3Country(string $country): string {
    $country = trim($country);
    if (strlen($country) > 3) {
      try {
        $country = (string) CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Country', $country, 'iso_code', 'name');
      }
      catch (Throwable $e) {
        return '';
      }
    }
    return CRM_Payarcjs_Country::alpha3($country);
  }

  /**
   * CRM_Contribute_Form_UpdateBilling passes the state name; PayArc wants
   * the abbreviation. Anything already short is passed through.
   */
  private function stateAbbreviation(string $state): string {
    $state = trim($state);
    if (strlen($state) <= 3) {
      return $state;
    }
    try {
      $abbreviation = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_StateProvince', $state, 'abbreviation', 'name');
      return $abbreviation ? (string) $abbreviation : $state;
    }
    catch (Throwable $e) {
      return $state;
    }
  }

  /**
   * Throw for anything but an approval. Donors get wording they can act on;
   * the gateway's own text travels in the error data (and is appended for
   * logged-in staff) so back-office users and logs keep the real reason.
   */
  private function assertApproved(array $response): void {
    $resultCode = (string) ($response['result_code'] ?? '');
    if ($resultCode === 'A') {
      return;
    }

    if ($resultCode === 'V') {
      throw new PaymentProcessorException(
        E::ts('Your bank requires an additional verification step that this payment form cannot complete yet. Please try a different card or contact us.'),
        'PAYMENT_REQUIRES_ACTION',
        $response + ['payarcjs_gateway_message' => 'Verification required (result_code V)']
      );
    }

    if ($resultCode === 'P') {
      throw $this->ambiguousException('PayArc partially approved this payment.', $response);
    }

    if (!in_array($resultCode, ['D', 'E'], TRUE)) {
      throw $this->ambiguousException('PayArc returned an unrecognized payment result.', $response);
    }

    $texts = CRM_Payarcjs_DonorMessage::fromResponse($response);
    $errorCode = (string) ($response['error_code'] ?? $response['errorcode'] ?? 'PAYMENT_DECLINED');
    throw new PaymentProcessorException(
      $this->withStaffDetail($texts['donor'], $texts['gateway']),
      $errorCode,
      $response + ['payarcjs_gateway_message' => $texts['gateway']]
    );
  }

  /**
   * A definitive failure before or during the gateway call (HTTP error,
   * invalid token, connection refused): donor wording plus the real reason
   * for staff and logs.
   */
  private function donorException(string $gatewayMessage, string $code, ?Throwable $previous = NULL): PaymentProcessorException {
    return new PaymentProcessorException(
      $this->withStaffDetail(CRM_Payarcjs_DonorMessage::donorText($gatewayMessage), $gatewayMessage),
      $code,
      ['payarcjs_gateway_message' => $gatewayMessage],
      $previous
    );
  }

  /**
   * The charge may or may not have happened. The donor must not retry; the
   * detail goes to staff and the logs.
   */
  private function ambiguousException(string $detail, array $response = [], ?Throwable $previous = NULL): PaymentProcessorException {
    $donor = E::ts('We could not confirm whether your payment went through. Please do not submit it again; contact us and we will check and get back to you.');
    return new PaymentProcessorException(
      $this->withStaffDetail($donor, $detail),
      'PAYMENT_AMBIGUOUS',
      $response + ['payarcjs_gateway_message' => $detail],
      $previous
    );
  }

  /**
   * Append the gateway's reason for logged-in staff (back-office forms).
   */
  private function withStaffDetail(string $donorMessage, string $gatewayMessage): string {
    if ($this->isStaffSession()) {
      return $donorMessage . ' ' . E::ts('Gateway response: %1', [1 => $gatewayMessage]);
    }
    return $donorMessage;
  }

  private function isStaffSession(): bool {
    try {
      return (bool) CRM_Core_Session::getLoggedInContactID() && CRM_Core_Permission::check('access CiviContribute');
    }
    catch (Throwable $e) {
      // No session (cron).
      return FALSE;
    }
  }

  private function storeReusableToken(PropertyBag $propertyBag, array $savedCard): ?int {
    $contactID = (int) ($this->getContactId($propertyBag) ?? 0);
    if (!$contactID) {
      Civi::log(E::SHORT_NAME)->error('Cannot store PayArc token without a contact ID.');
      return NULL;
    }

    $cardReference = (string) $savedCard['key'];
    // PayArc returns the expiration as MMYY; CiviCRM's expiry_date lets staff
    // find cards that are about to expire.
    $expiry = CRM_Payarcjs_Schedule::expiryDate((string) ($savedCard['expiration'] ?? ''));
    $existing = civicrm_api3('PaymentToken', 'get', [
      'sequential' => 1,
      'contact_id' => $contactID,
      'payment_processor_id' => $this->getID(),
      'token' => $cardReference,
      'options' => ['limit' => 1],
    ]);
    if (!empty($existing['values'][0]['id'])) {
      $paymentTokenID = (int) $existing['values'][0]['id'];
    }
    else {
      $created = civicrm_api3('PaymentToken', 'create', [
        'contact_id' => $contactID,
        'payment_processor_id' => $this->getID(),
        'token' => $cardReference,
        'email' => $this->getBillingEmail($propertyBag, $contactID),
        'billing_first_name' => (string) $propertyBag->getter('firstName', TRUE, ''),
        'billing_last_name' => (string) $propertyBag->getter('lastName', TRUE, ''),
        'masked_account_number' => (string) ($savedCard['cardnumber'] ?? $savedCard['number'] ?? ''),
        'ip_address' => CRM_Utils_System::ipAddress(),
      ] + ($expiry ? ['expiry_date' => $expiry] : []));
      $paymentTokenID = (int) $created['id'];
    }

    $recurID = (int) $propertyBag->getter('contributionRecurID', TRUE, 0);
    if ($recurID) {
      civicrm_api3('ContributionRecur', 'create', [
        'id' => $recurID,
        'payment_token_id' => $paymentTokenID,
        'processor_id' => $cardReference,
      ]);
    }

    return $paymentTokenID;
  }

  /**
   * Stop future installments without turning an already-approved sale into a
   * browser error which could encourage the donor to submit it again.
   */
  private function markRecurringUnusable(PropertyBag $propertyBag, string $message, ?Throwable $exception = NULL): void {
    $recurID = (int) $propertyBag->getter('contributionRecurID', TRUE, 0);
    Civi::log(E::SHORT_NAME)->critical($message, [
      'contribution_recur_id' => $recurID ?: NULL,
      'exception' => $exception,
    ]);

    if ($recurID) {
      try {
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $recurID,
          'contribution_status_id' => CRM_Core_PseudoConstant::getKey(
            'CRM_Contribute_BAO_ContributionRecur',
            'contribution_status_id',
            'Failed'
          ),
        ]);
      }
      catch (Throwable $statusException) {
        Civi::log(E::SHORT_NAME)->critical('Unable to stop the unusable recurring contribution.', [
          'contribution_recur_id' => $recurID,
          'exception' => $statusException,
        ]);
      }
    }
  }

}
