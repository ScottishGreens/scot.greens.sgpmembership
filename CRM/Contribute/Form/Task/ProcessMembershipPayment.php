<?php
/*
 */
class CRM_Contribute_Form_Task_ProcessMembershipPayment extends CRM_Contribute_Form_Task {

  /**
   * Build all the data structures needed to build the form.
   *
   * @return void
   */
  public function preProcess() {
    CRM_Core_Error::debug_log_message("Pre Process Membership Payment");
    parent::preProcess();
    // we have all the contribution ids, so now we get the contact ids
    parent::setContactIDs();
    $count = count($this->_contributionIds);
    foreach ($this->_contributionIds as $contrib_id) {
      CRM_Core_Error::debug_log_message("Process Membership Payment for {$contrib_id}");
      $ll = new CRM_Utils_SGP_RecurringContribution(array(
        'contribution_id' => $contrib_id,
      ));
      $res = $ll->processRC();
    }

    CRM_Core_Error::debug_log_message("Finished. {$count} payments processed.");

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
