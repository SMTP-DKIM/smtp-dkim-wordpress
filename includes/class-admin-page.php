<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Page d'administration — SMTP DKIM
 */
class smtpdkimc_Admin_Page {

    private string        $table;
    private string        $lic_table;
    private smtpdkimc_License $license;

    public function __construct() {
        global $wpdb;
        $this->table     = $wpdb->prefix . smtpdkimc_TABLE;
        $this->lic_table = $wpdb->prefix . smtpdkimc_LICENSE_TABLE;
        $this->license   = new smtpdkimc_License();

        add_action( 'admin_menu',            [ $this, 'register_menu'      ] );
        add_action( 'admin_init',            [ $this, 'maybe_run_migration' ] );
        add_action( 'phpmailer_init',        [ $this, 'configure_phpmailer' ], 10 );
        add_action( 'wp_mail_failed',        [ $this, 'log_mail_error'      ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'      ] );

        // Modale de feedback à la désactivation (page Extensions)
        add_action( 'admin_footer-plugins.php', [ $this, 'render_deactivation_modal' ] );
        add_action( 'wp_ajax_smtpdkimc_deactivation_feedback', [ $this, 'handle_deactivation_feedback' ] );
    }

    public function register_menu(): void {
        add_menu_page( 'SMTP DKIM', 'SMTP DKIM', 'manage_options',
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
        if ( is_resource( $resource ) ) {
            openssl_free_key( $resource );
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
            $lic_obj = new smtpdkimc_License();
            if ( ! empty( $cfg->dkim_private_key ) && $lic_obj->is_valid() && $lic_obj->has_valid_activation_sig() ) {
                $clean_key = $this->normalize_dkim_key( $cfg->dkim_private_key );
                if ( ! empty( $clean_key ) ) {
                    $domain = $cfg->dkim_domain ?: wp_parse_url( home_url(), PHP_URL_HOST );
                    $sel    = $cfg->dkim_selector ?: 'default';
                    $phpmailer->DKIM_domain = $domain;
                    $phpmailer->DKIM_selector = $sel;
                    $phpmailer->DKIM_private_string = $clean_key;
                    $phpmailer->DKIM_passphrase = '';
                    $phpmailer->DKIM_identity = $phpmailer->From;
                }
            }
            $phpmailer->SMTPOptions = ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
            $phpmailer->Timeout = 300;
            $phpmailer->SMTPDebug = (int) $cfg->smtp_debug_level;
            if ( $phpmailer->SMTPDebug > 0 ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                $phpmailer->Debugoutput = static fn($s,$l) => error_log("WPSWD Debug[$l]: $s");
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

        $lic        = new smtpdkimc_License();
        $lic_valid  = $lic->is_valid();
        $lic_info   = $lic->get_info();
        $dkim_on    = $lic_valid && ! empty( $cfg->dkim_private_key );
        $lic_row    = ( new smtpdkimc_License() )->get_info();
        $to         = ( $lic_row && ! empty( $lic_row->customer_email ) )
            ? $lic_row->customer_email
            : ( get_option('admin_email') ?: $cfg->email_from );
        $site_name  = get_bloginfo('name');
        $site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
        // Utiliser le domain DKIM sauvegardé (sans www si configuré ainsi) — même logique que le scan UI
        $dns_domain = ! empty( $cfg->dkim_domain ) ? $cfg->dkim_domain : $site_domain;
        $dkim_sel   = $cfg->dkim_selector ?: 'default';

        // Vider le cache DNS avant l'envoi pour forcer un scan frais (évite les données périmées du www.)
        $dns_checker_email = new smtpdkimc_DNS_Checker();
        $dns_checker_email->clear_cache( $dns_domain, $dkim_sel );
        $dns = $dns_checker_email->check_domain( $dns_domain, $dkim_sel );

        $ok_icon  = '&#x2705;';
        $warn_icon= '&#x26A0;&#xFE0F;';
        $bad_icon = '&#x274C;';

        $dns_icon = function( string $status ) use ($ok_icon,$warn_icon,$bad_icon): string {
            if ( $status === 'ok' )      return $ok_icon;
            if ( $status === 'warning' ) return $warn_icon;
            return $bad_icon;
        };

        $subject = ( $is_en ? 'Test SMTP DKIM — ' : 'Test SMTP DKIM — ' ) . $site_name;

        $row = static fn(string $label, string $val) =>
            '<tr><td style="padding:7px 12px;background:#f7f7f7;font-weight:700;width:42%;color:#444;border-bottom:1px solid #eee">' . esc_html($label) . '</td>'
            . '<td style="padding:7px 12px;border-bottom:1px solid #eee">' . $val . '</td></tr>';

        $section = static fn(string $title) =>
            '<tr><td colspan="2" style="padding:10px 12px 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#0073aa;background:#e8f4fd">'
            . esc_html($title) . '</td></tr>';

        $msg  = '<html><body style="font-family:Arial,sans-serif;color:#333;margin:0;padding:0;background:#f0f0f0">';
        $msg .= '<div style="max-width:640px;margin:24px auto;background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">';
        $msg .= '<div style="background:linear-gradient(135deg,#0073aa,#005580);padding:24px 28px;color:#fff">';
        $msg .= '<div style="font-size:20px;font-weight:700;margin-bottom:4px">SMTP DKIM</div>';
        $msg .= '<div style="opacity:.85;font-size:14px">';
        $msg .= $is_en ? 'Test email from <strong>' . esc_html($site_name) . '</strong>' : 'Email de test depuis <strong>' . esc_html($site_name) . '</strong>';
        $msg .= '</div></div>';
        $msg .= '<div style="padding:24px 28px">';
        $msg .= '<table style="border-collapse:collapse;width:100%;font-size:13px;border:1px solid #e0e0e0;border-radius:4px;overflow:hidden">';

        $msg .= $section( $is_en ? 'SMTP Configuration' : 'Configuration SMTP' );
        $msg .= $row( $is_en ? 'SMTP Host' : 'Hôte SMTP',   esc_html($cfg->smtp_host) . ':' . esc_html($cfg->smtp_port) );
        $msg .= $row( $is_en ? 'Encryption' : 'Chiffrement', esc_html( strtoupper( $cfg->smtp_encryption ) ) );
        $msg .= $row( $is_en ? 'From Email' : 'Expéditeur',  esc_html($cfg->email_from) );
        $msg .= $row( $is_en ? 'DKIM Private Key' : 'Clé privée DKIM',
            $dkim_on
                ? esc_html($ok_icon) . ' <strong>' . ($is_en ? 'Active' : 'Active') . '</strong> &mdash; ' . esc_html($dkim_sel) . '._domainkey.' . esc_html($cfg->dkim_domain ?: $dns_domain)
                : esc_html($bad_icon) . ' ' . ($is_en ? 'Inactive (license required)' : 'Inactive (licence requise)') );

        $msg .= $section( $is_en ? 'SMTP-DKIM License' : 'Licence SMTP-DKIM' );
        if ( $lic_valid && $lic_info ) {

            $plan_label = function( ?string $plan ) use ($is_en): string {
                if ( empty($plan) ) return $is_en ? 'Standard' : 'Standard';
                $map = [
                    'sdkm-single'    => $is_en ? '1 Site'          : '1 Site',
                    'sdkm-multi3'    => $is_en ? '3 Sites'         : '3 Sites',
                    'sdkm-multi5'    => $is_en ? '5 Sites'         : '5 Sites',
                    'sdkm-unlimited' => $is_en ? 'Unlimited Sites' : 'Sites illimités',
                    'sdkm-lifetime'  => $is_en ? 'Lifetime'        : 'À vie',
                ];
                return $map[ strtolower(trim($plan)) ] ?? ucfirst(str_replace(['-','_'], ' ', $plan));
            };

            $exp_ts_email = ( ! empty($lic_info->expires_at) && strtotime($lic_info->expires_at) > 0 )
                ? strtotime($lic_info->expires_at) : null;
            $is_expired  = $exp_ts_email && $exp_ts_email < time();
            $is_lifetime = ! $exp_ts_email
                        || in_array( strtolower($lic_info->plan_type ?? ''), ['sdkm-lifetime', 'lifetime'], true );

            $msg .= $row( $is_en ? 'Status' : 'Statut', esc_html($ok_icon) . ' <strong>' . ($is_en ? 'Active' : 'Active') . '</strong>' );
            if ( ! empty($lic_info->customer_email) ) {
                $msg .= $row( $is_en ? 'License holder' : 'Détenteur de la licence', esc_html( ( $lic_info->customer_name ? $lic_info->customer_name . ' — ' : '' ) . $lic_info->customer_email ) );
            }
            $msg .= $row( $is_en ? 'License Type' : 'Type de licence', '<strong>' . esc_html( $plan_label($lic_info->plan_type ?? null) ) . '</strong>' );
            $msg .= $row( $is_en ? 'Domain' : 'Domaine', esc_html($lic_info->domain ?? '-') );

            if ( $is_lifetime ) {
                $exp_val = $is_en
                    ? '&#x267E;&#xFE0F; <strong style="color:#2e7d32">Lifetime — never expires</strong>'
                    : '&#x267E;&#xFE0F; <strong style="color:#2e7d32">À vie — n\'expire jamais</strong>';
            } elseif ( $exp_ts_email ) {
                $exp_date  = gmdate( $is_en ? 'F j, Y' : 'd/m/Y', $exp_ts_email );
                $days_left = (int) ceil( ( $exp_ts_email - time() ) / 86400 );
                if ( $is_expired ) {
                    $exp_val = '&#x274C; <strong style="color:#c62828">'
                             . ( $is_en ? 'Expired on ' . $exp_date : 'Expirée le ' . $exp_date )
                             . '</strong>';
                } elseif ( $days_left <= 30 ) {
                    $exp_val = '&#x26A0;&#xFE0F; <strong style="color:#e65100">' . esc_html($exp_date) . '</strong>'
                             . ' &mdash; <em>' . ( $is_en ? $days_left . ' days remaining' : $days_left . ' jours restants' ) . '</em>';
                } else {
                    $exp_val = '&#x2705; ' . esc_html($exp_date)
                             . ' &mdash; <em>' . ( $is_en ? $days_left . ' days remaining' : $days_left . ' jours restants' ) . '</em>';
                }
            } else {
                $exp_val = $is_en ? 'N/A' : 'N/D';
            }
            $msg .= $row( $is_en ? 'Expiration' : 'Expiration', $exp_val );

            if ( ! empty($lic_info->license_key) ) {
                $msg .= $row( $is_en ? 'Key' : 'Clé', '<code style="font-size:11px;word-break:break-all;display:block;padding:8px;background:#f5f5f5;border-radius:3px">' . esc_html($lic_info->license_key) . '</code>' );
            }

            $msg .= '<tr><td colspan="2" style="padding:10px 12px;background:#fff8e1;font-size:12px;color:#6d5102;border-bottom:1px solid #eee">';
            if ( $is_en ) {
                $msg .= '&#x2139;&#xFE0F; When the license expires, your DKIM private key remains saved in your database. '
                      . 'DKIM signing is disabled until the license is renewed. '
                      . 'Your emails continue to be sent via SMTP, but without DKIM signature. '
                      . '<strong>&#x26A0;&#xFE0F; If the SMTP-DKIM license is expired, emails sent to your customers may land in their SPAM folder.</strong>';
            } else {
                $msg .= '&#x2139;&#xFE0F; Quand la licence expire, votre clé privée DKIM reste enregistrée dans votre base de données. '
                      . 'La signature DKIM est désactivée jusqu\'au renouvellement. '
                      . 'Vos emails continuent d\'être envoyés via SMTP, mais sans signature DKIM. '
                      . '<strong>&#x26A0;&#xFE0F; Si la licence smtp-dkim est expirée, les courriels envoyés à vos clients risquent de tomber dans le dossier SPAM de vos clients.</strong>';
            }
            $msg .= '</td></tr>';

        } else {
            $msg .= $row( $is_en ? 'Status' : 'Statut', esc_html($bad_icon) . ' ' . ($is_en ? 'No active license — <a href="https://smtp-dkim.com">smtp-dkim.com</a>' : 'Aucune licence active — <a href="https://smtp-dkim.com">smtp-dkim.com</a>') );
        }

        $msg .= $section( $is_en ? 'DNS Diagnostic — SPF · DKIM Public Key · DMARC' : 'Diagnostic DNS — SPF · Clé Publique DKIM · DMARC' );

        $spf_status  = $dns['spf']['status']   ?? 'missing';
        $dkim_status = $dns['dkim']['status']  ?? 'missing';
        $dmarc_status= $dns['dmarc']['status'] ?? 'missing';

        $status_label = function( string $s ) use ($is_en): string {
            if ( $s === 'ok' )      return $is_en ? 'OK ✅' : 'OK ✅';
            if ( $s === 'warning' ) return $is_en ? 'Warning ⚠️' : 'À corriger ⚠️';
            return $is_en ? 'Missing / Error ❌' : 'Absent / Erreur ❌';
        };

        $msg .= $row( 'SPF',   esc_html($status_label($spf_status))  . ( !empty($dns['spf']['record'])  ? '<br><code style="font-size:11px;color:#555">' . esc_html(substr($dns['spf']['record'],0,80))  . '</code>' : '' ) );
        $msg .= $row( $is_en ? 'DKIM Public Key' : 'Clé Publique DKIM', esc_html($status_label($dkim_status)) . ( !empty($dns['dkim']['record']) ? '<br><code style="font-size:11px;color:#555">' . esc_html(substr($dns['dkim']['record'],0,60)) . '…</code>' : '' ) );
        $msg .= $row( 'DMARC', esc_html($status_label($dmarc_status)) . ( !empty($dns['dmarc']['record']) ? '<br><code style="font-size:11px;color:#555">' . esc_html(substr($dns['dmarc']['record'],0,80)) . '</code>' : '' ) );

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

        $dkim_active = ! empty($cfg->dkim_private_key) && ( new smtpdkimc_License() )->is_valid();
        $body .= '<div style="background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:14px 18px;margin:16px 0;font-size:13px">';
        $body .= '<strong>' . ( $is_en ? 'Technical details' : 'Détails techniques' ) . '</strong><br><br>';
        $body .= ( $is_en ? 'SMTP Host' : 'Hôte SMTP' ) . ' : <code>' . esc_html($cfg->smtp_host ?: '—') . ':' . esc_html($cfg->smtp_port ?: '—') . '</code><br>';
        $body .= ( $is_en ? 'From' : 'Expéditeur' ) . ' : <code>' . esc_html($from) . '</code><br>';
        $body .= 'DKIM : ' . ( $dkim_active
            ? '<span style="color:#2e7d32">✅ ' . ( $is_en ? 'Active' : 'Activé' ) . '</span>'
            : '<span style="color:#e65100">⚠️ ' . ( $is_en ? 'Inactive' : 'Inactif' ) . '</span>' ) . '<br>';
        $body .= '</div>';

        $body .= '<p style="font-size:12px;color:#999">'
               . ( $is_en
                    ? '🔒 Security: this email does not contain any license details or sensitive information.'
                    : '🔒 Sécurité : cet email ne contient aucun détail de licence ni information sensible.' )
               . '</p>';
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

        <style>
        @keyframes smtpdkimc-spin { to { transform:rotate(360deg) } }
        #smtpdkimc-deact-overlay.active { display:flex !important }
        </style>

        <script>
        (function($){
            var deactUrl  = '';
            var nonce     = <?php echo wp_json_encode($nonce); ?>;
            var ajaxUrl   = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var errTxt    = <?php echo wp_json_encode($err_txt); ?>;
            var basename  = <?php echo wp_json_encode($basename); ?>;

            // Trouver le lien Désactiver du plugin et l'intercepter
            $(document).ready(function(){
                // Chercher le tr du plugin par son basename dans les liens action
                $('tr[data-slug]').each(function(){
                    var $row = $(this);
                    $row.find('a.deactivate-link, span.deactivate a, td.plugin-title ~ td a').each(function(){
                        var href = $(this).attr('href') || '';
                        // Vérifier que le lien de désactivation correspond à notre plugin
                        if ( href.indexOf('action=deactivate') !== -1 && href.indexOf( encodeURIComponent(basename) ) !== -1 ) {
                            $(this).on('click', function(e){
                                e.preventDefault();
                                deactUrl = href;
                                $('#smtpdkimc-deact-overlay').addClass('active');
                                // Reset état
                                $('input[name="smtpdkimc_reason"]').prop('checked', false);
                                $('#smtpdkimc-comment').val('');
                                $('#smtpdkimc-sending').hide();
                            });
                        }
                    });
                });

                // Aussi cibler via le texte du lien (fallback universel)
                $('#the-list tr').find('span.deactivate a, td.column-description span.deactivate a').each(function(){
                    var href = $(this).attr('href') || '';
                    if ( href.indexOf('action=deactivate') !== -1 && href.indexOf( encodeURIComponent(basename) ) !== -1 ) {
                        $(this).off('click').on('click', function(e){
                            e.preventDefault();
                            deactUrl = href;
                            $('#smtpdkimc-deact-overlay').addClass('active');
                            $('input[name="smtpdkimc_reason"]').prop('checked', false);
                            $('#smtpdkimc-comment').val('');
                            $('#smtpdkimc-sending').hide();
                        });
                    }
                });
            });

            // Annuler
            $(document).on('click', '#smtpdkimc-btn-cancel', function(){
                $('#smtpdkimc-deact-overlay').removeClass('active');
            });

            // Clic sur l'overlay pour fermer
            $(document).on('click', '#smtpdkimc-deact-overlay', function(e){
                if ( e.target === this ) {
                    $(this).removeClass('active');
                }
            });

            // Ignorer et désactiver — aucun feedback envoyé, on suit juste le lien
            $(document).on('click', '#smtpdkimc-btn-skip', function(){
                if ( deactUrl ) window.location.href = deactUrl;
            });

            // Envoyer et désactiver
            $(document).on('click', '#smtpdkimc-btn-submit', function(){
                var reason = $('input[name="smtpdkimc_reason"]:checked').val();
                if ( ! reason ) {
                    alert(errTxt);
                    return;
                }
                var comment = $('#smtpdkimc-comment').val();
                $('#smtpdkimc-btn-submit, #smtpdkimc-btn-skip, #smtpdkimc-btn-cancel').prop('disabled', true);
                $('#smtpdkimc-sending').show();

                $.post(ajaxUrl, {
                    action  : 'smtpdkimc_deactivation_feedback',
                    nonce   : nonce,
                    reason  : reason,
                    comment : comment
                }, function(){
                    // Peu importe le résultat, on désactive quand même
                    if ( deactUrl ) window.location.href = deactUrl;
                }).fail(function(){
                    if ( deactUrl ) window.location.href = deactUrl;
                });
            });

        })(jQuery);
        </script>
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

    public function render_page(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Permission refusee.', 'smtp-dkim') );
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
                    'smtp_debug_level'=> isset( $_POST['smtp_debug_level'] ) ? (int) $_POST['smtp_debug_level'] : 0,
                    'dkim_domain'     => isset( $_POST['dkim_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['dkim_domain'] ) ) : '',
                    'dkim_selector'   => isset( $_POST['dkim_selector'] ) ? sanitize_text_field( wp_unslash( $_POST['dkim_selector'] ) ) : 'default',
                    'dkim_private_key'=> isset( $_POST['dkim_private_key'] ) ? wp_strip_all_tags( wp_unslash( $_POST['dkim_private_key'] ) ) : '',
                ];

                // Sécurité : si la licence est active, protéger le domain et le sélecteur enregistrés.
                // L'utilisateur doit d'abord désactiver sa licence pour changer le domain.
                // Cela évite le bug "max activations atteint" causé par un changement de domain sans désactivation.
                $lic_guard = new smtpdkimc_License();
                if ( $lic_guard->is_valid() ) {
                    $cfg_guard = $this->get_config();
                    $data['dkim_domain']   = $cfg_guard->dkim_domain   ?: $data['dkim_domain'];
                    $data['dkim_selector'] = $cfg_guard->dkim_selector  ?: $data['dkim_selector'];
                }
                $saved       = $this->save_config( $data );
                $notice      = $saved ? $lang->t('save_ok') : $lang->t('save_err');
                $notice_type = $saved ? 'success' : 'error';
            }
            if ( isset( $_POST['smtpdkimc_activate_license'] ) && check_admin_referer( 'smtpdkimc_license_action', 'smtpdkimc_lic_nonce' ) ) {
                // Récupérer le domain choisi manuellement (sauvegardé en config) AVANT d'activer
                $cfg_before_activation = $this->get_config();
                $activation_domain = ! empty( $cfg_before_activation->dkim_domain )
                    ? $cfg_before_activation->dkim_domain
                    : wp_parse_url( home_url(), PHP_URL_HOST );

                $result = $this->license->activate(
                    isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '',
                    $activation_domain
                );
                $notice = $result['message'];
                $notice_type = $result['success'] ? 'success' : 'error';

                if ( $result['success'] ) {
                    $domain      = $activation_domain; // utiliser le domain choisi, pas home_url
                    $dns_checker = new smtpdkimc_DNS_Checker();

                    $detected = $dns_checker->detect_selector( $domain );

                    $current_cfg = $this->get_config();
                    $update_data = [
                        'enable_smtp'     => $current_cfg->enable_smtp,
                        'email_from'      => $current_cfg->email_from,
                        'email_from_name' => $current_cfg->email_from_name,
                        'force_from_email'=> $current_cfg->force_from_email,
                        'smtp_host'       => $current_cfg->smtp_host,
                        'smtp_port'       => $current_cfg->smtp_port,
                        'smtp_encryption' => $current_cfg->smtp_encryption,
                        'smtp_auto_tls'   => $current_cfg->smtp_auto_tls,
                        'smtp_auth'       => $current_cfg->smtp_auth,
                        'smtp_username'   => $current_cfg->smtp_username,
                        'smtp_password'   => '',
                        'smtp_debug_level'=> $current_cfg->smtp_debug_level,
                        'dkim_domain'     => $domain, // domaine choisi manuellement
                        'dkim_selector'   => $current_cfg->dkim_selector ?: $detected['selector'],
                        'dkim_private_key'=> $current_cfg->dkim_private_key ?? '',
                    ];
                    $this->save_config( $update_data );

                    $dns_checker->clear_cache( $domain, $detected['selector'] );

                    if ( $detected['found'] ) {
                        $notice .= ' | ' . $lang->t('lic_notice_found') . ' <strong>' . esc_html($detected['selector']) . '</strong>';
                    } else {
                        $notice .= ' | ' . $lang->t('lic_notice_default');
                    }

                    set_transient( 'smtpdkimc_just_activated', 1, 300 );
                }
            }
            if ( isset( $_POST['smtpdkimc_deactivate_license'] ) && check_admin_referer( 'smtpdkimc_license_action', 'smtpdkimc_lic_nonce' ) ) {
                $result = $this->license->deactivate_license();
                $notice = $result['message'];
                $notice_type = 'info';
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

        $this->license->maybe_recheck_for_admin();

        $cfg         = $this->get_config();
        $lic_info    = $this->license->get_info();
        $lic_valid   = $this->license->is_valid();
        $lic_key_val = $lic_info->license_key ?? '';

        $site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
        // Pour le diagnostic DNS et DKIM, utiliser le domain choisi manuellement (sans www si configuré ainsi)
        $dns_domain  = ! empty( $cfg->dkim_domain ) ? $cfg->dkim_domain : $site_domain;
        $dkim_sel    = ! empty( $cfg->dkim_selector ) ? $cfg->dkim_selector : 'default';
        $dns_checker = new smtpdkimc_DNS_Checker();
        $dns_status  = $dns_checker->check_domain( $dns_domain, $dkim_sel );

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
            SMTP DKIM
            <span style="font-size:13px;color:#888;font-weight:normal;margin-left:4px">v<?php echo esc_html( smtpdkimc_VERSION ); ?> &mdash; <a href="https://smtp-dkim.com" target="_blank">smtp-dkim.com</a></span>
        </h1>

        <?php $lang->render_switcher(); ?>

        <?php if ( $notice ): ?>
        <div class="notice notice-<?php echo $notice_type==='error'?'error':($notice_type==='info'?'info':'success'); ?> is-dismissible"><p><?php echo wp_kses_post( $notice ); ?></p></div>
        <?php endif; ?>

        <style>
        #wpswd-wrap{max-width:880px}
        .wpswd-card{background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px 24px;margin-bottom:20px}
        .wpswd-card h2{margin-top:0;font-size:1.1em;border-bottom:1px solid #eee;padding-bottom:10px;display:flex;align-items:center;gap:8px}
        .wpswd-row{display:grid;grid-template-columns:220px 1fr;gap:8px 16px;align-items:start;margin-bottom:14px}
        .wpswd-row label{font-weight:600;padding-top:6px}
        .wpswd-row input[type=text],.wpswd-row input[type=email],.wpswd-row input[type=password],.wpswd-row select,.wpswd-row textarea{width:100%;max-width:480px}
        .wpswd-row textarea{font-family:monospace;font-size:12px}
        .wpswd-desc{font-size:12px;color:#666;margin:3px 0 0}
        .wpswd-on{display:inline-block;padding:3px 10px;border-radius:3px;background:#d4edda;color:#155724;font-weight:700}
        .wpswd-off{display:inline-block;padding:3px 10px;border-radius:3px;background:#f8d7da;color:#721c24;font-weight:700}
        .wpswd-info{background:#e8f4fd;border-left:4px solid #2196F3;padding:10px 14px;border-radius:2px;margin:10px 0;font-size:13px}
        .wpswd-warn{background:#fff3cd;border-left:4px solid #ffc107;padding:10px 14px;border-radius:2px;margin:10px 0;font-size:13px}
        .wpswd-locked{background:#f9f9f9;border:2px dashed #ccc;border-radius:6px;padding:30px;text-align:center;color:#888}
        .wpswd-locked h3{color:#555;margin-top:0}
        .wpswd-buy-btn{display:inline-block;background:#0073aa;color:#fff;padding:10px 24px;border-radius:4px;text-decoration:none;font-weight:bold;margin-top:10px}
        .wpswd-buy-btn:hover{background:#005d8c;color:#fff}
        .wpswd-lic-active{background:#d4edda;border:1px solid #b8dfc1;padding:12px 16px;border-radius:4px;margin-bottom:12px}
        .wpswd-lic-inactive{background:#f8d7da;border:1px solid #f1b0b7;padding:12px 16px;border-radius:4px;margin-bottom:12px}
        .wpswd-dkim-locked{position:relative;opacity:.45;pointer-events:none;user-select:none}
        .wpswd-dkim-locked::after{content:"";position:absolute;inset:0;cursor:not-allowed;z-index:5}
        .wpswd-dns-row{display:grid;grid-template-columns:110px 1fr;gap:10px;align-items:start;margin-bottom:18px;padding-bottom:18px;border-bottom:1px solid #eee}
        .wpswd-dns-row:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0}
        .wpswd-dns-label{font-weight:800;font-size:1rem;padding-top:2px}
        .wpswd-dns-record{font-family:monospace;font-size:12px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;padding:8px 12px;margin:8px 0;word-break:break-all;white-space:pre-wrap}
        .wpswd-dns-fix{background:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;padding:12px 14px;margin-top:10px;font-size:13px}
        .wpswd-dns-fix strong{display:block;margin-bottom:8px}
        .wpswd-dns-fix table{border-collapse:collapse;width:100%}
        .wpswd-dns-fix td{padding:3px 8px;vertical-align:top}
        .wpswd-dns-fix td:first-child{font-weight:700;width:70px;color:#555}
        .wpswd-dns-fix code{background:#fff;padding:2px 6px;border-radius:3px;border:1px solid #ddd;font-size:12px;word-break:break-all}
        .wpswd-dns-issue{color:#c62828;font-size:13px;margin:4px 0}
        .wpswd-dns-loading{text-align:center;padding:30px;color:#888;font-size:14px}
        .wpswd-dns-spinner{display:inline-block;width:20px;height:20px;border:3px solid #ddd;border-top-color:#0073aa;border-radius:50%;animation:wpswd-spin .8s linear infinite;vertical-align:middle;margin-right:8px}
        @keyframes wpswd-spin{to{transform:rotate(360deg)}}
        .wpswd-dns-summary{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap}
        .wpswd-dns-badge{padding:6px 16px;border-radius:50px;font-weight:700;font-size:13px}
        .wpswd-dns-badge-ok{background:#d4edda;color:#155724}
        .wpswd-dns-badge-warn{background:#fff3cd;color:#856404}
        .wpswd-dns-badge-bad{background:#f8d7da;color:#721c24}
        #wpswd-lic-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;justify-content:center;align-items:center}
        #wpswd-lic-overlay.active{display:flex}
        #wpswd-lic-overlay-box{background:#fff;border-radius:8px;padding:32px 36px;max-width:480px;width:90%;text-align:center}
        #wpswd-lic-overlay-box h3{margin:0 0 12px;color:#0073aa}
        #wpswd-lic-overlay-progress{width:100%;height:6px;background:#e0e0e0;border-radius:3px;overflow:hidden;margin:16px 0}
        #wpswd-lic-overlay-bar{height:100%;background:#0073aa;border-radius:3px;transition:width .4s ease;width:0}
        </style>

        <form method="post" id="wpswd-main-form">
        <?php wp_nonce_field('smtpdkimc_save_config','smtpdkimc_nonce'); ?>

        <div class="wpswd-card">
            <h2><?php echo esc_html( $lang->t('activate_card_title') ); ?></h2>
            <div class="wpswd-row">
                <label><?php echo esc_html( $lang->t('activate_checkbox') ); ?></label>
                <div>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                        <input type="checkbox" name="enable_smtp" value="1" <?php echo esc_attr( $chk($cfg->enable_smtp) ); ?> style="width:18px;height:18px">
                        <span><?php echo esc_html( $lang->t('activate_desc') ); ?></span>
                    </label>
                    <p class="wpswd-desc">
                        <?php if ( $cfg->enable_smtp ): ?>
                            <span class="wpswd-on">&#x2713; ACTIF</span> &mdash;
                            <?php echo esc_html( $lang->t('status_on') ); ?>
                        <?php else: ?>
                            <span style="display:inline-block;background:#e3f2fd;color:#1565c0;padding:3px 10px;border-radius:3px;font-weight:700">
                                &#x2139; <?php echo esc_html( $lang->t('status_off_badge') ); ?>
                            </span><br>
                            <span style="font-size:12px;color:#555;line-height:1.6;display:block;margin-top:5px">
                                <?php echo esc_html( $lang->t('status_off_desc') ); ?>
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
                    <div class="wpswd-warn"><?php echo esc_html( $lang->t('pwd_aes_note') ); ?></div>
                </div>
            </div>
            <div class="wpswd-row">
                <label for="smtp_debug_level"><?php echo esc_html( $lang->t('debug_label') ); ?></label>
                <div>
                    <select id="smtp_debug_level" name="smtp_debug_level">
                        <?php foreach ([0=>$lang->t('debug_0'),1=>$lang->t('debug_1'),2=>$lang->t('debug_2'),3=>$lang->t('debug_3'),4=>$lang->t('debug_4')] as $k=>$v): ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php echo esc_attr( $sel((string)$cfg->smtp_debug_level,(string)$k) ); ?>><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <?php
        $just_activated = (bool) get_transient( 'smtpdkimc_just_activated' );
        if ( $just_activated ) {
            delete_transient( 'smtpdkimc_just_activated' );
        }
        $dkim_lic_ok = $lic_valid
            && $lic_info
            && $lic_info->status === 'active'
            && ! empty( $lic_info->domain );
        $need_private_key = $dkim_lic_ok && empty( $cfg->dkim_private_key );
        ?>

        <div class="wpswd-card" id="wpswd-dkim-card">
            <h2>&#x1F510; Signature DKIM
                <?php
                echo $dkim_lic_ok
                    ? '<span class="wpswd-on" style="font-size:12px">' . esc_html( $lang->t('lic_active_badge') ) . '</span>'
                    : '<span class="wpswd-off" style="font-size:12px">' . esc_html( $lang->t('lic_required_badge') ) . '</span>';
                ?>
            </h2>

            <?php if ( ! $dkim_lic_ok ): ?>
            <div class="wpswd-info" style="margin-bottom:16px">
                <?php echo esc_html( $lang->t('dkim_locked_info_full') ); ?>
            </div>

            <?php elseif ( $just_activated && $need_private_key ): ?>

            <?php elseif ( $need_private_key ): ?>
            <div style="background:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;padding:16px 18px;margin-bottom:16px">
                <p style="margin:0 0 8px;font-weight:700;color:#856404"><?php echo esc_html( $lang->t('missing_key_title') ); ?></p>
                <p style="margin:0 0 10px;color:#6d5102;font-size:.88rem;line-height:1.6">
                    <?php echo esc_html( $lang->t('missing_key_body') ); ?>
                </p>
                <p style="margin:0;color:#6d5102;font-size:.82rem;line-height:1.6;padding-top:8px;border-top:1px solid #f0d090">
                    <?php echo esc_html( $lang->t('missing_key_privacy') ); ?>
                </p>
            </div>

            <?php else: ?>
            <div class="wpswd-info">
                <?php echo esc_html( $lang->t('dkim_active_info_full') ); ?>
            </div>
            <?php endif; ?>

            <?php /* ── Domain et Sélecteur : TOUJOURS accessibles, avant et après activation ── */ ?>
            <div id="wpswd-dkim-fields">

                <div class="wpswd-row" style="margin-top:16px">
                    <label for="dkim_domain"><?php echo esc_html( $lang->t('dkim_domain_label') ); ?></label>
                    <div>
                        <input type="text" id="dkim_domain" name="dkim_domain"
                               value="<?php echo esc_attr( $cfg->dkim_domain ); ?>"
                               placeholder="<?php echo esc_attr( $site_domain ); ?>"
                               <?php echo $dkim_lic_ok ? 'readonly style="background:#f9f9f9;color:#555;cursor:not-allowed"' : ''; ?>>
                        <?php if ( $dkim_lic_ok ): ?>
                        <p class="wpswd-desc" style="color:#2e7d32"><?php echo esc_html( $lang->t('dkim_domain_auto_desc') ); ?></p>
                        <p class="wpswd-desc" style="color:#e65100;margin-top:5px">
                            &#x1F512; <?php echo $lang->get() === 'en'
                                ? '<strong>Domain locked</strong> — to change it, deactivate your license first. Then you can reactivate with the new domain.'
                                : '<strong>Domaine verrouillé</strong> — pour le modifier, désactivez d\'abord votre licence. Puis vous pourrez réactiver avec le nouveau domaine.'; ?>
                        </p>
                        <?php elseif ( ! empty($cfg->dkim_domain) ): ?>
                        <p class="wpswd-desc" style="color:#2e7d32"><?php echo esc_html( $lang->t('dkim_domain_auto_desc') ); ?></p>
                        <?php else: ?>
                        <p class="wpswd-desc"><?php echo esc_html( $lang->t('dkim_domain_manual_desc') ); ?></p>
                        <?php endif; ?>
                        <?php if ( ! $dkim_lic_ok ): ?>
                        <p class="wpswd-desc" style="color:#0073aa;margin-top:4px">&#x2139;&#xFE0F; <?php echo $lang->get() === 'en' ? 'You can set your domain here <strong>before</strong> activating your license.' : 'Vous pouvez définir votre domaine ici <strong>avant</strong> d\'activer votre licence.'; ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="wpswd-row">
                    <label for="dkim_selector"><?php echo esc_html( $lang->t('dkim_sel_label') ); ?></label>
                    <div>
                        <input type="text" id="dkim_selector" name="dkim_selector"
                               value="<?php echo esc_attr( $cfg->dkim_selector ); ?>" placeholder="default"
                               style="width:200px<?php echo $dkim_lic_ok ? ';background:#f9f9f9;color:#555;cursor:not-allowed' : ''; ?>"
                               <?php echo $dkim_lic_ok ? 'readonly' : ''; ?>>
                        <?php if ( $dkim_lic_ok ): ?>
                        <p class="wpswd-desc" style="color:#2e7d32">
                            <?php echo esc_html( $lang->t('dkim_sel_auto_desc') ); ?> <code><?php echo esc_html($cfg->dkim_selector); ?>._domainkey.<?php echo esc_html($cfg->dkim_domain ?: $site_domain); ?></code>
                        </p>
                        <p class="wpswd-desc" style="color:#e65100;margin-top:3px">&#x1F512; <?php echo $lang->get() === 'en' ? '<strong>Selector locked</strong> — deactivate your license to change it.' : '<strong>Sélecteur verrouillé</strong> — désactivez votre licence pour le modifier.'; ?></p>
                        <?php elseif ( !empty($cfg->dkim_selector) ): ?>
                        <p class="wpswd-desc" style="color:#2e7d32">
                            <?php echo esc_html( $lang->t('dkim_sel_auto_desc') ); ?> <code><?php echo esc_html($cfg->dkim_selector); ?>._domainkey.<?php echo esc_html($cfg->dkim_domain ?: $site_domain); ?></code>
                        </p>
                        <?php else: ?>
                        <p class="wpswd-desc"><?php echo esc_html( $lang->t('dkim_sel_manual_desc') ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

            </div><?php /* fin #wpswd-dkim-fields — domain/selector toujours actifs */ ?>

            <?php /* ── Clé Privée : verrouillée jusqu'à activation de la licence ── */ ?>
            <div id="wpswd-dkim-key-section" class="<?php echo $dkim_lic_ok ? '' : 'wpswd-dkim-locked'; ?>">
                <div class="wpswd-row">
                    <label for="dkim_private_key">
                        <?php echo esc_html( $lang->t('dkim_pub_key_lbl') ); ?>
                        <?php if ( $dkim_lic_ok && $need_private_key ): ?>
                        <span style="color:#c62828;font-size:11px;display:block;font-weight:normal;margin-top:3px"><?php echo esc_html( $lang->t('dkim_key_needed_star') ); ?></span>
                        <?php endif; ?>
                    </label>
                    <div>
                        <?php
                        $key_is_set = ! empty( $cfg->dkim_private_key );
                        $placeholder_text = $key_is_set
                            ? ($lang->get() === 'en'
                                ? '🔒 Key saved and encrypted — leave blank to keep it, or paste a new key from cPanel to replace it.'
                                : '🔒 Clé enregistrée et chiffrée — laissez vide pour la conserver, ou collez une nouvelle clé depuis cPanel pour la remplacer.')
                            : '-----BEGIN RSA PRIVATE KEY-----\n(Copiez ici TOUTE la cle privee depuis cPanel -> Email Deliverability -> DKIM -> View -> Private Key)\n-----END RSA PRIVATE KEY-----';
                        ?>
                        <textarea id="dkim_private_key" name="dkim_private_key" rows="9"
                                  placeholder="<?php echo esc_attr( $placeholder_text ); ?>"
                                  <?php echo $dkim_lic_ok ? '' : 'disabled'; ?>
                                  <?php echo ($dkim_lic_ok && $need_private_key) ? 'style="border:2px solid #1a56ff;box-shadow:0 0 0 3px rgba(26,86,255,.15);border-radius:4px;"' : ''; ?>
                        ><?php echo esc_textarea(''); ?></textarea>

                        <?php if ( !empty($cfg->dkim_private_key) ): ?>
                            <div style="background:#e8f5e9;border-left:4px solid #43a047;border-radius:4px;padding:12px 16px;margin:8px 0;font-size:13px">
                                🔒 <?php echo esc_html( $lang->t('dkim_key_encrypted_note') ); ?><br>
                                <span style="font-size:12px;color:#2e7d32;margin-top:6px;display:block;line-height:1.7">
                                    <?php echo $lang->get() === 'en'
                                        ? 'For security, the key is encrypted in your database and <strong>masked here</strong>. Leave the field blank to keep the current key.'
                                        : 'Pour la sécurité, la clé est chiffrée dans votre base de données et <strong>masquée ici</strong>. Laissez le champ vide pour conserver la clé actuelle.';
                                    ?>
                                </span>
                                <span style="font-size:12px;color:#1b5e20;margin-top:8px;display:block;background:#fff;border:1px solid #a5d6a7;border-radius:4px;padding:8px 12px;line-height:1.8">
                                    <?php echo $lang->get() === 'en'
                                        ? '<strong>To replace the key:</strong> go to your hosting control panel → <em>cPanel → Email Deliverability → your domain → DKIM → View → Private Key</em>. Copy the <strong>entire key</strong> including the header and footer lines, then paste it in the field above:<br><code style="font-size:11px;background:#f1f8e9;padding:2px 6px;border-radius:3px">-----BEGIN RSA PRIVATE KEY-----<br>... (key content) ...<br>-----END RSA PRIVATE KEY-----</code>'
                                        : '<strong>Pour remplacer la clé :</strong> rendez-vous dans votre hébergeur → <em>cPanel → Email Deliverability → votre domaine → DKIM → View → Private Key</em>. Copiez la <strong>clé complète</strong> en incluant les lignes de début et de fin, puis collez-la dans le champ ci-dessus :<br><code style="font-size:11px;background:#f1f8e9;padding:2px 6px;border-radius:3px">-----BEGIN RSA PRIVATE KEY-----<br>... (contenu de la clé) ...<br>-----END RSA PRIVATE KEY-----</code>';
                                    ?>
                                </span>
                            </div>
                        <?php elseif ( $dkim_lic_ok ): ?>
                            <p class="wpswd-desc" style="color:#c62828"><?php echo esc_html( $lang->t('dkim_key_missing_msg') ); ?></p>
                            <div style="background:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;padding:16px 18px;margin:10px 0;font-size:13px;line-height:1.7">
                                <strong style="display:block;margin-bottom:10px;color:#6d5102;font-size:.95rem">
                                    &#x1F4CD; Comment obtenir votre cle privee DKIM dans cPanel :
                                </strong>
                                <ol style="margin:0 0 12px 0;padding-left:20px;color:#5a4200">
                                    <li><?php echo esc_html( $lang->t('dkim_cpanel_step1') ); ?></li>
                                    <li>Dans la section <strong>Email</strong>, cliquez sur <strong>Email Deliverability</strong></li>
                                    <li><?php echo esc_html( $lang->t('dkim_cpanel_step3_pfx') ); ?> <strong><?php echo esc_html( $cfg->dkim_domain ?: $site_domain ); ?></strong> <?php echo esc_html( $lang->t('dkim_cpanel_step3_sfx') ); ?></li>
                                    <li><?php echo esc_html( $lang->t('dkim_cpanel_step4') ); ?></li>
                                    <li><?php echo esc_html( $lang->t('dkim_cpanel_step5') ); ?></li>
                                    <li><?php echo esc_html( $lang->t('dkim_cpanel_step6') ); ?><br>
                                        <code style="background:#fff3cd;padding:2px 6px;border-radius:3px">-----BEGIN RSA PRIVATE KEY-----</code><br>
                                        <?php echo esc_html( $lang->t('dkim_cpanel_step7') ); ?><br>
                                        <code style="background:#fff3cd;padding:2px 6px;border-radius:3px">-----END RSA PRIVATE KEY-----</code>
                                    </li>
                                    <li><?php echo esc_html( $lang->t('dkim_cpanel_step8') ); ?></li>
                                    <li><?php echo esc_html( $lang->t('dkim_cpanel_step9') ); ?></li>
                                </ol>
                                <div style="background:#fff;border:1px solid #fce8a0;border-radius:4px;padding:10px 12px;font-size:.82rem;color:#6d5102">
                                    <strong><?php echo esc_html( $lang->t('why_not_auto_title') ); ?></strong><br>
                                    <?php echo esc_html( $lang->t('why_not_auto_text') ); ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="wpswd-desc" style="color:#999"><?php echo esc_html( $lang->t('dkim_key_locked_msg') ); ?></p>
                        <?php endif; ?>

                        <?php if ( $dkim_lic_ok && !$just_activated ): ?>
                        <div style="background:#f0f7ff;border-left:4px solid #2196F3;padding:12px 16px;border-radius:2px;margin:10px 0;font-size:13px;line-height:1.6">
                            <strong style="display:block;margin-bottom:6px"><?php echo esc_html( $lang->t('why_not_auto_title') ); ?></strong>
                            <?php echo esc_html( $lang->t('why_not_auto_text') ); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div><?php /* fin #wpswd-dkim-key-section */ ?>

            <?php if ( ! $dkim_lic_ok ): ?>
            <input type="hidden" name="dkim_private_key" value="<?php echo esc_attr( $cfg->dkim_private_key ); ?>">
            <div style="text-align:center;margin-top:16px">
                <a href="https://smtp-dkim.com" target="_blank" class="wpswd-buy-btn"><?php echo esc_html( $lang->t('dkim_buy_btn') ); ?></a>
            </div>
            <?php endif; ?>
        </div>

        <p>
            <button type="submit" name="smtpdkimc_save" class="button button-primary button-hero"><?php echo esc_html( $lang->t('save_btn') ); ?></button>
        </p>
        </form>

        <div class="wpswd-card">
            <h2><?php echo esc_html( $lang->t('lic_card_title_full') ); ?></h2>

            <?php if ($lic_valid && $lic_info): ?>
            <div class="wpswd-lic-active">
    <strong><?php echo esc_html( $lang->t('lic_active_label_full') ); ?></strong><br>
    <?php echo esc_html( $lang->t('lic_key_lbl') ); ?> <code style="word-break:break-all"><?php echo esc_html( $lic_key_val ); ?></code><br>
    <?php if ( ! empty($lic_info->customer_name) ): ?>
    <?php echo esc_html( $lang->t('lic_holder_name_lbl') ); ?> <?php echo esc_html( $lic_info->customer_name ); ?><br>
    <?php endif; ?>
    <?php if ( ! empty($lic_info->customer_email) ): ?>
    <?php echo esc_html( $lang->t('lic_holder_email_lbl') ); ?> <?php echo esc_html( $lic_info->customer_email ); ?><br>
    <?php endif; ?>
    <?php echo esc_html( $lang->t('lic_domain_lbl') ); ?> <?php echo esc_html( $lic_info->domain ?? '-' ); ?><br>
    <?php
    $plan_map = [
        'single'    => $lang->get()==='en' ? '1 Site'          : '1 Site',
        'multi3'    => $lang->get()==='en' ? '3 Sites'         : '3 Sites',
        'multi5'    => $lang->get()==='en' ? '5 Sites'         : '5 Sites',
        'unlimited' => $lang->get()==='en' ? 'Unlimited Sites' : 'Sites illimités',
        'lifetime'  => $lang->get()==='en' ? 'Lifetime'        : 'À vie',
    ];
    $plan_raw   = strtolower( str_replace('sdkm-', '', $lic_info->plan_type ?? '') );
    $plan_label = $plan_map[$plan_raw] ?? ucfirst($plan_raw ?: ( $lang->get()==='en' ? 'Standard' : 'Standard' ));
    $exp_ts_page = ( ! empty($lic_info->expires_at) && strtotime($lic_info->expires_at) > 0 )
        ? strtotime($lic_info->expires_at) : null;
    $is_lft_page = ! $exp_ts_page
        || in_array( strtolower($lic_info->plan_type ?? ''), ['sdkm-lifetime', 'lifetime'], true );
    ?>
    <?php echo esc_html( $lang->t('lic_plan_lbl') ); ?> <?php echo esc_html( $plan_label ); ?><br>
    <?php if ( $is_lft_page ): ?>
    <?php echo esc_html( $lang->t('lic_exp_lbl') ); ?> &#x267E;&#xFE0F; <strong style="color:#2e7d32"><?php echo $lang->get() === 'en' ? 'Lifetime — never expires' : 'À vie — n\'expire jamais'; ?></strong><br>
    <?php elseif ( $exp_ts_page ): ?>
    <?php echo esc_html( $lang->t('lic_exp_lbl') ); ?> <?php echo esc_html(gmdate('d/m/Y', $exp_ts_page)); ?><br>
    <?php endif; ?>
    <?php echo esc_html( $lang->t('lic_check_lbl') ); ?> <?php echo esc_html( $lic_info->last_check ?? '-' ); ?>
</div>
            <form method="post">
                <?php wp_nonce_field('smtpdkimc_license_action','smtpdkimc_lic_nonce'); ?>
                <button type="submit" name="smtpdkimc_deactivate_license" class="button button-secondary"
                        onclick="return confirm('<?php echo esc_js( $lang->t('lic_deactivate_cfm') ); ?>')"><?php echo esc_html( $lang->t('lic_deactivate') ); ?></button>
            </form>
            <p style="font-size:13px;color:#666;background:#f9f9f9;border-left:3px solid #ccc;padding:10px 14px;border-radius:4px;margin-top:10px">
                <?php echo esc_html( $lang->t('lic_deactivate_explain') ); ?>
            </p>

            <?php else: ?>
            <div class="wpswd-lic-inactive">
                <?php
                if ( $lic_info && $lic_info->status === 'expired' ):
                    echo esc_html( $lang->t('lic_expired_msg') );
                elseif ( $lic_info && $lic_info->status === 'invalid' ):
                    echo esc_html( $lang->t('lic_invalid_msg') );
                elseif ( $lic_info && ! empty($lic_info->license_key) ):
                    echo esc_html( $lang->t('lic_deactivated_msg') );
                else:
                    echo esc_html( $lang->t('lic_none_msg') );
                endif;
                ?>
            </div>

            <?php if ( $lic_info && in_array($lic_info->status, ['expired','invalid']) ): ?>
            <div class="wpswd-warn" style="margin-bottom:12px">
                <?php echo esc_html( $lang->t('lic_expire_explain_full') ); ?>
            </div>
            <?php endif; ?>

            <p><?php echo esc_html( $lang->t('lic_buy_link_pfx') ); ?> <a href="https://smtp-dkim.com" target="_blank"><strong>smtp-dkim.com</strong></a> <?php echo esc_html( $lang->t('lic_buy_then') ); ?></p>

            <form method="post" id="wpswd-lic-form">
                <?php wp_nonce_field('smtpdkimc_license_action','smtpdkimc_lic_nonce'); ?>
                <div style="display:flex;gap:10px;align-items:center;max-width:540px">
                    <input type="text" id="wpswd-lic-key-input" name="license_key" value="<?php echo esc_attr( $lic_key_val ); ?>"
                           placeholder="SDKM-XXXXX-XXXXX-XXXXX-XXXXX" style="flex:1;font-family:monospace">
                    <button type="button" id="wpswd-lic-activate-btn" class="button button-primary">&#x2714; Activer</button>
                </div>
                <p class="wpswd-desc" style="margin-top:8px">
                    <?php echo esc_html( $lang->t('lic_activate_desc') ); ?>
                </p>
                <input type="hidden" name="smtpdkimc_activate_license" value="1">
            </form>
            <?php endif; ?>
        </div>

        <div class="wpswd-card">
            <h2><?php echo esc_html( $lang->t('test_card_title') ); ?></h2>

            <?php
            $lic_row_test  = $this->license->get_info();
            $holder_email  = ( $lic_row_test && ! empty($lic_row_test->customer_email) )
                ? $lic_row_test->customer_email
                : get_option('admin_email', '—');
            ?>

            <div style="background:#e8f4fd;border-left:4px solid #2196F3;padding:10px 14px;border-radius:4px;margin-bottom:14px;font-size:13px;color:#0d47a1">
                <?php echo esc_html( $lang->t('test_holder_explain') ); ?>
            </div>
            <p style="font-size:13px;margin-bottom:10px">
                <?php echo esc_html( $lang->t('test_send_to_lbl') ); ?> <strong><?php echo esc_html( $holder_email ); ?></strong>
            </p>
            <form method="post">
                <?php wp_nonce_field('smtpdkimc_test_email','smtpdkimc_test_nonce'); ?>
                <button type="submit" name="smtpdkimc_test" class="button button-secondary"><?php echo esc_html( $lang->t('test_btn') ); ?></button>
            </form>

        </div>

        <div class="wpswd-card">
            <h2><?php echo esc_html( $lang->t('test_external_title') ); ?></h2>
            <div style="background:#f9f9f9;border-left:4px solid #aaa;padding:10px 14px;border-radius:4px;margin-bottom:14px;font-size:13px;color:#444">
                <?php echo esc_html( $lang->t('test_external_explain') ); ?>
            </div>
            <form method="post" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <?php wp_nonce_field('smtpdkimc_test_external','smtpdkimc_test_ext_nonce'); ?>
                <input type="email" name="smtpdkimc_test_external_email" required
                       placeholder="destinataire@exemple.com"
                       value="<?php echo isset( $_POST['smtpdkimc_test_external_email'] ) ? esc_attr( sanitize_email( wp_unslash( $_POST['smtpdkimc_test_external_email'] ) ) ) : ''; ?>"
                       style="width:260px">
                <button type="submit" name="smtpdkimc_test_external" class="button button-secondary">
                    <?php echo esc_html( $lang->t('test_external_btn') ); ?>
                </button>
            </form>
            <p style="font-size:12px;color:#888;margin-top:8px">
                <?php echo esc_html( $lang->t('test_external_security') ); ?>
            </p>
        </div>

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
                <tr><?php ?><th><?php echo esc_html( $lang->t('sum_lic') ); ?></th><td>
                    <?php
                    if ( $lic_valid && $lic_info && $lic_info->status === 'active' ) {
                        echo '<span class="wpswd-on">' . esc_html( $lang->t('sum_lic_active') ) . '</span>';
                    } elseif ( $lic_info && $lic_info->status === 'expired' ) {
                        echo '<span class="wpswd-off">' . esc_html( $lang->t('sum_lic_expired') ) . ' &mdash; <a href="https://smtp-dkim.com" target="_blank">' . esc_html( $lang->t('lic_renew') ) . '</a></span>';
                    } elseif ( $lic_info && $lic_info->status === 'invalid' ) {
                        echo '<span class="wpswd-off">&#x2717; ' . esc_html( $lang->t('sum_lic_invalid') ) . ' &mdash; <a href="https://smtp-dkim.com" target="_blank">' . esc_html( $lang->t('sum_check_lnk') ) . '</a></span>';
                    } else {
                        echo '<span class="wpswd-off">&#x2717; &mdash; <a href="https://smtp-dkim.com" target="_blank">' . esc_html( $lang->t('sum_buy_lnk') ) . '</a></span>';
                    }
                    ?>
                  </td></tr>
                <tr><?php ?><th><?php echo esc_html( $lang->t('sum_dkim') ); ?></th><td>
                    <?php if ($lic_valid && !empty($cfg->dkim_private_key)): ?>
                        <span class="wpswd-on"><?php echo esc_html( $lang->t('sum_dkim_active_pfx') ); ?> <?php echo esc_html( $cfg->dkim_selector ); ?>._domainkey.<?php echo esc_html( $cfg->dkim_domain ?: $site_domain ); ?></span>
                    <?php elseif (!$lic_valid): ?>
                        <span class="wpswd-off"><?php echo esc_html( $lang->t('sum_dkim_req') ); ?></span>
                    <?php else: ?>
                        <span class="wpswd-off"><?php echo esc_html( $lang->t('sum_dkim_nokey') ); ?></span>
                    <?php endif; ?>
                  </td></tr>
               <tr>
    <th><?php echo esc_html( $lang->t('sum_spf') ); ?></th>
    <td>
        <?php 
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $dns_badge( $dns_status['spf']['status'] ?? 'missing' ); 
        ?>
    </td>
    <td>
        <?php 
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $dns_badge( $dns_status['dkim']['status'] ?? 'missing' ); 
        ?>
    </td>
    <td>
        <?php 
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $dns_badge( $dns_status['dmarc']['status'] ?? 'missing' ); 
        ?>
        <?php if ( ($dns_status['spf']['status'] ?? '') !== 'ok' ): ?>
            &nbsp;<a href="#wpswd-dns-card" style="font-size:11px"><?php echo esc_html( $lang->t('sum_fix_link') ); ?></a>
        <?php endif; ?>
    </td>
</tr><tr>
    <th><?php echo esc_html( $lang->t('sum_dkim_dns') ); ?></th>
    <td>
        <?php 
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $dns_badge( $dns_status['dkim']['status'] ?? 'missing' ); 
        ?>
        <?php if ( ($dns_status['dkim']['status'] ?? '') !== 'ok' ): ?>
            &nbsp;<a href="#wpswd-dns-card" style="font-size:11px"><?php echo esc_html( $lang->t('sum_fix_link') ); ?></a>
        <?php endif; ?>
    </td>
</tr>
               <tr>
    <th><?php echo esc_html( $lang->t('sum_dmarc') ); ?></th>
    <td>
        <?php 
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $dns_badge( $dns_status['dmarc']['status'] ?? 'missing' ); 
        ?>
        <?php if ( ($dns_status['dmarc']['status'] ?? '') !== 'ok' ): ?>
            &nbsp;<a href="#wpswd-dns-card" style="font-size:11px"><?php echo esc_html( $lang->t('sum_fix_link') ); ?></a>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <th><?php echo esc_html( $lang->t('sum_debug') ); ?></th>
    <td>
        <?php echo esc_html( $lang->t('sum_level') ); ?> <?php echo (int) $cfg->smtp_debug_level; ?>
    </td>
</tr>
</tbody>
</table>
<p style="margin-top:10px;font-size:12px;color:#888">
    <?php echo esc_html( $lang->t('sum_dns_note_full') ); ?>
    <a href="#wpswd-dns-card"><?php echo esc_html( $lang->t('sum_dns_scan') ); ?></a>
</p>
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
        <div id="wpswd-lic-overlay">
            <div id="wpswd-lic-overlay-box">
                <h3><?php echo esc_html( $lang->t('overlay_title') ); ?></h3>
                <p id="wpswd-lic-overlay-msg"><?php echo esc_html( $lang->t('overlay_msg1') ); ?></p>
                <div id="wpswd-lic-overlay-progress"><div id="wpswd-lic-overlay-bar"></div></div>
                <p id="wpswd-lic-overlay-step" style="font-size:12px;color:#888"><?php echo esc_html( $lang->t('overlay_step1') ); ?></p>
            </div>
        </div>

        <script>
        jQuery(function($){

            <?php if ( $just_activated && $need_private_key ): ?>
            setTimeout(function(){
                var $card = $('#wpswd-dkim-card');
                var $key  = $('#dkim_private_key');
                if($card.length){
                    $('html,body').animate({scrollTop: $card.offset().top - 80}, 600, function(){
                        $key.css({
                            'border'     : '2px solid #1a56ff',
                            'box-shadow' : '0 0 0 4px rgba(26,86,255,.2)',
                            'transition' : 'box-shadow .3s'
                        });
                        $key.focus();
                        setTimeout(function(){
                            $key.css({'box-shadow': 'none'});
                        }, 4000);
                    });
                }
            }, 400);
            <?php endif; ?>

            function statusIcon(s){
                if(s==='ok')      return '<span style="color:#2e7d32;font-size:1.1em">&#x2705;</span>';
                if(s==='warning') return '<span style="color:#e65100;font-size:1.1em">&#x26A0;&#xFE0F;</span>';
                return '<span style="color:#c62828;font-size:1.1em">&#x274C;</span>';
            }
            function statusBadge(s,label){
                var cls = s==='ok'?'ok':s==='warning'?'warn':'bad';
                var ico = s==='ok'?'&#x2705; ':'&#x274C; ';
                return '<span class="wpswd-dns-badge wpswd-dns-badge-'+cls+'">'+ico+label+'</span>';
            }
            function buildFix(fix){
                if(!fix) return '';
                var isOptional = fix.optional === true;
                var headerStyle = isOptional
                    ? 'background:#f0f8e8;border-left:4px solid #8bc34a'
                    : '';
                var headerIcon  = isOptional ? '<?php echo esc_js( $lang->t("dns_fix_optional") ); ?>' : '<?php echo esc_js( $lang->t("dns_fix_how") ); ?>';
                return '<div class="wpswd-dns-fix" style="'+headerStyle+'"><strong>'+headerIcon+' :</strong>'+
                    '<table>'+
                    '<tr><td><?php echo esc_js( $lang->t("dns_fix_type") ); ?></td><td><code>'+fix.type+'</code></td></tr>'+
                    '<tr><td><?php echo esc_js( $lang->t("dns_fix_name") ); ?></td><td><code>'+fix.name+'</code></td></tr>'+
                    '<tr><td><?php echo esc_js( $lang->t("dns_fix_value") ); ?></td><td><code>'+fix.value+'</code></td></tr>'+
                    '</table>'+
                    '<p style="margin:8px 0 0;color:#555;font-size:12px">&#x2139;&#xFE0F; '+fix.note+'</p>'+
                    '</div>';
            }
            function buildRow(label,data,extra){
                var issues=(data.issues||[]).map(function(i){
                    return '<p class="wpswd-dns-issue">&#x274C; '+i+'</p>';
                }).join('');
                var warnings=(data.warnings||[]).map(function(w){
                    return '<p style="color:#856404;font-size:13px;margin:4px 0">'+w+'</p>';
                }).join('');
                var record='';
                if(data.record){
                    var isLong = data.record.length > 100;
                    var style  = isLong
                        ? 'max-height:120px;overflow-y:auto;'
                        : '';
                    record = '<div class="wpswd-dns-record" style="'+style+'">'+data.record+'</div>';
                    if(isLong) record += '<p style="font-size:11px;color:#888;margin:2px 0 6px"><?php echo esc_js( $lang->t("dkim_scroll_note") ); ?></p>';
                }
                return '<div class="wpswd-dns-row">'+
                    '<div class="wpswd-dns-label">'+statusIcon(data.status)+' '+label+'</div>'+
                    '<div>'+record+(extra||'')+issues+warnings+buildFix(data.fix)+'</div>'+
                    '</div>';
            }
            function renderDNS(d){
                var allOk = d.spf.status==='ok' && d.dkim.status==='ok' && d.dmarc.status==='ok';
                var html = '<h3 style="margin:0 0 12px"><?php echo esc_js( $lang->t("dns_results_for") ); ?> <strong>'+d.domain+'</strong></h3>';
                html += '<div class="wpswd-dns-summary">';
                html += '<span>SPF '+statusBadge(d.spf.status,'SPF')+'</span>';
                html += '<span><?php echo esc_js( $lang->t("dkim_pub_key_lbl") ); ?> '+statusBadge(d.dkim.status,'<?php echo esc_js( $lang->t("dkim_pub_key_lbl") ); ?>')+'</span>';
                html += '<span>DMARC '+statusBadge(d.dmarc.status,'DMARC')+'</span>';
                html += '</div>';
                if(allOk) html += '<div class="wpswd-lic-active" style="margin-bottom:16px">&#x1F389; <strong><?php echo esc_js( $lang->t("dns_all_ok") ); ?></strong></div>';
                var spfExtra = d.spf.strength==='softfail'?'<p style="font-size:12px;color:#888;margin:4px 0"><?php echo esc_js( $lang->t("spf_softfail_lbl") ); ?></p>':
                               d.spf.strength==='strict'  ?'<p style="font-size:12px;color:#2e7d32;margin:4px 0"><?php echo esc_js( $lang->t("spf_strict_lbl") ); ?></p>':'';
                html += buildRow('SPF',d.spf,spfExtra);
                var dkimExtra = '';
                dkimExtra += '<div style="background:#e8f4fd;border-left:3px solid #2196F3;padding:7px 10px;border-radius:3px;margin:6px 0;font-size:12px;color:#0d47a1">';
                dkimExtra += '<?php echo esc_js( $lang->t("dns_pub_key_note") ); ?>';
                dkimExtra += '</div>';
                if(d.dkim.key_bits) dkimExtra += '<p style="font-size:12px;color:'+(d.dkim.key_bits>=2048?'#2e7d32':'#c62828')+';margin:4px 0"><?php echo esc_js( $lang->t("dkim_key_size_lbl") ); ?> '+d.dkim.key_bits+' <?php echo esc_js( $lang->t("dns_rsa_bits") ); ?>'+(d.dkim.key_bits>=2048?' <?php echo esc_js( $lang->t("dkim_optimal_lbl") ); ?>':' <?php echo esc_js( $lang->t("dkim_min_bits_lbl") ); ?>')+'</p>';
                if(d.dkim.found_alt) dkimExtra += '<p style="font-size:12px;color:#e65100;margin:4px 0">&#171;'+d.dkim.found_alt+'&#187; <?php echo esc_js( $lang->t("dns_alt_sel") ); ?></p>';
                html += buildRow('<?php echo esc_js( $lang->t("dkim_pub_key_lbl") ); ?>',d.dkim,dkimExtra);
                var dmarcExtra = d.dmarc.policy?'<p style="font-size:12px;color:'+(d.dmarc.policy==='none'?'#c62828':'#2e7d32')+';margin:4px 0"><?php echo esc_js( $lang->t("dmarc_policy_lbl") ); ?> p='+d.dmarc.policy+(d.dmarc.rua?' &middot; '+d.dmarc.rua:' &middot; <?php echo esc_js( $lang->t("dmarc_no_rua_warn") ); ?>')+'</p>':'';
                html += buildRow('DMARC',d.dmarc,dmarcExtra);
                return html;
            }

            $('#wpswd-dns-scan').on('click',function(){
                var domain   = $('#wpswd-dns-domain').val();
                var selector = $('#wpswd-dns-selector').val();
                var $res = $('#wpswd-dns-results');
                $res.show().html('<div class="wpswd-dns-loading"><span class="wpswd-dns-spinner"></span><?php echo esc_js( $lang->get()==="en" ? "Querying DNS via Cloudflare DoH…" : "Interrogation DNS via Cloudflare DoH…" ); ?></div>');
                $.post(ajaxurl,{
                    action:'smtpdkimc_dns_check',
                    nonce:'<?php echo esc_js( wp_create_nonce( "smtpdkimc_dns_check" ) ); ?>',
                    domain:domain, selector:selector
                },function(resp){
                    if(!resp.success){ $res.html('<div class="wpswd-warn"><?php echo esc_js( $lang->get()==="en" ? "Error:" : "Erreur :" ); ?> '+resp.data+'</div>'); return; }
                    $res.html(renderDNS(resp.data));
                }).fail(function(){ $res.html('<div class="wpswd-warn"><?php echo esc_js( $lang->get()==="en" ? "Network error." : "Erreur réseau." ); ?></div>'); });
            });

            $('#wpswd-lic-activate-btn').on('click', function(){
                var licKey = $.trim($('#wpswd-lic-key-input').val());
                if(!licKey){ alert('<?php echo esc_js( $lang->t("overlay_alert_empty") ); ?>'); return; }

                var $overlay = $('#wpswd-lic-overlay');
                var $bar     = $('#wpswd-lic-overlay-bar');
                var $msg     = $('#wpswd-lic-overlay-msg');
                var $step    = $('#wpswd-lic-overlay-step');
                $overlay.addClass('active');
                $bar.css('width','15%');
                $msg.text('<?php echo esc_js( $lang->t("overlay_msg1") ); ?>');
                $step.text('<?php echo esc_js( $lang->t("overlay_step1") ); ?>');

                setTimeout(function(){
                    $bar.css('width','45%');
                    $msg.text('<?php echo esc_js( $lang->t("overlay_msg2") ); ?>');
                    $step.text('<?php echo esc_js( $lang->t("overlay_step2") ); ?>');

                    $.post(ajaxurl,{
                        action:'smtpdkimc_dns_autodetect',
                        nonce:'<?php echo esc_js( wp_create_nonce( "smtpdkimc_dns_autodetect" ) ); ?>'
                    },function(resp){
                        $bar.css('width','80%');
                        $step.text('<?php echo esc_js( $lang->t("overlay_step3") ); ?>');

                        if(resp.success){
                            var data = resp.data;

                            // N'écraser le domain que si le champ est vide — respecter le choix manuel
                            if(data.domain && !$.trim($('#dkim_domain').val())){
                                $('#dkim_domain').val(data.domain);
                                $('input[name="dkim_domain"]').val(data.domain);
                            }

                            // N'écraser le sélecteur que si le champ est vide
                            if(data.selector && !$.trim($('#dkim_selector').val())){
                                $('#dkim_selector').val(data.selector);
                                $('input[name="dkim_selector"]').val(data.selector);
                                $('#wpswd-dns-selector').val(data.selector);
                            }

                            $msg.text(data.selector_found
                                ? '<?php echo esc_js( $lang->t("overlay_sel_found") ); ?> "' + data.selector + '"'
                                : '<?php echo esc_js( $lang->t("overlay_sel_manual") ); ?>');
                        } else {
                            $msg.text('<?php echo esc_js( $lang->t("overlay_sel_manual") ); ?>');
                        }

                        setTimeout(function(){
                            $bar.css('width','100%');
                            $msg.text('<?php echo esc_js( $lang->t("overlay_msg4") ); ?>');
                            $('#wpswd-lic-form').submit();
                        }, 600);
                    }).fail(function(){
                        setTimeout(function(){
                            $bar.css('width','100%');
                            $('#wpswd-lic-form').submit();
                        }, 400);
                    });
                }, 800);
            });
        });
        </script>
        <?php
    }
}