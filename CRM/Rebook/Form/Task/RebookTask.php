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

    $session = CRM_Core_Session::singleton();
    $this->_userContext = $session->readUserContext();

    $admin = CRM_Core_Permission::check('administer CiviCRM');
    if (!$admin) {
      CRM_Core_Error::fatal(ts('You do not have permission to access this page.'));
      CRM_Utils_System::redirect($this->_userContext);
    }

    if (count($this->_contributionIds) > 1) {
      CRM_Core_Session::setStatus(ts('Rebooking of multiple contributions not allowedt!'), ts("Rebooking not allowed!"), "error");
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
    $values = $this->exportValues();
    $toContactID = $values['contactId'];

    $contributionIds = $this->_contributionIds;
    $cancelled = CRM_Core_OptionGroup::getValue('contribution_status', 'Cancelled', 'name');
    foreach ($contributionIds as $contributionId) {

      $contribution = new CRM_Contribute_DAO_Contribution();
      $contribution->id = $contributionId;

      if ($contribution->find(true)) {
        // cancel contribution
        $params['contribution_status_id'] = $cancelled;
        $params['cancel_reason'] = ts('Rebooked to CiviCRM ID %1', array(1 => $toContactID));
        $params['cancel_date'] = date('YmdHis');

        CRM_Utils_Hook::pre('edit', 'Contribution', $contribution->id, $params);

        $contribution->copyValues($params);
        $contribution->save();

        /* not needed, but leave it in code for just in case.
          // crete note
          $params = array(
          'version' => 3,
          'sequential' => 1,
          'note' => 'Umgebucht zu CiviCRM ID ' . $toContactID,
          'entity_table' => 'civicrm_contribution',
          'entity_id' => $contribution->id
          );
         */
        $result = civicrm_api('Note', 'create', $params);

        CRM_Utils_Hook::post('edit', 'Contribution', $contribution->id, $contribution);

        // create new contribution
        $params = array(
            'version' => 3,
            'sequential' => 1,
            'contribution_contact_id' => $toContactID,
            'financial_type_id' => $contribution->financial_type_id,
            'contribution_payment_instrument_id' => $contribution->payment_instrument_id,
            'contribution_page_id' => $contribution->contribution_page_id,
            'payment_instrument_id' => $contribution->payment_instrument_id,
            'receive_date' => $contribution->receive_date,
            'non_deductible_amount' => $contribution->non_deductible_amount,
            'total_amount' => $contribution->total_amount,
            'fee_amount' => $contribution->fee_amount,
            'net_amount' => $contribution->net_amount,
            //'trxn_id' => $contribution->trxn_id,
            //'invoice_id' => $contribution->invoice_id,
            'currency' => $contribution->currency,
            //'cancel_date' => $contribution->cancel_date,
            //'cancel_reason' => $contribution->cancel_reason,
            'receipt_date' => $contribution->receipt_date,
            'thankyou_date' => $contribution->thankyou_date,
            'source' => $contribution->source,
            'amount_level' => $contribution->amount_level,
            'contribution_recur_id' => $contribution->contribution_recur_id,
            'honor_contact_id' => $contribution->honor_contact_id,
            'is_test' => $contribution->is_test,
            'is_pay_later' => $contribution->is_pay_later,
            'contribution_status_id' => 1,
            'honor_type_id' => $contribution->honor_type_id,
            //'address_id' => $contribution->address_id,
            'check_number' => $contribution->check_number,
            'campaign_id' => $contribution->campaign_id
        );
        $newContribution = civicrm_api('Contribution', 'create', $params);

        // create note
        $params = array(
            'version' => 3,
            'sequential' => 1,
            'note' => ts('Rebooked from CiviCRM ID %1', array(1 => $contribution->contact_id)),
            'entity_table' => 'civicrm_contribution',
            'entity_id' => $newContribution['id']
        );
        $result = civicrm_api('Note', 'create', $params);
      }

      CRM_Core_Session::setStatus(ts('%1 contribution(s) successfully rebooked!', array(1 => count($this->_contributionIds))), ts('Successfully rebooked !'), 'success');
    }

    parent::postProcess();
  }

}
