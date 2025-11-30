<?php
/**
 * Plugin Name: Auto Sync Pro
 * Description: Fetch posts from configurable REST endpoints, clean content, optional AI enhancements, configurable scheduling and image options. Full working plugin.
 * Version: 3.0.0
 * Author: Dirajumla 
 * Text Domain: auto-sync-pro
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/Autoloader.php';

use AutoSyncPro\Autoloader;
use AutoSyncPro\Plugin;

Autoloader::register();

register_activation_hook(__FILE__, ['AutoSyncPro\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['AutoSyncPro\Plugin', 'deactivate']);

Plugin::init();