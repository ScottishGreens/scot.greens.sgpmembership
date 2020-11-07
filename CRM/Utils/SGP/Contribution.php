<?php

  class CRM_Utils_SGP_Contribution {


    public function processMembershipContribution($contribution_id) {

        // Get Member Dues financial type
        $ft_memberdues = civicrm_api3('FinancialType', 'get', [
          'sequential' => 1,
          'return' => ["id"],
          'name' => "Member Dues",
        ]);

        $txn_custom_field = getTransactionIDCustomField();

        // If neither exist, we bounce
        if ($ft_memberdues['count'] == 0 || !is_null($txn_custom_field)) {
            return;
        }

        // Get Contribution
        Civi::log()->debug("Getting Contribution info");
        $contrib = civicrm_api3('Contribution', 'get', [
          'sequential' => 1,
          'return' => ["receive_date",'contact_id','contribution_recur_id',"total_amount","financial_type_id", "payment_instrument_id", $txn_custom_field],
          'id' => $contribution_id,
        ]); 

        // If it is a Membership Contribution, continue
        if ($contrib['values'][0]['financial_type_id'] == $ft_memberdues['id']) {

            // Fetch the DD payment isntrument

            $option_id_dd = civicrm_api3('OptionValue', 'get', [
              'sequential' => 1,
              'return' => ["name"],
              'option_group_id.name' => "payment_instrument",
              'label' => "Direct Debit",
            ]);

            // Set the RC transaction ID

            //If it is a DD then the TC transaction ID is the Contribution TXNID up to a '/' character
            if ($contrib['values'][0]['payment_instrument_id'] == $option_id_dd['values'][0]['option_group_id']) {
                $split = explode('/', $contrib['values'][0][$txn_custom_field]);
                $rc_transaction_id = $split[0];
            }
            else {
                $rc_transaction_id = $contrib['values'][0][$txn_custom_field];
            }

            // Fetch matching Recurring Contribution
            $contribrecur_get = civicrm_api3('ContributionRecur', 'get', array(
                'trxn_id' => $rc_transaction_id
            ) );

            $rc_id = $contribrecur_get['values'][0]['id'];

            if (is_numeric($rc_id)) {
                // If we find one, move it forward
                CRM_Utils_SGP_RecurringContribution::moveForward(
                    $contrib['values'][0]['contribution_recur_id'], 
                    $contrib['values'][0]['receive_date']
                );
            }
            else {
                // If not, generate an RC from this contribution
                $rc_id = CRM_Utils_SGP_RecurringContribution::generate(
                    $contribution_id
                );

            }

            // Either way, we link the RC to this Contribution
            $contrib = civicrm_api3('Contribution', 'create', [
              'id' => $contribution_id,
              'contribution_recur_id' => $rc_id
            ]); 

        }

    }

    public function updateRecurIDs($contact_id, $transaction_id, $recurring_contribution_id) {

        Civi::log()->debug("update Contact {$contact_id} payments with Recurring ID {$recurring_contribution_id} {$transaction_id}");

        // Get Recurring Transaction ID Custom Field
        $txn_custom_field = CRM_Utils_SGP_Contribution::getTransactionIDCustomField();
        if (is_null($txn_custom_field)) {
            Civi::log()->debug("No Custom Field");
            return;
        }

        // Link all Contributions with this Recurr Transaction ID to the Recurring Payment
        $contrib = civicrm_api3('Contribution', 'get', [
            'sequential' => 1,
            'return' => ["id"],
            'contact_id' => $contact_id,
            $txn_custom_field => $transaction_id,
            'api.Contribution.create' => [
                'contribution_recur_id' => $recurring_contribution_id
            ],
            'options' => ['limit' => 1000],
        ]); 

    }


    function getTransactionIDCustomField() {

        // Get Recurring Transaction ID Custom Field
        $cf_res = civicrm_api3('CustomField', 'get', [
          'sequential' => 1,
          'label' => "Recurring Transaction ID",
          'custom_group_id.extends' => "contribution",
        ]);

        Civi::log()->debug("RC Transaction ID CustomField custom_{$cf_res['id']}");

        return "custom_".$cf_res['id'];

    }

}

