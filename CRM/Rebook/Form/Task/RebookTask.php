<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Rebook_Form_Task_RebookTask extends CRM_Contribute_Form_Task {

  function buildQuickForm() {
    $contributionIds = implode(',', $this->_contributionIds);
    $this->setContactIDs();

    $this->add('text', 'contactId', ts('Kontakt-ID'), null, $required = true);
    $this->add('hidden', 'contributionIds', $contributionIds);
    $this->addDefaultButtons(ts('Umbuchen'));

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
      $errors['contactId'] = ts('Als Kontakt-ID sind nur ganzzahlige Werte erlaubt!');
      return empty($errors) ? TRUE : $errors;
    }

    // validation for contact
    $contact = new CRM_Contact_BAO_Contact();
    $contact->id = $contactId;

    if (!$contact->find(true)) {
      $errors['contactId'] = ts('Der Kontakt mit der CiviID ' . $contactId . ' existiert nicht!');
      return empty($errors) ? TRUE : $errors;
    }

    // Der Kontakt, auf den umgebucht wird, darf kein Haushalt sein.
    $contactType = $contact->getContactType($contactId);
    if (!empty($contactType) && $contactType == 'Household') {
      $errors['contactId'] = ts('Der Kontakt auf den umgebucht wird darf kein Haushalt sein!');
      return empty($errors) ? TRUE : $errors;
    }

    // Der Kontakt, auf den umgebucht wird, darf nicht im Papierkorb sein.
    $contactIsDeleted = $contact->is_deleted;
    if ($contactIsDeleted == 1) {
      $errors['contactId'] = ts('Der Kontakt, auf den umgebucht wird, darf nicht im Papierkorb sein!');
      return empty($errors) ? TRUE : $errors;
    }

    // Es dürfen nur abgeschlossene Zuwendungen umgebucht werden
    $arr = explode(",", $contributionIds);
    foreach ($arr as $contributionId) {
      $contribution = new CRM_Contribute_DAO_Contribution();
      $contribution->id = $contributionId;
      if ($contribution->find(true)) {
        if ($contribution->contribution_status_id != CRM_Core_OptionGroup::getValue('contribution_status', 'Completed', 'name')) {
          $errors['contactId'] = ts('Der Zuwenddung mit der  ID ' . $contributionId . ' ist nicht abgeschlossen!');
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

    //$this->setContactIDs();
    // check if contact exists
//    $contact = new CRM_Contact_BAO_Contact();
//    $contact->id = $toContactID;
//
//    if (!$contact->find(true)) {
//      CRM_Core_Session::setStatus(ts('Der Kontakt mit der ID ' . $toContactID . ' existiert nicht!'), 'Fehler', 'error');
//    }
    // get booking amounts
    $contributionIds = $this->_contributionIds;

    foreach ($contributionIds as $contributionId) {

      $contribution = new CRM_Contribute_DAO_Contribution();
      $contribution->id = $contributionId;

      if ($contribution->find(true)) {
        // cancel contribution
        $params['contribution_status_id'] = CRM_Core_OptionGroup::getValue('contribution_status', 'Cancelled', 'name');
        $params['cancel_reason'] = 'Umgebucht zu CiviID ' . $toContactID;
        $params['cancel_date'] = date('YmdHis');

        CRM_Utils_Hook::pre('edit', 'Contribution', $contribution->id, $params);

        $contribution->copyValues($params);
        $contribution->save();

        // crete note  
        $params = array(
            'version' => 3,
            'sequential' => 1,
            'note' => 'Umgebucht zu CiviID ' . $toContactID,
            'entity_table' => 'civicrm_contribution',
            'entity_id' => $contribution->id
        );
        
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
            'note' => 'Umgebucht von CiviID ' . $contribution->contact_id,
            'entity_table' => 'civicrm_contribution',
            'entity_id' => $newContribution['id']
        );
        $result = civicrm_api('Note', 'create', $params);
      }
      
      // todo anything todo for contribution_recur?
      
      CRM_Core_Session::setStatus(ts('Die Umbuchung wurde erfolgreich durchgeführt.'), '', 'success');
    }

    parent::postProcess();
  }

}
