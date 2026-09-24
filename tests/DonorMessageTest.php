<?php

use PHPUnit\Framework\TestCase;

final class DonorMessageTest extends TestCase {

  /**
   * @dataProvider responses
   */
  public function testDonorWording(array $response, string $expectedFragment, string $expectedGateway): void {
    $texts = CRM_Payarcjs_DonorMessage::fromResponse($response);
    self::assertStringContainsString($expectedFragment, $texts['donor']);
    self::assertSame($expectedGateway, $texts['gateway']);
    self::assertStringNotContainsString('(00)', $texts['donor'], 'Raw gateway codes must not reach the donor.');
  }

  public static function responses(): array {
    return [
      'sandbox decline' => [['result_code' => 'D', 'result' => 'Declined', 'error' => 'Card Declined (00)', 'error_code' => '10127'], 'Your card was declined', 'Card Declined (00) [10127]'],
      'insufficient funds' => [['result_code' => 'D', 'error' => 'Insufficient Funds (51)'], 'over the available limit', 'Insufficient Funds (51)'],
      'bad card number' => [['result_code' => 'E', 'error' => 'Invalid Card Number (1)', 'error_code' => '11'], 'card number is not valid', 'Invalid Card Number (1) [11]'],
      'expired' => [['result_code' => 'E', 'error' => 'Card Expired'], 'expired', 'Card Expired'],
      'cvv' => [['result_code' => 'D', 'error' => 'Card Code Verification Failed'], 'security code (CVV)', 'Card Code Verification Failed'],
      'avs' => [['result_code' => 'D', 'error' => 'AVS Mismatch: zip does not match'], 'billing address or postal code', 'AVS Mismatch: zip does not match'],
      'credentials' => [['result_code' => 'E', 'error' => 'Valid authentication required'], 'not configured correctly', 'Valid authentication required'],
      'source not allowed' => [['result_code' => 'E', 'error' => 'Transaction type not allowed from this source', 'error_code' => '80'], 'not configured correctly', 'Transaction type not allowed from this source [80]'],
      'processor down' => [['result_code' => 'E', 'error' => 'Processor Error: timeout'], 'did not respond', 'Processor Error: timeout'],
      'nested error' => [['result_code' => 'E', 'error' => ['message' => 'Invalid Expiration Date']], 'expiration date', 'Invalid Expiration Date'],
      'saved card token' => [['result_code' => 'E', 'error' => 'Invalid card reference token', 'errorcode' => '601'], 'card we have on file', 'Invalid card reference token [601]'],
      'empty decline' => [['result_code' => 'D'], 'Your card was declined', 'Declined'],
      'unknown' => [['result_code' => 'E', 'error' => 'Something odd'], 'could not be processed', 'Something odd'],
    ];
  }

  public function testTransportErrorsGetDonorWording(): void {
    self::assertStringContainsString('did not respond', CRM_Payarcjs_DonorMessage::donorText('The connection to PayArc failed: Could not resolve host'));
    self::assertStringContainsString('not configured correctly', CRM_Payarcjs_DonorMessage::donorText('PayArc returned HTTP 401.'  . ' API authentication failed'));
  }

}
