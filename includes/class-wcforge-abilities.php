<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the same tool catalog as WCFORGE_Server through WordPress's native
 * Abilities API (wp_register_ability(), WP 6.9+), tagged so any adapter built
 * on that API — including the "Enable Abilities for MCP" plugin's default
 * MCP server and its admin "Abilities" tab — can list and run these tools
 * without knowing anything about this plugin's own OAuth server.
 *
 * This mirrors WCOPS_Abilities in the WindCodex Ops plugin. The bespoke
 * JSON-RPC server in class-wcforge-server.php keeps working unchanged for
 * existing Claude custom connector installs.
 *
 * Every safety gate WCFORGE_Tools already enforces still applies through
 * this path, because it's the same dispatcher: per-primitive enable state
 * and read-only mode both still control which tools even appear in
 * get_tool_definitions(). Nothing here bypasses that — it just runs the
 * same catalog through a second front door. (Unlike WindCodex Forge Pro,
 * there's no is_execution_allowed()/production-site lock in this plugin at
 * all — every primitive here is read-only, so there's nothing to gate.)
 */
class WCFORGE_Abilities {

	const CATEGORY = 'windcodex-forge';

	private static $instance = null;

	/** @var WCFORGE_Settings */
	private $settings;

	/** @var WCFORGE_Tools */
	private $tools;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = WCFORGE_Settings::instance();
		$this->tools    = new WCFORGE_Tools( $this->settings );

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WindCodex Forge', 'windcodex-forge' ),
				'description' => __( 'Read-only site, file, database, and content-block inspection tools, plus memory notes, exposed by WindCodex Forge.', 'windcodex-forge' ),
			)
		);
	}

	/**
	 * Registers every tool from WCFORGE_Tools::get_tool_definitions() as a
	 * native WP Ability. Runs on every request that reaches this hook, so
	 * per-primitive toggles and read-only mode (both already applied inside
	 * get_tool_definitions()) are reflected automatically — a tool switched
	 * off in Settings is not registered through this path either.
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // WP < 6.9 – the Abilities API isn't available.
		}

		foreach ( $this->tools->get_tool_definitions() as $tool ) {
			$this->register_one( $tool );
		}
	}

	private function register_one( array $tool ) {
		$tool_name    = $tool['name'];
		$ability_name = self::CATEGORY . '/' . str_replace( '_', '-', $tool_name );

		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name ) ) {
			return;
		}

		$readonly    = ! empty( $tool['annotations']['readOnlyHint'] );
		$destructive = ! empty( $tool['annotations']['destructiveHint'] );

		wp_register_ability(
			$ability_name,
			array(
				'label'               => $tool['title'] ?? $tool_name,
				'description'         => $tool['description'] ?? '',
				'category'            => self::CATEGORY,
				'input_schema'        => $tool['inputSchema'] ?? array( 'type' => 'object' ),
				'execute_callback'    => function ( $input = array() ) use ( $tool_name ) {
					return $this->execute( $tool_name, (array) $input );
				},
				'permission_callback' => function () use ( $tool_name ) {
					return current_user_can( $this->capability_for( $tool_name ) );
				},
				'meta'                => array(
					// Core's own generic broadcast flags (documentation / future core surfaces).
					'public'       => true,
					// This plugin already gates everything behind its own OAuth/REST
					// layer for the JSON-RPC server; don't also open a second, raw
					// core REST route for these abilities.
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => $readonly,
						'destructive' => $destructive,
					),
					// The convention read by wordpress/mcp-adapter's default server
					// (bundled by "Enable Abilities for MCP" and similar plugins) to
					// decide what's MCP-reachable.
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Runs the tool through the same dispatcher and safety gates as the OAuth
	 * MCP server (read-only mode and per-primitive toggles still apply
	 * inside WCFORGE_Tools::call()), then unwraps the MCP {content, isError}
	 * envelope into the plain return value / WP_Error the Abilities API
	 * expects.
	 *
	 * Passes the real logged-in user so any per-tool checks run as *them*.
	 */
	private function execute( $tool_name, array $input ) {
		$result = $this->tools->call( $tool_name, $input, get_current_user_id() );

		WCFORGE_Activity_Log::record(
			get_current_user_id(),
			$tool_name,
			empty( $input ) ? '' : wp_json_encode( $input ),
			empty( $result['isError'] )
		);

		$text = $result['content'][0]['text'] ?? '';

		if ( ! empty( $result['isError'] ) ) {
			return new WP_Error( 'wcforge_tool_error', $text, array( 'status' => 400 ) );
		}

		return $text;
	}

	/**
	 * Every Forge tool requires manage_options, no exceptions.
	 *
	 * Unlike WindCodex Ops, there's no lower-risk "content editing" tier here
	 * to carve out — even though every tool in this plugin is read-only,
	 * file and raw database contents can still be sensitive. Override with
	 * the wcforge_ability_capability filter if a site genuinely wants a
	 * lower bar.
	 */
	private function capability_for( $tool_name ) {
		return apply_filters( 'wcforge_ability_capability', 'manage_options', $tool_name );
	}
}
