<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Element_Tjeneste
{
    public static function register_shortcodes(): void
    {
        add_shortcode('lts_tjeneste_navn', array(__CLASS__, 'name'));
        add_shortcode('lts_sted_navn', array(__CLASS__, 'place_name'));
        add_shortcode('lts_tjeneste_intro', array(__CLASS__, 'intro'));
        add_shortcode('lts_kommune_liste', array(__CLASS__, 'municipalities'));
        add_shortcode('lts_lokal_tekst', array(__CLASS__, 'local_text'));
        add_shortcode('lts_pris', array(__CLASS__, 'price'));
        add_shortcode('lts_faq', array(__CLASS__, 'faq'));
    }

    public static function register_builder(): void
    {
        self::add('lts_tjeneste_navn', __('Tjenestenavn', 'lokale-tjenestesider'), LTS_Elements::text_options('h1'));
        self::add('lts_sted_navn', __('Stedsnavn', 'lokale-tjenestesider'), LTS_Elements::text_options('h2'));
        self::add('lts_tjeneste_intro', __('Tjenesteintro', 'lokale-tjenestesider'), LTS_Elements::text_options('div'));
        self::add('lts_kommune_liste', __('Kommuneliste', 'lokale-tjenestesider'), LTS_Elements::box_options());
        self::add('lts_lokal_tekst', __('Lokal tekst', 'lokale-tjenestesider'), LTS_Elements::text_options('div'));
        self::add('lts_pris', __('Pris', 'lokale-tjenestesider'), self::price_options());
        self::add('lts_faq', __('FAQ', 'lokale-tjenestesider'), self::faq_options());
    }

    /**
     * @param mixed $atts
     */
    public static function name($atts): string
    {
        $parsed = LTS_Elements::parse(array('tag' => 'h1', 'color' => '', 'class' => '', 'id' => ''), $atts);
        $tjeneste = LTS_Context::current_tjeneste();
        if (!$tjeneste instanceof WP_Post) {
            return LTS_Elements::wrap('h1', $parsed, 'lts-tjeneste-navn', LTS_Elements::placeholder(__('Tjenestenavn', 'lokale-tjenestesider')));
        }

        $title = esc_html(get_the_title($tjeneste));
        $side = LTS_Context::current_side();
        if ($side instanceof WP_Post && LTS_Repository::service_is_public($tjeneste)) {
            $title = '<a href="' . esc_url(LTS_Rewrite::service_url($tjeneste->post_name)) . '">' . $title . '</a>';
        }

        return LTS_Elements::wrap('h1', $parsed, 'lts-tjeneste-navn', $title);
    }

    /**
     * @param mixed $atts
     */
    public static function place_name($atts): string
    {
        $parsed = LTS_Elements::parse(array('tag' => 'h2', 'color' => '', 'class' => '', 'id' => ''), $atts);
        $tjeneste = LTS_Context::current_tjeneste();
        if (!$tjeneste instanceof WP_Post) {
            return LTS_Elements::wrap('h2', $parsed, 'lts-sted-navn', LTS_Elements::placeholder(__('Stedsnavn', 'lokale-tjenestesider')));
        }

        $kommune = LTS_Context::current_kommune();
        if ($kommune instanceof WP_Post) {
            $text = sprintf(
                /* translators: 1: service name, 2: municipality name */
                __('%1$s i %2$s', 'lokale-tjenestesider'),
                get_the_title($tjeneste),
                get_the_title($kommune)
            );
        } else {
            $text = get_the_title($tjeneste);
        }

        return LTS_Elements::wrap('h2', $parsed, 'lts-sted-navn', esc_html($text));
    }

    /**
     * @param mixed $atts
     */
    public static function intro($atts): string
    {
        $parsed = LTS_Elements::parse(array('tag' => 'div', 'color' => '', 'class' => '', 'id' => ''), $atts);
        $tjeneste = LTS_Context::current_tjeneste();
        if (!$tjeneste instanceof WP_Post) {
            return LTS_Elements::wrap('div', $parsed, 'lts-tjeneste-intro', LTS_Elements::placeholder(__('Tjenesteintro', 'lokale-tjenestesider')));
        }

        $excerpt = trim((string) $tjeneste->post_excerpt);
        $body = LTS_Repository::body($tjeneste->ID);
        $html = '';
        if ($excerpt !== '') {
            $html .= '<p class="lts-ingress">' . esc_html($excerpt) . '</p>';
        }
        if ($body !== '') {
            $html .= wp_kses_post(wpautop($body));
        }
        if ($html === '') {
            $html = LTS_Elements::placeholder(__('Tjenesteintro', 'lokale-tjenestesider'));
        }

        return LTS_Elements::wrap('div', $parsed, 'lts-tjeneste-intro', $html);
    }

    /**
     * @param mixed $atts
     */
    public static function municipalities($atts): string
    {
        $parsed = LTS_Elements::parse(array('heading' => '', 'color' => '', 'class' => '', 'id' => ''), $atts);
        $tjeneste = LTS_Context::current_tjeneste();
        if (!$tjeneste instanceof WP_Post) {
            return LTS_Elements::wrap('div', $parsed, 'lts-kommune-liste', LTS_Elements::placeholder(__('Kommuneliste', 'lokale-tjenestesider')));
        }

        $rows = LTS_Repository::kommuner_for_tjeneste($tjeneste->ID);
        if ($rows === array()) {
            return LTS_Elements::wrap('div', $parsed, 'lts-kommune-liste', LTS_Elements::placeholder(__('Ingen kommuner', 'lokale-tjenestesider')));
        }

        $items = '';
        foreach ($rows as $row) {
            $url = LTS_Rewrite::local_url($tjeneste->post_name, $row['kommune']->post_name);
            $items .= '<li><a href="' . esc_url($url) . '">' . esc_html(get_the_title($row['kommune'])) . '</a></li>';
        }

        return LTS_Elements::wrap('div', $parsed, 'lts-kommune-liste', LTS_Elements::heading_html($parsed) . '<ul class="lts-list">' . $items . '</ul>');
    }

    /**
     * @param mixed $atts
     */
    public static function local_text($atts): string
    {
        $parsed = LTS_Elements::parse(array('tag' => 'div', 'color' => '', 'class' => '', 'id' => ''), $atts);
        $context = LTS_Context::get();
        $text = ($context && isset($context['local_text'])) ? (string) $context['local_text'] : '';
        $inner = $text !== '' ? wp_kses_post(wpautop($text)) : LTS_Elements::placeholder(__('Lokal tekst', 'lokale-tjenestesider'));

        return LTS_Elements::wrap('div', $parsed, 'lts-lokal-tekst', $inner);
    }

    /**
     * @param mixed $atts
     */
    public static function price($atts): string
    {
        $parsed = LTS_Elements::parse(array(
            'tag' => 'p',
            'color' => '',
            'class' => '',
            'id' => '',
            'suffix' => 'kr',
            'linje' => '1',
            'fra' => '0',
        ), $atts);
        $context = LTS_Context::get();
        $amount = ($context && array_key_exists('price', $context) && $context['price'] !== null) ? $context['price'] : null;
        if (!is_numeric($amount)) {
            return LTS_Elements::wrap('p', $parsed, 'lts-pris', LTS_Elements::placeholder(__('Pris', 'lokale-tjenestesider')));
        }

        $decimals = abs((float) $amount - round((float) $amount)) < 0.001 ? 0 : 2;
        $formatted = number_format_i18n((float) $amount, $decimals);
        $suffix = trim((string) $parsed['suffix']);
        $inner = '';
        if ((string) $parsed['fra'] === '1') {
            $inner .= '<span class="lts-pris-fra">' . esc_html__('fra', 'lokale-tjenestesider') . '</span>';
        }
        $inner .= '<span class="lts-pris-belop">' . esc_html(trim($formatted . ' ' . $suffix)) . '</span>';
        $note = ($context && isset($context['price_note'])) ? trim((string) $context['price_note']) : '';
        if ((string) $parsed['linje'] !== '0' && $note !== '') {
            $inner .= '<span class="lts-pris-linje">' . esc_html($note) . '</span>';
        }

        return LTS_Elements::wrap('p', $parsed, 'lts-pris', $inner);
    }

    /**
     * @param mixed $atts
     */
    public static function faq($atts): string
    {
        $parsed = LTS_Elements::parse(array(
            'heading' => '',
            'color' => '',
            'class' => '',
            'id' => '',
            'visning' => 'liste',
            'auto_open' => '',
            'faq_schema' => '',
        ), $atts);
        $context = LTS_Context::get();
        $faq = ($context && isset($context['faq']) && is_array($context['faq'])) ? $context['faq'] : array();
        if ($faq === array()) {
            return LTS_Elements::wrap('div', $parsed, 'lts-faq', LTS_Elements::placeholder(__('FAQ', 'lokale-tjenestesider')));
        }

        if ((string) $parsed['visning'] === 'accordion' && shortcode_exists('accordion') && shortcode_exists('accordion-item')) {
            return LTS_Elements::wrap('div', $parsed, 'lts-faq', self::faq_accordion($parsed, $faq));
        }

        $items = '';
        foreach ($faq as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items .= '<dt>' . esc_html((string) $row['question']) . '</dt>';
            $items .= '<dd>' . wp_kses_post(wpautop((string) $row['answer'])) . '</dd>';
        }

        return LTS_Elements::wrap('div', $parsed, 'lts-faq', LTS_Elements::heading_html($parsed) . '<dl>' . $items . '</dl>');
    }

    /**
     * @param array<string, string> $parsed
     * @param array<int, mixed> $faq
     */
    private static function faq_accordion(array $parsed, array $faq): string
    {
        $items = '';
        foreach ($faq as $row) {
            if (!is_array($row)) {
                continue;
            }
            $question = self::shortcode_text((string) $row['question']);
            $answer = str_replace(']', '&#93;', wpautop((string) $row['answer']));
            $items .= '[accordion-item title="' . $question . '"]' . $answer . '[/accordion-item]';
        }

        $heading = self::shortcode_text((string) $parsed['heading']);
        $auto = (string) $parsed['auto_open'] === 'true' ? 'true' : '';
        $schema = (string) $parsed['faq_schema'] === 'true' ? 'true' : '';
        $shortcode = '[accordion auto_open="' . $auto . '" faq_schema="' . $schema . '" title="' . $heading . '"]' . $items . '[/accordion]';

        return do_shortcode($shortcode);
    }

    private static function shortcode_text(string $value): string
    {
        return str_replace(array('[', ']', '"', '&', '<', '>'), '', $value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function price_options(): array
    {
        $options = LTS_Elements::text_options('p');
        $options['suffix'] = array(
            'type' => 'textfield',
            'heading' => __('Suffiks', 'lokale-tjenestesider'),
            'default' => 'kr',
        );
        $options['fra'] = array(
            'type' => 'select',
            'heading' => __('Fra', 'lokale-tjenestesider'),
            'default' => '0',
            'options' => array(
                '0' => __('Skjul', 'lokale-tjenestesider'),
                '1' => __('Vis', 'lokale-tjenestesider'),
            ),
        );
        $options['linje'] = array(
            'type' => 'select',
            'heading' => __('Prislinje', 'lokale-tjenestesider'),
            'default' => '1',
            'options' => array(
                '1' => __('Vis', 'lokale-tjenestesider'),
                '0' => __('Skjul', 'lokale-tjenestesider'),
            ),
        );

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private static function faq_options(): array
    {
        $options = LTS_Elements::box_options();
        $options['visning'] = array(
            'type' => 'select',
            'heading' => __('Visning', 'lokale-tjenestesider'),
            'default' => 'liste',
            'options' => array(
                'liste' => __('Liste', 'lokale-tjenestesider'),
                'accordion' => __('Accordion', 'lokale-tjenestesider'),
            ),
        );
        $options['auto_open'] = array(
            'type' => 'select',
            'heading' => __('Åpne første', 'lokale-tjenestesider'),
            'default' => '',
            'options' => array(
                '' => __('Av', 'lokale-tjenestesider'),
                'true' => __('På', 'lokale-tjenestesider'),
            ),
        );
        $options['faq_schema'] = array(
            'type' => 'select',
            'heading' => __('FAQ-schema', 'lokale-tjenestesider'),
            'default' => '',
            'options' => array(
                '' => __('Av', 'lokale-tjenestesider'),
                'true' => __('På', 'lokale-tjenestesider'),
            ),
        );

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
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
}
