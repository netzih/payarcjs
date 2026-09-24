<?php

/**
 * Scheduling rules for CiviCRM-managed recurring contributions.
 *
 * Pure PHP (no CiviCRM calls) so the date arithmetic, retry policy and the
 * deterministic invoice reference can be unit tested.
 */
class CRM_Payarcjs_Schedule {

  /**
   * Conclusive declines are retried this many times before the series stops.
   */
  public const MAX_FAILURES = 3;

  /**
   * Days between retries of a declined installment.
   */
  public const RETRY_DAYS = 3;

  /**
   * How long to wait before re-checking PayArc for an installment whose
   * result was never received (timeout, connection dropped).
   */
  public const RECONCILE_RETRY_MINUTES = 60;

  /**
   * A pending installment with no matching PayArc transaction after this
   * long is treated as never charged and handled as a decline.
   */
  public const RECONCILE_NOT_FOUND_HOURS = 24;

  /**
   * A pending installment that still cannot be reconciled after this long
   * (PayArc unreachable) stops the series for staff review.
   */
  public const RECONCILE_GIVE_UP_DAYS = 7;

  /**
   * Staff-readable, deterministic reference for one charge attempt:
   * payarcjs-{recur id}-{scheduled date}-{attempt number}.
   *
   * The job looks the reference up before charging, so a run that repeats a
   * due date cannot charge the donor twice, and every retry after a decline
   * gets its own attempt number and contribution record.
   */
  public static function invoiceID(int $recurID, string $scheduledDate, int $attempt): string {
    $day = substr(trim($scheduledDate), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
      throw new InvalidArgumentException('Scheduled date must start with YYYY-MM-DD.');
    }
    return 'payarcjs-' . $recurID . '-' . $day . '-' . max(0, $attempt);
  }

  public static function retryDate(DateTimeImmutable $now): string {
    return $now->modify('+' . self::RETRY_DAYS . ' days')->format('Y-m-d H:i:s');
  }

  public static function reconcileRetryDate(DateTimeImmutable $now): string {
    return $now->modify('+' . self::RECONCILE_RETRY_MINUTES . ' minutes')->format('Y-m-d H:i:s');
  }

  /**
   * The next scheduled date after $date, honouring the cycle day for monthly
   * series and clamping to the last day of shorter months.
   */
  public static function nextDate(string $date, int $interval, string $unit, int $cycleDay = 0): string {
    $interval = max(1, $interval);
    $current = new DateTimeImmutable($date);

    if ($unit === 'month') {
      $day = $cycleDay > 0 ? $cycleDay : (int) $current->format('j');
      $target = $current->modify('first day of this month')->modify("+{$interval} months");
      return $target->setDate(
        (int) $target->format('Y'),
        (int) $target->format('n'),
        min($day, (int) $target->format('t'))
      )->format('Y-m-d H:i:s');
    }

    if ($unit === 'year') {
      $targetYear = (int) $current->format('Y') + $interval;
      $month = (int) $current->format('n');
      $lastDay = (int) (new DateTimeImmutable("{$targetYear}-{$month}-01"))->format('t');
      $day = min((int) $current->format('j'), $lastDay);
      return $current->setDate($targetYear, $month, $day)->format('Y-m-d H:i:s');
    }

    if (!in_array($unit, ['day', 'week'], TRUE)) {
      throw new InvalidArgumentException('Unsupported recurring frequency unit: ' . $unit);
    }
    return $current->modify("+{$interval} {$unit}s")->format('Y-m-d H:i:s');
  }

  /**
   * Advance from the scheduled date until the result is in the future. A
   * series that was paused or reactivated resumes with its next future date
   * rather than charging every missed installment in a burst.
   */
  public static function nextFutureDate(string $date, int $interval, string $unit, int $cycleDay, DateTimeImmutable $now): string {
    $next = $date;
    $guard = 0;
    do {
      $next = self::nextDate($next, $interval, $unit, $cycleDay);
    } while (new DateTimeImmutable($next) <= $now && ++$guard < 600);
    return $next;
  }

  /**
   * Last day of the month for a PayArc MMYY expiration, or NULL if unusable.
   */
  public static function expiryDate(string $expiration): ?string {
    $expiration = preg_replace('/\D/', '', $expiration);
    if (strlen($expiration) === 4) {
      $month = (int) substr($expiration, 0, 2);
      $year = 2000 + (int) substr($expiration, 2, 2);
    }
    elseif (strlen($expiration) === 6) {
      $month = (int) substr($expiration, 0, 2);
      $year = (int) substr($expiration, 2, 4);
    }
    else {
      return NULL;
    }
    if ($month < 1 || $month > 12) {
      return NULL;
    }
    return (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('last day of this month')->format('Y-m-d');
  }

}
