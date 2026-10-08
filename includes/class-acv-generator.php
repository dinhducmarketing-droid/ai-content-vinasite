<?php
if (!defined('ABSPATH')) exit;

/**
 * Điều phối: sinh → validate → assemble → áp dụng (backup) → hoàn tác.
 */
class ACV_Generator
{
    const META_FAQ    = '_acv_faq';
    const META_BACKUP = '_acv_backup';

    /**
     * Gọi API sinh nội dung (chưa ghi). Trả payload đã chuẩn hoá.
     * @return array|WP_Error
     */
    public static function generate($post_id, $model_override = '')
    {
        $built  = ACV_Prompt::build($post_id);
        $enable_faq   = (bool) ACV_Settings::get('enable_faq');
        $enable_image = (bool) ACV_Settings::get('image_enable');
        $schema = ACV_Prompt::schema($enable_faq, $enable_image);
        $model  = $model_override ?: $built['profile']['model'];

        // Nới token đầu ra theo độ dài bài (bài viết dài cần nhiều hơn mặc định).
        $wmax = (int) ($built['profile']['wmax'] ?? 350);
        $max_tokens = max(3500, min(8000, $wmax * 4 + 800));

        $res = ACV_API::generate($model, $built['system'], $built['user'], $schema, $max_tokens);
        if (is_wp_error($res)) return $res;

        $d = $res['data'];
        $payload = array(
            'content_html'     => isset($d['content_html']) ? $d['content_html'] : '',
            'answer_summary'   => isset($d['answer_summary']) ? $d['answer_summary'] : '',
            'faq'              => ($enable_faq && !empty($d['faq']) && is_array($d['faq'])) ? $d['faq'] : array(),
            'cta_html'         => isset($d['cta_html']) ? $d['cta_html'] : '',
            'image_alt'        => isset($d['image_alt']) ? sanitize_text_field($d['image_alt']) : '',
            'image_prompt'     => isset($d['image_prompt']) ? sanitize_textarea_field($d['image_prompt']) : '',
            'meta_title'       => isset($d['meta_title']) ? sanitize_text_field($d['meta_title']) : '',
            'meta_description' => isset($d['meta_description']) ? sanitize_text_field($d['meta_description']) : '',
            'profile'          => $built['key'],
            'model'            => $res['model'],
            'usage'            => $res['usage'],
        );
        $payload['post_content'] = self::assemble($payload);
        $payload['issues']       = self::validate($payload, $built['profile']);
        $payload['cost']         = ACV_API::cost($res['model'], $res['usage']);
        $payload['words']        = self::word_count($payload['post_content']);

        // Log.
        ACV_Log::add($post_id, $built['key'], $res['model'], $res['usage'], $payload['cost'],
            empty($payload['issues']) ? 'ok' : 'warn');

        return $payload;
    }

    /** Ghép content_html + FAQ + CTA thành post_content. */
    public static function assemble($payload)
    {
        $html = trim($payload['content_html']);
        if (!empty($payload['faq'])) {
            $html .= "\n<h2>Câu hỏi thường gặp</h2>\n";
            foreach ($payload['faq'] as $f) {
                if (empty($f['q']) || empty($f['a'])) continue;
                $html .= '<h3>' . esc_html($f['q']) . "</h3>\n<p>" . esc_html($f['a']) . "</p>\n";
            }
        }
        if (!empty($payload['cta_html'])) {
            $html .= "\n" . trim($payload['cta_html']);
        }
        return trim($html);
    }

    public static function validate($payload, $profile)
    {
        $issues = array();
        $html   = $payload['post_content'];
        if ($html === '') { $issues[] = 'Nội dung rỗng'; return $issues; }
        if (strpos($html, '<h2') === false) $issues[] = 'Thiếu thẻ <h2>';
        // Bài viết: CTA mềm có thể không kèm hotline → không bắt buộc.
        if (($payload['profile'] ?? '') !== 'post') {
            $hotline = ACV_Settings::get('hotline');
            $hot_digits = preg_replace('/\D/', '', $hotline);
            if ($hot_digits && strpos(preg_replace('/\D/', '', $html), $hot_digits) === false) $issues[] = 'Thiếu hotline';
        }
        if (preg_match('/\[(brand|ngành|nganh|tên|ten)\]/iu', $html)) $issues[] = 'Còn placeholder';
        $wc = self::word_count($html);
        if ($wc < $profile['wmin'] - 40) $issues[] = "Quá ngắn ($wc từ < {$profile['wmin']})";
        if ($wc > $profile['wmax'] + 80) $issues[] = "Quá dài ($wc từ > {$profile['wmax']})";
        return $issues;
    }

    public static function word_count($html)
    {
        $txt = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($html)));
        if ($txt === '') return 0;
        return count(preg_split('/\s+/u', $txt));
    }

    /**
     * Áp dụng payload vào post. $fields = mảng trường được chọn.
     * @return array{ok:bool,msg:string}
     */
    public static function apply($post_id, $payload, $fields)
    {
        $post = get_post($post_id);
        if (!$post) return array('ok' => false, 'msg' => 'Không tìm thấy bài.');

        // Backup trước khi ghi.
        update_post_meta($post_id, self::META_BACKUP, array(
            'post_content' => $post->post_content,
            'excerpt'      => $post->post_excerpt,
            'rm_title'     => get_post_meta($post_id, 'rank_math_title', true),
            'rm_desc'      => get_post_meta($post_id, 'rank_math_description', true),
            'faq'          => get_post_meta($post_id, self::META_FAQ, true),
            'time'         => current_time('mysql'),
        ));

        $fields = (array) $fields;
        $done   = array();

        if (in_array('post_content', $fields, true) && $payload['post_content'] !== '') {
            wp_update_post(array('ID' => $post_id, 'post_content' => wp_kses_post($payload['post_content'])));
            update_post_meta($post_id, self::META_FAQ, $payload['faq']); // cho schema
            $done[] = 'nội dung';
        }
        if (in_array('rank_math_title', $fields, true) && $payload['meta_title'] !== '') {
            update_post_meta($post_id, 'rank_math_title', $payload['meta_title']);
            $done[] = 'title SEO';
        }
        if (in_array('rank_math_description', $fields, true) && $payload['meta_description'] !== '') {
            update_post_meta($post_id, 'rank_math_description', $payload['meta_description']);
            $done[] = 'meta desc';
        }
        if (in_array('image_alt', $fields, true) && $payload['image_alt'] !== '') {
            $thumb = get_post_thumbnail_id($post_id);
            if ($thumb) {
                update_post_meta($thumb, '_wp_attachment_image_alt', $payload['image_alt']);
                $done[] = 'alt ảnh';
            }
        }
        if (in_array('excerpt', $fields, true) && $payload['answer_summary'] !== '') {
            wp_update_post(array('ID' => $post_id, 'post_excerpt' => $payload['answer_summary']));
            $done[] = 'tóm tắt';
        }

        clean_post_cache($post_id);
        return array('ok' => true, 'msg' => 'Đã ghi: ' . (implode(', ', $done) ?: 'không có trường nào'));
    }

    /** Hoàn tác về bản backup. */
    public static function revert($post_id)
    {
        $b = get_post_meta($post_id, self::META_BACKUP, true);
        if (!$b || !is_array($b)) return array('ok' => false, 'msg' => 'Không có bản backup.');
        wp_update_post(array('ID' => $post_id, 'post_content' => $b['post_content'], 'post_excerpt' => $b['excerpt']));
        update_post_meta($post_id, 'rank_math_title', $b['rm_title']);
        update_post_meta($post_id, 'rank_math_description', $b['rm_desc']);
        update_post_meta($post_id, self::META_FAQ, $b['faq']);
        clean_post_cache($post_id);
        return array('ok' => true, 'msg' => 'Đã hoàn tác về bản ' . esc_html($b['time']));
    }
}
