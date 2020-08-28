<?php
/*
 */
class CRM_Contact_Form_Task_UpdateMembership extends CRM_Contact_Form_Task {

  /**
   * Build all the data structures needed to build the form.
   *
   * @return void
   */
  public function preProcess() {
    //CRM_Core_Error::debug_log_message("preProcess");
    parent::preProcess();


    $count = count($this->_contactIds);

    foreach ($this->_contactIds as $contact_id) {
      
		CRM_Core_Error::debug_log_message("Updating Membership for {$contact_id}");
		$ll = new CRM_Utils_SGP_Membership(
			array(
			  'contact_id' => $contact_id,
//			  'debug' => true,
			)
		);
		$res[] = $ll->updateMembership();

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
