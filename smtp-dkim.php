<?php
/**
* Plugin Name:  SMTP DKIM
* Plugin URI:   https://smtp-dkim.com
* Description:  Send WordPress emails via secure SMTP and sign them with DKIM to prevent spam delivery.
*               Unlike other SMTP plugins, all your configuration data (credentials, private key) stays
*               encrypted in your own WordPress database — nothing is sent to smtp-dkim.com servers.
*               Includes bilingual FR/EN interface, built-in DNS diagnostic (SPF, DKIM, DMARC),
*               and auto-detection of your DKIM selector. DKIM signing requires a licence from smtp-dkim.com.
* Version:      2.3.8
* Author:       SMTPDKIM
* Author URI:   https://profiles.wordpress.org/smtpdkim/
* License:      GPL-2.0+
* License URI:  https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain:  smtp-dkim
* Domain Path:  /languages
*/
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'smtpdkimc_VERSION', '2.3.8' );
define( 'smtpdkimc_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'smtpdkimc_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'smtpdkimc_TABLE',           'smtpdkimc_smtp_config' );
define( 'smtpdkimc_LICENSE_TABLE',   'smtpdkimc_license' );
define( 'smtpdkimc_API_URL',         'https://smtp-dkim.com/wp-json/sdlm/v1/validate' );
define( 'smtpdkimc_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once smtpdkimc_PLUGIN_DIR . 'includes/class-activator.php';
require_once smtpdkimc_PLUGIN_DIR . 'includes/class-license.php';
require_once smtpdkimc_PLUGIN_DIR . 'includes/class-dns-checker.php';
require_once smtpdkimc_PLUGIN_DIR . 'includes/class-lang.php';
require_once smtpdkimc_PLUGIN_DIR . 'includes/class-admin-page.php';

register_activation_hook(   __FILE__, [ 'smtpdkimc_Activator', 'activate'   ] );
register_deactivation_hook( __FILE__, [ 'smtpdkimc_Activator', 'deactivate' ] );
register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'smtpdkimc_license_sync' );
} );

// ──────────────────────────────────────────────────────────────────────────────
//  RESTE DU PLUGIN
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'init', function () {
    if ( is_admin() && isset( $_GET['smtpdkimc_lang'] ) ) {
        // ✅ AJOUT : Nonce verification pour la sécurité
        if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'smtpdkimc_lang_switch' ) ) {
            ( new smtpdkimc_Lang() );
        }
    }
} );

// ──────────────────────────────────────────────────────────────────────────────
//  CRON TOUTES LES 2H — synchronise le statut de la licence avec smtp-dkim.com
//  Garantit que révocation/expiration/désactivation depuis le manager se propage
//  au plus tard dans les 2h, même sans visite de la page admin.
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'smtpdkimc_license_sync', 'smtpdkimc_run_license_sync' );
function smtpdkimc_run_license_sync(): void {
    // Forcer un vrai appel API en vidant le transient
    delete_transient( 'smtpdkimc_license_valid' );
    $lic = new smtpdkimc_License();
    $lic->is_valid(); // déclenche recheck() → met à jour statut local
}

add_action( 'plugins_loaded', function () {
    new smtpdkimc_Admin_Page();
    new smtpdkimc_DNS_Checker();
} );

// Planifier le cron sur 'init' — NE PAS faire sur 'plugins_loaded'.
// wp_schedule_event() appelle wp_get_schedules() qui déclenche le filtre 'cron_schedules'.
// WooCommerce a un callback sur ce filtre qui contient __('Monthly','woocommerce').
// Appelé avant 'init', cela déclenche le chargement JIT du textdomain WooCommerce
// et produit le notice "Translation loading triggered too early" en WP 6.7+.
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'smtpdkimc_license_sync' ) ) {
        wp_schedule_event( time(), 'twicedaily', 'smtpdkimc_license_sync' );
    }
} );