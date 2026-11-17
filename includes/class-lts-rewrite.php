<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Rewrite
{
    public static function init(): void
    {
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('parse_request', array(__CLASS__, 'parse_request'));
        add_filter('redirect_canonical', array(__CLASS__, 'redirect_canonical'), 10, 2);
        add_filter('post_type_link', array(__CLASS__, 'post_type_link'), 10, 2);
        add_filter('rewrite_rules_array', array(__CLASS__, 'insert_rules'));
        add_action('template_redirect', array(__CLASS__, 'legacy_redirect'), 0);
        add_action('init', array(__CLASS__, 'maybe_flush'), 99);
        add_filter('fbc_breadcrumb_items', array(__CLASS__, 'breadcrumb_items'));
    }

    /**
     * @param array<string, string> $rules
     * @return array<string, string>
     */
    public static function insert_rules(array $rules): array
    {
        $ours = array(
            '^lts-mal/([0-9]+)/?$' => 'index.php?lts_mal=$matches[1]',
        );
        if (defined('WPSEO_VERSION')) {
            $ours['^lts-sitemap\\.xml$'] = 'index.php?lts_sitemap=1';
        }
        foreach (LTS_Repository::service_slugs() as $slug) {
            $service = LTS_Repository::published_by_slug(LTS_TJENESTE, $slug);
            if (!$service instanceof WP_Post) {
                continue;
            }
            $base = LTS_Repository::service_base($service);
            $prefix = $base !== '' ? $base . '/' : '';
            $ours['^' . $prefix . $slug . '/([^/]+)/?$'] = 'index.php?lts_service=' . $slug . '&lts_kommune=$matches[1]';
            if (LTS_Repository::service_is_public($service)) {
                $ours['^' . $prefix . $slug . '/?$'] = 'index.php?lts_service=' . $slug;
            }
            if ($base !== '') {
                $ours['^' . $slug . '/([^/]+)/?$'] = 'index.php?lts_service=' . $slug . '&lts_kommune=$matches[1]&lts_legacy=1';
                if (LTS_Repository::service_is_public($service)) {
                    $ours['^' . $slug . '/?$'] = 'index.php?lts_service=' . $slug . '&lts_legacy=1';
                }
            }
        }

        $kept = array();
        foreach ($rules as $pattern => $query) {
            if (is_string($query) && (strpos($query, 'lts_service=') !== false || strpos($query, 'lts_mal=') !== false || strpos($query, 'lts_sitemap=') !== false)) {
                continue;
            }
            $kept[$pattern] = $query;
        }

        return $ours + $kept;
    }

    public static function flush_rules(): void
    {
        flush_rewrite_rules(false);
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public static function query_vars(array $vars): array
    {
        $vars[] = 'lts_service';
        $vars[] = 'lts_kommune';
        $vars[] = 'lts_mal';
        $vars[] = 'lts_legacy';
        $vars[] = 'lts_sitemap';

        return $vars;
    }

    /**
     * @param WP $wp
     */
    public static function parse_request($wp): void
    {
        if (isset($wp->query_vars['lts_mal']) && $wp->query_vars['lts_mal'] !== '') {
            self::parse_template($wp);
            return;
        }

        if (!isset($wp->query_vars['lts_service']) || $wp->query_vars['lts_service'] === '') {
            return;
        }

        $service_slug = strtolower((string) $wp->query_vars['lts_service']);
        $kommune_slug = isset($wp->query_vars['lts_kommune']) ? strtolower((string) $wp->query_vars['lts_kommune']) : '';
        if (!LTS_Repository::is_slug($service_slug) || ($kommune_slug !== '' && !LTS_Repository::is_slug($kommune_slug))) {
            $wp->query_vars = array('error' => '404');
            return;
        }

        if ($kommune_slug === '') {
            $hub = LTS_Repository::published_by_slug(LTS_TJENESTE, $service_slug);
            if (!$hub instanceof WP_Post || !LTS_Repository::service_is_public($hub)) {
                $wp->query_vars = array('error' => '404');
                return;
            }
        }

        $context = LTS_Repository::resolve_public($service_slug, $kommune_slug !== '' ? $kommune_slug : null);

        if ($context === null) {
            $wp->query_vars = array('error' => '404');
            return;
        }

        $post = $context['side'] instanceof WP_Post ? $context['side'] : $context['tjeneste'];
        if (!$post instanceof WP_Post) {
            $wp->query_vars = array('error' => '404');
            return;
        }

        unset(
            $wp->query_vars['name'],
            $wp->query_vars['pagename'],
            $wp->query_vars['attachment'],
            $wp->query_vars['page_id'],
            $wp->query_vars['error']
        );
        $wp->query_vars['p'] = $post->ID;
        $wp->query_vars['post_type'] = $post->post_type;
        $wp->query_vars['lts_service'] = $service_slug;
        if ($context['kommune'] instanceof WP_Post) {
            $wp->query_vars['lts_kommune'] = $context['kommune']->post_name;
        }
    }

    /**
     * @param mixed $redirect
     * @return mixed
     */
    public static function redirect_canonical($redirect, string $requested)
    {
        unset($requested);
        if (get_query_var('lts_service') || get_query_var('lts_mal')) {
            return false;
        }

        return $redirect;
    }

    public static function legacy_redirect(): void
    {
        if ((string) get_query_var('lts_legacy') !== '1') {
            return;
        }

        $service = strtolower((string) get_query_var('lts_service'));
        $kommune = strtolower((string) get_query_var('lts_kommune'));
        if (!LTS_Repository::is_slug($service) || LTS_Repository::service_base_slug($service) === '') {
            return;
        }
        if ($kommune !== '' && !LTS_Repository::is_slug($kommune)) {
            return;
        }

        $url = $kommune !== '' ? self::local_url($service, $kommune) : self::service_url($service);
        wp_safe_redirect($url, 301);
        exit;
    }

    /**
     * @param mixed $post
     */
    public static function post_type_link(string $url, $post): string
    {
        if (!$post instanceof WP_Post) {
            return $url;
        }

        if ($post->post_type === LTS_TJENESTE && LTS_Repository::is_slug($post->post_name) && LTS_Repository::service_is_public($post)) {
            return self::service_url($post->post_name);
        }

        if ($post->post_type === LTS_SIDE) {
            $pair = (string) get_post_meta($post->ID, LTS_META_PAIR, true);
            if (strpos($pair, 'template:') === 0) {
                $url = self::template_url($post->ID);
                $preview = isset($_GET['lts_preview_kommune']) ? absint(wp_unslash($_GET['lts_preview_kommune'])) : 0;
                $editing = isset($_GET['post']) ? (int) $_GET['post'] : 0;
                if ($preview > 0 && $editing === (int) $post->ID) {
                    $url = add_query_arg('lts_preview_kommune', (string) $preview, $url);
                }

                return $url;
            }
            $pretty = self::canonical_for_post($post);
            if ($pretty !== '') {
                return $pretty;
            }
        }

        return $url;
    }

    public static function service_url(string $slug): string
    {
        return user_trailingslashit(home_url('/' . self::service_path($slug)));
    }

    public static function local_url(string $service, string $kommune): string
    {
        return user_trailingslashit(home_url('/' . self::service_path($service) . '/' . $kommune));
    }

    public static function service_path(string $slug): string
    {
        $base = LTS_Repository::service_base_slug($slug);

        return ($base !== '' ? $base . '/' : '') . $slug;
    }

    public static function template_url(int $post_id): string
    {
        return user_trailingslashit(home_url('/lts-mal/' . $post_id));
    }

    /**
     * @param WP $wp
     */
    private static function parse_template($wp): void
    {
        $id = absint($wp->query_vars['lts_mal']);
        $post = $id > 0 ? get_post($id) : null;
        $service_id = $post instanceof WP_Post ? (int) get_post_meta($post->ID, LTS_META_TJENESTE, true) : 0;
        $pair = $post instanceof WP_Post ? (string) get_post_meta($post->ID, LTS_META_PAIR, true) : '';
        if (!$post instanceof WP_Post || $post->post_type !== LTS_SIDE || $pair !== LTS_Repository::template_pair($service_id)) {
            $wp->query_vars = array('error' => '404');
            return;
        }

        unset(
            $wp->query_vars['name'],
            $wp->query_vars['pagename'],
            $wp->query_vars['attachment'],
            $wp->query_vars['page_id'],
            $wp->query_vars['error']
        );
        $wp->query_vars['p'] = $post->ID;
        $wp->query_vars['post_type'] = $post->post_type;
    }

    public static function canonical_for_post(WP_Post $post): string
    {
        if ($post->post_type === LTS_TJENESTE) {
            if (!LTS_Repository::service_is_public($post)) {
                return '';
            }

            return self::service_url($post->post_name);
        }

        if ($post->post_type !== LTS_SIDE) {
            return '';
        }

        $tjeneste = get_post((int) get_post_meta($post->ID, LTS_META_TJENESTE, true));
        $kommune = get_post((int) get_post_meta($post->ID, LTS_META_KOMMUNE, true));
        if (!$tjeneste instanceof WP_Post || !$kommune instanceof WP_Post) {
            return '';
        }
        if (!LTS_Repository::is_slug($tjeneste->post_name) || !LTS_Repository::is_slug($kommune->post_name)) {
            return '';
        }

        return self::local_url($tjeneste->post_name, $kommune->post_name);
    }

    /**
     * @param mixed $items
     * @return mixed
     */
    public static function breadcrumb_items($items)
    {
        if (!is_array($items) || $items === array()) {
            return $items;
        }

        $context = LTS_Context::get();
        $tjeneste = is_array($context) && isset($context['tjeneste']) && $context['tjeneste'] instanceof WP_Post
            ? $context['tjeneste']
            : null;
        if (!$tjeneste instanceof WP_Post) {
            return $items;
        }

        $extra = self::service_crumbs($tjeneste, isset($context['side']) && $context['side'] instanceof WP_Post);
        if ($extra === array()) {
            return $items;
        }

        $current = array_pop($items);
        foreach ($extra as $crumb) {
            $items[] = $crumb;
        }
        if (
            is_array($current)
            && isset($context['side'], $context['kommune'])
            && $context['side'] instanceof WP_Post
            && $context['kommune'] instanceof WP_Post
        ) {
            $name = get_the_title($context['kommune']);
            if ($name !== '') {
                $current['label'] = $name;
            }
        }
        $items[] = $current;

        return $items;
    }

    /**
     * @return array<int, array{label: string, url: string, current: bool, icon: string}>
     */
    private static function service_crumbs(WP_Post $tjeneste, bool $local): array
    {
        $crumbs = array();
        $base = LTS_Repository::service_base($tjeneste);
        if ($base !== '') {
            $page = self::published_page($base);
            if ($page instanceof WP_Post) {
                $crumbs[] = self::crumb(get_the_title($page), (string) get_permalink($page));
            } else {
                $crumbs[] = self::crumb(self::label_from_slug($base), '');
            }
        }

        if (!$local) {
            return $crumbs;
        }

        $public = LTS_Repository::service_is_public($tjeneste);
        $page = ($base !== '' && !$public) ? self::published_page($tjeneste->post_name) : null;
        if ($page instanceof WP_Post) {
            $crumbs[] = self::crumb(get_the_title($page), (string) get_permalink($page));
        } elseif ($public) {
            $crumbs[] = self::crumb(get_the_title($tjeneste), self::service_url($tjeneste->post_name));
        } else {
            $crumbs[] = self::crumb(get_the_title($tjeneste), '');
        }

        return $crumbs;
    }

    private static function published_page(string $path): ?WP_Post
    {
        $page = get_page_by_path($path, OBJECT, 'page');
        if (!$page instanceof WP_Post || $page->post_status !== 'publish') {
            return null;
        }

        return $page;
    }

    private static function label_from_slug(string $slug): string
    {
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            $first = mb_strtoupper(mb_substr($slug, 0, 1, 'UTF-8'), 'UTF-8');
            $rest = mb_substr($slug, 1, mb_strlen($slug, 'UTF-8'), 'UTF-8');

            return $first . $rest;
        }

        return ucfirst($slug);
    }

    /**
     * @return array{label: string, url: string, current: bool, icon: string}
     */
    private static function crumb(string $label, string $url): array
    {
        return array(
            'label' => $label,
            'url' => $url,
            'current' => false,
            'icon' => '',
        );
    }

    public static function maybe_flush(): void
    {
        if (get_option('lts_rewrite_version') === LTS_VERSION) {
            return;
        }

        flush_rewrite_rules(false);
        update_option('lts_rewrite_version', LTS_VERSION, false);
    }

    public static function is_builder_or_preview(): bool
    {
        if (is_preview()) {
            return true;
        }
        if (function_exists('ux_builder_is_iframe') && ux_builder_is_iframe()) {
            return true;
        }
        if (function_exists('ux_builder_is_editor') && ux_builder_is_editor()) {
            return true;
        }
        if (isset($_GET['preview']) || isset($_GET['uxb_iframe'])) {
            return true;
        }

        return false;
    }
}
