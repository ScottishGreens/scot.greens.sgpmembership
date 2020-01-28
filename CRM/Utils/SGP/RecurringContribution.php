<?php

  class CRM_Utils_GreenMembership_RecurringContribution {

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


    function createMembership() {

        CRM_Core_Error::debug_log_message("Creating Membership for {$this->contact_id}");

        // FIRST GET CORRECT MEMBERSHIP TYPE ID BASED ON AMOUNT AND FREQUENCY

        if ( ($this->frequency_unit == 'month' && $this->total_amount < 1)
            || ($this->frequency_unit == 'year' && $this->total_amount < 6) ) {
                $membership_rate = "Concession";
            }
            else {
                $membership_rate = "Standard";
        }

        $membershiptype = civicrm_api3('MembershipType', 'get', [
          'sequential' => 1,
          'duration_unit' => $this->frequency_unit,
          'financial_type_id' => $this->financial_type_id,
          'name' => ['LIKE' => "%" . $membership_rate . "%"],
          'options' => ['limit' => 1],
        ]);

        if ($membershiptype['count'] > 0) {

            CRM_Core_Error::debug_log_message("Membership Type {$membershiptype['values'][0]['id']}");
            $this->membership_type_id = $membershiptype['values'][0]['id'];

            $membership_params = array(
              'sequential' => 1,
              'return' => ["contact_id", "id", "custom_74","membership_type_id.duration_unit"],
              'contact_id' => $this->contact_id,
              'join_date' => $this->recurring_contribution_start,
              'start_date' => $this->recurring_contribution_start,
              'end_date' => $this->recurring_contribution_next,
              'is_test' => 0,
              'source' => "Membership Bot",
              'contribution_recur_id' => $this->recurring_contribution_id,
              "membership_type_id"  => $this->membership_type_id,
            );

            CRM_Core_Error::debug_var("Creating Membership params: ",$membership_params);

            $ppms = civicrm_api3('Membership', 'create', $membership_params);

            if ($ppms['count'] > 0) return $ppms['values'][0]['id'];
            else return false;
            
        }
        else {
            CRM_Core_Error::debug_log_message("No Membership Type");
            return false;
        }

    }

    function updatePaymentWithRecurContrib(){
        CRM_Core_Error::debug_log_message("Updating Payment {$this->contribution_id} with Recurring Contribution");

        // Get other payments and link them to this recurring ID
        $contrib_get = civicrm_api3('Contribution', 'get', [
          'sequential' => 1,
          'return' => ["receive_date","id"],
          "contact_id" => $this->contact_id,
          "financial_type_id" => $this->financial_type_id,
          "payment_instrument_id" => $this->payment_instrument_id,
          'options' => ['sort' => "receive_date DESC"],
          'api.Contribution.create' => ['contribution_recur_id' => $this->recurring_contribution_id],
        ]);

    }


    function createRecurringContribution() {

        // CREATE CONTRIB RECUR

        $contrib_params = array(
            'sequential' => 1,
            'contact_id' => $this->contact_id,
            'frequency_interval' => "1",
            'amount' => $this->total_amount,
            'currency' => "GBP",
            'contribution_status_id' => "In Progress",
            'start_date' => $this->recurring_contribution_start,
            'next_sched_contribution_date' => $this->recurring_contribution_next,
            'next_sched_contribution' => $this->recurring_contribution_next,
            'frequency_unit' => $this->frequency_unit,
            'financial_type_id' => $this->financial_type_id,
            'payment_instrument_id' => $this->payment_instrument_id,
            'payment_processor_id' => $this->processor_id,
        );

        CRM_Core_Error::debug_var("Creating Recurring Contribution: ",$contrib_params);

        $contribrecur = civicrm_api3('ContributionRecur', 'create', $contrib_params );

        return $contribrecur['values'][0]['id'];

    }


    function determineRecurringAttributes() {

        // If recurring contribution doesn't exist, there is a few attributes we need to figure out:
        // Start date
        // End date
        // Frequency

        $recent_contribs_params = array(
          'sequential' => 1,
          'return' => ["receive_date","id"],
          "contact_id" => $this->contact_id,
          "financial_type_id" => $this->financial_type_id,
          "payment_instrument_id" => $this->payment_instrument_id,
           'options' => ['sort' => "receive_date DESC", 'limit' => 100],
        );
        CRM_Core_Error::debug_var("recent payment params", $recent_contribs_params);

        $recent_contribs = civicrm_api3('Contribution', 'get', $recent_contribs_params); 

        if ($recent_contribs['count'] > 0) {

            // Find frequency by getting average interval between receive dates

            /*
            $average = $total / $count;
            echo "Average session duration: ".$average." seconds";

            $last_payed_date = date_create($recent_contribs['values'][0]['receive_date']);
            $interval = date_diff($this_payed_date, $last_payed_date);
            $interval_string = $interval->format("Y-m-d H:i:s");
            $number_of_days = (int) $interval->format('%a');    
            */


            $intervals = array();

            foreach ($recent_contribs['values'] as $key => $contrib) {

                CRM_Core_Error::debug_var("$contrib",$contrib);

                // frequency
                if(isset($recent_contribs['values'][($key+1)])) {
                    $intervals[] = abs(strtotime($recent_contribs['values'][$key]['receive_date']) - strtotime($recent_contribs['values'][($key+1)]['receive_date']));
                }

                // start and end

                if (!isset($this->recurring_contribution_start) ||
                    $recent_contribs['values'][$key]['receive_date'] < $this->recurring_contribution_start) {
                    CRM_Core_Error::debug_log_message("New Start Date {$recent_contribs['values'][$key]['receive_date']}");
                    $this->recurring_contribution_start = $recent_contribs['values'][$key]['receive_date'];
                }
                if (!isset($this->recurring_contribution_start) ||
                    $recent_contribs['values'][$key]['receive_date'] > $this->recurring_contribution_end) {
                    CRM_Core_Error::debug_log_message("New End Date {$recent_contribs['values'][$key]['receive_date']}");
                    $this->recurring_contribution_end = $recent_contribs['values'][$key]['receive_date'];
                }
            }

            // calculate frequency

            $date_average = array_sum($intervals) / count($intervals);
            $interval_average_days = $date_average / (3600 * 24);

            CRM_Core_Error::debug_log_message("Payment frequency unit {$interval_average_days} days");
            if (isset($interval_average_days) && $interval_average_days < 300 && $interval_average_days !== 0) {
                $this->frequency_unit = 'month';
            }
            else {
                $this->frequency_unit = 'year';
            }

            // next payment date


            $this->recurring_contribution_next = $this->moveIntervalForward();


        }
        else {
            // If there are no other payments we can assume this is a yearly payment
            $this->frequency_unit = 'year';
        }

    }

    function moveIntervalForward() {
        // Takes the receive_date and moves it forward by 1 interval, returning the new date

        $receive_date = date_create_from_format('YmdHis', $this->receive_date);
        if (!$receive_date) {
        $receive_date = date_create_from_format('Y-m-d H:i:s', $this->receive_date);
        }
        $new_end_date = date_modify($receive_date,'+1 ' . $this->frequency_unit);
        $new_end_date_string = $new_end_date->format('Y-m-d H:i:s');

        CRM_Core_Error::debug_log_message("Receive Date: {$this->receive_date}, interval: 1 {$this->frequency_unit}, new end date: {$new_end_date_string}");
        return $new_end_date_string;

    }

    function setMembershipRC() {

        // GET LATEST MATCHING MEMBERSHIP

        $ppms = civicrm_api3('Membership', 'get', [
          'sequential' => 1,
          'return' => ["contact_id", "id", "custom_74","membership_type_id","membership_type_id.duration_unit"],
          'contact_id' => $this->contact_id,
          'contribution_recur_id' => ['IS NULL' => 1],
          "membership_type_id.duration_unit"  => $this->frequency_unit,
          'options' => ['sort' => "end_date DESC", 'limit' => 1],
        ]);

        if ($ppms['count'] == 0) {
            // IF NO MEMBERSHIP WE NEED TO CREATE ONE
            // UPDATE: TO DO LATER
            //$this->membership_id = $this->createMembership();
            $this->addToErrorGroup();
        }
        else {
            $this->membership_type_id = $ppms['values'][0]['membership_type_id'];
            $this->membership_id = $ppms['values'][0]['id'];

            $membership_update_params = array(
              'sequential' => 1,
              'id' => $this->membership_id,
              'contact_id' => $this->contact_id,
              'membership_type_id' => $this->membership_type_id,
              'contribution_recur_id' => $this->recurring_contribution_id,
            );

            CRM_Core_Error::debug_var("Updating Membership: ",$membership_update_params);

            $membership_update = civicrm_api3('Membership', 'create', $membership_update_params);

        }

    }


    function setMembershipEndDate() {

        $getmembership = civicrm_api3('Membership', 'get', [
            'sequential' => 1,
            'return' => ['end_date','contribution_recur_id.frequency_unit'],
            'contact_id' => $this->contact_id,
            'contribution_recur_id.financial_type_id' => "Member Dues",
            'contribution_recur_id.payment_instrument_id' => $this->payment_instrument_id,
        ]);

        if ($getmembership['count'] > 0) {

            $this->frequency_unit = $getmembership['values'][0]['contribution_recur_id.frequency_unit'];

            $new_end_date = $this->moveIntervalForward();

            if ($new_end_date > $getmembership['values'][0]['end_date']) {  

                CRM_Core_Error::debug_log_message("Membership ID: {$getmembership['values'][0]['id']}, new End Date: {$new_end_date}");

                $result = civicrm_api3('Membership', 'create', [
                    'sequential' => 1,
                    'id' => $getmembership['values'][0]['id'],
                    'end_date' => $new_end_date,
                    'status_id' => "Current",
                ]);

            }
            else {

                CRM_Core_Error::debug_log_message("new End Date {$new_end_date} is before current end date {$getmembership['values'][0]['end_date']}");

            }
                
        }
        else {
            $this->addToErrorGroup();
        }

    }

    function addToErrorGroup() {

        CRM_Core_Error::debug_log_message("No Valid Membership for {$this->contact_id}, adding to Temp Group");
        $group_add = civicrm_api3('GroupContact', 'create', [
          'group_id' => $this->tempgroup_id,
          'contact_id' => $this->contact_id,
        ]);

    }

    function getExistingContribRecur() {

        CRM_Core_Error::debug_log_message("Get existing Recurr");

        $contribrecur = civicrm_api3('ContributionRecur', 'get',[
            'sequential' => 1,
            'contact_id' => $this->contact_id,
            'financial_type_id' => $this->financial_type_id,
            'payment_instrument_id' => $this->payment_instrument_id,
        ]);

        if ($contribrecur['count'] > 0) return $contribrecur['values'][0]['id'];
        else return false;

    }


    function getContributionInfo() {
        CRM_Core_Error::debug_log_message("Getting Contribution info");
        
        // GET CONTRIBUTION
        $contrib = array();
        $contrib = civicrm_api3('Contribution', 'get', [
          'sequential' => 1,
          'return' => ["receive_date",'contact_id','contribution_recur_id',"total_amount","financial_type_id", "payment_instrument_id"],
          'id' => $this->contribution_id
        ]); 
        $this->total_amount = $contrib['values'][0]['total_amount'];
        $this->contact_id = $contrib['values'][0]['contact_id'];
        $this->financial_type_id = $contrib['values'][0]['financial_type_id'];
        $this->payment_instrument_id = $contrib['values'][0]['payment_instrument_id'];
        $this->receive_date = $contrib['values'][0]['receive_date'];
        $this->recurring_contribution_id = $contrib['values'][0]['contribution_recur_id'];
    }

    function getTempGroup() {

        // GET TEMP GROUP

        CRM_Core_Error::debug_log_message("Get/Create Temp Group");

        $group = civicrm_api3('Group', 'get', [
           'sequential' => 1,
          'title' => "TEMP - Recurring Contribution Errors",
        ]);

        if ($group['count'] == 0) {
            CRM_Core_Error::debug_log_message("Creating Temp Group");
            $group = civicrm_api3('Group', 'create', [
              'sequential' => 1,
              'title' => "TEMP - Recurring Contribution Errors",
            ]);
        }

        $this->tempgroup_id = $group['values'][0]['id'];

    }


    function getPaymentProcessor() {

        $paymentprocessor = civicrm_api3('PaymentProcessor', 'get', [
            'sequential' => 1,
            'payment_instrument_id' => $this->payment_instrument_id,
        ]);

        if ($paymentprocessor['count'] > 0) {
            $this->processor_id = $paymentprocessor['values'][0]['id'];
        }
        else {
            CRM_Core_Error::debug_log_message("No Payment Processor for {$this->payment_instrument_id}");
        }
    }

    public function processRC() {
      $return = 0;

        $this->getTempGroup();

        if (!isset($this->payment_instrument_id)) {
            $this->getContributionInfo();
        }

        $this->getPaymentProcessor();

        CRM_Core_Error::debug_log_message("Processing payment for {$this->contact_id}, Type: {$this->payment_instrument_id}");

        if ($this->receive_date && $this->contact_id) {
            
            $this->setMembershipEndDate();

        }
        else {

            CRM_Core_Error::debug_log_message("No contact ID");

        }

      return $return;

    }

    /**
     * @return array
     */
    public function createRC() {
      $return = 0;

        $this->getTempGroup();

        // GET CONTRIBUTION

        $this->getContributionInfo();

        if ($this->recurring_contribution_id && $this->recurring_contribution_id !=="") {
            CRM_Core_Error::debug_log_message("Recurring Contribution exists for Contribution {$this->contribution_id}, Contact {$this->contact_id}");
            $this->updatePaymentWithRecurContrib();
            $this->setMembershipRC();
        }
        else {

            // GET EXISTING CONTRIB RECUR
            $this->recurring_contribution_id = $this->getExistingContribRecur();

            if ($this->recurring_contribution_id) {
                CRM_Core_Error::debug_log_message("Recurring Contribution exists");
            }

            else {

                CRM_Core_Error::debug_log_message("No Recurring Contribution");

                $this->determineRecurringAttributes();
                $this->recurring_contribution_id = $this->createRecurringContribution();

                if ($this->recurring_contribution_id) {
                    $this->setMembershipRC();
                }
                else {
                    $this->addToErrorGroup("No Recurring Contrib ID for some reason");
                }
            }
            if (isset($this->contribution_id) && isset($this->recurring_contribution_id)) {
                $this->updatePaymentWithRecurContrib();
            }
        }


/*

        $group_id = $group['values'][0]['id'];

        foreach ($ppms['values'] as $pp) {
            
            // FOR EACH MEMBERSHIP GET RECURR CONTRIB AND LINK

            CRM_Core_Error::debug_log_message("Processing {$pp['id']} for Contact {$pp['contact_id']}");

            $cr = civicrm_api3('ContributionRecur', 'get', [
                'sequential' => 1,
                'payment_instrument_id' => "Paypal",
                'financial_type_id' => "Member Dues",
                'contact_id' => $pp['contact_id'],
            ]);

            if ($cr['values'][0]['id']) {

                CRM_Core_Error::debug_log_message("Updating {$pp['id']} with ContributionRecur {$cr['values'][0]['id']}");

                $update_membership = civicrm_api3('Membership', 'create', [
                  'sequential' => 1,
                  'id' => $pp['id'],
                  'contribution_recur_id' => $cr['values'][0]['id'],
                ]);

            }
            else {

                if (isset($group_id) && isset($pp['contact_id'])) {

                    CRM_Core_Error::debug_log_message("No ContributionRecur for {$pp['contact_id']}, adding to Temp Group");
                    $group_add = civicrm_api3('GroupContact', 'create', [
                      'group_id' => $group_id,
                      'contact_id' => $pp['contact_id'],
                    ]);

                }

            }

        }
*/

      return $return;
    }

}

