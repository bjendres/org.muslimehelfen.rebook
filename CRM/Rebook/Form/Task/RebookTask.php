<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Rebook_Form_Task_RebookTask extends CRM_Contribute_Form_Task {

  function preProcess() {
    $contact_ids = array();

    parent::preProcess();

    $session = CRM_Core_Session::singleton();
    $this->_userContext = $session->readUserContext();

    $admin = CRM_Core_Permission::check('administer CiviCRM');
    if (!$admin) {
      CRM_Core_Error::fatal(ts('You do not have permission to access this page.'));
      CRM_Utils_System::redirect($this->_userContext);
    }

    foreach ($this->_contributionIds as $contributionId) {
      $params = array(
          'version' => 3,
          'sequential' => 1,
          'id' => $contributionId,
      );
      $contribution = civicrm_api('Contribution', 'getsingle', $params);

      if (empty($contribution['is_error'])) { // contribution exists
        array_push($contact_ids, $contribution['contact_id']);
      }
    }

    if (count(array_unique($contact_ids)) > 1) {

      CRM_Core_Session::setStatus(ts('Rebooking of multiple contributions form different contacts not allowed!'), ts("Rebooking not allowed!"), "error");
      CRM_Utils_System::redirect($this->_userContext);
    }
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
    $this->addFormRule(array('CRM_Rebook_Form_Task_RebookTask', 'rebookRules'));
  }

  static function rebookRules($values) {
    $errors = array();
    $contactId = $values['contactId'];
    $contributionIds = $values['contributionIds'];

    if (!preg_match('/^\d+$/', $contactId)) { // check if is int
      $errors['contactId'] = ts('Please enter only integer values for a CiviCRM ID!');
      return empty($errors) ? TRUE : $errors;
    }

    // validation for contact
    $contact = new CRM_Contact_BAO_Contact();
    $contact->id = $contactId;

    if (!$contact->find(true)) {
      $errors['contactId'] = ts('Contact with CiviCRM ID %1 doesn\'t exists!', array(1 => $contactId));
      return empty($errors) ? TRUE : $errors;
    }

    // Der Kontakt, auf den umgebucht wird, darf kein Haushalt sein.
    $contactType = $contact->getContactType($contactId);
    if (!empty($contactType) && $contactType == 'Household') {
      $errors['contactId'] = ts('The contact that will be rebooked to can not be a household!');
      return empty($errors) ? TRUE : $errors;
    }

    // Der Kontakt, auf den umgebucht wird, darf nicht im Papierkorb sein.
    $contactIsDeleted = $contact->is_deleted;
    if ($contactIsDeleted == 1) {
      $errors['contactId'] = ts('The contact that will be rebooked to can not be trashed!');
      return empty($errors) ? TRUE : $errors;
    }

    // Es dürfen nur abgeschlossene Zuwendungen umgebucht werden
    $completed = CRM_Core_OptionGroup::getValue('contribution_status', 'Completed', 'name');
    $arr = explode(",", $contributionIds);
    foreach ($arr as $contributionId) {
      $contribution = new CRM_Contribute_DAO_Contribution();
      $contribution->id = $contributionId;
      if ($contribution->find(true)) {
        if ($contribution->contribution_status_id != $completed) {
          $errors['contactId'] = ts('The contribution with ID %1 is not completed!', array(1 => $contributionId));
          return empty($errors) ? TRUE : $errors;
        }
      }
    }

    return empty($errors) ? TRUE : $errors;
  }

  function postProcess() {
    $params = array();
    $rebooked = false;
    $excludeList = array('id', 'contribution_id', 'trxn_id', 'invoice_id', 'cancel_date', 'cancel_reason', 'address_id', 'contribution_contact_id', 'contribution_status_id');
    $cancelledStatus = CRM_Core_OptionGroup::getValue('contribution_status', 'Cancelled', 'name');
    $contribution_fieldKeys = CRM_Contribute_DAO_Contribution::fieldKeys();

    $values = $this->exportValues();
    $toContactID = $values['contactId'];
    $contributionIds = $this->_contributionIds;

    foreach ($contributionIds as $contributionId) {
      $params = array(
          'version' => 3,
          'sequential' => 1,
          'id' => $contributionId,
      );
      $contribution = civicrm_api('Contribution', 'getsingle', $params);

      if (empty($contribution['is_error'])) { // contribution exists
        // cancel contribution
        $params = array(
            'version' => 3,
            'contribution_status_id' => $cancelledStatus,
            'cancel_reason' => ts('Rebooked to CiviCRM ID %1', array(1 => $toContactID)),
            'cancel_date' => date('YmdHis'),
            'id' => $contribution['id'],
        );
        $cancelledContribution = civicrm_api('Contribution', 'create', $params);

        // on error
        if (!empty($cancelledContribution['is_error']) && !empty($cancelledContribution['error_message'])) {
          CRM_Core_Session::setStatus($cancelledContribution['error_message'], ts("Error"), "error");
          CRM_Utils_System::redirect($this->_userContext);
        }

        // prepare $params array, take into account exclusionList and proper naming of Contribution fields
        $params = array(
            'version' => 3,
            'sequential' => 1,
            'contribution_contact_id' => $toContactID,
            'contribution_status_id' => 1
        );

        $custom_fields = array();
        foreach ($contribution as $key => $value) {

          if (!in_array($key, $excludeList) && in_array($key, $contribution_fieldKeys)) { // to be sure that this keys really exists
            $params[$key] = $value;
          }

          if (strstr($key, 'custom')) { // get custom fields 
            $custom_fields[$key] = $value;
          }
        }

        // create new contribution
        $newContribution = civicrm_api('Contribution', 'create', $params);

        // on error
        if (!empty($newContribution['is_error']) && !empty($newContribution['error_message'])) {
          CRM_Core_Session::setStatus($newContribution['error_message'], ts("Error"), "error");
          CRM_Utils_System::redirect($this->_userContext);
        }

        //copy custom values from contribution
        $params = array(
            'version' => 3,
            'sequential' => 1,
            'entity_id' => $newContribution['id']
        );
        $params = array_merge($params, $custom_fields);
        $customValue = civicrm_api('CustomValue', 'create', $params);
        
        if (!empty($customValue['is_error']) && !empty($customValue['error_message'])) {
          CRM_Core_Session::setStatus($customValue['error_message'], ts("Error"), "error");
          CRM_Utils_System::redirect($this->_userContext);
        }        

        // create note
        $params = array(
            'version' => 3,
            'sequential' => 1,
            'note' => ts('Rebooked from CiviCRM ID %1', array(1 => $contribution['id'])),
            'entity_table' => 'civicrm_contribution',
            'entity_id' => $newContribution['id']
        );
        $result = civicrm_api('Note', 'create', $params);

        $rebooked |= true;
      }
    }

    if ($rebooked)
      CRM_Core_Session::setStatus(ts('%1 contribution(s) successfully rebooked!', array(1 => count($this->_contributionIds))), ts('Successfully rebooked!'), 'success');
    else
      CRM_Core_Session::setStatus(ts('Please check your data and try again', array(1 => count($this->_contributionIds))), ts('Nothing rebooked!'), 'warning');

    parent::postProcess();
  }

}
