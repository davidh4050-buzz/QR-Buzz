<?php
/**
 * Plugin Name: QR Buzz
 * Description: QR code generation for WordPress (v0.1.0)
 * Version: 0.1.0
 * Author: QR Buzz
 */

if (!defined('ABSPATH')) exit;

define('QR_BUZZ_VERSION', '0.1.0');
define('QR_BUZZ_PATH', plugin_dir_path(__FILE__));
define('QR_BUZZ_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function($class){
    if (strpos($class, 'QRBuzz\\') !== 0) return;
    $class = str_replace('QRBuzz\\', '', $class);
    $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
    $file = QR_BUZZ_PATH . 'src/' . $class . '.php';
    if (file_exists($file)) require_once $file;
});

add_action('plugins_loaded', function(){
    (new QRBuzz\Plugin())->init();
});
