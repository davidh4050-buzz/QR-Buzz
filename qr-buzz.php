<?php
/**
 * Plugin Name: QR Buzz
 * Description: QR code generation and tracking for WordPress.
 * Version: 0.1.0
 * Author: QR Buzz
 * PHP Version: 8.3
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('QR_BUZZ_VERSION', '0.1.0');
define('QR_BUZZ_PATH', plugin_dir_path(__FILE__));
define('QR_BUZZ_URL', plugin_dir_url(__FILE__));

// Simple PSR-4 style autoloader
spl_autoload_register(function ($class) {
    if (strpos($class, 'QRBuzz\\') !== 0) {
        return;
    }

    $classPath = str_replace('QRBuzz\\', '', $class);
    $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $classPath);

    $file = QR_BUZZ_PATH . 'src/' . $classPath . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Boot plugin
add_action('plugins_loaded', function () {
    $plugin = new QRBuzz\Plugin();
    $plugin->init();
});
