<?php

/**
 * Extracts the card brand and last four digits from a PayArc response.
 *
 * Pure PHP so it can be unit tested without CiviCRM.
 */
class CRM_Payarcjs_CardDetails {

  /**
   * Labels used by CiviCRM's accept_creditcard option group.
   */
  private const BRANDS = [
    'visa' => 'Visa',
    'mastercard' => 'MasterCard',
    'master card' => 'MasterCard',
    'mc' => 'MasterCard',
    'amex' => 'Amex',
    'american express' => 'Amex',
    'discover' => 'Discover',
  ];

  /**
   * @return array{brand: ?string, last4: ?string}
   */
  public static function fromResponse(array $response): array {
    $saved = is_array($response['savedcard'] ?? NULL) ? $response['savedcard'] : [];
    $card = is_array($response['creditcard'] ?? NULL) ? $response['creditcard'] : [];

    $masked = (string) ($saved['cardnumber'] ?? $card['number'] ?? $card['cardnumber'] ?? '');
    $last4 = preg_match('/(\d{4})\D*$/', $masked, $m) ? $m[1] : NULL;

    $brand = self::normalizeBrand((string) ($saved['type'] ?? $card['type'] ?? $card['card_type'] ?? ''));
    if ($brand === NULL && $masked !== '') {
      $brand = self::brandFromPrefix($masked);
    }

    return ['brand' => $brand, 'last4' => $last4];
  }

  public static function normalizeBrand(string $brand): ?string {
    $key = strtolower(trim($brand));
    return self::BRANDS[$key] ?? NULL;
  }

  private static function brandFromPrefix(string $number): ?string {
    $digits = preg_replace('/\D/', '', substr($number, 0, 2));
    if ($digits === '' || $digits === NULL) {
      return NULL;
    }
    if ($digits[0] === '4') {
      return 'Visa';
    }
    if ($digits[0] === '5' || (strlen($digits) === 2 && (int) $digits >= 22 && (int) $digits <= 27)) {
      return 'MasterCard';
    }
    if (in_array($digits, ['34', '37'], TRUE)) {
      return 'Amex';
    }
    if ($digits[0] === '6') {
      return 'Discover';
    }
    return NULL;
  }

}
