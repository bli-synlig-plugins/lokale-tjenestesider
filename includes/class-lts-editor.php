<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Editor
{
    public static function init(): void
    {
        add_action('edit_form_after_title', array(__CLASS__, 'render'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_lts_save_editor', array(__CLASS__, 'ajax_save'));
        add_filter('admin_body_class', array(__CLASS__, 'body_class'));
    }

    public static function body_class(string $classes): string
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->base === 'post' && self::is_editor((string) $screen->post_type)) {
            $classes .= ' lts-screen-editor';
        }

        return $classes;
    }

    public static function assets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'post' || !self::is_editor((string) $screen->post_type)) {
            return;
        }

        global $post;
        if (!$post instanceof WP_Post) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('lts-editor', LTS_URL . 'assets/js/editor.js', array(), LTS_VERSION, true);
        wp_localize_script('lts-editor', 'ltsEditor', self::config($post));
    }

    public static function render(WP_Post $post): void
    {
        if (!self::is_editor($post->post_type)) {
            return;
        }

        $heading = self::heading($post->post_type);
        $tabs = self::tabs($post->post_type);
        $first = true;

        echo '<div id="lts-editor" class="lts-admin lts-editor" data-type="' . esc_attr($post->post_type) . '">';
        wp_nonce_field('lts_save_editor', 'lts_editor_nonce');
        echo '<header class="lts-top"><div><h1>' . esc_html($heading[0]) . '</h1>';
        echo '<p>' . esc_html($heading[1]) . '</p></div></header>';
        echo '<input type="text" class="lts-title-input" id="lts-title" name="lts_title" value="' . esc_attr(get_the_title($post)) . '" placeholder="' . esc_attr__('Navn', 'lokale-tjenestesider') . '" autocomplete="off">';
        echo '<p class="lts-slug-note"' . ($post->post_name === '' ? ' hidden' : '') . '>' . esc_html__('Slug:', 'lokale-tjenestesider') . ' <span id="lts-slug-note">' . esc_html($post->post_name) . '</span></p>';
        echo '<div class="lts-editor-grid">';
        echo '<nav class="lts-tabs" role="tablist">';
        foreach ($tabs as $id => $label) {
            echo '<button type="button" role="tab" id="lts-tab-' . esc_attr($id) . '" aria-controls="lts-panel-' . esc_attr($id) . '" aria-selected="' . ($first ? 'true' : 'false') . '">';
            echo esc_html($label);
            if ($id === 'services') {
                echo '<span class="lts-badge" data-lts-offer-count hidden>0</span>';
            }
            echo '</button>';
            $first = false;
        }
        echo '</nav>';
        echo '<div class="lts-editor-main">';
        $first = true;
        foreach ($tabs as $id => $label) {
            unset($label);
            echo '<section class="lts-card" role="tabpanel" id="lts-panel-' . esc_attr($id) . '" aria-labelledby="lts-tab-' . esc_attr($id) . '"' . ($first ? '' : ' hidden') . '>';
            self::panel($id, $post);
            echo '</section>';
            $first = false;
        }
        echo '</div>';
        self::aside($post);
        echo '</div>';
        echo '<div class="lts-dirty" hidden>';
        echo '<span>' . esc_html__('Du har ulagrede endringer', 'lokale-tjenestesider') . '</span>';
        echo '<button type="button" class="lts-btn" data-lts-reset>' . esc_html__('Tilbakestill', 'lokale-tjenestesider') . '</button>';
        echo '<button type="button" class="lts-btn is-primary" data-lts-save>' . esc_html__('Lagre endringer', 'lokale-tjenestesider') . '</button>';
        echo '</div>';
        echo '<div class="lts-toast" hidden role="status"></div>';
        echo '</div>';
    }

    public static function ajax_save(): void
    {
        $post_id = isset($_POST['post_ID']) ? absint($_POST['post_ID']) : 0;
        check_ajax_referer('lts_save_editor', 'lts_editor_nonce');

        $post = get_post($post_id);
        if (!$post instanceof WP_Post || !self::is_editor($post->post_type)) {
            wp_send_json_error(array('message' => __('Ugyldig innlegg.', 'lokale-tjenestesider')), 400);
        }
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Du har ikke tilgang til å lagre.', 'lokale-tjenestesider')), 403);
        }

        $_POST['lts_meta_nonce'] = wp_create_nonce('lts_save_meta');
        $_POST['post_ID'] = (string) $post_id;

        $title = isset($_POST['lts_title']) ? sanitize_text_field(wp_unslash($_POST['lts_title'])) : '';
        $update = array(
            'ID' => $post_id,
            'post_title' => $title,
            'post_status' => self::status_from_post(),
        );
        $slug_warning = self::slug_update($post, $update);
        if ($post->post_type === LTS_TJENESTE) {
            $update['post_excerpt'] = isset($_POST['lts_excerpt']) ? sanitize_text_field(wp_unslash($_POST['lts_excerpt'])) : '';
        }

        $result = wp_update_post(wp_slash($update), true);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }

        $with_image = array(LTS_TJENESTE, LTS_FYLKE, LTS_KOMMUNE);
        if (in_array($post->post_type, $with_image, true) && isset($_POST['lts_thumbnail'])) {
            $thumb = absint($_POST['lts_thumbnail']);
            if ($thumb > 0) {
                set_post_thumbnail($post_id, $thumb);
            } else {
                delete_post_thumbnail($post_id);
            }
        }

        $notice_key = 'lts_admin_notice_' . get_current_user_id();
        $notice = get_transient($notice_key);
        if (is_string($notice) && $notice !== '') {
            delete_transient($notice_key);
        } else {
            $notice = '';
        }
        if ($slug_warning !== '') {
            $notice = $notice === '' ? $slug_warning : $slug_warning . ' ' . $notice;
        }

        $fresh = get_post($post_id);
        if (!$fresh instanceof WP_Post) {
            wp_send_json_error(array('message' => __('Kunne ikke lese innlegget etter lagring.', 'lokale-tjenestesider')), 500);
        }

        wp_send_json_success(array(
            'message' => __('Endringene er lagret', 'lokale-tjenestesider'),
            'warning' => $notice,
            'status' => $fresh->post_status,
            'date' => self::date_label($fresh),
            'preview' => self::preview_url($fresh),
            'layouts' => $fresh->post_type === LTS_KOMMUNE ? self::layout_map($fresh->ID) : array(),
            'slug' => $fresh->post_name,
        ));
    }

    private static function is_editor(string $type): bool
    {
        return in_array($type, array(LTS_FYLKE, LTS_KOMMUNE, LTS_TJENESTE), true);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function heading(string $type): array
    {
        if ($type === LTS_FYLKE) {
            return array(
                __('Rediger fylke', 'lokale-tjenestesider'),
                __('Kontakt, åpningstider, priser og FAQ som kommunene arver.', 'lokale-tjenestesider'),
            );
        }
        if ($type === LTS_TJENESTE) {
            return array(
                __('Rediger tjeneste', 'lokale-tjenestesider'),
                __('Hub-siden bruker navnet som tittel og ingressen som metabeskrivelse.', 'lokale-tjenestesider'),
            );
        }

        return array(
            __('Rediger kommune', 'lokale-tjenestesider'),
            __('Lokal informasjon, kontakt og tjenestene kommunen tilbyr.', 'lokale-tjenestesider'),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function tabs(string $type): array
    {
        if ($type === LTS_FYLKE) {
            return array(
                'contact' => __('Kontakt', 'lokale-tjenestesider'),
                'hours' => __('Åpningstider', 'lokale-tjenestesider'),
                'services' => __('Tjenester', 'lokale-tjenestesider'),
                'faq' => __('FAQ', 'lokale-tjenestesider'),
                'content' => __('Innhold', 'lokale-tjenestesider'),
            );
        }
        if ($type === LTS_TJENESTE) {
            return array(
                'general' => __('Generelt', 'lokale-tjenestesider'),
                'people' => __('Kontaktpersoner', 'lokale-tjenestesider'),
            );
        }

        return array(
            'general' => __('Generelt', 'lokale-tjenestesider'),
            'contact' => __('Kontakt', 'lokale-tjenestesider'),
            'hours' => __('Åpningstider', 'lokale-tjenestesider'),
            'services' => __('Tjenester', 'lokale-tjenestesider'),
        );
    }

    private static function panel(string $id, WP_Post $post): void
    {
        if ($id === 'general' && $post->post_type === LTS_KOMMUNE) {
            self::panel_kommune_general($post);
            return;
        }
        if ($id === 'general' && $post->post_type === LTS_TJENESTE) {
            self::panel_tjeneste($post);
            return;
        }
        if ($id === 'people' && $post->post_type === LTS_TJENESTE) {
            self::panel_people($post);
            return;
        }
        if ($id === 'contact') {
            self::panel_contact($post);
            return;
        }
        if ($id === 'hours') {
            self::panel_hours($post);
            return;
        }
        if ($id === 'services') {
            self::panel_services($post);
            return;
        }
        if ($id === 'faq') {
            self::panel_faq($post);
            return;
        }
        if ($id === 'content') {
            self::panel_content($post);
        }
    }

    private static function panel_kommune_general(WP_Post $post): void
    {
        self::image_field($post, __('Tomt felt bruker fylkets bilde.', 'lokale-tjenestesider'));

        $fylke_id = (int) get_post_meta($post->ID, LTS_META_FYLKE, true);
        echo '<label class="lts-field" for="lts_fylke_id"><span>' . esc_html__('Fylke', 'lokale-tjenestesider') . '</span>';
        echo '<select id="lts_fylke_id" name="lts_fylke_id">';
        echo '<option value="0">' . esc_html__('Velg fylke', 'lokale-tjenestesider') . '</option>';
        foreach (self::posts(LTS_FYLKE) as $fylke) {
            echo '<option value="' . esc_attr((string) $fylke->ID) . '" ' . selected($fylke_id, $fylke->ID, false) . '>' . esc_html(get_the_title($fylke)) . '</option>';
        }
        echo '</select></label>';

        echo '<label class="lts-field"><span>' . esc_html__('Beskrivelse', 'lokale-tjenestesider') . '</span>';
        echo '<textarea name="lts_description" rows="5">' . esc_textarea(LTS_Repository::description($post->ID)) . '</textarea>';
        echo '<span class="lts-h">' . esc_html__('Brukes når en tjeneste ikke har egen lokal tekst.', 'lokale-tjenestesider') . '</span></label>';

        $codes = LTS_Repository::postnummer($post->ID);
        echo '<label class="lts-field"><span>' . esc_html__('Postnummer', 'lokale-tjenestesider') . '</span>';
        echo '<textarea name="lts_postnummer" rows="6">' . esc_textarea(implode("\n", $codes)) . '</textarea>';
        echo '<span class="lts-h">' . esc_html__('Ett nummer per linje. Brukes i kommunesøket.', 'lokale-tjenestesider') . '</span></label>';

        echo '<div class="lts-field"><span>' . esc_html__('Nærliggende kommuner', 'lokale-tjenestesider') . '</span>';
        echo '<div class="lts-chips" id="lts-chips">';
        foreach (LTS_Repository::nearby_ids($post->ID) as $nearby_id) {
            $nearby = get_post($nearby_id);
            if (!$nearby instanceof WP_Post || $nearby->post_type !== LTS_KOMMUNE) {
                continue;
            }
            self::chip($nearby->ID, get_the_title($nearby));
        }
        echo '</div>';
        echo '<select id="lts-add-nearby"><option value="">' . esc_html__('Legg til kommune', 'lokale-tjenestesider') . '</option>';
        foreach (self::posts(LTS_KOMMUNE) as $kommune) {
            if ($kommune->ID === $post->ID) {
                continue;
            }
            echo '<option value="' . esc_attr((string) $kommune->ID) . '">' . esc_html(get_the_title($kommune)) . '</option>';
        }
        echo '</select></div>';
    }

    private static function panel_tjeneste(WP_Post $post): void
    {
        $public = LTS_Repository::service_is_public($post);

        echo '<p class="lts-sub">' . esc_html__('Hub-tittelen er navnet øverst. Metabeskrivelsen på hub-en er ingressen.', 'lokale-tjenestesider') . '</p>';
        $template_id = LTS_Repository::ensure_template($post);
        $template_url = $template_id > 0 ? self::layout_url($template_id) : '';
        if ($template_url !== '') {
            echo '<p class="lts-layout-row"><a class="lts-layout" href="' . esc_url($template_url) . '">' . esc_html__('Rediger mal', 'lokale-tjenestesider') . '</a></p>';
            echo '<p class="lts-h">' . esc_html__('Samme mal brukes på alle lokale sider for tjenesten.', 'lokale-tjenestesider') . '</p>';
        }
        echo '<label class="lts-field"><span>' . esc_html__('Ingress', 'lokale-tjenestesider') . '</span>';
        echo '<textarea name="lts_excerpt" rows="3">' . esc_textarea($post->post_excerpt) . '</textarea></label>';
        echo '<label class="lts-field"><span>' . esc_html__('Brødtekst', 'lokale-tjenestesider') . '</span>';
        echo '<textarea name="lts_body" rows="8">' . esc_textarea(LTS_Repository::body($post->ID)) . '</textarea>';
        echo '<span class="lts-h">' . esc_html__('Layouten ligger i UX Builder og blir ikke overskrevet av import.', 'lokale-tjenestesider') . '</span></label>';

        echo '<div class="lts-switch-row">';
        echo '<label class="lts-switch"><input type="checkbox" name="lts_public_url" value="1" ' . checked($public, true, false) . '><span></span></label>';
        echo '<span><strong>' . esc_html__('Bruk permalenke', 'lokale-tjenestesider') . '</strong>';
        echo '<span class="lts-h">' . esc_html__('Av skjuler /slug/. /slug/kommune/ blir stående.', 'lokale-tjenestesider') . '</span></span>';
        echo '</div>';

        $base = LTS_Repository::service_base($post);
        $sample = $post->post_name !== '' ? $post->post_name : 'tjeneste';
        echo '<label class="lts-field" for="lts-base"><span>' . esc_html__('Overordnet sti', 'lokale-tjenestesider') . '</span>';
        echo '<input type="text" id="lts-base" name="lts_base" value="' . esc_attr($base) . '" spellcheck="false" autocomplete="off">';
        echo '<span class="lts-h">' . esc_html(sprintf(
            /* translators: %s: service slug */
            __('Tomt felt gir /%1$s/fredrikstad/. Verdien tjenester gir /tjenester/%1$s/fredrikstad/.', 'lokale-tjenestesider'),
            $sample
        )) . '</span></label>';

        $form_id = (string) get_post_meta($post->ID, LTS_META_GF, true);
        echo '<label class="lts-field" for="lts-gf"><span>' . esc_html__('Skjema-ID', 'lokale-tjenestesider') . '</span>';
        echo '<input type="text" id="lts-gf" name="lts_gf_id" value="' . esc_attr($form_id) . '" inputmode="numeric" autocomplete="off">';
        echo '<span class="lts-h">' . esc_html__('Ett Gravity Forms-skjema for tjenesten. Skjulte felt med parameternavn lts_fylke og lts_kommune fylles ut fra siden. Tomt felt bruker skjema-ID-en på fylket eller kommunen.', 'lokale-tjenestesider') . '</span></label>';

        self::image_field($post);
    }

    private static function image_field(WP_Post $post, string $hint = ''): void
    {
        $thumb_id = (int) get_post_thumbnail_id($post);
        $thumb_url = $thumb_id ? (string) wp_get_attachment_image_url($thumb_id, 'medium') : '';

        echo '<div class="lts-field"><span>' . esc_html__('Bilde', 'lokale-tjenestesider') . '</span>';
        echo '<input type="hidden" name="lts_thumbnail" id="lts-thumbnail" value="' . esc_attr((string) $thumb_id) . '">';
        echo '<div class="lts-thumb" id="lts-thumb"' . ($thumb_url === '' ? ' hidden' : '') . '>';
        echo '<img alt="" id="lts-thumb-img" src="' . esc_url($thumb_url) . '">';
        echo '</div>';
        echo '<div class="lts-actions">';
        echo '<button type="button" class="lts-btn" id="lts-thumb-pick">' . esc_html__('Velg bilde', 'lokale-tjenestesider') . '</button>';
        echo '<button type="button" class="lts-btn" id="lts-thumb-clear"' . ($thumb_id > 0 ? '' : ' hidden') . '>' . esc_html__('Fjern bilde', 'lokale-tjenestesider') . '</button>';
        echo '</div>';
        if ($hint !== '') {
            echo '<span class="lts-h">' . esc_html($hint) . '</span>';
        }
        echo '</div>';
    }

    private static function panel_people(WP_Post $post): void
    {
        $fields = LTS_Repository::people_field_choices();
        if ($fields === array()) {
            echo '<p class="lts-sub">' . esc_html__('Store Locator er ikke tilgjengelig, så listen viser alle i fylket.', 'lokale-tjenestesider') . '</p>';
            return;
        }

        $settings = LTS_Repository::people_filter($post);
        $mode = $settings['mode'];
        $selected = $settings['field'];
        $known = false;
        foreach ($fields as $field) {
            if ($field['key'] === $selected) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            $selected = $fields[0]['key'];
        }

        echo '<input type="hidden" name="lts_people_present" value="1">';
        echo '<label class="lts-field" for="lts-people-mode"><span>' . esc_html__('Visning', 'lokale-tjenestesider') . '</span>';
        echo '<select id="lts-people-mode" name="lts_people_mode">';
        $modes = array(
            'uten' => __('Vis alle uten filtrering', 'lokale-tjenestesider'),
            'basert' => __('Vis basert på filter', 'lokale-tjenestesider'),
            'med' => __('Vis alle med filter', 'lokale-tjenestesider'),
        );
        foreach ($modes as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($mode, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<span class="lts-h">' . esc_html__('Uten filtrering viser alle kontaktpersoner i fylket. Basert på filter viser bare den valgte oppføringen.', 'lokale-tjenestesider') . '</span></label>';

        echo '<div id="lts-people-fields"' . ($mode === 'uten' ? ' hidden' : '') . '>';
        echo '<label class="lts-field" for="lts-people-field"><span>' . esc_html__('Type', 'lokale-tjenestesider') . '</span>';
        echo '<select id="lts-people-field" name="lts_people_field">';
        foreach ($fields as $field) {
            echo '<option value="' . esc_attr($field['key']) . '" ' . selected($selected, $field['key'], false) . '>' . esc_html($field['label']) . '</option>';
        }
        echo '</select></label>';

        echo '<div id="lts-people-entry"' . ($mode === 'basert' ? '' : ' hidden') . '>';
        echo '<label class="lts-field" for="lts-people-value"><span>' . esc_html__('Oppføring', 'lokale-tjenestesider') . '</span>';
        echo '<select id="lts-people-value" name="lts_people_value">';
        echo '<option value="">' . esc_html__('Velg oppføring', 'lokale-tjenestesider') . '</option>';
        foreach ($fields as $field) {
            if ($field['key'] !== $selected) {
                continue;
            }
            foreach ($field['options'] as $option) {
                echo '<option value="' . esc_attr($option['slug']) . '" ' . selected($settings['value'], $option['slug'], false) . '>' . esc_html($option['label']) . '</option>';
            }
        }
        echo '</select></label></div></div>';
        echo '<script type="application/json" id="lts-people-choices">' . wp_json_encode($fields, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
    }

    private static function panel_contact(WP_Post $post): void
    {
        if ($post->post_type === LTS_FYLKE) {
            self::image_field($post);
        }

        $contact = LTS_Repository::contact($post->ID);
        if ($post->post_type === LTS_FYLKE) {
            echo '<p class="lts-sub">' . esc_html__('Kommunene arver det som står her når feltet er tomt hos kommunen.', 'lokale-tjenestesider') . '</p>';
        } else {
            echo '<p class="lts-sub">' . esc_html__('Tomme felt arves fra fylket.', 'lokale-tjenestesider') . '</p>';
        }

        echo '<div class="lts-pair">';
        self::text_field('lts_contact[phone]', __('Telefon', 'lokale-tjenestesider'), $contact['phone'], 'text');
        self::text_field('lts_contact[emergency]', __('Vakttelefon', 'lokale-tjenestesider'), $contact['emergency'], 'text');
        self::text_field('lts_contact[email]', __('E-post', 'lokale-tjenestesider'), $contact['email'], 'email');
        self::text_field('lts_contact[gf_id]', __('Skjema-ID', 'lokale-tjenestesider'), $contact['gf_id'], 'text');
        echo '</div>';
        echo '<label class="lts-field"><span>' . esc_html__('Adresse', 'lokale-tjenestesider') . '</span>';
        echo '<textarea name="lts_contact[address]" rows="3">' . esc_textarea($contact['address']) . '</textarea></label>';
    }

    private static function panel_hours(WP_Post $post): void
    {
        $contact = LTS_Repository::contact($post->ID);
        $inherit = $post->post_type === LTS_KOMMUNE;
        $custom = is_array($contact['hours']);
        $hours = $custom ? $contact['hours'] : array();
        if ($inherit && !$custom) {
            $fylke_id = (int) get_post_meta($post->ID, LTS_META_FYLKE, true);
            $parent = $fylke_id > 0 ? LTS_Repository::contact($fylke_id) : array('hours' => null);
            $hours = is_array($parent['hours']) ? $parent['hours'] : array();
        }

        if ($inherit) {
            echo '<div class="lts-seg" data-lts-hours-mode>';
            echo '<button type="button" data-value="inherit" aria-pressed="' . ($custom ? 'false' : 'true') . '">' . esc_html__('Arv fra fylket', 'lokale-tjenestesider') . '</button>';
            echo '<button type="button" data-value="custom" aria-pressed="' . ($custom ? 'true' : 'false') . '">' . esc_html__('Overstyr hele uken', 'lokale-tjenestesider') . '</button>';
            echo '</div>';
            echo '<input type="hidden" name="lts_hours_mode" value="' . esc_attr($custom ? 'custom' : 'inherit') . '">';
        } else {
            echo '<p class="lts-sub">' . esc_html__('Dette er uken kommunene arver.', 'lokale-tjenestesider') . '</p>';
            echo '<input type="hidden" name="lts_hours_mode" value="custom">';
        }

        echo '<div class="lts-days' . ($inherit && !$custom ? ' is-locked' : '') . '">';
        foreach (LTS_Repository::weekdays() as $day => $label) {
            $slot = isset($hours[$day]) && is_array($hours[$day]) ? $hours[$day] : null;
            $open = is_array($slot);
            echo '<div class="lts-day' . ($open ? ' is-open' : '') . '" data-day="' . esc_attr($day) . '">';
            echo '<span class="lts-day-name">' . esc_html($label) . '</span>';
            echo '<label class="lts-switch"><input type="checkbox" data-lts-day ' . checked($open, true, false) . '><span></span></label>';
            echo '<span class="lts-day-state" data-lts-day-label>' . esc_html($open ? __('Åpent', 'lokale-tjenestesider') : __('Stengt', 'lokale-tjenestesider')) . '</span>';
            echo '<input type="time" data-lts-opens name="lts_hours[' . esc_attr($day) . '][opens]" value="' . esc_attr($open ? (string) $slot['opens'] : '') . '">';
            echo '<span class="lts-day-dash" aria-hidden="true">–</span>';
            echo '<input type="time" data-lts-closes name="lts_hours[' . esc_attr($day) . '][closes]" value="' . esc_attr($open ? (string) $slot['closes'] : '') . '">';
            echo '<input type="hidden" data-lts-state name="lts_hours[' . esc_attr($day) . '][state]" value="' . esc_attr($open ? 'open' : 'closed') . '">';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function panel_services(WP_Post $post): void
    {
        $kommune = $post->post_type === LTS_KOMMUNE;
        $services = self::posts(LTS_TJENESTE);
        $fylke_id = $kommune ? (int) get_post_meta($post->ID, LTS_META_FYLKE, true) : 0;
        $allowed = null;
        if ($kommune) {
            $allowed = $fylke_id > 0 ? LTS_Repository::offered_slugs($fylke_id) : array();
            $services = array_values(array_filter($services, function (WP_Post $service) use ($allowed): bool {
                return in_array($service->post_name, $allowed, true);
            }));
            echo '<p class="lts-sub">' . esc_html__('Tomt prisfelt arver fylket. 0 er en gyldig pris.', 'lokale-tjenestesider') . '</p>';
        }

        echo '<div id="lts-services" data-kommune="' . ($kommune ? '1' : '0') . '">';
        if ($services === array()) {
            if ($kommune && $fylke_id <= 0) {
                $empty = __('Velg fylke. Deretter kan du slå på tjenestene fylket tilbyr.', 'lokale-tjenestesider');
            } elseif ($kommune) {
                $empty = __('Fylket tilbyr ingen tjenester ennå.', 'lokale-tjenestesider');
            } else {
                $empty = __('Opprett en tjeneste før du velger hva fylket tilbyr.', 'lokale-tjenestesider');
            }
            echo '<p class="lts-empty">' . esc_html($empty) . '</p>';
        } else {
            $offered = LTS_Repository::offered_slugs($post->ID);
            $prices = LTS_Repository::prices($post->ID);
            $notes = LTS_Repository::price_notes($post->ID);
            foreach ($services as $service) {
                $slug = $service->post_name;
                $on = in_array($slug, $offered, true);
                $price = array_key_exists($slug, $prices) ? LTS_Repository::price_string($prices[$slug]) : '';
                $extra = array(
                    'description' => '',
                    'seo' => '',
                    'meta' => '',
                    'layout' => '',
                    'note' => isset($notes[$slug]) ? $notes[$slug] : '',
                );
                if ($kommune) {
                    $extra['layout'] = self::layout_url(LTS_Repository::ensure_template($service));
                    $side = LTS_Repository::side_by_pair($service->ID, $post->ID);
                    if ($side instanceof WP_Post) {
                        $extra['description'] = LTS_Repository::description($side->ID);
                        $extra['seo'] = (string) get_post_meta($side->ID, LTS_META_SEO_TITLE, true);
                        $extra['meta'] = (string) get_post_meta($side->ID, LTS_META_META_DESC, true);
                    }
                }
                self::service_row($service, $on, $price, $kommune, $extra);
            }
        }
        echo '</div>';
    }

    /**
     * @param array{description: string, seo: string, meta: string, layout: string, note: string} $extra
     */
    private static function service_row(WP_Post $service, bool $on, string $price, bool $kommune, array $extra): void
    {
        $slug = $service->post_name;
        $title = get_the_title($service);
        echo '<div class="lts-offer lts-service" data-slug="' . esc_attr($slug) . '" data-title="' . esc_attr($title) . '" data-base="' . esc_attr(LTS_Repository::service_base($service)) . '">';
        echo '<div class="lts-service-head">';
        echo '<label class="lts-switch"><input type="checkbox" data-lts-offer name="lts_offer[' . esc_attr($slug) . '][on]" value="1" ' . checked($on, true, false) . '><span></span></label>';
        echo '<span class="lts-service-name">' . esc_html($title) . '</span>';
        if ($kommune) {
            echo '<span class="lts-tag" data-lts-price-pill></span>';
        }
        echo '</div>';
        echo '<div class="lts-offer-fields"' . ($on ? '' : ' hidden') . '>';
        echo '<label class="lts-field"><span>' . esc_html($kommune ? __('Lokal pris', 'lokale-tjenestesider') : __('Pris', 'lokale-tjenestesider')) . '</span>';
        echo '<input type="text" inputmode="decimal" data-lts-price name="lts_prices[' . esc_attr($slug) . ']" value="' . esc_attr($price) . '"></label>';
        echo '<label class="lts-field"><span>' . esc_html__('Prislinje', 'lokale-tjenestesider') . '</span>';
        echo '<input type="text" data-lts-note name="lts_price_notes[' . esc_attr($slug) . ']" value="' . esc_attr($extra['note']) . '">';
        echo '<span class="lts-h">' . esc_html($kommune
            ? __('Tomt felt arver fylket. For eksempel «3 125 kr/t etter to timer».', 'lokale-tjenestesider')
            : __('Vises under prisen. For eksempel «3 125 kr/t etter to timer».', 'lokale-tjenestesider')
        ) . '</span></label>';
        if ($kommune) {
            echo '<label class="lts-field"><span>' . esc_html__('Lokal tekst', 'lokale-tjenestesider') . '</span>';
            echo '<textarea rows="4" data-lts-local name="lts_offer[' . esc_attr($slug) . '][description]">' . esc_textarea($extra['description']) . '</textarea>';
            echo '<span class="lts-h">' . esc_html__('Tomt felt bruker kommunens beskrivelse.', 'lokale-tjenestesider') . '</span></label>';
            echo '<div class="lts-snippet" data-lts-snippet>';
            echo '<div class="lts-snippet-url"></div>';
            echo '<div class="lts-snippet-title"></div>';
            echo '<div class="lts-snippet-desc"></div>';
            echo '</div>';
            echo '<div class="lts-pair">';
            echo '<label class="lts-field"><span class="lts-label-row"><span>' . esc_html__('SEO-tittel', 'lokale-tjenestesider') . '</span><span class="lts-count" data-max="60"></span></span>';
            echo '<input type="text" data-lts-seo data-lts-counted data-max="60" name="lts_offer[' . esc_attr($slug) . '][seo_title]" value="' . esc_attr($extra['seo']) . '">';
            echo '<span class="lts-h">' . esc_html__('Tomt felt blir «Tjeneste i kommune».', 'lokale-tjenestesider') . '</span></label>';
            echo '<label class="lts-field"><span class="lts-label-row"><span>' . esc_html__('Metabeskrivelse', 'lokale-tjenestesider') . '</span><span class="lts-count" data-max="155"></span></span>';
            echo '<textarea rows="3" data-lts-meta data-lts-counted data-max="155" name="lts_offer[' . esc_attr($slug) . '][meta_description]">' . esc_textarea($extra['meta']) . '</textarea></label>';
            echo '</div>';
            echo '<p class="lts-layout-row"' . ($extra['layout'] === '' ? ' hidden' : '') . '><a class="lts-layout" href="' . esc_url($extra['layout']) . '">' . esc_html__('Rediger mal', 'lokale-tjenestesider') . '</a></p>';
        }
        echo '</div></div>';
    }

    private static function panel_content(WP_Post $post): void
    {
        echo '<p class="lts-sub">' . esc_html__('Velg UX-blokker per tjeneste. Sidene bruker blokken som hører til tjenesten og fylket.', 'lokale-tjenestesider') . '</p>';
        if (!post_type_exists('blocks')) {
            echo '<p class="lts-empty">' . esc_html__('UX-blokker krever Flatsome.', 'lokale-tjenestesider') . '</p>';
            return;
        }
        echo '<input type="hidden" name="lts_blocks_present" value="1">';

        $blocks = self::posts('blocks');
        $map = LTS_Repository::block_map($post->ID);
        $services = array();
        foreach (LTS_Repository::offered_slugs($post->ID) as $slug) {
            $service = LTS_Repository::by_slug(LTS_TJENESTE, $slug);
            if ($service instanceof WP_Post) {
                $services[] = $service;
            }
        }
        echo '<div id="lts-blocks">';
        if ($services === array()) {
            echo '<p class="lts-empty">' . esc_html__('Fylket tilbyr ingen tjenester ennå.', 'lokale-tjenestesider') . '</p>';
        }
        foreach ($services as $service) {
            $rows = isset($map[$service->post_name]) ? $map[$service->post_name] : array();
            echo '<div class="lts-block-service" data-slug="' . esc_attr($service->post_name) . '">';
            echo '<h3>' . esc_html(get_the_title($service)) . '</h3>';
            echo '<div class="lts-block-list">';
            foreach ($rows as $row) {
                self::block_row($blocks, $row['name'], $row['id'], $service->post_name);
            }
            echo '</div>';
            echo '<button type="button" class="lts-btn" data-lts-add-block>' . esc_html__('Legg til blokk', 'lokale-tjenestesider') . '</button>';
            echo '</div>';
        }
        echo '</div>';
        echo '<template id="lts-block-template">';
        self::block_row($blocks, '', 0, '');
        echo '</template>';
    }

    /**
     * @param WP_Post[] $blocks
     */
    private static function block_row(array $blocks, string $name, int $selected, string $slug): void
    {
        $name_attr = $slug !== '' ? ' name="lts_block_name[' . esc_attr($slug) . '][]"' : '';
        $id_attr = $slug !== '' ? ' name="lts_block_id[' . esc_attr($slug) . '][]"' : '';
        echo '<div class="lts-block-row">';
        echo '<label class="lts-field"><span>' . esc_html__('Navn', 'lokale-tjenestesider') . '</span>';
        echo '<input type="text" data-lts-block-field="name"' . $name_attr . ' value="' . esc_attr($name) . '" placeholder="' . esc_attr__('tilbud', 'lokale-tjenestesider') . '"></label>';
        echo '<label class="lts-field"><span>' . esc_html__('UX-blokk', 'lokale-tjenestesider') . '</span>';
        echo '<select data-lts-block data-lts-block-field="id"' . $id_attr . '>';
        echo '<option value="0">' . esc_html__('Velg blokk', 'lokale-tjenestesider') . '</option>';
        foreach ($blocks as $block) {
            $edit = self::layout_url($block->ID);
            echo '<option value="' . esc_attr((string) $block->ID) . '" data-edit="' . esc_url($edit) . '" ' . selected($selected, $block->ID, false) . '>' . esc_html(get_the_title($block)) . '</option>';
        }
        echo '</select></label>';
        $current = $selected > 0 ? self::layout_url($selected) : '';
        echo '<p class="lts-layout-row"' . ($current === '' ? ' hidden' : '') . '><a class="lts-block-edit" href="' . esc_url($current) . '">' . esc_html__('Rediger blokken', 'lokale-tjenestesider') . '</a></p>';
        echo '<button type="button" class="lts-btn" data-lts-remove-block>' . esc_html__('Fjern', 'lokale-tjenestesider') . '</button>';
        echo '</div>';
    }

    private static function panel_faq(WP_Post $post): void
    {
        $faq = LTS_Repository::faq($post->ID);
        echo '<h2>' . esc_html__('Vanlige spørsmål', 'lokale-tjenestesider') . '</h2>';
        echo '<p class="lts-sub">' . esc_html__('Arves av alle kommuner i fylket. Rekkefølgen her er rekkefølgen på nettsiden.', 'lokale-tjenestesider') . '</p>';
        echo '<p class="lts-faq-tools">';
        echo '<button type="button" class="lts-text-btn" data-lts-faq-open>' . esc_html__('Åpne alle', 'lokale-tjenestesider') . '</button>';
        echo '<button type="button" class="lts-text-btn" data-lts-faq-close>' . esc_html__('Lukk alle', 'lokale-tjenestesider') . '</button>';
        echo '</p>';
        echo '<div id="lts-faq" class="lts-faq-list" data-empty="' . esc_attr__('Nytt spørsmål', 'lokale-tjenestesider') . '" data-count="' . esc_attr(
            /* translators: %s: character count */
            __('%s tegn', 'lokale-tjenestesider')
        ) . '">';
        foreach ($faq as $index => $row) {
            self::faq_row((string) $row['question'], (string) $row['answer'], $index === 0, $index + 1, count($faq));
        }
        echo '</div>';
        echo '<button type="button" class="lts-faq-add" data-lts-add-faq>' . esc_html__('+ Legg til spørsmål', 'lokale-tjenestesider') . '</button>';
        echo '<template id="lts-faq-template">';
        self::faq_row('', '', true, 1, 1);
        echo '</template>';
    }

    private static function faq_row(string $question, string $answer, bool $open, int $number, int $total): void
    {
        $title = $question !== '' ? $question : __('Nytt spørsmål', 'lokale-tjenestesider');
        $preview = trim((string) preg_replace('/\s+/u', ' ', $answer));
        $length = function_exists('mb_strlen') ? mb_strlen($answer) : strlen($answer);
        echo '<article class="lts-faq-row' . ($open ? ' is-open' : '') . '">';
        echo '<div class="lts-faq-head">';
        echo '<span class="lts-faq-num" data-lts-faq-num>' . esc_html((string) $number) . '</span>';
        echo '<div class="lts-faq-summary">';
        echo '<strong data-lts-faq-title>' . esc_html($title) . '</strong>';
        echo '<p class="lts-faq-preview" data-lts-faq-preview>' . esc_html($preview) . '</p>';
        echo '</div>';
        echo '<div class="lts-faq-actions">';
        echo '<button type="button" class="lts-faq-icon" data-lts-faq-up aria-label="' . esc_attr__('Flytt opp', 'lokale-tjenestesider') . '"' . ($number === 1 ? ' disabled' : '') . '>' . self::faq_icon('up') . '</button>';
        echo '<button type="button" class="lts-faq-icon" data-lts-faq-down aria-label="' . esc_attr__('Flytt ned', 'lokale-tjenestesider') . '"' . ($number === $total ? ' disabled' : '') . '>' . self::faq_icon('down') . '</button>';
        echo '<button type="button" class="lts-faq-icon" data-lts-remove-faq aria-label="' . esc_attr__('Fjern', 'lokale-tjenestesider') . '">' . self::faq_icon('close') . '</button>';
        echo '<button type="button" class="lts-faq-icon lts-faq-toggle" data-lts-faq-toggle aria-expanded="' . ($open ? 'true' : 'false') . '" aria-label="' . esc_attr__('Vis spørsmål', 'lokale-tjenestesider') . '">' . self::faq_icon('chevron') . '</button>';
        echo '</div></div>';
        echo '<div class="lts-faq-body"' . ($open ? '' : ' hidden') . '>';
        echo '<label class="lts-field"><span>' . esc_html__('Spørsmål', 'lokale-tjenestesider') . '</span>';
        echo '<input type="text" name="lts_faq_question[]" value="' . esc_attr($question) . '"></label>';
        echo '<label class="lts-field"><span class="lts-label-row"><span>' . esc_html__('Svar', 'lokale-tjenestesider') . '</span>';
        echo '<span class="lts-count" data-lts-faq-count>' . esc_html(sprintf(
            /* translators: %s: character count */
            __('%s tegn', 'lokale-tjenestesider'),
            (string) $length
        )) . '</span></span>';
        echo '<textarea name="lts_faq_answer[]" rows="4">' . esc_textarea($answer) . '</textarea></label>';
        echo '</div></article>';
    }

    private static function faq_icon(string $name): string
    {
        $paths = array(
            'up' => 'M6 12l4-4 4 4',
            'down' => 'M6 8l4 4 4-4',
            'close' => 'M6 6l8 8M14 6l-8 8',
            'chevron' => 'M6 8l4 4 4-4',
        );
        $path = isset($paths[$name]) ? $paths[$name] : '';

        return '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="' . esc_attr($path) . '" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    private static function aside(WP_Post $post): void
    {
        $status = $post->post_status;
        $published = $status === 'publish' || $status === 'private';
        $preview = self::preview_url($post);
        $trash = get_delete_post_link($post->ID);

        echo '<aside class="lts-editor-side">';
        echo '<section class="lts-card">';
        echo '<h2>' . esc_html__('Publiser', 'lokale-tjenestesider') . '</h2>';
        echo '<label class="lts-field lts-slug" for="lts-slug"><span>' . esc_html__('Slug', 'lokale-tjenestesider') . '</span>';
        echo '<input type="text" id="lts-slug" name="lts_slug" value="' . esc_attr($post->post_name) . '" spellcheck="false" autocomplete="off"></label>';
        echo '<label class="lts-field"><span>' . esc_html__('Status', 'lokale-tjenestesider') . '</span>';
        echo '<select name="lts_status" id="lts-status">';
        echo '<option value="publish" ' . selected($published, true, false) . '>' . esc_html__('Publisert', 'lokale-tjenestesider') . '</option>';
        echo '<option value="draft" ' . selected($published, false, false) . '>' . esc_html__('Kladd', 'lokale-tjenestesider') . '</option>';
        echo '</select></label>';
        echo '<label class="lts-field"><span>' . esc_html__('Synlighet', 'lokale-tjenestesider') . '</span>';
        echo '<select name="lts_visibility" id="lts-visibility">';
        echo '<option value="public" ' . selected($status === 'private', false, false) . '>' . esc_html__('Offentlig', 'lokale-tjenestesider') . '</option>';
        echo '<option value="private" ' . selected($status === 'private', true, false) . '>' . esc_html__('Privat', 'lokale-tjenestesider') . '</option>';
        echo '</select></label>';
        echo '<p class="lts-date"><span>' . esc_html__('Dato', 'lokale-tjenestesider') . '</span> <strong id="lts-date">' . esc_html(self::date_label($post)) . '</strong></p>';
        echo '<div class="lts-actions">';
        echo '<a class="lts-btn" id="lts-preview" target="_blank" rel="noopener noreferrer" href="' . esc_url($preview) . '"' . ($preview === '' ? ' aria-disabled="true" tabindex="-1"' : '') . '>' . esc_html__('Forhåndsvis', 'lokale-tjenestesider') . '</a>';
        echo '<button type="button" class="lts-btn is-primary" data-lts-save>' . esc_html__('Oppdater', 'lokale-tjenestesider') . '</button>';
        echo '</div>';
        if (is_string($trash) && $trash !== '') {
            echo '<a class="lts-trash" href="' . esc_url($trash) . '">' . esc_html__('Flytt til papirkurven', 'lokale-tjenestesider') . '</a>';
        }
        echo '</section>';

        if ($post->post_type === LTS_KOMMUNE) {
            $fylke_id = (int) get_post_meta($post->ID, LTS_META_FYLKE, true);
            $fylke_title = $fylke_id > 0 ? get_the_title($fylke_id) : '';
            $inherit = $fylke_title !== ''
                ? sprintf(
                    /* translators: %s: county name */
                    __('Arves fra %s', 'lokale-tjenestesider'),
                    $fylke_title
                )
                : __('Arves fra fylket', 'lokale-tjenestesider');
            echo '<section class="lts-card" id="lts-inherit">';
            echo '<h2 id="lts-inherit-title">' . esc_html($inherit) . '</h2>';
            echo '<ul id="lts-inherit-list"></ul>';
            echo '</section>';
        }
        echo '</aside>';
    }

    private static function chip(int $id, string $title): void
    {
        echo '<span class="lts-chip">';
        echo '<input type="hidden" name="lts_nearby[]" value="' . esc_attr((string) $id) . '">';
        echo '<span>' . esc_html($title) . '</span>';
        echo '<button type="button" data-lts-remove-chip aria-label="' . esc_attr__('Fjern', 'lokale-tjenestesider') . '">&times;</button>';
        echo '</span>';
    }

    private static function text_field(string $name, string $label, string $value, string $type): void
    {
        echo '<label class="lts-field"><span>' . esc_html($label) . '</span>';
        echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></label>';
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(WP_Post $post): array
    {
        $fylker = array();
        foreach (self::posts(LTS_FYLKE) as $fylke) {
            $contact = LTS_Repository::contact($fylke->ID);
            $fylker[] = array(
                'id' => (int) $fylke->ID,
                'title' => get_the_title($fylke),
                'hours' => self::hours_payload(is_array($contact['hours']) ? $contact['hours'] : null),
                'prices' => self::price_payload(LTS_Repository::prices($fylke->ID)),
                'services' => LTS_Repository::offered_slugs($fylke->ID),
            );
        }

        $services = array();
        foreach (self::posts(LTS_TJENESTE) as $service) {
            $template = '';
            if ($post->post_type === LTS_KOMMUNE) {
                $template = self::layout_url(LTS_Repository::ensure_template($service));
            }
            $services[] = array(
                'id' => (int) $service->ID,
                'slug' => $service->post_name,
                'title' => get_the_title($service),
                'public' => LTS_Repository::service_is_public($service),
                'base' => LTS_Repository::service_base($service),
                'template' => $template,
            );
        }

        $kommuner = array();
        foreach (self::posts(LTS_KOMMUNE) as $kommune) {
            if ($kommune->ID === $post->ID) {
                continue;
            }
            $kommuner[] = array(
                'id' => (int) $kommune->ID,
                'title' => get_the_title($kommune),
            );
        }

        return array(
            'type' => $post->post_type,
            'placeSlug' => $post->post_name,
            'host' => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            'fylker' => $fylker,
            'services' => $services,
            'kommuner' => $kommuner,
            'i18n' => array(
                'open' => __('Åpent', 'lokale-tjenestesider'),
                'closed' => __('Stengt', 'lokale-tjenestesider'),
                'fromCounty' => __('Pris fra fylket', 'lokale-tjenestesider'),
                'localPrice' => __('Lokal pris: %s', 'lokale-tjenestesider'),
                'saved' => __('Endringene er lagret', 'lokale-tjenestesider'),
                'inheritHours' => __('Åpningstider', 'lokale-tjenestesider'),
                'inheritPrice' => __('Pris for %s', 'lokale-tjenestesider'),
                'noServices' => __('Fylket tilbyr ingen tjenester ennå.', 'lokale-tjenestesider'),
                'pickCounty' => __('Velg fylke. Deretter kan du slå på tjenestene fylket tilbyr.', 'lokale-tjenestesider'),
                'noneInherit' => __('Ingen felter arves.', 'lokale-tjenestesider'),
                'layout' => __('Rediger mal', 'lokale-tjenestesider'),
                'inheritFrom' => __('Arves fra %s', 'lokale-tjenestesider'),
                'inheritUnknown' => __('Arves fra fylket', 'lokale-tjenestesider'),
                'inPlace' => __('i', 'lokale-tjenestesider'),
                'chooseImage' => __('Velg bilde', 'lokale-tjenestesider'),
                'couldNotSave' => __('Kunne ikke lagre.', 'lokale-tjenestesider'),
                'localPriceLabel' => __('Lokal pris', 'lokale-tjenestesider'),
                'priceNote' => __('Prislinje', 'lokale-tjenestesider'),
                'priceNoteHint' => __('Tomt felt arver fylket. For eksempel «3 125 kr/t etter to timer».', 'lokale-tjenestesider'),
                'localText' => __('Lokal tekst', 'lokale-tjenestesider'),
                'localTextHint' => __('Tomt felt bruker kommunens beskrivelse.', 'lokale-tjenestesider'),
                'seoTitle' => __('SEO-tittel', 'lokale-tjenestesider'),
                'seoHint' => __('Tomt felt blir «Tjeneste i kommune».', 'lokale-tjenestesider'),
                'metaLabel' => __('Metabeskrivelse', 'lokale-tjenestesider'),
                'remove' => __('Fjern', 'lokale-tjenestesider'),
                'addKommune' => __('Legg til kommune', 'lokale-tjenestesider'),
                'trashConfirm' => __('Flytte til papirkurven?', 'lokale-tjenestesider'),
            ),
        );
    }

    /**
     * @param array<string, mixed>|null $hours
     * @return array<string, array{open: bool, opens: string, closes: string}>
     */
    private static function hours_payload(?array $hours): array
    {
        $out = array();
        foreach (array_keys(LTS_Repository::weekdays()) as $day) {
            $slot = is_array($hours) && isset($hours[$day]) && is_array($hours[$day]) ? $hours[$day] : null;
            $out[$day] = array(
                'open' => is_array($slot),
                'opens' => is_array($slot) && isset($slot['opens']) ? (string) $slot['opens'] : '',
                'closes' => is_array($slot) && isset($slot['closes']) ? (string) $slot['closes'] : '',
            );
        }

        return $out;
    }

    /**
     * @param array<string, float> $prices
     * @return array<string, string>
     */
    private static function price_payload(array $prices): array
    {
        $out = array();
        foreach ($prices as $slug => $amount) {
            $out[(string) $slug] = LTS_Repository::price_string((float) $amount);
        }

        return $out;
    }

    /**
     * @return WP_Post[]
     */
    private static function posts(string $type): array
    {
        return get_posts(array(
            'post_type' => $type,
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => 500,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ));
    }

    private static function status_from_post(): string
    {
        $visibility = isset($_POST['lts_visibility']) ? sanitize_key((string) wp_unslash($_POST['lts_visibility'])) : 'public';
        $state = isset($_POST['lts_status']) ? sanitize_key((string) wp_unslash($_POST['lts_status'])) : 'draft';
        if ($state !== 'publish') {
            return 'draft';
        }

        return $visibility === 'private' ? 'private' : 'publish';
    }

    /**
     * @param array<string, mixed> $update
     */
    private static function slug_update(WP_Post $post, array &$update): string
    {
        if (!isset($_POST['lts_slug'])) {
            return '';
        }

        $slug = sanitize_title(wp_unslash((string) $_POST['lts_slug']));
        if ($slug === '' || !LTS_Repository::is_slug($slug)) {
            return __('Slug må være små bokstaver, tall og bindestrek.', 'lokale-tjenestesider');
        }
        if ($slug === $post->post_name) {
            return '';
        }

        $existing = LTS_Repository::by_slug($post->post_type, $slug, array('publish', 'draft', 'pending', 'private', 'future', 'trash'));
        $same_type = $existing instanceof WP_Post && (int) $existing->ID !== (int) $post->ID;
        $public_conflict = $post->post_type === LTS_TJENESTE && LTS_Repository::slug_conflicts($slug, (int) $post->ID);
        if ($same_type || $public_conflict) {
            return sprintf(
                /* translators: %s: slug */
                __('Slug «%s» er allerede i bruk.', 'lokale-tjenestesider'),
                $slug
            );
        }

        $update['post_name'] = $slug;

        return '';
    }

    private static function date_label(WP_Post $post): string
    {
        if ($post->post_status === 'auto-draft' || $post->post_date_gmt === '0000-00-00 00:00:00') {
            return __('Ikke publisert', 'lokale-tjenestesider');
        }

        $stamp = strtotime($post->post_date);
        if ($stamp === false) {
            return __('Ikke publisert', 'lokale-tjenestesider');
        }

        return date_i18n('j. F Y', $stamp);
    }

    private static function preview_url(WP_Post $post): string
    {
        if ($post->post_name === '') {
            return '';
        }
        if ($post->post_type === LTS_TJENESTE) {
            if ($post->post_status !== 'publish' || !LTS_Repository::service_is_public($post)) {
                return '';
            }

            return LTS_Rewrite::service_url($post->post_name);
        }
        if ($post->post_type !== LTS_KOMMUNE) {
            return '';
        }

        foreach (LTS_Repository::offered_slugs($post->ID) as $slug) {
            $service = LTS_Repository::published_by_slug(LTS_TJENESTE, $slug);
            if (!$service instanceof WP_Post) {
                continue;
            }
            $side = LTS_Repository::side_by_pair($service->ID, $post->ID, array('publish'));
            if ($side instanceof WP_Post) {
                return LTS_Rewrite::local_url($service->post_name, $post->post_name);
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private static function layout_map(int $kommune_id): array
    {
        $urls = array();
        foreach (LTS_Repository::offered_slugs($kommune_id) as $slug) {
            $service = LTS_Repository::published_by_slug(LTS_TJENESTE, $slug);
            if (!$service instanceof WP_Post) {
                $service = LTS_Repository::by_slug(LTS_TJENESTE, $slug);
            }
            if (!$service instanceof WP_Post) {
                continue;
            }
            $urls[$slug] = self::layout_url(LTS_Repository::ensure_template($service));
        }

        return $urls;
    }

    private static function layout_url(int $side_id): string
    {
        if ($side_id <= 0) {
            return '';
        }
        if (function_exists('ux_builder_edit_url')) {
            $url = ux_builder_edit_url($side_id);

            return is_string($url) ? $url : '';
        }
        $url = get_edit_post_link($side_id, 'raw');

        return is_string($url) ? $url : '';
    }
}
