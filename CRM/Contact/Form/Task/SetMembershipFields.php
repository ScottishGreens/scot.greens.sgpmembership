<?php
/*
 */
class CRM_Contact_Form_Task_SetMembershipFields extends CRM_Contact_Form_Task {

  /**
   * Build all the data structures needed to build the form.
   *
   * @return void
   */
  public function preProcess() {
    //CRM_Core_Error::debug_log_message("preProcess");
    parent::preProcess();

    foreach ($this->_contactIds as $contact_id) {
      
      $result = civicrm_api3('Membership', 'get', array(
        'sequential' => 1,
        'return' => "id",
        'contact_id' => $contact_id,
        'membership_type_id' => array('IN' => array('1','2','8','9','10','11','13')),
        'options' => array('sort' => "id ASC"),
      ));

      if ($result['count'] > 0) {
        foreach ($result['values'] as $membership) {
          CRM_Core_Error::debug_log_message("Set Membership Fields for {$membership['id']}");
          $ll = new CRM_Utils_SGP_SetMembershipFields(
            array(
              'membership_id' => $membership['id'],
            )
          );
          $res = $ll->run();
        }
      }
      
    }

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
