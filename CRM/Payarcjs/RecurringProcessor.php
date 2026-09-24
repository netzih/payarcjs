<?php

use Civi\Payment\Exception\PaymentProcessorException;
use CRM_Payarcjs_ExtensionUtil as E;
use CRM_Payarcjs_Schedule as Schedule;
use Payarc\Charge;
use Payarc\DonorMessage;

/**
 * Charges due, CiviCRM-managed recurring contributions.
 *
 * A deterministic invoice ID and a pending contribution are written before
 * contacting PayArc. The invoice ID is sent as PayArc's Idempotency-Key. If
 * a worker stops after sending the request but before recording the answer,
 * a later run sends the identical request again (PayArc answers a repeated
 * key with the original charge) or, once the key may have expired, looks the
 * charge up by that reference, and records whatever happened instead of
 * charging again.
 */
class CRM_Payarcjs_RecurringProcessor {

  /**
   * Kept for callers; the policy lives in CRM_Payarcjs_Schedule.
   */
  public const MAX_FAILURES = Schedule::MAX_FAILURES;

  /**
   * Workflow name of the donor notification sent after a conclusive decline.
   * Declared in managed/payarcjs.mgd.php; editable under System Workflow Messages.
   */
  public const FAILED_PAYMENT_WORKFLOW = 'payarcjs_recurring_failed';

  private CRM_Core_Payment_Payarcjs $paymentProcessor;

  private int $processorID;

  public function __construct(CRM_Core_Payment_Payarcjs $paymentProcessor) {
    $this->paymentProcessor = $paymentProcessor;
    $this->processorID = (int) $paymentProcessor->getID();
  }

  public function run(int $limit = 25): array {
    $lock = Civi\Core\Container::singleton()
      ->get('lockManager')
      ->create('worker.payarcjs.' . $this->processorID);

    if (!$lock->isFree() || !$lock->acquire()) {
      return [
        'processed' => 0,
        'message' => E::ts('Another PayArc recurring-payment job is already running.'),
      ];
    }

    $summary = [
      'processed' => 0,
      'succeeded' => 0,
      'failed' => 0,
      'needs_review' => 0,
      'reconciling' => 0,
      'skipped' => 0,
    ];

    try {
      foreach ($this->getDueRecurringContributions($limit) as $recur) {
        ++$summary['processed'];
        try {
          $outcome = $this->processOne($recur);
          ++$summary[$outcome];
        }
        catch (Throwable $e) {
          ++$summary['needs_review'];
          // Nothing was sent to PayArc for this series (the pending
          // contribution is created before any charge, and failures after
          // that are handled in processOne), but leaving it due would make it
          // fail again on every run, so stop it for staff.
          $this->stopSeries((int) $recur['id'], NULL, E::ts('The recurring-payment job could not prepare this installment: %1', [1 => $e->getMessage()]), $e);
        }
      }
    }
    finally {
      $lock->release();
    }

    return $summary;
  }

  private function getDueRecurringContributions(int $limit): array {
    $limit = max(1, min(100, $limit));
    // Only series whose first payment already completed: core moves a recur
    // from Pending to In Progress when the initial contribution completes.
    $inProgress = $this->statusID('In Progress', TRUE);

    $sql = "
      SELECT cr.*
        FROM civicrm_contribution_recur cr
       WHERE cr.payment_processor_id = %1
         AND cr.contribution_status_id = %2
         AND cr.next_sched_contribution_date IS NOT NULL
         AND cr.next_sched_contribution_date <= NOW()
         AND (cr.failure_retry_date IS NULL OR cr.failure_retry_date <= NOW())
         AND cr.payment_token_id IS NOT NULL
       ORDER BY cr.next_sched_contribution_date, cr.id
       LIMIT {$limit}";

    $dao = CRM_Core_DAO::executeQuery($sql, [
      1 => [$this->processorID, 'Integer'],
      2 => [$inProgress, 'Integer'],
    ]);
    $rows = [];
    while ($dao->fetch()) {
      $rows[] = $dao->toArray();
    }
    return $rows;
  }

  private function processOne(array $recur): string {
    $recurID = (int) $recur['id'];
    $scheduledDate = (string) $recur['next_sched_contribution_date'];

    $installments = (int) ($recur['installments'] ?? 0);
    if ($installments > 0 && $this->completedCount($recurID) >= $installments) {
      $this->advanceSchedule($recur);
      return 'skipped';
    }

    // Each retry after a conclusive decline gets its own contribution record,
    // so the invoice ID includes the attempt number.
    $invoiceID = Schedule::invoiceID($recurID, $scheduledDate, (int) ($recur['failure_count'] ?? 0), CRM_Core_Payment_Payarcjs::siteTag());
    $contribution = $this->findAttempt($invoiceID);
    $createdNow = FALSE;

    if (!$contribution) {
      $created = civicrm_api3('Contribution', 'repeattransaction', [
        'contribution_recur_id' => $recurID,
        'contribution_status_id' => $this->statusID('Pending'),
        'total_amount' => $recur['amount'],
        'receive_date' => 'now',
        'is_email_receipt' => 0,
      ]);
      $contributionID = (int) $created['id'];
      civicrm_api3('Contribution', 'create', [
        'id' => $contributionID,
        'invoice_id' => $invoiceID,
      ]);
      $contribution = civicrm_api3('Contribution', 'getsingle', ['id' => $contributionID]);
      $createdNow = TRUE;
    }

    $statusName = CRM_Core_PseudoConstant::getName(
      'CRM_Contribute_BAO_Contribution',
      'contribution_status_id',
      $contribution['contribution_status_id']
    );

    if ($statusName === 'Completed') {
      $this->advanceSchedule($recur);
      return 'succeeded';
    }

    if ($statusName === 'Pending' && !$createdNow) {
      // A previous run sent (or may have sent) this charge and never recorded
      // the answer. Ask PayArc what happened to it.
      return $this->reconcile($recur, $contribution, $invoiceID);
    }

    if (!$this->hasUsableToken($recur)) {
      $this->stopForReview($recurID, $contribution, E::ts('No stored PayArc card reference is available.'));
      return 'needs_review';
    }

    return $this->charge($recur, $contribution, $invoiceID, FALSE);
  }

  /**
   * Charge the stored card for a pending attempt and record the answer.
   *
   * @param bool $isResend
   *   TRUE when this repeats an earlier request whose answer was lost. The
   *   request is identical (same invoice ID, so the same Idempotency-Key, and
   *   the attempt's own amount), and PayArc answers it with the earlier
   *   charge if there was one.
   */
  private function charge(array $recur, array $contribution, string $invoiceID, bool $isResend): string {
    $recurID = (int) $recur['id'];
    $amount = (string) ($contribution['total_amount'] ?? $recur['amount']);
    $paymentParams = [
      'amount' => $amount,
      'currency' => (string) $recur['currency'],
      'contact_id' => (int) $recur['contact_id'],
      'contribution_id' => (int) $contribution['id'],
      'contribution_recur_id' => $recurID,
      'invoice_id' => $invoiceID,
      'payment_token_id' => (int) $recur['payment_token_id'],
      'is_recur' => FALSE,
      'description' => E::ts('Recurring contribution %1', [1 => $recurID]),
    ];

    try {
      $result = $this->paymentProcessor->doPayment($paymentParams);
      if ($isResend) {
        $this->addNote((int) $contribution['id'], E::ts('PayArc reconciliation: the request was sent again with the same reference and PayArc answered with charge %1, which has been recorded.', [1 => $result['trxn_id']]));
      }
      $this->recordPayment($recur, $contribution, (string) $result['trxn_id'], $result);
      return 'succeeded';
    }
    catch (PaymentProcessorException $e) {
      $gatewayMessage = (string) ($e->getErrorData()['payarcjs_gateway_message'] ?? $e->getMessage());
      if ($e->getErrorCode() === 'PAYMENT_AMBIGUOUS') {
        // doPayment() has already sent it twice; the next run settles it.
        $this->deferReconciliation($recur, $contribution, E::ts('PayArc did not give a conclusive answer. The charge will be checked again in about an hour.'), $gatewayMessage);
        return 'reconciling';
      }
      $this->recordFailure($recur, $contribution, $gatewayMessage, $this->donorWording($e));
      return 'failed';
    }
    catch (Throwable $e) {
      // An unexpected failure after the request began is not safe to retry.
      $this->stopForReview($recurID, $contribution, $e->getMessage());
      return 'needs_review';
    }
  }

  /**
   * The donor part of a decline message. The processor appends "Gateway
   * response: ..." in staff sessions, which a job started from the
   * Scheduled Jobs page is; that part is not for the donor email.
   */
  private function donorWording(PaymentProcessorException $e): string {
    $message = $e->getMessage();
    $marker = trim(E::ts('Gateway response: %1', [1 => '']));
    $cut = $marker !== '' ? strpos($message, ' ' . $marker) : FALSE;
    return $cut === FALSE ? $message : substr($message, 0, $cut);
  }

  /**
   * Settle an attempt whose PayArc answer was never received.
   *
   * - While the attempt is younger than Schedule::REPLAY_WINDOW, the
   *   identical request is sent again: PayArc answers a repeated
   *   Idempotency-Key with the original charge, or makes the charge now if
   *   the first request never arrived.
   * - After that (PayArc does not say how long it keeps keys) the charge is
   *   looked up by its reference in the charge list, which is conclusive back
   *   to the send time. Approved: recorded as the payment. Declined or
   *   provably absent: handled like a decline, so the normal retry applies
   *   with a new attempt number and key.
   * - While PayArc cannot be asked, the series stays In Progress and is
   *   checked again after RECONCILE_RETRY_MINUTES, up to
   *   RECONCILE_GIVE_UP_DAYS, after which staff take over.
   */
  private function reconcile(array $recur, array $contribution, string $invoiceID, string $reason = ''): string {
    $recurID = (int) $recur['id'];
    $sentAt = strtotime((string) ($contribution['receive_date'] ?? '')) ?: time();
    $ageSeconds = time() - $sentAt;

    if (Schedule::reconcileStep($ageSeconds) === 'replay' && $this->hasUsableToken($recur)) {
      return $this->charge($recur, $contribution, $invoiceID, TRUE);
    }

    $amount = (string) ($contribution['total_amount'] ?? $recur['amount']);
    try {
      $found = $this->paymentProcessor->findChargeByReference($invoiceID, $sentAt - Schedule::LOOKUP_MARGIN, $amount);
    }
    catch (Throwable $e) {
      if ($ageSeconds >= Schedule::RECONCILE_GIVE_UP_DAYS * 86400) {
        $this->stopForReview($recurID, $contribution, E::ts('PayArc could not be asked about this installment for %1 days: %2', [1 => Schedule::RECONCILE_GIVE_UP_DAYS, 2 => $e->getMessage()]));
        return 'needs_review';
      }
      $this->deferReconciliation($recur, $contribution, E::ts('PayArc could not be asked about this installment (%1). It will be checked again in about an hour.', [1 => $e->getMessage()]), $reason);
      return 'reconciling';
    }

    if ($found === NULL) {
      $gateway = E::ts('No answer was received from PayArc and no charge with reference %1 was found afterwards, so the card was not charged.', [1 => $invoiceID]);
      $this->recordFailure($recur, $contribution, $gateway, DonorMessage::donorText('', 'E0200'));
      return 'failed';
    }

    $chargeID = Charge::id($found);
    switch (Charge::outcome($found)) {
      case Charge::APPROVED:
        if ($chargeID === '') {
          break;
        }
        $this->addNote((int) $contribution['id'], E::ts('PayArc reconciliation: the charge was found approved at PayArc (charge %1) and has been recorded.', [1 => $chargeID]));
        $this->recordPayment($recur, $contribution, $chargeID, $this->paymentProcessor->cardDetailsForCivi($found));
        return 'succeeded';

      case Charge::DECLINED:
        $texts = DonorMessage::fromResponse($found);
        $this->recordFailure($recur, $contribution, E::ts('Reconciled with PayArc: %1', [1 => $texts['gateway']]), $texts['donor']);
        return 'failed';

      case Charge::REVERSED:
        $this->stopForReview($recurID, $contribution, E::ts('PayArc charge %1 for this installment went through but has since been voided or refunded at PayArc. Record it by hand.', [1 => $chargeID]));
        return 'needs_review';
    }
    $this->stopForReview($recurID, $contribution, E::ts('PayArc charge %1 for this installment has status "%2", which does not say whether the card was charged.', [1 => $chargeID, 2 => (string) ($found['status'] ?? '')]));
    return 'needs_review';
  }

  private function deferReconciliation(array $recur, array $contribution, string $message, string $reason): void {
    civicrm_api3('ContributionRecur', 'create', [
      'id' => $recur['id'],
      'failure_retry_date' => Schedule::reconcileRetryDate(new DateTimeImmutable('now')),
    ]);
    $this->addNote((int) $contribution['id'], E::ts('PayArc reconciliation pending: %1', [1 => $message]) . ($reason !== '' ? ' (' . $reason . ')' : ''));
    Civi::log(E::SHORT_NAME)->warning('PayArc installment awaiting reconciliation.', [
      'contribution_recur_id' => $recur['id'],
      'contribution_id' => $contribution['id'],
      'invoice_id' => $contribution['invoice_id'] ?? NULL,
      'reason' => $reason,
    ]);
  }

  /**
   * Record an approved charge against the pending contribution and move the
   * series to its next date.
   */
  private function recordPayment(array $recur, array $contribution, string $transactionKey, array $cardDetails): void {
    $paymentCreate = [
      'contribution_id' => (int) $contribution['id'],
      'total_amount' => $recur['amount'],
      'payment_processor_id' => $this->processorID,
      'trxn_id' => $transactionKey,
      'is_send_contribution_notification' => TRUE,
    ];
    if (!empty($recur['payment_instrument_id'])) {
      $paymentCreate['payment_instrument_id'] = $recur['payment_instrument_id'];
    }
    foreach (['card_type_id', 'pan_truncation'] as $cardField) {
      if (!empty($cardDetails[$cardField])) {
        $paymentCreate[$cardField] = $cardDetails[$cardField];
      }
    }
    civicrm_api3('Payment', 'create', $paymentCreate);
    $this->advanceSchedule($recur);
  }

  /**
   * Both lookups must include test contributions: the test processor's
   * series are test records, and APIv3/APIv4 hide those by default.
   */
  private function findAttempt(string $invoiceID): ?array {
    $found = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id', 'contribution_status_id', 'invoice_id', 'receive_date', 'total_amount')
      ->addWhere('invoice_id', '=', $invoiceID)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->setLimit(1)
      ->execute()
      ->first();
    return $found ?: NULL;
  }

  private function hasUsableToken(array $recur): bool {
    if (empty($recur['payment_token_id'])) {
      return FALSE;
    }
    try {
      $token = civicrm_api3('PaymentToken', 'getvalue', [
        'id' => $recur['payment_token_id'],
        'contact_id' => $recur['contact_id'],
        'payment_processor_id' => $this->processorID,
        'return' => 'token',
      ]);
      return trim((string) $token) !== '';
    }
    catch (CRM_Core_Exception $e) {
      return FALSE;
    }
  }

  /**
   * @param string $message
   *   The gateway's reason, for the staff note and log.
   * @param string $donorMessage
   *   Plain-language wording for the donor email.
   */
  private function recordFailure(array $recur, array $contribution, string $message, string $donorMessage): void {
    civicrm_api3('Contribution', 'create', [
      'id' => $contribution['id'],
      'contribution_status_id' => $this->statusID('Failed'),
    ]);

    // Marking the contribution Failed makes core's
    // ContributionRecur::updateOnNewPayment() push next_sched_contribution_date
    // a whole period ahead, which would silently turn the three-day retry into
    // "next month". Keep the installment's own scheduled date so the retry is
    // due as soon as failure_retry_date passes.
    $scheduledDate = (string) $recur['next_sched_contribution_date'];

    $failureCount = ((int) ($recur['failure_count'] ?? 0)) + 1;
    if ($failureCount >= Schedule::MAX_FAILURES) {
      civicrm_api3('ContributionRecur', 'create', [
        'id' => $recur['id'],
        'failure_count' => $failureCount,
        'failure_retry_date' => '',
        'next_sched_contribution_date' => $scheduledDate,
        'contribution_status_id' => $this->statusID('Failed', TRUE),
      ]);
      $this->addNote((int) $contribution['id'], E::ts('PayArc payment failed (%1): %2. The recurring contribution was stopped after %3 failed attempts.', [
        1 => $failureCount,
        2 => $message,
        3 => Schedule::MAX_FAILURES,
      ]));
      Civi::log(E::SHORT_NAME)->warning('Recurring contribution stopped after repeated declines.', [
        'contribution_recur_id' => $recur['id'],
        'failure_count' => $failureCount,
      ]);
      $this->notifyDonor($recur, $contribution, $donorMessage, $failureCount, NULL);
      return;
    }

    $retryDate = Schedule::retryDate(new DateTimeImmutable('now'));
    civicrm_api3('ContributionRecur', 'create', [
      'id' => $recur['id'],
      'failure_count' => $failureCount,
      'failure_retry_date' => $retryDate,
      'next_sched_contribution_date' => $scheduledDate,
    ]);
    $this->addNote((int) $contribution['id'], E::ts('PayArc payment failed (attempt %1 of %2): %3', [
      1 => $failureCount,
      2 => Schedule::MAX_FAILURES,
      3 => $message,
    ]));
    $this->notifyDonor($recur, $contribution, $donorMessage, $failureCount, $retryDate);
  }

  /**
   * Email the donor about a declined installment using the
   * payarcjs_recurring_failed workflow message. The email includes a
   * checksum-protected link to CiviCRM's update-billing form so the donor can
   * replace the card themselves. Failure to send never affects the payment
   * bookkeeping that already happened.
   *
   * @param string|null $retryDate
   *   Next retry, or NULL when the series has been stopped.
   */
  private function notifyDonor(array $recur, array $contribution, string $declineMessage, int $failureCount, ?string $retryDate): void {
    try {
      $contactID = (int) $recur['contact_id'];
      $email = \Civi\Api4\Email::get(FALSE)
        ->addSelect('email')
        ->addWhere('contact_id', '=', $contactID)
        ->addWhere('on_hold', '=', 0)
        ->addOrderBy('is_primary', 'DESC')
        ->setLimit(1)
        ->execute()
        ->first();
      if (empty($email['email'])) {
        Civi::log(E::SHORT_NAME)->info('No email address for contact {contact}; failed-payment notice not sent.', ['contact' => $contactID]);
        return;
      }

      [$domainName, $domainEmail] = CRM_Core_BAO_Domain::getNameAndEmail();
      $updateBillingUrl = (string) ($this->paymentProcessor->subscriptionURL((int) $recur['id'], 'recur', 'billing') ?? '');

      CRM_Core_BAO_MessageTemplate::sendTemplate([
        'workflow' => self::FAILED_PAYMENT_WORKFLOW,
        'tokenContext' => [
          'contactId' => $contactID,
          'contribution_recurId' => (int) $recur['id'],
          'contributionId' => (int) $contribution['id'],
        ],
        'tplParams' => [
          'declineMessage' => $declineMessage,
          'attempt' => $failureCount,
          'maxAttempts' => Schedule::MAX_FAILURES,
          'isStopped' => $retryDate === NULL,
          'retryDate' => $retryDate ? CRM_Utils_Date::customFormat($retryDate, '%B %E%f, %Y') : '',
          'updateBillingUrl' => $updateBillingUrl,
        ],
        'from' => '"' . $domainName . '" <' . $domainEmail . '>',
        'toName' => CRM_Contact_BAO_Contact::displayName($contactID),
        'toEmail' => $email['email'],
      ]);
    }
    catch (Throwable $e) {
      Civi::log(E::SHORT_NAME)->error('Failed-payment notice could not be sent.', [
        'contribution_recur_id' => $recur['id'] ?? NULL,
        'exception' => $e,
      ]);
    }
  }

  private function stopForReview(int $recurID, array $contribution, string $message): void {
    $this->stopSeries($recurID, $contribution, E::ts('PayArc reconciliation required: %1', [1 => $message]));
  }

  /**
   * Stop a series (status Failed) so it is no longer picked up, and leave the
   * reason where staff will see it: a note on the attempt when one exists,
   * otherwise on the recurring contribution itself, plus the log.
   */
  private function stopSeries(int $recurID, ?array $contribution, string $message, ?Throwable $exception = NULL): void {
    try {
      civicrm_api3('ContributionRecur', 'create', [
        'id' => $recurID,
        'contribution_status_id' => $this->statusID('Failed', TRUE),
        'failure_retry_date' => '',
      ]);
      if ($contribution) {
        $this->addNote((int) $contribution['id'], $message);
      }
      else {
        civicrm_api3('Note', 'create', [
          'entity_table' => 'civicrm_contribution_recur',
          'entity_id' => $recurID,
          'subject' => E::ts('PayArc payment status'),
          'note' => $message,
        ]);
      }
    }
    catch (Throwable $e) {
      Civi::log(E::SHORT_NAME)->critical('Unable to stop the recurring contribution.', [
        'contribution_recur_id' => $recurID,
        'exception' => $e,
      ]);
    }
    Civi::log(E::SHORT_NAME)->critical($message, [
      'contribution_recur_id' => $recurID,
      'contribution_id' => $contribution['id'] ?? NULL,
      'invoice_id' => $contribution['invoice_id'] ?? NULL,
      'exception' => $exception,
    ]);
  }

  private function addNote(int $contributionID, string $message): void {
    civicrm_api3('Note', 'create', [
      'entity_table' => 'civicrm_contribution',
      'entity_id' => $contributionID,
      'subject' => E::ts('PayArc payment status'),
      'note' => $message,
    ]);
  }

  /**
   * Core's ContributionRecur::updateOnNewPayment() also updates status and
   * next_sched_contribution_date when the payment completes, but it counts
   * from the receive date, which drifts when cron runs late. This runs after
   * it and anchors the next date to the scheduled date and cycle day instead.
   */
  private function advanceSchedule(array $recur): void {
    $completedCount = $this->completedCount((int) $recur['id']);
    $installments = (int) ($recur['installments'] ?? 0);

    $values = [
      'id' => $recur['id'],
      'failure_count' => 0,
      'failure_retry_date' => '',
    ];
    if ($installments > 0 && $completedCount >= $installments) {
      $values['contribution_status_id'] = $this->statusID('Completed', TRUE);
      $values['next_sched_contribution_date'] = '';
    }
    else {
      $values['contribution_status_id'] = $this->statusID('In Progress', TRUE);
      $values['next_sched_contribution_date'] = Schedule::nextFutureDate(
        (string) $recur['next_sched_contribution_date'],
        (int) $recur['frequency_interval'],
        (string) $recur['frequency_unit'],
        (int) ($recur['cycle_day'] ?? 0),
        new DateTimeImmutable('now')
      );
    }
    civicrm_api3('ContributionRecur', 'create', $values);
  }

  private function completedCount(int $recurID): int {
    return \Civi\Api4\Contribution::get(FALSE)
      ->selectRowCount()
      ->addWhere('contribution_recur_id', '=', $recurID)
      ->addWhere('contribution_status_id:name', '=', 'Completed')
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->count();
  }

  private function statusID(string $name, bool $forRecur = FALSE): int {
    $bao = $forRecur ? 'CRM_Contribute_BAO_ContributionRecur' : 'CRM_Contribute_BAO_Contribution';
    return (int) CRM_Core_PseudoConstant::getKey($bao, 'contribution_status_id', $name);
  }

}
