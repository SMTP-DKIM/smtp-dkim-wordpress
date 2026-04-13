<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Gère la validation de la licence DKIM auprès de smtp-dkim.com.
 */
class smtpdkimc_License {

    /** Helper — retourne un message dans la bonne langue */
    private function msg( string $fr, string $en ): string {
        $uid  = get_current_user_id();
        $lang = get_user_meta( $uid, 'smtpdkimc_lang', true ) ?: 'fr';
        return $lang === 'en' ? $en : $fr;
    }

    /**
     * Durée du cache de validation.
     * 2h au lieu de 24h → synchronisation max 2h après une action côté manager.
     * Le cron tourne toutes les 2h pour forcer le recheck sans attendre une page vue.
     */
    const CACHE_TTL = 2 * HOUR_IN_SECONDS;

    /** Délai minimum entre deux rechecks forcés depuis la page admin (30 min). */
    const ADMIN_RECHECK_INTERVAL = 30 * MINUTE_IN_SECONDS;

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . smtpdkimc_LICENSE_TABLE;
    }

    /** Retourne une date valide ou null — évite de stocker '0000-00-00' en MySQL */
    private function clean_date( ?string $date ): ?string {
        if ( empty($date) || strtotime($date) <= 0 ) return null;
        return $date;
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  API PUBLIQUE
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Retourne true si la licence est active et valide.
     * Utilise le transient comme cache pour limiter les appels API.
     */
    public function is_valid(): bool {
        global $wpdb;

        $table_escaped = esc_sql( $this->table );
        $query = sprintf( "SELECT status, license_key, domain, last_check FROM %s LIMIT 1", $table_escaped );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( $query );
        
        if ( ! $row || empty( $row->license_key ) || $row->status !== 'active' ) {
            delete_transient( 'smtpdkimc_license_valid' );
            return false;
        }
        // Domaine vide = admin a libéré la licence, réactivation requise
        if ( empty( $row->domain ) ) {
            delete_transient( 'smtpdkimc_license_valid' );
            return false;
        }

        // Licence active en DB → utiliser le transient
        $cached = get_transient( 'smtpdkimc_license_valid' );
        if ( $cached !== false ) {
            return (bool) $cached;
        }

        return $this->recheck();
    }

    /**
     * Re-vérifie la licence si le dernier check date de plus de 5 minutes.
     * Appelé au chargement de la page admin du plugin.
     */
    public function maybe_recheck_for_admin(): void {
        global $wpdb;

        $table_escaped = esc_sql( $this->table );
        $query = sprintf( "SELECT license_key, last_check, customer_email FROM %s LIMIT 1", $table_escaped );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( $query );
        
        if ( ! $row || empty( $row->license_key ) ) {
            return;
        }

        $last    = $row->last_check ? strtotime( $row->last_check ) : 0;
        $elapsed = time() - $last;

        $needs_recheck = ( $elapsed > 30 * MINUTE_IN_SECONDS )
                      || empty( $row->customer_email );

        if ( $needs_recheck ) {
            delete_transient( 'smtpdkimc_license_valid' );
            $this->recheck();
        }
    }

    /**
     * Vérifie la signature RSA du serveur : DKIM ne peut pas fonctionner sans elle.
     */
    public function has_valid_activation_sig(): bool {
        global $wpdb;
        
        $table_escaped = esc_sql( $this->table );
        $query = sprintf( "SELECT license_key, domain, activation_sig, activation_expiry FROM %s LIMIT 1", $table_escaped );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( $query );
        
        if ( ! $row || empty( $row->activation_sig ) || empty( $row->activation_expiry ) ) return false;
        if ( (int) $row->activation_expiry < time() ) return false;

        $public_key = $this->get_server_public_key();
        if ( empty( $public_key ) ) return true;

        $message = $row->domain . '|' . $row->license_key . '|' . $row->activation_expiry;
        $sig_raw  = base64_decode( $row->activation_sig );
        return openssl_verify( $message, $sig_raw, $public_key, OPENSSL_ALGO_SHA256 ) === 1;
    }

    /**
     * Récupère et cache 24h la clé publique RSA depuis le serveur de licences.
     */
    private function get_server_public_key(): string {
        $cached = get_transient( 'smtpdkimc_server_public_key' );
        if ( $cached ) return $cached;

        $url      = str_replace( '/wp-json/sdlm/v1/validate', '/wp-json/sdlm/v1/public-key', smtpdkimc_API_URL );
        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );
        if ( is_wp_error( $response ) ) return $cached ?: '';

        $body      = json_decode( wp_remote_retrieve_body( $response ), true );
        $key       = $body['public_key'] ?? '';
        $rotated   = $body['rotated_at']  ?? '';

        if ( $key ) {
            set_transient( 'smtpdkimc_server_public_key',       $key,     DAY_IN_SECONDS );
            set_transient( 'smtpdkimc_server_pub_rotated_at',   $rotated, DAY_IN_SECONDS );
        }
        return $key;
    }

    /**
     * Vide le cache de la clé publique RSA.
     */
    public function clear_rsa_cache(): void {
        delete_transient( 'smtpdkimc_server_public_key' );
        delete_transient( 'smtpdkimc_server_pub_rotated_at' );
    }

    /**
     * Enregistre une nouvelle clé et valide immédiatement auprès de l'API.
     */
    public function activate( string $key, string $domain = '' ): array {
        global $wpdb;

        $key    = sanitize_text_field( trim( $key ) );
        if ( empty( $domain ) ) {
            $domain = wp_parse_url( home_url(), PHP_URL_HOST );
        }

        if ( empty( $key ) ) {
            return [ 'success' => false, 'message' => $this->msg('La clé de licence est vide.', 'License key is empty.') ];
        }

        $result = $this->call_api( $key, $domain, 'activate' );

        if ( ! $result['success'] ) {
            return $result;
        }

        $table_escaped = esc_sql( $this->table );
        $query = sprintf( "SELECT COUNT(*) FROM %s", $table_escaped );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = (int) $wpdb->get_var( $query );
        
 if ( $existing ) {

    // ✅ Mise à jour propre avec l'API WordPress
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->update(
        $this->table,
        [
            'license_key'       => $key,
            'status'            => 'active',
            'domain'            => $domain,
            'customer_email'    => $result['customer_email']    ?? null,
            'customer_name'     => $result['customer_name']     ?? null,
            'activated_at'      => current_time('mysql'),
            'expires_at'        => $this->clean_date($result['expires_at'] ?? ''),
            'plan_type'         => $result['plan_type']         ?? null,
            'last_check'        => current_time('mysql'),
            'activation_sig'    => $result['activation_sig']    ?? null,
            'activation_expiry' => $result['activation_expiry'] ?? 0,
        ],
        [ 'id' => 1 ],
        [
            '%s','%s','%s','%s','%s','%s','%s','%s','%s','%d'
        ],
        [
            '%d'
        ]
    );

} else {

    // ✅ Insertion propre (déjà bon)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->insert(
        $this->table,
        [
            'license_key'       => $key,
            'status'            => 'active',
            'domain'            => $domain,
            'customer_email'    => $result['customer_email']    ?? null,
            'customer_name'     => $result['customer_name']     ?? null,
            'activated_at'      => current_time('mysql'),
            'expires_at'        => $this->clean_date($result['expires_at'] ?? ''),
            'plan_type'         => $result['plan_type']         ?? null,
            'last_check'        => current_time('mysql'),
            'activation_sig'    => $result['activation_sig']    ?? null,
            'activation_expiry' => $result['activation_expiry'] ?? null,
        ],
        [
            '%s','%s','%s','%s','%s','%s','%s','%s','%s','%d'
        ]
    );
}

set_transient( 'smtpdkimc_license_valid', 1, self::CACHE_TTL );

return [
    'success' => true,
    'message' => $this->msg(
        'Licence activée avec succès. La fonctionnalité DKIM est maintenant disponible.',
        'License activated successfully. DKIM signing is now available.'
    )
];
    }

    /**
     * Désactive la licence localement + appel API de désenregistrement.
     */
    public function deactivate_license(): array {
        global $wpdb;

        $table_escaped = esc_sql( $this->table );
        $query = sprintf( "SELECT * FROM %s LIMIT 1", $table_escaped );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( $query );
        $domain = wp_parse_url( home_url(), PHP_URL_HOST );

        if ( $row && $row->license_key ) {
            // Utiliser le domain DKIM sauvegardé, pas home_url() (évite domain_mismatch avec www.)
            $config_table = $wpdb->prefix . smtpdkimc_TABLE;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $cfg_row = $wpdb->get_row( "SELECT dkim_domain FROM {$config_table} LIMIT 1" );
            $domain_for_api = ! empty( $cfg_row->dkim_domain ) ? $cfg_row->dkim_domain : $domain;
            $this->call_api( $row->license_key, $domain_for_api, 'deactivate' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $this->table, [
            'status'            => 'inactive',
            'domain'            => '',
            'last_check'        => current_time('mysql'),
            'activation_sig'    => null,
            'activation_expiry' => null,
        ], [ 'id' => 1 ] );

        delete_transient( 'smtpdkimc_license_valid' );

        return [ 'success' => true, 'message' => $this->msg(
            'Licence désactivée. La fonctionnalité DKIM est verrouillée.',
            'License deactivated. DKIM signing is locked.'
        ) ];
    }

    /**
     * Retourne les infos de la licence stockée en DB.
     */
    public function get_info(): ?object {
        global $wpdb;
        
        $table_escaped = esc_sql( $this->table );
        $query = sprintf( "SELECT * FROM %s LIMIT 1", $table_escaped );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row( $query );
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  INTERNE
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Appelle l'API et met à jour le statut local selon la réponse du serveur.
     */
    private function recheck(): bool {
        global $wpdb;

        $table_escaped = esc_sql( $this->table );
        $query = sprintf( "SELECT * FROM %s LIMIT 1", $table_escaped );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( $query );
        
        if ( ! $row || empty( $row->license_key ) ) {
            set_transient( 'smtpdkimc_license_valid', 0, self::CACHE_TTL );
            return false;
        }

        $domain = wp_parse_url( home_url(), PHP_URL_HOST );

        // Utiliser le domain DKIM sauvegardé manuellement en config (évite le problème www.)
        // Sans ce fix, si dkim_domain = 'saumontario.com' mais home_url = 'www.saumontario.com',
        // le check retourne 'domain_mismatch' et invalide la licence localement.
        $config_table = $wpdb->prefix . smtpdkimc_TABLE;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $cfg_row = $wpdb->get_row( "SELECT dkim_domain FROM {$config_table} LIMIT 1" );
        if ( ! empty( $cfg_row->dkim_domain ) ) {
            $domain = $cfg_row->dkim_domain;
        }

        // Vérifier si la clé RSA du serveur a été régénérée depuis notre dernier cache
        $cached_rotated = get_transient( 'smtpdkimc_server_pub_rotated_at' );
        if ( $cached_rotated ) {
            $pub_url  = str_replace( '/wp-json/sdlm/v1/validate', '/wp-json/sdlm/v1/public-key', smtpdkimc_API_URL );
            $pub_resp = wp_remote_get( $pub_url, [ 'timeout' => 5 ] );
            if ( ! is_wp_error( $pub_resp ) ) {
                $pub_body   = json_decode( wp_remote_retrieve_body( $pub_resp ), true );
                $new_rotated = $pub_body['rotated_at'] ?? '';
                if ( $new_rotated && $new_rotated !== $cached_rotated ) {
                    $this->clear_rsa_cache();
                }
            }
        }

        $result = $this->call_api( $row->license_key, $domain, 'check' );

        $valid      = $result['success'];
        $new_status = $result['status'] ?? ( $valid ? 'active' : 'invalid' );

        set_transient( 'smtpdkimc_license_valid', $valid ? 1 : 0, self::CACHE_TTL );

        // Mettre à jour le statut local
        $update = [
            'last_check' => current_time('mysql'),
            'status'     => $new_status,
        ];
        
        if ( ! empty( $result['expires_at'] ) && strtotime( $result['expires_at'] ) > 0 ) {
            $update['expires_at'] = $result['expires_at'];
        }
        if ( ! empty( $result['customer_email'] ) ) {
            $update['customer_email'] = $result['customer_email'];
        }
        if ( ! empty( $result['customer_name'] ) ) {
            $update['customer_name'] = $result['customer_name'];
        }
        if ( ! empty( $result['plan_type'] ) ) {
            $update['plan_type'] = $result['plan_type'];
        }
        if ( $valid && ! empty( $result['activation_sig'] ) && ! empty( $result['activation_expiry'] ) ) {
            $update['activation_sig']    = $result['activation_sig'];
            $update['activation_expiry'] = $result['activation_expiry'];
        } elseif ( ! $valid ) {
            $update['activation_sig']    = null;
            $update['activation_expiry'] = null;
            if ( in_array( $new_status, [ 'no_domain', 'inactive', 'revoked', 'expired' ], true ) ) {
                $update['domain'] = '';
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $this->table, $update, [ 'license_key' => $row->license_key ] );

        return $valid;
    }

    /**
     * Appelle l'API smtp-dkim.com.
     */
    private function call_api( string $key, string $domain, string $action ): array {
        $timestamp = time();
        $payload   = wp_json_encode( [
            'license_key' => $key,
            'domain'      => $domain,
            'action'      => $action,
            'plugin'      => 'smtp-dkim',
            'version'     => smtpdkimc_VERSION,
            'timestamp'   => $timestamp,
        ] );
        
        $hmac = hash_hmac( 'sha256', $payload . $timestamp, $key );

        $response = wp_remote_post( smtpdkimc_API_URL, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WPSWD-HMAC' => $hmac,
                'X-WPSWD-Time' => (string) $timestamp,
            ],
            'body' => $payload,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $this->msg(
                    'Impossible de contacter le serveur de licences : ',
                    'Unable to reach the license server: '
                ) . $response->get_error_message(),
                'status'  => 'network_error',
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body ) ) {
            return [
                'success' => false,
                'message' => $this->msg(
                    'Réponse invalide du serveur de licences (code ' . $code . ').',
                    'Invalid response from license server (code ' . $code . ').'
                ),
                'status'  => 'server_error',
            ];
        }

        $is_valid = ! empty( $body['valid'] );

        return [
            'success'           => $is_valid,
            'message'           => $body['message'] ?? ( $is_valid
                ? $this->msg('Licence valide.', 'License valid.')
                : $this->msg('Licence invalide ou expirée.', 'License invalid or expired.')
            ),
            'status'            => $body['status']            ?? ( $is_valid ? 'active' : 'invalid' ),
            'expires_at'        => $body['expires_at']        ?? null,
            'plan_type'         => $body['plan_type']         ?? $body['plan'] ?? null,
            'customer_email'    => $body['customer_email']    ?? null,
            'customer_name'     => $body['customer_name']     ?? null,
            'activation_sig'    => $body['activation_sig']    ?? null,
            'activation_expiry' => $body['activation_expiry'] ?? null,
        ];
    }
}