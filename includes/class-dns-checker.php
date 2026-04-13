<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class smtpdkimc_DNS_Checker {

    const DOH = 'https://cloudflare-dns.com/dns-query';

    /** Helper — retourne un texte dans la langue active de l'utilisateur WP */
    private function t( string $fr, string $en ): string {
        $lang = get_user_meta( get_current_user_id(), 'smtpdkimc_lang', true ) ?: 'fr';
        return $lang === 'en' ? $en : $fr;
    }
    const SELECTORS = [ 'default', 'mail', 'smtp', 's1', 's2', 'dkim', 'google', 'selector1', 'selector2', 'key1', 'cpanel', 'pm' ];

    public function __construct() {
        add_action( 'wp_ajax_smtpdkimc_dns_check',      [ $this, 'ajax_check'      ] );
        add_action( 'wp_ajax_smtpdkimc_dns_autodetect', [ $this, 'ajax_autodetect' ] );
    }

    public function check_domain( string $domain, string $selector = 'default' ): array {
        $cache_key = 'smtpdkimc_dns_' . md5( $domain . $selector );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;
        $result = [
            'domain' => $domain, 'selector' => $selector,
            'spf'    => $this->check_spf( $domain ),
            'dkim'   => $this->check_dkim( $domain, $selector ),
            'dmarc'  => $this->check_dmarc( $domain ),
        ];
        set_transient( $cache_key, $result, HOUR_IN_SECONDS );
        return $result;
    }

    public function clear_cache( string $domain, string $selector = 'default' ): void {
        delete_transient( 'smtpdkimc_dns_' . md5( $domain . $selector ) );
    }

    /**
     * Détecte automatiquement le sélecteur DKIM actif pour un domaine.
     * Teste les sélecteurs courants jusqu'à en trouver un qui répond.
     *
     * @return array [ 'selector' => string, 'found' => bool, 'public_key_snippet' => string ]
     */
    public function detect_selector( string $domain ): array {
        foreach ( self::SELECTORS as $sel ) {
            $records = $this->query_txt( $sel . '._domainkey.' . $domain );
            foreach ( $records as $txt ) {
                if ( stripos( $txt, 'v=DKIM1' ) !== false || stripos( $txt, 'p=' ) !== false ) {
                    // Extraire un extrait de la clé publique pour confirmation
                    preg_match( '/p=([^;]{0,40})/i', $txt, $m );
                    $snippet = isset( $m[1] ) ? substr( $m[1], 0, 20 ) . '…' : '';
                    return [
                        'selector'            => $sel,
                        'found'               => true,
                        'public_key_snippet'  => $snippet,
                    ];
                }
            }
        }
        return [
            'selector'           => 'default',
            'found'              => false,
            'public_key_snippet' => '',
        ];
    }

    public function ajax_check(): void {
        check_ajax_referer( 'smtpdkimc_dns_check', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( $this->t('Permission refusée.', 'Permission denied.') );
        }
        
        // ✅ Correction : wp_unslash() avant sanitization
        $domain   = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );
        $selector = sanitize_text_field( wp_unslash( $_POST['selector'] ?? 'default' ) );
        
        if ( empty( $domain ) ) {
            wp_send_json_error( $this->t('Domaine vide.', 'Empty domain.') );
        }
        $this->clear_cache( $domain, $selector );
        wp_send_json_success( $this->check_domain( $domain, $selector ) );
    }

    public function ajax_autodetect(): void {
        check_ajax_referer( 'smtpdkimc_dns_autodetect', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( $this->t('Permission refusée.', 'Permission denied.') );
        }
        
        // ✅ Correction : wp_parse_url() au lieu de parse_url()
        $domain = wp_parse_url( home_url(), PHP_URL_HOST );
        $found_selector = null;
        $found_record   = null;
        foreach ( self::SELECTORS as $sel ) {
            $records = $this->query_txt( $sel . '._domainkey.' . $domain );
            foreach ( $records as $txt ) {
                if ( stripos( $txt, 'v=DKIM1' ) !== false || stripos( $txt, 'p=' ) !== false ) {
                    $found_selector = $sel;
                    $found_record   = $txt;
                    break 2;
                }
            }
        }
        $selector = $found_selector ?? 'default';
        $this->clear_cache( $domain, $selector );
        $dns = $this->check_domain( $domain, $selector );
        wp_send_json_success( [
            'domain'         => $domain,
            'selector'       => $selector,
            'selector_found' => $found_selector !== null,
            'dkim_record'    => $found_record,
            'dns'            => $dns,
        ] );
    }

    private function check_spf( string $domain ): array {
        $records = $this->query_txt( $domain );
        foreach ( $records as $txt ) {
            if ( stripos( $txt, 'v=spf1' ) !== false ) {
                $issues = [];
                if ( strpos( $txt, '-all' ) !== false )      $strength = 'strict';
                elseif ( strpos( $txt, '~all' ) !== false )  $strength = 'softfail';
                elseif ( strpos( $txt, '?all' ) !== false ) { 
                    $strength = 'neutral';  
                    $issues[] = $this->t('Remplacez ?all par ~all ou -all.', 'Replace ?all with ~all or -all.');
                } else { 
                    $strength = 'unknown'; 
                    $issues[] = $this->t('Directive ALL manquante.', 'Missing ALL directive at end of record.');
                }
                return [ 'status' => empty($issues)?'ok':'warning', 'record' => $txt, 'strength' => $strength, 'issues' => $issues, 'fix' => null ];
            }
        }
        return [
            'status' => 'missing', 'record' => null, 'issues' => [ $this->t('Aucun enregistrement SPF trouvé.', 'No SPF record found.') ],
            'fix' => [ 'type' => 'TXT', 'name' => $domain . '.', 'value' => 'v=spf1 +a +mx ~all',
                       'note' => $this->t('Créez un enregistrement TXT sur le domaine racine dans cPanel → Zone Editor.', 'Create a TXT record on the root domain in cPanel → Zone Editor.') ],
        ];
    }

    private function check_dkim( string $domain, string $selector ): array {
        $records = $this->query_txt( $selector . '._domainkey.' . $domain );
        foreach ( $records as $txt ) {
            if ( stripos( $txt, 'v=DKIM1' ) !== false || stripos( $txt, 'p=' ) !== false ) {
                preg_match( '/p=([^;]+)/i', $txt, $m );
                $key_b64 = trim( $m[1] ?? '' );
                $key_len = $key_b64 ? strlen( base64_decode( $key_b64 ) ) * 8 : 0;
                $issues  = [];
                if ( $key_len > 0 && $key_len < 2048 ) {
                    $issues[] = $this->t("Clé RSA de {$key_len} bits — minimum recommandé : 2048 bits.", "RSA key is {$key_len} bits — minimum recommended: 2048 bits.");
                }
                return [ 'status' => empty($issues)?'ok':'warning', 'record' => $txt,
                         'key_bits' => $key_len?:null, 'selector' => $selector, 'issues' => $issues, 'fix' => null, 'found_alt' => null ];
            }
        }
        $found_alt = null;
        foreach ( array_diff( self::SELECTORS, [ $selector ] ) as $alt ) {
            foreach ( $this->query_txt( $alt . '._domainkey.' . $domain ) as $txt ) {
                if ( stripos( $txt, 'v=DKIM1' ) !== false || stripos( $txt, 'p=' ) !== false ) { 
                    $found_alt = $alt; 
                    break 2; 
                }
            }
        }
        return [
            'status' => 'missing', 'record' => null, 'selector' => $selector, 'found_alt' => $found_alt,
            'issues' => [ $found_alt
                ? $this->t("Aucun DKIM pour le sélecteur \"{$selector}\" mais \"{$found_alt}\" fonctionne — mettez-le à jour dans le plugin.", "No DKIM for selector \"{$selector}\" but \"{$found_alt}\" works — update it in the plugin.")
                : $this->t("Aucun enregistrement DKIM trouvé pour le sélecteur \"{$selector}\".", "No DKIM record found for selector \"{$selector}\".") ],
            'fix' => [ 'type' => 'TXT', 'name' => $selector . '._domainkey.' . $domain . '.',
                       'value' => 'v=DKIM1; k=rsa; p=VOTRE_CLE_PUBLIQUE',
                       'note'  => 'Cle publique disponible dans cPanel -> Email Deliverability -> selecteur "' . $selector . '".' ],
        ];
    }

    private function check_dmarc( string $domain ): array {
        $records = $this->query_txt( '_dmarc.' . $domain );
        foreach ( $records as $txt ) {
            if ( stripos( $txt, 'v=DMARC1' ) !== false ) {
                preg_match( '/p=([^;]+)/i',   $txt, $pm );
                preg_match( '/rua=([^;]+)/i', $txt, $rm );
                $policy   = strtolower( trim( $pm[1] ?? 'none' ) );
                $rua      = trim( $rm[1] ?? '' );
                $issues   = array();    // problèmes bloquants
                $warnings = array();    // avertissements optionnels
                $fix_val  = null;

                // Problème bloquant : p=none
                if ( $policy === 'none' ) {
                    $issues[] = $this->t('p=none — les emails frauduleux ne sont pas bloqués. Recommandé : p=quarantine.', 'p=none — fraudulent emails are not blocked. Recommended: p=quarantine.');
                    $fix_val  = str_replace( 'p=none', 'p=quarantine', $txt );
                    if ( empty($rua) ) $fix_val .= '; rua=mailto:postmaster@'.$domain;
                }

                // Optionnel : rua manquant (pas bloquant, juste recommande)
                if ( empty( $rua ) ) {
                    $warnings[] = $this->t('Pas de rua= — vous ne recevrez pas les rapports DMARC (optionnel mais recommandé).', 'No rua= — you will not receive DMARC reports (optional but recommended).');
                    if ( empty($issues) ) {
                        $fix_val = rtrim( $txt, ';' ) . '; rua=mailto:postmaster@' . $domain;
                    }
                }

                // Statut : ok si politique quarantine/reject (rua manquant seul = ok avec avertissement optionnel)
                $status = empty($issues) ? 'ok' : 'missing';

                return [
                    'status'   => $status,
                    'record'   => $txt, 'policy' => $policy, 'rua' => $rua,
                    'issues'   => $issues,
                    'warnings' => $warnings,
                    'fix'      => $fix_val ? [
                        'type'     => 'TXT',
                        'name'     => '_dmarc.' . $domain . '.',
                        'value'    => $fix_val,
                        'optional' => empty($issues),
                        'note'     => empty($issues)
                            ? $this->t('(Optionnel) Ajoutez rua= pour recevoir les rapports DMARC. Non requis pour la délivrabilité.', '(Optional) Add rua= to receive DMARC reports. Not required for deliverability.')
                            : $this->t('Modifiez l\'enregistrement TXT _dmarc existant dans votre zone DNS.', 'Update the existing _dmarc TXT record in your DNS zone.'),
                    ] : null,
                ];
            }
        }
        return [
            'status' => 'missing', 'record' => null, 'policy' => null, 'rua' => '', 'issues' => [ $this->t('Aucun enregistrement DMARC trouvé.', 'No DMARC record found.') ], 'warnings' => array(),
            'fix'    => [ 'type' => 'TXT', 'name' => '_dmarc.'.$domain.'.', 'value' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@'.$domain,
                          'optional' => false, 'note' => $this->t('Créez un nouvel enregistrement TXT _dmarc dans votre zone DNS.', 'Create a new _dmarc TXT record in your DNS zone.') ],
        ];
    }

    private function query_txt( string $name ): array {
        $url      = add_query_arg( [ 'name' => $name, 'type' => 'TXT' ], self::DOH );
        $response = wp_remote_get( $url, [ 'timeout' => 8, 'headers' => [ 'Accept' => 'application/dns-json' ], 'sslverify' => true ] );
        if ( is_wp_error( $response ) ) return array();
        $body    = json_decode( wp_remote_retrieve_body( $response ), true );
        $results = array();
        foreach ( $body['Answer'] ?? array() as $answer ) {
            if ( (int) $answer['type'] === 16 ) {
                $txt = trim( $answer['data'], '"' );
                $results[] = str_replace( '" "', '', $txt );
            }
        }
        return $results;
    }
}