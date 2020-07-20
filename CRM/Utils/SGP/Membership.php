<?php

  class CRM_Utils_SGP_Membership {

    var $contact_id = NULL;
    var $total_amount = NULL;
    var $financial_type_id = NULL;
    var $payment_instrument_id = NULL;
    var $contribution_id = NULL;
    var $processor_id = NULL;
    var $receive_date = NULL;
    var $membership_id = NULL;
    var $membership_type_id = NULL;
    var $frequency_unit = NULL;
    var $recurring_contribution_id = NULL;
    var $recurring_contribution_start = NULL;
    var $recurring_contribution_end = NULL;
    var $recurring_contribution_next = NULL;
    var $tempgroup_id = NULL;


    /**
     * @param array $params
     */
    public function __construct($params) {
      foreach ($params as $name => $value) {
        $this->$name = $value;
        CRM_Core_Error::debug_var("{$name} ",$value);
      }
    }

    public function updateMembership() {

        if (isset($this->contact_id)) {

            // Get Latest Members Dues Recurring Contribution

            $this->latest_members_dues = $this->getLatestMembershipContribution($this->contact_id);

            if (isset($this->latest_members_dues['contribution_recur_id']) &&
                is_numeric($this->latest_members_dues['contribution_recur_id'])) {


                $this->recurring_contribution = $this->getRecurringContribution($this->latest_members_dues['contribution_recur_id']);
                
                $this->membership = $this->getLinkedMembership(
                    $this->contact_id,
                    $this->latest_members_dues['contribution_recur_id']);


                // generate new end date
                $end_date = $this->moveIntervalForward(
                    $this->latest_members_dues['receive_date'],
                    $this->recurring_contribution['frequency_unit']
                );

                if ($this->membership['id']) {

                    // If linked membership, update it
                    $res = $this->setMembershipEndDate(
                        $this->membership,
                        $end_date
                    );

                }
                else {

                    // If no linked membership create one
                    $res = $this->createMembership(
                        $this->recurring_contribution,
                        $end_date
                    );

                }

            }
            else {

                if ($this->debug) CRM_Core_Error::debug_log_message("No Recurring Contribution linked to Contribution ({$this->latest_members_dues['contribution_id']})");

            }

            $mf = new CRM_Utils_SGP_SetMembershipFields( array(
                'contact_id' => $this->contact_id,
                'debug' => $this->debug
            ));
            $res = $mf->run();

        }
        else {

            if ($this->debug) CRM_Core_Error::debug_log_message("No Contact ID)");

        }


    }


    /**
     * @return array
     */
    public function getLatestMembershipContribution($contact_id) {

        if (isset($contact_id) && is_numeric($contact_id)) {

            $contribution_params = array(
                'sequential' => 1,
                'contact_id' => $contact_id,
                'financial_type_id' => "Member Dues",
                'contribution_status_id' => ["Completed", "Pending"],
                'options' => ['sort' => "receive_date DESC", 'limit' => 1],
            );

            if ($this->debug) CRM_Core_Error::debug_var("Getting latest Members Dues: ",$contribution_params);

            $contrib_res = null;
            $contrib_res = civicrm_api3('Contribution', 'get', $contribution_params );

            if ($contrib_res['count'] > 0) {
                return $contrib_res['values'][0];
            }
            else {
                if ($this->debug) CRM_Core_Error::debug_log_message("Failed to get Members Dues");
                return false;
            }

        }
        else {

            if ($this->debug) CRM_Core_Error::debug_log_message("No Contact ID {$contact_id}");

        }

    }



    /**
     * @return array
     */
    public function getRecurringContribution($recurr_id) {

        if (isset($recurr_id) && is_numeric($recurr_id)) {

            $recurr_params = array(
                'sequential' => 1,
                'id' => $recurr_id,
            );

            if ($this->debug) CRM_Core_Error::debug_var("Getting Recurring Contribution: ", $recurr_params);

            $recurr_res = null;
            $recurr_res = civicrm_api3('ContributionRecur', 'get', $recurr_params );

            if ($recurr_res['count'] > 0) {
                if ($this->debug) CRM_Core_Error::debug_var("Recurring Contribution: ", $recurr_res);
                return $recurr_res['values'][0];
            }
            else {
                if ($this->debug) CRM_Core_Error::debug_log_message("Failed to get Recurring Contribution");
                return false;
            }

        }
        else {

            if ($this->debug) CRM_Core_Error::debug_log_message("No Recurring ID {$recurr_id}");

        }
    }

    /**
     * @return array
     */
    public function getLinkedMembership($contact_id, $recurr_id) {

        if (isset($recurr_id) && is_numeric($recurr_id) &&
            isset($contact_id) && is_numeric($contact_id)) {

            $membership_params = array(
                'sequential' => 1,
                'contact_id' => $contact_id,
                'recurring_contribution_id' => $recurr_id
            );

            if ($this->debug) CRM_Core_Error::debug_var("Getting Membership: ", $membership_params);

            $membership_res = null;
            $membership_res = civicrm_api3('Membership', 'get', $membership_params );

            if ($membership_res['count'] > 0) {
                if ($this->debug) CRM_Core_Error::debug_var("Membership: ", $membership_res);
                return $membership_res['values'][0];
            }
            else {
                if ($this->debug) CRM_Core_Error::debug_log_message("Failed to get Membership");
                return false;
            }

        }
        else {

            if ($this->debug) CRM_Core_Error::debug_log_message("No IDs {$contact_id} {$recurr_id}");

        }
    }

    /**
     * @return array
     */
    public function createMembership($recurr, $end_date) {

        if (isset($recurr['id']) && is_numeric($recurr['id']) &&
            isset($end_date) && is_string($end_date)) {

            if ($this->debug) CRM_Core_Error::debug_log_message("Creating Membership for {$this->contact_id}");

            // FIRST GET CORRECT MEMBERSHIP TYPE ID BASED ON AMOUNT AND FREQUENCY

            if ( ($recurr['frequency_unit'] == 'month' && $recurr['amount'] < 1)
                || ($recurr['frequency_unit'] == 'year' && $recurr['amount'] < 6) ) {
                    $membership_rate = "Concession";
                }
                else {
                    $membership_rate = "Standard";
            }

            $membershiptype_params = array(
              'sequential' => 1,
              'duration_unit' => $recurr['frequency_unit'],
              'financial_type_id' => $recurr['financial_type_id'],
              'name' => ['LIKE' => "%" . $membership_rate . "%"],
              'options' => ['limit' => 1],
            );

            if ($this->debug) CRM_Core_Error::debug_var("Membership Type: ",$membershiptype_params);

            $membershiptype = civicrm_api3('MembershipType', 'get', $membershiptype_params);

            if ($membershiptype['count'] > 0) {

                if ($this->debug) CRM_Core_Error::debug_log_message("Membership Type {$membershiptype['values'][0]['id']}");
                $membership_type_id = $membershiptype['values'][0]['id'];

                $membership_params = array(
                  'sequential' => 1,
                  'skipStatusCal' => 0,
                  'return' => ["contact_id", "id", "custom_74","membership_type_id.duration_unit"],
                  'contact_id' => $this->contact_id,
                  'join_date' => $recurr['start_date'],
                  'start_date' => $recurr['start_date'],
                  'end_date' => $end_date,
                  'is_test' => 0,
                  'source' => "Membership Bot",
                  'contribution_recur_id' => $recurr['id'],
                  "membership_type_id"  => $membership_type_id,
                );

                if ($this->debug) $membership_params['debug'] = 1;

                if ($this->debug) CRM_Core_Error::debug_var("Creating Membership: ",$membership_params);

                $ppms = civicrm_api3('Membership', 'create', $membership_params);

                if ($ppms['count'] > 0) return $ppms['values'][0]['id'];
                else return false;
                
            }
            else {
                if ($this->debug) CRM_Core_Error::debug_log_message("No Membership Type");
                return false;
            }

        }

    }

    /**
     * @return array
     */
    public function setMembershipEndDate($membership, $end_date) {

        if (isset($membership['id']) 
            && is_numeric($membership['id'])) {

            $membership_params = array(
                'sequential' => 1,
                'skipStatusCal' => 0,
                'id' => $membership['id'],
                'join_date' => $membership['join_date'],
                'start_date' => $membership['start_date'],
                'end_date' => $end_date,
            );

            if ($this->debug) CRM_Core_Error::debug_var("Updating Membership: ",$membership_params);

            $membership_res = civicrm_api3('Membership', 'create', $membership_params );

            if ($membership_res['count'] > 0) {
                return $membership_res['values'][0]['id'];
            }
            else {
                if ($this->debug) CRM_Core_Error::debug_log_message("Failed to update Membership");
                return false;
            }

        }
        else {

            if ($this->debug) CRM_Core_Error::debug_log_message("No Membership ID");

        }

    }


    function moveIntervalForward($date,$frequency_unit) {

        if (is_string($date) && is_string($frequency_unit) && 
            in_array($frequency_unit, array('month','year'))
            ) {
            // Takes a date string and an interval string (month, day, year) and returns date string of (input date +1 interval)

            if ($this->debug) CRM_Core_Error::debug_log_message("Moving {$date} forward by 1 {$frequency_unit}");
            $date_obj = date_create_from_format('YmdHis', $date);
            if (!$date_obj) $date_obj = date_create_from_format('Y-m-d H:i:s', $date);
            $new_date_obj = date_modify($date_obj,'+1 ' . $frequency_unit);
            $new_date_string = $new_date_obj->format('Y-m-d H:i:s');
            if ($this->debug) CRM_Core_Error::debug_log_message("New date {$new_date_string}");
            return $new_date_string;

        }
        else return false;
    }

}

