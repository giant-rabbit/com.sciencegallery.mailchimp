<?php

require_once 'mailchimp.civix.php';
require_once 'vendor/mailchimp/Mailchimp.php';


/**
 * Implementation of hook_civicrm_pre
 */
function mailchimp_civicrm_pre($op, $objectName, $id, &$params) {
  // List all of the Object Names in use.
  $names = array(
    'Individual',
  );
  // List all of the Operations in use.
  $ops = array(
    'restore',
    'edit',
    'delete',
  );
  if (in_array($op, $ops) && in_array($objectName, $names)) {
    mailchimp_update_contact($op, $id, $params);
  }
}

/**
 * Implementation of hook_civicrm_post
 */
function mailchimp_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($objectName == 'SubscriptionHistory' && $op == 'create') {
    $subscription = $objectRef;
    switch ($subscription->status) {
      case 'Added':
        CRM_Mailchimp_Sync::subscribeContactToMailchimpList($subscription);
        break;
      case 'Removed':
        CRM_Mailchimp_Sync::unsubscribeContactFromMailchimpList($subscription);
        break;
      case 'Deleted':
        CRM_Mailchimp_Sync::deleteContactFromMailchimpList($subscription);
        break;
    }
  }
  if ($objectName == 'Group' && $op == 'delete') {
    mailchimp_delete_group($objectRef);
  }
}

/**
 * Update Mailchimp to Match the new Contact.
 */
function mailchimp_update_contact($op, $contact_id, $params) {

  // Get the API Key
  $api_key = CRM_Mailchimp_Utils::getConfig('api_key');

  // If the API Key or Client ID are empty
  // return now, for there is nothing else we can do.
  if (empty($api_key)) {
    return;
  }

  // Get the Groups
  $groups = CRM_Mailchimp_Utils::getConfig('groups', array());

  // Get the Groups
  $group_map = CRM_Mailchimp_Utils::getConfig('group_map', array());

  // Get the Contact
  $contact = new CRM_Contact_BAO_Contact();
  $contact->get('id', $contact_id);

  $logger = CRM_Core_Error::createDebugLogger();

  if (isset($params['group'])){
    $contact_groups = $params['group'];
  }
  else {
    $cg_api = civicrm_api3('GroupContact', 'get', array('contact_id' => $contact_id));
    foreach ($cg_api['values'] as $cg) {
      $contact_groups[$cg['group_id']] = TRUE;
    }
  }

  if(!isset($params['is_opt_out']))
    $params['is_opt_out'] = $contact->is_opt_out;

  if(!isset($params['privacy']['do_not_email']))
    $params['privacy']['do_not_email'] = $contact->do_not_email;

  // Check if we should mess with this user
  foreach ($contact_groups as $group_id => $in_group) {

    try {

      if ((!empty($groups[$group_id]) && !empty($group_map[$group_id])) && $in_group) {

        // Connect to Mail Chimp
        $mc_client = new Mailchimp($api_key);
        $mc_lists = new Mailchimp_Lists($mc_client);

        // Get the Contact's Current Primary Email.
        $email = new CRM_Core_BAO_Email();
        $email->whereAdd('contact_id = '.$contact->id);
        $email->whereAdd('is_primary = 1');
        $email->find(TRUE);

        if ($op == 'edit') {

          $primary_email = '';

          // Find the Primary Eamil from the Paramaters.
          foreach ($params['email'] as $email_params) {
            if (!empty($email_params['is_primary'])) {
              $primary_email = $email_params['email'];
            }
          }

          if(!isset($params['email']))
            $primary_email = $email->email;

          // See if the Current Primary Email is different from the submitted value.
          if ($email->email != $primary_email || $params['is_opt_out'] != $contact->is_opt_out ||
            $params['privacy']['do_not_email'] != $contact->do_not_email ) {

              // Update the List to reflect the new primary email.
              // If Both emails are not empty, the email has changed.
              if (!empty($email->email) && !empty($primary_email) &&
                !($params['is_opt_out'] || $params['privacy']['do_not_email']) &&
                !($contact->is_opt_out || $contact->do_not_email)) {
                  $updates = array('EMAIL' => $primary_email);

                  $mc_lists->updateMember( $group_map[$group_id],array('email'=>$email->email),$updates);
                }
              // if the Existing email is empty, subscribe the user (only if they are in the group)
              elseif (!empty($primary_email) && empty($email->email) ||
                (($contact->is_opt_out || $contact->do_not_email) && !($params['is_opt_out'] || $params['privacy']['do_not_email']))) {

                  // Create the Paramaters to be Subscribed
                  $vars = array (
                    'EMAIL' => $primary_email,
                    'FNAME' => $params['first_name'],
                    'LNAME' => $params['last_name'],
                    'groupings'=>array(),
                    'optin_time'=>date("Y-m-d H:i:s"),
                  );

                  // Add the Subscriber.
                  //¿ $result = $subscribers->add($subscriber); ?

                  $mc_lists->subscribe( $group_map[$group_id], array('email' => $primary_email), $vars,'html', false, true, false, false);
                }
              // If the exting email is not empty, but the primary email is, then they should be deleted.
              elseif (!empty($email->email) && empty($primary_email) ||
                (!($contact->is_opt_out || $contact->do_not_email) &&
                ($params['is_opt_out'] || $params['privacy']['do_not_email']))) {
                  // Delete the Subscriber.
                  $mc_lists->unsubscribe( $group_map[$group_id], array('email' => $email->email), false, false, false);

                  continue;
                }
            }

          //if there's a name change, update mailchimp
          if(isset($params['first_name']) && isset($params['last_name']) &&
            (($params['first_name'] != $contact->first_name) || ($params['last_name'] != $contact->last_name))){
              $updates = array (
                'FNAME' => $params['first_name'],
                'LNAME' => $params['last_name'],
              );

              $results = $mc_lists->updateMember($group_map[$group_id],array('email'=>$primary_email),$updates);
            }
        }

        // If the User is being deleted
        elseif ($op == 'delete' && !empty($email->email)) {

          // Loop through all groups that should be synced.
          foreach ($groups as $group_id => $sync) {

            // If a map exists for said group
            if ($sync && !empty($group_map[$group_id])) {

              // Set the List ID
              // $subscribers->set_list_id($group_map[$group_id]);

              // If the Contact hasn't been removed yet
              if (!empty($contact->is_deleted)) {
                // Delete the Subscriber
                //$result = $subscribers->delete($email->email);
              }
              else {
                // Remove the Subscriber
                // $result = $subscribers->unsubscribe($email->email);
              }

            }
          }

        }
        // If the Contact is being created or restored
        elseif (!empty($email->email) && $op == 'restore') {

          // Get all the Groups a Contact was in.
          $group_contact = new CRM_Contact_BAO_GroupContact();
          $group_contact->whereAdd('contact_id = '.$contact->id);
          $group_contact->whereAdd("status = 'Added'");
          $group_contact->find();

          // Loop through Each Group.
          while ($group_contact->fetch()) {

            // Set the Group ID.
            $group_id = $group_contact->group_id;

            // Make sure this group should be synced and it is mapped.
            if (!empty($groups[$group_id]) && !empty($group_map[$group_id])) {

              // Set the List ID.
              // $subscribers->set_list_id($group_map[$group_id]);

              $subscriber = array (
                'EmailAddress' => $email->email,
                'Name' => $contact->display_name,
                'Resubscribe' => $contact->do_not_email ? FALSE : TRUE,
                'RestartSubscriptionBasedAutoResponders' => FALSE,
              );
              // $result = $subscribers->add($subscriber);

            }

          }

        }

      }

    }
    catch(Exception $e) {
      CRM_Core_Error::createDebugLogger()->log('Contact ' . $contact->id . ' ' . $history->status . ': ' . $e->getMessage(), PEAR_LOG_WARNING);
    }
  }

}

/**
 * When a CiviCRM Group is deleted,
 * remove it's list and references.
 */
function mailchimp_delete_group($group) {

  // Get the Groups
  $groups = CRM_Mailchimp_Utils::getConfig('groups', array());

  // Get the Groups
  $group_map = CRM_Mailchimp_Utils::getConfig('group_map', array());

  // MailChimp won't allow us to remove a list via API, so just remove from the mapping
  // Make sure all data is ready
  if (!empty($api_key) && !empty($group_map[$group->id])) {

    unset($groups[$group->id]);

    CRM_Mailchimp_Utils::setConfig('groups', $groups);

    unset($group_map[$group->id]);

    CRM_Mailchimp_Utils::setConfig('group_map', $group_map);

  }

}

/**
 * Implementation of hook_civicrm_config
 */
function mailchimp_civicrm_config(&$config) {
  _mailchimp_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function mailchimp_civicrm_xmlMenu(&$files) {
  _mailchimp_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function mailchimp_civicrm_install() {
  return _mailchimp_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function mailchimp_civicrm_uninstall() {
  return _mailchimp_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function mailchimp_civicrm_enable() {

  $navigation = new CRM_Core_BAO_Navigation();

  $params = array(
    'name' => 'MailChimp Settings',
    'label' => 'MailChimp Settings',
    'url' => 'civicrm/admin/setting/mailchimp',
    'permission' => 'access CiviCRM',
    'parent_id' => 137,
    'is_active' => TRUE,
  );

  $navigation->add($params);

  $params = array(
    'name' => 'MailChimp Sync',
    'label' => 'MailChimp Sync',
    'url' => 'civicrm/admin/mailchimp/sync',
    'permission' => 'access CiviCRM',
    'parent_id' => 15,
    'is_active' => TRUE,
  );

  $navigation->add($params);

  return _mailchimp_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function mailchimp_civicrm_disable() {

  $navigation = new CRM_Core_BAO_Navigation();
  $navigation->url = 'civicrm/admin/setting/mailchimp';
  $navigation->find();

  while ($navigation->fetch()) {
    $navigation->processDelete($navigation->id);
  }

  $navigation = new CRM_Core_BAO_Navigation();
  $navigation->url = 'civicrm/admin/mailchimp/sync';
  $navigation->find();

  while ($navigation->fetch()) {
    $navigation->processDelete($navigation->id);
  }

  return _mailchimp_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function mailchimp_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _mailchimp_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function mailchimp_civicrm_managed(&$entities) {
  return _mailchimp_civix_civicrm_managed($entities);
}

function getHistoryID(){
  $sql = "SELECT history_id FROM civicrm_mailchimp_history ORDER BY id DESC LIMIT 0,1";
  $id = CRM_Core_DAO::singleValueQuery($sql, array());

  if(empty($id)){
    $id = 0;
  }

  return($id);
}

function setHistoryID($id){

  $sql = "SELECT id FROM civicrm_mailchimp_history ORDER BY id DESC LIMIT 0,1";
  $old_id = CRM_Core_DAO::singleValueQuery($sql, array());
  if(!empty ($old_id)){
    $sql = "UPDATE civicrm_mailchimp_history SET history_id = " . $id . " WHERE id = " . $old_id;
    CRM_Core_DAO::singleValueQuery($sql);
  }
  else {
    $sql = "INSERT INTO civicrm_mailchimp_history(history_id) VALUES (" . $id . ")";
    CRM_Core_DAO::singleValueQuery($sql);
  }
}
