<?php
/*
 */
class CRM_Contact_Form_Task_FixMissingMemberships extends CRM_Contact_Form_Task {

  /**
   * Build all the data structures needed to build the form.exi
   *
   * @return void
   */
  public function preProcess() {
    parent::preProcess();

    $count = count($this->_contactIds);

    foreach ($this->_contactIds as $contact_id) {

      CRM_Core_Error::debug_log_message("Processing {$contact_id}");

      $recurs = CRM_Utils_SGP_RecurringContribution::getMembersDuesRecurringContributions($contact_id);

      foreach ($recurs as $r) {

        // Fetch any linked memberships

        $memberships = civicrm_api3('Membership', 'get', [
          'sequential' => 1,
          'return' => 'id',
          'contri' => $contact_id,
          'contribution_recur_id' => $r['id'],
        ]);

        if ($memberships['count'] == 0) {

          // If there are no linked memberships, create one
          CRM_Core_Error::debug_log_message("Processing {$contact_id} Recuyrring Contribution {$r['id']}");
          $mem = new CRM_Utils_SGP_Membership();
          $res[] = $mem->fixMissingMembership($r['id']);

        }

      }

    }
    $this->assign('count', $count);
    $this->assign('rows', $res);

    CRM_Core_Error::debug_log_message("Finished. {$count} contacts processed.");

  }

  /**
   * Build the form object.
   *
   *
   * @return void
   */
  public function buildQuickForm() {
  }

  /**
   * Process the form after the input has been submitted and validated.
   *
   *
   * @return void
   */
  public function postProcess() {
  }

}
