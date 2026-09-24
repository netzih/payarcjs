<?php

require_once __DIR__ . '/CRM/Payarcjs/ExtensionUtil.php';
require_once __DIR__ . '/autoload.php';

use CRM_Payarcjs_ExtensionUtil as E;
use Payarc\GatewayClient;
use Payarc\GatewayException;

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
 * Implements hook_civicrm_check().
 *
 * Flags PayArc processors with missing or rejected credentials or an API URL
 * that does not match their live/test mode, In Progress series without a
 * stored card, a disabled recurring-payment job, and card payments paused
 * by the card-testing limits.
 */
function payarcjs_civicrm_check(&$messages, $statusNames = [], $includeDisabled = FALSE): void {
  $processors = \Civi\Api4\PaymentProcessor::get(FALSE)
    ->addSelect('id', 'name', 'is_test', 'user_name', 'signature', 'url_site')
    ->addWhere('payment_processor_type_id:name', '=', 'PayArcHostedFields')
    ->addWhere('is_active', '=', TRUE)
    // APIv4 hides test processor records unless asked.
    ->addWhere('is_test', 'IN', [TRUE, FALSE])
    ->execute();

  $hasLiveProcessor = FALSE;
  foreach ($processors as $processor) {
    $mode = $processor['is_test'] ? E::ts('test') : E::ts('live');
    $hasLiveProcessor = $hasLiveProcessor || !$processor['is_test'];
    $editAction = [
      'path' => 'civicrm/admin/paymentProcessor/edit',
      'query' => ['action' => 'update', 'id' => $processor['id'], 'reset' => 1],
    ];
    $missing = [];
    foreach (['signature' => E::ts('API bearer token'), 'user_name' => E::ts('Client ID')] as $field => $label) {
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
        E::ts('PayArc: incomplete credentials'),
        $processor['is_test'] ? \Psr\Log\LogLevel::WARNING : \Psr\Log\LogLevel::ERROR,
        'fa-credit-card'
      );
      $message->addAction(E::ts('Edit processor'), NULL, 'href', $editAction);
      $messages[] = $message;
      continue;
    }

    $host = strtolower((string) parse_url((string) ($processor['url_site'] ?? ''), PHP_URL_HOST));
    $isSandboxHost = str_starts_with($host, 'test');
    if ($host !== '' && $isSandboxHost !== (bool) $processor['is_test']) {
      $messages[] = new CRM_Utils_Check_Message(
        'payarcjsConfiguration_url_' . $processor['id'],
        E::ts('The %1 settings of payment processor "%2" use the API URL %3, which does not match that mode. Live credentials belong with api.payarc.net and sandbox credentials with testapi.payarc.net.', [
          1 => $mode,
          2 => $processor['name'],
          3 => $processor['url_site'],
        ]),
        E::ts('PayArc: API URL and mode differ'),
        \Psr\Log\LogLevel::WARNING,
        'fa-credit-card'
      );
    }

    $problems = payarcjs_check_credentials($processor, $host !== '' ? $isSandboxHost : (bool) $processor['is_test']);
    if ($problems) {
      $message = new CRM_Utils_Check_Message(
        'payarcjsConfiguration_rejected_' . $processor['id'],
        E::ts('PayArc refused the %1 settings of payment processor "%2": %3', [
          1 => $mode,
          2 => $processor['name'],
          3 => implode(' ', $problems),
        ]),
        E::ts('PayArc: credentials rejected'),
        $processor['is_test'] ? \Psr\Log\LogLevel::WARNING : \Psr\Log\LogLevel::ERROR,
        'fa-credit-card'
      );
      $message->addAction(E::ts('Edit processor'), NULL, 'href', $editAction);
      $messages[] = $message;
    }
  }

  payarcjs_check_recurs_without_token($messages, array_map('intval', $processors->column('id')));
  payarcjs_check_velocity($messages);

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
        E::ts('PayArc: recurring job disabled'),
        \Psr\Log\LogLevel::WARNING,
        'fa-clock-o'
      );
      $message->addAction(E::ts('Scheduled jobs'), NULL, 'href', ['path' => 'civicrm/admin/job', 'query' => ['reset' => 1]]);
      $messages[] = $message;
    }
  }
}

/**
 * Ask PayArc whether it accepts a processor's bearer token and Client ID,
 * without moving money (one charge listed; one card-field session opened and
 * left unused). Answers are cached for six hours per credential pair, so the
 * System Status page does not call PayArc every time it loads. A PayArc that
 * cannot be reached reports nothing: only a definite refusal is a problem.
 *
 * @return string[]
 *   Problems, empty when both were accepted or PayArc could not be asked.
 */
function payarcjs_check_credentials(array $processor, bool $isSandbox): array {
  $url = trim((string) ($processor['url_site'] ?? '')) ?: ($isSandbox ? GatewayClient::SANDBOX_URL : GatewayClient::LIVE_URL);
  $cacheKey = 'payarcjs_credentials_' . hash('sha256', $url . "\n" . $processor['signature'] . "\n" . $processor['user_name']);
  $cache = Civi::cache('long');
  $cached = $cache->get($cacheKey);
  if (is_array($cached)) {
    return $cached;
  }

  $problems = [];
  $reachable = TRUE;
  try {
    $client = new GatewayClient((string) $processor['signature'], $url, NULL, CRM_Core_Payment_Payarcjs::SOFTWARE);
    try {
      $client->verifyCredentials();
    }
    catch (\Payarc\AmbiguousGatewayException $e) {
      $reachable = FALSE;
    }
    catch (GatewayException $e) {
      $problems[] = E::ts('the API bearer token was refused (%1).', [1 => $e->getMessage()]);
    }
    try {
      $client->verifyClientId((string) $processor['user_name'], $isSandbox ? GatewayClient::SANDBOX_PORTAL : GatewayClient::LIVE_PORTAL);
    }
    catch (\Payarc\AmbiguousGatewayException $e) {
      $reachable = FALSE;
    }
    catch (GatewayException | InvalidArgumentException $e) {
      $problems[] = E::ts('the Client ID is not recognised by PayArc, so the card fields will not load.');
    }
  }
  catch (InvalidArgumentException $e) {
    $problems[] = $e->getMessage();
  }

  if ($reachable || $problems) {
    $cache->set($cacheKey, $problems, 6 * 3600);
  }
  return $problems;
}

/**
 * In Progress PayArc series with no stored card are never picked up by the
 * recurring job (it requires a token), so they would silently never charge.
 * This happens when the first charge was approved but the card reference
 * could not be stored, and core later moved the series back to In Progress.
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
    E::ts('PayArc: recurring gifts without a stored card'),
    \Psr\Log\LogLevel::WARNING,
    'fa-credit-card'
  );
}

/**
 * Card payments paused by the card-testing limits, with a resume action.
 */
function payarcjs_check_velocity(array &$messages): void {
  $until = CRM_Payarcjs_Velocity::singleton()->guard()->pausedUntil();
  if ($until === NULL) {
    return;
  }
  $message = new CRM_Utils_Check_Message(
    'payarcjsVelocity_paused',
    E::ts('Online card payments through PayArc are paused until %1 because too many payments were declined in a short time, which is what card testing (a bot trying stolen cards) looks like. Donors are asked to try again later; staff can still take payments on back-office forms. The limits are under Administer > System Settings > PayArc.', [
      1 => CRM_Utils_Date::customFormat(date('Y-m-d H:i:s', $until)),
    ]),
    E::ts('PayArc: card payments paused'),
    \Psr\Log\LogLevel::ERROR,
    'fa-shield'
  );
  $message->addAction(
    E::ts('Resume card payments'),
    E::ts('Resume online card payments now? If the attack is still going on, payments will pause again after the next run of declines.'),
    'api3',
    ['Payarcjs', 'resume']
  );
  $messages[] = $message;
}
