<?php
/**
 * Inscription B2B - Inscription professionnelle B2B pour PrestaShop
 *
 * @author    ZM40 — Nicolas Michaud (Magic Garden)
 * @copyright 2026 Nicolas Michaud — ZM40 / Magic Garden
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License version 3.0
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.5 : enregistrement du hook displayInvoiceLegalFreeText, qui porte les
 * mentions de TVA sur la facture PDF. Aucune donnée à migrer : les champs de
 * mention sont vides tant que le marchand ne les a pas saisis.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_0_5($module)
{
    if ($module->isRegisteredInHook('displayInvoiceLegalFreeText')) {
        return true;
    }

    return (bool) $module->registerHook('displayInvoiceLegalFreeText');
}
