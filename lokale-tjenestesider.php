<?php
/**
 * Plugin Name:       Lokale tjenestesider
 * Plugin URI:        https://kodekassa.no/plugins/lokale-tjenestesider
 * Description:       Lokale landingssider for tjenester og kommuner, med dynamiske URL-er, SEO og JSON-import.
 * Version:           1.0.6
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Benjamin Pedersen
 * Text Domain:       lokale-tjenestesider
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LTS_VERSION', '1.0.6');
define('LTS_FILE', __FILE__);
define('LTS_DIR', plugin_dir_path(__FILE__));
define('LTS_URL', plugin_dir_url(__FILE__));
define('LTS_DOCS_URL', 'https://kodekassa.no/plugins/lokale-tjenestesider');

define('LTS_FYLKE', 'lts_fylke');
define('LTS_KOMMUNE', 'lts_kommune');
define('LTS_TJENESTE', 'lts_tjeneste');
define('LTS_SIDE', 'lts_side');

define('LTS_META_CONTACT', '_lts_contact');
define('LTS_META_PRICES', '_lts_prices');
define('LTS_META_PRICE_NOTES', '_lts_price_notes');
define('LTS_META_BLOCKS', '_lts_blocks');
define('LTS_META_FAQ', '_lts_faq');
define('LTS_META_FYLKE', '_lts_fylke_id');
define('LTS_META_DESCRIPTION', '_lts_description');
define('LTS_META_NEARBY', '_lts_nearby');
define('LTS_META_POSTNUMMER', '_lts_postnummer');
define('LTS_META_BODY', '_lts_body');
define('LTS_META_TJENESTE', '_lts_tjeneste_id');
define('LTS_META_KOMMUNE', '_lts_kommune_id');
define('LTS_META_PAIR', '_lts_pair');
define('LTS_META_SEO_TITLE', '_lts_seo_title');
define('LTS_META_META_DESC', '_lts_meta_description');
define('LTS_META_SERVICES', '_lts_services');
define('LTS_META_PEOPLE', '_lts_people');
define('LTS_META_PUBLIC_URL', '_lts_public_url');
define('LTS_META_BASE', '_lts_base');
define('LTS_META_GF', '_lts_gf_id');
define('LTS_OPTION_SLUGS', 'lts_service_slugs');

require_once LTS_DIR . 'includes/class-lts-plugin.php';
require_once LTS_DIR . 'includes/class-lts-repository.php';
require_once LTS_DIR . 'includes/class-lts-post-types.php';
require_once LTS_DIR . 'includes/class-lts-meta.php';
require_once LTS_DIR . 'includes/class-lts-editor.php';
require_once LTS_DIR . 'includes/class-lts-context.php';
require_once LTS_DIR . 'includes/class-lts-rewrite.php';
require_once LTS_DIR . 'includes/class-lts-templates.php';
require_once LTS_DIR . 'includes/class-lts-seo.php';
require_once LTS_DIR . 'includes/class-lts-elements.php';
require_once LTS_DIR . 'includes/elements/class-lts-element-kommune.php';
require_once LTS_DIR . 'includes/elements/class-lts-element-tjeneste.php';
require_once LTS_DIR . 'includes/class-lts-import.php';

register_activation_hook(LTS_FILE, array('LTS_Plugin', 'activate'));
register_deactivation_hook(LTS_FILE, array('LTS_Plugin', 'deactivate'));

add_action('plugins_loaded', array('LTS_Plugin', 'init'));
