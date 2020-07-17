<p>{$count} Contributions processed.

<ul>
{foreach from=$rows item=row}
    <li>{$row}
{/foreach}
</ul>
<div class="form-item">
     {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>