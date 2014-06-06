<?php
class CRM_Mailchimp_Page_WebHook extends CRM_Core_Page {

  function run() {
    if (CRM_Utils_System::authenticateKey($abort = TRUE)) {
      $request = CRM_Utils_Request::exportValues();
      $groups = mailchimp_variable_get('groups', array());
      $group_map = mailchimp_variable_get('group_map', array());
      if (!empty($request['data']['list_id']) && !empty($request['type'])) {
        $request_type = $request['type'];
        $request_data = $request['data'];
        foreach ($groups as $group_id => $in_group) {
          if ($group_map[$group_id] == $request_data['list_id']) {
            if($request_type == 'profile') {
              $email = new CRM_Core_BAO_Email();
              $email->get('email', $request_data['email']);
              if (!empty($email->contact_id)) {
                $contact = new CRM_Contact_BAO_Contact();
                $contact->id = $email->contact_id;
                $contact->find(TRUE);
                if(!empty($request_data['merges']['FNAME']) && !empty($request_data['merges']['FNAME']) ) {
                  if(($contact->first_name != $request_data['merges']['FNAME']) || ($contact->last_name != $request_data['merges']['LNAME'])) {
                    $contact->first_name = $request_data['merges']['FNAME'];
                    $contact->last_name = $request_data['merges']['LNAME'];
                    $contact->sort_name = $contact->last_name . ', ' . $contact->first_name;
                    $contact->display_name = $contact->first_name . ' ' . $contact->last_name;
                    $contact->email_greeting_display = 'Dear ' . $contact->first_name;
                    $contact->save();
                  }
                }
              }
            }
            else if($request_type == 'upemail') {
              $email = new CRM_Core_BAO_Email();
              $email->get('email', $request_data['old_email']);
              if (!empty($email->contact_id)) {
                $email->email = $request_data['new_email'];
                $email->save();
              }
            }
            else if($request_type == 'cleaned') {
              $email = new CRM_Core_BAO_Email();
              $email->get('email', $request_data['email']);
              if (!empty($email->contact_id)) {
                $email->on_hold = 1;
                $email->holdEmail($email);
              }
            }
            else if($request_type == 'unsubscribe') {
              //instead of removing from CiviCRM groups, set the no bulk communications flag, this will prevent smart groups from being messed up
              $email = new CRM_Core_BAO_Email();
              $email->get('email', $request_data['email']);
              if (!empty($email->contact_id)) {
                $contact = new CRM_Contact_BAO_Contact();
                $contact->id = $email->contact_id;
                $contact->find(TRUE);
                $contact->is_opt_out = 1;
                $contact->save();
              }
            }
          }
        }
      }
      // Return the JSON output
      header('Content-type: application/json');
      print json_encode($data);
      CRM_Utils_System::civiExit();
    }
  }
}
