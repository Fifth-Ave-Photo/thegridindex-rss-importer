<?php
/**
 * Plugin Name:       TheGridIndex RSS Importer
 * Plugin URI:        https://github.com/Fifth-Ave-Photo/the-grid-index-rss-importer
 * Description:       Pull headlines from external RSS feeds into WordPress. Designed to pair with The Grid Index theme — imported posts are tagged with canonical source meta so the theme's "Read at Source" attribution lights up automatically. Works as a standalone importer if the theme isn't active.
 * Version:           1.0.74
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Fifth Avenue Photographic
 * Author URI:        https://fifthavenuephotographic.com/
 * Text Domain:       thegridindex-rss-importer
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package TheGridIndex_RSS_Importer
 *
 * v1.0.67 — Renamed from "Grid Index RSS Importer" / slug
 *   `grid-index-rss-importer` to "TheGridIndex RSS Importer" / slug
 *   `thegridindex-rss-importer`. The trademark-clearance review asked
 *   for a more clearly-coined brand name to distinguish the plugin from
 *   any generic "Grid Index" project association. The textdomain and
 *   slug now match the new brand name. INTERNAL CODE PREFIX is still
 *   `gip_` — that prefix has been the canonical project-internal prefix
 *   since v1.0.0, is used by the option key, cron hook, custom database
 *   table, action hooks, and per-post meta key, all of which are stored
 *   in user databases. Renaming the internal prefix would require a
 *   one-time data migration with no functional benefit and meaningful
 *   risk; we therefore retain `gip_` as the internal prefix and keep
 *   the public-facing identifiers aligned with the new brand.
 *
 * v1.0.73 — The PHP class name and version constant — which lived
 *   outside the database and only had internal callers — were renamed
 *   from `Grid_Index_RSS_Importer` / `GRID_INDEX_RSS_IMPORTER_VERSION`
 *   to `TheGridIndex_RSS_Importer` / `THEGRIDINDEX_RSS_IMPORTER_VERSION`
 *   so all class- and constant-level identifiers carry the new slug
 *   prefix. WP.org Plugin Check (PrefixAllGlobals.NonPrefixedClassFound
 *   and NonPrefixedConstantFound) flagged the legacy names. Unlike the
 *   `gip_` runtime prefix, these are static identifiers — no migration
 *   is needed because no live install reads their literal name.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'THEGRIDINDEX_RSS_IMPORTER_VERSION' ) ) {
	define( 'THEGRIDINDEX_RSS_IMPORTER_VERSION', '1.0.74' );
}

// v1.0.3: Removed the class_exists guard. If something else in the install
// (theme inc file, mu-plugin) was also defining this class earlier in the
// load chain, the plugin was silently no-oping. We now load unconditionally
// and rely on PHP to fatal loudly if there's a real conflict.

class TheGridIndex_RSS_Importer {

	const OPTION_KEY    = 'gip_rss_importer_settings';
	const CRON_HOOK     = 'gip_rss_importer_cron';
	const META_GUID     = '_gip_rss_guid_hash';
	const NONCE_ACTION  = 'gip_rss_importer_save';
	const NONCE_NAME    = 'gip_rss_importer_nonce';
	const PAGE_SLUG     = 'gip-rss-importer';
	const RSS_CAT_SLUG  = 'rss';
	const RSS_CAT_NAME  = 'RSS';

	// v1.0.40 — Granular per-feed categories. Each catalog feed has a
	// `category` field (News/World/Tech/Business/Science) which maps to a
	// WP category term created on demand. Posts imported from that feed
	// get tagged with BOTH the RSS catch-all AND the granular term, so
	// themes that query Category:RSS keep working AND users get
	// per-source browsing.
	const GRANULAR_CATEGORIES = array(
		// catalog name => array( slug, display_name, description )
		'News'     => array( 'slug' => 'news',     'name' => 'News',     'desc' => 'General news from major outlets.' ),
		'World'    => array( 'slug' => 'world',    'name' => 'World',    'desc' => 'International news desks.' ),
		'Tech'     => array( 'slug' => 'tech',     'name' => 'Tech',     'desc' => 'Technology news and reviews.' ),
		'Business' => array( 'slug' => 'business', 'name' => 'Business', 'desc' => 'Business, finance, and markets.' ),
		'Science'  => array( 'slug' => 'science',  'name' => 'Science',  'desc' => 'Science and research news.' ),
	);

	// v1.0.13 — fetch tuning. WP defaults are too aggressive (5s timeout) and
	// the default User-Agent gets filtered by some publisher WAFs.
	const FETCH_TIMEOUT_SECONDS   = 15;
	const FETCH_RETRY_DELAY_SECS  = 2;
	const FETCH_ERROR_BACKOFF_SEC = 600; // 10 minutes — skip recently-failed feeds during cron runs.

	// v1.0.24 — Cap on active feeds. Catalog tab toggles enforce this, and
	// the save handler also clamps to it as a defense-in-depth.
	const MAX_ACTIVE_FEEDS = 15;

	// v1.0.34 — Per-feed intervals. Each saved feed can fetch on its own
	// cadence. The cron itself runs every 5 minutes (the shortest interval)
	// and per-feed logic decides whether each one is actually due.
	const VALID_INTERVALS = array( '5min', '15min', '30min', 'hourly' );
	const DEFAULT_INTERVAL = 'hourly';

	// Seconds-per-interval lookup, used by the cron-eligibility check.
	const INTERVAL_SECONDS = array(
		'5min'   => 300,
		'15min'  => 900,
		'30min'  => 1800,
		'hourly' => 3600,
	);

	// v1.0.26 — Live progress state, written by the import loop so the
	// admin UI can poll it. Stored as a transient (auto-expires after a
	// few minutes if a run dies mid-flight) rather than an option to
	// avoid polluting wp_options with stale rows.
	const PROGRESS_TRANSIENT = 'gip_rss_progress';
	const PROGRESS_TTL_SECS  = 600; // 10 minutes — longer than any sane import.

	// v1.0.38 — Persistent "seen GUIDs" ledger. A custom table that survives
	// post deletion (postmeta is wiped when posts are permanently deleted,
	// which used to cause deleted posts to re-import on the next fetch).
	// Table name (without prefix); accessed via $wpdb->prefix . SEEN_TABLE.
	const SEEN_TABLE = 'gip_seen_guids';

	/** @var TheGridIndex_RSS_Importer|null */
	private static $instance = null;

	/** @var string Hook suffix returned by add_theme_page. */
	private $hook_suffix = '';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Register the menu via THREE different methods so at least one shows up
		// even if a plugin or platform filter strips out one of them.
		// v1.0.47 — Tools/Settings fallback registrations removed.
		// They were originally added as redundant safety nets when the main
		// menu was a top-level entry. Since v1.0.46 the main menu nests
		// under "Grid Index" (with a top-level fallback if the theme is
		// inactive), so the duplicate Tools/Settings entries were just
		// clutter — and worse, clicking through them produced a different
		// $hook_suffix that the CSS enqueue didn't recognize, rendering the
		// page completely unstyled. Single registration only.
		add_action( 'admin_menu',       array( $this, 'register_admin_page' ), 15 );

		add_action( 'admin_post_' . self::PAGE_SLUG . '_save',      array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::PAGE_SLUG . '_run',       array( $this, 'handle_run_now' ) );
		add_action( 'admin_post_' . self::PAGE_SLUG . '_force',     array( $this, 'handle_force_reimport' ) );
		add_action( 'admin_post_' . self::PAGE_SLUG . '_fetch_one', array( $this, 'handle_fetch_one_feed' ) );
		add_action( 'admin_post_' . self::PAGE_SLUG . '_restore',   array( $this, 'handle_restore_defaults' ) );
		add_action( 'admin_post_' . self::PAGE_SLUG . '_republish', array( $this, 'handle_republish_drafts' ) );
		add_action( 'admin_post_' . self::PAGE_SLUG . '_set_publish', array( $this, 'handle_set_publish' ) );
		add_action( 'admin_post_' . self::PAGE_SLUG . '_clear_feeds', array( $this, 'handle_clear_feeds' ) );
		add_action( 'admin_post_' . self::PAGE_SLUG . '_catalog_toggle', array( $this, 'handle_catalog_toggle' ) );
		add_action( 'admin_post_' . self::PAGE_SLUG . '_trim_to_cap', array( $this, 'handle_trim_to_cap' ) );
		add_action( 'admin_post_' . self::PAGE_SLUG . '_merge_dupes', array( $this, 'handle_merge_dupes' ) );
		add_action( 'admin_post_' . self::PAGE_SLUG . '_reset_ledger', array( $this, 'handle_reset_ledger' ) );
		add_action( 'wp_ajax_' . self::PAGE_SLUG . '_ajax_save',      array( $this, 'handle_ajax_save' ) );
		add_action( 'wp_ajax_' . self::PAGE_SLUG . '_ajax_progress',  array( $this, 'handle_ajax_progress' ) );
		add_action( 'wp_ajax_' . self::PAGE_SLUG . '_ajax_fetch_one', array( $this, 'handle_ajax_fetch_one' ) );
		add_action( self::CRON_HOOK,    array( $this, 'run_import' ) );
		add_filter( 'cron_schedules',   array( $this, 'register_cron_schedules' ) );
		add_action( 'init',             array( $this, 'maybe_reschedule_cron' ) );
		add_action( 'switch_theme',     array( $this, 'unschedule_cron' ) );
	}

	/**
	 * Activation hook callback — seed the default starter feeds if no
	 * settings exist yet. Safe to call repeatedly: it only writes feeds
	 * when the option is empty or has no feeds configured.
	 */
	public static function activate() {
		// Ensure the RSS category exists.
		self::ensure_rss_category();

		// v1.0.13 — Flush SimplePie's WordPress transient cache. Stale entries
		// from before fetch tuning was added (default UA, 5s timeout) can
		// cause the first post-update fetch to serve a cached error or empty
		// parse instead of actually re-fetching.
		self::flush_feed_cache();

		// v1.0.38 — Create the persistent seen-GUIDs ledger table.
		self::ensure_seen_table();

		// v1.0.38 — One-time migration: backfill the seen-GUIDs table from
		// existing postmeta so already-imported items can't re-import even if
		// their posts get permanently deleted. Marker means this runs once.
		$seen_marker = get_option( 'gip_rss_migration_v1_0_38_seen' );
		if ( ! $seen_marker ) {
			self::backfill_seen_from_postmeta();
			update_option( 'gip_rss_migration_v1_0_38_seen', time() );
		}

		// v1.0.40 — One-time migration: backfill the per-feed category field
		// on existing saved feeds by URL-matching against the catalog.
		// Existing custom (non-catalog) feeds get empty category — they
		// remain RSS-only until the user assigns one manually.
		$cat_marker = get_option( 'gip_rss_migration_v1_0_40_category' );
		if ( ! $cat_marker ) {
			$existing = get_option( self::OPTION_KEY, array() );
			if ( is_array( $existing ) && ! empty( $existing['feeds'] ) && is_array( $existing['feeds'] ) ) {
				$instance = self::instance();
				$catalog  = $instance->get_catalog_feeds();
				$by_url   = array();
				foreach ( $catalog as $cf ) {
					if ( ! empty( $cf['url'] ) && ! empty( $cf['category'] ) ) {
						$by_url[ $cf['url'] ] = $cf['category'];
					}
				}
				$changed = false;
				foreach ( $existing['feeds'] as &$f ) {
					if ( empty( $f['url'] ) ) continue;
					if ( ! empty( $f['category'] ) ) continue; // already set
					if ( isset( $by_url[ $f['url'] ] ) ) {
						$f['category'] = $by_url[ $f['url'] ];
						$changed = true;
					}
				}
				unset( $f );
				if ( $changed ) {
					update_option( self::OPTION_KEY, $existing );
				}
			}
			update_option( 'gip_rss_migration_v1_0_40_category', time() );
		}

		// v1.0.41 — One-time migration: remove CNN's deprecated feed URL from
		// any active feed lists. CNN dropped RSS in 2024 and the URL we
		// previously shipped (rss.cnn.com/rss/edition.rss) returns empty
		// data, causing the red-dot silent-failure state users see. Marker
		// gates this to a single run per install.
		$cnn_marker = get_option( 'gip_rss_migration_v1_0_41_cnn' );
		if ( ! $cnn_marker ) {
			$existing = get_option( self::OPTION_KEY, array() );
			if ( is_array( $existing ) && ! empty( $existing['feeds'] ) && is_array( $existing['feeds'] ) ) {
				$before = count( $existing['feeds'] );
				$existing['feeds'] = array_values( array_filter(
					$existing['feeds'],
					function( $f ) {
						return empty( $f['url'] ) || strpos( $f['url'], 'rss.cnn.com' ) === false;
					}
				) );
				if ( count( $existing['feeds'] ) !== $before ) {
					update_option( self::OPTION_KEY, $existing );
				}
			}
			update_option( 'gip_rss_migration_v1_0_41_cnn', time() );
		}

		// v1.0.49 — One-time migration: remove RSSHub bridge feeds (rsshub.app)
		// from any active feed lists. These were AP News and Reuters World
		// shipped via the community bridge; removed from the catalog for
		// WP.org submission compliance. Users who manually added their own
		// rsshub.app URLs after this migration runs are not affected.
		$rsshub_marker = get_option( 'gip_rss_migration_v1_0_49_rsshub' );
		if ( ! $rsshub_marker ) {
			$existing = get_option( self::OPTION_KEY, array() );
			if ( is_array( $existing ) && ! empty( $existing['feeds'] ) && is_array( $existing['feeds'] ) ) {
				$before = count( $existing['feeds'] );
				$existing['feeds'] = array_values( array_filter(
					$existing['feeds'],
					function( $f ) {
						return empty( $f['url'] ) || strpos( $f['url'], 'rsshub.app' ) === false;
					}
				) );
				if ( count( $existing['feeds'] ) !== $before ) {
					update_option( self::OPTION_KEY, $existing );
				}
			}
			update_option( 'gip_rss_migration_v1_0_49_rsshub', time() );
		}

		// v1.0.63 — One-time migration: remove the AP community-mirror S3 URL
		// from any active feed lists. Previously shipped as a starter feed
		// (`http://associated-press.s3-website-us-east-1.amazonaws.com/topnews.xml`);
		// WP.org reviewer flagged it as a remote-file-call dependency, since
		// the S3 mirror is unofficial and outside the plugin author's control.
		// AP itself retired official RSS in 2020. Users who manually added a
		// different AP bridge URL after this migration runs are not affected.
		$ap_marker = get_option( 'gip_rss_migration_v1_0_63_ap_mirror' );
		if ( ! $ap_marker ) {
			$existing = get_option( self::OPTION_KEY, array() );
			if ( is_array( $existing ) && ! empty( $existing['feeds'] ) && is_array( $existing['feeds'] ) ) {
				$before = count( $existing['feeds'] );
				$existing['feeds'] = array_values( array_filter(
					$existing['feeds'],
					function( $f ) {
						return empty( $f['url'] ) || strpos( $f['url'], 'associated-press.s3-website' ) === false;
					}
				) );
				if ( count( $existing['feeds'] ) !== $before ) {
					update_option( self::OPTION_KEY, $existing );
				}
			}
			update_option( 'gip_rss_migration_v1_0_63_ap_mirror', time() );
		}

		// v1.0.27 — One-time migration: when the catalog was reshaped, The
		// Atlantic was dropped. Remove it from any active feed list it's
		// still in. Gated by a marker option so this runs at most once per
		// install. We check by version constant so future cleanups can use
		// this same pattern with a different marker.
		$migration_marker = get_option( 'gip_rss_migration_v1_0_27' );
		if ( ! $migration_marker ) {
			$existing = get_option( self::OPTION_KEY, array() );
			if ( is_array( $existing ) && ! empty( $existing['feeds'] ) && is_array( $existing['feeds'] ) ) {
				$dropped = array( 'https://www.theatlantic.com/feed/all/' );
				$before  = count( $existing['feeds'] );
				$existing['feeds'] = array_values( array_filter( $existing['feeds'], function( $f ) use ( $dropped ) {
					return ! ( isset( $f['url'] ) && in_array( $f['url'], $dropped, true ) );
				} ) );
				if ( count( $existing['feeds'] ) !== $before ) {
					update_option( self::OPTION_KEY, $existing );
				}
			}
			update_option( 'gip_rss_migration_v1_0_27', time() );
		}

		// v1.0.28 — Defensive cap clamp. Anyone arriving here with more than
		// MAX_ACTIVE_FEEDS saved (because a pre-cap version saved them, or
		// because earlier cap enforcement only ran on save) gets trimmed to
		// the cap, keeping the first N in saved order. Marker means this
		// fires once per install.
		$cap_marker = get_option( 'gip_rss_migration_v1_0_28_cap' );
		if ( ! $cap_marker ) {
			$existing = get_option( self::OPTION_KEY, array() );
			if ( is_array( $existing ) && ! empty( $existing['feeds'] ) && is_array( $existing['feeds'] )
				&& count( $existing['feeds'] ) > self::MAX_ACTIVE_FEEDS ) {
				$existing['feeds'] = array_slice( $existing['feeds'], 0, self::MAX_ACTIVE_FEEDS );
				update_option( self::OPTION_KEY, $existing );
			}
			update_option( 'gip_rss_migration_v1_0_28_cap', time() );
		}

		// v1.0.34 — Backfill per-feed intervals. Any saved feed without an
		// `interval` field gets the catalog recommendation (if the URL is in
		// the catalog) or DEFAULT_INTERVAL. Marker means this runs once.
		$interval_marker = get_option( 'gip_rss_migration_v1_0_34_intervals' );
		if ( ! $interval_marker ) {
			$existing = get_option( self::OPTION_KEY, array() );
			if ( is_array( $existing ) && ! empty( $existing['feeds'] ) && is_array( $existing['feeds'] ) ) {
				$instance = self::instance();
				$changed  = false;
				foreach ( $existing['feeds'] as &$f ) {
					if ( empty( $f['url'] ) ) continue;
					if ( ! empty( $f['interval'] ) && in_array( $f['interval'], self::VALID_INTERVALS, true ) ) continue;
					$f['interval'] = $instance->get_recommended_interval_for_url( $f['url'] );
					$changed = true;
				}
				unset( $f ); // break the reference (PHP foreach-by-ref hygiene).
				if ( $changed ) {
					update_option( self::OPTION_KEY, $existing );
				}
			}
			update_option( 'gip_rss_migration_v1_0_34_intervals', time() );
		}

		// v1.0.29 — No more auto-seed of starter feeds on activation. Earlier
		// versions seeded the starter list whenever activate() ran with an
		// empty saved-feeds state. Users who *deliberately* deleted feeds and
		// then reinstalled the plugin (Deactivate → Delete → reinstall) had
		// their list silently rebuilt to the defaults — there is no way to
		// distinguish a genuine first install from a deliberate reset once
		// uninstall.php has wiped the option. Fresh installs now land on an
		// empty Feeds tab; the Catalog tab is the right place to populate.
		// The "Restore default feeds" button on the Feeds tab remains for
		// anyone who wants the starter list re-applied with one explicit
		// click.
	}

	/**
	 * Delete any SimplePie/WordPress feed cache transients. SimplePie keys
	 * its WP cache as feed_{md5(url)} and feed_mod_{md5(url)}; we wipe both
	 * scopes with a direct DB query because there are potentially many
	 * across the install (every plugin that calls fetch_feed leaves entries).
	 */
	public static function flush_feed_cache() {
		global $wpdb;
		if ( ! isset( $wpdb ) ) return;
		// PHPCS: direct $wpdb is intentional and unavoidable.
		// (1) DirectQuery / NoCaching — there is no WordPress API for "delete all
		//     transients matching a LIKE pattern"; the public delete_transient()
		//     requires the exact key, and we need to wipe every feed-cache row
		//     SimplePie left across the install. Caching the DELETE makes no
		//     sense — it's a fire-and-forget cleanup.
		// (2) The LIKE values are hard-coded constants (no user input), and the
		//     table identifier $wpdb->options is WordPress-controlled, so there
		//     is no injection surface here.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '\\_transient\\_feed\\_%'
			    OR option_name LIKE '\\_transient\\_timeout\\_feed\\_%'"
		);
	}

	/**
	 * Make sure the dedicated "RSS" category exists. Returns its term_id.
	 * Idempotent — safe to call many times.
	 */
	public static function ensure_rss_category() {
		$term = get_term_by( 'slug', self::RSS_CAT_SLUG, 'category' );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$created = wp_insert_term(
			self::RSS_CAT_NAME,
			'category',
			array(
				'slug'        => self::RSS_CAT_SLUG,
				'description' => 'Auto-created bucket for posts pulled in by the TheGridIndex RSS Importer.',
			)
		);
		if ( is_wp_error( $created ) ) {
			// Race: another process created it. Re-fetch.
			$term = get_term_by( 'slug', self::RSS_CAT_SLUG, 'category' );
			return $term ? (int) $term->term_id : 0;
		}
		return (int) $created['term_id'];
	}

	/**
	 * v1.0.40 — Ensure a granular category term exists (News, World, Tech,
	 * Business, Science). Returns its term_id. Idempotent. Called on
	 * demand during import rather than on activation so we don't pollute
	 * the category list with terms the user might never use.
	 *
	 * @param string $cat_key Catalog category name (e.g. "News", "Tech")
	 * @return int term_id or 0 on failure
	 */
	public function ensure_granular_category( $cat_key ) {
		if ( empty( self::GRANULAR_CATEGORIES[ $cat_key ] ) ) {
			return 0;
		}
		$cfg  = self::GRANULAR_CATEGORIES[ $cat_key ];
		$term = get_term_by( 'slug', $cfg['slug'], 'category' );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$created = wp_insert_term( $cfg['name'], 'category', array(
			'slug'        => $cfg['slug'],
			'description' => $cfg['desc'],
		) );
		if ( is_wp_error( $created ) ) {
			// Race or name collision: re-fetch by slug, then by name.
			$term = get_term_by( 'slug', $cfg['slug'], 'category' );
			if ( ! $term ) {
				$term = get_term_by( 'name', $cfg['name'], 'category' );
			}
			return $term ? (int) $term->term_id : 0;
		}
		return (int) $created['term_id'];
	}

	/**
	 * v1.0.38 — Create the persistent seen-GUIDs ledger table if it doesn't
	 * exist. Schema kept tiny: hash + first_seen + source_url. Uses dbDelta
	 * so future schema changes can be applied idempotently.
	 *
	 * The table survives WordPress post deletion (postmeta does NOT — when
	 * a post is permanently deleted, its meta rows go with it). Without
	 * this table, a deleted post would re-import on the next cron because
	 * the dedupe hash would be gone.
	 */
	public static function ensure_seen_table() {
		global $wpdb;
		$table   = $wpdb->prefix . self::SEEN_TABLE;
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			guid_hash CHAR(32) NOT NULL,
			first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			source_url VARCHAR(2048) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY guid_hash (guid_hash)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * v1.0.38 — Seed the seen-GUIDs ledger from existing postmeta. Runs
	 * once on activation of the v1.0.38 install so users upgrading from
	 * earlier versions don't see existing imports treated as "never seen"
	 * just because the table was empty when it was created.
	 */
	public static function backfill_seen_from_postmeta() {
		global $wpdb;
		$table = $wpdb->prefix . self::SEEN_TABLE;
		// PHPCS notes:
		// (1) InterpolatedNotPrepared: {$table} is composed from $wpdb->prefix
		//     (WordPress-controlled at install time) plus the hard-coded class
		//     constant self::SEEN_TABLE = 'gip_seen_guids'. Table identifiers
		//     cannot be passed via prepare() placeholders.
		// (2) DirectQuery / NoCaching: this is an admin/activation operation
		//     against our own custom ledger table; the object cache is the
		//     wrong tool for a one-time backfill, and there is no higher-level
		//     WP API for INSERT...SELECT into a custom table.
		// (3) slow_db_query_meta_key: looking up by meta_key is the entire
		//     point of the backfill (find every previously imported post by
		//     its GUID-hash meta). The query runs once on upgrade.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (guid_hash, first_seen)
			 SELECT pm.meta_value, COALESCE(p.post_date_gmt, NOW())
			 FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			   AND pm.meta_value <> ''",
			self::META_GUID
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}

	/**
	 * v1.0.38 — Has this GUID hash been seen before? Combines two sources:
	 *   1. The seen-GUIDs ledger (survives post deletion).
	 *   2. Existing postmeta — covers ANY status including trash. The
	 *      previous get_posts() lookup with status='any' excluded trash,
	 *      letting trashed posts re-import on the next fetch. We now scan
	 *      postmeta directly so trash is included.
	 * Returns true if either source has it.
	 */
	public function has_seen_guid( $guid_hash ) {
		global $wpdb;

		// Ledger check (persistent — covers permanently-deleted posts).
		$table = $wpdb->prefix . self::SEEN_TABLE;
		// PHPCS: {$table} interpolated for identifier (placeholders aren't
		// allowed for table names); $guid_hash is bound via %s placeholder.
		// Direct query is intentional — this is our own custom table and
		// gets a tight indexed lookup on the UNIQUE key. Hot path, called
		// once per feed item per fetch; object-caching the result would
		// add overhead without correctness benefit since the ledger only
		// grows and we already short-circuit on hit.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hit = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE guid_hash = %s LIMIT 1",
			$guid_hash
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $hit ) return true;

		// Postmeta check (covers trashed posts whose meta still exists).
		// PHPCS: direct postmeta query is intentional. The WP_Query
		// equivalent would build a meta_query that does effectively this
		// same scan, but with extra joins on wp_posts that we don't need;
		// we just need to know whether ANY row matches the meta_key+value.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pm = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			self::META_GUID,
			$guid_hash
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $pm;
	}

	/**
	 * v1.0.38 — Record a GUID hash in the ledger. Idempotent via the
	 * UNIQUE key (INSERT IGNORE silently skips dupes).
	 */
	public function record_seen_guid( $guid_hash, $source_url = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . self::SEEN_TABLE;
		// PHPCS: see has_seen_guid() above for the same rationale on direct
		// $wpdb queries against our custom ledger table.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (guid_hash, first_seen, source_url)
			 VALUES (%s, NOW(), %s)",
			$guid_hash,
			$source_url
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * v1.0.65 — Count the number of GUIDs currently in the ledger. Used by
	 * the admin UI to label the destructive "Reset" button with a live count
	 * so the user can see roughly how much history they're about to throw out.
	 * Returns 0 if the table doesn't exist yet (e.g. very fresh installs
	 * where activation hasn't completed).
	 */
	public function count_seen_guids() {
		global $wpdb;
		$table = $wpdb->prefix . self::SEEN_TABLE;
		// PHPCS: SHOW TABLES is the cheapest existence check; identifier
		// can't be parameterized via prepare(), so $table is bound as a
		// LIKE string literal via %s.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) return 0;
		// PHPCS: COUNT(*) on our own custom table; no user input, no
		// injection surface, and caching a row count for a continuously
		// growing ledger would be lying. See has_seen_guid() rationale.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * v1.0.65 — Destructive: wipe the seen-GUIDs ledger entirely.
	 *
	 * Why someone would want this:
	 *   The ledger remembers every GUID ever imported, even after the post
	 *   itself was deleted. That's deliberate — it means deleting a spammy
	 *   imported post doesn't cause it to come right back on the next cron
	 *   tick. But it also means users who delete imported posts to "clean
	 *   house" can't get them back via a normal re-import; the ledger
	 *   silently filters them as duplicates and the per-feed status reads
	 *   "0 new, N skipped" with no obvious explanation.
	 *
	 * What this does:
	 *   TRUNCATEs the ledger table. After this, every item currently in
	 *   every enabled feed becomes eligible for fresh import — including
	 *   items the user previously deleted. The next import run (manual
	 *   or scheduled) will import everything as if the plugin had never
	 *   seen those feeds before.
	 *
	 * Important nuance: postmeta still records the GUID hashes of any
	 *   live (un-deleted) imported posts. Those continue to be detected
	 *   by has_seen_guid()'s second-stage postmeta check, so a feed's
	 *   current items that match existing live posts will still be skipped
	 *   (correctly) as duplicates. The reset only re-enables import of
	 *   GUIDs whose posts no longer exist.
	 *
	 * Confirmation: the UI emits two stacked confirm() dialogs before
	 *   submitting; the second is intentionally worded as a hard
	 *   "are you sure" so the action is hard to do by accident.
	 */
	public function handle_reset_ledger() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		global $wpdb;
		$table = $wpdb->prefix . self::SEEN_TABLE;

		// Capture the row count before wiping so the success message is
		// informative ("Cleared 1,247 entries from the seen-GUIDs ledger.").
		$before = $this->count_seen_guids();

		// TRUNCATE is the right tool here: faster than DELETE, resets the
		// AUTO_INCREMENT, and doesn't touch postmeta. If TRUNCATE fails for
		// any reason (some hosts strip TRUNCATE permissions on the WP user),
		// fall back to DELETE. If the ledger is already empty, we still run
		// the query — it's a cheap no-op and lets us report a uniform success
		// message ("0 entries cleared") instead of branching on emptiness.
		//
		// PHPCS: identifier interpolation, direct query, no caching — all
		// intentional. The table identifier is composed from $wpdb->prefix
		// (WordPress-controlled) plus a hard-coded constant; no user input
		// reaches the SQL. TRUNCATE/DELETE on our own ledger table has no
		// higher-level WP API and there's nothing to cache about a wipe.
		$ok = false;
		$prev = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok   = false !== $wpdb->query( "TRUNCATE TABLE {$table}" );
		if ( ! $ok ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = false !== $wpdb->query( "DELETE FROM {$table}" );
		}
		$wpdb->suppress_errors( $prev );

		if ( $ok ) {
			$msg = sprintf(
				/* translators: %d: number of GUID entries cleared from the ledger */
				_n(
					'Reset complete — %d entry cleared from the seen-GUIDs ledger. Re-imports of previously deleted posts are now enabled.',
					'Reset complete — %d entries cleared from the seen-GUIDs ledger. Re-imports of previously deleted posts are now enabled.',
					$before,
					'thegridindex-rss-importer'
				),
				$before
			);
			$type = 'success';
		} else {
			$msg  = __( 'Could not reset the seen-GUIDs ledger. The database user may lack TRUNCATE/DELETE permission on the plugin table. Please contact your host or try again.', 'thegridindex-rss-importer' );
			$type = 'error';
		}

		wp_safe_redirect( add_query_arg( array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( $msg ),
			'gip_rss_type' => $type,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Restore the curated starter feed list (overwrites current feeds). */
	public function handle_restore_defaults() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$settings          = $this->get_settings();
		$settings['feeds'] = $this->get_starter_feeds();
		$this->save_settings( $settings );

		wp_safe_redirect( add_query_arg( array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( __( 'Default feed list restored.', 'thegridindex-rss-importer' ) ),
			'gip_rss_type' => 'success',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * v1.0.25 — Trim the saved feeds list down to MAX_ACTIVE_FEEDS by
	 * keeping the first N in saved order. Reached via the "Trim to 15"
	 * button on the over-cap warning in the Catalog tab.
	 *
	 * Saved-order is the simplest deterministic policy: whatever you added
	 * first stays, the most-recent extras get removed. Recoverable via the
	 * Catalog tab if you don't like the result.
	 */
	public function handle_trim_to_cap() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$s     = $this->get_settings();
		$feeds = is_array( $s['feeds'] ?? null ) ? $s['feeds'] : array();

		$before = count( $feeds );
		if ( $before <= self::MAX_ACTIVE_FEEDS ) {
			// Nothing to do.
			wp_safe_redirect( add_query_arg( array(
				'page'         => self::PAGE_SLUG,
				'gip_rss_msg'  => rawurlencode( __( 'Already at or below the cap. Nothing trimmed.', 'thegridindex-rss-importer' ) ),
				'gip_rss_type' => 'success',
			), admin_url( 'admin.php' ) ) . '#catalog' );
			exit;
		}

		$kept    = array_slice( $feeds, 0, self::MAX_ACTIVE_FEEDS );
		$removed = $before - count( $kept );

		$s['feeds'] = $kept;
		$this->save_settings( $s );

		wp_safe_redirect( add_query_arg( array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( sprintf(
				/* translators: 1: number removed, 2: cap */
				_n(
					'Trimmed %1$d feed. You now have %2$d active feeds.',
					'Trimmed %1$d feeds. You now have %2$d active feeds.',
					$removed, 'thegridindex-rss-importer'
				),
				$removed,
				count( $kept )
			) ),
			'gip_rss_type' => 'success',
		), admin_url( 'admin.php' ) ) . '#catalog' );
		exit;
	}

	/**
	 * v1.0.31 — Shared helper. Finds duplicate groups in the RSS category.
	 * Used by both the read-only Diagnostics card and the merge handler so
	 * the two can never disagree about what counts as a duplicate.
	 *
	 * @param int $limit Max posts to scan (most recent first). Default 2000.
	 * @return array {
	 *     groups: array<string, array<object>> keyed by normalized title,
	 *             values are arrays of post rows (ID, post_title, post_date,
	 *             source_name, guid_hash) sorted ASC by ID (oldest first).
	 *     scan_count: int — how many RSS posts were scanned
	 *     rss_cat_id: int — the RSS category ID, 0 if not found
	 * }
	 */
	/**
	 * v1.0.42 — Lightweight summary for the Feeds-tab banner. Returns
	 * counts only (not the full groups), cached in a transient so we
	 * don't re-run the full grouping on every Feeds-tab page load.
	 *
	 *   groups     — number of duplicate groups
	 *   surplus    — extra posts that would be trashed if merged
	 *                (e.g. group of 5 = 4 surplus)
	 *
	 * Cache TTL is short (3 minutes) so a merge or fresh import is
	 * reflected without manual purge.
	 *
	 * @return array{groups:int, surplus:int}
	 */
	public function count_duplicate_summary() {
		$cache_key = 'gip_rss_dup_summary';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['groups'], $cached['surplus'] ) ) {
			return $cached;
		}

		$result   = $this->find_duplicate_groups( 2000 );
		$groups_n = count( $result['groups'] );
		$surplus  = 0;
		foreach ( $result['groups'] as $members ) {
			$surplus += max( 0, count( $members ) - 1 );
		}
		$summary = array( 'groups' => $groups_n, 'surplus' => $surplus );
		set_transient( $cache_key, $summary, 3 * MINUTE_IN_SECONDS );
		return $summary;
	}

	public function find_duplicate_groups( $limit = 2000 ) {
		global $wpdb;
		$rss_cat = get_category_by_slug( self::RSS_CAT_SLUG );
		$rss_cat_id = $rss_cat ? (int) $rss_cat->term_id : 0;
		if ( ! $rss_cat_id ) {
			return array( 'groups' => array(), 'scan_count' => 0, 'rss_cat_id' => 0 );
		}

		// PHPCS: cross-table JOIN against term_relationships, term_taxonomy,
		// postmeta, and posts to find every post tagged with the RSS category
		// plus its source-name and guid-hash meta in a single round trip. The
		// WP_Query equivalent would need a tax_query + two meta_query lookups
		// and would still expand to roughly the same JOINs internally; this
		// expression is clearer and faster. Direct query is intentional.
		// All user-derived values ($rss_cat_id, $limit, meta key constant)
		// are bound via %d/%s placeholders; table identifiers are
		// WordPress-controlled.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_date,
			        srcname.meta_value AS source_name,
			        guidhash.meta_value AS guid_hash
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 LEFT JOIN {$wpdb->postmeta} srcname  ON srcname.post_id  = p.ID AND srcname.meta_key  = '_gridindex_source_name'
			 LEFT JOIN {$wpdb->postmeta} guidhash ON guidhash.post_id = p.ID AND guidhash.meta_key = %s
			 WHERE tt.term_id = %d
			   AND p.post_status IN ('publish','draft','pending')
			 ORDER BY p.post_date DESC
			 LIMIT %d",
			self::META_GUID,
			$rss_cat_id,
			(int) $limit
		) );

		$by_norm_title = array();
		foreach ( $rows as $r ) {
			$norm = strtolower( $r->post_title );
			$norm = preg_replace( '/[^a-z0-9]+/u', ' ', $norm );
			$norm = trim( preg_replace( '/\s+/', ' ', $norm ) );
			if ( $norm === '' ) continue;
			$by_norm_title[ $norm ][] = $r;
		}

		$groups = array();
		foreach ( $by_norm_title as $norm => $members ) {
			if ( count( $members ) < 2 ) continue;
			// Sort each group ASC by ID — keep the oldest, trash the rest.
			usort( $members, function( $a, $b ) { return (int) $a->ID - (int) $b->ID; } );
			$groups[ $norm ] = $members;
		}

		// Sort groups by size desc so large dupes show first in UI.
		uasort( $groups, function( $a, $b ) { return count( $b ) - count( $a ); } );

		return array(
			'groups'     => $groups,
			'scan_count' => count( $rows ),
			'rss_cat_id' => $rss_cat_id,
		);
	}

	/**
	 * v1.0.31 — Merge duplicate groups by trashing all but the oldest in each
	 * group. Goes to Trash (not permanent delete) so anything mis-grouped is
	 * recoverable from Posts → Trash for 30 days.
	 *
	 * Uses wp_trash_post() rather than wp_delete_post() — same difference as
	 * clicking "Trash" in the post list vs. "Delete Permanently."
	 */
	public function handle_merge_dupes() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$result = $this->find_duplicate_groups( 2000 );
		$groups = $result['groups'];

		$trashed = 0;
		$failed  = 0;
		foreach ( $groups as $members ) {
			// $members is sorted ASC by ID; keep [0], trash the rest.
			for ( $i = 1, $n = count( $members ); $i < $n; $i++ ) {
				$post_id = (int) $members[ $i ]->ID;
				if ( $post_id <= 0 ) { $failed++; continue; }
				$ok = wp_trash_post( $post_id );
				if ( $ok ) {
					$trashed++;
				} else {
					$failed++;
				}
			}
		}

		if ( $trashed === 0 && $failed === 0 ) {
			$msg  = __( 'No duplicates found to merge.', 'thegridindex-rss-importer' );
			$type = 'success';
		} else {
			$msg = sprintf(
				/* translators: 1: trashed count, 2: failed count */
				_n(
					'Merged duplicates: %1$d post moved to Trash.',
					'Merged duplicates: %1$d posts moved to Trash.',
					$trashed, 'thegridindex-rss-importer'
				),
				$trashed
			);
			if ( $failed > 0 ) {
				$msg .= ' ' . sprintf(
					/* translators: %d failed count */
					esc_html__( '(%d failed — see WP error log.)', 'thegridindex-rss-importer' ),
					$failed
				);
			}
			$msg .= ' ' . esc_html__( 'Restore any of them from Posts → Trash within 30 days.', 'thegridindex-rss-importer' );
			$type = 'success';
		}

		// v1.0.42 — Invalidate the dup-summary cache so the Feeds-tab banner
		// reflects the merge immediately.
		delete_transient( 'gip_rss_dup_summary' );

		wp_safe_redirect( add_query_arg( array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( $msg ),
			'gip_rss_type' => $type,
		), admin_url( 'admin.php' ) ) . '#diagnostics' );
		exit;
	}

	/**
	 * v1.0.24 — Toggle a catalog feed on or off. Adds the feed if it's not
	 * already in the saved list; removes it if it is. Enforces the
	 * MAX_ACTIVE_FEEDS cap when adding.
	 */
	public function handle_catalog_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$url = isset( $_REQUEST['feed_url'] ) ? esc_url_raw( wp_unslash( $_REQUEST['feed_url'] ) ) : '';
		if ( ! $url ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) ) . '#catalog' );
			exit;
		}

		// Find this URL in the catalog so we know its display name.
		$catalog_match = null;
		foreach ( $this->get_catalog_feeds() as $cf ) {
			if ( $cf['url'] === $url ) { $catalog_match = $cf; break; }
		}
		if ( ! $catalog_match ) {
			wp_safe_redirect( add_query_arg( array(
				'page'         => self::PAGE_SLUG,
				'gip_rss_msg'  => rawurlencode( __( 'Unknown catalog feed.', 'thegridindex-rss-importer' ) ),
				'gip_rss_type' => 'error',
			), admin_url( 'admin.php' ) ) . '#catalog' );
			exit;
		}

		$s     = $this->get_settings();
		$feeds = is_array( $s['feeds'] ?? null ) ? $s['feeds'] : array();

		// Is it already active?
		$active_idx = -1;
		foreach ( $feeds as $i => $f ) {
			if ( isset( $f['url'] ) && $f['url'] === $url ) { $active_idx = (int) $i; break; }
		}

		if ( $active_idx >= 0 ) {
			// Toggle OFF: remove from the active list.
			array_splice( $feeds, $active_idx, 1 );
			$msg  = sprintf(
				/* translators: %s feed name */
				__( '“%s” removed from your active feeds.', 'thegridindex-rss-importer' ),
				$catalog_match['name']
			);
			$type = 'success';
		} else {
			// Toggle ON: enforce the cap.
			if ( count( $feeds ) >= self::MAX_ACTIVE_FEEDS ) {
				$msg  = sprintf(
					/* translators: %d feed cap */
					__( 'You already have %d active feeds (the maximum). Remove one before adding another.', 'thegridindex-rss-importer' ),
					self::MAX_ACTIVE_FEEDS
				);
				$type = 'error';
			} else {
				$feeds[] = array(
					'url'      => $catalog_match['url'],
					'name'     => $catalog_match['name'],
					// v1.0.34 — use recommended interval from the catalog entry.
					'interval' => ! empty( $catalog_match['recommended_interval'] )
						&& in_array( $catalog_match['recommended_interval'], self::VALID_INTERVALS, true )
						? $catalog_match['recommended_interval']
						: self::DEFAULT_INTERVAL,
					// v1.0.40 — catalog category drives the granular post category.
					'category' => ! empty( $catalog_match['category'] ) && isset( self::GRANULAR_CATEGORIES[ $catalog_match['category'] ] )
						? $catalog_match['category']
						: '',
				);
				$msg  = sprintf(
					/* translators: %s feed name */
					__( '“%s” added to your active feeds.', 'thegridindex-rss-importer' ),
					$catalog_match['name']
				);
				$type = 'success';
			}
		}

		$s['feeds'] = array_values( $feeds );
		$this->save_settings( $s );

		// v1.0.35 — preserve view=list if the toggle was made from list view,
		// so the user stays in their chosen view after the redirect.
		$redirect_args = array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( $msg ),
			'gip_rss_type' => $type,
		);
		// PHPCS: read-only view-mode pass-through. The originating click
		// already passed check_admin_referer() via the wrapping handler;
		// 'view' just flows back into the redirect URL so the user lands
		// on the same view they were in.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['view'] ) && sanitize_key( wp_unslash( $_REQUEST['view'] ) ) === 'list' ) {
			$redirect_args['view'] = 'list';
		}
		// v1.0.36 — When the toggle ADDED a feed (not removed, not error),
		// pass the feed's index in the redirect so the toast can offer a
		// "Fetch now" action targeting that specific feed.
		if ( $active_idx < 0 && $type === 'success' ) {
			$new_idx = count( $s['feeds'] ) - 1; // the newly added feed sits at the end
			$redirect_args['gip_rss_added_idx']  = (int) $new_idx;
			$redirect_args['gip_rss_added_name'] = rawurlencode( $catalog_match['name'] );
		}
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . '#catalog' );
		exit;
	}

	/**
	 * Wipe the feeds array entirely. Keeps every other setting (post_status,
	 * frequency, image rules, etc.) — only the feeds list is cleared.
	 * Reached via the "Clear all feeds" button on the Feeds tab.
	 */
	public function handle_clear_feeds() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$s          = $this->get_settings();
		$count      = is_array( $s['feeds'] ?? null ) ? count( $s['feeds'] ) : 0;
		$s['feeds'] = array();
		$this->save_settings( $s );

		wp_safe_redirect( add_query_arg( array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( sprintf(
				/* translators: %d: number of feeds removed */
				_n( '%d feed removed.', '%d feeds removed.', $count, 'thegridindex-rss-importer' ),
				$count
			) ),
			'gip_rss_type' => 'success',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * One-click "set status to Publish + republish drafts" handler.
	 * Triggered from the prominent banner button on the admin page.
	 *
	 * Does two things in one click:
	 *   1. Saves post_status='publish' to settings (so future imports publish).
	 *   2. Republishes every existing draft tagged with our GUID hash meta
	 *      (so the existing backlog of drafts is fixed too).
	 */
	public function handle_set_publish() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$s                = $this->get_settings();
		$s['post_status'] = 'publish';
		$this->save_settings( $s );

		// Republish all existing imported drafts, same logic as handle_republish_drafts.
		// PHPCS: meta_query is necessary here — we specifically need to find
		// drafts that carry our GUID-hash meta (i.e., were imported by this
		// plugin) and not other drafts the user might have. The query runs
		// once on a one-time admin action, not on every page load.
		$drafts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'draft',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => array(
				array( 'key' => self::META_GUID, 'compare' => 'EXISTS' ),
			),
			'no_found_rows'  => true,
		) );
		$count = 0;
		foreach ( $drafts as $pid ) {
			$res = wp_update_post( array( 'ID' => (int) $pid, 'post_status' => 'publish' ), true );
			if ( ! is_wp_error( $res ) ) $count++;
		}

		wp_safe_redirect( add_query_arg( array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( sprintf(
				/* translators: %d: number of drafts republished */
				_n(
					'Set to Publish. %d existing draft was published.',
					'Set to Publish. %d existing drafts were published.',
					$count, 'thegridindex-rss-importer'
				),
				$count
			) ),
			'gip_rss_type' => 'success',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Bulk-publish every existing draft post that was imported by this
	 * plugin. Identified by the presence of the GUID hash meta we set on
	 * every import. Useful after a settings change (e.g. post_status was
	 * "draft" early on, you flipped to "publish", but the existing drafts
	 * are still drafts).
	 */
	public function handle_republish_drafts() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		// PHPCS: meta_query intentional — see handle_set_publish() rationale.
		// One-shot admin action, not a hot path.
		$drafts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'draft',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => array(
				array( 'key' => self::META_GUID, 'compare' => 'EXISTS' ),
			),
			'no_found_rows'  => true,
		) );

		$count = 0;
		foreach ( $drafts as $pid ) {
			$res = wp_update_post( array( 'ID' => (int) $pid, 'post_status' => 'publish' ), true );
			if ( ! is_wp_error( $res ) ) $count++;
		}

		wp_safe_redirect( add_query_arg( array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( sprintf(
				/* translators: %d: number of drafts republished */
				_n( '%d imported draft published.', '%d imported drafts published.', $count, 'thegridindex-rss-importer' ),
				$count
			) ),
			'gip_rss_type' => 'success',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public function get_defaults() {
		return array(
			'feeds'           => array(),       // array of array( url, name, category )
			'post_status'     => 'publish',     // publish | draft | pending — default publish so posts appear immediately
			'frequency'       => 'hourly',      // gip_rss_15min | hourly | twicedaily | manual
			'image_mode'      => 'feed_first',  // feed_first | content_first | none
			'min_image_width' => 1000,          // skip imports with images smaller than this (px wide)
			'max_per_run'     => 10,            // safety cap per feed per run
			'last_run'        => 0,
			'last_log'        => '',
			// v1.0.37 — Preserve settings, feeds, and GUID dedupe meta if the
			// user deletes the plugin. v1.0.49 — Default changed to FALSE for
			// WP.org submission compliance. WordPress.org plugin guidelines
			// require that uninstall.php remove all plugin data by default;
			// data persistence must be opt-in, not opt-out. Users who want to
			// preserve their feed list across delete-and-reinstall can check
			// the box on the Settings tab BEFORE uninstalling.
			'keep_on_uninstall' => false,
		);
	}

	/**
	 * Curated starter feeds — populated automatically on activation, or
	 * restored manually via the "Restore default feeds" button on the
	 * settings page. Categories are 0 (default) so the user can map them
	 * to whatever taxonomy they want without our assumptions.
	 *
	 * @return array
	 */
	public function get_starter_feeds() {
		return array(
			// World & general news (verified working)
			array( 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/HomePage.xml',  'name' => 'New York Times' ),
			// Google News top stories. Note: Google News links go through a
			// redirect (news.google.com/...) rather than directly to the
			// publisher, so the "Read at Source" attribution will point at
			// Google rather than the original outlet. Items also lack a
			// direct article image, so the min-image-width gate may skip
			// many entries depending on how much SimplePie can extract.
			array( 'url' => 'https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en',     'name' => 'Google News' ),
			array( 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/World.xml',     'name' => 'NYT World' ),
			array( 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/Business.xml',  'name' => 'NYT Business' ),
			array( 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/Technology.xml','name' => 'NYT Technology' ),
			array( 'url' => 'https://feeds.bbci.co.uk/news/world/rss.xml',                'name' => 'BBC World' ),
			array( 'url' => 'https://feeds.npr.org/1001/rss.xml',                         'name' => 'NPR News' ),
			array( 'url' => 'https://www.aljazeera.com/xml/rss/all.xml',                  'name' => 'Al Jazeera' ),
			array( 'url' => 'https://www.theguardian.com/world/rss',                      'name' => 'The Guardian World' ),
			array( 'url' => 'https://www.theguardian.com/us-news/rss',                    'name' => 'The Guardian US' ),
			// v1.0.63 — AP S3 community-mirror URL removed. WP.org reviewer
			// flagged it as a remote-file-call dependency, which is correct:
			// it's an unofficial S3 mirror that AP doesn't operate or endorse,
			// AP themselves retired official RSS in 2020, and the mirror's
			// uptime is outside the plugin author's control. Users who
			// specifically want AP coverage can add a bridge URL manually on
			// the Feeds tab; we don't ship a default that points at unsanctioned
			// infrastructure. (The catalog has not shipped AP since v1.0.49 for
			// the same reason — this just brings the activation-time starter
			// list into parity.)
			// Tech (full RSS, reliable)
			array( 'url' => 'https://techcrunch.com/feed/',                               'name' => 'TechCrunch' ),
			array( 'url' => 'https://www.theverge.com/rss/index.xml',                     'name' => 'The Verge' ),
			array( 'url' => 'https://feeds.arstechnica.com/arstechnica/index',            'name' => 'Ars Technica' ),
			array( 'url' => 'https://www.wired.com/feed/rss',                             'name' => 'Wired' ),
			array( 'url' => 'https://www.engadget.com/rss.xml',                           'name' => 'Engadget' ),
			array( 'url' => 'https://hnrss.org/frontpage',                                'name' => 'Hacker News' ),
			// AI-specific
			array( 'url' => 'https://openai.com/blog/rss.xml',                            'name' => 'OpenAI' ),
			array( 'url' => 'https://blog.google/technology/ai/rss/',                     'name' => 'Google AI' ),
			array( 'url' => 'https://huggingface.co/blog/feed.xml',                       'name' => 'Hugging Face' ),
		);
	}

	/**
	 * v1.0.27 — Catalog reshaped per user direction: news-heavy (USA + world
	 * + politics), less tech, AI/Culture sections removed entirely. Reuters
	 * intentionally excluded (RSS retired in 2020). Verified URLs as of
	 * May 2026 where possible; URLs from less-recently-checked publishers
	 * (CBS, ABC, AP) follow long-stable canonical patterns confirmed via
	 * 2026 third-party feed directories.
	 *
	 * @return array of arrays { url, name, category }
	 */
	public function get_catalog_feeds() {
		return array(
			// News (18) — major US national, world, and politics-heavy publishers.
			// v1.0.34 — recommended_interval values reflect publishing volume:
			//   5min   → wire services (Google News, AP, BBC World, Politico, Al Jazeera)
			//   15min  → high-volume newspapers (NYT, WaPo, Guardian, NPR, USA Today, etc.)
			//   30min  → tech sites (bursty but not minute-paced)
			//   hourly → business / weekly / slow desks (FT, HBR, Fast Co., Forbes, Bloomberg, WSJ)
			array( 'category' => 'News', 'name' => 'New York Times',         'recommended_interval' => '15min', 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/HomePage.xml' ),
			array( 'category' => 'News', 'name' => 'NYT World',              'recommended_interval' => '15min', 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/World.xml' ),
			array( 'category' => 'News', 'name' => 'NYT Politics',           'recommended_interval' => '15min', 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/Politics.xml' ),
			array( 'category' => 'News', 'name' => 'BBC World',              'recommended_interval' => '5min',  'url' => 'https://feeds.bbci.co.uk/news/world/rss.xml' ),
			array( 'category' => 'News', 'name' => 'BBC US & Canada',        'recommended_interval' => '15min', 'url' => 'https://feeds.bbci.co.uk/news/world/us_and_canada/rss.xml' ),
			array( 'category' => 'News', 'name' => 'The Guardian World',     'recommended_interval' => '15min', 'url' => 'https://www.theguardian.com/world/rss' ),
			array( 'category' => 'News', 'name' => 'The Guardian US',        'recommended_interval' => '15min', 'url' => 'https://www.theguardian.com/us-news/rss' ),
			array( 'category' => 'News', 'name' => 'NPR News',               'recommended_interval' => '15min', 'url' => 'https://feeds.npr.org/1001/rss.xml' ),
			array( 'category' => 'News', 'name' => 'NPR Politics',           'recommended_interval' => '15min', 'url' => 'https://feeds.npr.org/1014/rss.xml' ),
			array( 'category' => 'News', 'name' => 'Al Jazeera',             'recommended_interval' => '5min',  'url' => 'https://www.aljazeera.com/xml/rss/all.xml' ),
			array( 'category' => 'News', 'name' => 'Google News',            'recommended_interval' => '5min',  'url' => 'https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en' ),
			array( 'category' => 'News', 'name' => 'USA Today',              'recommended_interval' => '15min', 'url' => 'https://rssfeeds.usatoday.com/usatoday-NewsTopStories' ),
			array( 'category' => 'News', 'name' => 'Washington Post',        'recommended_interval' => '15min', 'url' => 'https://feeds.washingtonpost.com/rss/national' ),
			array( 'category' => 'News', 'name' => 'WaPo Politics',          'recommended_interval' => '15min', 'url' => 'https://feeds.washingtonpost.com/rss/politics' ),
			// v1.0.49 — AP News and Reuters World removed. Both publishers
			// retired their official RSS feeds; the catalog previously
			// shipped community bridge URLs via rsshub.app. For WP.org
			// submission compliance, the catalog should not point users at
			// third-party services they haven't been disclosed about, and
			// the bridges themselves are unreliable (volunteer-run, outside
			// the publisher's control). Users who specifically want either
			// source can add a bridge URL manually on the Feeds tab.
			array( 'category' => 'News', 'name' => 'ABC News',               'recommended_interval' => '15min', 'url' => 'https://abcnews.go.com/abcnews/topstories' ),
			array( 'category' => 'News', 'name' => 'CBS News',               'recommended_interval' => '15min', 'url' => 'https://www.cbsnews.com/latest/rss/main' ),
			array( 'category' => 'News', 'name' => 'Politico',               'recommended_interval' => '5min',  'url' => 'https://rss.politico.com/politics-news.xml' ),
			// v1.0.35 — News expansion (8 added).
			array( 'category' => 'News', 'name' => 'NBC News',               'recommended_interval' => '5min',  'url' => 'https://feeds.nbcnews.com/nbcnews/public/news' ),
			// CNN removed in v1.0.41 — CNN deprecated their RSS feeds in 2024.
			// The previous URL (rss.cnn.com/rss/edition.rss) still resolves
			// but returns an empty or stale feed. cnn.com/services/rss/ now
			// 302s to the homepage. No usable official replacement exists.
			array( 'category' => 'News', 'name' => 'The Hill',               'recommended_interval' => '15min', 'url' => 'https://thehill.com/news/feed/' ),
			array( 'category' => 'News', 'name' => 'ProPublica',             'recommended_interval' => 'hourly','url' => 'https://feeds.propublica.org/propublica/main' ),
			array( 'category' => 'News', 'name' => 'Time',                   'recommended_interval' => '30min', 'url' => 'https://time.com/feed/' ),
			array( 'category' => 'News', 'name' => 'Bloomberg Politics',     'recommended_interval' => '15min', 'url' => 'https://feeds.bloomberg.com/politics/news.rss' ),
			array( 'category' => 'News', 'name' => 'LA Times',               'recommended_interval' => '15min', 'url' => 'https://www.latimes.com/local/rss2.0.xml' ),

			// Tech (9) — bursty publishing, 30 min is plenty.
			array( 'category' => 'Tech', 'name' => 'TechCrunch',  'recommended_interval' => '30min', 'url' => 'https://techcrunch.com/feed/' ),
			array( 'category' => 'Tech', 'name' => 'The Verge',   'recommended_interval' => '30min', 'url' => 'https://www.theverge.com/rss/index.xml' ),
			array( 'category' => 'Tech', 'name' => 'Ars Technica','recommended_interval' => '30min', 'url' => 'https://feeds.arstechnica.com/arstechnica/index' ),
			array( 'category' => 'Tech', 'name' => 'Wired',       'recommended_interval' => '30min', 'url' => 'https://www.wired.com/feed/rss' ),
			array( 'category' => 'Tech', 'name' => 'Engadget',    'recommended_interval' => '30min', 'url' => 'https://www.engadget.com/rss.xml' ),
			array( 'category' => 'Tech', 'name' => 'Hacker News', 'recommended_interval' => '30min', 'url' => 'https://hnrss.org/frontpage' ),
			// v1.0.35 — Tech expansion (3 added).
			array( 'category' => 'Tech', 'name' => '9to5Mac',     'recommended_interval' => '30min', 'url' => 'https://9to5mac.com/feed/' ),
			array( 'category' => 'Tech', 'name' => 'MIT Tech Review', 'recommended_interval' => 'hourly','url' => 'https://www.technologyreview.com/feed/' ),
			array( 'category' => 'Tech', 'name' => 'ZDNet',       'recommended_interval' => '30min', 'url' => 'https://www.zdnet.com/news/rss.xml' ),

			// Business (8) — slower cadence, hourly is honest.
			array( 'category' => 'Business', 'name' => 'Bloomberg Technology',   'recommended_interval' => 'hourly', 'url' => 'https://feeds.bloomberg.com/technology/news.rss' ),
			array( 'category' => 'Business', 'name' => 'Financial Times',        'recommended_interval' => 'hourly', 'url' => 'https://www.ft.com/rss/home' ),
			array( 'category' => 'Business', 'name' => 'Harvard Business Review','recommended_interval' => 'hourly', 'url' => 'https://hbr.org/feed' ),
			array( 'category' => 'Business', 'name' => 'Fast Company',           'recommended_interval' => 'hourly', 'url' => 'https://www.fastcompany.com/latest/rss' ),
			array( 'category' => 'Business', 'name' => 'Forbes Innovation',      'recommended_interval' => 'hourly', 'url' => 'https://www.forbes.com/innovation/feed/' ),
			// WSJ feed publishes headlines + summaries, but article links
			// require a paid subscription to read in full.
			array( 'category' => 'Business', 'name' => 'WSJ Markets',          'recommended_interval' => 'hourly', 'url' => 'https://feeds.a.dj.com/rss/RSSMarketsMain.xml' ),
			// v1.0.35 — Business expansion (2 added).
			array( 'category' => 'Business', 'name' => 'MarketWatch',         'recommended_interval' => '30min', 'url' => 'https://feeds.marketwatch.com/marketwatch/topstories/' ),
			array( 'category' => 'Business', 'name' => 'CNBC Top News',       'recommended_interval' => '15min', 'url' => 'https://www.cnbc.com/id/100003114/device/rss/rss.html' ),

			// v1.0.35 — Science (3, new section).
			array( 'category' => 'Science', 'name' => 'Science Daily',       'recommended_interval' => 'hourly','url' => 'https://www.sciencedaily.com/rss/all.xml' ),
			array( 'category' => 'Science', 'name' => 'Ars Technica Science','recommended_interval' => 'hourly','url' => 'https://feeds.arstechnica.com/arstechnica/science' ),
			array( 'category' => 'Science', 'name' => 'NASA News',           'recommended_interval' => 'hourly','url' => 'https://www.nasa.gov/feed/' ),

			// v1.0.35 — World/Regional (4, new section) — international and Commonwealth desks.
			array( 'category' => 'World', 'name' => 'Deutsche Welle (EN)',   'recommended_interval' => '15min', 'url' => 'https://rss.dw.com/rdf/rss-en-all' ),
			array( 'category' => 'World', 'name' => 'France 24 (EN)',        'recommended_interval' => '15min', 'url' => 'https://www.france24.com/en/rss' ),
			array( 'category' => 'World', 'name' => 'CBC News (Canada)',     'recommended_interval' => '15min', 'url' => 'https://www.cbc.ca/cmlink/rss-topstories' ),
			array( 'category' => 'World', 'name' => 'ABC News (Australia)',  'recommended_interval' => '15min', 'url' => 'https://www.abc.net.au/news/feed/51120/rss.xml' ),
		);
	}

	/**
	 * v1.0.34 — Look up the recommended interval for a feed URL. Returns
	 * the catalog recommendation if known, otherwise DEFAULT_INTERVAL.
	 */
	public function get_recommended_interval_for_url( $url ) {
		foreach ( $this->get_catalog_feeds() as $cf ) {
			if ( $cf['url'] === $url && ! empty( $cf['recommended_interval'] ) ) {
				return $cf['recommended_interval'];
			}
		}
		return self::DEFAULT_INTERVAL;
	}

	/**
	 * v1.0.34 — Friendly label for an interval slug ("Every 5 min", etc).
	 */
	public function interval_label( $interval ) {
		switch ( $interval ) {
			case '5min':   return __( 'Every 5 min',   'thegridindex-rss-importer' );
			case '15min':  return __( 'Every 15 min',  'thegridindex-rss-importer' );
			case '30min':  return __( 'Every 30 min',  'thegridindex-rss-importer' );
			case 'hourly': return __( 'Every hour',    'thegridindex-rss-importer' );
		}
		return $interval;
	}

	public function get_settings() {
		$saved    = get_option( self::OPTION_KEY, array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $this->get_defaults() );

		// v1.0.13 — defensive normalization. If the saved post_status is anything
		// other than one of our three valid values (e.g. the option got written
		// with a legacy/empty/typo value, or a future schema change leaked in),
		// fall back to the documented default rather than letting wp_insert_post
		// silently treat it as something we didn't intend.
		$valid_status = array( 'publish', 'draft', 'pending' );
		if ( ! in_array( $settings['post_status'] ?? '', $valid_status, true ) ) {
			$settings['post_status'] = 'publish';
		}

		return $settings;
	}

	public function save_settings( array $settings ) {
		update_option( self::OPTION_KEY, $settings, false );
	}

	/**
	 * Resolve the active color mode from the global Grid Index theme options
	 * so this page tracks the same dark/light setting as Theme Options.
	 *
	 * @return string 'dark' or 'light'
	 */
	private function color_mode() {
		if ( function_exists( 'gridindex_get_option' ) ) {
			$mode = (string) gridindex_get_option( 'color_mode', 'dark' );
		} else {
			$opts = get_option( 'gridindex_theme_options', array() );
			$mode = isset( $opts['color_mode'] ) ? (string) $opts['color_mode'] : 'dark';
		}
		return ( $mode === 'light' ) ? 'light' : 'dark';
	}

	/* ---------------------------------------------------------------------
	 * Cron
	 * ------------------------------------------------------------------- */

	public function register_cron_schedules( $schedules ) {
		// v1.0.33 — Added 5min and 30min options for breaking-news-heavy sites.
		// See WP-Cron caveat warning in the Settings UI: actual execution
		// depends on site traffic for triggering wp-cron.php, so a 5-min
		// schedule is best paired with a real Linux cron job at the host.
		if ( ! isset( $schedules['gip_rss_5min'] ) ) {
			$schedules['gip_rss_5min'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 Minutes (TheGridIndex RSS)', 'thegridindex-rss-importer' ),
			);
		}
		if ( ! isset( $schedules['gip_rss_15min'] ) ) {
			$schedules['gip_rss_15min'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 Minutes (TheGridIndex RSS)', 'thegridindex-rss-importer' ),
			);
		}
		if ( ! isset( $schedules['gip_rss_30min'] ) ) {
			$schedules['gip_rss_30min'] = array(
				'interval' => 30 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 30 Minutes (TheGridIndex RSS)', 'thegridindex-rss-importer' ),
			);
		}
		return $schedules;
	}

	public function maybe_reschedule_cron() {
		$s = $this->get_settings();
		$freq = $s['frequency'];

		if ( 'manual' === $freq ) {
			$this->unschedule_cron();
			return;
		}

		$valid = array( 'gip_rss_5min', 'gip_rss_15min', 'gip_rss_30min', 'hourly', 'twicedaily' );
		if ( ! in_array( $freq, $valid, true ) ) {
			$freq = 'hourly';
		}

		$next             = wp_next_scheduled( self::CRON_HOOK );
		$current_schedule = wp_get_schedule( self::CRON_HOOK );
		if ( $current_schedule !== $freq ) {
			$this->unschedule_cron();
			wp_schedule_event( time() + 60, $freq, self::CRON_HOOK );
		} elseif ( ! $next ) {
			wp_schedule_event( time() + 60, $freq, self::CRON_HOOK );
		}
	}

	public function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ---------------------------------------------------------------------
	 * Admin page registration + asset enqueue
	 * ------------------------------------------------------------------- */

	public function register_admin_page() {
		// v1.0.51 — Detect the theme's parent menu by walking $GLOBALS['menu']
		// directly, which is the authoritative menu structure WordPress uses
		// to render the sidebar. The previous check (v1.0.46–v1.0.50) looked
		// at $GLOBALS['admin_page_hooks'][$parent_slug], but that global is
		// keyed by HOOK SUFFIX (e.g., 'toplevel_page_gridindex'), not by raw
		// slug. The check therefore always returned false — even when The
		// Grid Index theme was active — and the plugin always took the
		// standalone-top-level branch. That's why menu position 25 wasn't
		// applying for users WITH the theme: they were silently in the
		// fallback branch, which itself was working, but they expected the
		// nested submenu placement.
		//
		// $GLOBALS['menu'] is an array of [position] => [title, cap, slug, ...]
		// entries — index 2 is the slug. We scan it for our parent slug.
		$parent_slug   = 'gridindex';
		$parent_exists = false;
		if ( isset( $GLOBALS['menu'] ) && is_array( $GLOBALS['menu'] ) ) {
			foreach ( $GLOBALS['menu'] as $entry ) {
				if ( isset( $entry[2] ) && $entry[2] === $parent_slug ) {
					$parent_exists = true;
					break;
				}
			}
		}

		if ( $parent_exists ) {
			$this->hook_suffix = add_submenu_page(
				$parent_slug,
				__( 'Grid RSS', 'thegridindex-rss-importer' ),     // browser tab title
				__( 'Grid RSS', 'thegridindex-rss-importer' ),     // menu label
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_admin_page' )
			);
		} else {
			// v1.0.62 — Standalone fallback (theme inactive) now registers
			// under Settings instead of as a top-level menu item. Per WP.org
			// review guidance: configuration pages belong under Settings;
			// top-level menu entries should be reserved for plugins whose
			// primary surface is heavily-used content management (e.g.
			// WooCommerce). Previously this fell back to add_menu_page() at
			// position 25, which sits alongside Comments and competes with
			// core items for visibility. Submenu placement under "Grid Index"
			// (when the theme is active) is unchanged — that's the preferred
			// home; Settings is the standalone fallback.
			$this->hook_suffix = add_options_page(
				__( 'Grid RSS', 'thegridindex-rss-importer' ),
				__( 'Grid RSS', 'thegridindex-rss-importer' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_admin_page' )
			);
		}

		// Stylesheet enqueue. v1.0.50 — Load the BUNDLED copy of theme-options.css
		// from the plugin's own assets/admin/ first, so the UI renders correctly
		// even when The Grid Index theme is not the active theme (WP.org submission
		// requires the plugin to work standalone — depending on a specific theme
		// being active is grounds for rejection). The bundled copy is a snapshot;
		// if the theme IS active and has a newer version, we layer it on top so
		// theme updates can still customize the look. The original mistake was
		// using get_template_directory() which only resolves to The Grid Index
		// when it's the active theme — when a different theme was active, the
		// path returned someone else's theme directory, the file didn't exist,
		// the enqueue silently failed, and every admin page rendered as plain
		// WordPress admin without any of our card / pill / button styling.
		add_action( 'admin_enqueue_scripts', function( $screen_hook ) {
			if ( ! $this->is_importer_screen() ) return;

			$ver           = THEGRIDINDEX_RSS_IMPORTER_VERSION;
			$bundled_path  = plugin_dir_path( __FILE__ ) . 'assets/admin/theme-options.css';
			$bundled_url   = plugin_dir_url( __FILE__ ) . 'assets/admin/theme-options.css';

			// v1.0.51 — Defensive admin notice if the bundled CSS is missing.
			// On some hosts, the "Replace current plugin" upload path has been
			// observed to skip subdirectories — leaving the plugin's PHP files
			// in place but never extracting the assets/ folder. Without the
			// stylesheet, the entire admin UI renders unstyled. This notice
			// makes the failure visible instead of silent.
			if ( ! file_exists( $bundled_path ) ) {
				add_action( 'admin_notices', function() use ( $bundled_path ) {
					echo '<div class="notice notice-error"><p><strong>TheGridIndex RSS Importer:</strong> Bundled stylesheet missing at <code>' . esc_html( $bundled_path ) . '</code>. The plugin upload may have skipped the <code>assets/</code> folder. Please re-upload the plugin or extract manually.</p></div>';
				} );
				return;
			}

			// Always load the bundled copy as the base layer.
			wp_enqueue_style(
				'gip-rss-importer-shell',
				$bundled_url,
				array(),
				$ver . '.' . filemtime( $bundled_path )
			);

			// If The Grid Index theme is active AND has its own copy, layer it
			// on top so theme updates can override individual rules. Detected by
			// matching the active stylesheet directory name against the theme
			// slug — guarding against other themes that happen to ship a file
			// at the same path.
			$theme        = wp_get_theme();
			$is_grid_idx  = ( $theme->get_stylesheet() === 'the-grid-index' || $theme->get_template() === 'the-grid-index' );
			if ( $is_grid_idx ) {
				$theme_css = get_template_directory() . '/assets/admin/theme-options.css';
				if ( file_exists( $theme_css ) ) {
					wp_enqueue_style(
						'gip-rss-importer-shell-theme',
						get_template_directory_uri() . '/assets/admin/theme-options.css',
						array( 'gip-rss-importer-shell' ),
						$ver . '.' . filemtime( $theme_css )
					);
				}
			}

			// v1.0.63 — Importer-specific admin styles and behaviors. Previously
			// emitted inline from render_admin_page() (a <style> block of ~1,070
			// lines and a <script> block of ~432 lines). WP.org review flagged
			// the inline tags; both have been extracted to standalone files in
			// /assets/admin/ and are loaded via the WordPress enqueue APIs.
			$admin_css_path = plugin_dir_path( __FILE__ ) . 'assets/admin/admin.css';
			$admin_js_path  = plugin_dir_path( __FILE__ ) . 'assets/admin/admin.js';
			$admin_css_url  = plugin_dir_url( __FILE__ )  . 'assets/admin/admin.css';
			$admin_js_url   = plugin_dir_url( __FILE__ )  . 'assets/admin/admin.js';

			if ( file_exists( $admin_css_path ) ) {
				// Layered AFTER the shell (and after the theme override, if any)
				// so the importer-specific rules win where they need to.
				$deps = array( 'gip-rss-importer-shell' );
				if ( $is_grid_idx && isset( $theme_css ) && file_exists( $theme_css ) ) {
					$deps[] = 'gip-rss-importer-shell-theme';
				}
				wp_enqueue_style(
					'gip-rss-importer-admin',
					$admin_css_url,
					$deps,
					$ver . '.' . filemtime( $admin_css_path )
				);
			}

			if ( file_exists( $admin_js_path ) ) {
				wp_enqueue_script(
					'gip-rss-importer-admin',
					$admin_js_url,
					array(),
					$ver . '.' . filemtime( $admin_js_path ),
					true // load in footer so the DOM exists when the IIFE runs.
				);
				// Dynamic config previously inlined as <script>window.gipRssCfg = {...}</script>.
				// wp_localize_script() emits this as a small inline <script> tag
				// keyed to the enqueued handle, which is the WP.org-blessed
				// pattern for passing PHP values to JS (in contrast to the raw
				// inline <script> block the reviewer flagged).
				wp_localize_script(
					'gip-rss-importer-admin',
					'gipRssCfg',
					array(
						'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
						'saveAction'     => self::PAGE_SLUG . '_ajax_save',
						'progressAction' => self::PAGE_SLUG . '_ajax_progress',
						'fetchOneAction' => self::PAGE_SLUG . '_ajax_fetch_one',
						'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
						'i18n'           => array(
							'saving' => __( 'Saving…', 'thegridindex-rss-importer' ),
							'saved'  => __( 'Saved', 'thegridindex-rss-importer' ),
							'error'  => __( 'Save failed', 'thegridindex-rss-importer' ),
						),
					)
				);
			}
		} );

		// Drop wp-admin's left/right padding around our shell, like Theme Options does.
		// v1.0.68 — Also emit the current color-mode class on the body so the
		// stylesheet can paint #wpbody-content (the wp-admin container behind
		// our wrap) to match. Without this the area below our wrap shows
		// wp-admin's default white background, creating a visible band when
		// the user scrolls past our card stack — see screenshot from QA.
		add_filter( 'admin_body_class', function( $c ) {
			if ( $this->is_importer_screen() ) {
				$c .= ' gi-options-host';
				$c .= ' gi-options-host--' . $this->color_mode();
			}
			return $c;
		} );
	}

	/* ---------------------------------------------------------------------
	 * Admin page render
	 * ------------------------------------------------------------------- */

	/**
	 * v1.0.47 — Detect whether the current admin request is rendering the
	 * Grid RSS page. Used by CSS enqueue and body-class hooks. Robust to
	 * different menu placements (top-level, Grid Index submenu, future
	 * relocations) because it matches by the `page` query arg rather than
	 * the captured hook suffix.
	 *
	 * Returns true when either:
	 *   - The current URL contains `?page=gip-rss-importer` (catches the
	 *     normal admin page load and admin-post.php / admin-ajax.php).
	 *   - get_current_screen() reports a screen ID that ends in our slug
	 *     (catches edge cases after late hook switches).
	 */
	private function is_importer_screen() {
		// PHPCS: read-only check of which admin page we're on. This is not
		// form processing — it's a "should I enqueue assets here?" gate.
		// No nonce is required (or even meaningful) to inspect $_GET['page'];
		// that's how WordPress itself routes to admin pages.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === self::PAGE_SLUG ) {
			return true;
		}
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && is_string( $screen->id ) ) {
				// Match anything like *_page_gip-rss-importer (top-level,
				// submenu, tools, options — all end in our slug).
				if ( substr( $screen->id, -strlen( self::PAGE_SLUG ) ) === self::PAGE_SLUG ) {
					return true;
				}
			}
		}
		return false;
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$s          = $this->get_settings();
		$mode       = $this->color_mode();
		$mode_class = 'gi-mode-' . $mode;
		$mode_label = ucfirst( $mode );
		$next_run   = wp_next_scheduled( self::CRON_HOOK );

		// PHPCS: these are display-only reads, populated by our own redirect
		// handlers after they finish a write. The originating handlers all
		// run check_admin_referer() before writing; the redirect carries the
		// resulting human-readable message back to this page for display
		// only. Re-verifying a nonce on the display side would require
		// re-signing the message in the redirect, which adds zero security
		// (the value is already passed through sanitize_text_field() and
		// then esc_html'd or esc_attr'd at the use site). Reasoning per
		// WordPress's own Settings API admin notices, which use the same
		// "settings_updated"-style URL parameters without nonces.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg  = isset( $_GET['gip_rss_msg'] )  ? sanitize_text_field( wp_unslash( $_GET['gip_rss_msg'] ) )  : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['gip_rss_type'] ) ? sanitize_text_field( wp_unslash( $_GET['gip_rss_type'] ) ) : 'success';

		$save_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap gridindex-theme-options <?php echo esc_attr( $mode_class ); ?>">

			<div class="gi-hero">
				<div class="gi-hero__inner">
					<div class="gi-hero__brand">
						<?php
						// v1.0.68 — Removed the "THEGRIDINDEX" eyebrow above
						// the title per the brand simplification request.
						// v1.0.69 — Per follow-up direction, the hero title
						// is the FULL plugin display name ("TheGridIndex RSS
						// Importer") to match the v1.0.67 trademark-clearance
						// rename. The standalone teal accent rule above the
						// title remains as the visual signature element.
						// Title font-size is tuned down from 42px → 36px and
						// max-width is removed so the longer title can either
						// fit on one line on a typical desktop or wrap once
						// at the brand/product boundary on narrower screens.
						?>
						<span class="gi-hero__accent-rule" aria-hidden="true"></span>
						<h1 class="gi-hero__title"><?php esc_html_e( 'TheGridIndex RSS Importer', 'thegridindex-rss-importer' ); ?></h1>
						<p class="gi-hero__sub"><?php esc_html_e( 'Pull headlines from external feeds into your WordPress site. Imported posts are auto-tagged with their original source so The Grid Index theme (when active) shows attribution and respects the "Hide comments on imported RSS" toggle.', 'thegridindex-rss-importer' ); ?></p>
					</div>
					<div class="gi-hero__meta">
						<span class="gi-badge <?php echo $mode === 'dark' ? 'gi-badge--dark' : ''; ?>">
							<?php
							/* translators: %s: current color mode label (Light or Dark). */
							printf( esc_html__( 'Mode: %s', 'thegridindex-rss-importer' ), esc_html( $mode_label ) ); ?>
						</span>
						<span class="gi-badge"><?php
							/* translators: %s: plugin version number (e.g. "1.0.66"). */
							printf( esc_html__( 'v%s', 'thegridindex-rss-importer' ), esc_html( THEGRIDINDEX_RSS_IMPORTER_VERSION ) ); ?></span>
						<?php
						// v1.0.53 — Theme pairing reference. v1.0.54 — Only show
						// when theme is INACTIVE. v1.0.59 briefly made it a link
						// to thegridindex.com; v1.0.60 reverts to the passive
						// muted indicator per direction — informational only, no
						// link, no CTA. The theme isn't on WordPress.org yet so
						// there's no useful destination to point at.
						$active_theme = wp_get_theme();
						$theme_active = ( $active_theme->get_stylesheet() === 'the-grid-index'
							|| $active_theme->get_template() === 'the-grid-index' );
						if ( ! $theme_active ) :
							$theme_pill_title = __( 'Designed to pair with The Grid Index theme. The plugin works standalone, but theme-specific features (Read at Source button, hide-comments toggle) only activate when the theme is.', 'thegridindex-rss-importer' );
						?>
							<span class="gi-badge gi-badge--muted" title="<?php echo esc_attr( $theme_pill_title ); ?>">
								<?php esc_html_e( 'Theme: The Grid Index — not active', 'thegridindex-rss-importer' ); ?>
							</span>
						<?php endif; ?>
						<?php
						$rss_term_badge = get_term_by( 'slug', self::RSS_CAT_SLUG, 'category' );
						if ( $rss_term_badge && ! is_wp_error( $rss_term_badge ) ) :
							$badge_link = admin_url( 'edit.php?category_name=' . self::RSS_CAT_SLUG );
						?>
							<a class="gi-badge" style="text-decoration:none;" href="<?php echo esc_url( $badge_link ); ?>">
								<?php
								printf(
									/* translators: 1: post count */
									esc_html__( 'Category: RSS (%d)', 'thegridindex-rss-importer' ),
									(int) $rss_term_badge->count
								);
								?>
							</a>
						<?php endif; ?>
						<?php if ( $next_run && $s['frequency'] !== 'manual' ) : ?>
							<span class="gi-badge gi-badge--success">
								<?php
								printf(
									/* translators: %s: human time difference */
									esc_html__( 'Next run in %s', 'thegridindex-rss-importer' ),
									esc_html( human_time_diff( time(), $next_run ) )
								);
								?>
							</span>
						<?php elseif ( $s['frequency'] === 'manual' ) : ?>
							<span class="gi-badge gi-badge--warning">
								<?php esc_html_e( 'Manual only', 'thegridindex-rss-importer' ); ?>
							</span>
						<?php endif; ?>
						<?php
						// v1.0.48 — Exact date+time of the last completed import,
						// shown as a green pill alongside the existing pills.
						// Uses wp_date() so it respects the site's timezone and the
						// configured date/time format from Settings → General.
						if ( ! empty( $s['last_run'] ) ) :
							$last_run_fmt = wp_date(
								get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
								(int) $s['last_run']
							);
						?>
							<span class="gi-badge gi-badge--success" title="<?php
								/* translators: %s: relative time, e.g. "5 minutes ago" */
								printf( esc_attr__( '%s ago', 'thegridindex-rss-importer' ), esc_attr( human_time_diff( (int) $s['last_run'], time() ) ) );
							?>">
								<?php
								printf(
									/* translators: %s: localized date+time */
									esc_html__( 'Last run: %s', 'thegridindex-rss-importer' ),
									esc_html( $last_run_fmt )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php
			// v1.0.15 — Prominent status banner. Shows the current import
			// post_status front and center. If it's not 'publish', surface a
			// big one-click button that flips the setting AND republishes
			// existing drafts. We landed here because the buried Import
			// Settings dropdown was easy to miss.
			$current_status = $s['post_status'] ?? 'publish';
			?>
			<?php
			// v1.0.20 — Status indicator. When everything is fine (post_status
			// === 'publish'), this is a quiet inline pill, not a full-width
			// banner — the page shouldn't shout about a normal state. When
			// attention IS needed (draft/pending), keep the loud full-width
			// warning with the one-click fix button.
			$current_status = $s['post_status'] ?? 'publish';
			?>
			<?php if ( $current_status === 'publish' ) : ?>
				<div class="gip-status-pill gip-status-pill--ok" title="<?php esc_attr_e( 'Imported posts will be published immediately. Change in Settings → New post status.', 'thegridindex-rss-importer' ); ?>">
					<?php esc_html_e( '✓ Publish mode', 'thegridindex-rss-importer' ); ?>
				</div>
			<?php else : ?>
				<div class="gip-status-banner gip-status-banner--warn">
					<div class="gip-status-banner__msg">
						<strong><?php
						printf(
							/* translators: %s current post_status */
							esc_html__( 'Import status: %s.', 'thegridindex-rss-importer' ),
							esc_html( ucfirst( $current_status ) )
						);
						?></strong>
						<?php esc_html_e( 'New imports are NOT publishing immediately. Click the button to fix this and publish any existing imported drafts.', 'thegridindex-rss-importer' ); ?>
					</div>
					<form method="post" action="<?php echo esc_url( $save_url ); ?>" style="margin:0;">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>_set_publish" />
						<button type="submit" class="button button-primary button-hero gip-status-banner__btn">
							<?php esc_html_e( 'Switch to Publish & fix existing drafts', 'thegridindex-rss-importer' ); ?>
						</button>
					</form>
				</div>
			<?php endif; ?>

			<div class="gi-shell" style="grid-template-columns:1fr;">

				<div class="gi-main">

					<?php if ( $msg ) :
						$notice_class    = ( $type === 'error' ) ? 'gi-notice gi-notice--reset' : 'gi-notice';
						// v1.0.36 — When the redirect came from a catalog ADD,
						// pass the new feed's index + name through to the toast
						// JS so it can render a "Fetch now" button.
						$added_idx_attr  = '';
						$added_name_attr = '';
						// PHPCS: see notice-msg/type rationale above. Same pattern:
						// our own admin-post handler set these via the redirect URL
						// after running its own check_admin_referer().
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended
						if ( isset( $_GET['gip_rss_added_idx'] ) && $_GET['gip_rss_added_idx'] !== '' ) {
							// phpcs:ignore WordPress.Security.NonceVerification.Recommended
							$added_idx_attr  = ' data-added-idx="' . esc_attr( (int) $_GET['gip_rss_added_idx'] ) . '"';
						}
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended
						if ( isset( $_GET['gip_rss_added_name'] ) ) {
							// phpcs:ignore WordPress.Security.NonceVerification.Recommended
							$added_name_attr = ' data-added-name="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['gip_rss_added_name'] ) ) ) . '"';
						}
					?>
						<?php
						// PHPCS: $added_idx_attr and $added_name_attr are
						// constructed entirely from esc_attr()-wrapped values
						// above (the index is cast to int first, the name is
						// sanitize_text_field'd then esc_attr'd). PHPCS can't
						// trace the escape across the assignment, but these
						// are escape-on-construction strings being concatenated
						// for output.
						?>
						<div class="<?php echo esc_attr( $notice_class ); ?>"<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $added_idx_attr . $added_name_attr; ?>><?php echo esc_html( $msg ); ?></div>
					<?php endif; ?>

					<nav class="gip-tabs" role="tablist" aria-label="<?php esc_attr_e( 'RSS Importer sections', 'thegridindex-rss-importer' ); ?>">
						<button type="button" class="gip-tab is-active" data-tab="feeds" role="tab" aria-selected="true"><?php esc_html_e( 'Feeds', 'thegridindex-rss-importer' ); ?></button>
						<button type="button" class="gip-tab" data-tab="catalog" role="tab"><?php esc_html_e( 'Catalog', 'thegridindex-rss-importer' ); ?></button>
						<button type="button" class="gip-tab" data-tab="settings" role="tab"><?php esc_html_e( 'Settings', 'thegridindex-rss-importer' ); ?></button>
						<button type="button" class="gip-tab" data-tab="diagnostics" role="tab"><?php esc_html_e( 'Diagnostics', 'thegridindex-rss-importer' ); ?></button>
						<button type="button" class="gip-tab" data-tab="support" role="tab"><?php esc_html_e( 'Support', 'thegridindex-rss-importer' ); ?></button>
					</nav>

					<?php // v1.0.26 — Hidden forms so the Run/Force buttons inside the main settings form (Feeds toolbar) can submit them via the HTML5 `form=` attribute. ?>
					<form id="gip-run-form" method="post" action="<?php echo esc_url( $save_url ); ?>" style="display:none;" data-gip-long-action="import">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>_run" />
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					</form>
					<form id="gip-force-form" method="post" action="<?php echo esc_url( $save_url ); ?>" style="display:none;" data-gip-long-action="force">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>_force" />
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					</form>

					<form method="post" action="<?php echo esc_url( $save_url ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>_save" />
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

						<!-- ============== FEEDS TAB ============== -->
						<div class="gip-tab-panel is-active" data-panel="feeds" role="tabpanel">
						<div class="gi-card">
							<div class="gi-card__head">
								<h2 class="gi-card__title"><?php esc_html_e( 'Feeds', 'thegridindex-rss-importer' ); ?></h2>
								<p class="gi-card__sub"><?php
									$rss_term = get_term_by( 'slug', self::RSS_CAT_SLUG, 'category' );
									if ( $rss_term && ! is_wp_error( $rss_term ) ) {
										$cat_link = admin_url( 'edit.php?category_name=' . self::RSS_CAT_SLUG );
										printf(
											/* translators: 1: category link */
											wp_kses_post( __( 'All imported posts go into the dedicated %1$s category. Add one feed per row.', 'thegridindex-rss-importer' ) ),
											'<a href="' . esc_url( $cat_link ) . '"><strong>' . esc_html( self::RSS_CAT_NAME ) . '</strong></a>'
										);
									} else {
										esc_html_e( 'Add one feed per row. Imported posts will be auto-tagged with their original source.', 'thegridindex-rss-importer' );
									}
								?></p>
							</div>
							<div class="gi-card__body">

								<?php
								// v1.0.42 — Duplicate-detection banner. Only renders when
								// at least one duplicate group exists. The full detector
								// and merge tool live on the Diagnostics tab; this banner
								// surfaces the problem where the user is actually working.
								$dup_summary = $this->count_duplicate_summary();
								if ( $dup_summary['groups'] > 0 ) :
									$diag_url = esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) ) . '#diagnostics' );
								?>
									<div class="gip-dup-banner" role="alert">
										<div class="gip-dup-banner__icon">⚠</div>
										<div class="gip-dup-banner__msg">
											<strong><?php
												printf(
													esc_html(
														/* translators: 1: group count, 2: surplus count. */
														_n(
															'%1$d duplicate group found across your RSS posts (%2$d extra post can be merged).',
															'%1$d duplicate groups found across your RSS posts (%2$d extra posts can be merged).',
															$dup_summary['groups'], 'thegridindex-rss-importer'
														)
													),
													(int) $dup_summary['groups'],
													(int) $dup_summary['surplus']
												);
											?></strong>
											<span class="gip-dup-banner__sub"><?php esc_html_e( 'Some feeds republish the same item with different GUIDs, or a force-reimport left extras behind. Review and merge them from the Diagnostics tab.', 'thegridindex-rss-importer' ); ?></span>
										</div>
										<a class="gi-btn gi-btn--ghost gip-dup-banner__btn" href="<?php echo esc_url( $diag_url ); ?>" data-jump-to-dup="1">
											<?php esc_html_e( 'Review on Diagnostics', 'thegridindex-rss-importer' ); ?>
										</a>
									</div>
								<?php endif; ?>

								<?php if ( empty( $s['feeds'] ) ) : ?>
									<div class="gip-empty-nudge">
										<strong><?php esc_html_e( 'No feeds yet.', 'thegridindex-rss-importer' ); ?></strong>
										<?php esc_html_e( 'The fastest way to get started is the Catalog tab — pick from 30 curated, verified-working feeds. Or paste a feed URL into the row below.', 'thegridindex-rss-importer' ); ?>
									</div>
								<?php endif; ?>

								<div class="gip-feeds-toolbar">
									<span class="gip-save-indicator" id="gip-save-indicator" aria-live="polite"></span>
									<button type="submit" form="gip-run-form" class="gi-btn gi-btn--primary"><?php esc_html_e( '↻ Import Now', 'thegridindex-rss-importer' ); ?></button>
									<button type="submit" form="gip-force-form" class="gi-btn gi-btn--ghost"
										onclick="return confirm('<?php echo esc_js( __( 'Force re-import will DELETE existing copies of items published in the last 24 hours and re-fetch them fresh. The deletes are permanent. Continue?', 'thegridindex-rss-importer' ) ); ?>');">
										<?php esc_html_e( '⟳ Force re-import 24h', 'thegridindex-rss-importer' ); ?>
									</button>
									<span style="opacity:.4;">·</span>
									<button type="button" class="gi-btn" id="gip-rss-add-row-top">+ <?php esc_html_e( 'Add Feed', 'thegridindex-rss-importer' ); ?></button>
								</div>

								<div id="gip-rss-feeds-list" class="gip-rss-feeds-list">
									<?php
									$feeds = ! empty( $s['feeds'] ) ? $s['feeds'] : array( array( 'url' => '', 'name' => '' ) );
									?>
									<div class="gip-rss-feeds-header">
										<span class="gip-rss-feeds-header__cell gip-rss-feeds-header__cell--status"></span>
										<span class="gip-rss-feeds-header__cell gip-rss-feeds-header__cell--url"><?php esc_html_e( 'Feed URL', 'thegridindex-rss-importer' ); ?></span>
										<span class="gip-rss-feeds-header__cell gip-rss-feeds-header__cell--name"><?php esc_html_e( 'Source Name', 'thegridindex-rss-importer' ); ?></span>
										<span class="gip-rss-feeds-header__cell gip-rss-feeds-header__cell--interval"><?php esc_html_e( 'Interval', 'thegridindex-rss-importer' ); ?></span>
										<span class="gip-rss-feeds-header__cell gip-rss-feeds-header__cell--fetched"><?php esc_html_e( 'Last Fetched', 'thegridindex-rss-importer' ); ?></span>
										<span class="gip-rss-feeds-header__cell gip-rss-feeds-header__cell--actions"></span>
									</div>
									<?php
									foreach ( $feeds as $i => $feed ) :
										$url           = isset( $feed['url'] )  ? $feed['url']  : '';
										$name          = isset( $feed['name'] ) ? $feed['name'] : '';
										$last_status   = $feed['last_status']   ?? '';
										$last_message  = $feed['last_message']  ?? '';
										$last_fetched  = (int) ( $feed['last_fetched'] ?? 0 );

										// Status dot color by state. 'never' = unknown/not yet run.
										$dot_class = 'gip-dot--never';
										if ( $last_status === 'ok' )      $dot_class = 'gip-dot--ok';
										if ( $last_status === 'all-dup' ) $dot_class = 'gip-dot--dup';
										if ( $last_status === 'empty' )   $dot_class = 'gip-dot--empty';
										if ( $last_status === 'error' )   $dot_class = 'gip-dot--error';

										// Build the tooltip / detail string lazily — only renders if we have anything.
										$detail_bits = array();
										if ( $last_status )  $detail_bits[] = strtoupper( $last_status );
										if ( $last_message ) $detail_bits[] = $last_message;
										if ( $last_fetched ) {
											$detail_bits[] = sprintf(
												/* translators: %s: human time difference */
												__( 'last fetch %s ago', 'thegridindex-rss-importer' ),
												human_time_diff( $last_fetched, time() )
											);
										}
										$detail = implode( ' · ', $detail_bits );
										if ( ! $detail ) $detail = __( 'Never fetched', 'thegridindex-rss-importer' );

										$fetch_url = wp_nonce_url(
											add_query_arg( array(
												'action'     => self::PAGE_SLUG . '_fetch_one',
												'feed_index' => (int) $i,
											), admin_url( 'admin-post.php' ) ),
											self::NONCE_ACTION,
											self::NONCE_NAME
										);
									?>
										<div class="gip-rss-feed-row">
											<span class="gip-rss-feed-row__cell gip-rss-feed-row__cell--status">
												<span class="gip-dot <?php echo esc_attr( $dot_class ); ?>"
												      title="<?php echo esc_attr( $detail ); ?>"
												      data-detail="<?php echo esc_attr( $detail ); ?>"
												      tabindex="0"
												      aria-label="<?php echo esc_attr( $detail ); ?>"></span>
											</span>
											<span class="gip-rss-feed-row__cell gip-rss-feed-row__cell--url">
												<input class="gi-input" type="url" name="feeds[<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $url ); ?>" placeholder="https://example.com/feed/" />
											</span>
											<span class="gip-rss-feed-row__cell gip-rss-feed-row__cell--name">
												<input class="gi-input" type="text" name="feeds[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'Source name', 'thegridindex-rss-importer' ); ?>" />
												<?php
												// v1.0.40 — per-feed category picker. Empty value = RSS only.
												$current_cat = isset( $feed['category'] ) && isset( self::GRANULAR_CATEGORIES[ $feed['category'] ] )
													? $feed['category'] : '';
												?>
												<select class="gi-select gip-rss-feed-row__cat" name="feeds[<?php echo (int) $i; ?>][category]" title="<?php esc_attr_e( 'Granular category for posts from this feed (in addition to RSS).', 'thegridindex-rss-importer' ); ?>">
													<option value="" <?php selected( $current_cat, '' ); ?>><?php esc_html_e( '— RSS only —', 'thegridindex-rss-importer' ); ?></option>
													<?php foreach ( self::GRANULAR_CATEGORIES as $key => $cfg ) : ?>
														<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_cat, $key ); ?>><?php echo esc_html( $cfg['name'] ); ?></option>
													<?php endforeach; ?>
												</select>
											</span>
											<span class="gip-rss-feed-row__cell gip-rss-feed-row__cell--interval">
												<?php
												// v1.0.34 — Per-feed interval picker.
												$current_interval = isset( $feed['interval'] ) && in_array( $feed['interval'], self::VALID_INTERVALS, true )
													? $feed['interval']
													: $this->get_recommended_interval_for_url( $url );
												?>
												<select class="gi-select" name="feeds[<?php echo (int) $i; ?>][interval]">
													<?php foreach ( self::VALID_INTERVALS as $iv ) : ?>
														<option value="<?php echo esc_attr( $iv ); ?>" <?php selected( $current_interval, $iv ); ?>><?php echo esc_html( $this->interval_label( $iv ) ); ?></option>
													<?php endforeach; ?>
												</select>
											</span>
											<span class="gip-rss-feed-row__cell gip-rss-feed-row__cell--fetched">
												<?php
												if ( $last_fetched ) {
													printf(
														/* translators: %s: human-readable time difference */
														'<span class="gip-rss-feed-row__fetched" title="%s">%s</span>',
														esc_attr( wp_date( 'M j, Y g:i a', $last_fetched ) ),
														sprintf(
															/* translators: %s: human-readable diff like "2 minutes" */
															esc_html__( '%s ago', 'thegridindex-rss-importer' ),
															esc_html( human_time_diff( $last_fetched, time() ) )
														)
													);
												} else {
													echo '<span class="gip-rss-feed-row__fetched gip-rss-feed-row__fetched--never">' . esc_html__( 'Never', 'thegridindex-rss-importer' ) . '</span>';
												}
												?>
											</span>
											<span class="gip-rss-feed-row__cell gip-rss-feed-row__cell--actions">
												<a href="<?php echo esc_url( $fetch_url ); ?>" class="gi-btn gi-btn--ghost gip-rss-feed-row__btn gip-fetch-link" data-gip-long-action="fetch" title="<?php esc_attr_e( 'Fetch this feed now', 'thegridindex-rss-importer' ); ?>">↻</a>
												<button type="button" class="gi-btn gi-btn--ghost gip-rss-feed-row__btn gip-rss-remove-row" title="<?php esc_attr_e( 'Remove this feed', 'thegridindex-rss-importer' ); ?>">×</button>
											</span>
											<div class="gip-rss-feed-row__detail" hidden><?php echo esc_html( $detail ); ?></div>
										</div>
									<?php endforeach; ?>
								</div>

								<div style="margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
									<button type="submit" class="gi-btn gi-btn--primary"><?php esc_html_e( 'Save Feeds', 'thegridindex-rss-importer' ); ?></button>
									<span style="opacity:.5;">·</span>
									<a href="<?php echo esc_url( wp_nonce_url(
										add_query_arg( array( 'action' => self::PAGE_SLUG . '_restore' ), admin_url( 'admin-post.php' ) ),
										self::NONCE_ACTION,
										self::NONCE_NAME
									) ); ?>"
									   class="gi-btn gi-btn--ghost"
									   onclick="return confirm('<?php echo esc_js( __( 'Replace your current feed list with the default starter feeds (NYT, BBC, AP, Guardian, etc.)? Your current list will be overwritten.', 'thegridindex-rss-importer' ) ); ?>');">
										<?php esc_html_e( '↻ Restore default feeds', 'thegridindex-rss-importer' ); ?>
									</a>
									<span style="opacity:.5;">·</span>
									<a href="<?php echo esc_url( wp_nonce_url(
										add_query_arg( array( 'action' => self::PAGE_SLUG . '_republish' ), admin_url( 'admin-post.php' ) ),
										self::NONCE_ACTION,
										self::NONCE_NAME
									) ); ?>"
									   class="gi-btn gi-btn--ghost"
									   onclick="return confirm('<?php echo esc_js( __( 'Publish ALL existing draft posts that were imported by this RSS plugin? This is intended to repair posts that landed as drafts under earlier settings. Cannot be undone in bulk.', 'thegridindex-rss-importer' ) ); ?>');">
										<?php esc_html_e( '⇪ Publish all RSS drafts', 'thegridindex-rss-importer' ); ?>
									</a>
									<span style="opacity:.5;">·</span>
									<a href="<?php echo esc_url( wp_nonce_url(
										add_query_arg( array( 'action' => self::PAGE_SLUG . '_clear_feeds' ), admin_url( 'admin-post.php' ) ),
										self::NONCE_ACTION,
										self::NONCE_NAME
									) ); ?>"
									   class="gi-btn gi-btn--ghost"
									   style="color:#ef4444;"
									   onclick="return confirm('<?php echo esc_js( __( 'Remove ALL feeds from the importer? This wipes the entire feed list. Other settings (post status, frequency, image rules) are preserved. Already-imported posts are NOT deleted.', 'thegridindex-rss-importer' ) ); ?>');">
										<?php esc_html_e( '🗑 Clear all feeds', 'thegridindex-rss-importer' ); ?>
									</a>
									<?php
									// v1.0.65 — "Reset seen-GUIDs ledger." Destructive: lets every
									// item currently in every enabled feed be re-imported on the
									// next run, including items the user previously deleted. The
									// button only renders when the ledger actually has rows (no
									// point showing it on a clean install). Two-stage confirmation
									// guards against accidental clicks; the second prompt is
									// intentionally worded as a hard "type-or-cancel" moment.
									$ledger_count = $this->count_seen_guids();
									if ( $ledger_count > 0 ) :
										/* translators: %s: formatted row count (with thousands separators). */
										$btn_label = sprintf( __( '⟲ Reset seen-GUIDs ledger (%s entries)', 'thegridindex-rss-importer' ), number_format_i18n( $ledger_count ) );
										$confirm_1 = sprintf(
											/* translators: %s: formatted row count */
											__( "Reset the seen-GUIDs ledger?\n\nThis will clear %s entries. After the reset, the next import run will treat every item currently in your enabled feeds as new — including items you previously imported and then deleted. Live (un-deleted) imported posts are still detected by their post metadata and won't duplicate.\n\nThe import history for already-deleted posts will be permanently lost.\n\nContinue?", 'thegridindex-rss-importer' ),
											number_format_i18n( $ledger_count )
										);
										$confirm_2 = __( "Are you absolutely sure?\n\nThis cannot be undone. Click OK to wipe the ledger now, or Cancel to back out.", 'thegridindex-rss-importer' );
										?>
										<span style="opacity:.5;">·</span>
										<a href="<?php echo esc_url( wp_nonce_url(
											add_query_arg( array( 'action' => self::PAGE_SLUG . '_reset_ledger' ), admin_url( 'admin-post.php' ) ),
											self::NONCE_ACTION,
											self::NONCE_NAME
										) ); ?>"
										   class="gi-btn gi-btn--ghost"
										   style="color:#ef4444;"
										   onclick="return confirm('<?php echo esc_js( $confirm_1 ); ?>') && confirm('<?php echo esc_js( $confirm_2 ); ?>');">
											<?php echo esc_html( $btn_label ); ?>
										</a>
									<?php endif; ?>
									</div>
								</div>
							</div>
						</div><!-- /.gip-tab-panel feeds -->

						<!-- ============== SETTINGS TAB ============== -->
						<div class="gip-tab-panel" data-panel="settings" role="tabpanel" hidden>

							<!-- ============== IMPORT SETTINGS CARD ============== -->
							<div class="gi-card">
							<div class="gi-card__head">
								<h2 class="gi-card__title"><?php esc_html_e( 'Import Settings', 'thegridindex-rss-importer' ); ?></h2>
								<p class="gi-card__sub"><?php esc_html_e( 'How and how often new items are pulled in.', 'thegridindex-rss-importer' ); ?></p>
							</div>
							<div class="gi-card__body">
								<div class="gi-grid gi-grid--3">
									<div class="gi-field">
										<label class="gi-field__label" for="gip-rss-status"><?php esc_html_e( 'New post status', 'thegridindex-rss-importer' ); ?></label>
										<select id="gip-rss-status" class="gi-select" name="post_status">
											<option value="publish" <?php selected( $s['post_status'], 'publish' ); ?>><?php esc_html_e( 'Publish immediately', 'thegridindex-rss-importer' ); ?></option>
											<option value="draft"   <?php selected( $s['post_status'], 'draft' );   ?>><?php esc_html_e( 'Draft (review first)', 'thegridindex-rss-importer' ); ?></option>
											<option value="pending" <?php selected( $s['post_status'], 'pending' ); ?>><?php esc_html_e( 'Pending review', 'thegridindex-rss-importer' ); ?></option>
										</select>
										<p class="gi-field__desc"><?php esc_html_e( 'Status of newly imported posts.', 'thegridindex-rss-importer' ); ?></p>
									</div>

									<div class="gi-field">
										<label class="gi-field__label" for="gip-rss-freq"><?php esc_html_e( 'Check frequency', 'thegridindex-rss-importer' ); ?></label>
										<select id="gip-rss-freq" class="gi-select" name="frequency">
											<option value="gip_rss_5min"  <?php selected( $s['frequency'], 'gip_rss_5min' );  ?>><?php esc_html_e( 'Every 5 minutes', 'thegridindex-rss-importer' ); ?></option>
											<option value="gip_rss_15min" <?php selected( $s['frequency'], 'gip_rss_15min' ); ?>><?php esc_html_e( 'Every 15 minutes', 'thegridindex-rss-importer' ); ?></option>
											<option value="gip_rss_30min" <?php selected( $s['frequency'], 'gip_rss_30min' ); ?>><?php esc_html_e( 'Every 30 minutes', 'thegridindex-rss-importer' ); ?></option>
											<option value="hourly"        <?php selected( $s['frequency'], 'hourly' );        ?>><?php esc_html_e( 'Hourly', 'thegridindex-rss-importer' ); ?></option>
											<option value="twicedaily"    <?php selected( $s['frequency'], 'twicedaily' );    ?>><?php esc_html_e( 'Twice daily', 'thegridindex-rss-importer' ); ?></option>
											<option value="manual"        <?php selected( $s['frequency'], 'manual' );        ?>><?php esc_html_e( 'Manual only', 'thegridindex-rss-importer' ); ?></option>
										</select>
										<p class="gi-field__desc"><?php esc_html_e( 'How often the cron checks for feeds due to fetch. Each feed has its own interval (set on the Feeds tab); this is the polling rate. Match this to your fastest feed\'s interval — feeds with slower intervals will be skipped until they\'re due.', 'thegridindex-rss-importer' ); ?></p>
										<?php if ( in_array( $s['frequency'], array( 'gip_rss_5min', 'gip_rss_15min' ), true ) ) : ?>
											<p class="gip-freq-warn">
												<strong><?php esc_html_e( 'Heads up:', 'thegridindex-rss-importer' ); ?></strong>
												<?php esc_html_e( 'WP-Cron only fires when someone visits your site. For reliable 5–15 minute intervals, add a real cron job at your host that hits wp-cron.php every minute (Hostinger → Cron Jobs). Without that, short intervals can lag on quiet sites.', 'thegridindex-rss-importer' ); ?>
											</p>
										<?php endif; ?>
									</div>

									<div class="gi-field">
										<label class="gi-field__label" for="gip-rss-image"><?php esc_html_e( 'Featured image', 'thegridindex-rss-importer' ); ?></label>
										<select id="gip-rss-image" class="gi-select" name="image_mode">
											<option value="feed_first"    <?php selected( $s['image_mode'], 'feed_first' );    ?>><?php esc_html_e( 'Try feed image, then content', 'thegridindex-rss-importer' ); ?></option>
											<option value="content_first" <?php selected( $s['image_mode'], 'content_first' ); ?>><?php esc_html_e( 'First image from content only', 'thegridindex-rss-importer' ); ?></option>
											<option value="none"          <?php selected( $s['image_mode'], 'none' );          ?>><?php esc_html_e( 'No featured image', 'thegridindex-rss-importer' ); ?></option>
										</select>
										<p class="gi-field__desc"><?php esc_html_e( 'Where to source the post thumbnail from.', 'thegridindex-rss-importer' ); ?></p>
									</div>

									<div class="gi-field">
										<label class="gi-field__label" for="gip-rss-min-width"><?php esc_html_e( 'Minimum image width (px)', 'thegridindex-rss-importer' ); ?></label>
										<input id="gip-rss-min-width" class="gi-input" type="number" name="min_image_width" value="<?php echo (int) ( $s['min_image_width'] ?? 1000 ); ?>" min="0" max="4000" step="50" />
										<p class="gi-field__desc"><?php esc_html_e( 'Skip imports whose source image is narrower than this. Defaults to 1000px so the homepage hero stays sharp. Set to 0 to disable the check.', 'thegridindex-rss-importer' ); ?></p>
									</div>

									<div class="gi-field">
										<label class="gi-field__label" for="gip-rss-max"><?php esc_html_e( 'Max items per feed per run', 'thegridindex-rss-importer' ); ?></label>
										<input id="gip-rss-max" class="gi-input" type="number" name="max_per_run" value="<?php echo (int) $s['max_per_run']; ?>" min="1" max="100" />
										<p class="gi-field__desc"><?php esc_html_e( 'Safety cap so a backlogged feed never floods your site.', 'thegridindex-rss-importer' ); ?></p>
									</div>
							</div>

								<?php // v1.0.37 — Data persistence on uninstall ?>
								<div class="gip-uninstall-pref">
									<input type="hidden" name="keep_on_uninstall_present" value="1" />
									<label class="gip-uninstall-pref__label">
										<input type="checkbox" name="keep_on_uninstall" value="1" <?php checked( ! empty( $s['keep_on_uninstall'] ) ); ?> />
										<span>
											<strong><?php esc_html_e( 'Keep my data if I uninstall this plugin', 'thegridindex-rss-importer' ); ?></strong>
											<span class="gip-uninstall-pref__desc">
												<?php esc_html_e( 'When enabled, deleting this plugin in WordPress preserves your feed list, settings, and dedupe history — reinstalling restores everything. Default OFF: a routine delete will wipe plugin data. Imported posts are kept either way regardless of this setting.', 'thegridindex-rss-importer' ); ?>
											</span>
										</span>
									</label>
								</div>

								<div style="margin-top:18px; display:flex; gap:10px;">
									<button type="submit" class="gi-btn gi-btn--primary"><?php esc_html_e( 'Save Settings', 'thegridindex-rss-importer' ); ?></button>
								</div>
							</div>
						</div>
					</form>
					</div><!-- /.gip-tab-panel settings -->

					<!-- ============== CATALOG TAB ============== -->
					<?php
					$catalog       = $this->get_catalog_feeds();
					$active_urls   = array();
					if ( ! empty( $s['feeds'] ) && is_array( $s['feeds'] ) ) {
						foreach ( $s['feeds'] as $f ) {
							if ( ! empty( $f['url'] ) ) $active_urls[ $f['url'] ] = true;
						}
					}
					$active_count = count( $s['feeds'] ?? array() );

					// v1.0.35 — Group catalog feeds. Sections render in a fixed
					// order with a virtual "Breaking" section at the top that
					// pulls every feed with recommended_interval=5min — fastest
					// publishers, surfaced for breaking-news use cases. The
					// breaking entries also still appear in their home category
					// so users can see them in context.
					$grouped  = array();
					$breaking = array();
					foreach ( $catalog as $cf ) {
						$grouped[ $cf['category'] ][] = $cf;
						if ( ( $cf['recommended_interval'] ?? '' ) === '5min' ) {
							$breaking[] = $cf;
						}
					}

					// Fixed display order. Categories not in this list fall to the end.
					$cat_order = array( 'News', 'World', 'Tech', 'Business', 'Science' );
					$ordered = array();
					if ( ! empty( $breaking ) ) {
						$ordered['Breaking News'] = $breaking;
					}
					foreach ( $cat_order as $cn ) {
						if ( isset( $grouped[ $cn ] ) ) {
							$ordered[ $cn ] = $grouped[ $cn ];
							unset( $grouped[ $cn ] );
						}
					}
					foreach ( $grouped as $cn => $list ) {
						$ordered[ $cn ] = $list; // any unrecognized categories at the end
					}

					// View toggle: ?view=list switches to compact rows; default cards.
					// PHPCS: presentation-mode query param — no write, no nonce needed.
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$view_mode = ( isset( $_GET['view'] ) && sanitize_key( wp_unslash( $_GET['view'] ) ) === 'list' ) ? 'list' : 'cards';
					$cards_url = esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) ) . '#catalog' );
					$list_url  = esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'view' => 'list' ), admin_url( 'admin.php' ) ) . '#catalog' );
					?>
					<div class="gip-tab-panel" data-panel="catalog" role="tabpanel" hidden>
						<div class="gi-card">
							<div class="gi-card__head">
								<h2 class="gi-card__title"><?php esc_html_e( 'Catalog', 'thegridindex-rss-importer' ); ?></h2>
								<p class="gi-card__sub">
									<?php
									$catalog_total = count( $catalog );
									if ( $active_count > self::MAX_ACTIVE_FEEDS ) {
										printf(
											/* translators: 1: catalog total, 2: active count, 3: max active */
											esc_html__( '%1$d curated, verified-working feeds. You currently have %2$d active feeds — over the limit of %3$d.', 'thegridindex-rss-importer' ),
											(int) $catalog_total,
											(int) $active_count,
											(int) self::MAX_ACTIVE_FEEDS
										);
									} else {
										printf(
											/* translators: 1: catalog total, 2: active count, 3: max active */
											esc_html__( '%1$d curated, verified-working feeds. You currently have %2$d of %3$d active feeds.', 'thegridindex-rss-importer' ),
											(int) $catalog_total,
											(int) $active_count,
											(int) self::MAX_ACTIVE_FEEDS
										);
									}
									?>
								</p>
							</div>

							<?php if ( $active_count > self::MAX_ACTIVE_FEEDS ) :
								$trim_url = wp_nonce_url(
									add_query_arg( array( 'action' => self::PAGE_SLUG . '_trim_to_cap' ), admin_url( 'admin-post.php' ) ),
									self::NONCE_ACTION,
									self::NONCE_NAME
								);
								$over_by = $active_count - self::MAX_ACTIVE_FEEDS;
							?>
								<div class="gip-catalog-warn">
									<div class="gip-catalog-warn__msg">
										<strong><?php esc_html_e( 'Over the cap.', 'thegridindex-rss-importer' ); ?></strong>
										<?php
										printf(
											esc_html(
												/* translators: 1: number of feeds the user is over the cap by; 2: cap value (e.g. 15). */
												_n(
													'You have %1$d feed more than the %2$d-feed limit. Until you trim, the Catalog can\'t add new feeds — but you can still remove active ones.',
													'You have %1$d feeds more than the %2$d-feed limit. Until you trim, the Catalog can\'t add new feeds — but you can still remove active ones.',
													$over_by, 'thegridindex-rss-importer'
												)
											),
											(int) $over_by,
											(int) self::MAX_ACTIVE_FEEDS
										);
										?>
									</div>
									<a href="<?php echo esc_url( $trim_url ); ?>" class="gi-btn gi-btn--primary gip-catalog-warn__btn"
									   onclick="return confirm('<?php
											printf(
												/* translators: 1: target feed cap (e.g. 15). 2: same number repeated in the explanatory clause. */
												esc_attr__( 'Trim your feed list to %1$d? This keeps the first %2$d feeds in saved order and removes the rest. You can re-add any catalog feed afterward.', 'thegridindex-rss-importer' ),
												(int) self::MAX_ACTIVE_FEEDS,
												(int) self::MAX_ACTIVE_FEEDS
											);
									?>');">
										<?php
										printf(
											/* translators: %d max active */
											esc_html__( 'Trim to %d', 'thegridindex-rss-importer' ),
											(int) self::MAX_ACTIVE_FEEDS
										);
										?>
									</a>
								</div>
							<?php endif; ?>

							<div class="gi-card__body">

								<!-- v1.0.35 — View toggle (Cards / List). -->
								<div class="gip-catalog-viewbar">
									<a href="<?php echo esc_url( $cards_url ); ?>" class="gip-catalog-viewbar__btn<?php echo $view_mode === 'cards' ? ' is-active' : ''; ?>"><?php esc_html_e( 'Cards', 'thegridindex-rss-importer' ); ?></a>
									<a href="<?php echo esc_url( $list_url ); ?>"  class="gip-catalog-viewbar__btn<?php echo $view_mode === 'list'  ? ' is-active' : ''; ?>"><?php esc_html_e( 'List', 'thegridindex-rss-importer' ); ?></a>
								</div>

								<?php foreach ( $ordered as $cat_name => $cat_feeds ) : ?>
									<div class="gip-catalog-group">
										<h3 class="gip-catalog-group__title">
											<?php echo esc_html( $cat_name ); ?>
											<?php if ( $cat_name === 'Breaking News' ) : ?>
												<span class="gip-catalog-group__hint"><?php esc_html_e( '5-min cadence — wire services and high-volume desks', 'thegridindex-rss-importer' ); ?></span>
											<?php endif; ?>
										</h3>

										<?php if ( $view_mode === 'list' ) : ?>
											<!-- LIST VIEW -->
											<div class="gip-catalog-list">
												<?php foreach ( $cat_feeds as $cf ) :
													$is_active   = isset( $active_urls[ $cf['url'] ] );
													$cap_reached = ! $is_active && $active_count >= self::MAX_ACTIVE_FEEDS;
													$toggle_url  = wp_nonce_url(
														add_query_arg( array(
															'action'   => self::PAGE_SLUG . '_catalog_toggle',
															'feed_url' => rawurlencode( $cf['url'] ),
															'view'     => 'list',
														), admin_url( 'admin-post.php' ) ),
														self::NONCE_ACTION,
														self::NONCE_NAME
													);
												?>
													<div class="gip-catalog-list-row<?php echo $is_active ? ' is-active' : ''; ?><?php echo $cap_reached ? ' is-disabled' : ''; ?>">
														<span class="gip-catalog-list-row__name"><?php echo esc_html( $cf['name'] ); ?></span>
														<span class="gip-catalog-list-row__host"><?php echo esc_html( wp_parse_url( $cf['url'], PHP_URL_HOST ) ); ?></span>
														<span class="gip-catalog-list-row__interval">⏱ <?php echo esc_html( $this->interval_label( $cf['recommended_interval'] ?? self::DEFAULT_INTERVAL ) ); ?></span>
														<span class="gip-catalog-list-row__action">
															<?php if ( $is_active ) : ?>
																<a href="<?php echo esc_url( $toggle_url ); ?>" class="gi-btn gi-btn--ghost gip-catalog-list-row__btn"><?php esc_html_e( '✓ Remove', 'thegridindex-rss-importer' ); ?></a>
															<?php elseif ( $cap_reached ) : ?>
																<button type="button" class="gi-btn gi-btn--ghost gip-catalog-list-row__btn" disabled><?php esc_html_e( 'Cap reached', 'thegridindex-rss-importer' ); ?></button>
															<?php else : ?>
																<a href="<?php echo esc_url( $toggle_url ); ?>" class="gi-btn gi-btn--primary gip-catalog-list-row__btn"><?php esc_html_e( '+ Add', 'thegridindex-rss-importer' ); ?></a>
															<?php endif; ?>
														</span>
													</div>
												<?php endforeach; ?>
											</div>

										<?php else : ?>
											<!-- CARDS VIEW (original) -->
											<div class="gip-catalog-grid">
												<?php foreach ( $cat_feeds as $cf ) :
													$is_active   = isset( $active_urls[ $cf['url'] ] );
													$cap_reached = ! $is_active && $active_count >= self::MAX_ACTIVE_FEEDS;
													$toggle_url  = wp_nonce_url(
														add_query_arg( array(
															'action'   => self::PAGE_SLUG . '_catalog_toggle',
															'feed_url' => rawurlencode( $cf['url'] ),
														), admin_url( 'admin-post.php' ) ),
														self::NONCE_ACTION,
														self::NONCE_NAME
													);
												?>
													<div class="gip-catalog-card<?php echo $is_active ? ' is-active' : ''; ?><?php echo $cap_reached ? ' is-disabled' : ''; ?>">
														<div class="gip-catalog-card__head">
															<div class="gip-catalog-card__name"><?php echo esc_html( $cf['name'] ); ?></div>
															<?php if ( $is_active ) : ?>
																<span class="gip-catalog-card__badge"><?php esc_html_e( 'Active', 'thegridindex-rss-importer' ); ?></span>
															<?php endif; ?>
														</div>
														<div class="gip-catalog-card__host"><?php echo esc_html( wp_parse_url( $cf['url'], PHP_URL_HOST ) ); ?></div>
														<?php if ( ! empty( $cf['recommended_interval'] ) ) : ?>
															<div class="gip-catalog-card__interval" title="<?php esc_attr_e( 'Recommended fetch interval based on this feed\'s typical publishing rate. You can override per-feed on the Feeds tab.', 'thegridindex-rss-importer' ); ?>">
																⏱ <?php echo esc_html( $this->interval_label( $cf['recommended_interval'] ) ); ?>
															</div>
														<?php endif; ?>
														<?php if ( $cap_reached ) : ?>
															<button type="button" class="gi-btn gi-btn--ghost gip-catalog-card__btn" disabled title="<?php
																printf(
																	/* translators: %d: max active feeds */
																	esc_attr__( 'Cap reached (%d). Remove an active feed to add this one.', 'thegridindex-rss-importer' ),
																	(int) self::MAX_ACTIVE_FEEDS
																);
															?>">
																<?php esc_html_e( 'Cap reached', 'thegridindex-rss-importer' ); ?>
															</button>
														<?php else : ?>
															<a href="<?php echo esc_url( $toggle_url ); ?>" class="gi-btn <?php echo $is_active ? 'gi-btn--ghost' : 'gi-btn--primary'; ?> gip-catalog-card__btn">
																<?php echo $is_active
																	? esc_html__( '✓ Remove', 'thegridindex-rss-importer' )
																	: esc_html__( '+ Add to feeds', 'thegridindex-rss-importer' ); ?>
															</a>
														<?php endif; ?>
													</div>
												<?php endforeach; ?>
											</div>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</div><!-- /.gip-tab-panel catalog -->

					<!-- ============== DIAGNOSTICS TAB ============== -->
					<div class="gip-tab-panel" data-panel="diagnostics" role="tabpanel" hidden>

						<!-- ============== LAST RUN LOG (moved from old Run tab in v1.0.28) ============== -->
						<?php if ( ! empty( $s['last_log'] ) ) : ?>
							<div class="gi-card">
								<div class="gi-card__head">
									<h2 class="gi-card__title"><?php esc_html_e( 'Last import log', 'thegridindex-rss-importer' ); ?></h2>
									<p class="gi-card__sub">
										<?php
										if ( ! empty( $s['last_run'] ) ) {
											printf(
												/* translators: %s: human-readable time difference */
												esc_html__( 'Last run: %s ago.', 'thegridindex-rss-importer' ),
												esc_html( human_time_diff( (int) $s['last_run'], time() ) )
											);
										}
										?>
									</p>
								</div>
								<div class="gi-card__body">
									<pre class="gip-rss-log"><?php echo esc_html( $s['last_log'] ); ?></pre>
								</div>
							</div>
						<?php endif; ?>

						<!-- ============== SILENT BREAKAGE DETECTOR (v1.0.41) ============== -->
						<?php
						$health_window_hours = 48;
						$health_cutoff_gmt   = gmdate( 'Y-m-d H:i:s', time() - $health_window_hours * HOUR_IN_SECONDS );
						$active_feeds        = is_array( $s['feeds'] ?? null ) ? $s['feeds'] : array();
						$health_rows         = array();
						if ( ! empty( $active_feeds ) ) {
							global $wpdb;
							foreach ( $active_feeds as $idx => $hf ) {
								if ( empty( $hf['url'] ) ) continue;
								$feed_name = isset( $hf['name'] ) && $hf['name'] !== '' ? $hf['name'] : $hf['url'];

								// Count posts imported from this feed in the last N hours.
								// Match on _gridindex_source_name (the canonical source meta
								// set on every import) — exactly equal, since users may have
								// multiple feeds with similar names.
								// PHPCS: direct query is intentional. WP_Query with a
								// meta_query for this exact-equal match would build the
								// same JOIN with extra overhead and return full posts when
								// we only want a COUNT(DISTINCT). Health check runs once
								// per admin page render; no caching needed since the answer
								// changes minute-to-minute as imports happen.
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
								$count = (int) $wpdb->get_var( $wpdb->prepare(
									"SELECT COUNT(DISTINCT p.ID)
									 FROM {$wpdb->posts} p
									 INNER JOIN {$wpdb->postmeta} pm
									   ON pm.post_id = p.ID
									   AND pm.meta_key = '_gridindex_source_name'
									   AND pm.meta_value = %s
									 WHERE p.post_status IN ('publish','draft','pending')
									   AND p.post_date_gmt > %s",
									$feed_name,
									$health_cutoff_gmt
								) );

								$last_fetched = isset( $hf['last_fetched'] ) ? (int) $hf['last_fetched'] : 0;
								$fetched_recently = ( $last_fetched > 0 ) && ( ( time() - $last_fetched ) < 24 * HOUR_IN_SECONDS );

								// Verdict logic:
								//   OK       — fetched recently AND posts in window
								//   silent   — fetched recently AND zero posts in window  ← the bug we're surfacing
								//   stale    — never/not-recently fetched
								//   pending  — never fetched yet (last_fetched == 0)
								if ( $last_fetched === 0 ) {
									$verdict = 'pending';
								} elseif ( ! $fetched_recently ) {
									$verdict = 'stale';
								} elseif ( $count === 0 ) {
									$verdict = 'silent';
								} else {
									$verdict = 'ok';
								}

								$health_rows[] = array(
									'name'         => $feed_name,
									'url'          => $hf['url'],
									'last_fetched' => $last_fetched,
									'count'        => $count,
									'verdict'      => $verdict,
								);
							}
						}

						// Sort: silent first, then stale, then pending, then ok.
						$verdict_order = array( 'silent' => 0, 'stale' => 1, 'pending' => 2, 'ok' => 3 );
						usort( $health_rows, function( $a, $b ) use ( $verdict_order ) {
							return $verdict_order[ $a['verdict'] ] <=> $verdict_order[ $b['verdict'] ];
						} );

						$silent_n = 0; foreach ( $health_rows as $hr ) { if ( $hr['verdict'] === 'silent' ) $silent_n++; }
						?>
						<div class="gi-card">
							<div class="gi-card__head">
								<h2 class="gi-card__title"><?php esc_html_e( 'Feed health check', 'thegridindex-rss-importer' ); ?></h2>
								<p class="gi-card__sub">
									<?php
									printf(
										/* translators: 1: window hours, 2: silent count */
										esc_html__( 'Feeds that fetch successfully but haven\'t imported any posts in the last %1$d hours are flagged "silent" — common when a publisher deprecates a feed (the URL still returns 200 but produces no items). %2$d silent feed(s) found.', 'thegridindex-rss-importer' ),
										(int) $health_window_hours,
										(int) $silent_n
									);
									?>
								</p>
							</div>
							<div class="gi-card__body">
								<?php if ( empty( $health_rows ) ) : ?>
									<p class="gi-field__desc"><?php esc_html_e( 'No active feeds to check.', 'thegridindex-rss-importer' ); ?></p>
								<?php else : ?>
									<div class="gip-health">
										<div class="gip-health__head">
											<span class="gip-health__cell gip-health__cell--name"><?php esc_html_e( 'Feed', 'thegridindex-rss-importer' ); ?></span>
											<span class="gip-health__cell gip-health__cell--fetched"><?php esc_html_e( 'Last fetch', 'thegridindex-rss-importer' ); ?></span>
											<span class="gip-health__cell gip-health__cell--count"><?php
												/* translators: %d: lookback window in hours (e.g. 24). */
												printf( esc_html__( 'Posts in %dh', 'thegridindex-rss-importer' ), (int) $health_window_hours ); ?></span>
											<span class="gip-health__cell gip-health__cell--verdict"><?php esc_html_e( 'Status', 'thegridindex-rss-importer' ); ?></span>
										</div>
										<?php foreach ( $health_rows as $hr ) :
											$verdict = $hr['verdict'];
											$verdict_label = array(
												'silent'  => __( '⚠ silent', 'thegridindex-rss-importer' ),
												'stale'   => __( 'stale fetch', 'thegridindex-rss-importer' ),
												'pending' => __( 'never fetched', 'thegridindex-rss-importer' ),
												'ok'      => __( '✓ ok', 'thegridindex-rss-importer' ),
											)[ $verdict ];
											$fetched_label = $hr['last_fetched']
												/* translators: %s: human-readable elapsed time (e.g. "5 minutes"). */
												? sprintf( esc_html__( '%s ago', 'thegridindex-rss-importer' ), esc_html( human_time_diff( $hr['last_fetched'], time() ) ) )
												: esc_html__( '—', 'thegridindex-rss-importer' );
										?>
											<div class="gip-health__row gip-health__row--<?php echo esc_attr( $verdict ); ?>">
												<span class="gip-health__cell gip-health__cell--name">
													<strong><?php echo esc_html( $hr['name'] ); ?></strong>
													<span class="gip-health__url"><?php echo esc_html( $hr['url'] ); ?></span>
												</span>
												<span class="gip-health__cell gip-health__cell--fetched"><?php echo wp_kses_post( $fetched_label ); ?></span>
												<span class="gip-health__cell gip-health__cell--count"><?php echo (int) $hr['count']; ?></span>
												<span class="gip-health__cell gip-health__cell--verdict">
													<span class="gip-health__pill gip-health__pill--<?php echo esc_attr( $verdict ); ?>"><?php echo esc_html( $verdict_label ); ?></span>
												</span>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</div>
						</div>

						<!-- ============== DIAGNOSTICS CARD ============== -->
						<?php
						// PHPCS: meta_key is essential here — we want exactly the
						// 8 most recent posts carrying our META_GUID, which is
						// the canonical "this was imported by this plugin" flag.
						// Runs once per admin page render, capped at 8 results,
						// so the perf impact is negligible.
						$recent = get_posts( array(
							'post_type'      => 'post',
							'post_status'    => 'any',
							// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
							'meta_key'       => self::META_GUID,
							'posts_per_page' => 8,
							'orderby'        => 'date',
							'order'          => 'DESC',
							'fields'         => 'ids',
						) );
						?>
						<div class="gi-card">
						<div class="gi-card__head">
							<h2 class="gi-card__title"><?php esc_html_e( 'Recent imports — diagnostics', 'thegridindex-rss-importer' ); ?></h2>
							<p class="gi-card__sub"><?php esc_html_e( 'The 8 most recent posts created by this importer, with the source meta the theme reads to render the "Read at Source" button.', 'thegridindex-rss-importer' ); ?></p>
						</div>
						<div class="gi-card__body">
							<?php if ( empty( $recent ) ) : ?>
								<p class="gi-field__desc"><?php esc_html_e( 'No posts have been imported yet. Add a feed above and click Import Now.', 'thegridindex-rss-importer' ); ?></p>
							<?php else : ?>
								<table class="widefat gip-diag-table">
									<thead>
										<tr>
											<th class="gip-diag-col-title"><?php esc_html_e( 'Post', 'thegridindex-rss-importer' ); ?></th>
											<th class="gip-diag-col-url"><?php esc_html_e( 'Source URL', 'thegridindex-rss-importer' ); ?></th>
											<th class="gip-diag-col-source"><?php esc_html_e( 'Source', 'thegridindex-rss-importer' ); ?></th>
											<th class="gip-diag-col-status"><?php esc_html_e( 'Attribution', 'thegridindex-rss-importer' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $recent as $rid ) :
											$src_url  = get_post_meta( $rid, '_gridindex_source_url', true );
											$src_name = get_post_meta( $rid, '_gridindex_source_name', true );
											$ok       = $src_url && $src_name;
										?>
											<tr>
												<td class="gip-diag-cell-title">
													<a href="<?php echo esc_url( get_edit_post_link( $rid ) ); ?>"><?php echo esc_html( get_the_title( $rid ) ); ?></a>
													<div class="gip-diag-date"><?php echo esc_html( get_the_date( 'M j, Y g:i a', $rid ) ); ?></div>
												</td>
												<td class="gip-diag-cell-url">
													<?php if ( $src_url ) : ?>
														<a href="<?php echo esc_url( $src_url ); ?>" target="_blank" rel="noopener" class="gip-diag-url" title="<?php echo esc_attr( $src_url ); ?>"><?php echo esc_html( $src_url ); ?></a>
													<?php else : ?>
														<span class="gip-diag-missing"><?php esc_html_e( '— missing —', 'thegridindex-rss-importer' ); ?></span>
													<?php endif; ?>
												</td>
												<td class="gip-diag-cell-source">
													<?php echo $src_name ? esc_html( $src_name ) : '<span class="gip-diag-missing">— missing —</span>'; ?>
												</td>
												<td class="gip-diag-cell-status">
													<?php if ( $ok ) : ?>
														<span class="gip-diag-ok" title="<?php esc_attr_e( 'Source attribution meta is set. The theme will render the “Read at Source” button on this post.', 'thegridindex-rss-importer' ); ?>">✓ <?php esc_html_e( 'OK', 'thegridindex-rss-importer' ); ?></span>
													<?php else : ?>
														<span class="gip-diag-bad" title="<?php esc_attr_e( 'Source meta is missing. The theme cannot render the “Read at Source” button on this post.', 'thegridindex-rss-importer' ); ?>">✗ <?php esc_html_e( 'Missing', 'thegridindex-rss-importer' ); ?></span>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<p class="gi-field__desc" style="margin-top:12px;">
									<?php esc_html_e( 'If a row shows "missing" meta but you imported it via this page, another plugin is overwriting the meta after insert. Check Aggregator, WP All Import, or any RSS-related plugin and disable it for these posts.', 'thegridindex-rss-importer' ); ?>
								</p>
							<?php endif; ?>
						</div>
					</div>

					<!-- ============== DUPLICATE FINDER (v1.0.30) + MERGE BUTTON (v1.0.31) ============== -->
					<?php
					$dup_result = $this->find_duplicate_groups( 2000 );
					$dup_groups = $dup_result['groups'];
					$rss_cat_id = $dup_result['rss_cat_id'];

					$total_dup_posts   = 0;
					$total_dup_extras  = 0; // posts that would be removed if we kept 1 per group
					foreach ( $dup_groups as $g ) {
						$total_dup_posts  += count( $g );
						$total_dup_extras += count( $g ) - 1;
					}

					$merge_url = wp_nonce_url(
						add_query_arg( array( 'action' => self::PAGE_SLUG . '_merge_dupes' ), admin_url( 'admin-post.php' ) ),
						self::NONCE_ACTION,
						self::NONCE_NAME
					);
					?>
					<div class="gi-card" id="gip-dup-detector">
						<div class="gi-card__head">
							<h2 class="gi-card__title"><?php esc_html_e( 'Duplicate detector', 'thegridindex-rss-importer' ); ?></h2>
							<p class="gi-card__sub">
								<?php
								if ( ! $rss_cat_id ) {
									esc_html_e( 'RSS category not found — nothing to scan.', 'thegridindex-rss-importer' );
								} elseif ( empty( $dup_groups ) ) {
									esc_html_e( 'No duplicates found in the most recent 2,000 RSS posts.', 'thegridindex-rss-importer' );
								} else {
									printf(
										/* translators: 1: groups, 2: total dup posts, 3: extras that could be removed */
										esc_html__( 'Found %1$d duplicate groups across %2$d posts. Merging keeps the oldest in each group and moves %3$d extras to Trash (recoverable for 30 days).', 'thegridindex-rss-importer' ),
										count( $dup_groups ),
										(int) $total_dup_posts,
										(int) $total_dup_extras
									);
								}
								?>
							</p>
						</div>
						<?php if ( ! empty( $dup_groups ) ) : ?>
							<div class="gi-card__body">

								<div style="margin-bottom:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
									<a href="<?php echo esc_url( $merge_url ); ?>"
									   class="gi-btn gi-btn--primary"
									   onclick="return confirm('<?php
									   		printf(
									   			/* translators: %d: total number of duplicate posts (extras beyond the kept "oldest") that will be moved to Trash. */
									   			esc_attr__( 'Move %d duplicate posts to Trash? The oldest post in each duplicate group will be kept; the rest go to Trash where you can recover them from Posts → Trash within 30 days. Continue?', 'thegridindex-rss-importer' ),
									   			(int) $total_dup_extras
									   		);
									   ?>');">
										<?php
										printf(
											/* translators: %d: extras count */
											esc_html__( '🗑 Merge duplicates — trash %d posts', 'thegridindex-rss-importer' ),
											(int) $total_dup_extras
										);
										?>
									</a>
									<span style="opacity:.6; font-size:12px;"><?php esc_html_e( 'Reversible: posts go to Trash, recoverable for 30 days.', 'thegridindex-rss-importer' ); ?></span>
								</div>

								<table class="widefat gip-diag-table">
									<thead>
										<tr>
											<th class="gip-diag-col-title"><?php esc_html_e( 'Title (normalized match)', 'thegridindex-rss-importer' ); ?></th>
											<th style="width:60px; text-align:center;"><?php esc_html_e( 'Count', 'thegridindex-rss-importer' ); ?></th>
											<th><?php esc_html_e( 'Posts (oldest first — oldest is kept on merge)', 'thegridindex-rss-importer' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php
										$shown = 0;
										foreach ( $dup_groups as $norm => $members ) :
											if ( $shown++ >= 25 ) break; // Cap visual list to first 25 groups.
											$first_title = $members[0]->post_title;
										?>
											<tr>
												<td class="gip-diag-cell-title">
													<strong><?php echo esc_html( $first_title ); ?></strong>
												</td>
												<td style="text-align:center; font-weight:700;">
													<?php echo (int) count( $members ); ?>
												</td>
												<td>
													<?php foreach ( $members as $mi => $m ) :
														$edit_url = get_edit_post_link( $m->ID );
														$src      = $m->source_name ? $m->source_name : __( '?', 'thegridindex-rss-importer' );
														$guid     = $m->guid_hash   ? substr( $m->guid_hash, 0, 8 ) : '—';
														$date_h   = mysql2date( 'M j, g:i a', $m->post_date );
														$is_keep  = ( $mi === 0 );
													?>
														<div class="gip-diag-dup-row<?php echo $is_keep ? ' gip-diag-dup-row--keep' : ''; ?>">
															<?php if ( $is_keep ) : ?>
																<span class="gip-diag-dup-keep-badge" title="<?php esc_attr_e( 'This post will be kept on merge (oldest in group).', 'thegridindex-rss-importer' ); ?>"><?php esc_html_e( 'KEEP', 'thegridindex-rss-importer' ); ?></span>
															<?php else : ?>
																<span class="gip-diag-dup-trash-badge" title="<?php esc_attr_e( 'This post will be moved to Trash on merge.', 'thegridindex-rss-importer' ); ?>"><?php esc_html_e( 'TRASH', 'thegridindex-rss-importer' ); ?></span>
															<?php endif; ?>
															<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo (int) $m->ID; ?></a>
															<span class="gip-diag-dup-meta"><?php echo esc_html( $date_h ); ?> · <?php echo esc_html( $src ); ?> · <code title="<?php esc_attr_e( 'first 8 chars of GUID dedupe hash', 'thegridindex-rss-importer' ); ?>"><?php echo esc_html( $guid ); ?></code></span>
														</div>
													<?php endforeach; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<?php if ( count( $dup_groups ) > 25 ) : ?>
									<p class="gi-field__desc" style="margin-top:10px;">
										<?php
										printf(
											/* translators: %d: groups truncated */
											esc_html__( 'Showing the 25 largest groups. %d more groups not shown — they\'ll still be merged when you click the button.', 'thegridindex-rss-importer' ),
											count( $dup_groups ) - 25
										);
										?>
									</p>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>

					</div><!-- /.gip-tab-panel diagnostics -->

					<!-- ============== SUPPORT TAB (v1.0.43) ============== -->
					<div class="gip-tab-panel" data-panel="support" role="tabpanel" hidden>

						<!-- v1.0.58: Project link banner at the top of the Support
						     tab. The previous placement (bottom of Credits card,
						     bottom of tab) was hard to find — user had to scroll
						     past the entire 15-entry FAQ to see it. This puts the
						     button as the FIRST thing visible when Support opens. -->
						<div class="gip-project-banner">
							<div class="gip-project-banner__copy">
								<span class="gip-project-banner__label"><?php esc_html_e( 'PROJECT HOME', 'thegridindex-rss-importer' ); ?></span>
								<h3 class="gip-project-banner__title"><?php esc_html_e( 'The Grid Index', 'thegridindex-rss-importer' ); ?></h3>
								<p class="gip-project-banner__desc">
									<?php esc_html_e( 'Documentation, the theme catalog, and project updates. This plugin is part of The Grid Index family by Fifth Avenue Photographic.', 'thegridindex-rss-importer' ); ?>
								</p>
							</div>
							<div class="gip-project-banner__cta">
								<a class="gi-btn gi-btn--primary gip-project-banner__btn"
								   href="https://thegridindex.com/"
								   target="_blank"
								   rel="noopener noreferrer">
									<?php esc_html_e( 'Visit The Grid Index ↗', 'thegridindex-rss-importer' ); ?>
								</a>
							</div>
						</div>

						<div class="gi-card">
							<div class="gi-card__head">
								<h2 class="gi-card__title"><?php esc_html_e( 'Support & knowledge base', 'thegridindex-rss-importer' ); ?></h2>
								<p class="gi-card__sub">
									<?php
									printf(
										/* translators: %s plugin version */
										esc_html__( 'Common questions about the plugin. Content current as of v%s. If something here is out of date or contradicts what the plugin actually does, the plugin behavior is the source of truth — please flag the discrepancy.', 'thegridindex-rss-importer' ),
										esc_html( THEGRIDINDEX_RSS_IMPORTER_VERSION )
									);
									?>
								</p>
							</div>
							<div class="gi-card__body">
								<div class="gip-faq-search">
									<input type="search" id="gip-faq-search" class="gi-input" placeholder="<?php esc_attr_e( 'Search the knowledge base…', 'thegridindex-rss-importer' ); ?>" aria-label="<?php esc_attr_e( 'Search FAQ', 'thegridindex-rss-importer' ); ?>" />
									<span class="gip-faq-search__hint" id="gip-faq-search-hint"></span>
								</div>

								<?php
								// FAQ data organized into sections. Each entry has a
								// unique slug (anchor + filter target), question, and
								// answer rendered as HTML (we trust the strings we
								// ship here; no user input).
								$faq_sections = array(
									array(
										'title' => __( 'Getting started', 'thegridindex-rss-importer' ),
										'items' => array(
											array(
												'slug' => 'what-does-this-do',
												'q'    => __( 'What does this plugin actually do?', 'thegridindex-rss-importer' ),
												'a'    => __( 'It pulls headlines from RSS feeds (NYT, BBC, TechCrunch, etc.) into your site as WordPress posts in the dedicated "RSS" category. Each post links back to the original source. The Catalog tab has 49 pre-verified feeds you can toggle on with one click; the Feeds tab lists what\'s currently active; Settings controls how often the cron checks and what the default post status is.', 'thegridindex-rss-importer' ),
											),
											array(
												'slug' => 'add-custom-feed',
												'q'    => __( 'How do I add a feed that\'s not in the catalog?', 'thegridindex-rss-importer' ),
												'a'    => __( 'On the Feeds tab, click "+ Add Feed", paste the RSS URL in the FEED URL column, give it a display name, pick an interval, and (optionally) pick a granular category. The form auto-saves about 800ms after you stop typing. Then click "Import Now" or wait for the next cron tick.', 'thegridindex-rss-importer' ),
											),
											array(
												'slug' => 'where-do-posts-go',
												'q'    => __( 'What\'s the "RSS" category — can I rename it?', 'thegridindex-rss-importer' ),
												'a'    => __( 'Every imported post is auto-tagged with the dedicated "RSS" category (slug: rss). Since v1.0.40, posts also get a granular category — News, World, Tech, Business, or Science — based on the feed\'s catalog entry. The "RSS" term itself is created on activation and not renamed by the plugin; if you rename or delete it in Posts → Categories, the plugin will recreate it on the next import.', 'thegridindex-rss-importer' ),
											),
										),
									),
									array(
										'title' => __( 'Imports & scheduling', 'thegridindex-rss-importer' ),
										'items' => array(
											array(
												'slug' => 'import-vs-force',
												'q'    => __( 'What\'s the difference between "Import Now" and "Force re-import 24h"?', 'thegridindex-rss-importer' ),
												'a'    => __( '"Import Now" fetches every active feed and imports anything new — items already imported are skipped via the dedupe ledger. "Force re-import 24h" looks at items published in the last 24 hours, DELETES the existing copies of any matches, and re-imports them fresh. Useful when you\'ve changed image rules or post-status defaults and want existing recent items to pick up the new settings.', 'thegridindex-rss-importer' ),
											),
											array(
												'slug' => 'per-feed-intervals',
												'q'    => __( 'How do per-feed intervals work?', 'thegridindex-rss-importer' ),
												'a'    => __( 'Each feed has its own check interval (5 min / 15 min / 30 min / hourly) set on the Feeds tab. The global Settings frequency is the POLLING rate — how often the cron wakes up to check whether any feed is due. Match it to your fastest feed\'s interval. A feed set to 5 min while the cron polls hourly effectively runs hourly. Catalog feeds come with sensible defaults; you can override per-feed.', 'thegridindex-rss-importer' ),
											),
											array(
												'slug' => 'drafts-not-publishing',
												'q'    => __( 'Why aren\'t my posts publishing — they\'re all drafts?', 'thegridindex-rss-importer' ),
												'a'    => __( 'Settings → New post status defaults to "Publish immediately" since v1.0.14. If your posts are coming in as drafts, check the dropdown there. A historical bug (pre-1.0.14) caused publish requests to be silently demoted to draft because the feed\'s date was passed to WordPress without timezone normalization, making the post look "future-scheduled." If you see this on 1.0.14+, the green "Publish all RSS drafts" button on the Feeds tab flips any existing drafts to publish in one click.', 'thegridindex-rss-importer' ),
											),
											array(
												'slug' => 'wp-cron-unreliable',
												'q'    => __( 'WP-Cron isn\'t reliable on my site — what do I do?', 'thegridindex-rss-importer' ),
												'a'    => __( 'WP-Cron only fires when someone visits the site. On a quiet site, "Every 5 minutes" can actually run every 30+ minutes. For reliable short intervals, set up a real Linux cron job at your host that pings wp-cron.php every minute. On Hostinger: Cron Jobs → Add Cron Job → Command: wget -q -O - https://YOURSITE/wp-cron.php?doing_wp_cron > /dev/null 2>&1 → Schedule: every minute. Then in WordPress wp-config.php, add: define( \'DISABLE_WP_CRON\', true ); so internal WP-Cron stops fighting the real one.', 'thegridindex-rss-importer' ),
											),
										),
									),
									array(
										'title' => __( 'Feed health & troubleshooting', 'thegridindex-rss-importer' ),
										'items' => array(
											array(
												'slug' => 'red-dot',
												'q'    => __( 'Why are some feeds showing red dots?', 'thegridindex-rss-importer' ),
												'a'    => __( 'A red dot in the leftmost column of the Feeds table means the most recent fetch returned an error (HTTP failure, timeout, or invalid XML). Hover the dot for the error message, or check Diagnostics → Last import log. Failed feeds are backed off for 10 minutes before being retried, so you won\'t hammer a broken feed every cron tick.', 'thegridindex-rss-importer' ),
											),
											array(
												'slug' => 'green-zero-posts',
												'q'    => __( 'Why do some feeds show 0 posts even though they\'re green?', 'thegridindex-rss-importer' ),
												'a'    => __( 'Green just means the HTTP fetch succeeded. The feed may have returned an empty or stale response — most often because the publisher deprecated their RSS without 404\'ing the URL (this is what happened with CNN before we dropped it in v1.0.41). The "Feed health check" card on the Diagnostics tab flags feeds that fetch successfully but haven\'t imported any posts in 48 hours. If a feed shows ⚠ silent for multiple days, the URL is probably dead.', 'thegridindex-rss-importer' ),
											),
											array(
												'slug' => 'diagnostics-first-look',
												'q'    => __( 'The Diagnostics tab confuses me. What should I look at first?', 'thegridindex-rss-importer' ),
												'a'    => __( 'Three things in order: (1) "Feed health check" — anything in red is silently broken. (2) "Duplicate detector" — extra copies of the same story across feeds, with a Merge button to trash duplicates while keeping the oldest. (3) "Last import log" — raw line-by-line output of the most recent run, useful for tracing specific failures.', 'thegridindex-rss-importer' ),
											),
											array(
												'slug' => 'images-when',
												'q'    => __( 'When does a post get a featured image?', 'thegridindex-rss-importer' ),
												'a'    => __( 'The plugin tries (in this order, depending on Settings → Featured image mode): feed image, then content image, then nothing. Specifically: enclosure tags, media:thumbnail / media:content, the first <img> in the description, and og:image lookups. If Minimum image width is set (default 1000px), images below that threshold are skipped — so the post may import without a featured image even if one was attached. Set width to 0 to disable the check.', 'thegridindex-rss-importer' ),
											),
											array(
												'slug' => 'image-width-filter',
												'q'    => __( 'Can I exclude items below a certain image width?', 'thegridindex-rss-importer' ),
												'a'    => __( 'Yes — Settings → Minimum image width. Default 1000px. The plugin reads each candidate image\'s dimensions before sideloading and skips anything narrower. Items without any usable image still import (no exclusion), they just don\'t get a featured image. Set to 0 to disable the check entirely.', 'thegridindex-rss-importer' ),
											),
										),
									),
									array(
										'title' => __( 'Duplicates & deleted posts', 'thegridindex-rss-importer' ),
										'items' => array(
											array(
												'slug' => 'deleted-comes-back',
												'q'    => __( 'Why did a deleted post come back?', 'thegridindex-rss-importer' ),
												'a'    => __( 'It shouldn\'t, on v1.0.38 or later. Before 1.0.38, the dedupe lookup excluded trashed posts AND lost track of permanently-deleted posts entirely (because postmeta is wiped when a post is permanently deleted). v1.0.38 added a persistent seen-GUIDs ledger table that records every imported hash and survives post deletion. If you\'re on 1.0.38+ and seeing posts re-import after deletion, that\'s a bug — please report it with the post title and source feed.', 'thegridindex-rss-importer' ),
											),
											array(
												'slug' => 'duplicate-merge',
												'q'    => __( 'How does the duplicate detector decide what to merge?', 'thegridindex-rss-importer' ),
												'a'    => __( 'It scans the 2,000 most recent RSS posts, normalizes each title (lowercase, strip punctuation, collapse whitespace), and groups any with identical normalized titles. Groups with 2+ posts are reported. Merging keeps the OLDEST post in each group (lowest ID) and moves the rest to Trash (recoverable for 30 days, not permanently deleted). This is conservative — if titles differ even slightly between sources, they won\'t be flagged as dupes.', 'thegridindex-rss-importer' ),
											),
										),
									),
									array(
										'title' => __( 'Maintenance', 'thegridindex-rss-importer' ),
										'items' => array(
											array(
												'slug' => 'uninstall-data',
												'q'    => __( 'What happens if I uninstall the plugin?', 'thegridindex-rss-importer' ),
												'a'    => __( 'Settings → Keep my data if I uninstall this plugin controls this (default ON since v1.0.37). When ON: feed list, settings, dedupe ledger, and per-post GUID meta are preserved across delete-and-reinstall. Imported posts themselves are NEVER deleted by uninstall regardless of the setting — that\'s your content. The cron schedule and SimplePie feed cache are always cleared on uninstall.', 'thegridindex-rss-importer' ),
											),
											array(
												'slug' => 'restore-defaults',
												'q'    => __( 'How do I reset to the default feed list?', 'thegridindex-rss-importer' ),
												'a'    => __( 'Feeds tab → "Restore default feeds" button at the bottom. This overwrites your current feed list with a curated 6-feed starter set (BBC World, Al Jazeera, Google News, NBC News, Science Daily, CBS News). Your existing feeds are removed but already-imported posts stay put. The dedupe ledger also stays — so re-importing won\'t create duplicates.', 'thegridindex-rss-importer' ),
											),
										),
									),
								);

								foreach ( $faq_sections as $section ) :
								?>
									<div class="gip-faq-section">
										<h3 class="gip-faq-section__title"><?php echo esc_html( $section['title'] ); ?></h3>
										<?php foreach ( $section['items'] as $item ) : ?>
											<details class="gip-faq-item" id="faq-<?php echo esc_attr( $item['slug'] ); ?>" data-faq-q="<?php echo esc_attr( $item['q'] ); ?>" data-faq-a="<?php echo esc_attr( wp_strip_all_tags( $item['a'] ) ); ?>">
												<summary class="gip-faq-item__q"><?php echo esc_html( $item['q'] ); ?></summary>
												<div class="gip-faq-item__a"><?php echo wp_kses_post( wpautop( $item['a'] ) ); ?></div>
											</details>
										<?php endforeach; ?>
									</div>
								<?php endforeach; ?>

								<p class="gip-faq-foot">
									<?php esc_html_e( 'Still stuck? The Diagnostics tab\'s import log + feed health check together cover most issues. If a specific feed is misbehaving, copy its URL and last error message before asking for help.', 'thegridindex-rss-importer' ); ?>
								</p>
							</div>
						</div>

						<!-- ============== CREDITS CARD (v1.0.55, restructured v1.0.56) ============== -->
						<div class="gi-card gip-support-card">
							<div class="gi-card__head">
								<h2 class="gi-card__title"><?php esc_html_e( 'Credits', 'thegridindex-rss-importer' ); ?></h2>
								<p class="gi-card__sub">
									<?php esc_html_e( 'Who made this and what else is in the family.', 'thegridindex-rss-importer' ); ?>
								</p>
							</div>
							<div class="gi-card__body">
								<div class="gip-credits">
									<div class="gip-credits__main">
										<p class="gip-credits__line gip-credits__line--lead">
											<?php
											printf(
												/* translators: %s: parent company name */
												esc_html__( 'Developed by %s.', 'thegridindex-rss-importer' ),
												'<strong>Fifth Avenue Photographic</strong>'
											);
											?>
										</p>
										<p class="gip-credits__line">
											<?php
											printf(
												/* translators: %s: site link */
												esc_html__( 'Fifth Avenue Photographic is the parent company behind The Grid Index theme and this companion plugin. Project home: %s.', 'thegridindex-rss-importer' ),
												'<a class="gip-credits__link" href="https://thegridindex.com/" target="_blank" rel="noopener noreferrer">thegridindex.com</a>'
											);
											?>
										</p>
									</div>

									<div class="gip-credits__products">
										<div class="gip-credits__product">
											<span class="gip-credits__product-label"><?php esc_html_e( 'Theme', 'thegridindex-rss-importer' ); ?></span>
											<span class="gip-credits__product-name"><?php esc_html_e( 'The Grid Index', 'thegridindex-rss-importer' ); ?></span>
											<span class="gip-credits__product-desc"><?php esc_html_e( 'Editorial WordPress theme for magazine-style sites.', 'thegridindex-rss-importer' ); ?></span>
										</div>
										<div class="gip-credits__product">
											<span class="gip-credits__product-label"><?php esc_html_e( 'Plugin', 'thegridindex-rss-importer' ); ?></span>
											<span class="gip-credits__product-name"><?php esc_html_e( 'TheGridIndex RSS Importer', 'thegridindex-rss-importer' ); ?></span>
											<span class="gip-credits__product-desc"><?php esc_html_e( 'This plugin — pulls headlines from external RSS feeds.', 'thegridindex-rss-importer' ); ?></span>
										</div>
									</div>

									<!-- v1.0.57: button restored. The button indicates the
									     plugin is part of The Grid Index family and gives
									     the user a clear visual entry point to the project. -->
									<div class="gip-credits__cta">
										<a class="gi-btn gi-btn--ghost gip-credits__btn"
										   href="https://thegridindex.com/"
										   target="_blank"
										   rel="noopener noreferrer">
											<?php esc_html_e( 'Visit The Grid Index ↗', 'thegridindex-rss-importer' ); ?>
										</a>
										<span class="gip-credits__cta-hint">
											<?php esc_html_e( 'This plugin works with The Grid Index theme.', 'thegridindex-rss-importer' ); ?>
										</span>
									</div>

									<p class="gip-credits__version">
										<?php
										printf(
											/* translators: %s: plugin version */
											esc_html__( 'You are running version %s.', 'thegridindex-rss-importer' ),
											esc_html( THEGRIDINDEX_RSS_IMPORTER_VERSION )
										);
										?>
									</p>
								</div>
							</div>
						</div>
					</div><!-- /.gip-tab-panel support -->

				</div><!-- /.gi-main -->
			</div><!-- /.gi-shell -->
		</div><!-- /.wrap -->

		<?php
		// v1.0.63 — Inline <style> and <script> blocks that used to live here
		// have been extracted to /assets/admin/admin.css and /assets/admin/admin.js
		// respectively, and are loaded via wp_enqueue_style() and wp_enqueue_script()
		// from the admin_enqueue_scripts hook earlier in this file. Dynamic config
		// (AJAX URL, nonces, action names, i18n strings) is passed to admin.js via
		// wp_localize_script() as window.gipRssCfg. WP.org review compliance:
		// plugins must not emit inline <script>/<style> tags from page render code.
	}

	/* ---------------------------------------------------------------------
	 * Admin actions
	 * ------------------------------------------------------------------- */

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$this->save_from_input( wp_unslash( $_POST ) );

		$this->unschedule_cron();
		$this->maybe_reschedule_cron();

		wp_safe_redirect( add_query_arg( array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( __( 'Settings saved.', 'thegridindex-rss-importer' ) ),
			'gip_rss_type' => 'success',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * v1.0.22 — AJAX save endpoint for auto-save while typing.
	 *
	 * Same payload and sanitization as handle_save(), but returns JSON
	 * instead of redirecting so the page doesn't reload between keystrokes.
	 * Hooked via wp_ajax_{action} so only logged-in users hit it; the
	 * permission check below restricts to manage_options.
	 */
	public function handle_ajax_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );

		// The form posts a normal $_POST payload (same field names as the
		// manual save). wp_unslash to match WP's auto-magic-quotes handling.
		$this->save_from_input( wp_unslash( $_POST ) );

		// Don't bounce the cron schedule on every keystroke — that's wasteful
		// and 'frequency' rarely changes while typing into URL fields. The
		// next full Save Settings click (or activation) will reconcile it.

		wp_send_json_success( array( 'saved_at' => time() ) );
	}

	/**
	 * v1.0.26 — AJAX endpoint that returns the current import progress.
	 * Polled by the admin JS while an import is running so the spinner
	 * label can show "Fetching 3 of 11 — TechCrunch".
	 *
	 * Returns either a progress state object or { idle: true } if no
	 * import is in flight. Gated to manage_options.
	 */
	public function handle_ajax_progress() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		// Nonce check — same nonce we use for save; cheaper than a separate
		// nonce since both are admin-only short-lived polls.
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );

		$state = $this->read_progress();
		if ( ! $state ) {
			wp_send_json_success( array( 'idle' => true ) );
		}
		wp_send_json_success( $state );
	}

	/**
	 * v1.0.36 — AJAX endpoint for non-reloading single-feed fetch. Called
	 * from the "Fetch now" action in the post-Catalog-add toast, so the
	 * user can immediately verify a newly-added feed works without
	 * leaving the Catalog tab.
	 *
	 * Reuses run_import() in single-feed mode. The progress transient is
	 * already written by the import loop, so the existing progress
	 * polling endpoint surfaces status without any extra work here.
	 */
	public function handle_ajax_fetch_one() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );

		$idx = isset( $_POST['feed_index'] ) ? (int) $_POST['feed_index'] : -1;
		if ( $idx < 0 ) {
			wp_send_json_error( array( 'message' => 'bad_index' ), 400 );
		}

		// Validate the index points at a real feed before we kick off work.
		$s = $this->get_settings();
		if ( empty( $s['feeds'][ $idx ] ) || empty( $s['feeds'][ $idx ]['url'] ) ) {
			wp_send_json_error( array( 'message' => 'no_feed_at_index' ), 404 );
		}

		// Run import for just this one feed. $manual=true bypasses the
		// per-feed interval gating. The progress transient is updated by
		// the import loop so the JS poller picks it up.
		$result = $this->run_import( true, 0, $idx );

		// Reload latest feed state for the response so the toast can show
		// per-feed totals.
		$s2  = $this->get_settings();
		$row = $s2['feeds'][ $idx ] ?? array();

		wp_send_json_success( array(
			'feed_index'    => $idx,
			'feed_name'     => isset( $row['name'] ) ? $row['name'] : '',
			'totals'        => $result,
			'last_status'   => isset( $row['last_status'] )   ? $row['last_status']   : '',
			'last_message'  => isset( $row['last_message'] )  ? $row['last_message']  : '',
			'last_imported' => isset( $row['last_imported'] ) ? (int) $row['last_imported'] : 0,
			'last_skipped'  => isset( $row['last_skipped'] )  ? (int) $row['last_skipped']  : 0,
			'last_errors'   => isset( $row['last_errors'] )   ? (int) $row['last_errors']   : 0,
			'last_fetched'  => isset( $row['last_fetched'] )  ? (int) $row['last_fetched']  : 0,
		) );
	}

	/**
	 * Persist a submitted settings payload. Shared by handle_save() (full
	 * page submit) and handle_ajax_save() (auto-save while typing).
	 *
	 * @param array $in Raw $_POST-shaped input (already unslashed).
	 */
	private function save_from_input( $in ) {
		// Build a URL→status map from the existing settings so we can
		// preserve last_status/last_fetched fields across a save (the form
		// only posts URL + name, not status fields).
		$existing      = $this->get_settings();
		$status_by_url = array();
		if ( ! empty( $existing['feeds'] ) && is_array( $existing['feeds'] ) ) {
			foreach ( $existing['feeds'] as $existing_feed ) {
				if ( ! empty( $existing_feed['url'] ) ) {
					$status_by_url[ $existing_feed['url'] ] = array_intersect_key(
						$existing_feed,
						array_flip( array( 'last_status', 'last_message', 'last_imported', 'last_skipped', 'last_errors', 'last_fetched', 'category' ) )
					);
				}
			}
		}

		$feeds = array();
		if ( ! empty( $in['feeds'] ) && is_array( $in['feeds'] ) ) {
			foreach ( $in['feeds'] as $row ) {
				$url = isset( $row['url'] ) ? esc_url_raw( trim( $row['url'] ) ) : '';
				if ( ! $url ) continue;

				// v1.0.34 — Per-feed interval. Form value wins if valid;
				// otherwise fall back to the catalog recommendation for this
				// URL (if known); otherwise DEFAULT_INTERVAL.
				$posted_interval = isset( $row['interval'] ) ? sanitize_key( $row['interval'] ) : '';
				if ( in_array( $posted_interval, self::VALID_INTERVALS, true ) ) {
					$interval = $posted_interval;
				} else {
					$interval = $this->get_recommended_interval_for_url( $url );
				}

				// v1.0.40 — Per-feed granular category. Empty string is valid
				// (means "RSS only"). Otherwise must be one of the whitelist
				// keys (News/World/Tech/Business/Science).
				$posted_cat = isset( $row['category'] ) ? sanitize_text_field( $row['category'] ) : '';
				if ( $posted_cat !== '' && ! isset( self::GRANULAR_CATEGORIES[ $posted_cat ] ) ) {
					$posted_cat = '';
				}

				$feed_record = array(
					'url'      => $url,
					'name'     => isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '',
					'interval' => $interval,
					'category' => $posted_cat,
				);
				if ( isset( $status_by_url[ $url ] ) ) {
					$feed_record = array_merge( $feed_record, $status_by_url[ $url ] );
					// Re-assert the freshly-validated interval and category after
					// the merge (status_by_url may carry older values we want
					// overridden by the form values).
					$feed_record['interval'] = $interval;
					$feed_record['category'] = $posted_cat;
				}
				$feeds[] = $feed_record;
			}
		}

		// v1.0.24 — Defense-in-depth: clamp to the active-feeds cap.
		// Catalog toggles already enforce this; this catches anyone editing
		// the Feeds tab directly past the limit.
		if ( count( $feeds ) > self::MAX_ACTIVE_FEEDS ) {
			$feeds = array_slice( $feeds, 0, self::MAX_ACTIVE_FEEDS );
		}

		$valid_status = array( 'publish', 'draft', 'pending' );
		$valid_freq   = array( 'gip_rss_5min', 'gip_rss_15min', 'gip_rss_30min', 'hourly', 'twicedaily', 'manual' );
		$valid_image  = array( 'feed_first', 'content_first', 'none' );

		$settings = $this->get_settings();
		$settings['feeds']       = $feeds;
		// v1.0.13 — invalid/missing post_status falls back to 'publish' to match
		// the documented default in get_defaults(). Previously fell back to 'draft',
		// which silently flipped imported posts to draft when the form value was
		// missing for any reason (e.g. a stripped POST or a legacy save shape).
		$settings['post_status'] = in_array( $in['post_status'] ?? '', $valid_status, true ) ? $in['post_status'] : 'publish';
		$settings['frequency']   = in_array( $in['frequency'] ?? '', $valid_freq, true )     ? $in['frequency']   : 'hourly';
		$settings['image_mode']      = in_array( $in['image_mode'] ?? '', $valid_image, true )   ? $in['image_mode']  : 'feed_first';
		$settings['min_image_width'] = max( 0, min( 4000, (int) ( $in['min_image_width'] ?? 1000 ) ) );
		$settings['max_per_run']     = max( 1, min( 100, (int) ( $in['max_per_run'] ?? 10 ) ) );

		// v1.0.37 — keep_on_uninstall. Unchecked checkboxes don't post a
		// value, so we explicitly read it as a presence check. The form
		// includes a hidden marker `keep_on_uninstall_present=1` to tell
		// us the checkbox WAS on the page (vs. an AJAX save from a panel
		// that doesn't include it) — without the marker we leave the
		// stored value alone.
		if ( isset( $in['keep_on_uninstall_present'] ) ) {
			$settings['keep_on_uninstall'] = ! empty( $in['keep_on_uninstall'] );
		}

		$this->save_settings( $settings );
	}

	public function handle_run_now() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$result = $this->run_import( true );

		$msg = sprintf(
			/* translators: 1: imported, 2: skipped, 3: errors */
			__( 'Import complete — %1$d new, %2$d skipped, %3$d errors.', 'thegridindex-rss-importer' ),
			$result['imported'], $result['skipped'], $result['errors']
		);

		wp_safe_redirect( add_query_arg( array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( $msg ),
			'gip_rss_type' => $result['errors'] ? 'error' : 'success',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Force re-import of recent items (last 24h by default). Deletes the
	 * existing duplicate first, then imports a fresh copy. Useful after
	 * settings changes (e.g. you flipped post_status from draft to publish
	 * and want recent items to come back as published).
	 */
	public function handle_force_reimport() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$hours  = 24;
		$result = $this->run_import( true, $hours );

		$msg = sprintf(
			/* translators: 1: hours, 2: imported, 3: skipped, 4: errors */
			__( 'Force re-import (last %1$dh) complete — %2$d refreshed, %3$d skipped, %4$d errors.', 'thegridindex-rss-importer' ),
			$hours, $result['imported'], $result['skipped'], $result['errors']
		);

		wp_safe_redirect( add_query_arg( array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( $msg ),
			'gip_rss_type' => $result['errors'] ? 'error' : 'success',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Fetch a single feed by its index in the feeds array. Used by the
	 * per-row "Fetch" button in the admin so you can test one feed at a
	 * time without waiting through all the others.
	 */
	public function handle_fetch_one_feed() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$idx    = isset( $_REQUEST['feed_index'] ) ? (int) $_REQUEST['feed_index'] : -1;
		$result = $this->run_import( true, 0, $idx );

		$s    = $this->get_settings();
		$feed = $s['feeds'][ $idx ] ?? null;
		$name = $feed['name'] ?? ( $feed['url'] ?? 'feed' );

		$msg = sprintf(
			/* translators: 1: feed name, 2: imported, 3: skipped, 4: errors */
			__( '%1$s — %2$d new, %3$d skipped, %4$d errors.', 'thegridindex-rss-importer' ),
			$name, $result['imported'], $result['skipped'], $result['errors']
		);

		wp_safe_redirect( add_query_arg( array(
			'page'         => self::PAGE_SLUG,
			'gip_rss_msg'  => rawurlencode( $msg ),
			'gip_rss_type' => $result['errors'] ? 'error' : 'success',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Importer
	 * ------------------------------------------------------------------- */

	/**
	 * Run an import across all configured feeds.
	 *
	 * @param bool $manual Whether this is a manual run (affects logging only).
	 * @return array { imported:int, skipped:int, errors:int }
	 */
	public function run_import( $manual = false, $force_reimport_hours = 0, $only_feed_index = null ) {
		$s      = $this->get_settings();
		$log    = array();
		$totals = array( 'imported' => 0, 'skipped' => 0, 'errors' => 0 );

		// If force-reimport mode is on, propagate it to import_item via the
		// settings array. Items older than $force_reimport_hours fall back to
		// normal dup-skip behavior (handled inside import_item).
		if ( $force_reimport_hours > 0 ) {
			$s['_force_reimport']       = true;
			$s['_force_reimport_hours'] = (int) $force_reimport_hours;
		}

		// v1.0.13 — Mark manual single-feed fetches so fetch_one_feed bypasses
		// the error backoff. The user pressing "Fetch" on a row in the admin
		// is an explicit retry signal that should always honor.
		if ( $only_feed_index !== null && $manual ) {
			$s['_manual_single_feed'] = true;
		}

		$scope_label = '';
		if ( $only_feed_index !== null ) {
			$scope_label = ' (single feed)';
		} elseif ( $force_reimport_hours > 0 ) {
			$scope_label = sprintf( ' (force re-import last %dh)', $force_reimport_hours );
		}

		$log[] = sprintf( '[%s] %s import started%s.',
			current_time( 'mysql' ),
			$manual ? 'Manual' : 'Scheduled',
			$scope_label
		);

		if ( empty( $s['feeds'] ) ) {
			$log[] = '  No feeds configured.';
			$this->store_log( $log );
			return $totals;
		}

		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		// v1.0.26 — Build the list of feeds we'll actually iterate (honoring
		// $only_feed_index) so the progress UI can show "3 of N" against the
		// real iteration count, not the configured total.
		// v1.0.34 — For CRON runs (not manual), also filter by per-feed
		// interval: only fetch a feed if at least `interval` seconds have
		// passed since its last_fetched. Manual Import Now still hits every
		// feed; single-feed mode ($only_feed_index) also bypasses the check.
		$iter_indices  = array();
		$skipped_count = 0;
		$now           = time();
		foreach ( $s['feeds'] as $idx => $feed_cfg ) {
			if ( $only_feed_index !== null && (int) $idx !== (int) $only_feed_index ) continue;
			if ( empty( $feed_cfg['url'] ) ) continue;

			// Per-feed interval gating, cron-only.
			if ( ! $manual && $only_feed_index === null ) {
				$interval     = isset( $feed_cfg['interval'] ) && in_array( $feed_cfg['interval'], self::VALID_INTERVALS, true )
					? $feed_cfg['interval'] : self::DEFAULT_INTERVAL;
				$interval_sec = self::INTERVAL_SECONDS[ $interval ];
				$last         = (int) ( $feed_cfg['last_fetched'] ?? 0 );
				if ( $last > 0 && ( $now - $last ) < $interval_sec ) {
					$skipped_count++;
					continue; // Not due yet.
				}
			}
			$iter_indices[] = (int) $idx;
		}
		if ( $skipped_count > 0 ) {
			$log[] = sprintf( '(skipped %d feeds not yet due for their interval)', $skipped_count );
		}
		$total      = count( $iter_indices );
		$step       = 0;
		$started_at = time();

		foreach ( $iter_indices as $idx ) {
			$feed_cfg = $s['feeds'][ $idx ];
			$url      = $feed_cfg['url'];
			$step++;

			// Write progress BEFORE the (potentially slow) fetch starts, so
			// pollers see "Fetching 3 of 11 — TechCrunch" the moment we begin.
			$this->write_progress( array(
				'step'        => $step,
				'total'       => $total,
				'current'     => isset( $feed_cfg['name'] ) && $feed_cfg['name'] !== '' ? $feed_cfg['name'] : $url,
				'started_at'  => $started_at,
				'updated_at'  => time(),
				'state'       => 'running',
			) );

			$log[] = '';
			$log[] = '> Feed: ' . $url;

			$feed_totals = $this->fetch_one_feed( $idx, $feed_cfg, $s, $log );
			$totals['imported'] += $feed_totals['imported'];
			$totals['skipped']  += $feed_totals['skipped'];
			$totals['errors']   += $feed_totals['errors'];
		}

		// Mark the run complete so the last poll picks it up before the
		// page reload — but only briefly; the page reload after redirect
		// will reset the UI to idle.
		$this->write_progress( array(
			'step'       => $total,
			'total'      => $total,
			'current'    => '',
			'started_at' => $started_at,
			'updated_at' => time(),
			'state'      => 'done',
		) );
		// Clear shortly after; 5s is enough for the last poll to read 'done'.
		// We can't sleep here (we're still serving the request), so we just
		// set a short TTL on the 'done' record.
		set_transient( self::PROGRESS_TRANSIENT, get_transient( self::PROGRESS_TRANSIENT ), 10 );

		$log[] = '';
		$log[] = sprintf( '[%s] Done. %d new, %d skipped, %d errors.',
			current_time( 'mysql' ),
			$totals['imported'], $totals['skipped'], $totals['errors']
		);

		// v1.0.42 — If we actually imported anything, the dup summary may
		// have changed; clear its cache so the Feeds banner reflects reality.
		if ( $totals['imported'] > 0 ) {
			delete_transient( 'gip_rss_dup_summary' );
		}

		$this->store_log( $log );
		return $totals;
	}

	/**
	 * Fetch a single feed and import its items. Updates the feed's
	 * last_status/last_fetched fields so the admin UI can show per-feed
	 * health at a glance. Receives $log by reference so per-item log
	 * lines append to the run log alongside other feeds.
	 *
	 * @param int   $idx       Index in $settings['feeds'] for this feed.
	 * @param array $feed_cfg  Feed config: url, name.
	 * @param array $settings  Effective settings for this run.
	 * @param array &$log      Run log lines (by reference).
	 * @return array { imported:int, skipped:int, errors:int }
	 */
	/**
	 * SimplePie tuning callback. Attached to `wp_feed_options` only during
	 * our own fetches via fetch_one_feed(); detached immediately after.
	 *
	 * Applies:
	 *   - 15s timeout (WP default is 5s — too aggressive for cold edges).
	 *   - Real-looking User-Agent that identifies us honestly without
	 *     getting filtered as a bot by lazy WAF rules.
	 *   - Disable SimplePie's HTTP/file cache so we never read a stale
	 *     12-hour-old copy on a fresh import run.
	 *
	 * @param SimplePie $simplepie SimplePie instance to configure.
	 * @param string    $url       Feed URL being fetched.
	 */
	public function tune_simplepie_for_fetch( $simplepie, $url ) {
		if ( ! is_object( $simplepie ) ) return;

		// Timeout. SimplePie ≥1.5 has set_timeout(); guarded for safety.
		if ( method_exists( $simplepie, 'set_timeout' ) ) {
			$simplepie->set_timeout( self::FETCH_TIMEOUT_SECONDS );
		}

		// User-Agent. Identify the importer honestly (so well-behaved
		// publishers can recognize us in their logs) but lead with a
		// Mozilla-shaped string so simple WAF rules don't reject us.
		if ( method_exists( $simplepie, 'set_useragent' ) ) {
			$site_url = home_url( '/' );
			$ua       = sprintf(
				'Mozilla/5.0 (compatible; GridIndexRSS/%s; +%s)',
				defined( 'THEGRIDINDEX_RSS_IMPORTER_VERSION' ) ? THEGRIDINDEX_RSS_IMPORTER_VERSION : '1.0',
				esc_url_raw( $site_url )
			);
			$simplepie->set_useragent( $ua );
		}

		// Cache off. set_cache_duration(0) post-fetch (existing code) doesn't
		// help if SimplePie already served a stale cached copy on the way in,
		// so we disable the cache layer entirely for our own fetches.
		if ( method_exists( $simplepie, 'enable_cache' ) ) {
			$simplepie->enable_cache( false );
		}
	}

	/**
	 * Wrap fetch_feed() with one retry on transient failure. On a hard
	 * WP_Error (timeout, DNS, 5xx), wait a couple seconds and try once
	 * more before giving up. Doubles total request time only when the
	 * first attempt failed — successful fetches are unaffected.
	 *
	 * @param string $url   Feed URL.
	 * @param array  &$log  Run log lines (by reference) for retry annotation.
	 * @return SimplePie|WP_Error
	 */
	private function fetch_with_retry( $url, &$log ) {
		$first = fetch_feed( $url );
		if ( ! is_wp_error( $first ) ) {
			return $first;
		}

		$log[] = sprintf(
			'  RETRY: first attempt failed (%s), retrying in %ds…',
			$first->get_error_message(),
			self::FETCH_RETRY_DELAY_SECS
		);

		// SimplePie may have stashed a partial parse in WP's transient
		// cache for this URL; clear it so the retry actually re-fetches.
		// SimplePie's WP cache key is sha1 of the URL.
		delete_transient( 'feed_' . md5( $url ) );
		delete_transient( 'feed_mod_' . md5( $url ) );

		sleep( self::FETCH_RETRY_DELAY_SECS );
		return fetch_feed( $url );
	}

	private function fetch_one_feed( $idx, $feed_cfg, $settings, &$log ) {
		$totals = array( 'imported' => 0, 'skipped' => 0, 'errors' => 0 );
		$url    = $feed_cfg['url'] ?? '';

		// v1.0.13 — Error backoff. If this feed errored recently (default 10min),
		// skip it for this scheduled run so one bad feed doesn't eat timeout
		// budget on every cron tick. Manual single-feed fetches and force-reimport
		// runs bypass the backoff so the user can still retry on demand.
		$is_manual_single_feed   = ! empty( $settings['_manual_single_feed'] );
		$is_force_reimport       = ! empty( $settings['_force_reimport'] );
		$bypass_backoff          = $is_manual_single_feed || $is_force_reimport;

		if ( ! $bypass_backoff
			&& isset( $feed_cfg['last_status'] ) && $feed_cfg['last_status'] === 'error'
			&& isset( $feed_cfg['last_fetched'] )
			&& ( time() - (int) $feed_cfg['last_fetched'] ) < self::FETCH_ERROR_BACKOFF_SEC
		) {
			$secs_left = self::FETCH_ERROR_BACKOFF_SEC - ( time() - (int) $feed_cfg['last_fetched'] );
			$log[]     = sprintf(
				'  SKIP: in error-backoff (%ds left). Last error: %s',
				$secs_left,
				isset( $feed_cfg['last_message'] ) ? $feed_cfg['last_message'] : 'unknown'
			);
			// Don't count as an error for this run — it's a deliberate skip.
			return $totals;
		}

		// v1.0.13 — Attach SimplePie tuning for this fetch only. Detached in
		// the finally block below regardless of outcome.
		add_filter( 'wp_feed_options', array( $this, 'tune_simplepie_for_fetch' ), 10, 2 );

		try {
			$feed = $this->fetch_with_retry( $url, $log );
		} finally {
			remove_filter( 'wp_feed_options', array( $this, 'tune_simplepie_for_fetch' ), 10 );
		}

		if ( is_wp_error( $feed ) ) {
			$err_msg = $feed->get_error_message();
			$totals['errors']++;
			$log[] = '  ERROR: ' . $err_msg;
			$this->update_feed_status( $idx, array(
				'last_status'   => 'error',
				'last_message'  => $err_msg,
				'last_imported' => 0,
				'last_skipped'  => 0,
				'last_errors'   => 1,
				'last_fetched'  => time(),
			) );
			return $totals;
		}

		$feed->set_cache_duration( 0 );
		$max       = (int) $settings['max_per_run'];
		$max_items = $feed->get_item_quantity( $max );
		$items     = $feed->get_items( 0, $max_items );

		if ( ! $items ) {
			$log[] = '  (no items)';
			$this->update_feed_status( $idx, array(
				'last_status'   => 'empty',
				'last_message'  => 'no items in feed',
				'last_imported' => 0,
				'last_skipped'  => 0,
				'last_errors'   => 0,
				'last_fetched'  => time(),
			) );
			return $totals;
		}

		$source_name = $feed_cfg['name'] ?? '';
		if ( ! $source_name ) $source_name = $feed->get_title();
		if ( ! $source_name ) {
			$host        = wp_parse_url( $url, PHP_URL_HOST );
			$source_name = $host ? preg_replace( '/^www\./', '', $host ) : 'RSS';
		}

		foreach ( $items as $item ) {
			$result = $this->import_item( $item, $feed_cfg, $source_name, $settings );
			$log[]  = '  ' . $result['log'];
			if ( $result['status'] === 'imported' )    $totals['imported']++;
			elseif ( $result['status'] === 'skipped' ) $totals['skipped']++;
			else                                       $totals['errors']++;
		}

		$status = 'ok';
		if ( $totals['imported'] === 0 && $totals['skipped'] > 0 ) $status = 'all-dup';
		if ( $totals['errors'] > 0 && $totals['imported'] === 0 )  $status = 'error';
		$msg = sprintf( '%d new, %d skipped', $totals['imported'], $totals['skipped'] );

		$this->update_feed_status( $idx, array(
			'last_status'   => $status,
			'last_message'  => $msg,
			'last_imported' => (int) $totals['imported'],
			'last_skipped'  => (int) $totals['skipped'],
			'last_errors'   => (int) $totals['errors'],
			'last_fetched'  => time(),
		) );

		return $totals;
	}

	/**
	 * Persist per-feed status fields back into the settings option.
	 * Re-reads settings each time to avoid clobbering concurrent updates
	 * (e.g. if two feeds finish near-simultaneously when the runtime
	 * eventually parallelizes).
	 */
	private function update_feed_status( $idx, array $status ) {
		$s = $this->get_settings();
		if ( ! isset( $s['feeds'][ $idx ] ) || ! is_array( $s['feeds'][ $idx ] ) ) return;
		$s['feeds'][ $idx ] = array_merge( $s['feeds'][ $idx ], $status );
		$this->save_settings( $s );
	}

	private function store_log( array $log ) {
		$s             = $this->get_settings();
		$s['last_run'] = time();
		$s['last_log'] = implode( "\n", $log );
		$this->save_settings( $s );
	}

	/**
	 * v1.0.26 — Write the current import progress to a transient so the
	 * admin UI can poll for it via AJAX. Called once before each feed in
	 * the import loop.
	 */
	private function write_progress( array $state ) {
		set_transient( self::PROGRESS_TRANSIENT, $state, self::PROGRESS_TTL_SECS );
	}

	/**
	 * v1.0.26 — Read current progress. Returns null when no import is
	 * running (transient absent). Public so the AJAX handler can call it.
	 */
	public function read_progress() {
		$state = get_transient( self::PROGRESS_TRANSIENT );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Import a single feed item.
	 *
	 * @param SimplePie_Item $item
	 * @param array          $feed_cfg
	 * @param string         $source_name
	 * @param array          $settings
	 * @return array { status: 'imported'|'skipped'|'error', log: string }
	 */
	private function import_item( $item, $feed_cfg, $source_name, $settings ) {
		$title = trim( wp_strip_all_tags( (string) $item->get_title() ) );
		if ( ! $title ) {
			return array( 'status' => 'skipped', 'log' => 'skip: no title' );
		}

		// Build the canonical identifier: prefer GUID, fall back to permalink.
		$guid_raw = $item->get_id();
		if ( ! $guid_raw ) $guid_raw = $item->get_permalink();
		if ( ! $guid_raw ) $guid_raw = $title;
		$guid_hash = md5( (string) $guid_raw );

		// v1.0.38 — Dedup check via the persistent seen-GUIDs ledger.
		// This catches BOTH cases the old get_posts() lookup missed:
		//   1. Trashed posts (post_status='any' excluded 'trash', so trashed
		//      items would re-import on the next fetch — wrong).
		//   2. Permanently-deleted posts (no postmeta record exists, so the
		//      old query returned 0 results and treated the item as new).
		// Force-reimport intentionally STILL honors the ledger so users
		// who deliberately deleted posts don't see them re-imported.
		$already_seen = $this->has_seen_guid( $guid_hash );

		// Still look up live (non-trashed) post IDs because force-reimport
		// needs to delete existing copies before re-creating fresh ones.
		// PHPCS notes:
		// - slow_db_query_meta_key / slow_db_query_meta_value: looking up by
		//   meta_key+meta_value is the entire point — find the live post
		//   for a specific GUID hash. The combined key+value lookup is
		//   indexed (postmeta has a key/value composite index) and runs
		//   at most once per dedup-confirmed item per fetch.
		// - SuppressFilters_suppress_filters: this is a deliberate
		//   defense against third-party plugins that hook pre_get_posts
		//   and silently restrict results. In a force-reimport, missing
		//   an "existing" post here means we orphan the old copy when
		//   we re-import; we want to bypass other plugins' filters to
		//   make sure we see the post we know is there. The query is
		//   scoped tightly (post_type='post', specific meta key+value,
		//   numberposts=1, fields='ids'), so it's not an injection or
		//   information-disclosure surface.
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
		$existing = $already_seen ? get_posts( array(
			'post_type'        => 'post',
			'post_status'      => array( 'publish', 'draft', 'pending' ),
			'meta_key'         => self::META_GUID,
			'meta_value'       => $guid_hash,
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		) ) : array();
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters

		// Force-reimport mode: also gated by item recency so we never re-fetch
		// the entire archive. Only items published in the last N hours qualify.
		$force_reimport = ! empty( $settings['_force_reimport'] );
		$item_age_hours = null;
		if ( $force_reimport ) {
			$item_date_gmt = $item->get_date( 'Y-m-d H:i:s' );
			if ( $item_date_gmt ) {
				$item_age_hours = ( time() - strtotime( $item_date_gmt . ' UTC' ) ) / 3600;
			}
			$max_age = isset( $settings['_force_reimport_hours'] ) ? (int) $settings['_force_reimport_hours'] : 24;
			if ( $item_age_hours === null || $item_age_hours > $max_age ) {
				// Too old for force-reimport scope; fall through to normal dup behavior.
				$force_reimport = false;
			}
		}

		if ( $already_seen ) {
			if ( $force_reimport && ! empty( $existing ) ) {
				// Delete the old copy (and its featured image attachment) so
				// we can re-import fresh with current settings.
				foreach ( $existing as $old_id ) {
					$old_thumb = get_post_thumbnail_id( $old_id );
					wp_delete_post( $old_id, true ); // true = bypass trash
					if ( $old_thumb ) {
						wp_delete_attachment( $old_thumb, true );
					}
				}
				// Continue past the dup check — fall through to import.
			} else {
				// v1.0.38 — Skip whether $existing exists or not.
				// Empty $existing here means the post was permanently deleted
				// but we still have the seen-ledger record. Skipping respects
				// the user's deletion.
				$reason = empty( $existing ) ? 'skip (previously deleted, ledger blocks re-import): '
				                              : 'skip (dup): ';
				return array( 'status' => 'skipped', 'log' => $reason . $title );
			}
		}

		$permalink = esc_url_raw( (string) $item->get_permalink() );
		$content   = (string) $item->get_content();
		if ( ! $content ) $content = (string) $item->get_description();
		$content = wp_kses_post( $content );

		// v1.0.14 — Date handling fix.
		//
		// SimplePie's get_date('Y-m-d H:i:s') returns the item's date string
		// without timezone info, which we were then assigning to BOTH
		// post_date and post_date_gmt. That confused wp_insert_post:
		//   - If the resulting "GMT" timestamp parsed as future relative to
		//     site time, WP would either reschedule the post (status='future')
		//     OR — when post_date_gmt is malformed/inconsistent with post_date —
		//     silently fall back to status='draft'.
		// Use get_date('U') to get an unambiguous Unix timestamp, clamp to
		// the present if the feed claims a future date (some feeds publish
		// items dated hours ahead — common with daily-edition publications
		// timestamped at midnight EST etc.), then format both fields from
		// that single source of truth.
		$item_unix = (int) $item->get_date( 'U' );
		$now_unix  = time();
		if ( $item_unix <= 0 ) {
			$item_unix = $now_unix;
		}
		// Clamp future-dated items to now so wp_insert_post never auto-demotes
		// 'publish' to 'future' (or, in malformed cases, 'draft').
		if ( $item_unix > $now_unix ) {
			$item_unix = $now_unix;
		}
		$date_gmt = gmdate( 'Y-m-d H:i:s', $item_unix );

		// IMAGE-REQUIRED PRE-CHECK: per v1.0.7 policy, posts with no
		// extractable image are skipped entirely — no empty thumbnails
		// in the Latest feed, no half-rendered cards on the homepage.
		$image_url = '';
		if ( $settings['image_mode'] === 'feed_first' ) {
			$image_url = $this->extract_feed_image( $item );
			if ( ! $image_url ) $image_url = $this->extract_content_image( $content );
		} elseif ( $settings['image_mode'] === 'content_first' ) {
			$image_url = $this->extract_content_image( $content );
		}
		// Only enforce the no-image skip when the user actually wants images.
		if ( $settings['image_mode'] !== 'none' && ! $image_url ) {
			return array( 'status' => 'skipped', 'log' => 'skip (no image): ' . $title );
		}

		// IMAGE-DIMENSION PRE-CHECK (v1.0.8): download the image to a temp
		// file BEFORE inserting the post and measure it. If smaller than the
		// configured minimum width, skip the post entirely. The temp file is
		// reused for sideload below if we proceed, so we never double-download.
		$tmp_image_path = '';
		if ( $image_url && $settings['image_mode'] !== 'none' ) {
			$min_w = isset( $settings['min_image_width'] ) ? (int) $settings['min_image_width'] : 1000;
			if ( $min_w > 0 ) {
				if ( ! function_exists( 'download_url' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				$tmp = download_url( $image_url, 15 );
				if ( is_wp_error( $tmp ) ) {
					return array( 'status' => 'skipped', 'log' => 'skip (image download failed): ' . $title );
				}
				$dims = @getimagesize( $tmp );
				if ( ! $dims || empty( $dims[0] ) ) {
					wp_delete_file( $tmp );
					return array( 'status' => 'skipped', 'log' => 'skip (image unreadable): ' . $title );
				}
				if ( (int) $dims[0] < $min_w ) {
					wp_delete_file( $tmp );
					return array( 'status' => 'skipped', 'log' => sprintf( 'skip (image too small %dx%d): %s', (int) $dims[0], (int) $dims[1], $title ) );
				}
				// Passed — keep the temp file for sideload.
				$tmp_image_path = $tmp;
			}
		}

		// Every imported post goes into the dedicated "RSS" category.
		$rss_cat_id = self::ensure_rss_category();

		// v1.0.40 — Plus the granular category from the feed config (News,
		// World, Tech, Business, Science) if set. Posts get both terms so
		// theme code that queries Category:RSS keeps working AND users can
		// browse per-source.
		$cat_ids = array();
		if ( $rss_cat_id ) $cat_ids[] = $rss_cat_id;
		$feed_cat_key = isset( $feed_cfg['category'] ) ? (string) $feed_cfg['category'] : '';
		if ( $feed_cat_key !== '' && isset( self::GRANULAR_CATEGORIES[ $feed_cat_key ] ) ) {
			$granular_id = $this->ensure_granular_category( $feed_cat_key );
			if ( $granular_id ) $cat_ids[] = $granular_id;
		}

		$postarr = array(
			'post_title'    => $title,
			'post_content'  => $content,
			'post_status'   => $settings['post_status'],
			'post_type'     => 'post',
			'post_date_gmt' => $date_gmt,
			'post_date'     => get_date_from_gmt( $date_gmt ),
		);
		if ( $cat_ids ) {
			$postarr['post_category'] = $cat_ids;
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			if ( $tmp_image_path ) wp_delete_file( $tmp_image_path );
			return array( 'status' => 'error', 'log' => 'error: ' . $post_id->get_error_message() );
		}

		// v1.0.14 — Post-insert status verification. If the user wants
		// 'publish' but WordPress (or another plugin's wp_insert_post_data
		// filter) demoted us to 'draft' or 'future', re-assert. We've
		// already eliminated the most common cause of demotion (future
		// post_date_gmt — see the date-handling block above) but other
		// plugins on the site may still intervene, and this catches it.
		$intended_status = $settings['post_status'];
		$actual_status   = get_post_status( $post_id );
		if ( $intended_status === 'publish' && $actual_status !== 'publish' ) {
			wp_update_post( array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			) );
		}

		// Belt-and-braces: re-assert the category in case any save_post
		// hook from another plugin reset it to Uncategorized.
		if ( $cat_ids ) {
			wp_set_post_categories( $post_id, $cat_ids, false );
		}

		// Canonical Grid Index source meta — lights up theme attribution.
		if ( $permalink )    update_post_meta( $post_id, '_gridindex_source_url',  $permalink );
		if ( $source_name )  update_post_meta( $post_id, '_gridindex_source_name', $source_name );
		update_post_meta( $post_id, self::META_GUID, $guid_hash );

		// v1.0.38 — Also record this GUID in the persistent ledger so it
		// stays blocked from re-import even if this post is later
		// permanently deleted.
		$this->record_seen_guid( $guid_hash, $permalink ?: '' );

		// Sideload — reusing the pre-downloaded temp file when we have one
		// so we don't make a second HTTP request for the same image.
		if ( $tmp_image_path ) {
			$this->sideload_featured_image_from_tmp( $tmp_image_path, $image_url, $post_id, $title );
		} elseif ( $image_url ) {
			$this->sideload_featured_image( $image_url, $post_id, $title );
		}

		return array( 'status' => 'imported', 'log' => 'import: ' . $title );
	}

	private function extract_feed_image( $item ) {
		$enclosure = $item->get_enclosure();
		if ( $enclosure ) {
			$link = $enclosure->get_link();
			if ( $link && $this->looks_like_image( $link ) ) return esc_url_raw( $link );
			$thumb = method_exists( $enclosure, 'get_thumbnail' ) ? $enclosure->get_thumbnail() : '';
			if ( $thumb ) return esc_url_raw( $thumb );
		}
		$ns = 'http://search.yahoo.com/mrss/';
		foreach ( array( 'thumbnail', 'content' ) as $tag ) {
			$nodes = $item->get_item_tags( $ns, $tag );
			if ( $nodes && isset( $nodes[0]['attribs']['']['url'] ) ) {
				return esc_url_raw( $nodes[0]['attribs']['']['url'] );
			}
		}
		return '';
	}

	private function extract_content_image( $html ) {
		if ( ! $html ) return '';
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$url = $m[1];
			if ( $this->looks_like_image( $url ) ) return esc_url_raw( $url );
		}
		return '';
	}

	private function looks_like_image( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) return false;
		return (bool) preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $path );
	}

	private function sideload_featured_image( $url, $post_id, $desc ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $url, 15 );
		if ( is_wp_error( $tmp ) ) return false;

		$file_array = array(
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $tmp,
		);

		$attach_id = media_handle_sideload( $file_array, $post_id, $desc );
		if ( is_wp_error( $attach_id ) ) {
			wp_delete_file( $tmp );
			return false;
		}

		set_post_thumbnail( $post_id, $attach_id );
		return $attach_id;
	}

	/**
	 * Sideload a featured image from a temp file we already downloaded
	 * (used when we pre-downloaded for dimension checking). Avoids a
	 * duplicate HTTP request to the same URL.
	 */
	private function sideload_featured_image_from_tmp( $tmp_path, $original_url, $post_id, $desc ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$basename = basename( wp_parse_url( $original_url, PHP_URL_PATH ) );
		if ( ! $basename ) $basename = 'rss-image-' . $post_id . '.jpg';

		$file_array = array(
			'name'     => $basename,
			'tmp_name' => $tmp_path,
		);

		$attach_id = media_handle_sideload( $file_array, $post_id, $desc );
		if ( is_wp_error( $attach_id ) ) {
			wp_delete_file( $tmp_path );
			return false;
		}

		set_post_thumbnail( $post_id, $attach_id );
		return $attach_id;
	}
}

TheGridIndex_RSS_Importer::instance();

// Activation hook — seed the curated starter feed list on first install.
register_activation_hook( __FILE__, array( 'TheGridIndex_RSS_Importer', 'activate' ) );
