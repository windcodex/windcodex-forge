<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines every tool this connector can offer, and dispatches tool calls.
 *
 * Every tool is individually toggleable from the admin settings page, and
 * every call is recorded in WCFORGE_Activity_Log.
 */
class WCFORGE_Tools {

	/** @var WCFORGE_Settings */
	private $settings;

	/**
	 * The account a tool call is checked against. Kept here instead of
	 * switching the request's logged-in user to that account, so a
	 * remote call never runs with anyone's logged-in session - it only
	 * reads, after user_can() confirms that account may see the data.
	 *
	 * @var int
	 */
	private $acting_user_id = 0;

	public function __construct( WCFORGE_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build the list of tools currently exposed, based on enabled groups
	 * and the read-only switch. Shape matches the MCP tools/list schema.
	 */
	public function get_tool_definitions() {
		$tools = array();

		// The "look around" tool – always available, read-only, so the AI
		// can understand the site before writing code for it.
		if ( $this->settings->is_group_enabled( 'wp_site' ) ) {
			$tools = array_merge( $tools, $this->wp_site_tools() );
		}

		// Read-only power tools (file read, DB read, content-block read,
		// memory). Unlike WindCodex Forge Pro, none of these can change
		// anything on the site, so they're not gated behind the live-site
		// production check at all - that check exists specifically to
		// protect the write/execute primitives, none of which exist in
		// this plugin. Individually toggleable below like everything else.
		$tools = array_merge( $tools, $this->dev_tools() );

		// Per-primitive enable/disable – a real filter, not
		// decorative. This runs regardless of the live-site block above:
		// both have to allow a tool for it to actually appear.
		$settings_ref = $this->settings;
		$tools = array_values(
			array_filter(
				$tools,
				function ( $tool ) use ( $settings_ref ) {
					return $settings_ref->is_primitive_available( $tool['name'] );
				}
			)
		);

		// If read-only mode is on, strip out every tool that isn't flagged readOnlyHint.
		if ( $this->settings->is_read_only() ) {
			$tools = array_values(
				array_filter(
					$tools,
					function ( $tool ) {
						return ! empty( $tool['annotations']['readOnlyHint'] );
					}
				)
			);
		}

		return $tools;
	}

	/**
	 * Dispatch a tools/call request to the right handler.
	 *
	 * @param string   $name    Tool name.
	 * @param array    $args    Arguments supplied by Claude.
	 * @param int      $user_id WP user ID resolved from the request's auth:
	 *                          the user who logged in and approved the OAuth
	 *                          consent screen, or the logged-in user for an
	 *                          Abilities API call. There is no fallback user.
	 * @return array MCP-shaped tool result: ['content' => [...], 'isError' => bool]
	 */
	public function call( $name, array $args, $user_id ) {
		$available = wp_list_pluck( $this->get_tool_definitions(), 'name' );

		if ( ! in_array( $name, $available, true ) ) {
			return $this->error_result( "Unknown or disabled tool: {$name}" );
		}

		// Check every tool's capability against the authenticated account.
		// The request's current user is never switched to it. There is no
		// fallback user.
		$acting_user_id = (int) $user_id;
		if ( $acting_user_id <= 0 ) {
			return $this->error_result( 'No authenticated user for this call.' );
		}
		$this->acting_user_id = $acting_user_id;

		// Deny by default: every tool first requires the "Who can connect"
		// capability, on top of any stricter check inside the tool itself.
		if ( ! $this->acting_user_can( $this->settings->get_connect_capability() ) ) {
			return $this->error_result( 'Your account is not allowed to use this connector.' );
		}

		try {
			$result = $this->dispatch( $name, $args );
		} catch ( Exception $e ) {
			$result = $this->error_result( $e->getMessage() );
		}

		// The audit-trail entry is written by the caller (the MCP server's
		// tools/call handler, or the Abilities API wrapper), so rejected
		// calls are recorded too and nothing is logged twice.
		return $result;
	}

	/**
	 * Capability check against the authenticated account (see
	 * $acting_user_id), not the request's current user.
	 *
	 * @param string $capability Capability name.
	 * @param mixed  ...$args    Optional object ID etc., as for user_can().
	 */
	private function acting_user_can( $capability, ...$args ) {
		return $this->acting_user_id > 0 && user_can( $this->acting_user_id, $capability, ...$args );
	}

	private function dispatch( $name, array $args ) {
		switch ( $name ) {
			case 'wp_get_site_info':
				return $this->wp_get_site_info( $args );
			case 'wp_list_plugins':
				return $this->wp_list_plugins( $args );
			case 'wp_get_active_theme':
				return $this->wp_get_active_theme( $args );
			case 'wp_list_hooks':
				return $this->wp_list_hooks( $args );
			case 'wp_get_design_authority':
				return $this->wp_get_design_authority( $args );
			case 'wp_take_screenshot':
				return $this->wp_take_screenshot( $args );
			case 'wp_inspect_page':
				return $this->wp_inspect_page( $args );
			case 'wp_list_recent_activity':
				return $this->wp_list_recent_activity( $args );
			case 'wp_list_files':
				return $this->wp_list_files( $args );
			case 'wp_read_file':
				return $this->wp_read_file( $args );
			case 'wp_get_content_block':
				return $this->wp_get_content_block( $args );
			case 'wp_list_reusable_blocks':
				return $this->wp_list_reusable_blocks( $args );
			case 'wp_remember':
				return $this->wp_remember( $args );
			case 'wp_list_memories':
				return $this->wp_list_memories( $args );
			case 'wp_forget':
				return $this->wp_forget( $args );
			case 'wp_list_tables':
				return $this->wp_list_tables( $args );
			case 'wp_describe_table':
				return $this->wp_describe_table( $args );
			case 'wp_db_read':
				return $this->wp_db_read( $args );
			default:
				return $this->error_result( "Tool not implemented: {$name}" );
		}
	}

	/* -----------------------------------------------------------------
	 * Tool group: Site info
	 * ------------------------------------------------------------- */

	private function wp_site_tools() {
		return array(
			array(
				'name'        => 'wp_get_site_info',
				'title'       => 'Get Site Info',
				'description' => 'Get basic info about this WordPress site: name, description, URL, WordPress version, active theme, and whether WooCommerce is active.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_list_plugins',
				'title'       => 'List Plugins',
				'description' => 'Lists installed plugins with their name, version, and whether each is currently active – so code can be written to work with what\'s actually installed instead of guessing.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_active_theme',
				'title'       => 'Get Active Theme',
				'description' => 'Gets detailed information about the active theme: name, version, template/stylesheet slugs, and parent theme if this is a child theme.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_list_hooks',
				'title'       => 'List Hooks',
				'description' => 'Searches WordPress\'s registered actions and filters for hook names containing a search term, and how many callbacks are attached to each – useful for finding the right hook to use before writing code, rather than guessing. Optionally scope results to hooks with at least one callback actually defined inside a specific plugin\'s folder, rather than just matching on hook name.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array( 'type' => 'string', 'description' => 'Substring to match against hook names, e.g. "woocommerce_order".' ),
						'plugin' => array( 'type' => 'string', 'description' => 'Optional: a plugin folder name (e.g. "woocommerce") from wp_list_plugins. Only hooks with a callback whose code lives inside that plugin\'s folder are returned.' ),
					),
					'required'   => array( 'search' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_design_authority',
				'title'       => 'Get Design Authority',
				'description' => 'Read-only: works out which design system, if any, should govern new visual work on this site - an active page builder, the active block theme\'s own defined color/font tokens, or neither - and returns concrete guidance for which to follow. Meant to be called before creating or styling new content, not after.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
					array(
				'name'        => 'wp_take_screenshot',
				'title'       => 'Take Screenshot',
				'description' => 'Gets a screenshot URL of a page as it currently renders, via WordPress.com\'s public mshots service (no API key required, no browser installed on this server). The screenshot may take a few seconds to generate the first time a given URL is requested – if it looks blank or stale, wait and request it again.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array( 'type' => 'string', 'description' => 'Full URL of the page to screenshot. Must be publicly reachable – this can\'t screenshot a page behind a login.' ),
					),
					'required'   => array( 'url' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_inspect_page',
				'title'       => 'Inspect Page',
				'description' => 'Fetches a page\'s rendered HTML and reports its basic structure: title, meta description, heading count by level, image count, script/stylesheet count, and page size. This is static-HTML inspection, not a real browser – it won\'t see content injected by client-side JavaScript after page load.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array( 'type' => 'string' ),
					),
					'required'   => array( 'url' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_list_recent_activity',
				'title'       => 'List Recent Activity',
				'description' => 'Lists the most recent tool calls made through this connector – including ones from earlier sessions – with full argument detail, success/failure, and timestamp. Use this to check what has already been changed before deciding what to do next, instead of relying on conversation memory alone.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'description' => 'Maximum entries to return (default 30, max 100).' ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
		);
	}

	private function wp_get_site_info( $args ) {
		global $wp_version;

		$theme = wp_get_theme();

		$data = array(
			'name'              => get_bloginfo( 'name' ),
			'description'       => get_bloginfo( 'description' ),
			'url'               => home_url(),
			'wordpress_version' => $wp_version,
			'active_theme'      => $theme ? $theme->get( 'Name' ) : null,
			'woocommerce_active' => class_exists( 'WooCommerce' ),
			'woocommerce_version' => class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' ) ? WC_VERSION : null,
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_list_recent_activity( $args ) {
		$limit   = max( 1, min( 100, (int) ( $args['limit'] ?? 30 ) ) );
		$entries = WCFORGE_Activity_Log::get_recent( $limit );

		$data = array_map(
			function ( $entry ) {
				return array(
					'tool'       => $entry->tool_name,
					'summary'    => $entry->summary,
					'success'    => (bool) $entry->success,
					'created_at' => $entry->created_at,
				);
			},
			$entries
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_list_plugins( $args ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );

		$data = array();
		foreach ( $all_plugins as $path => $info ) {
			$data[] = array(
				'name'    => $info['Name'],
				'version' => $info['Version'],
				'active'  => in_array( $path, $active_plugins, true ),
			);
		}

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_get_active_theme( $args ) {
		$theme = wp_get_theme();

		$data = array(
			'name'         => $theme->get( 'Name' ),
			'version'      => $theme->get( 'Version' ),
			'template'     => $theme->get_template(),
			'stylesheet'   => $theme->get_stylesheet(),
			'is_child_theme' => $theme->parent() ? true : false,
			'parent_theme' => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Best-effort resolution of the file a hook callback is actually
	 * defined in, via Reflection – used to scope wp_list_hooks to a
	 * specific plugin's own code rather than just matching on hook name.
	 * Returns false for anything Reflection can't pin down (e.g. an
	 * internal PHP function), which simply excludes it from a
	 * plugin-scoped search rather than erroring.
	 */
	private function get_callback_file( $callback ) {
		try {
			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				return ( new ReflectionMethod( $callback[0], $callback[1] ) )->getFileName();
			}
			if ( $callback instanceof Closure ) {
				return ( new ReflectionFunction( $callback ) )->getFileName();
			}
			if ( is_string( $callback ) && function_exists( $callback ) ) {
				return ( new ReflectionFunction( $callback ) )->getFileName();
			}
			if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				return ( new ReflectionMethod( $callback, '__invoke' ) )->getFileName();
			}
		} catch ( \ReflectionException $e ) {
			return false;
		}
		return false;
	}

	private function wp_list_hooks( $args ) {
		global $wp_filter;

		$search        = strtolower( sanitize_text_field( $args['search'] ?? '' ) );
		$plugin_filter = trim( sanitize_text_field( $args['plugin'] ?? '' ), '/' );

		if ( '' === $search ) {
			return $this->error_result( 'search is required.' );
		}

		$plugin_dir = $plugin_filter ? wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $plugin_filter ) : '';

		$matches = array();
		foreach ( $wp_filter as $hook_name => $hook_object ) {
			if ( false === strpos( strtolower( (string) $hook_name ), $search ) ) {
				continue;
			}

			$callback_count = 0;
			$plugin_match   = ( '' === $plugin_filter );

			if ( is_object( $hook_object ) && isset( $hook_object->callbacks ) && is_array( $hook_object->callbacks ) ) {
				foreach ( $hook_object->callbacks as $priority_group ) {
					foreach ( $priority_group as $callback_data ) {
						$callback_count++;
						if ( '' !== $plugin_filter && ! $plugin_match ) {
							$file = $this->get_callback_file( $callback_data['function'] ?? null );
							if ( $file && 0 === strpos( wp_normalize_path( $file ), $plugin_dir ) ) {
								$plugin_match = true;
							}
						}
					}
				}
			}

			if ( ! $plugin_match ) {
				continue;
			}

			$matches[] = array(
				'hook'            => $hook_name,
				'callback_count'  => $callback_count,
			);
			if ( count( $matches ) >= 100 ) {
				break; // Cap output for very broad search terms.
			}
		}

		return $this->text_result( wp_json_encode( $matches, JSON_PRETTY_PRINT ) );
	}

	private function wp_get_design_authority( $args ) {
		if ( ! $this->acting_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}

		$builders = array();
		if ( defined( 'ELEMENTOR_VERSION' ) || class_exists( 'Elementor\Plugin' ) ) {
			$builders[] = 'Elementor';
		}
		if ( defined( 'ET_BUILDER_VERSION' ) || function_exists( 'et_setup_theme' ) ) {
			$builders[] = 'Divi';
		}
		if ( class_exists( 'FLBuilderModel' ) || defined( 'FL_BUILDER_VERSION' ) ) {
			$builders[] = 'Beaver Builder';
		}

		if ( count( $builders ) > 1 ) {
			return $this->text_result(
				wp_json_encode(
					array(
						'authority'         => 'ambiguous',
						'builders_detected' => $builders,
						'guidance'          => 'More than one page builder appears active (' . implode( ', ', $builders ) . '). This is unusual - confirm with the site owner which one actually governs new content before assuming either one\'s conventions apply.',
					),
					JSON_PRETTY_PRINT
				)
			);
		}

		if ( 1 === count( $builders ) ) {
			return $this->text_result(
				wp_json_encode(
					array(
						'authority' => $builders[0],
						'guidance'  => "{$builders[0]} governs this site's design. Before creating new visual content, inspect a few existing pages built with it to find the colors, fonts, and spacing already in use, and match those rather than introducing new values.",
					),
					JSON_PRETTY_PRINT
				)
			);
		}

		// No page builder - check whether the active block theme defines
		// its own design tokens via theme.json. wp_get_global_settings()
		// is real WordPress core API (5.9+), not a guess at internal
		// plugin storage the way a page builder's own globals would be.
		$is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$design_tokens  = null;

		if ( $is_block_theme && function_exists( 'wp_get_global_settings' ) ) {
			$global_settings = wp_get_global_settings();
			$palette         = $global_settings['color']['palette']['theme'] ?? array();

			$font_families = array();
			foreach ( (array) ( $global_settings['typography']['fontFamilies']['theme'] ?? array() ) as $font ) {
				$name = $font['name'] ?? ( $font['fontFamily'] ?? null );
				if ( $name ) {
					$font_families[] = $name;
				}
			}

			if ( ! empty( $palette ) || ! empty( $font_families ) ) {
				$design_tokens = array(
					'colors'        => array_map(
						function ( $c ) {
							return array( 'name' => $c['name'] ?? '', 'value' => $c['color'] ?? '' );
						},
						$palette
					),
					'font_families' => array_values( array_unique( $font_families ) ),
				);
			}
		}

		if ( $design_tokens ) {
			return $this->text_result(
				wp_json_encode(
					array(
						'authority'     => 'block_theme',
						'theme'         => wp_get_theme()->get( 'Name' ),
						'design_tokens' => $design_tokens,
						'guidance'      => "This site's active block theme defines its own design tokens above (from theme.json). Match these colors and font families rather than introducing new ones.",
					),
					JSON_PRETTY_PRINT
				)
			);
		}

		return $this->text_result(
			wp_json_encode(
				array(
					'authority' => 'none',
					'guidance'  => 'No dominant design system detected on this site - no active page builder, and no custom design tokens defined by the active theme. You have design authority for new visual content: use sensible, tasteful, accessible defaults for typography, color, and spacing.',
				),
				JSON_PRETTY_PRINT
			)
		);
	}

	private function wp_take_screenshot( $args ) {
		$url = esc_url_raw( $args['url'] ?? '' );
		if ( '' === $url ) {
			return $this->error_result( 'url is required.' );
		}
		// mshots is a free, public, no-API-key screenshot service run by
		// WordPress.com – this doesn't require installing a headless
		// browser on this server, which isn't realistic for a WP plugin.
		$screenshot_url = 'https://s.wordpress.com/mshots/v1/' . rawurlencode( $url ) . '?w=1200';
		return $this->text_result( wp_json_encode( array( 'screenshot_url' => $screenshot_url, 'note' => 'The image may take a few seconds to render the first time this URL is requested – if it looks blank, wait a moment and fetch it again.' ), JSON_PRETTY_PRINT ) );
	}

	private function wp_inspect_page( $args ) {
		$url = esc_url_raw( $args['url'] ?? '' );
		if ( '' === $url ) {
			return $this->error_result( 'url is required.' );
		}
		// The "safe" variant refuses private/loopback addresses and
		// non-standard ports, so the AI can't use this to probe the
		// server's internal network.
		$response = wp_safe_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $this->error_result( 'Could not fetch the page: ' . $response->get_error_message() );
		}
		$html = wp_remote_retrieve_body( $response );

		preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $title_match );
		preg_match( '/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/is', $html, $desc_match );

		$data = array(
			'http_status'      => wp_remote_retrieve_response_code( $response ),
			'page_size_bytes'  => strlen( $html ),
			'title'            => isset( $title_match[1] ) ? trim( wp_strip_all_tags( $title_match[1] ) ) : null,
			'meta_description' => $desc_match[1] ?? null,
			'heading_counts'   => array(
				'h1' => substr_count( strtolower( $html ), '<h1' ),
				'h2' => substr_count( strtolower( $html ), '<h2' ),
				'h3' => substr_count( strtolower( $html ), '<h3' ),
			),
			'image_count'      => substr_count( strtolower( $html ), '<img' ),
			'script_count'     => substr_count( strtolower( $html ), '<script' ),
			'stylesheet_count' => substr_count( strtolower( $html ), 'rel="stylesheet"' ) + substr_count( strtolower( $html ), "rel='stylesheet'" ),
			'note'             => 'This inspects the raw HTML response only – content added by client-side JavaScript after page load won\'t be reflected here.',
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	/* -----------------------------------------------------------------
	 * Tool group: Read-only file, database, content-block, and memory
	 * access. None of this can change anything on the site - WindCodex
	 * Forge has no write or code-execution primitives at all (see
	 * WindCodex Forge Pro for those). Every call here still requires
	 * manage_options (Administrator), even when "Who can connect" allows
	 * Editors, since file and raw database
	 * contents can be sensitive even read-only.
	 *
	 * File access is sandboxed to WP_CONTENT_DIR (themes/plugins/uploads),
	 * not the whole server.
	 * ------------------------------------------------------------- */

	private function dev_tools() {
		return array(
			array(
				'name'        => 'wp_list_files',
				'title'       => 'List Files',
				'description' => 'Lists the files and folders within a specified directory under wp-content, providing visibility into the site\'s file structure.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array( 'type' => 'string', 'description' => 'Path relative to wp-content, e.g. "themes/twentytwentyfour". Empty for wp-content root.' ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),array(
				'name'        => 'wp_read_file',
				'title'       => 'Read File',
				'description' => 'Reads the contents of a text file located within wp-content (themes, plugins, or uploads).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array( 'type' => 'string', 'description' => 'Path relative to wp-content, e.g. "themes/mytheme/functions.php".' ),
					),
					'required'   => array( 'path' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),array(
				'name'        => 'wp_get_content_block',
				'title'       => 'Get Content Block',
				'description' => 'Read-only: reads one specific block\'s full structure (attributes, inner HTML, and how many nested blocks it contains) by position, using the real Gutenberg block parser - deeper than a simple type/position listing.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'position' => array( 'type' => 'integer', 'description' => 'The block index (0-based).' ),
					),
					'required'   => array( 'id', 'position' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),array(
				'name'        => 'wp_list_reusable_blocks',
				'title'       => 'List Reusable Blocks',
				'description' => 'Read-only: lists this site\'s registered reusable blocks (synced patterns), so one can be referenced or inserted by ID rather than rebuilding it from scratch.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'default' => 20 ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),array(
				'name'        => 'wp_remember',
				'title'       => 'Remember',
				'description' => 'Saves a fact, preference, or piece of context site-wide so a future, separate conversation can pick it back up. Give it a short key (e.g. "brand_color") to make it overwritable rather than duplicated on the next save, or omit the key for a one-off freeform note. Plaintext storage - not meant for secrets or credentials.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'key'     => array( 'type' => 'string', 'description' => 'Optional short identifier. Saving again with the same key overwrites it instead of creating a duplicate.' ),
						'content' => array( 'type' => 'string', 'description' => 'The fact or note to remember.' ),
					),
					'required'   => array( 'content' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),array(
				'name'        => 'wp_list_memories',
				'title'       => 'List Memories',
				'description' => 'Read-only: lists saved memories, most recently updated first, optionally filtered to ones whose key or content contains a search term. Call this near the start of a conversation to pick up context saved in an earlier, separate one.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array( 'type' => 'string', 'description' => 'Optional: only return memories whose key or content contains this text.' ),
						'limit'  => array( 'type' => 'integer', 'default' => 50 ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),array(
				'name'        => 'wp_forget',
				'title'       => 'Forget',
				'description' => 'Permanently deletes one saved memory, by its numeric ID (from wp_list_memories) or by its key.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id_or_key' => array( 'type' => 'string', 'description' => 'The memory\'s numeric ID or its key.' ),
					),
					'required'   => array( 'id_or_key' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),array(
				'name'        => 'wp_list_tables',
				'title'       => 'List Database Tables',
				'description' => 'Read-only: lists every table in the WordPress database, with its approximate row count and size on disk – a first look before deciding what to query with wp_db_read.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),array(
				'name'        => 'wp_describe_table',
				'title'       => 'Describe Database Table',
				'description' => 'Read-only: lists a single table\'s columns – name, type, nullability, key, and default – the data structure behind it, rather than its actual row data.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'table' => array( 'type' => 'string', 'description' => 'The exact table name (with prefix), e.g. "wp_options", from wp_list_tables.' ),
					),
					'required'   => array( 'table' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),array(
				'name'        => 'wp_db_read',
				'title'       => 'Read Database',
				'description' => 'Read-only: reads rows from a single database table. The query is built from structured filters (no raw SQL) – table and column names must exist (see wp_list_tables / wp_describe_table), and every filter value is bound as a prepared-statement placeholder. Results are capped at 200 rows.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'table'    => array( 'type' => 'string', 'description' => 'The exact table name (with prefix), e.g. "wp_options", from wp_list_tables.' ),
						'columns'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Columns to return. Omit for all columns.',
						),
						'where'    => array(
							'type'        => 'array',
							'description' => 'Conditions, combined with AND.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'column'   => array( 'type' => 'string' ),
									'operator' => array(
										'type' => 'string',
										'enum' => self::DB_READ_OPERATORS,
										'description' => 'Defaults to "=". IN / NOT IN take an array value; IS NULL / IS NOT NULL take no value.',
									),
									'value'    => array( 'description' => 'A string or number, or an array of them for IN / NOT IN.' ),
								),
								'required'   => array( 'column' ),
							),
						),
						'order_by' => array( 'type' => 'string', 'description' => 'Column to sort by.' ),
						'order'    => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'description' => 'Defaults to ASC.' ),
						'limit'    => array( 'type' => 'integer', 'description' => 'Rows to return, 1–200. Defaults to 50.' ),
						'offset'   => array( 'type' => 'integer', 'description' => 'Rows to skip. Defaults to 0.' ),
						'count'    => array( 'type' => 'boolean', 'description' => 'If true, return only the number of matching rows.' ),
					),
					'required'   => array( 'table' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
		);
	}

	/**
	 * Resolve a wp-content-relative path to an absolute one, refusing
	 * anything that escapes WP_CONTENT_DIR (blocks ../ traversal, symlink
	 * tricks caught via realpath, absolute-path overrides, etc.).
	 * Returns the safe absolute path, or null if it's not allowed.
	 */
	public static function resolve_sandboxed_path( $relative_path ) {
		$base = realpath( WP_CONTENT_DIR );
		if ( ! $base ) {
			return null;
		}

		$relative_path = str_replace( '\\', '/', (string) $relative_path );
		$relative_path = ltrim( $relative_path, '/' );

		if ( false !== strpos( $relative_path, "\0" ) ) {
			return null;
		}

		// An empty path resolves to wp-content itself - documented
		// behavior for tools like wp_list_files ("Empty for wp-content
		// root").
		if ( '' === $relative_path ) {
			return $base;
		}

		// Reject traversal outright via string parsing, since
		// intermediate directories in the path may not exist yet for
		// realpath() to check them.
		foreach ( explode( '/', $relative_path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		$candidate = $base . '/' . $relative_path;

		// Walk up from the candidate to the nearest ancestor that actually
		// exists on disk, and confirm THAT is really inside $base (still
		// catches symlink tricks on any part of the path that does
		// exist). Everything below it is already known-safe from the
		// traversal check above, even though it doesn't exist on disk yet.
		$check = $candidate;
		while ( ! file_exists( $check ) ) {
			$parent = dirname( $check );
			if ( $parent === $check ) {
				break; // Reached filesystem root without finding anything real.
			}
			$check = $parent;
		}

		$real_check = realpath( $check );
		if ( ! $real_check || 0 !== strpos( $real_check . '/', $base . '/' ) ) {
			return null;
		}

		return $candidate;
	}

	/**
	 * WindCodex Forge has no write/execute primitives at all, so unlike
	 * Forge Pro's require_dev_admin(), this doesn't gate on
	 * is_execution_allowed() - there's nothing here that a production site
	 * needs protecting from. Still requires an Administrator account,
	 * even when "Who can connect" allows Editors, since these tools can read
	 * file contents and raw database rows.
	 */
	private function require_dev_admin() {
		if ( ! $this->acting_user_can( 'manage_options' ) ) {
			return $this->error_result( 'This tool requires an Administrator account.' );
		}
		return null;
	}

	private function wp_list_files( $args ) {
		$gate = $this->require_dev_admin();
		if ( $gate ) {
			return $gate;
		}

		$path = $this->resolve_sandboxed_path( $args['path'] ?? '' );
		if ( ! $path || ! is_dir( $path ) ) {
			return $this->error_result( 'Path not found or outside wp-content.' );
		}

		$items = scandir( $path );
		$data  = array();
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$data[] = array(
				'name'   => $item,
				'is_dir' => is_dir( $path . '/' . $item ),
			);
		}

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_read_file( $args ) {
		$gate = $this->require_dev_admin();
		if ( $gate ) {
			return $gate;
		}

		$path = $this->resolve_sandboxed_path( $args['path'] ?? '' );
		if ( ! $path || ! is_file( $path ) ) {
			return $this->error_result( 'File not found or outside wp-content.' );
		}
		if ( filesize( $path ) > 500000 ) {
			return $this->error_result( 'File is too large to read (over 500KB).' );
		}

		return $this->text_result( (string) file_get_contents( $path ) ); // phpcs:ignore
	}

	private function real_blocks_with_indices( $post_content ) {
		$raw     = parse_blocks( $post_content );
		$indices = array();
		foreach ( $raw as $i => $block ) {
			if ( null !== $block['blockName'] ) {
				$indices[] = $i;
			}
		}
		return array( $raw, $indices );
	}

	private function wp_get_content_block( $args ) {
		$id       = (int) ( $args['id'] ?? 0 );
		$position = (int) ( $args['position'] ?? -1 );
		$post     = get_post( $id );

		if ( ! $post ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! $this->acting_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to read this post.' );
		}

		list( $raw, $indices ) = $this->real_blocks_with_indices( $post->post_content );

		if ( $position < 0 || $position >= count( $indices ) ) {
			return $this->error_result( "No block at position {$position}. This post has " . count( $indices ) . ' block(s).' );
		}

		$block = $raw[ $indices[ $position ] ];
		$inner_block_count = count( array_filter( $block['innerBlocks'], function ( $b ) { return null !== $b['blockName']; } ) );

		$data = array(
			'position'          => $position,
			'type'              => $block['blockName'],
			'attrs'             => $block['attrs'],
			'inner_html'        => $block['innerHTML'],
			'inner_block_count' => $inner_block_count,
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_list_reusable_blocks( $args ) {
		if ( ! $this->acting_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}

		$limit  = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$blocks = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
			)
		);

		$data = array_map(
			function ( $b ) {
				return array( 'id' => $b->ID, 'title' => get_the_title( $b ) );
			},
			$blocks
		);

		return $this->text_result( wp_json_encode( array_values( $data ), JSON_PRETTY_PRINT ) );
	}

	private function wp_remember( $args ) {
		$gate = $this->require_dev_admin();
		if ( $gate ) {
			return $gate;
		}
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$content = trim( (string) ( $args['content'] ?? '' ) );
		$key     = (string) ( $args['key'] ?? '' );

		if ( '' === $content ) {
			return $this->error_result( 'content is required.' );
		}

		$result = WCFORGE_Memory::remember( $key, $content );

		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		return $this->text_result( ( '' !== trim( $key ) ? "Remembered under key \"{$key}\"" : 'Remembered as a new note' ) . " (ID {$result})." );
	}

	private function wp_list_memories( $args ) {
		$limit  = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$search = trim( (string) ( $args['search'] ?? '' ) );

		$rows = '' !== $search ? WCFORGE_Memory::search( $search, $limit ) : WCFORGE_Memory::get_all( $limit );

		$data = array_map(
			function ( $row ) {
				return array(
					'id'         => (int) $row->id,
					'key'        => $row->memory_key,
					'content'    => $row->content,
					'updated_at' => $row->updated_at,
				);
			},
			$rows
		);

		return $this->text_result( wp_json_encode( array_values( $data ), JSON_PRETTY_PRINT ) );
	}

	private function wp_forget( $args ) {
		$gate = $this->require_dev_admin();
		if ( $gate ) {
			return $gate;
		}
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$id_or_key = trim( (string) ( $args['id_or_key'] ?? '' ) );
		if ( '' === $id_or_key ) {
			return $this->error_result( 'id_or_key is required.' );
		}

		$deleted = WCFORGE_Memory::forget( $id_or_key );

		if ( ! $deleted ) {
			return $this->error_result( "No memory found matching \"{$id_or_key}\"." );
		}

		return $this->text_result( "Forgot memory \"{$id_or_key}\"." );
	}

	/**
	 * Every table name that comes from the AI (never trust it directly in
	 * SQL) is checked against the real list of tables that actually exist
	 * before it's used in any query – this is what makes it safe to
	 * interpolate afterward.
	 */
	private function validate_table_exists( $table_name ) {
		global $wpdb;
		$real_tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore
		return in_array( $table_name, $real_tables, true );
	}

	private function wp_list_tables( $args ) {
		$gate = $this->require_dev_admin();
		if ( $gate ) {
			return $gate;
		}

		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore

		$data = array_map(
			function ( $row ) {
				return array(
					'table'     => $row['Name'] ?? null,
					'row_count' => isset( $row['Rows'] ) ? (int) $row['Rows'] : null,
					'size_bytes' => isset( $row['Data_length'], $row['Index_length'] ) ? (int) $row['Data_length'] + (int) $row['Index_length'] : null,
				);
			},
			(array) $rows
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_describe_table( $args ) {
		$gate = $this->require_dev_admin();
		if ( $gate ) {
			return $gate;
		}

		global $wpdb;
		$table = sanitize_text_field( $args['table'] ?? '' );

		if ( '' === $table ) {
			return $this->error_result( 'table is required.' );
		}
		if ( ! $this->validate_table_exists( $table ) ) {
			return $this->error_result( "Table \"{$table}\" does not exist on this database." );
		}

		// The name is whitelisted against SHOW TABLES above and bound as an identifier (%i). Live schema read: no cache.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );

		$data = array_map(
			function ( $col ) {
				return array(
					'column'  => $col['Field'] ?? null,
					'type'    => $col['Type'] ?? null,
					'nullable' => 'YES' === ( $col['Null'] ?? '' ),
					'key'     => $col['Key'] ?? null,
					'default' => $col['Default'] ?? null,
				);
			},
			(array) $columns
		);

		return $this->text_result( wp_json_encode( array( 'table' => $table, 'columns' => $data ), JSON_PRETTY_PRINT ) );
	}

	const MAX_SNAPSHOT_ROWS = 5000;

	/** Comparison operators wp_db_read accepts in a where condition. */
	const DB_READ_OPERATORS = array( '=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL' );

	const DB_READ_MAX_ROWS = 200;

	/** Column names of a table already confirmed by validate_table_exists(). */
	private function get_table_columns( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
	}

	/** The wpdb::prepare() placeholder matching a scalar filter value. */
	private function placeholder_for( $value ) {
		if ( is_int( $value ) || is_bool( $value ) ) {
			return '%d';
		}
		if ( is_float( $value ) ) {
			return '%f';
		}
		return '%s';
	}

	/**
	 * Columns/meta keys that hold login credentials. Selecting, filtering,
	 * or sorting on one is refused outright, as is filtering for one by
	 * value (e.g. meta_key = 'session_tokens'), so they can't be probed
	 * in ways the result-side redaction below wouldn't see.
	 */
	const CREDENTIAL_IDENTIFIER_PATTERN = '/user_pass|user_activation_key|session_tokens|_application_passwords/i';

	/** An option_name/meta_key matching this has its value redacted. */
	const SECRET_KEY_PATTERN = '/passw|secret|token|api_?key|apikey|private_?key|auth_?key|license_?key|_salt|credential/i';

	/** Password/key hashes (phpass, WP 6.8 bcrypt and fast-hash, bcrypt, argon2) anywhere in a value. */
	const HASH_VALUE_PATTERN = '/\$generic\$|\$[PH]\$[.\/0-9A-Za-z]{31}|\$wp\$2y\$|\$2[aby]\$\d{2}\$|\$argon2(?:i|d|id)\$/';

	/**
	 * Replace credential-looking values in wp_db_read results with
	 * "[redacted]": whole credential columns, the value half of an
	 * option_name/meta_key row whose key looks secret, and any value
	 * containing a password hash regardless of where it came from.
	 */
	private function redact_credentials( array $rows, &$redacted ) {
		foreach ( $rows as $i => $row ) {
			$secret_row = false;
			foreach ( array( 'option_name', 'meta_key' ) as $key_column ) {
				if ( isset( $row[ $key_column ] ) && preg_match( self::SECRET_KEY_PATTERN, (string) $row[ $key_column ] ) ) {
					$secret_row = true;
				}
			}

			foreach ( $row as $column => $value ) {
				if ( null === $value || in_array( $column, array( 'option_name', 'meta_key' ), true ) ) {
					continue;
				}
				if (
					preg_match( self::CREDENTIAL_IDENTIFIER_PATTERN, (string) $column )
					|| ( $secret_row && in_array( $column, array( 'option_value', 'meta_value' ), true ) )
					|| preg_match( self::HASH_VALUE_PATTERN, (string) $value )
				) {
					$rows[ $i ][ $column ] = '[redacted]';
					$redacted++;
				}
			}
		}
		return $rows;
	}

	private function wp_db_read( $args ) {
		$gate = $this->require_dev_admin();
		if ( $gate ) {
			return $gate;
		}

		global $wpdb;

		$credentials_error = 'This request references login credentials (password hashes, reset keys, session tokens, or application passwords), which wp_db_read never returns.';

		// No raw SQL is accepted. The statement is assembled here from
		// structured input: identifiers must match the live schema and are
		// bound with %i, operators come from an allowlist, and every value
		// goes through $wpdb->prepare().
		$table = (string) ( $args['table'] ?? '' );
		if ( '' === $table ) {
			return $this->error_result( 'table is required.' );
		}
		if ( ! $this->validate_table_exists( $table ) ) {
			return $this->error_result( "Table \"{$table}\" does not exist on this database." );
		}

		// A '%' in a table or column name would be read as a placeholder by prepare().
		if ( false !== strpos( $table, '%' ) ) {
			return $this->error_result( 'Tables whose names contain "%" are not supported.' );
		}

		$table_columns = $this->get_table_columns( $table );
		$is_column     = function ( $name ) use ( $table_columns ) {
			return is_string( $name ) && false === strpos( $name, '%' ) && in_array( $name, $table_columns, true );
		};
		$is_credential = function ( $name ) {
			return (bool) preg_match( self::CREDENTIAL_IDENTIFIER_PATTERN, (string) $name );
		};

		$count_only = ! empty( $args['count'] );

		// SELECT list: requested columns, or every column except credentials.
		$columns = isset( $args['columns'] ) ? (array) $args['columns'] : array();
		if ( empty( $columns ) ) {
			$columns = array_values( array_filter( $table_columns, function ( $c ) use ( $is_column, $is_credential ) {
				return $is_column( $c ) && ! $is_credential( $c );
			} ) );
			if ( empty( $columns ) ) {
				return $this->error_result( "Table \"{$table}\" has no readable columns." );
			}
		}
		foreach ( $columns as $column ) {
			if ( ! $is_column( $column ) ) {
				return $this->error_result( 'Unknown column "' . ( is_scalar( $column ) ? $column : '' ) . "\" in table \"{$table}\". Use wp_describe_table to list its columns." );
			}
			if ( $is_credential( $column ) ) {
				return $this->error_result( $credentials_error );
			}
		}

		// WHERE: each condition is column (validated) + operator (allowlisted) + bound value(s).
		$clauses = array();
		$params  = array();
		$where   = isset( $args['where'] ) ? (array) $args['where'] : array();
		foreach ( $where as $condition ) {
			$condition = (array) $condition;
			$column    = $condition['column'] ?? null;
			$operator  = strtoupper( trim( (string) ( $condition['operator'] ?? '=' ) ) );
			$value     = $condition['value'] ?? null;

			if ( ! $is_column( $column ) ) {
				return $this->error_result( 'Unknown where column "' . ( is_scalar( $column ) ? $column : '' ) . "\" in table \"{$table}\"." );
			}
			if ( $is_credential( $column ) ) {
				return $this->error_result( $credentials_error );
			}
			if ( ! in_array( $operator, self::DB_READ_OPERATORS, true ) ) {
				return $this->error_result( "Unsupported operator \"{$operator}\". Allowed: " . implode( ', ', self::DB_READ_OPERATORS ) . '.' );
			}

			// The column is bound as an identifier (%i), ahead of its value(s).
			$params[] = $column;

			if ( 'IS NULL' === $operator || 'IS NOT NULL' === $operator ) {
				$clauses[] = "%i {$operator}";
				continue;
			}

			if ( 'IN' === $operator || 'NOT IN' === $operator ) {
				$values = is_array( $value ) ? array_values( $value ) : array();
				if ( empty( $values ) || count( $values ) > 100 ) {
					return $this->error_result( "{$operator} needs an array of 1 to 100 values." );
				}
				$placeholders = array();
				foreach ( $values as $item ) {
					if ( ! is_scalar( $item ) ) {
						return $this->error_result( "{$operator} values must be strings or numbers." );
					}
					if ( $is_credential( $item ) ) {
						return $this->error_result( $credentials_error );
					}
					$placeholders[] = $this->placeholder_for( $item );
					$params[]       = $item;
				}
				$clauses[] = "%i {$operator} (" . implode( ', ', $placeholders ) . ')';
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				return $this->error_result( "The {$operator} operator needs a string or number value (use IS NULL to match NULL)." );
			}
			if ( $is_credential( $value ) ) {
				return $this->error_result( $credentials_error );
			}
			$clauses[] = "%i {$operator} " . $this->placeholder_for( $value );
			$params[]  = $value;
		}

		$limit  = max( 1, min( self::DB_READ_MAX_ROWS, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		// SELECT/FROM identifiers come first in the statement, so their
		// values go in front of the WHERE parameters collected above.
		if ( $count_only ) {
			$sql    = 'SELECT COUNT(*) AS row_count FROM %i';
			$params = array_merge( array( $table ), $params );
		} else {
			$sql    = 'SELECT ' . implode( ', ', array_fill( 0, count( $columns ), '%i' ) ) . ' FROM %i';
			$params = array_merge( array_values( $columns ), array( $table ), $params );
		}
		if ( $clauses ) {
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
		}

		if ( ! $count_only && ! empty( $args['order_by'] ) ) {
			if ( ! $is_column( $args['order_by'] ) ) {
				return $this->error_result( 'Unknown order_by column "' . ( is_scalar( $args['order_by'] ) ? $args['order_by'] : '' ) . "\" in table \"{$table}\"." );
			}
			if ( $is_credential( $args['order_by'] ) ) {
				return $this->error_result( $credentials_error );
			}
			$direction = 'DESC' === strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) ? 'DESC' : 'ASC';
			$sql      .= ' ORDER BY %i ' . $direction;
			$params[]  = $args['order_by'];
		}

		if ( $count_only ) {
			// COUNT(*) is one row; the LIMIT just keeps a placeholder in every statement.
			$sql     .= ' LIMIT %d';
			$params[] = 1;
		} else {
			// One extra row tells us whether the result was truncated.
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit + 1;
			$params[] = $offset;
		}

		$wpdb->last_error = ''; // Clear any stale error from a prior query on this same request.
		// Live diagnostic read, so no caching. $sql is built only from literals and
		// placeholders: every identifier is %i (validated against the live schema),
		// operators come from DB_READ_OPERATORS, ORDER BY direction is ASC/DESC, and
		// every value is bound here. The scanner can't follow a statement assembled
		// across several lines, hence NotPrepared/UnescapedDBParameter.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		// $wpdb->get_results() returns an empty array on a failed query,
		// not null - checking last_error directly is what actually
		// distinguishes "query failed" from "query succeeded, 0 rows".
		if ( ! empty( $wpdb->last_error ) ) {
			return $this->error_result( 'Database error: ' . $wpdb->last_error );
		}

		if ( $count_only ) {
			return $this->text_result( wp_json_encode( array( 'table' => $table, 'row_count' => (int) ( $results[0]['row_count'] ?? 0 ) ), JSON_PRETTY_PRINT ) );
		}

		$truncated = false;
		if ( is_array( $results ) && count( $results ) > $limit ) {
			$results   = array_slice( $results, 0, $limit );
			$truncated = true;
		}

		$redacted = 0;
		$results  = is_array( $results ) ? $this->redact_credentials( $results, $redacted ) : array();

		$response = array(
			'row_count' => count( $results ),
			'truncated' => $truncated,
			'rows'      => $results,
		);
		if ( $redacted ) {
			$response['redacted_values'] = $redacted;
			$response['note']            = 'Values that look like passwords, secrets, API keys, or tokens were replaced with "[redacted]".';
		}

		return $this->text_result( wp_json_encode( $response, JSON_PRETTY_PRINT ) );
	}

	private function text_result( $text ) {
		return array(
			'content' => array(
				array( 'type' => 'text', 'text' => $text ),
			),
			'isError' => false,
		);
	}

	private function error_result( $message ) {
		return array(
			'content' => array(
				array( 'type' => 'text', 'text' => $message ),
			),
			'isError' => true,
		);
	}
}
