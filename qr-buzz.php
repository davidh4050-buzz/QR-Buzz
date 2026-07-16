<?php
/**
 * Plugin Name: QR Buzz
 * Description: QR code management, branded design, campaigns, smart destinations, dynamic QR tracking, static QR types, and privacy-conscious scan analytics for WordPress (v0.9.0)
 * Version: 0.9.0
 * Requires PHP: 8.3
 * Requires at least: 7.0
 * Author: QR Buzz
 */

if (!defined('ABSPATH')) exit;

define('QR_BUZZ_VERSION', '0.9.0');
define('QR_BUZZ_PATH', plugin_dir_path(__FILE__));
define('QR_BUZZ_URL', plugin_dir_url(__FILE__));

$qrBuzzAutoload = QR_BUZZ_PATH . 'vendor/autoload.php';
if (file_exists($qrBuzzAutoload)) {
    require_once $qrBuzzAutoload;
}

spl_autoload_register(function($class){
    if (strpos($class, 'QRBuzz\\') !== 0) return;
    $class = str_replace('QRBuzz\\', '', $class);
    $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
    $file = QR_BUZZ_PATH . 'src/' . $class . '.php';
    if (file_exists($file)) require_once $file;
});

register_activation_hook(__FILE__, function(){
    QRBuzz\Database\Installer::activate();
    (new QRBuzz\Redirect\RedirectHandler())->addRewriteRule();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function(){
    flush_rewrite_rules();
});

add_action('plugins_loaded', function(){
    (new QRBuzz\Plugin())->init();
});
