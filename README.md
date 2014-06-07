com.giantrabbit.mailchimp
============================

CiviCRM/Mailchimp Sync

This CiviCRM extension provides two way synchronisation between CiviCRM groups and MailChimp Lists. Changes to users in regular groups are synced with MailChimp in real-time. Changes made in MailChimp are synchronized back to CiviCRM using Mailchimp's Webhooks API.

INSTALLATION
------------
1. Create lists on MailChimp with the same names as the groups you want to sync in CiviCRM.
2. Install and enable the extenstion.
3. Go to http://<yourdomain>/civicrm/admin/setting/mailchimp and enter your MailChimp API key and select the groups you want to sync with Mailchimp.
4. Run an initial sync at http://<yourdomain>/civicrm/admin/mailchimp/sync.
