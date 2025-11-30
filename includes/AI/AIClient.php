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

        if (empty($orig_title) && empty($orig_desc)) {
            $this->log('AI generation skipped: no content provided');
            return false;
        }

        $result = false;
        switch ($this->provider) {
            case 'openai':
                $result = $this->callOpenAI($title_inst, $desc_inst, $orig_title, $orig_desc);
                break;
            case 'openrouter':
                $result = $this->callOpenRouter($title_inst, $desc_inst, $orig_title, $orig_desc);
                break;
            case 'gemini':
                $result = $this->callGemini($title_inst, $desc_inst, $orig_title, $orig_desc);
                break;
            default:
                $this->log('Unknown AI provider: ' . $this->provider);
                return false;
        }

        if ($result && (isset($result['title']) || isset($result['description']))) {
            $this->log('AI generation successful');
            return $result;
        }

        return false;
    }

    protected function callOpenAI($title_inst, $desc_inst, $orig_title, $orig_desc) {
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        $model = !empty($this->model) ? $this->model : 'gpt-4o-mini';

        $system_prompt = 'You are a content enhancement assistant. You will receive content improvement instructions and return JSON with enhanced title and description fields.';

        $user_prompt = "Instructions:\n";
        if (!empty($title_inst)) {
            $user_prompt .= "Title: " . $title_inst . "\n";
        }
        if (!empty($desc_inst)) {
            $user_prompt .= "Description: " . $desc_inst . "\n";
        }

        $user_prompt .= "\nOriginal Content:\n";
        if (!empty($orig_title)) {
            $user_prompt .= "Title: " . $orig_title . "\n";
        }
        if (!empty($orig_desc)) {
            $user_prompt .= "Description: " . substr($orig_desc, 0, 1500) . "\n";
        }

        $user_prompt .= "\nReturn only valid JSON in this exact format:\n{\"title\": \"enhanced title\", \"description\": \"enhanced description\"}";

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_prompt]
            ],
            'max_tokens' => 1000,
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
            $response_text = trim($body['choices'][0]['message']['content']);
            return $this->parseAIResponse($response_text);
        }

        $this->log('OpenAI API returned unexpected response format');
        return false;
    }

    protected function callOpenRouter($title_inst, $desc_inst, $orig_title, $orig_desc) {
        $endpoint = 'https://openrouter.ai/api/v1/chat/completions';
        $model = !empty($this->model) ? $this->model : 'openai/gpt-4o-mini';

        $system_prompt = 'You are a content enhancement assistant. You will receive content improvement instructions and return JSON with enhanced title and description fields.';

        $user_prompt = "Instructions:\n";
        if (!empty($title_inst)) {
            $user_prompt .= "Title: " . $title_inst . "\n";
        }
        if (!empty($desc_inst)) {
            $user_prompt .= "Description: " . $desc_inst . "\n";
        }

        $user_prompt .= "\nOriginal Content:\n";
        if (!empty($orig_title)) {
            $user_prompt .= "Title: " . $orig_title . "\n";
        }
        if (!empty($orig_desc)) {
            $user_prompt .= "Description: " . substr($orig_desc, 0, 1500) . "\n";
        }

        $user_prompt .= "\nReturn only valid JSON in this exact format:\n{\"title\": \"enhanced title\", \"description\": \"enhanced description\"}";

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_prompt]
            ],
            'max_tokens' => 1000,
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
            $response_text = trim($body['choices'][0]['message']['content']);
            return $this->parseAIResponse($response_text);
        }

        $this->log('OpenRouter API returned unexpected response format');
        return false;
    }

    protected function callGemini($title_inst, $desc_inst, $orig_title, $orig_desc) {
        $model = !empty($this->model) ? $this->model : 'gemini-pro';
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $this->api_key;

        $user_prompt = "You are a content enhancement assistant. Return only valid JSON.\n\nInstructions:\n";
        if (!empty($title_inst)) {
            $user_prompt .= "Title: " . $title_inst . "\n";
        }
        if (!empty($desc_inst)) {
            $user_prompt .= "Description: " . $desc_inst . "\n";
        }

        $user_prompt .= "\nOriginal Content:\n";
        if (!empty($orig_title)) {
            $user_prompt .= "Title: " . $orig_title . "\n";
        }
        if (!empty($orig_desc)) {
            $user_prompt .= "Description: " . substr($orig_desc, 0, 1500) . "\n";
        }

        $user_prompt .= "\nReturn only valid JSON in this exact format:\n{\"title\": \"enhanced title\", \"description\": \"enhanced description\"}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $user_prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 1000,
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
            $response_text = trim($body['candidates'][0]['content']['parts'][0]['text']);
            return $this->parseAIResponse($response_text);
        }

        $this->log('Gemini API returned unexpected response format');
        return false;
    }

    protected function parseAIResponse($response_text) {
        $json = json_decode($response_text, true);

        if (is_array($json)) {
            $result = [];
            if (!empty($json['title'])) {
                $result['title'] = trim($json['title']);
            }
            if (!empty($json['description'])) {
                $result['description'] = trim($json['description']);
            }

            if (!empty($result)) {
                $this->log('Successfully parsed AI response: ' . wp_json_encode($result));
                return $result;
            }
        }

        $this->log('Failed to parse AI response as JSON: ' . substr($response_text, 0, 200));
        return false;
    }

    protected function log($msg) {
        $opts = get_option('auto_sync_pro_options_v2', []);
        if (!empty($opts['debug'])) {
            error_log('[AutoSyncPro AI] ' . $msg);
        }
    }
}
