<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Rebook_Form_Task_RebookTask extends CRM_Contribute_Form_Task {
  

  function preProcess() {
    parent::preProcess();
  
    $admin = CRM_Core_Permission::check('administer CiviCRM');
    if (!$admin) {
      CRM_Core_Error::fatal(ts('You do not have the permissions required to access this page.'));
      CRM_Utils_System::redirect($this->_userContext);
    }

    // check if the contributions are all from the same contact
    CRM_Rebook_Form_Rebook::checkSameContact($this->_contributionIds, $this->_userContext);
  }


  function buildQuickForm() {
    $contributionIds = implode(',', $this->_contributionIds);
    $this->setContactIDs();

    $this->add('text', 'contactId', ts('CiviCRM ID'), null, $required = true);
    $this->add('hidden', 'contributionIds', $contributionIds);
    $this->addDefaultButtons(ts('Rebook'));

    parent::buildQuickForm();
  }


  function addRules() {
    $this->addFormRule(array('CRM_Rebook_Form_Rebook', 'rebookRules'));
  }


  function postProcess() {
    $values = $this->exportValues();
    CRM_Rebook_Form_Rebook::rebook($this->_contributionIds, $values['contactId'], $this->_userContext);
    parent::postProcess();

    // finally, redirect to original contact's contribution overview
    $origin_contact_id = CRM_Rebook_Form_Rebook::checkSameContact($this->_contributionIds, NULL);
    if (!empty($origin_contact_id)) {
      $url = CRM_Utils_System::url('civicrm/contact/view', "reset=1&cid=$origin_contact_id&selectedChild=contribute");
    } else {
      $url = CRM_Utils_System::url('civicrm', "");
    }
    CRM_Utils_System::redirect($url);    
  }

}
