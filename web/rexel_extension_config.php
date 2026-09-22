<?php
/**
 * Interruptor aislado del prototipo de scraping mediante extension.
 * Cambiar a false oculta el acceso del panel y hace que el API responda 503.
 */
if (!defined('REXEL_EXTENSION_EXPERIMENT_ENABLED')) {
    define('REXEL_EXTENSION_EXPERIMENT_ENABLED', true);
}

