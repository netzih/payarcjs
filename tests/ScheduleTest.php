<?php

use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase {

  public function testInvoiceIDIsReadableAndDeterministic(): void {
    self::assertSame('payarcjs-6-2026-09-10-0', CRM_Payarcjs_Schedule::invoiceID(6, '2026-09-10 00:00:00', 0));
    self::assertSame('payarcjs-6-2026-09-10-2', CRM_Payarcjs_Schedule::invoiceID(6, '2026-09-10 17:45:00', 2));
    self::assertSame(
      CRM_Payarcjs_Schedule::invoiceID(6, '2026-09-10 00:00:00', 0),
      CRM_Payarcjs_Schedule::invoiceID(6, '2026-09-10 00:00:00', 0)
    );
  }

  public function testInvoiceIDCarriesTheSiteTag(): void {
    $tag = CRM_Payarcjs_Schedule::siteTag('site key');
    self::assertMatchesRegularExpression('/^[0-9a-f]{6}$/', $tag);
    self::assertSame($tag, CRM_Payarcjs_Schedule::siteTag('site key'));
    self::assertNotSame($tag, CRM_Payarcjs_Schedule::siteTag('another site'));
    self::assertSame('', CRM_Payarcjs_Schedule::siteTag(''));
    self::assertSame('payarcjs-' . $tag . '-6-2026-09-10-1', CRM_Payarcjs_Schedule::invoiceID(6, '2026-09-10', 1, $tag));
    // Anything but lowercase letters and digits is dropped, so the reference
    // stays a valid Idempotency-Key.
    self::assertSame('payarcjs-ab12-6-2026-09-10-0', CRM_Payarcjs_Schedule::invoiceID(6, '2026-09-10', 0, 'AB-12 '));
  }

  public function testLostAnswersAreReplayedWithinTheHourThenLookedUp(): void {
    self::assertSame('replay', CRM_Payarcjs_Schedule::reconcileStep(0));
    self::assertSame('replay', CRM_Payarcjs_Schedule::reconcileStep(CRM_Payarcjs_Schedule::REPLAY_WINDOW - 1));
    self::assertSame('lookup', CRM_Payarcjs_Schedule::reconcileStep(CRM_Payarcjs_Schedule::REPLAY_WINDOW));
    self::assertSame('lookup', CRM_Payarcjs_Schedule::reconcileStep(3 * 86400));
  }

  public function testInvoiceIDRejectsUnusableDates(): void {
    $this->expectException(InvalidArgumentException::class);
    CRM_Payarcjs_Schedule::invoiceID(6, 'now', 0);
  }

  /**
   * @dataProvider nextDates
   */
  public function testNextDate(string $expected, string $date, int $interval, string $unit, int $cycleDay): void {
    self::assertSame($expected, CRM_Payarcjs_Schedule::nextDate($date, $interval, $unit, $cycleDay));
  }

  public static function nextDates(): array {
    return [
      'daily' => ['2026-09-11 05:00:00', '2026-09-10 05:00:00', 1, 'day', 0],
      'every two weeks' => ['2026-09-24 05:00:00', '2026-09-10 05:00:00', 2, 'week', 0],
      'monthly on cycle day' => ['2026-10-15 09:30:00', '2026-09-15 09:30:00', 1, 'month', 15],
      'monthly clamps to short month' => ['2026-02-28 00:00:00', '2026-01-31 00:00:00', 1, 'month', 31],
      'monthly returns to cycle day after short month' => ['2026-03-31 00:00:00', '2026-02-28 00:00:00', 1, 'month', 31],
      'quarterly' => ['2026-12-01 00:00:00', '2026-09-01 00:00:00', 3, 'month', 1],
      'yearly from leap day' => ['2029-02-28 00:00:00', '2028-02-29 00:00:00', 1, 'year', 0],
      'zero interval treated as one' => ['2026-09-11 00:00:00', '2026-09-10 00:00:00', 0, 'day', 0],
    ];
  }

  public function testNextDateRejectsUnknownUnits(): void {
    $this->expectException(InvalidArgumentException::class);
    CRM_Payarcjs_Schedule::nextDate('2026-09-10 00:00:00', 1, 'fortnight');
  }

  public function testNextFutureDateSkipsMissedInstallments(): void {
    $now = new DateTimeImmutable('2026-09-20 12:00:00');
    self::assertSame('2026-09-21 05:00:00', CRM_Payarcjs_Schedule::nextFutureDate('2026-09-10 05:00:00', 1, 'day', 0, $now));
    self::assertSame('2026-10-15 00:00:00', CRM_Payarcjs_Schedule::nextFutureDate('2026-06-15 00:00:00', 1, 'month', 15, $now));
  }

  public function testRetryPolicy(): void {
    $now = new DateTimeImmutable('2026-09-09 05:05:57');
    self::assertSame(3, CRM_Payarcjs_Schedule::MAX_FAILURES);
    self::assertSame('2026-09-12 05:05:57', CRM_Payarcjs_Schedule::retryDate($now));
    self::assertSame('2026-09-09 06:05:57', CRM_Payarcjs_Schedule::reconcileRetryDate($now));
  }

  public function testExpiryDateFromMonthAndYear(): void {
    self::assertSame('2029-12-31', CRM_Payarcjs_Schedule::expiryDate('12', '2029'));
    self::assertSame('2028-02-29', CRM_Payarcjs_Schedule::expiryDate('2', '28'));
    self::assertSame('2027-06-30', CRM_Payarcjs_Schedule::expiryDate('06', '2027'));
    self::assertNull(CRM_Payarcjs_Schedule::expiryDate(NULL, NULL));
    self::assertNull(CRM_Payarcjs_Schedule::expiryDate('13', '2029'));
    self::assertNull(CRM_Payarcjs_Schedule::expiryDate('12', '229'));
  }

}
