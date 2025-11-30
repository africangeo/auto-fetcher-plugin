<?php
namespace AutoSyncPro\AI;

if (!defined('ABSPATH')) exit;

class AIClient {
    protected $provider;
    protected $api_key;
    protected $model;

    public function __construct($provider, $api_key, $model = '') {
        $this->provider = $provider;
        $this->api_key = $api_key;
        $this->model = $model;
    }

    public function generate($input = []) {
        if (empty($this->provider) || empty($this->api_key)) {
            $this->log('AI generation skipped: provider or API key not configured');
            return false;
        }

        $title_inst = isset($input['title_instruction']) ? $input['title_instruction'] : '';
        $desc_inst = isset($input['description_instruction']) ? $input['description_instruction'] : '';
        $orig_title = isset($input['original_title']) ? $input['original_title'] : '';
        $orig_desc = isset($input['original_description']) ? $input['original_description'] : '';

        $prompt_parts = [];
        if (!empty($title_inst)) {
            $prompt_parts[] = "Title Enhancement: " . $title_inst;
        }
        if (!empty($desc_inst)) {
            $prompt_parts[] = "Description Enhancement: " . $desc_inst;
        }

        if (empty($prompt_parts)) {
            $this->log('AI generation skipped: no instructions provided');
            return false;
        }

        $prompt = implode("\n\n", $prompt_parts);
        $prompt .= "\n\nOriginal Title: " . $orig_title;
        $prompt .= "\n\nOriginal Description: " . substr($orig_desc, 0, 1000);
        $prompt .= "\n\nPlease provide the enhanced content.";

        $result = false;
        switch ($this->provider) {
            case 'openai':
                $result = $this->callOpenAI($prompt);
                break;
            case 'openrouter':
                $result = $this->callOpenRouter($prompt);
                break;
            case 'gemini':
                $result = $this->callGemini($prompt);
                break;
            default:
                $this->log('Unknown AI provider: ' . $this->provider);
                return false;
        }

        if ($result && !empty($result['content'])) {
            $generated_text = $result['content'];
            return [
                'title' => $generated_text,
                'description' => $generated_text
            ];
        }

        return false;
    }

    protected function callOpenAI($prompt) {
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        $model = !empty($this->model) ? $this->model : 'gpt-4o-mini';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a content enhancement assistant. Improve the provided content based on the instructions.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 800,
            'temperature' => 0.7
        ];

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 30
        ];

        $this->log('Calling OpenAI API with model: ' . $model);
        $resp = wp_remote_post($endpoint, $args);

        if (is_wp_error($resp)) {
            $this->log('OpenAI API error: ' . $resp->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($status_code !== 200) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
            $this->log('OpenAI API returned status ' . $status_code . ': ' . $error_msg);
            return false;
        }

        if (!empty($body['choices'][0]['message']['content'])) {
            $this->log('OpenAI API call successful');
            return ['content' => trim($body['choices'][0]['message']['content'])];
        }

        $this->log('OpenAI API returned unexpected response format');
        return false;
    }

    protected function callOpenRouter($prompt) {
        $endpoint = 'https://openrouter.ai/api/v1/chat/completions';
        $model = !empty($this->model) ? $this->model : 'openai/gpt-4o-mini';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a content enhancement assistant. Improve the provided content based on the instructions.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 800,
            'temperature' => 0.7
        ];

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => home_url(),
                'X-Title' => 'Auto Sync Pro'
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 30
        ];

        $this->log('Calling OpenRouter API with model: ' . $model);
        $resp = wp_remote_post($endpoint, $args);

        if (is_wp_error($resp)) {
            $this->log('OpenRouter API error: ' . $resp->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($status_code !== 200) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
            $this->log('OpenRouter API returned status ' . $status_code . ': ' . $error_msg);
            return false;
        }

        if (!empty($body['choices'][0]['message']['content'])) {
            $this->log('OpenRouter API call successful');
            return ['content' => trim($body['choices'][0]['message']['content'])];
        }

        $this->log('OpenRouter API returned unexpected response format');
        return false;
    }

    protected function callGemini($prompt) {
        $model = !empty($this->model) ? $this->model : 'gemini-pro';
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $this->api_key;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 800,
                'topP' => 0.8,
                'topK' => 40
            ]
        ];

        $args = [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 30
        ];

        $this->log('Calling Gemini API with model: ' . $model);
        $resp = wp_remote_post($endpoint, $args);

        if (is_wp_error($resp)) {
            $this->log('Gemini API error: ' . $resp->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($status_code !== 200) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
            $this->log('Gemini API returned status ' . $status_code . ': ' . $error_msg);
            return false;
        }

        if (!empty($body['candidates'][0]['content']['parts'][0]['text'])) {
            $this->log('Gemini API call successful');
            return ['content' => trim($body['candidates'][0]['content']['parts'][0]['text'])];
        }

        $this->log('Gemini API returned unexpected response format');
        return false;
    }

    protected function log($msg) {
        $opts = get_option('auto_sync_pro_options_v2', []);
        if (!empty($opts['debug'])) {
            error_log('[AutoSyncPro AI] ' . $msg);
        }
    }
}
