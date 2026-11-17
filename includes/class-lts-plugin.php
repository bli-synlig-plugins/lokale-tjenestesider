<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Plugin
{
    public static function init(): void
    {
        LTS_Post_Types::init();
        LTS_Meta::init();
        LTS_Editor::init();
        LTS_Rewrite::init();
        LTS_Templates::init();
        LTS_Seo::init();
        LTS_Elements::init();
        LTS_Import::init();

        add_filter('plugin_action_links_' . plugin_basename(LTS_FILE), array(__CLASS__, 'action_links'));
        add_filter('plugin_row_meta', array(__CLASS__, 'row_meta'), 10, 2);

        if (!self::has_flatsome()) {
            add_action('admin_notices', array(__CLASS__, 'flatsome_notice'));
        }
    }

    public static function activate(): void
    {
        LTS_Post_Types::register();
        LTS_Repository::sync_service_slugs(false);
        add_filter('rewrite_rules_array', array('LTS_Rewrite', 'insert_rules'));
        flush_rewrite_rules();
        update_option('lts_rewrite_version', LTS_VERSION, false);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function has_flatsome(): bool
    {
        return function_exists('add_ux_builder_shortcode')
            || function_exists('flatsome_option')
            || class_exists('Flatsome_Default', false)
            || defined('FLATSOME_VERSION');
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public static function action_links(array $links): array
    {
        $url = admin_url('admin.php?page=lts-import');
        $link = '<a href="' . esc_url($url) . '">' . esc_html__('Import', 'lokale-tjenestesider') . '</a>';
        array_unshift($links, $link);

        return $links;
    }

    /**
     * @param array<int, string> $links
     * @return array<int, string>
     */
    public static function row_meta(array $links, string $file): array
    {
        if ($file !== plugin_basename(LTS_FILE)) {
            return $links;
        }

        $links[] = '<a href="' . esc_url(LTS_DOCS_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Dokumentasjon', 'lokale-tjenestesider') . '</a>';

        return $links;
    }

    public static function flatsome_notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Lokale tjenestesider: UX Builder-elementene krever Flatsome. Kortkodene og sidene virker uten temaet.', 'lokale-tjenestesider');
        echo '</p></div>';
    }
}
