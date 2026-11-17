<?php

if (!defined('ABSPATH')) {
    exit;
}

class LTS_Import
{
    const PREVIEW = 'lts_import_preview_';
    const RESULT = 'lts_import_result_';

    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'handle'));
    }

    public static function menu(): void
    {
        add_submenu_page(
            'lokale-tjenestesider',
            __('Import', 'lokale-tjenestesider'),
            __('Import', 'lokale-tjenestesider'),
            'edit_posts',
            'lts-import',
            array(__CLASS__, 'render')
        );
    }

    public static function handle(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page === 'lts-import' && isset($_GET['lts_export'])) {
            check_admin_referer('lts_import_export');
            self::download();
        }
        if (!isset($_POST['lts_import_step'])) {
            return;
        }

        $step = sanitize_key((string) wp_unslash($_POST['lts_import_step']));
        if ($step === 'preview') {
            check_admin_referer('lts_import_preview');
            self::store_preview(self::read_json());
        } elseif ($step === 'confirm') {
            check_admin_referer('lts_import_confirm');
            self::confirm();
        } elseif ($step === 'cancel') {
            check_admin_referer('lts_import_cancel');
            delete_transient(self::PREVIEW . get_current_user_id());
        }

        wp_safe_redirect(admin_url('admin.php?page=lts-import'));
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }

        $preview = get_transient(self::PREVIEW . get_current_user_id());
        $result = get_transient(self::RESULT . get_current_user_id());
        if (is_array($result)) {
            delete_transient(self::RESULT . get_current_user_id());
        }

        $notice = is_array($result) && isset($result['message']) ? (string) $result['message'] : '';
        $saved = is_array($result) && $notice === '';
        $step = 1;
        if ($saved) {
            $step = 3;
        } elseif (is_array($preview)) {
            $step = 2;
        }

        LTS_Post_Types::shell_start(
            __('Tjenestesider', 'lokale-tjenestesider'),
            __('Last opp JSON, se over endringene, og lagre først når forhåndsvisningen er uten feil. En ny import oppdaterer treff på slug og rører ikke UX Builder-layouten.', 'lokale-tjenestesider'),
            'lts-import'
        );

        self::steps($step);

        if ($step === 3) {
            self::render_saved($result);
        } elseif ($step === 2) {
            echo '<div class="lts-card lts-import">';
            if (!self::render_preview($preview)) {
                echo '</div>';
            }
        } else {
            if ($notice !== '') {
                echo '<div class="lts-alert is-error"><p>' . esc_html($notice) . '</p></div>';
            }
            self::render_upload();
        }

        LTS_Post_Types::shell_end();
    }

    private static function steps(int $current): void
    {
        $items = array(
            1 => __('Last opp', 'lokale-tjenestesider'),
            2 => __('Forhåndsvis', 'lokale-tjenestesider'),
            3 => __('Lagret', 'lokale-tjenestesider'),
        );

        echo '<ol class="lts-steps">';
        foreach ($items as $number => $label) {
            $class = '';
            if ($number === $current) {
                $class = ' is-current';
            } elseif ($number < $current) {
                $class = ' is-done';
            }
            echo '<li class="lts-step' . $class . '"><span>' . esc_html((string) $number) . '</span>' . esc_html($label) . '</li>';
        }
        echo '</ol>';
    }

    private static function render_upload(): void
    {
        echo '<div class="lts-card lts-import">';
        echo '<h2>' . esc_html__('JSON', 'lokale-tjenestesider') . '</h2>';
        echo '<p class="lts-sub">' . esc_html__('Fil eller innlimt tekst. Ingenting lagres før du bekrefter forhåndsvisningen.', 'lokale-tjenestesider') . '</p>';
        echo '<form method="post" enctype="multipart/form-data" class="lts-import-form">';
        wp_nonce_field('lts_import_preview');
        echo '<input type="hidden" name="lts_import_step" value="preview">';
        echo '<p class="lts-t">' . esc_html__('JSON-fil · maks 8 MB', 'lokale-tjenestesider') . '</p>';
        echo '<label class="lts-drop" for="lts_json" data-lts-drop>';
        echo '<input type="file" id="lts_json" name="lts_json" accept="application/json,.json">';
        echo '<strong data-lts-drop-title>' . esc_html__('Velg fil eller dra den hit', 'lokale-tjenestesider') . '</strong>';
        echo '<span class="lts-h">' . esc_html__('json', 'lokale-tjenestesider') . '</span>';
        echo '<span class="lts-drop-name" data-lts-drop-name hidden></span>';
        echo '</label>';
        echo '<p class="lts-or">' . esc_html__('ELLER', 'lokale-tjenestesider') . '</p>';
        echo '<label class="lts-t" for="lts_json_paste">' . esc_html__('Lim inn JSON', 'lokale-tjenestesider') . '</label>';
        echo '<textarea id="lts_json_paste" name="lts_json_paste" rows="8"></textarea>';
        echo '<div class="lts-import-actions">';
        echo '<button type="submit" class="lts-btn is-primary">' . esc_html__('Forhåndsvis', 'lokale-tjenestesider') . '</button>';
        $export = wp_nonce_url(admin_url('admin.php?page=lts-import&lts_export=1'), 'lts_import_export');
        echo '<a class="lts-btn" href="' . esc_url($export) . '">' . esc_html__('Last ned eksport', 'lokale-tjenestesider') . '</a>';
        echo '</div>';
        echo '</form></div>';
        self::render_structure();
    }

    private static function render_structure(): void
    {
        echo '<div class="lts-card lts-import">';
        echo '<h2>' . esc_html__('Struktur', 'lokale-tjenestesider') . '</h2>';
        echo '<p class="lts-sub">' . esc_html__('Versjon er 1. Nøklene er slugs med små bokstaver, tall, bindestrek eller understrek. Postnummer er fire siffer. Tomme felt på kommunen arver fylket. nearby er andre kommuneslugs, ikke stedsnavn. Den utfylte oppskriften ligger i IMPORT.md i plugin-mappen. UX Builder-layout, bilder, overordnet sti og kontaktpersoner ligger ikke i fila.', 'lokale-tjenestesider') . '</p>';
        echo '<pre class="lts-json-sample">' . esc_html(self::structure_example()) . '</pre>';
        echo '</div>';
    }

    private static function structure_example(): string
    {
        return <<<'JSON'
{
  "version": 1,
  "services": {
    "spyling": {
      "title": "Spyling",
      "excerpt": "Kort ingress.",
      "content": "<p>Brødtekst.</p>"
    }
  },
  "counties": {
    "vestland": {
      "title": "Vestland",
      "contact": {
        "phone": "+47 55 00 00 00",
        "email": "vestland@example.com",
        "address": "Kontoret, 5000 Bergen",
        "open": {
          "monday": { "opens": "08:00", "closes": "16:00" },
          "saturday": false,
          "sunday": false
        },
        "emergency": { "phone": "+47 900 00 000" }
      },
      "price": { "spyling": 6000 },
      "price_note": { "spyling": "Pris fra." },
      "faq": [
        { "question": "Dekker dere hele fylket?", "answer": "Ja." }
      ],
      "municipalities": {
        "bergen": {
          "title": "Bergen",
          "description": "Lokal beskrivelse.",
          "postnummer": ["5003", "5006"],
          "nearby": ["askoy"],
          "price": { "spyling": 6200 },
          "services": {
            "spyling": {
              "title": "Spyling i Bergen",
              "meta_description": "Kort metabeskrivelse.",
              "description": "Lokal tekst."
            }
          }
        },
        "askoy": {
          "title": "Askøy",
          "nearby": ["bergen"]
        }
      }
    }
  }
}
JSON;
    }

    private static function download(): void
    {
        $json = wp_json_encode(self::export_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="lokale-tjenestesider.json"');
        echo $json;
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    private static function export_data(): array
    {
        $services = array();
        $known = array();
        foreach (self::export_posts(LTS_TJENESTE) as $post) {
            if (!LTS_Repository::is_slug($post->post_name)) {
                continue;
            }
            $known[$post->post_name] = true;
            $services[$post->post_name] = array(
                'title' => get_the_title($post),
                'excerpt' => $post->post_excerpt,
                'content' => LTS_Repository::body($post->ID),
            );
        }

        $counties = array();
        $county_slug = array();
        foreach (self::export_posts(LTS_FYLKE) as $post) {
            if (!LTS_Repository::is_slug($post->post_name)) {
                continue;
            }
            $county_slug[$post->ID] = $post->post_name;
            $county = array(
                'title' => get_the_title($post),
                'contact' => self::export_contact($post->ID),
                'price' => self::export_prices($post->ID, $known),
            );
            $notes = self::export_notes($post->ID, $known);
            if ($notes !== array()) {
                $county['price_note'] = $notes;
            }
            $county['faq'] = self::export_faq($post->ID);
            $county['municipalities'] = array();
            $counties[$post->post_name] = $county;
        }

        foreach (self::export_posts(LTS_KOMMUNE) as $post) {
            $fylke_id = (int) get_post_meta($post->ID, LTS_META_FYLKE, true);
            if (!isset($county_slug[$fylke_id]) || !LTS_Repository::is_slug($post->post_name)) {
                continue;
            }
            $slug = $county_slug[$fylke_id];
            $city = array(
                'title' => get_the_title($post),
                'description' => LTS_Repository::description($post->ID),
                'postnummer' => LTS_Repository::postnummer($post->ID),
                'nearby' => self::export_nearby($post->ID),
                'contact' => self::export_contact($post->ID),
                'price' => self::export_prices($post->ID, $known),
            );
            $notes = self::export_notes($post->ID, $known);
            if ($notes !== array()) {
                $city['price_note'] = $notes;
            }
            $city['services'] = self::export_pages($post->ID, $known);
            $counties[$slug]['municipalities'][$post->post_name] = $city;
        }

        return array(
            'version' => 1,
            'services' => $services,
            'counties' => $counties,
        );
    }

    /**
     * @return WP_Post[]
     */
    private static function export_posts(string $type): array
    {
        $posts = get_posts(array(
            'post_type' => $type,
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ));

        return is_array($posts) ? $posts : array();
    }

    /**
     * @return array<string, mixed>
     */
    private static function export_contact(int $post_id): array
    {
        $contact = LTS_Repository::contact($post_id);
        $out = array(
            'phone' => $contact['phone'],
            'email' => $contact['email'],
            'address' => $contact['address'],
        );
        if ($contact['gf_id'] !== '') {
            $out['gf_id'] = $contact['gf_id'];
        }
        if (is_array($contact['hours'])) {
            $out['open'] = $contact['hours'];
        }
        if ($contact['emergency'] !== '') {
            $out['emergency'] = array('phone' => $contact['emergency']);
        }

        return $out;
    }

    /**
     * @param array<string, bool> $known
     * @return array<string, int|float>
     */
    private static function export_prices(int $post_id, array $known): array
    {
        $prices = array();
        foreach (LTS_Repository::prices($post_id) as $slug => $amount) {
            if (!isset($known[$slug])) {
                continue;
            }
            $prices[$slug] = ((float) $amount === (float) (int) $amount) ? (int) $amount : (float) $amount;
        }
        ksort($prices);

        return $prices;
    }

    /**
     * @param array<string, bool> $known
     * @return array<string, string>
     */
    private static function export_notes(int $post_id, array $known): array
    {
        $notes = array();
        foreach (LTS_Repository::price_notes($post_id) as $slug => $note) {
            if (isset($known[$slug]) && $note !== '') {
                $notes[$slug] = $note;
            }
        }
        ksort($notes);

        return $notes;
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    private static function export_faq(int $post_id): array
    {
        $faq = array();
        foreach (LTS_Repository::faq($post_id) as $item) {
            $faq[] = array(
                'question' => $item['question'],
                'answer' => $item['answer'],
            );
        }

        return $faq;
    }

    /**
     * @return string[]
     */
    private static function export_nearby(int $post_id): array
    {
        $nearby = array();
        foreach (LTS_Repository::nearby_ids($post_id) as $id) {
            $post = get_post($id);
            if ($post instanceof WP_Post && LTS_Repository::is_slug($post->post_name)) {
                $nearby[] = $post->post_name;
            }
        }
        sort($nearby);

        return $nearby;
    }

    /**
     * @param array<string, bool> $known
     * @return array<string, array{title: string, meta_description: string, description: string}>
     */
    private static function export_pages(int $post_id, array $known): array
    {
        $sides = get_posts(array(
            'post_type' => LTS_SIDE,
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => -1,
            'meta_key' => LTS_META_KOMMUNE,
            'meta_value' => (string) $post_id,
            'suppress_filters' => true,
        ));
        $pages = array();
        if (!is_array($sides)) {
            return $pages;
        }
        foreach ($sides as $side) {
            if (!$side instanceof WP_Post) {
                continue;
            }
            $service = get_post((int) get_post_meta($side->ID, LTS_META_TJENESTE, true));
            if (!$service instanceof WP_Post || !isset($known[$service->post_name])) {
                continue;
            }
            $pages[$service->post_name] = array(
                'title' => (string) get_post_meta($side->ID, LTS_META_SEO_TITLE, true),
                'meta_description' => (string) get_post_meta($side->ID, LTS_META_META_DESC, true),
                'description' => LTS_Repository::description($side->ID),
            );
        }
        ksort($pages);

        return $pages;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function render_saved(array $result): void
    {
        $create = (int) ($result['create'] ?? 0);
        $update = (int) ($result['update'] ?? 0);

        echo '<div class="lts-card lts-import-done">';
        echo '<svg class="lts-import-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5 9.2 17 19 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
        echo '<h2>' . esc_html__('Importen er lagret', 'lokale-tjenestesider') . '</h2>';
        echo '<p>' . esc_html(sprintf(
            /* translators: 1: created count, 2: updated count */
            __('%1$d opprettet og %2$d oppdatert.', 'lokale-tjenestesider'),
            $create,
            $update
        )) . '</p>';
        echo '<a class="lts-btn is-primary" href="' . esc_url(admin_url('admin.php?page=lts-import')) . '">' . esc_html__('Ny import', 'lokale-tjenestesider') . '</a>';
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $preview
     */
    private static function render_preview(array $preview): bool
    {
        $counts = isset($preview['counts']) && is_array($preview['counts']) ? $preview['counts'] : array();
        $errors = isset($preview['errors']) && is_array($preview['errors']) ? $preview['errors'] : array();
        $rows = isset($preview['rows']) && is_array($preview['rows']) ? $preview['rows'] : array();

        $create = (int) ($counts['create'] ?? 0);
        $update = (int) ($counts['update'] ?? 0);
        $unchanged = (int) ($counts['unchanged'] ?? 0);
        $error_count = count($errors);

        echo '<h2>' . esc_html__('Forhåndsvisning', 'lokale-tjenestesider') . '</h2>';
        echo '<p class="lts-sub">' . esc_html__('Slik vil importen påvirke nettstedet. Ingenting er lagret ennå.', 'lokale-tjenestesider') . '</p>';

        if ($error_count > 0) {
            echo '<div class="lts-alert is-error"><p>' . esc_html__('Feil i fila stopper lagringen. Rett JSON og last opp på nytt.', 'lokale-tjenestesider') . '</p><ul>';
            foreach ($errors as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $path = isset($error['path']) ? (string) $error['path'] : '';
                $message = isset($error['message']) ? (string) $error['message'] : '';
                echo '<li>' . esc_html($path !== '' ? $path . ': ' . $message : $message) . '</li>';
            }
            echo '</ul></div>';
        } elseif ($create + $update < 1) {
            echo '<div class="lts-alert is-ok"><p>' . esc_html__('Ingen endringer mot innholdet som allerede er lagret.', 'lokale-tjenestesider') . '</p></div>';
        } else {
            echo '<div class="lts-alert is-ok"><p>' . esc_html__('Ingen feil funnet. Du kan lagre importen.', 'lokale-tjenestesider') . '</p></div>';
        }

        echo '<ul class="lts-counts">';
        echo '<li><strong>' . esc_html((string) $create) . '</strong><span>' . esc_html__('Nye', 'lokale-tjenestesider') . '</span></li>';
        echo '<li><strong>' . esc_html((string) $update) . '</strong><span>' . esc_html__('Oppdateres', 'lokale-tjenestesider') . '</span></li>';
        echo '<li><strong>' . esc_html((string) $unchanged) . '</strong><span>' . esc_html__('Uendret', 'lokale-tjenestesider') . '</span></li>';
        echo '<li><strong>' . esc_html((string) $error_count) . '</strong><span>' . esc_html__('Feil', 'lokale-tjenestesider') . '</span></li>';
        echo '</ul>';

        if ($error_count === 0) {
            self::render_rows($rows);
        }

        self::cancel_form('lts-import-back', __('– Tilbake', 'lokale-tjenestesider'), 'lts-btn');

        $pending = $create + $update;
        if ($error_count > 0 || $pending < 1) {
            return false;
        }

        echo '</div><div class="lts-import-bar">';
        echo '<p>' . esc_html(self::ready_label($create, $update)) . '</p>';
        self::cancel_form('', __('Avbryt', 'lokale-tjenestesider'), 'lts-btn');
        echo '<form method="post">';
        wp_nonce_field('lts_import_confirm');
        echo '<input type="hidden" name="lts_import_step" value="confirm">';
        echo '<button type="submit" class="lts-btn is-primary">' . esc_html__('Lagre import', 'lokale-tjenestesider') . '</button>';
        echo '</form></div>';

        return true;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private static function store_preview($data): void
    {
        if (!is_array($data)) {
            set_transient(self::PREVIEW . get_current_user_id(), array(
                'errors' => array(array('path' => '', 'message' => __('JSON-fila kunne ikke leses.', 'lokale-tjenestesider'))),
                'counts' => array('create' => 0, 'update' => 0, 'unchanged' => 0),
                'rows' => array(),
                'data' => null,
            ), 30 * MINUTE_IN_SECONDS);
            return;
        }

        $checked = self::validate($data);
        $rows = $checked['errors'] === array() ? self::diff($checked['normalized']) : array();
        $counts = array('create' => 0, 'update' => 0, 'unchanged' => 0);
        foreach ($rows as $row) {
            if (isset($counts[$row['action']])) {
                $counts[$row['action']]++;
            }
        }

        set_transient(self::PREVIEW . get_current_user_id(), array(
            'errors' => $checked['errors'],
            'counts' => $counts,
            'rows' => $rows,
            'data' => $checked['errors'] === array() ? $data : null,
        ), 30 * MINUTE_IN_SECONDS);
    }

    private static function confirm(): void
    {
        $preview = get_transient(self::PREVIEW . get_current_user_id());
        if (!is_array($preview) || !isset($preview['data']) || !is_array($preview['data'])) {
            set_transient(self::RESULT . get_current_user_id(), array(
                'create' => 0,
                'update' => 0,
                'unchanged' => 0,
                'message' => __('Forhåndsvisningen er utløpt. Last opp fila på nytt.', 'lokale-tjenestesider'),
            ), MINUTE_IN_SECONDS);
            return;
        }

        $checked = self::validate($preview['data']);
        if ($checked['errors'] !== array()) {
            self::store_preview($preview['data']);
            return;
        }

        $counts = self::apply($checked['normalized']);
        delete_transient(self::PREVIEW . get_current_user_id());
        set_transient(self::RESULT . get_current_user_id(), $counts, MINUTE_IN_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function read_json(): ?array
    {
        $raw = '';
        if (isset($_FILES['lts_json']) && is_array($_FILES['lts_json']) && (int) $_FILES['lts_json']['error'] === UPLOAD_ERR_OK) {
            $name = isset($_FILES['lts_json']['name']) ? (string) $_FILES['lts_json']['name'] : '';
            $tmp = isset($_FILES['lts_json']['tmp_name']) ? (string) $_FILES['lts_json']['tmp_name'] : '';
            if (!preg_match('/\.json$/i', $name) || !is_uploaded_file($tmp)) {
                return null;
            }
            $contents = file_get_contents($tmp);
            $raw = is_string($contents) ? $contents : '';
        } elseif (isset($_POST['lts_json_paste'])) {
            $raw = (string) wp_unslash($_POST['lts_json_paste']);
        }

        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        if (trim($raw) === '' || strlen($raw) > 8000000) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{errors: array<int, array{path: string, message: string}>, normalized: array<string, mixed>}
     */
    public static function validate(array $data): array
    {
        $errors = array();
        $normalized = array(
            'services' => array(),
            'counties' => array(),
            'municipalities' => array(),
            'pages' => array(),
        );

        if (!isset($data['version']) || (int) $data['version'] !== 1) {
            $errors[] = self::err('version', __('Versjon må være 1.', 'lokale-tjenestesider'));
        }

        $services = isset($data['services']) && is_array($data['services']) ? $data['services'] : array();
        foreach ($services as $slug => $service) {
            $path = 'services.' . $slug;
            if (!LTS_Repository::is_slug((string) $slug)) {
                $errors[] = self::err($path, __('Slug må være små bokstaver, tall, bindestrek eller understrek.', 'lokale-tjenestesider'));
                continue;
            }
            if (!is_array($service)) {
                $errors[] = self::err($path, __('Tjenesten må være et objekt.', 'lokale-tjenestesider'));
                continue;
            }
            $existing = LTS_Repository::by_slug(LTS_TJENESTE, (string) $slug);
            $except = $existing instanceof WP_Post ? (int) $existing->ID : 0;
            if (LTS_Repository::slug_conflicts((string) $slug, $except)) {
                $errors[] = self::err($path, __('Slug er opptatt av en side, et innlegg eller en kategori.', 'lokale-tjenestesider'));
            }
            $normalized['services'][$slug] = array(
                'title' => self::need_string($service, 'title', $path, $errors),
                'excerpt' => self::optional_string($service, 'excerpt', $path, $errors),
                'content' => self::optional_string($service, 'content', $path, $errors),
            );
        }

        $known_services = array_fill_keys(array_keys($normalized['services']), true);
        $county_slugs = array();
        $municipality_slugs = array();
        $counties = isset($data['counties']) && is_array($data['counties']) ? $data['counties'] : array();

        foreach ($counties as $slug => $county) {
            $path = 'counties.' . $slug;
            if (!self::slug_ok((string) $slug, $path, $errors) || !is_array($county)) {
                if (is_array($county) === false && LTS_Repository::is_slug((string) $slug)) {
                    $errors[] = self::err($path, __('Fylket må være et objekt.', 'lokale-tjenestesider'));
                }
                continue;
            }
            $county_slugs[$slug] = true;
            $normalized['counties'][$slug] = array(
                'title' => self::need_string($county, 'title', $path, $errors),
                'contact' => self::contact_block(isset($county['contact']) ? $county['contact'] : array(), $path . '.contact', $errors),
                'prices' => self::price_block(isset($county['price']) ? $county['price'] : array(), $path . '.price', $errors),
                'notes' => self::note_block($county, $path . '.price_note', $errors),
                'faq' => self::faq_block(isset($county['faq']) ? $county['faq'] : array(), $path . '.faq', $errors),
            );

            $municipalities = isset($county['municipalities']) && is_array($county['municipalities']) ? $county['municipalities'] : array();
            foreach ($municipalities as $city_slug => $city) {
                $city_path = $path . '.municipalities.' . $city_slug;
                if (!self::slug_ok((string) $city_slug, $city_path, $errors)) {
                    continue;
                }
                if (isset($municipality_slugs[$city_slug])) {
                    $errors[] = self::err($city_path, __('Kommuneslug er brukt mer enn én gang.', 'lokale-tjenestesider'));
                    continue;
                }
                if (!is_array($city)) {
                    $errors[] = self::err($city_path, __('Kommunen må være et objekt.', 'lokale-tjenestesider'));
                    continue;
                }
                if (isset($city['title']) && !is_string($city['title'])) {
                    $errors[] = self::err($city_path . '.title', __('Kommunenavn må være en tekst. Tjenestetitler ligger under services.', 'lokale-tjenestesider'));
                }
                $municipality_slugs[$city_slug] = true;
                $nearby = array();
                if (isset($city['nearby'])) {
                    if (!is_array($city['nearby'])) {
                        $errors[] = self::err($city_path . '.nearby', __('Nærliggende kommuner må være en liste med slugs.', 'lokale-tjenestesider'));
                    } else {
                        foreach ($city['nearby'] as $near) {
                            if (!is_string($near) || !LTS_Repository::is_slug($near)) {
                                $errors[] = self::err($city_path . '.nearby', __('Ugyldig kommuneslug.', 'lokale-tjenestesider'));
                                continue;
                            }
                            $nearby[] = $near;
                        }
                    }
                }

                $normalized['municipalities'][$city_slug] = array(
                    'title' => self::need_string($city, 'title', $city_path, $errors),
                    'description' => self::optional_string($city, 'description', $city_path, $errors),
                    'county' => (string) $slug,
                    'nearby' => $nearby,
                    'contact' => self::contact_block(isset($city['contact']) ? $city['contact'] : array(), $city_path . '.contact', $errors),
                    'prices' => self::price_block(isset($city['price']) ? $city['price'] : array(), $city_path . '.price', $errors),
                    'notes' => self::note_block($city, $city_path . '.price_note', $errors),
                    'postnummer' => self::postnummer_block($city, $city_path . '.postnummer', $errors),
                    'services' => array(),
                );

                $pages = isset($city['services']) && is_array($city['services']) ? $city['services'] : array();
                foreach ($pages as $service_slug => $page) {
                    $page_path = $city_path . '.services.' . $service_slug;
                    if (!self::slug_ok((string) $service_slug, $page_path, $errors)) {
                        continue;
                    }
                    if (!is_array($page)) {
                        $errors[] = self::err($page_path, __('Den lokale siden må være et objekt.', 'lokale-tjenestesider'));
                        continue;
                    }
                    $known_services[(string) $service_slug] = $known_services[(string) $service_slug] ?? false;
                    $normalized['pages'][] = array(
                        'service' => (string) $service_slug,
                        'municipality' => (string) $city_slug,
                        'title' => self::optional_string($page, 'title', $page_path, $errors),
                        'meta_description' => self::optional_string($page, 'meta_description', $page_path, $errors),
                        'description' => self::optional_string($page, 'description', $page_path, $errors),
                    );
                    $normalized['municipalities'][$city_slug]['services'][] = (string) $service_slug;
                }
            }
        }

        foreach ($normalized['counties'] as $slug => $county) {
            self::known_prices($county['prices'], 'counties.' . $slug . '.price', $known_services, $errors);
            self::known_notes($county['notes'], 'counties.' . $slug . '.price_note', $known_services, $errors);
        }
        foreach ($normalized['municipalities'] as $slug => $city) {
            self::known_prices($city['prices'], 'counties.' . $city['county'] . '.municipalities.' . $slug . '.price', $known_services, $errors);
            self::known_notes($city['notes'], 'counties.' . $city['county'] . '.municipalities.' . $slug . '.price_note', $known_services, $errors);
            foreach ($city['nearby'] as $near) {
                if (!isset($municipality_slugs[$near]) && !LTS_Repository::by_slug(LTS_KOMMUNE, $near)) {
                    $errors[] = self::err('counties.' . $city['county'] . '.municipalities.' . $slug . '.nearby', sprintf(
                        /* translators: %s: municipality slug */
                        __('Ukjent kommune «%s».', 'lokale-tjenestesider'),
                        $near
                    ));
                }
            }
        }
        foreach ($normalized['pages'] as $page) {
            $slug = $page['service'];
            $in_file = isset($normalized['services'][$slug]);
            if (!$in_file && !LTS_Repository::by_slug(LTS_TJENESTE, $slug)) {
                $errors[] = self::err('services.' . $slug, sprintf(
                    /* translators: %s: service slug */
                    __('Ukjent tjeneste «%s».', 'lokale-tjenestesider'),
                    $slug
                ));
            }
        }

        return array(
            'errors' => $errors,
            'normalized' => $normalized,
        );
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<int, array{action: string, type: string, slug: string, title: string}>
     */
    private static function diff(array $normalized): array
    {
        $rows = array();
        foreach ($normalized['services'] as $slug => $service) {
            $rows[] = self::row('Tjeneste', (string) $slug, (string) $service['title'], self::service_snapshot($service), self::stored_service((string) $slug));
        }
        foreach ($normalized['counties'] as $slug => $county) {
            $rows[] = self::row('Fylke', (string) $slug, (string) $county['title'], self::county_snapshot($county, (string) $slug), self::stored_county((string) $slug));
        }
        foreach ($normalized['municipalities'] as $slug => $city) {
            $rows[] = self::row('Kommune', (string) $slug, (string) $city['title'], self::city_snapshot($city, (string) $slug), self::stored_city((string) $slug));
        }
        foreach ($normalized['pages'] as $page) {
            $slug = $page['service'] . '/' . $page['municipality'];
            $rows[] = self::row('Lokal side', $slug, (string) $page['title'], self::page_snapshot($page), self::stored_page($page['service'], $page['municipality']));
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{create: int, update: int, unchanged: int}
     */
    private static function apply(array $normalized): array
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }
        wp_suspend_cache_invalidation(true);
        if (!defined('LTS_IMPORTING')) {
            define('LTS_IMPORTING', true);
        }

        $counts = array('create' => 0, 'update' => 0, 'unchanged' => 0);
        foreach (self::diff($normalized) as $row) {
            if (isset($counts[$row['action']])) {
                $counts[$row['action']]++;
            }
        }
        $county_ids = array();
        $city_ids = array();
        $service_ids = array();

        foreach ($normalized['services'] as $slug => $service) {
            $result = self::upsert(LTS_TJENESTE, (string) $slug, (string) $service['title'], array(
                'post_excerpt' => (string) $service['excerpt'],
            ));
            if (!$result['id']) {
                continue;
            }
            update_post_meta($result['id'], LTS_META_BODY, wp_kses_post((string) $service['content']));
            $service_ids[$slug] = $result['id'];
        }
        foreach ($normalized['counties'] as $slug => $county) {
            $result = self::upsert(LTS_FYLKE, (string) $slug, (string) $county['title'], array());
            if (!$result['id']) {
                continue;
            }
            update_post_meta($result['id'], LTS_META_CONTACT, LTS_Repository::sanitize_contact($county['contact']));
            update_post_meta($result['id'], LTS_META_PRICES, LTS_Repository::sanitize_prices($county['prices']));
            if (is_array($county['notes'])) {
                update_post_meta($result['id'], LTS_META_PRICE_NOTES, self::clean_notes($county['notes']));
            }
            update_post_meta($result['id'], LTS_META_FAQ, LTS_Repository::sanitize_faq($county['faq']));
            $county_ids[$slug] = $result['id'];
        }
        foreach ($normalized['municipalities'] as $slug => $city) {
            $result = self::upsert(LTS_KOMMUNE, (string) $slug, (string) $city['title'], array());
            if (!$result['id']) {
                continue;
            }
            $county_id = isset($county_ids[$city['county']]) ? $county_ids[$city['county']] : 0;
            if (!$county_id) {
                $existing = LTS_Repository::by_slug(LTS_FYLKE, (string) $city['county']);
                $county_id = $existing instanceof WP_Post ? $existing->ID : 0;
            }
            update_post_meta($result['id'], LTS_META_FYLKE, $county_id);
            update_post_meta($result['id'], LTS_META_DESCRIPTION, wp_kses_post((string) $city['description']));
            update_post_meta($result['id'], LTS_META_CONTACT, LTS_Repository::sanitize_contact($city['contact']));
            update_post_meta($result['id'], LTS_META_PRICES, LTS_Repository::sanitize_prices($city['prices']));
            if (is_array($city['notes'])) {
                update_post_meta($result['id'], LTS_META_PRICE_NOTES, self::clean_notes($city['notes']));
            }
            if (is_array($city['postnummer'])) {
                update_post_meta($result['id'], LTS_META_POSTNUMMER, $city['postnummer']);
            }
            $city_ids[$slug] = $result['id'];
        }
        foreach ($normalized['municipalities'] as $slug => $city) {
            $ids = array();
            foreach ($city['nearby'] as $near) {
                if (isset($city_ids[$near])) {
                    $ids[] = $city_ids[$near];
                    continue;
                }
                $existing = LTS_Repository::by_slug(LTS_KOMMUNE, $near);
                if ($existing instanceof WP_Post) {
                    $ids[] = $existing->ID;
                }
            }
            if (!empty($city_ids[$slug])) {
                update_post_meta($city_ids[$slug], LTS_META_NEARBY, $ids);
            }
        }
        foreach ($normalized['pages'] as $page) {
            $service_id = self::id_for($service_ids, LTS_TJENESTE, $page['service']);
            $city_id = self::id_for($city_ids, LTS_KOMMUNE, $page['municipality']);
            if (!$service_id || !$city_id) {
                continue;
            }
            $existing = LTS_Repository::side_by_pair($service_id, $city_id);
            $title = trim((string) $page['title']);
            if ($title === '') {
                $title = get_the_title($service_id) . ' i ' . get_the_title($city_id);
            }
            if ($existing instanceof WP_Post) {
                wp_update_post(array(
                    'ID' => $existing->ID,
                    'post_title' => $title,
                    'post_status' => 'publish',
                ));
                $id = $existing->ID;
            } else {
                $id = wp_insert_post(array(
                    'post_type' => LTS_SIDE,
                    'post_status' => 'publish',
                    'post_title' => $title,
                ), true);
            }
            if (is_wp_error($id) || !$id) {
                continue;
            }
            update_post_meta($id, LTS_META_TJENESTE, $service_id);
            update_post_meta($id, LTS_META_KOMMUNE, $city_id);
            update_post_meta($id, LTS_META_PAIR, $service_id . ':' . $city_id);
            update_post_meta($id, LTS_META_SEO_TITLE, sanitize_text_field((string) $page['title']));
            update_post_meta($id, LTS_META_META_DESC, sanitize_textarea_field((string) $page['meta_description']));
            update_post_meta($id, LTS_META_DESCRIPTION, wp_kses_post((string) $page['description']));
        }
        self::sync_offers($normalized, $county_ids, $city_ids);

        wp_suspend_cache_invalidation(false);
        LTS_Repository::sync_service_slugs(true);

        return $counts;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{action: string, id: int}
     */
    /**
     * @param array<string, mixed> $normalized
     * @param array<string, int> $county_ids
     * @param array<string, int> $city_ids
     */
    private static function sync_offers(array $normalized, array $county_ids, array $city_ids): void
    {
        foreach ($normalized['counties'] as $slug => $county) {
            if (empty($county_ids[$slug]) || !is_array($county)) {
                continue;
            }
            $slugs = array();
            $prices = isset($county['prices']) && is_array($county['prices']) ? $county['prices'] : array();
            foreach ($prices as $service => $amount) {
                unset($amount);
                if (LTS_Repository::is_slug((string) $service)) {
                    $slugs[] = (string) $service;
                }
            }
            foreach ($normalized['municipalities'] as $city) {
                if (!is_array($city) || (string) $city['county'] !== (string) $slug) {
                    continue;
                }
                $services = isset($city['services']) && is_array($city['services']) ? $city['services'] : array();
                foreach ($services as $service) {
                    if (LTS_Repository::is_slug((string) $service)) {
                        $slugs[] = (string) $service;
                    }
                }
            }
            update_post_meta((int) $county_ids[$slug], LTS_META_SERVICES, array_values(array_unique($slugs)));
        }

        foreach ($normalized['municipalities'] as $slug => $city) {
            if (empty($city_ids[$slug]) || !is_array($city)) {
                continue;
            }
            $services = array();
            $listed = isset($city['services']) && is_array($city['services']) ? $city['services'] : array();
            foreach ($listed as $service) {
                if (LTS_Repository::is_slug((string) $service)) {
                    $services[] = (string) $service;
                }
            }
            $services = array_values(array_unique($services));
            $city_id = (int) $city_ids[$slug];
            update_post_meta($city_id, LTS_META_SERVICES, $services);

            $sides = get_posts(array(
                'post_type' => LTS_SIDE,
                'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => 200,
                'meta_key' => LTS_META_KOMMUNE,
                'meta_value' => (string) $city_id,
                'suppress_filters' => true,
            ));
            foreach ($sides as $side) {
                $service = get_post((int) get_post_meta($side->ID, LTS_META_TJENESTE, true));
                if (!$service instanceof WP_Post || in_array($service->post_name, $services, true)) {
                    continue;
                }
                if ($side->post_status === 'draft') {
                    continue;
                }
                wp_update_post(array(
                    'ID' => $side->ID,
                    'post_status' => 'draft',
                ));
            }
        }
    }

    private static function upsert(string $type, string $slug, string $title, array $fields): array
    {
        $existing = LTS_Repository::by_slug($type, $slug);
        $postarr = array_merge($fields, array(
            'post_type' => $type,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_name' => $slug,
        ));
        if ($existing instanceof WP_Post) {
            $postarr['ID'] = $existing->ID;
            wp_update_post($postarr);
            return array('action' => 'update', 'id' => $existing->ID);
        }

        $id = wp_insert_post($postarr, true);
        if (is_wp_error($id) || !$id) {
            return array('action' => 'unchanged', 'id' => 0);
        }

        return array('action' => 'create', 'id' => (int) $id);
    }

    /**
     * @param array<string, int> $map
     */
    private static function id_for(array $map, string $type, string $slug): int
    {
        if (isset($map[$slug])) {
            return (int) $map[$slug];
        }
        $post = LTS_Repository::by_slug($type, $slug);

        return $post instanceof WP_Post ? (int) $post->ID : 0;
    }

    /**
     * @param array<string, mixed>|null $stored
     * @param array<string, mixed> $incoming
     * @return array{action: string, type: string, slug: string, title: string}
     */
    private static function row(string $type, string $slug, string $title, array $incoming, ?array $stored): array
    {
        if ($stored === null) {
            $action = 'create';
        } elseif (self::same($stored, $incoming)) {
            $action = 'unchanged';
        } else {
            $action = 'update';
        }

        return array(
            'action' => $action,
            'type' => $type,
            'slug' => $slug,
            'title' => $title,
        );
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function same(array $left, array $right): bool
    {
        return wp_json_encode(self::normalize_compare($left)) === wp_json_encode(self::normalize_compare($right));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_compare($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if (is_float($item) || (is_string($item) && is_numeric($item) && in_array($key, array(), true))) {
                $value[$key] = $item;
            }
            $value[$key] = self::normalize_compare($item);
        }
        if (self::is_assoc($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     */
    private static function is_assoc(array $value): bool
    {
        $index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $index) {
                return true;
            }
            $index++;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, string>
     */
    private static function service_snapshot(array $service): array
    {
        return array(
            'title' => (string) $service['title'],
            'excerpt' => (string) $service['excerpt'],
            'content' => wp_kses_post((string) $service['content']),
        );
    }

    /**
     * @return array<string, string>|null
     */
    private static function stored_service(string $slug): ?array
    {
        $post = LTS_Repository::by_slug(LTS_TJENESTE, $slug);
        if (!$post instanceof WP_Post) {
            return null;
        }

        return array(
            'title' => get_the_title($post),
            'excerpt' => (string) $post->post_excerpt,
            'content' => LTS_Repository::body($post->ID),
        );
    }

    /**
     * @param array<string, mixed> $county
     * @return array<string, mixed>
     */
    private static function county_snapshot(array $county, string $slug): array
    {
        return array(
            'title' => (string) $county['title'],
            'contact' => LTS_Repository::sanitize_contact($county['contact']),
            'prices' => self::price_map($county['prices']),
            'price_notes' => self::note_map(self::notes_for_snapshot($county['notes'], LTS_FYLKE, $slug)),
            'faq' => LTS_Repository::sanitize_faq($county['faq']),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function stored_county(string $slug): ?array
    {
        $post = LTS_Repository::by_slug(LTS_FYLKE, $slug);
        if (!$post instanceof WP_Post) {
            return null;
        }

        return array(
            'title' => get_the_title($post),
            'contact' => LTS_Repository::contact($post->ID),
            'prices' => self::price_map(LTS_Repository::prices($post->ID)),
            'price_notes' => self::note_map(LTS_Repository::price_notes($post->ID)),
            'faq' => LTS_Repository::faq($post->ID),
        );
    }

    /**
     * @param array<string, mixed> $city
     * @return array<string, mixed>
     */
    private static function city_snapshot(array $city, string $slug): array
    {
        $nearby = $city['nearby'];
        sort($nearby);

        return array(
            'title' => (string) $city['title'],
            'description' => wp_kses_post((string) $city['description']),
            'county' => (string) $city['county'],
            'nearby' => $nearby,
            'contact' => LTS_Repository::sanitize_contact($city['contact']),
            'prices' => self::price_map($city['prices']),
            'price_notes' => self::note_map(self::notes_for_snapshot($city['notes'], LTS_KOMMUNE, $slug)),
            'postnummer' => self::postnummer_for_snapshot($city['postnummer'], $slug),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function stored_city(string $slug): ?array
    {
        $post = LTS_Repository::by_slug(LTS_KOMMUNE, $slug);
        if (!$post instanceof WP_Post) {
            return null;
        }
        $fylke = LTS_Repository::fylke_for_kommune($post);
        $nearby = array();
        foreach (LTS_Repository::nearby_ids($post->ID) as $id) {
            $neighbor = get_post($id);
            if ($neighbor instanceof WP_Post) {
                $nearby[] = $neighbor->post_name;
            }
        }
        sort($nearby);

        return array(
            'title' => get_the_title($post),
            'description' => LTS_Repository::description($post->ID),
            'county' => $fylke instanceof WP_Post ? $fylke->post_name : '',
            'nearby' => $nearby,
            'contact' => LTS_Repository::contact($post->ID),
            'prices' => self::price_map(LTS_Repository::prices($post->ID)),
            'price_notes' => self::note_map(LTS_Repository::price_notes($post->ID)),
            'postnummer' => LTS_Repository::postnummer($post->ID),
        );
    }

    /**
     * @param array<string, string> $page
     * @return array<string, string>
     */
    private static function page_snapshot(array $page): array
    {
        return array(
            'service' => (string) $page['service'],
            'municipality' => (string) $page['municipality'],
            'title' => sanitize_text_field((string) $page['title']),
            'meta_description' => sanitize_textarea_field((string) $page['meta_description']),
            'description' => wp_kses_post((string) $page['description']),
        );
    }

    /**
     * @return array<string, string>|null
     */
    private static function stored_page(string $service, string $municipality): ?array
    {
        $tjeneste = LTS_Repository::by_slug(LTS_TJENESTE, $service);
        $kommune = LTS_Repository::by_slug(LTS_KOMMUNE, $municipality);
        if (!$tjeneste instanceof WP_Post || !$kommune instanceof WP_Post) {
            return null;
        }
        $side = LTS_Repository::side_by_pair($tjeneste->ID, $kommune->ID);
        if (!$side instanceof WP_Post) {
            return null;
        }

        return array(
            'service' => $service,
            'municipality' => $municipality,
            'title' => (string) get_post_meta($side->ID, LTS_META_SEO_TITLE, true),
            'meta_description' => (string) get_post_meta($side->ID, LTS_META_META_DESC, true),
            'description' => LTS_Repository::description($side->ID),
        );
    }

    /**
     * @param mixed $prices
     * @return array<string, string>
     */
    private static function price_map($prices): array
    {
        $clean = LTS_Repository::sanitize_prices(is_array($prices) ? $prices : array());
        $map = array();
        foreach ($clean as $slug => $amount) {
            $map[$slug] = LTS_Repository::price_string($amount);
        }
        ksort($map);

        return $map;
    }

    /**
     * @param array<string, string>|null $notes
     * @return array<string, string>
     */
    private static function notes_for_snapshot($notes, string $type, string $slug): array
    {
        if (is_array($notes)) {
            return self::clean_notes($notes);
        }
        $post = LTS_Repository::by_slug($type, $slug);

        return $post instanceof WP_Post ? LTS_Repository::price_notes($post->ID) : array();
    }

    /**
     * @param string[]|null $codes
     * @return string[]
     */
    private static function postnummer_for_snapshot($codes, string $slug): array
    {
        if (is_array($codes)) {
            return LTS_Repository::sanitize_postnummer($codes);
        }
        $post = LTS_Repository::by_slug(LTS_KOMMUNE, $slug);

        return $post instanceof WP_Post ? LTS_Repository::postnummer($post->ID) : array();
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array{path: string, message: string}> $errors
     * @return string[]|null
     */
    private static function postnummer_block(array $source, string $path, array &$errors): ?array
    {
        if (!array_key_exists('postnummer', $source)) {
            return null;
        }
        $raw = $source['postnummer'];
        if ($raw === null || $raw === array() || $raw === '') {
            return array();
        }
        if (!is_array($raw)) {
            $errors[] = self::err($path, __('Postnummer må være en liste.', 'lokale-tjenestesider'));
            return array();
        }

        $codes = array();
        foreach ($raw as $code) {
            if (!is_string($code) && !is_int($code)) {
                $errors[] = self::err($path, __('Postnummer må være fire sifre.', 'lokale-tjenestesider'));
                continue;
            }
            $digits = preg_replace('/\D/', '', (string) $code);
            if (!is_string($digits) || strlen($digits) !== 4) {
                $errors[] = self::err($path, __('Postnummer må være fire sifre.', 'lokale-tjenestesider'));
                continue;
            }
            if (!in_array($digits, $codes, true)) {
                $codes[] = $digits;
            }
        }
        sort($codes);

        return $codes;
    }

    /**
     * @param mixed $notes
     * @return array<string, string>
     */
    private static function note_map($notes): array
    {
        $clean = self::clean_notes(is_array($notes) ? $notes : array());
        ksort($clean);

        return $clean;
    }

    /**
     * @param mixed $notes
     * @return array<string, string>
     */
    private static function clean_notes($notes): array
    {
        if (!is_array($notes)) {
            return array();
        }
        $clean = array();
        foreach ($notes as $slug => $note) {
            $slug = (string) $slug;
            $note = sanitize_text_field((string) $note);
            if (LTS_Repository::is_slug($slug) && $note !== '') {
                $clean[$slug] = $note;
            }
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array{path: string, message: string}> $errors
     * @return array<string, string>|null
     */
    private static function note_block(array $source, string $path, array &$errors): ?array
    {
        if (!array_key_exists('price_note', $source)) {
            return null;
        }
        $raw = $source['price_note'];
        if ($raw === null || $raw === array()) {
            return array();
        }
        if (!is_array($raw)) {
            $errors[] = self::err($path, __('Prislinje må være et objekt med tjenesteslug og tekst.', 'lokale-tjenestesider'));
            return array();
        }

        $notes = array();
        foreach ($raw as $slug => $note) {
            if (!LTS_Repository::is_slug((string) $slug)) {
                $errors[] = self::err($path . '.' . $slug, __('Ugyldig tjenesteslug.', 'lokale-tjenestesider'));
                continue;
            }
            if (!is_string($note)) {
                $errors[] = self::err($path . '.' . $slug, __('Prislinje må være en tekst.', 'lokale-tjenestesider'));
                continue;
            }
            $clean = sanitize_text_field($note);
            if ($clean !== '') {
                $notes[(string) $slug] = $clean;
            }
        }

        return $notes;
    }

    /**
     * @param array<string, string>|null $notes
     * @param array<string, bool> $known
     * @param array<int, array{path: string, message: string}> $errors
     */
    private static function known_notes($notes, string $path, array $known, array &$errors): void
    {
        if (!is_array($notes)) {
            return;
        }
        foreach (array_keys($notes) as $slug) {
            $in_file = isset($known[$slug]) && $known[$slug] === true;
            if (!$in_file && !LTS_Repository::by_slug(LTS_TJENESTE, (string) $slug)) {
                $errors[] = self::err($path . '.' . $slug, sprintf(
                    /* translators: %s: service slug */
                    __('Ukjent tjeneste «%s».', 'lokale-tjenestesider'),
                    $slug
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array{path: string, message: string}> $errors
     */
    private static function need_string(array $source, string $key, string $path, array &$errors): string
    {
        if (!isset($source[$key]) || !is_string($source[$key]) || trim($source[$key]) === '') {
            $errors[] = self::err($path . '.' . $key, __('Feltet må være en tekst.', 'lokale-tjenestesider'));
            return '';
        }

        return sanitize_text_field($source[$key]);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array{path: string, message: string}> $errors
     */
    private static function optional_string(array $source, string $key, string $path, array &$errors): string
    {
        if (!isset($source[$key]) || $source[$key] === '') {
            return '';
        }
        if (!is_string($source[$key])) {
            $errors[] = self::err($path . '.' . $key, __('Feltet må være en tekst.', 'lokale-tjenestesider'));
            return '';
        }

        return $key === 'content' || $key === 'description' ? (string) $source[$key] : sanitize_text_field($source[$key]);
    }

    /**
     * @param mixed $raw
     * @param array<int, array{path: string, message: string}> $errors
     * @return array<string, mixed>
     */
    private static function contact_block($raw, string $path, array &$errors): array
    {
        if ($raw === null || $raw === array()) {
            return LTS_Repository::empty_contact();
        }
        if (!is_array($raw)) {
            $errors[] = self::err($path, __('Kontakt må være et objekt.', 'lokale-tjenestesider'));
            return LTS_Repository::empty_contact();
        }
        if (isset($raw['email']) && $raw['email'] !== '' && (!is_string($raw['email']) || !is_email($raw['email']))) {
            $errors[] = self::err($path . '.email', __('Ugyldig e-postadresse.', 'lokale-tjenestesider'));
        }
        if (isset($raw['open'])) {
            self::validate_hours($raw['open'], $path . '.open', $errors);
        }

        return LTS_Repository::contact_from_import($raw);
    }

    /**
     * @param mixed $open
     * @param array<int, array{path: string, message: string}> $errors
     */
    private static function validate_hours($open, string $path, array &$errors): void
    {
        if (!is_array($open)) {
            $errors[] = self::err($path, __('Åpningstider må være et objekt.', 'lokale-tjenestesider'));
            return;
        }
        $days = LTS_Repository::weekdays();
        foreach ($open as $day => $value) {
            if (!isset($days[$day])) {
                $errors[] = self::err($path . '.' . $day, __('Ukjent ukedag.', 'lokale-tjenestesider'));
                continue;
            }
            if ($value === false) {
                continue;
            }
            if (!is_array($value) || !isset($value['opens'], $value['closes'])) {
                $errors[] = self::err($path . '.' . $day, __('Dagen må være false eller et objekt med opens og closes.', 'lokale-tjenestesider'));
                continue;
            }
            if (LTS_Repository::sanitize_time((string) $value['opens']) === '' || LTS_Repository::sanitize_time((string) $value['closes']) === '') {
                $errors[] = self::err($path . '.' . $day, __('Klokkeslett må være TT:MM.', 'lokale-tjenestesider'));
            }
        }
    }

    /**
     * @param mixed $raw
     * @param array<int, array{path: string, message: string}> $errors
     * @return array<string, float>
     */
    private static function price_block($raw, string $path, array &$errors): array
    {
        if ($raw === null || $raw === array()) {
            return array();
        }
        if (!is_array($raw)) {
            $errors[] = self::err($path, __('Pris må være et objekt med tjenesteslug og beløp.', 'lokale-tjenestesider'));
            return array();
        }
        foreach ($raw as $slug => $amount) {
            if (!LTS_Repository::is_slug((string) $slug)) {
                $errors[] = self::err($path . '.' . $slug, __('Ugyldig tjenesteslug.', 'lokale-tjenestesider'));
            }
            if (!is_int($amount) && !is_float($amount)) {
                $errors[] = self::err($path . '.' . $slug, __('Pris må være et tall.', 'lokale-tjenestesider'));
            }
        }

        return LTS_Repository::sanitize_prices($raw);
    }

    /**
     * @param array<string, float> $prices
     * @param array<string, bool> $known
     * @param array<int, array{path: string, message: string}> $errors
     */
    private static function known_prices(array $prices, string $path, array $known, array &$errors): void
    {
        foreach (array_keys($prices) as $slug) {
            $in_file = isset($known[$slug]) && $known[$slug] === true;
            if (!$in_file && !LTS_Repository::by_slug(LTS_TJENESTE, (string) $slug)) {
                $errors[] = self::err($path . '.' . $slug, sprintf(
                    /* translators: %s: service slug */
                    __('Ukjent tjeneste «%s».', 'lokale-tjenestesider'),
                    $slug
                ));
            }
        }
    }

    /**
     * @param mixed $raw
     * @param array<int, array{path: string, message: string}> $errors
     * @return array<int, array{question: string, answer: string}>
     */
    private static function faq_block($raw, string $path, array &$errors): array
    {
        if ($raw === null || $raw === array()) {
            return array();
        }
        if (!is_array($raw)) {
            $errors[] = self::err($path, __('FAQ må være en liste.', 'lokale-tjenestesider'));
            return array();
        }
        $rows = array();
        foreach ($raw as $index => $row) {
            if (!is_array($row) || !isset($row['question'], $row['answer']) || !is_string($row['question']) || !is_string($row['answer'])) {
                $errors[] = self::err($path . '.' . $index, __('Hvert spørsmål må ha question og answer som tekst.', 'lokale-tjenestesider'));
                continue;
            }
            if (trim($row['question']) === '' || trim($row['answer']) === '') {
                $errors[] = self::err($path . '.' . $index, __('Spørsmål og svar må begge være fylt ut.', 'lokale-tjenestesider'));
                continue;
            }
            $rows[] = array(
                'question' => $row['question'],
                'answer' => $row['answer'],
            );
        }

        return $rows;
    }

    /**
     * @param mixed $raw
     * @param array<int, array{path: string, message: string}> $errors
     * @return string[]
     */
    private static function string_list($raw, string $path, array &$errors): array
    {
        if ($raw === null || $raw === array()) {
            return array();
        }
        if (!is_array($raw)) {
            $errors[] = self::err($path, __('Feltet må være en liste med tekster.', 'lokale-tjenestesider'));
            return array();
        }
        $items = array();
        foreach ($raw as $item) {
            if (!is_string($item) || trim($item) === '') {
                $errors[] = self::err($path, __('Listeverdier må være tekst.', 'lokale-tjenestesider'));
                continue;
            }
            $items[] = sanitize_text_field($item);
        }

        return $items;
    }

    /**
     * @param array<int, array{path: string, message: string}> $errors
     */
    private static function slug_ok(string $slug, string $path, array &$errors): bool
    {
        if (LTS_Repository::is_slug($slug)) {
            return true;
        }
        $errors[] = self::err($path, __('Slug må være små bokstaver, tall, bindestrek eller understrek. ø blir ikke en URL.', 'lokale-tjenestesider'));

        return false;
    }

    /**
     * @return array{path: string, message: string}
     */
    private static function err(string $path, string $message): array
    {
        return array(
            'path' => $path,
            'message' => $message,
        );
    }

    private static function action_label(string $action): string
    {
        if ($action === 'create') {
            return __('Ny', 'lokale-tjenestesider');
        }
        if ($action === 'update') {
            return __('Oppdateres', 'lokale-tjenestesider');
        }

        return __('Uendret', 'lokale-tjenestesider');
    }

    private static function action_class(string $action): string
    {
        if ($action === 'create') {
            return 'is-new';
        }
        if ($action === 'update') {
            return 'is-update';
        }

        return 'is-same';
    }

    /**
     * @param array<int, mixed> $rows
     */
    private static function render_rows(array $rows): void
    {
        $changes = array();
        $same = array();
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['action'])) {
                continue;
            }
            if ($row['action'] === 'unchanged') {
                $same[] = $row;
            } else {
                $changes[] = $row;
            }
        }

        $ordered = array_merge($changes, $same);
        if ($ordered === array()) {
            return;
        }

        echo '<table class="lts-import-table"><thead><tr>';
        echo '<th>' . esc_html__('Type', 'lokale-tjenestesider') . '</th>';
        echo '<th>' . esc_html__('Slug', 'lokale-tjenestesider') . '</th>';
        echo '<th>' . esc_html__('Tittel', 'lokale-tjenestesider') . '</th>';
        echo '<th>' . esc_html__('Status', 'lokale-tjenestesider') . '</th>';
        echo '</tr></thead><tbody>';
        $shown = 0;
        foreach ($ordered as $row) {
            if ($shown >= 200) {
                break;
            }
            $shown++;
            $action = (string) $row['action'];
            echo '<tr>';
            echo '<td>' . esc_html((string) $row['type']) . '</td>';
            echo '<td><code>' . esc_html((string) $row['slug']) . '</code></td>';
            echo '<td>' . esc_html((string) $row['title']) . '</td>';
            echo '<td><span class="lts-status ' . esc_attr(self::action_class($action)) . '">' . esc_html(self::action_label($action)) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="lts-h">' . esc_html__('Eksisterende innhold matches på slug. UX Builder-layouten beholdes.', 'lokale-tjenestesider') . '</p>';
        if (count($ordered) > 200) {
            echo '<p class="lts-h">' . esc_html(sprintf(
                /* translators: %d: number of rows */
                __('Viser 200 av %d rader.', 'lokale-tjenestesider'),
                count($ordered)
            )) . '</p>';
        }
    }

    private static function cancel_form(string $class, string $label, string $button): void
    {
        echo '<form method="post"' . ($class !== '' ? ' class="' . esc_attr($class) . '"' : '') . '>';
        wp_nonce_field('lts_import_cancel');
        echo '<input type="hidden" name="lts_import_step" value="cancel">';
        echo '<button type="submit" class="' . esc_attr($button) . '">' . esc_html($label) . '</button>';
        echo '</form>';
    }

    private static function ready_label(int $create, int $update): string
    {
        $parts = array();
        if ($create > 0) {
            $parts[] = sprintf(
                /* translators: %d: number of new rows */
                _n('%d ny', '%d nye', $create, 'lokale-tjenestesider'),
                $create
            );
        }
        if ($update > 0) {
            $parts[] = sprintf(
                /* translators: %d: number of updates */
                _n('%d oppdatering', '%d oppdateringer', $update, 'lokale-tjenestesider'),
                $update
            );
        }

        return sprintf(
            /* translators: %s: summary of pending rows */
            _n('%s er klar', '%s er klare', $create + $update, 'lokale-tjenestesider'),
            implode(' og ', $parts)
        );
    }
}
