<?php

class CRM_Mailchimp_Form_Setting extends CRM_Core_Form {

  /**
   * Function to return the Form Name.
   *
   * @return None
   * @access public
   */
  public function getTitle() {
    return ts('MailChimp Settings');
  }

  /**
   * Function to actually build the form
   *
   * @return None
   * @access public
   */
  public function buildQuickForm() {
    $this->addElement('text', 'api_key', ts('API Key'), array(
      'size' => 48,
    ));
    $defaults['api_key'] = CRM_Mailchimp_Utils::getConfig('api_key', FALSE);
    $groups = CRM_Contact_BAO_Group::getGroups();
    $checkboxes = array();
    foreach ($groups as $group) {
      $checkboxes[] = &HTML_QuickForm::createElement('checkbox', $group->id, $group->title);
    }
    $this->addGroup($checkboxes, 'groups', ts('Groups'));
    $current = CRM_Mailchimp_Utils::getConfig('groups', FALSE);
    if (!empty($current)) {
      foreach ($current as $key => $value) {
        $defaults['groups'][$key] = $value;
      }
    }
    $buttons = array(
      array(
        'type' => 'submit',
        'name' => ts('Save'),
      ),
    );
    $this->addButtons($buttons);
    $this->setDefaults($defaults);
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return None
   */
  public function postProcess() {
    $params = $this->controller->exportValues($this->_name);
    if (CRM_Utils_Array::value('api_key', $params)) {
      CRM_Mailchimp_Utils::setConfig('api_key', $params['api_key']);
    }
    if (CRM_Utils_Array::value('client_id', $params)) {
      CRM_Mailchimp_Utils::setConfig('client_id', $params['client_id']);
    }
    if (CRM_Utils_Array::value('groups', $params)) {
      CRM_Mailchimp_Utils::setConfig('groups', $params['groups']);
    }
    if (CRM_Utils_Array::value('api_key', $params) && CRM_Utils_Array::value('groups', $params)) {
      $this->mapGroups($params);
    }
  }

  /**
   * Function to Map CiviCRM Groups to MailChimp Lists.
   *
   * @access private
   *
   * @return None
   */
  private function mapGroups($params = array()) {
    $group_map = CRM_Mailchimp_Utils::getConfig('group_map', FALSE);
    $group_map = !empty($group_map) ? $group_map : array();
    $groups = CRM_Contact_BAO_Group::getGroups();
    $group_ids = array();
    foreach ($groups as $group) {
      $group_ids[$group->id] = $group->id;
    }
    foreach ($group_map as $key => $value) {
      if (!in_array($key, $group_ids)) {
        unset($group_map[$key]);
      }
    }
    $mc_client = new Mailchimp($params['api_key']);
    $mc_lists = new Mailchimp_Lists($mc_client);
    $result = $mc_lists->getlist();
    $lists = $result['data'];
    $list_ids = array();
    foreach ($lists as $list) {
      $list_ids[$list['name']] = $list['id'];
    }
    $group_map = array();
    foreach ($groups as $group) {
      if (empty($params['groups'][$group->id])) {
        continue;
      }
      if (empty($list_ids[$group->title])) {
        CRM_Core_Session::setStatus('MailChimp List <strong>' . $group->title . '</strong> does not exist, please create in MailChimp', 'Configuration error', 'error');
      }
      else {
     	$group_map[$group->id] = $list_ids[$group->title];
        $query = array(
          'key' => CIVICRM_SITE_KEY,
        );
        $url = CRM_Utils_System::url('civicrm/mailchimp/webhook', $query, TRUE);
        $setWebhook = TRUE;
        $results = $mc_lists->webhooks($list_ids[$group->title]);
        if (count($results) != 0) {
          foreach ($results as $webhook) {
            if ($webhook['url'] == $url) {
              $setWebhook = FALSE;
            }
          }
        }
        if ($setWebhook) {
          $actions = array(
            'subscribe' => true,
            'unsubscribe' => true,
            'profile' => true,
            'cleaned' => true,
            'upemail' => true,
            'campaign' => true,
          );
          $sources = array(
            'user' => true,
            'admin' => true,
            'api' => false,
          );
          $mc_lists->webhookAdd($list_ids[$group->title], $url, $actions, $sources);
        }
      }
    }
    CRM_Mailchimp_Utils::setConfig('group_map', $group_map);
    CRM_Core_Session::setStatus(ts('CiviCRM Groups to Mailchimp Lists mapping saved.'), '', 'success');
  }
}
