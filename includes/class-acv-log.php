<?php
if (!defined('ABSPATH')) exit;

/**
 * Nhật ký sinh nội dung (token + chi phí).
 */
class ACV_Log
{
    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'acv_log';
    }

    public static function install()
    {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            profile VARCHAR(40) NOT NULL DEFAULT '',
            model VARCHAR(60) NOT NULL DEFAULT '',
            tokens_in INT NOT NULL DEFAULT 0,
            tokens_out INT NOT NULL DEFAULT 0,
            cost_usd DECIMAL(10,5) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function add($post_id, $profile, $model, $usage, $cost, $status)
    {
        global $wpdb;
        $wpdb->insert(self::table(), array(
            'post_id'    => (int) $post_id,
            'profile'    => $profile,
            'model'      => $model,
            'tokens_in'  => (int) (($usage['input_tokens'] ?? 0) + ($usage['cache_read_input_tokens'] ?? 0) + ($usage['cache_creation_input_tokens'] ?? 0)),
            'tokens_out' => (int) ($usage['output_tokens'] ?? 0),
            'cost_usd'   => round((float) $cost, 5),
            'status'     => $status,
            'created_at' => current_time('mysql'),
        ));
    }

    public static function render_page()
    {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 100");
        $total = (float) $wpdb->get_var("SELECT SUM(cost_usd) FROM $table");
        $cnt   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        ?>
        <div class="wrap">
            <h1>Nhật ký AI Content</h1>
            <p>Tổng lượt sinh: <strong><?php echo esc_html($cnt); ?></strong> · Tổng chi phí ước tính: <strong>$<?php echo esc_html(number_format($total, 4)); ?></strong></p>
            <table class="widefat striped">
                <thead><tr><th>Thời gian</th><th>Bài</th><th>Loại</th><th>Model</th><th>Token in/out</th><th>Chi phí</th><th>Trạng thái</th></tr></thead>
                <tbody>
                <?php if (!$rows) : ?>
                    <tr><td colspan="7">Chưa có dữ liệu.</td></tr>
                <?php else : foreach ($rows as $r) : ?>
                    <tr>
                        <td><?php echo esc_html($r->created_at); ?></td>
                        <td><a href="<?php echo esc_url(get_edit_post_link($r->post_id)); ?>">#<?php echo esc_html($r->post_id); ?> <?php echo esc_html(get_the_title($r->post_id)); ?></a></td>
                        <td><?php echo esc_html($r->profile); ?></td>
                        <td><?php echo esc_html($r->model); ?></td>
                        <td><?php echo esc_html($r->tokens_in . ' / ' . $r->tokens_out); ?></td>
                        <td>$<?php echo esc_html(number_format($r->cost_usd, 5)); ?></td>
                        <td><?php echo esc_html($r->status); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
