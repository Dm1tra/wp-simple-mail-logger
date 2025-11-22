<?php
/**
 * Plugin Name: WP Simple Mail Logger
 * Plugin URI: https://supports.by
 * Description: Logs all outgoing emails in WordPress, provides test email sending and admin panel log viewer.
 * Version: 1.0.0
 * Author: Arthur Dzmitrakou
 * Author URI: https://supports.by
 * License: GPLv2 or later
 * Text Domain: wp-simple-mail-logger
 */

if (!defined('ABSPATH')) exit;

class WP_Simple_Mail_Logger {
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'simple_mail_log';
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_menu', [$this, 'admin_page']);
        add_action('wp_mail_succeeded', [$this, 'mail_ok'], 10, 1);
        add_action('wp_mail_failed', [$this, 'mail_fail'], 10, 1);
    }

    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table = $this->table;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            time DATETIME NOT NULL,
            to_email LONGTEXT NOT NULL,
            subject LONGTEXT NOT NULL,
            message LONGTEXT NOT NULL,
            headers LONGTEXT NULL,
            attachments LONGTEXT NULL,
            status VARCHAR(20) NOT NULL,
            error LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY time (time)
        ) $charset;";
        dbDelta($sql);
    }

    public function admin_page() {
        add_management_page('Mail Log', 'Mail Log', 'manage_options', 'wp-simple-mail-logger', [$this, 'render']);
    }

    public function mail_ok($data) {
        $this->log('succeeded', $data, '');
    }

    public function mail_fail($error) {
        $data = $error->get_error_data('wp_mail_failed');
        if (!is_array($data)) $data = [];
        $msg = $error->get_error_message('wp_mail_failed');
        if (!$msg) $msg = $error->get_error_message();
        $this->log('failed', $data, $msg);
    }

    private function log($status, $data, $err) {
        global $wpdb;
        if ($this->missing()) return;

        $to = isset($data['to']) ? $this->to_string($data['to']) : '';
        $subject = isset($data['subject']) ? $data['subject'] : '';
        $message = isset($data['message']) ? $data['message'] : '';
        $headers = isset($data['headers']) ? $this->to_string($data['headers']) : '';
        $attachments = isset($data['attachments']) ? $this->to_string($data['attachments']) : '';

        $wpdb->insert(
            $this->table,
            [
                'time' => current_time('mysql'),
                'to_email' => $to,
                'subject' => $subject,
                'message' => $message,
                'headers' => $headers,
                'attachments' => $attachments,
                'status' => $status,
                'error' => $err
            ],
            ['%s','%s','%s','%s','%s','%s','%s','%s']
        );
    }

    private function missing() {
        global $wpdb;
        static $c = null;
        if ($c === null) {
            $r = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->table));
            $c = ($r !== $this->table);
        }
        return $c;
    }

    private function to_string($v) {
        if (is_array($v)) return implode(', ', $v);
        if (is_object($v)) return wp_json_encode($v);
        return (string)$v;
    }

    public function render() {
        if (!current_user_can('manage_options')) wp_die('Access denied');

        global $wpdb;
        $msg = '';

        if (isset($_POST['sml_send_test'])) {
            check_admin_referer('sml_send_test', 'sml_nonce');
            $email = sanitize_email($_POST['sml_test_email']);
            if (!is_email($email)) {
                $msg = '<div class="notice notice-error"><p>Invalid email.</p></div>';
            } else {
                $subject = 'Test email from WP Simple Mail Logger';
                $body = "This is a test email.\nSent at: " . current_time('mysql') . "\nSite: " . home_url('/');
                if (wp_mail($email, $subject, $body)) {
                    $msg = '<div class="notice notice-success"><p>Test email sent.</p></div>';
                } else {
                    $msg = '<div class="notice notice-error"><p>Failed to send test email.</p></div>';
                }
            }
        }

        if (isset($_POST['sml_clear_log'])) {
            check_admin_referer('sml_clear_log', 'sml_clear_nonce');
            if (!$this->missing()) {
                $wpdb->query("TRUNCATE TABLE {$this->table}");
                $msg = '<div class="notice notice-success"><p>Log cleared.</p></div>';
            }
        }

        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        if (!$this->missing()) {
            if ($status === 'succeeded' || $status === 'failed') {
                $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE status = %s ORDER BY time DESC, id DESC LIMIT 200", $status), ARRAY_A);
            } else {
                $logs = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY time DESC, id DESC LIMIT 200", ARRAY_A);
            }
        } else {
            $logs = [];
        }

        ?>
        <div class="wrap">
            <h1>WP Simple Mail Logger</h1>
            <?php echo $msg; ?>

            <h2>Send Test Email</h2>
            <form method="post">
                <?php wp_nonce_field('sml_send_test', 'sml_nonce'); ?>
                <p><input type="email" name="sml_test_email" class="regular-text" placeholder="your@email.com" required></p>
                <p><input type="submit" name="sml_send_test" class="button button-primary" value="Send Test Email"></p>
            </form>

            <hr>

            <h2>Mail Log</h2>

            <?php if ($this->missing()): ?>
                <div class="notice notice-error"><p>Mail log table does not exist. Reactivate the plugin.</p></div>
            <?php else: ?>

                <form method="get">
                    <input type="hidden" name="page" value="wp-simple-mail-logger">
                    <select name="status">
                        <option value="">All</option>
                        <option value="succeeded" <?php selected($status,'succeeded'); ?>>Succeeded</option>
                        <option value="failed" <?php selected($status,'failed'); ?>>Failed</option>
                    </select>
                    <input type="submit" class="button" value="Filter">
                </form>

                <form method="post" onsubmit="return confirm('Clear log?');" style="margin-top:10px;">
                    <?php wp_nonce_field('sml_clear_log', 'sml_clear_nonce'); ?>
                    <input type="submit" name="sml_clear_log" class="button" value="Clear Log">
                </form>

                <?php if (empty($logs)): ?>
                    <p>No log entries found.</p>
                <?php else: ?>
                    <style>
                        .sml-table td { vertical-align: top; font-size: 12px; line-height: 1.4; }
                        .sml-ok { color: #008000; font-weight: 600; }
                        .sml-fail { color: #b32d2e; font-weight: 600; }
                        .sml-msg { max-width: 600px; white-space:pre-wrap; word-break:break-word; }
                    </style>

                    <table class="widefat striped sml-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Time</th>
                                <th>To</th>
                                <th>Subject</th>
                                <th>Message</th>
                                <th>Status</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $r): ?>
                                <tr>
                                    <td><?php echo $r['id']; ?></td>
                                    <td><?php echo esc_html($r['time']); ?></td>
                                    <td><?php echo nl2br(esc_html($r['to_email'])); ?></td>
                                    <td><?php echo esc_html($r['subject']); ?></td>
                                    <td class="sml-msg"><?php echo nl2br(esc_html($r['message'])); ?></td>
                                    <td>
                                        <?php if ($r['status']==='failed'): ?>
                                            <span class="sml-fail">Failed</span>
                                        <?php else: ?>
                                            <span class="sml-ok">Succeeded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo nl2br(esc_html($r['error'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

new WP_Simple_Mail_Logger();
