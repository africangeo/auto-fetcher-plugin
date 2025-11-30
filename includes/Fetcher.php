<?php
namespace AutoSyncPro;

use AutoSyncPro\AI\AIClient;

if (!defined('ABSPATH')) exit;

class Fetcher {
    public static function handle($is_manual = false) {
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());

        // ensure category exists
        $cat = get_category_by_slug('latest-jobs');
        if (!$cat) {
            $term = wp_insert_term('Latest Jobs', 'category', ['slug' => 'latest-jobs']);
            $cat_id = is_wp_error($term) ? 0 : $term['term_id'];
        } else {
            $cat_id = $cat->term_id;
        }

        if (empty($opts['sources']) || !is_array($opts['sources'])) {
            self::log('No sources configured');
            return;
        }

        foreach ($opts['sources'] as $source) {
            $source = trim($source);
            if ($source === '') continue;
            $posts = self::fetch_posts_from_source($source);
            if (!$posts || !is_array($posts)) continue;
            foreach ($posts as $p) {
                $slug = isset($p->slug) ? sanitize_title($p->slug) : '';
                if (empty($slug)) {
                    $slug = isset($p->title->rendered) ? sanitize_title($p->title->rendered) : 'aspom-' . uniqid();
                }
                if (self::post_exists_by_slug($slug)) continue;
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

    public static function post_exists_by_slug($slug) {
        $q = new \WP_Query(['name' => $slug, 'post_type' => 'post', 'posts_per_page' => 1]);
        return $q->have_posts();
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
            $ai = new AIClient($opts['ai_provider'], $opts['ai_api_key'], $opts['ai_model']);
            $ai_input = [
                'title_instruction' => $opts['ai_instruction_title'],
                'description_instruction' => $opts['ai_instruction_description'],
                'original_title' => $title_raw,
                'original_description' => wp_strip_all_tags($content_raw),
            ];
            $res = $ai->generate($ai_input);
            if ($res && is_array($res)) {
                if (!empty($res['title'])) $title_raw = wp_strip_all_tags($res['title']);
                if (!empty($res['description'])) $content_raw = wp_kses_post($res['description']);
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

        // featured image
        if (!empty($opts['fetch_featured_image'])) {
            if (!empty($opts['use_custom_featured']) && !empty($opts['custom_featured_url'])) {
                self::attach_remote_image($opts['custom_featured_url'], $post_id);
            } else {
                self::attach_from_remote_post($remote_post, $source_url, $post_id);
            }
        }

        // minimal button append
        self::append_button($post_id);

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
        if ($image_url) self::attach_remote_image($image_url, $post_id);
    }

    public static function attach_remote_image($image_url, $post_id) {
        // download & sideload
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            self::log('download_url failed: ' . $image_url);
            return false;
        }
        $file = [
            'name' => basename($image_url),
            'tmp_name' => $tmp
        ];
        $wp_filetype = wp_check_filetype($file['name']);
        if (!in_array(strtolower($wp_filetype['ext']), ['jpg','jpeg','png','gif','webp'])) {
            @unlink($tmp);
            return false;
        }
        $attach_id = media_handle_sideload($file, $post_id);
        if (is_wp_error($attach_id)) {
            @unlink($tmp);
            self::log('media sideload error: ' . $attach_id->get_error_message());
            return false;
        }
        set_post_thumbnail($post_id, $attach_id);
        return $attach_id;
    }

    public static function append_button($post_id) {
        $html = '<div style="text-align:center;margin-top:20px;"><a href="#" style="background:#0073aa;color:#fff;padding:10px 18px;border-radius:4px;text-decoration:none;">Latest Job vacancy</a></div>';
        $current = get_post_field('post_content', $post_id);
        wp_update_post(['ID' => $post_id, 'post_content' => $current . $html]);
    }

    public static function log($msg) {
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());
        if (!$opts['debug']) return;
        if (is_array($msg) || is_object($msg)) $msg = print_r($msg, true);
        error_log('[AutoSyncPro] ' . $msg);
    }
}
