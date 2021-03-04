<?php
/*
 */
class CRM_Contribute_Form_Task_ProcessMemberDues extends CRM_Contribute_Form_Task {

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
      Civi::log()->debug("Processing Contribution Member Dues {$contrib_id}");
      CRM_Utils_SGP_Contribution::linkContributionToRC($contrib_id);

      $contrib = civicrm_api3('ContributionRecur', 'get', [
        'sequential' => 1,
        'return' => 'contact_id',
        'id' => $contrib_id,
      ]);

      if (isset($contrib['values'][0]['contact_id'])) {
        $mem = new CRM_Utils_SGP_Membership();
        $res[] = $mem->refreshAll($contrib['values'][0]['contact_id']);
      }

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
