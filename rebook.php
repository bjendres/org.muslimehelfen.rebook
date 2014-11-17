<?php

require_once 'rebook.civix.php';

/**
 * Implementation of hook_civicrm_config
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function rebook_civicrm_config(&$config) {
  _rebook_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function rebook_civicrm_xmlMenu(&$files) {
  _rebook_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function rebook_civicrm_install() {
  return _rebook_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function rebook_civicrm_uninstall() {
  return _rebook_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function rebook_civicrm_enable() {
  return _rebook_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function rebook_civicrm_disable() {
  return _rebook_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function rebook_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _rebook_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function rebook_civicrm_managed(&$entities) {
  return _rebook_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_caseTypes
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function rebook_civicrm_caseTypes(&$caseTypes) {
  _rebook_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implementation of hook_civicrm_alterSettingsFolders
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function rebook_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _rebook_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Add an action for rebooking after doing a search
 *
 * @param string $$objectType specifies the component
 * @param array  $tasks       the list of actions
 *
 * @access public
 */
function rebook_civicrm_searchTasks($objectType, &$tasks) {
  if ($objectType == 'contribution') {
    if (CRM_Core_Permission::check('edit contributions')) {
      $tasks[] = array(
          'title'  => ts('Rebook to contact'),
          'class'  => 'CRM_Rebook_Form_Task_RebookTask',
          'result' => false);
    }
  }
}

/**
 * Add an action to a list of contributions
 *
 * @todo  use civicrm_links hook as soon as it works properly (>= v4.5)
 *
 * @access public
 */
function rebook_civicrm_searchColumns( $objectName, &$headers,  &$values, &$selector ) {
  if ($objectName == 'contribution') {
    // gather some data
    $contribution_status_complete = (int) CRM_Core_OptionGroup::getValue('contribution_status', 'Completed', 'name');
    $title = ts('Rebook');
    $url = CRM_Utils_System::url('civicrm/rebook', "contributionIds=__CONTRIBUTION_ID__");
    $action = "<a title=\"$title\" class=\"action-item action-item\" href=\"$url\">$title</a>";

    // add 'rebook' action link to each row
    foreach ($values as $rownr => $row) {
      $contribution_status_id = $row['contribution_status_id'];
      // ... but only for completed contributions
      if ($contribution_status_id==$contribution_status_complete) {
        $contribution_id = $row['contribution_id'];
        $this_action = str_replace('__CONTRIBUTION_ID__', $contribution_id, $action);
        $values[$rownr]['action'] = str_replace('</span>', $this_action.'</span>', $row['action']);        
      }
    }
  }
}
