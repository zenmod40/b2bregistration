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
 * Limiteur de débit anti-abus (fenêtre fixe, stockage fichier).
 *
 * Protège la clé API INSEE / le service VIES contre le martèlement par des bots.
 * Fail-soft : toute erreur de stockage → la requête est autorisée (jamais de
 * blocage d'un client légitime à cause d'un souci disque).
 */
class B2bRateLimiter
{
    /**
     * @param string $key    identifiant du bucket (ex. IP du visiteur)
     * @param int    $limit  nombre max de requêtes par fenêtre
     * @param int    $window durée de la fenêtre en secondes
     * @return bool  true = autorisé, false = quota dépassé
     */
    public static function allow($key, $limit = 20, $window = 60)
    {
        $dir = self::dir();
        if ($dir === '') {
            return true; // pas de stockage disponible → fail-soft
        }

        $file = $dir . sha1((string) $key) . '.json';
        $now = time();
        $count = 0;
        $start = $now;

        $raw = @file_get_contents($file);
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['start'], $data['count'])) {
                if (($now - (int) $data['start']) < (int) $window) {
                    $start = (int) $data['start'];
                    $count = (int) $data['count'];
                }
            }
        }

        if ($count >= (int) $limit) {
            return false;
        }

        $payload = json_encode(array('start' => $start, 'count' => $count + 1));
        @file_put_contents($file, $payload, LOCK_EX);

        // Purge opportuniste des buckets périmés (1 chance sur 50).
        if (function_exists('random_int')) {
            try {
                if (random_int(1, 50) === 1) {
                    self::gc($dir, $window);
                }
            } catch (Exception $e) {
                // ignore
            }
        }

        return true;
    }

    /**
     * Répertoire de stockage des buckets (créé au besoin, hors racine web).
     */
    private static function dir()
    {
        $base = defined('_PS_CACHE_DIR_') ? _PS_CACHE_DIR_ : sys_get_temp_dir();
        $dir = rtrim($base, '/\\') . '/b2r_ratelimit/';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return '';
            }
            @file_put_contents($dir . 'index.php', "<?php header('Location: ../');\n");
        }

        return $dir;
    }

    /**
     * Supprime les fichiers de bucket plus vieux que la fenêtre.
     */
    private static function gc($dir, $window)
    {
        $files = @glob($dir . '*.json');
        if (!is_array($files)) {
            return;
        }
        $now = time();
        foreach ($files as $f) {
            if (($now - (int) @filemtime($f)) > ((int) $window + 5)) {
                @unlink($f);
            }
        }
    }
}
