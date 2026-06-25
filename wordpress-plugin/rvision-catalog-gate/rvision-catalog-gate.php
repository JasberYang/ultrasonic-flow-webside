<?php
/**
 * Plugin Name: Rvision Catalog Gate
 * Description: Adds YK-C product homepage, member login, email verification, and email-based catalog delivery for R vision.
 * Version: 1.1.7
 * Author: R vision
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Rvision_Catalog_Gate
{
    private const OPTION_KEY = 'rvision_catalog_gate_options';
    private const DB_VERSION_KEY = 'rvision_catalog_gate_db_version';
    private const DB_VERSION = '1.1.0';
    private const SESSION_COOKIE = 'rvision_member_session';
    private const SESSION_DAYS = 90;
    private const CODE_TTL_MINUTES = 10;

    public static function init(): void
    {
        $plugin = new self();
        register_activation_hook(__FILE__, [$plugin, 'activate']);
        register_deactivation_hook(__FILE__, [$plugin, 'deactivate']);

        add_action('plugins_loaded', [$plugin, 'maybe_upgrade']);
        add_action('init', [$plugin, 'register_routes']);
        add_filter('query_vars', [$plugin, 'query_vars']);
        add_action('template_redirect', [$plugin, 'redirect_www_host'], -1);
        add_action('template_redirect', [$plugin, 'render_product_home'], 0);
        add_action('template_redirect', [$plugin, 'handle_frontend_route']);
        add_action('admin_menu', [$plugin, 'admin_menu']);
        add_action('admin_init', [$plugin, 'register_settings']);
        add_action('phpmailer_init', [$plugin, 'configure_smtp']);

        add_action('wp_ajax_nopriv_rvision_member_register', [$plugin, 'ajax_register']);
        add_action('wp_ajax_rvision_member_register', [$plugin, 'ajax_register']);
        add_action('wp_ajax_nopriv_rvision_member_verify_registration', [$plugin, 'ajax_verify_registration']);
        add_action('wp_ajax_rvision_member_verify_registration', [$plugin, 'ajax_verify_registration']);
        add_action('wp_ajax_nopriv_rvision_member_login', [$plugin, 'ajax_login']);
        add_action('wp_ajax_rvision_member_login', [$plugin, 'ajax_login']);
        add_action('wp_ajax_nopriv_rvision_member_logout', [$plugin, 'ajax_logout']);
        add_action('wp_ajax_rvision_member_logout', [$plugin, 'ajax_logout']);
        add_action('wp_ajax_nopriv_rvision_member_me', [$plugin, 'ajax_me']);
        add_action('wp_ajax_rvision_member_me', [$plugin, 'ajax_me']);
        add_action('wp_ajax_nopriv_rvision_member_request_password_reset', [$plugin, 'ajax_request_password_reset']);
        add_action('wp_ajax_rvision_member_request_password_reset', [$plugin, 'ajax_request_password_reset']);
        add_action('wp_ajax_nopriv_rvision_member_reset_password', [$plugin, 'ajax_reset_password']);
        add_action('wp_ajax_rvision_member_reset_password', [$plugin, 'ajax_reset_password']);
        add_action('wp_ajax_nopriv_rvision_catalog_request', [$plugin, 'ajax_catalog_request']);
        add_action('wp_ajax_rvision_catalog_request', [$plugin, 'ajax_catalog_request']);
        add_action('wp_ajax_nopriv_rvision_member_nonce', [$plugin, 'ajax_nonce']);
        add_action('wp_ajax_rvision_member_nonce', [$plugin, 'ajax_nonce']);
    }

    public function activate(): void
    {
        $this->maybe_upgrade();
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

    public function maybe_upgrade(): void
    {
        if (!get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, $this->default_options());
        }

        if (get_option(self::DB_VERSION_KEY) === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $members = $this->members_table();
        $sessions = $this->sessions_table();
        $codes = $this->codes_table();
        $downloads = $this->downloads_table();

        dbDelta("CREATE TABLE {$members} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company varchar(191) NOT NULL,
            email varchar(191) NOT NULL,
            phone varchar(64) NOT NULL,
            last_name varchar(80) NOT NULL,
            first_name varchar(80) NOT NULL DEFAULT '',
            department varchar(120) NOT NULL DEFAULT '',
            demand varchar(40) NOT NULL DEFAULT '',
            password_hash varchar(255) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            email_verified_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            last_login_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$sessions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            member_id bigint(20) unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            user_agent varchar(255) NOT NULL DEFAULT '',
            ip varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY member_id (member_id),
            KEY expires_at (expires_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$codes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            member_id bigint(20) unsigned DEFAULT NULL,
            email varchar(191) NOT NULL,
            purpose varchar(24) NOT NULL,
            code_hash varchar(255) NOT NULL,
            expires_at datetime NOT NULL,
            consumed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY email_purpose (email, purpose),
            KEY expires_at (expires_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$downloads} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            member_id bigint(20) unsigned NOT NULL,
            email varchar(191) NOT NULL,
            downloaded_at datetime NOT NULL,
            user_agent varchar(255) NOT NULL DEFAULT '',
            ip varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY member_id (member_id),
            KEY downloaded_at (downloaded_at)
        ) {$charset_collate};");

        update_option(self::DB_VERSION_KEY, self::DB_VERSION);
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
            $path = trim((string)parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
            if ($path === 'catalog-download') {
                $action = 'download';
            } elseif (in_array($path, ['catalog-register', 'catalog-login', 'catalog-verify'], true)) {
                $action = 'register';
            }
        }

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
            'Rvision 会员管理',
            'Rvision 会员',
            'manage_options',
            'rvision-catalog-settings',
            [$this, 'render_settings_page'],
            'dashicons-groups',
            58
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
        $input = is_array($input) ? $input : [];
        $current = $this->options();
        $smtp_secure = sanitize_text_field($input['smtp_secure'] ?? 'ssl');
        if (!in_array($smtp_secure, ['ssl', 'tls', 'none'], true)) {
            $smtp_secure = 'ssl';
        }

        $smtp_password = (string)($input['smtp_password'] ?? '');
        if ($smtp_password === '') {
            $smtp_password = $current['smtp_password'];
        }

        return [
            'catalog_path' => sanitize_text_field($input['catalog_path'] ?? ''),
            'smtp_enabled' => empty($input['smtp_enabled']) ? '0' : '1',
            'smtp_host' => sanitize_text_field($input['smtp_host'] ?? 'smtp.qq.com'),
            'smtp_port' => max(1, (int)($input['smtp_port'] ?? 465)),
            'smtp_secure' => $smtp_secure,
            'smtp_username' => sanitize_text_field($input['smtp_username'] ?? ''),
            'smtp_password' => $smtp_password,
            'smtp_from_email' => sanitize_email($input['smtp_from_email'] ?? ''),
            'smtp_from_name' => sanitize_text_field($input['smtp_from_name'] ?? 'Rvision 睿视智能'),
        ];
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->options();
        $catalog_file = $this->catalog_file();
        $members = $this->admin_members();
        $downloads = $this->admin_downloads();
        ?>
        <div class="wrap">
            <h1>Rvision 会员管理</h1>
            <p>目录下载通过邮箱发送 PDF 附件。会员功能保留，验证码和目录邮件均通过下方 SMTP 配置发送。</p>
            <form method="post" action="options.php">
                <?php settings_fields('rvision_catalog_gate'); ?>
                <h2>目录文件</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="rvision_catalog_path">目录文件路径</label></th>
                        <td>
                            <input id="rvision_catalog_path" class="large-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[catalog_path]" value="<?php echo esc_attr($options['catalog_path']); ?>" type="text" />
                            <p class="description">当前文件：<?php echo esc_html($catalog_file); ?> <?php echo is_readable($catalog_file) ? '可读取' : '不可读取'; ?></p>
                        </td>
                    </tr>
                </table>
                <h2>SMTP 邮箱验证码</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">启用 SMTP</th>
                        <td>
                            <label>
                                <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_enabled]" value="1" type="checkbox" <?php checked($options['smtp_enabled'], '1'); ?> />
                                使用 SMTP 发送注册验证码、找回密码验证码和产品目录邮件
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_host">SMTP 服务器</label></th>
                        <td><input id="rvision_smtp_host" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_host]" value="<?php echo esc_attr($options['smtp_host']); ?>" type="text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_port">端口</label></th>
                        <td><input id="rvision_smtp_port" class="small-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_port]" value="<?php echo esc_attr($options['smtp_port']); ?>" type="number" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_secure">加密方式</label></th>
                        <td>
                            <select id="rvision_smtp_secure" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_secure]">
                                <option value="ssl" <?php selected($options['smtp_secure'], 'ssl'); ?>>SSL</option>
                                <option value="tls" <?php selected($options['smtp_secure'], 'tls'); ?>>TLS</option>
                                <option value="none" <?php selected($options['smtp_secure'], 'none'); ?>>无</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_username">邮箱账号</label></th>
                        <td><input id="rvision_smtp_username" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_username]" value="<?php echo esc_attr($options['smtp_username']); ?>" type="text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_password">邮箱授权码</label></th>
                        <td>
                            <input id="rvision_smtp_password" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_password]" value="" type="password" autocomplete="new-password" />
                            <p class="description">留空表示继续使用已保存的授权码。授权码保存在 WordPress 数据库，不写入插件代码。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_from_email">发件邮箱</label></th>
                        <td><input id="rvision_smtp_from_email" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_from_email]" value="<?php echo esc_attr($options['smtp_from_email']); ?>" type="email" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rvision_smtp_from_name">发件名称</label></th>
                        <td><input id="rvision_smtp_from_name" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[smtp_from_name]" value="<?php echo esc_attr($options['smtp_from_name']); ?>" type="text" /></td>
                    </tr>
                </table>
                <?php submit_button('保存设置'); ?>
            </form>

            <h2>会员列表</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>公司</th>
                        <th>姓名</th>
                        <th>邮箱</th>
                        <th>手机号</th>
                        <th>部门</th>
                        <th>需求</th>
                        <th>状态</th>
                        <th>注册时间</th>
                        <th>最近登录</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$members) : ?>
                        <tr><td colspan="9">暂无会员。</td></tr>
                    <?php else : ?>
                        <?php foreach ($members as $member) : ?>
                            <tr>
                                <td><?php echo esc_html($member->company); ?></td>
                                <td><?php echo esc_html($member->last_name . $member->first_name); ?></td>
                                <td><?php echo esc_html($member->email); ?></td>
                                <td><?php echo esc_html($member->phone); ?></td>
                                <td><?php echo esc_html($member->department); ?></td>
                                <td><?php echo esc_html($this->demand_label($member->demand)); ?></td>
                                <td><?php echo esc_html($member->status); ?></td>
                                <td><?php echo esc_html($member->created_at); ?></td>
                                <td><?php echo esc_html($member->last_login_at ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2>最近目录发送记录</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>邮箱</th>
                        <th>发送时间</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$downloads) : ?>
                        <tr><td colspan="3">暂无下载记录。</td></tr>
                    <?php else : ?>
                        <?php foreach ($downloads as $download) : ?>
                            <tr>
                                <td><?php echo esc_html($download->email); ?></td>
                                <td><?php echo esc_html($download->downloaded_at); ?></td>
                                <td><?php echo esc_html($download->ip); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function redirect_www_host(): void
    {
        $host = strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')));
        if ($host !== 'www.rvisiontek.com') {
            return;
        }

        $request_uri = wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        wp_safe_redirect('https://rvisiontek.com' . $request_uri, 301);
        exit;
    }

    public function render_product_home(): void
    {
        if (!is_front_page() && !is_home()) {
            return;
        }

        $site_dir = plugin_dir_path(__FILE__) . 'assets/site/';
        $html_file = $site_dir . 'index.html';
        $css_file = $site_dir . 'styles.css';
        $js_file = $site_dir . 'script.js';

        if (!is_readable($html_file) || !is_readable($css_file) || !is_readable($js_file)) {
            return;
        }

        $html = file_get_contents($html_file);
        $css = file_get_contents($css_file);
        $js = file_get_contents($js_file);
        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rvision_member'),
            'downloadUrl' => home_url('/catalog-download/'),
        ];

        $html = preg_replace('#<link\\s+rel=["\\\']stylesheet["\\\']\\s+href=["\\\']styles\\.css["\\\']\\s*/?>#i', '<style>' . $css . '</style>', $html, 1);
        $html = preg_replace('#</head>#i', '<script>window.RVISION_MEMBER=' . wp_json_encode($config) . ';</script></head>', $html, 1);
        $html = preg_replace('#<script\\s+src=["\\\']script\\.js["\\\']></script>#i', '<script>' . $js . '</script>', $html, 1);

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function handle_download(): void
    {
        wp_safe_redirect(home_url('/?catalog=request'));
        exit;
    }

    private function handle_register(): void
    {
        wp_safe_redirect(home_url('/?member=register'));
        exit;
    }

    private function handle_verify(): void
    {
        wp_safe_redirect(home_url('/?member=login'));
        exit;
    }

    public function ajax_register(): void
    {
        $this->require_ajax_nonce();

        global $wpdb;

        $company = $this->posted_text('company');
        $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
        $phone = $this->posted_text('phone');
        $last_name = $this->posted_text('last_name');
        $first_name = $this->posted_text('first_name');
        $department = $this->posted_text('department');
        $demand = sanitize_key(wp_unslash($_POST['demand'] ?? ''));
        $password = (string)wp_unslash($_POST['password'] ?? '');

        if ($company === '' || $email === '' || $phone === '' || $last_name === '' || $password === '') {
            wp_send_json_error(['message' => '请填写公司、邮箱、手机号、姓和密码。'], 400);
        }

        if (!is_email($email)) {
            wp_send_json_error(['message' => '邮箱格式不正确。'], 400);
        }

        if (strlen($password) < 8) {
            wp_send_json_error(['message' => '密码至少需要 8 位。'], 400);
        }

        if (!array_key_exists($demand, $this->demand_options())) {
            wp_send_json_error(['message' => '请选择有效需求类型。'], 400);
        }

        $existing = $this->get_member_by_email($email);
        $now = $this->now();
        $data = [
            'company' => $company,
            'email' => $email,
            'phone' => $phone,
            'last_name' => $last_name,
            'first_name' => $first_name,
            'department' => $department,
            'demand' => $demand,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 'pending',
            'updated_at' => $now,
        ];

        if ($existing && $existing->status === 'active') {
            wp_send_json_error(['message' => '该邮箱已注册，请直接登录或使用找回密码。'], 409);
        }

        if ($existing && $existing->status === 'disabled') {
            wp_send_json_error(['message' => '该账号暂不可用，请联系销售工程师。'], 403);
        }

        if ($existing) {
            $wpdb->update($this->members_table(), $data, ['id' => (int)$existing->id]);
            $member_id = (int)$existing->id;
        } else {
            $data['created_at'] = $now;
            $wpdb->insert($this->members_table(), $data);
            $member_id = (int)$wpdb->insert_id;
        }

        if (!$member_id) {
            wp_send_json_error(['message' => '注册失败，请稍后重试。'], 500);
        }

        $code = $this->create_code($member_id, $email, 'register');
        if (!$this->send_code_email($email, $code, 'register')) {
            wp_send_json_error(['message' => '验证码邮件发送失败，请检查 SMTP 设置。'], 500);
        }

        wp_send_json_success([
            'message' => '验证码已发送，请检查邮箱。',
            'email' => $email,
        ]);
    }

    public function ajax_verify_registration(): void
    {
        $this->require_ajax_nonce();

        global $wpdb;

        $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
        $code = $this->posted_text('code');
        $member = $this->get_member_by_email($email);

        if (!$member || $member->status === 'disabled') {
            wp_send_json_error(['message' => '账号不存在或不可用。'], 404);
        }

        if (!$this->verify_code($email, 'register', $code)) {
            wp_send_json_error(['message' => '验证码不正确或已过期。'], 400);
        }

        $now = $this->now();
        $wpdb->update(
            $this->members_table(),
            [
                'status' => 'active',
                'email_verified_at' => $now,
                'updated_at' => $now,
                'last_login_at' => $now,
            ],
            ['id' => (int)$member->id]
        );

        $member = $this->get_member_by_email($email);
        $this->create_session((int)$member->id);

        wp_send_json_success([
            'message' => '注册完成，已登录。',
            'member' => $this->member_response($member),
        ]);
    }

    public function ajax_login(): void
    {
        $this->require_ajax_nonce();

        global $wpdb;

        $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
        $password = (string)wp_unslash($_POST['password'] ?? '');
        $member = $this->get_member_by_email($email);

        if (!$member || !password_verify($password, $member->password_hash)) {
            wp_send_json_error(['message' => '邮箱或密码不正确。'], 401);
        }

        if ($member->status === 'pending') {
            wp_send_json_error(['message' => '邮箱尚未验证，请先完成注册验证码。'], 403);
        }

        if ($member->status !== 'active') {
            wp_send_json_error(['message' => '账号暂不可用，请联系销售工程师。'], 403);
        }

        $now = $this->now();
        $wpdb->update($this->members_table(), ['last_login_at' => $now, 'updated_at' => $now], ['id' => (int)$member->id]);
        $this->create_session((int)$member->id);
        $member = $this->get_member_by_email($email);

        wp_send_json_success([
            'message' => '登录成功。',
            'member' => $this->member_response($member),
        ]);
    }

    public function ajax_logout(): void
    {
        $this->require_ajax_nonce();
        $this->clear_session();
        wp_send_json_success(['message' => '已退出登录。']);
    }

    public function ajax_me(): void
    {
        $this->require_ajax_nonce();
        $member = $this->current_member();
        wp_send_json_success([
            'loggedIn' => (bool)$member,
            'member' => $member ? $this->member_response($member) : null,
        ]);
    }

    public function ajax_request_password_reset(): void
    {
        $this->require_ajax_nonce();

        $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
        if (!is_email($email)) {
            wp_send_json_error(['message' => '邮箱格式不正确。'], 400);
        }

        $member = $this->get_member_by_email($email);
        if (!$member || $member->status !== 'active') {
            wp_send_json_success(['message' => '如果该邮箱已注册，验证码将发送到该邮箱。']);
        }

        $code = $this->create_code((int)$member->id, $email, 'reset');
        if (!$this->send_code_email($email, $code, 'reset')) {
            wp_send_json_error(['message' => '验证码邮件发送失败，请检查 SMTP 设置。'], 500);
        }

        wp_send_json_success(['message' => '验证码已发送，请检查邮箱。']);
    }

    public function ajax_reset_password(): void
    {
        $this->require_ajax_nonce();

        global $wpdb;

        $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
        $code = $this->posted_text('code');
        $password = (string)wp_unslash($_POST['password'] ?? '');
        $member = $this->get_member_by_email($email);

        if (!$member || $member->status !== 'active') {
            wp_send_json_error(['message' => '账号不存在或不可用。'], 404);
        }

        if (strlen($password) < 8) {
            wp_send_json_error(['message' => '新密码至少需要 8 位。'], 400);
        }

        if (!$this->verify_code($email, 'reset', $code)) {
            wp_send_json_error(['message' => '验证码不正确或已过期。'], 400);
        }

        $wpdb->update(
            $this->members_table(),
            ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => $this->now()],
            ['id' => (int)$member->id]
        );
        $wpdb->delete($this->sessions_table(), ['member_id' => (int)$member->id]);

        wp_send_json_success(['message' => '密码已重置，请重新登录。']);
    }

    public function ajax_nonce(): void
    {
        wp_send_json_success([
            'nonce' => wp_create_nonce('rvision_member'),
        ]);
    }

    public function ajax_catalog_request(): void
    {
        $this->require_ajax_nonce();

        $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
        if (!is_email($email)) {
            wp_send_json_error(['message' => '请填写有效的邮箱地址。'], 400);
        }

        $file = $this->catalog_file();
        if (!is_readable($file)) {
            wp_send_json_error(['message' => '目录文件暂不可用，请联系销售工程师。'], 500);
        }

        if (!$this->send_catalog_email($email, $file)) {
            wp_send_json_error(['message' => '目录邮件发送失败，请稍后重试或联系销售工程师。'], 500);
        }

        $this->log_catalog_request($email);

        wp_send_json_success([
            'message' => '产品目录已发送，请检查邮箱。',
            'email' => $email,
        ]);
    }

    public function configure_smtp($phpmailer): void
    {
        $options = $this->options();
        if ($options['smtp_enabled'] !== '1' || $options['smtp_host'] === '' || $options['smtp_username'] === '' || $options['smtp_password'] === '') {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $options['smtp_host'];
        $phpmailer->SMTPAuth = true;
        $phpmailer->Port = (int)$options['smtp_port'];
        $phpmailer->Username = $options['smtp_username'];
        $phpmailer->Password = $options['smtp_password'];
        $phpmailer->SMTPSecure = $options['smtp_secure'] === 'none' ? '' : $options['smtp_secure'];
        $phpmailer->CharSet = 'UTF-8';
        $phpmailer->Timeout = 15;
        $phpmailer->SMTPKeepAlive = false;

        if (is_email($options['smtp_from_email'])) {
            try {
                $phpmailer->setFrom($options['smtp_from_email'], $options['smtp_from_name'], false);
            } catch (Exception $error) {
                // Keep wp_mail usable if PHPMailer rejects the configured sender.
            }
        }
    }

    private function require_ajax_nonce(): void
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'rvision_member')) {
            wp_send_json_error(['message' => '页面已过期，请刷新后重试。'], 403);
        }
    }

    private function posted_text(string $key): string
    {
        return sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
    }

    private function create_code(int $member_id, string $email, string $purpose): string
    {
        global $wpdb;

        $now = $this->now();
        $codes_table = $this->codes_table();
        $code = (string)wp_rand(100000, 999999);
        $expires_at = date('Y-m-d H:i:s', current_time('timestamp') + self::CODE_TTL_MINUTES * MINUTE_IN_SECONDS);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$codes_table} SET consumed_at = %s WHERE email = %s AND purpose = %s AND consumed_at IS NULL",
            $now,
            $email,
            $purpose
        ));

        $wpdb->insert($codes_table, [
            'member_id' => $member_id,
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => $expires_at,
            'created_at' => $now,
        ]);

        return $code;
    }

    private function verify_code(string $email, string $purpose, string $code): bool
    {
        global $wpdb;

        if (!preg_match('/^\\d{6}$/', $code)) {
            return false;
        }

        $codes_table = $this->codes_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$codes_table} WHERE email = %s AND purpose = %s AND consumed_at IS NULL AND expires_at >= %s ORDER BY id DESC LIMIT 1",
            $email,
            $purpose,
            $this->now()
        ));

        if (!$row || !password_verify($code, $row->code_hash)) {
            return false;
        }

        $wpdb->update($codes_table, ['consumed_at' => $this->now()], ['id' => (int)$row->id]);
        return true;
    }

    private function send_code_email(string $email, string $code, string $purpose): bool
    {
        $title = $purpose === 'reset' ? 'Rvision 密码找回验证码' : 'Rvision 注册验证码';
        $intro = $purpose === 'reset' ? '您正在重置 Rvision 产品资料下载账号密码。' : '您正在注册 Rvision 产品资料下载账号。';
        $body = sprintf(
            '<p>%s</p><p>验证码：<strong style="font-size:22px">%s</strong></p><p>验证码 %d 分钟内有效。如非本人操作，请忽略本邮件。</p>',
            esc_html($intro),
            esc_html($code),
            self::CODE_TTL_MINUTES
        );

        return wp_mail($email, $title, $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    private function send_catalog_email(string $email, string $file): bool
    {
        $title = 'Rvision YK-C 产品目录';
        $body = '<p>您好，感谢关注 Rvision 睿视智能 YK-C 外夹式超声波流量计。</p>'
            . '<p>产品目录 PDF 已随邮件附件发送，请查收。</p>'
            . '<p>如需选型支持，请直接回复本邮件或联系销售工程师。</p>';

        return wp_mail($email, $title, $body, ['Content-Type: text/html; charset=UTF-8'], [$file]);
    }

    private function current_member()
    {
        global $wpdb;

        $token = sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE] ?? ''));
        if ($token === '') {
            return null;
        }

        $hash = hash('sha256', $token);
        $sessions_table = $this->sessions_table();
        $members_table = $this->members_table();
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT m.* FROM {$sessions_table} s INNER JOIN {$members_table} m ON m.id = s.member_id WHERE s.token_hash = %s AND s.expires_at >= %s AND m.status = 'active' LIMIT 1",
            $hash,
            $this->now()
        ));

        if (!$member) {
            $this->clear_session();
            return null;
        }

        $wpdb->update($sessions_table, ['last_seen_at' => $this->now()], ['token_hash' => $hash]);
        return $member;
    }

    private function create_session(int $member_id): void
    {
        global $wpdb;

        $raw_token = wp_generate_password(64, false, false);
        $expires_ts = time() + self::SESSION_DAYS * DAY_IN_SECONDS;
        $expires_at = date('Y-m-d H:i:s', current_time('timestamp') + self::SESSION_DAYS * DAY_IN_SECONDS);

        $wpdb->insert($this->sessions_table(), [
            'member_id' => $member_id,
            'token_hash' => hash('sha256', $raw_token),
            'expires_at' => $expires_at,
            'created_at' => $this->now(),
            'last_seen_at' => $this->now(),
            'user_agent' => $this->user_agent(),
            'ip' => $this->client_ip(),
        ]);

        $this->set_session_cookie($raw_token, $expires_ts);
    }

    private function clear_session(): void
    {
        global $wpdb;

        $token = sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE] ?? ''));
        if ($token !== '') {
            $wpdb->delete($this->sessions_table(), ['token_hash' => hash('sha256', $token)]);
        }

        $this->set_session_cookie('', time() - HOUR_IN_SECONDS);
    }

    private function set_session_cookie(string $value, int $expires): void
    {
        $args = [
            'expires' => $expires,
            'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
            $args['domain'] = COOKIE_DOMAIN;
        }

        setcookie(self::SESSION_COOKIE, $value, $args);
        if ($value === '') {
            unset($_COOKIE[self::SESSION_COOKIE]);
        } else {
            $_COOKIE[self::SESSION_COOKIE] = $value;
        }
    }

    private function member_response($member): array
    {
        $name = trim($member->last_name . $member->first_name);
        $initial_source = $member->last_name ?: $member->email;
        $initial = function_exists('mb_substr') ? mb_substr($initial_source, 0, 1) : substr($initial_source, 0, 1);
        return [
            'email' => $member->email,
            'company' => $member->company,
            'name' => $name !== '' ? $name : $member->email,
            'initial' => $initial,
            'demand' => $this->demand_label($member->demand),
        ];
    }

    private function log_download($member): void
    {
        global $wpdb;
        $wpdb->insert($this->downloads_table(), [
            'member_id' => (int)$member->id,
            'email' => $member->email,
            'downloaded_at' => $this->now(),
            'user_agent' => $this->user_agent(),
            'ip' => $this->client_ip(),
        ]);
    }

    private function log_catalog_request(string $email): void
    {
        global $wpdb;
        $wpdb->insert($this->downloads_table(), [
            'member_id' => 0,
            'email' => $email,
            'downloaded_at' => $this->now(),
            'user_agent' => $this->user_agent(),
            'ip' => $this->client_ip(),
        ]);
    }

    private function get_member_by_email(string $email)
    {
        global $wpdb;
        $members_table = $this->members_table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$members_table} WHERE email = %s LIMIT 1", $email));
    }

    private function admin_members(): array
    {
        global $wpdb;
        $members_table = $this->members_table();
        return $wpdb->get_results("SELECT * FROM {$members_table} ORDER BY created_at DESC LIMIT 200") ?: [];
    }

    private function admin_downloads(): array
    {
        global $wpdb;
        $downloads_table = $this->downloads_table();
        return $wpdb->get_results("SELECT * FROM {$downloads_table} ORDER BY downloaded_at DESC LIMIT 100") ?: [];
    }

    private function demand_options(): array
    {
        return [
            'quote' => '询价',
            'technical' => '技术咨询',
            'sample' => '样品测试',
        ];
    }

    private function demand_label(string $key): string
    {
        $options = $this->demand_options();
        return $options[$key] ?? $key;
    }

    private function now(): string
    {
        return current_time('mysql');
    }

    private function user_agent(): string
    {
        return substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    }

    private function client_ip(): string
    {
        return substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);
    }

    private function members_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rvision_members';
    }

    private function sessions_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rvision_member_sessions';
    }

    private function codes_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rvision_member_codes';
    }

    private function downloads_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rvision_catalog_downloads';
    }

    private function catalog_file(): string
    {
        $path = $this->options()['catalog_path'];
        if ($path === '') {
            return plugin_dir_path(__FILE__) . 'assets/catalog.pdf';
        }
        return $path;
    }

    private function options(): array
    {
        return wp_parse_args(get_option(self::OPTION_KEY, []), $this->default_options());
    }

    private function default_options(): array
    {
        return [
            'catalog_path' => '',
            'smtp_enabled' => '1',
            'smtp_host' => 'smtp.qq.com',
            'smtp_port' => 465,
            'smtp_secure' => 'ssl',
            'smtp_username' => 'JackMa@rvisiontek.com',
            'smtp_password' => '',
            'smtp_from_email' => 'JackMa@rvisiontek.com',
            'smtp_from_name' => 'Rvision 睿视智能',
        ];
    }
}

Rvision_Catalog_Gate::init();
