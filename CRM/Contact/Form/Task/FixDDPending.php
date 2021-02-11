<?php
/*
 */
class CRM_Contact_Form_Task_FixDDPending extends CRM_Contact_Form_Task {

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

      $recur = civicrm_api3('ContributionRecur', 'get', [
        'sequential' => 1,
        'return' => 'id',
        'contact_id' => $contact_id,
      ]);

      foreach ($recur['values'] as $r) {
        CRM_Core_Error::debug_log_message("Processing {$contact_id} Recurring Contribution {$r['id']}");
        CRM_Utils_SGP_RecurringContribution::fixPendingDDPayments($r['id']);
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
