<?php
namespace AutoSyncPro;

if (!defined('ABSPATH')) exit;

class Plugin {
    const OPTION_KEY = 'auto_sync_pro_options_v2';
    const CRON_HOOK = 'asp_full_cron_hook';

    public static function init() {
        add_action('init', [__CLASS__, 'init_hooks']);
    }

    public static function init_hooks() {
        // Load admin
        if (is_admin()) {
            Admin\Settings::init();
            add_action('admin_enqueue_scripts', [Admin\Settings::class, 'enqueue_assets']);
        }

        // Setup a custom cron schedule filter to ensure interval exists
        add_filter('cron_schedules', [__CLASS__, 'add_cron_schedule']);
        add_action(self::CRON_HOOK, [Fetcher::class, 'handle']);

        // AJAX for manual run
        add_action('wp_ajax_aspom_manual_run_ajax', [__CLASS__, 'ajax_manual_run']);
    }

    public static function activate() {
        $defaults = self::defaults();
        if (get_option(self::OPTION_KEY) === false) {
            add_option(self::OPTION_KEY, $defaults);
        }
        // ensure schedule exists
        self::reschedule();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function defaults() {
        return [
            'sources' => [],
            'source_categories' => [],
            'interval_seconds' => 300,
            'remove_from_title' => [],
            'remove_from_description' => [],
            'replacement_pairs' => [],
            'strip_all_links' => false,
            'fetch_featured_image' => true,
            'use_custom_featured' => false,
            'custom_featured_url' => '',
            'ai_enabled' => false,
            'ai_provider' => '',
            'ai_api_key' => '',
            'ai_model' => '',
            'ai_instruction_title' => '',
            'ai_instruction_description' => '',
            'debug' => false,
        ];
    }

    public static function add_cron_schedule($schedules) {
        $opts = get_option(self::OPTION_KEY, self::defaults());
        $secs = intval($opts['interval_seconds'] ?: 300);
        if ($secs < 5) $secs = 5;
        $schedules['aspom_custom_interval'] = [
            'interval' => $secs,
            'display' => sprintf('Auto Sync Pro every %d seconds', $secs),
        ];
        return $schedules;
    }

    public static function reschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event(time(), 'aspom_custom_interval', self::CRON_HOOK);
    }

    public static function ajax_manual_run() {
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);
        check_ajax_referer('aspom_manual_run_ajax', 'nonce', true);
        // run the fetcher synchronously (safe on admin)
        Fetcher::handle(true);
        wp_send_json_success('Manual run completed');
    }
}
