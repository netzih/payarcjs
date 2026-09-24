<?php
// Shared helpers for the manual sandbox scripts. Requires a booted CiviCRM (cv scr).

function payarcjs_sandbox_env(): void {
  $file = getenv('HOME') . '/.config/payarcjs/sandbox.env';
  if (!is_readable($file)) {
    throw new RuntimeException("Missing $file");
  }
  foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line[0] !== '#' && str_contains($line, '=')) {
      [$k, $v] = explode('=', $line, 2);
      putenv("$k=$v");
    }
  }
}

/**
 * Mint a Pay.js payment key server-side, as the card iframe does.
 */
function payarcjs_mint(string $number = '4000100011112224'): string {
  $ch = curl_init('https://sandbox.payarc.com/api/v2/pub/payment_keys');
  curl_setopt_array($ch, [
    CURLOPT_POST => TRUE,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode(getenv('PAYARC_PAYJS_PUBLIC_KEY') . ':s2//')],
    CURLOPT_POSTFIELDS => json_encode(['creditcard' => ['number' => $number, 'expiration' => '1229', 'cvc' => '123']]),
  ]);
  $r = json_decode((string) curl_exec($ch), TRUE);
  if (empty($r['key'])) {
    throw new RuntimeException('Could not mint a payment key: ' . json_encode($r));
  }
  return $r['key'];
}

function payarcjs_test_processor_id(): int {
  $id = \Civi\Api4\PaymentProcessor::get(FALSE)
    ->addSelect('id')
    ->addWhere('payment_processor_type_id:name', '=', 'PayArcHostedFields')
    ->addWhere('is_test', '=', TRUE)
    ->addWhere('is_active', '=', TRUE)
    ->execute()
    ->first()['id'] ?? NULL;
  if (!$id) {
    throw new RuntimeException('No active test-mode PayArc Pay.js processor found.');
  }
  return (int) $id;
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
