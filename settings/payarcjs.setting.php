<?php

use CRM_Payarcjs_ExtensionUtil as E;

/**
 * Settings for importing transactions made outside CiviCRM (for example a
 * WooCommerce or Gravity Forms store using the same PayArc merchant account).
 * The settings page is created by the setting-admin mixin at
 * civicrm/admin/setting/payarcjs (Administer > System Settings).
 */
return [
  'payarcjs_apple_pay_enabled' => [
    'name' => 'payarcjs_apple_pay_enabled',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => FALSE,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Offer Apple Pay on contribution pages'),
    'description' => E::ts('Shows an Apple Pay button above the card box for one-time gifts, in Safari on devices with a card in Apple Wallet. Requires Apple Pay to be enabled and this site\'s domain registered in the PayArc console (Settings > Apple Pay), and the domain verification file hosted at /.well-known/apple-developer-merchantid-domain-association. Recurring gifts, back-office forms and card updates always use the card box.'),
    'settings_pages' => ['payarcjs' => ['weight' => 1]],
  ],
  'payarcjs_apple_pay_display_name' => [
    'name' => 'payarcjs_apple_pay_display_name',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Apple Pay merchant name'),
    'description' => E::ts('Shown on the Apple Pay sheet as the payee. Defaults to the organization name from the CiviCRM domain.'),
    'html_attributes' => ['size' => 40],
    'settings_pages' => ['payarcjs' => ['weight' => 2]],
  ],
  'payarcjs_import_source_names' => [
    'name' => 'payarcjs_import_source_names',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Import transactions from these PayArc source key names'),
    'description' => E::ts('Comma-separated NAMES of the API keys (source keys) whose approved sales should be imported, exactly as they appear in the "Source" column of PayArc transaction reports and in the Name column under Settings > API Keys (for example: WooCommerce, Gravity Forms). This is the key\'s label, not the key string itself. Leave empty to import nothing. Enter * to import every sale on the account that CiviCRM does not already have. Transactions CiviCRM made itself are always skipped. The job result lists the source names PayArc reported, which helps when a name does not match.'),
    'html_attributes' => ['size' => 60],
    'settings_pages' => ['payarcjs' => ['weight' => 10]],
  ],
  'payarcjs_import_processor_id' => [
    'name' => 'payarcjs_import_processor_id',
    'type' => 'Integer',
    'html_type' => 'select',
    'default' => NULL,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Read transactions with this processor\'s API key'),
    'description' => E::ts('Leave empty to read with the active live PayArc Pay.js processor, whose key normally sees every transaction on the merchant account. If a key turns out to be limited to its own history, add a second PayArc Pay.js payment processor record (it can be disabled) holding the store\'s API key and PIN, and select it here.'),
    'pseudoconstant' => ['table' => 'civicrm_payment_processor', 'keyColumn' => 'id', 'labelColumn' => 'name', 'condition' => "class_name = 'Payment_Payarcjs' AND is_test = 0"],
    'html_attributes' => ['placeholder' => E::ts('- active live PayArc processor -')],
    'settings_pages' => ['payarcjs' => ['weight' => 15]],
  ],
  'payarcjs_import_financial_type_id' => [
    'name' => 'payarcjs_import_financial_type_id',
    'type' => 'Integer',
    'html_type' => 'select',
    // Donation in a standard install.
    'default' => 1,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Financial type for imported transactions'),
    'description' => E::ts('Imported contributions are recorded with this financial type.'),
    'pseudoconstant' => ['table' => 'civicrm_financial_type', 'keyColumn' => 'id', 'labelColumn' => 'name', 'condition' => 'is_active = 1'],
    'settings_pages' => ['payarcjs' => ['weight' => 20]],
  ],
  'payarcjs_import_currency' => [
    'name' => 'payarcjs_import_currency',
    'type' => 'String',
    'html_type' => 'text',
    'default' => 'USD',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Currency of imported transactions'),
    'description' => E::ts('PayArc transaction reports do not state a currency; imported contributions use this three-letter code (the merchant account currency).'),
    'html_attributes' => ['size' => 4, 'maxlength' => 3],
    'settings_pages' => ['payarcjs' => ['weight' => 30]],
  ],
  'payarcjs_import_timezone' => [
    'name' => 'payarcjs_import_timezone',
    'type' => 'String',
    'html_type' => 'text',
    'default' => 'America/Los_Angeles',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('PayArc account time zone'),
    'description' => E::ts('PayArc reports transaction times in the merchant account\'s time zone without saying which. Normally the import works this out from a transaction CiviCRM itself made; this zone (an identifier such as America/New_York) is used only when there is none to compare with.'),
    'html_attributes' => ['size' => 30],
    'settings_pages' => ['payarcjs' => ['weight' => 35]],
  ],
  'payarcjs_import_lookback_days' => [
    'name' => 'payarcjs_import_lookback_days',
    'type' => 'Integer',
    'html_type' => 'text',
    'default' => 7,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Import window (days)'),
    'description' => E::ts('Each scheduled run of "PayArc import transactions" looks this many days back for sales, refunds and voids not yet in CiviCRM. A wider window is safe (already-imported transactions are skipped) but slower; the manual API call can import any date range.'),
    'html_attributes' => ['size' => 4],
    'settings_pages' => ['payarcjs' => ['weight' => 40]],
  ],
];
