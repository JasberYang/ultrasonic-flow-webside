<?php
/**
 * Plugin Name: Rvision Catalog Gate
 * Description: Adds public YK-C catalog download and product homepage for R vision.
 * Version: 1.0.3
 * Author: R vision
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Rvision_Catalog_Gate
{
    private const OPTION_KEY = 'rvision_catalog_gate_options';

    public static function init(): void
    {
        $plugin = new self();
        register_activation_hook(__FILE__, [$plugin, 'activate']);
        register_deactivation_hook(__FILE__, [$plugin, 'deactivate']);

        add_action('init', [$plugin, 'register_routes']);
        add_filter('query_vars', [$plugin, 'query_vars']);
        add_action('template_redirect', [$plugin, 'render_product_home'], 0);
        add_action('template_redirect', [$plugin, 'handle_frontend_route']);
        add_action('admin_menu', [$plugin, 'admin_menu']);
        add_action('admin_init', [$plugin, 'register_settings']);
    }

    public function activate(): void
    {
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
            'Rvision 目录下载',
            'Rvision 目录下载',
            'manage_options',
            'rvision-catalog-settings',
            [$this, 'render_settings_page'],
            'dashicons-download',
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

        return [
            'catalog_path' => sanitize_text_field($input['catalog_path'] ?? ''),
        ];
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
            <p>当前目录为公开下载，不需要登录、注册或邮箱验证。</p>
            <form method="post" action="options.php">
                <?php settings_fields('rvision_catalog_gate'); ?>
                <table class="form-table" role="presentation">
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

        $html = preg_replace('#<link\\s+rel=["\\\']stylesheet["\\\']\\s+href=["\\\']styles\\.css["\\\']\\s*/?>#i', '<style>' . $css . '</style>', $html, 1);
        $html = preg_replace('#<script\\s+src=["\\\']script\\.js["\\\']></script>#i', '<script>' . $js . '</script>', $html, 1);

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function handle_download(): void
    {
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
        wp_safe_redirect(home_url('/catalog-download/'));
        exit;
    }

    private function handle_verify(): void
    {
        wp_safe_redirect(home_url('/catalog-download/'));
        exit;
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
        ];
    }
}

Rvision_Catalog_Gate::init();
