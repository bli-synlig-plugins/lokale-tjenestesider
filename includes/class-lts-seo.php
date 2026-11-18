<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Seo
{
    public static function init(): void
    {
        add_filter('document_title_parts', array(__CLASS__, 'title_parts'));
        add_action('wp_head', array(__CLASS__, 'meta_description'), 1);
        add_action('wp_head', array(__CLASS__, 'social_image'), 1);
        add_action('wp_head', array(__CLASS__, 'canonical'), 1);
        add_action('wp_sitemaps_init', array(__CLASS__, 'sitemap'));
        add_filter('wpseo_canonical', array(__CLASS__, 'filter_canonical'));
        add_filter('get_canonical_url', array(__CLASS__, 'filter_canonical'));
        if (!self::yoast_active()) {
            return;
        }

        add_filter('wpseo_title', array(__CLASS__, 'yoast_title'));
        add_filter('wpseo_metadesc', array(__CLASS__, 'yoast_description'));
        add_filter('wpseo_opengraph_title', array(__CLASS__, 'yoast_title'));
        add_filter('wpseo_opengraph_desc', array(__CLASS__, 'yoast_description'));
        add_filter('wpseo_opengraph_url', array(__CLASS__, 'filter_canonical'));
        add_filter('wpseo_add_opengraph_images', array(__CLASS__, 'yoast_image'));
        add_filter('wpseo_twitter_title', array(__CLASS__, 'yoast_title'));
        add_filter('wpseo_twitter_description', array(__CLASS__, 'yoast_description'));
        add_filter('wpseo_sitemap_exclude_post_type', array(__CLASS__, 'exclude_post_type'), 10, 2);
        add_filter('wpseo_sitemap_index', array(__CLASS__, 'yoast_index'));
        add_action('template_redirect', array(__CLASS__, 'yoast_sitemap'), 0);
    }

    private static function yoast_active(): bool
    {
        return defined('WPSEO_VERSION');
    }

    /**
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    public static function title_parts(array $parts): array
    {
        $seo = self::public_seo();
        if ($seo === null) {
            return $parts;
        }

        $parts['title'] = $seo['title'];

        return $parts;
    }

    public static function meta_description(): void
    {
        if (self::yoast_active()) {
            return;
        }

        $seo = self::public_seo();
        if ($seo === null || $seo['description'] === '') {
            return;
        }

        echo '<meta name="description" content="' . esc_attr($seo['description']) . '">' . "\n";
    }

    public static function social_image(): void
    {
        if (self::yoast_active()) {
            return;
        }

        $image = self::share_image();
        if ($image['url'] === '') {
            return;
        }

        echo '<meta property="og:image" content="' . esc_url($image['url']) . '">' . "\n";
        if ($image['width'] > 0 && $image['height'] > 0) {
            echo '<meta property="og:image:width" content="' . esc_attr((string) $image['width']) . '">' . "\n";
            echo '<meta property="og:image:height" content="' . esc_attr((string) $image['height']) . '">' . "\n";
        }
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url($image['url']) . '">' . "\n";
    }

    /**
     * @param mixed $images
     * @return mixed
     */
    public static function yoast_image($images)
    {
        if (!is_object($images) || !method_exists($images, 'add_image_by_url')) {
            return $images;
        }

        $image = self::share_image();
        if ($image['url'] === '') {
            return $images;
        }

        $images->add_image_by_url($image['url']);

        return $images;
    }

    /**
     * @return array{url: string, width: int, height: int}
     */
    private static function share_image(): array
    {
        $empty = array(
            'url' => '',
            'width' => 0,
            'height' => 0,
        );
        if (self::public_seo() === null) {
            return $empty;
        }

        $context = LTS_Context::get();
        $kommune = is_array($context) && isset($context['kommune']) && $context['kommune'] instanceof WP_Post
            ? $context['kommune']
            : null;
        if (!$kommune instanceof WP_Post) {
            return $empty;
        }

        $image_id = (int) get_post_thumbnail_id($kommune);
        if ($image_id < 1 && isset($context['fylke']) && $context['fylke'] instanceof WP_Post) {
            $image_id = (int) get_post_thumbnail_id($context['fylke']);
        }
        $attachment = $image_id > 0 ? get_post($image_id) : null;
        if (!$attachment instanceof WP_Post || $attachment->post_type !== 'attachment') {
            return $empty;
        }

        $url = (string) wp_get_attachment_image_url($image_id, 'full');
        if ($url === '') {
            return $empty;
        }

        $width = 0;
        $height = 0;
        $meta = wp_get_attachment_metadata($image_id);
        if (is_array($meta)) {
            $width = isset($meta['width']) ? (int) $meta['width'] : 0;
            $height = isset($meta['height']) ? (int) $meta['height'] : 0;
        }

        return array(
            'url' => $url,
            'width' => $width,
            'height' => $height,
        );
    }

    public static function canonical(): void
    {
        if (self::yoast_active()) {
            return;
        }

        $seo = self::public_seo();
        if ($seo === null || $seo['url'] === '') {
            return;
        }

        remove_action('wp_head', 'rel_canonical');
        echo '<link rel="canonical" href="' . esc_url($seo['url']) . '">' . "\n";
    }

    /**
     * @param mixed $url
     * @return mixed
     */
    public static function filter_canonical($url)
    {
        $seo = self::public_seo();
        if ($seo === null || $seo['url'] === '') {
            return $url;
        }

        return $seo['url'];
    }

    /**
     * @param mixed $title
     * @return mixed
     */
    public static function yoast_title($title)
    {
        $seo = self::public_seo();
        if ($seo === null || $seo['title'] === '') {
            return $title;
        }

        return $seo['title'];
    }

    /**
     * @param mixed $description
     * @return mixed
     */
    public static function yoast_description($description)
    {
        $seo = self::public_seo();
        if ($seo === null || $seo['description'] === '') {
            return $description;
        }

        return $seo['description'];
    }

    /**
     * @param mixed $exclude
     * @param mixed $post_type
     * @return mixed
     */
    public static function exclude_post_type($exclude, $post_type)
    {
        if (in_array((string) $post_type, array(LTS_SIDE, LTS_TJENESTE), true)) {
            return true;
        }

        return $exclude;
    }

    /**
     * @param mixed $index
     * @return mixed
     */
    public static function yoast_index($index)
    {
        if (!is_string($index)) {
            $index = '';
        }

        $loc = esc_url(home_url('/lts-sitemap.xml'));
        $index .= "\n<sitemap>\n<loc>" . $loc . "</loc>\n</sitemap>";

        return $index;
    }

    public static function yoast_sitemap(): void
    {
        if ((string) get_query_var('lts_sitemap') !== '1' || !self::yoast_active()) {
            return;
        }
        if (!class_exists('WP_Sitemaps_Provider')) {
            return;
        }

        require_once LTS_DIR . 'includes/class-lts-sitemap.php';
        $provider = new LTS_Sitemap_Provider();
        status_header(200);
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow', true);
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($provider->entries() as $url) {
            if (!isset($url['loc']) || $url['loc'] === '') {
                continue;
            }
            echo '<url><loc>' . esc_url($url['loc']) . '</loc>';
            if (isset($url['lastmod']) && $url['lastmod'] !== '') {
                echo '<lastmod>' . esc_html($url['lastmod']) . '</lastmod>';
            }
            echo "</url>\n";
        }
        echo '</urlset>';
        exit;
    }

    /**
     * @param WP_Sitemaps $sitemaps
     */
    public static function sitemap($sitemaps): void
    {
        if (!isset($sitemaps->registry) || !class_exists('WP_Sitemaps_Provider')) {
            return;
        }

        require_once LTS_DIR . 'includes/class-lts-sitemap.php';
        $sitemaps->registry->add_provider('lokale', new LTS_Sitemap_Provider());
    }

    /**
     * @return array{title: string, description: string, url: string}|null
     */
    private static function public_seo(): ?array
    {
        if (!get_query_var('lts_service') || LTS_Rewrite::is_builder_or_preview()) {
            return null;
        }

        $context = LTS_Context::get();
        if (!$context || !isset($context['seo']) || !is_array($context['seo'])) {
            return null;
        }

        return array(
            'title' => isset($context['seo']['title']) ? (string) $context['seo']['title'] : '',
            'description' => isset($context['seo']['description']) ? (string) $context['seo']['description'] : '',
            'url' => isset($context['seo']['url']) ? (string) $context['seo']['url'] : '',
        );
    }
}
