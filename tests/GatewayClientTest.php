<?php

use PHPUnit\Framework\TestCase;

final class GatewayClientTest extends TestCase {

  public function testPaymentKeySaleCanRequestReusableCard(): void {
    $request = [];
    $client = $this->client($request, 201, [
      'result_code' => 'A',
      'key' => 'transaction-key',
      'savedcard' => ['key' => 'card-reference'],
    ]);

    $response = $client->saleWithPaymentKey('single-use-key', '12.3', [
      'invoice' => '42',
      'currency' => 'USD',
      'custid' => '7',
    ], TRUE);

    self::assertSame('transaction-key', $response['key']);
    self::assertSame('POST', $request['method']);
    self::assertSame('https://sandbox.payarc.com/api/v2/transactions', $request['url']);
    self::assertSame([
      'command' => 'cc:sale',
      'amount' => '12.30',
      'software' => 'CiviCRM PayArc Pay.js/0.1',
      'invoice' => '42',
      'currency' => 'USD',
      'custid' => '7',
      'payment_key' => 'single-use-key',
      'save_card' => TRUE,
    ], json_decode($request['body'], TRUE));

    $authorization = $this->header($request['headers'], 'Authorization: ');
    $decoded = base64_decode(substr($authorization, strlen('Basic ')), TRUE);
    self::assertMatchesRegularExpression('/^api-key:s2\/[a-f0-9]{32}\/[a-f0-9]{64}$/', $decoded);
  }

  public function testStoredCardReferenceUsesCreditCardFieldAndZeroExpiry(): void {
    $request = [];
    $client = $this->client($request, 200, ['result_code' => 'A', 'key' => 'txn']);

    $client->saleWithCardReference('card-reference', '9.99');
    $payload = json_decode($request['body'], TRUE);

    self::assertSame('card-reference', $payload['creditcard']['number']);
    self::assertSame('0000', $payload['creditcard']['expiration']);
    self::assertArrayNotHasKey('payment_key', $payload);
  }

  public function testVerifyCredentialsListsOneTransaction(): void {
    $request = [];
    $client = $this->client($request, 200, ['type' => 'list', 'data' => []]);

    $client->verifyCredentials();

    self::assertSame('GET', $request['method']);
    self::assertSame('https://sandbox.payarc.com/api/v2/transactions?limit=1', $request['url']);
    self::assertNull($request['body']);
  }

  public function testVerifyPublicKeyUsesPublicAuthAndMintsNoCharge(): void {
    $request = [];
    $client = $this->client($request, 200, ['type' => 'payment_key', 'key' => 'k']);

    $client->verifyPublicKey('public-key');

    self::assertSame('https://sandbox.payarc.com/api/v2/pub/payment_keys', $request['url']);
    self::assertSame('Basic ' . base64_encode('public-key:s2//'), $this->header($request['headers'], 'Authorization: '));
    self::assertSame(['creditcard'], array_keys(json_decode($request['body'], TRUE)));
  }

  public function testVerifyPublicKeyReportsUnknownKey(): void {
    $request = [];
    $client = $this->client($request, 401, ['error' => 'Specified source key not found.', 'errorcode' => 23]);

    $this->expectException(CRM_Payarcjs_GatewayException::class);
    $this->expectExceptionMessage('Specified source key not found.');
    $client->verifyPublicKey('wrong');
  }

  public function testServerErrorIsAmbiguous(): void {
    $request = [];
    $client = $this->client($request, 503, ['error' => 'Unavailable']);

    $this->expectException(CRM_Payarcjs_AmbiguousGatewayException::class);
    $client->saleWithPaymentKey('single-use-key', '1.00');
  }

  public function testDefinitiveHttpErrorPreservesNestedMessage(): void {
    $request = [];
    $client = $this->client($request, 400, ['error' => ['message' => 'Invalid key']]);

    $this->expectException(CRM_Payarcjs_GatewayException::class);
    $this->expectExceptionMessage('Invalid key');
    $client->saleWithPaymentKey('single-use-key', '1.00');
  }

  public function testVerifyAndSaveCardAuthorizesThenVoids(): void {
    $requests = [];
    $responses = [
      ['result_code' => 'A', 'refnum' => '555', 'key' => 'authkey', 'savedcard' => ['key' => 'ref', 'type' => 'Visa', 'cardnumber' => '4000xxxxxxxx2224']],
      ['result_code' => 'A', 'refnum' => '555', 'key' => 'authkey'],
    ];
    $client = new CRM_Payarcjs_GatewayClient('key', 'pin', 'https://sandbox.payarc.com/api/v2', static function ($method, $url, $headers, $body) use (&$requests, &$responses) {
      $requests[] = json_decode($body, TRUE);
      return ['status' => 200, 'body' => json_encode(array_shift($responses))];
    });

    $response = $client->verifyAndSaveCardWithPaymentKey('single-use-key', ['email' => 'donor@example.org', 'billing_address' => ['firstname' => 'A', 'city' => '']]);

    self::assertSame('ref', $response['savedcard']['key']);
    self::assertArrayNotHasKey('void_error', $response);
    self::assertCount(2, $requests);
    self::assertSame('cc:authonly', $requests[0]['command']);
    self::assertSame('1.00', $requests[0]['amount']);
    self::assertTrue($requests[0]['save_card']);
    self::assertSame('single-use-key', $requests[0]['payment_key']);
    self::assertArrayNotHasKey('email', $requests[0], 'A top-level email would make PayArc send its own receipt.');
    self::assertSame(['firstname' => 'A'], $requests[0]['billing_address']);
    self::assertSame(['command' => 'void', 'refnum' => '555'], $requests[1]);
  }

  public function testDonorEmailIsOnlySentInsideBillingAddress(): void {
    $request = [];
    $client = $this->client($request, 200, ['result_code' => 'A', 'key' => 'txn']);

    $client->saleWithPaymentKey('single-use-key', '5', [
      'email' => 'donor@example.org',
      'billing_address' => ['firstname' => 'A', 'email' => 'donor@example.org', 'street' => ''],
    ]);
    $payload = json_decode($request['body'], TRUE);

    self::assertArrayNotHasKey('email', $payload);
    self::assertSame(['firstname' => 'A', 'email' => 'donor@example.org'], $payload['billing_address']);
  }

  public function testVerifyAndSaveCardReportsFailedVoid(): void {
    $responses = [
      ['result_code' => 'A', 'refnum' => '555', 'savedcard' => ['key' => 'ref']],
      ['result_code' => 'E', 'error' => 'Transaction already voided.'],
    ];
    $client = new CRM_Payarcjs_GatewayClient('key', 'pin', 'https://sandbox.payarc.com/api/v2', static function () use (&$responses) {
      return ['status' => 200, 'body' => json_encode(array_shift($responses))];
    });

    $response = $client->verifyAndSaveCardWithPaymentKey('single-use-key');

    self::assertSame('ref', $response['savedcard']['key']);
    self::assertSame('Transaction already voided.', $response['void_error']);
  }

  public function testVerifyAndSaveCardDoesNotVoidDeclines(): void {
    $calls = 0;
    $client = new CRM_Payarcjs_GatewayClient('key', 'pin', 'https://sandbox.payarc.com/api/v2', static function () use (&$calls) {
      $calls++;
      return ['status' => 200, 'body' => json_encode(['result_code' => 'D', 'refnum' => '556', 'error' => 'Card Declined'])];
    });

    $response = $client->verifyAndSaveCardWithPaymentKey('single-use-key');

    self::assertSame('D', $response['result_code']);
    self::assertSame(1, $calls);
  }

  public function testPartialRefundUsesOriginalReference(): void {
    $request = [];
    $client = $this->client($request, 200, ['result_code' => 'A', 'key' => 'refund-key']);

    $client->refund('123456789', '4.5');

    self::assertSame([
      'command' => 'refund',
      'refnum' => '123456789',
      'amount' => '4.50',
    ], json_decode($request['body'], TRUE));
  }

  public function testRefundAndVoidAcceptTransactionKeys(): void {
    $request = [];
    $client = $this->client($request, 200, ['result_code' => 'A', 'key' => 'k']);

    $client->refund('5nf1fgqtpnhsmzv');
    self::assertSame(['command' => 'refund', 'trankey' => '5nf1fgqtpnhsmzv'], json_decode($request['body'], TRUE));

    $client->void(' bnfb4dbycv5pfrd ');
    self::assertSame(['command' => 'void', 'trankey' => 'bnfb4dbycv5pfrd'], json_decode($request['body'], TRUE));
  }

  public function testFindTransactionByOrderIdPagesUntilFound(): void {
    $urls = [];
    $pages = [
      array_map(static fn($i) => ['key' => "k$i", 'orderid' => "other-$i"], range(1, 100)),
      [['key' => 'k-old', 'orderid' => 'other-x'], ['key' => 'wanted', 'orderid' => 'payarcjs-7-2026-09-09-0', 'result_code' => 'A']],
    ];
    $client = new CRM_Payarcjs_GatewayClient('key', 'pin', 'https://sandbox.payarc.com/api/v2', static function (string $method, string $url) use (&$urls, &$pages): array {
      $urls[] = $url;
      return ['status' => 200, 'body' => json_encode(['type' => 'list', 'data' => array_shift($pages) ?? []])];
    });

    $found = $client->findTransactionByOrderId('payarcjs-7-2026-09-09-0');

    self::assertSame('wanted', $found['key']);
    self::assertSame([
      'https://sandbox.payarc.com/api/v2/transactions?limit=100&offset=0',
      'https://sandbox.payarc.com/api/v2/transactions?limit=100&offset=100',
    ], $urls);
  }

  public function testFindTransactionByOrderIdStopsAtShortPage(): void {
    $calls = 0;
    $client = new CRM_Payarcjs_GatewayClient('key', 'pin', 'https://sandbox.payarc.com/api/v2', static function () use (&$calls): array {
      $calls++;
      return ['status' => 200, 'body' => json_encode(['type' => 'list', 'data' => [['key' => 'k', 'orderid' => 'nope']]])];
    });

    self::assertNull($client->findTransactionByOrderId('payarcjs-7-2026-09-09-0'));
    self::assertSame(1, $calls);
  }

  public function testEmptyTransactionReferenceIsRejected(): void {
    $request = [];
    $client = $this->client($request, 200, ['result_code' => 'A']);

    $this->expectException(InvalidArgumentException::class);
    $client->void('   ');
  }

  private function client(array &$request, int $status, array $response): CRM_Payarcjs_GatewayClient {
    $transport = static function(string $method, string $url, array $headers, ?string $body) use (&$request, $status, $response): array {
      $request = compact('method', 'url', 'headers', 'body');
      return ['status' => $status, 'body' => json_encode($response, JSON_THROW_ON_ERROR)];
    };
    return new CRM_Payarcjs_GatewayClient('api-key', 'api-pin', 'https://sandbox.payarc.com/api/v2', $transport);
  }

  private function header(array $headers, string $prefix): string {
    foreach ($headers as $header) {
      if (str_starts_with($header, $prefix)) {
        return substr($header, strlen($prefix));
      }
    }
    self::fail('Header not found: ' . $prefix);
  }

}
