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
 * Envoi des e-mails du workflow B2B (fail-silent : un échec d'envoi ne casse
 * jamais l'inscription).
 */
class B2bMailer
{
    /**
     * Chemin des templates de mail du module.
     */
    private static function path()
    {
        return _PS_MODULE_DIR_ . 'b2bregistration/mails/';
    }

    private static function baseVars(Customer $customer)
    {
        return array(
            '{firstname}' => $customer->firstname,
            '{lastname}'  => $customer->lastname,
            '{email}'     => $customer->email,
            '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
        );
    }

    private static function send($idLang, $template, $subject, array $vars, $toEmail, $toName)
    {
        if (!Validate::isEmail($toEmail)) {
            return false;
        }

        try {
            return (bool) Mail::Send(
                (int) $idLang,
                $template,
                $subject,
                $vars,
                $toEmail,
                $toName,
                null,
                null,
                null,
                null,
                self::path()
            );
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Au client : compte pro reçu, en attente de validation par le marchand.
     */
    public static function sendPending(Customer $customer, $idLang)
    {
        $vars = self::baseVars($customer);

        return self::send(
            $idLang,
            'b2b_pending',
            'Votre demande de compte professionnel est en cours de validation',
            $vars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname
        );
    }

    /**
     * Au client : compte pro activé.
     */
    public static function sendApproved(Customer $customer, $idLang)
    {
        $vars = self::baseVars($customer);

        return self::send(
            $idLang,
            'b2b_approved',
            'Votre compte professionnel a été activé',
            $vars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname
        );
    }

    /**
     * Au client : demande de compte pro refusée (avec motif éventuel).
     */
    public static function sendRejected(Customer $customer, $idLang, $reason = '')
    {
        $vars = self::baseVars($customer);
        $vars['{reason}'] = (string) $reason;

        return self::send(
            $idLang,
            'b2b_rejected',
            'Votre demande de compte professionnel',
            $vars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname
        );
    }

    /**
     * Aux employés notifiés : nouvelle demande pro à examiner.
     *
     * @param string[] $recipients  e-mails des employés
     */
    public static function sendAdminNotice(Customer $customer, B2bRequest $request, $idLang, array $recipients)
    {
        $vars = self::baseVars($customer);
        $vars['{company}']    = $request->company;
        $vars['{siret}']      = $request->siret;
        $vars['{vat_number}'] = $request->vat_number;
        $vars['{bo_link}']    = Context::getContext()->link->getAdminLink('AdminB2bRequests');

        $ok = true;
        foreach ($recipients as $email) {
            $email = trim((string) $email);
            if ($email === '') {
                continue;
            }
            $ok = self::send(
                $idLang,
                'b2b_admin_new',
                'Nouvelle demande de compte professionnel',
                $vars,
                $email,
                $email
            ) && $ok;
        }

        return $ok;
    }
}
