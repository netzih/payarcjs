<?php
// cv scr tests/sandbox/onetime.php
// One-time sandbox payment through doPayment(), then a partial refund (refused
// while unsettled) and a full refund (void) through the mjwshared refund API.
require_once __DIR__ . '/lib.php';
payarcjs_sandbox_env();
$processorID = payarcjs_test_processor_id();
$out = 'payarcjs_out';

$contactID = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Onetime', 'last_name' => 'Tester', 'email' => 'onetime@example.org'])['id'];
$order = civicrm_api3('Order', 'create', ['contact_id' => $contactID, 'financial_type_id' => 'Donation', 'total_amount' => 5.00, 'currency' => 'USD', 'payment_processor_id' => $processorID, 'is_test' => 1, 'invoice_id' => md5(uniqid()), 'source' => 'sandbox onetime.php']);
$contributionID = (int) $order['id'];
$out('contact / pending contribution', "$contactID / $contributionID");

$processor = \Civi\Payment\System::singleton()->getById($processorID);
$params = ['amount' => 5.00, 'currency' => 'USD', 'contact_id' => $contactID, 'contribution_id' => $contributionID, 'invoice_id' => $order['values'][$contributionID]['invoice_id'], 'email' => 'onetime@example.org', 'first_name' => 'Onetime', 'last_name' => 'Tester', 'billing_street_address' => '1 Main St', 'billing_city' => 'Richmond', 'billing_state_province' => 'VA', 'billing_postal_code' => '23220', 'billing_country' => 'US', 'payment_token' => 'payjs:' . payarcjs_mint(), 'is_recur' => 0, 'description' => 'Sandbox one-time test'];
$result = $processor->doPayment($params);
$out('doPayment', $result);
civicrm_api3('Payment', 'create', ['contribution_id' => $contributionID, 'total_amount' => 5.00, 'trxn_id' => $result['trxn_id'], 'payment_processor_id' => $processorID, 'card_type_id' => $result['card_type_id'] ?? NULL, 'pan_truncation' => $result['pan_truncation'] ?? NULL]);
$c = civicrm_api3('Contribution', 'getsingle', ['id' => $contributionID]);
$out('contribution', $c['contribution_status'] . ' trxn=' . $c['trxn_id']);

$payments = fn() => \Civi\Api4\FinancialTrxn::get(FALSE)->addSelect('id', 'trxn_id', 'total_amount', 'card_type_id:label', 'pan_truncation', 'status_id:name')
  ->addJoin('EntityFinancialTrxn AS eft', 'INNER', ['eft.financial_trxn_id', '=', 'id'], ['eft.entity_table', '=', '"civicrm_contribution"'], ['eft.entity_id', '=', $contributionID])
  ->execute()->getArrayCopy();
$out('payments', $payments());
$paymentID = (int) $payments()[0]['id'];

echo "\n-- partial refund on an unsettled charge (expect refusal)\n";
try { $out('partial', \Civi\Api4\PaymentMJW::refund(FALSE)->setPaymentID($paymentID)->setRefundAmount(2.00)->execute()->getArrayCopy()); }
catch (Throwable $e) { $out('partial refused', $e->getMessage()); }
echo "\n-- full refund on an unsettled charge (expect void)\n";
try { $out('full', \Civi\Api4\PaymentMJW::refund(FALSE)->setPaymentID($paymentID)->setRefundAmount(5.00)->execute()->getArrayCopy()); }
catch (Throwable $e) { $out('full FAILED', $e->getMessage()); }
$c = civicrm_api3('Contribution', 'getsingle', ['id' => $contributionID]);
$out('contribution after refund', $c['contribution_status']);
$out('financial trxns', $payments());
