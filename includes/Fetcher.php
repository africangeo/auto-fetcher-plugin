<?php
namespace AutoSyncPro;

use AutoSyncPro\AI\AIClient;

if (!defined('ABSPATH')) exit;

class Fetcher {
    public static function handle($is_manual = false) {
        update_option('aspom_last_run_time', time());

        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());

        if (empty($opts['sources']) || !is_array($opts['sources'])) {
            self::log('No sources configured');
            return;
        }

        foreach ($opts['sources'] as $index => $source) {
            $source = trim($source);
            if ($source === '') continue;

            $cat_id = 0;
            if (!empty($opts['source_categories'][$index])) {
                $cat_id = intval($opts['source_categories'][$index]);
            }

            $posts = self::fetch_posts_from_source($source);
            if (!$posts || !is_array($posts)) continue;
            foreach ($posts as $p) {
                $remote_id = isset($p->id) ? intval($p->id) : 0;
                $slug = isset($p->slug) ? sanitize_title($p->slug) : '';
                if (self::post_already_imported($remote_id, $source, $slug)) {
                    self::log('Post already imported from ' . $source . ' (ID: ' . $remote_id . ', Slug: ' . $slug . ')');
                    continue;
                }
                self::publish_post($p, $source, $cat_id, $opts);
            }
        }
    }

    public static function fetch_posts_from_source($base_url) {
        $base_url = rtrim($base_url, '/');
        $urls_to_try = [
            $base_url . '/posts?per_page=10',
            $base_url . '/wp/v2/posts?per_page=10',
            $base_url . '/wp-json/wp/v2/posts?per_page=10',
        ];
        foreach ($urls_to_try as $url) {
            $resp = wp_remote_get($url, ['timeout' => 20]);
            if (is_wp_error($resp)) {
                self::log('Fetch failed: ' . $url . ' - ' . $resp->get_error_message());
                continue;
            }
            $body = wp_remote_retrieve_body($resp);
            $json = json_decode($body);
            if (is_array($json)) return $json;
        }
        return [];
    }

    public static function post_already_imported($remote_id, $source_url, $slug = '') {
        // First check by remote_id if available
        if ($remote_id > 0) {
            $args = [
                'post_type' => 'post',
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => '_aspom_remote_id',
                        'value' => $remote_id,
                        'compare' => '='
                    ],
                    [
                        'key' => '_aspom_source_url',
                        'value' => $source_url,
                        'compare' => '='
                    ]
                ]
            ];
            $q = new \WP_Query($args);
            if ($q->have_posts()) return true;
        }

        // Fallback: check by slug and source URL to prevent duplicates
        if (!empty($slug)) {
            $args = [
                'post_type' => 'post',
                'name' => $slug,
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => '_aspom_source_url',
                        'value' => $source_url,
                        'compare' => '='
                    ]
                ]
            ];
            $q = new \WP_Query($args);
            if ($q->have_posts()) return true;
        }

        return false;
    }

    public static function publish_post($remote_post, $source_url, $cat_id, $opts) {
        $title_raw = isset($remote_post->title->rendered) ? wp_strip_all_tags($remote_post->title->rendered) : '';
        $content_raw = isset($remote_post->content->rendered) ? $remote_post->content->rendered : '';

        // filters
        foreach ($opts['remove_from_title'] as $rem) {
            if ($rem === '') continue;
            $title_raw = str_ireplace($rem, '', $title_raw);
        }
        foreach ($opts['remove_from_description'] as $rem) {
            if ($rem === '') continue;
            $content_raw = str_ireplace($rem, '', $content_raw);
        }

        // replacement pairs
        foreach ($opts['replacement_pairs'] as $pair) {
            if (!is_array($pair)) continue;
            $s = isset($pair['search']) ? $pair['search'] : '';
            $r = isset($pair['replace']) ? $pair['replace'] : '';
            if ($s === '') continue;
            $content_raw = str_replace($s, $r, $content_raw);
            $title_raw = str_replace($s, $r, $title_raw);
        }

        // strip links if enabled
        if (!empty($opts['strip_all_links'])) {
            $content_raw = preg_replace('#<a.*?href=["\'](.*?)["\'].*?>(.*?)</a>#is', '$2', $content_raw);
            $content_raw = preg_replace('@https?://[^\s"\']+@i', '', $content_raw);
        }

        // AI enrichment
        if (!empty($opts['ai_enabled']) && !empty($opts['ai_provider']) && !empty($opts['ai_api_key'])) {
            self::log('Attempting AI enrichment for post...');
            $ai = new AIClient($opts['ai_provider'], $opts['ai_api_key'], isset($opts['ai_model']) ? $opts['ai_model'] : '');
            $ai_input = [
                'title_instruction' => isset($opts['ai_instruction_title']) ? $opts['ai_instruction_title'] : '',
                'description_instruction' => isset($opts['ai_instruction_description']) ? $opts['ai_instruction_description'] : '',
                'original_title' => $title_raw,
                'original_description' => $content_raw,
            ];
            $res = $ai->generate($ai_input);
            if ($res && is_array($res)) {
                if (!empty($res['title'])) {
                    $title_raw = wp_strip_all_tags($res['title']);
                    self::log('AI title updated');
                }
                if (!empty($res['description'])) {
                    $content_raw = wp_kses_post($res['description']);
                    self::log('AI description updated');
                }
            } else {
                self::log('AI generation returned no results');
            }
        }

        $postarr = [
            'post_title' => wp_strip_all_tags($title_raw),
            'post_content' => $content_raw,
            'post_status' => 'publish',
            'post_author' => 1,
            'post_category' => $cat_id ? [$cat_id] : [],
            'post_name' => isset($remote_post->slug) ? sanitize_title($remote_post->slug) : sanitize_title($title_raw),
        ];

        $post_id = wp_insert_post($postarr);
        if (is_wp_error($post_id) || !$post_id) {
            self::log('Failed insert: ' . print_r($postarr, true));
            return;
        }

        update_post_meta($post_id, '_aspom_remote_id', isset($remote_post->id) ? intval($remote_post->id) : 0);
        update_post_meta($post_id, '_aspom_source_url', $source_url);

        // featured image
        if (!empty($opts['fetch_featured_image'])) {
            if (!empty($opts['use_custom_featured']) && !empty($opts['custom_featured_url'])) {
                self::attach_remote_image($opts['custom_featured_url'], $post_id);
            } else {
                self::attach_from_remote_post($remote_post, $source_url, $post_id);
            }
        }

        // RankMath
        if (class_exists('RankMath')) {
            $title = get_the_title($post_id);
            $words = preg_split('/\s+/', $title);
            $keywords = $title;
            if (count($words) >= 2) $keywords = $words[0] . ', ' . end($words);
            update_post_meta($post_id, 'rank_math_focus_keyword', $keywords);
            do_action('rank_math/reindex_post', $post_id);
        }

        self::log('Inserted post ' . $post_id . ' from ' . $source_url);
    }

    public static function attach_from_remote_post($remote_post, $source_url, $post_id) {
        $image_url = null;
        if (!empty($remote_post->featured_media) && is_numeric($remote_post->featured_media)) {
            $mid = intval($remote_post->featured_media);
            $resp = wp_remote_get(rtrim($source_url, '/') . '/wp/v2/media/' . $mid, ['timeout' => 15]);
            if (!is_wp_error($resp)) {
                $md = json_decode(wp_remote_retrieve_body($resp));
                if (!empty($md->source_url)) $image_url = $md->source_url;
            }
        }
        if (!$image_url && !empty($remote_post->content->rendered)) {
            if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $remote_post->content->rendered, $m)) {
                $image_url = $m[1];
            }
        }
        if ($image_url) {
            $result = self::attach_remote_image($image_url, $post_id);
            if ($result === false && !empty($remote_post->content->rendered)) {
                self::log('Image failed to download, removing from content: ' . $image_url);
                $content = get_post_field('post_content', $post_id);
                $content = preg_replace('/<figure[^>]*>.*?<img[^>]*src=["\']' . preg_quote($image_url, '/') . '["\'][^>]*>.*?<\/figure>/is', '', $content);
                $content = preg_replace('/<img[^>]*src=["\']' . preg_quote($image_url, '/') . '["\'][^>]*\s*\/?>/is', '', $content);
                $content = preg_replace('/<p>\s*<\/p>/is', '', $content);
                wp_update_post(['ID' => $post_id, 'post_content' => $content]);
            }
        }
    }

    public static function attach_remote_image($image_url, $post_id) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $image_url = esc_url_raw($image_url);
        if (empty($image_url)) {
            self::log('Empty image URL provided');
            return false;
        }

        self::log('Downloading image: ' . $image_url);
        $tmp = download_url($image_url, 30);
        if (is_wp_error($tmp)) {
            self::log('download_url failed: ' . $tmp->get_error_message());
            return false;
        }

        $file_array = [
            'name' => basename(parse_url($image_url, PHP_URL_PATH)),
            'tmp_name' => $tmp
        ];

        if (empty($file_array['name']) || strpos($file_array['name'], '.') === false) {
            $file_array['name'] = 'image-' . time() . '.jpg';
        }

        $wp_filetype = wp_check_filetype($file_array['name']);
        if (empty($wp_filetype['ext']) || !in_array(strtolower($wp_filetype['ext']), ['jpg','jpeg','png','gif','webp','bmp'])) {
            self::log('Invalid file type detected: ' . $wp_filetype['ext'] . ' for URL: ' . $image_url);
            @unlink($tmp);
            return false;
        }

        $attach_id = media_handle_sideload($file_array, $post_id, null);

        if (is_wp_error($attach_id)) {
            @unlink($tmp);
            self::log('media_handle_sideload error: ' . $attach_id->get_error_message());
            return false;
        }

        set_post_thumbnail($post_id, $attach_id);
        self::log('Successfully attached image ID ' . $attach_id . ' to post ' . $post_id);
        return $attach_id;
    }

    public static function log($msg) {
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());
        if (!$opts['debug']) return;
        if (is_array($msg) || is_object($msg)) $msg = print_r($msg, true);
        error_log('[AutoSyncPro] ' . $msg);
    }
}
