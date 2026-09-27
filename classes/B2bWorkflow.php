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

require_once dirname(__FILE__) . '/B2bRequest.php';
require_once dirname(__FILE__) . '/B2bGroupRule.php';
require_once dirname(__FILE__) . '/B2bValidator.php';
require_once dirname(__FILE__) . '/B2bCompat.php';
require_once dirname(__FILE__) . '/B2bMailer.php';

class B2bWorkflowException extends Exception
{
    // getMessage() : code stable (bad_status, customer_missing, reason_invalid, save_failed).
    // Avertissements (état enregistré) dans le tableau renvoyé : no_group_assigned,
    // group_removal_failed, mail_failed, service_unavailable, history_failed.
}

/**
 * Modération d'une demande B2B : valider, refuser, revérifier. Point d'entrée
 * unique du back-office et de Régie (regiebridge) : mêmes e-mails, mêmes
 * groupes, quel que soit l'appelant. Indépendant du contrôleur admin :
 * l'employé est passé en paramètre.
 */
class B2bWorkflow
{
    /** Incrémentée à chaque changement du contrat public. */
    const API_VERSION = 1;

    /**
     * pending → approved, ou rejected → approved.
     *
     * @return array ['status', 'groups' => int[] ajoutés, 'emails' => string[], 'warnings' => string[]]
     *
     * @throws B2bWorkflowException
     */
    public static function approve(B2bRequest $request, $idEmployee, $source = 'bo')
    {
        $from = self::expectStatus($request, array(B2bRequest::STATUS_PENDING, B2bRequest::STATUS_REJECTED));
        $customer = new Customer((int) $request->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            throw new B2bWorkflowException('customer_missing');
        }

        $logged = self::transition($request, $from, B2bRequest::STATUS_APPROVED, 'approve', $idEmployee, $source);
        $result = array('status' => B2bRequest::STATUS_APPROVED, 'groups' => array(), 'emails' => array(), 'warnings' => array());
        if (!$logged) {
            $result['warnings'][] = 'history_failed';
        }

        $added = self::assignGroups($customer, $request);
        if ($added === null) {
            $result['warnings'][] = 'no_group_assigned';
        } else {
            $result['groups'] = $added;
        }

        if (B2bMailer::sendApproved($customer, (int) $customer->id_lang)) {
            $result['emails'][] = 'b2b_approved';
        } else {
            $result['warnings'][] = 'mail_failed';
        }

        return $result;
    }

    /**
     * pending → rejected, ou approved → rejected. Une demande validée perd les
     * groupes pro ajoutés par le module ; son groupe par défaut redevient celui
     * d'avant la validation, ou le groupe « Client » de la boutique. Le client
     * n'est pas supprimé.
     *
     * @return array ['status', 'groups_removed' => int[], 'emails' => string[], 'warnings' => string[]]
     *
     * @throws B2bWorkflowException
     */
    public static function reject(B2bRequest $request, $reason, $idEmployee, $source = 'bo')
    {
        $reason = trim((string) $reason);
        if ($reason !== '' && !Validate::isCleanHtml($reason)) {
            throw new B2bWorkflowException('reason_invalid');
        }
        $from = self::expectStatus($request, array(B2bRequest::STATUS_PENDING, B2bRequest::STATUS_APPROVED));

        $logged = self::transition($request, $from, B2bRequest::STATUS_REJECTED, 'reject', $idEmployee, $source, $reason);
        $result = array('status' => B2bRequest::STATUS_REJECTED, 'groups_removed' => array(), 'emails' => array(), 'warnings' => array());
        if (!$logged) {
            $result['warnings'][] = 'history_failed';
        }

        $customer = new Customer((int) $request->id_customer);
        if ($from === B2bRequest::STATUS_APPROVED && Validate::isLoadedObject($customer)) {
            $removed = self::removeGroups($customer, $request);
            if ($removed === null) {
                $result['warnings'][] = 'group_removal_failed';
            } else {
                $result['groups_removed'] = $removed;
            }
        }

        if (Validate::isLoadedObject($customer) && B2bMailer::sendRejected($customer, (int) $customer->id_lang, $reason)) {
            $result['emails'][] = 'b2b_rejected';
        } else {
            $result['warnings'][] = 'mail_failed';
        }

        return $result;
    }

    /**
     * Relance les vérifications SIRET / TVA activées en configuration et
     * enregistre leur résultat. Ne change pas le statut, n'envoie aucun e-mail.
     * Un service injoignable laisse le résultat précédent en place.
     *
     * @return array ['siret_valid', 'vat_valid', 'siret_detail' => array, 'vat_detail' => array, 'warnings' => string[]]
     *
     * @throws B2bWorkflowException
     */
    public static function recheck(B2bRequest $request, $idEmployee, $source = 'bo')
    {
        if (!Validate::isLoadedObject($request)) {
            throw new B2bWorkflowException('bad_status');
        }

        $warnings = array();
        $fields = array();
        $changes = array();
        $checks = array(
            'siret' => self::checkSiret((string) $request->siret, (string) $request->country_iso),
            'vat'   => self::checkVat((string) $request->vat_number, (string) $request->siret),
        );
        foreach ($checks as $name => $check) {
            if ($check === null) {
                continue; // vérification désactivée ou pays non éligible
            }
            if (empty($check['detail']['reachable'])) {
                $warnings[] = 'service_unavailable';
                continue;
            }
            $before = (int) $request->{$name . '_valid'};
            $request->{$name . '_valid'} = (int) $check['valid'];
            $request->{$name . '_detail'} = json_encode($check['detail']);
            $fields[$name . '_valid'] = (int) $check['valid'];
            $fields[$name . '_detail'] = pSQL($request->{$name . '_detail'});
            $changes[] = $name . ' ' . $before . '→' . (int) $check['valid'];
        }
        $warnings = array_values(array_unique($warnings));

        if ($fields) {
            $fields['date_upd'] = date('Y-m-d H:i:s');
            try {
                $saved = Db::getInstance()->update('b2r_request', $fields, '`id_b2r_request` = ' . (int) $request->id);
            } catch (Exception $e) {
                $saved = false;
            }
            if (!$saved) {
                throw new B2bWorkflowException('save_failed');
            }
            $request->clearCache();
        }

        $comment = $changes ? implode(', ', $changes) : 'aucune vérification effectuée';
        if ($warnings) {
            $comment .= ' (service injoignable)';
        }
        if (!self::logHistory($request->id, 'recheck', $request->status, $request->status, $idEmployee, $source, $comment)) {
            $warnings[] = 'history_failed';
        }

        return array(
            'siret_valid'  => (int) $request->siret_valid,
            'vat_valid'    => (int) $request->vat_valid,
            'siret_detail' => self::decode($request->siret_detail),
            'vat_detail'   => self::decode($request->vat_detail),
            'warnings'     => $warnings,
        );
    }

    /**
     * Chemin absolu de la pièce jointe (Kbis), null si absente.
     */
    public static function attachmentPath(B2bRequest $request)
    {
        $name = basename((string) $request->attachment);
        $path = _PS_MODULE_DIR_ . 'b2bregistration/uploads/' . $name;

        return ($name !== '' && is_file($path)) ? $path : null;
    }

    /**
     * Historique de la demande, du plus ancien au plus récent ; [] si la table est absente.
     *
     * @return array[] action, status_from, status_to, id_employee, employee, source, comment, date_add
     */
    public static function history(B2bRequest $request)
    {
        try {
            $rows = Db::getInstance()->executeS('
                SELECT h.`action`, h.`status_from`, h.`status_to`, h.`id_employee`,
                       TRIM(CONCAT(IFNULL(e.`firstname`, \'\'), \' \', IFNULL(e.`lastname`, \'\'))) AS `employee`,
                       h.`source`, h.`comment`, h.`date_add`
                FROM `' . _DB_PREFIX_ . 'b2r_request_history` h
                LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON (e.`id_employee` = h.`id_employee`)
                WHERE h.`id_b2r_request` = ' . (int) $request->id . '
                ORDER BY h.`date_add` ASC, h.`id_b2r_request_history` ASC');
        } catch (Exception $e) {
            $rows = false; // table absente tant que l'upgrade 1.1.0 n'a pas tourné
        }

        // Types stables pour l'appelant : PS 8 rend des chaînes, PS 9 des entiers.
        return array_map(static function ($row) {
            $row['id_employee'] = (int) $row['id_employee'];

            return $row;
        }, is_array($rows) ? $rows : array());
    }

    /**
     * Ajoute une ligne d'historique. Ne lève jamais : un historique en échec ne
     * doit bloquer ni l'inscription du client ni l'e-mail d'un geste.
     *
     * @return bool
     */
    public static function logHistory($idRequest, $action, $from, $to, $idEmployee, $source, $comment = null)
    {
        $row = array(
            'id_b2r_request' => (int) $idRequest,
            'action'         => pSQL(Tools::substr((string) $action, 0, 16)),
            'status_from'    => pSQL((string) $from),
            'status_to'      => pSQL((string) $to),
            'id_employee'    => (int) $idEmployee,
            'source'         => pSQL(Tools::substr((string) $source, 0, 16)),
            'date_add'       => date('Y-m-d H:i:s'),
        );
        if ($comment !== null && $comment !== '') {
            $row['comment'] = pSQL((string) $comment, true);
        }

        try {
            // $null_values à false : sinon Db::insert() change '' (status_from d'une création) en NULL.
            return (bool) Db::getInstance()->insert('b2r_request_history', $row);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Groupes pro prévus pour une portée pays (domestic / eu / world) : ceux des
     * règles actives, sinon B2R_DEFAULT_GROUP ; [] si la portée est désactivée.
     *
     * @return int[]
     */
    public static function groupsForScope($scope)
    {
        $enabledMap = array(
            B2bGroupRule::SCOPE_DOMESTIC => 'B2R_ASSIGN_DOMESTIC',
            B2bGroupRule::SCOPE_EU       => 'B2R_ASSIGN_EU',
            B2bGroupRule::SCOPE_WORLD    => 'B2R_ASSIGN_WORLD',
        );
        if (!isset($enabledMap[$scope]) || !(int) Configuration::get($enabledMap[$scope])) {
            return array();
        }

        $groupIds = B2bGroupRule::getGroupIdsForScope($scope);
        if (empty($groupIds)) {
            $fallback = (int) Configuration::get('B2R_DEFAULT_GROUP');
            if ($fallback) {
                $groupIds = array($fallback);
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $groupIds))));
    }

    /**
     * Affecte les groupes pro de la portée du client et en fait son groupe par
     * défaut. Retient les groupes réellement ajoutés et l'ancien groupe par
     * défaut, pour pouvoir les retirer si la demande est refusée plus tard.
     *
     * @return int[]|null groupes ajoutés ; null si aucun groupe n'a pu être affecté
     */
    public static function assignGroups(Customer $customer, B2bRequest $request)
    {
        $groupIds = self::groupsForScope($request->country_scope);
        if (empty($groupIds)) {
            return null;
        }

        $current = self::customerGroups($customer);
        $added = array_values(array_diff($groupIds, $current));
        $defaultBefore = (int) $customer->id_default_group;

        try {
            if ($added) {
                $customer->addGroups($added);
            }
            $customer->id_default_group = (int) $groupIds[0];
            $customer->update();
            Customer::resetAddressCache(); // vide aussi le cache des groupes du processus
        } catch (Exception $e) {
            return null;
        }

        $request->id_group_assigned = (int) $groupIds[0];
        $where = '`id_b2r_request` = ' . (int) $request->id;
        try {
            $saved = Db::getInstance()->update('b2r_request', array(
                'id_group_assigned'       => (int) $groupIds[0],
                'groups_added'            => pSQL(json_encode($added)),
                'id_default_group_before' => $defaultBefore,
            ), $where);
        } catch (Exception $e) {
            $saved = false;
        }
        if (!$saved) {
            // Colonnes absentes avant l'upgrade 1.1.0 : l'affectation reste
            // faite ; un refus ultérieur retombera sur les groupes de la portée.
            try {
                Db::getInstance()->update('b2r_request', array('id_group_assigned' => (int) $groupIds[0]), $where);
            } catch (Exception $e) {
                // non bloquant
            }
        }
        $request->clearCache();

        return $added;
    }

    /**
     * Retire au client les groupes ajoutés par sa validation.
     *
     * @return int[]|null groupes retirés ; null en cas d'échec
     */
    protected static function removeGroups(Customer $customer, B2bRequest $request)
    {
        $row = self::extraColumns($request);
        $added = isset($row['groups_added']) ? json_decode((string) $row['groups_added'], true) : null;
        if (!is_array($added)) {
            // Validation antérieure à la 1.1.0 : les groupes ajoutés n'ont pas
            // été retenus, on retire ceux que le module affecte à cette portée.
            $added = array_merge(array((int) $request->id_group_assigned), self::groupsForScope($request->country_scope));
        }
        $current = self::customerGroups($customer);
        $remove = array_values(array_intersect($current, array_filter(array_map('intval', $added))));
        $keep = array_values(array_diff($current, $remove));

        $default = (int) $customer->id_default_group;
        if (in_array($default, $remove, true)) {
            $before = isset($row['id_default_group_before']) ? (int) $row['id_default_group_before'] : 0;
            $default = ($before && !in_array($before, $remove, true) && Validate::isLoadedObject(new Group($before)))
                ? $before
                : (int) Configuration::get('PS_CUSTOMER_GROUP');
        }
        if (!in_array($default, $keep, true)) {
            $keep[] = $default;
        }

        try {
            $customer->updateGroup($keep);
            $customer->id_default_group = $default;
            $customer->update();
            Customer::resetAddressCache(); // vide aussi le cache des groupes du processus
            Db::getInstance()->update('b2r_request', array('id_group_assigned' => 0), '`id_b2r_request` = ' . (int) $request->id);
            $request->id_group_assigned = 0;
            $request->clearCache();
        } catch (Exception $e) {
            return null;
        }

        return $remove;
    }

    /**
     * Vérification SIRET selon la configuration (format Luhn, puis INSEE si activé).
     *
     * @return array|null ['valid' => -1|0|1, 'detail' => array] ; null si non applicable
     */
    public static function checkSiret($siret, $iso)
    {
        if ($siret === '' || !(int) Configuration::get('B2R_ENABLE_SIRET') || !B2bCompat::isInseeCountry($iso)) {
            return null;
        }
        if (!B2bValidator::isValidSiret($siret)) {
            return array('valid' => B2bRequest::CHECK_INVALID, 'detail' => array('reachable' => true, 'reason' => 'format'));
        }
        if (!(int) Configuration::get('B2R_ENABLE_INSEE')) {
            // Format Luhn correct, pas de vérification en ligne.
            return array('valid' => B2bRequest::CHECK_VALID, 'detail' => array('reachable' => true, 'reason' => 'luhn'));
        }

        $res = B2bValidator::checkInsee($siret, Configuration::get('B2R_INSEE_KEY'));
        $valid = $res['reachable']
            ? ($res['valid'] ? B2bRequest::CHECK_VALID : B2bRequest::CHECK_INVALID)
            : B2bRequest::CHECK_UNKNOWN;

        return array('valid' => $valid, 'detail' => $res);
    }

    /**
     * Vérification TVA intracommunautaire via VIES si activée. Le détail porte
     * `auto_computed` quand le numéro est celui calculé depuis le SIREN.
     *
     * @return array|null ['valid' => -1|0|1, 'detail' => array] ; null si non applicable
     */
    public static function checkVat($vat, $siret)
    {
        if ($vat === '' || !(int) Configuration::get('B2R_ENABLE_VIES') || !B2bCompat::isViesCountry(Tools::substr($vat, 0, 2))) {
            return null;
        }

        $res = B2bValidator::checkVies($vat);
        $valid = $res['reachable']
            ? ($res['valid'] ? B2bRequest::CHECK_VALID : B2bRequest::CHECK_INVALID)
            : B2bRequest::CHECK_UNKNOWN;
        if (strlen($siret) === 14 && $vat === B2bValidator::frenchVatFromSiren(substr($siret, 0, 9))) {
            $res['auto_computed'] = true;
        }

        return array('valid' => $valid, 'detail' => $res);
    }

    /**
     * Statut courant s'il fait partie des états de départ permis.
     *
     * @throws B2bWorkflowException
     */
    protected static function expectStatus(B2bRequest $request, array $allowed)
    {
        if (!Validate::isLoadedObject($request) || !in_array($request->status, $allowed, true)) {
            throw new B2bWorkflowException('bad_status');
        }

        return $request->status;
    }

    /**
     * Change l'état si, et seulement si, la demande est encore dans l'état lu :
     * la condition sur `status` rend le geste sûr face à un double clic ou à
     * deux postes simultanés (BO + Régie).
     *
     * @return bool ligne d'historique écrite
     *
     * @throws B2bWorkflowException
     */
    protected static function transition(B2bRequest $request, $from, $to, $action, $idEmployee, $source, $reason = null)
    {
        $now = date('Y-m-d H:i:s');
        $fields = array('status' => pSQL($to), 'date_upd' => $now);
        if ($reason !== null && $reason !== '') {
            $fields['note'] = pSQL($reason, true);
        }

        $db = Db::getInstance();
        try {
            // PS 8 lève sur une erreur SQL, PS 9 rend false : même issue pour l'appelant.
            $saved = $db->update('b2r_request', $fields, '`id_b2r_request` = ' . (int) $request->id . ' AND `status` = \'' . pSQL($from) . '\'');
        } catch (Exception $e) {
            $saved = false;
        }
        if (!$saved) {
            throw new B2bWorkflowException('save_failed');
        }
        if ((int) $db->Affected_Rows() !== 1) {
            // Un autre poste est passé entre la lecture et l'écriture.
            throw new B2bWorkflowException('bad_status');
        }

        // Écriture hors ObjectModel::update() : vider le cache objet, sinon un
        // new B2bRequest() dans le même processus relit l'ancien état.
        $request->clearCache();
        $request->status = $to;
        $request->date_upd = $now;
        if (isset($fields['note'])) {
            $request->note = $reason;
        }

        return self::logHistory($request->id, $action, $from, $to, $idEmployee, $source, $reason);
    }

    /**
     * Colonnes ajoutées en 1.1.0, lues hors ObjectModel pour que le module
     * reste fonctionnel tant que l'upgrade n'a pas tourné.
     */
    protected static function extraColumns(B2bRequest $request)
    {
        try {
            $row = Db::getInstance()->getRow('SELECT `groups_added`, `id_default_group_before` FROM `' . _DB_PREFIX_ . 'b2r_request` WHERE `id_b2r_request` = ' . (int) $request->id);
        } catch (Exception $e) {
            $row = false;
        }

        return is_array($row) ? $row : array();
    }

    /**
     * Groupes du client lus en base : Customer::getGroups() garde un cache par
     * processus, périmé si un autre geste vient de les modifier.
     *
     * @return int[]
     */
    protected static function customerGroups(Customer $customer)
    {
        $rows = Db::getInstance()->executeS('SELECT `id_group` FROM `' . _DB_PREFIX_ . 'customer_group` WHERE `id_customer` = ' . (int) $customer->id);

        return array_map('intval', array_column(is_array($rows) ? $rows : array(), 'id_group'));
    }

    protected static function decode($json)
    {
        $data = json_decode((string) $json, true);

        return is_array($data) ? $data : array();
    }
}
