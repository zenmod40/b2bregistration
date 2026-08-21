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
 * Validation SIRET / SIREN / TVA intracommunautaire.
 *
 * Trois niveaux, du moins coûteux au plus coûteux, tous fail-soft :
 *   1. Checksum offline (Luhn) — gratuit, instantané, hors-ligne.
 *   2. VIES (TVA intracommunautaire, UE) — service public EU, gratuit, sans clé.
 *   3. INSEE Sirene (SIRET, FR) — vérifie l'existence réelle de l'établissement ;
 *      nécessite une clé API INSEE gratuite.
 *
 * Toute méthode réseau renvoie un champ `reachable` : si false (timeout / panne /
 * non configuré), l'appelant décide de ne PAS bloquer l'inscription (fail-soft).
 */
class B2bValidator
{
    const HTTP_TIMEOUT = 5;

    // VIES REST (public, sans authentification).
    const VIES_REST = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/{ms}/vat/{num}';

    // INSEE Sirene — portail API (clé d'intégration en en-tête).
    const INSEE_SIRET = 'https://api.insee.fr/api-sirene/3.11/siret/{siret}';

    /**
     * Normalise un SIRET/SIREN : ne garde que les chiffres.
     */
    public static function normalizeSiret($value)
    {
        return preg_replace('/[^0-9]/', '', (string) $value);
    }

    /**
     * Normalise un numéro de TVA : majuscules, sans espaces ni ponctuation.
     */
    public static function normalizeVat($value)
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    /**
     * Algorithme de Luhn (clé de contrôle SIREN/SIRET).
     */
    private static function luhn($digits)
    {
        $sum = 0;
        $len = strlen($digits);
        $parity = $len % 2;
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$i];
            if (($i % 2) === $parity) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return ($sum % 10) === 0;
    }

    /**
     * Validité formelle d'un SIREN (9 chiffres + Luhn).
     */
    public static function isValidSiren($siren)
    {
        $siren = self::normalizeSiret($siren);
        if (strlen($siren) !== 9) {
            return false;
        }

        return self::luhn($siren);
    }

    /**
     * Numéro de TVA intracommunautaire FR calculable depuis le SIREN.
     * Algorithme officiel (déterministe, sans appel réseau) :
     *   clé = (12 + 3 × (SIREN mod 97)) mod 97
     *   TVA = "FR" + clé(2 chiffres) + SIREN
     * Retourne null si SIREN invalide.
     */
    public static function frenchVatFromSiren($siren)
    {
        $siren = self::normalizeSiret($siren);
        if (strlen($siren) !== 9 || !ctype_digit($siren)) {
            return null;
        }
        $key = (12 + 3 * ((int) $siren % 97)) % 97;

        return 'FR' . str_pad((string) $key, 2, '0', STR_PAD_LEFT) . $siren;
    }

    /**
     * Validité formelle d'un SIRET (14 chiffres + Luhn).
     * Exception La Poste : SIREN 356000000 — la somme des chiffres doit être
     * un multiple de 5 (les établissements ne respectent pas Luhn).
     */
    public static function isValidSiret($siret)
    {
        $siret = self::normalizeSiret($siret);
        if (strlen($siret) !== 14) {
            return false;
        }

        if (strpos($siret, '356000000') === 0) {
            $sum = array_sum(str_split($siret));
            return ($sum % 5) === 0;
        }

        return self::luhn($siret);
    }

    /**
     * Format plausible d'un numéro de TVA intracommunautaire (2 lettres + 2 à 13 alphanum).
     */
    public static function isValidVatFormat($vat)
    {
        $vat = self::normalizeVat($vat);

        return (bool) preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $vat);
    }

    /**
     * Vérification VIES (TVA intracommunautaire).
     *
     * @return array{valid:bool,reachable:bool,name:string,address:string,country:string}
     */
    public static function checkVies($vat)
    {
        $out = array('valid' => false, 'reachable' => false, 'name' => '', 'address' => '', 'country' => '');

        $vat = self::normalizeVat($vat);
        if (!self::isValidVatFormat($vat)) {
            // Format invalide → réponse certaine (joignabilité non requise).
            $out['reachable'] = true;
            return $out;
        }

        $ms  = Tools::substr($vat, 0, 2);
        $num = Tools::substr($vat, 2);
        $url = str_replace(array('{ms}', '{num}'), array(rawurlencode($ms), rawurlencode($num)), self::VIES_REST);

        $body = self::httpGet($url, array('Accept: application/json'));
        if ($body === '') {
            return $out; // injoignable → fail-soft
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $out;
        }

        // Codes d'erreur VIES qui signifient "service indisponible/saturé", PAS
        // "TVA invalide". Dans ces cas l'isValid:false retourné n'est pas fiable
        // → on marque reachable=false pour basculer en fail-soft (UNKNOWN, pas INVALID).
        // Réf: https://ec.europa.eu/taxation_customs/vies/technicalInformation.html
        $userError = isset($json['userError']) ? (string) $json['userError'] : '';
        $transientErrors = array(
            'MS_MAX_CONCURRENT_REQ',
            'GLOBAL_MAX_CONCURRENT_REQ',
            'SERVICE_UNAVAILABLE',
            'MS_UNAVAILABLE',
            'TIMEOUT',
        );
        if ($userError !== '' && in_array($userError, $transientErrors, true)) {
            // VIES injoignable de fait → fail-soft (CHECK_UNKNOWN côté appelant).
            $out['reachable'] = false;
            $out['country']   = $ms;
            $out['user_error'] = $userError;
            return $out;
        }

        $out['reachable'] = true;
        $out['valid']     = !empty($json['isValid']) || (isset($json['valid']) && $json['valid']);
        $out['name']      = isset($json['name']) ? (string) $json['name'] : '';
        $out['address']   = isset($json['address']) ? (string) $json['address'] : '';
        $out['country']   = $ms;
        if ($userError !== '') {
            $out['user_error'] = $userError;
        }

        return $out;
    }

    /**
     * Vérification SIRET via l'API Sirene de l'INSEE (établissement réel + actif).
     *
     * @param string $siret
     * @param string $apiKey  clé d'intégration INSEE
     * @return array{valid:bool,reachable:bool,active:bool,name:string,naf:string,street:string,postcode:string,city:string}
     */
    public static function checkInsee($siret, $apiKey)
    {
        $out = array(
            'valid' => false, 'reachable' => false, 'active' => false,
            'name' => '', 'naf' => '', 'street' => '', 'postcode' => '', 'city' => '',
        );

        $siret = self::normalizeSiret($siret);
        if (!self::isValidSiret($siret)) {
            $out['reachable'] = true; // format certainement invalide
            return $out;
        }
        if (trim((string) $apiKey) === '') {
            return $out; // non configuré → fail-soft (pas de blocage)
        }

        $url  = str_replace('{siret}', rawurlencode($siret), self::INSEE_SIRET);
        $body = self::httpGet($url, array(
            'Accept: application/json',
            'X-INSEE-Api-Key-Integration: ' . trim((string) $apiKey),
        ));
        if ($body === '') {
            return $out; // injoignable → fail-soft
        }

        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['etablissement'])) {
            // 404 = SIRET inexistant : réponse certaine.
            $out['reachable'] = true;
            return $out;
        }

        $out['reachable'] = true;
        $etab = $json['etablissement'];

        // État administratif courant de l'établissement (A = actif, F = fermé).
        $periodes = isset($etab['periodesEtablissement']) && is_array($etab['periodesEtablissement'])
            ? $etab['periodesEtablissement'] : array();
        $etat = '';
        if (!empty($periodes)) {
            $etat = isset($periodes[0]['etatAdministratifEtablissement'])
                ? (string) $periodes[0]['etatAdministratifEtablissement'] : '';
            $out['naf'] = isset($periodes[0]['activitePrincipaleEtablissement'])
                ? (string) $periodes[0]['activitePrincipaleEtablissement'] : '';
        }
        $out['active'] = ($etat === 'A');
        $out['valid']  = $out['active'];

        // Dénomination : personne morale, sinon nom/prénom (entrepreneur individuel).
        if (isset($etab['uniteLegale']['denominationUniteLegale']) && $etab['uniteLegale']['denominationUniteLegale'] !== '') {
            $out['name'] = (string) $etab['uniteLegale']['denominationUniteLegale'];
        } elseif (isset($etab['uniteLegale']['nomUniteLegale'])) {
            $prenom = isset($etab['uniteLegale']['prenom1UniteLegale']) ? (string) $etab['uniteLegale']['prenom1UniteLegale'] : '';
            $out['name'] = trim($prenom . ' ' . (string) $etab['uniteLegale']['nomUniteLegale']);
        }

        // Adresse de l'établissement (pour pré-remplissage).
        if (isset($etab['adresseEtablissement']) && is_array($etab['adresseEtablissement'])) {
            $a = $etab['adresseEtablissement'];
            $street = trim(implode(' ', array_filter(array(
                isset($a['numeroVoieEtablissement']) ? (string) $a['numeroVoieEtablissement'] : '',
                isset($a['typeVoieEtablissement']) ? (string) $a['typeVoieEtablissement'] : '',
                isset($a['libelleVoieEtablissement']) ? (string) $a['libelleVoieEtablissement'] : '',
            ))));
            $out['street']   = $street;
            $out['postcode'] = isset($a['codePostalEtablissement']) ? (string) $a['codePostalEtablissement'] : '';
            $out['city']     = isset($a['libelleCommuneEtablissement']) ? (string) $a['libelleCommuneEtablissement'] : '';
        }

        return $out;
    }

    /**
     * GET HTTP court, fail-silent. cURL puis fallback stream. '' si échec.
     */
    private static function httpGet($url, array $headers = array())
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_USERAGENT      => 'ZM40-B2BRegistration',
            ));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // 200 OK et 404 (réponse exploitable côté appelant) sont retournés ;
            // les erreurs réseau / 5xx renvoient '' → fail-soft.
            if (is_string($body) && $body !== '' && (($code >= 200 && $code < 300) || $code === 404)) {
                return $body;
            }
            return '';
        }

        $ctx = stream_context_create(array(
            'http' => array(
                'method'        => 'GET',
                'timeout'       => self::HTTP_TIMEOUT,
                'header'        => implode("\r\n", $headers),
                'ignore_errors' => true,
            ),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $body = @Tools::file_get_contents($url, false, $ctx);

        return is_string($body) ? $body : '';
    }
}
