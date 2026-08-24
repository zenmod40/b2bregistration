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
 * 1.0.4 : enregistrement du hook actionCustomerAccountUpdate, nécessaire pour
 * enregistrer les modifications des champs pro depuis « Mes informations ».
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_0_4($module)
{
    if ($module->isRegisteredInHook('actionCustomerAccountUpdate')) {
        return true;
    }

    return (bool) $module->registerHook('actionCustomerAccountUpdate');
}
