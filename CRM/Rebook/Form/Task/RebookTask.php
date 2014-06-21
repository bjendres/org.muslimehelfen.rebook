<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Rebook_Form_Task_RebookTask extends CRM_Contribute_Form_Task {

  function buildQuickForm() {

    $this->add('text', 'contactId', ts('Kontakt-ID'), null, $required = true);
    $this->addDefaultButtons(ts('Umbuchen'));

    parent::buildQuickForm();
  }

  function addRules() {
    $this->addFormRule(array('CRM_Rebook_Form_Task_RebookTask', 'rebookRules'));
  }

  static function rebookRules($values) {
    $errors = array();

    if (!preg_match('/^\d+$/', $values['contactId'])) { // check if is int
      $errors['contactId'] = ts('Als Kontakt-ID sind nur ganzzahlige Werte erlaubt!');
    }
    return empty($errors) ? TRUE : $errors;
  }

  function postProcess() {
    $values = $this->exportValues();
    $toContactID = $values['contactId'];
    $this->setContactIDs();
    // error_log(print_r($this->_contactIds, 1));

    // get booking amounts
    // cancel from orig
    // rebook to contact

    parent::postProcess();
  }

}