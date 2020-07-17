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

      //CRM_Core_Error::debug_log_message("Setting Membership Fields for contact {$this->contact_id}");


      if (isset($this->contact_id) &&
          is_numeric($this->contact_id)) {

        $this->custom_fields = $this->getCustomFields();
        $this->membership_optionvalues = $this->getMembershipStatuses();

        // GET LAST MEMBER DUES PAYMENT

        $this->membership_payment_method = $this->getMembershipPaymentMethod($this->contact_id);

        if (isset($this->membership_payment_method) &&
              $this->membership_payment_method !== FALSE ) {

          // IF WE HAVE A MEMBERS DUES PAYMENT, CONTINUE
        
          if (strtolower($this->membership_payment_method) == 'electronic direct debit')
            $this->membership_payment_method = 'direct debit';

          // GET ALL MEMBERSHIPS


          $this->first_membership = $this->getLastMembership($this->contact_id);
          $this->last_membership = $this->getLastMembership($this->contact_id);

          if (isset($this->last_membership['id']) &&
              is_numeric($this->last_membership['id'])) {

            $is_member = 0;

            switch ($this->last_membership['status_id.name']) {

               case 'Pending':
                $this->last_membership['status_id.name'] = 'New';
               case 'New':
               case 'Current':
               case 'Grace - Pending':
               case 'Grace':
                $is_member = 1;
                break;
               case 'Deceased':
               case 'Cancelled':
               case 'Expired':
               default:
                $is_member = 0;
                break;

            }

            if ($this->debug) CRM_Core_Error::debug_log_message("Is Member? {$is_member}");

            $update_params = array(
              'sequential' => 1,
              'id' => $this->contact_id,
            );

            if (isset($this->custom_fields['memberexpiry'])) {

              if ($is_member == 1) {
                $update_params[$this->custom_fields['memberexpiry']] = NULL;
              }
              else {
                $update_params[$this->custom_fields['memberexpiry']] = $this->last_membership['end_date'];
              }

            } 

            if (isset($this->custom_fields['memberjoin']))
              $update_params[$this->custom_fields['memberjoin']] = $this->first_membership['start_date'];

            if (isset($this->custom_fields['memberactive']))
              $update_params[$this->custom_fields['memberactive']] = $is_member;

            if (isset($this->custom_fields['membershippaymentmethod'])) 
              $update_params[$this->custom_fields['membershippaymentmethod']] = $this->membership_payment_method;

            if (isset($this->custom_fields['memberstatus']))
              $update_params[$this->custom_fields['memberstatus']] = $this->last_membership['status_id.name'];

            try {
              if ($this->debug) CRM_Core_Error::debug_var("Setting Membership fields",$update_params);
              $result = civicrm_api3('Contact', 'create', $update_params);

            }
            catch (CiviCRM_API3_Exception $e) {
              CRM_Core_Error::debug_var("Error: ",$e);
            }

          }


        }
        else {

          if ($this->debug) CRM_Core_Error::debug_log_message("No Members Dues payment for Contact: {$this->contact_id}");

          $update_params = array(
            'sequential' => 1,
            'id' => $this->contact_id,
          );

          $update_params[$this->custom_fields['memberexpiry']] = NULL;
          $update_params[$this->custom_fields['memberjoin']] = NULL;
          $update_params[$this->custom_fields['memberactive']] = 0;
          $update_params[$this->custom_fields['membershippaymentmethod']] = NULL;
          $update_params[$this->custom_fields['memberstatus']] = NULL;

          try {
            if ($this->debug) CRM_Core_Error::debug_var("Setting Membership fields",$update_params);
            $result = civicrm_api3('Contact', 'create', $update_params);

          }
          catch (CiviCRM_API3_Exception $e) {
            CRM_Core_Error::debug_var("Error: ",$e);
          }

        }

      }

  }

  function getFirstMembership($contact_id) {

    if (isset($contact_id) &&
        is_numeric($contact_id)) {

      if ($this->debug) CRM_Core_Error::debug_log_message("Getting First Membership for {$this->contact_id}");

      
      // Get Payment Method
      try {
        $result = civicrm_api3('Membership', 'get', array(
          'sequential' => 1,
          'return' => ["status_id.name", "end_date", "start_date"],
          'contact_id' => $contact_id,
          'financial_type_id' => "Member Dues",
          'options' => array('sort' => "end_date DESC", 'limit' => 1),
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Error::debug_var("Error: ",$e);
      }

      if ($this->debug) CRM_Core_Error::debug_var("First Membership: ", $result);

      if ($result['count'] > 0) return $result['values'][0];
      else return false;

    }

  }


  function getLastMembership($contact_id) {

    if (isset($contact_id) &&
        is_numeric($contact_id)) {

      if ($this->debug) CRM_Core_Error::debug_log_message("Getting Latest Membership for {$this->contact_id}");

      
      // Get Payment Method
      try {
        $result = civicrm_api3('Membership', 'get', array(
          'sequential' => 1,
          'return' => ["status_id.name", "end_date", "start_date"],
          'contact_id' => $contact_id,
          'financial_type_id' => "Member Dues",
          'options' => array('sort' => "end_date DESC", 'limit' => 1),
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Error::debug_var("Error: ",$e);
      }

      if ($this->debug) CRM_Core_Error::debug_var("Latest Membership: ", $result);

      if ($result['count'] > 0) return $result['values'][0];
      else return false;

    }

  }


  function getMembershipPaymentMethod($contact_id) {

    if (isset($contact_id) &&
        is_numeric($contact_id)) {

      if ($this->debug) CRM_Core_Error::debug_log_message("Getting Membership Payment Method for {$this->contact_id}");

      // Get Payment Method
      try {
        $result = civicrm_api3('Contribution', 'get', array(
          'sequential' => 1,
          'return' => ['id','payment_instrument'],
          'contact_id' => $contact_id,
          'financial_type_id' => "Member Dues",
          'options' => array('sort' => "receive_date DESC", 'limit' => 1),
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Error::debug_var("Error: ",$e);
      }

      /*
      if ($result['count'] == 0) {
        // If no membership payments, check for recurring payments. Direct debit memberships are created before payment is taken.
        try {
           $result = civicrm_api3('ContributionRecur', 'get', [
            'sequential' => 1,
            'contact_id' => $contact_id,
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
      } */


      if ($this->debug) CRM_Core_Error::debug_var("Last Members Dues: ", $result);

      if ($result['count'] > 0) return strtolower($result['values'][0]['payment_instrument']);
      else return false;

    }

  }


  function getMembershipStatuses(){
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

  function getCustomFields() {

    //CRM_Core_Error::debug_log_message("Getting Custom Fields");
    $result = NULL;

    try {
      $result = civicrm_api3('CustomField', 'get', array(
        'sequential' => 1,
        'return' => "id,label",
        'label' => array('IN'
            => array("Member Status", "Member Join Date", "Member Expiry Date","Member Payment Method","Payment Method","Member Payment Frequency","Is Member")),
      ));
    }
    catch (CiviCRM_API3_Exception $e) {
      CRM_Core_Error::debug_var("Error: ",$e);
    }
    
    $custom_fields = array();

    if ( isset($result) && $result['count'] > 0 ) {
      foreach ($result['values'] as $field) {
        switch ($field['label']) {
          case 'Is Member':
            $custom_fields['memberactive'] = 'custom_' . $field['id'];
            //CRM_Core_Error::debug_log_message("Current field: {$custom_fields['memberactive']}");
            break;

          case 'Member Status':
            $custom_fields['memberstatus'] = 'custom_' . $field['id'];
            //CRM_Core_Error::debug_log_message("Member Status field: {$custom_fields['memberstatus']}");
            break;

          case 'Member Join Date':
            $custom_fields['memberjoin'] = 'custom_' . $field['id'];
            //CRM_Core_Error::debug_log_message("Member Join field: {$custom_fields['memberjoin']}");
            break;

          case 'Member Expiry Date':
            $custom_fields['memberexpiry'] = 'custom_' . $field['id'];
            //CRM_Core_Error::debug_log_message("Member Expiry field: {$custom_fields['memberexpiry']}");
            break;

          case 'Member Payment Method':
            $custom_fields['membershippaymentmethod'] = 'custom_' . $field['id'];
            //CRM_Core_Error::debug_log_message("Contact Payment Method field: {$custom_fields['membershippaymentmethod']}");
            break;

          case 'Member Payment Frequency':
            //$custom_fields['membershippaymentfreq'] = 'custom_' . $field['id'];
            //CRM_Core_Error::debug_log_message("Member Payment Frequency field: {$custom_fields['membershippaymentmethod']}");
            break;

        }
      }
    }

    return $custom_fields;
  }


}