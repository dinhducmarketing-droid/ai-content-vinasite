<?php
/**
 * Plugin Name: AI Content Vinasite
 * Plugin URI: https://github.com/dinhducmarketing-droid/ai-content-vinasite
 * Description: Sinh nội dung SEO + tối ưu Google AI Overviews (GEO) cho sản phẩm & kho mẫu bằng Claude API. Answer-first, H2 câu hỏi, FAQ + schema (Rank Math), CTA, alt ảnh, tạo ảnh minh hoạ khớp bài qua fal.ai.
 * Version: 1.1.2
 * Author: VinaSite Việt Nam
 * Author URI: https://vinasite.com.vn/
 * Update URI: https://github.com/dinhducmarketing-droid/ai-content-vinasite
 * Text Domain: ai-content-vinasite
 */

if (!defined('ABSPATH')) exit;

define('ACV_VER', '1.1.2');
define('ACV_FILE', __FILE__);
define('ACV_DIR', plugin_dir_path(__FILE__));
define('ACV_URL', plugin_dir_url(__FILE__));

require_once ACV_DIR . 'includes/class-acv-settings.php';
require_once ACV_DIR . 'includes/class-acv-log.php';
require_once ACV_DIR . 'includes/class-acv-api.php';
require_once ACV_DIR . 'includes/class-acv-prompt.php';
require_once ACV_DIR . 'includes/class-acv-generator.php';
require_once ACV_DIR . 'includes/class-acv-schema.php';
require_once ACV_DIR . 'includes/class-acv-image.php';
require_once ACV_DIR . 'includes/class-acv-metabox.php';

register_activation_hook(__FILE__, array('ACV_Log', 'install'));

// Tự cập nhật từ GitHub (repo Public → không cần token), giống theme VinaSite.
require_once ACV_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';
$acv_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/dinhducmarketing-droid/ai-content-vinasite/',
    __FILE__,
    'ai-content-vinasite'
);
$acv_update_checker->setBranch('main');

add_action('plugins_loaded', function () {
    ACV_Settings::init();
    ACV_Metabox::init();
    ACV_Schema::init();
});

// Enqueue admin assets trên màn hình sửa bài + trang settings.
add_action('admin_enqueue_scripts', function ($hook) {
    $on_edit = in_array($hook, array('post.php', 'post-new.php'), true);
    $on_settings = (strpos((string) $hook, 'ai-content-vinasite') !== false);
    if (!$on_edit && !$on_settings) return;
    if ($on_edit && !ACV_Settings::profile_for_post_type(get_post_type())) return;

    wp_enqueue_style('acv-admin', ACV_URL . 'assets/admin.css', array(), ACV_VER);
    wp_enqueue_script('acv-admin', ACV_URL . 'assets/admin.js', array('jquery'), ACV_VER, true);
    wp_localize_script('acv-admin', 'ACV', array(
        'nonce' => wp_create_nonce('acv_nonce'),
    ));
});

// Link "Cài đặt" ở trang Plugins.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=ai-content-vinasite');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Cài đặt', 'ai-content-vinasite') . '</a>');
    return $links;
});

// Cảnh báo nếu chưa nhập API key.
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (ACV_Settings::get('api_key')) return;
    $url = admin_url('admin.php?page=ai-content-vinasite');
    echo '<div class="notice notice-warning"><p><strong>AI Content Vinasite:</strong> chưa nhập Claude API key. '
        . '<a href="' . esc_url($url) . '">Nhập tại đây</a> để bắt đầu sinh nội dung.</p></div>';
});
