<?php

use CRM_Payarcjs_ExtensionUtil as E;

/**
 * Payarcjs.importtransactions API specification.
 */
function _civicrm_api3_payarcjs_importtransactions_spec(array &$spec): void {
  $spec['from'] = [
    'title' => 'Start of the window (date/time). Defaults to now minus the configured lookback days.',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['to'] = [
    'title' => 'End of the window (date/time). Defaults to now.',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['lookback_days'] = [
    'title' => 'Days before now to start from when "from" is not given.',
    'type' => CRM_Utils_Type::T_INT,
  ];
  $spec['dry_run'] = [
    'title' => 'Report what would be imported without writing anything.',
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'api.default' => FALSE,
  ];
  $spec['source_names'] = [
    'title' => 'Override the configured source key names (comma-separated, or * for all).',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['financial_type_id'] = [
    'title' => 'Override the configured financial type.',
    'type' => CRM_Utils_Type::T_INT,
  ];
  $spec['processor_id'] = [
    'title' => 'PayArc payment processor whose credentials to use (may be disabled). Defaults to the "Read transactions with this processor" setting, then the active live PayArc processor.',
    'type' => CRM_Utils_Type::T_INT,
  ];
  $spec['max_pages'] = [
    'title' => 'Maximum pages of 100 transactions to read (default 50).',
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 50,
  ];
}

/**
 * Import PayArc transactions made outside CiviCRM as contributions.
 *
 * @throws CRM_Core_Exception
 */
function civicrm_api3_payarcjs_importtransactions(array $params): array {
  $processorID = (int) ($params['processor_id'] ?? 0);
  if (!$processorID) {
    $processorID = (int) Civi::settings()->get('payarcjs_import_processor_id');
  }
  if (!$processorID) {
    $processorID = (int) (\Civi\Api4\PaymentProcessor::get(FALSE)
      ->addSelect('id')
      ->addWhere('payment_processor_type_id:name', '=', 'PayArcHostedFields')
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('is_test', '=', FALSE)
      ->addOrderBy('id', 'ASC')
      ->setLimit(1)
      ->execute()
      ->first()['id'] ?? 0);
  }
  if (!$processorID) {
    throw new CRM_Core_Exception(E::ts('No active live PayArc Pay.js payment processor was found.'));
  }
  $processor = Civi\Payment\System::singleton()->getById($processorID);
  if (!$processor instanceof CRM_Core_Payment_Payarcjs) {
    throw new CRM_Core_Exception(E::ts('Payment processor %1 is not a PayArc Pay.js processor.', [1 => $processorID]));
  }

  $lookback = (int) ($params['lookback_days'] ?? 0);
  if ($lookback <= 0) {
    $lookback = (int) (Civi::settings()->get('payarcjs_import_lookback_days') ?: 7);
  }
  try {
    $from = !empty($params['from']) ? new DateTimeImmutable((string) $params['from']) : (new DateTimeImmutable('now'))->modify("-{$lookback} days");
    $to = !empty($params['to']) ? new DateTimeImmutable((string) $params['to']) : NULL;
  }
  catch (Throwable $e) {
    throw new CRM_Core_Exception(E::ts('The from/to dates could not be read: %1', [1 => $e->getMessage()]));
  }

  $importer = CRM_Payarcjs_TransactionImporter::fromSettings($processor, [
    'source_names' => $params['source_names'] ?? NULL,
    'financial_type_id' => $params['financial_type_id'] ?? NULL,
    'dry_run' => !empty($params['dry_run']),
  ]);
  $summary = $importer->run($from, $to, (int) ($params['max_pages'] ?? 50));

  return civicrm_api3_create_success([$summary], $params, 'Payarcjs', 'importtransactions');
}
