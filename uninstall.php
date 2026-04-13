<?php
/**
 * Plugin uninstall script
 *
 * @package SMTP_DKIM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

( function () use ( $wpdb ) {

	// ── 1. Suppression des tables (préfixe $wpdb->prefix) ────────────────────
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . 'smtpdkimc_smtp_config' ) );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . 'smtpdkimc_license' ) );

	// Anciennes tables (avant renommage du plugin)
	$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . 'wpswd_smtp_config' ) );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . 'wpswd_license' ) );
	// phpcs:enable

	// ── 2. Options nommées — plugin actuel (smtpdkimc_) ──────────────────────
	$options = array(
		'smtpdkimc_db_version',
		'smtpdkimc_smtp_settings',
		'smtpdkimc_dkim_settings',
		'smtpdkimc_license_status',
		'smtpdkimc_license_data',
		'smtpdkimc_license_key',
		'smtpdkimc_license_expires',
		'smtpdkimc_license_status_cache',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// ── 3. Options nommées — anciens noms (wpswd_ / wp_smtp_dkim / wp-smtp-dkim)
	$legacy_options = array(
		'wpswd_db_version',
		'wpswd_smtp_settings',
		'wpswd_dkim_settings',
		'wpswd_license_status',
		'wpswd_license_data',
		'wpswd_license_key',
		'wpswd_license_expires',
		'wpswd_license_status_cache',
	);
	foreach ( $legacy_options as $option ) {
		delete_option( $option );
	}

	// ── 4. Nettoyage par LIKE dans {$wpdb->options} (utilise le vrai nom de
	//       table avec préfixe, fourni par WordPress via $wpdb->options) ───────
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$like_patterns = array(
		// Actuel
		'smtpdkimc\_%',
		'\_transient\_smtpdkimc\_%',
		'\_transient\_timeout\_smtpdkimc\_%',
		// Anciens noms
		'wpswd\_%',
		'\_transient\_wpswd\_%',
		'\_transient\_timeout\_wpswd\_%',
		'wp\_smtp\_dkim\_%',
		'wp-smtp-dkim\_%',
	);

	foreach ( $like_patterns as $pattern ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);
	}
	// phpcs:enable

	// Historique des plugins récemment désactivés
	delete_option( 'recently_activated' );

} )();

if ( function_exists( 'wp_cache_flush' ) ) {
	wp_cache_flush();
}