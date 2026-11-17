<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Elements
{
    /** @var array<int, array<string, string>>|null */
    private static $color_helpers = null;

    public static function init(): void
    {
        add_action('init', array('LTS_Element_Kommune', 'register_shortcodes'));
        add_action('init', array('LTS_Element_Tjeneste', 'register_shortcodes'));
        add_action('ux_builder_setup', array(__CLASS__, 'register_builder'));
        add_action('ux_builder_enqueue_scripts', array(__CLASS__, 'enqueue_builder'));
    }

    public static function register_builder(): void
    {
        if (!function_exists('add_ux_builder_shortcode')) {
            return;
        }

        LTS_Element_Kommune::register_search();

        $type = LTS_Context::editing_post_type();
        $ajax = LTS_Context::is_builder_ajax();

        if ($ajax || in_array($type, array(LTS_KOMMUNE, LTS_SIDE), true)) {
            LTS_Element_Kommune::register_builder();
        }
        if ($ajax || in_array($type, array(LTS_TJENESTE, LTS_SIDE), true)) {
            LTS_Element_Tjeneste::register_builder();
        }
    }

    /**
     * @param string $context
     */
    public static function enqueue_builder($context = ''): void
    {
        if ($context !== 'content') {
            return;
        }

        LTS_Templates::register_assets();
        LTS_Templates::enqueue_assets();
        wp_enqueue_script('lts-sok');
    }

    /**
     * @param array<string, string> $defaults
     * @param mixed $atts
     * @return array<string, string>
     */
    public static function parse(array $defaults, $atts): array
    {
        if (!is_array($atts)) {
            $atts = array();
        }

        return shortcode_atts($defaults, $atts);
    }

    /**
     * @return array<string, mixed>
     */
    public static function text_options(string $default_tag): array
    {
        return array(
            'tag' => array(
                'type' => 'select',
                'heading' => __('Tag', 'lokale-tjenestesider'),
                'default' => $default_tag,
                'options' => array(
                    'h1' => 'H1',
                    'h2' => 'H2',
                    'h3' => 'H3',
                    'h4' => 'H4',
                    'p' => 'P',
                    'div' => 'Div',
                ),
            ),
            'color' => self::color_option(__('Farge', 'lokale-tjenestesider')),
            'advanced_options' => self::advanced_options(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function box_options(): array
    {
        return array(
            'heading' => array(
                'type' => 'textfield',
                'heading' => __('Overskrift', 'lokale-tjenestesider'),
                'default' => '',
            ),
            'color' => self::color_option(__('Farge', 'lokale-tjenestesider')),
            'advanced_options' => self::advanced_options(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function color_option(string $heading): array
    {
        return array(
            'type' => 'colorpicker',
            'heading' => $heading,
            'default' => '',
            'format' => 'rgb',
            'alpha' => true,
            'helpers' => self::color_helpers(),
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function color_helpers(): array
    {
        if (is_array(self::$color_helpers)) {
            return self::$color_helpers;
        }

        $file = get_template_directory() . '/inc/builder/shortcodes/helpers/colors.php';
        if (is_readable($file) && class_exists('Flatsome_Default', false)) {
            $helpers = require $file;
            if (is_array($helpers) && $helpers !== array()) {
                self::$color_helpers = $helpers;
                return self::$color_helpers;
            }
        }

        self::$color_helpers = array(
            array('title' => 'Dark', 'value' => 'rgb(0,0,0)'),
            array('title' => 'White', 'value' => 'rgb(255,255,255)'),
            array('title' => 'Primary', 'value' => ''),
            array('title' => 'Secondary', 'value' => ''),
            array('title' => 'Success', 'value' => ''),
        );

        return self::$color_helpers;
    }

    /**
     * @return array<string, mixed>
     */
    public static function advanced_options(): array
    {
        return array(
            'type' => 'group',
            'heading' => __('Avansert', 'lokale-tjenestesider'),
            'options' => array(
                'class' => array(
                    'type' => 'textfield',
                    'heading' => __('Klasse', 'lokale-tjenestesider'),
                    'default' => '',
                    'full_width' => true,
                ),
                'id' => array(
                    'type' => 'textfield',
                    'heading' => __('ID', 'lokale-tjenestesider'),
                    'default' => '',
                ),
            ),
        );
    }

    /**
     * @param array<string, string> $atts
     */
    public static function tag(array $atts, string $fallback): string
    {
        $tag = isset($atts['tag']) ? strtolower((string) $atts['tag']) : $fallback;
        $allowed = array('h1', 'h2', 'h3', 'h4', 'p', 'div', 'span');

        return in_array($tag, $allowed, true) ? $tag : $fallback;
    }

    /**
     * @param array<string, string> $atts
     */
    public static function attrs(array $atts, string $class): string
    {
        $classes = array($class);
        if (!empty($atts['class'])) {
            $parts = preg_split('/\s+/', (string) $atts['class']);
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    $part = sanitize_html_class($part);
                    if ($part !== '') {
                        $classes[] = $part;
                    }
                }
            }
        }

        $html = ' class="' . esc_attr(implode(' ', $classes)) . '"';
        if (!empty($atts['id'])) {
            $id = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $atts['id']);
            if (is_string($id) && $id !== '') {
                $html .= ' id="' . esc_attr($id) . '"';
            }
        }

        $color = self::sanitize_color(isset($atts['color']) ? (string) $atts['color'] : '');
        if ($color !== '') {
            $html .= ' style="' . esc_attr('--lts-color:' . $color . ';color:var(--lts-color)') . '"';
        }

        return $html;
    }

    public static function sanitize_color(string $color): string
    {
        $color = trim($color);
        if ($color === '') {
            return '';
        }
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color)) {
            return $color;
        }
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(?:0|1|0?\.\d+)\s*)?\)$/', $color)) {
            return $color;
        }

        return '';
    }

    /**
     * @param array<string, string> $atts
     */
    public static function wrap(string $fallback_tag, array $atts, string $class, string $inner): string
    {
        if ($inner === '') {
            return '';
        }

        LTS_Templates::enqueue_assets();
        $tag = self::tag($atts, $fallback_tag);

        return '<' . $tag . self::attrs($atts, $class) . '>' . $inner . '</' . $tag . '>';
    }

    public static function placeholder(string $label): string
    {
        if (!LTS_Context::is_preview()) {
            return '';
        }

        LTS_Templates::enqueue_assets();

        return '<span class="lts-placeholder">' . esc_html($label) . '</span>';
    }

    public static function tel_href(string $phone): string
    {
        $clean = preg_replace('/[^0-9+]/', '', $phone);

        return is_string($clean) && $clean !== '' ? 'tel:' . $clean : '';
    }

    /**
     * @param array<string, string> $atts
     */
    public static function heading_html(array $atts): string
    {
        $heading = isset($atts['heading']) ? trim((string) $atts['heading']) : '';
        if ($heading === '') {
            return '';
        }

        return '<h2 class="lts-heading">' . esc_html($heading) . '</h2>';
    }
}
