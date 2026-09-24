<?php

require_once __DIR__ . '/CRM/Payarcjs/ExtensionUtil.php';

use CRM_Payarcjs_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 */
function payarcjs_civicrm_config(&$config): void {
  $includePath = __DIR__ . PATH_SEPARATOR . get_include_path();
  set_include_path($includePath);
  // Register templates/ with Smarty so CiviCRM finds the field help in
  // templates/CRM/Admin/Page/PaymentProcessor.extra.hlp (Card.tpl is loaded
  // by absolute path and does not need this).
  CRM_Core_Smarty::singleton()->addTemplateDir(__DIR__ . DIRECTORY_SEPARATOR . 'templates');
}

/**
 * Implements hook_civicrm_buildForm().
 */
function payarcjs_civicrm_buildForm($formName, &$form): void {
  if ($formName !== 'CRM_Admin_Form_PaymentProcessor') {
    return;
  }

  $processor = $form->getVar('_paymentProcessorDAO');
  if (!$processor || $processor->class_name !== 'Payment_Payarcjs') {
    return;
  }

  foreach (['accept_credit_cards', 'url_recur', 'test_url_recur'] as $field) {
    if ($form->elementExists($field)) {
      $form->removeElement($field);
    }
  }
}

/**
 * Implements hook_civicrm_alterAPIPermissions().
 */
function payarcjs_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions): void {
  $permissions['payarcjs']['importtransactions'] = ['administer CiviContribute'];
}

/**
 * Implements hook_civicrm_check().
 *
 * Flags PayArc processors with missing credentials or an API URL that does
 * not match their live/test mode, and a disabled recurring-payment job.
 */
function payarcjs_civicrm_check(&$messages, $statusNames = [], $includeDisabled = FALSE): void {
  $processors = \Civi\Api4\PaymentProcessor::get(FALSE)
    ->addSelect('id', 'name', 'is_test', 'user_name', 'password', 'signature', 'url_site')
    ->addWhere('payment_processor_type_id:name', '=', 'PayArcHostedFields')
    ->addWhere('is_active', '=', TRUE)
    // APIv4 hides test processor records unless asked.
    ->addWhere('is_test', 'IN', [TRUE, FALSE])
    ->execute();

  $hasLiveProcessor = FALSE;
  foreach ($processors as $processor) {
    $mode = $processor['is_test'] ? E::ts('test') : E::ts('live');
    $hasLiveProcessor = $hasLiveProcessor || !$processor['is_test'];
    $missing = [];
    foreach (['user_name' => E::ts('API key'), 'password' => E::ts('API PIN'), 'signature' => E::ts('Pay.js public key')] as $field => $label) {
      if (trim((string) ($processor[$field] ?? '')) === '') {
        $missing[] = $label;
      }
    }
    if ($missing) {
      $message = new CRM_Utils_Check_Message(
        'payarcjsConfiguration_credentials_' . $processor['id'],
        E::ts('The %1 settings of payment processor "%2" are missing: %3. Payments through it will fail.', [
          1 => $mode,
          2 => $processor['name'],
          3 => implode(', ', $missing),
        ]),
        E::ts('PayArc Pay.js: incomplete credentials'),
        $processor['is_test'] ? \Psr\Log\LogLevel::WARNING : \Psr\Log\LogLevel::ERROR,
        'fa-credit-card'
      );
      $message->addAction(E::ts('Edit processor'), NULL, 'href', [
        'path' => 'civicrm/admin/paymentProcessor/edit',
        'query' => ['action' => 'update', 'id' => $processor['id'], 'reset' => 1],
      ]);
      $messages[] = $message;
    }

    $host = strtolower((string) parse_url((string) ($processor['url_site'] ?? ''), PHP_URL_HOST));
    $isSandboxHost = str_contains($host, 'sandbox');
    if ($host !== '' && $isSandboxHost !== (bool) $processor['is_test']) {
      $messages[] = new CRM_Utils_Check_Message(
        'payarcjsConfiguration_url_' . $processor['id'],
        E::ts('The %1 settings of payment processor "%2" use the API URL %3, which does not match that mode. Live credentials belong with secure.payarc.com and sandbox credentials with sandbox.payarc.com.', [
          1 => $mode,
          2 => $processor['name'],
          3 => $processor['url_site'],
        ]),
        E::ts('PayArc Pay.js: API URL and mode differ'),
        \Psr\Log\LogLevel::WARNING,
        'fa-credit-card'
      );
    }
  }

  payarcjs_check_recurs_without_token($messages, array_map('intval', $processors->column('id')));

  if ($hasLiveProcessor) {
    $activeJobs = \Civi\Api4\Job::get(FALSE)
      ->selectRowCount()
      ->addWhere('api_entity', '=', 'Job')
      ->addWhere('api_action', '=', 'run_payment_cron')
      ->addWhere('parameters', 'LIKE', '%PayArcHostedFields%')
      ->addWhere('is_active', '=', TRUE)
      ->execute()
      ->count();
    if ($activeJobs === 0) {
      $message = new CRM_Utils_Check_Message(
        'payarcjsConfiguration_job',
        E::ts('The scheduled job "PayArc recurring card payments" is disabled, so recurring gifts through PayArc will not be charged.'),
        E::ts('PayArc Pay.js: recurring job disabled'),
        \Psr\Log\LogLevel::WARNING,
        'fa-clock-o'
      );
      $message->addAction(E::ts('Scheduled jobs'), NULL, 'href', ['path' => 'civicrm/admin/job', 'query' => ['reset' => 1]]);
      $messages[] = $message;
    }
  }
}

/**
 * In Progress PayArc series with no stored card are never picked up by the
 * recurring job (it requires a token), so they would silently never charge.
 * This happens when the first charge was approved but the card token could
 * not be stored, and core later moved the series back to In Progress.
 */
function payarcjs_check_recurs_without_token(array &$messages, array $processorIDs): void {
  if (!$processorIDs) {
    return;
  }
  $recurs = \Civi\Api4\ContributionRecur::get(FALSE)
    ->addSelect('id', 'contact_id', 'contact_id.display_name', 'amount', 'currency', 'is_test')
    ->addWhere('payment_processor_id', 'IN', $processorIDs)
    ->addWhere('contribution_status_id:name', '=', 'In Progress')
    ->addWhere('payment_token_id', 'IS NULL')
    ->addWhere('is_test', 'IN', [TRUE, FALSE])
    ->setLimit(20)
    ->execute();
  if (!count($recurs)) {
    return;
  }
  $items = [];
  foreach ($recurs as $recur) {
    $url = CRM_Utils_System::url('civicrm/contact/view/contributionrecur', ['reset' => 1, 'id' => $recur['id'], 'cid' => $recur['contact_id']]);
    $items[] = sprintf('<li><a href="%s">%s</a>: %s %s%s</li>',
      htmlspecialchars($url),
      htmlspecialchars(E::ts('Recurring contribution %1 for %2', [1 => $recur['id'], 2 => $recur['contact_id.display_name']])),
      htmlspecialchars(CRM_Utils_Money::format($recur['amount'], $recur['currency'])),
      htmlspecialchars(E::ts('per period')),
      $recur['is_test'] ? ' ' . htmlspecialchars(E::ts('(test)')) : ''
    );
  }
  $messages[] = new CRM_Utils_Check_Message(
    'payarcjsConfiguration_recur_without_token',
    E::ts('These recurring contributions are In Progress but have no stored PayArc card, so the recurring job will never charge them. Ask the donor to update the card (Change Billing Details) or cancel the series.') . '<ul>' . implode('', $items) . '</ul>',
    E::ts('PayArc Pay.js: recurring gifts without a stored card'),
    \Psr\Log\LogLevel::WARNING,
    'fa-credit-card'
  );
}
