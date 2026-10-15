<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-wide persistent memory - facts, preferences, or context an AI
 * conversation can save so a *future*, separate conversation doesn't
 * start from zero. Deliberately site-wide rather than per-user: the
 * natural model here is "the site remembers this," not "this particular
 * chat remembers this." Mirrors WindCodex Ops's own memory feature -
 * same schema, same behavior, kept as a separate table since the two
 * plugins are meant to work fully independently of each other.
 *
 * Plaintext storage, not a secrets vault. No automatic expiration in
 * this version; a memory persists until explicitly forgotten.
 */
class WCFORGE_Memory {

	const MAX_MEMORIES = 200;

	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'wcforge_memories';
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				memory_key VARCHAR(191) NULL,
				content TEXT NOT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY memory_key (memory_key)
			) {$charset_collate};"
		);
	}

	/**
	 * Save a memory. If a key is given and a memory with that key already
	 * exists, it's overwritten (upsert) rather than duplicated. A
	 * null/empty key always creates a new freeform note.
	 *
	 * Returns the memory's ID, or a WP_Error if the site-wide cap has
	 * been reached (only checked for genuinely new rows, not updates to
	 * an existing key).
	 */
	public static function remember( $key, $content ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcforge_memories';
		$now   = current_time( 'mysql', true );
		$key   = '' !== trim( (string) $key ) ? trim( (string) $key ) : null;

		if ( $key ) {
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE memory_key = %s", $key ) ); // phpcs:ignore
			if ( $existing ) {
				$wpdb->update( $table, array( 'content' => $content, 'updated_at' => $now ), array( 'id' => $existing ) ); // phpcs:ignore
				return (int) $existing;
			}
		}

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		if ( $count >= self::MAX_MEMORIES ) {
			return new WP_Error( 'memory_limit_reached', 'This site has reached its ' . self::MAX_MEMORIES . '-memory limit. Use wp_forget to remove something no longer needed before saving a new one.' );
		}

		$wpdb->insert( // phpcs:ignore
			$table,
			array(
				'memory_key' => $key,
				'content'    => $content,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	public static function forget( $id_or_key ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcforge_memories';

		if ( is_numeric( $id_or_key ) ) {
			return (bool) $wpdb->delete( $table, array( 'id' => (int) $id_or_key ) ); // phpcs:ignore
		}
		return (bool) $wpdb->delete( $table, array( 'memory_key' => (string) $id_or_key ) ); // phpcs:ignore
	}

	public static function get_all( $limit = 200 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcforge_memories';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit ) ); // phpcs:ignore
	}

	public static function search( $query, $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcforge_memories';
		$like  = '%' . $wpdb->esc_like( $query ) . '%';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE content LIKE %s OR memory_key LIKE %s ORDER BY updated_at DESC LIMIT %d", $like, $like, $limit ) ); // phpcs:ignore
	}

	public static function count() {
		global $wpdb;
		$table = $wpdb->prefix . 'wcforge_memories';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
	}
}
