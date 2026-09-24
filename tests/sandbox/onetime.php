<?php
// cv scr tests/sandbox/onetime.php
// One-time sandbox payment through doPayment(); the same request again
// (PayArc replays the charge for the same invoice ID); then a partial refund
// (refused while unsettled) and a full refund (void) through the mjwshared
// refund API.
require_once __DIR__ . '/lib.php';
$processorID = payarcjs_test_processor_id();
$out = 'payarcjs_out';
$amount = number_format(5 + mt_rand(0, 99) / 100, 2, '.', '');

$contactID = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Onetime', 'last_name' => 'Tester', 'email' => 'onetime@example.org'])['id'];
$invoiceID = bin2hex(random_bytes(16));
$order = civicrm_api3('Order', 'create', ['contact_id' => $contactID, 'financial_type_id' => 'Donation', 'total_amount' => $amount, 'currency' => 'USD', 'payment_processor_id' => $processorID, 'is_test' => 1, 'invoice_id' => $invoiceID, 'source' => 'sandbox onetime.php']);
$contributionID = (int) $order['id'];
$out('contact / pending contribution / amount', "$contactID / $contributionID / $amount");

$processor = \Civi\Payment\System::singleton()->getById($processorID);
$params = ['amount' => $amount, 'currency' => 'USD', 'contact_id' => $contactID, 'contribution_id' => $contributionID, 'invoice_id' => $invoiceID, 'email' => 'onetime@example.org', 'first_name' => 'Onetime', 'last_name' => 'Tester', 'billing_street_address' => '1 Main St', 'billing_city' => 'Richmond', 'billing_state_province' => 'VA', 'billing_postal_code' => '23220', 'billing_country' => 'US', 'payment_token' => 'payarc:' . payarcjs_mint(), 'is_recur' => 0, 'description' => 'Online Contribution: Sandbox one-time test'];
$result = $processor->doPayment($params);
$out('doPayment', $result);

echo "\n-- the same invoice ID again with a fresh token (expect the same charge back)\n";
$again = $params;
$again['payment_token'] = 'payarc:' . payarcjs_mint();
$replayed = $processor->doPayment($again);
$out('replayed trxn_id', $replayed['trxn_id']);
$out('same charge', $replayed['trxn_id'] === $result['trxn_id'] ? 'YES' : 'NO - a second charge was made');

civicrm_api3('Payment', 'create', ['contribution_id' => $contributionID, 'total_amount' => $amount, 'trxn_id' => $result['trxn_id'], 'payment_processor_id' => $processorID, 'card_type_id' => $result['card_type_id'] ?? NULL, 'pan_truncation' => $result['pan_truncation'] ?? NULL]);
$c = civicrm_api3('Contribution', 'getsingle', ['id' => $contributionID]);
$out('contribution', $c['contribution_status'] . ' trxn=' . $c['trxn_id']);

$payments = fn() => \Civi\Api4\FinancialTrxn::get(FALSE)->addSelect('id', 'trxn_id', 'total_amount', 'card_type_id:label', 'pan_truncation', 'status_id:name')
  ->addJoin('EntityFinancialTrxn AS eft', 'INNER', ['eft.financial_trxn_id', '=', 'id'], ['eft.entity_table', '=', '"civicrm_contribution"'], ['eft.entity_id', '=', $contributionID])
  ->execute()->getArrayCopy();
$out('payments', $payments());
$paymentID = (int) $payments()[0]['id'];

echo "\n-- partial refund on an unsettled charge (expect refusal, nothing sent)\n";
try { $out('partial', \Civi\Api4\PaymentMJW::refund(FALSE)->setPaymentID($paymentID)->setRefundAmount(2.00)->execute()->getArrayCopy()); }
catch (Throwable $e) { $out('partial refused', $e->getMessage()); }
echo "\n-- full refund on an unsettled charge (expect void)\n";
try { $out('full', \Civi\Api4\PaymentMJW::refund(FALSE)->setPaymentID($paymentID)->setRefundAmount((float) $amount)->execute()->getArrayCopy()); }
catch (Throwable $e) { $out('full FAILED', $e->getMessage()); }
$c = civicrm_api3('Contribution', 'getsingle', ['id' => $contributionID]);
$out('contribution after refund', $c['contribution_status']);
$out('financial trxns', $payments());

echo "\n-- a second full refund (expect refusal: already voided)\n";
try { $out('second', \Civi\Api4\PaymentMJW::refund(FALSE)->setPaymentID($paymentID)->setRefundAmount((float) $amount)->execute()->getArrayCopy()); }
catch (Throwable $e) { $out('second refused', $e->getMessage()); }

echo "\n-- a wrong CVV is refused when the card is tokenized (as in the Hosted Fields)\n";
try { payarcjs_mint('4012000098765439', '111'); $out('bad CVV', 'token minted?!'); }
catch (Throwable $e) { $out('bad CVV refused', $e->getMessage()); }

echo "\n-- a used token under a new invoice ID (expect the re-enter wording, not a configuration fault)\n";
$used = ['invoice_id' => bin2hex(random_bytes(16))] + $params;
unset($used['contribution_id']);
try { $processor->doPayment($used); $out('used token', 'APPROVED?!'); }
catch (Throwable $e) { $out('used token refused', $e->getMessage()); }
