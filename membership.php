<?php

require_once 'membership.civix.php';

function membership_civicrm_searchTasks($objectType, &$tasks) {
  if($objectType=='contact') {
    array_push($tasks, array(
      'title' => "SGP - Set Membership Fields", 
      'class' => "CRM_Contact_Form_Task_SetMembershipFlag", 
    ));
    array_push($tasks, array(
      'title' => "SGP - Set Branches and Areas", 
      'class' => "CRM_Contact_Form_Task_SetBranchAreas", 
    ));
    array_push($tasks, array(
      'title' => "SGP - Create/Update Mailing List", 
      'class' => "CRM_Contact_Form_Task_CreateACLGroup", 
    ));
    array_push($tasks, array(
      'title' => "SGP - Generate Green ID", 
      'class' => "CRM_Contact_Form_Task_GenerateGreenID", 
    ));
  }
  if($objectType=='contribution') {
    array_push($tasks, array(
      'title' => "SGP - Create Recurring Payments", 
      'class' => "CRM_Contribute_Form_Task_CreateRecurringPayment", 
    ));
    array_push($tasks, array(
      'title' => "SGP - Process Membership Payments", 
      'class' => "CRM_Contribute_Form_Task_ProcessMembershipPayment", 
    ));
  }
  
}

function membership_civicrm_post($op, $objectName, $id, &$params) {
  if(($op == 'create') && $objectName=='Individual') {
    // GENERATE GREEN ID
    $gg = new CRM_Utils_GreenID_Generate(array('id'=>$id));
    $res = $gg->run();
  }
  if(($op == 'edit' || $op == 'create') && ($objectName=='Membership')) {
    $membership = array();
    $membership['membership_id'] =  $id;
    $custom_fields = CRM_Utils_membership_CustomFields::getCustomFields();
    $mf = new CRM_Utils_GreenMembership_SetMembershipFlag(
      array_merge($membership,
        array(
          'custom_fields' => $custom_fields,
        )
      )
    );
    $res = $mf->run();
  }
  if(($op == 'edit' || $op == 'create') && ($objectName=='Relationship')) {
    $custom_fields = CRM_Utils_membership_CustomFields::getCustomFields();
    $ll = new CRM_Utils_GreenAcls_Generate(array(
      'contact_id' => $params->contact_id_a,
      'custom_fields' => $custom_fields,
    ));
    $res = $ll->run();
    $ll = new CRM_Utils_GreenAcls_Generate(array(
      'contact_id' => $params->contact_id_b,
      'custom_fields' => $custom_fields,
    ));
    $res = $ll->run();
  }
  if($op == 'create'
    && $objectName=='Address'
    && $params->is_primary == 1) {
    membership_process_address($params, false);
  }
}

function membership_civicrm_pre($op, $objectName, $id, &$params) {
  if($op == 'edit'
    && $objectName=='Address'
    && $params['is_primary'] == 1) {
    membership_process_address($params, true);
  }
  if($objectName == 'ContributionRecur') {
    $params['is_email_receipt'] = 0;
  }
  if(($op == 'create') && ($objectName=='Contribution')
    && $params['financial_type_id'] == 2) {
    $pp = new CRM_Utils_GreenMembership_RecurringContribution($params);
    $res = $pp->processRC();
  }
}

function membership_process_address($params,$check_postcode) {
  $custom_fields = CRM_Utils_membership_CustomFields::getCustomFields();
  $params_array = (array) $params;
  $ll = new CRM_Utils_membership_AreaAssign(
    array(
      'contact_id' => $params_array['contact_id'],
      'contact_type' => $params_array['contact_type'],
      'contact_sub_type' => $params_array['contact_sub_type'],
      'is_primary' => $params_array['is_primary'],
      'postal_code' => $params_array['postal_code'],
      'custom_fields' => $custom_fields,
      'no_area_contact' => $no_area_contact,
      'check_existing_postcode' => $check_postcode
    )
  );
  $res = $ll->run();
}

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function membership_civicrm_config(&$config) {
  _membership_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @param array $files
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function membership_civicrm_xmlMenu(&$files) {
  _membership_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */

function membership_civicrm_install() {
  _membership_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function membership_civicrm_uninstall() {
  _membership_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function membership_civicrm_enable() {
  _membership_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function membership_civicrm_disable() {
  _membership_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed
 *   Based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function membership_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _membership_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function membership_civicrm_managed(&$entities) {
  _membership_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * @param array $caseTypes
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function membership_civicrm_caseTypes(&$caseTypes) {
  _membership_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function membership_civicrm_angularModules(&$angularModules) {
_membership_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function membership_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _membership_civix_civicrm_alterSettingsFolders($metaDataFolders);
}
