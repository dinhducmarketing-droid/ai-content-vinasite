<?php
if (!defined('ABSPATH')) exit;

/**
 * Client gọi Claude Messages API qua WP HTTP API (không cần composer).
 * Dùng structured output (output_config.format) để bảo đảm JSON parse được.
 */
class ACV_API
{
    const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const VERSION  = '2023-06-01';

    /**
     * @return array{data:array,usage:array,model:string}|WP_Error
     */
    public static function generate($model, $system, $user, $schema, $max_tokens = 3500)
    {
        $key = ACV_Settings::get('api_key');
        if (!$key) return new WP_Error('no_key', 'Chưa cấu hình Claude API key.');

        $body = array(
            'model'      => $model,
            'max_tokens' => (int) $max_tokens,
            'system'     => array(array(
                'type'          => 'text',
                'text'          => $system,
                'cache_control' => array('type' => 'ephemeral'), // tiết kiệm ~90% phần system lặp lại
            )),
            'messages'      => array(array('role' => 'user', 'content' => $user)),
            'output_config' => array('format' => array('type' => 'json_schema', 'schema' => $schema)),
        );

        $res = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 90,
            'headers' => array(
                'x-api-key'         => $key,
                'anthropic-version' => self::VERSION,
                'content-type'      => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $raw  = wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);

        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
            return new WP_Error('api_error', $msg);
        }
        if (isset($data['stop_reason']) && $data['stop_reason'] === 'refusal') {
            return new WP_Error('refusal', 'Mô hình từ chối yêu cầu này.');
        }

        $text = '';
        foreach ((array) ($data['content'] ?? array()) as $b) {
            if (($b['type'] ?? '') === 'text') { $text = $b['text']; break; }
        }
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) return new WP_Error('parse', 'Không parse được JSON từ phản hồi.');

        return array(
            'data'  => $parsed,
            'usage' => isset($data['usage']) ? $data['usage'] : array(),
            'model' => isset($data['model']) ? $data['model'] : $model,
        );
    }

    /** Test key bằng 1 request nhỏ. @return true|WP_Error */
    public static function test($key = '')
    {
        if (!$key) $key = ACV_Settings::get('api_key');
        if (!$key) return new WP_Error('no_key', 'Chưa nhập API key.');
        $res = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 30,
            'headers' => array(
                'x-api-key'         => $key,
                'anthropic-version' => self::VERSION,
                'content-type'      => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model'      => 'claude-haiku-4-5',
                'max_tokens' => 8,
                'messages'   => array(array('role' => 'user', 'content' => 'ping')),
            )),
        ));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        if ($code === 200) return true;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return new WP_Error('api_error', isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code));
    }

    /** Ước tính chi phí USD từ usage. */
    public static function cost($model, $usage)
    {
        $p = isset(ACV_Settings::$prices[$model]) ? ACV_Settings::$prices[$model] : array(3.0, 15.0);
        $in    = (int) ($usage['input_tokens'] ?? 0);
        $out   = (int) ($usage['output_tokens'] ?? 0);
        $cread = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $ccrea = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        return ($in * $p[0] + $out * $p[1] + $cread * $p[0] * 0.1 + $ccrea * $p[0] * 1.25) / 1000000;
    }
}
