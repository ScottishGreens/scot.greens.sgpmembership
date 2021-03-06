<?php

  class CRM_Utils_SGP_Membership {

    /**
     * @param array $params
     */
    public function __construct() {
    }

    public function generate($recurring_contribution_id) {

        Civi::log()->debug("Creating Membership, RC ID: {$recurring_contribution_id}");

        // Fetch Recurring Contribution
        $contribrecur_get = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'id' => $recurring_contribution_id
        ) );

        $recurr = $contribrecur_get['values'][0];

        // Fetch Membership Type
        $membershiptype_id = CRM_Utils_SGP_Membership::fetchMembershipType($recurr);

        if (!isset($membershiptype_id)) {
            Civi::log()->debug("No Membership Type");
            return;
        }

        // Membershp Params
        $membership_params = array(
          'sequential' => 1,
          'skipStatusCal' => 0,
          'contact_id' => $recurr['contact_id'],
          'join_date' => $recurr['create_date'],
          'start_date' => $recurr['start_date'],
          'end_date' => $recurr['next_sched_contribution'],
          'is_test' => 0,
          'source' => "Membership Bot",
          'status_id' => 2,
          'is_override' => 0,
          'is_pay_later' => 0,
          'contribution_recur_id' => $recurring_contribution_id,
          'membership_type_id'  => $membershiptype_id,
        );

        CRM_Core_Error::debug_var("membership_params: ",$membership_params);

        $membership_result = civicrm_api3('Membership', 'create', $membership_params);
        

    }

    public function fetchMatching($recurring_contribution_id) {

        Civi::log()->debug("Fetch Matching Membershp for RC {$recurring_contribution_id} and Transaction ID {$transaction_id}");

        // Get Recurring Transaction ID Custom Field
        $transaction_id_field = CRM_Utils_SGP_Membership::getTransactionIDCustomField();

        if (!isset($transaction_id_field)) {
            return;
        }

        // Fetch Recurring Contribution
        $contribrecur_get = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'id' => $recurring_contribution_id
        ) );

        $membership_params = array(
            'sequential' => 1,
            'return' => 'is_override',
            'contact_id' => $contribrecur_get['values'][0]['contact_id'],
            'membership_type_id.duration_unit' => $contribrecur_get['values'][0]['frequency_unit'],
            $transaction_id_field =>  $contribrecur_get['values'][0][$transaction_id_field],
            'is_test' => 0,
            'status_id' => ['NOT IN' => ["Cancelled", "Deceased"]],
        );

        $membership = civicrm_api3('Membership', 'get', $membership_params );

        if ($membership['error'] || $membership['count'] == 0  || $membership['values'][0]['is_override'] == 1 ) {
             Civi::log()->debug("No active membership");
            return false;
        }

        Civi::log()->debug("Fetched Membership {$membership['id']}");
        return $membership['id'];

    }

    public function fetchMembershipType($recurr) {

       // Fetch matching Membership Type

        if ( ($recurr['frequency_unit'] == 'month' && $recurr['amount'] < 1)
            || ($recurr['frequency_unit'] == 'year' && $recurr['amount'] < 6) ) {
                $membership_rate = "Concession";
            }
            else {
                $membership_rate = "Standard";
        }

        $membershiptype = civicrm_api3('MembershipType', 'get', array(
          'sequential' => 1,
          'duration_unit' => $recurr['frequency_unit'],
          'financial_type_id' => $recurr['financial_type_id'],
          'name' => ['LIKE' => "%" . $membership_rate . "%"],
          'options' => ['limit' => 1],
        ));

        return $membershiptype['id'];

    }

    public function fixMissingMembership($recurring_contribution_id) {

        // Fetch similar Membership
        $membership_id = CRM_Utils_SGP_Membership::fetchMatching($recurring_contribution_id);

        if (is_numeric($membership_id)) {

            Civi::log()->debug("Matching membership exists {$membership_id}");
 
            // If we find one, set the Recurring ID and update it
            CRM_Utils_SGP_Membership::setRecurringID($membership_id, $recurring_contribution_id);
            CRM_Utils_SGP_Membership::refresh($membership_id);

        }
        else {

            Civi::log()->debug("Creating membership for {$recurring_contribution_id}");

            // else, generate Linked Membership
            CRM_Utils_SGP_Membership::generate($recurring_contribution_id);
        }

    }


    public function fixMembershipType($membership_id) {

        Civi::log()->debug("Fix Membership {$membership_id}");

        // Get Membership

        $membership_get = civicrm_api3('Membership', 'get', array(
            'sequential' => 1,
            'id' => $membership_id,
            'contribution_recur_id' => ['IS NOT NULL' => 1],
        ));

        if ($membership_get['count'] == 0) {
            Civi::log()->debug("Membership does not exist or lacks a Recurring Payment ID {$membership_id}");
            return false;
        }

        // Get Linked Recurring Contribution

        $contribrecur_get = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'contact_id' => $membership_get['values'][0]['contact_id'],
            'id' => $membership_get['values'][0]['contribution_recur_id']
        ) );

        if ($contribrecur_get['count'] == 0) {
            Civi::log()->debug("ContributionRecur does not exist, ID {$membership_get['values'][0]['contribution_recur_id']}, Contact {$membership_get['values'][0]['contact_id']}");
            return false;
        }

        // Check Frequency Matches

        if ($membership_get['values'][0]['membership_type_id.duration_unit'] == $contribrecur_get['values'][0]['frequency_unit']) {

            // If it matches, our work here id done

            Civi::log()->debug("ContributionRecur ({$contribrecur_get['values'][0]['frequency_unit']}) matches Membership ({$membership_get['values'][0]['membership_type_id.duration_unit']})");

            return true;

        }
        else {

            // If not, lets change the membership type

            Civi::log()->debug("Updating membership type to {$contribrecur_get['values'][0]['frequency_unit']}");

            // Fetch Membership Type
            $membershiptype_id = CRM_Utils_SGP_Membership::fetchMembershipType($contribrecur_get['values'][0]);

            // Update Membership
            $membership_params = $membership_get['values'][0];
            $membership_params['skipStatusCal'] = 0;
            $membership_params['membership_type_id'] = $membershiptype_id;

            CRM_Core_Error::debug_var("membership_params: ",$membership_params);

            $membership_set = civicrm_api3('Membership', 'create', $membership_params);

        }



    }

    public function setRecurringID($membership_id, $recurring_contribution_id) {

        Civi::log()->debug("setRecurringID {$recurring_contribution_id} for Membership {$membership_id}");

        // Update Membership
        $membership_get = civicrm_api3('Membership', 'get', array(
            'sequential' => 1,
            'return' => ["contact_id", "membership_type_id","join_date", "start_date", "end_date"],
            'id' => $membership_id,
        ));

        $membership_params = $membership_get['values'][0];
        $membership_params['skipStatusCal'] = 1;
        $membership_params['contribution_recur_id'] = $recurring_contribution_id;

        CRM_Core_Error::debug_var("membership_params: ",$membership_params);

        $membership_set = civicrm_api3('Membership', 'create', $membership_params);

        CRM_Core_Error::debug_var("membership_params: ",$membership_set);


    }

    public function refreshAll($contact_id) {

      Civi::log()->debug("REFRESHING ALL MEMBERSHIPS FOR {$contact_id}");

      $memberships = civicrm_api3('Membership', 'get', [
        'sequential' => 1,
        'return' => 'id',
        'contact_id' => $contact_id,
      ]);

      foreach ($memberships['values'] as $m) {
       CRM_Core_Error::debug_log_message("Processing {$contact_id} Membership {$m['id']}");
        $res[] = CRM_Utils_SGP_Membership::refresh($m['id']);
      }

      return $res;

    }


    public function refresh($membership_id) {

        Civi::log()->debug("REFRESHING MEMBERSHIP {$membership_id}");

        // Fetch Membership
        $membership = civicrm_api3('Membership', 'get', array(
            'sequential' => 1,
            'id' => $membership_id,
            'return' => ["id","is_override","membership_type_id.duration_unit", "contribution_recur_id","contact_id", "membership_type_id","join_date", "start_date", "end_date"],
            'is_test' => 0,
            'status_id' => ['NOT IN' => ["Cancelled", "Deceased"]],
        ) );

        //

        if ($membership['error'] 
          || $membership['count'] == 0
          || $membership['values'][0]['is_override'] == 1) {
             Civi::log()->debug("Membership {$membership_id} - No active membership");
            return false;
        }

        if  (is_numeric($membership['values'][0]['contribution_recur_id'])) {

          // If this is a membership with a Recurring Contribution, update it so it has the correct end dates
          CRM_Utils_SGP_RecurringContribution::update($membership['values'][0]['contribution_recur_id']);


          // If it is a Direct Debit membership we need to refresh the Membership end date.

          $mem = CRM_Utils_SGP_Membership::updateEndDateFromRC($membership['values'][0]['contribution_recur_id']);

          
          return true;

        }
        else {  

            // We don't currenly refresh manual memberships
             Civi::log()->debug("Membership {$membership_id} - No linked recurring contribution");
            return false;

        }


    }


    public function updateEndDateFromRC($recurring_contribution_id) {

        $rc = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'id' => $recurring_contribution_id
        ) );

        $membership = civicrm_api3('Membership', 'get', array(
            'sequential' => 1,
            'contact_id' => $rc['values'][0]['contact_id'],
            'contribution_recur_id' => $recurring_contribution_id,
            'is_test' => 0,
            'status_id' => ['NOT IN' => ["Cancelled", "Deceased"]]
        ) );

        if ($membership['count'] == 0 
          || $membership['values'][0]['is_override'] == 1 
          || $rc['error'] 
          || $rc['count'] == 0 ) {
             Civi::log()->debug("No active membership");
            return false;
        }

        Civi::log()->debug("New Membership end date for ID {$membership['values'][0]['id']} is {$rc['values'][0]['next_sched_contribution_date']}");

        $membership_set_params = $membership['values'][0];
        $membership_set_params['skipStatusCal'] = 0;

        if ($rc['values'][0]['create_date'] < $rc['values'][0]['start_date']) {
          // If the RC creation date is before the start date, we use it as the join date.
          $membership_set_params['join_date'] = $rc['values'][0]['create_date'];
        }
        else {
          // Else we use the RC start date for join date
          $membership_set_params['join_date'] = $rc['values'][0]['start_date'];
        }

        $membership_set_params['start_date'] =  $rc['values'][0]['start_date'];
        $membership_set_params['end_date'] =  $rc['values'][0]['next_sched_contribution_date'];

        $membership_set = civicrm_api3('Membership', 'create', $membership_set_params);
        
        return true;

    }



    function getTransactionIDCustomField() {

        // Get Recurring Transaction ID Custom Field
        $cf_res = civicrm_api3('CustomField', 'get', array(
          'sequential' => 1,
          'label' => "Payment Reference",
          'custom_group_id.extends' => "membership",
        ));

        Civi::log()->debug("RC Transaction ID CustomField custom_{$cf_res['id']}");

        return "custom_".$cf_res['id'];

    }


    public function setMembershipFields($contact_id) {

        Civi::log()->debug("Setting Membership Fields for {$contact_id}");

        $custom_fields = CRM_Utils_SGP_Membership::getMembershipCustomFields();

        $update_params = array(
          'sequential' => 1,
          'id' => $contact_id,
        );

        $update_params[$custom_fields['memberexpiry']] = '';
        $update_params[$custom_fields['memberjoin']] = '';
        $update_params[$custom_fields['membershippaymentmethod']] = '';
        $update_params[$custom_fields['memberstatus']] = '';


        $membership_optionvalues = CRM_Utils_SGP_Membership::getMembershipStatuses();

        // GET FIRST MEMBERSHIP FOR START DATE
        $first_membership = CRM_Utils_SGP_Membership::getFirstMembership($contact_id);

        if (is_numeric($first_membership['id']))
          $update_params[$custom_fields['memberjoin']] = $first_membership['join_date'];

        // GET LAST MEMBERSHIP FOR STATUS AND END DATE
        $last_membership = CRM_Utils_SGP_Membership::getLastMembership($contact_id);

        if (isset($last_membership['id']) &&
          is_numeric($last_membership['id'])) {

            if ($last_membership['status_id.name'] == 'Pending') {

                $last_membership['status_id.name'] = 'New';

            }

            $update_params[$custom_fields['memberstatus']] = $last_membership['status_id.name'];

            // Generate expiry date by adding 3 months of grace onto the end date of the membership
            $date_obj = date_create_from_format('YmdHis', $last_membership['end_date']);
            if (!$date_obj) $date_obj = date_create_from_format('Y-m-d', $last_membership['end_date']);
            if (!$date_obj) $date_obj = date_create_from_format('Y-m-d H:i:s', $last_membership['end_date']);
            $expiry_date_obj = date_modify($date_obj,'+3 months');
            if ($expiry_date_obj !== FALSE) {
              $update_params[$custom_fields['memberexpiry']] = $expiry_date_obj->format('Y-m-d');
            }

        } 


        // GET LAST MEMBER DUES PAYMENT FOR METHOD

        $membership_payment_method = CRM_Utils_SGP_Membership::getMembershipPaymentMethod($contact_id);

        if (isset($membership_payment_method) &&
              $membership_payment_method !== FALSE ) {

          if (strtolower($membership_payment_method) == 'electronic direct debit')
            $membership_payment_method = 'direct debit';

          $update_params[$custom_fields['membershippaymentmethod']] = $membership_payment_method;

        }

        Civi::log()->debug("Update params: ", $update_params);

        try {
          $result = civicrm_api3('Contact', 'create', $update_params);

        }
        catch (CiviCRM_API3_Exception $e) {
          CRM_Core_Error::debug_var("Error: ",$e);
        }


  }

  function getFirstMembership($contact_id) {

    if (isset($contact_id) &&
        is_numeric($contact_id)) {
      
      // Get Payment Method
      try {
        $result = civicrm_api3('Membership', 'get', array(
          'sequential' => 1,
          'contact_id' => $contact_id,
          'return' => ["status_id.name", "end_date", "start_date", "join_date"],
          "membership_type_id.member_of_contact_id" => 1,
          'membership_type_id.financial_type_id' => "Member Dues",
          'options' => array('sort' => "join_date ASC", 'limit' => 1),
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Error::debug_var("Error: ",$e);
      }

      if ($result['count'] > 0) return $result['values'][0];
      else return false;

    }

  }


  function getLastMembership($contact_id) {

      // Get Payment Method
      try {
        $result = civicrm_api3('Membership', 'get', array(
          'sequential' => 1,
          'contact_id' => $contact_id,
          'return' => ["status_id.name", "end_date", "start_date", "join_date"],
          "membership_type_id.member_of_contact_id" => 1,
          'membership_type_id.financial_type_id' => "Member Dues",
          'options' => array('sort' => "end_date DESC", 'limit' => 1),
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Error::debug_var("Error: ",$e);
      }


      if ($result['count'] > 0) return $result['values'][0];
      else return false;


  }


  function getMembershipPaymentMethod($contact_id) {

      // Get Payment Method
      try {
        $result = civicrm_api3('Contribution', 'get', array(
          'sequential' => 1,
          'return' => ['id','payment_instrument'],
          'contribution_status_id' => ["Completed", "Pending"],
          'contact_id' => $contact_id,
          'financial_type_id' => "Member Dues",
          'options' => array('sort' => "receive_date DESC", 'limit' => 1),
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Error::debug_var("Error: ",$e);
      }

      if ($result['count'] > 0) return strtolower($result['values'][0]['payment_instrument']);
      else return false;

  }


  function getMembershipStatuses() {

    $result = civicrm_api3('OptionGroup', 'get', array(
      'sequential' => 1,
      'return' => "id",
      'title' => "Member Status",
      'api.OptionValue.get' => array('return' => "value,label", 'option_group_id' => "\$value.id"),
    ));

    foreach ($result['values'][0]["api.OptionValue.get"]['values'] as $option) {
      $membership_optionvalues[strtolower($option['label'])] = $option['value'];
    }

    return $membership_optionvalues;

  }

  function getMembershipCustomFields() {

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

