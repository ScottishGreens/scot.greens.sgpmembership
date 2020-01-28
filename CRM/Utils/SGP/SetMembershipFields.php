<?php

  class CRM_Utils_SGP_SetMembershipFields {

    var $id = NULL;
    var $membership_id = NULL;
    var $membership_start_date = NULL;
    var $membership_expiry_date = NULL;
    var $contact_id = NULL;
    var $contact_start_date = NULL;
    var $contact_expiry_date = NULL;
    var $membership_status = NULL;
    var $contact_status = NULL;
    var $contact_customfield = NULL;
    var $membership_optionvalues = NULL;
    var $memberactive = NULL;
    var $paymentmethod_optionvalues = NULL;
    var $custom_fields = array();
    var $parse_all = NULL;

    /**
     * @param array $params
     */
    public function __construct($params) {
      //CRM_Core_Error::debug_var("Params: ",$params);
      foreach ($params as $name => $value) {
        $this->$name = $value;
      }
    }

    /**
    * run
    **/

    public function run() {

      //CRM_Core_Error::debug_log_message("Processing Membership ID {$this->membership_id}");

      if (empty($this->custom_fields['ward'])) {
        $this->custom_fields = CRM_Utils_membership_CustomFields::getCustomFields();
      } 


      // Get Membership
      //CRM_Core_Error::debug_var("membership get");
      $params = array(
        'sequential' => 1,
        'id' => $this->membership_id,
        'return' => "status_id,contact_id,start_date,end_date,{$this->custom_fields['membershippaymentmethod']}",
        'membership_type_id' => array('IN' => array('1','2','8','9','10','11','13')),
        'api.MembershipStatus.get' => array(
          'sequential' => 1,
          'return' => "label",
          'id' => "\$value.status_id"
        ),
        'api.Contact.get' => array(
          'sequential' => 1, 
          'return' => "{$this->custom_fields['memberjoin']}, {$this->custom_fields['memberexpiry']}", 
          'id' => "\$value.contact_id"
        ),
      );
      $m_result = civicrm_api3('Membership', 'get', $params);
      
      // If we get a Membership, and we should, continue
      if ($m_result['count'] > 0 ) {

        if (empty($this->membership_optionvalues)) {
          $this->getMembershipStatuses();
          $this->getPaymentMethods();
        }

        // Process result
        $this->contact_id = $m_result['values'][0]['contact_id'];
        $this->membership_start_date = $m_result['values'][0]['start_date'];
        $this->membership_expiry_date = $m_result['values'][0]['end_date'];
        //$this->membership_payment_method = $m_result['values'][0][$this->custom_fields['membershippaymentmethod']];
        //$this->membership_payment_freq = $m_result['values'][0][$this->custom_fields['membershippaymentfreq']];
        $this->contact_start_date = $m_result['values'][0]['api.Contact.get']['values'][0][$this->custom_fields['memberjoin']];
        $this->contact_expiry_date = $m_result['values'][0]['api.Contact.get']['values'][0][$this->custom_fields['memberexpiry']];
        $this->contact_status = $m_result['values'][0]['api.Contact.get']['values'][0][$this->custom_fields['memberstatus']];
        $this->membership_status = $m_result['values'][0]['api.MembershipStatus.get']['values'][0]['label'];
        $this->membership_payment_method = $this->getMembershipPaymentMethod();
        //$this->membership_payment_freq = $this->getMembershipPaymentFrequency();
        if (strtolower($this->membership_payment_method) == 'electronic direct debit') $this->membership_payment_method = 'direct debit';
        
        //CRM_Core_Error::debug_log_message("ID {$this->membership_id}, Method: {$this->membership_payment_method}, Status: {$m_result['values'][0]['status_id']} {$this->contact_status}");

        // Does this contact have another active membership?
        $other_active_memberships = $other_grace_memberships = $other_grace_pending_memberships = false;
        $other_active_memberships = $this->ContactHasActiveMembership($this->membership_id);
        $other_grace_memberships = $this->ContactHasGraceMembership($this->membership_id);
        $other_grace_pending_memberships = $this->ContactHasGracePendingMembership($this->membership_id);
        $new_status = NULL;

        // If this membership's start date is < the contact member start date, update it
        if (empty($this->contact_start_date)
          || ($this->membership_start_date < $this->contact_start_date)) {
          $this->setStartDate();
        }

        // Switch based on the current status
        switch ($this->membership_status) {
           case 'Pending':
           case 'New':
            //CRM_Core_Error::debug_log_message("Membership - New");
             if($other_active_memberships || $other_grace_memberships || $other_grace_pending_memberships) {
              $this->setCurrent();
             }
             else {
              $this->setNew();
             }
             break;
           case 'Current':
            //CRM_Core_Error::debug_log_message("Membership - Current");
            $this->setCurrent();
            break;
           case 'Grace (Pending)':
            //CRM_Core_Error::debug_log_message("Membership - Grace (Pending)");
             if(!$other_active_memberships) {
              $this->setGracePending();
             }
             break;
           case 'Grace':
            //CRM_Core_Error::debug_log_message("Membership - Grace");
             if(!$other_active_memberships) {
              $this->setGrace();
             }
             break;
           case 'Deceased':
           case 'Cancelled':
            //CRM_Core_Error::debug_log_message("Membership - Cancelled");
             if(!$other_active_memberships && !$other_grace_memberships && !$other_grace_pending_memberships) {
              $this->setCancelled();
             }
             break;
           case 'Expired':
            //CRM_Core_Error::debug_log_message("Membership - Expired");
             if(!$other_active_memberships && !$other_grace_memberships && !$other_grace_pending_memberships) {
              $this->setExpired();
             }
             break;
         }
        $this->setPaymentFields();
      }
    }

    /**
     * @return array
     */

    public function setGrace() {
      $this->membership_status = 'Member - Grace';
      $this->memberactive = true;
      $this->removeExpiryDate();
    }
    public function setGracePending() {
      $this->membership_status =  'Member - Grace (Pending)';
      $this->memberactive = true;
      $this->removeExpiryDate();
    }
    public function setNew() {
      $this->membership_status =  'Member - New';
      $this->memberactive = true;
      $this->removeExpiryDate();
    }
    public function setCurrent() {
      $this->membership_status =  'Member - Current';
      $this->memberactive = true;
      $this->removeExpiryDate();
    }
    public function setCancelled() {
      $this->membership_status =  'Member - Cancelled';
      $this->memberactive = false;
      if (empty($this->contact_expiry_date)
        || ($this->membership_expiry_date > $this->contact_expiry_date)) {
        $this->setExpiryDate();
      }
    }
    public function setExpired() {
     $this->membership_status =  'Member - Expired';
      $this->memberactive = false;
      if (empty($this->contact_expiry_date)
        || ($this->membership_expiry_date > $this->contact_expiry_date)) {
        $this->setExpiryDate();
      }
    }

    public function setStartDate() {
      if (!empty($this->contact_id) && !empty($this->membership_start_date)) {
        try {
          //CRM_Core_Error::debug_log_message("Setting Membership Start {$this->contact_id}: {$this->membership_start_date}");
          // Set Membership Flag
          $result = civicrm_api3('Contact', 'create', array(
            'sequential' => 1,
            'contact_type' => "Individual",
            'id' => $this->contact_id,
            $this->custom_fields['memberjoin'] => $this->membership_start_date,
          ));
        }
        catch (CiviCRM_API3_Exception $e) {
          CRM_Core_Error::debug_var("Error: ",$e);
        }
      }
    }

    public function setExpiryDate() {
      if (!empty($this->contact_id) && !empty($this->membership_expiry_date)) {
        try {
          //CRM_Core_Error::debug_log_message("Setting Membership Expiry {$this->contact_id}: {$this->membership_expiry_date}");
          // Set Membership Flag
          $result = civicrm_api3('Contact', 'create', array(
            'sequential' => 1,
            'contact_type' => "Individual",
            'id' => $this->contact_id,
            $this->custom_fields['memberexpiry'] => $this->membership_expiry_date,
          ));
        }
        catch (CiviCRM_API3_Exception $e) {
          CRM_Core_Error::debug_var("Error: ",$e);
        }
      }
    }

    public function removeExpiryDate() {
      if (!empty($this->contact_id)) {
        try {
          //CRM_Core_Error::debug_log_message("Removing Membership Expiry {$this->contact_id}");
          // Set Membership Flag
          $result = civicrm_api3('Contact', 'create', array(
            'sequential' => 1,
            'contact_type' => "Individual",
            'id' => $this->contact_id,
            $this->custom_fields['memberexpiry'] => "",
          ));
        }
        catch (CiviCRM_API3_Exception $e) {
          CRM_Core_Error::debug_var("Error: ",$e);
        }
      }
    }


    public function getMembershipStatuses(){
      $result = NULL;
      $result = civicrm_api3('OptionGroup', 'get', array(
        'sequential' => 1,
        'return' => "id",
        'title' => "Member Status",
        'api.OptionValue.get' => array('return' => "value,label", 'option_group_id' => "\$value.id"),
      ));
      foreach ($result['values'][0]["api.OptionValue.get"]['values'] as $option) {
        $this->membership_optionvalues[strtolower($option['label'])] = $option['value'];
      }
      //CRM_Core_Error::debug_var("Membership Statuses: ",$this->membership_optionvalues);
    }

    /**
    * ContactHasActiveMembership
    * Find if $contact_id has an active membership (excluding $membership_id_exclude)
    **/
    public function ContactHasActiveMembership($membership_id_exclude){
        //CRM_Core_Error::debug_log_message("Checking any active memberhips for {$this->contact_id}");
        $m2_result = civicrm_api3('Membership', 'get',  array(
          'sequential' => 1,
          'contact_id' => $this->contact_id,
          'return' => "status_id,contact_id",
          'id' => array('!=' => $membership_id_exclude),
          'status_id' => array('IN' => array("New", "Current")),
          'membership_type_id' => array('IN' => array('1','2','8','9','10','11','13'))
        ));
        if ($m2_result['count'] > 0) {
          CRM_Core_Error::debug_log_message("{$this->contact_id} has other active membership");
          return true;
        }
        else return false;
    }

    /**
    * ContactHasGraceMembership
    * Find if $contact_id has an active membership (excluding $membership_id_exclude)
    **/
    public function ContactHasGraceMembership($membership_id_exclude){
        //CRM_Core_Error::debug_log_message("Checking any grace memberships for {$this->contact_id}");
        $m2_result = civicrm_api3('Membership', 'get',  array(
          'sequential' => 1,
          'contact_id' => $this->contact_id,
          'return' => "status_id,contact_id",
          'id' => array('!=' => $membership_id_exclude),
          'status_id' => "Grace",
          'membership_type_id' => array('IN' => array('1','2','8','9','10','11','13'))
        ));
        if ($m2_result['count'] > 0) {
          //CRM_Core_Error::debug_log_message("{$this->contact_id} has other grace membership");
          return true;
        }
        else return false;
    }

    /**
    * ContactHasGracePendingMembership
    * Find if $contact_id has an active membership (excluding $membership_id_exclude)
    **/
    public function ContactHasGracePendingMembership($membership_id_exclude){
        //CRM_Core_Error::debug_log_message("Checking any grace (pending) memberships for {$this->contact_id}");
        $m2_result = civicrm_api3('Membership', 'get',  array(
          'sequential' => 1,
          'contact_id' => $this->contact_id,
          'return' => "status_id,contact_id",
          'id' => array('!=' => $membership_id_exclude),
          'status_id' => "Grace - Pending",
          'membership_type_id' => array('IN' => array('1','2','8','9','10','11','13'))
        ));
        if ($m2_result['count'] > 0) {
          //CRM_Core_Error::debug_log_message("{$this->contact_id} has other grace (pending) membership");
          return true;
        }
        else return false;
    }


    /**
    * CancelOtherMemberships
    * Cancel all other non-recurring SGP Memberships for $contact_id (excluding $membership_id_exclude)
    **/

    function CancelOtherMemberships($contact_id, $membership_id_exclude){
      //CRM_Core_Error::debug_log_message("Cancelling other active memberships for {$this->contact_id}");
      $m_result = civicrm_api3('Membership', 'get',  array(
        'sequential' => 1,
        'contact_id' => $contact_id,
        'return' => "status_id,contact_id",
        'custom_73' => array('IN' => array(1, "paypal", "standing order", "cash", "Cheque")),
        'id' => array('!=' => $membership_id_exclude),
        'status_id' => array('IN' => array("New", "Current", "Grace")),
        'membership_type_id' => array('IN' => array('1','2','8','9','10','11','13'))
      ));
      if ($m_result['count'] > 0) {
        foreach ($m_result['values'] as $membership) {
          //CRM_Core_Error::debug_log_message("{$this->contact_id} cancelling Membership {$membership['id']}");
          $m2_result = civicrm_api3('Membership', 'create',  array(
            'sequential' => 1,
            'id' => $membership['id'],
            'status_id' => '6',
            'end_date' => date("Y-m-d"),
          ));
        }
      }
    }



    ///////////////////////////
    // Payment Methods
    ///////////////////////////

    public function getMembershipPaymentFrequency(){
      //CRM_Core_Error::debug_log_message("Getting Membership Payment Freq for {$this->contact_id}");
      // Get Payment Method
      try {
        $result = civicrm_api3('Membership', 'get', array(
          'sequential' => 1,
          'return' => ["membership_type_id.name", "end_date", "membership_type_id.duration_unit"],
          'contact_id' => $this->contact_id,
          'options' => ['sort' => "end_date DESC"],
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Error::debug_var("Error: ",$e);
      }
      if ($result['values'][0]['membership_type_id.duration_unit'] == 'year') {
        return 'yearly';
      }
      elseif ($result['values'][0]['membership_type_id.duration_unit'] == 'month') {
        return 'monthly';
      }
      else {
        return 'other';
      }
    }

    public function getMembershipPaymentMethod(){
      //CRM_Core_Error::debug_log_message("Getting Membership Payment Method for {$this->contact_id}");
      // Get Payment Method
      try {
        $result = civicrm_api3('Contribution', 'get', array(
          'sequential' => 1,
          'contact_id' => $this->contact_id,
          'financial_type_id' => "Member Dues",
          'options' => array('sort' => "receive_date DESC", 'limit' => 1),
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Error::debug_var("Error: ",$e);
      }
      if ($result['count'] == 0) {
        // If no membership payments, check for recurring payments. Direct debit memberships are created before payment is taken.
        try {
           $result = civicrm_api3('ContributionRecur', 'get', [
            'sequential' => 1,
            'contact_id' => $this->contact_id,
            'payment_processor_id' => 3,
          ]);
        }
        catch (CiviCRM_API3_Exception $e) {
          CRM_Core_Error::debug_var("Error: ",$e);
        }
        if ($result['count'] > 0) {
          return 'direct debit';
        }
      }
      else {
        return strtolower($result['values'][0]['payment_instrument']);     
      }
    }

    function setPaymentFields(){
      if (!empty($this->contact_id)) {
        try {
          $payment_fields_params = array(
            'sequential' => 1,
            'contact_type' => "Individual",
            'id' => $this->contact_id,
          );
          if (isset($this->memberactive))
            $payment_fields_params[$this->custom_fields['memberactive']] = $this->memberactive;
          if (isset($this->membership_payment_method))
            $payment_fields_params[$this->custom_fields['membershippaymentmethod']] = strtolower($this->membership_payment_method);
          if (isset($this->membership_status))
            $payment_fields_params[$this->custom_fields['memberstatus']] = $this->membership_optionvalues[strtolower($this->membership_status)];

          CRM_Core_Error::debug_var("Setting Membership fields",$payment_fields_params);
          // Set Membership Flag

          $result = civicrm_api3('Contact', 'create', $payment_fields_params);
        }
        catch (CiviCRM_API3_Exception $e) {
          CRM_Core_Error::debug_var("Error: ",$e);
        }
      }
    }

    public function getPaymentMethods(){
      $result = NULL;
      $result = civicrm_api3('OptionGroup', 'get', array(
        'sequential' => 1,
        'return' => "id",
        'title' => "Payment Method",
        'api.OptionValue.get' => array('return' => "value,label", 'option_group_id' => "\$value.id"),
      ));
      foreach ($result['values'][0]["api.OptionValue.get"]['values'] as $option) {
        $this->paymentmethod_optionvalues[strtolower($option['label'])] = $option['value'];
      }
      //CRM_Core_Error::debug_var("Payment Methods: ",$this->paymentmethod_optionvalues);
    }


  }