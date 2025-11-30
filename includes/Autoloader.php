<?php
namespace AutoSyncPro;

class Autoloader {
    public static function register() {
        spl_autoload_register([__CLASS__, 'load']);
    }
    public static function load($class) {
        $prefix = __NAMESPACE__ . '\\';
        if (strpos($class, $prefix) !== 0) return;
        $relative = substr($class, strlen($prefix));
        $path = str_replace('\\', '/', $relative);
        $file = plugin_dir_path(__DIR__) . 'includes/' . $path . '.php';
        if (file_exists($file)) require_once $file;
    }
}
