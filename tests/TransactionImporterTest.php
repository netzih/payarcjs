<?php

use PHPUnit\Framework\TestCase;

final class TransactionImporterTest extends TestCase {

  /**
   * @dataProvider rows
   */
  public function testClassify(string $expected, array $row): void {
    self::assertSame($expected, CRM_Payarcjs_TransactionImporter::classify($row));
  }

  public static function rows(): array {
    return [
      'approved sale' => ['sale', ['trantype_code' => 'S', 'result_code' => 'A', 'status_code' => 'P']],
      'settled sale' => ['sale', ['trantype_code' => 'S', 'result_code' => 'A', 'status_code' => 'S']],
      'declined sale' => ['ignore', ['trantype_code' => 'S', 'result_code' => 'D', 'status_code' => 'P']],
      'errored sale' => ['ignore', ['trantype_code' => 'S', 'result_code' => 'E']],
      'voided sale (type V)' => ['void', ['trantype_code' => 'V', 'result_code' => 'A', 'status_code' => 'P']],
      'voided sale (status V)' => ['void', ['trantype_code' => 'S', 'result_code' => 'A', 'status_code' => 'V']],
      'approved refund' => ['refund', ['trantype_code' => 'C', 'result_code' => 'A', 'status_code' => 'S']],
      'failed refund' => ['ignore', ['trantype_code' => 'C', 'result_code' => 'E']],
      'auth only' => ['ignore', ['trantype_code' => 'A', 'result_code' => 'A']],
      'empty row' => ['ignore', []],
    ];
  }

  public function testMatchRefundNeedsExactlyOneCandidateOnInvoice(): void {
    $candidates = [
      ['id' => 1, 'invoice_number' => 'WC-100', 'total_amount' => 50, 'refunded_amount' => 0, 'pan_truncation' => '2224'],
      ['id' => 2, 'invoice_number' => 'WC-101', 'total_amount' => 20, 'refunded_amount' => 0, 'pan_truncation' => '1009'],
    ];
    $match = CRM_Payarcjs_TransactionImporter::matchRefund(['invoice' => 'wc-100', 'amount' => '10.00'], $candidates);
    self::assertSame(1, $match['id']);
  }

  public function testMatchRefundUsesCardAndRemainingAmountToDisambiguate(): void {
    $candidates = [
      ['id' => 1, 'invoice_number' => 'WC-100', 'total_amount' => 50, 'refunded_amount' => 45, 'pan_truncation' => '2224'],
      ['id' => 2, 'invoice_number' => 'WC-100', 'total_amount' => 50, 'refunded_amount' => 0, 'pan_truncation' => '1009'],
    ];
    // Too much for candidate 1 (only 5 left), so candidate 2.
    self::assertSame(2, CRM_Payarcjs_TransactionImporter::matchRefund(['invoice' => 'WC-100', 'amount' => '10.00'], $candidates)['id']);
    // Card narrows it to candidate 1.
    self::assertSame(1, CRM_Payarcjs_TransactionImporter::matchRefund(['invoice' => 'WC-100', 'amount' => '5.00', 'creditcard' => ['number' => '4000xxxxxxxx2224']], $candidates)['id']);
    // Ambiguous: both fit.
    self::assertNull(CRM_Payarcjs_TransactionImporter::matchRefund(['invoice' => 'WC-100', 'amount' => '5.00'], $candidates));
  }

  public function testOffsetTimezoneRoundsToQuarterHours(): void {
    self::assertSame('-07:00', CRM_Payarcjs_TransactionImporter::offsetTimezone(-7 * 3600 + 40)->getName());
    self::assertSame('+05:30', CRM_Payarcjs_TransactionImporter::offsetTimezone(5 * 3600 + 30 * 60 - 200)->getName());
    self::assertSame('+00:00', CRM_Payarcjs_TransactionImporter::offsetTimezone(12)->getName());
  }

  public function testMatchRefundWithoutInvoiceIsUnmatched(): void {
    $candidates = [['id' => 1, 'invoice_number' => '', 'total_amount' => 50, 'refunded_amount' => 0]];
    self::assertNull(CRM_Payarcjs_TransactionImporter::matchRefund(['invoice' => '', 'amount' => '5.00'], $candidates));
  }

}
