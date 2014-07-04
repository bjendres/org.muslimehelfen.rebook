<div class="crm-form-block crm-block crm-contact-task-pdf-form-block">
  {* HEADER *}
  <div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="top"}
  </div>

  <div class="messages status no-popup">
      <div class="icon inform-icon"></div>
      <p>{ts}Are you sure you want to rebook the selected contributions?{/ts}</p>
      <p>{ts}Number of selected contributions:{/ts} {$totalSelectedContributions}</p><b/>
  </div>
  
  <p><strong>{ts}Please enter the target CiviCRM ID?{/ts}</strong></p>
  

  {$form.contactId.label}<br />
  {$form.contactId.html}
  {$form.contributionIds.html}
  <br />

  {* FOOTER *}
  <div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
