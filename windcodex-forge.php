<?php
/**
 * Plugin Name:       WindCodex Forge – Read-Only AI Diagnostics
 * Plugin URI:        https://windcodex.com/
 * Description:       Gives Claude, ChatGPT, and other MCP-compatible AI coding assistants safe, read-only access to inspect a WordPress site: installed plugins, theme, hooks, files, database structure and data, Gutenberg content blocks, and cross-conversation memory. No write or code-execution capability of any kind, so it's safe to run on a live site.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            WindCodex
 * Author URI:        https://windcodex.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       windcodex-forge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WCFORGE_VERSION', '1.0.0' );
define( 'WCFORGE_PLUGIN_FILE', __FILE__ );
define( 'WCFORGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCFORGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCFORGE_REST_NAMESPACE', 'windcodex-forge/v1' );
define( 'WCFORGE_REST_ROUTE', '/mcp' );
if ( ! defined( 'WCFORGE_PLUGIN_BASE' ) ) {
	define( 'WCFORGE_PLUGIN_BASE', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'WCFORGE_PRO_PLUGIN_BASE' ) ) {
	define( 'WCFORGE_PRO_PLUGIN_BASE', 'windcodex-forge-pro/windcodex-forge-pro.php' );
}

require_once WCFORGE_PLUGIN_DIR . 'includes/class-wcforge-rate-limiter.php';
require_once WCFORGE_PLUGIN_DIR . 'includes/class-wcforge-settings.php';
require_once WCFORGE_PLUGIN_DIR . 'includes/class-wcforge-oauth.php';
require_once WCFORGE_PLUGIN_DIR . 'includes/class-wcforge-activity-log.php';
require_once WCFORGE_PLUGIN_DIR . 'includes/class-wcforge-memory.php';
require_once WCFORGE_PLUGIN_DIR . 'includes/class-wcforge-tools.php';
require_once WCFORGE_PLUGIN_DIR . 'includes/class-wcforge-server.php';
require_once WCFORGE_PLUGIN_DIR . 'includes/class-wcforge-abilities.php';
require_once WCFORGE_PLUGIN_DIR . 'includes/class-wcforge-icons.php';
require_once WCFORGE_PLUGIN_DIR . 'admin/class-wcforge-admin.php';

/**
 * WindCodex Forge Pro is a genuinely separate, standalone plugin (full free
 * + pro feature set) rather than an add-on to this one - the two are never
 * meant to run active at the same time on one site.
 */
function wcforge_pro_is_active() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active( WCFORGE_PRO_PLUGIN_BASE );
}

/**
 * Set by wcforge_activate() when an activation is refused because Pro is
 * active, so the Plugins screen that loads next can say "not activated"
 * rather than "deactivated".
 */
define( 'WCFORGE_ACTIVATION_REFUSED', 'wcforge_activation_refused' );

function wcforge_activation_refused_message() {
	return __( '<strong>WindCodex Forge</strong> was not activated because <strong>WindCodex Forge Pro</strong> is already active. Pro includes every tool this plugin offers, so you don\'t need both. To use the free version instead, deactivate WindCodex Forge Pro first.', 'windcodex-forge' );
}

function wcforge_pro_active_notice() {
	$refused = get_transient( WCFORGE_ACTIVATION_REFUSED );
	delete_transient( WCFORGE_ACTIVATION_REFUSED );

	$message = $refused
		? wcforge_activation_refused_message()
		: __( '<strong>WindCodex Forge</strong> has been deactivated because <strong>WindCodex Forge Pro</strong> is active - Pro already includes every tool this plugin offers, so running both isn\'t necessary.', 'windcodex-forge' );

	echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
}

/**
 * On the Plugins screen straight after a refused activation, drop the
 * "Plugin activated." message WordPress would otherwise print next to ours.
 */
function wcforge_hide_activated_message() {
	if ( get_transient( WCFORGE_ACTIVATION_REFUSED ) ) {
		unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only suppresses a core display message, no state change.
	}
}

/**
 * Kick everything off.
 */
function wcforge_init() {
	if ( wcforge_pro_is_active() ) {
		// Straight after a refused activation, only switch off on a real admin
		// page (normally the Plugins screen WordPress redirects to), where the
		// notice below can explain it. A cron, AJAX, REST or front-end request
		// that happens to run first would otherwise deactivate Forge silently.
		// Until then Forge stays inert; the transient's expiry bounds the wait.
		if ( get_transient( WCFORGE_ACTIVATION_REFUSED ) && ( ! is_admin() || wp_doing_ajax() ) ) {
			return;
		}

		// Silent: skip our deactivation hook - nothing was set up, and
		// flushing rewrite rules this early (plugins_loaded) isn't safe.
		deactivate_plugins( WCFORGE_PLUGIN_BASE, true );
		if ( is_admin() ) {
			add_action( 'load-plugins.php', 'wcforge_hide_activated_message' );
			add_action( 'admin_notices', 'wcforge_pro_active_notice' );
			add_action( 'network_admin_notices', 'wcforge_pro_active_notice' );
		}
		return;
	}

	// Registered here, after the Pro check, so a refused activation leaves
	// no trace (no rewrite flush, no option written).
	add_action( 'init', 'wcforge_maybe_flush_rewrite_rules', 20 );

	WCFORGE_Settings::instance();
	WCFORGE_OAuth::instance();
	WCFORGE_Server::instance();
	WCFORGE_Abilities::instance();

	// Safety net for sites upgrading from a version of the plugin that
	// predates OAuth support: make sure the tables/static client exist
	// even if the activation hook never re-ran.
	if ( ! get_option( 'wcforge_oauth_tables_installed' ) ) {
		WCFORGE_OAuth::install_tables();
		update_option( 'wcforge_oauth_tables_installed', WCFORGE_VERSION );
	}

	// Same idea for the activity log table.
	if ( ! get_option( 'wcforge_activity_log_installed' ) ) {
		WCFORGE_Activity_Log::install_table();
		update_option( 'wcforge_activity_log_installed', WCFORGE_VERSION );
	}

	if ( ! get_option( 'wcforge_memories_installed' ) ) {
		WCFORGE_Memory::install_table();
		update_option( 'wcforge_memories_installed', WCFORGE_VERSION );
	}

	if ( is_admin() ) {
		WCFORGE_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'wcforge_init' );

/**
 * Flush rewrite rules once after updating to a version that changed them,
 * for sites that update plugin files without deactivating/reactivating.
 *
 * IMPORTANT: this must run on 'init' (after WCFORGE_OAuth has registered its
 * rewrite rule, also on 'init') rather than on 'plugins_loaded' – WordPress's
 * rewrite engine isn't fully ready that early, and calling flush_rewrite_rules()
 * during plugins_loaded can throw a fatal error.
 */
function wcforge_maybe_flush_rewrite_rules() {
	if ( get_option( 'wcforge_rewrite_flushed_version' ) !== WCFORGE_VERSION ) {
		flush_rewrite_rules();
		update_option( 'wcforge_rewrite_flushed_version', WCFORGE_VERSION );
	}
}

/**
 * On activation: refuse to activate, with an explanation, if Pro is already
 * running (Pro includes every feature this plugin offers), otherwise set sane defaults and
 * create the OAuth tables (including a pre-registered static client so
 * there's always a Client ID/Secret an admin can paste manually if a
 * platform's auto-registration fails).
 */
function wcforge_activate() {
	if ( wcforge_pro_is_active() ) {
		// WP-CLI has no Plugins screen to show a notice on, so refuse outright:
		// WordPress only adds a plugin to active_plugins after this hook
		// returns, so stopping here leaves Forge inactive with the reason
		// printed as a CLI error.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			wp_die( wp_kses_post( wcforge_activation_refused_message() ) );
		}

		// In the browser, skip all setup and let WordPress return to the
		// Plugins screen; wcforge_init() switches Forge straight back off on
		// that load and shows wcforge_pro_active_notice() explaining why.
		set_transient( WCFORGE_ACTIVATION_REFUSED, 1, 5 * MINUTE_IN_SECONDS );
		return;
	}

	if ( false === get_option( 'wcforge_settings' ) ) {
		update_option( 'wcforge_settings', WCFORGE_Settings::default_settings() );
	}

	WCFORGE_OAuth::install_tables();
	update_option( 'wcforge_oauth_tables_installed', WCFORGE_VERSION );

	WCFORGE_Activity_Log::install_table();
	update_option( 'wcforge_activity_log_installed', WCFORGE_VERSION );

	WCFORGE_Memory::install_table();
	update_option( 'wcforge_memories_installed', WCFORGE_VERSION );

	// The rewrite rule is normally registered on 'init', which has already
	// fired by the time this activation callback runs – so register it
	// explicitly here before flushing, or the rule won't exist yet to flush.
	WCFORGE_OAuth::add_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wcforge_activate' );

/**
 * On deactivation: just flush rewrite rules. We deliberately keep settings
 * so re-activating doesn't break an existing connector.
 */
function wcforge_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wcforge_deactivate' );
