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

require_once dirname(__FILE__) . '/classes/B2bRequest.php';
require_once dirname(__FILE__) . '/classes/B2bGroupRule.php';
require_once dirname(__FILE__) . '/classes/B2bValidator.php';
require_once dirname(__FILE__) . '/classes/B2bCompat.php';
require_once dirname(__FILE__) . '/classes/B2bMailer.php';
require_once dirname(__FILE__) . '/classes/B2bInvoiceMentions.php';
require_once dirname(__FILE__) . '/classes/B2bWorkflow.php';

class B2bRegistration extends Module
{
    const ADMIN_CONTROLLER = 'AdminB2bRequests';

    /**
     * ID du customer dont on doit SUPPRIMER le mail de bienvenue natif PrestaShop.
     * Rempli par processProRegistration() en cas de refus auto. Consommé par
     * hookActionEmailSendBefore() qui annule l'envoi.
     */
    private static $suppressAccountCreationFor = 0;

    public function __construct()
    {
        $this->name = 'b2bregistration';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'ZM40';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('Inscription B2B');
        $this->description = $this->l('Inscription professionnelle B2B : champs dédiés, vérification SIRET (INSEE) et TVA intracommunautaire (VIES), affectation automatique de groupe par pays, modération.');
        $this->confirmUninstall = $this->l('Désinstaller Inscription B2B ? Les demandes et règles enregistrées seront supprimées.');
    }

    /* ----------------------------------------------------------------------
     * Install / uninstall
     * -------------------------------------------------------------------- */

    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('additionalCustomerFormFields')
            || !$this->registerHook('actionCustomerAccountAdd')
            || !$this->registerHook('actionCustomerAccountUpdate')
            || !$this->registerHook('displayCustomerAccountForm')
            || !$this->registerHook('displayHeader')
            || !$this->registerHook('actionObjectCustomerDeleteAfter')
            || !$this->registerHook('actionEmailSendBefore')
            || !$this->registerHook('displayNav1')
            || !$this->registerHook('displayNav2')
            || !$this->registerHook('displayInvoiceLegalFreeText')
        ) {
            return false;
        }

        if (!$this->installSql() || !$this->installTab()) {
            return false;
        }

        $this->setDefaults();
        $this->ensureDefaultGroup();

        return true;
    }

    public function uninstall()
    {
        $this->uninstallTab();
        $this->uninstallSql();

        foreach ($this->configKeys() as $key) {
            Configuration::deleteByName($key);
        }
        // Clés ZM40 Common (réseau) — nettoyage standard.
        foreach (array('ZM40_NET_ENABLED', 'ZM40_FEED_CACHE', 'ZM40_FEED_CACHE_TS', 'ZM40_LASTCHECK_B2BREGISTRATION', 'ZM40_LATEST_B2BREGISTRATION') as $k) {
            Configuration::deleteByName($k);
        }

        return parent::uninstall();
    }

    private function installSql()
    {
        $sql = include dirname(__FILE__) . '/sql/install.php';
        if (!is_array($sql)) {
            return false;
        }
        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    private function uninstallSql()
    {
        $sql = include dirname(__FILE__) . '/sql/uninstall.php';
        if (is_array($sql)) {
            foreach ($sql as $query) {
                try {
                    Db::getInstance()->execute($query);
                } catch (Exception $e) {
                    // best effort
                }
            }
        }

        return true;
    }

    private function installTab()
    {
        if (Tab::getIdFromClassName(self::ADMIN_CONTROLLER)) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = self::ADMIN_CONTROLLER;
        $tab->module = $this->name;
        $tab->active = 1;
        // Rattaché à la section Clients quand elle existe.
        $parent = (int) Tab::getIdFromClassName('AdminParentCustomer');
        if (!$parent) {
            $parent = (int) Tab::getIdFromClassName('AdminCustomers');
        }
        $tab->id_parent = $parent ? $parent : 0;
        $tab->icon = 'business_center';

        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = $this->l('Demandes B2B');
        }

        try {
            return (bool) $tab->add();
        } catch (Exception $e) {
            return false;
        }
    }

    private function uninstallTab()
    {
        $id = (int) Tab::getIdFromClassName(self::ADMIN_CONTROLLER);
        if ($id) {
            try {
                $tab = new Tab($id);
                $tab->delete();
            } catch (Exception $e) {
                // best effort
            }
        }

        return true;
    }

    /**
     * Clés de configuration du module (pour le nettoyage à la désinstallation).
     *
     * @return string[]
     */
    private function configKeys()
    {
        return array_merge(array(
            'B2R_ONLY_PRO', 'B2R_INTRO_CMS', 'B2R_TERMS_CMS',
            'B2R_FIELD_APE', 'B2R_FIELD_WEBSITE', 'B2R_FIELD_PHONE', 'B2R_ALLOW_UPLOAD',
            'B2R_REQUIRE_SIRET', 'B2R_REQUIRE_VAT',
            'B2R_ENABLE_SIRET', 'B2R_ENABLE_INSEE', 'B2R_INSEE_KEY',
            'B2R_ENABLE_VIES', 'B2R_BLOCK_INVALID', 'B2R_AUTOFILL',
            'B2R_MODERATION', 'B2R_NOTIFY_EMAILS',
            'B2R_ASSIGN_DOMESTIC', 'B2R_ASSIGN_EU', 'B2R_ASSIGN_WORLD',
            'B2R_USE_NATIVE_B2B', 'B2R_DEFAULT_GROUP',
            'B2R_HEADER_LINK', 'B2R_HEADER_LABEL', 'B2R_HEADER_HOOK',
            'B2R_CMS_TERMS', // ancienne clé (lue par erreur jusqu'en 1.0.6), nettoyée à la désinstallation
        ), B2bInvoiceMentions::keys());
    }

    private function setDefaults()
    {
        $defaults = array(
            'B2R_ONLY_PRO'        => 0,
            'B2R_INTRO_CMS'       => 0,
            'B2R_TERMS_CMS'       => 0,
            'B2R_FIELD_APE'       => 1,
            'B2R_FIELD_WEBSITE'   => 1,
            'B2R_FIELD_PHONE'     => 0,
            'B2R_ALLOW_UPLOAD'    => 0,
            'B2R_REQUIRE_SIRET'   => 1,
            'B2R_REQUIRE_VAT'     => 0, // beaucoup de pros FR n'ont pas de TVA intracom (franchise en base)
            'B2R_ENABLE_SIRET'    => 1,
            'B2R_ENABLE_INSEE'    => 0,
            'B2R_INSEE_KEY'       => '',
            'B2R_ENABLE_VIES'     => 1,
            'B2R_BLOCK_INVALID'   => 0, // par défaut on n'auto-refuse pas : l'admin modère manuellement
            'B2R_AUTOFILL'        => 1,
            'B2R_MODERATION'      => 0,
            'B2R_NOTIFY_EMAILS'   => (string) Configuration::get('PS_SHOP_EMAIL'),
            'B2R_ASSIGN_DOMESTIC' => 1,
            'B2R_ASSIGN_EU'       => 1,
            'B2R_ASSIGN_WORLD'    => 0,
            'B2R_USE_NATIVE_B2B'  => 0,
            'B2R_DEFAULT_GROUP'   => 0,
            'B2R_HEADER_LINK'     => 0, // bouton "Inscription Pro" dans le top bar
            'B2R_HEADER_LABEL'    => '', // libellé custom (vide → "Inscription Pro")
            'B2R_HEADER_HOOK'     => 'nav1', // nav1 (top bar) / nav2 (header)
            'ZM40_NET_ENABLED'    => 1,
        );
        foreach ($defaults as $k => $v) {
            Configuration::updateValue($k, $v);
        }
    }

    /**
     * Crée un groupe « Professionnels » par défaut s'il n'existe pas encore et
     * pose une règle d'affectation domestique. Idempotent.
     */
    private function ensureDefaultGroup()
    {
        $id = (int) Configuration::get('B2R_DEFAULT_GROUP');
        if ($id && Validate::isLoadedObject(new Group($id))) {
            return;
        }

        try {
            $group = new Group();
            foreach (Language::getLanguages(false) as $lang) {
                $group->name[(int) $lang['id_lang']] = $this->l('Professionnels');
            }
            $group->price_display_method = 1; // afficher les prix HT pour les pros
            $group->show_prices = true;
            $group->add();
            if ($group->id) {
                Configuration::updateValue('B2R_DEFAULT_GROUP', (int) $group->id);
                // Règle par défaut : pros du pays de la boutique → ce groupe.
                B2bGroupRule::replaceScope(B2bGroupRule::SCOPE_DOMESTIC, array((int) $group->id));
            }
        } catch (Exception $e) {
            // Si la création échoue, le module reste fonctionnel (modération manuelle possible).
        }
    }

    /* ----------------------------------------------------------------------
     * Front : médias + champs de formulaire
     * -------------------------------------------------------------------- */

    public function hookDisplayHeader()
    {
        if (!$this->isRegistrationContext()) {
            return;
        }
        $this->context->controller->addCSS($this->_path . 'views/css/front.css', 'all');
        $this->context->controller->addJS($this->_path . 'views/js/front.js');

        Media::addJsDef(array(
            'b2rConfig' => array(
                'ajaxUrl'      => $this->context->link->getModuleLink($this->name, 'validate', array(), true),
                'autofill'     => (int) Configuration::get('B2R_AUTOFILL'),
                'enableSiret'  => (int) Configuration::get('B2R_ENABLE_SIRET'),
                'enableInsee'  => (int) Configuration::get('B2R_ENABLE_INSEE'),
                'enableVies'   => (int) Configuration::get('B2R_ENABLE_VIES'),
                'requireSiret' => (int) Configuration::get('B2R_REQUIRE_SIRET'),
                'onlyPro'      => (int) Configuration::get('B2R_ONLY_PRO'),
                // Sur « Mes informations », les champs portent déjà les valeurs
                // du client : pas de reveal progressif (il masquerait tout tant
                // que le SIRET n'est pas resaisi).
                'isIdentity'   => (int) ($this->context->controller->php_self === 'identity'),
                // IDs des pays INSEE (FR + MC + DROM-COM) à exclure du dropdown
                // pays quand le client clique "Pas de SIRET — entreprise hors France".
                'inseeIdCountries' => B2bCompat::inseeIdCountries(),
                'token'        => Tools::getToken(false),
                'i18n'         => array(
                    'checking'    => $this->l('Vérification en cours...'),
                    'siretValid'  => $this->l('SIRET valide'),
                    'siretInvalid' => $this->l('SIRET invalide ou établissement fermé'),
                    'vatValid'    => $this->l('Numéro de TVA valide'),
                    'vatInvalid'  => $this->l('Numéro de TVA invalide'),
                    'unreachable' => $this->l('Vérification indisponible, votre demande sera examinée manuellement'),
                    'submitBlocked' => $this->l('Le SIRET doit être validé avant de pouvoir créer le compte professionnel.'),
                ),
            ),
        ));
    }

    /**
     * Sommes-nous sur une page où le formulaire client est affiché ?
     */
    private function isRegistrationContext()
    {
        $controller = isset($this->context->controller) ? $this->context->controller : null;
        if (!$controller || !isset($controller->php_self)) {
            return false;
        }

        return in_array($controller->php_self, array('authentication', 'registration', 'identity'), true);
    }

    /**
     * Champs additionnels du formulaire client (hook stable 1.7 → 9).
     *
     * @return array
     */
    public function hookAdditionalCustomerFormFields($params)
    {
        $fields = array();

        // En PS 1.7 / 8.x / 9, la classe canonique côté front est la legacy `FormField`
        // (autoload via classes/form/FormFieldCore.php). Le namespace
        // \PrestaShop\PrestaShop\Core\Form\FormField n'existe PAS en PS 8.2 (vérifié).
        // On utilise donc la legacy en priorité, avec fallback namespace pour les
        // installs custom qui pourraient l'exposer.
        $fqn = null;
        foreach (array('FormField', 'PrestaShop\\PrestaShop\\Core\\Form\\FormField') as $candidate) {
            if (class_exists($candidate)) {
                $fqn = $candidate;
                break;
            }
        }
        if ($fqn === null) {
            return $fields;
        }

        $onlyPro = (int) Configuration::get('B2R_ONLY_PRO');

        // NB : la bascule particulier/professionnel est désormais rendue en HAUT
        // du formulaire (tuiles via hookDisplayCustomerAccountForm), pas ici.
        // On n'ajoute donc plus la checkbox dans les FormField additionnels.

        $required = $onlyPro; // si boutique 100 % pro, les champs pro sont requis

        // Ordre UX optimisé : SIRET d'abord (auto-remplit Raison sociale + APE + TVA + Adresse),
        // puis Raison sociale, APE, TVA, Site web, Téléphone, Pays.

        if ((int) Configuration::get('B2R_ENABLE_SIRET')) {
            $siret = new $fqn();
            $siret->setName('b2r_siret')->setType('text')->setLabel($this->l('SIRET (14 chiffres)'))
                ->setRequired((bool) ($required && Configuration::get('B2R_REQUIRE_SIRET')));
            $fields[] = $siret;
        }

        $company = new $fqn();
        $company->setName('b2r_company')->setType('text')->setLabel($this->l('Raison sociale'))->setRequired((bool) $required);
        $fields[] = $company;

        if ((int) Configuration::get('B2R_FIELD_APE')) {
            $ape = new $fqn();
            $ape->setName('b2r_ape')->setType('text')->setLabel($this->l('Code APE / NAF'))->setRequired(false);
            $fields[] = $ape;
        }

        // Le numéro de TVA est un champ central du B2B : toujours proposé,
        // auto-rempli côté front depuis le SIREN (algo France) si pertinent.
        $vat = new $fqn();
        $vat->setName('b2r_vat')->setType('text')->setLabel($this->l('N° de TVA intracommunautaire'))
            ->setRequired((bool) ($required && Configuration::get('B2R_REQUIRE_VAT')));
        $fields[] = $vat;

        if ((int) Configuration::get('B2R_FIELD_WEBSITE')) {
            $web = new $fqn();
            $web->setName('b2r_website')->setType('text')->setLabel($this->l('Site web'))->setRequired(false);
            $fields[] = $web;
        }
        if ((int) Configuration::get('B2R_FIELD_PHONE')) {
            $phone = new $fqn();
            $phone->setName('b2r_phone')->setType('text')->setLabel($this->l('Téléphone professionnel'))->setRequired(false);
            $fields[] = $phone;
        }

        // Pays de l'entreprise (détermine le scope d'affectation de groupe).
        $country = new $fqn();
        $country->setName('b2r_country')->setType('select')->setLabel($this->l('Pays de l\'entreprise'))->setRequired(false);
        $values = array();
        foreach (Country::getCountries($this->context->language->id, true) as $c) {
            $values[(int) $c['id_country']] = $c['name'];
        }
        $country->setAvailableValues($values);
        $country->setValue((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        $fields[] = $country;

        $this->fillFieldsFromRequest($fields);

        return $fields;
    }

    /**
     * Repeuple les champs pro depuis la demande B2B du client connecté.
     *
     * PrestaShop ne remplit le formulaire client qu'avec les propriétés de
     * l'objet Customer (CustomerForm::fillFromCustomer) : nos champs b2r_*
     * n'en font pas partie et arrivaient donc vides sur « Mes informations ».
     * En POST, PrestaShop écrase ensuite ces valeurs par celles soumises.
     *
     * @param FormField[] $fields
     */
    private function fillFieldsFromRequest(array $fields)
    {
        $customer = isset($this->context->customer) ? $this->context->customer : null;
        if (!$customer || !$customer->id || !$customer->isLogged()) {
            return; // inscription : rien à pré-remplir
        }

        $request = B2bRequest::getByCustomer((int) $customer->id);
        if (!Validate::isLoadedObject($request)) {
            return; // client B2C
        }

        // array_filter : on ne remplace jamais une valeur par du vide (le pays
        // conserve ainsi le défaut boutique si la demande n'en portait pas).
        $values = array_filter(array(
            'b2r_siret'   => (string) $request->siret,
            'b2r_company' => (string) $request->company,
            'b2r_ape'     => (string) $request->ape,
            'b2r_vat'     => (string) $request->vat_number,
            'b2r_website' => (string) $request->website,
            'b2r_phone'   => (string) $request->pro_phone,
            'b2r_country' => (int) $request->id_country,
        ));

        foreach ($fields as $field) {
            $name = $field->getName();
            if (isset($values[$name])) {
                $field->setValue($values[$name]);
            }
        }
    }

    /**
     * Bloc d'introduction au-dessus du formulaire (lien CMS de préinscription).
     */
    public function hookDisplayCustomerAccountForm($params)
    {
        // La bascule particulier/pro n'a de sens QUE sur la page d'inscription
        // (création de compte). Sur la page "Mes informations" (controller 'identity'),
        // le client a déjà choisi son type, on ne ré-affiche pas les tuiles.
        $controller = isset($this->context->controller) ? $this->context->controller : null;
        $phpSelf = ($controller && isset($controller->php_self)) ? $controller->php_self : '';
        $isRegistration = ($phpSelf === 'registration' || $phpSelf === 'authentication');

        $introId = (int) Configuration::get('B2R_INTRO_CMS');
        $allowUpload = (int) Configuration::get('B2R_ALLOW_UPLOAD');
        $onlyPro = (int) Configuration::get('B2R_ONLY_PRO');

        // Hors inscription : on ne rend le template que s'il y a un upload ou un intro
        // CMS à afficher (sinon, rien à montrer sur la page identity).
        if (!$isRegistration && !$introId && !$allowUpload) {
            return '';
        }

        $introUrl = '';
        if ($introId) {
            try {
                $introUrl = $this->context->link->getCMSLink((int) $introId);
            } catch (Exception $e) {
                $introUrl = '';
            }
        }

        // CGV B2B custom : si une page CMS est désignée, on affiche une case à
        // cocher obligatoire côté Pro (attribut required posé par front.js).
        // Pas de contrôle serveur : la case n'est pas un champ du formulaire
        // client, et le compte existe déjà quand actionCustomerAccountAdd s'exécute.
        $termsUrl = '';
        $termsCmsId = (int) Configuration::get('B2R_TERMS_CMS');
        if ($termsCmsId) {
            try {
                $termsUrl = $this->context->link->getCMSLink($termsCmsId);
            } catch (Exception $e) {
                $termsUrl = '';
            }
        }

        $this->context->smarty->assign(array(
            'b2r_intro_url'    => $introUrl,
            'b2r_allow_upload' => $allowUpload,
            'b2r_only_pro'     => $onlyPro,
            'b2r_show_tiles'   => $isRegistration,
            'b2r_terms_url'    => $termsUrl,
        ));

        return $this->display(__FILE__, 'views/templates/hook/account_form.tpl');
    }

    /* ----------------------------------------------------------------------
     * Front : traitement de l'inscription professionnelle
     * -------------------------------------------------------------------- */

    public function hookActionCustomerAccountAdd($params)
    {
        if (empty($params['newCustomer']) || !($params['newCustomer'] instanceof Customer)) {
            return;
        }
        $customer = $params['newCustomer'];

        $onlyPro = (int) Configuration::get('B2R_ONLY_PRO');
        $isPro = $onlyPro ? true : (bool) Tools::getValue('b2r_is_pro');
        if (!$isPro) {
            return; // inscription B2C standard
        }

        $this->processProRegistration($customer);
    }

    /**
     * Mise à jour des champs pro depuis « Mes informations » (page identity).
     * Sans ça, les valeurs affichées seraient réaffichées telles quelles au
     * rechargement et toute correction du client serait perdue.
     */
    public function hookActionCustomerAccountUpdate($params)
    {
        if (empty($params['customer']) || !($params['customer'] instanceof Customer)) {
            return;
        }
        $customer = $params['customer'];

        // Le formulaire client sert aussi ailleurs (tunnel, autres modules) :
        // si aucun champ pro n'a été soumis, on ne touche à rien.
        if (!Tools::getIsset('b2r_siret') && !Tools::getIsset('b2r_company')) {
            return;
        }

        $request = B2bRequest::getByCustomer((int) $customer->id);
        if (!Validate::isLoadedObject($request)) {
            return; // client B2C : aucune demande à mettre à jour
        }
        $tracked = array('company', 'siret', 'vat_number', 'ape', 'website', 'pro_phone', 'id_country');
        $before = array();
        foreach ($tracked as $property) {
            $before[$property] = (string) $request->$property;
        }

        if (Tools::getIsset('b2r_siret')) {
            $siret = B2bValidator::normalizeSiret(Tools::getValue('b2r_siret'));
            if ($siret !== (string) $request->siret) {
                $request->siret = $siret;
                // Identifiant modifié après coup : la vérification d'origine ne
                // vaut plus. On repasse en « non vérifié » pour que le back-office
                // ne présente pas un SIRET validé qui ne l'est plus.
                $request->siret_valid = B2bRequest::CHECK_UNKNOWN;
                $request->siret_detail = json_encode(array('reason' => 'customer_edit'));
            }
        }
        if (Tools::getIsset('b2r_vat')) {
            $vat = B2bValidator::normalizeVat(Tools::getValue('b2r_vat'));
            if ($vat !== (string) $request->vat_number) {
                $request->vat_number = $vat;
                $request->vat_valid = B2bRequest::CHECK_UNKNOWN;
                $request->vat_detail = json_encode(array('reason' => 'customer_edit'));
            }
        }
        $simple = array(
            'b2r_company' => 'company',
            'b2r_ape'     => 'ape',
            'b2r_website' => 'website',
            'b2r_phone'   => 'pro_phone',
        );
        foreach ($simple as $input => $property) {
            if (Tools::getIsset($input)) {
                $request->$property = trim((string) Tools::getValue($input));
            }
        }
        $idCountry = (int) Tools::getValue('b2r_country');
        if ($idCountry && $idCountry !== (int) $request->id_country) {
            $request->id_country = $idCountry;
            $request->country_iso = strtoupper((string) Country::getIsoById($idCountry));
            // Le scope est recalculé pour le back-office, mais les groupes déjà
            // affectés ne bougent pas : un changement de pays reste une décision admin.
            $request->country_scope = B2bCompat::countryScope($idCountry);
        }

        try {
            $request->update();
        } catch (Exception $e) {
            return;
        }
        $changed = array();
        foreach ($tracked as $property) {
            if ((string) $request->$property !== $before[$property]) {
                $changed[] = $property;
            }
        }
        if ($changed) {
            B2bWorkflow::logHistory($request->id, 'customer_edit', $request->status, $request->status, 0, 'front', implode(', ', $changed));
        }

        B2bCompat::writeNativeCustomerFields($customer, array(
            'company' => $request->company,
            'siret'   => $request->siret,
            'website' => $request->website,
            'ape'     => $request->ape,
        ));
        B2bCompat::writeNativeAddressVat((int) $customer->id, $request->vat_number, $request->company);
    }

    /**
     * Lien "Inscription Pro" dans la nav top (nav1 par défaut).
     * Affiché seulement si B2R_HEADER_LINK=1 et que le hook choisi en BO correspond.
     * Cible : page CMS dédiée si B2R_INTRO_CMS défini, sinon /inscription?b2r_pro=1
     * (le JS auto-sélectionne la tuile Pro au load).
     */
    public function hookDisplayNav1($params)
    {
        return $this->renderHeaderLink('nav1');
    }

    public function hookDisplayNav2($params)
    {
        return $this->renderHeaderLink('nav2');
    }

    /**
     * Mention de TVA sur la facture PDF.
     *
     * Attention : le noyau ne lit `PS_INVOICE_LEGAL_FREE_TEXT` que si ce hook
     * ne renvoie rien. Dès qu'on répond quelque chose, on remplace le texte
     * libre de la boutique — il faut donc le reprendre nous-mêmes.
     */
    public function hookDisplayInvoiceLegalFreeText($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $mention = B2bInvoiceMentions::forOrder($order);
        if ($mention === '') {
            // Rien à ajouter : le noyau reprend le texte libre de la boutique.
            return '';
        }

        $shopText = trim((string) Configuration::get(
            'PS_INVOICE_LEGAL_FREE_TEXT',
            (int) $order->id_lang,
            null,
            (int) $order->id_shop
        ));

        return $shopText === '' ? $mention : $shopText . "\n" . $mention;
    }

    private function renderHeaderLink($hookId)
    {
        if (!(int) Configuration::get('B2R_HEADER_LINK')) {
            return '';
        }
        $chosenHook = (string) Configuration::get('B2R_HEADER_HOOK');
        if ($chosenHook === '') { $chosenHook = 'nav1'; }
        if ($chosenHook !== $hookId) {
            return '';
        }

        $label = trim((string) Configuration::get('B2R_HEADER_LABEL'));
        if ($label === '') {
            $label = $this->l('Inscription Pro');
        }

        // Cible : si une CMS d'intro pro est configurée → on pointe dessus, sinon
        // le formulaire d'inscription standard avec ?b2r_pro=1 (auto-sélection Pro).
        $url = '';
        $cmsId = (int) Configuration::get('B2R_INTRO_CMS');
        if ($cmsId) {
            try {
                $url = $this->context->link->getCMSLink($cmsId);
            } catch (Exception $e) {
                $url = '';
            }
        }
        if ($url === '') {
            try {
                $url = $this->context->link->getPageLink('registration', true) . '?b2r_pro=1';
            } catch (Exception $e) {
                $url = __PS_BASE_URI__ . 'inscription?b2r_pro=1';
            }
        }

        $this->context->smarty->assign(array(
            'b2r_header_url'   => $url,
            'b2r_header_label' => $label,
        ));

        return $this->display(__FILE__, 'views/templates/hook/header_link.tpl');
    }

    /**
     * Cascade : à la suppression d'un client, on supprime ses demandes B2B.
     * Évite les orphelins (demande "en attente" liée à un client inexistant).
     */
    public function hookActionObjectCustomerDeleteAfter($params)
    {
        if (empty($params['object']) || !($params['object'] instanceof Customer)) {
            return;
        }
        $idCustomer = (int) $params['object']->id;
        if ($idCustomer <= 0) {
            return;
        }
        require_once _PS_MODULE_DIR_ . 'b2bregistration/classes/B2bRequest.php';
        try {
            B2bRequest::deleteByCustomer($idCustomer);
        } catch (Exception $e) {
            // best-effort
        }
    }

    /**
     * Suppression du mail "Bienvenue / compte créé" natif PrestaShop lorsque
     * la demande B2B associée a été refusée auto (processProRegistration a
     * mis l'id client dans self::$suppressAccountCreationFor). Sinon le client
     * recevrait à la fois un mail "compte créé" et un mail "demande refusée".
     *
     * Retourne false pour annuler l'envoi.
     */
    public function hookActionEmailSendBefore($params)
    {
        if (!self::$suppressAccountCreationFor) {
            return true;
        }
        $template = isset($params['template']) ? (string) $params['template'] : '';
        // Le template natif PS pour la création de compte client est "account".
        if ($template !== 'account') {
            return true;
        }
        $to = isset($params['to']) ? $params['to'] : array();
        if (!is_array($to)) {
            return true;
        }
        // Si le destinataire correspond au customer qu'on vient de refuser
        // → on annule l'envoi (autres mails de la même session ignorés).
        $customer = new Customer((int) self::$suppressAccountCreationFor);
        if (!Validate::isLoadedObject($customer)) {
            // Customer déjà supprimé → on bloque par sécurité.
            return false;
        }
        foreach ($to as $addr) {
            if (is_string($addr) && strcasecmp(trim($addr), trim((string) $customer->email)) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Cœur du workflow : collecte, validation, stockage, affectation, e-mails.
     */
    private function processProRegistration(Customer $customer)
    {
        $idLang = (int) $this->context->language->id;
        $idCountry = (int) Tools::getValue('b2r_country');
        if (!$idCountry) {
            $idCountry = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        }
        $iso = strtoupper((string) Country::getIsoById($idCountry));
        $scope = B2bCompat::countryScope($idCountry);

        $company = trim((string) Tools::getValue('b2r_company'));
        $siret = B2bValidator::normalizeSiret(Tools::getValue('b2r_siret'));
        $vat = B2bValidator::normalizeVat(Tools::getValue('b2r_vat'));
        $ape = trim((string) Tools::getValue('b2r_ape'));
        $website = trim((string) Tools::getValue('b2r_website'));
        $phone = trim((string) Tools::getValue('b2r_phone'));

        // --- Validation (fail-soft) ---
        $siretValid = B2bRequest::CHECK_UNKNOWN;
        $vatValid = B2bRequest::CHECK_UNKNOWN;
        $siretDetail = array();
        $vatDetail = array();
        $blocked = false;

        $check = B2bWorkflow::checkSiret($siret, $iso);
        if ($check !== null) {
            $siretValid = $check['valid'];
            $siretDetail = $check['detail'];
            if ($siretValid === B2bRequest::CHECK_VALID && isset($siretDetail['name'])) { // INSEE
                if ($company === '' && $siretDetail['name'] !== '') {
                    $company = $siretDetail['name'];
                }
                if ($ape === '' && $siretDetail['naf'] !== '') {
                    $ape = $siretDetail['naf'];
                }
            }

            if ((int) Configuration::get('B2R_BLOCK_INVALID')
                && (int) Configuration::get('B2R_REQUIRE_SIRET')
                && $siretValid === B2bRequest::CHECK_INVALID) {
                $blocked = true;
            }
        }

        $check = B2bWorkflow::checkVat($vat, $siret);
        if ($check !== null) {
            $vatValid = $check['valid'];
            $vatDetail = $check['detail'];
            if ($vatValid === B2bRequest::CHECK_VALID && $company === '' && $vatDetail['name'] !== '') {
                $company = $vatDetail['name'];
            }

            // Le n° de TVA a-t-il été auto-calculé depuis le SIREN (algo FR) ?
            // Si oui, c'est une suggestion, pas une déclaration du client : on ne
            // bloque PAS s'il n'est pas enregistré au VIES (cas franchise en base,
            // micro-entreprise, etc. qui ont une TVA calculable mais non active).
            if ((int) Configuration::get('B2R_BLOCK_INVALID')
                && (int) Configuration::get('B2R_REQUIRE_VAT')
                && $vatValid === B2bRequest::CHECK_INVALID
                && empty($vatDetail['auto_computed'])) {
                $blocked = true;
            }
        }

        // --- Pièce jointe (Kbis) ---
        $attachment = $this->handleUpload($customer);

        // --- Décision de statut ---
        $moderation = (int) Configuration::get('B2R_MODERATION');
        if ($blocked) {
            $status = B2bRequest::STATUS_REJECTED;
        } elseif ($moderation) {
            $status = B2bRequest::STATUS_PENDING;
        } else {
            $status = B2bRequest::STATUS_APPROVED;
        }

        // --- Enregistrement de la demande ---
        $request = new B2bRequest();
        $request->id_customer = (int) $customer->id;
        $request->id_shop = (int) $this->context->shop->id;
        $request->company = $company;
        $request->siret = $siret;
        $request->vat_number = $vat;
        $request->ape = $ape;
        $request->website = $website;
        $request->pro_phone = $phone;
        $request->attachment = $attachment;
        $request->id_country = $idCountry;
        $request->country_iso = $iso;
        $request->country_scope = $scope;
        $request->siret_valid = (int) $siretValid;
        $request->vat_valid = (int) $vatValid;
        $request->siret_detail = json_encode($siretDetail);
        $request->vat_detail = json_encode($vatDetail);
        $request->status = $status;
        $request->note = $blocked ? $this->l('Refus automatique : identifiant fiscal invalide.') : '';
        try {
            $request->add();
        } catch (Exception $e) {
            return;
        }
        B2bWorkflow::logHistory($request->id, 'create', '', $status, 0, 'front', $blocked ? $request->note : null);

        // --- Champs natifs opportunistes (factures, BO) ---
        B2bCompat::writeNativeCustomerFields($customer, array(
            'company' => $company,
            'siret'   => $siret,
            'website' => $website,
            'ape'     => $ape,
        ));
        B2bCompat::writeNativeAddressVat((int) $customer->id, $vat, $company);

        // --- Affectation de groupe + e-mails selon le statut ---
        if ($status === B2bRequest::STATUS_APPROVED) {
            B2bWorkflow::assignGroups($customer, $request);
            B2bMailer::sendApproved($customer, $idLang);
        } elseif ($status === B2bRequest::STATUS_PENDING) {
            B2bMailer::sendPending($customer, $idLang);
            B2bMailer::sendAdminNotice($customer, $request, $idLang, $this->getNotifyEmails());
        } else { // rejected
            B2bMailer::sendRejected($customer, $idLang, $request->note);

            // Refus auto = le compte client PrestaShop ne doit PAS être conservé
            // (cohérence métier : on a refusé l'entreprise, on n'en garde pas
            // l'enveloppe perso). Marqueur statique consommé par
            // hookActionEmailSendBefore() pour bloquer le mail de bienvenue natif.
            self::$suppressAccountCreationFor = (int) $customer->id;
            try {
                // On lie la demande à id_customer=0 pour conserver la trace
                // métier (audit) sans bloquer la suppression du customer.
                $request->id_customer = 0;
                $request->update();
            } catch (Exception $e) {
                // best-effort, on continue.
            }
            try {
                $customer->delete();
            } catch (Exception $e) {
                // best-effort, on continue.
            }
        }

        // Notifie aussi l'admin en mode auto (visibilité des nouveaux pros).
        if ($status === B2bRequest::STATUS_APPROVED) {
            B2bMailer::sendAdminNotice($customer, $request, $idLang, $this->getNotifyEmails());
        }
    }

    /**
     * Gère l'upload optionnel d'une pièce justificative (Kbis).
     *
     * @return string  nom de fichier stocké, ou ''
     */
    private function handleUpload(Customer $customer)
    {
        if (!(int) Configuration::get('B2R_ALLOW_UPLOAD') || empty($_FILES['b2r_attachment']['tmp_name'])) {
            return '';
        }
        $file = $_FILES['b2r_attachment'];
        if (!empty($file['error']) || !is_uploaded_file($file['tmp_name'])) {
            return '';
        }
        if ((int) $file['size'] > 4 * 1024 * 1024) { // 4 Mo max
            return '';
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = array('pdf', 'jpg', 'jpeg', 'png');
        if (!in_array($ext, $allowed, true)) {
            return '';
        }

        $dir = _PS_MODULE_DIR_ . $this->name . '/uploads/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @file_put_contents($dir . 'index.php', "<?php header('Location: ../');\n");
            @file_put_contents($dir . '.htaccess', "Deny from all\n");
        }
        $name = 'kbis_' . (int) $customer->id . '_' . Tools::passwdGen(8) . '.' . $ext;
        if (!@move_uploaded_file($file['tmp_name'], $dir . $name)) {
            return '';
        }

        return $name;
    }

    /**
     * Liste des e-mails à notifier (config B2R_NOTIFY_EMAILS, séparés par , ou ;).
     *
     * @return string[]
     */
    private function getNotifyEmails()
    {
        $raw = (string) Configuration::get('B2R_NOTIFY_EMAILS');
        $parts = preg_split('/[,;\s]+/', $raw);
        $out = array();
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && Validate::isEmail($p)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /* ----------------------------------------------------------------------
     * API publique (utilisée par le contrôleur de modération)
     * -------------------------------------------------------------------- */

    /**
     * Approuve une demande (compatibilité 1.0.x) : délègue à B2bWorkflow.
     *
     * @return bool
     */
    public function approveRequest($idRequest)
    {
        try {
            B2bWorkflow::approve(new B2bRequest((int) $idRequest), (int) $this->context->employee->id, 'bo');
        } catch (B2bWorkflowException $e) {
            return false;
        }

        return true;
    }

    /**
     * Refuse une demande (compatibilité 1.0.x) : délègue à B2bWorkflow.
     *
     * @return bool
     */
    public function rejectRequest($idRequest, $reason = '')
    {
        try {
            B2bWorkflow::reject(new B2bRequest((int) $idRequest), $reason, (int) $this->context->employee->id, 'bo');
        } catch (B2bWorkflowException $e) {
            return false;
        }

        return true;
    }

    /* ----------------------------------------------------------------------
     * Back-office : page de configuration
     * -------------------------------------------------------------------- */

    public function getContent()
    {
        require_once dirname(__FILE__) . '/lib/zm40/Zm40CommonB2b.php';

        $output = '';
        if (Tools::isSubmit('submitB2rConfig')) {
            $output .= $this->postProcessConfig();
        }

        $this->context->controller->addCSS($this->_path . 'views/css/zm40-common.css');
        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');

        $this->assignZm40();
        $this->context->smarty->assign(array(
            'b2r_form'      => $this->renderConfigForm(),
            'b2r_rules'     => B2bGroupRule::getAllByScope(),
            'b2r_groups'    => Group::getGroups($this->context->language->id),
            'b2r_pending'   => (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'b2r_request` WHERE status = "pending"'
            ),
            'b2r_bo_link'   => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER),
            'zm40_ah_name'  => $this->displayName,
            'zm40_ah_sub'   => $this->l('Inscription professionnelle, SIRET / TVA, groupe automatique'),
            'zm40_ah_version' => $this->version,
            'zm40_ah_shop'  => Configuration::get('PS_SHOP_NAME'),
        ));

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    private function assignZm40()
    {
        $this->context->smarty->assign(array(
            'zm40_net_enabled'   => Zm40CommonB2b::isNetEnabled() ? 1 : 0,
            'zm40_footer_html'   => Zm40CommonB2b::footer($this->displayName, $this->version, $this->name),
            'zm40_update'        => Zm40CommonB2b::checkUpdate($this->name, $this->version),
            'zm40_modules'       => Zm40CommonB2b::modulesFeed($this->name),
            'zm40_about_name'    => $this->displayName,
            'zm40_about_license' => 'OSL 3.0',
            'zm40_about_github'  => Zm40CommonB2b::githubUrl($this->name),
            'zm40_about_site'    => Zm40CommonB2b::siteUrl($this->name, 'panel', '/contact'),
            'zm40_about_modules' => Zm40CommonB2b::siteUrl($this->name, 'panel', '/'),
        ));
    }

    private function postProcessConfig()
    {
        $bools = array(
            'B2R_ONLY_PRO', 'B2R_FIELD_APE', 'B2R_FIELD_WEBSITE', 'B2R_FIELD_PHONE',
            'B2R_ALLOW_UPLOAD', 'B2R_REQUIRE_SIRET', 'B2R_REQUIRE_VAT', 'B2R_ENABLE_SIRET',
            'B2R_ENABLE_INSEE', 'B2R_ENABLE_VIES', 'B2R_BLOCK_INVALID', 'B2R_AUTOFILL',
            'B2R_MODERATION', 'B2R_ASSIGN_DOMESTIC', 'B2R_ASSIGN_EU', 'B2R_ASSIGN_WORLD',
            'B2R_USE_NATIVE_B2B', 'ZM40_NET_ENABLED',
        );
        foreach ($bools as $k) {
            Configuration::updateValue($k, (int) Tools::getValue($k));
        }
        Configuration::updateValue('B2R_INSEE_KEY', trim((string) Tools::getValue('B2R_INSEE_KEY')));
        Configuration::updateValue('B2R_NOTIFY_EMAILS', trim((string) Tools::getValue('B2R_NOTIFY_EMAILS')));
        Configuration::updateValue('B2R_INTRO_CMS', (int) Tools::getValue('B2R_INTRO_CMS'));
        Configuration::updateValue('B2R_TERMS_CMS', (int) Tools::getValue('B2R_TERMS_CMS'));

        // Règles d'affectation de groupe par scope.
        foreach (array(B2bGroupRule::SCOPE_DOMESTIC, B2bGroupRule::SCOPE_EU, B2bGroupRule::SCOPE_WORLD) as $scope) {
            $sel = Tools::getValue('b2r_groups_' . $scope);
            if (!is_array($sel)) {
                $sel = array();
            }
            B2bGroupRule::replaceScope($scope, array_map('intval', $sel));
        }

        // Mentions de facture : un champ par langue, HTML interdit (le PDF
        // échappe le texte, une balise s'y afficherait telle quelle).
        foreach (B2bInvoiceMentions::keys() as $key) {
            $perLang = array();
            foreach ($this->context->controller->getLanguages() as $lang) {
                $idLang = (int) $lang['id_lang'];
                $perLang[$idLang] = strip_tags(trim((string) Tools::getValue($key . '_' . $idLang)));
            }
            Configuration::updateValue($key, $perLang);
        }

        // Mode B2B natif PrestaShop (optionnel).
        Configuration::updateValue('PS_B2B_ENABLE', (int) Tools::getValue('B2R_USE_NATIVE_B2B'));

        Zm40CommonB2b::clearFeedCache();
        B2bCompat::clearCache();

        return $this->displayConfirmation($this->l('Paramètres enregistrés.'));
    }

    /**
     * Formulaire de configuration (HelperForm).
     */
    private function renderConfigForm()
    {
        $cmsList = $this->getCmsOptions();

        $onOff = function ($name, $label, $tab, $desc = '') {
            return array(
                'type' => 'switch', 'label' => $label, 'name' => $name, 'is_bool' => true,
                'tab' => $tab, 'desc' => $desc,
                'values' => array(
                    array('id' => $name . '_on', 'value' => 1, 'label' => $this->l('Oui')),
                    array('id' => $name . '_off', 'value' => 0, 'label' => $this->l('Non')),
                ),
            );
        };

        $suggestions = B2bInvoiceMentions::suggestions();
        $mention = function ($name, $label, $desc) use ($suggestions) {
            return array(
                'type' => 'textarea', 'label' => $label, 'name' => $name,
                'tab' => 't_invoice', 'lang' => true, 'rows' => 2, 'cols' => 60,
                'desc' => $desc . ' ' . sprintf($this->l('Formulation usuelle : « %s »'), $suggestions[$name]),
            );
        };

        $inputs = array(
            // Onglet « Formulaire »
            $onOff('B2R_ONLY_PRO', $this->l('Boutique réservée aux professionnels'), 't_form', $this->l('Masque l\'inscription des particuliers ; tous les comptes sont B2B.')),
            $onOff('B2R_FIELD_APE', $this->l('Champ Code APE / NAF'), 't_form'),
            $onOff('B2R_FIELD_WEBSITE', $this->l('Champ Site web'), 't_form'),
            $onOff('B2R_FIELD_PHONE', $this->l('Champ Téléphone professionnel'), 't_form'),
            $onOff('B2R_ALLOW_UPLOAD', $this->l('Autoriser l\'envoi d\'une pièce (Kbis, PDF/JPG/PNG, 4 Mo max)'), 't_form'),
            array('type' => 'select', 'tab' => 't_form', 'label' => $this->l('Page CMS de préinscription'), 'name' => 'B2R_INTRO_CMS',
                'options' => array('query' => $cmsList, 'id' => 'id', 'name' => 'name'),
                'desc' => $this->l('Affichée en lien au-dessus du formulaire pour informer les pros.')),
            array('type' => 'select', 'tab' => 't_form', 'label' => $this->l('Page CMS des CGV professionnelles'), 'name' => 'B2R_TERMS_CMS',
                'options' => array('query' => $cmsList, 'id' => 'id', 'name' => 'name')),

            // Onglet « Vérification SIRET / TVA »
            $onOff('B2R_ENABLE_SIRET', $this->l('Vérifier le SIRET (FR)'), 't_valid', $this->l('Contrôle de la clé (Luhn), hors-ligne et instantané.')),
            $onOff('B2R_ENABLE_INSEE', $this->l('Vérification live INSEE Sirene'), 't_valid', $this->l('Existence réelle de l\'établissement + pré-remplissage. Nécessite une clé API INSEE gratuite.')),
            array('type' => 'text', 'tab' => 't_valid', 'label' => $this->l('Clé API INSEE'), 'name' => 'B2R_INSEE_KEY',
                'desc' => $this->l('Clé d\'intégration du portail API INSEE. Gratuite — voir le guide ci-dessous.')),
            array('type' => 'html', 'tab' => 't_valid', 'name' => 'b2r_insee_guide', 'html_content' =>
                '<details class="b2r-guide">'
                . '<summary>' . $this->l('Comment obtenir ma clé API INSEE ? (gratuit, ~5 min)') . '</summary>'
                . '<div class="b2r-guide-body">'
                . '<ol style="margin:10px 0 0 18px">'
                . '<li>' . $this->l('Créez un compte gratuit sur le portail API de l\'INSEE :')
                    . ' <a href="https://portail-api.insee.fr/" target="_blank" rel="noopener">portail-api.insee.fr</a>.</li>'
                . '<li>' . $this->l('Connectez-vous, puis créez une « Application » dans votre espace.') . '</li>'
                . '<li>' . $this->l('Souscrivez à l\'API « Sirene » (Sirene V3) depuis le catalogue des API.') . '</li>'
                . '<li>' . $this->l('Ouvrez votre application : une clé d\'intégration (API Key) y est générée.') . '</li>'
                . '<li>' . $this->l('Copiez cette clé et collez-la dans le champ « Clé API INSEE » ci-dessus.') . '</li>'
                . '<li>' . $this->l('Activez « Vérification live INSEE Sirene » puis enregistrez.') . '</li>'
                . '</ol>'
                . '<p style="margin-top:10px">'
                . $this->l('La vérification SIRET fonctionne sans clé (contrôle de la clé Luhn). La clé INSEE ajoute la vérification de l\'existence réelle de l\'établissement et le pré-remplissage automatique (raison sociale, APE, adresse).')
                . '</p>'
                . '</div>'
                . '</details>',
            ),
            $onOff('B2R_ENABLE_VIES', $this->l('Vérification TVA via VIES (UE)'), 't_valid', $this->l('Service public européen, gratuit, sans clé.')),
            $onOff('B2R_REQUIRE_SIRET', $this->l('SIRET obligatoire (pros FR)'), 't_valid'),
            $onOff('B2R_REQUIRE_VAT', $this->l('N° TVA obligatoire (pros UE)'), 't_valid'),
            $onOff('B2R_BLOCK_INVALID', $this->l('Refuser si identifiant invalide'), 't_valid', $this->l('Si une vérification aboutit et échoue, la demande est refusée. En cas de service indisponible, la demande passe en modération (jamais bloquée à tort).')),
            $onOff('B2R_AUTOFILL', $this->l('Pré-remplir les champs depuis SIRET / TVA'), 't_valid', $this->l('Raison sociale, APE et adresse récupérés automatiquement.')),

            // Onglet « Workflow »
            $onOff('B2R_MODERATION', $this->l('Activation manuelle (modération)'), 't_flow', $this->l('Si activé, chaque compte pro doit être validé en back-office avant l\'affectation au groupe.')),
            array('type' => 'text', 'tab' => 't_flow', 'label' => $this->l('E-mails à notifier'), 'name' => 'B2R_NOTIFY_EMAILS',
                'desc' => $this->l('Séparés par des virgules. Notifiés à chaque nouvelle demande pro.')),

            // Onglet « Affectation de groupe »
            $onOff('B2R_ASSIGN_DOMESTIC', $this->l('Affecter les pros du pays de la boutique'), 't_group'),
            array('type' => 'html', 'tab' => 't_group', 'label' => $this->l('Groupes (pays boutique)'), 'name' => 'b2r_grp_domestic',
                'html_content' => $this->renderGroupSelect(B2bGroupRule::SCOPE_DOMESTIC)),
            $onOff('B2R_ASSIGN_EU', $this->l('Affecter les pros de l\'UE'), 't_group'),
            array('type' => 'html', 'tab' => 't_group', 'label' => $this->l('Groupes (UE)'), 'name' => 'b2r_grp_eu',
                'html_content' => $this->renderGroupSelect(B2bGroupRule::SCOPE_EU)),
            $onOff('B2R_ASSIGN_WORLD', $this->l('Affecter les pros hors UE'), 't_group'),
            array('type' => 'html', 'tab' => 't_group', 'label' => $this->l('Groupes (reste du monde)'), 'name' => 'b2r_grp_world',
                'html_content' => $this->renderGroupSelect(B2bGroupRule::SCOPE_WORLD)),

            // Intégration B2B native PS — déplacée dans l'onglet Workflow pour
            // limiter le nombre d'onglets (réglage marginal, pas un onglet dédié).
            array('type' => 'html', 'tab' => 't_flow', 'name' => 'b2r_native_info', 'html_content' =>
                '<hr style="margin:20px 0 14px">'
                . '<h4 style="margin:0 0 8px;font-size:14px">' . $this->l('Mode B2B natif PrestaShop') . '</h4>'
                . '<div class="alert alert-info" style="margin-bottom:0">'
                . '<p><strong>' . $this->l('Sans cette option (recommandé par défaut)') . '</strong></p>'
                . '<ul style="margin:6px 0 12px 18px">'
                . '<li>' . $this->l('Le SIRET, la société et le n° de TVA sont déjà enregistrés sur la fiche client et sur ses adresses.') . '</li>'
                . '<li>' . $this->l('Les factures affichent donc correctement la société et la TVA intracommunautaire.') . '</li>'
                . '<li>' . $this->l('L\'affichage de la boutique (prix TTC, formulaires) reste inchangé pour vos clients particuliers.') . '</li>'
                . '</ul>'
                . '<p><strong>' . $this->l('En activant le mode B2B natif PrestaShop') . '</strong> ' . $this->l('(option globale PS_B2B_ENABLE) :') . '</p>'
                . '<ul style="margin:6px 0 0 18px">'
                . '<li>' . $this->l('Les champs société, SIRET, APE et délais/encours de paiement apparaissent sur la fiche client du back-office.') . '</li>'
                . '<li>' . $this->l('Le champ société peut être présenté à TOUS les clients, y compris les particuliers (B2C).') . '</li>'
                . '<li>' . $this->l('L\'affichage des prix peut basculer en HT et certains comportements de la boutique changent globalement.') . '</li>'
                . '<li>' . $this->l('À réserver aux boutiques majoritairement professionnelles qui veulent l\'interface B2B native de PrestaShop.') . '</li>'
                . '</ul>'
                . '</div>',
            ),
            $onOff('B2R_USE_NATIVE_B2B', $this->l('Activer le mode B2B natif PrestaShop'), 't_flow', $this->l('Modifie le comportement global de la boutique (voir ci-dessus). Laissez désactivé si vous n\'avez pas spécifiquement besoin de l\'interface B2B native.')),

            // Onglet « Mentions sur facture »
            array('type' => 'html', 'tab' => 't_invoice', 'name' => 'b2r_invoice_info', 'html_content' =>
                '<div class="alert alert-info">'
                . '<p>' . $this->l('Une commande facturée sans TVA doit indiquer le fondement de l\'exonération. PrestaShop ne propose qu\'un texte libre unique : ces champs ajoutent la mention correspondant au régime réellement appliqué à chaque commande.') . '</p>'
                . '<p>' . $this->l('La mention n\'est ajoutée que si la facture ne porte aucune TVA. Le cas est déduit de la commande — pays de taxation et TVA appliquée — et non de la fiche client actuelle : modifier un numéro de TVA ne réécrit pas une facture déjà émise.') . '</p>'
                . '<p style="margin-bottom:0"><strong>' . $this->l('Champs vides : rien n\'est ajouté.') . '</strong> ' . $this->l('Les formulations proposées sous chaque champ supposent une boutique française et doivent être validées par votre comptable avant usage.') . '</p>'
                . '</div>'
                . '<p class="help-block">' . $this->l('Le texte libre de facture de PrestaShop (Commandes → Factures) reste utilisé, la mention s\'y ajoute. Utilisez-le pour ce qui ne dépend pas du client : franchise en base, autoliquidation en sous-traitance.') . '</p>',
            ),
            $mention(B2bInvoiceMentions::KEY_EU_GOODS, $this->l('Livraison intracommunautaire de biens'),
                $this->l('Client dans un autre pays de l\'Union européenne.')),
            $mention(B2bInvoiceMentions::KEY_EU_SERVICE, $this->l('Prestation de services intracommunautaire'),
                $this->l('Utilisé à la place du précédent quand la commande ne contient que des produits dématérialisés.')),
            $mention(B2bInvoiceMentions::KEY_EXPORT, $this->l('Exportation hors Union européenne'),
                $this->l('Client hors UE.')),
            $mention(B2bInvoiceMentions::KEY_OUTSIDE_VAT, $this->l('Territoire hors champ de la TVA'),
                $this->l('Guyane et Mayotte.')),
        );

        $fields_form = array(
            'form' => array(
                'legend' => array('title' => $this->l('Configuration'), 'icon' => 'icon-cogs'),
                'tabs' => array(
                    't_form'   => $this->l('Formulaire'),
                    't_valid'  => $this->l('Vérification SIRET / TVA'),
                    't_flow'   => $this->l('Workflow'),
                    't_group'  => $this->l('Affectation de groupe'),
                    't_invoice' => $this->l('Mentions sur facture'),
                ),
                'input' => $inputs,
                'submit' => array('title' => $this->l('Enregistrer')),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitB2rConfig';
        // Requis par les champs multilingues de l'onglet « Mentions sur facture ».
        $helper->languages = $this->context->controller->getLanguages();
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->fields_value = $this->getConfigFieldsValues();

        return $helper->generateForm(array($fields_form));
    }

    private function getConfigFieldsValues()
    {
        $keys = array(
            'B2R_ONLY_PRO', 'B2R_FIELD_APE', 'B2R_FIELD_WEBSITE', 'B2R_FIELD_PHONE',
            'B2R_ALLOW_UPLOAD', 'B2R_INTRO_CMS', 'B2R_TERMS_CMS',
            'B2R_ENABLE_SIRET', 'B2R_ENABLE_INSEE', 'B2R_INSEE_KEY', 'B2R_ENABLE_VIES',
            'B2R_REQUIRE_SIRET', 'B2R_REQUIRE_VAT', 'B2R_BLOCK_INVALID', 'B2R_AUTOFILL',
            'B2R_MODERATION', 'B2R_NOTIFY_EMAILS', 'B2R_USE_NATIVE_B2B',
            'B2R_ASSIGN_DOMESTIC', 'B2R_ASSIGN_EU', 'B2R_ASSIGN_WORLD',
        );
        $values = array();
        foreach ($keys as $k) {
            $values[$k] = Configuration::get($k);
        }

        // Mentions de facture : une valeur par langue.
        foreach ($this->context->controller->getLanguages() as $lang) {
            $idLang = (int) $lang['id_lang'];
            foreach (B2bInvoiceMentions::keys() as $k) {
                $values[$k][$idLang] = Configuration::get($k, $idLang);
            }
        }

        return $values;
    }

    /**
     * Rend un <select multiple> des groupes clients, présélectionnant ceux déjà
     * liés au scope. Inséré comme champ « html » dans le HelperForm.
     */
    private function renderGroupSelect($scope)
    {
        $selected = B2bGroupRule::getGroupIdsForScope($scope);
        $groups = Group::getGroups($this->context->language->id);
        $html = '<select name="b2r_groups_' . htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') . '[]" multiple="multiple" class="b2r-group-select" size="5">';
        foreach ($groups as $g) {
            $id = (int) $g['id_group'];
            $sel = in_array($id, $selected, true) ? ' selected="selected"' : '';
            $html .= '<option value="' . $id . '"' . $sel . '>'
                . htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select>';
        $html .= '<p class="help-block">' . $this->l('Maintenez Ctrl/Cmd pour sélectionner plusieurs groupes.') . '</p>';

        return $html;
    }

    private function getCmsOptions()
    {
        $out = array(array('id' => 0, 'name' => $this->l('— Aucune —')));
        try {
            $pages = CMS::getCMSPages($this->context->language->id);
            if (is_array($pages)) {
                foreach ($pages as $p) {
                    $out[] = array('id' => (int) $p['id_cms'], 'name' => $p['meta_title']);
                }
            }
        } catch (Exception $e) {
            // ignore
        }

        return $out;
    }

}
