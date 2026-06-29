{*
 * Inscription B2B - Bloc affiché au-dessus du formulaire client (front-office).
 * Contient :
 *   - Bascule "Particulier / Professionnel" en deux tuiles visuelles (sauf si boutique 100% pro)
 *   - Hidden input b2r_is_pro mis à jour par la tuile sélectionnée (lu par le backend)
 *   - Bloc intro CMS optionnel
 *   - Champ upload Kbis optionnel (révélé en mode pro)
 *
 * @license GPL-3.0-or-later
 *}
{* Tout le bloc est wrappé dans .b2r-form-prepend → déplacé en TÊTE du <form>
   au chargement par le JS (hookDisplayCustomerAccountForm rend en bas dans
   Classic 2.2.0, on force l'emplacement souhaité). *}
<div class="b2r-form-prepend">
{if isset($b2r_show_tiles) && $b2r_show_tiles && (!isset($b2r_only_pro) || !$b2r_only_pro)}
<div class="b2r-account-type" role="radiogroup" aria-label="{l s='Type de compte' mod='b2bregistration'}">
    <label class="b2r-tile b2r-tile-particulier">
        <input type="radio" name="b2r_account_type" value="particulier" data-b2r-pro="0" checked>
        <span class="b2r-tile-inner">
            <span class="b2r-tile-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="7" r="4"></circle><path d="M5.5 21a6.5 6.5 0 0 1 13 0"></path></svg>
            </span>
            <span class="b2r-tile-body">
                <span class="b2r-tile-title">{l s='Particulier' mod='b2bregistration'}</span>
                <span class="b2r-tile-sub">{l s='Achat personnel' mod='b2bregistration'}</span>
            </span>
        </span>
    </label>
    <label class="b2r-tile b2r-tile-pro">
        <input type="radio" name="b2r_account_type" value="pro" data-b2r-pro="1">
        <span class="b2r-tile-inner">
            <span class="b2r-tile-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V7l7-4 7 4v14"></path><path d="M9 10h.01M9 14h.01M9 18h.01M15 10h.01M15 14h.01M15 18h.01"></path></svg>
            </span>
            <span class="b2r-tile-body">
                <span class="b2r-tile-title">{l s='Professionnel' mod='b2bregistration'}</span>
                <span class="b2r-tile-sub">{l s='SIRET, TVA, facturation pro' mod='b2bregistration'}</span>
            </span>
        </span>
    </label>
</div>
{/if}
{if isset($b2r_show_tiles) && $b2r_show_tiles}
<input type="hidden" name="b2r_is_pro" id="b2r-is-pro-hidden" value="{if isset($b2r_only_pro) && $b2r_only_pro}1{else}0{/if}">
{/if}
{if isset($b2r_intro_url) && $b2r_intro_url}
    <div class="b2r-intro alert alert-info" data-b2r-pro-only="1" style="display:none;">
        <i class="material-icons">business_center</i>
        {l s='Vous êtes un professionnel ?' mod='b2bregistration'}
        <a href="{$b2r_intro_url|escape:'html':'UTF-8'}">{l s='En savoir plus sur le compte professionnel' mod='b2bregistration'}</a>
    </div>
{/if}
{if isset($b2r_allow_upload) && $b2r_allow_upload}
    <div class="b2r-upload form-group" data-b2r-pro-only="1" data-b2r-upload="1" style="display:none;">
        <label class="form-control-label">{l s='Pièce justificative (Kbis, PDF/JPG/PNG)' mod='b2bregistration'}</label>
        <input type="file" name="b2r_attachment" accept=".pdf,.jpg,.jpeg,.png">
        <small class="form-text text-muted">{l s='Optionnel. 4 Mo maximum.' mod='b2bregistration'}</small>
    </div>
{/if}
{if isset($b2r_terms_url) && $b2r_terms_url}
    <div class="b2r-terms form-group" data-b2r-pro-only="1" style="display:none;">
        <label class="b2r-terms-label">
            <input type="checkbox" name="b2r_terms_accepted" value="1" required>
            <span>{l s='J\'accepte les' mod='b2bregistration'}
                <a href="{$b2r_terms_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='conditions générales B2B' mod='b2bregistration'}</a>.
            </span>
        </label>
    </div>
{/if}
</div>{* /.b2r-form-prepend *}
