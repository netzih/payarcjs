<?php

/**
 * Payarcjs.resume: lift a pause of card payments set by the card-testing
 * limits (CRM_Payarcjs_Velocity). The System Status message offers it.
 * Needs "administer CiviCRM", the default for extension APIs.
 */
function civicrm_api3_payarcjs_resume(array $params): array {
  CRM_Payarcjs_Velocity::singleton()->guard()->resume();
  return civicrm_api3_create_success(['resumed' => 1], $params, 'Payarcjs', 'resume');
}

function _civicrm_api3_payarcjs_resume_spec(array &$spec): void {
}
