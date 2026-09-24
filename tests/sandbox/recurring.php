<?php
// cv scr tests/sandbox/recurring.php
// Recurring signup -> cron charge -> lost answers (replay, lookup, absent)
// -> decline with donor email -> card replacement -> cron charge.
require_once __DIR__ . '/lib.php';
$processorID = payarcjs_test_processor_id();
$out = 'payarcjs_out';
$cron = fn() => civicrm_api3('Job', 'run_payment_cron', ['processor_name' => 'PayArcHostedFields', 'mode' => 'test']);
// Scheduled dates must differ between runs: the worker derives the invoice ID from them.
$dueAt = fn(int $id, int $daysAgo) => civicrm_api3('ContributionRecur', 'create', ['id' => $id, 'next_sched_contribution_date' => date('Y-m-d H:i:s', time() - $daysAgo * 86400), 'failure_count' => 0, 'failure_retry_date' => '']);
// Pennies vary the amount: the sandbox declines some amounts at random (D2026).
$amount = number_format(3 + mt_rand(0, 99) / 100, 2, '.', '');
$processor = \Civi\Payment\System::singleton()->getById($processorID);

echo "== 1. recurring signup (card saved first, then the saved card charged)\n";
$contactID = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Recur', 'last_name' => 'Tester', 'email' => 'recur@example.org'])['id'];
$recurID = (int) civicrm_api3('ContributionRecur', 'create', ['contact_id' => $contactID, 'amount' => $amount, 'currency' => 'USD', 'frequency_unit' => 'month', 'frequency_interval' => 1, 'start_date' => date('Y-m-d H:i:s'), 'next_sched_contribution_date' => date('Y-m-d H:i:s'), 'cycle_day' => (int) date('j'), 'contribution_status_id' => 'Pending', 'payment_processor_id' => $processorID, 'is_test' => 1, 'financial_type_id' => 'Donation', 'payment_instrument_id' => 'Credit Card'])['id'];
$order = civicrm_api3('Order', 'create', ['contact_id' => $contactID, 'financial_type_id' => 'Donation', 'total_amount' => $amount, 'currency' => 'USD', 'payment_processor_id' => $processorID, 'is_test' => 1, 'contribution_recur_id' => $recurID, 'invoice_id' => bin2hex(random_bytes(16)), 'source' => 'sandbox recurring.php']);
$contributionID = (int) $order['id'];
$params = ['amount' => $amount, 'currency' => 'USD', 'contact_id' => $contactID, 'contribution_id' => $contributionID, 'contribution_recur_id' => $recurID, 'invoice_id' => $order['values'][$contributionID]['invoice_id'], 'email' => 'recur@example.org', 'first_name' => 'Recur', 'last_name' => 'Tester', 'billing_street_address' => '1 Main St', 'billing_city' => 'Richmond', 'billing_state_province' => 'VA', 'billing_postal_code' => '23220', 'billing_country' => 'US', 'payment_token' => 'payarc:' . payarcjs_mint(), 'is_recur' => 1, 'frequency_unit' => 'month', 'frequency_interval' => 1, 'description' => 'Online Contribution: Sandbox recurring test'];
$result = $processor->doPayment($params);
$out('doPayment', $result);
civicrm_api3('Payment', 'create', ['contribution_id' => $contributionID, 'total_amount' => $amount, 'trxn_id' => $result['trxn_id'], 'payment_processor_id' => $processorID, 'card_type_id' => $result['card_type_id'] ?? NULL, 'pan_truncation' => $result['pan_truncation'] ?? NULL]);
$out('recur', payarcjs_recur_state($recurID));
$tokenID = (int) payarcjs_recur_state($recurID)['token_id'];
$out('payment token', civicrm_api3('PaymentToken', 'getsingle', ['id' => $tokenID, 'return' => 'token,masked_account_number,expiry_date,email']));

echo "\n== 2. cron charges a due installment\n";
$dueAt($recurID, 1);
$cron();
$out('contributions', payarcjs_contributions($recurID));
$out('recur', payarcjs_recur_state($recurID));

echo "\n== 3. cron with nothing due\n";
$before = count(payarcjs_contributions($recurID));
$cron();
$out('new contributions', count(payarcjs_contributions($recurID)) - $before);

/**
 * Leave a pending attempt behind the way a crashed run would: the pending
 * contribution with the installment's invoice ID, optionally after the
 * charge reached PayArc (sent with that reference, answer never recorded).
 */
$crashedAttempt = function (int $daysAgo, int $sentSecondsAgo, bool $reachedPayArc) use ($recurID, $dueAt, $processor, $contactID) {
  $dueAt($recurID, $daysAgo);
  // PayArc refuses the same card and amount twice within minutes as a
  // duplicate, so every scenario charges a different amount.
  civicrm_api3('ContributionRecur', 'create', ['id' => $recurID, 'amount' => number_format(4 + mt_rand(0, 899) / 100, 2, '.', '')]);
  $recur = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recurID]);
  $invoiceID = CRM_Payarcjs_Schedule::invoiceID($recurID, $recur['next_sched_contribution_date'], 0, CRM_Core_Payment_Payarcjs::siteTag());
  $created = civicrm_api3('Contribution', 'repeattransaction', ['contribution_recur_id' => $recurID, 'contribution_status_id' => 'Pending', 'total_amount' => $recur['amount'], 'receive_date' => date('Y-m-d H:i:s', time() - $sentSecondsAgo), 'is_email_receipt' => 0]);
  civicrm_api3('Contribution', 'create', ['id' => $created['id'], 'invoice_id' => $invoiceID]);
  $chargeID = NULL;
  if ($reachedPayArc) {
    $sendParams = ['amount' => $recur['amount'], 'currency' => 'USD', 'contact_id' => $contactID, 'contribution_id' => (int) $created['id'], 'contribution_recur_id' => $recurID, 'invoice_id' => $invoiceID, 'payment_token_id' => (int) $recur['payment_token_id'], 'is_recur' => FALSE, 'description' => 'Recurring contribution ' . $recurID];
    try {
      $sent = $processor->doPayment($sendParams);
    }
    catch (\Civi\Payment\Exception\PaymentProcessorException $e) {
      throw new RuntimeException('The charge before the simulated crash failed (' . $e->getErrorCode() . '): ' . $e->getMessage() . ' Run the script again.');
    }
    $chargeID = $sent['trxn_id'];
  }
  return [$invoiceID, (int) $created['id'], $chargeID];
};

echo "\n== 4. lost answer, replayed within the hour: the charge reached PayArc (expect that charge recorded, no second one)\n";
[$invoiceID, $pendingID, $chargeID] = $crashedAttempt(2, 60, TRUE);
$out('invoice / charge made before the crash', "$invoiceID / $chargeID");
$out('cron', $cron()['values'] ?? NULL);
$c = civicrm_api3('Contribution', 'getsingle', ['id' => $pendingID]);
$out('attempt', $c['contribution_status'] . ' trxn=' . ($c['trxn_id'] ?? ''));
$out('same charge', ($c['trxn_id'] ?? '') === $chargeID ? 'YES' : 'NO');
$out('note', payarcjs_latest_note($recurID));

echo "\n== 5. lost answer, looked up after the hour: the charge reached PayArc (expect it found and recorded)\n";
[$invoiceID, $pendingID, $chargeID] = $crashedAttempt(3, 2 * 3600, TRUE);
$out('invoice / charge made before the crash', "$invoiceID / $chargeID");
$cron();
$c = civicrm_api3('Contribution', 'getsingle', ['id' => $pendingID]);
$out('attempt', $c['contribution_status'] . ' trxn=' . ($c['trxn_id'] ?? ''));
$out('same charge', ($c['trxn_id'] ?? '') === $chargeID ? 'YES' : 'NO');
$out('note', payarcjs_latest_note($recurID));

echo "\n== 6. lost answer, looked up after the hour: the request never reached PayArc (expect failed, retry scheduled)\n";
[$invoiceID, $pendingID] = $crashedAttempt(4, 2 * 3600, FALSE);
$cron();
$c = civicrm_api3('Contribution', 'getsingle', ['id' => $pendingID]);
$out('attempt', $c['contribution_status']);
$out('recur', payarcjs_recur_state($recurID));
$out('note', payarcjs_latest_note($recurID));

echo "\n== 7. decline: token points at a bogus card reference\n";
$goodReference = civicrm_api3('PaymentToken', 'getvalue', ['id' => $tokenID, 'return' => 'token']);
civicrm_api3('PaymentToken', 'create', ['id' => $tokenID, 'token' => 'zzzzzzzzzzzzzzzz:zzzzzzzzzzzzzzzz']);
$dueAt($recurID, 5);
$cron();
$out('contributions', array_slice(payarcjs_contributions($recurID), -2));
$out('recur', payarcjs_recur_state($recurID));
$out('latest note', payarcjs_latest_note($recurID));
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

echo "\n== 8. card replacement (updateSubscriptionBillingInfo: save, verify with a voided \$1 authorization)\n";
$message = '';
$ok = $processor->updateSubscriptionBillingInfo($message, ['contributionRecurID' => $recurID, 'payment_token' => 'payarc:' . payarcjs_mint('5146315000000055', '998'), 'first_name' => 'Recur', 'last_name' => 'Tester', 'street_address' => '1 Main St', 'city' => 'Richmond', 'state_province' => 'Virginia', 'postal_code' => '23220', 'country' => 'United States', 'email' => 'recur@example.org']);
$out('result', $ok === TRUE ? 'TRUE' : (is_object($ok) ? get_class($ok) . ': ' . json_encode($ok->getMessages()) : $ok));
$out('message', $message);
$out('recur', payarcjs_recur_state($recurID));

echo "\n== 9. cron charges the replaced card\n";
$dueAt($recurID, 6);
civicrm_api3('ContributionRecur', 'create', ['id' => $recurID, 'amount' => number_format(13 + mt_rand(0, 99) / 100, 2, '.', '')]);
$cron();
$out('contributions', array_slice(payarcjs_contributions($recurID), -1));
$out('recur', payarcjs_recur_state($recurID));
