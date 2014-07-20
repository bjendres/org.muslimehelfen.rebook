<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 */
class CRM_Rebook_Form_Rebook extends CRM_Core_Form {

  protected $contribution_ids = array();
  

  function preProcess() {
    parent::preProcess();
  
    $admin = CRM_Core_Permission::check('administer CiviCRM');
    if (!$admin) {
      CRM_Core_Error::fatal(ts('You do not have the permissions required to access this page.'));
      CRM_Utils_System::redirect($this->_userContext);
    }

    if (empty($_REQUEST['contributionIds'])) {
      die(ts("You need to specifiy a contribution to rebook."));
    }

    $this->contribution_ids = array((int) $_REQUEST['contributionIds']);

    // check if the contributions are all from the same contact
    CRM_Rebook_Form_Rebook::checkSameContact($this->contribution_ids);
  }


  function buildQuickForm() {
    $contributionIds = implode(',', $this->contribution_ids);

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
    CRM_Rebook_Form_Rebook::rebook($this->contribution_ids, $values['contactId']);
    parent::postProcess();
  }




  /**
   * Checks if the given contributions are of the same contact - one of the requirements for rebooking
   *
   * @param $contribution_ids  an array of contribution IDs
   */
  static function checkSameContact($contribution_ids) {
    $session = CRM_Core_Session::singleton();
    $contact_ids = array();

    foreach ($contribution_ids as $contributionId) {
      $params = array(
          'version' => 3,
          'sequential' => 1,
          'id' => $contributionId,
      );
      $contribution = civicrm_api('Contribution', 'getsingle', $params);

      if (empty($contribution['is_error'])) { // contribution exists
        array_push($contact_ids, $contribution['contact_id']);
      } else {
        CRM_Core_Session::setStatus(ts("At least one of the given contributions doesn't exist!"), ts("Error"), "error");
        CRM_Utils_System::redirect($session->readUserContext());
        return;
      }
    }

    if (count(array_unique($contact_ids)) > 1) {
      CRM_Core_Session::setStatus(ts('Rebooking of multiple contributions from different contacts is not allowed!'), ts("Rebooking not allowed!"), "error");
      CRM_Utils_System::redirect($session->readUserContext());
    }    
  }


  /**
   * Will rebooking all given contributions to the given target contact
   *
   * @param $contribution_ids  an array of contribution IDs
   * @param $contact_id        the target contact ID
   */
  static function rebook($contribution_ids, $contact_id) {
    $excludeList = array('id', 'contribution_id', 'trxn_id', 'invoice_id', 'cancel_date', 'cancel_reason', 'address_id', 'contribution_contact_id', 'contribution_status_id');
    $cancelledStatus = CRM_Core_OptionGroup::getValue('contribution_status', 'Cancelled', 'name');
    $contribution_fieldKeys = CRM_Contribute_DAO_Contribution::fieldKeys();

    $contribution_count = count($contribution_ids);
    $session = CRM_Core_Session::singleton();
    $rebooked = 0;

    foreach ($contribution_ids as $contributionId) {
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
            'cancel_reason' => ts('Rebooked to CiviCRM ID %1', array(1 => $contact_id)),
            'cancel_date' => date('YmdHis'),
            'id' => $contribution['id'],
        );
        $cancelledContribution = civicrm_api('Contribution', 'create', $params);

        // on error
        if (!empty($cancelledContribution['is_error']) && !empty($cancelledContribution['error_message'])) {
          CRM_Core_Session::setStatus($cancelledContribution['error_message'], ts("Error"), "error");
          CRM_Utils_System::redirect($session->readUserContext());
        }

        // prepare $params array, take into account exclusionList and proper naming of Contribution fields
        $params = array(
            'version' => 3,
            'sequential' => 1,
            'contribution_contact_id' => $contact_id,
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
          CRM_Utils_System::redirect($session->readUserContext());
          return;
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
          CRM_Utils_System::redirect($session->readUserContext());
          return;
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

        $rebooked += 1;
      }
    }

    if ($rebooked == $contribution_count)
      CRM_Core_Session::setStatus(ts('%1 contribution(s) successfully rebooked!', array(1 => $contribution_count)), ts('Successfully rebooked!'), 'success');
    else
      CRM_Core_Session::setStatus(ts('Please check your data and try again', array(1 => $contribution_count)), ts('Nothing rebooked!'), 'warning');
  }


  /**
   * Rule set for the rebooking forms
   */
  static function rebookRules($values) {
    $errors = array();
    $contactId = $values['contactId'];
    $contributionIds = $values['contributionIds'];

    if (!preg_match('/^\d+$/', $contactId)) { // check if is int
      $errors['contactId'] = ts('Please enter a CiviCRM ID!');
      return empty($errors) ? TRUE : $errors;
    }

    // validation for contact
    $contact = new CRM_Contact_BAO_Contact();
    $contact->id = $contactId;

    if (!$contact->find(true)) {
      $errors['contactId'] = ts('A contact with CiviCRM ID %1 doesn\'t exist!', array(1 => $contactId));
      return empty($errors) ? TRUE : $errors;
    }

    // Der Kontakt, auf den umgebucht wird, darf kein Haushalt sein.
    $contactType = $contact->getContactType($contactId);
    if (!empty($contactType) && $contactType == 'Household') {
      $errors['contactId'] = ts('The target contact can not be a household!');
      return empty($errors) ? TRUE : $errors;
    }

    // Der Kontakt, auf den umgebucht wird, darf nicht im Papierkorb sein.
    $contactIsDeleted = $contact->is_deleted;
    if ($contactIsDeleted == 1) {
      $errors['contactId'] = ts('The target contact can not be in trash!');
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

}
