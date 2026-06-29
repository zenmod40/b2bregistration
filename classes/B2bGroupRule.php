<?php
/**
 * Inscription B2B - Inscription professionnelle B2B pour PrestaShop
 *
 * @author    ZM40 — Nicolas Michaud (Magic Garden)
 * @copyright 2026 Nicolas Michaud — ZM40 / Magic Garden
 * @license   GPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
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
     * @param string $scope
     * @return int[]
     */
    public static function getGroupIdsForScope($scope)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id_group FROM `' . _DB_PREFIX_ . 'b2r_group_rule`
             WHERE active = 1 AND scope = "' . pSQL($scope) . '"
             ORDER BY position ASC'
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
            'SELECT scope, id_group FROM `' . _DB_PREFIX_ . 'b2r_group_rule`
             WHERE active = 1 ORDER BY position ASC'
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
