<?php

require_once 'sgpmembership.civix.php';

function sgpmembership_civicrm_searchTasks($objectType, &$tasks) {
  if($objectType=='contact') {
    array_push($tasks, array(
      'title' => "SGP - Refresh Memberships", 
      'class' => "CRM_Contact_Form_Task_UpdateMemberships", 
    ));
    array_push($tasks, array(
      'title' => "SGP - Fix Missing Memberships",
      'class' => "CRM_Contact_Form_Task_FixMissingMemberships", 
    ));
    array_push($tasks, array(
      'title' => "SGP - Fix Membership Types", 
      'class' => "CRM_Contact_Form_Task_FixMembershipTypes", 
    ));
    array_push($tasks, array(
      'title' => "SGP - Fix Recurring Contributions", 
      'class' => "CRM_Contact_Form_Task_FixRecurringContributions", 
    ));
    array_push($tasks, array(
      'title' => "SGP - Set Recurring Contributions Source", 
      'class' => "CRM_Contact_Form_Task_SetRecurringContributionsSource", 
    ));
/*    array_push($tasks, array(
      'title' => "SGP - Fix DD Pending Payments", 
      'class' => "CRM_Contact_Form_Task_FixDDPending", 
    )); */
  }
  if($objectType=='contribution') {
    array_push($tasks, array(
      'title' => "SGP - Generate Recurring Contribution", 
      'class' => "CRM_Contribute_Form_Task_GenerateRecurringPayment", 
    ));
    array_push($tasks, array(
      'title' => "SGP - Process Member Dues", 
      'class' => "CRM_Contribute_Form_Task_ProcessMemberDues", 
    ));
  }
  
}

function sgpmembership_civicrm_post($op, $objectName, $id, &$params) {

  if($op == 'create') {

    switch ($objectName) {

      case 'ContributionRecur':

        Civi::log()->debug("TRIGGER - NEW RECURRING CONTRIBUTION {$id}");

        CRM_Utils_SGP_RecurringContribution::setSource($id);

      //
      // PROCESS PAYPAL / SO MEMBERS DUES ON IMPORT OR MANUAL CREATION
      //

      case 'Contribution':

        // Check not a test payment and status is completed
        if ($params->is_test !== 0 &&
            $params->contribution_status_id == 1) {

          // Get Member Dues financial type
          $ft_memberdues = civicrm_api3('FinancialType', 'get', ['sequential' => 1,'return' => ["name"],'id' => $params->financial_type_id, ]);

          if ($ft_memberdues['values'][0]['name'] !== 'Member Dues') {
            Civi::log()->debug("Not Member Dues");
            return;
          }

            $method_name = civicrm_api3('OptionValue', 'get', ['sequential' => 1,'return' => ["name"],'option_group_id.name' => "payment_instrument",'value' => $params->payment_instrument_id, ]);

            if (strtolower($method_name['values'][0]['name']) == 'standing order' ||
                strtolower($method_name['values'][0]['name']) == 'paypal' ) {

                Civi::log()->debug("TRIGGER - NEW MEMBERS DUES CONTRIBUTION {$id}");

                // If this payment is paypal or standing order, process it
                // Link this Contribution to a matching RC
                CRM_Utils_SGP_Contribution::linkContributionToRC($id);

                // Refresh all Memberships for this contact
                CRM_Utils_SGP_Membership::refreshAll($params->contact_id);

            }
            else {
              Civi::log()->debug("Payment Method is {$method_name['values'][0]['name']} (ID: {$params->payment_instrument_id}) so will not be processed");
            }

        }

        break;

    }

  }

  if($op == 'edit' || $op == 'create') {

    switch ($objectName) {

      case 'Membership':

        Civi::log()->debug("TRIGGER - MEMBERSHIP SAVE {$id}");

        $mem = new CRM_Utils_SGP_Membership();
        $mem->setMembershipFields($params->contact_id);
        break;


      case 'ContributionRecur':
      
        // Fetch the RC 
        $result = civicrm_api3('ContributionRecur', 'get', [
          'sequential' => 1,
          'id' => $id,
          'financial_type_id' => "Member Dues",
          'payment_instrument_id' => ['IN' => ["Paypal", "Standing Order"]],
        ]);

        if ($result['count'] > 0) {

          Civi::log()->debug("TRIGGER - PAYPAL/SO DUES CONTRIBUTION SAVE {$id}");

          // If the RC is Member Dues and Paypal or Standing Orders update a linked membership

          $mem = CRM_Utils_SGP_Membership::updateEndDateFromRC($id);

        }

    }

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
