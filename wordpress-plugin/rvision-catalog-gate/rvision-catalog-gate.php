<?php
/**
 * Plugin Name: Rvision Catalog Gate
 * Description: Adds verified catalog download, lead capture, email verification, and CSV export for R vision.
 * Version: 1.0.1
 * Author: R vision
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Rvision_Catalog_Gate
{
    private const OPTION_KEY = 'rvision_catalog_gate_options';
    private const COOKIE_NAME = 'rvision_catalog_verified';
    private const DB_VERSION = '1.0.0';

    public static function init(): void
    {
        $plugin = new self();
        register_activation_hook(__FILE__, [$plugin, 'activate']);
        register_deactivation_hook(__FILE__, [$plugin, 'deactivate']);

        add_action('init', [$plugin, 'register_routes']);
        add_filter('query_vars', [$plugin, 'query_vars']);
        add_action('template_redirect', [$plugin, 'handle_frontend_route']);
        add_action('admin_menu', [$plugin, 'admin_menu']);
        add_action('admin_init', [$plugin, 'register_settings']);
        add_action('admin_post_rvision_export_catalog_leads', [$plugin, 'export_csv']);
        add_action('phpmailer_init', [$plugin, 'configure_smtp']);
    }

    public function activate(): void
    {
        $this->create_table();
        $this->register_routes();
        flush_rewrite_rules();

        if (!get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, $this->default_options());
        }
    }

    public function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function register_routes(): void
    {
        add_rewrite_rule('^catalog-download/?$', 'index.php?rvision_catalog_action=download', 'top');
        add_rewrite_rule('^catalog-register/?$', 'index.php?rvision_catalog_action=register', 'top');
        add_rewrite_rule('^catalog-login/?$', 'index.php?rvision_catalog_action=register', 'top');
        add_rewrite_rule('^catalog-verify/?$', 'index.php?rvision_catalog_action=verify', 'top');
    }

    public function query_vars(array $vars): array
    {
        $vars[] = 'rvision_catalog_action';
        return $vars;
    }

    public function handle_frontend_route(): void
    {
        $action = get_query_var('rvision_catalog_action');
        if (!$action) {
            return;
        }

        if ($action === 'download') {
            $this->handle_download();
        }

        if ($action === 'register') {
            $this->handle_register();
        }

        if ($action === 'verify') {
            $this->handle_verify();
        }
    }

    public function admin_menu(): void
    {
        add_menu_page(
            'Rvision 下载名单',
            'Rvision 下载名单',
            'manage_options',
            'rvision-catalog-leads',
            [$this, 'render_leads_page'],
            'dashicons-download',
            58
        );

        add_submenu_page(
            'rvision-catalog-leads',
            '下载设置',
            '下载设置',
            'manage_options',
            'rvision-catalog-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'rvision_catalog_gate',
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_options'],
                'default' => $this->default_options(),
            ]
        );
    }

    public function sanitize_options($input): array
    {
        $current = $this->options();
        $input = is_array($input) ? $input : [];

        $smtp_secure = $input['smtp_secure'] ?? $current['smtp_secure'];
        if (!in_array($smtp_secure, ['ssl', 'tls'], true)) {
            $smtp_secure = 'ssl';
        }

        $options = [
            'from_email' => sanitize_email($input['from_email'] ?? $current['from_email']),
            'from_name' => sanitize_text_field($input['from_name'] ?? $current['from_name']),
            'smtp_host' => sanitize_text_field($input['smtp_host'] ?? $current['smtp_host']),
            'smtp_port' => max(1, (int)($input['smtp_port'] ?? $current['smtp_port'])),
            'smtp_secure' => $smtp_secure,
            'smtp_user' => sanitize_text_field($input['smtp_user'] ?? $current['smtp_user']),
            'smtp_pass' => $current['smtp_pass'],
            'valid_days' => max(1, (int)($input['valid_days'] ?? $current['valid_days'])),
            'token_hours' => max(1, (int)($input['token_hours'] ?? $current['token_hours'])),
            'catalog_path' => sanitize_text_field($input['catalog_path'] ?? $current['catalog_path']),
        ];

        if (!empty($input['smtp_pass'])) {
            $options['smtp_pass'] = sanitize_text_field($input['smtp_pass']);
        }

        return $options;
    }

    public function configure_smtp($phpmailer): void
    {
        $options = $this->options();
        if (empty($options['smtp_host']) || empty($options['smtp_user']) || empty($options['smtp_pass'])) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $options['smtp_host'];
        $phpmailer->Port = (int)$options['smtp_port'];
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = $options['smtp_user'];
        $phpmailer->Password = $options['smtp_pass'];
        $phpmailer->SMTPSecure = $options['smtp_secure'];
        $phpmailer->From = $options['from_email'];
        $phpmailer->FromName = $options['from_name'];
        $phpmailer->CharSet = 'UTF-8';
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->options();
        $catalog_file = $this->catalog_file();
        ?>
        <div class="wrap">
            <h1>Rvision 下载设置</h1>
            <p>SMTP 授权码不会显示在页面里，也不会被提交到代码仓库。</p>
            <form method="post" action="options.php">
                <?php settings_fields('rvision_catalog_gate'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="rvision_from_email">发件邮箱</label></th>
                        <td><input id="rvision_from_email" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[from_email]" value="<?php echo esc_attr($options['from_email']); ?>" type="email" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_from_name">发件人名称</label></th>
                        <td><input id="rvision_from_name" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[from_name]" value="<?php echo esc_attr($options['from_name']); ?>" type="text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_host">SMTP 主机</label></th>
                        <td><input id="rvision_smtp_host" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_host]" value="<?php echo esc_attr($options['smtp_host']); ?>" type="text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_port">SMTP 端口</label></th>
                        <td><input id="rvision_smtp_port" class="small-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_port]" value="<?php echo esc_attr($options['smtp_port']); ?>" type="number" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_secure">加密方式</label></th>
                        <td>
                            <select id="rvision_smtp_secure" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_secure]">
                                <option value="ssl" <?php selected($options['smtp_secure'], 'ssl'); ?>>SSL</option>
                                <option value="tls" <?php selected($options['smtp_secure'], 'tls'); ?>>TLS</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_user">SMTP 用户名</label></th>
                        <td><input id="rvision_smtp_user" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_user]" value="<?php echo esc_attr($options['smtp_user']); ?>" type="text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_pass">SMTP 授权码</label></th>
                        <td>
                            <input id="rvision_smtp_pass" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_pass]" value="" type="password" autocomplete="new-password" />
                            <p class="description"><?php echo empty($options['smtp_pass']) ? '尚未保存授权码。' : '已保存授权码。如需更换，请在此输入新授权码。'; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_valid_days">验证有效期</label></th>
                        <td><input id="rvision_valid_days" class="small-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[valid_days]" value="<?php echo esc_attr($options['valid_days']); ?>" type="number" /> 天</td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_token_hours">邮件链接有效期</label></th>
                        <td><input id="rvision_token_hours" class="small-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[token_hours]" value="<?php echo esc_attr($options['token_hours']); ?>" type="number" /> 小时</td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_catalog_path">目录文件路径</label></th>
                        <td>
                            <input id="rvision_catalog_path" class="large-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[catalog_path]" value="<?php echo esc_attr($options['catalog_path']); ?>" type="text" />
                            <p class="description">当前文件：<?php echo esc_html($catalog_file); ?> <?php echo is_readable($catalog_file) ? '可读取' : '不可读取'; ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('保存设置'); ?>
            </form>
        </div>
        <?php
    }

    public function render_leads_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table = $this->table_name();
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 300");
        $export_url = wp_nonce_url(admin_url('admin-post.php?action=rvision_export_catalog_leads'), 'rvision_export_catalog_leads');
        ?>
        <div class="wrap">
            <h1>Rvision 下载名单</h1>
            <p><a class="button button-primary" href="<?php echo esc_url($export_url); ?>">导出 CSV</a></p>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>公司</th>
                        <th>邮箱</th>
                        <th>手机号</th>
                        <th>姓名</th>
                        <th>部门</th>
                        <th>需求</th>
                        <th>状态</th>
                        <th>注册时间</th>
                        <th>验证时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="9">暂无记录</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row->company); ?></td>
                            <td><?php echo esc_html($row->email); ?></td>
                            <td><?php echo esc_html($row->phone); ?></td>
                            <td><?php echo esc_html(trim($row->last_name . $row->first_name)); ?></td>
                            <td><?php echo esc_html($row->department); ?></td>
                            <td><?php echo esc_html($row->need_type); ?></td>
                            <td><?php echo $row->verified_at ? '已验证' : '待验证'; ?></td>
                            <td><?php echo esc_html($row->created_at); ?></td>
                            <td><?php echo esc_html($row->verified_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function export_csv(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        check_admin_referer('rvision_export_catalog_leads');

        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table_name()} ORDER BY created_at DESC", ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=rvision-catalog-leads-' . gmdate('Ymd-His') . '.csv');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['公司', '邮箱', '手机号', '姓', '名', '部门', '需求', '是否验证', '注册时间', '验证时间', 'IP']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['company'],
                $row['email'],
                $row['phone'],
                $row['last_name'],
                $row['first_name'],
                $row['department'],
                $row['need_type'],
                $row['verified_at'] ? '已验证' : '待验证',
                $row['created_at'],
                $row['verified_at'],
                $row['ip_address'],
            ]);
        }
        fclose($out);
        exit;
    }

    private function handle_download(): void
    {
        if (!$this->has_download_access()) {
            wp_safe_redirect(home_url('/catalog-register/'));
            exit;
        }

        $file = $this->catalog_file();
        if (!is_readable($file)) {
            wp_die('目录文件暂不可用，请联系销售工程师。');
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="Rvision-YK-C-Catalog.pdf"');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    private function handle_register(): void
    {
        $errors = [];
        $success = false;
        $values = [
            'company' => '',
            'email' => '',
            'phone' => '',
            'last_name' => '',
            'first_name' => '',
            'department' => '',
            'need_type' => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['rvision_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rvision_nonce'])), 'rvision_catalog_register')) {
                $errors[] = '表单已过期，请刷新后重试。';
            }

            foreach ($values as $key => $_) {
                $values[$key] = sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
            }
            $values['email'] = sanitize_email($values['email']);

            foreach (['company' => '公司', 'email' => '邮箱', 'phone' => '手机号', 'last_name' => '姓'] as $key => $label) {
                if ($values[$key] === '') {
                    $errors[] = $label . '为必填项。';
                }
            }
            if ($values['email'] && !is_email($values['email'])) {
                $errors[] = '邮箱格式不正确。';
            }
            if ($values['need_type'] && !in_array($values['need_type'], $this->need_options(), true)) {
                $errors[] = '需求选项不正确。';
            }

            if (!$errors) {
                $success = $this->save_lead_and_send_email($values);
                if (!$success) {
                    $errors[] = '验证邮件发送失败，请稍后再试或直接联系我们。';
                }
            }
        }

        $this->render_register_page($values, $errors, $success);
        exit;
    }

    private function handle_verify(): void
    {
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if (!$token) {
            wp_die('验证链接无效。');
        }

        global $wpdb;
        $hash = $this->hash_token($token);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name()} WHERE token_hash = %s AND token_expires_at >= %s",
                $hash,
                current_time('mysql')
            )
        );

        if (!$row) {
            wp_die('验证链接无效或已过期，请重新提交下载申请。');
        }

        $wpdb->update(
            $this->table_name(),
            ['verified_at' => current_time('mysql'), 'token_hash' => null],
            ['id' => (int)$row->id],
            ['%s', '%s'],
            ['%d']
        );

        $this->set_verified_cookie($row->email);
        wp_safe_redirect(home_url('/catalog-download/'));
        exit;
    }

    private function save_lead_and_send_email(array $values): bool
    {
        global $wpdb;
        $token = wp_generate_password(40, false, false);
        $token_hash = $this->hash_token($token);
        $options = $this->options();
        $expires = gmdate('Y-m-d H:i:s', time() + ((int)$options['token_hours'] * HOUR_IN_SECONDS));

        $data = [
            'company' => $values['company'],
            'email' => $values['email'],
            'phone' => $values['phone'],
            'last_name' => $values['last_name'],
            'first_name' => $values['first_name'],
            'department' => $values['department'],
            'need_type' => $values['need_type'],
            'token_hash' => $token_hash,
            'token_expires_at' => get_date_from_gmt($expires),
            'verified_at' => null,
            'ip_address' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            'user_agent' => substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500),
            'created_at' => current_time('mysql'),
        ];

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_name()} WHERE email = %s", $values['email']));
        if ($existing) {
            $wpdb->update($this->table_name(), $data, ['id' => (int)$existing]);
        } else {
            $wpdb->insert($this->table_name(), $data);
        }

        $verify_url = add_query_arg('token', rawurlencode($token), home_url('/catalog-verify/'));
        $subject = '请验证邮箱并下载 YK-C 产品目录';
        $message = $this->email_template($values, $verify_url);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        return wp_mail($values['email'], $subject, $message, $headers);
    }

    private function render_register_page(array $values, array $errors, bool $success): void
    {
        $has_access = $this->has_download_access();
        $status_label = $has_access ? '已登录' : ($success ? '待邮箱验证' : '未登录');
        $status_class = $has_access ? 'is-ok' : ($success ? 'is-pending' : 'is-muted');
        status_header(200);
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>下载 YK-C 产品目录 | R vision</title>
            <style>
                *{box-sizing:border-box}
                body{margin:0;background:#f4f7f8;color:#14171a;font-family:Arial,"PingFang SC","Microsoft YaHei",sans-serif}
                a{color:inherit}
                .rv-wrap{min-height:100vh;display:grid;place-items:center;padding:32px 18px}
                .rv-panel{width:min(760px,100%);background:#fff;border:1px solid #d9e0e6;border-radius:8px;box-shadow:0 24px 70px rgba(16,31,45,.14);overflow:hidden}
                .rv-head{padding:28px 32px;color:#fff;background:#14171a}
                .rv-head-top{display:flex;align-items:center;justify-content:space-between;gap:18px}
                .rv-brand{color:#fff;text-decoration:none;font-weight:800}
                .rv-head p{margin:10px 0 0;color:rgba(255,255,255,.72);line-height:1.7}
                .rv-body{padding:28px 32px}
                .rv-status{display:inline-flex;align-items:center;gap:8px;min-height:34px;padding:6px 8px 6px 10px;border:1px solid rgba(255,255,255,.18);border-radius:8px;background:rgba(255,255,255,.08);font-size:13px;white-space:nowrap}
                .rv-status-dot{width:8px;height:8px;border-radius:999px;background:#9aa4ad}
                .rv-status.is-ok .rv-status-dot{background:#33d08f}
                .rv-status.is-pending .rv-status-dot{background:#f0b429}
                .rv-status a{display:inline-flex;align-items:center;height:24px;padding:0 9px;border-radius:6px;background:#fff;color:#14171a;text-decoration:none;font-weight:800}
                .rv-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
                label{display:flex;flex-direction:column;gap:7px;min-width:0;font-weight:800}
                .rv-label-text{display:flex;align-items:center;gap:4px;line-height:1.35}
                input,select{display:block;width:100%;height:44px;border:1px solid #cfd8df;border-radius:8px;background:#fff;padding:0 12px;font:inherit}
                .rv-full{grid-column:1/-1}
                .rv-actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:18px}
                .rv-form-actions{grid-column:1/-1;margin-top:2px}
                .rv-button,.rv-link-button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;border-radius:8px;padding:0 18px;font-weight:800;text-decoration:none}
                .rv-button{border:0;background:#d33838;color:#fff;cursor:pointer}
                .rv-link-button{border:1px solid #cfd8df;background:#fff;color:#14171a}
                .rv-errors,.rv-success{margin-bottom:18px;padding:14px 16px;border-radius:8px;line-height:1.7}
                .rv-errors{background:#fff0f0;color:#9f1f1f}
                .rv-success{background:#edf9f4;color:#166548}
                .rv-required{color:#d33838}
                @media (max-width:640px){.rv-head-top{align-items:flex-start;flex-direction:column}.rv-grid{grid-template-columns:1fr}.rv-head,.rv-body{padding:24px 20px}.rv-actions{align-items:stretch;flex-direction:column}.rv-button,.rv-link-button{width:100%}}
            </style>
        </head>
        <body>
            <main class="rv-wrap">
                <section class="rv-panel">
                    <div class="rv-head">
                        <div class="rv-head-top">
                            <a class="rv-brand" href="<?php echo esc_url(home_url('/')); ?>">R vision 睿视智能</a>
                            <div class="rv-status <?php echo esc_attr($status_class); ?>">
                                <span class="rv-status-dot" aria-hidden="true"></span>
                                <span><?php echo esc_html($status_label); ?></span>
                                <?php if ($has_access): ?>
                                    <a href="<?php echo esc_url(home_url('/catalog-download/')); ?>">下载目录</a>
                                <?php elseif ($success): ?>
                                    <a href="<?php echo esc_url(home_url('/catalog-download/')); ?>">验证后下载</a>
                                <?php else: ?>
                                    <a href="#catalog-form">登录/验证</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <h1>下载 YK-C 产品目录</h1>
                        <p><?php echo $has_access ? '您已完成验证，可直接下载产品目录。' : '请填写信息并完成邮箱验证。验证成功后，90 天内可直接下载产品目录。'; ?></p>
                    </div>
                    <div class="rv-body">
                        <?php if ($has_access): ?>
                            <div class="rv-success">当前状态为已登录，可直接下载 YK-C 产品目录。</div>
                            <div class="rv-actions">
                                <a class="rv-button" href="<?php echo esc_url(home_url('/catalog-download/')); ?>">下载目录</a>
                                <a class="rv-link-button" href="<?php echo esc_url(home_url('/')); ?>">返回首页</a>
                            </div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="rv-success">验证邮件已发送，请打开邮箱点击验证链接。验证完成后将自动下载目录；如浏览器未自动下载，可返回此页点击下载目录。</div>
                            <div class="rv-actions">
                                <a class="rv-button" href="<?php echo esc_url(home_url('/catalog-download/')); ?>">验证后下载目录</a>
                                <a class="rv-link-button" href="<?php echo esc_url(home_url('/')); ?>">返回首页</a>
                            </div>
                        <?php endif; ?>
                        <?php if ($errors): ?>
                            <div class="rv-errors">
                                <?php foreach ($errors as $error): ?>
                                    <div><?php echo esc_html($error); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!$success && !$has_access): ?>
                            <form id="catalog-form" method="post" action="<?php echo esc_url(home_url('/catalog-register/')); ?>">
                                <?php wp_nonce_field('rvision_catalog_register', 'rvision_nonce'); ?>
                                <div class="rv-grid">
                                    <label class="rv-full"><span class="rv-label-text">公司 <span class="rv-required">*</span></span><input name="company" value="<?php echo esc_attr($values['company']); ?>" autocomplete="organization" required></label>
                                    <label><span class="rv-label-text">邮箱 <span class="rv-required">*</span></span><input name="email" type="email" value="<?php echo esc_attr($values['email']); ?>" autocomplete="email" required></label>
                                    <label><span class="rv-label-text">手机号 <span class="rv-required">*</span></span><input name="phone" type="tel" value="<?php echo esc_attr($values['phone']); ?>" autocomplete="tel" required></label>
                                    <label><span class="rv-label-text">姓 <span class="rv-required">*</span></span><input name="last_name" value="<?php echo esc_attr($values['last_name']); ?>" autocomplete="family-name" required></label>
                                    <label><span class="rv-label-text">名</span><input name="first_name" value="<?php echo esc_attr($values['first_name']); ?>" autocomplete="given-name"></label>
                                    <label><span class="rv-label-text">部门</span><input name="department" value="<?php echo esc_attr($values['department']); ?>" autocomplete="organization-title"></label>
                                    <label><span class="rv-label-text">需求</span><select name="need_type"><option value="">请选择</option><?php foreach ($this->need_options() as $need): ?><option value="<?php echo esc_attr($need); ?>" <?php selected($values['need_type'], $need); ?>><?php echo esc_html($need); ?></option><?php endforeach; ?></select></label>
                                    <div class="rv-actions rv-form-actions">
                                        <button class="rv-button" type="submit">发送验证邮件</button>
                                        <a class="rv-link-button" href="<?php echo esc_url(home_url('/')); ?>">返回首页</a>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>
            </main>
        </body>
        </html>
        <?php
    }

    private function has_download_access(): bool
    {
        return is_user_logged_in() || $this->verified_cookie();
    }

    private function email_template(array $values, string $verify_url): string
    {
        $name = trim($values['last_name'] . $values['first_name']);
        return '<p>' . esc_html($name ?: '您好') . '，</p>'
            . '<p>感谢关注 R vision YK-C 外夹式超声波流量计。请点击下面的链接完成邮箱验证并下载产品目录：</p>'
            . '<p><a href="' . esc_url($verify_url) . '" style="display:inline-block;background:#d33838;color:#fff;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:bold;">验证邮箱并下载目录</a></p>'
            . '<p>如果按钮无法打开，请复制以下链接到浏览器：</p>'
            . '<p>' . esc_html($verify_url) . '</p>'
            . '<p>R vision 睿视智能</p>';
    }

    private function verified_cookie(): bool
    {
        $cookie = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME] ?? ''));
        if (!$cookie) {
            return false;
        }
        $decoded = base64_decode($cookie, true);
        if (!$decoded) {
            return false;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 3) {
            return false;
        }
        [$email, $expires, $sig] = $parts;
        if ((int)$expires < time()) {
            return false;
        }
        return hash_equals($this->cookie_signature($email, (int)$expires), $sig);
    }

    private function set_verified_cookie(string $email): void
    {
        $days = (int)$this->options()['valid_days'];
        $expires = time() + ($days * DAY_IN_SECONDS);
        $value = base64_encode($email . '|' . $expires . '|' . $this->cookie_signature($email, $expires));
        setcookie(self::COOKIE_NAME, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function cookie_signature(string $email, int $expires): string
    {
        return hash_hmac('sha256', $email . '|' . $expires, wp_salt('auth'));
    }

    private function hash_token(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }

    private function catalog_file(): string
    {
        $path = $this->options()['catalog_path'];
        if ($path === '') {
            return plugin_dir_path(__FILE__) . 'assets/catalog.pdf';
        }
        return $path;
    }

    private function need_options(): array
    {
        return ['询价', '技术咨询', '样品测试'];
    }

    private function options(): array
    {
        return wp_parse_args(get_option(self::OPTION_KEY, []), $this->default_options());
    }

    private function default_options(): array
    {
        return [
            'from_email' => 'JackMa@rvisiontek.com',
            'from_name' => 'R vision 睿视智能',
            'smtp_host' => 'smtp.qq.com',
            'smtp_port' => 465,
            'smtp_secure' => 'ssl',
            'smtp_user' => 'JackMa@rvisiontek.com',
            'smtp_pass' => '',
            'valid_days' => 90,
            'token_hours' => 24,
            'catalog_path' => '',
        ];
    }

    private function create_table(): void
    {
        global $wpdb;
        $table = $this->table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company varchar(255) NOT NULL,
            email varchar(190) NOT NULL,
            phone varchar(80) NOT NULL,
            last_name varchar(80) NOT NULL,
            first_name varchar(80) NOT NULL DEFAULT '',
            department varchar(120) NOT NULL DEFAULT '',
            need_type varchar(80) NOT NULL DEFAULT '',
            token_hash varchar(128) DEFAULT NULL,
            token_expires_at datetime DEFAULT NULL,
            verified_at datetime DEFAULT NULL,
            ip_address varchar(80) NOT NULL DEFAULT '',
            user_agent varchar(500) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            KEY verified_at (verified_at),
            KEY token_hash (token_hash)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('rvision_catalog_gate_db_version', self::DB_VERSION);
    }

    private function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rvision_catalog_leads';
    }
}

Rvision_Catalog_Gate::init();
