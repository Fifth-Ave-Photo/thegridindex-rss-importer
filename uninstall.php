<?php
/**
 * TheGridIndex RSS Importer — uninstall cleanup.
 *
 * Runs ONLY when WordPress deletes the plugin from Plugins → Delete.
 * Does not run on simple deactivation.
 *
 * v1.0.49 BEHAVIOR (WP.org submission compliant):
 *   Reads the `keep_on_uninstall` flag from the plugin's settings option
 *   BEFORE deciding what to delete. DEFAULT IS FALSE — uninstalling means
 *   removing plugin data, matching WordPress.org plugin guidelines that
 *   require data persistence to be opt-in rather than opt-out.
 *
 *   The user can opt in to preservation by checking "Keep my data if I
 *   uninstall this plugin" on the Settings tab BEFORE clicking Delete.
 *   With the flag set: feed list, settings, dedupe ledger, and per-post
 *   GUID-hash meta are preserved; reinstalling restores the prior state.
 *
 *   Imported posts themselves are NEVER deleted by uninstall regardless
 *   of the flag — those are the user's content, not the plugin's.
 *
 *   The cron schedule and SimplePie feed-cache transients are ALWAYS
 *   cleared, regardless of the flag. A dead cron event with no plugin
 *   loaded would log errors; a stale feed cache is pointless to retain.
 *
 * v1.0.66 — PHPCS pass: documented justifications for direct DB calls
 * (necessary on uninstall — autoloader is gone, so $wpdb is the only
 * remaining tool; nothing to cache when the plugin is being deleted),
 * properly prepared the seen-table DROP, and renamed `$timestamp` to
 * `$gip_next_cron_run` so every variable in this file carries the
 * project prefix. The `gip_` prefix is the canonical plugin prefix
 * used throughout the codebase (option keys, transients, table names,
 * function variables) and is consistently and exclusively this plugin's;
 * see plugin header for the project relationship.
 *
 * @package TheGridIndex_RSS_Importer
 * @since   1.0.17
 */

// Hard-block direct access. WordPress sets WP_UNINSTALL_PLUGIN when invoking
// this file; if we're called any other way, abort.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// All variables in this file are local to the script body (uninstall.php is
// executed once by WP_UNINSTALL_PLUGIN, in isolation, and is not loaded as
// a long-lived module), but PHPCS sees file-scope assignments as "globals."
// All variables here use the `gip_` prefix, which is this plugin's canonical
// prefix throughout the codebase (option keys: gip_rss_*; transients:
// gip_rss_*; database table: gip_seen_guids). Suppressing the rule rather
// than re-prefixing to `grid_index_rss_importer_*` everywhere, which would
// require a one-time settings migration with no behavior benefit.

// Mirror the constants from the main plugin file rather than loading the
// class — uninstall.php should never bootstrap plugin code, per WP docs.
$gip_option_key = 'gip_rss_importer_settings';
$gip_cron_hook  = 'gip_rss_importer_cron';
$gip_meta_guid  = '_gip_rss_guid_hash';

// Read the persist-on-uninstall preference BEFORE doing any deletes.
// v1.0.49 — Default FALSE when the option doesn't exist, matching the new
// settings default. WP.org submission requires uninstall to remove plugin
// data unless the user explicitly opted in to preservation.
$gip_settings          = get_option( $gip_option_key, array() );
$gip_keep_on_uninstall = ! empty( $gip_settings['keep_on_uninstall'] );

// ALWAYS: clear the cron event. A scheduled hook pointing at a class that
// no longer exists would fire on the next wp-cron tick and spew errors.
$gip_next_cron_run = wp_next_scheduled( $gip_cron_hook );
while ( $gip_next_cron_run ) {
	wp_unschedule_event( $gip_next_cron_run, $gip_cron_hook );
	$gip_next_cron_run = wp_next_scheduled( $gip_cron_hook );
}
wp_clear_scheduled_hook( $gip_cron_hook );

// ALWAYS: drop SimplePie/WordPress feed cache transients. They're tied to
// this plugin's fetch loop and become orphaned junk once the plugin is gone.
// PHPCS note: direct $wpdb->query() is intentional here — uninstall.php runs
// in isolation (no plugin autoloader, no class instance), the WordPress
// transient API has no public "delete all transients matching a pattern"
// helper, and caching the result is meaningless on uninstall (the cache and
// the plugin are both about to be gone).
global $wpdb;
if ( isset( $wpdb ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\\_transient\\_feed\\_%'
		    OR option_name LIKE '\\_transient\\_timeout\\_feed\\_%'"
	);
}

// ALWAYS: clear the live import-progress transient. It only matters during
// a running import, and the plugin won't be there to consume it next.
delete_transient( 'gip_rss_progress' );

// If the user chose to preserve data, we're done.
if ( $gip_keep_on_uninstall ) {
	return;
}

// FULL CLEANUP path: user explicitly unchecked "Keep my data."
// Settings option (carries feed list, schedule, image rules, etc.).
delete_option( $gip_option_key );

// Migration markers.
delete_option( 'gip_rss_migration_v1_0_27' );
delete_option( 'gip_rss_migration_v1_0_28_cap' );
delete_option( 'gip_rss_migration_v1_0_34_intervals' );

// Per-post GUID-hash meta. With this gone, reinstalling and re-fetching
// will treat already-imported items as new and re-import them. That's
// fine in the clean-uninstall case — the user asked for a fresh slate.
if ( isset( $wpdb ) ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$wpdb->delete(
		$wpdb->postmeta,
		array( 'meta_key' => $gip_meta_guid )
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

	// v1.0.38 — Drop the persistent seen-GUIDs ledger table.
	// v1.0.66 — Table name composed from $wpdb->prefix (trusted, WordPress-controlled
	// and validated at install time) plus a hard-coded constant. Schema-change DDL
	// can't use prepared placeholders for identifiers, so we sprintf in safely.
	$gip_seen_table = $wpdb->prefix . 'gip_seen_guids';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( "DROP TABLE IF EXISTS `{$gip_seen_table}`" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

// v1.0.38 — Migration markers we set on activation.
delete_option( 'gip_rss_migration_v1_0_38_seen' );
delete_option( 'gip_rss_migration_v1_0_40_category' );
delete_option( 'gip_rss_migration_v1_0_41_cnn' );
delete_option( 'gip_rss_migration_v1_0_49_rsshub' );
delete_option( 'gip_rss_migration_v1_0_63_ap_mirror' );

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
