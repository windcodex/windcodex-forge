<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place for reading/writing plugin configuration.
 *
 * WindCodex Forge has no write or code-execution primitives at all -
 * every tool here is read-only (file read, database SELECT, content-block
 * read, memory). Because of that, unlike WindCodex Forge Pro, there is no
 * live-site production check anywhere in this class - that check exists
 * specifically to protect write/execute primitives, none of which exist
 * in this plugin, so it's safe to run on a live, customer-facing site.
 */
class WCFORGE_Settings {

	private static $instance = null;

	const OPTION_KEY = 'wcforge_settings';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Default configuration used on activation and as a fallback.
	 */
	public static function default_settings() {
		return array(
			'enabled'                    => true,
			'read_only'                  => false,
			'connect_capability'         => 'manage_options',
			'tool_groups'                => array(
				'wp_site' => true, // The "look around" tool – always safe, always on by default.
			),
			'max_results_limit'          => 50,
			'requests_per_minute_limit'  => 120,
			'allow_dynamic_registration' => true,
			'delete_data_on_uninstall'   => false,
			'log_ai_activity'            => true,
			// Per-primitive on/off. Every primitive here is read-only, so
			// there's no risk tier to weigh against a live-site check -
			// all default on.
			'primitive_enabled'          => array(
				'wp_get_site_info'         => true,
				'wp_list_plugins'          => true,
				'wp_get_active_theme'      => true,
				'wp_list_hooks'            => true,
				'wp_get_design_authority'  => true,
				'wp_take_screenshot'       => true,
				'wp_inspect_page'          => true,
				'wp_list_recent_activity'  => true,
				'wp_list_files'            => true,
				'wp_read_file'             => true,
				'wp_db_read'               => true,
				'wp_list_tables'           => true,
				'wp_describe_table'        => true,
				'wp_get_content_block'     => true,
				'wp_list_reusable_blocks'  => true,
				'wp_remember'              => true,
				'wp_list_memories'         => true,
				'wp_forget'                => true,
			),
			'onboarding_completed'      => false,
		);
	}

	/**
	 * Single source of truth for each primitive's display metadata:
	 * icon, title, description, and risk tier.
	 */
	public static function primitive_metadata() {
		return array(
			'wp_get_site_info'         => array( 'icon' => 'info-circle', 'title' => 'Site info', 'desc' => 'Read-only: WordPress version, active theme.', 'risk' => 'low' ),
			'wp_list_plugins'          => array( 'icon' => 'plug', 'title' => 'List plugins', 'desc' => 'Read-only: installed plugins and versions.', 'risk' => 'low' ),
			'wp_get_active_theme'      => array( 'icon' => 'compass', 'title' => 'Get active theme', 'desc' => 'Read-only: active theme details.', 'risk' => 'low' ),
			'wp_list_hooks'            => array( 'icon' => 'link', 'title' => 'List hooks', 'desc' => 'Read-only: search registered WordPress hooks.', 'risk' => 'low' ),
			'wp_get_design_authority'  => array( 'icon' => 'layout-grid', 'title' => 'Get design authority', 'desc' => 'Read-only: which design system, if any, should govern new visual work.', 'risk' => 'low' ),
			'wp_take_screenshot'       => array( 'icon' => 'photo', 'title' => 'Take screenshot', 'desc' => 'Read-only: screenshot a page via a public service.', 'risk' => 'low' ),
			'wp_inspect_page'          => array( 'icon' => 'search', 'title' => 'Inspect page', 'desc' => 'Read-only: static HTML structure of a page.', 'risk' => 'low' ),
			'wp_list_recent_activity'  => array( 'icon' => 'activity', 'title' => 'List recent activity', 'desc' => 'Read-only: what this connector has done recently.', 'risk' => 'low' ),
			'wp_list_files'            => array( 'icon' => 'folder', 'title' => 'List files', 'desc' => 'Read-only: browse files within wp-content.', 'risk' => 'low' ),
			'wp_read_file'             => array( 'icon' => 'search', 'title' => 'Read file', 'desc' => 'Read-only: view a file\'s contents.', 'risk' => 'low' ),
			'wp_db_read'               => array( 'icon' => 'database', 'title' => 'Read database', 'desc' => 'Read rows from a table using structured, prepared filters.', 'risk' => 'low' ),
			'wp_list_tables'           => array( 'icon' => 'database', 'title' => 'List tables', 'desc' => 'Read-only: see what database tables exist.', 'risk' => 'low' ),
			'wp_describe_table'        => array( 'icon' => 'database', 'title' => 'Describe table', 'desc' => 'Read-only: see a table\'s column structure.', 'risk' => 'low' ),
			'wp_get_content_block'     => array( 'icon' => 'layout-grid', 'title' => 'Get content block', 'desc' => 'Read one block\'s full structure by position.', 'risk' => 'low' ),
			'wp_list_reusable_blocks'  => array( 'icon' => 'layout-grid', 'title' => 'List reusable blocks', 'desc' => 'List registered reusable blocks (synced patterns).', 'risk' => 'low' ),
			'wp_remember'              => array( 'icon' => 'history', 'title' => 'Remember', 'desc' => 'Save a fact or note site-wide.', 'risk' => 'low' ),
			'wp_list_memories'         => array( 'icon' => 'history', 'title' => 'List memories', 'desc' => 'Read-only: list saved memories.', 'risk' => 'low' ),
			'wp_forget'                => array( 'icon' => 'history', 'title' => 'Forget', 'desc' => 'Delete a saved memory.', 'risk' => 'low' ),
		);
	}

	/**
	 * The 4 primitive bundles shown in Settings, each bundling one or more
	 * real primitive_enabled keys underneath.
	 */
	public static function bundle_metadata() {
		return array(
			'introspection'  => array( 'icon' => 'compass', 'title' => 'Site introspection', 'desc' => 'Discover plugins, theme, hooks, files, and recent activity', 'risk' => 'low', 'primitives' => array( 'wp_get_site_info', 'wp_list_plugins', 'wp_get_active_theme', 'wp_list_hooks', 'wp_take_screenshot', 'wp_inspect_page', 'wp_list_recent_activity', 'wp_get_design_authority', 'wp_list_files', 'wp_read_file' ) ),
			'db_read'        => array( 'icon' => 'database', 'title' => 'Database read', 'desc' => 'Read data for debugging and context', 'risk' => 'low', 'primitives' => array( 'wp_db_read', 'wp_list_tables', 'wp_describe_table' ) ),
			'content_blocks' => array( 'icon' => 'layout-grid', 'title' => 'Content blocks (read-only)', 'desc' => 'Read a Gutenberg block\'s structure, and list reusable blocks', 'risk' => 'low', 'primitives' => array( 'wp_get_content_block', 'wp_list_reusable_blocks' ) ),
			'memory'         => array( 'icon' => 'history', 'title' => 'Memory', 'desc' => 'Save and recall notes across separate conversations', 'risk' => 'low', 'primitives' => array( 'wp_remember', 'wp_list_memories', 'wp_forget' ) ),
		);
	}

	public function is_primitive_enabled( $name ) {
		$enabled = $this->get( 'primitive_enabled', array() );
		if ( array_key_exists( $name, $enabled ) ) {
			return ! empty( $enabled[ $name ] );
		}
		// The key is entirely absent from saved settings - this happens
		// when a plugin update adds a new primitive after settings were
		// already saved, since WordPress doesn't deep-merge new nested
		// array keys into an existing option. Fall back to the shipped
		// default so a newly-added primitive isn't silently disabled on
		// every site that already had settings saved.
		$defaults = self::default_settings();
		return ! empty( $defaults['primitive_enabled'][ $name ] );
	}

	/**
	 * Whether a specific primitive is actually reachable right now. There
	 * is no live-site gate here - every primitive in this plugin is
	 * read-only, so the only condition is whether it's switched on.
	 */
	public function is_primitive_available( $name ) {
		return $this->is_primitive_enabled( $name );
	}

	/**
	 * Connector-wide requests-per-minute limit, user-editable from the
	 * General tab (default 120). Clamped to a sane range on every read, not
	 * just on save, so a stray/hand-edited option value can never disable
	 * the protection entirely (0 or absurdly high) or make the connector
	 * unusably slow (single digits).
	 */
	public function get_requests_per_minute_limit() {
		$limit = (int) $this->get( 'requests_per_minute_limit', 120 );
		return max( 10, min( 600, $limit ) );
	}

	public function get_all() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $settings, self::default_settings() );
	}

	public function get( $key, $default = null ) {
		$all = $this->get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	public function update( array $partial ) {
		$all = $this->get_all();
		$all = array_merge( $all, $partial );
		update_option( self::OPTION_KEY, $all );
		return $all;
	}

	public function is_group_enabled( $group ) {
		$groups = $this->get( 'tool_groups', array() );
		return ! empty( $groups[ $group ] );
	}

	public function is_read_only() {
		return (bool) $this->get( 'read_only', false );
	}

	public function is_enabled() {
		return (bool) $this->get( 'enabled', true );
	}

	public function get_max_results_limit() {
		$limit = (int) $this->get( 'max_results_limit', 50 );
		return $limit > 0 ? $limit : 50;
	}

	/**
	 * Choices for "Who can connect". Anything else stored in the option
	 * falls back to Administrators only.
	 */
	public static function connect_capability_choices() {
		return array(
			'manage_options'    => __( 'Administrators only', 'windcodex-forge' ),
			'edit_others_posts' => __( 'Editors and above', 'windcodex-forge' ),
		);
	}

	/**
	 * The capability a user needs to approve an AI connection – and that the
	 * connection's user must still hold on every request and tool call, so a
	 * demoted or deleted user's connection stops working immediately.
	 */
	public function get_connect_capability() {
		$capability = (string) $this->get( 'connect_capability', 'manage_options' );
		if ( ! array_key_exists( $capability, self::connect_capability_choices() ) ) {
			$capability = 'manage_options';
		}
		return (string) apply_filters( 'wcforge_connect_capability', $capability );
	}
}
