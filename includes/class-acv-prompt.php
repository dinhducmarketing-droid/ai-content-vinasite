<?php
if (!defined('ABSPATH')) exit;

/**
 * Dựng system + user prompt từ template + dữ liệu post, và JSON schema cho output.
 */
class ACV_Prompt
{
    /** @return array{system:string,user:string,profile:array,key:string} */
    public static function build($post)
    {
        $post = get_post($post);
        $key  = ACV_Settings::profile_for_post_type($post->post_type);
        $s    = ACV_Settings::all();
        $p    = $s['profiles'][$key];

        $title   = html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8');
        $nganh   = self::nganh($post, $key);
        $danhmuc = self::danh_muc($post, $key);
        $price   = self::price($post, $key);
        $attrs   = self::attributes($post, $key);

        $sys = strtr($p['system'], array(
            '{brand_voice}' => $s['brand_voice'],
            '{hotline}'     => $s['hotline'],
            '{wmin}'        => $p['wmin'],
            '{wmax}'        => $p['wmax'],
        ));

        // Khi bật tạo ảnh: yêu cầu mô hình trả thêm image_prompt KHỚP NGỮ CẢNH bài.
        // Chèn động (không lưu trong profile) để mọi site đang chạy đều có, kể cả
        // profile đã lưu từ trước.
        if (!empty($s['image_enable'])) {
            $sys .= "\n- image_prompt: MỘT prompt TIẾNG ANH mô tả ảnh minh hoạ khớp ĐÚNG chủ đề bài (suy ra từ tiêu đề + ngành + nội dung bạn vừa viết). "
                . "Tả cảnh/chủ thể cụ thể, bối cảnh, góc máy và ánh sáng; phong cách ảnh chụp thật, chuyên nghiệp, phù hợp làm ảnh đại diện NGANG (16:9). "
                . "TUYỆT ĐỐI không có chữ/text/typography/logo/watermark trong ảnh, không khung viền. Chỉ 1–3 câu súc tích.";
        }
        $usr = strtr($p['user'], array(
            '{title}'      => $title,
            '{nganh}'      => $nganh,
            '{danh_muc}'   => $danhmuc,
            '{price}'      => $price,
            '{attributes}' => $attrs,
        ));

        return array('system' => $sys, 'user' => $usr, 'profile' => $p, 'key' => $key);
    }

    /** JSON schema cho structured output. */
    public static function schema($enable_faq = true, $enable_image = false)
    {
        $props = array(
            'answer_summary'   => array('type' => 'string'),
            'content_html'     => array('type' => 'string'),
            'cta_html'         => array('type' => 'string'),
            'image_alt'        => array('type' => 'string'),
            'meta_title'       => array('type' => 'string'),
            'meta_description' => array('type' => 'string'),
        );
        $required = array('answer_summary', 'content_html', 'cta_html', 'image_alt', 'meta_title', 'meta_description');

        if ($enable_image) {
            $props['image_prompt'] = array('type' => 'string');
            $required[] = 'image_prompt';
        }

        if ($enable_faq) {
            $props['faq'] = array(
                'type'  => 'array',
                'items' => array(
                    'type'                 => 'object',
                    'properties'           => array('q' => array('type' => 'string'), 'a' => array('type' => 'string')),
                    'required'             => array('q', 'a'),
                    'additionalProperties' => false,
                ),
            );
            $required[] = 'faq';
        }

        return array(
            'type'                 => 'object',
            'properties'           => $props,
            'required'             => $required,
            'additionalProperties' => false,
        );
    }

    /** Taxonomy chứa "ngành/danh mục" theo loại nội dung. */
    private static function tax_for($key)
    {
        if ($key === 'product') return 'product_cat';
        if ($key === 'post')    return 'category';
        return 'theme_cat'; // kho_mau
    }

    private static function nganh($post, $key)
    {
        $tax = self::tax_for($key);
        $terms = wp_get_post_terms($post->ID, $tax);
        if (is_wp_error($terms) || !$terms) return '';
        $cat = '';
        foreach ($terms as $t) { if ($t->parent) { $cat = $t->name; break; } }
        if (!$cat) $cat = $terms[0]->name;
        $n = html_entity_decode($cat, ENT_QUOTES, 'UTF-8');
        $n = preg_replace('/^Mẫu website\s*/iu', '', $n);
        $n = str_replace(array(' & ', '&amp;', ' - ', ' – ', '-', '–'), array(' và ', ' và ', ' ', ' ', ' ', ' '), $n);
        $n = preg_replace('/\s+/u', ' ', trim($n));
        return mb_strtolower($n, 'UTF-8');
    }

    private static function danh_muc($post, $key)
    {
        $tax = self::tax_for($key);
        $terms = wp_get_post_terms($post->ID, $tax, array('fields' => 'names'));
        if (is_wp_error($terms) || !$terms) return '';
        return implode(', ', array_map(function ($n) {
            return html_entity_decode($n, ENT_QUOTES, 'UTF-8');
        }, $terms));
    }

    private static function price($post, $key)
    {
        if ($key !== 'product' || !function_exists('wc_get_product')) return '';
        $prod = wc_get_product($post->ID);
        if (!$prod) return '';
        $price = $prod->get_price();
        return $price !== '' ? number_format((float) $price, 0, ',', '.') . ' đ' : '';
    }

    private static function attributes($post, $key)
    {
        if ($key !== 'product' || !function_exists('wc_get_product')) return '';
        $prod = wc_get_product($post->ID);
        if (!$prod) return '';
        $out = array();
        foreach ($prod->get_attributes() as $name => $attr) {
            $label = wc_attribute_label(is_object($attr) ? $attr->get_name() : $name);
            $vals  = is_object($attr) ? $prod->get_attribute($attr->get_name()) : '';
            if ($vals) $out[] = $label . ': ' . $vals;
        }
        return implode('; ', $out);
    }
}
