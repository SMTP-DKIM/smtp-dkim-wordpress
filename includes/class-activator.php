<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class smtpdkimc_Activator {

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // ── Table de configuration SMTP ──────────────────────────────────────
        $t_config = $wpdb->prefix . smtpdkimc_TABLE;
        $t_lic = $wpdb->prefix . smtpdkimc_LICENSE_TABLE;

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

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        dbDelta( "CREATE TABLE {$t_lic} (
            id           INT(11)      NOT NULL AUTO_INCREMENT,
            license_key     VARCHAR(512) NOT NULL DEFAULT '',
            status          VARCHAR(20)  NOT NULL DEFAULT 'inactive',
            domain          VARCHAR(255) NOT NULL DEFAULT '',
            customer_email     VARCHAR(255)          DEFAULT NULL,
            customer_name      VARCHAR(255)          DEFAULT NULL,
            activated_at       DATETIME              DEFAULT NULL,
            expires_at         DATETIME              DEFAULT NULL,
            plan_type          VARCHAR(50)           DEFAULT NULL,
            last_check         DATETIME              DEFAULT NULL,
            activation_sig     TEXT                  DEFAULT NULL,
            activation_expiry  INT(11)               DEFAULT NULL,
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

        // ── Migrations avec SQL sécurisée ─────────────────────────────────────
        // Note : Les modifications de schéma (ALTER TABLE) nécessitent des requêtes directes.
        // WordPress ne fournit pas d'API abstraite pour cela en dehors de dbDelta().
        // Ces requêtes sont exécutées UNE FOIS lors de l'activation, donc le caching n'est pas pertinent.
        
        // Vérification et ajout de la colonne plan_type
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $has_plan = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = %s
             AND COLUMN_NAME = 'plan_type'",
            $t_lic
        ) );
        if ( ! $has_plan ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$t_lic} ADD COLUMN plan_type VARCHAR(50) DEFAULT NULL AFTER expires_at" );
        }

        // Vérification et modification de la colonne license_key
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $col = $wpdb->get_row( $wpdb->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = %s
             AND COLUMN_NAME = 'license_key'",
            $t_lic
        ) );
        if ( $col && stripos( $col->COLUMN_TYPE, 'varchar(64)' ) !== false ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$t_lic} MODIFY license_key VARCHAR(512) NOT NULL DEFAULT ''" );
        }

        // Vérification et ajout des colonnes customer_email et customer_name
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $has_email = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = %s
             AND COLUMN_NAME = 'customer_email'",
            $t_lic
        ) );
        if ( ! $has_email ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$t_lic} ADD COLUMN customer_email VARCHAR(255) DEFAULT NULL AFTER domain" );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$t_lic} ADD COLUMN customer_name VARCHAR(255) DEFAULT NULL AFTER customer_email" );
        }

        // Vérification et ajout des colonnes activation_sig et activation_expiry
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $has_sig = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = %s
             AND COLUMN_NAME = 'activation_sig'",
            $t_lic
        ) );
        if ( ! $has_sig ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$t_lic} ADD COLUMN activation_sig TEXT DEFAULT NULL AFTER last_check" );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$t_lic} ADD COLUMN activation_expiry INT(11) DEFAULT NULL AFTER activation_sig" );
        }

        update_option( 'smtpdkimc_db_version', smtpdkimc_VERSION );
    }

    public static function deactivate(): void {
        // Conservation des données intentionnelle
    }
}