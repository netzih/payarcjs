<?php

use CRM_Payarcjs_ExtensionUtil as E;

/**
 * Wallet buttons. The settings page is created by the setting-admin mixin at
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
    'description' => E::ts('Shows a "Pay with Apple Pay" button above the card fields for one-time gifts, in browsers that support Apple Pay (Safari). The Apple Pay sheet opens in a small PayArc window, so this site needs no Apple merchant setup or domain file; the sheet names the payee "Payarc". Where Apple Pay is available, it is shown instead of Google Pay. Recurring gifts, back-office forms and card updates always use the card fields.'),
    'settings_pages' => ['payarcjs' => ['weight' => 1]],
  ],
  'payarcjs_google_pay_enabled' => [
    'name' => 'payarcjs_google_pay_enabled',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => FALSE,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Offer Google Pay on contribution pages'),
    'description' => E::ts('Shows a "Pay with Google Pay" button above the card fields for one-time gifts, in browsers where Apple Pay is not offered. Google Pay opens in a small PayArc window. Recurring gifts, back-office forms and card updates always use the card fields.'),
    'settings_pages' => ['payarcjs' => ['weight' => 2]],
  ],
];
