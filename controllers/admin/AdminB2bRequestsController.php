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

require_once _PS_MODULE_DIR_ . 'b2bregistration/classes/B2bRequest.php';
require_once _PS_MODULE_DIR_ . 'b2bregistration/classes/B2bWorkflow.php';

/**
 * Back-office : liste de modération des demandes d'inscription B2B.
 */
class AdminB2bRequestsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'b2r_request';
        $this->className = 'B2bRequest';
        $this->identifier = 'id_b2r_request';
        $this->lang = false;
        $this->explicitSelect = true;
        $this->allow_export = true;

        parent::__construct();

        // company ET date_add existent à la fois dans b2b_request et customer (collision SQL).
        // On alias les colonnes ambiguës et on préfixe a! dans tous les filter_key pour
        // expliciter la table source.
        $this->_select = 'a.company AS b2r_company, a.date_add AS b2r_date_add, CONCAT(c.firstname, " ", c.lastname) AS customer_name, c.email AS customer_email';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (c.id_customer = a.id_customer)';

        $this->fields_list = array(
            'id_b2r_request' => array('title' => $this->l('ID'), 'align' => 'center', 'class' => 'fixed-width-xs'),
            'b2r_company'    => array('title' => $this->l('Société'), 'filter_key' => 'a!company'),
            'customer_name'  => array('title' => $this->l('Client'), 'havingFilter' => true),
            'siret'          => array('title' => $this->l('SIRET'), 'filter_key' => 'a!siret'),
            'vat_number'     => array('title' => $this->l('N° TVA'), 'filter_key' => 'a!vat_number'),
            'country_iso'    => array('title' => $this->l('Pays'), 'align' => 'center', 'filter_key' => 'a!country_iso'),
            'siret_valid'    => array('title' => $this->l('SIRET'), 'align' => 'center', 'callback' => 'renderCheck', 'search' => false, 'orderby' => false),
            'vat_valid'      => array('title' => $this->l('TVA'), 'align' => 'center', 'callback' => 'renderCheck', 'search' => false, 'orderby' => false),
            'status'         => array('title' => $this->l('Statut'), 'align' => 'center', 'callback' => 'renderStatus', 'type' => 'select',
                'list' => array('pending' => $this->l('En attente'), 'approved' => $this->l('Approuvé'), 'rejected' => $this->l('Refusé')),
                'filter_key' => 'a!status'),
            'b2r_date_add'   => array('title' => $this->l('Date'), 'type' => 'datetime', 'align' => 'center', 'filter_key' => 'a!date_add', 'orderby' => true),
        );

        $this->addRowAction('view');
        $this->addRowAction('approve');
        $this->addRowAction('reject');
        $this->addRowAction('delete');

        $this->bulk_actions = array(
            'delete' => array('text' => $this->l('Supprimer la sélection'), 'confirm' => $this->l('Supprimer les demandes sélectionnées ?'), 'icon' => 'icon-trash'),
        );
    }

    /**
     * Compatibilité traduction cross-version : PrestaShop 9 a retiré la méthode
     * legacy l() des contrôleurs admin. On délègue au natif sur 1.7/8, sinon on
     * passe par le traducteur Symfony (repli : chaîne source).
     */
    public function l($string, $class = null, $addslashes = false, $htmlentities = true)
    {
        if (method_exists(get_parent_class($this), 'l')) {
            return parent::l($string, $class, $addslashes, $htmlentities);
        }
        if (method_exists($this, 'trans')) {
            return $this->trans($string, array(), 'Modules.B2bregistration.Admin');
        }

        return $string;
    }

    public function renderCheck($value)
    {
        $value = (int) $value;
        if ($value === B2bRequest::CHECK_VALID) {
            return '<span class="badge badge-success" title="' . $this->l('Valide') . '"><i class="icon-check"></i></span>';
        }
        if ($value === B2bRequest::CHECK_INVALID) {
            return '<span class="badge badge-danger" title="' . $this->l('Invalide') . '"><i class="icon-remove"></i></span>';
        }

        return '<span class="badge" title="' . $this->l('Non vérifié') . '">—</span>';
    }

    public function renderStatus($value)
    {
        switch ($value) {
            case B2bRequest::STATUS_APPROVED:
                return '<span class="badge badge-success">' . $this->l('Approuvé') . '</span>';
            case B2bRequest::STATUS_REJECTED:
                return '<span class="badge badge-danger">' . $this->l('Refusé') . '</span>';
            default:
                return '<span class="badge badge-warning">' . $this->l('En attente') . '</span>';
        }
    }

    /**
     * Boutons d'action personnalisés (approuver / refuser).
     */
    public function displayApproveLink($token, $id)
    {
        return $this->renderActionLink('approve', 'icon-check', $this->l('Approuver'), $id);
    }

    public function displayRejectLink($token, $id)
    {
        return $this->renderActionLink('reject', 'icon-remove', $this->l('Refuser'), $id);
    }

    private function renderActionLink($action, $icon, $label, $id)
    {
        $href = self::$currentIndex
            . '&' . $action . $this->table . '=1'
            . '&' . $this->identifier . '=' . (int) $id
            . '&token=' . $this->token;

        return '<a class="btn btn-default" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="' . $icon . '"></i> ' . $label . '</a>';
    }

    public function postProcess()
    {
        $id = (int) Tools::getValue($this->identifier);

        if (Tools::isSubmit('approve' . $this->table) && $id) {
            $this->runWorkflow('approve', $id, $this->l('Demande approuvée, le client a été notifié.'));
        }

        if (Tools::isSubmit('reject' . $this->table) && $id) {
            $this->runWorkflow('reject', $id, $this->l('Demande refusée, le client a été notifié.'));
        }

        return parent::postProcess();
    }

    /**
     * Exécute un geste de B2bWorkflow et traduit ses codes en messages.
     */
    private function runWorkflow($action, $id, $confirmation)
    {
        $request = new B2bRequest($id);
        $idEmployee = (int) $this->context->employee->id;
        try {
            $result = ($action === 'reject')
                ? B2bWorkflow::reject($request, $this->l('Demande refusée par le marchand.'), $idEmployee, 'bo')
                : B2bWorkflow::approve($request, $idEmployee, 'bo');
        } catch (B2bWorkflowException $e) {
            $messages = array(
                'bad_status'       => $this->l('Cette demande est déjà dans cet état : aucune action effectuée, aucun e-mail envoyé.'),
                'customer_missing' => $this->l('Le compte client de cette demande n\'existe plus : impossible de l\'approuver.'),
                'reason_invalid'   => $this->l('Le motif contient des caractères non autorisés.'),
                'save_failed'      => $this->l('Impossible d\'enregistrer le nouvel état : aucun e-mail envoyé.'),
            );
            $this->errors[] = isset($messages[$e->getMessage()]) ? $messages[$e->getMessage()] : $e->getMessage();

            return;
        }

        $this->confirmations[] = $confirmation;
        $warnings = array(
            'no_group_assigned'    => $this->l('Aucun groupe professionnel n\'a été affecté (portée désactivée ou sans groupe) : le client n\'a pas les prix pro.'),
            'group_removal_failed' => $this->l('Les groupes professionnels n\'ont pas pu être retirés au client : vérifiez sa fiche.'),
            'mail_failed'          => $this->l('L\'état a été enregistré, mais l\'e-mail au client n\'a pas pu être envoyé.'),
        );
        foreach ($result['warnings'] as $code) {
            if (isset($warnings[$code])) {
                $this->warnings[] = $warnings[$code];
            }
        }
    }

    public function renderView()
    {
        $id = (int) Tools::getValue($this->identifier);
        $request = new B2bRequest($id);
        if (!Validate::isLoadedObject($request)) {
            return parent::renderView();
        }

        $this->context->smarty->assign(array(
            'b2r' => $request,
            'siret_detail' => json_decode((string) $request->siret_detail, true),
            'vat_detail'   => json_decode((string) $request->vat_detail, true),
        ));

        return $this->module->display(
            _PS_MODULE_DIR_ . 'b2bregistration/b2bregistration.php',
            'views/templates/admin/request_view.tpl'
        );
    }
}
