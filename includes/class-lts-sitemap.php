<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Sitemap_Provider extends WP_Sitemaps_Provider
{
    public function __construct()
    {
        $this->name = 'lokale';
        $this->object_type = 'lts';
    }

    /**
     * @return array<string, string>
     */
    public function get_object_subtypes()
    {
        return array('lts' => 'lts');
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function entries(): array
    {
        return $this->all_urls();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function get_url_list($page_num, $object_subtype = '')
    {
        if ($object_subtype !== '' && $object_subtype !== 'lts') {
            return array();
        }
        $urls = $this->all_urls();
        $per_page = $this->per_page();
        $offset = ((int) $page_num - 1) * $per_page;

        return array_slice($urls, $offset, $per_page);
    }

    public function get_max_num_pages($object_subtype = '')
    {
        if ($object_subtype !== '' && $object_subtype !== 'lts') {
            return 0;
        }
        $count = count($this->all_urls());
        if ($count === 0) {
            return 0;
        }

        return (int) ceil($count / $this->per_page());
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function all_urls(): array
    {
        static $urls = null;
        if (is_array($urls)) {
            return $urls;
        }

        $urls = array();
        $services = get_posts(array(
            'post_type' => LTS_TJENESTE,
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => true,
        ));
        foreach ($services as $service) {
            if (!LTS_Repository::service_is_public($service)) {
                continue;
            }
            $urls[] = $this->entry(LTS_Rewrite::service_url($service->post_name), $service);
        }

        $sides = get_posts(array(
            'post_type' => LTS_SIDE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => true,
        ));
        foreach ($sides as $side) {
            $url = LTS_Rewrite::canonical_for_post($side);
            if ($url === '') {
                continue;
            }
            $urls[] = $this->entry($url, $side);
        }

        return $urls;
    }

    /**
     * @return array<string, string>
     */
    private function entry(string $url, WP_Post $post): array
    {
        $entry = array(
            'loc' => $url,
        );
        $modified = get_post_modified_time('c', true, $post);
        if (is_string($modified) && $modified !== '') {
            $entry['lastmod'] = $modified;
        }

        return $entry;
    }

    private function per_page(): int
    {
        $max = 2000;
        if (function_exists('wp_sitemaps_get_max_urls')) {
            $max = (int) wp_sitemaps_get_max_urls($this->object_type);
        }

        return $max > 0 ? $max : 2000;
    }
}
