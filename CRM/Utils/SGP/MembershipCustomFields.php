<?php

  class CRM_Utils_SGP_MembershipCustomFields {

    /**
     * @return array
     */
    public static function getCustomFields() {

      //CRM_Core_Error::debug_log_message("Getting Custom Fields");
      $result = NULL;

      try {
        $result = civicrm_api3('CustomField', 'get', array(
          'sequential' => 1,
          'return' => "id,label",
          'label' => array('IN'
              => array("Member Status", "Member Join Date", "Member Expiry Date","Member Payment Method","Payment Method","Member Payment Frequency","Active Member")),
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Error::debug_var("Error: ",$e);
      }
      
      $custom_fields = array();

      if ( isset($result) && $result['count'] > 0 ) {
        foreach ($result['values'] as $field) {
          switch ($field['label']) {
            case 'Active Member':
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