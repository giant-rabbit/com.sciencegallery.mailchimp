<?php
// This file declares a managed database record of type "Job".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array (
  0 => 
  array (
    'name' => 'Cron:Mailchimp.SyncSubscriptions',
    'entity' => 'Job',
    'params' => 
    array (
      'version' => 3,
      'name' => 'Sync Mailchimp Subscriptions',
      'description' => 'Sync Mailchimp Subscriptions with CiviCRM Groups',
      'run_frequency' => 'Always',
      'api_entity' => 'Mailchimp',
      'api_action' => 'SyncSubscriptions',
      'parameters' => 'limit=100',
    ),
  ),
);
