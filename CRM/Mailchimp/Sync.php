<?php

class CRM_Mailchimp_Sync {
  
  private function initializeMailchimpApi($mailchimp_api) {
    $api_key = CRM_Mailchimp_Utils::getConfig('api_key');
    if (empty($api_key)) {
      throw new Exception("A Mailchimp API key must be set to use CiviCRM Mailchimp Sync.");
    }
    $mailchimp = new Mailchimp($api_key);
    return new $mailchimp_api($mailchimp);
  }

  public function subscribeContactToMailchimpList($subscription) {
    $mailchimp_lists_api = self::initializeMailchimpApi('Mailchimp_Lists');
    $list_id = CRM_Mailchimp_Utils::getMailchimpListId($subscription->group_id);
    if ($list_id) {
      $contact_details = self::formatContactDetailsForMailchimp($subscription);
      $mailchimp_subscribe = $mailchimp_lists_api->subscribe($list_id, array('email' => $contact_details['email']), $contact_details['mailchimp_params'], 'html', FALSE, TRUE, FALSE, FALSE);
      return $mailchimp_subscribe;
    }
  }

  public function unsubscribeContactFromMailchimpList($subscription) {
    $mailchimp_lists_api = self::initializeMailchimpApi('Mailchimp_Lists');
    $list_id = CRM_Mailchimp_Utils::getMailchimpListId($subscription->group_id);
    if ($list_id) {
      $contact_details = self::formatContactDetailsForMailchimp($subscription);
      $mailchimp_unsubscribe = $mailchimp_lists_api->unsubscribe($list_id, array('email' => $contact_details['email']), FALSE, FALSE, FALSE);
      return $mailchimp_unsubscribe;
    }
  }

  public function deleteContactFromMailchimpList($subscription) {
    $mailchimp_lists_api = self::initializeMailchimpApi('Mailchimp_Lists');
    $list_id = CRM_Mailchimp_Utils::getMailchimpListId($subscription->group_id);
    if ($list_id) {
      $contact_details = self::formatContactDetailsForMailchimp($subscription);
      $mailchimp_delete = $mailchimp_lists_api->unsubscribe($list_id, array('email' => $contact_details['email']), TRUE, FALSE, FALSE);
      return $mailchimp_delete;
    }                     
  }

  public function formatContactDetailsForMailchimp($subscription) {
    $params = array(
      'contact_id' => $subscription->contact_id
    );
    $defaults = array();
    $contact = CRM_Contact_BAO_Contact::retrieve($params, $defaults);
    if ($contact->is_opt_out || $contact->do_not_email) {
      return FALSE;
    }
    $email_address = NULL;
    foreach ($contact->email as $email) {
      if ($email['is_primary'] && !$email['on_hold']) {
        $email_address = $email['email'];
      }
    }
    if (!$email_address) {
      return FALSE;
    }
    $mailchimp_params = array(
      'EMAIL' => $email_address,
      'groupings' => array(),
      'optin_time' => $subscription->date
    );
    if (!empty($contact->first_name)) {
      $mailchimp_params['FNAME'] = $contact->first_name;
    }
    if (!empty($contact->last_name)) {
      $mailchimp_params['LNAME'] = $contact->last_name;
    }
    $contact_details['email'] = $email_address;
    $contact_details['mailchimp_params'] = $mailchimp_params;
    return $contact_details;
  }
}
