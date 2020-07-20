<?php

  class CRM_Utils_SGP_RecurringContribution {

    var $contribution_id = NULL;
    var $contribution = array();
    var $recurring_contribution = array();

    /**
     * @param array $params
     */
    public function __construct($params) {
      foreach ($params as $name => $value) {
        $this->$name = $value;
        if ($this->debug) CRM_Core_Error::debug_var("{$name} ",$value);
      }
    }

    public function generate() {

        if (isset($this->contribution_id) &&
            is_numeric($this->contribution_id)) {

            // Get Contribution
            $this->contribution = $this->getContributionInfo($this->contribution_id);

            // If there is a Recurring Contribution ID here, there is nothing to do. If not, continue
            if ( !isset($this->contribution['recurring_contribution_id']) ||
                $this->contribution['recurring_contribution_id'] !== '') {

                // Lets look for other Recurring Contributions that might match this 
                $this->recurring_contribution = $this->getMatchingRecurringContribution($this->contribution);

                $this->matching_contributions = $this->getMatchingContributions($this->contribution);

                if ( isset($this->recurring_contribution['id'])) {
                    // If we find one, add this payment to it, we're done 

                    if ($this->debug) CRM_Core_Error::debug_log_message("Recurring Contribution already exists");

                     $this->setRecurringContributionIDs($this->matching_contributions, $this->recurring_contribution['id']);
                }
                else {
                    // If we don't find one, make one, add all matching payments to it. 

                    if ($this->debug) CRM_Core_Error::debug_log_message("Creating Recurring Contribution");

                    $recur_attrs = $this->determineRecurringAttributes($this->matching_contributions);

                    $this->recurring_contribution = $this->createRecurringContribution($recur_attrs);

                    $this->setRecurringContributionIDs($this->matching_contributions, $this->recurring_contribution['id']);

                }

            }
            else {
                if ($this->debug) CRM_Core_Error::debug_log_message("Contact ID {$this->contribution['contact_id']}: Recurring payment already exists");
            }

          return $msg;

        }
        else {

            CRM_Core_Error::debug_log_message("Contribution ID not set");

        }

    }



    function getContributionInfo($contrib_id) {
        
        if ($this->debug) CRM_Core_Error::debug_log_message("Getting Contribution info");
        
        $contrib = civicrm_api3('Contribution', 'get', [
          'sequential' => 1,
          'return' => ["receive_date",'contact_id','contribution_recur_id',"total_amount","financial_type_id", "payment_instrument_id"],
          'id' => $contrib_id
        ]); 

        if ($contrib['count'] > 0) return $contrib['values'][0];
        else return false;

    }



    function getMatchingRecurringContribution($contribution){

        $contrib_params = array(
            'sequential' => 1,
            'contact_id' => $contribution['contact_id'],
            'financial_type_id' => $contribution['financial_type_id'],
            'payment_instrument_id' => $contribution['payment_instrument_id'],
            'amount' => $contribution['total_amount'],
            'contribution_status_id' => ['NOT IN' => ["Cancelled"]],
            'is_test' => "0",
        );

        if ($this->debug) CRM_Core_Error::debug_var("Get matching Recurring Contribution: ",$contrib_params);

        $contribrecur = civicrm_api3('ContributionRecur', 'get', $contrib_params);

        if ($contribrecur['count'] > 0) return $contribrecur['values'][0];
        else return false;

    }



    function setRecurringContributionIDs($contribs, $recur_id){

        if (is_array($contribs) &&
            is_numeric($recur_id)) {

            foreach ($contribs as $contr) {

                if ($this->debug) CRM_Core_Error::debug_log_message("Updating Payment {$contr['contribution_id']} with Recurring Contribution {$recur_id}");

                // Get other payments and link them to this recurring ID
                $contrib_get = civicrm_api3('Contribution', 'create', [
                  'sequential' => 1,
                  'id' => $contr['contribution_id'],
                  'contribution_recur_id' => $recur_id,
                ]);

            }

        }
        else {
            CRM_Core_Error::debug_log_message("No Contribution ID");
        }

    }


    function getPaymentProcessor($payment_instrument_id) {

        $paymentprocessor = civicrm_api3('PaymentProcessor', 'get', [
            'sequential' => 1,
            'payment_instrument_id' => $payment_instrument_id,
            'domain_id' => 1,
            'is_active' => 1,
            'is_test' => 0,
        ]);

        if ($paymentprocessor['count'] > 0) {
            return $paymentprocessor['values'][0]['id'];
        }
        else {
            CRM_Core_Error::debug_log_message("No Payment Processor for {$this->payment_instrument_id}");
            return false;
        }

    }

    function getMatchingContributions($contrib) {

         $matching_contribs_params = array(
            'sequential' => 1,
            'return' => ["receive_date","id"],
            'contribution_recur_id' => ['IS NULL' => 1],
            'contact_id' => $contrib['contact_id'],
            'amount' => $contrib['total_amount'],
            'financial_type_id' => $contrib['financial_type_id'],
            'payment_instrument_id' => $contrib['payment_instrument_id'],
            'options' => ['sort' => "receive_date DESC", 'limit' => 200],
        );

        $matching_contribs = civicrm_api3('Contribution', 'get', $matching_contribs_params); 

        if ($matching_contribs['count'] > 0) {
            return $matching_contribs['values'];
        }
        else {
            CRM_Core_Error::debug_log_message("No matching contributions for {$contrib['id']}");
            return false;
        }

    }

    function createRecurringContribution($contrib) {

        // calculate frequency, start, and end of recurring contribution

        $payment_processor_id = $this->getPaymentProcessor($contrib['payment_instrument_id']);

        $contrib_params = array(
            'sequential' => 1,
            'contact_id' => $contrib['contact_id'],
            'frequency_interval' => "1",
            'amount' => $contrib['total_amount'],
            'currency' => "GBP",
            'contribution_status_id' => "In Progress",
            'start_date' => $contrib['recurring_contribution_start'],
            'next_sched_contribution_date' => $recur['recurring_contribution_next'],
            'next_sched_contribution' => $recur['recurring_contribution_next'],
            'frequency_unit' => $contrib['frequency_unit'],
            'financial_type_id' => $contrib['financial_type_id'],
            'payment_instrument_id' => $contrib['payment_instrument_id'],
            'payment_processor_id' => $payment_processor_id,
            'is_email_receipt' => 0,
        );

        CRM_Core_Error::debug_var("Creating Recurring Contribution: ",$contrib_params);

        $contribrecur = civicrm_api3('ContributionRecur', 'create', $contrib_params );

        if ($contribrecur['count'] > 0) {
            return $contribrecur['values'][0]['id'];
        }
        else {
            CRM_Core_Error::debug_log_message("Failed to create Recurring Contribution");
            return false;
        }

    }


    function determineRecurringAttributes($contribs) {

        if (is_array($contribs)) {

            // Find frequency by getting average interval between receive dates

            $intervals = array();

            foreach ($contribs as $key => $contrib) {

                // Go through all contributions

                if ($this->debug) CRM_Core_Error::debug_var("$contrib",$contrib);

                // 
                if(isset($contribs['values'][($key+1)])) {
                    $intervals[] = abs(strtotime($contribs['values'][$key]['receive_date']) - strtotime($contribs['values'][($key+1)]['receive_date']));
                }

                // Find First and Last Contributions

                if (!isset($recur['recurring_contribution_start']) ||
                    $contribs['values'][$key]['receive_date'] < $recur['recurring_contribution_start']) {

                    $recur['recurring_contribution_start'] = $contribs['values'][$key]['receive_date'];

                    if ($this->debug) CRM_Core_Error::debug_log_message("New Start Date {$contribs['values'][$key]['receive_date']}");
                }

                if (!isset($recur['recurring_contribution_start']) ||
                    $contribs['values'][$key]['receive_date'] > $recur['recurring_contribution_end']) {

                    $recur['recurring_contribution_end'] = $contribs['values'][$key]['receive_date'];

                    if ($this->debug) CRM_Core_Error::debug_log_message("New End Date {$contribs['values'][$key]['receive_date']}");
                    

                }
            }

            // calculate frequency

            $date_average = array_sum($intervals) / count($intervals);
            $interval_average_days = $date_average / (3600 * 24);

            if ($this->debug) CRM_Core_Error::debug_log_message("Payment frequency unit {$interval_average_days} days");
            
            if (isset($interval_average_days) && $interval_average_days < 300 && $interval_average_days !== 0) {
                $recur['frequency_unit'] = 'month';
            }
            else {
                $recur['frequency_unit'] = 'year';
            }

        }
        else {
            // If there are no other payments we can assume this is a yearly payment
            $recur['frequency_unit'] = 'year';
            $recur['recurring_contribution_start'] = $contrib['receive_date'];
            $recur['recurring_contribution_end'] = $contrib['receive_date'];
        }

        // calculate next payment date

        $date_obj = date_create_from_format('YmdHis', $recur['recurring_contribution_end']);
        if (!$date_obj) $date_obj = date_create_from_format('Y-m-d H:i:s', $recur['recurring_contribution_end']);

        $new_date_obj = date_modify($date_obj,'+1 ' . $recur['frequency_unit']);

        $recur['next_sched_contribution_date'] =  $new_date_obj->format('Y-m-d H:i:s');

        // assign other attributes

        $recur['contact_id'] =  $contrib['contact_id'];
        $recur['amount'] =  $contrib['total_amount'];
        $recur['financial_type_id'] =  $contrib['financial_type_id'];
        $recur['payment_instrument_id'] =  $contrib['payment_instrument_id'];

        return $recur;

    }


}

