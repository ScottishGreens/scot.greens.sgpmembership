<?php

  class CRM_Utils_SGP_Contribution {


    public function processMembershipContribution($contribution_id) {

        // Get Member Dues financial type
        $ft_memberdues = civicrm_api3('FinancialType', 'get', [
          'sequential' => 1,
          'return' => ["id"],
          'name' => "Member Dues",
        ]);

        $txn_custom_field = CRM_Utils_SGP_Contribution::getTransactionIDCustomField();

        // If neither exist, we bounce
        if (is_null($txn_custom_field)) {
            Civi::log()->debug("No Custom Field");
            return;
        }
        if ($ft_memberdues['count'] == 0) {
            Civi::log()->debug("No Member Dues FinancialType");
            return;
        }

        // Get Contribution
        Civi::log()->debug("Getting Contribution info");
        $contrib = civicrm_api3('Contribution', 'get', [
          'sequential' => 1,
          'return' => ["receive_date",'contact_id','contribution_recur_id',"total_amount","financial_type_id", "payment_instrument_id", "trxn_id", $txn_custom_field],
          'id' => $contribution_id,
        ]); 

        // If it is a Membership Contribution, continue
        if ($contrib['values'][0]['financial_type_id'] == $ft_memberdues['id']) {

            if (is_numeric($contrib['values'][0]['contribution_recur_id'])) {
                // If there is a recurring contribution ID, just set the variable
                $rc_id = $contrib['values'][0]['contribution_recur_id'];
            }
            else {
                // If no RC_ID we try to find a matching RC by transaction ID

                // Fetch the DD payment instrument

                $option_id_dd = civicrm_api3('OptionValue', 'get', [
                  'sequential' => 1,
                  'return' => ["name"],
                  'option_group_id.name' => "payment_instrument",
                  'label' => "Direct Debit",
                ]);

                if ($contrib['values'][0]['payment_instrument_id'] == $option_id_dd['values'][0]['option_group_id']) {
                    // If it is a DD then the RC_TXN_ID is the Contribution TXN ID up to a '/' character
                    $split = explode('/', $contrib['values'][0]['trxn_id']);
                    $rc_transaction_id = $split[0];
                }
                else {
                    // Else the RC_TXN_ID is the Custom Field TXN ID we imported from a CSV
                    $rc_transaction_id = $contrib['values'][0][$txn_custom_field];
                }

                if (!is_numeric($rc_transaction_id)) {
                    Civi::log()->debug("No valid transaction id");
                    return;
                }

                // Fetch Recurring Contribution by transaction id
                $contribrecur_get = civicrm_api3('ContributionRecur', 'get', array(
                    'trxn_id' => $rc_transaction_id
                ) );

                if ($contribrecur_get['count'] != 0) {
                    Civi::log()->debug("Recurring Contribution fetched by transaction_id {$contribrecur_get['values'][0]['id']}");
                    $rc_id = $contribrecur_get['values'][0]['id'];
                }
                else {
                    Civi::log()->debug("Generating Recurring Contribution");
                    $rc_id = CRM_Utils_SGP_RecurringContribution::generate(
                        $contribution_id
                    );

                }
            }

            // Update the fetched or generated RC

            CRM_Utils_SGP_RecurringContribution::update($rc_id);

            // Either way, we link the RC to this Contribution
            $contrib_res = civicrm_api3('Contribution', 'create', [
              'id' => $contribution_id,
              'contact_id' => $contrib['values'][0]['contact_id'],
              'contribution_recur_id' => $rc_id
            ]); 

            return true;

        }

        return false;

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

