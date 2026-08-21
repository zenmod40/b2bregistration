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

$sql = array();

// Demande d'inscription B2B (source de vérité métier).
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'b2r_request` (
    `id_b2r_request` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_customer` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `company` VARCHAR(255) NOT NULL DEFAULT \'\',
    `siret` VARCHAR(32) NOT NULL DEFAULT \'\',
    `vat_number` VARCHAR(32) NOT NULL DEFAULT \'\',
    `ape` VARCHAR(16) NOT NULL DEFAULT \'\',
    `website` VARCHAR(255) NOT NULL DEFAULT \'\',
    `pro_phone` VARCHAR(32) NOT NULL DEFAULT \'\',
    `attachment` VARCHAR(255) NOT NULL DEFAULT \'\',
    `id_country` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `country_iso` VARCHAR(3) NOT NULL DEFAULT \'\',
    `country_scope` VARCHAR(16) NOT NULL DEFAULT \'\',
    `siret_valid` TINYINT(1) NOT NULL DEFAULT -1,
    `vat_valid` TINYINT(1) NOT NULL DEFAULT -1,
    `siret_detail` TEXT NULL,
    `vat_detail` TEXT NULL,
    `id_group_assigned` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
    `note` TEXT NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_b2r_request`),
    KEY `id_customer` (`id_customer`),
    KEY `status` (`status`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

// Règles d'affectation de groupe par scope pays (domestic / eu / world).
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'b2r_group_rule` (
    `id_b2r_group_rule` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope` VARCHAR(16) NOT NULL DEFAULT \'domestic\',
    `id_group` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id_b2r_group_rule`),
    KEY `scope` (`scope`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

return $sql;
