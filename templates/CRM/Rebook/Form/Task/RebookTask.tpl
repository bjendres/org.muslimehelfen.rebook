<div class="crm-form-block crm-block crm-contact-task-pdf-form-block">
  {* HEADER *}
  <div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="top"}
  </div>

  <div class="messages status no-popup">
      <div class="icon inform-icon"></div>
      <p>Sind Sie sicher, dass Sie die ausgewählten Zuwendungen umbuchen möchten?</p>
      <p>Anzahl der ausgewählten Zuwendungen: {$totalSelectedContributions}</p><b/>
  </div>
  
  <p><strong>Bitte geben Sie die CiviCRM ID an, zu der umgebucht werden soll?</strong></p>

  {$form.contactId.label}<br />
  {$form.contactId.html}
  {$form.contributionIds.html}
  <br />

  {* FOOTER *}
  <div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
