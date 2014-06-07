<?php

/**
 * Mailchimp.SyncSubscriptions API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
function _civicrm_api3_mailchimp_syncsubscriptions_spec(&$spec) {

}

/**
 * Mailchimp.SyncSubscriptions API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_mailchimp_syncsubscriptions($params) {

  // Get the API Key
  $api_key = CRM_Mailchimp_Utils::getConfig('api_key');

  // If the API Key or Client ID are empty
  // return now, for there is nothing else we can do.
  if (empty($api_key)) {
    return CRM_Queue_Task::TASK_FAIL;
  }

  // Get the Groups
  $groups = CRM_Mailchimp_Utils::getConfig('groups', array());

  // Get the Groups
  $group_map = CRM_Mailchimp_Utils::getConfig('group_map', array());

  // Set the Group IDs to an empty array
  $group_ids = array();

  // Loop through each Group
  foreach ($groups as $group_id => $sync) {

    // If the Group is Supposed to be synced and a map exists for the group
    if (!empty($groups[$group_id]) && !empty($group_map[$group_id])) {
      $group_ids[$group_id] = $group_id;
    }

  }


  $mc_client = new Mailchimp($api_key);
  $mc_lists = new Mailchimp_Lists($mc_client);

  //Only want to sync the smart groups
  $all_groups = new CRM_Contact_BAO_Group();
  $all_groups->whereAdd('id IN ('.implode(',', $group_ids).')');
  $all_groups->whereAdd('saved_search_id IS NOT NULL');
  $all_groups->find();

  //Empty the group ids array so we can populate it only with smart groups
  $group_ids = array();

  while($all_groups->fetch()){
    $group_ids[$all_groups->id] = $all_groups->id;
  }

  $group_contact_cache = new CRM_Contact_BAO_GroupContactCache();
  $group_contact_cache->check(implode(',', $group_ids));
  $group_contact_cache->whereAdd('group_id IN ('.implode(',', $group_ids).')');
  $group_contact_cache->orderBy('id ASC');
  $group_contact_cache->find();
  $count = $group_contact_cache->count();

  $included_emails = array();
  $mailchimp_emails = array();
  $additions = array();
  $removals = array();

  //first get list of all the emails in the groups
  while ($group_contact_cache->fetch()) {

    $contact = new CRM_Contact_BAO_Contact();
    $contact->id = $group_contact_cache->contact_id;
    $contact->find(TRUE);

    $email = new CRM_Core_BAO_Email();
    $email->contact_id = $group_contact_cache->contact_id;
    $email->is_primary = TRUE;
    $email->find(TRUE);

    $history = new CRM_Contact_BAO_SubscriptionHistory();
    $history->group_id = $group_id;
    $history->contact_id = $group_contact_cache->contact_id;
    $history->orderBy('id DESC');
    $history->limit(0, 1);
    $history->find(TRUE);

    //we want to make sure only the emails that have not opted out and are not on hold are synched

    if(($contact->is_opt_out == 0) && ($email->on_hold == 0)){
      $included_emails[$group_contact_cache->group_id][$email->email] = array (
        'contact_id' => $group_contact_cache->contact_id,
        'first_name' => $contact->first_name,
        'last_name' => $contact->last_name,
        'optin_time'=>$history->date,
      );
    }


  }

  //Now get the lists from the mailchimp API
  foreach($group_map as $group_id => $list_id){
    $listinfo = $mc_lists->getList(array("list_id" => $list_id,));

    $listCount =  $listinfo['data'][0]['stats']['member_count'];
    $counted = 0;
    $page = 0;

    while ($counted < $listCount) {
      $results = $mc_lists->members($list_id, 'subscribed', array('start'=>$page,));

      foreach($results['data'] as $member) {
        $mailchimp_emails[$group_id][$member['email']] = $member['id'];
        $counted += 1;
      }
      $page +=1;
    }
  }

  //do a comparison and figure out what addresses need to be added
  foreach($included_emails as $group_id => $group_include) {
    foreach($group_include as $email => $member){
      if(empty($mailchimp_emails[$group_id][$email])){
        $additions[$group_id][$email] = $member;
      }
    }
  }

  //do a comparison and figure out what addresses need to be removed
  foreach($mailchimp_emails as $group_id => $group_include) {
    foreach($group_include as $email => $member){
      if(empty($included_emails[$group_id][$email])){
        $removals[$group_id][$email] = $member;
      }
    }
  }

  foreach($additions as $group_id => $group_include) {
    foreach($group_include as $email => $member) {
      //add the new subscribers
      if(!empty($email)){
        if (empty($member['first_name'])) $buffer = array( 'EMAIL'=>$email, 'groupings'=>array(), 'optin_time'=>date("Y-m-d H:i:s"));
        elseif (empty($member['last_name'])) $buffer = array( 'EMAIL'=>$email, 'FNAME'=>$member['first_name'], 'groupings'=>array(), 'optin_time'=>date("Y-m-d H:i:s"));
        else $buffer = array( 'EMAIL'=>$email, 'FNAME'=>$member['first_name'], 'LNAME'=>$member['last_name'], 'groupings'=>array(), 'optin_time'=>date("Y-m-d H:i:s"));

        try {
          $results = $mc_lists->subscribe( $group_map[$group_id], array('email' => $email), $buffer,'html', false, true, false, false);
        }
        catch (Exception $e) {
          CRM_Core_Session::setStatus($e->getMessage(), 'Sync error', 'error');
        }
      }
    }
  }

  //remove the old ones
  foreach($removals as $group_id => $group_include) {
    foreach($group_include as $email => $member) {
      if(!empty($email)){
        try {
          $results = $mc_lists->unsubscribe( $group_map[$group_id], array('email' => $email), true, false, false);
        }
        catch (Exception $e) {
          CRM_Core_Session::setStatus($e->getMessage(), 'Sync error', 'error');
        }
      }
    }
  }

  return civicrm_api3_create_success(array(1), array("synced"));

}

