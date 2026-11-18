<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Meta
{
    public static function init(): void
    {
        add_action('add_meta_boxes', array(__CLASS__, 'boxes'));
        add_action('save_post_' . LTS_FYLKE, array(__CLASS__, 'save_fylke'));
        add_action('save_post_' . LTS_KOMMUNE, array(__CLASS__, 'save_kommune'));
        add_action('save_post_' . LTS_TJENESTE, array(__CLASS__, 'save_tjeneste'));
        add_action('save_post_' . LTS_SIDE, array(__CLASS__, 'save_side'));
        add_action('save_post_' . LTS_TJENESTE, array(__CLASS__, 'sync_after_service'), 30);
        add_action('trashed_post', array(__CLASS__, 'sync_after_status'));
        add_action('trashed_post', array(__CLASS__, 'detach_fylke_kommuner'));
        add_action('untrashed_post', array(__CLASS__, 'sync_after_status'));
        add_action('deleted_post', array(__CLASS__, 'sync_after_status'));
        add_action('before_delete_post', array(__CLASS__, 'delete_template'));
        add_action('before_delete_post', array(__CLASS__, 'detach_fylke_kommuner'));
        add_filter('wp_insert_post_data', array(__CLASS__, 'reject_conflicting_slug'), 10, 2);
        add_filter('pre_trash_post', array(__CLASS__, 'guard_delete'), 10, 2);
        add_filter('pre_delete_post', array(__CLASS__, 'guard_delete'), 10, 2);
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function boxes(): void
    {
        add_meta_box('lts_side', __('Lokal side', 'lokale-tjenestesider'), array(__CLASS__, 'box_side'), LTS_SIDE, 'normal', 'high');
        remove_meta_box('postexcerpt', LTS_TJENESTE, 'normal');
        foreach (array(LTS_FYLKE, LTS_KOMMUNE, LTS_TJENESTE) as $type) {
            remove_meta_box('submitdiv', $type, 'side');
            remove_meta_box('slugdiv', $type, 'normal');
            remove_meta_box('postimagediv', $type, 'side');
        }
    }

    public static function assets(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        $types = array(LTS_FYLKE, LTS_KOMMUNE, LTS_TJENESTE, LTS_SIDE);
        $editor = $screen && in_array((string) $screen->post_type, $types, true);
        $shell_ids = array(
            'toplevel_page_lokale-tjenestesider',
            'lokale-tjenestesider_page_lts-import',
            'admin_page_lokale-tjenestesider_page_lts-import',
        );
        $shell = in_array($page, array('lokale-tjenestesider', 'lts-import'), true)
            || in_array($hook, $shell_ids, true)
            || ($screen && in_array((string) $screen->id, $shell_ids, true));
        if (!$editor && !$shell) {
            return;
        }

        wp_enqueue_style('lts-admin', LTS_URL . 'assets/css/admin.css', array(), LTS_VERSION);
        if ($editor) {
            wp_enqueue_script('lts-admin', LTS_URL . 'assets/js/admin.js', array(), LTS_VERSION, true);
        }
        if ($page === 'lts-import' || $hook === 'lokale-tjenestesider_page_lts-import' || $hook === 'admin_page_lokale-tjenestesider_page_lts-import' || ($screen && (string) $screen->id === 'lokale-tjenestesider_page_lts-import')) {
            wp_enqueue_script('lts-import', LTS_URL . 'assets/js/import.js', array(), LTS_VERSION, true);
        }
    }

    public static function box_fylke(WP_Post $post): void
    {
        self::nonce();
        $contact = LTS_Repository::contact($post->ID);
        echo '<p class="description">' . esc_html__('Kontakt, priser og FAQ arves av kommunene i fylket. Kommunen kan overstyre kontakt og pris. Huk av tjenestene fylket tilbyr.', 'lokale-tjenestesider') . '</p>';
        self::contact_fields($contact, false);
        self::service_offer_fields($post, false);
        self::faq_fields(LTS_Repository::faq($post->ID));
    }

    public static function box_kommune(WP_Post $post): void
    {
        self::nonce();
        $fylke_id = (int) get_post_meta($post->ID, LTS_META_FYLKE, true);
        echo '<p><label for="lts_fylke_id"><strong>' . esc_html__('Fylke', 'lokale-tjenestesider') . '</strong></label></p>';
        echo '<select id="lts_fylke_id" name="lts_fylke_id">';
        echo '<option value="0">' . esc_html__('Velg fylke', 'lokale-tjenestesider') . '</option>';
        foreach (self::posts(LTS_FYLKE) as $fylke) {
            echo '<option value="' . esc_attr((string) $fylke->ID) . '" ' . selected($fylke_id, $fylke->ID, false) . '>' . esc_html(get_the_title($fylke)) . '</option>';
        }
        echo '</select>';

        self::textarea_field('lts_description', __('Beskrivelse', 'lokale-tjenestesider'), LTS_Repository::description($post->ID), __('Felles tekst for kommunen. Elementet Kommuneintro leser dette feltet.', 'lokale-tjenestesider'));
        self::nearby_field($post->ID);
        echo '<p class="description">' . esc_html__('Tomme kontaktfelt og tomme priser arver fylket. Åpningstider arves samlet, med mindre du overstyrer hele uken. Tekst og SEO for hver tjeneste ligger under avhukingen.', 'lokale-tjenestesider') . '</p>';
        self::contact_fields(LTS_Repository::contact($post->ID), true);
        self::service_offer_fields($post, true);
    }

    public static function box_tjeneste(WP_Post $post): void
    {
        self::nonce();
        self::textarea_field('lts_excerpt', __('Ingress', 'lokale-tjenestesider'), (string) $post->post_excerpt, __('Kort tekst som elementet Tjenesteintro viser først. Brukes også som metabeskrivelse på hub-siden.', 'lokale-tjenestesider'));
        self::textarea_field('lts_body', __('Brødtekst', 'lokale-tjenestesider'), LTS_Repository::body($post->ID), __('Lengre tekst om tjenesten. Layouten ligger i UX Builder og blir ikke overskrevet av import.', 'lokale-tjenestesider'));
        $public = LTS_Repository::service_is_public($post);
        echo '<p><label><input type="checkbox" name="lts_public_url" value="1" ' . checked($public, true, false) . '> ' . esc_html__('Bruk permalenke', 'lokale-tjenestesider') . '</label></p>';
        echo '<p class="description">' . esc_html__('Av skjuler /slug/. /slug/kommune/ blir stående. Skru den av hvis en vanlig side allerede bruker hub-adressen.', 'lokale-tjenestesider') . '</p>';
    }

    public static function box_side(WP_Post $post): void
    {
        self::nonce();
        $tjeneste_id = (int) get_post_meta($post->ID, LTS_META_TJENESTE, true);
        $kommune_id = (int) get_post_meta($post->ID, LTS_META_KOMMUNE, true);

        echo '<p><label for="lts_tjeneste_id"><strong>' . esc_html__('Tjeneste', 'lokale-tjenestesider') . '</strong></label></p>';
        echo '<select id="lts_tjeneste_id" name="lts_tjeneste_id">';
        echo '<option value="0">' . esc_html__('Velg tjeneste', 'lokale-tjenestesider') . '</option>';
        foreach (self::posts(LTS_TJENESTE) as $tjeneste) {
            echo '<option value="' . esc_attr((string) $tjeneste->ID) . '" ' . selected($tjeneste_id, $tjeneste->ID, false) . '>' . esc_html(get_the_title($tjeneste)) . '</option>';
        }
        echo '</select>';

        echo '<p><label for="lts_kommune_id"><strong>' . esc_html__('Kommune', 'lokale-tjenestesider') . '</strong></label></p>';
        echo '<select id="lts_kommune_id" name="lts_kommune_id">';
        echo '<option value="0">' . esc_html__('Velg kommune', 'lokale-tjenestesider') . '</option>';
        foreach (self::posts(LTS_KOMMUNE) as $kommune) {
            echo '<option value="' . esc_attr((string) $kommune->ID) . '" ' . selected($kommune_id, $kommune->ID, false) . '>' . esc_html(get_the_title($kommune)) . '</option>';
        }
        echo '</select>';

        echo '<p class="description">' . esc_html__('Tekst, SEO-tittel og metabeskrivelse redigeres på kommunen, under tjenesten den tilbyr. Denne posten holder URL-en og UX Builder-layouten.', 'lokale-tjenestesider') . '</p>';
    }

    public static function save_fylke(int $post_id): void
    {
        if (!self::saving($post_id)) {
            return;
        }

        $previous = LTS_Repository::offered_slugs($post_id);
        $offered = self::offers_from_post(null);
        update_post_meta($post_id, LTS_META_SERVICES, $offered);
        update_post_meta($post_id, LTS_META_CONTACT, self::contact_from_post(false));
        update_post_meta($post_id, LTS_META_PRICES, self::prices_for($offered));
        update_post_meta($post_id, LTS_META_PRICE_NOTES, self::notes_for($offered));
        update_post_meta($post_id, LTS_META_FAQ, self::faq_from_post());
        if (isset($_POST['lts_blocks_present'])) {
            update_post_meta($post_id, LTS_META_BLOCKS, self::blocks_from_post($offered));
        }
        self::limit_kommune_offers($post_id, $previous, $offered);
    }

    public static function save_kommune(int $post_id): void
    {
        if (!self::saving($post_id)) {
            return;
        }

        $fylke_id = isset($_POST['lts_fylke_id']) ? absint($_POST['lts_fylke_id']) : 0;
        $fylke = $fylke_id ? get_post($fylke_id) : null;
        update_post_meta($post_id, LTS_META_FYLKE, ($fylke instanceof WP_Post && $fylke->post_type === LTS_FYLKE) ? $fylke_id : 0);
        update_post_meta($post_id, LTS_META_DESCRIPTION, self::posted_html('lts_description'));
        if (isset($_POST['lts_postnummer'])) {
            update_post_meta($post_id, LTS_META_POSTNUMMER, self::postnummer_from_post());
        }
        update_post_meta($post_id, LTS_META_NEARBY, self::nearby_from_post($post_id));
        update_post_meta($post_id, LTS_META_CONTACT, self::contact_from_post(true));
        $fylke_id = (int) get_post_meta($post_id, LTS_META_FYLKE, true);
        $allowed = $fylke_id > 0 ? LTS_Repository::offered_slugs($fylke_id) : array();
        $offered = self::offers_from_post($allowed);
        update_post_meta($post_id, LTS_META_SERVICES, $offered);
        update_post_meta($post_id, LTS_META_PRICES, self::prices_for($offered));
        update_post_meta($post_id, LTS_META_PRICE_NOTES, self::notes_for($offered));
        self::sync_kommune_sides($post_id, $offered);
    }

    public static function save_tjeneste(int $post_id): void
    {
        if (!self::saving($post_id)) {
            return;
        }

        update_post_meta($post_id, LTS_META_BODY, self::posted_html('lts_body'));
        $post = get_post($post_id);
        $was_public = $post instanceof WP_Post ? LTS_Repository::service_is_public($post) : true;
        $public = isset($_POST['lts_public_url']) ? '1' : '0';
        update_post_meta($post_id, LTS_META_PUBLIC_URL, $public);
        $base_changed = self::save_service_base($post_id, $post);
        self::save_service_form($post_id);
        if (($public === '1') !== $was_public || $base_changed) {
            LTS_Rewrite::flush_rules();
        }
        if (isset($_POST['lts_people_present'])) {
            update_post_meta($post_id, LTS_META_PEOPLE, self::people_from_post());
        }
        $excerpt = self::posted_text('lts_excerpt');
        if ($excerpt !== (string) get_post_field('post_excerpt', $post_id)) {
            remove_action('save_post_' . LTS_TJENESTE, array(__CLASS__, 'save_tjeneste'));
            wp_update_post(array(
                'ID' => $post_id,
                'post_excerpt' => $excerpt,
            ));
            add_action('save_post_' . LTS_TJENESTE, array(__CLASS__, 'save_tjeneste'));
        }
    }

    /**
     * @param WP_Post|null $post
     */
    private static function save_service_base(int $post_id, $post): bool
    {
        if (!isset($_POST['lts_base'])) {
            return false;
        }

        $current = $post instanceof WP_Post ? LTS_Repository::service_base($post) : '';
        $raw = trim((string) wp_unslash($_POST['lts_base']), " \t\n\r\0\x0B/");
        if ($raw === '') {
            $next = '';
        } elseif (LTS_Repository::is_slug($raw)) {
            $next = $raw;
        } else {
            self::notice(__('Overordnet sti må være små bokstaver, tall og bindestrek, uten skråstrek.', 'lokale-tjenestesider'));

            return false;
        }
        if ($next === $current) {
            return false;
        }

        update_post_meta($post_id, LTS_META_BASE, $next);

        return true;
    }

    private static function save_service_form(int $post_id): void
    {
        if (!isset($_POST['lts_gf_id'])) {
            return;
        }

        $id = absint($_POST['lts_gf_id']);
        if ($id > 0) {
            update_post_meta($post_id, LTS_META_GF, (string) $id);
            return;
        }

        delete_post_meta($post_id, LTS_META_GF);
    }

    public static function save_side(int $post_id): void
    {
        if (!self::saving($post_id)) {
            return;
        }

        $tjeneste_id = isset($_POST['lts_tjeneste_id']) ? absint($_POST['lts_tjeneste_id']) : 0;
        $kommune_id = isset($_POST['lts_kommune_id']) ? absint($_POST['lts_kommune_id']) : 0;
        $tjeneste = $tjeneste_id ? get_post($tjeneste_id) : null;
        $kommune = $kommune_id ? get_post($kommune_id) : null;
        $valid = $tjeneste instanceof WP_Post && $tjeneste->post_type === LTS_TJENESTE
            && $kommune instanceof WP_Post && $kommune->post_type === LTS_KOMMUNE;

        if (!$valid) {
            self::notice(__('Velg både tjeneste og kommune.', 'lokale-tjenestesider'));
        } else {
            $other = LTS_Repository::side_by_pair($tjeneste_id, $kommune_id);
            if ($other instanceof WP_Post && (int) $other->ID !== $post_id) {
                self::notice(__('Kombinasjonen finnes allerede på en annen lokal side.', 'lokale-tjenestesider'));
            } else {
                update_post_meta($post_id, LTS_META_TJENESTE, $tjeneste_id);
                update_post_meta($post_id, LTS_META_KOMMUNE, $kommune_id);
                update_post_meta($post_id, LTS_META_PAIR, $tjeneste_id . ':' . $kommune_id);
            }
        }
    }

    public static function sync_after_service(int $post_id): void
    {
        if (defined('LTS_IMPORTING') && LTS_IMPORTING) {
            return;
        }
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        LTS_Repository::sync_service_slugs();
    }

    public static function sync_after_status(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || $post->post_type !== LTS_TJENESTE) {
            return;
        }

        LTS_Repository::sync_service_slugs();
    }

    public static function detach_fylke_kommuner(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || $post->post_type !== LTS_FYLKE) {
            return;
        }

        $kommuner = get_posts(array(
            'post_type' => LTS_KOMMUNE,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_key' => LTS_META_FYLKE,
            'meta_value' => (string) $post_id,
        ));
        foreach ($kommuner as $id) {
            update_post_meta((int) $id, LTS_META_FYLKE, 0);
        }
        if ($kommuner !== array()) {
            self::notice(sprintf(
                /* translators: %d: number of municipalities */
                __('Fylket er fjernet. %d kommuner er ikke lenger knyttet til et fylke.', 'lokale-tjenestesider'),
                count($kommuner)
            ));
        }
    }

    /**
     * @param mixed $check
     * @param mixed $post
     * @return mixed
     */
    public static function guard_delete($check, $post)
    {
        if ($check !== null || !$post instanceof WP_Post) {
            return $check;
        }

        $message = self::delete_message($post);
        if ($message === '') {
            return $check;
        }

        self::notice($message);

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public static function reject_conflicting_slug(array $data, array $postarr): array
    {
        if (!isset($data['post_type']) || $data['post_type'] !== LTS_TJENESTE) {
            return $data;
        }
        if (in_array((string) $data['post_status'], array('auto-draft', 'inherit'), true)) {
            return $data;
        }

        $id = isset($postarr['ID']) ? (int) $postarr['ID'] : 0;
        if (!self::permalink_requested($id)) {
            return $data;
        }
        $slug = (string) $data['post_name'];
        if ($slug === '') {
            $slug = sanitize_title((string) $data['post_title']);
        }
        if (!LTS_Repository::slug_conflicts($slug, $id)) {
            return $data;
        }

        $previous = $id ? (string) get_post_field('post_name', $id) : '';
        if ($previous !== '' && !LTS_Repository::slug_conflicts($previous, $id)) {
            $data['post_name'] = $previous;
        } else {
            $data['post_status'] = 'draft';
            $data['post_name'] = $slug !== '' ? $slug . '-avvist' : 'tjeneste-avvist';
        }

        self::notice(sprintf(
            /* translators: %s: rejected slug */
            __('URL-en «%s» er opptatt av en side, et innlegg eller en kategori, og ble ikke tatt i bruk.', 'lokale-tjenestesider'),
            $slug
        ));

        return $data;
    }

    public static function admin_notice(): void
    {
        $key = 'lts_admin_notice_' . get_current_user_id();
        $message = get_transient($key);
        if (!is_string($message) || $message === '') {
            return;
        }

        delete_transient($key);
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private static function delete_message(WP_Post $post): string
    {
        if ($post->post_type === LTS_KOMMUNE) {
            $count = LTS_Repository::count_by_meta(LTS_SIDE, LTS_META_KOMMUNE, (string) $post->ID);
            if ($count > 0) {
                return sprintf(
                    /* translators: %d: number of local pages */
                    __('Kommunen kan ikke slettes. %d lokale sider bruker den.', 'lokale-tjenestesider'),
                    $count
                );
            }
        }

        if ($post->post_type === LTS_TJENESTE) {
            $count = LTS_Repository::count_by_meta(LTS_SIDE, LTS_META_TJENESTE, (string) $post->ID);
            if (LTS_Repository::template_for($post->ID) instanceof WP_Post) {
                $count = max(0, $count - 1);
            }
            if ($count > 0) {
                return sprintf(
                    /* translators: %d: number of local pages */
                    __('Tjenesten kan ikke slettes. %d lokale sider bruker den.', 'lokale-tjenestesider'),
                    $count
                );
            }
        }

        return '';
    }

    private static function saving(int $post_id): bool
    {
        if (defined('LTS_IMPORTING') && LTS_IMPORTING) {
            return false;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }
        if (wp_is_post_revision($post_id)) {
            return false;
        }
        if (!isset($_POST['lts_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lts_meta_nonce'])), 'lts_save_meta')) {
            return false;
        }
        $submitted = isset($_POST['post_ID']) ? absint($_POST['post_ID']) : 0;
        if ($submitted !== $post_id) {
            return false;
        }

        return current_user_can('edit_post', $post_id);
    }

    private static function permalink_requested(int $id): bool
    {
        if (isset($_POST['lts_meta_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lts_meta_nonce'])), 'lts_save_meta')) {
            return isset($_POST['lts_public_url']);
        }
        if ($id > 0) {
            $post = get_post($id);
            if ($post instanceof WP_Post) {
                return LTS_Repository::service_is_public($post);
            }
        }

        return true;
    }

    private static function nonce(): void
    {
        wp_nonce_field('lts_save_meta', 'lts_meta_nonce');
    }

    private static function notice(string $message): void
    {
        set_transient('lts_admin_notice_' . get_current_user_id(), $message, 60);
    }

    /**
     * @return array{phone: string, email: string, address: string, gf_id: string, emergency: string, hours: array<string, mixed>|null}
     */
    private static function contact_from_post(bool $allow_inherit): array
    {
        $posted = isset($_POST['lts_contact']) && is_array($_POST['lts_contact']) ? wp_unslash($_POST['lts_contact']) : array();
        $mode = isset($_POST['lts_hours_mode']) ? sanitize_key((string) wp_unslash($_POST['lts_hours_mode'])) : 'custom';
        $hours = null;
        if (!$allow_inherit || $mode === 'custom') {
            $raw = isset($_POST['lts_hours']) && is_array($_POST['lts_hours']) ? wp_unslash($_POST['lts_hours']) : array();
            $open = array();
            foreach (array_keys(LTS_Repository::weekdays()) as $day) {
                $row = isset($raw[$day]) && is_array($raw[$day]) ? $raw[$day] : array();
                $state = isset($row['state']) ? sanitize_key((string) $row['state']) : 'closed';
                if ($state === 'open') {
                    $open[$day] = array(
                        'opens' => isset($row['opens']) ? (string) $row['opens'] : '',
                        'closes' => isset($row['closes']) ? (string) $row['closes'] : '',
                    );
                } else {
                    $open[$day] = false;
                }
            }
            $hours = $open;
        }

        return LTS_Repository::sanitize_contact(array(
            'phone' => isset($posted['phone']) ? $posted['phone'] : '',
            'email' => isset($posted['email']) ? $posted['email'] : '',
            'address' => isset($posted['address']) ? $posted['address'] : '',
            'gf_id' => isset($posted['gf_id']) ? $posted['gf_id'] : '',
            'emergency' => isset($posted['emergency']) ? $posted['emergency'] : '',
            'hours' => $hours,
        ));
    }

    /**
     * @return array<string, float>
     */
    private static function prices_from_post(): array
    {
        $posted = isset($_POST['lts_prices']) && is_array($_POST['lts_prices']) ? wp_unslash($_POST['lts_prices']) : array();
        $raw = array();
        foreach ($posted as $slug => $amount) {
            $amount = trim((string) $amount);
            if ($amount === '') {
                continue;
            }
            $raw[(string) $slug] = str_replace(',', '.', $amount);
        }

        return LTS_Repository::sanitize_prices($raw);
    }

    /**
     * @param string[] $offered
     * @return array<string, string>
     */
    private static function notes_for(array $offered): array
    {
        $posted = isset($_POST['lts_price_notes']) && is_array($_POST['lts_price_notes']) ? wp_unslash($_POST['lts_price_notes']) : array();
        $keep = array();
        foreach ($offered as $slug) {
            $note = isset($posted[$slug]) ? sanitize_text_field((string) $posted[$slug]) : '';
            if ($note !== '') {
                $keep[$slug] = $note;
            }
        }

        return $keep;
    }

    /**
     * @return string[]
     */
    private static function postnummer_from_post(): array
    {
        $raw = isset($_POST['lts_postnummer']) ? (string) wp_unslash($_POST['lts_postnummer']) : '';

        return LTS_Repository::sanitize_postnummer($raw);
    }

    /**
     * @param string[] $offered
     * @return array<string, array<int, array{name: string, id: int}>>
     */
    private static function blocks_from_post(array $offered): array
    {
        $names = isset($_POST['lts_block_name']) && is_array($_POST['lts_block_name']) ? wp_unslash($_POST['lts_block_name']) : array();
        $ids = isset($_POST['lts_block_id']) && is_array($_POST['lts_block_id']) ? wp_unslash($_POST['lts_block_id']) : array();
        $map = array();
        foreach ($offered as $slug) {
            if (!isset($ids[$slug]) || !is_array($ids[$slug])) {
                continue;
            }
            $slug_names = isset($names[$slug]) && is_array($names[$slug]) ? $names[$slug] : array();
            $rows = array();
            foreach ($ids[$slug] as $index => $id) {
                $id = absint($id);
                $block = $id ? get_post($id) : null;
                if (!$block instanceof WP_Post || $block->post_type !== 'blocks') {
                    continue;
                }
                $rows[] = array(
                    'name' => isset($slug_names[$index]) ? sanitize_title((string) $slug_names[$index]) : '',
                    'id' => $id,
                );
            }
            if ($rows !== array()) {
                $map[$slug] = $rows;
            }
        }

        return $map;
    }

    public static function delete_template(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || $post->post_type !== LTS_TJENESTE) {
            return;
        }

        $template = LTS_Repository::template_for($post_id);
        if ($template instanceof WP_Post) {
            wp_delete_post($template->ID, true);
        }
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    private static function faq_from_post(): array
    {
        $questions = isset($_POST['lts_faq_question']) ? (array) wp_unslash($_POST['lts_faq_question']) : array();
        $answers = isset($_POST['lts_faq_answer']) ? (array) wp_unslash($_POST['lts_faq_answer']) : array();
        $rows = array();
        foreach ($questions as $index => $question) {
            $rows[] = array(
                'question' => (string) $question,
                'answer' => isset($answers[$index]) ? (string) $answers[$index] : '',
            );
        }

        return LTS_Repository::sanitize_faq($rows);
    }

    /**
     * @return int[]
     */
    private static function nearby_from_post(int $post_id): array
    {
        $posted = isset($_POST['lts_nearby']) ? (array) wp_unslash($_POST['lts_nearby']) : array();
        $ids = array();
        foreach ($posted as $id) {
            $id = absint($id);
            $post = $id ? get_post($id) : null;
            if ($post instanceof WP_Post && $post->post_type === LTS_KOMMUNE && $id !== $post_id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array{phone: string, email: string, address: string, gf_id: string, emergency: string, hours: array<string, mixed>|null} $contact
     */
    private static function contact_fields(array $contact, bool $allow_inherit): void
    {
        echo '<h3>' . esc_html__('Kontakt', 'lokale-tjenestesider') . '</h3>';
        echo '<p><label>' . esc_html__('Telefon', 'lokale-tjenestesider') . '<br><input type="text" class="widefat" name="lts_contact[phone]" value="' . esc_attr($contact['phone']) . '"></label></p>';
        echo '<p><label>' . esc_html__('E-post', 'lokale-tjenestesider') . '<br><input type="email" class="widefat" name="lts_contact[email]" value="' . esc_attr($contact['email']) . '"></label></p>';
        echo '<p><label>' . esc_html__('Adresse', 'lokale-tjenestesider') . '<br><textarea class="widefat" rows="3" name="lts_contact[address]">' . esc_textarea($contact['address']) . '</textarea></label></p>';
        echo '<p><label>' . esc_html__('Vakttelefon', 'lokale-tjenestesider') . '<br><input type="text" class="widefat" name="lts_contact[emergency]" value="' . esc_attr($contact['emergency']) . '"></label></p>';
        echo '<p><label>' . esc_html__('Skjema-ID', 'lokale-tjenestesider') . '<br><input type="text" class="regular-text" name="lts_contact[gf_id]" value="' . esc_attr($contact['gf_id']) . '"></label></p>';

        $custom = is_array($contact['hours']);
        echo '<h3>' . esc_html__('Åpningstider', 'lokale-tjenestesider') . '</h3>';
        if ($allow_inherit) {
            echo '<p><label><input type="radio" name="lts_hours_mode" value="inherit" ' . checked(!$custom, true, false) . '> ' . esc_html__('Arv fra fylket', 'lokale-tjenestesider') . '</label><br>';
            echo '<label><input type="radio" name="lts_hours_mode" value="custom" ' . checked($custom, true, false) . '> ' . esc_html__('Overstyr hele uken', 'lokale-tjenestesider') . '</label></p>';
        } else {
            echo '<input type="hidden" name="lts_hours_mode" value="custom">';
        }

        $hours = is_array($contact['hours']) ? $contact['hours'] : array();
        echo '<table class="widefat lts-hours"><tbody>';
        foreach (LTS_Repository::weekdays() as $day => $label) {
            $slot = isset($hours[$day]) && is_array($hours[$day]) ? $hours[$day] : null;
            $open = is_array($slot);
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
            echo '<label><input type="radio" name="lts_hours[' . esc_attr($day) . '][state]" value="closed" ' . checked(!$open, true, false) . '> ' . esc_html__('Stengt', 'lokale-tjenestesider') . '</label> ';
            echo '<label><input type="radio" name="lts_hours[' . esc_attr($day) . '][state]" value="open" ' . checked($open, true, false) . '> ' . esc_html__('Åpent', 'lokale-tjenestesider') . '</label> ';
            echo '<input type="time" name="lts_hours[' . esc_attr($day) . '][opens]" value="' . esc_attr($open ? (string) $slot['opens'] : '') . '"> ';
            echo '<input type="time" name="lts_hours[' . esc_attr($day) . '][closes]" value="' . esc_attr($open ? (string) $slot['closes'] : '') . '">';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function service_offer_fields(WP_Post $post, bool $kommune): void
    {
        $services = self::posts(LTS_TJENESTE);
        $allowed = null;
        if ($kommune) {
            $fylke_id = (int) get_post_meta($post->ID, LTS_META_FYLKE, true);
            $allowed = $fylke_id > 0 ? LTS_Repository::offered_slugs($fylke_id) : array();
            $services = array_values(array_filter($services, function (WP_Post $service) use ($allowed): bool {
                return in_array($service->post_name, $allowed, true);
            }));
        }

        echo '<h3>' . esc_html__('Tjenester', 'lokale-tjenestesider') . '</h3>';
        if ($kommune) {
            echo '<p class="description">' . esc_html__('Tomt prisfelt arver fylket. 0 er en gyldig pris. Lokal tekst og SEO lagres på siden for denne kombinasjonen.', 'lokale-tjenestesider') . '</p>';
        }
        if ($services === array()) {
            if ($kommune && (int) get_post_meta($post->ID, LTS_META_FYLKE, true) <= 0) {
                $empty = __('Velg fylke og lagre. Deretter kan du huke av tjenestene fylket tilbyr.', 'lokale-tjenestesider');
            } elseif ($kommune) {
                $empty = __('Fylket tilbyr ingen tjenester ennå.', 'lokale-tjenestesider');
            } else {
                $empty = __('Opprett en tjeneste før du velger hva fylket tilbyr.', 'lokale-tjenestesider');
            }
            echo '<p>' . esc_html($empty) . '</p>';
            return;
        }

        $offered = LTS_Repository::offered_slugs($post->ID);
        $prices = LTS_Repository::prices($post->ID);
        foreach ($services as $service) {
            $slug = $service->post_name;
            $on = in_array($slug, $offered, true);
            $side = null;
            if ($kommune) {
                $side = LTS_Repository::side_by_pair($service->ID, $post->ID);
            }
            $value = array_key_exists($slug, $prices) ? LTS_Repository::price_string($prices[$slug]) : '';
            echo '<div class="lts-offer">';
            echo '<label><input type="checkbox" data-lts-offer name="lts_offer[' . esc_attr($slug) . '][on]" value="1" ' . checked($on, true, false) . '> ' . esc_html(sprintf(
                /* translators: %s: service name */
                __('Tilbyr %s', 'lokale-tjenestesider'),
                get_the_title($service)
            )) . '</label>';
            echo '<div class="lts-offer-fields"' . ($on ? '' : ' hidden') . '>';
            echo '<p><label>' . esc_html__('Pris', 'lokale-tjenestesider') . '<br><input type="text" class="regular-text" inputmode="decimal" name="lts_prices[' . esc_attr($slug) . ']" value="' . esc_attr($value) . '"></label></p>';
            if ($kommune) {
                $description = $side instanceof WP_Post ? LTS_Repository::description($side->ID) : '';
                $seo = $side instanceof WP_Post ? (string) get_post_meta($side->ID, LTS_META_SEO_TITLE, true) : '';
                $meta = $side instanceof WP_Post ? (string) get_post_meta($side->ID, LTS_META_META_DESC, true) : '';
                echo '<p><label>' . esc_html__('Lokal tekst', 'lokale-tjenestesider') . '<br><textarea class="large-text" rows="4" name="lts_offer[' . esc_attr($slug) . '][description]">' . esc_textarea($description) . '</textarea></label></p>';
                echo '<p class="description">' . esc_html__('Vises av elementet Lokal tekst. Tomt felt bruker kommunens beskrivelse.', 'lokale-tjenestesider') . '</p>';
                echo '<p><label>' . esc_html__('SEO-tittel', 'lokale-tjenestesider') . '<br><input type="text" class="large-text" name="lts_offer[' . esc_attr($slug) . '][seo_title]" value="' . esc_attr($seo) . '"></label></p>';
                echo '<p class="description">' . esc_html__('Tomt felt blir «Tjeneste i kommune».', 'lokale-tjenestesider') . '</p>';
                echo '<p><label>' . esc_html__('Meta description', 'lokale-tjenestesider') . '<br><textarea class="large-text" rows="3" name="lts_offer[' . esc_attr($slug) . '][meta_description]">' . esc_textarea($meta) . '</textarea></label></p>';
                if ($side instanceof WP_Post) {
                    $layout = function_exists('ux_builder_edit_url') ? ux_builder_edit_url($side->ID) : get_edit_post_link($side->ID, 'raw');
                    if (is_string($layout) && $layout !== '') {
                        echo '<p><a href="' . esc_url($layout) . '">' . esc_html__('Rediger layout i UX Builder', 'lokale-tjenestesider') . '</a></p>';
                    }
                }
            }
            echo '</div></div>';
        }
    }

    /**
     * @param string[]|null $allowed
     * @return string[]
     */
    private static function offers_from_post(?array $allowed): array
    {
        $posted = isset($_POST['lts_offer']) && is_array($_POST['lts_offer']) ? wp_unslash($_POST['lts_offer']) : array();
        $slugs = array();
        foreach ($posted as $slug => $row) {
            if (!is_array($row) || empty($row['on'])) {
                continue;
            }
            $slug = (string) $slug;
            if (!LTS_Repository::is_slug($slug)) {
                continue;
            }
            if ($allowed !== null && !in_array($slug, $allowed, true)) {
                continue;
            }
            $slugs[] = $slug;
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param string[] $offered
     * @return array<string, float>
     */
    private static function prices_for(array $offered): array
    {
        $prices = self::prices_from_post();
        $keep = array();
        foreach ($offered as $slug) {
            if (array_key_exists($slug, $prices)) {
                $keep[$slug] = $prices[$slug];
            }
        }

        return $keep;
    }

    /**
     * @param string[] $previous
     * @param string[] $offered
     */
    private static function limit_kommune_offers(int $fylke_id, array $previous, array $offered): void
    {
        $added = array_values(array_diff($offered, $previous));
        $removed = array_values(array_diff($previous, $offered));
        if ($added === array() && $removed === array()) {
            return;
        }

        $kommuner = get_posts(array(
            'post_type' => LTS_KOMMUNE,
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => -1,
            'meta_key' => LTS_META_FYLKE,
            'meta_value' => (string) $fylke_id,
            'suppress_filters' => true,
        ));
        foreach ($kommuner as $kommune) {
            if (!$kommune instanceof WP_Post) {
                continue;
            }
            $current = LTS_Repository::offered_slugs($kommune->ID);
            $next = $current;
            foreach ($removed as $slug) {
                $next = array_values(array_diff($next, array($slug)));
                $service = LTS_Repository::by_slug(LTS_TJENESTE, $slug);
                if ($service instanceof WP_Post) {
                    self::set_side_status($service->ID, $kommune->ID, 'draft');
                }
            }
            foreach ($added as $slug) {
                $service = LTS_Repository::by_slug(LTS_TJENESTE, $slug);
                if (!$service instanceof WP_Post) {
                    continue;
                }
                if (!in_array($slug, $next, true)) {
                    $next[] = $slug;
                }
                self::ensure_side($service, $kommune->ID, 'publish');
            }
            if ($next !== $current) {
                update_post_meta($kommune->ID, LTS_META_SERVICES, array_values($next));
            }
        }
    }

    /**
     * @param string[] $offered
     */
    private static function sync_kommune_sides(int $kommune_id, array $offered): void
    {
        $posted = isset($_POST['lts_offer']) && is_array($_POST['lts_offer']) ? wp_unslash($_POST['lts_offer']) : array();
        foreach (self::posts(LTS_TJENESTE) as $service) {
            if (in_array($service->post_name, $offered, true)) {
                $side_id = self::ensure_side($service, $kommune_id, 'publish');
                if ($side_id <= 0) {
                    continue;
                }
                $row = isset($posted[$service->post_name]) && is_array($posted[$service->post_name]) ? $posted[$service->post_name] : array();
                update_post_meta($side_id, LTS_META_DESCRIPTION, wp_kses_post(isset($row['description']) ? (string) $row['description'] : ''));
                update_post_meta($side_id, LTS_META_SEO_TITLE, sanitize_text_field(isset($row['seo_title']) ? (string) $row['seo_title'] : ''));
                update_post_meta($side_id, LTS_META_META_DESC, sanitize_textarea_field(isset($row['meta_description']) ? (string) $row['meta_description'] : ''));
                continue;
            }
            self::set_side_status($service->ID, $kommune_id, 'draft');
        }
    }

    private static function ensure_side(WP_Post $service, int $kommune_id, string $status): int
    {
        $existing = LTS_Repository::side_by_pair($service->ID, $kommune_id);
        $title = get_the_title($service) . ' i ' . get_the_title($kommune_id);
        if ($existing instanceof WP_Post) {
            if ($existing->post_status !== $status) {
                wp_update_post(array(
                    'ID' => $existing->ID,
                    'post_status' => $status,
                ));
            }
            return (int) $existing->ID;
        }

        $id = wp_insert_post(array(
            'post_type' => LTS_SIDE,
            'post_status' => $status,
            'post_title' => $title,
        ), true);
        if (is_wp_error($id) || !$id) {
            return 0;
        }

        update_post_meta($id, LTS_META_TJENESTE, $service->ID);
        update_post_meta($id, LTS_META_KOMMUNE, $kommune_id);
        update_post_meta($id, LTS_META_PAIR, $service->ID . ':' . $kommune_id);

        return (int) $id;
    }

    private static function set_side_status(int $service_id, int $kommune_id, string $status): void
    {
        $existing = LTS_Repository::side_by_pair($service_id, $kommune_id);
        if (!$existing instanceof WP_Post || $existing->post_status === $status) {
            return;
        }

        wp_update_post(array(
            'ID' => $existing->ID,
            'post_status' => $status,
        ));
    }

    /**
     * @param array<int, array{question: string, answer: string}> $faq
     */
    private static function faq_fields(array $faq): void
    {
        echo '<h3>' . esc_html__('FAQ', 'lokale-tjenestesider') . '</h3>';
        echo '<div id="lts-faq">';
        if ($faq === array()) {
            $faq[] = array('question' => '', 'answer' => '');
        }
        foreach ($faq as $row) {
            self::faq_row($row['question'], $row['answer']);
        }
        echo '</div>';
        echo '<p><button type="button" class="button" data-lts-add-faq>' . esc_html__('Legg til spørsmål', 'lokale-tjenestesider') . '</button></p>';
        echo '<template id="lts-faq-template">';
        self::faq_row('', '');
        echo '</template>';
    }

    private static function faq_row(string $question, string $answer): void
    {
        echo '<div class="lts-faq-row">';
        echo '<p><label>' . esc_html__('Spørsmål', 'lokale-tjenestesider') . '<br><input type="text" class="widefat" name="lts_faq_question[]" value="' . esc_attr($question) . '"></label></p>';
        echo '<p><label>' . esc_html__('Svar', 'lokale-tjenestesider') . '<br><textarea class="widefat" rows="3" name="lts_faq_answer[]">' . esc_textarea($answer) . '</textarea></label></p>';
        echo '<p><button type="button" class="button-link-delete" data-lts-remove-faq>' . esc_html__('Fjern', 'lokale-tjenestesider') . '</button></p>';
        echo '</div>';
    }

    private static function nearby_field(int $post_id): void
    {
        $selected = LTS_Repository::nearby_ids($post_id);
        echo '<p><label for="lts_nearby"><strong>' . esc_html__('Nærliggende kommuner', 'lokale-tjenestesider') . '</strong></label></p>';
        echo '<select id="lts_nearby" name="lts_nearby[]" multiple size="8" class="lts-nearby">';
        foreach (self::posts(LTS_KOMMUNE) as $kommune) {
            if ((int) $kommune->ID === $post_id) {
                continue;
            }
            echo '<option value="' . esc_attr((string) $kommune->ID) . '" ' . selected(in_array((int) $kommune->ID, $selected, true), true, false) . '>' . esc_html(get_the_title($kommune)) . '</option>';
        }
        echo '</select>';
    }

    private static function text_field(string $name, string $label, string $value): void
    {
        echo '<p><label for="' . esc_attr($name) . '"><strong>' . esc_html($label) . '</strong></label><br>';
        echo '<input type="text" class="widefat" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></p>';
    }

    private static function textarea_field(string $name, string $label, string $value, string $help): void
    {
        echo '<p><label for="' . esc_attr($name) . '"><strong>' . esc_html($label) . '</strong></label><br>';
        echo '<textarea class="widefat" rows="5" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea>';
        if ($help !== '') {
            echo '<span class="description">' . esc_html($help) . '</span>';
        }
        echo '</p>';
    }

    /**
     * @return array{mode: string, field: string, value: string}
     */
    private static function people_from_post(): array
    {
        $mode = isset($_POST['lts_people_mode']) ? sanitize_key(wp_unslash((string) $_POST['lts_people_mode'])) : 'uten';
        if (!in_array($mode, array('uten', 'basert', 'med'), true)) {
            $mode = 'uten';
        }

        $field = isset($_POST['lts_people_field']) ? sanitize_key(wp_unslash((string) $_POST['lts_people_field'])) : '';
        $value = isset($_POST['lts_people_value']) ? sanitize_title(wp_unslash((string) $_POST['lts_people_value'])) : '';
        $known = null;
        foreach (LTS_Repository::people_field_choices() as $row) {
            if ($row['key'] === $field) {
                $known = $row;
                break;
            }
        }
        if ($known === null) {
            return array(
                'mode' => $mode,
                'field' => '',
                'value' => '',
            );
        }

        $kept = '';
        foreach ($known['options'] as $option) {
            if ($option['slug'] === $value) {
                $kept = $value;
                break;
            }
        }

        return array(
            'mode' => $mode,
            'field' => $field,
            'value' => $kept,
        );
    }

    private static function posted_text(string $key): string
    {
        if (!isset($_POST[$key])) {
            return '';
        }

        return sanitize_text_field(wp_unslash((string) $_POST[$key]));
    }

    private static function posted_textarea(string $key): string
    {
        if (!isset($_POST[$key])) {
            return '';
        }

        return sanitize_textarea_field(wp_unslash((string) $_POST[$key]));
    }

    private static function posted_html(string $key): string
    {
        if (!isset($_POST[$key])) {
            return '';
        }

        return wp_kses_post(wp_unslash((string) $_POST[$key]));
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
}
