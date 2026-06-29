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
 * Couche de compatibilité PrestaShop 1.7 / 8 / 9 + intégration native opportuniste.
 *
 * Stratégie : code unique, stack legacy stable (ObjectModel + ModuleAdminController
 * + hooks de formulaire client). Les rares divergences de version sont isolées ici.
 */
class B2bCompat
{
    // Codes ISO des 27 États membres de l'UE (+ Irlande du Nord via XI sur la TVA).
    private static $euIso = array(
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE',
    );

    // Pays/territoires couverts par l'API INSEE Sirene : France métropolitaine,
    // Monaco (accord historique), DROM (départements d'outre-mer) et COM
    // (collectivités d'outre-mer). Tous utilisent le même système SIRENE.
    private static $inseeIso = array(
        'FR', 'MC',
        // DROM (départements et régions d'outre-mer)
        'GP', 'RE', 'MQ', 'GF', 'YT',
        // COM (collectivités d'outre-mer)
        'NC', 'PF', 'BL', 'MF', 'PM', 'WF',
    );

    // Territoires considérés "domestic" pour une boutique France métropolitaine
    // (FR + DROM, fiscalement et réglementairement traités comme territoire FR).
    // Monaco et COM ne sont PAS domestic (statuts douaniers / fiscaux différents).
    private static $frDomesticIso = array(
        'FR', 'GP', 'RE', 'MQ', 'GF', 'YT',
    );

    /**
     * Vrai à partir de PrestaShop 8.0.
     */
    public static function isPs8Plus()
    {
        return version_compare(_PS_VERSION_, '8.0.0', '>=');
    }

    /**
     * Code ISO (majuscule) du pays par défaut de la boutique.
     */
    public static function shopCountryIso()
    {
        $id = (int) Configuration::get('PS_COUNTRY_DEFAULT');

        return strtoupper((string) Country::getIsoById($id));
    }

    /**
     * Détermine le scope géographique d'un pays vis-à-vis de la boutique.
     *
     * @param int $idCountry
     * @return string  domestic | eu | world
     */
    public static function countryScope($idCountry)
    {
        $iso = strtoupper((string) Country::getIsoById((int) $idCountry));
        if ($iso === '') {
            return B2bGroupRule::SCOPE_WORLD;
        }

        $shopIso = self::shopCountryIso();

        // Domestic : pays direct OU territoires considérés comme tels pour FR.
        // Pour une boutique FR métropolitaine, les DROM (Guadeloupe, Réunion,
        // Martinique, Guyane, Mayotte) sont domestic au sens TVA / réglementaire.
        if ($iso === $shopIso) {
            return B2bGroupRule::SCOPE_DOMESTIC;
        }
        if ($shopIso === 'FR' && in_array($iso, self::$frDomesticIso, true)) {
            return B2bGroupRule::SCOPE_DOMESTIC;
        }

        if (in_array($iso, self::$euIso, true)) {
            return B2bGroupRule::SCOPE_EU;
        }

        return B2bGroupRule::SCOPE_WORLD;
    }

    /**
     * Pays éligible à la vérification VIES (UE + Irlande du Nord).
     */
    public static function isViesCountry($iso)
    {
        $iso = strtoupper((string) $iso);

        return in_array($iso, self::$euIso, true) || $iso === 'XI';
    }

    /**
     * Pays éligible à la vérification SIRET INSEE.
     * France métropolitaine + Monaco + DROM + COM (tous gérés par le SIRENE INSEE).
     */
    public static function isInseeCountry($iso)
    {
        return in_array(strtoupper((string) $iso), self::$inseeIso, true);
    }

    /**
     * Mappe un code postal français (issu d'INSEE) vers le code ISO du
     * territoire correspondant (FR métropole / DROM / COM / Monaco).
     * Réf INSEE COG : codes postaux 9xxxx pour DROM-COM, 980xx pour Monaco.
     *
     * @param string $postcode  ex: "97110", "98000", "13600"
     * @return string  code ISO 2 lettres (FR par défaut si non reconnu)
     */
    public static function isoFromFrenchPostcode($postcode)
    {
        $p = preg_replace('/\D/', '', (string) $postcode);
        if (strlen($p) < 2) {
            return 'FR';
        }
        // Monaco : postcodes 980xx
        if (strpos($p, '980') === 0) { return 'MC'; }
        // DROM (3 premiers chiffres)
        $prefix3 = substr($p, 0, 3);
        $dromMap = array(
            '971' => 'GP', // Guadeloupe
            '972' => 'MQ', // Martinique
            '973' => 'GF', // Guyane
            '974' => 'RE', // La Réunion
            '975' => 'PM', // Saint-Pierre-et-Miquelon
            '976' => 'YT', // Mayotte
            '977' => 'BL', // Saint-Barthélemy
            '978' => 'MF', // Saint-Martin (partie française)
            '986' => 'WF', // Wallis-et-Futuna
            '987' => 'PF', // Polynésie française
            '988' => 'NC', // Nouvelle-Calédonie
        );
        if (isset($dromMap[$prefix3])) {
            return $dromMap[$prefix3];
        }
        return 'FR';
    }

    /**
     * Trouve l'id_country PrestaShop correspondant à un code ISO. Retourne 0
     * si le pays n'est pas activé dans la boutique.
     */
    public static function idCountryFromIso($iso)
    {
        $iso = strtoupper((string) $iso);
        if ($iso === '') { return 0; }
        try {
            $id = (int) Country::getByIso($iso, true);
            return $id > 0 ? $id : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Liste des id_country PrestaShop correspondant aux territoires INSEE
     * (FR + MC + DROM + COM). Utilisée pour filtrer le dropdown pays en mode
     * "étranger" (l'utilisateur a cliqué "Pas de SIRET — entreprise hors France").
     *
     * @return int[]
     */
    public static function inseeIdCountries()
    {
        $ids = array();
        foreach (self::$inseeIso as $iso) {
            $id = self::idCountryFromIso($iso);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Libellé lisible d'un territoire INSEE depuis son ISO. Utilisé comme
     * fallback quand le pays n'est pas activé dans Country (PS retournerait '').
     * Ex: la Martinique peut ne pas figurer dans la liste activée de la boutique,
     * on veut quand même afficher "Martinique" au client après détection postcode.
     */
    public static function inseeTerritoryName($iso)
    {
        static $names = array(
            'FR' => 'France',
            'MC' => 'Monaco',
            'GP' => 'Guadeloupe',
            'MQ' => 'Martinique',
            'GF' => 'Guyane',
            'RE' => 'La Réunion',
            'YT' => 'Mayotte',
            'PM' => 'Saint-Pierre-et-Miquelon',
            'BL' => 'Saint-Barthélemy',
            'MF' => 'Saint-Martin',
            'WF' => 'Wallis-et-Futuna',
            'PF' => 'Polynésie française',
            'NC' => 'Nouvelle-Calédonie',
        );
        $iso = strtoupper((string) $iso);
        return isset($names[$iso]) ? $names[$iso] : '';
    }

    /**
     * Écrit de façon opportuniste les champs B2B natifs sur le client, si les
     * colonnes existent (siret, company, website, ape). Ne force jamais le mode B2B.
     *
     * @param Customer $customer
     * @param array    $data  clés : company, siret, website, ape
     */
    public static function writeNativeCustomerFields(Customer $customer, array $data)
    {
        $map = array(
            'company' => isset($data['company']) ? $data['company'] : null,
            'siret'   => isset($data['siret']) ? $data['siret'] : null,
            'website' => isset($data['website']) ? $data['website'] : null,
            'ape'     => isset($data['ape']) ? $data['ape'] : null,
        );

        $changed = false;
        foreach ($map as $prop => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (property_exists($customer, $prop)) {
                $customer->$prop = $value;
                $changed = true;
            }
        }

        if ($changed) {
            try {
                $customer->update();
            } catch (Exception $e) {
                // Colonne absente / contrainte : on n'échoue pas l'inscription.
            }
        }
    }

    /**
     * Reporte le numéro de TVA et la société sur les adresses du client (les
     * factures PrestaShop lisent la TVA depuis l'adresse, toutes versions).
     *
     * @param int    $idCustomer
     * @param string $vatNumber
     * @param string $company
     */
    public static function writeNativeAddressVat($idCustomer, $vatNumber, $company)
    {
        $vatNumber = trim((string) $vatNumber);
        $company   = trim((string) $company);
        if ($vatNumber === '' && $company === '') {
            return;
        }

        $ids = Db::getInstance()->executeS(
            'SELECT id_address FROM `' . _DB_PREFIX_ . 'address`
             WHERE id_customer = ' . (int) $idCustomer . ' AND deleted = 0'
        );
        if (!is_array($ids)) {
            return;
        }

        foreach ($ids as $row) {
            try {
                $address = new Address((int) $row['id_address']);
                $touched = false;
                if ($vatNumber !== '' && property_exists($address, 'vat_number') && $address->vat_number === '') {
                    $address->vat_number = $vatNumber;
                    $touched = true;
                }
                if ($company !== '' && property_exists($address, 'company') && (string) $address->company === '') {
                    $address->company = $company;
                    $touched = true;
                }
                if ($touched) {
                    $address->update();
                }
            } catch (Exception $e) {
                // Adresse invalide : on continue.
            }
        }
    }

    /**
     * Vide les caches susceptibles de masquer un nouveau contrôleur admin sur PS 8/9.
     * Fail-silent (les API diffèrent selon la version).
     */
    public static function clearCache()
    {
        try {
            if (method_exists('Tools', 'clearAllCache')) {
                Tools::clearAllCache();
            }
        } catch (Exception $e) {
            // best effort
        }
    }
}
