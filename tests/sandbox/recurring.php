<?php
// cv scr tests/sandbox/recurring.php
// Recurring signup -> cron charge -> decline with donor email -> card replacement -> cron charge.
require_once __DIR__ . '/lib.php';
payarcjs_sandbox_env();
$processorID = payarcjs_test_processor_id();
$out = 'payarcjs_out';
$cron = fn() => civicrm_api3('Job', 'run_payment_cron', ['processor_name' => 'PayArcHostedFields', 'mode' => 'test']);
// Scheduled dates must differ between runs: the worker derives the invoice ID from them.
$dueAt = fn(int $id, int $secondsAgo) => civicrm_api3('ContributionRecur', 'create', ['id' => $id, 'next_sched_contribution_date' => date('Y-m-d H:i:s', time() - $secondsAgo)]);

echo "== 1. recurring signup\n";
$contactID = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Recur', 'last_name' => 'Tester', 'email' => 'recur@example.org'])['id'];
$recurID = (int) civicrm_api3('ContributionRecur', 'create', ['contact_id' => $contactID, 'amount' => 3.00, 'currency' => 'USD', 'frequency_unit' => 'month', 'frequency_interval' => 1, 'start_date' => date('Y-m-d H:i:s'), 'next_sched_contribution_date' => date('Y-m-d H:i:s'), 'cycle_day' => (int) date('j'), 'contribution_status_id' => 'Pending', 'payment_processor_id' => $processorID, 'is_test' => 1, 'financial_type_id' => 'Donation', 'payment_instrument_id' => 'Credit Card'])['id'];
$order = civicrm_api3('Order', 'create', ['contact_id' => $contactID, 'financial_type_id' => 'Donation', 'total_amount' => 3.00, 'currency' => 'USD', 'payment_processor_id' => $processorID, 'is_test' => 1, 'contribution_recur_id' => $recurID, 'invoice_id' => md5(uniqid()), 'source' => 'sandbox recurring.php']);
$contributionID = (int) $order['id'];
$processor = \Civi\Payment\System::singleton()->getById($processorID);
$params = ['amount' => 3.00, 'currency' => 'USD', 'contact_id' => $contactID, 'contribution_id' => $contributionID, 'contribution_recur_id' => $recurID, 'invoice_id' => $order['values'][$contributionID]['invoice_id'], 'email' => 'recur@example.org', 'first_name' => 'Recur', 'last_name' => 'Tester', 'billing_street_address' => '1 Main St', 'billing_city' => 'Richmond', 'billing_state_province' => 'VA', 'billing_postal_code' => '23220', 'billing_country' => 'US', 'payment_token' => 'payjs:' . payarcjs_mint(), 'is_recur' => 1, 'frequency_unit' => 'month', 'frequency_interval' => 1, 'description' => 'Sandbox recurring test'];
$result = $processor->doPayment($params);
$out('doPayment', $result);
civicrm_api3('Payment', 'create', ['contribution_id' => $contributionID, 'total_amount' => 3.00, 'trxn_id' => $result['trxn_id'], 'payment_processor_id' => $processorID, 'card_type_id' => $result['card_type_id'] ?? NULL, 'pan_truncation' => $result['pan_truncation'] ?? NULL]);
$out('recur', payarcjs_recur_state($recurID));
$tokenID = (int) payarcjs_recur_state($recurID)['token_id'];
$out('payment token', civicrm_api3('PaymentToken', 'getsingle', ['id' => $tokenID, 'return' => 'token,masked_account_number,email']));

echo "\n== 2. cron charges a due installment\n";
$dueAt($recurID, 86400);
$cron();
$out('contributions', payarcjs_contributions($recurID));
$out('recur', payarcjs_recur_state($recurID));

echo "\n== 3. cron with nothing due\n";
$before = count(payarcjs_contributions($recurID));
$cron();
$out('new contributions', count(payarcjs_contributions($recurID)) - $before);

echo "\n== 4. decline: token points at a bogus card reference\n";
civicrm_api3('PaymentToken', 'create', ['id' => $tokenID, 'token' => 'zzzz-zzzz-zzzz-zzzz']);
$dueAt($recurID, 2 * 86400);
$cron();
$out('contributions', payarcjs_contributions($recurID));
$out('recur', payarcjs_recur_state($recurID));
$note = CRM_Core_DAO::singleValueQuery("SELECT n.note FROM civicrm_note n JOIN civicrm_contribution c ON c.id = n.entity_id AND n.entity_table = 'civicrm_contribution' WHERE c.contribution_recur_id = %1 ORDER BY n.id DESC LIMIT 1", [1 => [$recurID, 'Integer']]);
$out('latest note', $note);
$mail = CRM_Core_DAO::executeQuery("SELECT recipient_email, headers, body FROM civicrm_mailing_spool ORDER BY id DESC LIMIT 1")->fetchAll();
if ($mail) {
  preg_match('/^Subject: (.*)$/mi', $mail[0]['headers'], $m);
  $out('spooled email', $mail[0]['recipient_email'] . ' | ' . ($m[1] ?? '?'));
  $body = $mail[0]['body'];
  $i = stripos($body, 'Dear');
  echo 'body: ', preg_replace('/\s+/', ' ', substr($body, $i !== FALSE ? $i : 0, 600)), "\n";
}
else {
  $out('spooled email', 'NONE (is outbound mail redirected to the database?)');
}

echo "\n== 5. card replacement (updateSubscriptionBillingInfo)\n";
$message = '';
$ok = $processor->updateSubscriptionBillingInfo($message, ['contributionRecurID' => $recurID, 'payment_token' => 'payjs:' . payarcjs_mint(), 'first_name' => 'Recur', 'last_name' => 'Tester', 'street_address' => '1 Main St', 'city' => 'Richmond', 'state_province' => 'Virginia', 'postal_code' => '23220', 'country' => 'United States', 'email' => 'recur@example.org']);
$out('result', $ok === TRUE ? 'TRUE' : (is_object($ok) ? get_class($ok) . ': ' . json_encode($ok->getMessages()) : $ok));
$out('message', $message);
$out('recur', payarcjs_recur_state($recurID));

echo "\n== 6. cron charges the replaced card\n";
$dueAt($recurID, 3 * 86400);
$cron();
$out('contributions', payarcjs_contributions($recurID));
$out('recur', payarcjs_recur_state($recurID));
