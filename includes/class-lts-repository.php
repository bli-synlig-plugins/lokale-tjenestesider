<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Repository
{
    /**
     * @return array<string, string>
     */
    public static function weekdays(): array
    {
        return array(
            'monday' => __('Mandag', 'lokale-tjenestesider'),
            'tuesday' => __('Tirsdag', 'lokale-tjenestesider'),
            'wednesday' => __('Onsdag', 'lokale-tjenestesider'),
            'thursday' => __('Torsdag', 'lokale-tjenestesider'),
            'friday' => __('Fredag', 'lokale-tjenestesider'),
            'saturday' => __('Lørdag', 'lokale-tjenestesider'),
            'sunday' => __('Søndag', 'lokale-tjenestesider'),
        );
    }

    public static function is_slug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $slug);
    }

    /**
     * @return array{phone: string, email: string, address: string, gf_id: string, emergency: string, hours: array<string, mixed>|null}
     */
    public static function empty_contact(): array
    {
        return array(
            'phone' => '',
            'email' => '',
            'address' => '',
            'gf_id' => '',
            'emergency' => '',
            'hours' => null,
        );
    }

    /**
     * @param mixed $raw
     * @return array{phone: string, email: string, address: string, gf_id: string, emergency: string, hours: array<string, mixed>|null}
     */
    public static function sanitize_contact($raw): array
    {
        $contact = self::empty_contact();
        if (!is_array($raw)) {
            return $contact;
        }

        $contact['phone'] = isset($raw['phone']) ? sanitize_text_field((string) $raw['phone']) : '';
        $email = isset($raw['email']) ? sanitize_email((string) $raw['email']) : '';
        $contact['email'] = is_email($email) ? $email : '';
        $contact['address'] = isset($raw['address']) ? sanitize_textarea_field((string) $raw['address']) : '';
        $contact['gf_id'] = isset($raw['gf_id']) ? sanitize_text_field((string) $raw['gf_id']) : '';
        $contact['emergency'] = isset($raw['emergency']) ? sanitize_text_field((string) $raw['emergency']) : '';
        $contact['hours'] = self::sanitize_hours(isset($raw['hours']) ? $raw['hours'] : null);

        return $contact;
    }

    /**
     * Import shape: open + emergency.phone.
     *
     * @param mixed $raw
     * @return array{phone: string, email: string, address: string, gf_id: string, emergency: string, hours: array<string, mixed>|null}
     */
    public static function contact_from_import($raw): array
    {
        if (!is_array($raw)) {
            return self::empty_contact();
        }

        $emergency = '';
        if (isset($raw['emergency']) && is_array($raw['emergency']) && isset($raw['emergency']['phone'])) {
            $emergency = (string) $raw['emergency']['phone'];
        } elseif (isset($raw['emergency']) && is_string($raw['emergency'])) {
            $emergency = $raw['emergency'];
        }

        return self::sanitize_contact(array(
            'phone' => isset($raw['phone']) ? $raw['phone'] : '',
            'email' => isset($raw['email']) ? $raw['email'] : '',
            'address' => isset($raw['address']) ? $raw['address'] : '',
            'gf_id' => isset($raw['gf_id']) ? $raw['gf_id'] : '',
            'emergency' => $emergency,
            'hours' => array_key_exists('open', $raw) ? $raw['open'] : null,
        ));
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>|null
     */
    public static function sanitize_hours($raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === array()) {
            return null;
        }

        if (!is_array($raw)) {
            return null;
        }

        $hours = array();
        foreach (array_keys(self::weekdays()) as $day) {
            if (!array_key_exists($day, $raw) || $raw[$day] === false || $raw[$day] === null || $raw[$day] === 'closed') {
                $hours[$day] = false;
                continue;
            }

            $slot = $raw[$day];
            if (!is_array($slot)) {
                $hours[$day] = false;
                continue;
            }

            $opens = self::sanitize_time(isset($slot['opens']) ? (string) $slot['opens'] : '');
            $closes = self::sanitize_time(isset($slot['closes']) ? (string) $slot['closes'] : '');
            if ($opens === '' || $closes === '') {
                $hours[$day] = false;
                continue;
            }

            $hours[$day] = array(
                'opens' => $opens,
                'closes' => $closes,
            );
        }

        return $hours;
    }

    public static function sanitize_time(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            return '';
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($hour > 23 || $minute > 59) {
            return '';
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * @param mixed $raw
     * @return array<string, float>
     */
    public static function sanitize_prices($raw): array
    {
        if (!is_array($raw)) {
            return array();
        }

        $prices = array();
        foreach ($raw as $slug => $amount) {
            $slug = (string) $slug;
            if (!self::is_slug($slug) || !is_numeric($amount)) {
                continue;
            }
            $prices[$slug] = (float) $amount;
        }

        return $prices;
    }

    /**
     * @param mixed $raw
     * @return array<int, array{question: string, answer: string}>
     */
    public static function sanitize_faq($raw): array
    {
        if (!is_array($raw)) {
            return array();
        }

        $faq = array();
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $question = isset($row['question']) ? sanitize_text_field((string) $row['question']) : '';
            $answer = isset($row['answer']) ? wp_kses_post((string) $row['answer']) : '';
            if ($question === '' && trim(wp_strip_all_tags($answer)) === '') {
                continue;
            }
            $faq[] = array(
                'question' => $question,
                'answer' => $answer,
            );
        }

        return $faq;
    }

    public static function price_string(float $amount): string
    {
        if (abs($amount - round($amount)) < 0.001) {
            return (string) (int) round($amount);
        }

        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    /**
     * @param string[] $statuses
     */
    public static function by_slug(string $type, string $slug, array $statuses = array('publish', 'draft', 'pending', 'private', 'future')): ?WP_Post
    {
        if ($slug === '') {
            return null;
        }

        $posts = get_posts(array(
            'name' => $slug,
            'post_type' => $type,
            'post_status' => $statuses,
            'posts_per_page' => 1,
            'suppress_filters' => true,
        ));

        return $posts ? $posts[0] : null;
    }

    public static function published_by_slug(string $type, string $slug): ?WP_Post
    {
        $post = self::by_slug($type, $slug, array('publish'));

        return $post instanceof WP_Post ? $post : null;
    }

    /**
     * @return array{phone: string, email: string, address: string, gf_id: string, emergency: string, hours: array<string, mixed>|null}
     */
    public static function contact(int $post_id): array
    {
        $stored = get_post_meta($post_id, LTS_META_CONTACT, true);

        return self::sanitize_contact(is_array($stored) ? $stored : array());
    }

    /**
     * @param array{phone: string, email: string, address: string, gf_id: string, emergency: string, hours: array<string, mixed>|null} $base
     * @param array{phone: string, email: string, address: string, gf_id: string, emergency: string, hours: array<string, mixed>|null} $override
     * @return array{phone: string, email: string, address: string, gf_id: string, emergency: string, hours: array<string, mixed>|null}
     */
    public static function merge_contact(array $base, array $override): array
    {
        foreach (array('phone', 'email', 'address', 'gf_id', 'emergency') as $key) {
            if ($override[$key] !== '') {
                $base[$key] = $override[$key];
            }
        }

        if (is_array($override['hours'])) {
            $base['hours'] = $override['hours'];
        }

        return $base;
    }

    /**
     * @return array<string, float>
     */
    public static function prices(int $post_id): array
    {
        $stored = get_post_meta($post_id, LTS_META_PRICES, true);

        return self::sanitize_prices(is_array($stored) ? $stored : array());
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    public static function sanitize_postnummer($raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\s*,\s*|\R/', $raw) ?: array();
        }
        if (!is_array($raw)) {
            return array();
        }

        $codes = array();
        foreach ($raw as $code) {
            $digits = preg_replace('/\D/', '', (string) $code);
            if (!is_string($digits) || strlen($digits) !== 4 || in_array($digits, $codes, true)) {
                continue;
            }
            $codes[] = $digits;
        }
        sort($codes);

        return $codes;
    }

    /**
     * @return string[]
     */
    public static function postnummer(int $post_id): array
    {
        $stored = get_post_meta($post_id, LTS_META_POSTNUMMER, true);

        return self::sanitize_postnummer(is_array($stored) ? $stored : array());
    }

    /**
     * @return array<string, string>
     */
    public static function price_notes(int $post_id): array
    {
        $stored = get_post_meta($post_id, LTS_META_PRICE_NOTES, true);
        if (!is_array($stored)) {
            return array();
        }

        $notes = array();
        foreach ($stored as $slug => $note) {
            $slug = (string) $slug;
            $note = sanitize_text_field((string) $note);
            if (self::is_slug($slug) && $note !== '') {
                $notes[$slug] = $note;
            }
        }

        return $notes;
    }

    public static function price_note_for(?WP_Post $kommune, ?WP_Post $fylke, string $service_slug): string
    {
        if ($service_slug === '') {
            return '';
        }
        if ($kommune instanceof WP_Post) {
            $local = self::price_notes($kommune->ID);
            if (isset($local[$service_slug]) && $local[$service_slug] !== '') {
                return $local[$service_slug];
            }
        }
        if ($fylke instanceof WP_Post) {
            $regional = self::price_notes($fylke->ID);
            if (isset($regional[$service_slug])) {
                return $regional[$service_slug];
            }
        }

        return '';
    }

    /**
     * @return array<int, array{name: string, id: int}>
     */
    public static function blocks(int $fylke_id, string $service_slug): array
    {
        $stored = get_post_meta($fylke_id, LTS_META_BLOCKS, true);
        if (!is_array($stored)) {
            return array();
        }
        if (self::is_legacy_block_list($stored)) {
            return self::block_rows($stored);
        }
        if ($service_slug === '' || !isset($stored[$service_slug]) || !is_array($stored[$service_slug])) {
            return array();
        }

        return self::block_rows($stored[$service_slug]);
    }

    /**
     * Flat lists from before blocks were stored per service are shown on every offered service until the next save.
     *
     * @return array<string, array<int, array{name: string, id: int}>>
     */
    public static function block_map(int $fylke_id): array
    {
        $stored = get_post_meta($fylke_id, LTS_META_BLOCKS, true);
        if (!is_array($stored)) {
            return array();
        }
        if (self::is_legacy_block_list($stored)) {
            $rows = self::block_rows($stored);
            $map = array();
            foreach (self::offered_slugs($fylke_id) as $slug) {
                $map[$slug] = $rows;
            }

            return $map;
        }

        $map = array();
        foreach ($stored as $slug => $rows) {
            $slug = (string) $slug;
            if (!self::is_slug($slug) || !is_array($rows)) {
                continue;
            }
            $map[$slug] = self::block_rows($rows);
        }

        return $map;
    }

    /**
     * @param array<mixed> $stored
     */
    private static function is_legacy_block_list(array $stored): bool
    {
        $first = reset($stored);

        return is_array($first) && (isset($first['id']) || isset($first['name']));
    }

    /**
     * @param array<mixed> $stored
     * @return array<int, array{name: string, id: int}>
     */
    private static function block_rows(array $stored): array
    {
        $rows = array();
        foreach ($stored as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $rows[] = array(
                'name' => isset($row['name']) ? sanitize_title((string) $row['name']) : '',
                'id' => $id,
            );
        }

        return $rows;
    }

    public static function template_pair(int $service_id): string
    {
        return 'template:' . $service_id;
    }

    public static function template_for(int $service_id): ?WP_Post
    {
        if ($service_id <= 0) {
            return null;
        }

        $posts = get_posts(array(
            'post_type' => LTS_SIDE,
            'post_status' => array('private', 'draft', 'pending', 'publish'),
            'posts_per_page' => 1,
            'suppress_filters' => true,
            'meta_key' => LTS_META_PAIR,
            'meta_value' => self::template_pair($service_id),
        ));

        return $posts ? $posts[0] : null;
    }

    public static function ensure_template(WP_Post $service): int
    {
        if ($service->post_type !== LTS_TJENESTE) {
            return 0;
        }

        $existing = self::template_for($service->ID);
        if ($existing instanceof WP_Post) {
            return (int) $existing->ID;
        }

        $copied = self::newest_local_layout($service->ID);
        $id = wp_insert_post(array(
            'post_type' => LTS_SIDE,
            'post_status' => 'private',
            'post_title' => wp_slash(sprintf(
                /* translators: %s: service name */
                __('Mal: %s', 'lokale-tjenestesider'),
                get_the_title($service)
            )),
            'post_content' => wp_slash($copied),
        ), true);
        if (is_wp_error($id) || !$id) {
            return 0;
        }

        update_post_meta($id, LTS_META_TJENESTE, $service->ID);
        update_post_meta($id, LTS_META_PAIR, self::template_pair($service->ID));

        return (int) $id;
    }

    private static function newest_local_layout(int $service_id): string
    {
        $sides = get_posts(array(
            'post_type' => LTS_SIDE,
            'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => 50,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => true,
            'meta_key' => LTS_META_TJENESTE,
            'meta_value' => (string) $service_id,
        ));
        foreach ($sides as $side) {
            $pair = (string) get_post_meta($side->ID, LTS_META_PAIR, true);
            if (strpos($pair, 'template:') === 0) {
                continue;
            }
            if (LTS_Templates::has_layout((string) $side->post_content)) {
                return (string) $side->post_content;
            }
        }

        return '';
    }

    public static function price_for(?WP_Post $kommune, ?WP_Post $fylke, string $service_slug): ?float
    {
        if ($service_slug === '') {
            return null;
        }

        if ($kommune instanceof WP_Post) {
            $local = self::prices($kommune->ID);
            if (array_key_exists($service_slug, $local)) {
                return $local[$service_slug];
            }
        }

        if ($fylke instanceof WP_Post) {
            $regional = self::prices($fylke->ID);
            if (array_key_exists($service_slug, $regional)) {
                return $regional[$service_slug];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    public static function faq(int $fylke_id): array
    {
        $stored = get_post_meta($fylke_id, LTS_META_FAQ, true);

        return self::sanitize_faq(is_array($stored) ? $stored : array());
    }

    public static function description(int $post_id): string
    {
        return (string) get_post_meta($post_id, LTS_META_DESCRIPTION, true);
    }

    public static function body(int $post_id): string
    {
        return (string) get_post_meta($post_id, LTS_META_BODY, true);
    }

    /**
     * @return int[]
     */
    public static function nearby_ids(int $kommune_id): array
    {
        $stored = get_post_meta($kommune_id, LTS_META_NEARBY, true);
        if (!is_array($stored)) {
            return array();
        }

        $ids = array();
        foreach ($stored as $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== $kommune_id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return WP_Post[]
     */
    public static function nearby_posts(int $kommune_id): array
    {
        $posts = array();
        foreach (self::nearby_ids($kommune_id) as $id) {
            $post = get_post($id);
            if ($post instanceof WP_Post && $post->post_type === LTS_KOMMUNE && $post->post_status === 'publish') {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    public static function fylke_for_kommune(?WP_Post $kommune): ?WP_Post
    {
        if (!$kommune instanceof WP_Post) {
            return null;
        }

        $fylke = get_post((int) get_post_meta($kommune->ID, LTS_META_FYLKE, true));
        if (!$fylke instanceof WP_Post || $fylke->post_type !== LTS_FYLKE) {
            return null;
        }

        return $fylke;
    }

    public static function side_by_pair(int $tjeneste_id, int $kommune_id, array $statuses = array('publish', 'draft', 'pending', 'private', 'future')): ?WP_Post
    {
        if ($tjeneste_id <= 0 || $kommune_id <= 0) {
            return null;
        }

        $posts = get_posts(array(
            'post_type' => LTS_SIDE,
            'post_status' => $statuses,
            'posts_per_page' => 1,
            'suppress_filters' => true,
            'meta_key' => LTS_META_PAIR,
            'meta_value' => $tjeneste_id . ':' . $kommune_id,
        ));

        return $posts ? $posts[0] : null;
    }

    /**
     * @return array{mode: string, field: string, value: string}
     */
    public static function people_filter(?WP_Post $tjeneste): array
    {
        $empty = array(
            'mode' => 'uten',
            'field' => '',
            'value' => '',
        );
        if (!$tjeneste instanceof WP_Post) {
            return $empty;
        }

        $stored = get_post_meta($tjeneste->ID, LTS_META_PEOPLE, true);
        if (!is_array($stored)) {
            return $empty;
        }

        $mode = isset($stored['mode']) ? sanitize_key((string) $stored['mode']) : 'uten';
        if (!in_array($mode, array('uten', 'basert', 'med'), true)) {
            $mode = 'uten';
        }

        return array(
            'mode' => $mode,
            'field' => isset($stored['field']) ? sanitize_key((string) $stored['field']) : '',
            'value' => isset($stored['value']) ? sanitize_title((string) $stored['value']) : '',
        );
    }

    /**
     * Employee fields in Store Locator that have a list of choices.
     *
     * @return array<int, array{key: string, label: string, options: array<int, array{slug: string, label: string}>}>
     */
    public static function people_field_choices(): array
    {
        if (!class_exists('\StoreLocator\CustomFields\CustomFields')) {
            return array();
        }

        $fields = array();
        foreach (\StoreLocator\CustomFields\CustomFields::for_entity('employees') as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            $choices = $type === 'radio' || ($type === 'checkbox' && \StoreLocator\CustomFields\CustomFields::is_multi($field));
            if (!$choices) {
                continue;
            }
            $key = sanitize_key((string) ($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $options = array();
            foreach (\StoreLocator\CustomFields\CustomFields::resolved_options($field) as $label) {
                $label = sanitize_text_field((string) $label);
                $slug = sanitize_title($label);
                if ($label === '' || $slug === '') {
                    continue;
                }
                $options[] = array(
                    'slug' => $slug,
                    'label' => $label,
                );
            }
            if ($options === array()) {
                continue;
            }
            $fields[] = array(
                'key' => $key,
                'label' => sanitize_text_field((string) ($field['label'] ?? $key)),
                'options' => $options,
            );
        }

        return $fields;
    }

    /**
     * @return array<int, array{tjeneste: WP_Post, side: WP_Post}>
     */
    public static function services_for_kommune(int $kommune_id): array
    {
        $sides = get_posts(array(
            'post_type' => LTS_SIDE,
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'suppress_filters' => true,
            'meta_key' => LTS_META_KOMMUNE,
            'meta_value' => (string) $kommune_id,
        ));

        $rows = array();
        foreach ($sides as $side) {
            $tjeneste = get_post((int) get_post_meta($side->ID, LTS_META_TJENESTE, true));
            if ($tjeneste instanceof WP_Post && $tjeneste->post_type === LTS_TJENESTE && $tjeneste->post_status === 'publish') {
                $rows[] = array(
                    'tjeneste' => $tjeneste,
                    'side' => $side,
                );
            }
        }

        usort($rows, function (array $a, array $b): int {
            return strcasecmp($a['tjeneste']->post_title, $b['tjeneste']->post_title);
        });

        return $rows;
    }

    /**
     * @return array<int, array{kommune: WP_Post, side: WP_Post}>
     */
    public static function kommuner_for_tjeneste(int $tjeneste_id): array
    {
        $sides = get_posts(array(
            'post_type' => LTS_SIDE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'suppress_filters' => true,
            'meta_key' => LTS_META_TJENESTE,
            'meta_value' => (string) $tjeneste_id,
        ));

        $rows = array();
        foreach ($sides as $side) {
            $kommune = get_post((int) get_post_meta($side->ID, LTS_META_KOMMUNE, true));
            if ($kommune instanceof WP_Post && $kommune->post_type === LTS_KOMMUNE && $kommune->post_status === 'publish') {
                $rows[] = array(
                    'kommune' => $kommune,
                    'side' => $side,
                );
            }
        }

        usort($rows, function (array $a, array $b): int {
            return strcasecmp($a['kommune']->post_title, $b['kommune']->post_title);
        });

        return $rows;
    }

    public static function count_by_meta(string $type, string $key, string $value): int
    {
        $query = new WP_Query(array(
            'post_type' => $type,
            'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => $key,
            'meta_value' => $value,
            'suppress_filters' => true,
        ));

        return (int) $query->found_posts;
    }

    public static function slug_conflicts(string $slug, int $except_id = 0): bool
    {
        if ($slug === '') {
            return false;
        }

        $page = get_page_by_path($slug, OBJECT, array('page', 'post'));
        if ($page instanceof WP_Post && (int) $page->ID !== $except_id) {
            return true;
        }

        $category = get_term_by('slug', $slug, 'category');
        if ($category instanceof WP_Term) {
            return true;
        }

        $existing = self::by_slug(LTS_TJENESTE, $slug);
        if ($existing instanceof WP_Post && (int) $existing->ID !== $except_id) {
            return true;
        }

        return false;
    }

    /**
     * @return string[]
     */
    public static function service_slugs(): array
    {
        $stored = get_option(LTS_OPTION_SLUGS, array());
        if (!is_array($stored)) {
            return array();
        }

        $slugs = array();
        foreach ($stored as $slug) {
            $slug = (string) $slug;
            if (self::is_slug($slug)) {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @return string[]
     */
    public static function public_service_slugs(): array
    {
        $slugs = array();
        foreach (self::service_slugs() as $slug) {
            $post = self::published_by_slug(LTS_TJENESTE, $slug);
            if ($post instanceof WP_Post && self::service_is_public($post)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    public static function service_is_public(WP_Post $post): bool
    {
        if ($post->post_type !== LTS_TJENESTE) {
            return false;
        }

        return get_post_meta($post->ID, LTS_META_PUBLIC_URL, true) !== '0';
    }

    public static function service_base(WP_Post $post): string
    {
        if ($post->post_type !== LTS_TJENESTE) {
            return '';
        }

        $base = trim((string) get_post_meta($post->ID, LTS_META_BASE, true), '/');

        return self::is_slug($base) ? $base : '';
    }

    public static function service_base_slug(string $slug): string
    {
        $post = self::by_slug(LTS_TJENESTE, $slug);

        return $post instanceof WP_Post ? self::service_base($post) : '';
    }

    /**
     * @return string[]
     */
    public static function offered_slugs(int $post_id): array
    {
        $stored = get_post_meta($post_id, LTS_META_SERVICES, true);
        if (is_array($stored)) {
            return self::clean_slugs($stored);
        }

        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return array();
        }
        if ($post->post_type === LTS_FYLKE) {
            return self::legacy_fylke_offers($post_id);
        }
        if ($post->post_type === LTS_KOMMUNE) {
            return self::legacy_kommune_offers($post_id);
        }

        return array();
    }

    /**
     * @param array<int, mixed> $stored
     * @return string[]
     */
    private static function clean_slugs(array $stored): array
    {
        $slugs = array();
        foreach ($stored as $slug) {
            $slug = (string) $slug;
            if (self::is_slug($slug)) {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @return string[]
     */
    private static function legacy_fylke_offers(int $fylke_id): array
    {
        $slugs = array_keys(self::prices($fylke_id));
        $kommuner = get_posts(array(
            'post_type' => LTS_KOMMUNE,
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => 500,
            'fields' => 'ids',
            'meta_key' => LTS_META_FYLKE,
            'meta_value' => (string) $fylke_id,
            'suppress_filters' => true,
        ));
        foreach ($kommuner as $kommune_id) {
            $slugs = array_merge($slugs, self::legacy_kommune_offers((int) $kommune_id));
        }

        return self::clean_slugs($slugs);
    }

    /**
     * @return string[]
     */
    private static function legacy_kommune_offers(int $kommune_id): array
    {
        $sides = get_posts(array(
            'post_type' => LTS_SIDE,
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'meta_key' => LTS_META_KOMMUNE,
            'meta_value' => (string) $kommune_id,
            'suppress_filters' => true,
        ));
        $slugs = array();
        foreach ($sides as $side) {
            $service = get_post((int) get_post_meta($side->ID, LTS_META_TJENESTE, true));
            if ($service instanceof WP_Post) {
                $slugs[] = $service->post_name;
            }
        }

        return self::clean_slugs($slugs);
    }

    public static function sync_service_slugs(bool $flush = true): void
    {
        $posts = get_posts(array(
            'post_type' => LTS_TJENESTE,
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ));

        $slugs = array();
        foreach ($posts as $post) {
            if (self::is_slug($post->post_name)) {
                $slugs[] = $post->post_name;
            }
        }

        $previous = self::service_slugs();
        if ($previous === $slugs) {
            return;
        }

        update_option(LTS_OPTION_SLUGS, $slugs, false);
        if ($flush) {
            flush_rewrite_rules(false);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolve_public(string $service_slug, ?string $kommune_slug): ?array
    {
        $tjeneste = self::published_by_slug(LTS_TJENESTE, $service_slug);
        if (!$tjeneste instanceof WP_Post) {
            return null;
        }

        if ($kommune_slug === null || $kommune_slug === '') {
            return self::build_context($tjeneste, null, null);
        }

        $kommune = self::published_by_slug(LTS_KOMMUNE, $kommune_slug);
        if (!$kommune instanceof WP_Post) {
            return null;
        }

        $side = self::side_by_pair($tjeneste->ID, $kommune->ID, array('publish'));
        if (!$side instanceof WP_Post) {
            return null;
        }

        return self::build_context($tjeneste, $kommune, $side);
    }

    /**
     * @return array<string, mixed>
     */
    public static function context_from_post(WP_Post $post): array
    {
        if ($post->post_type === LTS_TJENESTE) {
            return self::build_context($post, null, null);
        }

        if ($post->post_type === LTS_KOMMUNE) {
            return self::build_context(null, $post, null);
        }

        if ($post->post_type === LTS_SIDE) {
            $tjeneste = get_post((int) get_post_meta($post->ID, LTS_META_TJENESTE, true));
            $kommune = get_post((int) get_post_meta($post->ID, LTS_META_KOMMUNE, true));
            $pair = (string) get_post_meta($post->ID, LTS_META_PAIR, true);
            if ((!$kommune instanceof WP_Post || $kommune->post_type !== LTS_KOMMUNE) && strpos($pair, 'template:') === 0) {
                $kommune = self::preview_kommune($post);
            }

            return self::build_context(
                ($tjeneste instanceof WP_Post && $tjeneste->post_type === LTS_TJENESTE) ? $tjeneste : null,
                ($kommune instanceof WP_Post && $kommune->post_type === LTS_KOMMUNE) ? $kommune : null,
                $post
            );
        }

        return self::build_context(null, null, null);
    }

    private static function preview_kommune(WP_Post $template): ?WP_Post
    {
        if (!current_user_can('edit_post', $template->ID)) {
            return null;
        }

        $id = 0;
        if (isset($_GET['lts_preview_kommune'])) {
            $id = absint(wp_unslash($_GET['lts_preview_kommune']));
        }
        if ($id <= 0 && isset($_POST['lts_preview_kommune'])) {
            $id = absint(wp_unslash($_POST['lts_preview_kommune']));
        }
        if ($id <= 0) {
            $referer = wp_get_referer();
            if (!is_string($referer) || $referer === '') {
                $referer = isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : '';
            }
            $query = is_string($referer) ? wp_parse_url($referer, PHP_URL_QUERY) : null;
            if (is_string($query) && $query !== '') {
                parse_str($query, $args);
                if (isset($args['lts_preview_kommune'])) {
                    $id = absint($args['lts_preview_kommune']);
                }
            }
        }
        if ($id <= 0) {
            return null;
        }

        $kommune = get_post($id);
        if (!$kommune instanceof WP_Post || $kommune->post_type !== LTS_KOMMUNE) {
            return null;
        }

        return $kommune;
    }

    /**
     * @return array<string, mixed>
     */
    public static function build_context(?WP_Post $tjeneste, ?WP_Post $kommune, ?WP_Post $side): array
    {
        $fylke = self::fylke_for_kommune($kommune);
        $county_contact = $fylke instanceof WP_Post ? self::contact($fylke->ID) : self::empty_contact();
        $city_contact = $kommune instanceof WP_Post ? self::contact($kommune->ID) : self::empty_contact();
        $service_slug = $tjeneste instanceof WP_Post ? $tjeneste->post_name : '';

        $local_description = $side instanceof WP_Post ? self::description($side->ID) : '';
        $city_description = $kommune instanceof WP_Post ? self::description($kommune->ID) : '';
        $text = $local_description !== '' ? $local_description : $city_description;

        return array(
            'tjeneste' => $tjeneste,
            'kommune' => $kommune,
            'fylke' => $fylke,
            'side' => $side,
            'contact' => self::merge_contact($county_contact, $city_contact),
            'price' => self::price_for($kommune, $fylke, $service_slug),
            'price_note' => self::price_note_for($kommune, $fylke, $service_slug),
            'faq' => $fylke instanceof WP_Post ? self::faq($fylke->ID) : array(),
            'local_text' => $text,
            'seo' => self::seo($tjeneste, $kommune, $side, $text),
        );
    }

    /**
     * @return array{title: string, description: string, url: string}
     */
    public static function seo(?WP_Post $tjeneste, ?WP_Post $kommune, ?WP_Post $side, string $text): array
    {
        $service_title = $tjeneste instanceof WP_Post ? get_the_title($tjeneste) : '';
        $city_title = $kommune instanceof WP_Post ? get_the_title($kommune) : '';
        $title = $service_title;
        $description = '';
        $url = '';

        if ($tjeneste instanceof WP_Post && $kommune instanceof WP_Post) {
            $custom = $side instanceof WP_Post ? trim((string) get_post_meta($side->ID, LTS_META_SEO_TITLE, true)) : '';
            $title = $custom !== '' ? $custom : trim($service_title . ' i ' . $city_title);
            $meta = $side instanceof WP_Post ? trim((string) get_post_meta($side->ID, LTS_META_META_DESC, true)) : '';
            $description = $meta !== '' ? $meta : self::plain_excerpt($text);
            $url = LTS_Rewrite::local_url($tjeneste->post_name, $kommune->post_name);
        } elseif ($tjeneste instanceof WP_Post) {
            $excerpt = trim((string) $tjeneste->post_excerpt);
            $description = $excerpt !== '' ? self::plain_excerpt($excerpt) : self::plain_excerpt(self::body($tjeneste->ID));
            $url = LTS_Rewrite::service_url($tjeneste->post_name);
        }

        return array(
            'title' => $title,
            'description' => $description,
            'url' => $url,
        );
    }

    public static function plain_excerpt(string $html, int $words = 30): string
    {
        $text = trim(wp_strip_all_tags($html));
        if ($text === '') {
            return '';
        }

        return wp_trim_words($text, $words, '…');
    }
}
