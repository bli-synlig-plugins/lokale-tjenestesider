<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Post_Types
{
    public static function init(): void
    {
        add_action('init', array(__CLASS__, 'register'));
        add_action('admin_menu', array(__CLASS__, 'menu'), 9);
        add_filter('post_row_actions', array(__CLASS__, 'row_actions'), 10, 2);
        add_filter('manage_' . LTS_FYLKE . '_posts_columns', array(__CLASS__, 'insert_slug_column'), 20);
        add_action('manage_' . LTS_FYLKE . '_posts_custom_column', array(__CLASS__, 'slug_column'), 10, 2);
        add_filter('manage_' . LTS_KOMMUNE . '_posts_columns', array(__CLASS__, 'kommune_columns'));
        add_filter('manage_' . LTS_KOMMUNE . '_posts_columns', array(__CLASS__, 'insert_slug_column'), 20);
        add_action('manage_' . LTS_KOMMUNE . '_posts_custom_column', array(__CLASS__, 'kommune_column'), 10, 2);
        add_action('manage_' . LTS_KOMMUNE . '_posts_custom_column', array(__CLASS__, 'slug_column'), 10, 2);
        add_action('restrict_manage_posts', array(__CLASS__, 'kommune_fylke_filter'));
        add_action('pre_get_posts', array(__CLASS__, 'filter_kommune_by_fylke'));
        add_filter('manage_' . LTS_TJENESTE . '_posts_columns', array(__CLASS__, 'insert_slug_column'), 20);
        add_action('manage_' . LTS_TJENESTE . '_posts_custom_column', array(__CLASS__, 'slug_column'), 10, 2);
        add_filter('manage_' . LTS_SIDE . '_posts_columns', array(__CLASS__, 'side_columns'));
        add_action('manage_' . LTS_SIDE . '_posts_custom_column', array(__CLASS__, 'side_column'), 10, 2);
    }

    public static function register(): void
    {
        if (function_exists('add_ux_builder_post_type')) {
            add_ux_builder_post_type(LTS_KOMMUNE);
            add_ux_builder_post_type(LTS_TJENESTE);
            add_ux_builder_post_type(LTS_SIDE);
        }

        $menu = 'lokale-tjenestesider';

        self::register_type(LTS_FYLKE, 'Fylker', 'Fylke', $menu, array('title', 'thumbnail'), false);
        self::register_type(LTS_KOMMUNE, 'Kommuner', 'Kommune', $menu, array('title', 'thumbnail'), true);
        self::register_type(LTS_TJENESTE, 'Tjenester', 'Tjeneste', $menu, array('title', 'excerpt', 'thumbnail'), true);
        self::register_type(LTS_SIDE, 'Lokale sider', 'Lokal side', $menu, array('title'), true, false);
    }

    /**
     * @param string[] $supports
     */
    private static function register_type(string $type, string $plural, string $singular, string $menu, array $supports, bool $queryable, bool $in_menu = true): void
    {
        register_post_type($type, array(
            'labels' => array(
                'name' => $plural,
                'singular_name' => $singular,
                'add_new' => 'Legg til',
                'add_new_item' => 'Legg til ' . strtolower($singular),
                'edit_item' => 'Rediger ' . strtolower($singular),
                'new_item' => 'Ny ' . strtolower($singular),
                'view_item' => 'Vis ' . strtolower($singular),
                'search_items' => 'Søk i ' . strtolower($plural),
                'not_found' => 'Ingen ' . strtolower($plural) . ' funnet',
                'not_found_in_trash' => 'Ingen ' . strtolower($plural) . ' i papirkurven',
                'menu_name' => $plural,
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => $in_menu ? $menu : false,
            'show_in_rest' => false,
            'supports' => $supports,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => $queryable,
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));
    }

    public static function menu(): void
    {
        add_menu_page(
            __('Tjenestesider', 'lokale-tjenestesider'),
            __('Tjenestesider', 'lokale-tjenestesider'),
            'edit_posts',
            'lokale-tjenestesider',
            array(__CLASS__, 'render_dashboard'),
            'dashicons-location-alt',
            26
        );
    }

    public static function shell_start(string $title, string $lead, string $current): void
    {
        $screen = $current === 'lts-import' ? ' lts-import-screen' : '';
        echo '<div class="wrap lts-admin' . $screen . '">';
        echo '<div class="lts-app">';
        echo '<header class="lts-top"><div>';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<p>' . esc_html($lead) . '</p>';
        echo '</div><div class="lts-top-meta">';
        echo '<a class="lts-docs" href="' . esc_url(LTS_DOCS_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Dokumentasjon', 'lokale-tjenestesider') . '</a>';
        echo '<span class="lts-pill">v' . esc_html(LTS_VERSION) . '</span></div></header>';
        echo '<nav class="lts-nav" aria-label="' . esc_attr__('Tjenestesider', 'lokale-tjenestesider') . '">';
        $items = array(
            'lokale-tjenestesider' => array(__('Oversikt', 'lokale-tjenestesider'), admin_url('admin.php?page=lokale-tjenestesider')),
            'lts-import' => array(__('Import', 'lokale-tjenestesider'), admin_url('admin.php?page=lts-import')),
        );
        foreach ($items as $id => $item) {
            echo '<a href="' . esc_url($item[1]) . '"' . ($id === $current ? ' aria-current="page"' : '') . '>' . esc_html($item[0]) . '</a>';
        }
        echo '</nav><main class="lts-main">';
    }

    public static function shell_end(): void
    {
        echo '</main></div></div>';
    }

    public static function render_dashboard(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }

        self::shell_start(
            __('Tjenestesider', 'lokale-tjenestesider'),
            __('Fylker, kommuner og tjenester er datagrunnlaget. En kommune som tilbyr en tjeneste vises på /tjeneste/kommune/.', 'lokale-tjenestesider'),
            'lokale-tjenestesider'
        );
        $links = array(
            admin_url('edit.php?post_type=' . LTS_FYLKE) => array(
                __('Fylker', 'lokale-tjenestesider'),
                __('Kontakt, priser og FAQ som kommunene arver.', 'lokale-tjenestesider'),
            ),
            admin_url('edit.php?post_type=' . LTS_KOMMUNE) => array(
                __('Kommuner', 'lokale-tjenestesider'),
                __('Hvilke tjenester stedet tilbyr, med tekst og SEO.', 'lokale-tjenestesider'),
            ),
            admin_url('edit.php?post_type=' . LTS_TJENESTE) => array(
                __('Tjenester', 'lokale-tjenestesider'),
                __('Hub-side for tjenesten, og om permalenken er på.', 'lokale-tjenestesider'),
            ),
            admin_url('admin.php?page=lts-import') => array(
                __('Import', 'lokale-tjenestesider'),
                __('Last opp JSON og se over endringene før de lagres.', 'lokale-tjenestesider'),
            ),
        );
        echo '<div class="lts-links">';
        foreach ($links as $url => $item) {
            echo '<a class="lts-card lts-link" href="' . esc_url($url) . '">';
            echo '<h2>' . esc_html($item[0]) . '</h2>';
            echo '<p class="lts-sub">' . esc_html($item[1]) . '</p>';
            echo '</a>';
        }
        echo '</div>';
        self::shell_end();
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public static function row_actions(array $actions, $post): array
    {
        if (!$post instanceof WP_Post) {
            return $actions;
        }

        if (in_array($post->post_type, array(LTS_FYLKE, LTS_KOMMUNE), true)) {
            unset($actions['view']);
        }
        if ($post->post_type === LTS_TJENESTE && !LTS_Repository::service_is_public($post)) {
            unset($actions['view']);
        }

        return $actions;
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function insert_slug_column(array $columns): array
    {
        $next = array();
        foreach ($columns as $key => $label) {
            $next[$key] = $label;
            if ($key === 'title') {
                $next['lts_slug'] = __('Slug', 'lokale-tjenestesider');
            }
        }
        if (!isset($next['lts_slug'])) {
            $next['lts_slug'] = __('Slug', 'lokale-tjenestesider');
        }

        return $next;
    }

    public static function slug_column(string $column, int $post_id): void
    {
        if ($column !== 'lts_slug') {
            return;
        }

        $post = get_post($post_id);
        if (!$post instanceof WP_Post || $post->post_name === '') {
            echo '—';
            return;
        }

        echo '<code>' . esc_html($post->post_name) . '</code>';
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function kommune_columns(array $columns): array
    {
        $next = array();
        foreach ($columns as $key => $label) {
            $next[$key] = $label;
            if ($key === 'title') {
                $next['lts_fylke'] = __('Fylke', 'lokale-tjenestesider');
                $next['lts_urls'] = __('Tjenester og URL-er', 'lokale-tjenestesider');
            }
        }
        if (!isset($next['lts_fylke'])) {
            $next['lts_fylke'] = __('Fylke', 'lokale-tjenestesider');
            $next['lts_urls'] = __('Tjenester og URL-er', 'lokale-tjenestesider');
        }

        return $next;
    }

    /**
     * @param string $post_type
     */
    public static function kommune_fylke_filter($post_type): void
    {
        if ($post_type !== LTS_KOMMUNE) {
            return;
        }

        $selected = isset($_GET['lts_fylke']) ? absint($_GET['lts_fylke']) : 0;
        $fylker = get_posts(array(
            'post_type' => LTS_FYLKE,
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ));

        echo '<label class="screen-reader-text" for="lts-fylke-filter">' . esc_html__('Filtrer på fylke', 'lokale-tjenestesider') . '</label>';
        echo '<select name="lts_fylke" id="lts-fylke-filter">';
        echo '<option value="">' . esc_html__('Alle fylker', 'lokale-tjenestesider') . '</option>';
        foreach ($fylker as $fylke) {
            if (!$fylke instanceof WP_Post) {
                continue;
            }
            $label = get_the_title($fylke);
            if ($fylke->post_name !== '') {
                $label .= ' (' . $fylke->post_name . ')';
            }
            echo '<option value="' . esc_attr((string) $fylke->ID) . '" ' . selected($selected, $fylke->ID, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public static function filter_kommune_by_fylke(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        if ($query->get('post_type') !== LTS_KOMMUNE) {
            return;
        }

        $fylke = isset($_GET['lts_fylke']) ? absint($_GET['lts_fylke']) : 0;
        if ($fylke <= 0) {
            return;
        }

        $meta = $query->get('meta_query');
        if (!is_array($meta)) {
            $meta = array();
        }
        $meta[] = array(
            'key' => LTS_META_FYLKE,
            'value' => (string) $fylke,
        );
        $query->set('meta_query', $meta);
    }

    public static function kommune_column(string $column, int $post_id): void
    {
        if ($column === 'lts_fylke') {
            $fylke_id = (int) get_post_meta($post_id, LTS_META_FYLKE, true);
            $fylke = $fylke_id ? get_post($fylke_id) : null;
            echo $fylke instanceof WP_Post ? esc_html(get_the_title($fylke)) . ' (' . esc_html($fylke->post_name) . ')' : '—';
            return;
        }

        if ($column !== 'lts_urls') {
            return;
        }

        $kommune = get_post($post_id);
        if (!$kommune instanceof WP_Post || $kommune->post_status !== 'publish' || $kommune->post_name === '') {
            echo '—';
            return;
        }

        $links = array();
        foreach (LTS_Repository::offered_slugs($post_id) as $slug) {
            $service = LTS_Repository::published_by_slug(LTS_TJENESTE, $slug);
            if (!$service instanceof WP_Post) {
                continue;
            }
            $side = LTS_Repository::side_by_pair($service->ID, $post_id, array('publish'));
            if (!$side instanceof WP_Post) {
                continue;
            }
            $url = LTS_Rewrite::local_url($service->post_name, $kommune->post_name);
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ($path === '') {
                $path = '/' . $service->post_name . '/' . $kommune->post_name . '/';
            }
            $links[] = '<a href="' . esc_url($url) . '">' . esc_html($path) . '</a>';
        }

        echo $links === array() ? '—' : implode('<br>', $links);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function side_columns(array $columns): array
    {
        $columns['lts_tjeneste'] = __('Tjeneste', 'lokale-tjenestesider');
        $columns['lts_kommune'] = __('Kommune', 'lokale-tjenestesider');

        return $columns;
    }

    public static function side_column(string $column, int $post_id): void
    {
        if ($column === 'lts_tjeneste') {
            $id = (int) get_post_meta($post_id, LTS_META_TJENESTE, true);
        } elseif ($column === 'lts_kommune') {
            $id = (int) get_post_meta($post_id, LTS_META_KOMMUNE, true);
        } else {
            return;
        }

        $post = $id ? get_post($id) : null;
        echo $post instanceof WP_Post ? esc_html(get_the_title($post)) : '—';
    }
}
