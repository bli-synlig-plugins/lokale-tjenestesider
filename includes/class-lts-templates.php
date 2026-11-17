<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Templates
{
    public static function init(): void
    {
        add_action('template_redirect', array(__CLASS__, 'prepare'));
        add_filter('template_include', array(__CLASS__, 'include_template'));
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
        add_action('admin_bar_menu', array(__CLASS__, 'admin_bar'), 80);
    }

    public static function prepare(): void
    {
        if (get_query_var('lts_service')) {
            $post = get_queried_object();
            if ($post instanceof WP_Post) {
                LTS_Context::set(LTS_Repository::context_from_post($post));
            }
            return;
        }

        if (!is_singular(array(LTS_TJENESTE, LTS_SIDE, LTS_KOMMUNE))) {
            return;
        }

        $post = get_queried_object();
        if (!$post instanceof WP_Post) {
            return;
        }

        if (LTS_Rewrite::is_builder_or_preview()) {
            LTS_Context::set(LTS_Repository::context_from_post($post));
            return;
        }

        if ($post->post_type === LTS_KOMMUNE) {
            self::force_404();
            return;
        }

        $url = LTS_Rewrite::canonical_for_post($post);
        if ($url !== '') {
            wp_safe_redirect($url, 301);
            exit;
        }
    }

    public static function include_template(string $template): string
    {
        if (is_404()) {
            return $template;
        }

        $public = (bool) get_query_var('lts_service');
        $preview = LTS_Rewrite::is_builder_or_preview() && is_singular(array(LTS_TJENESTE, LTS_SIDE, LTS_KOMMUNE));
        if (!$public && !$preview) {
            return $template;
        }

        if (!$public && is_singular(LTS_TJENESTE)) {
            $file = 'service.php';
        } elseif (get_query_var('lts_kommune') || is_singular(array(LTS_SIDE, LTS_KOMMUNE))) {
            $file = 'local.php';
        } else {
            $file = 'service.php';
        }

        $located = self::locate($file);

        return $located !== '' ? $located : $template;
    }

    public static function locate(string $file): string
    {
        $theme = locate_template(array('lokale-tjenestesider/' . $file));
        if ($theme !== '') {
            return $theme;
        }

        $plugin = LTS_DIR . 'templates/' . $file;

        return is_readable($plugin) ? $plugin : '';
    }

    /**
     * @param string[] $classes
     * @return string[]
     */
    public static function body_class(array $classes): array
    {
        $context = LTS_Context::get();
        if (!$context) {
            return $classes;
        }

        $classes[] = 'lts-page';
        if (get_query_var('lts_kommune') || is_singular(array(LTS_SIDE, LTS_KOMMUNE))) {
            $classes[] = 'lts-local';
        } else {
            $classes[] = 'lts-service';
        }

        return $classes;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function render(array $context, string $which): void
    {
        self::enqueue_assets();
        if ($which === 'local' && LTS_Rewrite::is_builder_or_preview()) {
            $queried = get_queried_object();
            $editing = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
            if ($queried instanceof WP_Post && $editing > 0 && (int) $queried->ID === $editing) {
                echo apply_filters('the_content', (string) $queried->post_content);
                return;
            }
        }

        $post = null;
        if ($which === 'local' && isset($context['tjeneste']) && $context['tjeneste'] instanceof WP_Post) {
            $post = LTS_Repository::template_for($context['tjeneste']->ID);
        } elseif ($which === 'service' && $context['tjeneste'] instanceof WP_Post) {
            $post = $context['tjeneste'];
        }

        $content = $post instanceof WP_Post ? (string) $post->post_content : '';
        $custom = self::has_layout($content);
        if (!$custom) {
            $content = $which === 'service' ? self::default_service() : self::default_local();
            echo '<div class="lts-default">' . do_shortcode($content) . '</div>';
            return;
        }

        echo apply_filters('the_content', $content);
    }

    public static function has_layout(string $content): bool
    {
        if (trim($content) === '') {
            return false;
        }
        if (strpos($content, '<post-wrapper') !== false) {
            return true;
        }
        if (preg_match('/\[lts_[a-z0-9_]+/', $content)) {
            return true;
        }
        if (preg_match('/\[(row|section|ux_|button|text|gap|col)\b/', $content)) {
            return true;
        }

        return trim(wp_strip_all_tags($content)) !== '';
    }

    public static function default_service(): string
    {
        return "[lts_tjeneste_navn tag=\"h1\"]\n[lts_tjeneste_intro]\n[lts_kommune_liste]";
    }

    public static function default_local(): string
    {
        return "[lts_tjeneste_navn tag=\"h1\"]\n[lts_kommune_navn tag=\"h2\"]\n[lts_tjeneste_intro]\n[lts_lokal_tekst]\n[lts_pris]\n[lts_faq]\n[lts_kontakt]\n[lts_naerliggende]\n[lts_tjeneste_liste]";
    }

    public static function register_assets(): void
    {
        wp_register_style('lts-frontend', LTS_URL . 'assets/css/frontend.css', array(), LTS_VERSION);
        wp_register_script('lts-sok', LTS_URL . 'assets/js/sok.js', array(), LTS_VERSION, true);
    }

    public static function enqueue_assets(): void
    {
        if (!wp_style_is('lts-frontend', 'registered')) {
            self::register_assets();
        }
        wp_enqueue_style('lts-frontend');
    }

    public static function admin_bar(WP_Admin_Bar $bar): void
    {
        $kommune = LTS_Context::current_kommune();
        $tjeneste = LTS_Context::current_tjeneste();
        if (!$kommune instanceof WP_Post || !$tjeneste instanceof WP_Post) {
            return;
        }

        $children = array();
        if (current_user_can('edit_post', $kommune->ID)) {
            $url = get_edit_post_link($kommune->ID, 'raw');
            if (is_string($url) && $url !== '') {
                $children[] = array(
                    'id' => 'lts-edit-kommune',
                    'title' => __('Rediger kommune', 'lokale-tjenestesider'),
                    'href' => $url,
                );
            }
        }

        if (current_user_can('edit_post', $tjeneste->ID)) {
            $template_id = LTS_Repository::ensure_template($tjeneste);
            if ($template_id > 0 && current_user_can('edit_post', $template_id)) {
                $url = self::builder_url($template_id);
                if ($url !== '') {
                    $url = add_query_arg('lts_preview_kommune', (string) $kommune->ID, $url);
                    $children[] = array(
                        'id' => 'lts-edit-template',
                        'title' => __('Rediger mal', 'lokale-tjenestesider'),
                        'href' => $url,
                    );
                }
            }
        }

        if ($children === array()) {
            return;
        }

        $bar->add_node(array(
            'id' => 'lts-edit',
            'title' => __('Rediger', 'lokale-tjenestesider'),
        ));
        foreach ($children as $child) {
            $child['parent'] = 'lts-edit';
            $bar->add_node($child);
        }
    }

    private static function builder_url(int $post_id): string
    {
        if (function_exists('ux_builder_edit_url')) {
            $url = ux_builder_edit_url($post_id);

            return is_string($url) ? $url : '';
        }
        $url = get_edit_post_link($post_id, 'raw');

        return is_string($url) ? $url : '';
    }

    private static function force_404(): void
    {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
}
