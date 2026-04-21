<?php
/**
* Plugin Name:  SignEmail SMTP & DNS Diagnostic
* Plugin URI:   https://smtp-dkim.com
* Description:  Send WordPress emails via SMTP. Verify SPF, DKIM and DMARC records. DKIM signing available in the Premium version.
* Version:      2.4.35
* Author:       SMTPDKIM
* Author URI:   https://profiles.wordpress.org/smtpdkim/
* License:      GPL-2.0+
* License URI:  https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain:  signemail-smtp-dns-diagnostic
* Domain Path:  /languages
*/
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'smtpdkimc_VERSION', '2.4.35' );
define( 'smtpdkimc_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'smtpdkimc_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'smtpdkimc_TABLE',           'smtpdkimc_smtp_config' );

define( 'smtpdkimc_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once smtpdkimc_PLUGIN_DIR . 'includes/class-activator.php';

require_once smtpdkimc_PLUGIN_DIR . 'includes/class-dns-checker.php';
require_once smtpdkimc_PLUGIN_DIR . 'includes/class-lang.php';
require_once smtpdkimc_PLUGIN_DIR . 'includes/class-admin-page.php';

register_activation_hook(   __FILE__, [ 'smtpdkimc_Activator', 'activate'   ] );
register_deactivation_hook( __FILE__, [ 'smtpdkimc_Activator', 'deactivate' ] );

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

add_action( 'plugins_loaded', function () {
    new smtpdkimc_Admin_Page();
    new smtpdkimc_DNS_Checker();
} );

