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
    $completedStatus = CRM_Core_OptionGroup::getValue('contribution_status', 'Completed', 'name');
    $contribution_fieldKeys = CRM_Contribute_DAO_Contribution::fieldKeys();
    $sepa_ooff_payment_id = CRM_Core_OptionGroup::getValue('payment_instrument', 'OOFF', 'name');

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
            'version'                 => 3,
            'contribution_status_id'  => $cancelledStatus,
            'cancel_reason'           => ts('Rebooked to CiviCRM ID %1', array(1 => $contact_id)),
            'cancel_date'             => date('YmdHis'),
            'id'                      => $contribution['id'],
        );
        $cancelledContribution = civicrm_api('Contribution', 'create', $params);
        if (!empty($cancelledContribution['is_error']) && !empty($cancelledContribution['error_message'])) {
          CRM_Core_Session::setStatus($cancelledContribution['error_message'], ts("Error"), "error");
          //CRM_Utils_System::redirect($session->readUserContext());
        }

        // Now compile $attributes, taking the exclusionList into account
        $attributes = array(
            'version'                 => 3,
            'contribution_contact_id' => $contact_id,
            'contribution_status_id'  => $completedStatus,
            'payment_instrument_id'   => CRM_Core_OptionGroup::getValue('payment_instrument', $contribution['instrument_id'], 'id'), // this seems to be an API bug
        );
        foreach ($contribution as $key => $value) {

          if (!in_array($key, $excludeList) && in_array($key, $contribution_fieldKeys)) { // to be sure that this keys really exists
            $attributes[$key] = $value;
          }

          if (strstr($key, 'custom')) { // get custom fields 
            // load custom field spec for exception handling
            $custom_field_id = substr($key, 7);
            $custom_field = civicrm_api('CustomField', 'getsingle', array('id'=>$custom_field_id,'version'=>3));

            // Exception 1: dates are not properly formatted
            if ($custom_field['data_type'] == 'Date') {
              if (!empty($value)) {
                $value = date('YmdHis', strtotime($value));
              }
            }
            $attributes[$key] = $value;
          }
        }

        // create new contribution
        $newContribution = civicrm_api('Contribution', 'create', $attributes);
        if (!empty($newContribution['is_error']) && !empty($newContribution['error_message'])) {
          CRM_Core_Session::setStatus($newContribution['error_message'], ts("Error"), "error");
        }

        // Exception handling for SEPA OOFF payments (org.project60.sepa extension)
        if ($attributes['payment_instrument_id'] == $sepa_ooff_payment_id) {
          CRM_Rebook_Form_Rebook::fixOOFFMandate($contribution, $attributes);
        }

        // create rebook note
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

    if ($rebooked == $contribution_count) {
      CRM_Core_Session::setStatus(ts('%1 contribution(s) successfully rebooked!', array(1 => $contribution_count)), ts('Successfully rebooked!'), 'success');
    } else {
      error_log("org.muslimehelfen.rebook: Only $rebooked of $contribution_count contributions rebooked.");
      CRM_Core_Session::setStatus(ts('Please check your data and try again', array(1 => $contribution_count)), ts('Nothing rebooked!'), 'warning');
      CRM_Utils_System::redirect($session->readUserContext());
    }
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

  /**
   * Fixes the problem, that the cloned contribution does not have a mandate.
   *
   * Approach is:
   *  1) move old (valid) mandate to new contribution
   *  2) create new (invalid) mandate and attach to old contribution
   * 
   * @see org.project60.sepa extension
   */
  static function fixOOFFMandate($old_contribution, $new_contribution) {
    $old_mandate = civicrm_api('SepaMandate', 'getsingle', array('entity_id'=>$old_contribution['id'], 'entity_table'=>'civicrm_contribution', 'version' => 3));
    error_log(print_r($old_mandate,1));
    if (!empty($old_mandate['is_error'])) {
      CRM_Core_Session::setStatus($old_mandate['error_message'], ts("Error"), "error");
      return;
    }

    // find a new, unused mandate reference
    $new_reference_pattern = $old_mandate['reference'].'REB%02d';
    $new_reference = '';
    for ($i = 1; $i <= 100; $i++) {
      $new_reference = sprintf($new_reference_pattern, $i);
      if (strlen($new_reference) > 35) {
        CRM_Core_Session::setStatus(ts("Cannot find a new mandate reference, exceeds 35 characters."), ts("Error"), "error");
        return;                  
      }
      
      // see if this reference already exists
      $exists = civicrm_api('SepaMandate', 'getsingle', array('reference' => $new_reference, 'version' => 3));
      if (empty($exists['is_error'])) {
        // found -> it exists -> damn -> keep looking...
        if ($i == 100) {
          // that's it, we tried... maybe something else is wrong
          CRM_Core_Session::setStatus(ts("Cannot find a new mandate reference"), ts("Error"), "error");
          return;
        } else {
          // keep looking!
          continue;
        }
      } else {
        // we found a reference
        break;
      }
    }

    // create an invalid clone of the mandate
    $create_clone = civicrm_api('SepaMandate', 'create', array(
      'version'               => 3,
      'entity_id'             => $old_contribution['id'],
      'entity_table'          => 'civicrm_contribution',
      'status'                => 'INVALID',
      'reference'             => $new_reference,
      'source'                => $old_mandate['source'],
      'date'                  => date('YmdHis', strtotime($old_mandate['date'])),
      'validation_date'       => date('YmdHis', strtotime($old_mandate['validation_date'])),
      'creation_date'         => date('YmdHis', strtotime($old_mandate['creation_date'])),
      'first_contribution_id' => empty($old_mandate['first_contribution_id'])?'':$old_mandate['first_contribution_id'],
      'type'                  => $old_mandate['type'],
      'contact_id'            => $old_mandate['contact_id'],
      'iban'                  => $old_mandate['iban'],
      'bic'                   => $old_mandate['bic']));
    if (!empty($create_clone['is_error'])) {
      CRM_Core_Session::setStatus($create_clone['error_message'], ts("Error"), "error");
      return;
    }

    // set old mandate to new contribution
    $result = civicrm_api('SepaMandate', 'create', array('id' => $old_mandate['id'], 'entity_id' => $new_contribution['id'], 'version' => 3));
    if (!empty($result['is_error'])) {
      CRM_Core_Session::setStatus($result['error_message'], ts("Error"), "error");
      return;
    }
  }

}
