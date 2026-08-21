{*
 * Inscription B2B - Page de configuration (back-office)
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License version 3.0
 *}
{include file="./_partials/zm40_update.tpl"}

{* En-tête ZM40 inline (autonome, pas de marqueur à résoudre au build). *}
<style>
.zm40-ah { background: linear-gradient(135deg, #2563EB 0%, #1e3a8a 100%); color:#fff; padding:28px 32px; border-radius:8px; margin-bottom:24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
.zm40-ah * { box-sizing:border-box; }
.zm40-ah h2 { margin:0; font-size:22px; font-weight:600; color:#fff; line-height:1.2; }
.zm40-ah-sub { opacity:.85; font-size:13px; margin-top:4px; color:#fff; }
.zm40-ah-badge { background:rgba(255,255,255,.2); padding:6px 14px; border-radius:20px; font-size:12px; font-weight:500; color:#fff; white-space:nowrap; }
</style>
<div class="zm40-ah">
    <div>
        <h2>{$zm40_ah_name|escape:'html':'UTF-8'}</h2>
        <div class="zm40-ah-sub">{$zm40_ah_sub|escape:'html':'UTF-8'} &middot; v{$zm40_ah_version|escape:'html':'UTF-8'}</div>
    </div>
    {if isset($zm40_ah_shop) && $zm40_ah_shop}<span class="zm40-ah-badge">{$zm40_ah_shop|escape:'html':'UTF-8'}</span>{/if}
</div>

{if isset($b2r_pending) && $b2r_pending > 0}
    <div class="alert alert-warning">
        <i class="icon-time"></i>
        {$b2r_pending|intval} {l s='demande(s) professionnelle(s) en attente de validation.' mod='b2bregistration'}
        <a class="btn btn-warning btn-xs" href="{$b2r_bo_link|escape:'html':'UTF-8'}">{l s='Voir les demandes' mod='b2bregistration'}</a>
    </div>
{else}
    <div class="alert alert-info">
        <i class="icon-list"></i>
        {l s='Gérez et modérez les inscriptions professionnelles dans l\'onglet dédié.' mod='b2bregistration'}
        <a class="btn btn-default btn-xs" href="{$b2r_bo_link|escape:'html':'UTF-8'}"><i class="icon-briefcase"></i> {l s='Ouvrir les demandes B2B' mod='b2bregistration'}</a>
    </div>
{/if}

{assign var=zm40_has_modules value=(isset($zm40_modules) && $zm40_modules|@count)}

{if $zm40_has_modules}
    <ul class="nav nav-tabs" id="b2r-config-tabs">
        <li class="active"><a href="#b2r-tab-config" data-toggle="tab"><i class="icon-cogs"></i> {l s='Configuration' mod='b2bregistration'}</a></li>
        <li><a href="#b2r-tab-modules" data-toggle="tab"><i class="icon-th-large"></i> {l s='Modules ZM40' mod='b2bregistration'}</a></li>
    </ul>
    <div class="tab-content" style="padding-top:15px;">
        <div class="tab-pane active" id="b2r-tab-config">
            {$b2r_form nofilter}
        </div>
        <div class="tab-pane" id="b2r-tab-modules">
            {include file="./_partials/zm40_modules.tpl"}
        </div>
    </div>
{else}
    {$b2r_form nofilter}
{/if}

{* Panel « libre & open source » + prestations — toujours en bas, visible quel que soit l'onglet actif *}
{include file="./_partials/zm40_panel.tpl"}

{include file="./_partials/zm40_footer.tpl"}
