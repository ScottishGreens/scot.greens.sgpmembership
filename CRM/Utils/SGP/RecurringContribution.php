<?php

  class CRM_Utils_SGP_RecurringContribution {


    public function generate($contribution_id) {

        // Get Recurring Transaction ID Custom Field
        $recur_transaction_id_field = CRM_Utils_SGP_Contribution::getTransactionIDCustomField();

        // Get Member Dues financial type
        $ft_memberdues = civicrm_api3('FinancialType', 'get', [
          'sequential' => 1,
          'return' => ["id"],
          'name' => "Member Dues",
        ]);

        // If neither exist, we bounce
        if ($ft_memberdues['count'] == 0 || is_null($recur_transaction_id_field)) {
            Civi::log()->debug("Custom field error");
            return;
        }

        // Get Contribution
        Civi::log()->debug("Getting Contribution info");

        $contrib = civicrm_api3('Contribution', 'get', [
          'sequential' => 1,
          'return' => ["receive_date",'contact_id','contribution_recur_id',"total_amount","financial_type_id", "payment_instrument_id", "trxn_id", $recur_transaction_id_field],
          'id' => $contribution_id
        ]); 


        // If no matching contribution for some reason, we bounce
        if ($contrib['count'] == 0 || 
            $contrib['error'] == 1) {
            Civi::log()->debug("No Contribution");
            return false;
        }

        $contrib = $contrib['values'][0];

        // Get Payment Instrument/Method

        $payment_instrument = civicrm_api3('OptionValue', 'get', [
          'sequential' => 1,
          'return' => ["label", "value"],
          'option_group_id.name' => "payment_instrument",
          'value' => $contrib['payment_instrument_id'],
        ]);

        switch ($payment_instrument['values'][0]['label']) {

            case 'Direct Debit':
            case 'Electronic Direct Debit':
                $trxn_ids = explode('/',$contrib['trxn_id']);
                $recurring_transaction_id = $trxn_ids[0];
                break;
            case 'Paypal':
            case 'Standing Order':
                $recurring_transaction_id = $contrib[$recur_transaction_id_field];
                break;
            default:
                Civi::log()->debug("This is not a recurring payment");
                return false;
        }

        Civi::log()->debug("contrib: ", $contrib);

        // If Contribution has no transaction ID, we bounce
        if (!isset($contrib[$recur_transaction_id_field])) {
            Civi::log()->debug("No Transaction ID");
            return false;
        }

        // If Recurring ID is set for this Contribution, return that
        if (!isset($contrib['contribution_recur_id']) ||
            !empty($contrib['contribution_recur_id']) ) {
            Civi::log()->debug("Recurring Contribution ID already set {$contrib['contribution_recur_id']}");
            return $contrib['contribution_recur_id'];
        }


        // If we can find a matching Recurring Contribution ID is set for this Contribution, return that
        $recurring_match = CRM_Utils_SGP_RecurringContribution::fetchMatching($contrib['contact_id'], $contrib[$recur_transaction_id_field]);

        if (is_numeric($recurring_match) ) {
            Civi::log()->debug("Recurring Contribution match found {$recurring_match}");
            CRM_Utils_SGP_Contribution::updateRecurIDs($contrib['contact_id'], $contrib[$recur_transaction_id_field],$recurring_match);
            return $recurring_match;
        }

        // Get Domain
        $domain = CRM_Core_BAO_Domain::getDomain();

        Civi::log()->debug("Domain ID {$domain->id}");

        // Create a Recurring Contribution with these 

        $recur_attributes = array();

        // Contact
        $recur_attributes['contact_id'] =  $contrib['contact_id'];

        // Set Financials
        $recur_attributes['currency'] =  $contrib['currency'];
        $recur_attributes['amount'] =  $contrib['total_amount'];
        $recur_attributes['financial_type_id'] =  $contrib['financial_type_id'];
        $recur_attributes['contribution_type_id'] =  $contrib['financial_type_id'];
        $recur_attributes['payment_instrument_id'] =  $contrib['payment_instrument_id'];

        // IDs
        $recur_attributes['trxn_id'] =  $contrib[$recur_transaction_id_field];
        $recur_attributes['processor_id'] =  $contrib[$recur_transaction_id_field];
        $recur_attributes['invoice_id'] =  $contrib[$recur_transaction_id_field];

        // Frequency
        $recur_attributes['frequency_unit'] 
            = CRM_Utils_SGP_RecurringContribution::calculateFrequency(
                $contrib['contact_id'], 
                $contrib[$recur_transaction_id_field],
                $recur_transaction_id_field);
        $recur_attributes['frequency_interval'] = 1;

        // Set Dates
        $recur_attributes['create_date'] = CRM_Utils_Date::currentDBDate();

        // Get First Contribution
        $first_contrib = civicrm_api3('Contribution', 'get', [
            'sequential' => 1,
            'return' => ["receive_date"],
            'contact_id' => $contrib['contact_id'],
            $recur_transaction_id_field => $contrib[$recur_transaction_id_field],
            'options' => ['sort' => "receive_date ASC"],
        ]); 
        $recur_attributes['start_date'] = $first_contrib['values'][0]['receive_date'];

        // Get Latest Contribution
        $latest_contrib = civicrm_api3('Contribution', 'get', [
            'sequential' => 1,
            'return' => ["receive_date"],
            'contact_id' => $contrib['contact_id'],
            $recur_transaction_id_field => $contrib[$recur_transaction_id_field],
            'options' => ['sort' => "receive_date DESC"],
        ]); 
        $recur_attributes['modified_date'] = $latest_contrib['values'][0]['receive_date'];

        // Set Next Contribution
        $recur_attributes['next_sched_contribution'] = CRM_Utils_SGP_RecurringContribution::generateNextDate($contrib['receive_date'],$recur_attributes['frequency_unit']);
        $recur_attributes['next_sched_contribution_date'] = $recur_attributes['next_sched_contribution'];

        // Set Payment Processor
        $pp_params = array(
            'payment_instrument_id' => $contrib['payment_instrument_id'],
            'domain_id' => $domain->id,
            'is_active' => 1,
            'is_test' => 0,
        );
        $pp_def = array();
        $pp = CRM_Financial_BAO_PaymentProcessor::retrieve($pp_params, $pp_def);
        $recur_attributes['payment_processor_id'] = $pp->id;

        // Other Attributes
        $recur_attributes['is_test'] =  0;
        $recur_attributes['is_email_receipt'] =  0;
        $recur_attributes['failure_count'] =  0;
        $recur_attributes['auto_renew'] =  0;
        $recur_attributes['contribution_status_id'] = "In Progress";

        Civi::log()->debug("Creating Recurring Contribution");
        CRM_Core_Error::debug_var('recur_attributes', $recur_attributes);

        try {
            $contribrecur = civicrm_api3('ContributionRecur', 'create', $recur_attributes );
        }
        catch (CiviCRM_API3_Exception $e) { 
            CRM_Core_Error::debug_var("Error: ",$e); 
        }

        // Link other Contributions to this RC
        CRM_Utils_SGP_Contribution::updateRecurIDs($contrib['contact_id'], $contrib[$recur_transaction_id_field],$contribrecur['id']);

        // Fetch similar Membership
        $mem = new CRM_Utils_SGP_Membership();
        $membership_id = $mem->fetchMatching($contribrecur['id']);

        if (is_numeric($membership_id)) {
            // If we find one, set the Recurring ID and update it
            $mem->setRecurringID($membership_id, $contribrecur['id']);
            $mem->refresh($membership_id);

        }
        else {
            // else, generate Linked Membership
            $mem->generate($contribrecur['id']);
        }

        return $contribrecur['id'];

    }



    // setFrequency()

    public function setFrequency($recurring_contribution_id) {

        Civi::log()->debug("RC {$recurring_contribution_id} - set Frequency");

        // Get Recurring Transaction ID Custom Field
        $recur_transaction_id_field = CRM_Utils_SGP_Contribution::getTransactionIDCustomField();

        // Fetch RC
        $rc_current = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'id' => $recurring_contribution_id,
        ) );

        Civi::log()->debug("RC {$recurring_contribution_id} {$rc_current['values'][0]['contact_id']} {$rc_current['values'][0]['trxn_id']}");

        // Calc Frequency
        $rc_current['values'][0]['frequency_unit'] 
            = CRM_Utils_SGP_RecurringContribution::calculateFrequency(
                $rc_current['values'][0]['contact_id'], 
                $rc_current['values'][0]['trxn_id'],
                $recur_transaction_id_field);

        // Set frequency
        $rc = civicrm_api3('ContributionRecur', 'create', $rc_current['values'][0]);

    }


    // removeUnmatchedPayments()

    // Find payments linked to this Rc that do not have the correct transaction ID and unlink them.

    public function removeUnmatchedPayments($recurring_contribution_id) {

        Civi::log()->debug("RC {$recurring_contribution_id} - remove unmatched payments");

        $txn_custom_field = CRM_Utils_SGP_Contribution::getTransactionIDCustomField();

        // If neither exist, we bounce
        if (is_null($txn_custom_field)) {
            Civi::log()->debug("RC {$recurring_contribution_id} - No Transaction ID Custom Field");
            return;
        }

        // Fetch RC transaction ID
        $rc_trxid = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'return' => ['trxn_id'],
            'id' => $recurring_contribution_id
        ) );

        if (!isset($rc_trxid['values'][0]['trxn_id'])) {
            Civi::log()->debug("RC {$recurring_contribution_id} - No Transaction ID");
            return;
        }

        // Get payments linked to this RC that do NOT have the matching transaction ID and unlink them from this RC
        $contribs = civicrm_api3('Contribution', 'get', [
          'sequential' => 1,
          $txn_custom_field =>  ['!=' => $rc_trxid['values'][0]['trxn_id']],
          'contribution_recur_id' => $recurring_contribution_id,
          'api.Contribution.create' => ['contribution_recur_id' => ''],
        ]); 

    }


    // setTransactionID()

    // To set the transaction ID for a Recurring Contribution we find a linked Contribution and fetch the Recurring Transaction ID from it. This Recurring Transaction ID for Paypal or Standing Order payments is included on the import.


    public function setTransactionID($recurring_contribution_id) {

        Civi::log()->debug("Set transaction ID for {$recurring_contribution_id}");

        $txn_custom_field = CRM_Utils_SGP_Contribution::getTransactionIDCustomField();

        // If neither exist, we bounce
        if (is_null($txn_custom_field)) {
            Civi::log()->debug("No Transaction ID Custom Field");
            return;
        }

        // Fetch RC
        $contribrecur_get = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'return' => ['frequency_unit', 'amount', 'contact_id', 'id', 'frequency_interval', 'trxn_id'],
            'id' => $recurring_contribution_id
        ) );

        if (!empty($contribrecur_get['values'][0]['trxn_id'])) {
            Civi::log()->debug("Transaction ID already exists {$contribrecur_get['values'][0]['trxn_id']}");
            return;
        }

        // Get latest linked payment
        $contribs = civicrm_api3('Contribution', 'get', [
          'sequential' => 1,
          'options' => ['limit' => 1],
          'return' => ['id',$txn_custom_field],
          'contribution_recur_id' => $recurring_contribution_id,
          'options' => ['sort' => "receive_date DESC"],
        ]); 

        // If neither exist, we bounce
        if (!isset($contribs['values'][0][$txn_custom_field])) {
            Civi::log()->debug("No matching contributions with transaction_id");
            return;
        }

        $recurring_transaction_id = $contribs['values'][0][$txn_custom_field];

        $recurr_create_params = $contribrecur_get['values'][0];
        $recurr_create_params['trxn_id'] = $recurring_transaction_id;


        Civi::log()->debug("Set transaction ID to {$recurring_transaction_id}");

        $recurr = civicrm_api3('ContributionRecur', 'create', $recurr_create_params); 

        return true;

    }

    public function getMembersDuesRecurringContributions($contact_id) {

        Civi::log()->debug("Fetch Members Dues RC for {$contact_id} ");

        $recurr = civicrm_api3('ContributionRecur', 'get', [
          'sequential' => 1,
          'contact_id' => $contact_id,
          'financial_type_id' => "Member Dues",
        ]); 

        if ($recurr['count'] == 0) {
            Civi::log()->debug("No matching RCs ");
            return false;
        }

        return $recurr['values'];

    }

    public function fetchMatching($contact_id, $transaction_id) {

        Civi::log()->debug("Fetch matching RC for {$contact_id} {$transaction_id} ");

        $recurr = civicrm_api3('ContributionRecur', 'get', [
          'sequential' => 1,
          'contact_id' => $contact_id,
          'trxn_id' => $transaction_id,
        ]); 

        return $recurr['values'][0]['id'];

    }


    function calculateFrequency($contact_id, $transaction_id, $recur_transaction_id_field) {

        Civi::log()->debug("Calculating frequency");


        Civi::log()->debug("Determine Recurring Attributes: ", array('sequential' => 1,
          'return' => ["receive_date",'contact_id','contribution_recur_id',"total_amount","financial_type_id", "payment_instrument_id", ],
          'contact_id' => $contact_id,
          'options' => ['limit' => 1000],
          $recur_transaction_id_field => $transaction_id,));


        $contribs = civicrm_api3('Contribution', 'get', [
          'sequential' => 1,
          'return' => ["receive_date",'contact_id','contribution_recur_id',"total_amount","financial_type_id", "payment_instrument_id", ],
          'contact_id' => $contact_id,
          'options' => ['limit' => 1000],
          $recur_transaction_id_field => $transaction_id,
        ]); 

        // Get all Contributions with this Recurring Transaction ID

        //Civi::log()->debug("Determine Recurring Attributes: ", $contribs);

        if (is_array($contribs) &&
            count($contribs) > 1) {

            // Find frequency by getting average interval between receive dates

            $intervals = array();

            foreach ($contribs['values'] as $key => $contrib) {
                // Go through all contributions
                if(isset($contribs['values'][($key+1)])) {
                    $intervals[] = abs(strtotime($contribs['values'][$key]['receive_date']) - strtotime($contribs['values'][($key+1)]['receive_date']));
                }
            }

            // calculate frequency
            if (is_array($intervals)) {
                $date_average = array_sum($intervals) / count($intervals);
                $interval_average_days = $date_average / (3600 * 24);
                Civi::log()->debug("Payment frequency {$interval_average_days} days");
            }
            
            if (isset($interval_average_days) && 
                $interval_average_days < 40 && 
                $interval_average_days !== 0) {
                $frequency = 'month';
            }
            else {
                $frequency = 'year';
            }

        }
        else {
            $frequency = 'year';
        }

        Civi::log()->debug("Frequency unit {$frequency}");

        return $frequency;

    }


    public function update($recurring_contribution_id) {

        Civi::log()->debug("UPDATING RECURRING CONTRIBUTION {$recurring_contribution_id}");

        // Fetch RC
        $contribrecur_get = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'return' => ['frequency_unit', 'amount', 'contact_id', 'id', 'frequency_interval'],
            'id' => $recurring_contribution_id
        ) );

        // Fetch Latest Linked Payment
        $latest_contrib = civicrm_api3('Contribution', 'get', [
            'sequential' => 1,
            'return' => ["id", "receive_date"],
            'contribution_recur_id' => $recurring_contribution_id,
            'is_test' => 0,
            'contribution_status_id' => "Completed",
            'options' => ['sort' => "receive_date DESC", 'limit' => 1],
        ]);

        // If no linked contributions, bounce
        if ($latest_contrib['count'] == 0 ) {
            Civi::log()->debug("RC {$recurring_contribution_id} - No linked contributions");
            return;
        }
        
        Civi::log()->debug("RC {$recurring_contribution_id} - Latest Contribution is {$latest_contrib['values'][0]['receive_date']}");

        $next_date = CRM_Utils_SGP_RecurringContribution::generateNextDate(
            $latest_contrib['values'][0]['receive_date'],
            $contribrecur_get['values'][0]['frequency_unit']);

        Civi::log()->debug("RC {$recurring_contribution_id} - Next payment date is {$next_date}");

        $contribution_set_params = $contribrecur_get['values'][0];
        $contribution_set_params['next_sched_contribution'] = $next_date;
        $contribution_set_params['next_sched_contribution_date'] = $next_date;
        $contribution_set_params['modified_date'] = $latest_contrib['values'][0]['receive_date'];

        try {

            $contribrecur_set = civicrm_api3('ContributionRecur', 'create', $contribution_set_params);
        }

        catch (CiviCRM_API3_Exception $e) { CRM_Core_Error::debug_var("Error: ",$e); }

        return true;

    }


    public function generateNextDate($date,$interval) {

        $date_obj = date_create_from_format('YmdHis', $date);
        if (!$date_obj) $date_obj = date_create_from_format('Y-m-d H:i:s', $date);

        $next_payment_date_obj = date_modify($date_obj,'+1 ' . $interval);

        return $next_payment_date_obj->format('Y-m-d H:i:s');

    }

    public function fix($recurring_contribution_id) {

        CRM_Utils_SGP_RecurringContribution::setTransactionID($recurring_contribution_id);

        $recurr_get = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'id' => $recurring_contribution_id,
        ));

        // Fix unmatched contacts

        $unmatched_contact_get = civicrm_api3('Contribution', 'get', array(
            'sequential' => 1,
            'contact_id' => ['!=' => $recurr_get['values'][0]['contact_id']],
            'contribution_recur_id' => $recurring_contribution_id,
            'options' => ['limit' => 1000],
        ));
        
        Civi::log()->debug("Mismatched on contact", $unmatched_contact_get);

        $unmatched_financial_type_get = civicrm_api3('Contribution', 'get', array(
            'sequential' => 1,
            'financial_type_id' => ['!=' => $recurr_get['values'][0]['financial_type_id']],
            'contribution_recur_id' => $recurring_contribution_id,
            'options' => ['limit' => 1000],
        ));

        Civi::log()->debug("Mismatched on financial type", $unmatched_financial_type_get);

        $unmatched_payment_instrument_get = civicrm_api3('Contribution', 'get', array(
            'sequential' => 1,
            'payment_instrument_id' => ['!=' => $recurr_get['values'][0]['payment_instrument_id']],
            'contribution_recur_id' => $recurring_contribution_id,
            'options' => ['limit' => 1000],
        ));

        Civi::log()->debug("Mismatched on payment instrument", $unmatched_payment_instrument_get);

        $unmatched_amount_get = civicrm_api3('Contribution', 'get', array(
            'sequential' => 1,
            'total_amount' => ['!=' => $recurr_get['values'][0]['amount']],
            'contribution_recur_id' => $recurring_contribution_id,
            'options' => ['limit' => 1000],
        ));

        Civi::log()->debug("Mismatched on amount", $unmatched_amount_get);

        $unmatched_contributions = array_merge(
            $unmatched_contact_get['values'], 
            $unmatched_financial_type_get['values'], 
            $unmatched_payment_instrument_get['values'], 
            $unmatched_amount_get['values']);

        $contrib_ids = array();

        foreach ($unmatched_contributions as $contrib) {

            Civi::log()->debug("Mismatched Contribution: {$contrib['id']}");
            $contrib_ids[] = $contrib['id'];
        }

        if (sizeof($contrib_ids) == 0) {
            Civi::log()->debug("No unmatched contributions for recurring_contribution_id {$recurring_contribution_id}");
            return false;
        }

        $contrib_set = civicrm_api3('Contribution', 'get', array(
            'sequential' => 1,
            'id' => ['IN' => $contrib_ids],
            'options' => ['limit' => 1000],
            'api.Contribution.create' => ['contribution_recur_id' => ''],
        ));

        Civi::log()->debug("Cleared Recurring IDs", $contrib_set);

    }


    function getRCSourceCustomField() {

        // Get Recurring Transaction ID Custom Field
        $cf_res = civicrm_api3('CustomField', 'get', [
          'sequential' => 1,
          'label' => "Recurring Payment Source",
          'custom_group_id.extends' => "ContributionRecur",
        ]);

        Civi::log()->debug("Recurring Payment Source CustomField custom_{$cf_res['id']}");

        return "custom_".$cf_res['id'];

    }


    public function setSource($recurring_contribution_id) {

        $recur_source_field = CRM_Utils_SGP_RecurringContribution::getRCSourceCustomField();

        if (!isset($recur_source_field)) {
            Civi::log()->debug("No Custom Field");
            return false;
        }

        $contrib_get = civicrm_api3('Contribution', 'get', array(
            'sequential' => 1,  
            'return' => ["contribution_source","contribution_page_id"],
            'contribution_recur_id' => $recurring_contribution_id,
            'options' => array('sort' => "receive_date ASC", 'limit' => 1),
        ));

        if (!isset($contrib_get['values'][0])) {
            Civi::log()->debug("No Contributions");
            return false;
        }

        $contrib_page_get = civicrm_api3('ContributionPage', 'get', [
          'sequential' => 1,
          'return' => ["title"],
          'id' => $contrib_get['values'][0]['contribution_page_id'],
        ]);

        // If the Contribution Souce is SDCR (generic Smart Debit collection code)  we use the contribution page name as a backup

        if ($contrib_get['values'][0]['contribution_source'] == '[SDCR]') {
            $first_payment_source = $contrib_page_get['values'][0]['title'];
        }
        else {
            $first_payment_source = $contrib_get['values'][0]['contribution_source'];
        }

        Civi::log()->debug("Source: {$first_payment_source} ");

        $contribrecur_get = civicrm_api3('ContributionRecur', 'get', array(
            'sequential' => 1,
            'id' => $recurring_contribution_id,
            'api.ContributionRecur.create' => [$recur_source_field => $first_payment_source],
        ) );


    }
        

    /*
    First DD Contributions should be set to pending rather than completed.
    */
    public function fixPendingDDPayments($recurring_contribution_id) {

        $recur_source_field = CRM_Utils_SGP_RecurringContribution::getRCSourceCustomField();

        if (!isset($recur_source_field)) {
            Civi::log()->debug("No Custom Field");
            return false;
        }

        $contrib_get = civicrm_api3('Contribution', 'get', array(
            'sequential' => 1,  
            'return' => ["trxn_id"],
            'payment_instrument_id' => ["Direct Debit", "Electronic Direct Debit"],
            'contribution_recur_id' => $recurring_contribution_id,
            'options' => array('sort' => "receive_date ASC", 'limit' => 1),
        ));

        if (!isset($contrib_get['values'][0])) {
            Civi::log()->debug("No Contributions");
            return false;
        }

        /* If the transaction ID does not contain a / character then it isn't a 'real' payment */

        if (strpos($contrib_get['values'][0]['trxn_id'],'/') == 0) {

            $contrib_set = civicrm_api3('Contribution', 'create', array(
                'sequential' => 1,  
                'id' => $contrib_get['values'][0]['id'],
                'contribution_status_id' => "Pending",
            ));

        } 
        else {
            
            Civi::log()->debug("Not a pending payment, Txn: {$contrib_get['values'][0]['trxn_id']}");
            return false;

        }


    }
        


}

