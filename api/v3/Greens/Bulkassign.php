<?php

/**
 * Greens.Bulkassign API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_greens_Bulkassign_spec(&$spec) {
}

/**
 * Greens.Bulkassign API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_greens_Bulkassign($params) {
  $ll = new CRM_Utils_membership_BatchUpdate($params);
  $result = $ll->run();
  if ($result['is_error'] == 0) {
    return civicrm_api3_create_success($result['messages']);
  }
  else {
    return civicrm_api3_create_error($result['messages']);
  }
}

