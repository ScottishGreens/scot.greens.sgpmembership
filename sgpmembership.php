<?php

require_once 'sgpmembership.civix.php';

function sgpmembership_civicrm_searchTasks($objectType, &$tasks) {
  if($objectType=='contact') {
    array_push($tasks, array(
      'title' => "SGP - Set Membership Fields", 
      'class' => "CRM_Contact_Form_Task_SetMembershipFlag", 
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

function sgpmembership_civicrm_post($op, $objectName, $id, &$params) {
  if(($op == 'edit' || $op == 'create') && ($objectName=='Membership')) {
    $membership = array();
    $membership['membership_id'] =  $id;
    $custom_fields = CRM_Utils_SGP_MembershipCustomFields::getCustomFields();
    $mf = new CRM_Utils_SGP_SetMembershipFlag(
      array_merge($membership,
        array(
          'custom_fields' => $custom_fields,
        )
      )
    );
    $res = $mf->run();
  }
}

function sgpmembership_civicrm_pre($op, $objectName, $id, &$params) {
  if(($op == 'create') && ($objectName=='Contribution')
    && $params['financial_type_id'] == 2) {
    $pp = new CRM_Utils_SGP_RecurringContribution($params);
    $res = $pp->processRC();
  }
}


/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function sgpmembership_civicrm_config(&$config) {
  _sgpmembership_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @param array $files
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function sgpmembership_civicrm_xmlMenu(&$files) {
  _sgpmembership_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */

function sgpmembership_civicrm_install() {
  _sgpmembership_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function sgpmembership_civicrm_uninstall() {
  _sgpmembership_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function sgpmembership_civicrm_enable() {
  _sgpmembership_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function sgpmembership_civicrm_disable() {
  _sgpmembership_civix_civicrm_disable();
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
function sgpmembership_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _sgpmembership_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function sgpmembership_civicrm_managed(&$entities) {
  _sgpmembership_civix_civicrm_managed($entities);
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
function sgpmembership_civicrm_caseTypes(&$caseTypes) {
  _sgpmembership_civix_civicrm_caseTypes($caseTypes);
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
function sgpmembership_civicrm_angularModules(&$angularModules) {
_sgpmembership_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function sgpmembership_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _sgpmembership_civix_civicrm_alterSettingsFolders($metaDataFolders);
}
