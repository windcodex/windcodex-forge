<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A minimal audit log. Every tool call – success or failure – is recorded
 * here by WCFORGE_Tools::call(), so there's a record of exactly what an AI
 * platform read on the site and as whom.
 */
class WCFORGE_Activity_Log {

	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'wcforge_activity_log';
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				tool_name VARCHAR(64) NOT NULL,
				summary TEXT NULL,
				success TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id)
			) {$charset_collate};"
		);
	}

	public static function record( $user_id, $tool_name, $summary, $success = true ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'wcforge_activity_log',
			array(
				'user_id'    => (int) $user_id,
				'tool_name'  => sanitize_key( $tool_name ),
				'summary'    => wp_kses_post( mb_substr( (string) $summary, 0, 2000 ) ),
				'success'    => $success ? 1 : 0,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	public static function get_recent( $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcforge_activity_log';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore
	}

	/**
	 * A real count of today's recorded calls – used as the "changes made
	 * this session" figure, since Forge doesn't track browser sessions
	 * separately and a UTC-day count is the closest honest proxy.
	 */
	public static function get_today_count() {
		global $wpdb;
		$table       = $wpdb->prefix . 'wcforge_activity_log';
		$today_start = gmdate( 'Y-m-d 00:00:00' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $today_start ) ); // phpcs:ignore
	}

	public static function clear() {
		global $wpdb;
		$table = $wpdb->prefix . 'wcforge_activity_log';
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
	}
}
