<?php
if (!defined('ABSPATH')) exit;

/**
 * Meta box trên trang sửa + các AJAX: preview / apply / revert / test key.
 */
class ACV_Metabox
{
    public static function init()
    {
        add_action('add_meta_boxes', array(__CLASS__, 'register'));
        add_action('wp_ajax_acv_preview', array(__CLASS__, 'ajax_preview'));
        add_action('wp_ajax_acv_apply', array(__CLASS__, 'ajax_apply'));
        add_action('wp_ajax_acv_revert', array(__CLASS__, 'ajax_revert'));
        add_action('wp_ajax_acv_test_key', array(__CLASS__, 'ajax_test'));
        add_action('wp_ajax_acv_test_image', array(__CLASS__, 'ajax_test_image'));
        add_action('wp_ajax_acv_gen_image', array(__CLASS__, 'ajax_gen_image'));
    }

    public static function register()
    {
        foreach (ACV_Settings::get('profiles') as $p) {
            foreach ((array) $p['post_types'] as $pt) {
                add_meta_box('acv-box', 'AI Content Vinasite', array(__CLASS__, 'render'), $pt, 'side', 'high');
            }
        }
    }

    public static function render($post)
    {
        $key   = ACV_Settings::profile_for_post_type($post->post_type);
        $prof  = ACV_Settings::get('profiles')[$key];
        $has_backup = (bool) get_post_meta($post->ID, ACV_Generator::META_BACKUP, true);
        $fields = array(
            'post_content'          => 'Nội dung bài',
            'rank_math_title'       => 'Title SEO',
            'rank_math_description' => 'Meta description',
            'image_alt'             => 'Alt ảnh đại diện',
            'excerpt'               => 'Tóm tắt (excerpt)',
        );
        $default_on = array('post_content', 'rank_math_title', 'rank_math_description', 'image_alt');
        ?>
        <div id="acv-metabox" data-post="<?php echo esc_attr($post->ID); ?>">
            <p>
                <label><strong>Model</strong></label><br>
                <select id="acv-model" style="width:100%">
                    <?php foreach (ACV_Settings::$models as $mid => $mlabel) : ?>
                        <option value="<?php echo esc_attr($mid); ?>" <?php selected($prof['model'], $mid); ?>><?php echo esc_html($mlabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p><strong>Điền vào:</strong></p>
            <?php foreach ($fields as $fk => $flabel) : ?>
                <label style="display:block">
                    <input type="checkbox" class="acv-field" value="<?php echo esc_attr($fk); ?>" <?php checked(in_array($fk, $default_on, true)); ?>>
                    <?php echo esc_html($flabel); ?>
                </label>
            <?php endforeach; ?>
            <p>
                <button type="button" class="button button-primary" id="acv-generate">⚡ Tạo nội dung (AI)</button>
            </p>
            <div id="acv-status" class="acv-status"></div>
            <div id="acv-meta" class="acv-meta"></div>
            <div id="acv-preview" class="acv-preview" style="display:none"></div>
            <p>
                <button type="button" class="button button-primary" id="acv-apply" style="display:none">✓ Áp dụng</button>
                <button type="button" class="button" id="acv-revert" style="<?php echo $has_backup ? '' : 'display:none'; ?>">↩ Hoàn tác</button>
            </p>

            <?php if (ACV_Settings::get('image_enable')) : ?>
            <hr>
            <div id="acv-img-section">
                <p><strong>🖼 Ảnh đại diện</strong></p>
                <label style="display:block;font-size:12px;color:#646970">Prompt ảnh (AI gợi ý theo bài, sửa được):</label>
                <textarea id="acv-img-prompt" rows="3" style="width:100%" placeholder="Tạo nội dung trước để AI gợi ý prompt ảnh — hoặc tự nhập mô tả ảnh (tiếng Anh tốt nhất)."></textarea>
                <p>
                    <button type="button" class="button button-secondary" id="acv-gen-image">🖼 Tạo ảnh &amp; đặt làm đại diện</button>
                </p>
                <div id="acv-img-status" class="acv-status"></div>
                <div id="acv-img-result" class="acv-img-result"></div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function check($post_id)
    {
        check_ajax_referer('acv_nonce', '_n');
        $post_id = (int) $post_id;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Không đủ quyền.');
        }
        return $post_id;
    }

    public static function ajax_preview()
    {
        $post_id = self::check($_POST['post'] ?? 0);
        $model   = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        if (!isset(ACV_Settings::$models[$model])) $model = '';

        $payload = ACV_Generator::generate($post_id, $model);
        if (is_wp_error($payload)) wp_send_json_error($payload->get_error_message());

        set_transient('acv_prev_' . $post_id . '_' . get_current_user_id(), $payload, 30 * MINUTE_IN_SECONDS);

        $issues = $payload['issues']
            ? '<span class="acv-warn">⚠ ' . esc_html(implode(' · ', $payload['issues'])) . '</span>'
            : '<span class="acv-ok">✓ Đạt chuẩn</span>';
        $meta = '<ul>'
            . '<li>' . $issues . '</li>'
            . '<li>Số từ: <strong>' . (int) $payload['words'] . '</strong></li>'
            . '<li>Model: ' . esc_html($payload['model']) . ' · ~$' . number_format($payload['cost'], 5) . '</li>'
            . '<li><strong>Title:</strong> ' . esc_html($payload['meta_title']) . '</li>'
            . '<li><strong>Meta:</strong> ' . esc_html($payload['meta_description']) . '</li>'
            . '<li><strong>Alt:</strong> ' . esc_html($payload['image_alt']) . '</li>'
            . '</ul>';

        wp_send_json_success(array(
            'preview'      => wp_kses_post($payload['post_content']),
            'meta'         => $meta,
            'image_prompt' => isset($payload['image_prompt']) ? $payload['image_prompt'] : '',
        ));
    }

    /** Test kết nối nguồn tạo ảnh (fal.ai / OpenAI) với key đang gõ ở form. */
    public static function ajax_test_image()
    {
        check_ajax_referer('acv_nonce', '_n');
        if (!current_user_can('manage_options')) wp_send_json_error('Không đủ quyền.');
        $provider = (isset($_POST['provider']) && $_POST['provider'] === 'openai') ? 'openai' : 'fal';
        $key      = isset($_POST['key']) ? trim(sanitize_text_field(wp_unslash($_POST['key']))) : '';
        $model    = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        $endpoint = isset($_POST['endpoint']) ? esc_url_raw(trim(wp_unslash($_POST['endpoint']))) : '';
        $r = ACV_Image::test_key($provider, $key, $model, $endpoint);
        is_wp_error($r) ? wp_send_json_error($r->get_error_message()) : wp_send_json_success('Kết nối OK, key dùng được.');
    }

    /** Tạo ảnh đại diện từ prompt (fal.ai/OpenAI) → set featured image. */
    public static function ajax_gen_image()
    {
        $post_id = self::check($_POST['post'] ?? 0);
        if (!ACV_Settings::get('image_enable')) wp_send_json_error('Tính năng tạo ảnh đang tắt.');

        $prompt = isset($_POST['prompt']) ? sanitize_textarea_field(wp_unslash($_POST['prompt'])) : '';
        $alt    = '';
        // Lấy prompt / alt từ bản xem trước nếu ô prompt để trống.
        $payload = get_transient('acv_prev_' . $post_id . '_' . get_current_user_id());
        if (is_array($payload)) {
            if ($prompt === '' && !empty($payload['image_prompt'])) $prompt = $payload['image_prompt'];
            if (!empty($payload['image_alt'])) $alt = $payload['image_alt'];
        }
        if ($prompt === '') wp_send_json_error('Chưa có prompt ảnh. Tạo nội dung trước, hoặc tự nhập mô tả.');

        $r = ACV_Image::generate_and_attach($post_id, $prompt, $alt);
        if (is_wp_error($r)) wp_send_json_error($r->get_error_message());

        wp_send_json_success(array(
            'url'  => $r['url'],
            'cost' => number_format($r['cost'], 4),
        ));
    }

    public static function ajax_apply()
    {
        $post_id = self::check($_POST['post'] ?? 0);
        $payload = get_transient('acv_prev_' . $post_id . '_' . get_current_user_id());
        if (!$payload || !is_array($payload)) wp_send_json_error('Bản xem trước đã hết hạn, hãy tạo lại.');

        $fields = isset($_POST['fields']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['fields'])) : array();
        $r = ACV_Generator::apply($post_id, $payload, $fields);
        delete_transient('acv_prev_' . $post_id . '_' . get_current_user_id());
        $r['ok'] ? wp_send_json_success($r['msg']) : wp_send_json_error($r['msg']);
    }

    public static function ajax_revert()
    {
        $post_id = self::check($_POST['post'] ?? 0);
        $r = ACV_Generator::revert($post_id);
        $r['ok'] ? wp_send_json_success($r['msg']) : wp_send_json_error($r['msg']);
    }

    public static function ajax_test()
    {
        check_ajax_referer('acv_nonce', '_n');
        if (!current_user_can('manage_options')) wp_send_json_error('Không đủ quyền.');
        $key = isset($_POST['key']) ? trim(sanitize_text_field(wp_unslash($_POST['key']))) : '';
        $r = ACV_API::test($key);
        is_wp_error($r) ? wp_send_json_error($r->get_error_message()) : wp_send_json_success('Key hợp lệ.');
    }
}
