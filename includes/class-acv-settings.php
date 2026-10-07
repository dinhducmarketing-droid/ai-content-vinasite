<?php
if (!defined('ABSPATH')) exit;

/**
 * Quản lý cấu hình + trang Settings.
 * Lưu tất cả trong 1 option 'acv_settings'.
 */
class ACV_Settings
{
    const OPTION = 'acv_settings';

    public static $models = array(
        'claude-haiku-4-5'  => 'Claude Haiku 4.5 (rẻ nhất — $1/$5 /1M)',
        'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (cân bằng — $3/$15 /1M)',
        'claude-opus-4-8'   => 'Claude Opus 4.8 (mạnh nhất — $5/$25 /1M)',
    );

    // Giá [input, output] USD / 1 triệu token.
    public static $prices = array(
        'claude-haiku-4-5'  => array(1.0, 5.0),
        'claude-sonnet-4-6' => array(3.0, 15.0),
        'claude-opus-4-8'   => array(5.0, 25.0),
    );

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
    }

    public static function defaults()
    {
        $sys_kho = "Bạn là chuyên gia content SEO của Vinasite — công ty thiết kế website tại Việt Nam. "
            . "Viết nội dung mô tả MẪU WEBSITE, tối ưu cho Google AI Overviews và SEO.\n"
            . "QUY TẮC:\n"
            . "- Văn phong: {brand_voice}. Tiếng Việt tự nhiên, câu ngắn, khẳng định, giàu dữ kiện (chuẩn SEO, responsive đa thiết bị, tốc độ tải nhanh, bàn giao nhanh).\n"
            . "- content_html: MỞ ĐẦU bằng 1 đoạn TRẢ LỜI TRỰC TIẾP 2–3 câu (mẫu website [ngành] là gì, dành cho ai). Sau đó các mục H2 DẠNG CÂU HỎI: 'Đặc điểm nổi bật của mẫu website [ngành]?', 'Mẫu website này phù hợp với ai?', 'Ưu điểm kỹ thuật'. Dùng <p>, <h2>, <ul><li>. KHÔNG chèn FAQ, KHÔNG chèn CTA, KHÔNG dùng H1. Tổng {wmin}–{wmax} từ.\n"
            . "- faq: 4–6 câu hỏi người dùng hay tìm (giá thiết kế, thời gian bàn giao, có tùy biến không, có hỗ trợ SEO/responsive không, có bàn giao mã nguồn không). Mỗi câu trả lời 40–60 từ, súc tích, trích dẫn được.\n"
            . "- cta_html: 1 đoạn <p> kêu gọi liên hệ Vinasite, kèm hotline {hotline}. Biến tấu tự nhiên, không sáo rỗng.\n"
            . "- image_alt: alt text chuẩn SEO cho ảnh đại diện, chứa ngành, < 125 ký tự.\n"
            . "- meta_title ≤ 60 ký tự, meta_description 140–160 ký tự, có hotline {hotline}.\n"
            . "- CHỈ trả về JSON đúng schema, không thêm lời dẫn.";
        $usr_kho = "Mẫu website: {title}\nNgành: {nganh}\nDanh mục: {danh_muc}\nViết nội dung cho mẫu này.";

        $sys_prod = "Bạn là chuyên gia content SEO của Vinasite. Viết MÔ TẢ SẢN PHẨM tối ưu Google AI Overviews và SEO.\n"
            . "QUY TẮC:\n"
            . "- Văn phong: {brand_voice}. Câu ngắn, khẳng định, giàu dữ kiện, nêu lợi ích cụ thể.\n"
            . "- content_html: mở đầu bằng 1 đoạn trả lời trực tiếp (sản phẩm là gì, cho ai), sau đó H2 'Đặc điểm nổi bật', 'Vì sao nên chọn'. Dùng <p>, <h2>, <ul><li>. Không FAQ/CTA/H1. Tổng {wmin}–{wmax} từ.\n"
            . "- faq: 3–5 câu hỏi (giá, bảo hành, giao hàng, tư vấn), mỗi đáp 40–60 từ.\n"
            . "- cta_html: kêu gọi mua/liên hệ kèm hotline {hotline}.\n"
            . "- image_alt < 125 ký tự. meta_title ≤ 60, meta_description 140–160 ký tự.\n"
            . "- CHỈ trả về JSON đúng schema.";
        $usr_prod = "Sản phẩm: {title}\nDanh mục: {danh_muc}\nGiá: {price}\nThuộc tính: {attributes}\nViết mô tả cho sản phẩm này.";

        return array(
            'api_key'     => '',
            'hotline'     => '08 8686 3838',
            'brand_voice' => 'chuyên nghiệp, uy tín, đáng tin cậy',
            'enable_faq'  => 1,
            'profiles'    => array(
                'kho_mau' => array(
                    'label'      => 'Kho mẫu (kho_mau)',
                    'post_types' => array('kho_mau'),
                    'model'      => 'claude-sonnet-4-6',
                    'wmin'       => 250,
                    'wmax'       => 350,
                    'system'     => $sys_kho,
                    'user'       => $usr_kho,
                ),
                'product' => array(
                    'label'      => 'Sản phẩm (product)',
                    'post_types' => array('product'),
                    'model'      => 'claude-haiku-4-5',
                    'wmin'       => 120,
                    'wmax'       => 220,
                    'system'     => $sys_prod,
                    'user'       => $usr_prod,
                ),
            ),
        );
    }

    public static function all()
    {
        $saved = get_option(self::OPTION, array());
        $def = self::defaults();
        $out = array_merge($def, is_array($saved) ? $saved : array());
        // merge profiles theo từng key để không mất default khi lưu thiếu.
        $out['profiles'] = isset($saved['profiles']) && is_array($saved['profiles'])
            ? $saved['profiles'] + $def['profiles']
            : $def['profiles'];
        return $out;
    }

    public static function get($key = null)
    {
        $all = self::all();
        if ($key === null) return $all;
        return isset($all[$key]) ? $all[$key] : null;
    }

    /** Trả về key profile ('kho_mau'/'product') khớp post type, hoặc false. */
    public static function profile_for_post_type($pt)
    {
        if (!$pt) return false;
        foreach (self::get('profiles') as $key => $p) {
            if (in_array($pt, (array) $p['post_types'], true)) return $key;
        }
        return false;
    }

    public static function menu()
    {
        add_menu_page('AI Content Vinasite', 'AI Content', 'manage_options',
            'ai-content-vinasite', array(__CLASS__, 'render'), 'dashicons-edit-large', 58);
        add_submenu_page('ai-content-vinasite', 'Nhật ký', 'Nhật ký', 'manage_options',
            'ai-content-vinasite-log', array('ACV_Log', 'render_page'));
    }

    public static function register()
    {
        register_setting('acv_group', self::OPTION, array(__CLASS__, 'sanitize'));
    }

    public static function sanitize($input)
    {
        $out = self::defaults();
        $out['api_key']     = isset($input['api_key']) ? trim(sanitize_text_field($input['api_key'])) : '';
        $out['hotline']     = isset($input['hotline']) ? sanitize_text_field($input['hotline']) : $out['hotline'];
        $out['brand_voice'] = isset($input['brand_voice']) ? sanitize_text_field($input['brand_voice']) : $out['brand_voice'];
        $out['enable_faq']  = !empty($input['enable_faq']) ? 1 : 0;

        if (!empty($input['profiles']) && is_array($input['profiles'])) {
            foreach ($out['profiles'] as $key => &$p) {
                if (empty($input['profiles'][$key])) continue;
                $in = $input['profiles'][$key];
                if (!empty($in['model']) && isset(self::$models[$in['model']])) $p['model'] = $in['model'];
                $p['wmin']   = max(40, (int) ($in['wmin'] ?? $p['wmin']));
                $p['wmax']   = max($p['wmin'], (int) ($in['wmax'] ?? $p['wmax']));
                $p['system'] = isset($in['system']) ? wp_kses_post(wp_unslash($in['system'])) : $p['system'];
                $p['user']   = isset($in['user']) ? wp_kses_post(wp_unslash($in['user'])) : $p['user'];
            }
            unset($p);
        }
        return $out;
    }

    public static function render()
    {
        if (!current_user_can('manage_options')) return;
        $s = self::all();
        ?>
        <div class="wrap acv-wrap">
            <h1>AI Content Vinasite</h1>
            <p>Sinh nội dung SEO + tối ưu Google AI Overviews bằng Claude API. Mở 1 sản phẩm/mẫu → hộp "AI Content" bên phải → <em>Tạo nội dung</em> → xem trước → áp dụng (có backup &amp; hoàn tác).</p>
            <form method="post" action="options.php">
                <?php settings_fields('acv_group'); ?>
                <h2>Kết nối API</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Claude API key</label></th>
                        <td>
                            <input type="password" name="acv_settings[api_key]" value="<?php echo esc_attr($s['api_key']); ?>" class="regular-text" autocomplete="off" placeholder="sk-ant-...">
                            <button type="button" class="button" id="acv-test-key">Test kết nối</button>
                            <span id="acv-test-result"></span>
                            <p class="description">Lấy key tại console.anthropic.com. Key lưu trong DB site của bạn.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Hotline</label></th>
                        <td><input type="text" name="acv_settings[hotline]" value="<?php echo esc_attr($s['hotline']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>Giọng văn brand</label></th>
                        <td><input type="text" name="acv_settings[brand_voice]" value="<?php echo esc_attr($s['brand_voice']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>FAQ + Schema (AI Overviews)</label></th>
                        <td><label><input type="checkbox" name="acv_settings[enable_faq]" value="1" <?php checked($s['enable_faq'], 1); ?>> Sinh FAQ &amp; xuất schema FAQPage qua Rank Math</label></td>
                    </tr>
                </table>

                <?php foreach ($s['profiles'] as $key => $p) : ?>
                    <h2>Loại nội dung: <?php echo esc_html($p['label']); ?> <small>(post type: <?php echo esc_html(implode(', ', $p['post_types'])); ?>)</small></h2>
                    <table class="form-table">
                        <tr>
                            <th><label>Model</label></th>
                            <td>
                                <select name="acv_settings[profiles][<?php echo esc_attr($key); ?>][model]">
                                    <?php foreach (self::$models as $mid => $mlabel) : ?>
                                        <option value="<?php echo esc_attr($mid); ?>" <?php selected($p['model'], $mid); ?>><?php echo esc_html($mlabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Số từ (min – max)</label></th>
                            <td>
                                <input type="number" min="40" name="acv_settings[profiles][<?php echo esc_attr($key); ?>][wmin]" value="<?php echo esc_attr($p['wmin']); ?>" style="width:90px"> –
                                <input type="number" min="40" name="acv_settings[profiles][<?php echo esc_attr($key); ?>][wmax]" value="<?php echo esc_attr($p['wmax']); ?>" style="width:90px">
                            </td>
                        </tr>
                        <tr>
                            <th><label>System prompt</label></th>
                            <td><textarea name="acv_settings[profiles][<?php echo esc_attr($key); ?>][system]" rows="10" class="large-text code"><?php echo esc_textarea($p['system']); ?></textarea>
                                <p class="description">Biến: <code>{brand_voice}</code> <code>{hotline}</code> <code>{wmin}</code> <code>{wmax}</code></p></td>
                        </tr>
                        <tr>
                            <th><label>User prompt</label></th>
                            <td><textarea name="acv_settings[profiles][<?php echo esc_attr($key); ?>][user]" rows="4" class="large-text code"><?php echo esc_textarea($p['user']); ?></textarea>
                                <p class="description">Biến: <code>{title}</code> <code>{nganh}</code> <code>{danh_muc}</code> <code>{price}</code> <code>{attributes}</code></p></td>
                        </tr>
                    </table>
                <?php endforeach; ?>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
