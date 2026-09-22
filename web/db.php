<?php
/**
 * db.php - Centralized SQLite Database Connection & Configuration Helper
 * 
 * Ensures WAL mode, 30s busy timeout, foreign keys, and persistent error handling.
 */

require_once __DIR__ . '/error_classifier.php';

// Sincronizar zona horaria con Orlando, Florida (Eastern Time: EST/EDT)
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'America/New_York');
}
date_default_timezone_set(APP_TIMEZONE);

if (!function_exists('get_db_connection')) {
    /**
     * Devuelve una instancia PDO compartida (Singleton por petición).
     * @return PDO
     * @throws Exception
     */
    function get_db_connection(): PDO
    {
        static $pdo = null;
        if ($pdo !== null) {
            return $pdo;
        }

        $db_path = realpath(__DIR__ . '/../database/diccionario.db');
        if (!$db_path || !file_exists($db_path)) {
            throw new Exception("No se encontró la base de datos en: " . __DIR__ . '/../database/diccionario.db');
        }

        $pdo = new PDO('sqlite:' . $db_path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 30,
        ]);

        // Pragmas críticos para evitar bloqueos por concurrencia y maximizar rendimiento
        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA synchronous = NORMAL;");
        $pdo->exec("PRAGMA busy_timeout = 30000;");
        $pdo->exec("PRAGMA foreign_keys = ON;");
        $pdo->exec("PRAGMA cache_size = -64000;"); // 64 MB de caché en RAM
        $pdo->exec("PRAGMA temp_store = MEMORY;"); // Tablas temporales en RAM
        $pdo->exec("PRAGMA mmap_size = 268435456;"); // Mmap I/O hasta 256 MB

        return $pdo;
    }
}

if (!function_exists('get_config_value')) {
    /**
     * Obtiene un valor de app_config por su clave.
     * @param string $key Clave en app_config.
     * @param mixed $default Valor por defecto si no existe.
     * @return mixed
     */
    function get_config_value(string $key, $default = false)
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("SELECT value FROM app_config WHERE key = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return ($val !== false) ? $val : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
}

if (!function_exists('set_config_value')) {
    /**
     * Guarda o actualiza un valor en app_config.
     * @param string $key Clave en app_config.
     * @param string $value Valor a guardar.
     * @return bool
     */
    function set_config_value(string $key, string $value): bool
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO app_config (key, value) VALUES (?, ?)");
            return $stmt->execute([$key, $value]);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('get_all_categories_from_db')) {
    /**
     * Obtiene todas las categorías configuradas ordenadas alfabéticamente.
     * @return array
     */
    function get_all_categories_from_db(): array
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->query("SELECT DISTINCT category FROM scraping_urls WHERE category IS NOT NULL AND category != '' ORDER BY category");
            $cats = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return !empty($cats) ? $cats : ['EMT', 'PVC', 'Wires'];
        } catch (Exception $e) {
            return ['EMT', 'PVC', 'Wires'];
        }
    }
}
