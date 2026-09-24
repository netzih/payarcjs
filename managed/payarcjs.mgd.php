<?php

use CRM_Payarcjs_ExtensionUtil as E;

$failedText = <<<'TXT'
Dear {contact.first_name},

We were unable to process your recurring gift of {contribution_recur.amount} to {domain.name}.

{$declineMessage}

{if $isStopped}Your recurring gift has been paused after {$maxAttempts} unsuccessful attempts. To resume it, please update your card details:
{$updateBillingUrl}
{else}We will try again on {$retryDate}. If your card has changed, you can update it before then:
{$updateBillingUrl}
{/if}
Thank you for your support.

{domain.name}
{domain.phone}
{domain.email}
TXT;

$failedHtml = <<<'HTML'
<p>Dear {contact.first_name},</p>
<p>We were unable to process your recurring gift of <strong>{contribution_recur.amount}</strong> to {domain.name}.</p>
<p>{$declineMessage}</p>
{if $isStopped}
<p>Your recurring gift has been paused after {$maxAttempts} unsuccessful attempts. To resume it, please <a href="{$updateBillingUrl}">update your card details</a>.</p>
{else}
<p>We will try again on {$retryDate}. If your card has changed, you can <a href="{$updateBillingUrl}">update it</a> before then.</p>
{/if}
<p>Thank you for your support.</p>
<p>{domain.name}<br>{domain.phone}<br>{domain.email}</p>
HTML;

$failedTemplate = [
  'workflow_name' => 'payarcjs_recurring_failed',
  'msg_title' => E::ts('PayArc Pay.js - Recurring Payment Failed'),
  'msg_subject' => E::ts('Your recurring gift to {domain.name} could not be processed'),
  'msg_text' => $failedText,
  'msg_html' => $failedHtml,
  'is_active' => TRUE,
];

return [
  [
    'name' => 'PayArcHostedFieldsFailedWorkflow',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'msg_tpl_workflow_contribution',
        'name' => 'payarcjs_recurring_failed',
        'label' => E::ts('PayArc Pay.js - Recurring Payment Failed'),
        'is_active' => TRUE,
        'is_reserved' => TRUE,
      ],
      'match' => ['option_group_id', 'name'],
    ],
  ],
  [
    // Pristine copy shown by "Revert to default" in System Workflow Messages.
    'name' => 'PayArcHostedFieldsFailedTemplateReserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => $failedTemplate + ['is_reserved' => TRUE, 'is_default' => FALSE],
      'match' => ['workflow_name', 'is_reserved'],
    ],
  ],
  [
    // Editable copy; never overwritten by upgrades so site edits persist.
    'name' => 'PayArcHostedFieldsFailedTemplate',
    'entity' => 'MessageTemplate',
    'cleanup' => 'unused',
    'update' => 'never',
    'params' => [
      'version' => 4,
      'values' => $failedTemplate + ['is_reserved' => FALSE, 'is_default' => TRUE],
      'match' => ['workflow_name', 'is_default'],
    ],
  ],
  [
    'name' => 'PayArcHostedFields',
    'entity' => 'PaymentProcessorType',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'PayArcHostedFields',
        'title' => E::ts('PayArc Pay.js'),
        'description' => E::ts('PayArc card payments using hosted Pay.js fields and REST API v2.'),
        'user_name_label' => E::ts('API key'),
        'password_label' => E::ts('API PIN'),
        'signature_label' => E::ts('Pay.js public key'),
        'class_name' => 'Payment_Payarcjs',
        'url_site_default' => 'https://secure.payarc.com/api/v2',
        'url_site_test_default' => 'https://sandbox.payarc.com/api/v2',
        'billing_mode' => 1,
        'is_recur' => TRUE,
        'is_active' => TRUE,
        'payment_instrument_id:name' => 'Credit Card',
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'PayArcHostedFieldsCron',
    'entity' => 'Job',
    'update' => 'unmodified',
    'params' => [
      'version' => 3,
      'run_frequency' => 'Always',
      'name' => E::ts('PayArc recurring card payments'),
      'description' => E::ts('Charge due CiviCRM-managed recurring contributions through PayArc.'),
      'api_entity' => 'Job',
      'api_action' => 'run_payment_cron',
      'parameters' => 'processor_name=PayArcHostedFields',
      'is_active' => 1,
    ],
  ],
  [
    'name' => 'PayArcHostedFieldsImport',
    'entity' => 'Job',
    'update' => 'unmodified',
    'params' => [
      'version' => 3,
      'run_frequency' => 'Hourly',
      'name' => E::ts('PayArc import transactions'),
      'description' => E::ts('Import approved PayArc sales made outside CiviCRM (from the source keys named in PayArc Pay.js Settings) as contributions, and apply their refunds and voids. Does nothing until source keys are configured.'),
      'api_entity' => 'Payarcjs',
      'api_action' => 'importtransactions',
      'parameters' => '',
      'is_active' => 1,
    ],
  ],
];
