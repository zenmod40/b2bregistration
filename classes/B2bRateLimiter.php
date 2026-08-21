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
