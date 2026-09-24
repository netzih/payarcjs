<?php

/**
 * Turns a PayArc decline or error into wording a donor can act on.
 *
 * The gateway's own text ("Card Declined (00)", "Invalid Card Number (1)")
 * is meant for merchants; donors need to know whether to fix a typo, try
 * another card, call their bank, or try again later. The raw text is kept
 * alongside for staff notes and logs. Pure PHP so it can be unit tested.
 */
class CRM_Payarcjs_DonorMessage {

  /**
   * @param array $response
   *   PayArc transaction response (result_code D or E), or an array with
   *   'error' for transport/HTTP failures.
   * @return array{donor: string, gateway: string}
   */
  public static function fromResponse(array $response): array {
    $gateway = self::gatewayText($response);
    return ['donor' => self::donorText($gateway, (string) ($response['result_code'] ?? '')), 'gateway' => $gateway];
  }

  /**
   * The gateway's text plus its numeric error code for staff.
   */
  public static function gatewayText(array $response): string {
    $text = '';
    foreach (['error', 'result'] as $field) {
      if (is_string($response[$field] ?? NULL) && trim($response[$field]) !== '') {
        $text = trim($response[$field]);
        break;
      }
      if (is_array($response[$field] ?? NULL)) {
        foreach (['message', 'description', 'error'] as $nested) {
          if (is_string($response[$field][$nested] ?? NULL) && trim($response[$field][$nested]) !== '') {
            $text = trim($response[$field][$nested]);
            break 2;
          }
        }
      }
    }
    if ($text === '') {
      $text = ($response['result_code'] ?? '') === 'D' ? 'Declined' : 'Error';
    }
    $code = (string) ($response['error_code'] ?? $response['errorcode'] ?? '');
    if ($code !== '' && !str_contains($text, $code)) {
      $text .= ' [' . $code . ']';
    }
    return $text;
  }

  public static function donorText(string $gateway, string $resultCode = ''): string {
    $g = strtolower($gateway);
    $ts = static fn(string $s) => function_exists('ts') ? CRM_Payarcjs_ExtensionUtil::ts($s) : $s;

    // Order matters: specific reasons before the generic decline.
    if (preg_match('/insufficient|nsf|over.?limit|exceeds.*limit/', $g)) {
      return $ts('Your card was declined by your bank because the amount is over the available limit. Please try a different card or contact your bank.');
    }
    if (preg_match('/pick.?up|lost|stolen|fraud|restricted|hold.?card|do not honou?r/', $g)) {
      return $ts('Your card was declined by your bank. Please contact your bank or try a different card.');
    }
    if (preg_match('/expir/', $g)) {
      return $ts('The card appears to be expired or the expiration date was entered incorrectly. Please check the expiration date and try again.');
    }
    if (preg_match('/cvv|cvc|cvv2|card code|security code|card verification/', $g)) {
      return $ts('The card security code (CVV) did not match. Please check the three or four digit code and try again.');
    }
    if (preg_match('/avs|address|zip|postal/', $g)) {
      return $ts('The billing address or postal code did not match the card. Please check the billing address and try again.');
    }
    if (preg_match('/card reference|reference token|saved card|stored card/', $g)) {
      return $ts('The card we have on file for you could not be used. Please update your card details.');
    }
    if (preg_match('/invalid card|card number|luhn|not between|card type|unsupported card|not accepted/', $g)) {
      return $ts('The card number is not valid or this card type is not accepted. Please check the card number and try again.');
    }
    if (preg_match('/duplicate/', $g)) {
      return $ts('This looks like a duplicate of a payment made a moment ago, so it was not charged again. Please check your email for a receipt before trying again.');
    }
    if (preg_match('/declin|not approved|call|referral|reject/', $g) || $resultCode === 'D') {
      return $ts('Your card was declined. Please check the card details, try a different card, or contact your bank.');
    }
    if (preg_match('/timeout|timed out|unavailable|try again|processor error|gateway error|connection|network|busy|system error/', $g)) {
      return $ts('The payment could not be completed because the card processor did not respond. Please wait a moment and try again. If the problem continues, contact us.');
    }
    if (preg_match('/authenticat|password|api key|source key|not allowed|permission|merchant|disabled|pin/', $g)) {
      return $ts('The payment system is not configured correctly, so no charge was made. Please contact us so we can fix it.');
    }
    if (preg_match('/amount|currency/', $g)) {
      return $ts('The payment amount could not be processed. Please contact us.');
    }
    return $ts('The payment could not be processed. Please check the card details and try again, or contact us for help.');
  }

}
