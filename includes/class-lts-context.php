<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Context
{
    /** @var array<string, mixed>|null */
    private static $context = null;

    /**
     * @param array<string, mixed> $context
     */
    public static function set(array $context): void
    {
        self::$context = $context;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(): ?array
    {
        if (self::$context !== null) {
            return self::$context;
        }

        $post_id = self::editing_post_id();
        if ($post_id <= 0 && is_singular(array(LTS_KOMMUNE, LTS_TJENESTE, LTS_SIDE))) {
            $post_id = (int) get_queried_object_id();
        }
        if ($post_id <= 0) {
            return null;
        }

        $post = get_post($post_id);
        if (!$post instanceof WP_Post || !in_array($post->post_type, array(LTS_KOMMUNE, LTS_TJENESTE, LTS_SIDE), true)) {
            return null;
        }

        self::$context = LTS_Repository::context_from_post($post);

        return self::$context;
    }

    public static function current_kommune(): ?WP_Post
    {
        $context = self::get();
        if (!$context || !$context['kommune'] instanceof WP_Post) {
            return null;
        }

        return $context['kommune'];
    }

    public static function current_tjeneste(): ?WP_Post
    {
        $context = self::get();
        if (!$context || !$context['tjeneste'] instanceof WP_Post) {
            return null;
        }

        return $context['tjeneste'];
    }

    public static function current_side(): ?WP_Post
    {
        $context = self::get();
        if (!$context || !$context['side'] instanceof WP_Post) {
            return null;
        }

        return $context['side'];
    }

    public static function current_fylke(): ?WP_Post
    {
        $context = self::get();
        if (!$context || !$context['fylke'] instanceof WP_Post) {
            return null;
        }

        return $context['fylke'];
    }

    public static function editing_post_id(): int
    {
        foreach (array('post', 'post_id', 'edit_post_id') as $key) {
            if (isset($_GET[$key]) && (int) $_GET[$key] > 0) {
                return (int) $_GET[$key];
            }
            if (isset($_POST[$key]) && (int) $_POST[$key] > 0) {
                return (int) $_POST[$key];
            }
        }

        if (isset($_POST['data'])) {
            $data = $_POST['data'];
            if (is_string($data)) {
                $decoded = json_decode(wp_unslash($data), true);
                if (is_array($decoded) && isset($decoded['id'])) {
                    return (int) $decoded['id'];
                }
            } elseif (is_array($data) && isset($data['post_id'])) {
                return (int) $data['post_id'];
            } elseif (is_array($data) && isset($data['id'])) {
                return (int) $data['id'];
            }
        }

        return 0;
    }

    public static function editing_post_type(): string
    {
        $post_id = self::editing_post_id();
        if ($post_id <= 0) {
            return '';
        }

        $type = get_post_type($post_id);

        return is_string($type) ? $type : '';
    }

    public static function is_builder_ajax(): bool
    {
        if (!wp_doing_ajax()) {
            return false;
        }

        $ux_action = isset($_POST['ux_builder_action']) ? sanitize_key((string) wp_unslash($_POST['ux_builder_action'])) : '';
        if ($ux_action === 'do_shortcode') {
            return true;
        }

        $action = isset($_POST['action']) ? sanitize_key((string) wp_unslash($_POST['action'])) : '';

        return in_array($action, array('ux_builder_save', 'ux_builder_copy_as_shortcode'), true);
    }

    public static function is_preview(): bool
    {
        if (is_preview()) {
            return true;
        }
        if (function_exists('ux_builder_is_iframe') && ux_builder_is_iframe()) {
            return true;
        }
        if (defined('UX_BUILDER_DOING_AJAX') && UX_BUILDER_DOING_AJAX) {
            return true;
        }
        if (defined('UX_BUILDER_AJAX_REQUEST') && UX_BUILDER_AJAX_REQUEST) {
            return true;
        }

        return self::is_builder_ajax();
    }
}
