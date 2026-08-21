{*
 * Inscription B2B - Lien "Inscription Pro" dans la nav top.
 * Activé via B2R_HEADER_LINK + B2R_HEADER_HOOK (nav1 / nav2).
 * CSS inline (style global, mais minuscule + scopé .b2r-header-link).
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License version 3.0
 *}
<style>
.b2r-header-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    font-size: 13px;
    font-weight: 600;
    color: inherit;
    background: rgba(249, 115, 22, .08);
    border: 1px solid rgba(249, 115, 22, .3);
    border-radius: 999px;
    text-decoration: none !important;
    transition: all .15s ease;
    line-height: 1.4;
    white-space: nowrap;
}
.b2r-header-link:hover {
    background: #f97316;
    border-color: #f97316;
    color: #fff !important;
}
.b2r-header-link .material-icons {
    font-size: 16px;
    line-height: 1;
}
@media (max-width: 540px) {
    .b2r-header-link .material-icons + span { display: none; }
}
</style>
<a class="b2r-header-link" href="{$b2r_header_url|escape:'html':'UTF-8'}">
    <i class="material-icons" aria-hidden="true">business_center</i>
    <span>{$b2r_header_label|escape:'html':'UTF-8'}</span>
</a>
