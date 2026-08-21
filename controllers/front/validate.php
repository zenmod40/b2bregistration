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
 * Endpoint AJAX de vérification SIRET / TVA en temps réel + pré-remplissage.
 * En lecture seule, fail-soft : ne bloque jamais, renvoie toujours du JSON.
 */
class B2bregistrationValidateModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function postProcess()
    {
        $this->reply($this->compute());
    }

    public function initContent()
    {
        parent::initContent();
        $this->reply($this->compute());
    }

    private function compute()
    {
        require_once _PS_MODULE_DIR_ . 'b2bregistration/classes/B2bValidator.php';
        require_once _PS_MODULE_DIR_ . 'b2bregistration/classes/B2bCompat.php';
        require_once _PS_MODULE_DIR_ . 'b2bregistration/classes/B2bRateLimiter.php';

        // Anti-abus : 20 vérifications / minute / IP. Au-delà → fail-soft (examen
        // manuel), sans appel réseau : protège le quota INSEE / VIES des bots.
        if (!B2bRateLimiter::allow('validate_' . Tools::getRemoteAddr(), 20, 60)) {
            return array('checked' => true, 'reachable' => false, 'valid' => false, 'throttled' => true);
        }

        $field = Tools::getValue('field');
        $autofillEnabled = (int) Configuration::get('B2R_AUTOFILL');

        if ($field === 'siret') {
            if (!(int) Configuration::get('B2R_ENABLE_SIRET')) {
                return array('field' => 'siret', 'checked' => false);
            }
            $siret = B2bValidator::normalizeSiret(Tools::getValue('value'));
            $len = strlen($siret);

            // 9 chiffres = SIREN (identifiant entreprise) saisi à la place du SIRET (14, identifiant
            // établissement). Le SIREN passe le Luhn mais ne permet ni la vérif live INSEE Sirene
            // (qui requiert l'établissement précis) ni le pré-remplissage. On le refuse avec un
            // message clair plutôt que de simuler un "valide" trompeur.
            if ($len === 9 && B2bValidator::isValidSiren($siret)) {
                return array(
                    'field'     => 'siret',
                    'checked'   => true,
                    'reachable' => true,
                    'valid'     => false,
                    'is_siren'  => true,
                    'message'   => 'Vous avez saisi un SIREN (9 chiffres). Le champ SIRET attend 14 chiffres (SIREN + 5 chiffres de l\'établissement).',
                );
            }

            if (!B2bValidator::isValidSiret($siret)) {
                return array('field' => 'siret', 'checked' => true, 'reachable' => true, 'valid' => false);
            }

            if (!(int) Configuration::get('B2R_ENABLE_INSEE')) {
                // Vérif live désactivée : on confirme le format Luhn uniquement.
                return array('field' => 'siret', 'checked' => true, 'reachable' => true, 'valid' => true, 'format_only' => true);
            }

            $res = B2bValidator::checkInsee($siret, Configuration::get('B2R_INSEE_KEY'));
            $out = array(
                'field'     => 'siret',
                'checked'   => true,
                'reachable' => (bool) $res['reachable'],
                'valid'     => (bool) $res['valid'],
            );
            if ($autofillEnabled && $res['valid']) {
                // TVA FR calculable depuis le SIREN (algo deterministe, sans appel réseau) :
                // TVA = "FR" + ((12 + 3*(SIREN mod 97)) mod 97) + SIREN
                $siren = substr($siret, 0, 9);
                $frVat = B2bValidator::frenchVatFromSiren($siren);

                // Détection du territoire INSEE depuis le code postal (FR métropole
                // par défaut, ou DROM/COM/Monaco selon préfixe).
                $detectedIso = B2bCompat::isoFromFrenchPostcode((string) $res['postcode']);
                $detectedIdCountry = B2bCompat::idCountryFromIso($detectedIso);
                $detectedCountryName = '';
                if ($detectedIdCountry) {
                    $detectedCountryName = (string) Country::getNameById(
                        (int) Context::getContext()->language->id,
                        $detectedIdCountry
                    );
                }
                // Fallback : si le pays n'est pas activé en boutique (id=0) ou
                // si Country::getNameById renvoie vide, on prend le libellé statique
                // du territoire (Martinique, Guadeloupe, etc.) pour l'affichage.
                if ($detectedCountryName === '') {
                    $detectedCountryName = B2bCompat::inseeTerritoryName($detectedIso);
                }

                // Adresse formatée pour affichage "Siège (INSEE) : ..." sous le SIRET.
                // On inclut systématiquement le pays détecté (FR / DROM / COM / Monaco)
                // pour que le client confirme visuellement que c'est bien son territoire.
                $addressDisplay = trim(implode(' ', array_filter(array(
                    (string) $res['street'],
                    trim(((string) $res['postcode']) . ' ' . ((string) $res['city'])),
                ))));
                if ($detectedCountryName !== '') {
                    $addressDisplay .= ' — ' . $detectedCountryName;
                }

                $out['autofill'] = array(
                    'company'           => $res['name'],
                    'ape'               => $res['naf'],
                    'vat'               => $frVat,
                    'address_display'   => $addressDisplay,
                    'street'            => $res['street'],
                    'postcode'          => $res['postcode'],
                    'city'              => $res['city'],
                    'country_iso'       => $detectedIso,
                    'id_country'        => $detectedIdCountry,
                    'country_name'      => $detectedCountryName,
                );
            }

            return $out;
        }

        if ($field === 'vat') {
            if (!(int) Configuration::get('B2R_ENABLE_VIES')) {
                return array('field' => 'vat', 'checked' => false);
            }
            $vat = B2bValidator::normalizeVat(Tools::getValue('value'));
            if (!B2bValidator::isValidVatFormat($vat) || !B2bCompat::isViesCountry(Tools::substr($vat, 0, 2))) {
                return array('field' => 'vat', 'checked' => true, 'reachable' => true, 'valid' => false);
            }

            $res = B2bValidator::checkVies($vat);
            $out = array(
                'field'     => 'vat',
                'checked'   => true,
                'reachable' => (bool) $res['reachable'],
                'valid'     => (bool) $res['valid'],
            );
            if ($autofillEnabled && $res['valid'] && $res['name'] !== '') {
                $out['autofill'] = array('company' => $res['name']);
            }

            return $out;
        }

        return array('checked' => false);
    }

    private function reply(array $data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
