{locale path="nextgen/locale" domain="partdb"}
<div class="panel panel-primary">
    <div class="panel-heading">
        <i class="fa fa-globe" aria-hidden="true"></i>&nbsp;
        {t}Alle Bauteile{/t}
    </div>
    <form method="post" action="" class="no-progbar">
        <input type="hidden" name="table_rowcount" value="{$table_rowcount}">
           {include file='../smarty_table.tpl'}
    </form>
</div>