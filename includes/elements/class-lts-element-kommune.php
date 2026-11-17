<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Element_Kommune
{
    public static function register_shortcodes(): void
    {
        add_shortcode('lts_kommune_navn', array(__CLASS__, 'name'));
        add_shortcode('lts_kommune_intro', array(__CLASS__, 'intro'));
        add_shortcode('lts_tjeneste_liste', array(__CLASS__, 'services'));
        add_shortcode('lts_naerliggende', array(__CLASS__, 'nearby'));
        add_shortcode('lts_kontakt', array(__CLASS__, 'contact'));
        add_shortcode('lts_apningstider', array(__CLASS__, 'hours'));
        add_shortcode('lts_kontaktinfo', array(__CLASS__, 'contact_info'));
        add_shortcode('lts_kontaktknapp', array(__CLASS__, 'contact_button'));
        add_shortcode('lts_kontaktpersoner', array(__CLASS__, 'locator_people'));
        add_shortcode('lts_skjema', array(__CLASS__, 'form'));
        add_shortcode('lts_bilde', array(__CLASS__, 'image'));
        add_shortcode('lts_fylke_innhold', array(__CLASS__, 'blocks'));
        add_shortcode('lts_sok', array(__CLASS__, 'search'));
        add_filter('gform_field_value_lts_fylke', array(__CLASS__, 'field_fylke'));
        add_filter('gform_field_value_lts_kommune', array(__CLASS__, 'field_kommune'));
        add_action('gform_pre_submission', array(__CLASS__, 'lock_place_fields'));
    }

    public static function register_builder(): void
    {
        self::add('lts_kommune_navn', __('Kommunenavn', 'lokale-tjenestesider'), LTS_Elements::text_options('h2'));
        self::add('lts_kommune_intro', __('Kommuneintro', 'lokale-tjenestesider'), LTS_Elements::text_options('div'));
        self::add('lts_tjeneste_liste', __('Tjenesteliste', 'lokale-tjenestesider'), LTS_Elements::box_options());
        self::add('lts_naerliggende', __('Nærliggende kommuner', 'lokale-tjenestesider'), LTS_Elements::box_options());
        self::add('lts_kontakt', __('Kontaktboks', 'lokale-tjenestesider'), self::contact_options());
        self::add('lts_apningstider', __('Åpningstider', 'lokale-tjenestesider'), self::hours_options());
        self::add('lts_kontaktinfo', __('Kontaktinformasjon', 'lokale-tjenestesider'), self::contact_info_options());
        self::add('lts_kontaktknapp', __('Kontaktknapp', 'lokale-tjenestesider'), self::contact_button_options());
        self::add('lts_kontaktpersoner', __('Kontaktpersoner', 'lokale-tjenestesider'), LTS_Elements::box_options());
        self::add('lts_skjema', __('Skjema', 'lokale-tjenestesider'), self::form_options());
        self::add('lts_bilde', __('Bilde', 'lokale-tjenestesider'), self::image_options());
        self::add('lts_fylke_innhold', __('Fylkesinnhold', 'lokale-tjenestesider'), self::block_options());
    }

    /**
     * @param mixed $atts
     */
    public static function name($atts): string
    {
        $parsed = LTS_Elements::parse(array('tag' => 'h2', 'color' => '', 'class' => '', 'id' => ''), $atts);
        $kommune = LTS_Context::current_kommune();
        $inner = $kommune instanceof WP_Post
            ? esc_html(get_the_title($kommune))
            : LTS_Elements::placeholder(__('Kommunenavn', 'lokale-tjenestesider'));

        return LTS_Elements::wrap('h2', $parsed, 'lts-kommune-navn', $inner);
    }

    /**
     * @param mixed $atts
     */
    public static function blocks($atts): string
    {
        $parsed = LTS_Elements::parse(array('heading' => '', 'color' => '', 'class' => '', 'id' => '', 'navn' => ''), $atts);
        $fylke = LTS_Context::current_fylke();
        $tjeneste = LTS_Context::current_tjeneste();
        if (!$fylke instanceof WP_Post || !$tjeneste instanceof WP_Post || !shortcode_exists('block')) {
            return LTS_Elements::wrap('div', $parsed, 'lts-fylke-innhold', LTS_Elements::placeholder(__('Fylkesinnhold', 'lokale-tjenestesider')));
        }

        $wanted = sanitize_title((string) $parsed['navn']);
        $html = '';
        foreach (LTS_Repository::blocks($fylke->ID, $tjeneste->post_name) as $row) {
            if ($wanted !== '' && $row['name'] !== $wanted) {
                continue;
            }
            $block = get_post($row['id']);
            if (!$block instanceof WP_Post || $block->post_type !== 'blocks' || $block->post_status !== 'publish') {
                continue;
            }
            $slug = sanitize_title($block->post_name);
            if ($slug === '') {
                continue;
            }
            $html .= do_shortcode('[block id="' . $slug . '"]');
        }
        if ($html === '') {
            return LTS_Elements::wrap('div', $parsed, 'lts-fylke-innhold', LTS_Elements::placeholder(__('Fylkesinnhold', 'lokale-tjenestesider')));
        }

        return LTS_Elements::wrap('div', $parsed, 'lts-fylke-innhold', LTS_Elements::heading_html($parsed) . $html);
    }

    /**
     * @param mixed $atts
     */
    public static function intro($atts): string
    {
        $parsed = LTS_Elements::parse(array('tag' => 'div', 'color' => '', 'class' => '', 'id' => ''), $atts);
        $kommune = LTS_Context::current_kommune();
        $html = $kommune instanceof WP_Post ? LTS_Repository::description($kommune->ID) : '';
        $inner = $html !== '' ? wp_kses_post(wpautop($html)) : LTS_Elements::placeholder(__('Kommuneintro', 'lokale-tjenestesider'));

        return LTS_Elements::wrap('div', $parsed, 'lts-kommune-intro', $inner);
    }

    /**
     * @param mixed $atts
     */
    public static function services($atts): string
    {
        $parsed = LTS_Elements::parse(array('heading' => '', 'color' => '', 'class' => '', 'id' => ''), $atts);
        $kommune = LTS_Context::current_kommune();
        if (!$kommune instanceof WP_Post) {
            return LTS_Elements::wrap('div', $parsed, 'lts-service-list', LTS_Elements::placeholder(__('Tjenesteliste', 'lokale-tjenestesider')));
        }

        $rows = LTS_Repository::services_for_kommune($kommune->ID);
        if ($rows === array()) {
            return LTS_Elements::wrap('div', $parsed, 'lts-service-list', LTS_Elements::placeholder(__('Ingen tjenester', 'lokale-tjenestesider')));
        }

        $items = '';
        foreach ($rows as $row) {
            $url = LTS_Rewrite::local_url($row['tjeneste']->post_name, $kommune->post_name);
            $items .= '<li><a href="' . esc_url($url) . '">' . esc_html(get_the_title($row['tjeneste'])) . '</a></li>';
        }

        return LTS_Elements::wrap('div', $parsed, 'lts-service-list', LTS_Elements::heading_html($parsed) . '<ul class="lts-list">' . $items . '</ul>');
    }

    /**
     * @param mixed $atts
     */
    public static function nearby($atts): string
    {
        $parsed = LTS_Elements::parse(array('heading' => '', 'color' => '', 'class' => '', 'id' => ''), $atts);
        $kommune = LTS_Context::current_kommune();
        if (!$kommune instanceof WP_Post) {
            return LTS_Elements::wrap('div', $parsed, 'lts-nearby', LTS_Elements::placeholder(__('Nærliggende kommuner', 'lokale-tjenestesider')));
        }

        $neighbors = LTS_Repository::nearby_posts($kommune->ID);
        if ($neighbors === array()) {
            return LTS_Elements::wrap('div', $parsed, 'lts-nearby', LTS_Elements::placeholder(__('Ingen nærliggende kommuner', 'lokale-tjenestesider')));
        }

        $tjeneste = LTS_Context::current_tjeneste();
        $items = '';
        foreach ($neighbors as $neighbor) {
            $label = esc_html(get_the_title($neighbor));
            $linked = false;
            if ($tjeneste instanceof WP_Post) {
                $side = LTS_Repository::side_by_pair($tjeneste->ID, $neighbor->ID, array('publish'));
                if ($side instanceof WP_Post) {
                    $url = LTS_Rewrite::local_url($tjeneste->post_name, $neighbor->post_name);
                    $items .= '<li><a href="' . esc_url($url) . '">' . $label . '</a></li>';
                    $linked = true;
                }
            }
            if (!$linked) {
                $items .= '<li>' . $label . '</li>';
            }
        }

        return LTS_Elements::wrap('div', $parsed, 'lts-nearby', LTS_Elements::heading_html($parsed) . '<ul class="lts-list">' . $items . '</ul>');
    }

    /**
     * @param mixed $atts
     */
    public static function contact($atts): string
    {
        $parsed = LTS_Elements::parse(array(
            'heading' => '',
            'color' => '',
            'class' => '',
            'id' => '',
            'show_hours' => 'true',
        ), $atts);
        $context = LTS_Context::get();
        if (!$context) {
            return LTS_Elements::wrap('aside', $parsed, 'lts-contact', LTS_Elements::placeholder(__('Kontaktboks', 'lokale-tjenestesider')));
        }

        $contact = is_array($context['contact']) ? $context['contact'] : LTS_Repository::empty_contact();
        $html = LTS_Elements::heading_html($parsed);
        $html .= self::office_html($contact, $parsed['show_hours'] !== 'false');
        if ($html === '') {
            return LTS_Elements::wrap('aside', $parsed, 'lts-contact', LTS_Elements::placeholder(__('Kontaktboks', 'lokale-tjenestesider')));
        }

        $gf = self::form_id($context);
        if ($gf > 0) {
            $parsed['class'] = trim($parsed['class'] . ' lts-has-form');
        }
        $extra = $gf > 0 ? ' data-gf-id="' . esc_attr((string) $gf) . '"' : '';
        LTS_Templates::enqueue_assets();
        $tag = 'aside';

        return '<' . $tag . LTS_Elements::attrs($parsed, 'lts-contact') . $extra . '>' . $html . '</' . $tag . '>';
    }

    /**
     * @param mixed $atts
     */
    public static function hours($atts): string
    {
        $parsed = LTS_Elements::parse(array(
            'heading' => '',
            'color' => '',
            'class' => '',
            'id' => '',
            'visning' => 'tabell',
        ), $atts);
        $context = LTS_Context::get();
        if (!$context) {
            return LTS_Elements::wrap('div', $parsed, 'lts-hours-block', LTS_Elements::placeholder(__('Åpningstider', 'lokale-tjenestesider')));
        }

        $contact = is_array($context['contact']) ? $context['contact'] : LTS_Repository::empty_contact();
        $hours = isset($contact['hours']) && is_array($contact['hours']) ? $contact['hours'] : array();
        $html = (string) $parsed['visning'] === 'sammendrag' ? self::hours_summary($hours) : self::hours_html($hours);
        if ($html === '') {
            return LTS_Elements::wrap('div', $parsed, 'lts-hours-block', LTS_Elements::placeholder(__('Åpningstider', 'lokale-tjenestesider')));
        }

        return LTS_Elements::wrap('div', $parsed, 'lts-hours-block', LTS_Elements::heading_html($parsed) . $html);
    }

    /**
     * @param mixed $atts
     */
    public static function contact_info($atts): string
    {
        $parsed = LTS_Elements::parse(array(
            'heading' => '',
            'color' => '',
            'class' => '',
            'id' => '',
            'telefon' => '1',
            'epost' => '1',
            'vakt' => '1',
            'adresse' => '1',
        ), $atts);
        $context = LTS_Context::get();
        if (!$context) {
            return LTS_Elements::wrap('div', $parsed, 'lts-contact-info', LTS_Elements::placeholder(__('Kontaktinformasjon', 'lokale-tjenestesider')));
        }

        $contact = is_array($context['contact']) ? $context['contact'] : LTS_Repository::empty_contact();
        $html = self::fields_html(
            $contact,
            (string) $parsed['telefon'] !== '0',
            (string) $parsed['epost'] !== '0',
            (string) $parsed['adresse'] !== '0',
            (string) $parsed['vakt'] !== '0'
        );
        if ($html === '') {
            return LTS_Elements::wrap('div', $parsed, 'lts-contact-info', LTS_Elements::placeholder(__('Kontaktinformasjon', 'lokale-tjenestesider')));
        }

        return LTS_Elements::wrap('div', $parsed, 'lts-contact-info', LTS_Elements::heading_html($parsed) . $html);
    }

    /**
     * @param mixed $atts
     */
    public static function contact_button($atts): string
    {
        $parsed = LTS_Elements::parse(array(
            'felt' => 'telefon',
            'style' => '',
            'color' => '',
            'size' => '',
            'radius' => '',
            'class' => '',
            'id' => '',
        ), $atts);
        $context = LTS_Context::get();
        if (!$context) {
            return LTS_Elements::placeholder(__('Kontaktknapp', 'lokale-tjenestesider'));
        }

        $contact = is_array($context['contact']) ? $context['contact'] : LTS_Repository::empty_contact();
        $target = self::contact_target($contact, (string) $parsed['felt']);
        if ($target['text'] === '' || $target['link'] === '') {
            return LTS_Elements::placeholder(__('Kontaktknapp', 'lokale-tjenestesider'));
        }

        return self::flatsome_button($target['text'], $target['link'], $parsed);
    }

    /**
     * @param mixed $atts
     */
    public static function locator_people($atts): string
    {
        $parsed = LTS_Elements::parse(array(
            'heading' => '',
            'color' => '',
            'class' => '',
            'id' => '',
        ), $atts);
        $fylke = LTS_Context::current_fylke();
        if (!$fylke instanceof WP_Post || !shortcode_exists('store_locator__employees')) {
            return LTS_Elements::wrap('div', $parsed, 'lts-kontaktpersoner', LTS_Elements::placeholder(__('Kontaktpersoner', 'lokale-tjenestesider')));
        }

        $tokens = array();
        foreach (array(get_the_title($fylke), $fylke->post_name) as $name) {
            $token = sanitize_title((string) $name);
            if ($token !== '' && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }
        if ($tokens === array()) {
            return LTS_Elements::wrap('div', $parsed, 'lts-kontaktpersoner', LTS_Elements::placeholder(__('Kontaktpersoner', 'lokale-tjenestesider')));
        }

        $shortcode = '[store_locator__employees show="filter" query_1_key="employee:fylke" query_1_compare="is" query_1_value_employee-fylke="' . implode(',', $tokens) . '"' . self::people_filter_attrs() . ']';
        $html = do_shortcode($shortcode);
        if (trim($html) === '') {
            return LTS_Elements::wrap('div', $parsed, 'lts-kontaktpersoner', LTS_Elements::placeholder(__('Kontaktpersoner', 'lokale-tjenestesider')));
        }

        return LTS_Elements::wrap('div', $parsed, 'lts-kontaktpersoner', LTS_Elements::heading_html($parsed) . $html);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function form_id(array $context): int
    {
        $tjeneste = isset($context['tjeneste']) && $context['tjeneste'] instanceof WP_Post ? $context['tjeneste'] : null;
        if ($tjeneste instanceof WP_Post) {
            $id = absint(get_post_meta($tjeneste->ID, LTS_META_GF, true));
            if ($id > 0) {
                return $id;
            }
        }

        $contact = isset($context['contact']) && is_array($context['contact']) ? $context['contact'] : array();

        return isset($contact['gf_id']) ? absint($contact['gf_id']) : 0;
    }

    /**
     * @param array<string, string> $place
     */
    private static function field_values(array $place): string
    {
        $pairs = array();
        foreach ($place as $key => $value) {
            if ($value === '') {
                continue;
            }
            $pairs[] = $key . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }

    /**
     * @param array<string, mixed>|null $context
     * @return array{lts_fylke: string, lts_kommune: string}
     */
    private static function place_values($context): array
    {
        $fylke = '';
        $kommune = '';
        if (is_array($context)) {
            if (isset($context['fylke']) && $context['fylke'] instanceof WP_Post) {
                $fylke = get_the_title($context['fylke']);
            }
            if (isset($context['kommune']) && $context['kommune'] instanceof WP_Post) {
                $kommune = get_the_title($context['kommune']);
            }
        }

        return array(
            'lts_fylke' => $fylke,
            'lts_kommune' => $kommune,
        );
    }

    /**
     * @param mixed $value
     */
    public static function field_fylke($value): string
    {
        $place = self::place_values(self::page_context());

        return $place['lts_fylke'] !== '' ? $place['lts_fylke'] : (string) $value;
    }

    /**
     * @param mixed $value
     */
    public static function field_kommune($value): string
    {
        $place = self::place_values(self::page_context());

        return $place['lts_kommune'] !== '' ? $place['lts_kommune'] : (string) $value;
    }

    /**
     * @param mixed $form
     */
    public static function lock_place_fields($form): void
    {
        if (!is_array($form) || !isset($form['fields']) || !is_array($form['fields'])) {
            return;
        }

        $place = self::place_values(self::page_context());
        if ($place['lts_fylke'] === '' && $place['lts_kommune'] === '') {
            return;
        }

        foreach ($form['fields'] as $field) {
            $name = '';
            $id = 0;
            if (is_object($field)) {
                $name = isset($field->inputName) ? (string) $field->inputName : '';
                $id = isset($field->id) ? (int) $field->id : 0;
            } elseif (is_array($field)) {
                $name = isset($field['inputName']) ? (string) $field['inputName'] : '';
                $id = isset($field['id']) ? (int) $field['id'] : 0;
            }
            if ($id < 1 || !isset($place[$name])) {
                continue;
            }
            $_POST['input_' . $id] = $place[$name];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function page_context(): ?array
    {
        $context = LTS_Context::get();
        if (is_array($context) && self::has_place($context)) {
            return $context;
        }

        $service = get_query_var('lts_service');
        if (is_string($service) && LTS_Repository::is_slug($service)) {
            $kommune = get_query_var('lts_kommune');
            $resolved = LTS_Repository::resolve_public($service, is_string($kommune) && $kommune !== '' ? $kommune : null);
            if (is_array($resolved)) {
                return $resolved;
            }
        }

        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : '';

        return self::context_from_url($referer);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function has_place(array $context): bool
    {
        return (isset($context['fylke']) && $context['fylke'] instanceof WP_Post)
            || (isset($context['kommune']) && $context['kommune'] instanceof WP_Post);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function context_from_url(string $url): ?array
    {
        if ($url === '') {
            return null;
        }

        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($home_host) || !is_string($host) || strtolower($home_host) !== strtolower($host)) {
            return null;
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $path = trim($path, '/');
        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        $home_path = is_string($home_path) ? trim($home_path, '/') : '';
        if ($home_path !== '' && ($path === $home_path || strpos($path, $home_path . '/') === 0)) {
            $path = trim(substr($path, strlen($home_path)), '/');
        }

        $parts = $path === '' ? array() : explode('/', $path);
        $matches = array();
        foreach (LTS_Repository::service_slugs() as $slug) {
            $service = LTS_Repository::published_by_slug(LTS_TJENESTE, $slug);
            if (!$service instanceof WP_Post) {
                continue;
            }
            $base = LTS_Repository::service_base($service);
            $candidates = array(array($slug));
            if ($base !== '') {
                $candidates[] = array($base, $slug);
            }
            foreach ($candidates as $prefix) {
                if (array_slice($parts, 0, count($prefix)) !== $prefix) {
                    continue;
                }
                $rest = array_slice($parts, count($prefix));
                if (count($rest) > 1) {
                    continue;
                }
                $matches[] = array(
                    'slug' => $slug,
                    'kommune' => isset($rest[0]) ? $rest[0] : null,
                    'length' => count($prefix),
                );
            }
        }

        if ($matches === array()) {
            return null;
        }

        usort($matches, static function (array $a, array $b): int {
            return $b['length'] <=> $a['length'];
        });
        $best = $matches[0];
        $kommune = is_string($best['kommune']) && LTS_Repository::is_slug($best['kommune']) ? $best['kommune'] : null;

        return LTS_Repository::resolve_public($best['slug'], $kommune);
    }

    /**
     * @param mixed $atts
     */
    public static function form($atts): string
    {
        $parsed = LTS_Elements::parse(array(
            'heading' => '',
            'color' => '',
            'class' => '',
            'id' => '',
            'tittel' => '0',
            'beskrivelse' => '0',
            'ajax' => '0',
        ), $atts);
        $context = LTS_Context::get();
        $form_id = self::form_id(is_array($context) ? $context : array());
        if (!$context || $form_id < 1 || !shortcode_exists('gravityform')) {
            return LTS_Elements::wrap('div', $parsed, 'lts-skjema', LTS_Elements::placeholder(__('Skjema', 'lokale-tjenestesider')));
        }

        $title = (string) $parsed['tittel'] === '1' ? 'true' : 'false';
        $description = (string) $parsed['beskrivelse'] === '1' ? 'true' : 'false';
        $ajax = (string) $parsed['ajax'] === '1' ? 'true' : 'false';
        $shortcode = '[gravityform id="' . $form_id . '" title="' . $title . '" description="' . $description . '" ajax="' . $ajax . '"';
        $values = self::field_values(self::place_values($context));
        if ($values !== '') {
            $shortcode .= ' field_values="' . $values . '"';
        }
        $html = do_shortcode($shortcode . ']');
        if (trim($html) === '') {
            return LTS_Elements::wrap('div', $parsed, 'lts-skjema', LTS_Elements::placeholder(__('Skjema', 'lokale-tjenestesider')));
        }

        return LTS_Elements::wrap('div', $parsed, 'lts-skjema', LTS_Elements::heading_html($parsed) . $html);
    }

    /**
     * @param mixed $atts
     */
    public static function image($atts): string
    {
        $parsed = LTS_Elements::parse(array(
            'heading' => '',
            'color' => '',
            'class' => '',
            'id' => '',
            'size' => 'large',
            'ratio' => '',
        ), $atts);
        $kommune = LTS_Context::current_kommune();
        $fylke = LTS_Context::current_fylke();
        $image_id = $kommune instanceof WP_Post ? (int) get_post_thumbnail_id($kommune) : 0;
        if ($image_id < 1 && $fylke instanceof WP_Post) {
            $image_id = (int) get_post_thumbnail_id($fylke);
        }
        $attachment = $image_id > 0 ? get_post($image_id) : null;
        if (!$attachment instanceof WP_Post || $attachment->post_type !== 'attachment') {
            return LTS_Elements::wrap('div', $parsed, 'lts-bilde', LTS_Elements::placeholder(__('Bilde', 'lokale-tjenestesider')));
        }

        $size = (string) $parsed['size'];
        if (!in_array($size, array('thumbnail', 'medium', 'large', 'full'), true)) {
            $size = 'large';
        }
        $place = $kommune instanceof WP_Post ? $kommune : $fylke;
        $alt = (string) get_post_meta($image_id, '_wp_attachment_image_alt', true);
        if ($alt === '' && $place instanceof WP_Post) {
            $alt = get_the_title($place);
        }
        $html = wp_get_attachment_image($image_id, $size, false, array(
            'class' => 'lts-place-image',
            'alt' => $alt,
        ));
        if ($html === '') {
            return LTS_Elements::wrap('div', $parsed, 'lts-bilde', LTS_Elements::placeholder(__('Bilde', 'lokale-tjenestesider')));
        }

        $ratio = self::image_ratio((string) $parsed['ratio']);
        if ($ratio !== '') {
            $html = '<div class="lts-bilde-frame has-ratio" style="' . esc_attr('--lts-img-height:' . $ratio) . '">' . $html . '</div>';
        }

        return LTS_Elements::wrap('div', $parsed, 'lts-bilde', LTS_Elements::heading_html($parsed) . $html);
    }

    private static function image_ratio(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d+(?:\.\d+)?%$/', $value)) {
            return $value;
        }
        if (preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            $number = (float) $value;
            if ($number > 0 && $number <= 1000) {
                return rtrim(rtrim(sprintf('%.2f', $number), '0'), '.') . '%';
            }
        }

        return '';
    }

    private static function people_filter_attrs(): string
    {
        $filter = LTS_Repository::people_filter(LTS_Context::current_tjeneste());
        $field = $filter['field'];
        if ($field === '' || ($filter['mode'] !== 'basert' && $filter['mode'] !== 'med')) {
            return '';
        }

        if ($filter['mode'] === 'med') {
            return ' show_filters="true" filter_1_source="employee:' . $field . '"';
        }
        if ($filter['value'] === '') {
            return '';
        }

        $source = 'employee:' . $field;
        $attr = sanitize_key(str_replace(':', '-', $source));

        return ' query_2_key="' . $source . '" query_2_compare="is" query_2_value_' . $attr . '="' . $filter['value'] . '"';
    }

    /**
     * @return array<string, mixed>
     */
    private static function contact_options(): array
    {
        $options = LTS_Elements::box_options();
        $options['show_hours'] = array(
            'type' => 'checkbox',
            'heading' => __('Åpningstider', 'lokale-tjenestesider'),
            'default' => 'true',
        );

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private static function hours_options(): array
    {
        $options = LTS_Elements::box_options();
        $options['visning'] = array(
            'type' => 'select',
            'heading' => __('Visning', 'lokale-tjenestesider'),
            'default' => 'tabell',
            'options' => array(
                'tabell' => __('Tabell', 'lokale-tjenestesider'),
                'sammendrag' => __('Sammendrag', 'lokale-tjenestesider'),
            ),
        );

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private static function contact_info_options(): array
    {
        $options = LTS_Elements::box_options();
        $options['telefon'] = self::show_option(__('Telefon', 'lokale-tjenestesider'));
        $options['epost'] = self::show_option(__('E-post', 'lokale-tjenestesider'));
        $options['vakt'] = self::show_option(__('Vaktnummer', 'lokale-tjenestesider'));
        $options['adresse'] = self::show_option(__('Adresse', 'lokale-tjenestesider'));

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private static function contact_button_options(): array
    {
        return array(
            'felt' => array(
                'type' => 'select',
                'heading' => __('Felt', 'lokale-tjenestesider'),
                'default' => 'telefon',
                'options' => array(
                    'telefon' => __('Telefon', 'lokale-tjenestesider'),
                    'epost' => __('E-post', 'lokale-tjenestesider'),
                    'vakt' => __('Vaktnummer', 'lokale-tjenestesider'),
                ),
            ),
            'style' => array(
                'type' => 'select',
                'heading' => __('Stil', 'lokale-tjenestesider'),
                'default' => '',
                'options' => array(
                    '' => __('Standard', 'lokale-tjenestesider'),
                    'outline' => __('Omriss', 'lokale-tjenestesider'),
                    'link' => __('Lenke', 'lokale-tjenestesider'),
                    'underline' => __('Understreket', 'lokale-tjenestesider'),
                    'shade' => __('Skygge', 'lokale-tjenestesider'),
                    'bevel' => __('Fase', 'lokale-tjenestesider'),
                    'gloss' => __('Glans', 'lokale-tjenestesider'),
                ),
            ),
            'color' => array(
                'type' => 'select',
                'heading' => __('Farge', 'lokale-tjenestesider'),
                'default' => '',
                'options' => array(
                    '' => __('Standard', 'lokale-tjenestesider'),
                    'primary' => __('Primær', 'lokale-tjenestesider'),
                    'secondary' => __('Sekundær', 'lokale-tjenestesider'),
                    'alert' => __('Varsel', 'lokale-tjenestesider'),
                    'success' => __('Suksess', 'lokale-tjenestesider'),
                    'white' => __('Hvit', 'lokale-tjenestesider'),
                ),
            ),
            'size' => array(
                'type' => 'select',
                'heading' => __('Størrelse', 'lokale-tjenestesider'),
                'default' => '',
                'options' => array(
                    '' => __('Normal', 'lokale-tjenestesider'),
                    'xxsmall' => 'XX-Small',
                    'xsmall' => 'X-Small',
                    'smaller' => 'Smaller',
                    'small' => 'Small',
                    'large' => 'Large',
                    'larger' => 'Larger',
                    'xlarge' => 'X-Large',
                    'xxlarge' => 'XX-Large',
                ),
            ),
            'radius' => array(
                'type' => 'slider',
                'heading' => __('Radius', 'lokale-tjenestesider'),
                'default' => '0',
                'min' => '0',
                'max' => '99',
            ),
            'advanced_options' => LTS_Elements::advanced_options(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private static function form_options(): array
    {
        $options = LTS_Elements::box_options();
        $options['tittel'] = array(
            'type' => 'select',
            'heading' => __('Skjematittel', 'lokale-tjenestesider'),
            'default' => '0',
            'options' => array(
                '0' => __('Skjul', 'lokale-tjenestesider'),
                '1' => __('Vis', 'lokale-tjenestesider'),
            ),
        );
        $options['beskrivelse'] = array(
            'type' => 'select',
            'heading' => __('Skjemabeskrivelse', 'lokale-tjenestesider'),
            'default' => '0',
            'options' => array(
                '0' => __('Skjul', 'lokale-tjenestesider'),
                '1' => __('Vis', 'lokale-tjenestesider'),
            ),
        );
        $options['ajax'] = array(
            'type' => 'select',
            'heading' => __('Ajax', 'lokale-tjenestesider'),
            'default' => '0',
            'options' => array(
                '0' => __('Av', 'lokale-tjenestesider'),
                '1' => __('På', 'lokale-tjenestesider'),
            ),
        );

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private static function image_options(): array
    {
        $options = LTS_Elements::box_options();
        $options['size'] = array(
            'type' => 'select',
            'heading' => __('Størrelse', 'lokale-tjenestesider'),
            'default' => 'large',
            'options' => array(
                'thumbnail' => __('Miniatyr', 'lokale-tjenestesider'),
                'medium' => __('Medium', 'lokale-tjenestesider'),
                'large' => __('Stor', 'lokale-tjenestesider'),
                'full' => __('Full', 'lokale-tjenestesider'),
            ),
        );
        $options['ratio'] = array(
            'type' => 'scrubfield',
            'heading' => __('Sideforhold', 'lokale-tjenestesider'),
            'default' => '',
            'placeholder' => __('Auto', 'lokale-tjenestesider'),
            'min' => 0,
            'max' => 1000,
            'step' => 1,
            'helpers' => array(
                array('title' => __('Auto', 'lokale-tjenestesider'), 'value' => ''),
                array('title' => '1:1', 'value' => '100%'),
                array('title' => '4:3', 'value' => '75%'),
                array('title' => '16:9', 'value' => '56.25%'),
                array('title' => '1:2', 'value' => '50%'),
            ),
        );

        return $options;
    }

    private static function show_option(string $heading): array
    {
        return array(
            'type' => 'select',
            'heading' => $heading,
            'default' => '1',
            'options' => array(
                '1' => __('Vis', 'lokale-tjenestesider'),
                '0' => __('Skjul', 'lokale-tjenestesider'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    /**
     * @return array<string, mixed>
     */
    private static function block_options(): array
    {
        $options = LTS_Elements::box_options();
        $options['navn'] = array(
            'type' => 'textfield',
            'heading' => __('Navn på blokken', 'lokale-tjenestesider'),
            'default' => '',
        );

        return $options;
    }

    private static function add(string $tag, string $name, array $options): void
    {
        add_ux_builder_shortcode($tag, array(
            'name' => $name,
            'category' => __('Tjenestesider', 'lokale-tjenestesider'),
            'priority' => -100,
            'wrap' => false,
            'options' => $options,
        ));
    }

    /**
     * @param array<string, mixed> $contact
     */
    private static function office_html(array $contact, bool $show_hours): string
    {
        $html = self::fields_html($contact, true, true, true, true);
        if ($show_hours && isset($contact['hours']) && is_array($contact['hours'])) {
            $html .= self::hours_html($contact['hours']);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $contact
     */
    private static function fields_html(array $contact, bool $show_phone, bool $show_email, bool $show_address, bool $show_emergency): string
    {
        $html = '';
        $phone = isset($contact['phone']) ? (string) $contact['phone'] : '';
        $email = isset($contact['email']) ? (string) $contact['email'] : '';
        $address = isset($contact['address']) ? (string) $contact['address'] : '';
        $emergency = isset($contact['emergency']) ? (string) $contact['emergency'] : '';

        if ($show_phone && $phone !== '') {
            $href = LTS_Elements::tel_href($phone);
            $html .= '<p class="lts-contact__phone"><a href="' . esc_attr($href) . '">' . esc_html($phone) . '</a></p>';
        }
        if ($show_email && $email !== '') {
            $html .= '<p class="lts-contact__email"><a href="' . esc_attr('mailto:' . $email) . '">' . esc_html($email) . '</a></p>';
        }
        if ($show_address && $address !== '') {
            $html .= '<p class="lts-contact__address">' . nl2br(esc_html($address)) . '</p>';
        }
        if ($show_emergency && $emergency !== '') {
            $href = LTS_Elements::tel_href($emergency);
            $html .= '<p class="lts-contact__emergency">' . esc_html__('Vakt', 'lokale-tjenestesider') . ': <a href="' . esc_attr($href) . '">' . esc_html($emergency) . '</a></p>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $hours
     */
    private static function hours_html(array $hours): string
    {
        $rows = '';
        foreach (LTS_Repository::weekdays() as $day => $label) {
            if (!array_key_exists($day, $hours)) {
                continue;
            }
            $rows .= '<li><span>' . esc_html($label) . '</span> <span>' . esc_html(self::hour_value($hours[$day])) . '</span></li>';
        }

        if ($rows === '') {
            return '';
        }

        return '<ul class="lts-hours">' . $rows . '</ul>';
    }

    /**
     * @param array<string, mixed> $hours
     */
    private static function hours_summary(array $hours): string
    {
        $groups = array();
        $current = null;
        foreach (LTS_Repository::weekdays() as $day => $label) {
            if (!array_key_exists($day, $hours)) {
                if ($current !== null) {
                    $groups[] = $current;
                    $current = null;
                }
                continue;
            }
            $value = self::hour_value($hours[$day]);
            if ($current !== null && $current['value'] === $value) {
                $current['labels'][] = $label;
                continue;
            }
            if ($current !== null) {
                $groups[] = $current;
            }
            $current = array(
                'labels' => array($label),
                'value' => $value,
            );
        }
        if ($current !== null) {
            $groups[] = $current;
        }

        $rows = '';
        foreach ($groups as $group) {
            $rows .= '<li><span>' . esc_html(self::day_span($group['labels'])) . ':</span> <span>' . esc_html($group['value']) . '</span></li>';
        }

        return $rows === '' ? '' : '<ul class="lts-hours lts-hours--summary">' . $rows . '</ul>';
    }

    /**
     * @param string[] $labels
     */
    private static function day_span(array $labels): string
    {
        $count = count($labels);
        if ($count <= 1) {
            return $count === 1 ? $labels[0] : '';
        }
        if ($count === 2) {
            return $labels[0] . ' ' . __('og', 'lokale-tjenestesider') . ' ' . $labels[1];
        }

        return $labels[0] . ' – ' . $labels[$count - 1];
    }

    /**
     * @param mixed $slot
     */
    private static function hour_value($slot): string
    {
        if (!is_array($slot)) {
            return __('Stengt', 'lokale-tjenestesider');
        }
        $opens = isset($slot['opens']) ? (string) $slot['opens'] : '';
        $closes = isset($slot['closes']) ? (string) $slot['closes'] : '';
        if ($opens === '' || $closes === '') {
            return __('Stengt', 'lokale-tjenestesider');
        }

        return $opens . '–' . $closes;
    }

    /**
     * @param array<string, mixed> $contact
     * @return array{text: string, link: string}
     */
    private static function contact_target(array $contact, string $field): array
    {
        if ($field === 'epost') {
            $email = isset($contact['email']) ? sanitize_email((string) $contact['email']) : '';

            return array(
                'text' => $email,
                'link' => $email !== '' ? 'mailto:' . $email : '',
            );
        }

        $key = $field === 'vakt' ? 'emergency' : 'phone';
        $number = isset($contact[$key]) ? trim((string) $contact[$key]) : '';
        $link = $number !== '' ? LTS_Elements::tel_href($number) : '';

        return array(
            'text' => $link !== '' ? $number : '',
            'link' => $link,
        );
    }

    /**
     * @param array<string, string> $parsed
     */
    private static function flatsome_button(string $text, string $link, array $parsed): string
    {
        $text = self::shortcode_text($text);
        $link = self::shortcode_text($link);
        if ($text === '' || $link === '') {
            return LTS_Elements::placeholder(__('Kontaktknapp', 'lokale-tjenestesider'));
        }
        if (!shortcode_exists('button')) {
            return '<a class="button" href="' . esc_url($link) . '">' . esc_html($text) . '</a>';
        }

        $attrs = ' text="' . $text . '" link="' . $link . '"';
        $style = self::one_of((string) $parsed['style'], array('outline', 'link', 'underline', 'shade', 'bevel', 'gloss'));
        if ($style !== '') {
            $attrs .= ' style="' . $style . '"';
        }
        $color = self::one_of((string) $parsed['color'], array('primary', 'secondary', 'alert', 'success', 'white'));
        if ($color !== '') {
            $attrs .= ' color="' . $color . '"';
        }
        $size = self::one_of((string) $parsed['size'], array('xxsmall', 'xsmall', 'smaller', 'small', 'large', 'larger', 'xlarge', 'xxlarge'));
        if ($size !== '') {
            $attrs .= ' size="' . $size . '"';
        }
        $radius = (string) $parsed['radius'];
        if (preg_match('/^\d{1,2}$/', $radius) && (int) $radius > 0 && (int) $radius <= 99) {
            $attrs .= ' radius="' . $radius . '"';
        }
        $class = self::class_list((string) $parsed['class']);
        if ($class !== '') {
            $attrs .= ' class="' . $class . '"';
        }
        $id = sanitize_html_class((string) $parsed['id']);
        if ($id !== '') {
            $attrs .= ' id="' . $id . '"';
        }

        return do_shortcode('[button' . $attrs . ']');
    }

    private static function shortcode_text(string $value): string
    {
        return str_replace(array('[', ']', '"', '&', '<', '>'), '', $value);
    }

    /**
     * @param string[] $allowed
     */
    private static function one_of(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : '';
    }

    private static function class_list(string $value): string
    {
        $classes = array();
        foreach (preg_split('/\s+/', $value) ?: array() as $part) {
            $class = sanitize_html_class($part);
            if ($class !== '') {
                $classes[] = $class;
            }
        }

        return implode(' ', $classes);
    }

    public static function register_search(): void
    {
        self::add('lts_sok', __('Kommunesøk', 'lokale-tjenestesider'), self::search_options());
    }

    /**
     * @param mixed $atts
     */
    public static function search($atts): string
    {
        $parsed = LTS_Elements::parse(array(
            'heading' => '',
            'color' => '',
            'class' => '',
            'id' => '',
            'tjeneste' => '',
        ), $atts);
        $service = self::search_service(sanitize_title((string) $parsed['tjeneste']));
        $rows = self::search_rows($service);
        $count = count($rows);
        $fylker = array();
        foreach ($rows as $row) {
            if ($row['fylke_slug'] === '' || isset($fylker[$row['fylke_slug']])) {
                continue;
            }
            $fylker[$row['fylke_slug']] = $row['fylke'];
        }
        asort($fylker);

        $options = '<option value="">' . esc_html(sprintf(__('Alle fylker (%s)', 'lokale-tjenestesider'), number_format_i18n($count))) . '</option>';
        foreach ($fylker as $slug => $title) {
            $options .= '<option value="' . esc_attr($slug) . '">' . esc_html($title) . '</option>';
        }

        $items = '';
        foreach ($rows as $row) {
            $label = '<span class="lts-sok__place">' . esc_html($row['name']) . '</span>';
            $label .= '<span class="lts-sok__county">' . esc_html($row['fylke']) . '</span>';
            if ($row['url'] !== '') {
                $body = '<a class="lts-sok__row" href="' . esc_url($row['url']) . '">' . $label . '</a>';
            } else {
                $body = '<span class="lts-sok__row">' . $label . '</span>';
            }
            $items .= '<li class="lts-sok__item" data-name="' . esc_attr(self::search_fold($row['name'])) . '" data-fylke="' . esc_attr(self::search_fold($row['fylke'])) . '" data-fylke-slug="' . esc_attr($row['fylke_slug']) . '" data-postnummer="' . esc_attr(implode(' ', $row['codes'])) . '">' . $body . '</li>';
        }

        LTS_Templates::register_assets();
        wp_enqueue_script('lts-sok');

        $html = LTS_Elements::heading_html($parsed);
        $html .= '<div class="lts-sok" data-lts-sok data-label-one="' . esc_attr(__('sted', 'lokale-tjenestesider')) . '" data-label-many="' . esc_attr(__('steder', 'lokale-tjenestesider')) . '" data-label-all="' . esc_attr(__('Alle fylker (%s)', 'lokale-tjenestesider')) . '">';
        $html .= '<div class="lts-sok__controls">';
        $html .= '<input class="lts-sok__input" type="search" placeholder="' . esc_attr__('Søk etter kommune eller postnummer', 'lokale-tjenestesider') . '">';
        $html .= '<select class="lts-sok__fylke">' . $options . '</select>';
        $html .= '</div>';
        $html .= '<p class="lts-sok__count">' . esc_html(sprintf(_n('%s sted', '%s steder', $count, 'lokale-tjenestesider'), number_format_i18n($count))) . '</p>';
        $html .= '<ul class="lts-sok__list">' . $items . '</ul>';
        $html .= '</div>';

        return LTS_Elements::wrap('div', $parsed, 'lts-sok-block', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private static function search_options(): array
    {
        $choices = array('' => __('Automatisk', 'lokale-tjenestesider'));
        $services = get_posts(array(
            'post_type' => LTS_TJENESTE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ));
        foreach ($services as $service) {
            if ($service instanceof WP_Post) {
                $choices[$service->post_name] = get_the_title($service);
            }
        }

        return array(
            'tjeneste' => array(
                'type' => 'select',
                'heading' => __('Tjeneste', 'lokale-tjenestesider'),
                'default' => '',
                'options' => $choices,
            ),
        ) + LTS_Elements::box_options();
    }

    private static function search_service(string $slug): ?WP_Post
    {
        if ($slug !== '') {
            $service = LTS_Repository::by_slug(LTS_TJENESTE, $slug);
            if ($service instanceof WP_Post && $service->post_status === 'publish') {
                return $service;
            }

            return null;
        }

        $current = LTS_Context::current_tjeneste();

        return ($current instanceof WP_Post && $current->post_status === 'publish') ? $current : null;
    }

    /**
     * @return array<int, array{name: string, fylke: string, fylke_slug: string, codes: string[], url: string}>
     */
    private static function search_rows(?WP_Post $service): array
    {
        $allowed = $service instanceof WP_Post ? self::published_kommune_ids($service->ID) : null;
        $posts = get_posts(array(
            'post_type' => LTS_KOMMUNE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ));
        $rows = array();
        foreach ($posts as $kommune) {
            if (!$kommune instanceof WP_Post) {
                continue;
            }
            if (is_array($allowed) && empty($allowed[$kommune->ID])) {
                continue;
            }
            $fylke = LTS_Repository::fylke_for_kommune($kommune);
            $rows[] = array(
                'name' => get_the_title($kommune),
                'fylke' => $fylke instanceof WP_Post ? get_the_title($fylke) : '',
                'fylke_slug' => $fylke instanceof WP_Post ? $fylke->post_name : '',
                'codes' => LTS_Repository::postnummer($kommune->ID),
                'url' => $service instanceof WP_Post ? LTS_Rewrite::local_url($service->post_name, $kommune->post_name) : '',
            );
        }

        return $rows;
    }

    /**
     * @return array<int, bool>
     */
    private static function published_kommune_ids(int $tjeneste_id): array
    {
        $posts = get_posts(array(
            'post_type' => LTS_SIDE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_key' => LTS_META_TJENESTE,
            'meta_value' => (string) $tjeneste_id,
        ));
        $ids = array();
        foreach ($posts as $id) {
            $kommune_id = (int) get_post_meta((int) $id, LTS_META_KOMMUNE, true);
            if ($kommune_id > 0) {
                $ids[$kommune_id] = true;
            }
        }

        return $ids;
    }

    private static function search_fold(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
