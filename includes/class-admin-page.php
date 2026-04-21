<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Page d'administration — SMTP DKIM
 */
class smtpdkimc_Admin_Page {

    private string        $table;

    public function __construct() {
        global $wpdb;
        $this->table     = $wpdb->prefix . smtpdkimc_TABLE;

        add_action( 'admin_menu',            [ $this, 'register_menu'         ] );
        add_action( 'admin_init',            [ $this, 'maybe_run_migration'   ] );
        add_action( 'admin_init',            [ $this, 'handle_debug_level_save' ] );
        add_action( 'phpmailer_init',        [ $this, 'configure_phpmailer' ], 10 );
        add_action( 'wp_mail_failed',        [ $this, 'log_mail_error'      ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'             ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_deactivation_assets' ] );

        // Modale de feedback à la désactivation (page Extensions)
        add_action( 'admin_footer-plugins.php', [ $this, 'render_deactivation_modal' ] );
        add_action( 'wp_ajax_smtpdkimc_deactivation_feedback', [ $this, 'handle_deactivation_feedback' ] );
        add_action( 'wp_ajax_smtpdkimc_get_debug_log',         [ $this, 'ajax_get_debug_log'          ] );
        add_action( 'wp_ajax_smtpdkimc_clear_debug_log',       [ $this, 'ajax_clear_debug_log'        ] );
        add_action( 'wp_ajax_smtpdkimc_test_external',         [ $this, 'ajax_test_external'          ] );

    }

    public function register_menu(): void {
        add_menu_page( 'SignEmail SMTP & DNS Diagnostic', 'SignEmail SMTP & DNS Diagnostic', 'manage_options',
            'smtp-dkim', [ $this, 'render_page' ], 'dashicons-email-alt2', 75 );
    }

    public function maybe_run_migration(): void {
        if ( get_option( 'smtpdkimc_db_version' ) !== smtpdkimc_VERSION ) {
            smtpdkimc_Activator::activate();
        }
    }

    // ── CHIFFREMENT ──────────────────────────────────────────────────────────

    private function encrypt_dkim_key( string $pem ): string {
        if ( $pem === '' || ( str_contains( $pem, ':' ) && base64_decode( explode( ':', $pem )[0], true ) !== false ) ) {
            return $pem;
        }
        $key    = $this->get_encryption_key();
        $iv     = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $pem, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return $cipher === false ? $pem : base64_encode( $iv ) . ':' . base64_encode( $cipher );
    }

    private function decrypt_dkim_key( string $stored ): string {
        if ( $stored === '' || strpos( $stored, ':' ) === false ) {
            return $stored;
        }
        $parts = explode( ':', $stored, 2 );
        $iv_raw = base64_decode( $parts[0], true );
        if ( $iv_raw === false || strlen( $iv_raw ) !== 16 ) {
            return $stored;
        }
        $plain = openssl_decrypt( base64_decode( $parts[1] ), 'AES-256-CBC', $this->get_encryption_key(), OPENSSL_RAW_DATA, $iv_raw );
        return $plain === false ? $stored : $plain;
    }

    private function encrypt_password( string $plain ): string {
        if ( $plain === '' ) {
            return '';
        }
        $key = $this->get_encryption_key();
        $iv  = openssl_random_pseudo_bytes( 16 );
        $c   = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return $c === false ? '' : base64_encode( $iv ) . ':' . base64_encode( $c );
    }

    private function decrypt_password( string $stored ): string {
        if ( $stored === '' || strpos( $stored, ':' ) === false ) {
            return $stored;
        }
        $parts = explode( ':', $stored, 2 );
        $p = openssl_decrypt( base64_decode( $parts[1] ), 'AES-256-CBC', $this->get_encryption_key(), OPENSSL_RAW_DATA, base64_decode( $parts[0] ) );
        return $p === false ? '' : $p;
    }

    private function get_encryption_key(): string {
        $raw = ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY !== 'put your unique phrase here' )
            ? SECURE_AUTH_KEY : home_url() . 'wpswd-fallback';
        return hash( 'sha256', $raw, true );
    }

       // ── CONFIG DB ────────────────────────────────────────────────────────────

    private function get_config(): object {
    global $wpdb;
    $table_name = $this->table;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row( "SELECT * FROM {$table_name} LIMIT 1" );
    if ( ! $row ) {
        return (object) [
            'id'=>1,'enable_smtp'=>0,'email_from'=>get_option('admin_email',''),
            'email_from_name'=>get_bloginfo('name'),'force_from_email'=>1,
            'smtp_host'=>'','smtp_port'=>'465','smtp_encryption'=>'ssl',
            'smtp_auto_tls'=>1,'smtp_auth'=>1,'smtp_username'=>'',
            'smtp_password'=>'','smtp_debug_level'=>0,
            'dkim_domain'=>'','dkim_selector'=>'default','dkim_private_key'=>'',
        ];
    }
    $row->smtp_password  = $this->decrypt_password( $row->smtp_password  ?? '' );
    $row->dkim_private_key = $this->decrypt_dkim_key( $row->dkim_private_key ?? '' );
    return $row;
}

private function save_config( array $data ): bool {
    global $wpdb;
    $table_name = $this->table;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $exists = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
    if ( isset( $data['smtp_password'] ) && $data['smtp_password'] !== '' ) {
        $data['smtp_password'] = $this->encrypt_password( $data['smtp_password'] );
    } else {
        if ( $exists ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $pwd = $wpdb->get_var( "SELECT smtp_password FROM {$table_name} LIMIT 1" );
            $data['smtp_password'] = $pwd ?? '';
        }
    }
    if ( ! empty( $data['dkim_private_key'] ) ) {
        $data['dkim_private_key'] = $this->encrypt_dkim_key( $data['dkim_private_key'] );
    } elseif ( $exists ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $key = $wpdb->get_var( "SELECT dkim_private_key FROM {$table_name} LIMIT 1" );
        $data['dkim_private_key'] = $key ?? '';
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return $exists
        ? $wpdb->update( $this->table, $data, ['id' => 1] ) !== false  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        : $wpdb->insert( $this->table, array_merge( ['id' => 1], $data ) ) !== false;  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
    // ── NORMALISATION CLE DKIM PEM ───────────────────────────────────────────

    private function normalize_dkim_key( string $raw ): string {
        $raw = trim( $raw );
        if ( empty( $raw ) ) {
            return '';
        }
        preg_match( '/-----BEGIN ([A-Z ]+)-----/', $raw, $hm );
        preg_match( '/-----END ([A-Z ]+)-----/',   $raw, $fm );
        $header_label = $hm[1] ?? 'RSA PRIVATE KEY';
        $footer_label = $fm[1] ?? 'RSA PRIVATE KEY';
        $body = preg_replace( '/-----[^-]+-----/', '', $raw );
        $body = preg_replace( '/\s+/', '', $body );
        $body = preg_replace( '/[^A-Za-z0-9+\/=]/', '', $body );
        if ( empty( $body ) || base64_decode( $body, true ) === false ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'WPSWD: Cle DKIM base64 invalide.' );
            }
            return '';
        }
        $pem = "-----BEGIN {$header_label}-----\n" . chunk_split( $body, 64, "\n" ) . "-----END {$footer_label}-----\n";
        $resource = openssl_pkey_get_private( $pem );
        if ( $resource === false ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'WPSWD: Cle DKIM rejetee par OpenSSL: ' . openssl_error_string() );
            }
            return '';
        }
        return $pem;
    }

    // ── PHPMAILER ────────────────────────────────────────────────────────────

    public function configure_phpmailer( $phpmailer ): void {
        $cfg = $this->get_config();
        if ( empty( $cfg->enable_smtp ) ) {
            return;
        }
        if ( empty( $cfg->smtp_host ) || empty( $cfg->smtp_username ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'WPSWD: config SMTP incomplete.' );
            }
            return;
        }
        try {
            $phpmailer->isSMTP();
            $phpmailer->Host       = trim( $cfg->smtp_host );
            $phpmailer->Port       = (int) $cfg->smtp_port;
            $phpmailer->SMTPSecure = match( $cfg->smtp_encryption ) { 'ssl' => 'ssl', 'tls' => 'tls', default => '' };
            $phpmailer->SMTPAutoTLS = (bool) $cfg->smtp_auto_tls;
            $phpmailer->SMTPAuth   = (bool) $cfg->smtp_auth;
            if ( $cfg->smtp_auth ) {
                $phpmailer->Username = trim( $cfg->smtp_username );
                $phpmailer->Password = $cfg->smtp_password;
            }
            if ( $cfg->force_from_email && ! empty( $cfg->email_from ) ) {
                $phpmailer->setFrom( $cfg->email_from, $cfg->email_from_name ?: get_bloginfo('name'), false );
                $phpmailer->Sender = $cfg->email_from;
            }
            $phpmailer->CharSet = 'UTF-8';
            $phpmailer->Encoding = 'quoted-printable';
            $phpmailer->XMailer = ' ';

            $phpmailer->SMTPOptions = ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
            $phpmailer->Timeout = 300;
            $phpmailer->SMTPDebug = (int) $cfg->smtp_debug_level;
            if ( $phpmailer->SMTPDebug > 0 ) {
                $phpmailer->Debugoutput = static function( $s, $l ) {
                    $log_file = smtpdkimc_PLUGIN_DIR . 'smtp-debug.log';
                    // Limiter à 500 KB — tronquer si dépassé
                    if ( file_exists($log_file) && filesize($log_file) > 512000 ) {
                        $lines = file($log_file);
                        file_put_contents( $log_file, implode('', array_slice($lines, -200)) );
                    }
                    $line = '[' . gmdate('Y-m-d H:i:s') . '] [L' . $l . '] ' . trim($s) . "\n";
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                    file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
                };
            }
        } catch ( \Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'WPSWD PHPMailer Error: ' . $e->getMessage() );
            }
        }
    }

    public function log_mail_error( \WP_Error $e ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'WPSWD mail failed: ' . $e->get_error_message() );
        }
    }

    public function ajax_get_debug_log(): void {
        check_ajax_referer( 'smtpdkimc_debug_log', 'nonce' );
        if ( ! current_user_can('manage_options') ) wp_die('', 403);
        $log_file = smtpdkimc_PLUGIN_DIR . 'smtp-debug.log';
        if ( ! file_exists($log_file) ) {
            wp_send_json_success( [ 'log' => '', 'size' => '0 KB', 'exists' => false ] );
        }
        $size_kb = round( filesize($log_file) / 1024, 1 );
        // Lire les 300 dernières lignes pour ne pas surcharger le navigateur
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $last  = array_slice($lines, -300);
        wp_send_json_success( [
            'log'    => implode("\n", $last),
            'size'   => $size_kb . ' KB',
            'exists' => true,
            'count'  => count($lines),
        ] );
    }

    public function ajax_clear_debug_log(): void {
        check_ajax_referer( 'smtpdkimc_debug_log', 'nonce' );
        if ( ! current_user_can('manage_options') ) wp_die('', 403);
        $log_file = smtpdkimc_PLUGIN_DIR . 'smtp-debug.log';
        if ( file_exists($log_file) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $log_file, '' );
        }
        wp_send_json_success( [ 'message' => 'Log vidé.' ] );
    }

    public function ajax_test_external(): void {
        check_ajax_referer( 'smtpdkimc_test_external', 'nonce' );
        if ( ! current_user_can('manage_options') ) wp_die('', 403);
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        if ( ! is_email($email) ) { wp_send_json_error( 'Email invalide.' ); }
        $result = $this->send_external_test_email( $email );
        if ( $result['success'] ) {
            wp_send_json_success( $result['message'] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    // ── TEST EMAIL ───────────────────────────────────────────────────────────

    private function send_test_email(): array {
        $cfg    = $this->get_config();
        $lang   = new smtpdkimc_Lang();
        $is_en  = $lang->get() === 'en';

        $errors = [];
        if ( empty($cfg->enable_smtp) )     $errors[] = $is_en ? 'SMTP disabled.' : 'SMTP désactivé.';
        if ( empty($cfg->smtp_host) )        $errors[] = $is_en ? 'SMTP Host missing.' : 'SMTP Host manquant.';
        if ( $cfg->smtp_auth && empty($cfg->smtp_username) ) $errors[] = $is_en ? 'Username missing.' : 'Identifiant manquant.';
        if ( empty($cfg->email_from) )       $errors[] = $is_en ? 'From Email missing.' : 'Email From manquant.';
        if ( ! empty($errors) ) return [ 'success' => false, 'message' => implode('<br>', $errors) ];

        $to          = get_option('admin_email') ?: $cfg->email_from;

        $site_name  = get_bloginfo('name');
        $site_domain = wp_parse_url( home_url(), PHP_URL_HOST );

        $ok_icon  = '&#x2705;';
        $warn_icon= '&#x26A0;&#xFE0F;';
        $bad_icon = '&#x274C;';

        $subject = ( $is_en ? 'Test SignEmail SMTP & DNS Diagnostic — ' : 'Test SignEmail SMTP & DNS Diagnostic — ' ) . $site_name;

        $row = static fn(string $label, string $val) =>
            '<tr><td style="padding:7px 12px;background:#f7f7f7;font-weight:700;width:42%;color:#444;border-bottom:1px solid #eee">' . esc_html($label) . '</td>'
            . '<td style="padding:7px 12px;border-bottom:1px solid #eee">' . $val . '</td></tr>';

        $section = static fn(string $title) =>
            '<tr><td colspan="2" style="padding:10px 12px 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#0073aa;background:#e8f4fd">'
            . esc_html($title) . '</td></tr>';

        $msg  = '<html><body style="font-family:Arial,sans-serif;color:#333;margin:0;padding:0;background:#f0f0f0">';
        $msg .= '<div style="max-width:640px;margin:24px auto;background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">';
        $msg .= '<div style="background:linear-gradient(135deg,#0073aa,#005580);padding:24px 28px;color:#fff">';
        $msg .= '<div style="font-size:20px;font-weight:700;margin-bottom:4px">SignEmail SMTP & DNS Diagnostic</div>';
        $msg .= '<div style="opacity:.85;font-size:14px">';
        $msg .= $is_en ? 'Test email from <strong>' . esc_html($site_name) . '</strong>' : 'Email de test depuis <strong>' . esc_html($site_name) . '</strong>';
        $msg .= '</div></div>';
        $msg .= '<div style="padding:24px 28px">';
        $msg .= '<table style="border-collapse:collapse;width:100%;font-size:13px;border:1px solid #e0e0e0;border-radius:4px;overflow:hidden">';

        $msg .= $section( $is_en ? 'SMTP Configuration' : 'Configuration SMTP' );
        $msg .= $row( $is_en ? 'SMTP Host' : 'Hôte SMTP',   esc_html($cfg->smtp_host) . ':' . esc_html($cfg->smtp_port) );
        $msg .= $row( $is_en ? 'Encryption' : 'Chiffrement', esc_html( strtoupper( $cfg->smtp_encryption ) ) );
        $msg .= $row( $is_en ? 'From Email' : 'Expéditeur',  esc_html($cfg->email_from) );

        $msg .= '</table>';

        $msg .= '<p style="font-size:11px;color:#aaa;margin-top:20px;text-align:right">';
        $msg .= ( $is_en ? 'Sent on ' : 'Envoyé le ' ) . esc_html(gmdate('d/m/Y H:i:s')) . ' — <a href="https://smtp-dkim.com" style="color:#aaa">smtp-dkim.com</a>';
        $msg .= '</p>';
        $msg .= '</div></div></body></html>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ($cfg->email_from_name ?: $site_name) . ' <' . $cfg->email_from . '>',
        ];

        $result = wp_mail( $to, $subject, $msg, $headers );
        if ( $result ) {
            $ok_msg = $is_en ? 'Email sent to <strong>' . esc_html($to) . '</strong>.' : 'Email envoyé à <strong>' . esc_html($to) . '</strong>.';
            return [ 'success' => true, 'message' => $ok_msg ];
        }
        global $phpmailer;
        $detail = ( isset($phpmailer) && is_object($phpmailer) && ! empty($phpmailer->ErrorInfo) )
            ? $phpmailer->ErrorInfo
            : ( $is_en ? 'Unknown error.' : 'Erreur inconnue.' );
        $fail_msg = $is_en ? 'Failed: ' . esc_html($detail) : 'Échec : ' . esc_html($detail);
        return [ 'success' => false, 'message' => $fail_msg ];
    }

    private function send_external_test_email( string $to ): array {
        global $phpmailer;
        $lang      = new smtpdkimc_Lang();
        $is_en     = $lang->get() === 'en';
        $cfg       = $this->get_config();
        $site_name = get_bloginfo('name') ?: get_home_url();
        $site_url  = get_home_url();
        $from      = $cfg->email_from    ?: get_option('admin_email');
        $from_name = $cfg->email_from_name ?: $site_name;

        $subject = $is_en
            ? '[SMTP-DKIM Test] Delivery test from ' . $site_name
            : '[Test SMTP-DKIM] Test d\'envoi depuis ' . $site_name;

        $body  = '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;color:#333">';
        $body .= '<div style="background:#1a56ff;padding:20px 24px;border-radius:6px 6px 0 0">';
        $body .= '<h2 style="color:#fff;margin:0;font-size:18px">' . ( $is_en ? '✅ Email delivery test' : '✅ Test de délivrabilité email' ) . '</h2>';
        $body .= '</div>';
        $body .= '<div style="background:#f9f9f9;border:1px solid #e0e0e0;border-top:0;padding:24px;border-radius:0 0 6px 6px">';

        $body .= '<p>' . ( $is_en
            ? 'This is an automated delivery test sent from <strong>' . esc_html($site_url) . '</strong>.'
            : 'Ceci est un test de délivrabilité automatique envoyé depuis <strong>' . esc_html($site_url) . '</strong>.' ) . '</p>';

        $body .= '<p>' . ( $is_en
            ? 'If you received this email in your <strong>inbox</strong>, your SMTP and DKIM configuration is working correctly.'
            : 'Si vous recevez cet email dans votre <strong>boîte de réception</strong> (et non dans les spams), votre configuration SMTP et DKIM fonctionne correctement.' ) . '</p>';

        $body .= '<div style="background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:14px 18px;margin:16px 0;font-size:13px">';
        $body .= '<strong>' . ( $is_en ? 'Technical details' : 'Détails techniques' ) . '</strong><br><br>';
        $body .= ( $is_en ? 'SMTP Host' : 'Hôte SMTP' ) . ' : <code>' . esc_html($cfg->smtp_host ?: '—') . ':' . esc_html($cfg->smtp_port ?: '—') . '</code><br>';
        $body .= ( $is_en ? 'From' : 'Expéditeur' ) . ' : <code>' . esc_html($from) . '</code><br>';

        $body .= '</div>';

        $body .= '<p style="font-size:12px;color:#999">'
               . ( $is_en
                    ? '🔒 Security: this email does not contain any license details or sensitive information.'
                    : '🔒 Sécurité : cet email ne contient aucun détail de licence ni information sensible.' )
               . '</p>';

        $body .= '<div style="background:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;padding:14px 18px;margin:16px 0;font-size:13px;color:#6d5102">';
        $body .= '<strong style="display:block;margin-bottom:8px">' . ( $is_en ? '⚠️ Email landing in spam or marked «Not Verified»?' : '⚠️ Email dans les spams ou marqué «Non Vérifié» ?' ) . '</strong>';
        $body .= $is_en
            ? 'If this email landed in your <strong>spam folder</strong> or is marked as <strong>Not Verified</strong>, it means you need the <strong>Premium version</strong> to add a private DKIM digital signature to your outgoing emails. This signature tells Gmail, Outlook and Yahoo that your emails are authentic.<br><br>The Premium version is available on our website: <a href="https://smtp-dkim.com" style="color:#1a56ff;font-weight:700">smtp-dkim.com</a>'
            : 'Si cet email tombe dans les <strong>spams</strong> ou est marqué comme <strong>Non Vérifié</strong>, cela signifie que vous avez besoin de la <strong>version Premium</strong> pour ajouter la signature numérique DKIM privée sur l\'envoi de vos emails. Cette signature indique à Gmail, Outlook et Yahoo que vos emails sont authentiques.<br><br>La version Premium est disponible sur notre site web : <a href="https://smtp-dkim.com" style="color:#1a56ff;font-weight:700">smtp-dkim.com</a>';
        $body .= '</div>';

        $body .= '</div></div>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from . '>',
        ];

        $sent = wp_mail( $to, $subject, $body, $headers );

        if ( $sent ) {
            return [ 'success' => true, 'message' => $is_en
                ? '✅ Test email sent to <strong>' . esc_html($to) . '</strong>. Check your inbox (and spam folder).'
                : '✅ Email de test envoyé à <strong>' . esc_html($to) . '</strong>. Vérifiez votre boîte de réception (et le dossier spam).' ];
        }

        $error = isset($phpmailer) && is_object($phpmailer) ? $phpmailer->ErrorInfo : '';
        return [ 'success' => false, 'message' => $is_en
            ? '❌ Failed to send: ' . esc_html($error ?: 'Unknown error.')
            : '❌ Échec de l\'envoi : ' . esc_html($error ?: 'Erreur inconnue.') ];
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'toplevel_page_smtp-dkim' ) {
            return;
        }

        wp_enqueue_style( 'smtpdkimc-admin', smtpdkimc_PLUGIN_URL . 'assets/css/smtpdkimc-admin.css', [], smtpdkimc_VERSION );

        $lang             = new smtpdkimc_Lang();
        $is_en            = $lang->get() === 'en';
        $just_activated   = (bool) get_transient( 'smtpdkimc_just_activated' );

        wp_enqueue_script( 'smtpdkimc-admin', smtpdkimc_PLUGIN_URL . 'assets/js/smtpdkimc-admin.js', [ 'jquery' ], smtpdkimc_VERSION . '.2', true );
        wp_localize_script( 'smtpdkimc-admin', 'smtpdkimcAdmin', [
            'justActivated'      => $just_activated,
            'dkimSigningStatus'  => ( class_exists('smtpdkimc_License') && ! empty( $this->get_config()->dkim_private_key ) ) ? 'active' : ( class_exists('smtpdkimc_License') ? 'inactive' : 'premium_only' ),

            'nonceDns'          => wp_create_nonce( 'smtpdkimc_dns_check' ),
            'nonceDebugLog'     => wp_create_nonce( 'smtpdkimc_debug_log' ),
            'nonceTestExternal' => wp_create_nonce( 'smtpdkimc_test_external' ),

            'i18n'            => [
                'dns_fix_optional'    => $lang->t( 'dns_fix_optional' ),
                'dns_fix_how'         => $lang->t( 'dns_fix_how' ),
                'dns_fix_type'        => $lang->t( 'dns_fix_type' ),
                'dns_fix_name'        => $lang->t( 'dns_fix_name' ),
                'dns_fix_value'       => $lang->t( 'dns_fix_value' ),
                'dns_results_for'     => $lang->t( 'dns_results_for' ),
                'dkim_pub_key_lbl'    => $lang->t( 'dkim_pub_key_lbl' ),
                'dns_all_ok'          => $lang->t( 'dns_all_ok' ),
                'spf_softfail_lbl'    => $lang->t( 'spf_softfail_lbl' ),
                'spf_strict_lbl'      => $lang->t( 'spf_strict_lbl' ),
                'dkim_scroll_note'    => $lang->t( 'dkim_scroll_note' ),
                'dkim_key_size_lbl'   => $lang->t( 'dkim_key_size_lbl' ),
                'dns_rsa_bits'        => $lang->t( 'dns_rsa_bits' ),
                'dkim_optimal_lbl'    => $lang->t( 'dkim_optimal_lbl' ),
                'dkim_min_bits_lbl'   => $lang->t( 'dkim_min_bits_lbl' ),
                'dns_alt_sel'         => $lang->t( 'dns_alt_sel' ),
                'dns_pub_key_note'    => $lang->t( 'dns_pub_key_note' ),
                'dmarc_policy_lbl'    => $lang->t( 'dmarc_policy_lbl' ),
                'dmarc_no_rua_warn'   => $lang->t( 'dmarc_no_rua_warn' ),

                'dkim_sign_lbl'       => $is_en ? 'DKIM Signing Private Key' : 'Signature DKIM Clé Privée',
                'dkim_sign_active'    => $is_en ? 'Active'                : 'Active',
                'dkim_sign_inactive'  => $is_en ? 'Not configured'        : 'Non configurée',
                'dkim_sign_premium'   => $is_en ? 'Available in the <strong>Premium version</strong> &mdash; <a href="https://smtp-dkim.com" target="_blank" style="font-weight:700">smtp-dkim.com</a>'
                                                : 'Disponible dans la <strong>version Premium</strong> &mdash; <a href="https://smtp-dkim.com" target="_blank" style="font-weight:700">smtp-dkim.com</a>',
                'dns_loading'         => $is_en ? 'Querying DNS via Cloudflare DoH…' : 'Interrogation DNS via Cloudflare DoH…',
                'dns_error'           => $is_en ? 'Error:' : 'Erreur :',
                'dns_network_error'   => $is_en ? 'Network error.' : 'Erreur réseau.',
                'confirm_clear'       => $is_en ? 'Clear the debug log?' : 'Vider le fichier de log ?',
                'log_lines'           => $is_en ? 'lines' : 'lignes',
                'log_no_file'         => $is_en ? 'No log file yet.' : 'Fichier vide ou absent.',
                'log_refresh_btn'     => $is_en ? 'Refresh log' : 'Rafraîchir le log',
            ],
        ] );
    }

    public function enqueue_deactivation_assets( string $hook ): void {
        if ( $hook !== 'plugins.php' || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $lang    = new smtpdkimc_Lang();
        $is_en   = $lang->get() === 'en';
        $err_txt = $is_en ? 'Please select a reason before sending.' : 'Veuillez s\xc3\xa9lectionner une raison avant d\'envoyer.';

        wp_enqueue_style( 'smtpdkimc-deact', smtpdkimc_PLUGIN_URL . 'assets/css/smtpdkimc-deact.css', [], smtpdkimc_VERSION );
        wp_enqueue_script( 'smtpdkimc-deact', smtpdkimc_PLUGIN_URL . 'assets/js/smtpdkimc-deact.js', [ 'jquery' ], smtpdkimc_VERSION, true );
        wp_localize_script( 'smtpdkimc-deact', 'smtpdkimcDeact', [
            'nonce'   => wp_create_nonce( 'smtpdkimc_deactivation_feedback' ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'errTxt'  => $err_txt,
            'basename'=> defined( 'smtpdkimc_PLUGIN_BASENAME' ) ? smtpdkimc_PLUGIN_BASENAME : 'smtp-dkim/smtp-dkim.php',
        ] );
    }

    // ── MODALE DE FEEDBACK À LA DÉSACTIVATION ────────────────────────────────

    /**
     * Injecte la modale + JS dans le footer de la page Extensions.
     * Conforme au standard WordPress.org : Skip toujours disponible.
     */
    public function render_deactivation_modal(): void {
        if ( ! current_user_can('manage_options') ) {
            return;
        }

        $lang  = new smtpdkimc_Lang();
        $is_en = $lang->get() === 'en';

        // Clé de nonce — utilisée côté JS pour l'appel AJAX
        $nonce    = wp_create_nonce('smtpdkimc_deactivation_feedback');
        $basename = defined('smtpdkimc_PLUGIN_BASENAME') ? smtpdkimc_PLUGIN_BASENAME : 'smtp-dkim/smtp-dkim.php';

        $reasons = $is_en ? [
            'not_working'   => 'Plugin not working as expected',
            'better_plugin' => 'Found a better plugin',
            'temporary'     => 'Temporary deactivation (troubleshooting)',
            'no_need'       => 'No longer need SMTP / DKIM',
            'too_complex'   => 'Too complex to configure',
            'missing_feat'  => 'Missing a feature I need',
            'other'         => 'Other reason',
        ] : [
            'not_working'   => 'Le plugin ne fonctionne pas correctement',
            'better_plugin' => 'J\'ai trouvé un meilleur plugin',
            'temporary'     => 'Désactivation temporaire (dépannage)',
            'no_need'       => 'Je n\'ai plus besoin du SMTP / DKIM',
            'too_complex'   => 'Trop compliqué à configurer',
            'missing_feat'  => 'Il manque une fonctionnalité',
            'other'         => 'Autre raison',
        ];

        $radio_html = '';
        foreach ( $reasons as $value => $label ) {
            $radio_html .= '<label style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:6px;cursor:pointer;transition:background .15s" '
                . 'onmouseover="this.style.background=\'#f0f7ff\'" onmouseout="this.style.background=\'transparent\'">'
                . '<input type="radio" name="smtpdkimc_reason" value="' . esc_attr($value) . '" style="width:16px;height:16px;accent-color:#0073aa;flex-shrink:0">'
                . '<span style="font-size:14px;color:#333">' . esc_html($label) . '</span>'
                . '</label>';
        }

        $title       = $is_en ? 'Before you deactivate…'          : 'Avant de désactiver…';
        $subtitle    = $is_en ? 'Could you tell us why? Your feedback helps us improve SMTP DKIM.'
                               : 'Pouvez-vous nous dire pourquoi ? Vos commentaires nous aident à améliorer SMTP DKIM.';
        $comment_lbl = $is_en ? 'Additional comments (optional):'  : 'Commentaires supplémentaires (optionnel) :';
        $comment_ph  = $is_en ? 'Describe the issue or your suggestion…' : 'Décrivez le problème ou votre suggestion…';
        $btn_submit  = $is_en ? '✉ Send & Deactivate'              : '✉ Envoyer et désactiver';
        $btn_skip    = $is_en ? 'Skip & Deactivate'                : 'Ignorer et désactiver';
        $btn_cancel  = $is_en ? 'Cancel'                           : 'Annuler';
        $sending_txt = $is_en ? 'Sending feedback…'                : 'Envoi du feedback…';
        $err_txt     = $is_en ? 'Please select a reason before sending.' : 'Veuillez sélectionner une raison avant d\'envoyer.';
        ?>
        <div id="smtpdkimc-deact-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;justify-content:center;align-items:center">
            <div style="background:#fff;border-radius:10px;max-width:520px;width:94%;padding:32px 36px;box-shadow:0 8px 40px rgba(0,0,0,.22);position:relative;max-height:90vh;overflow-y:auto">

                <!-- Header -->
                <div style="margin-bottom:20px">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                        <span style="font-size:26px">💬</span>
                        <h3 style="margin:0;font-size:18px;color:#1a1a1a"><?php echo esc_html($title); ?></h3>
                    </div>
                    <p style="margin:0;color:#666;font-size:13px;line-height:1.5"><?php echo esc_html($subtitle); ?></p>
                </div>

                <!-- Raisons -->
                <div id="smtpdkimc-reasons" style="border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;margin-bottom:16px">
                    <?php echo $radio_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>

                <!-- Commentaire -->
                <div style="margin-bottom:20px">
                    <label style="display:block;font-size:13px;color:#555;margin-bottom:6px;font-weight:600">
                        <?php echo esc_html($comment_lbl); ?>
                    </label>
                    <textarea id="smtpdkimc-comment" rows="3" style="width:100%;box-sizing:border-box;border:1px solid #ddd;border-radius:6px;padding:10px 12px;font-size:13px;resize:vertical;font-family:inherit"
                              placeholder="<?php echo esc_attr($comment_ph); ?>"></textarea>
                </div>

                <!-- Boutons -->
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <button id="smtpdkimc-btn-submit" class="button button-primary" style="flex:1;min-width:160px;padding:8px 16px;font-size:14px">
                        <?php echo esc_html($btn_submit); ?>
                    </button>
                    <button id="smtpdkimc-btn-skip" class="button button-secondary" style="padding:8px 16px;font-size:14px">
                        <?php echo esc_html($btn_skip); ?>
                    </button>
                    <button id="smtpdkimc-btn-cancel" class="button button-link" style="padding:8px 12px;font-size:13px;color:#777">
                        <?php echo esc_html($btn_cancel); ?>
                    </button>
                </div>

                <!-- État envoi -->
                <div id="smtpdkimc-sending" style="display:none;text-align:center;padding:12px 0;color:#0073aa;font-size:13px">
                    <span style="display:inline-block;width:16px;height:16px;border:2px solid #ddd;border-top-color:#0073aa;border-radius:50%;animation:smtpdkimc-spin .7s linear infinite;vertical-align:middle;margin-right:8px"></span>
                    <?php echo esc_html($sending_txt); ?>
                </div>

            </div>
        </div>

        <?php
    }

    /**
     * Reçoit le feedback de désactivation et envoie un email à support@smtp-dkim.com.
     */
    public function handle_deactivation_feedback(): void {
        if ( ! check_ajax_referer('smtpdkimc_deactivation_feedback', 'nonce', false) ) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $reason_key = sanitize_text_field( wp_unslash( $_POST['reason']  ?? '' ) );
        $comment    = sanitize_textarea_field( wp_unslash( $_POST['comment'] ?? '' ) );

        $lang  = new smtpdkimc_Lang();
        $is_en = $lang->get() === 'en';

        $reasons_map = $is_en ? [
            'not_working'   => 'Plugin not working as expected',
            'better_plugin' => 'Found a better plugin',
            'temporary'     => 'Temporary deactivation (troubleshooting)',
            'no_need'       => 'No longer need SMTP / DKIM',
            'too_complex'   => 'Too complex to configure',
            'missing_feat'  => 'Missing a feature I need',
            'other'         => 'Other reason',
        ] : [
            'not_working'   => 'Le plugin ne fonctionne pas correctement',
            'better_plugin' => 'J\'ai trouvé un meilleur plugin',
            'temporary'     => 'Désactivation temporaire (dépannage)',
            'no_need'       => 'Je n\'ai plus besoin du SMTP / DKIM',
            'too_complex'   => 'Trop compliqué à configurer',
            'missing_feat'  => 'Il manque une fonctionnalité',
            'other'         => 'Autre raison',
        ];

        $reason_label = $reasons_map[$reason_key] ?? $reason_key;
        $site_url     = home_url();
        $site_name    = get_bloginfo('name');
        $admin_email  = get_option('admin_email');

        // Infos licence si disponibles
        $lic_info = $this->license->get_info();
        $lic_key    = $lic_info->license_key    ?? '';
        $lic_domain = $lic_info->domain         ?? '';
        $lic_email  = $lic_info->customer_email ?? '';
        $lic_name   = $lic_info->customer_name  ?? '';
        $lic_status = $lic_info->status         ?? '';

        // Envoyer vers le manager smtp-dkim.com via HTTP (indépendant de la config SMTP du client)
        $feedback_url = str_replace( '/wp-json/sdlm/v1/validate', '/wp-json/sdlm/v1/feedback', smtpdkimc_API_URL );

        wp_remote_post( $feedback_url, [
            'timeout'  => 8,
            'blocking' => false, // Non bloquant — on n'attend pas la réponse
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( [
                'reason'         => $reason_key,
                'reason_label'   => $reason_label,
                'comment'        => $comment,
                'site_name'      => $site_name,
                'site_url'       => $site_url,
                'admin_email'    => $admin_email,
                'wp_version'     => get_bloginfo('version'),
                'plugin_version' => defined('smtpdkimc_VERSION') ? smtpdkimc_VERSION : '?',
                'license_key'    => $lic_key,
                'license_domain' => $lic_domain,
                'license_email'  => $lic_email,
                'license_name'   => $lic_name,
                'license_status' => $lic_status,
            ] ),
        ] );

        wp_send_json_success();
    }

    public function handle_debug_level_save(): void {
        if ( ! isset( $_POST['smtpdkimc_save'], $_POST['smtpdkimc_debug_anchor'] ) ) return;
        if ( ! isset( $_POST['smtpdkimc_nonce'] ) ) return;
        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['smtpdkimc_nonce'] ) ), 'smtpdkimc_save_config' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $cfg = (array) $this->get_config();
        $cfg['smtp_debug_level'] = isset( $_POST['smtp_debug_level'] ) ? (int) $_POST['smtp_debug_level'] : 0;
        $this->save_config( $cfg );

        wp_safe_redirect( admin_url( 'admin.php?page=smtp-dkim' ) . '#wpswd-debug-card' );
        exit;
    }

    public function render_page(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Permission refusee.', 'signemail-smtp-dns-diagnostic') );
        }

        $lang = new smtpdkimc_Lang();

        $notice = null;
        $notice_type = 'success';

        $request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

        if ( $request_method === 'POST' ) {
            if ( isset( $_POST['smtpdkimc_save'] ) && check_admin_referer( 'smtpdkimc_save_config', 'smtpdkimc_nonce' ) ) {
                $data = [
                    'enable_smtp'     => isset( $_POST['enable_smtp'] ) ? 1 : 0,
                    'email_from'      => isset( $_POST['email_from'] ) ? sanitize_email( wp_unslash( $_POST['email_from'] ) ) : '',
                    'email_from_name' => isset( $_POST['email_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['email_from_name'] ) ) : '',
                    'force_from_email'=> isset( $_POST['force_from_email'] ) ? 1 : 0,
                    'smtp_host'       => isset( $_POST['smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_host'] ) ) : '',
                    'smtp_port'       => isset( $_POST['smtp_port'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_port'] ) ) : '465',
                    'smtp_encryption' => isset( $_POST['smtp_encryption'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_encryption'] ) ) : 'ssl',
                    'smtp_auto_tls'   => isset( $_POST['smtp_auto_tls'] ) ? 1 : 0,
                    'smtp_auth'       => isset( $_POST['smtp_auth'] ) ? 1 : 0,
                    'smtp_username'   => isset( $_POST['smtp_username'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_username'] ) ) : '',
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    'smtp_password'   => isset( $_POST['smtp_password'] ) ? wp_unslash( $_POST['smtp_password'] ) : '',
                    'smtp_debug_level'=> isset( $_POST['smtp_debug_level'] ) ? (int) $_POST['smtp_debug_level'] : (int) ( $this->get_config()->smtp_debug_level ?? 0 ),

                ];

                $saved       = $this->save_config( $data );
                $notice      = $saved ? $lang->t('save_ok') : $lang->t('save_err');
                $notice_type = $saved ? 'success' : 'error';
            }

            if ( isset( $_POST['smtpdkimc_test'] ) && check_admin_referer( 'smtpdkimc_test_email', 'smtpdkimc_test_nonce' ) ) {
                $test = $this->send_test_email();
                $notice = $test['message'];
                $notice_type = $test['success'] ? 'success' : 'error';
            }
            if ( isset( $_POST['smtpdkimc_test_external'] ) && check_admin_referer( 'smtpdkimc_test_external', 'smtpdkimc_test_ext_nonce' ) ) {
                $ext_to = isset( $_POST['smtpdkimc_test_external_email'] ) ? sanitize_email( wp_unslash( $_POST['smtpdkimc_test_external_email'] ) ) : '';
                if ( $ext_to ) {
                    $test = $this->send_external_test_email( $ext_to );
                    $notice = $test['message'];
                    $notice_type = $test['success'] ? 'success' : 'error';
                } else {
                    $notice = $lang->t('test_external_label') . ' invalide.';
                    $notice_type = 'error';
                }
            }
        }

        $cfg         = $this->get_config();

        $site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
        // Domaine et sélecteur disponibles en lite aussi (pour l'outil DNS)
        $dns_domain  = ! empty( $cfg->dkim_domain ) ? $cfg->dkim_domain : $site_domain;
        $dkim_sel    = ! empty( $cfg->dkim_selector ) ? $cfg->dkim_selector : 'default';

        $chk = static fn($v) => $v ? 'checked' : '';
        $sel = static fn($a,$b) => $a === $b ? 'selected' : '';
        $val = static fn($v) => esc_attr($v ?? '');

        global $wpdb;
        $table_name = $this->table;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $pwd_encrypted = $wpdb->get_var( "SELECT smtp_password FROM {$table_name} LIMIT 1" ) ?? '';
        $pwd_is_set    = $pwd_encrypted !== '';
        $pwd_chiffre   = strpos($pwd_encrypted, ':') !== false;

        $dns_badge = function( string $status ) use ($lang): string {
            if ( $status === 'ok' )      return '<span style="color:#2e7d32;font-weight:700">&#x2713; OK</span>';
            if ( $status === 'warning' ) return '<span style="color:#e65100;font-weight:700">&#x26A0; ' . esc_html($lang->t('dns_to_fix')) . '</span>';
            return '<span style="color:#c62828;font-weight:700">&#x2717; ' . esc_html($lang->t('dns_missing')) . '</span>';
        };

        ?>
        <div class="wrap" id="wpswd-wrap">
        <h1 style="display:flex;align-items:center;gap:10px">
            <span class="dashicons dashicons-email-alt2" style="font-size:30px;width:30px;height:30px;color:#0073aa"></span>
            SignEmail SMTP & DNS Diagnostic
            <span style="font-size:13px;color:#888;font-weight:normal;margin-left:4px">v<?php echo esc_html( smtpdkimc_VERSION ); ?> &mdash; <a href="https://smtp-dkim.com" target="_blank">smtp-dkim.com</a></span>
        </h1>

        <?php $lang->render_switcher(); ?>

        <?php if ( $notice ): ?>
        <div class="notice notice-<?php echo $notice_type==='error'?'error':($notice_type==='info'?'info':'success'); ?> is-dismissible"><p><?php echo wp_kses_post( $notice ); ?></p></div>
        <?php endif; ?>

        <div class="wpswd-card">
            <h2><?php echo esc_html( $lang->t('sum_card_title') ); ?></h2>
            <table class="widefat striped" style="max-width:640px">
                <tbody>
                <tr><?php ?><th><?php echo esc_html( $lang->t('sum_smtp') ); ?></th><td><?php echo $cfg->enable_smtp ? '<span class="wpswd-on">&#x2713; ' . esc_html( $lang->t('sum_actif') ) . '</span>' : '<span class="wpswd-off">&#x2717; ' . esc_html( $lang->t('sum_inactif') ) . '</span>'; ?></td></tr>
                <tr><?php ?><th><?php echo esc_html( $lang->t('sum_from') ); ?></th><td><?php echo esc_html( $cfg->email_from ); ?></td></tr>
                <tr><?php ?><th><?php echo esc_html( $lang->t('sum_host') ); ?></th><td><?php echo esc_html( $cfg->smtp_host ); ?> : <?php echo esc_html( $cfg->smtp_port ); ?></td></tr>
                <tr><?php ?><th><?php echo esc_html( $lang->t('sum_enc') ); ?></th><td><?php echo esc_html( strtoupper( $cfg->smtp_encryption ) ); ?></td></tr>
                <tr><?php ?><th><?php echo esc_html( $lang->t('sum_user') ); ?></th><td><?php echo $cfg->smtp_username ? esc_html( $cfg->smtp_username ) : '<em style="color:#aaa">' . esc_html( $lang->t('sum_unconfigured') ) . '</em>'; ?></td></tr>
                <tr><?php ?><th><?php echo esc_html( $lang->t('sum_pwd') ); ?></th><td>
                    <?php if (!$pwd_is_set): ?><em style="color:#aaa"><?php echo esc_html( $lang->t('sum_unconfigured') ); ?></em>
                    <?php elseif ($pwd_chiffre): ?><span style="color:#2e7d32"><?php echo esc_html( $lang->t('sum_pwd_enc') ); ?></span>
                    <?php else: ?><span style="color:#e65100"><?php echo esc_html( $lang->t('sum_pwd_plain') ); ?></span><?php endif; ?>
                  </td></tr>
                
                <?php if ( ! class_exists( 'smtpdkimc_License' ) ): ?>
                <tr>
                    <th><?php echo $lang->get() === 'en' ? 'DKIM Private Key' : 'DKIM Privé'; ?></th>
                    <td><span style="color:#888;font-style:italic">&#x1F512; <?php echo $lang->get() === 'en' ? 'Available in Premium version &mdash; <a href="https://smtp-dkim.com" target="_blank">smtp-dkim.com</a>' : 'Disponible en version Premium &mdash; <a href="https://smtp-dkim.com" target="_blank">smtp-dkim.com</a>'; ?></span></td>
                </tr>
                <?php endif; ?>
</tbody>
</table>

        </div>

        <form method="post" id="wpswd-main-form">
        <?php wp_nonce_field('smtpdkimc_save_config','smtpdkimc_nonce'); ?>

        <div class="wpswd-card">
            <h2><?php echo esc_html( $lang->t('activate_card_title') ); ?></h2>
            <div class="wpswd-row">
                <label><?php echo esc_html( $lang->t('activate_checkbox') ); ?></label>
                <div>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                        <input type="checkbox" name="enable_smtp" value="1" <?php echo esc_attr( $chk($cfg->enable_smtp) ); ?> style="width:18px;height:18px">
                        <span><?php echo wp_kses_post( $lang->t('activate_desc') ); ?></span>
                    </label>
                    <p class="wpswd-desc">
                        <?php if ( $cfg->enable_smtp ): ?>
                            <span class="wpswd-on">&#x2713; ACTIF</span> &mdash;
                            <?php echo esc_html( $lang->t('status_on') ); ?>
                        <?php else: ?>
                            <span style="display:inline-block;background:#e3f2fd;color:#1565c0;padding:3px 10px;border-radius:3px;font-weight:700">
                                <?php echo esc_html( $lang->t('status_off_badge') ); ?>
                            </span><br>
                            <span style="font-size:12px;color:#555;line-height:1.6;display:block;margin-top:5px">
                                <?php echo wp_kses_post( $lang->t('status_off_desc') ); ?>
                            </span>
                        <?php endif; ?>
                    </p>
                    <?php if ( class_exists('WPMailSMTP\Options') || function_exists('wp_mail_smtp') ): ?>
                    <div class="wpswd-warn"><?php echo esc_html( $lang->t('conflict_warn') ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="wpswd-card">
            <h2><?php echo esc_html( $lang->t('sender_card_title_lbl') ); ?></h2>
            <div class="wpswd-row">
                <label for="email_from"><?php echo esc_html( $lang->t('email_from_label') ); ?></label>
                <div>
                    <input type="email" id="email_from" name="email_from" value="<?php echo esc_attr( $cfg->email_from ); ?>" placeholder="<?php echo esc_attr( $lang->t('email_from_placeholder') ); ?>">
                    <p class="wpswd-desc"><?php echo esc_html( $lang->t('email_from_desc') ); ?></p>
                </div>
            </div>
            <div class="wpswd-row">
                <label for="email_from_name"><?php echo esc_html( $lang->t('from_name_label') ); ?></label>
                <input type="text" id="email_from_name" name="email_from_name" value="<?php echo esc_attr( $cfg->email_from_name ); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
            </div>
            <div class="wpswd-row">
                <label><?php echo esc_html( $lang->t('force_from_label') ); ?></label>
                <label><input type="checkbox" name="force_from_email" value="1" <?php echo esc_attr( $chk($cfg->force_from_email) ); ?>> <?php echo esc_html( $lang->t('force_from_desc') ); ?></label>
            </div>
        </div>

        <div class="wpswd-card">
            <h2><?php echo esc_html( $lang->t('smtp_card_title') ); ?></h2>
            <div style="background:#e8f5e9;border-left:4px solid #43a047;border-radius:4px;padding:12px 16px;margin-bottom:16px;font-size:13px;line-height:1.6">
                <?php echo wp_kses_post( $lang->t('smtp_privacy') ); ?>
            </div>
            <div class="wpswd-row">
                <label for="smtp_host"><?php echo esc_html( $lang->t('smtp_host_label') ); ?></label>
                <div>
                    <input type="text" id="smtp_host" name="smtp_host" value="<?php echo esc_attr( $cfg->smtp_host ); ?>" placeholder="<?php echo esc_attr( $lang->t('smtp_host_placeholder') ); ?>">
                    <p class="wpswd-desc"><?php echo esc_html( $lang->t('smtp_host_desc') ); ?></p>
                </div>
            </div>
            <div class="wpswd-row">
                <label for="smtp_encryption"><?php echo esc_html( $lang->t('enc_label') ); ?></label>
                <select id="smtp_encryption" name="smtp_encryption" onchange="document.getElementById('smtp_port').value=this.value==='ssl'?'465':this.value==='tls'?'587':'25'">
                    <option value="ssl"  <?php echo esc_attr( $sel($cfg->smtp_encryption,'ssl') );  ?>><?php echo esc_html( $lang->t('enc_ssl') ); ?></option>
                    <option value="tls"  <?php echo esc_attr( $sel($cfg->smtp_encryption,'tls') );  ?>><?php echo esc_html( $lang->t('enc_tls') ); ?></option>
                    <option value="none" <?php echo esc_attr( $sel($cfg->smtp_encryption,'none') ); ?>><?php echo esc_html( $lang->t('enc_none') ); ?></option>
                </select>
            </div>
            <div class="wpswd-row">
                <label for="smtp_port"><?php echo esc_html( $lang->t('port_label') ); ?></label>
                <input type="text" id="smtp_port" name="smtp_port" value="<?php echo esc_attr( $cfg->smtp_port ); ?>" style="width:80px">
            </div>
            <div class="wpswd-row">
                <label><?php echo esc_html( $lang->t('auto_tls_label') ); ?></label>
                <label><input type="checkbox" name="smtp_auto_tls" value="1" <?php echo esc_attr( $chk($cfg->smtp_auto_tls) ); ?>> <?php echo esc_html( $lang->t('auto_tls_desc') ); ?></label>
            </div>
            <div class="wpswd-row">
                <label><?php echo esc_html( $lang->t('auth_label') ); ?></label>
                <label><input type="checkbox" name="smtp_auth" value="1" <?php echo esc_attr( $chk($cfg->smtp_auth) ); ?>> <?php echo esc_html( $lang->t('auth_desc') ); ?></label>
            </div>
            <div class="wpswd-row">
                <label for="smtp_username"><?php echo esc_html( $lang->t('user_label') ); ?></label>
                <input type="text" id="smtp_username" name="smtp_username" value="<?php echo esc_attr( $cfg->smtp_username ); ?>" autocomplete="off" placeholder="<?php echo esc_attr( $lang->t('email_from_placeholder') ); ?>">
            </div>
            <div class="wpswd-row">
                <label for="smtp_password"><?php echo esc_html( $lang->t('pwd_label') ); ?></label>
                <div>
                    <div style="display:flex;gap:8px;align-items:center;max-width:480px">
                        <input type="password" id="smtp_password" name="smtp_password" value=""
                               placeholder="<?php echo esc_attr( $pwd_is_set ? $lang->t('pwd_placeholder_keep') : $lang->t('pwd_placeholder_new') ); ?>"
                               autocomplete="new-password" style="flex:1">
                        <button type="button" class="button" onclick="var f=document.getElementById('smtp_password');f.type=f.type==='password'?'text':'password';this.textContent=f.type==='password'?'&#x1F441;&#xFE0F; Voir':'&#x1F648; Masquer'">&#x1F441;&#xFE0F; Voir</button>
                    </div>
                    <?php if ($pwd_is_set && $pwd_chiffre): ?>
                        <p class="wpswd-desc" style="color:#2e7d32"><?php echo esc_html( $lang->t('pwd_encrypted_note') ); ?></p>
                    <?php elseif ($pwd_is_set): ?>
                        <p class="wpswd-desc" style="color:#e65100"><?php echo esc_html( $lang->t('pwd_warn') ); ?></p>
                    <?php else: ?>
                        <p class="wpswd-desc" style="color:#d63638"><?php echo esc_html( $lang->t('pwd_missing') ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        

        <p>
            <button type="submit" name="smtpdkimc_save" class="button button-primary button-hero"><?php echo esc_html( $lang->t('save_btn') ); ?></button>
        </p>
        </form>

        <?php if ( false ) : /* ── Visionneur de logs SMTP ── désactivé temporairement */ ?>
        <div class="wpswd-card">
            <h2>📋 <?php echo $lang->get() === 'en' ? 'SMTP Debug Log' : 'Log de debug SMTP'; ?></h2>
            <?php if ( (int)$cfg->smtp_debug_level === 0 ): ?>
            <p class="wpswd-desc" style="color:#e65100;margin:0 0 12px">
                ⚠️ <?php echo $lang->get() === 'en' ? 'Debug is disabled (level 0) — no logs are recorded.' : 'Debug désactivé (niveau 0) — aucun log n\'est enregistré.'; ?>
            </p>
            <?php else: ?>
            <p class="wpswd-desc" style="color:#2e7d32;margin:0 0 12px">
                ✅ <?php echo $lang->get() === 'en' ? 'Debug active (level ' . (int)$cfg->smtp_debug_level . ') — logs are written after each email send.' : 'Debug actif (niveau ' . (int)$cfg->smtp_debug_level . ') — les logs sont écrits après chaque envoi.'; ?>
            </p>
            <?php endif; ?>
            <div style="display:flex;gap:8px;margin-bottom:10px">
                <button type="button" id="wpswd-debug-refresh" class="button button-secondary" style="font-size:12px">
                    🔄 <?php echo $lang->get() === 'en' ? 'Refresh log' : 'Rafraîchir le log'; ?>
                </button>
                <button type="button" id="wpswd-debug-clear" class="button" style="font-size:12px;color:#c62828;border-color:#c62828">
                    🗑️ <?php echo $lang->get() === 'en' ? 'Clear log' : 'Vider le log'; ?>
                </button>
                <span id="wpswd-debug-meta" style="font-size:11px;color:#888;line-height:28px"></span>
            </div>
            <textarea id="wpswd-debug-log" readonly
                style="width:100%;max-width:600px;height:220px;font-family:monospace;font-size:11px;background:#1e1e1e;color:#d4d4d4;border:1px solid #444;border-radius:4px;padding:10px;resize:vertical;white-space:pre;overflow-x:hidden;display:block"
                placeholder="<?php echo esc_attr( $lang->get() === 'en' ? 'No logs yet. Send a test email to generate debug output.' : 'Aucun log pour l\'instant. Envoyez un email de test pour générer des données.' ); ?>"></textarea>
            <p class="wpswd-desc" style="font-size:11px;margin-top:6px">
                📁 <?php echo $lang->get() === 'en' ? 'File:' : 'Fichier :'; ?>
                <code><?php echo esc_html( smtpdkimc_PLUGIN_DIR . 'smtp-debug.log' ); ?></code>
                <?php echo $lang->get() === 'en' ? '— Max 500 KB (auto-truncated).' : '— Max 500 Ko (tronqué automatiquement).'; ?>
            </p>
        </div>
        <?php endif; /* fin log SMTP */ ?>

        

        

        <div class="wpswd-card">
            <h2><?php echo esc_html( $lang->t('test_external_title') ); ?></h2>
            <?php if ( ! $cfg->enable_smtp ): ?>
            <div class="wpswd-warn"><?php echo wp_kses_post( $lang->t('smtp_must_enable_warn') ); ?></div>
            <?php else: ?>
            <div style="background:#f9f9f9;border-left:4px solid #aaa;padding:10px 14px;border-radius:4px;margin-bottom:14px;font-size:13px;color:#444">
                <?php if ( ! class_exists( 'smtpdkimc_License' ) ): ?>
                    <?php echo wp_kses_post( $lang->t('test_external_explain_lite') ); ?>
                <?php else: ?>
                    <?php echo esc_html( $lang->t('test_external_explain') ); ?>
                <?php endif; ?>
            </div>
            <form method="post" id="wpswd-test-external-form" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <?php wp_nonce_field('smtpdkimc_test_external','smtpdkimc_test_ext_nonce'); ?>
                <input type="email" name="smtpdkimc_test_external_email" id="wpswd-test-ext-email" required
                       placeholder="destinataire@exemple.com"
                       value=""
                       style="width:260px">
                <button type="submit" name="smtpdkimc_test_external" id="wpswd-test-ext-btn" class="button button-secondary">
                    <?php echo esc_html( $lang->t('test_external_btn') ); ?>
                </button>
                <span id="wpswd-test-ext-spinner" style="display:none;color:#888;font-size:13px">⏳ <?php echo $lang->get()==='en'?'Sending…':'Envoi en cours…'; ?></span>
            </form>
            <div id="wpswd-test-ext-result" style="margin-top:8px"></div>
            <p style="font-size:12px;color:#888;margin-top:8px">
                <?php echo esc_html( $lang->t('test_external_security') ); ?>
            </p>
            <?php endif; ?>
        </div>

        <?php
        $_is_en = $lang->get() === 'en';
        $_debug_labels = [
            0 => $_is_en ? '0 — Disabled (no log)'         : '0 — Désactivé (aucun log)',
            1 => $_is_en ? '1 — Errors only'               : '1 — Erreurs uniquement',
            2 => $_is_en ? '2 — Client/server messages'    : '2 — Messages client/serveur',
            3 => $_is_en ? '3 — Detailed connection info'  : '3 — Infos de connexion détaillées',
            4 => $_is_en ? '4 — Full low-level debug'      : '4 — Debug complet (bas niveau)',
        ];
        ?>
        <div class="wpswd-card" id="wpswd-debug-card">
            <h2>&#x1F4CB; <?php echo $_is_en ? 'SMTP Debug Log' : 'Journal SMTP'; ?></h2>
            <form method="post">
                <?php wp_nonce_field('smtpdkimc_save_config','smtpdkimc_nonce'); ?>
                <input type="hidden" name="smtpdkimc_save" value="1">
                <input type="hidden" name="smtpdkimc_debug_anchor" value="1">
                <?php foreach ( (array) $cfg as $ck => $cv ) {
                    if ( $ck === 'smtp_debug_level' ) continue;
                    if ( is_string($cv) || is_int($cv) ) {
                        echo '<input type="hidden" name="' . esc_attr($ck) . '" value="' . esc_attr((string)$cv) . '">';
                    }
                } ?>
                <div class="wpswd-row" style="margin-bottom:8px">
                    <label for="wpswd-debug-level-select" style="font-weight:600;font-size:13px;color:#2c3e50;padding-top:7px">
                        <?php echo $_is_en ? 'Debug Level' : 'Niveau de debug'; ?>
                    </label>
                    <div>
                        <select id="wpswd-debug-level-select" name="smtp_debug_level" style="min-width:280px;border:1px solid #c5cdd8;border-radius:4px;padding:6px 10px;font-size:13px;background:#fafbfc">
                            <?php foreach ( $_debug_labels as $k => $v ): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php echo (int)$cfg->smtp_debug_level === $k ? 'selected' : ''; ?>><?php echo esc_html($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="wpswd-desc" style="margin-top:5px">
                            <?php echo $_is_en
                                ? 'Level 0 = disabled. Level 1–4 = progressively more detail.'
                                : 'Niveau 0 = désactivé. Niveaux 1–4 = de plus en plus de détails.'; ?>
                        </p>
                    </div>
                </div>
                <div class="wpswd-row" style="margin-bottom:16px">
                    <div></div>
                    <div>
                        <button type="submit" class="button button-primary">
                            <?php echo $_is_en ? '&#x1F4BE; Save debug level' : '&#x1F4BE; Enregistrer le niveau'; ?>
                        </button>
                    </div>
                </div>
            </form>
            <?php if ( (int) $cfg->smtp_debug_level > 0 ): ?>
            <?php if ( ! $cfg->enable_smtp ): ?>
            <div class="wpswd-warn"><?php echo wp_kses_post( $lang->t('smtp_must_enable_warn') ); ?></div>
            <?php else: ?>
            <p class="wpswd-desc" style="margin-bottom:8px;font-family:monospace;background:#f4f4f4;padding:5px 10px;border-radius:4px;border:1px solid #ddd">
                &#x1F4C2; <?php echo esc_html( smtpdkimc_PLUGIN_DIR . 'smtp-debug.log' ); ?>
            </p>
            <div style="display:flex;gap:8px;margin-bottom:10px">
                <button type="button" id="wpswd-debug-refresh" class="button">&#x21BB; <?php echo $_is_en ? 'Refresh' : 'Actualiser'; ?></button>
                <button type="button" id="wpswd-debug-clear" class="button" style="color:#c62828">&#x1F5D1; <?php echo $_is_en ? 'Clear log' : 'Vider le journal'; ?></button>
                <span id="wpswd-debug-meta" style="font-size:11px;color:#888;line-height:28px"></span>
            </div>
            <textarea id="wpswd-debug-log" readonly style="width:100%;height:220px;font-family:monospace;font-size:11px;background:#1e1e1e;color:#d4d4d4;border:1px solid #333;border-radius:4px;padding:10px;resize:vertical;box-sizing:border-box;overflow-x:hidden"><?php
                $log_file = smtpdkimc_PLUGIN_DIR . 'smtp-debug.log';
                echo esc_textarea( file_exists( $log_file ) ? file_get_contents( $log_file ) : ( $_is_en ? '(no log yet)' : '(journal vide)' ) );
            ?></textarea>
            <?php endif; ?>
            <?php else: ?>
            <div class="wpswd-info"><?php echo $_is_en
                ? '&#x1F6AB; Debug is disabled. Select level 1–4 above to start logging SMTP activity.'
                : '&#x1F6AB; Debug désactivé. Sélectionnez le niveau 1–4 ci-dessus pour commencer à journaliser l\'activité SMTP.'; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="wpswd-card" id="wpswd-dns-card">
            <h2><?php echo esc_html( $lang->t('dns_card_title') ); ?></h2>
            <p><?php echo esc_html( $lang->t('dns_desc') ); ?></p>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px">
                <div>
                    <label style="font-weight:600;display:block;margin-bottom:4px"><?php echo esc_html( $lang->t('dns_domain_lbl') ); ?></label>
                    <input type="text" id="wpswd-dns-domain" value="<?php echo esc_attr( $dns_domain ); ?>" style="width:260px" placeholder="votre-domaine.com">
                </div>
                <div>
                    <label style="font-weight:600;display:block;margin-bottom:4px"><?php echo esc_html( $lang->t('dns_sel_lbl') ); ?></label>
                    <input type="text" id="wpswd-dns-selector" value="<?php echo esc_attr( $dkim_sel ); ?>" style="width:120px" placeholder="default">
                </div>
                <div style="padding-top:24px">
                    <button type="button" id="wpswd-dns-scan" class="button button-primary" style="height:36px;padding:0 20px">
                        <?php echo esc_html( $lang->t('dns_scan_btn') ); ?>
                    </button>
                </div>
            </div>
            <p style="font-size:12px;color:#888;margin:0 0 16px">
                <?php echo esc_html( $lang->t('dns_manual_lbl') ); ?>
                <a href="https://mxtoolbox.com/SuperTool.aspx?action=spf%3A<?php echo urlencode( $dns_domain ); ?>" target="_blank">SPF</a> &middot;
                <a href="https://mxtoolbox.com/SuperTool.aspx?action=dmarc%3A<?php echo urlencode( $dns_domain ); ?>" target="_blank">DMARC</a> &middot;
                <a href="https://mxtoolbox.com/SuperTool.aspx?action=dkim%3A<?php echo urlencode( $dns_domain ); ?>%3A<?php echo urlencode( $dkim_sel ); ?>" target="_blank">DKIM</a>
            </p>
            <div id="wpswd-dns-results" style="display:none"></div>
        </div>

        </div><?php // fin .wrap

        ?>
        

        <?php
    }

}
