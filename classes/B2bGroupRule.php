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
 * Règle d'affectation de groupe par scope géographique.
 * Un scope peut pointer vers plusieurs groupes (plusieurs lignes).
 */
class B2bGroupRule extends ObjectModel
{
    const SCOPE_DOMESTIC = 'domestic'; // pays de la boutique
    const SCOPE_EU       = 'eu';       // Union européenne (hors pays boutique)
    const SCOPE_WORLD    = 'world';    // reste du monde

    /** @var int */
    public $id_b2r_group_rule;
    /** @var string */
    public $scope;
    /** @var int */
    public $id_group;
    /** @var int */
    public $active;
    /** @var int */
    public $position;

    public static $definition = array(
        'table'   => 'b2r_group_rule',
        'primary' => 'id_b2r_group_rule',
        'fields'  => array(
            'scope'    => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16),
            'id_group' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'active'   => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'position' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
        ),
    );

    /**
     * IDs de groupes actifs pour un scope donné.
     *
     * La jointure sur `group` écarte les règles pointant vers un groupe
     * supprimé depuis : sans elle la liste n'est pas vide, le repli sur
     * B2R_DEFAULT_GROUP ne joue pas, et le client validé atterrit dans un
     * groupe fantôme — donc sans prix pro et sans erreur visible.
     *
     * @param string $scope
     * @return int[]
     */
    public static function getGroupIdsForScope($scope)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT r.id_group FROM `' . _DB_PREFIX_ . 'b2r_group_rule` r
             INNER JOIN `' . _DB_PREFIX_ . 'group` g ON g.id_group = r.id_group
             WHERE r.active = 1 AND r.scope = "' . pSQL($scope) . '"
             ORDER BY r.position ASC'
        );

        $ids = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $ids[] = (int) $r['id_group'];
            }
        }

        return $ids;
    }

    /**
     * Toutes les règles, indexées par scope.
     *
     * @return array<string,int[]>
     */
    public static function getAllByScope()
    {
        $out = array(
            self::SCOPE_DOMESTIC => array(),
            self::SCOPE_EU       => array(),
            self::SCOPE_WORLD    => array(),
        );

        $rows = Db::getInstance()->executeS(
            'SELECT r.scope, r.id_group FROM `' . _DB_PREFIX_ . 'b2r_group_rule` r
             INNER JOIN `' . _DB_PREFIX_ . 'group` g ON g.id_group = r.id_group
             WHERE r.active = 1 ORDER BY r.position ASC'
        );
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $scope = (string) $r['scope'];
                if (isset($out[$scope])) {
                    $out[$scope][] = (int) $r['id_group'];
                }
            }
        }

        return $out;
    }

    /**
     * Remplace l'ensemble des règles d'un scope par la liste de groupes fournie.
     *
     * @param string $scope
     * @param int[]  $groupIds
     */
    public static function replaceScope($scope, array $groupIds)
    {
        Db::getInstance()->delete('b2r_group_rule', 'scope = "' . pSQL($scope) . '"');
        $position = 0;
        foreach ($groupIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            Db::getInstance()->insert('b2r_group_rule', array(
                'scope'    => pSQL($scope),
                'id_group' => $id,
                'active'   => 1,
                'position' => $position++,
            ));
        }
    }
}
