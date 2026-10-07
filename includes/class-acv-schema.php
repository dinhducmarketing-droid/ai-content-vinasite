<?php
if (!defined('ABSPATH')) exit;

/**
 * Xuất FAQPage schema cho AI Overviews.
 * Ưu tiên đẩy qua Rank Math (gộp vào @graph, không trùng); nếu không có Rank Math thì tự in ở wp_head.
 */
class ACV_Schema
{
    public static function init()
    {
        // Rank Math: gộp vào JSON-LD graph có sẵn.
        add_filter('rank_math/json_ld', array(__CLASS__, 'rank_math'), 99, 2);
        // Fallback khi không có Rank Math.
        add_action('wp_head', array(__CLASS__, 'fallback'), 20);
    }

    private static function faq_items($post_id)
    {
        $faq = get_post_meta($post_id, ACV_Generator::META_FAQ, true);
        if (!$faq || !is_array($faq)) return array();
        $items = array();
        foreach ($faq as $f) {
            if (empty($f['q']) || empty($f['a'])) continue;
            $items[] = array(
                '@type'          => 'Question',
                'name'           => wp_strip_all_tags($f['q']),
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => wp_strip_all_tags($f['a'])),
            );
        }
        return $items;
    }

    public static function rank_math($data, $jsonld)
    {
        if (!is_singular()) return $data;
        $items = self::faq_items(get_the_ID());
        if ($items) {
            $data['acv_faqpage'] = array(
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => $items,
            );
        }
        return $data;
    }

    public static function fallback()
    {
        if (class_exists('RankMath')) return; // Rank Math đã xử lý
        if (!is_singular()) return;
        $items = self::faq_items(get_the_ID());
        if (!$items) return;
        $schema = array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items);
        echo "\n<script type=\"application/ld+json\">" . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
    }
}
