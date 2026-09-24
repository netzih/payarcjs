<?php

use CRM_Payarcjs_ExtensionUtil as E;

/**
 * Imports PayArc transactions that were not made through CiviCRM (a
 * WooCommerce or Gravity Forms store on the same merchant account, for
 * example) as CiviCRM contributions, and applies refunds and voids of those
 * imported sales.
 *
 * The transaction list is read newest first (the endpoint has no filters), a
 * sale whose key is already a CiviCRM transaction ID is skipped, and only
 * sales from the configured source keys are imported. Contacts are matched
 * with the Individual Unsupervised dedupe rule on the billing name and email,
 * and created when nothing matches.
 */
class CRM_Payarcjs_TransactionImporter {

  public const ALL_SOURCES = '*';

  public const SOURCE_PREFIX = 'PayArc import: ';

  private CRM_Core_Payment_Payarcjs $processor;

  /**
   * Lower-cased source key names, or [] with $allSources.
   */
  private array $sourceNames;

  private bool $allSources;

  private int $financialTypeID;

  private string $currency;

  private bool $dryRun;

  private array $summary = [];

  private ?DateTimeZone $gatewayTimezone = NULL;

  /**
   * @param array $options
   *   source_names (array|string), financial_type_id, currency, dry_run.
   */
  public function __construct(CRM_Core_Payment_Payarcjs $processor, array $options = []) {
    $this->processor = $processor;

    $names = $options['source_names'] ?? '';
    if (is_string($names)) {
      $names = preg_split('/[,\n]/', $names) ?: [];
    }
    $names = array_values(array_filter(array_map(static fn($n) => strtolower(trim((string) $n)), $names), static fn($n) => $n !== ''));
    $this->allSources = in_array(self::ALL_SOURCES, $names, TRUE);
    $this->sourceNames = array_diff($names, [self::ALL_SOURCES]);

    $this->financialTypeID = (int) ($options['financial_type_id'] ?? 0);
    if (!$this->financialTypeID) {
      $this->financialTypeID = (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Donation');
    }
    $currency = strtoupper(trim((string) ($options['currency'] ?? 'USD')));
    $this->currency = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    $this->dryRun = !empty($options['dry_run']);
  }

  /**
   * Build from the extension settings, with optional overrides (API params).
   */
  public static function fromSettings(CRM_Core_Payment_Payarcjs $processor, array $overrides = []): self {
    $options = [
      'source_names' => Civi::settings()->get('payarcjs_import_source_names'),
      'financial_type_id' => Civi::settings()->get('payarcjs_import_financial_type_id'),
      'currency' => Civi::settings()->get('payarcjs_import_currency'),
    ];
    foreach ($overrides as $key => $value) {
      if ($value !== NULL && $value !== '') {
        $options[$key] = $value;
      }
    }
    return new self($processor, $options);
  }

  public function isConfigured(): bool {
    return $this->allSources || $this->sourceNames;
  }

  /**
   * Import everything created between $from and $to (inclusive, PayArc's
   * clock). Returns a summary; nothing is written in dry-run mode.
   */
  public function run(DateTimeImmutable $from, ?DateTimeImmutable $to = NULL, int $maxPages = 50): array {
    $this->summary = [
      'dry_run' => $this->dryRun,
      'from' => $from->format('Y-m-d H:i:s'),
      'to' => $to ? $to->format('Y-m-d H:i:s') : NULL,
      'source_names' => $this->allSources ? self::ALL_SOURCES : implode(', ', $this->sourceNames),
      'gateway_timezone' => NULL,
      'pages' => 0,
      'rows_seen' => 0,
      'sources_seen' => [],
      'imported' => [],
      'refunds_applied' => [],
      'voids_applied' => [],
      'contacts_created' => 0,
      'skipped_known' => 0,
      'skipped_source' => 0,
      'skipped_type' => 0,
      'unmatched_refunds' => [],
      'errors' => [],
    ];
    if (!$this->isConfigured()) {
      $this->summary['message'] = E::ts('No PayArc source keys are configured for import (Administer > System Settings > PayArc Pay.js Settings).');
      return $this->summary;
    }

    $this->summary['gateway_timezone'] = $this->gatewayTimezone()->getName();

    $pageSize = 100;
    $reachedStart = FALSE;
    $refunds = [];
    for ($page = 0; $page < max(1, $maxPages) && !$reachedStart; $page++) {
      $rows = $this->processor->listGatewayTransactions($pageSize, $page * $pageSize);
      $this->summary['pages']++;
      foreach ($rows as $row) {
        $created = $this->createdAt($row);
        if ($created && $created < $from) {
          $reachedStart = TRUE;
          break;
        }
        if ($to && $created && $created > $to) {
          continue;
        }
        $this->summary['rows_seen']++;
        $seenSource = (string) ($row['source_name'] ?? '');
        $this->summary['sources_seen'][$seenSource] = ($this->summary['sources_seen'][$seenSource] ?? 0) + 1;
        try {
          switch (self::classify($row)) {
            case 'sale':
              $this->importSale($row);
              break;

            case 'void':
              $this->applyVoid($row);
              break;

            case 'refund':
              // Refunds are matched after all sales in the window are known.
              $refunds[] = $row;
              break;

            default:
              $this->summary['skipped_type']++;
          }
        }
        catch (Throwable $e) {
          $this->summary['errors'][] = ['key' => $row['key'] ?? '', 'error' => $e->getMessage()];
          Civi::log(E::SHORT_NAME)->error(sprintf('PayArc import failed for transaction %s: %s', $row['key'] ?? '', $e->getMessage()));
        }
      }
      if (count($rows) < $pageSize) {
        break;
      }
    }

    foreach ($refunds as $row) {
      try {
        $this->applyRefund($row);
      }
      catch (Throwable $e) {
        $this->summary['errors'][] = ['key' => $row['key'] ?? '', 'error' => $e->getMessage()];
      }
    }

    // The commonest misconfiguration: the key itself, or a misspelt name,
    // instead of the key's name as PayArc reports it.
    if (!$this->allSources && $this->summary['rows_seen'] > 0 && $this->summary['skipped_source'] > 0 && !$this->summary['imported'] && !$this->summary['skipped_known']) {
      $seen = array_keys($this->summary['sources_seen']);
      $this->summary['message'] = E::ts('No transactions matched the configured source key name(s) "%1". In this window PayArc reported these source names: %2. Enter the name of the key as shown in PayArc\'s "Source" column, not the key itself.', [
        1 => implode(', ', $this->sourceNames),
        2 => $seen ? implode(', ', array_map(static fn($n) => '"' . $n . '"', $seen)) : E::ts('(none)'),
      ]);
    }

    return $this->summary;
  }

  /**
   * What a transaction list row represents for the import.
   *
   * @return string
   *   'sale' (approved card sale), 'void' (a sale that was voided), 'refund'
   *   (approved credit back to the card) or 'ignore' (declines, errors,
   *   authorizations, anything else).
   */
  public static function classify(array $row): string {
    if (($row['result_code'] ?? '') !== 'A') {
      return 'ignore';
    }
    $type = strtoupper((string) ($row['trantype_code'] ?? ''));
    $status = strtoupper((string) ($row['status_code'] ?? ''));
    if ($type === 'V' || ($type === 'S' && $status === 'V')) {
      return 'void';
    }
    if ($type === 'S') {
      return 'sale';
    }
    if ($type === 'C') {
      return 'refund';
    }
    return 'ignore';
  }

  /**
   * Pick the single imported sale a refund belongs to. PayArc refund rows do
   * not reference the original transaction; they carry the same invoice
   * number, so candidates are matched on invoice, then narrowed by the card's
   * last four digits and the amount still refundable. Anything but exactly
   * one match returns NULL.
   *
   * @param array $refund
   *   Row with 'amount', 'invoice' and optionally creditcard.number.
   * @param array[] $candidates
   *   Each with 'invoice_number', 'total_amount', 'refunded_amount' and
   *   optionally 'pan_truncation'.
   */
  public static function matchRefund(array $refund, array $candidates): ?array {
    $invoice = strtolower(trim((string) ($refund['invoice'] ?? '')));
    if ($invoice === '') {
      return NULL;
    }
    $amount = (float) ($refund['amount'] ?? 0);
    $last4 = self::lastFour((string) ($refund['creditcard']['number'] ?? ''));

    $matches = [];
    foreach ($candidates as $candidate) {
      if (strtolower(trim((string) ($candidate['invoice_number'] ?? ''))) !== $invoice) {
        continue;
      }
      $remaining = (float) ($candidate['total_amount'] ?? 0) - (float) ($candidate['refunded_amount'] ?? 0);
      if ($amount > $remaining + 0.005) {
        continue;
      }
      $candidateLast4 = self::lastFour((string) ($candidate['pan_truncation'] ?? ''));
      if ($last4 !== '' && $candidateLast4 !== '' && $last4 !== $candidateLast4) {
        continue;
      }
      $matches[] = $candidate;
    }
    return count($matches) === 1 ? $matches[0] : NULL;
  }

  private static function lastFour(string $masked): string {
    return preg_match('/(\d{4})\D*$/', $masked, $m) ? $m[1] : '';
  }

  /**
   * A row's creation time as a site-time value. PayArc stamps 'created' in
   * the merchant account's time zone with no offset, so it is read in the
   * gateway zone and converted.
   */
  private function createdAt(array $row): ?DateTimeImmutable {
    try {
      if (empty($row['created'])) {
        return NULL;
      }
      return (new DateTimeImmutable((string) $row['created'], $this->gatewayTimezone()))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
    catch (Throwable $e) {
      return NULL;
    }
  }

  private function siteTime(array $row): string {
    $created = $this->createdAt($row);
    return $created ? $created->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
  }

  /**
   * The zone PayArc uses for 'created'. Worked out by comparing a
   * transaction CiviCRM made itself (receive_date is site time) with PayArc's
   * timestamp for it, rounded to a quarter hour; the configured zone is the
   * fallback when there is nothing to compare.
   */
  private function gatewayTimezone(): DateTimeZone {
    if ($this->gatewayTimezone) {
      return $this->gatewayTimezone;
    }
    $fallback = (string) (Civi::settings()->get('payarcjs_import_timezone') ?: 'America/Los_Angeles');
    try {
      $this->gatewayTimezone = new DateTimeZone($fallback);
    }
    catch (Throwable $e) {
      $this->gatewayTimezone = new DateTimeZone('America/Los_Angeles');
    }

    try {
      $reference = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('trxn_id', 'receive_date')
        ->addJoin('EntityFinancialTrxn AS eft', 'INNER', ['eft.entity_id', '=', 'id'], ['eft.entity_table', '=', '"civicrm_contribution"'])
        ->addJoin('FinancialTrxn AS ft', 'INNER', ['ft.id', '=', 'eft.financial_trxn_id'])
        ->addWhere('ft.payment_processor_id', '=', $this->processor->getID())
        ->addWhere('ft.is_payment', '=', TRUE)
        ->addWhere('trxn_id', 'IS NOT EMPTY')
        ->addWhere('source', 'NOT LIKE', self::SOURCE_PREFIX . '%')
        ->addWhere('is_test', 'IN', [TRUE, FALSE])
        ->addOrderBy('receive_date', 'DESC')
        ->setLimit(1)
        ->execute()
        ->first();
      if ($reference && preg_match('/^[a-z0-9]{10,}$/i', (string) $reference['trxn_id'])) {
        $detail = $this->processor->getGatewayTransaction((string) $reference['trxn_id']);
        if (!empty($detail['created'])) {
          $site = new DateTimeImmutable((string) $reference['receive_date'], new DateTimeZone(date_default_timezone_get()));
          $gatewayAsUtc = new DateTimeImmutable((string) $detail['created'], new DateTimeZone('UTC'));
          // Reading the gateway's local stamp as UTC and subtracting the true
          // instant gives the gateway zone's offset east of UTC.
          $this->gatewayTimezone = self::offsetTimezone($gatewayAsUtc->getTimestamp() - $site->getTimestamp());
        }
      }
    }
    catch (Throwable $e) {
      Civi::log(E::SHORT_NAME)->warning(sprintf('Could not work out the PayArc time zone; using %s: %s', $this->gatewayTimezone->getName(), $e->getMessage()));
    }
    return $this->gatewayTimezone;
  }

  /**
   * A fixed-offset zone from seconds east of UTC, rounded to 15 minutes.
   */
  public static function offsetTimezone(int $secondsEastOfUtc): DateTimeZone {
    $rounded = (int) round($secondsEastOfUtc / 900) * 900;
    $rounded = max(-14 * 3600, min(14 * 3600, $rounded));
    $sign = $rounded < 0 ? '-' : '+';
    $abs = abs($rounded);
    return new DateTimeZone(sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60)));
  }

  private function sourceAllowed(array $row): bool {
    if ($this->allSources) {
      return TRUE;
    }
    return in_array(strtolower(trim((string) ($row['source_name'] ?? ''))), $this->sourceNames, TRUE);
  }

  private function importSale(array $row): void {
    $key = (string) ($row['key'] ?? '');
    if ($key === '') {
      $this->summary['skipped_type']++;
      return;
    }
    if (!$this->sourceAllowed($row)) {
      $this->summary['skipped_source']++;
      return;
    }
    if ($this->transactionKnown($key)) {
      $this->summary['skipped_known']++;
      return;
    }

    // The list row has no billing address; the detail does.
    $detail = $this->processor->getGatewayTransaction($key);
    $billing = is_array($detail['billing_address'] ?? NULL) ? $detail['billing_address'] : [];
    $identity = $this->identityFrom($billing, $detail);

    $entry = [
      'key' => $key,
      'created' => $row['created'] ?? '',
      'amount' => $row['amount'] ?? '',
      'source_name' => $row['source_name'] ?? '',
      'invoice' => $row['invoice'] ?? '',
      'orderid' => $row['orderid'] ?? '',
      'contact' => $identity['display'],
    ];
    if ($this->dryRun) {
      $entry['contact_id'] = $this->findContact($identity, $billing) ?: 'new';
      $this->summary['imported'][] = $entry;
      return;
    }

    $contactID = $this->findContact($identity, $billing);
    if (!$contactID) {
      $contactID = $this->createContact($identity, $billing);
      $this->summary['contacts_created']++;
    }

    $noteLines = [
      E::ts('Imported from PayArc.'),
      E::ts('Source key: %1', [1 => $row['source_name'] ?? '']),
      E::ts('Transaction key: %1, ref %2', [1 => $key, 2 => $row['refnum'] ?? '']),
    ];
    foreach (['orderid' => E::ts('Order ID'), 'invoice' => E::ts('Invoice'), 'custid' => E::ts('Customer ID'), 'description' => E::ts('Description')] as $field => $label) {
      if (!empty($detail[$field])) {
        $noteLines[] = $label . ': ' . $detail[$field];
      }
    }

    $params = [
      'contact_id' => $contactID,
      'financial_type_id' => $this->financialTypeID,
      'total_amount' => (string) $row['amount'],
      'currency' => $this->currency,
      'receive_date' => $this->siteTime($row),
      'trxn_id' => $key,
      'contribution_status_id' => 'Completed',
      'payment_instrument_id' => 'Credit Card',
      // civicrm_contribution.payment_processor: lets staff refund an imported
      // sale from CiviCRM through this processor.
      'payment_processor' => $this->processor->getID(),
      'is_test' => !empty($this->processor->getPaymentProcessor()['is_test']) ? 1 : 0,
      'source' => self::SOURCE_PREFIX . ($row['source_name'] ?? ''),
      'note' => implode("\n", $noteLines),
    ];
    if (!empty($row['invoice'])) {
      $params['invoice_number'] = (string) $row['invoice'];
    }
    $params += $this->processor->cardDetailsForCivi($detail + $row);

    $created = civicrm_api3('Contribution', 'create', $params);
    $entry['contact_id'] = $contactID;
    $entry['contribution_id'] = (int) $created['id'];
    $this->summary['imported'][] = $entry;
  }

  private function applyVoid(array $row): void {
    $key = (string) ($row['key'] ?? '');
    $contribution = $key !== '' ? $this->importedContribution($key) : NULL;
    if (!$contribution) {
      // Not an imported sale (or CiviCRM voided it itself); nothing to do.
      $this->summary['skipped_type']++;
      return;
    }
    if ($this->transactionKnown($key . ':void') || (float) $contribution['refunded_amount'] > 0) {
      $this->summary['skipped_known']++;
      return;
    }
    $entry = ['key' => $key, 'contribution_id' => $contribution['id'], 'amount' => $contribution['total_amount']];
    if (!$this->dryRun) {
      $this->recordRefund($contribution, (float) $contribution['total_amount'], $key . ':void', $this->siteTime($row), E::ts('Voided at PayArc (transaction %1); recorded by the PayArc import.', [1 => $key]));
    }
    $this->summary['voids_applied'][] = $entry;
  }

  private function applyRefund(array $row): void {
    $key = (string) ($row['key'] ?? '');
    if ($key === '' || $this->transactionKnown($key)) {
      // Refunds issued from CiviCRM already carry this key.
      $this->summary['skipped_known']++;
      return;
    }
    $invoice = trim((string) ($row['invoice'] ?? ''));
    $candidates = $invoice !== '' ? $this->importedContributionsByInvoice($invoice) : [];
    $match = self::matchRefund($row, $candidates);
    if (!$match) {
      $this->summary['unmatched_refunds'][] = [
        'key' => $key,
        'created' => $row['created'] ?? '',
        'amount' => $row['amount'] ?? '',
        'invoice' => $invoice,
        'source_name' => $row['source_name'] ?? '',
        'candidates' => count($candidates),
      ];
      return;
    }
    $entry = ['key' => $key, 'contribution_id' => $match['id'], 'amount' => $row['amount'] ?? ''];
    if (!$this->dryRun) {
      $this->recordRefund($match, (float) $row['amount'], $key, $this->siteTime($row), E::ts('Refund %1 issued at PayArc (source key %2); recorded by the PayArc import.', [1 => CRM_Utils_Money::format((float) $row['amount'], $this->currency), 2 => $row['source_name'] ?? '']));
    }
    $this->summary['refunds_applied'][] = $entry;
  }

  private function recordRefund(array $contribution, float $amount, string $trxnID, string $date, string $note): void {
    civicrm_api3('Payment', 'create', [
      'contribution_id' => $contribution['id'],
      'total_amount' => -abs($amount),
      'trxn_id' => $trxnID,
      'trxn_date' => $date,
      'payment_processor_id' => $this->processor->getID(),
      'payment_instrument_id' => 'Credit Card',
      'is_send_contribution_notification' => FALSE,
    ]);
    civicrm_api3('Note', 'create', [
      'entity_table' => 'civicrm_contribution',
      'entity_id' => $contribution['id'],
      'subject' => E::ts('PayArc import'),
      'note' => $note,
    ]);
  }

  /**
   * Is this PayArc key already recorded as a contribution or payment?
   */
  private function transactionKnown(string $key): bool {
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->selectRowCount()
      ->addWhere('trxn_id', '=', $key)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->count();
    if ($contribution) {
      return TRUE;
    }
    return (bool) \Civi\Api4\FinancialTrxn::get(FALSE)
      ->selectRowCount()
      ->addWhere('trxn_id', '=', $key)
      ->execute()
      ->count();
  }

  /**
   * The contribution recorded for a PayArc key (imported or made by CiviCRM),
   * with the amount already refunded. A sale voided in the PayArc console is
   * applied whichever system recorded it; refunds are matched to imported
   * sales only, by invoice number.
   */
  private function importedContribution(string $key): ?array {
    // Core appends refund IDs to contribution.trxn_id ("sale,refund"), so the
    // sale is found through its payment record rather than that field.
    $payment = \Civi\Api4\EntityFinancialTrxn::get(FALSE)
      ->addSelect('entity_id')
      ->addJoin('FinancialTrxn AS ft', 'INNER', ['ft.id', '=', 'financial_trxn_id'])
      ->addWhere('entity_table', '=', 'civicrm_contribution')
      ->addWhere('ft.trxn_id', '=', $key)
      ->addWhere('ft.is_payment', '=', TRUE)
      ->addWhere('ft.total_amount', '>', 0)
      ->setLimit(1)
      ->execute()
      ->first();
    if (!$payment) {
      return NULL;
    }
    $rows = $this->contributionsWhere([['id', '=', (int) $payment['entity_id']]]);
    return $rows[0] ?? NULL;
  }

  private function importedContributionsByInvoice(string $invoice): array {
    return $this->contributionsWhere([
      ['invoice_number', '=', $invoice],
      ['source', 'LIKE', self::SOURCE_PREFIX . '%'],
    ]);
  }

  private function contributionsWhere(array $clauses): array {
    $get = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id', 'contact_id', 'total_amount', 'invoice_number', 'source', 'contribution_status_id:name', 'trxn_id')
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->setLimit(25);
    foreach ($clauses as $clause) {
      $get->addWhere(...$clause);
    }
    $rows = [];
    foreach ($get->execute() as $contribution) {
      $payments = \Civi\Api4\FinancialTrxn::get(FALSE)
        ->addSelect('total_amount', 'pan_truncation')
        ->addJoin('EntityFinancialTrxn AS eft', 'INNER', ['eft.financial_trxn_id', '=', 'id'])
        ->addWhere('eft.entity_table', '=', 'civicrm_contribution')
        ->addWhere('eft.entity_id', '=', $contribution['id'])
        ->addWhere('is_payment', '=', TRUE)
        ->execute();
      $refunded = 0.0;
      $pan = '';
      foreach ($payments as $payment) {
        if ((float) $payment['total_amount'] < 0) {
          $refunded += -(float) $payment['total_amount'];
        }
        elseif (!empty($payment['pan_truncation'])) {
          $pan = (string) $payment['pan_truncation'];
        }
      }
      $contribution['refunded_amount'] = $refunded;
      $contribution['pan_truncation'] = $pan;
      $rows[] = $contribution;
    }
    return $rows;
  }

  /**
   * Who paid, from the transaction detail. PayArc returns the billing name
   * as first_name/last_name (it accepts firstname/lastname on input) and only
   * returns an email when the sale carried one at the top level; failing
   * that, the customer record (custkey) is consulted.
   *
   * @return array{first_name: string, last_name: string, email: string, display: string}
   */
  private function identityFrom(array $billing, array $detail): array {
    $first = trim((string) ($billing['first_name'] ?? $billing['firstname'] ?? ''));
    $last = trim((string) ($billing['last_name'] ?? $billing['lastname'] ?? ''));
    if ($first === '' && $last === '') {
      $cardholder = trim((string) ($detail['creditcard']['cardholder'] ?? ''));
      if ($cardholder !== '') {
        $parts = preg_split('/\s+/', $cardholder);
        $last = array_pop($parts);
        $first = implode(' ', $parts);
      }
    }
    // PayArc does not return the payer's email on a transaction, even when
    // one was sent. A store plugin can pass it as custid; customer records
    // (custkey) are the other source.
    $email = '';
    foreach ([$detail['email'] ?? '', $billing['email'] ?? '', $detail['customer']['email'] ?? '', $detail['custid'] ?? ''] as $candidate) {
      $candidate = strtolower(trim((string) $candidate));
      if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
        $email = $candidate;
        break;
      }
    }
    if ($email === '' && !empty($detail['custkey'])) {
      try {
        $customer = $this->processor->getGatewayCustomer((string) $detail['custkey']);
        $candidate = strtolower(trim((string) ($customer['email'] ?? '')));
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
          $email = $candidate;
        }
        if ($first === '' && $last === '') {
          $first = trim((string) ($customer['first_name'] ?? ''));
          $last = trim((string) ($customer['last_name'] ?? ''));
        }
      }
      catch (Throwable $e) {
        // No customer record or no permission to read it; carry on without.
      }
    }
    $display = trim($first . ' ' . $last);
    if ($email !== '') {
      $display .= ($display !== '' ? ' ' : '') . '<' . $email . '>';
    }
    return ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'display' => $display];
  }

  private function findContact(array $identity, array $billing): ?int {
    if ($identity['email'] === '' && $identity['first_name'] === '' && $identity['last_name'] === '') {
      return NULL;
    }
    $values = ['contact_type' => 'Individual'];
    foreach (['first_name', 'last_name'] as $field) {
      if ($identity[$field] !== '') {
        $values[$field] = $identity[$field];
      }
    }
    if ($identity['email'] !== '') {
      $values['email_primary.email'] = $identity['email'];
    }
    try {
      $duplicates = \Civi\Api4\Contact::getDuplicates(FALSE)
        ->setDedupeRule('Individual.Unsupervised')
        ->setValues($values)
        ->execute();
      if (count($duplicates)) {
        return (int) $duplicates->first()['id'];
      }
    }
    catch (Throwable $e) {
      Civi::log(E::SHORT_NAME)->warning('Dedupe lookup failed during PayArc import: ' . $e->getMessage());
    }
    if ($identity['email'] !== '') {
      $email = \Civi\Api4\Email::get(FALSE)
        ->addSelect('contact_id')
        ->addWhere('email', '=', $identity['email'])
        ->addWhere('contact_id.is_deleted', '=', FALSE)
        ->addOrderBy('is_primary', 'DESC')
        ->setLimit(1)
        ->execute()
        ->first();
      if ($email) {
        return (int) $email['contact_id'];
      }
    }
    return $this->findContactByName($identity, $billing);
  }

  /**
   * Without an email the only handle is the billing name. An exact first and
   * last name match is used when it is unique; several namesakes are told
   * apart by postal code, then by having been created by this import.
   * Anything still ambiguous creates a new contact rather than guess.
   */
  private function findContactByName(array $identity, array $billing): ?int {
    if ($identity['first_name'] === '' || $identity['last_name'] === '') {
      return NULL;
    }
    $candidates = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('id', 'source', 'address_primary.postal_code')
      ->addWhere('contact_type', '=', 'Individual')
      ->addWhere('is_deleted', '=', FALSE)
      ->addWhere('first_name', '=', $identity['first_name'])
      ->addWhere('last_name', '=', $identity['last_name'])
      ->setLimit(20)
      ->execute()
      ->getArrayCopy();
    if (count($candidates) === 1) {
      return (int) $candidates[0]['id'];
    }
    if (!$candidates) {
      return NULL;
    }
    $postal = preg_replace('/\s+/', '', strtolower((string) ($billing['postalcode'] ?? '')));
    if ($postal !== '') {
      $samePostal = array_values(array_filter($candidates, static fn($c) => preg_replace('/\s+/', '', strtolower((string) ($c['address_primary.postal_code'] ?? ''))) === $postal));
      if (count($samePostal) === 1) {
        return (int) $samePostal[0]['id'];
      }
    }
    $imported = array_values(array_filter($candidates, static fn($c) => ($c['source'] ?? '') === E::ts('PayArc import')));
    return count($imported) === 1 ? (int) $imported[0]['id'] : NULL;
  }

  private function createContact(array $identity, array $billing): int {
    $params = [
      'contact_type' => 'Individual',
      'source' => E::ts('PayArc import'),
    ];
    if ($identity['first_name'] !== '') {
      $params['first_name'] = $identity['first_name'];
    }
    if ($identity['last_name'] !== '') {
      $params['last_name'] = $identity['last_name'];
    }
    if ($identity['first_name'] === '' && $identity['last_name'] === '' && $identity['email'] === '') {
      $params['first_name'] = 'PayArc';
      $params['last_name'] = E::ts('Customer');
    }
    $contactID = (int) civicrm_api3('Contact', 'create', $params)['id'];

    // Contact details are best effort: a bad address must not lose the gift.
    $details = [];
    if ($identity['email'] !== '') {
      $details['Email'] = ['email' => $identity['email'], 'is_primary' => 1, 'location_type_id' => 'Billing'];
    }
    $address = array_filter([
      'street_address' => $billing['street'] ?? '',
      'supplemental_address_1' => $billing['street2'] ?? '',
      'city' => $billing['city'] ?? '',
      'postal_code' => $billing['postalcode'] ?? '',
    ]);
    if ($address) {
      $countryID = $this->countryID((string) ($billing['country'] ?? ''));
      if ($countryID) {
        $address['country_id'] = $countryID;
      }
      $stateID = $this->stateProvinceID((string) ($billing['state'] ?? ''), $countryID);
      if ($stateID) {
        $address['state_province_id'] = $stateID;
      }
      $details['Address'] = $address + ['location_type_id' => 'Billing', 'is_billing' => 1, 'is_primary' => 1];
    }
    $phone = trim((string) ($billing['phone'] ?? ''));
    if ($phone !== '') {
      $details['Phone'] = ['phone' => $phone, 'location_type_id' => 'Billing', 'is_primary' => 1];
    }
    foreach ($details as $entity => $values) {
      try {
        civicrm_api3($entity, 'create', $values + ['contact_id' => $contactID]);
      }
      catch (Throwable $e) {
        Civi::log(E::SHORT_NAME)->warning(sprintf('PayArc import: could not add %s to new contact %d: %s', $entity, $contactID, $e->getMessage()));
      }
    }
    return $contactID;
  }

  private function countryID(string $country): ?int {
    $alpha2 = CRM_Payarcjs_Country::alpha2($country);
    if ($alpha2 === '') {
      return NULL;
    }
    $row = \Civi\Api4\Country::get(FALSE)->addSelect('id')->addWhere('iso_code', '=', $alpha2)->execute()->first();
    return $row ? (int) $row['id'] : NULL;
  }

  private function stateProvinceID(string $state, ?int $countryID): ?int {
    $state = trim($state);
    if ($state === '') {
      return NULL;
    }
    $get = \Civi\Api4\StateProvince::get(FALSE)->addSelect('id')->setLimit(1);
    $get->addWhere(strlen($state) <= 3 ? 'abbreviation' : 'name', '=', $state);
    if ($countryID) {
      $get->addWhere('country_id', '=', $countryID);
    }
    $row = $get->execute()->first();
    return $row ? (int) $row['id'] : NULL;
  }

}
