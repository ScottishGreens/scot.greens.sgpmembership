<?php
/*
 */
class CRM_Contribute_Form_Task_GenerateRecurringPayment extends CRM_Contribute_Form_Task {

  /**
   * Build all the data structures needed to build the form.
   *
   * @return void
   */
  public function preProcess() {

    parent::preProcess();
    // we have all the contribution ids, so now we get the contact ids
    parent::setContactIDs();
    
    $count = count($this->_contributionIds);
    $rows = array();

    foreach ($this->_contributionIds as $contrib_id) {
      Civi::log()->debug("Processing Contribution {$contrib_id}");
      $rows[] = CRM_Utils_SGP_RecurringContribution::generate($contrib_id);
    }
    
    $this->assign('count', $count);
    $this->assign('rows', $rows);

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
