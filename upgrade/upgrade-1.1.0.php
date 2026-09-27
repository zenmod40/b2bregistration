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
 * 1.1.0 : table d'historique des demandes, colonnes qui retiennent ce que la
 * validation a changé (pour le retirer en cas de refus), et reprise de la page
 * de CGV enregistrée sous l'ancienne clé. Les demandes existantes ne sont pas
 * modifiées. Idempotent.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_1_0($module)
{
    $db = Db::getInstance();

    // CREATE TABLE IF NOT EXISTS : sans effet sur les tables déjà présentes.
    $sql = include dirname(__FILE__) . '/../sql/install.php';
    foreach (is_array($sql) ? $sql : array() as $query) {
        if (!$db->execute($query)) {
            return false;
        }
    }

    $existing = array_column($db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'b2r_request`') ?: array(), 'Field');
    $columns = array(
        'groups_added' => 'ADD `groups_added` VARCHAR(255) NOT NULL DEFAULT \'\' AFTER `note`',
        'id_default_group_before' => 'ADD `id_default_group_before` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `groups_added`',
    );
    foreach ($columns as $column => $alter) {
        if (!in_array($column, $existing, true)
            && !$db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'b2r_request` ' . $alter)) {
            return false;
        }
    }

    // La page de CGV était enregistrée sous B2R_TERMS_CMS mais lue sous B2R_CMS_TERMS.
    if (!(int) Configuration::get('B2R_TERMS_CMS') && (int) Configuration::get('B2R_CMS_TERMS')) {
        Configuration::updateValue('B2R_TERMS_CMS', (int) Configuration::get('B2R_CMS_TERMS'));
    }
    Configuration::deleteByName('B2R_CMS_TERMS');

    return true;
}
