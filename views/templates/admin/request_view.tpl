{*
 * Inscription B2B - Détail d'une demande (back-office)
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License version 3.0
 *}
<div class="panel">
    <div class="panel-heading"><i class="icon-building"></i> {l s='Demande B2B' mod='b2bregistration'} #{$b2r->id|intval}</div>
    <table class="table">
        <tr><th>{l s='Société' mod='b2bregistration'}</th><td>{$b2r->company|escape:'html':'UTF-8'}</td></tr>
        <tr><th>SIRET</th><td>{$b2r->siret|escape:'html':'UTF-8'}</td></tr>
        <tr><th>{l s='N° TVA' mod='b2bregistration'}</th><td>{$b2r->vat_number|escape:'html':'UTF-8'}</td></tr>
        <tr><th>{l s='Code APE' mod='b2bregistration'}</th><td>{$b2r->ape|escape:'html':'UTF-8'}</td></tr>
        <tr><th>{l s='Site web' mod='b2bregistration'}</th><td>{$b2r->website|escape:'html':'UTF-8'}</td></tr>
        <tr><th>{l s='Téléphone' mod='b2bregistration'}</th><td>{$b2r->pro_phone|escape:'html':'UTF-8'}</td></tr>
        <tr><th>{l s='Pays' mod='b2bregistration'}</th><td>{$b2r->country_iso|escape:'html':'UTF-8'} ({$b2r->country_scope|escape:'html':'UTF-8'})</td></tr>
        <tr><th>{l s='Statut' mod='b2bregistration'}</th><td>{$b2r_status_badge nofilter}</td></tr>
        {if $b2r->attachment}
            <tr><th>{l s='Pièce jointe' mod='b2bregistration'}</th><td>{$b2r->attachment|escape:'html':'UTF-8'}</td></tr>
        {/if}
        {if $b2r->note}
            <tr><th>{l s='Note' mod='b2bregistration'}</th><td>{$b2r->note|escape:'html':'UTF-8'}</td></tr>
        {/if}
    </table>

    <h4>{l s='Détail des vérifications' mod='b2bregistration'}</h4>
    <table class="table">
        <tr><th>SIRET</th><td>{$siret_text|escape:'html':'UTF-8'}{if $siret_check_url} <a href="{$siret_check_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='Vérifier sur l\'Annuaire des entreprises' mod='b2bregistration'} <i class="icon-external-link"></i></a>{/if}</td></tr>
        <tr><th>{l s='TVA' mod='b2bregistration'}</th><td>{$vat_text|escape:'html':'UTF-8'}</td></tr>
    </table>

    {if $b2r_approve_url || $b2r_reject_url}
        <div class="panel-footer">
            {if $b2r_approve_url}
                <a class="btn btn-success" href="{$b2r_approve_url|escape:'html':'UTF-8'}"><i class="icon-check"></i> {l s='Approuver' mod='b2bregistration'}</a>
            {/if}
            {if $b2r_reject_url}
                <a class="btn btn-danger" style="margin-left:8px" href="{$b2r_reject_url|escape:'html':'UTF-8'}" onclick="return confirm('{l s='Refuser cette demande ? Le client sera notifié et perdra ses tarifs professionnels.' mod='b2bregistration' js=1}');"><i class="icon-remove"></i> {l s='Refuser' mod='b2bregistration'}</a>
            {/if}
        </div>
    {/if}
</div>
