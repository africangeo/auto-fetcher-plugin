<?php
namespace AutoSyncPro\Admin;

use AutoSyncPro\Plugin;
use AutoSyncPro\Fetcher;

if (!defined('ABSPATH')) exit;

class Settings {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_aspom_save_settings', [__CLASS__, 'save_settings']);
        add_action('wp_ajax_aspom_manual_run_ajax', [__CLASS__, 'ajax_manual_run']);
        add_action('admin_notices', [__CLASS__, 'show_cron_status']);
    }

    public static function enqueue_assets($hook) {
        // only on our pages
        if (strpos($hook, 'aspom-settings') === false && strpos($hook, 'toplevel_page_aspom-settings') === false && strpos($hook, 'aspom-sources') === false) {
            // still enqueue for plugin page
        }
        wp_enqueue_style('aspom-admin', plugin_dir_url(__DIR__) . '../assets/css/admin.css', [], '1.0');
        wp_enqueue_script('aspom-admin', plugin_dir_url(__DIR__) . '../assets/js/admin.js', ['jquery'], '1.0', true);
        wp_localize_script('aspom-admin', 'aspom_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aspom_manual_run_ajax')
        ]);
    }

    public static function menu() {
        add_menu_page('Auto Sync Pro', 'Auto Sync Pro', 'manage_options', 'aspom-settings', [__CLASS__, 'page'], 'dashicons-update', 76);
        add_submenu_page('aspom-settings', 'Sources', 'Sources', 'manage_options', 'aspom-sources', [__CLASS__, 'page_sources']);
        add_submenu_page('aspom-settings', 'Filters', 'Filters', 'manage_options', 'aspom-filters', [__CLASS__, 'page_filters']);
        add_submenu_page('aspom-settings', 'AI', 'AI', 'manage_options', 'aspom-ai', [__CLASS__, 'page_ai']);
    }

    public static function show_cron_status() {
        if (!current_user_can('manage_options')) return;
        $screen = get_current_screen();
        if (!$screen || (strpos($screen->id, 'aspom') === false && strpos($screen->id, 'toplevel_page_aspom') === false)) return;

        $next_run = wp_next_scheduled(Plugin::CRON_HOOK);
        $last_run = get_option('aspom_last_run_time', 0);

        if ($next_run) {
            $time_diff = $next_run - time();
            $next_run_display = $time_diff > 0 ? 'in ' . human_time_diff(time(), $next_run) : 'overdue';
        } else {
            $next_run_display = 'Not scheduled';
        }

        $last_run_display = $last_run ? human_time_diff($last_run) . ' ago' : 'Never';
        ?>
        <div class="notice notice-info" style="padding:12px;">
            <p><strong>Auto Sync Pro Status:</strong> Next run: <code><?php echo esc_html($next_run_display); ?></code> | Last run: <code><?php echo esc_html($last_run_display); ?></code></p>
        </div>
        <?php
    }

    public static function page() {
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());
        ?>
        <div class="wrap">
            <h1>Auto Sync Pro</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="aspom_save_settings" />
                <?php wp_nonce_field('aspom_save_settings_nonce'); ?>

                <h2>Scheduler</h2>
                <table class="form-table">
                    <tr valign="top"><th>Interval (seconds)</th>
                        <td><input type="number" name="interval_seconds" value="<?php echo esc_attr($opts['interval_seconds']); ?>" min="5" /></td>
                    </tr>
                </table>

                <h2>General</h2>
                <table class="form-table">
                    <tr valign="top"><th>Fetch featured image</th>
                        <td><input type="checkbox" name="fetch_featured_image" <?php checked($opts['fetch_featured_image']); ?> /></td>
                    </tr>
                    <tr valign="top"><th>Use custom featured image URL</th>
                        <td>
                            <input type="checkbox" name="use_custom_featured" <?php checked($opts['use_custom_featured']); ?> />
                            <br/>
                            <input type="url" name="custom_featured_url" value="<?php echo esc_attr($opts['custom_featured_url']); ?>" style="width:50%;" />
                        </td>
                    </tr>
                    <tr valign="top"><th>Strip all links from description</th>
                        <td><input type="checkbox" name="strip_all_links" <?php checked($opts['strip_all_links']); ?> /></td>
                    </tr>
                    <tr valign="top"><th>Debug logging</th>
                        <td><input type="checkbox" name="debug" <?php checked($opts['debug']); ?> /> <em>Enable error_log output for debugging</em></td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <hr/>
            <h2>Quick Actions</h2>
            <form method="post">
                <?php wp_nonce_field('aspom_manual_run_action'); ?>
                <button id="aspom-ajax-run" class="button button-primary">Run Now (AJAX)</button>
                <input type="button" id="aspom-php-run" class="button" value="Run Now (Server)" />
            </form>
            <p id="aspom-php-result"></p>

        </div>
        <?php
    }

    public static function page_sources() {
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());
        $categories = get_categories(['hide_empty' => false]);
        ?>
        <div class="wrap">
            <h1>Sources</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="aspom_save_settings" />
                <?php wp_nonce_field('aspom_save_settings_nonce'); ?>
                <p>Configure REST API sources and assign categories to each source.</p>
                <table class="form-table">
                    <thead>
                        <tr>
                            <th style="width:60%;">REST API URL</th>
                            <th style="width:40%;">Category</th>
                        </tr>
                    </thead>
                    <tbody id="aspom-sources-list">
                        <?php
                        if (!empty($opts['sources'])) {
                            foreach ($opts['sources'] as $idx => $source) {
                                $cat_id = isset($opts['source_categories'][$idx]) ? intval($opts['source_categories'][$idx]) : 0;
                                ?>
                                <tr>
                                    <td><input type="text" name="sources[]" value="<?php echo esc_attr($source); ?>" style="width:100%;" placeholder="https://example.com/wp-json" /></td>
                                    <td>
                                        <select name="source_categories[]" style="width:100%;">
                                            <option value="0">-- No Category --</option>
                                            <?php foreach ($categories as $cat) { ?>
                                                <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($cat_id, $cat->term_id); ?>><?php echo esc_html($cat->name); ?></option>
                                            <?php } ?>
                                        </select>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            ?>
                            <tr>
                                <td><input type="text" name="sources[]" value="" style="width:100%;" placeholder="https://example.com/wp-json" /></td>
                                <td>
                                    <select name="source_categories[]" style="width:100%;">
                                        <option value="0">-- No Category --</option>
                                        <?php foreach ($categories as $cat) { ?>
                                            <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                                        <?php } ?>
                                    </select>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="aspom-add-source">Add Another Source</button></p>
                <p class="description">Examples: <code>https://example.com/wp-json</code> or <code>https://example.com/wp-json/wp/v2</code></p>
                <?php submit_button('Save Sources'); ?>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($){
            $('#aspom-add-source').on('click', function(){
                var row = '<tr>' +
                    '<td><input type="text" name="sources[]" value="" style="width:100%;" placeholder="https://example.com/wp-json" /></td>' +
                    '<td><select name="source_categories[]" style="width:100%;"><option value="0">-- No Category --</option><?php foreach ($categories as $cat) { ?><option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_js($cat->name); ?></option><?php } ?></select></td>' +
                    '</tr>';
                $('#aspom-sources-list').append(row);
            });
        });
        </script>
        <?php
    }

    public static function page_filters() {
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());
        ?>
        <div class="wrap">
            <h1>Filters & Replacements</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="aspom_save_settings" />
                <?php wp_nonce_field('aspom_save_settings_nonce'); ?>

                <h2>Remove from title (one per line)</h2>
                <textarea name="remove_from_title_raw" rows="6" cols="80"><?php echo esc_textarea(implode("\n", $opts['remove_from_title'])); ?></textarea>

                <h2>Remove from description (one per line)</h2>
                <textarea name="remove_from_description_raw" rows="6" cols="80"><?php echo esc_textarea(implode("\n", $opts['remove_from_description'])); ?></textarea>

                <h2>Replacement pairs (one per line as <code>search => replace</code>)</h2>
                <textarea name="replacement_pairs_raw" rows="8" cols="80"><?php
                    $lines = [];
                    foreach ($opts['replacement_pairs'] as $p) { $lines[] = $p['search'] . ' => ' . $p['replace']; }
                    echo esc_textarea(implode("\n", $lines));
                ?></textarea>

                <?php submit_button('Save Filters'); ?>
            </form>
        </div>
        <?php
    }

    public static function page_ai() {
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());
        ?>
        <div class="wrap">
            <h1>AI Settings</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="aspom_save_settings" />
                <?php wp_nonce_field('aspom_save_settings_nonce'); ?>

                <table class="form-table">
                    <tr valign="top"><th>Enable AI features</th>
                        <td><input type="checkbox" name="ai_enabled" <?php checked($opts['ai_enabled']); ?> /></td>
                    </tr>
                    <tr valign="top"><th>Provider</th>
                        <td>
                            <select name="ai_provider">
                                <option value="">-- choose --</option>
                                <option value="openai" <?php selected($opts['ai_provider'], 'openai'); ?>>OpenAI</option>
                                <option value="openrouter" <?php selected($opts['ai_provider'], 'openrouter'); ?>>OpenRouter</option>
                                <option value="gemini" <?php selected($opts['ai_provider'], 'gemini'); ?>>Gemini</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top"><th>API Key</th>
                        <td><input type="text" name="ai_api_key" value="<?php echo esc_attr($opts['ai_api_key']); ?>" style="width:50%;" /></td>
                    </tr>
                    <tr valign="top"><th>Model</th>
                        <td><input type="text" name="ai_model" value="<?php echo esc_attr($opts['ai_model']); ?>" /></td>
                    </tr>
                    <tr valign="top"><th>Instruction for title</th>
                        <td><input type="text" name="ai_instruction_title" value="<?php echo esc_attr($opts['ai_instruction_title']); ?>" style="width:80%;" /></td>
                    </tr>
                    <tr valign="top"><th>Instruction for description</th>
                        <td><textarea name="ai_instruction_description" rows="4" cols="80"><?php echo esc_textarea($opts['ai_instruction_description']); ?></textarea></td>
                    </tr>
                </table>

                <?php submit_button('Save AI Settings'); ?>
            </form>
        </div>
        <?php
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('aspom_save_settings_nonce');

        $defaults = \AutoSyncPro\Plugin::defaults();
        $opts = get_option(\AutoSyncPro\Plugin::OPTION_KEY, $defaults);

        if (isset($_POST['sources'])) {
            $sources = array_map('trim', array_map('esc_url_raw', $_POST['sources']));
            $sources = array_filter($sources);
            $opts['sources'] = array_values($sources);

            $categories = isset($_POST['source_categories']) ? array_map('intval', $_POST['source_categories']) : [];
            $opts['source_categories'] = $categories;
        }

        if (isset($_POST['interval_seconds'])) {
            $opts['interval_seconds'] = intval($_POST['interval_seconds']);
            if ($opts['interval_seconds'] < 5) $opts['interval_seconds'] = 5;
        }

        if (isset($_POST['fetch_featured_image']) || isset($_POST['debug']) || isset($_POST['strip_all_links'])) {
            $opts['fetch_featured_image'] = isset($_POST['fetch_featured_image']) ? true : false;
            $opts['use_custom_featured'] = isset($_POST['use_custom_featured']) ? true : false;
            $opts['custom_featured_url'] = isset($_POST['custom_featured_url']) ? esc_url_raw($_POST['custom_featured_url']) : '';
            $opts['strip_all_links'] = isset($_POST['strip_all_links']) ? true : false;
            $opts['debug'] = isset($_POST['debug']) ? true : false;
        }

        if (isset($_POST['remove_from_title_raw'])) {
            $opts['remove_from_title'] = array_values(array_filter(array_map('trim', explode("\n", wp_unslash($_POST['remove_from_title_raw'])))));
        }
        if (isset($_POST['remove_from_description_raw'])) {
            $opts['remove_from_description'] = array_values(array_filter(array_map('trim', explode("\n", wp_unslash($_POST['remove_from_description_raw'])))));
        }
        if (isset($_POST['replacement_pairs_raw'])) {
            $pairs = [];
            $lines = array_filter(array_map('trim', explode("\n", wp_unslash($_POST['replacement_pairs_raw']))));
            foreach ($lines as $ln) {
                if (strpos($ln, '=>') !== false) {
                    list($s, $r) = array_map('trim', explode('=>', $ln, 2));
                    if ($s !== '') $pairs[] = ['search' => $s, 'replace' => $r];
                }
            }
            $opts['replacement_pairs'] = $pairs;
        }

        if (isset($_POST['ai_enabled']) || isset($_POST['ai_provider']) || isset($_POST['ai_api_key'])) {
            $opts['ai_enabled'] = isset($_POST['ai_enabled']) ? true : false;
            $opts['ai_provider'] = isset($_POST['ai_provider']) ? sanitize_text_field($_POST['ai_provider']) : $opts['ai_provider'];
            $opts['ai_api_key'] = isset($_POST['ai_api_key']) ? sanitize_text_field($_POST['ai_api_key']) : $opts['ai_api_key'];
            $opts['ai_model'] = isset($_POST['ai_model']) ? sanitize_text_field($_POST['ai_model']) : $opts['ai_model'];
            $opts['ai_instruction_title'] = isset($_POST['ai_instruction_title']) ? sanitize_text_field($_POST['ai_instruction_title']) : $opts['ai_instruction_title'];
            $opts['ai_instruction_description'] = isset($_POST['ai_instruction_description']) ? sanitize_text_field($_POST['ai_instruction_description']) : $opts['ai_instruction_description'];
        }

        update_option(\AutoSyncPro\Plugin::OPTION_KEY, $opts);

        \AutoSyncPro\Plugin::reschedule();

        $redirect = wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=aspom-settings');
        wp_safe_redirect($redirect);
        exit;
    }

    public static function ajax_manual_run() {
        check_ajax_referer('aspom_manual_run_ajax', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        Fetcher::handle(true);
        wp_send_json_success('Manual run complete');
    }
}
