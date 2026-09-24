<?php
// Shared helpers for the manual sandbox scripts. Requires a booted CiviCRM (cv scr).

/**
 * The PayArc sandbox credentials of the test-mode processor.
 */
function payarcjs_sandbox_processor(): array {
  $processor = \Civi\Api4\PaymentProcessor::get(FALSE)
    ->addSelect('id', 'signature', 'url_site')
    ->addWhere('payment_processor_type_id:name', '=', 'PayArcHostedFields')
    ->addWhere('is_test', '=', TRUE)
    ->addWhere('is_active', '=', TRUE)
    ->execute()
    ->first();
  if (!$processor) {
    throw new RuntimeException('No active test-mode PayArc Hosted Fields processor found.');
  }
  if (!str_contains((string) $processor['url_site'], 'testapi.')) {
    throw new RuntimeException('Refusing to run: the test processor does not point at the PayArc sandbox.');
  }
  return $processor;
}

function payarcjs_test_processor_id(): int {
  return (int) payarcjs_sandbox_processor()['id'];
}

/**
 * Mint a single-use token server-side for a sandbox test card, as the
 * Hosted Fields do in the browser. TSYS cert cards: Visa 4012000098765439
 * (CVV 999), Mastercard 5146315000000055 (998), Discover 6011000993026909
 * (996). The sandbox now and then declines at random with D2026.
 */
function payarcjs_mint(string $number = '4012000098765439', string $cvv = '999'): string {
  $processor = payarcjs_sandbox_processor();
  $ch = curl_init(rtrim($processor['url_site'], '/') . '/tokens');
  curl_setopt_array($ch, [
    CURLOPT_POST => TRUE,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $processor['signature']],
    CURLOPT_POSTFIELDS => json_encode([
      'card_source' => 'INTERNET',
      'card_number' => $number,
      'exp_month' => '12',
      'exp_year' => '2029',
      'cvv' => $cvv,
      'card_holder_name' => 'Sandbox Tester',
      'zip' => '85284',
    ]),
  ]);
  $r = json_decode((string) curl_exec($ch), TRUE);
  $token = $r['data']['id'] ?? NULL;
  if (!$token) {
    throw new RuntimeException('Could not mint a token: ' . json_encode($r));
  }
  return (string) $token;
}

function payarcjs_out(string $label, $value): void {
  echo $label, ': ', (is_scalar($value) || $value === NULL ? var_export($value, TRUE) : json_encode($value)), "\n";
}

function payarcjs_contributions(int $recurID): array {
  return \Civi\Api4\Contribution::get(FALSE)
    ->addSelect('id', 'contribution_status_id:name', 'total_amount', 'trxn_id', 'invoice_id')
    ->addWhere('contribution_recur_id', '=', $recurID)
    ->addWhere('is_test', 'IN', [TRUE, FALSE])
    ->addOrderBy('id')
    ->execute()
    ->getArrayCopy();
}

function payarcjs_recur_state(int $recurID): array {
  $r = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recurID]);
  return [
    'status' => CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $r['contribution_status_id']),
    'next' => $r['next_sched_contribution_date'] ?? NULL,
    'failure_count' => $r['failure_count'] ?? NULL,
    'retry' => $r['failure_retry_date'] ?? NULL,
    'token_id' => $r['payment_token_id'] ?? NULL,
    'processor_id' => $r['processor_id'] ?? NULL,
  ];
}

function payarcjs_latest_note(int $recurID): ?string {
  return CRM_Core_DAO::singleValueQuery("SELECT n.note FROM civicrm_note n JOIN civicrm_contribution c ON c.id = n.entity_id AND n.entity_table = 'civicrm_contribution' WHERE c.contribution_recur_id = %1 ORDER BY n.id DESC LIMIT 1", [1 => [$recurID, 'Integer']]);
}
