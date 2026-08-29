<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Mentions légales de TVA à porter sur la facture.
 *
 * Une vente exonérée doit indiquer le fondement de l'exonération. PrestaShop ne
 * propose qu'un texte libre unique, identique pour toutes les commandes : cette
 * classe choisit la mention qui correspond au régime réellement appliqué.
 *
 * Les textes sont saisis par le marchand et vides par défaut — le module ne
 * décide pas à sa place de ce qui est écrit sur une facture.
 */
class B2bInvoiceMentions
{
    /** Livraison intracommunautaire de biens à un assujetti. */
    const KEY_EU_GOODS = 'B2R_MENTION_EU_GOODS';

    /** Prestation de services intracommunautaire entre assujettis. */
    const KEY_EU_SERVICE = 'B2R_MENTION_EU_SERVICE';

    /** Exportation hors Union européenne. */
    const KEY_EXPORT = 'B2R_MENTION_EXPORT';

    /** Territoires hors champ d'application de la TVA (Guyane, Mayotte). */
    const KEY_OUTSIDE_VAT = 'B2R_MENTION_OUTSIDE_VAT';

    /** Collectivités françaises exclues du champ d'application de la TVA. */
    private static $outsideVatIso = array('GF', 'YT');

    /**
     * Clés de configuration, dans l'ordre d'affichage.
     *
     * @return string[]
     */
    public static function keys()
    {
        return array(
            self::KEY_EU_GOODS,
            self::KEY_EU_SERVICE,
            self::KEY_EXPORT,
            self::KEY_OUTSIDE_VAT,
        );
    }

    /**
     * Formulations usuelles, proposées en aide de saisie uniquement. Elles ne
     * sont jamais écrites automatiquement : elles supposent une boutique
     * française et doivent être validées par un comptable.
     *
     * @return array<string, string>
     */
    public static function suggestions()
    {
        return array(
            self::KEY_EU_GOODS    => 'Exonération de TVA, article 262 ter, I du CGI — autoliquidation par le preneur.',
            self::KEY_EU_SERVICE  => 'Autoliquidation, article 283-2 du CGI (article 44 de la directive 2006/112/CE).',
            self::KEY_EXPORT      => 'Exonération de TVA, article 262 I du CGI.',
            self::KEY_OUTSIDE_VAT => 'TVA non applicable, article 294-1 du CGI.',
        );
    }

    /**
     * Mention applicable à une commande, chaîne vide s'il n'y en a pas.
     *
     * Tout est déduit de la commande elle-même, jamais de l'état courant du
     * client : invalider un numéro de TVA six mois plus tard ne doit pas
     * réécrire une facture déjà émise.
     *
     * @param Order $order
     * @return string
     */
    public static function forOrder($order)
    {
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        // De la TVA a été facturée : il n'y a pas d'exonération à motiver.
        $vat = (float) $order->total_paid_tax_incl - (float) $order->total_paid_tax_excl;
        if ($vat > 0.005) {
            return '';
        }

        $key = self::keyForOrder($order);
        if ($key === '') {
            return '';
        }

        return trim((string) Configuration::get($key, (int) $order->id_lang, null, (int) $order->id_shop));
    }

    /**
     * Détermine le cas applicable à une commande exonérée.
     *
     * @param Order $order
     * @return string  clé de configuration, ou chaîne vide
     */
    private static function keyForOrder($order)
    {
        $idCountry = self::taxCountryId($order);
        if ($idCountry <= 0) {
            return '';
        }

        $iso = strtoupper((string) Country::getIsoById($idCountry));
        if ($iso === '') {
            return '';
        }

        if (in_array($iso, self::$outsideVatIso, true)) {
            return self::KEY_OUTSIDE_VAT;
        }

        switch (B2bCompat::countryScope($idCountry)) {
            case B2bGroupRule::SCOPE_EU:
                return self::isServiceOnly($order) ? self::KEY_EU_SERVICE : self::KEY_EU_GOODS;

            case B2bGroupRule::SCOPE_WORLD:
                return self::KEY_EXPORT;

            default:
                // Pays de la boutique : une commande sans TVA relève d'un régime
                // propre au vendeur (franchise en base, sous-traitance), pas du
                // client. Le texte libre de PrestaShop couvre déjà ce besoin.
                return '';
        }
    }

    /**
     * Adresse retenue pour la taxation, selon le réglage de la boutique.
     *
     * @param Order $order
     * @return int
     */
    private static function taxCountryId($order)
    {
        $field = Configuration::get('PS_TAX_ADDRESS_TYPE');
        if ($field !== 'id_address_invoice' && $field !== 'id_address_delivery') {
            $field = 'id_address_delivery';
        }

        $idAddress = (int) $order->{$field};
        if ($idAddress <= 0) {
            $idAddress = (int) $order->id_address_invoice;
        }
        if ($idAddress <= 0) {
            return 0;
        }

        $address = new Address($idAddress);

        return Validate::isLoadedObject($address) ? (int) $address->id_country : 0;
    }

    /**
     * Commande composée uniquement de produits dématérialisés.
     *
     * ponytail: heuristique — « produit virtuel » n'est pas « prestation de
     * services » au sens fiscal. Elle couvre le cas courant (téléchargements,
     * abonnements) ; une boutique qui vend des services non dématérialisés
     * devra saisir la même mention dans les deux champs.
     *
     * @param Order $order
     * @return bool
     */
    private static function isServiceOnly($order)
    {
        $products = $order->getProductsDetail();
        if (!is_array($products) || !$products) {
            return false;
        }

        foreach ($products as $row) {
            if (empty($row['is_virtual'])) {
                return false;
            }
        }

        return true;
    }
}
