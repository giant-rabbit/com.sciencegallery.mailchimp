<?php

class CRM_Mailchimp_Utils {

  /**
   * Set configuration setting.
   */
  static function setConfig($variable, $value = NULL) {
    return CRM_Core_BAO_Setting::setItem(
      $value,
      'MailChimp Preferences',
      $variable
    );
  }

  /**
   * Get configuration setting.
   */
  static function getConfig($variable, $default = NULL) {
    return CRM_Core_BAO_Setting::getItem(
      'MailChimp Preferences',
      $variable,
      NULL,
      $default
    );
  }

  static function getMailchimpListId($group_id) {
    $groups = CRM_Mailchimp_Utils::getConfig('groups', array());
    $group_map = CRM_Mailchimp_Utils::getConfig('group_map', array());
    if (empty($groups[$group_id]) || empty($group_map[$group_id])) {
      return FALSE;
    }
    return $group_map[$group_id];
  }
}
