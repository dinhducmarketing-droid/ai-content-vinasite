<?php
if (!defined('ABSPATH')) exit;

/**
 * Sinh ảnh minh hoạ — Claude không tạo ảnh, nên dùng:
 *  - OpenAI Images (DALL·E 3 / gpt-image-1) → trả base64
 *  - fal.ai (FLUX) → trả URL, tải về rồi base64
 * Sideload vào Media Library.
 */
class ACV_Image
{
    /** Model OpenAI (giữ tên $models để tương thích nơi khác). */
    public static $models = array(
        'dall-e-3'    => 'DALL·E 3',
        'gpt-image-1' => 'gpt-image-1 (cần verify org)',
    );

    /** Model fal.ai. */
    public static $fal_models = array(
        'fal-ai/flux/schnell'  => 'FLUX schnell (nhanh, rẻ nhất)',
        'fal-ai/flux/dev'      => 'FLUX dev (chất lượng cao)',
        'fal-ai/flux-pro/v1.1' => 'FLUX Pro v1.1 (cao cấp)',
    );

    public static $sizes = array(
        '1024x1024' => 'Vuông 1024×1024',
        '1792x1024' => 'Ngang 1792×1024',
        '1024x1792' => 'Dọc 1024×1792',
    );

    /** Preset phong cách ảnh nhanh. */
    public static function presets()
    {
        return array(
            ''           => array('label' => '(Dùng tuỳ chỉnh bên dưới)', 'style' => ''),
            'photo'      => array('label' => 'Ảnh chụp thật',     'style' => 'a realistic photograph, professional architectural photography, photorealistic, real life, natural daylight, ultra detailed, shot on DSLR, not an illustration, not a 3d render, not a painting'),
            'cartoon'    => array('label' => 'Hoạt hình',          'style' => 'cartoon illustration, vibrant friendly colors'),
            '3d'         => array('label' => '3D Render',          'style' => '3D render, cinematic lighting, clean'),
            'watercolor' => array('label' => 'Màu nước',           'style' => 'soft watercolor painting style'),
            'flat'       => array('label' => 'Flat Illustration',  'style' => 'flat vector illustration, minimal, clean'),
            'oil'        => array('label' => 'Tranh sơn dầu',      'style' => 'oil painting style, artistic'),
        );
    }

    /** Ghép preset + tuỳ chỉnh + hướng dẫn chung vào prompt. */
    private static function apply_style($prompt)
    {
        $parts   = array();
        $presets = self::presets();
        $pk = ACV_Settings::get('image_preset');
        if ($pk && isset($presets[$pk]) && $presets[$pk]['style']) $parts[] = $presets[$pk]['style'];
        $custom = trim((string) ACV_Settings::get('image_style'));
        if ($custom) $parts[] = $custom;
        $dir = trim((string) ACV_Settings::get('image_direction'));
        if ($dir) $parts[] = $dir;
        if ($parts) $prompt .= '. ' . implode(', ', $parts);
        return $prompt;
    }

    public static function provider()
    {
        return (ACV_Settings::get('image_provider') === 'fal') ? 'fal' : 'openai';
    }

    public static function cost($provider, $model, $size)
    {
        if ($provider === 'fal') {
            if (strpos($model, 'schnell') !== false) return 0.003;
            if (strpos($model, 'pro') !== false) return 0.04;
            return 0.025; // dev
        }
        if ($model === 'gpt-image-1') return 0.04;
        return ($size === '1024x1024') ? 0.04 : 0.08;
    }

    /** @return array{b64:string,mime:string,cost:float}|WP_Error */
    public static function generate($prompt)
    {
        $prompt = trim((string) $prompt);
        if ($prompt === '') return new WP_Error('no_prompt', 'Thiếu prompt ảnh.');
        $prompt = self::apply_style($prompt);
        $r = (self::provider() === 'fal') ? self::gen_fal($prompt) : self::gen_openai($prompt);
        if (is_wp_error($r)) return $r;
        $wm = self::watermark($r['b64'], $r['mime']);
        $r['b64']  = $wm[0];
        $r['mime'] = $wm[1];
        return $r;
    }

    /** Đóng dấu logo lên ảnh (GD). @return array [b64, mime] */
    private static function watermark($b64, $mime)
    {
        if (!ACV_Settings::get('watermark_enable') || !function_exists('imagecreatefromstring')) return array($b64, $mime);
        $logo_bytes = self::read_logo(trim((string) ACV_Settings::get('watermark_logo')));
        if (!$logo_bytes) return array($b64, $mime);
        $base = @imagecreatefromstring(base64_decode($b64));
        $logo = @imagecreatefromstring($logo_bytes);
        if (!$base || !$logo) { if ($base) imagedestroy($base); if ($logo) imagedestroy($logo); return array($b64, $mime); }

        $bw = imagesx($base); $bh = imagesy($base);
        $lw0 = imagesx($logo); $lh0 = imagesy($logo);
        $pct = max(5, min(60, (int) (ACV_Settings::get('watermark_size') ?: 18)));
        $lw  = max(1, (int) round($bw * $pct / 100));
        $lh  = max(1, (int) round($lh0 * $lw / max(1, $lw0)));
        $pad = (int) round($bw * 0.025);
        switch (ACV_Settings::get('watermark_position')) {
            case 'bottom-left': $x = $pad;               $y = $bh - $lh - $pad; break;
            case 'top-right':   $x = $bw - $lw - $pad;   $y = $pad;             break;
            case 'top-left':    $x = $pad;               $y = $pad;             break;
            case 'center':      $x = (int) (($bw - $lw) / 2); $y = (int) (($bh - $lh) / 2); break;
            default:            $x = $bw - $lw - $pad;   $y = $bh - $lh - $pad; // bottom-right
        }
        imagealphablending($base, true);
        imagesavealpha($base, true);
        imagecopyresampled($base, $logo, $x, $y, 0, 0, $lw, $lh, $lw0, $lh0);
        ob_start(); imagepng($base); $out = ob_get_clean();
        imagedestroy($base); imagedestroy($logo);
        return $out ? array(base64_encode($out), 'image/png') : array($b64, $mime);
    }

    private static function read_logo($url)
    {
        if (!$url) return false;
        $id = attachment_url_to_postid($url);
        if ($id) { $p = get_attached_file($id); if ($p && file_exists($p)) return file_get_contents($p); }
        $up = wp_get_upload_dir();
        if (isset($up['baseurl']) && strpos($url, $up['baseurl']) === 0) {
            $p = $up['basedir'] . substr($url, strlen($up['baseurl']));
            if (file_exists($p)) return file_get_contents($p);
        }
        $r = wp_remote_get($url, array('timeout' => 30));
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) != 200) return false;
        return wp_remote_retrieve_body($r);
    }

    private static function gen_openai($prompt)
    {
        $key = trim((string) ACV_Settings::get('openai_api_key'));
        if (!$key) return new WP_Error('no_openai', 'Chưa cấu hình OpenAI API key.');
        $model = ACV_Settings::get('image_model') ?: 'dall-e-3';
        $size  = ACV_Settings::get('image_size') ?: '1024x1024';
        $body  = array('model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => self::size_openai($model, $size));
        if ($model !== 'gpt-image-1') $body['response_format'] = 'b64_json';

        $res = wp_remote_post('https://api.openai.com/v1/images/generations', array(
            'timeout' => 120,
            'headers' => array('Authorization' => 'Bearer ' . $key, 'content-type' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200) return new WP_Error('openai_error', 'OpenAI: ' . (isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code)));
        $b64 = isset($data['data'][0]['b64_json']) ? $data['data'][0]['b64_json'] : '';
        if (!$b64) return new WP_Error('no_image', 'OpenAI không trả về ảnh.');
        return array('b64' => $b64, 'mime' => 'image/png', 'cost' => self::cost('openai', $model, $size));
    }

    private static function gen_fal($prompt)
    {
        $key = trim((string) ACV_Settings::get('fal_api_key'));
        if (!$key) return new WP_Error('no_fal', 'Chưa cấu hình fal.ai API key.');
        $model = ACV_Settings::get('fal_model') ?: 'fal-ai/flux/schnell';
        $size  = ACV_Settings::get('image_size') ?: '1024x1024';
        $endpoint = trim((string) ACV_Settings::get('fal_endpoint'));
        $base  = $endpoint ? untrailingslashit($endpoint) : 'https://fal.run';
        $body  = array('prompt' => $prompt, 'image_size' => self::size_fal($size), 'num_images' => 1);

        $res = wp_remote_post($base . '/' . $model, array(
            'timeout' => 120,
            'headers' => array('Authorization' => 'Key ' . $key, 'content-type' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200) {
            $msg = 'HTTP ' . $code;
            if (isset($data['detail'])) $msg = is_array($data['detail']) ? wp_json_encode($data['detail']) : $data['detail'];
            elseif (isset($data['error'])) $msg = is_array($data['error']) ? wp_json_encode($data['error']) : $data['error'];
            return new WP_Error('fal_error', 'fal.ai: ' . $msg);
        }
        // Qua proxy Worker: trả thẳng {b64, mime}.
        if ($endpoint && isset($data['b64'])) {
            return array('b64' => $data['b64'], 'mime' => isset($data['mime']) ? $data['mime'] : 'image/jpeg', 'cost' => self::cost('fal', $model, $size));
        }
        $url = isset($data['images'][0]['url']) ? $data['images'][0]['url'] : '';
        if (!$url) return new WP_Error('no_image', 'fal.ai không trả về ảnh.');
        $ct = isset($data['images'][0]['content_type']) ? $data['images'][0]['content_type'] : 'image/jpeg';

        $img = wp_remote_get($url, array('timeout' => 60));
        if (is_wp_error($img)) return new WP_Error('fal_download', 'Không tải được ảnh fal: ' . $img->get_error_message());
        $bytes = wp_remote_retrieve_body($img);
        if (!$bytes) return new WP_Error('fal_download', 'Ảnh fal rỗng.');
        $hdr_ct = wp_remote_retrieve_header($img, 'content-type');
        if ($hdr_ct) $ct = $hdr_ct;
        return array('b64' => base64_encode($bytes), 'mime' => $ct, 'cost' => self::cost('fal', $model, $size));
    }

    private static function size_openai($model, $size)
    {
        if ($model !== 'gpt-image-1') return $size;
        $m = array('1792x1024' => '1536x1024', '1024x1792' => '1024x1536', '1024x1024' => '1024x1024');
        return isset($m[$size]) ? $m[$size] : '1024x1024';
    }

    private static function size_fal($size)
    {
        $m = array('1024x1024' => 'square_hd', '1792x1024' => 'landscape_16_9', '1024x1792' => 'portrait_16_9');
        return isset($m[$size]) ? $m[$size] : 'square_hd';
    }

    /**
     * Tạo ảnh từ prompt → sideload → đặt làm ảnh đại diện của bài.
     * @return array{id:int,url:string,cost:float}|WP_Error
     */
    public static function generate_and_attach($post_id, $prompt, $alt = '', $slug = '')
    {
        $post_id = (int) $post_id;
        if (!$post_id) return new WP_Error('no_post', 'Thiếu bài viết.');

        $gen = self::generate($prompt);
        if (is_wp_error($gen)) return $gen;

        $post  = get_post($post_id);
        $slug  = $slug ?: ($post ? $post->post_name : '');
        $slug  = $slug ?: ($post ? sanitize_title(get_the_title($post)) : 'ai-image');
        $alt   = $alt !== '' ? $alt : ($post ? get_the_title($post) : '');

        $att = self::sideload($gen['b64'], $post_id, $alt, '', $slug, $gen['mime']);
        if (is_wp_error($att)) return $att;

        set_post_thumbnail($post_id, $att);
        return array('id' => (int) $att, 'url' => wp_get_attachment_url($att), 'cost' => isset($gen['cost']) ? $gen['cost'] : 0.0);
    }

    /** Sideload base64 → Media Library. @return int attachment_id|WP_Error */
    public static function sideload($b64, $post_id, $alt, $caption, $slug, $mime = 'image/png')
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $bytes = base64_decode($b64);
        if ($bytes === false || $bytes === '') return new WP_Error('decode', 'Không giải mã được ảnh.');

        $ext = ($mime === 'image/jpeg' || $mime === 'image/jpg') ? 'jpg' : (($mime === 'image/webp') ? 'webp' : 'png');
        $base = sanitize_title($slug ?: 'ai-image');
        $filename = $base . '-' . substr(md5($b64 . $post_id), 0, 8) . '.' . $ext;

        $upload = wp_upload_bits($filename, null, $bytes);
        if (!empty($upload['error'])) return new WP_Error('upload', $upload['error']);

        $attach_id = wp_insert_attachment(array(
            'post_mime_type' => $mime,
            'post_title'     => $alt ?: $filename,
            'post_excerpt'   => (string) $caption,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ), $upload['file'], $post_id);
        if (is_wp_error($attach_id)) return $attach_id;

        $meta = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $meta);
        if ($alt) update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
        return (int) $attach_id;
    }

    /** Test (chỉ OpenAI có endpoint free; fal thì thử bằng tạo ảnh thật). @return true|WP_Error */
    public static function test()
    {
        $key = trim((string) ACV_Settings::get('openai_api_key'));
        if (!$key) return new WP_Error('no_openai', 'Chưa nhập OpenAI API key.');
        $res = wp_remote_get('https://api.openai.com/v1/models', array('timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $key)));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        if ($code === 200) return true;
        $d = json_decode(wp_remote_retrieve_body($res), true);
        return new WP_Error('openai_error', isset($d['error']['message']) ? $d['error']['message'] : ('HTTP ' . $code));
    }
}
