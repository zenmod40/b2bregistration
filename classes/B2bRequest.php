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
 * Demande d'inscription B2B : source de vérité métier (champs pro, résultats
 * de validation SIRET/TVA, statut de modération, groupe affecté).
 */
class B2bRequest extends ObjectModel
{
    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    // Tri-état de validation : -1 = non vérifié, 0 = invalide, 1 = valide.
    const CHECK_UNKNOWN = -1;
    const CHECK_INVALID = 0;
    const CHECK_VALID   = 1;

    /** @var int */
    public $id_b2r_request;
    /** @var int */
    public $id_customer;
    /** @var int */
    public $id_shop;
    /** @var string */
    public $company;
    /** @var string */
    public $siret;
    /** @var string */
    public $vat_number;
    /** @var string */
    public $ape;
    /** @var string */
    public $website;
    /** @var string */
    public $pro_phone;
    /** @var string */
    public $attachment;
    /** @var int */
    public $id_country;
    /** @var string */
    public $country_iso;
    /** @var string */
    public $country_scope;
    /** @var int */
    public $siret_valid;
    /** @var int */
    public $vat_valid;
    /** @var string */
    public $siret_detail;
    /** @var string */
    public $vat_detail;
    /** @var int */
    public $id_group_assigned;
    /** @var string */
    public $status;
    /** @var string */
    public $note;
    /** @var string */
    public $date_add;
    /** @var string */
    public $date_upd;

    public static $definition = array(
        'table'   => 'b2r_request',
        'primary' => 'id_b2r_request',
        'fields'  => array(
            'id_customer'       => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'id_shop'           => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'company'           => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 255),
            'siret'             => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32),
            'vat_number'        => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32),
            'ape'               => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16),
            'website'           => array('type' => self::TYPE_STRING, 'validate' => 'isUrlOrEmpty', 'size' => 255),
            'pro_phone'         => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32),
            'attachment'        => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 255),
            'id_country'        => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'country_iso'       => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 3),
            'country_scope'     => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16),
            'siret_valid'       => array('type' => self::TYPE_INT, 'validate' => 'isInt'),
            'vat_valid'         => array('type' => self::TYPE_INT, 'validate' => 'isInt'),
            'siret_detail'      => array('type' => self::TYPE_STRING, 'validate' => 'isCleanHtml'),
            'vat_detail'        => array('type' => self::TYPE_STRING, 'validate' => 'isCleanHtml'),
            'id_group_assigned' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'status'            => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16),
            'note'              => array('type' => self::TYPE_STRING, 'validate' => 'isCleanHtml'),
            'date_add'          => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd'          => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * Dernière demande B2B d'un client (la plus récente).
     *
     * @param int $idCustomer
     * @return B2bRequest|null
     */
    public static function getByCustomer($idCustomer)
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT id_b2r_request FROM `' . _DB_PREFIX_ . 'b2r_request`
             WHERE id_customer = ' . (int) $idCustomer . '
             ORDER BY id_b2r_request DESC'
        );

        return $id ? new self($id) : null;
    }

    /**
     * Supprime toutes les demandes B2B liées à un client (cascade depuis le
     * hookActionObjectCustomerDeleteAfter du module). Best-effort.
     *
     * @param int $idCustomer
     * @return bool
     */
    public static function deleteByCustomer($idCustomer)
    {
        $idCustomer = (int) $idCustomer;
        if ($idCustomer <= 0) {
            return false;
        }
        return (bool) Db::getInstance()->delete('b2r_request', 'id_customer = ' . $idCustomer);
    }
}
