<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class smtpdkimc_Activator {

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // ── Table de configuration SMTP ──────────────────────────────────────
        $t_config = $wpdb->prefix . smtpdkimc_TABLE;

        // dbDelta n'accepte pas les requêtes préparées, on garde l'interpolation ici
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        dbDelta( "CREATE TABLE {$t_config} (
            id                INT(11)      NOT NULL AUTO_INCREMENT,
            enable_smtp       TINYINT(1)   NOT NULL DEFAULT 0,
            email_from        VARCHAR(255) NOT NULL DEFAULT '',
            email_from_name   VARCHAR(255) NOT NULL DEFAULT '',
            force_from_email  TINYINT(1)   NOT NULL DEFAULT 1,
            smtp_host         VARCHAR(255) NOT NULL DEFAULT '',
            smtp_port         VARCHAR(10)  NOT NULL DEFAULT '465',
            smtp_encryption   VARCHAR(10)  NOT NULL DEFAULT 'ssl',
            smtp_auto_tls     TINYINT(1)   NOT NULL DEFAULT 1,
            smtp_auth         TINYINT(1)   NOT NULL DEFAULT 1,
            smtp_username     VARCHAR(255) NOT NULL DEFAULT '',
            smtp_password     VARCHAR(512) NOT NULL DEFAULT '',
            smtp_debug_level  TINYINT(1)   NOT NULL DEFAULT 0,
            dkim_domain       VARCHAR(255) NOT NULL DEFAULT '',
            dkim_selector     VARCHAR(100) NOT NULL DEFAULT 'default',
            dkim_private_key  LONGTEXT              DEFAULT NULL,
            created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};" );

        // ── Ligne de config par défaut ───────────────────────────────────────
        // Les noms de tables ne peuvent pas être préparés en MySQL
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_config}" );
        if ( $count === 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->insert( $t_config, [
                'enable_smtp'    => 0,
                'email_from'     => get_option( 'admin_email', '' ),
                'email_from_name'=> get_bloginfo( 'name' ),
                'smtp_port'      => '465',
                'smtp_encryption'=> 'ssl',
                'smtp_auto_tls'  => 1,
                'smtp_auth'      => 1,
                'dkim_selector'  => 'default',
            ] );
        }

        update_option( 'smtpdkimc_db_version', smtpdkimc_VERSION );
    }

    public static function deactivate(): void {
        // Conservation des données intentionnelle
    }
}
