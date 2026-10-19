<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings > WindCodex Forge admin screen.
 *
 * A tabbed settings UI (Connection / Tools / General / Activity) built on
 * the shared "elegant" design system – the same tokens, badges, toggle
 * spec, and text-only tab bar as WindCodex Ops, so the two plugins read as
 * one family – while still using native WP form controls underneath for
 * accessibility and familiarity.
 */
class WCFORGE_Admin {

	private static $instance = null;

	/** @var WCFORGE_Settings */
	private $settings;

	const TABS = array(
		'connection' => array( 'label' => 'Connection' ),
		'tools'      => array( 'label' => 'Tools' ),
		'general'    => array( 'label' => 'General' ),
		'log'        => array( 'label' => 'Activity' ),
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = WCFORGE_Settings::instance();

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_post_wcforge_save_general', array( $this, 'handle_save_general' ) );
		add_action( 'admin_post_wcforge_save_tools', array( $this, 'handle_save_tools' ) );
		add_action( 'admin_post_wcforge_onboarding', array( $this, 'handle_onboarding' ) );
		add_action( 'admin_post_wcforge_revoke_client', array( $this, 'handle_revoke_client' ) );
		add_action( 'admin_post_wcforge_rotate_oauth_secret', array( $this, 'handle_rotate_oauth_secret' ) );
		add_action( 'admin_post_wcforge_revoke_oauth', array( $this, 'handle_revoke_oauth' ) );
		add_action( 'admin_post_wcforge_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_filter( 'plugin_action_links_' . WCFORGE_PLUGIN_BASE, array( $this, 'plugin_action_links' ) );
	}

	public function add_settings_page() {
		add_options_page(
			'WindCodex Forge',
			'WindCodex Forge',
			'manage_options',
			'windcodex-forge',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add "Settings" and "Docs" links to the plugin's row on the Plugins screen.
	 */
	public function plugin_action_links( array $links ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=windcodex-forge' ) ) . '">' . esc_html__( 'Settings', 'windcodex-forge' ) . '</a>';
		$docs_link     = '<a href="' . esc_url( 'https://docs.windcodex.com/docs/forge' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Docs', 'windcodex-forge' ) . '</a>';
		array_unshift( $links, $settings_link, $docs_link );
		return $links;
	}

	public function maybe_show_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: these query args only pick which already-saved notice text to display, they never change state (the actual mutations they follow were already nonce-checked in their own admin-post handlers).
		if ( ! isset( $_GET['page'] ) || 'windcodex-forge' !== $_GET['page'] ) {
			return;
		}
		if ( isset( $_GET['wcforge_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible wcforge-notice"><p>' . esc_html__( 'Settings saved!', 'windcodex-forge' ) . '</p></div>';
		}
		if ( isset( $_GET['wcforge_oauth_rotated'] ) ) {
			echo '<div class="notice notice-success is-dismissible wcforge-notice"><p>' . esc_html__( 'OAuth client secret regenerated. Update it wherever you pasted the old one.', 'windcodex-forge' ) . '</p></div>';
		}
		if ( isset( $_GET['wcforge_oauth_revoked'] ) ) {
			echo '<div class="notice notice-success is-dismissible wcforge-notice"><p>' . esc_html__( 'All OAuth access has been revoked. Anything connected via OAuth will need to reauthorize.', 'windcodex-forge' ) . '</p></div>';
		}
		if ( isset( $_GET['wcforge_client_revoked'] ) ) {
			echo '<div class="notice notice-success is-dismissible wcforge-notice"><p>' . esc_html__( 'Access revoked for that app. It will need to reauthorize to reconnect.', 'windcodex-forge' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: just picks which settings tab to render, no state change.
		$tab = sanitize_key( $_GET['tab'] ?? 'connection' );
		return array_key_exists( $tab, self::TABS ) ? $tab : 'connection';
	}

	private function tab_url( $tab ) {
		return add_query_arg(
			array( 'page' => 'windcodex-forge', 'tab' => $tab ),
			admin_url( 'options-general.php' )
		);
	}

	public function handle_save_general() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcforge_save_general' );

		$this->settings->update(
			array(
				'enabled'                    => ! empty( $_POST['enabled'] ),
				'read_only'                  => ! empty( $_POST['read_only'] ),
				'connect_capability'         => sanitize_key( wp_unslash( $_POST['connect_capability'] ?? 'manage_options' ) ),
				'max_results_limit'          => max( 1, absint( $_POST['max_results_limit'] ?? 50 ) ),
				'requests_per_minute_limit'  => max( 10, min( 600, absint( $_POST['requests_per_minute_limit'] ?? 120 ) ) ),
				'allow_dynamic_registration' => ! empty( $_POST['allow_dynamic_registration'] ),
				'delete_data_on_uninstall'   => ! empty( $_POST['delete_data_on_uninstall'] ),
				'log_ai_activity'            => ! empty( $_POST['log_ai_activity'] ),
			)
		);

		wp_safe_redirect( add_query_arg( 'wcforge_saved', '1', $this->tab_url( 'general' ) ) );
		exit;
	}

	public function handle_save_tools() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcforge_save_tools' );

		$tool_groups = array(
			'wp_site' => true, // Always-safe "look around" tool; no longer a separate toggle in the UI, kept on.
		);

		$primitive_enabled = array();
		foreach ( WCFORGE_Settings::bundle_metadata() as $bundle_key => $bundle ) {
			$on = ! empty( $_POST[ 'bundle_' . $bundle_key ] );
			foreach ( $bundle['primitives'] as $primitive ) {
				$primitive_enabled[ $primitive ] = $on;
			}
		}

		$this->settings->update(
			array(
				'tool_groups'       => $tool_groups,
				'primitive_enabled' => $primitive_enabled,
			)
		);

		wp_safe_redirect( add_query_arg( 'wcforge_saved', '1', $this->tab_url( 'tools' ) ) );
		exit;
	}

	public function handle_onboarding() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcforge_onboarding' );

		$this->settings->update( array( 'onboarding_completed' => true ) );

		wp_safe_redirect( $this->tab_url( 'connection' ) );
		exit;
	}

	public function handle_revoke_client() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcforge_revoke_client' );

		$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		if ( $client_id ) {
			WCFORGE_OAuth::revoke_client_tokens( $client_id );
		}

		wp_safe_redirect( add_query_arg( 'wcforge_client_revoked', '1', $this->tab_url( 'connection' ) ) );
		exit;
	}

	private function render_onboarding() {
		$this->print_styles();
		$endpoint = WCFORGE_Server::instance()->get_endpoint_url();
		?>
		<div class="wrap wcforge-wrap">
			<hr class="wp-header-end" />
			<div class="wcforge-onboarding">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wcforge-onboarding-form" class="wcforge-onboarding-card">
					<?php wp_nonce_field( 'wcforge_onboarding' ); ?>
					<input type="hidden" name="action" value="wcforge_onboarding" />

					<div class="wcforge-onboarding-progress">
						<span class="wcforge-onboarding-progress-dot" data-progress-dot="1"></span>
						<span class="wcforge-onboarding-progress-dot" data-progress-dot="2"></span>
					</div>

					<div class="wcforge-onboarding-step is-active" data-step="1">
						<?php WCFORGE_Icons::render( 'compass', 'wcforge-onboarding-icon' ); ?>
						<h1>WindCodex Forge</h1>
						<p>This gives a connected AI safe, read-only access to inspect this site: installed plugins, theme, hooks, files, database structure and data, Gutenberg content blocks, and cross-conversation memory.</p>
						<p><strong>No write or code-execution capability of any kind</strong> - that's not a setting to be careful with, it simply isn't present in this plugin. Safe to run on a live site.</p>
						<div class="wcforge-onboarding-actions-row">
							<button type="button" class="button button-primary wcforge-btn-primary" data-onboarding-next>Continue</button>
						</div>
					</div>

					<div class="wcforge-onboarding-step" data-step="2">
						<h1>Connect an AI platform</h1>
						<p>In your AI platform's connector settings, add a new custom connector with this URL.</p>
						<?php $this->render_copy_field( 'wcforge-onboarding-endpoint', $endpoint ); ?>
						<div class="wcforge-onboarding-actions-row">
							<button type="submit" class="button button-primary wcforge-btn-primary">Finish</button>
						</div>
						<button type="button" class="wcforge-onboarding-back" data-onboarding-back>Back</button>
					</div>
				</form>
			</div>
		</div>
		<?php
		wp_register_script( 'wcforge-admin-onboarding', false, array(), WCFORGE_VERSION, true );
		wp_enqueue_script( 'wcforge-admin-onboarding' );
		wp_add_inline_script(
			'wcforge-admin-onboarding',
			<<<'JS'
			document.addEventListener('DOMContentLoaded', function () {
				var steps = document.querySelectorAll('.wcforge-onboarding-step');
				var progressDots = document.querySelectorAll('[data-progress-dot]');
				function showStep(n) {
					steps.forEach(function (s) {
						s.classList.toggle('is-active', parseInt(s.getAttribute('data-step'), 10) === n);
					});
					progressDots.forEach(function (dot) {
						var step = parseInt(dot.getAttribute('data-progress-dot'), 10);
						dot.classList.toggle('is-active', step === n);
						dot.classList.toggle('is-done', step < n);
					});
				}
				showStep(1);
				document.querySelectorAll('[data-onboarding-next]').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var current = document.querySelector('.wcforge-onboarding-step.is-active');
						showStep(parseInt(current.getAttribute('data-step'), 10) + 1);
					});
				});
				document.querySelectorAll('[data-onboarding-back]').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var current = document.querySelector('.wcforge-onboarding-step.is-active');
						showStep(parseInt(current.getAttribute('data-step'), 10) - 1);
					});
				});
				document.querySelectorAll('.wcforge-copy-btn').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var input = document.getElementById(btn.getAttribute('data-copy-target'));
						if (!input) { return; }
						input.select();
						navigator.clipboard && navigator.clipboard.writeText(input.value);
					});
				});
			});
			JS
		);
	}

	public function handle_rotate_oauth_secret() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcforge_rotate_oauth_secret' );

		WCFORGE_OAuth::regenerate_static_client_secret();

		wp_safe_redirect( add_query_arg( 'wcforge_oauth_rotated', '1', $this->tab_url( 'connection' ) ) );
		exit;
	}

	public function handle_revoke_oauth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcforge_revoke_oauth' );

		WCFORGE_OAuth::revoke_all_tokens();

		wp_safe_redirect( add_query_arg( 'wcforge_oauth_revoked', '1', $this->tab_url( 'connection' ) ) );
		exit;
	}

	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wcforge_clear_log' );

		WCFORGE_Activity_Log::clear();

		wp_safe_redirect( $this->tab_url( 'log' ) );
		exit;
	}

	/**
	 * One shared stylesheet for the whole settings screen, printed once
	 * regardless of which tab is active. Same token values as WindCodex
	 * Ops, so the two plugins read as one system – only copy and icons
	 * differ.
	 */
	private function print_styles() {
		ob_start();
		?>
			.wcforge-wrap {
				--wcforge-text: #14181F;
				--wcforge-text-secondary: #6B6B63;
				--wcforge-text-muted: #9C9990;
				--wcforge-accent: #B5541C;
				--wcforge-accent-dark: #8A3F14;
				--wcforge-on-accent: #FFFFFF;
				--wcforge-risk-low: #27500A;
				--wcforge-risk-low-soft: #EAF3DE;
				--wcforge-risk-medium: #633806;
				--wcforge-risk-medium-soft: #FAEEDA;
				--wcforge-risk-high: #9B2226;
				--wcforge-risk-high-soft: #FAE7E7;
				--wcforge-success: #27500A;
				--wcforge-success-soft: #EAF3DE;
				--wcforge-danger: #9B2226;
				--wcforge-danger-soft: #FAE7E7;
				--wcforge-warning: #633806;
				--wcforge-warning-soft: #FAEEDA;
				--wcforge-border: #E3E0D8;
				--wcforge-surface: #FFFFFF;
				--wcforge-surface-muted: #F5F4F0;
				--wcforge-radius: 14px;
				--wcforge-radius-sm: 10px;
				--wcforge-mono: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
				max-width: none;
				margin-right: 20px;
				border-radius: var(--wcforge-radius);
				padding: 0 0 24px;
				color: var(--wcforge-text);
				font-size: 13px;
			}
			.wcforge-wrap, .wcforge-wrap * { box-sizing: border-box; }

			/* Sits above .wcforge-wrap so any notice relocated here – ours
			   or another plugin's – renders at the very top of the page,
			   in its own default WordPress styling, instead of wherever
			   it happened to get injected. */

			.wcforge-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 16px;
				flex-wrap: wrap;
				padding: 22px 26px;
				margin: 20px 0 24px;
				background: var(--wcforge-surface);
				border: 0.5px solid var(--wcforge-border);
				border-radius: var(--wcforge-radius);
				box-shadow: 0 1px 3px rgba(20, 24, 31, 0.05);
			}
			.wcforge-header-left { display: flex; align-items: center; gap: 16px; }
			.wcforge-header-icon {
				width: 44px; height: 44px; padding: 10px; box-sizing: border-box; flex: none;
				background: rgba(181, 84, 28, 0.08); border: none;
				border-radius: 50%; color: var(--wcforge-accent);
			}
			.wcforge-header-text { display: flex; flex-direction: column; gap: 6px; }
			.wcforge-header-text h1 {
				color: var(--wcforge-text); margin: 0; font-size: 19px; font-weight: 600; letter-spacing: -0.01em;
				padding: 0; line-height: 1.25;
			}
			.wcforge-header-rule { width: 28px; height: 2px; background: var(--wcforge-accent); border-radius: 1px; }
			.wcforge-header-text p { color: var(--wcforge-text-secondary); margin: 0; font-size: 13px; font-weight: 400; line-height: 1.5; }
			.wcforge-header-right { display: flex; align-items: center; gap: 12px; flex: none; }

			.wcforge-help-wrap { position: relative; }
			.wcforge-help-btn {
				display: inline-flex; align-items: center; gap: 5px;
				padding: 7px 13px;
				border: 1px solid var(--wcforge-accent); border-radius: var(--wcforge-radius-sm);
				background: var(--wcforge-surface); color: var(--wcforge-accent);
				font-size: 13px; font-weight: 600; font-family: inherit; line-height: 1;
				cursor: pointer;
				transition: background .15s ease;
			}
			.wcforge-help-btn .dashicons { font-size: 18px; width: 18px; height: 18px; }
			.wcforge-help-btn:hover,
			.wcforge-help-btn.is-open { background: rgba(181, 84, 28, 0.08); }
			.wcforge-help-btn:focus-visible { outline: 2px solid var(--wcforge-accent); outline-offset: 2px; }
			.wcforge-help-dropdown {
				position: absolute; top: calc(100% + 8px); right: 0; z-index: 9999;
				min-width: 200px; overflow: hidden;
				background: var(--wcforge-surface);
				border: 0.5px solid var(--wcforge-border); border-radius: var(--wcforge-radius-sm);
				box-shadow: 0 4px 20px rgba(20, 24, 31, 0.12);
			}
			.wcforge-help-dropdown[hidden] { display: none; }
			.wcforge-help-item {
				display: flex; align-items: center; gap: 10px;
				padding: 10px 14px;
				font-size: 13px; font-weight: 500; color: var(--wcforge-text);
				text-decoration: none;
				border-bottom: 0.5px solid var(--wcforge-border);
				transition: background .12s ease, color .12s ease;
			}
			.wcforge-help-item:last-child { border-bottom: none; }
			.wcforge-help-item:hover,
			.wcforge-help-item:focus { background: var(--wcforge-surface-muted); color: var(--wcforge-accent); box-shadow: none; }
			.wcforge-help-item-icon { font-size: 16px; width: 16px; height: 16px; flex-shrink: 0; color: var(--wcforge-text-muted); }
			.wcforge-help-item:hover .wcforge-help-item-icon,
			.wcforge-help-item:focus .wcforge-help-item-icon { color: var(--wcforge-accent); }

			.wcforge-tabs {
				display: flex; gap: 12px;
				border-bottom: 0.5px solid var(--wcforge-border);
				margin: 0 0 28px; padding: 0 4px;
			}
			.wcforge-tab {
				position: relative;
				display: inline-flex; align-items: center;
				padding: 15px 14px 17px;
				font-size: 13.5px; font-weight: 400; color: var(--wcforge-text-secondary);
				letter-spacing: -0.005em;
				text-decoration: none;
				transition: color .15s ease;
			}
			.wcforge-tab:hover { color: var(--wcforge-text); }
			.wcforge-tab.is-active { color: var(--wcforge-text); font-weight: 600; }
			.wcforge-tab.is-active::after {
				content: ""; position: absolute; left: 0; right: 0; bottom: -0.5px;
				height: 2.5px; background: var(--wcforge-accent); border-radius: 2px;
			}
			.wcforge-tab:focus { outline: none; box-shadow: none; }
			.wcforge-tab:focus-visible {
				outline: 2px solid var(--wcforge-accent); outline-offset: 3px; border-radius: 4px;
				box-shadow: none;
			}

			.wcforge-lede { color: var(--wcforge-text-secondary); font-size: 13px; margin: 0 0 20px; }
			.wcforge-undo-footnote {
				display: flex; align-items: center; gap: 8px;
				font-size: 12.5px; color: var(--wcforge-text-secondary);
				background: var(--wcforge-surface-muted); border: 0.5px solid var(--wcforge-border); border-radius: var(--wcforge-radius-sm);
				padding: 12px 16px;
			}
			.wcforge-undo-footnote .dashicons { color: var(--wcforge-risk-low); font-size: 16px; width: 16px; height: 16px; flex: none; }

			.wcforge-stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 24px; }
			.wcforge-stat-card {
				background: var(--wcforge-surface); border: 0.5px solid var(--wcforge-border); border-radius: var(--wcforge-radius-sm);
				padding: 1rem;
			}
			.wcforge-stat-card-label { font-size: 13px; color: var(--wcforge-text-secondary); font-weight: 400; margin-bottom: 8px; }
			.wcforge-stat-card-value { font-size: 28px; font-weight: 500; color: var(--wcforge-text); display: flex; align-items: center; gap: 8px; line-height: 1; }
			.wcforge-stat-card-value .dashicons { font-size: 18px; width: 18px; height: 18px; }
			.wcforge-stat-card-value .dashicons.is-success { color: var(--wcforge-risk-low); }
			.wcforge-stat-card-sub { font-size: 12px; color: var(--wcforge-text-muted); margin-top: 6px; }
			.wcforge-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
			.wcforge-dot.is-on { background: var(--wcforge-success); }
			.wcforge-dot.is-off { background: var(--wcforge-danger); }

			.wcforge-card {
				background: var(--wcforge-surface); border: 0.5px solid var(--wcforge-border); border-radius: var(--wcforge-radius);
				padding: 20px 24px; margin-bottom: 20px;
			}
			.wcforge-card-danger { border-color: var(--wcforge-danger); background: var(--wcforge-danger-soft); }
			.wcforge-section-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: var(--wcforge-text); margin: 0 0 4px; }
			.wcforge-section-title.is-danger { color: var(--wcforge-danger); }
			.wcforge-section-title .dashicons { font-size: 16px; width: 16px; height: 16px; color: var(--wcforge-text-secondary); }
			.wcforge-section-desc { font-size: 13px; color: var(--wcforge-text-secondary); margin: 0 0 16px; line-height: 1.6; }

			.wcforge-field-row { padding: 14px 0; border-bottom: 0.5px solid var(--wcforge-border); }
			.wcforge-field-row:last-child { border-bottom: none; padding-bottom: 0; }
			.wcforge-field-row:first-child { padding-top: 0; }
			.wcforge-field-label { font-weight: 500; font-size: 13px; color: var(--wcforge-text); margin-bottom: 6px; display: block; }
			.wcforge-field-desc { font-size: 12.5px; color: var(--wcforge-text-secondary); margin: 6px 0 0; line-height: 1.6; }
			.wcforge-field-desc code { background: var(--wcforge-surface-muted); padding: 1px 5px; border-radius: 6px; font-size: 11.5px; }

			.wcforge-input-copy { width: 100%; max-width: 460px; font-family: var(--wcforge-mono); font-size: 12.5px; background: var(--wcforge-surface-muted); border: 0.5px solid var(--wcforge-border); border-radius: var(--wcforge-radius-sm); padding: 8px 10px; }
			.wcforge-input-text { font-size: 13px; color: var(--wcforge-text); background: var(--wcforge-surface); border: 0.5px solid var(--wcforge-border); border-radius: var(--wcforge-radius-sm); padding: 8px 10px; }
			.wcforge-input-text:focus { outline: none; border-color: var(--wcforge-accent); box-shadow: 0 0 0 3px rgba(181,84,28,.15); }
			.wcforge-wrap select { font-size: 13px; color: var(--wcforge-text); background: var(--wcforge-surface); border: 0.5px solid var(--wcforge-border); border-radius: var(--wcforge-radius-sm); padding: 6px 8px; }
			.wcforge-discovery-box { background: var(--wcforge-surface-muted); border: 0.5px solid var(--wcforge-border); border-radius: var(--wcforge-radius-sm); padding: 10px 12px; display: flex; flex-direction: column; gap: 4px; max-width: 560px; }
			.wcforge-discovery-box code { background: transparent; padding: 0; font-size: 12px; color: var(--wcforge-text); }

			.wcforge-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 500; }
			.wcforge-badge.is-success { background: var(--wcforge-success-soft); color: var(--wcforge-success); }
			.wcforge-badge.is-danger { background: var(--wcforge-danger-soft); color: var(--wcforge-danger); }
			.wcforge-badge.is-neutral { background: var(--wcforge-surface-muted); color: var(--wcforge-text-secondary); }

			.wcforge-tool-list { border: 0.5px solid var(--wcforge-border); border-radius: var(--wcforge-radius); overflow: hidden; margin: 4px 0 26px; background: var(--wcforge-surface); }
			.wcforge-tool-row { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 0.5px solid var(--wcforge-border); }
			.wcforge-tool-row:last-child { border-bottom: none; }
			.wcforge-tool-row.is-disabled { background: var(--wcforge-surface-muted); opacity: .6; }
			.wcforge-tool-row-icon { color: var(--wcforge-text-secondary); font-size: 18px; width: 18px; height: 18px; flex: none; margin-top: 1px; }
			.wcforge-tool-row-main { flex: 1; min-width: 0; }
			.wcforge-tool-row-title-line { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 2px; }
			.wcforge-tool-row-title { font-weight: 500; font-size: 14px; color: var(--wcforge-text); }
			.wcforge-tool-row-desc { color: var(--wcforge-text-secondary); font-size: 13px; line-height: 1.5; margin: 0; }

			.wcforge-risk-badge {
				display: inline-flex; align-items: center; font-size: 12px; font-weight: 500;
				padding: 2px 9px; border-radius: 999px;
			}
			.wcforge-risk-badge.is-low { background: var(--wcforge-risk-low-soft); color: var(--wcforge-risk-low); }
			.wcforge-risk-badge.is-medium { background: var(--wcforge-risk-medium-soft); color: var(--wcforge-risk-medium); }
			.wcforge-risk-badge.is-high { background: var(--wcforge-risk-high-soft); color: var(--wcforge-risk-high); }

			.wcforge-revoke-link {
				background: none; border: none; padding: 4px 0; margin-top: 1px;
				color: var(--wcforge-danger); font-size: 12.5px; font-weight: 500; cursor: pointer;
			}
			.wcforge-revoke-link:hover { text-decoration: underline; }
			.wcforge-revoke-link:focus { outline: none; box-shadow: none; }
			.wcforge-revoke-link:focus-visible { outline: 2px solid var(--wcforge-accent); outline-offset: 2px; border-radius: 2px; }

			a.button.wcforge-btn-secondary { display: inline-flex; align-items: center; }

			.wcforge-switch { position: relative; display: inline-block; width: 36px; height: 20px; flex: none; }
			.wcforge-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
			.wcforge-switch .wcforge-slider { position: absolute; inset: 0; background: #d8d5cc; border-radius: 999px; cursor: pointer; transition: background .15s ease; }
			.wcforge-switch .wcforge-slider::before { content: ""; position: absolute; height: 16px; width: 16px; left: 2px; top: 2px; background: var(--wcforge-on-accent); border-radius: 50%; transition: transform .15s ease; }
			.wcforge-switch input:checked + .wcforge-slider { background: var(--wcforge-accent); }
			.wcforge-switch input:checked + .wcforge-slider::before { transform: translateX(16px); }
			.wcforge-switch input:focus-visible + .wcforge-slider { outline: 2px solid var(--wcforge-accent); outline-offset: 2px; }

			.wcforge-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; }

			.wcforge-btn-primary.button,
			.wcforge-btn-secondary.button,
			.wcforge-btn-danger-outline.button {
				border-radius: var(--wcforge-radius-sm);
				padding: 6px 16px;
				height: auto;
				line-height: 1.5;
				font-weight: 500;
				font-size: 13px;
			}
			.wcforge-btn-primary.button { background: var(--wcforge-accent); border: 0.5px solid var(--wcforge-accent); color: var(--wcforge-on-accent); box-shadow: none; }
			.wcforge-btn-primary.button:hover { background: var(--wcforge-accent-dark); color: var(--wcforge-on-accent); }
			.wcforge-btn-primary.button:focus { box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--wcforge-accent); border-color: var(--wcforge-accent); }
			.wcforge-btn-secondary.button { background: var(--wcforge-surface); border: 0.5px solid var(--wcforge-border); color: var(--wcforge-text); box-shadow: none; transition: background .15s ease; }
			.wcforge-btn-secondary.button:hover { background: var(--wcforge-surface-muted); color: var(--wcforge-text); }
			.wcforge-btn-secondary.button:focus { box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--wcforge-border); }
			.wcforge-btn-secondary.button:disabled { opacity: .5; cursor: not-allowed; }
			.wcforge-btn-danger-outline.button { background: var(--wcforge-surface); border: 0.5px solid var(--wcforge-danger); color: var(--wcforge-danger); box-shadow: none; transition: background .15s ease; }
			.wcforge-btn-danger-outline.button:hover { background: var(--wcforge-danger-soft); color: var(--wcforge-danger); }
			.wcforge-btn-danger-outline.button:focus { box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--wcforge-danger-soft); }

			.wcforge-copy-row { display: flex; gap: 8px; align-items: stretch; max-width: 460px; }
			.wcforge-copy-row .wcforge-input-copy { max-width: none; flex: 1; margin: 0; }
			.wcforge-copy-btn {
				flex: none; display: flex; align-items: center; justify-content: center;
				width: 34px; border-radius: var(--wcforge-radius-sm); border: 0.5px solid var(--wcforge-border);
				background: var(--wcforge-surface); cursor: pointer; color: var(--wcforge-text-secondary);
				transition: background .15s ease, color .15s ease;
			}
			.wcforge-copy-btn:hover { background: var(--wcforge-surface-muted); color: var(--wcforge-accent); }
			.wcforge-copy-btn .dashicons { font-size: 15px; width: 15px; height: 15px; }
			.wcforge-copy-btn.is-copied { background: var(--wcforge-success-soft); color: var(--wcforge-success); }
			.wcforge-copy-icon-copied { display: none; }
			.wcforge-copy-btn.is-copied .wcforge-copy-icon-default { display: none; }
			.wcforge-copy-btn.is-copied .wcforge-copy-icon-copied { display: inline-block; }

			.wcforge-empty-state { text-align: center; padding: 40px 20px; color: var(--wcforge-text-secondary); }
			.wcforge-empty-state .dashicons { font-size: 32px; width: 32px; height: 32px; color: var(--wcforge-text-muted); margin-bottom: 8px; display: block; margin-left: auto; margin-right: auto; }
			.wcforge-empty-state p { margin: 0; font-size: 13px; }

			.wcforge-onboarding {
				display: flex; align-items: center; justify-content: center;
				min-height: 70vh; padding: 40px 20px;
			}
			.wcforge-onboarding-card {
				background: var(--wcforge-surface); border: 0.5px solid var(--wcforge-border);
				border-radius: var(--wcforge-radius); padding: 40px 44px;
				width: 100%; max-width: 560px; margin: 0 auto;
			}

			.wcforge-onboarding-progress { display: flex; align-items: center; justify-content: center; gap: 6px; margin: 0 0 28px; }
			.wcforge-onboarding-progress-dot { width: 6px; height: 6px; border-radius: 999px; background: var(--wcforge-border); transition: background .15s ease, width .15s ease; }
			.wcforge-onboarding-progress-dot.is-done { background: var(--wcforge-accent); opacity: .45; }
			.wcforge-onboarding-progress-dot.is-active { background: var(--wcforge-accent); width: 18px; }

			.wcforge-onboarding-step { display: none; text-align: center; }
			.wcforge-onboarding-step.is-active { display: block; }
			.wcforge-onboarding-step .wcforge-copy-row { margin: 16px auto; }
			.wcforge-onboarding-icon { display: block; margin: 0 auto 14px; font-size: 32px; width: 32px; height: 32px; color: var(--wcforge-accent); text-align: center; }
			.wcforge-onboarding-icon.is-success { color: var(--wcforge-risk-low); }
			.wcforge-onboarding-icon.is-danger { color: var(--wcforge-risk-high); }
			.wcforge-onboarding-step > h1 { font-size: 20px; font-weight: 500; margin: 0 0 8px; color: var(--wcforge-text); text-align: center; }
			.wcforge-onboarding-step > p { color: var(--wcforge-text-secondary); font-size: 14px; line-height: 1.6; margin: 0 0 8px; text-align: center; }
			.wcforge-onboarding-actions-row { display: flex; gap: 10px; justify-content: center; margin-top: 24px; }
			.wcforge-onboarding-actions-row .button { margin: 0; flex: 1; text-align: center; }
			.wcforge-onboarding-back {
				display: block; margin: 16px auto 0; background: none; border: none;
				color: var(--wcforge-text-secondary); font-size: 12.5px; cursor: pointer; text-decoration: underline;
			}

		<?php
		$css = ob_get_clean();
		wp_register_style( 'wcforge-admin', false, array(), WCFORGE_VERSION );
		wp_enqueue_style( 'wcforge-admin' );
		wp_add_inline_style( 'wcforge-admin', $css );

		ob_start();
		?>
		document.addEventListener('DOMContentLoaded', function () {
			document.querySelectorAll('.wcforge-copy-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var input = document.getElementById(btn.getAttribute('data-copy-target'));
					if (!input) { return; }
					input.select();
					navigator.clipboard && navigator.clipboard.writeText(input.value).then(function () {
						btn.classList.add('is-copied');
						setTimeout(function () {
							btn.classList.remove('is-copied');
						}, 1500);
					});
				});
			});

			var helpBtn = document.getElementById('wcforge-help-btn');
			var helpDropdown = document.getElementById('wcforge-help-dropdown');
			if (helpBtn && helpDropdown) {
				var setHelpOpen = function (open) {
					helpDropdown.hidden = !open;
					helpBtn.classList.toggle('is-open', open);
					helpBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
				};
				helpBtn.addEventListener('click', function (e) {
					e.stopPropagation();
					setHelpOpen(helpDropdown.hidden);
				});
				helpDropdown.addEventListener('click', function (e) {
					e.stopPropagation();
				});
				document.addEventListener('click', function () {
					setHelpOpen(false);
				});
				document.addEventListener('keydown', function (e) {
					if ('Escape' === e.key && !helpDropdown.hidden) {
						setHelpOpen(false);
						helpBtn.focus();
					}
				});
			}
		});
		<?php
		$js = ob_get_clean();
		wp_register_script( 'wcforge-admin-shared', false, array(), WCFORGE_VERSION, true );
		wp_enqueue_script( 'wcforge-admin-shared' );
		wp_add_inline_script( 'wcforge-admin-shared', $js );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings->get_all();

		if ( empty( $settings['onboarding_completed'] ) && ! isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: just decides whether to show onboarding vs. the tab, no state change.
			$this->render_onboarding();
			return;
		}

		$tab = $this->current_tab();
		$this->print_styles();
		?>
		<div class="wrap wcforge-wrap">
			<hr class="wp-header-end" />
			<div class="wcforge-header">
				<div class="wcforge-header-left">
					<?php WCFORGE_Icons::render( 'compass', 'wcforge-header-icon' ); ?>
					<div class="wcforge-header-text">
						<h1>WindCodex Forge</h1>
						<span class="wcforge-header-rule"></span>
						<p>Read-only AI diagnostics for this site</p>
					</div>
				</div>
				<div class="wcforge-header-right">
					<div class="wcforge-help-wrap">
						<button type="button" class="wcforge-help-btn" id="wcforge-help-btn" aria-expanded="false" aria-haspopup="true" aria-controls="wcforge-help-dropdown">
							<span class="dashicons dashicons-editor-help"></span>
							<?php esc_html_e( 'Help', 'windcodex-forge' ); ?>
						</button>
						<div class="wcforge-help-dropdown" id="wcforge-help-dropdown" hidden>
							<a href="https://docs.windcodex.com/docs/forge" target="_blank" rel="noopener" class="wcforge-help-item">
								<span class="wcforge-help-item-icon dashicons dashicons-media-document"></span>
								<?php esc_html_e( 'Documentation', 'windcodex-forge' ); ?>
							</a>
							<a href="https://wordpress.org/support/plugin/windcodex-forge/" target="_blank" rel="noopener" class="wcforge-help-item">
								<span class="wcforge-help-item-icon dashicons dashicons-sos"></span>
								<?php esc_html_e( 'Support Forum', 'windcodex-forge' ); ?>
							</a>
							<a href="https://wordpress.org/support/plugin/windcodex-forge/reviews/#new-post" target="_blank" rel="noopener" class="wcforge-help-item">
								<span class="wcforge-help-item-icon dashicons dashicons-star-filled"></span>
								<?php esc_html_e( 'Submit a Review', 'windcodex-forge' ); ?>
							</a>
						</div>
					</div>
				</div>
			</div>

			<nav class="wcforge-tabs">
				<?php foreach ( self::TABS as $slug => $tab_data ) : ?>
					<a href="<?php echo esc_url( $this->tab_url( $slug ) ); ?>" class="wcforge-tab <?php echo esc_attr( $tab === $slug ? 'is-active' : '' ); ?>">
						<?php echo esc_html( $tab_data['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'tools':
					$this->render_tab_tools();
					break;
				case 'general':
					$this->render_tab_general();
					break;
				case 'log':
					$this->render_tab_log();
					break;
				default:
					$this->render_tab_connection();
					break;
			}
			?>
		</div>
		<?php
	}

	/* -----------------------------------------------------------------
	 * Tab: Connection
	 * ------------------------------------------------------------- */

	private function render_tab_connection() {
		$settings      = $this->settings->get_all();
		$endpoint      = WCFORGE_Server::instance()->get_endpoint_url();
		$client        = WCFORGE_OAuth::get_static_client();
		$clients       = WCFORGE_OAuth::list_all_clients_with_activity();
		$issuer_url    = untrailingslashit( home_url() );
		$tools         = new WCFORGE_Tools( $this->settings );
		$tool_count    = count( $tools->get_tool_definitions() );
		$last_activity = get_option( 'wcforge_last_activity' );
		?>
		<p class="wcforge-lede">Connect Claude, ChatGPT, or any other MCP-compatible AI platform to this site using the details below.</p>

		<div class="wcforge-stat-row">
			<div class="wcforge-stat-card">
				<div class="wcforge-stat-card-label">Status</div>
				<div class="wcforge-stat-card-value" style="font-size:16px;">
					<span class="wcforge-dot <?php echo esc_attr( $settings['enabled'] ? 'is-on' : 'is-off' ); ?>"></span>
					<?php echo esc_html( $settings['enabled'] ? 'Enabled' : 'Disabled' ); ?>
				</div>
				<div class="wcforge-stat-card-sub">Toggle from the General tab</div>
			</div>
			<div class="wcforge-stat-card">
				<div class="wcforge-stat-card-label">Active tools</div>
				<div class="wcforge-stat-card-value">
					<?php WCFORGE_Icons::render( 'settings' ); ?>
					<?php echo (int) $tool_count; ?>
				</div>
				<div class="wcforge-stat-card-sub">Based on the Tools tab</div>
			</div>
			<div class="wcforge-stat-card">
				<div class="wcforge-stat-card-label">Last activity</div>
				<div class="wcforge-stat-card-value" style="font-size:14px;">
					<?php WCFORGE_Icons::render( 'history' ); ?>
					<?php echo $last_activity ? esc_html( $last_activity ) : 'None yet'; ?>
				</div>
				<div class="wcforge-stat-card-sub"><?php echo esc_html( $last_activity ? 'UTC' : 'Waiting for first tool call' ); ?></div>
			</div>
		</div>

		<div class="wcforge-card">
			<div class="wcforge-section-title"><?php WCFORGE_Icons::render( 'link' ); ?> Connector URL</div>
			<p class="wcforge-section-desc">Paste this into the platform's "Add custom connector" form. Set Transport to <strong>Streamable HTTP</strong> where asked.</p>
			<?php $this->render_copy_field( 'wcforge-connector-url', $endpoint ); ?>
		</div>

		<div class="wcforge-card">
			<div class="wcforge-section-title"><?php WCFORGE_Icons::render( 'key' ); ?> OAuth</div>
			<p class="wcforge-section-desc">Most platforms register themselves automatically. If auto-registration fails, paste this Client ID/Secret into that platform's Advanced/OAuth settings instead.</p>

			<div class="wcforge-field-row">
				<span class="wcforge-field-label">Client ID</span>
				<?php $this->render_copy_field( 'wcforge-client-id', $client->client_id ?? '' ); ?>
			</div>
			<div class="wcforge-field-row">
				<span class="wcforge-field-label">Client Secret</span>
				<?php $this->render_copy_field( 'wcforge-client-secret', $client->client_secret ?? '' ); ?>
				<p class="wcforge-field-desc">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('This breaks anything currently using the old secret. Continue?');" style="margin-top:8px;">
						<?php wp_nonce_field( 'wcforge_rotate_oauth_secret' ); ?>
						<input type="hidden" name="action" value="wcforge_rotate_oauth_secret" />
						<button type="submit" class="button wcforge-btn-secondary">Regenerate Client Secret</button>
					</form>
				</p>
			</div>
			<div class="wcforge-field-row">
				<span class="wcforge-field-label">Discovery URLs</span>
				<div class="wcforge-discovery-box">
					<code><?php echo esc_html( $issuer_url . '/.well-known/oauth-authorization-server/wcforge' ); ?></code>
					<code><?php echo esc_html( $issuer_url . '/.well-known/oauth-protected-resource/wcforge' ); ?></code>
				</div>
				<p class="wcforge-field-desc">
					<span class="wcforge-badge is-neutral">Auto-discovered</span>&nbsp;Found automatically by MCP clients via a 401 challenge – you shouldn't need to paste these anywhere. These are scoped specifically to WindCodex Forge so they won't collide with another WindCodex plugin active on the same site.
				</p>
			</div>
			<div class="wcforge-field-row">
				<span class="wcforge-field-label">Auto-registration</span>
				<p class="wcforge-field-desc">
					Currently <span class="wcforge-badge <?php echo esc_attr( $settings['allow_dynamic_registration'] ? 'is-success' : 'is-neutral' ); ?>"><?php echo esc_html( $settings['allow_dynamic_registration'] ? 'Allowed' : 'Disabled' ); ?></span>
					&nbsp;– change this under the <a href="<?php echo esc_url( $this->tab_url( 'general' ) ); ?>">General</a> tab.
				</p>
			</div>
			<div class="wcforge-field-row">
				<span class="wcforge-field-label">Revoke access</span>
				<p class="wcforge-field-desc" style="margin-top:8px;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('This signs out every app connected via OAuth. Continue?');">
						<?php wp_nonce_field( 'wcforge_revoke_oauth' ); ?>
						<input type="hidden" name="action" value="wcforge_revoke_oauth" />
						<button type="submit" class="button wcforge-btn-danger-outline">Revoke All OAuth Access</button>
					</form>
				</p>
			</div>
		</div>

		<div class="wcforge-card">
			<div class="wcforge-section-title"><?php WCFORGE_Icons::render( 'plug' ); ?> Connected apps</div>
			<p class="wcforge-section-desc">Every AI platform currently authorized to reach this site through WindCodex Forge.</p>

			<?php if ( empty( $clients ) ) : ?>
				<p class="wcforge-empty-inline">No apps have connected yet.</p>
			<?php endif; ?>

			<div class="wcforge-tool-list"<?php echo empty( $clients ) ? ' style="margin-top:12px;"' : ''; ?>>
				<?php foreach ( $clients as $client_row ) : ?>
					<?php
					$client_label     = $client_row->client_name ?: $client_row->client_id;
					$client_icon_name = ( false !== stripos( $client_label, 'gpt' ) || false !== stripos( $client_label, 'openai' ) ) ? 'brand-openai' : 'message-circle-2';
					?>
					<div class="wcforge-tool-row">
						<?php WCFORGE_Icons::render( $client_icon_name, 'wcforge-tool-row-icon' ); ?>
						<div class="wcforge-tool-row-main">
							<div class="wcforge-tool-row-title-line">
								<span class="wcforge-tool-row-title"><?php echo esc_html( $client_row->client_name ?: $client_row->client_id ); ?></span>
							</div>
							<p class="wcforge-tool-row-desc">
								Connected <?php echo esc_html( human_time_diff( strtotime( $client_row->created_at . ' UTC' ) ) ); ?> ago
								· last used <?php echo $client_row->last_used ? esc_html( human_time_diff( strtotime( $client_row->last_used . ' UTC' ) ) ) . ' ago' : 'never'; ?>
								<?php
								$acting_user = $client_row->acting_user_id ? get_userdata( $client_row->acting_user_id ) : false;
								if ( $acting_user ) :
									$role_label = ! empty( $acting_user->roles ) ? ucfirst( $acting_user->roles[0] ) : 'No role';
									?>
									· acting as <?php echo esc_html( $acting_user->display_name ); ?> (<?php echo esc_html( $role_label ); ?>)
								<?php else : ?>
									· registered, but never signed in
								<?php endif; ?>
							</p>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Revoke access for \'<?php echo esc_js( $client_row->client_name ?: $client_row->client_id ); ?>\'? It will need to reauthorize to reconnect.');">
							<?php wp_nonce_field( 'wcforge_revoke_client' ); ?>
							<input type="hidden" name="action" value="wcforge_revoke_client" />
							<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_row->client_id ); ?>" />
							<button type="submit" class="wcforge-revoke-link">Revoke</button>
						</form>
					</div>
				<?php endforeach; ?>
				<div class="wcforge-tool-row">
					<?php WCFORGE_Icons::render( 'plus', 'wcforge-tool-row-icon is-accent' ); ?>
					<div class="wcforge-tool-row-main">
						<span class="wcforge-tool-row-title" style="color:var(--wcforge-accent);">Add a new connection</span>
						<p class="wcforge-tool-row-desc">This plugin doesn't start connections itself – in your AI platform's own settings, add a custom connector pointing at the Connector URL above; the platform initiates authorization from its side.</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* -----------------------------------------------------------------
	 * Tab: Tools
	 * ------------------------------------------------------------- */

	private function render_tab_tools() {
		$settings      = $this->settings->get_all();
		$bundles       = WCFORGE_Settings::bundle_metadata();
		$session_count = WCFORGE_Activity_Log::get_today_count();
		?>
		<div class="wcforge-stat-row">
			<div class="wcforge-stat-card">
				<div class="wcforge-stat-card-label">Capability</div>
				<div class="wcforge-stat-card-value" style="font-size:16px;">
					<?php WCFORGE_Icons::render( 'circle-check', 'is-success' ); ?> Read-only
				</div>
				<div class="wcforge-stat-card-sub">No write or code-execution tools exist in this plugin</div>
			</div>
			<div class="wcforge-stat-card">
				<div class="wcforge-stat-card-label">Changes this session</div>
				<div class="wcforge-stat-card-value"><?php echo (int) $session_count; ?></div>
			</div>
		</div>

		<div class="wcforge-section-title" style="margin-top:4px;">Primitives</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wcforge_save_tools' ); ?>
			<input type="hidden" name="action" value="wcforge_save_tools" />

			<div class="wcforge-tool-list">
				<?php foreach ( $bundles as $bundle_key => $bundle ) : ?>
					<?php $this->render_bundle_row( $bundle_key, $bundle, $settings['primitive_enabled'] ); ?>
				<?php endforeach; ?>
			</div>

			<?php submit_button( 'Save Settings', 'primary wcforge-btn-primary' ); ?>
		</form>
		<?php
	}

	/**
	 * A bundle counts as "on" if every real primitive it contains is
	 * enabled. Every bundle in this plugin is low-risk and read-only, so
	 * unlike WindCodex Forge Pro there's no environment/production gate
	 * to weigh against the toggle.
	 */
	private function render_bundle_row( $key, $bundle, $primitive_enabled ) {
		$checked = true;
		foreach ( $bundle['primitives'] as $primitive ) {
			if ( empty( $primitive_enabled[ $primitive ] ) ) {
				$checked = false;
				break;
			}
		}
		?>
		<div class="wcforge-tool-row">
			<?php WCFORGE_Icons::render( $bundle['icon'], 'wcforge-tool-row-icon' ); ?>
			<div class="wcforge-tool-row-main">
				<div class="wcforge-tool-row-title-line">
					<span class="wcforge-tool-row-title"><?php echo esc_html( $bundle['title'] ); ?></span>
					<span class="wcforge-risk-badge is-<?php echo esc_attr( $bundle['risk'] ); ?>"><?php echo esc_html( ucfirst( $bundle['risk'] ) ); ?> risk</span>
				</div>
				<p class="wcforge-tool-row-desc"><?php echo esc_html( $bundle['desc'] ); ?></p>
			</div>
			<label class="wcforge-switch">
				<input type="checkbox" name="bundle_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $checked ); ?> />
				<span class="wcforge-slider"></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Render a readonly value with a one-click copy button next to it.
	 */
	private function render_copy_field( $id, $value ) {
		?>
		<div class="wcforge-copy-row">
			<input type="text" id="<?php echo esc_attr( $id ); ?>" readonly="readonly" class="wcforge-input-copy" value="<?php echo esc_attr( $value ); ?>" onclick="this.select();" />
			<button type="button" class="wcforge-copy-btn" data-copy-target="<?php echo esc_attr( $id ); ?>" title="Copy to clipboard">
				<?php WCFORGE_Icons::render( 'clipboard', 'wcforge-copy-icon-default' ); ?>
				<?php WCFORGE_Icons::render( 'check', 'wcforge-copy-icon-copied' ); ?>
			</button>
		</div>
		<?php
	}

	/* -----------------------------------------------------------------
	 * Tab: General
	 * ------------------------------------------------------------- */

	private function render_tab_general() {
		$settings = $this->settings->get_all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wcforge_save_general' ); ?>
			<input type="hidden" name="action" value="wcforge_save_general" />

			<div class="wcforge-card">
				<div class="wcforge-section-title"><?php WCFORGE_Icons::render( 'server-2' ); ?> Connector</div>

				<div class="wcforge-field-row wcforge-toggle-row">
					<div>
						<span class="wcforge-field-label">Connector status</span>
						<p class="wcforge-field-desc">Turn off to immediately stop every connected AI platform from reaching this site, without deleting your configuration.</p>
					</div>
					<label class="wcforge-switch"><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> /><span class="wcforge-slider"></span></label>
				</div>

				<div class="wcforge-field-row wcforge-toggle-row">
					<div>
						<span class="wcforge-field-label">Read-only mode</span>
						<p class="wcforge-field-desc">Only expose read tools – no create/update/delete tools of any kind.</p>
					</div>
					<label class="wcforge-switch"><input type="checkbox" name="read_only" value="1" <?php checked( $settings['read_only'] ); ?> /><span class="wcforge-slider"></span></label>
				</div>

				<div class="wcforge-field-row">
					<span class="wcforge-field-label">Who can connect</span>
					<select name="connect_capability">
						<?php foreach ( WCFORGE_Settings::connect_capability_choices() as $capability => $label ) : ?>
							<option value="<?php echo esc_attr( $capability ); ?>" <?php selected( $this->settings->get_connect_capability(), $capability ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="wcforge-field-desc">Who may approve an AI connection on the consent screen. Each connection acts with that person's own permissions, and stops working as soon as they no longer have this role. Revoke individual connections on the Connection tab.</p>
				</div>

				<div class="wcforge-field-row">
					<span class="wcforge-field-label">Max results per list call</span>
					<input type="number" min="1" max="200" name="max_results_limit" value="<?php echo esc_attr( $settings['max_results_limit'] ); ?>" class="wcforge-input-text" style="width:80px;" />
				</div>

				<div class="wcforge-field-row">
					<span class="wcforge-field-label">Requests per minute</span>
					<input type="number" min="10" max="600" name="requests_per_minute_limit" value="<?php echo esc_attr( $settings['requests_per_minute_limit'] ?? 120 ); ?>" class="wcforge-input-text" style="width:80px;" />
					<p class="wcforge-field-desc">Per-user cap on tool calls through the MCP connector. Default 120 - allowed range 10 to 600.</p>
				</div>

				<div class="wcforge-field-row wcforge-toggle-row">
					<div>
						<span class="wcforge-field-label">OAuth auto-registration</span>
						<p class="wcforge-field-desc">Allow platforms to register themselves automatically. Turn off to only ever use the static Client ID/Secret on the Connection tab.</p>
					</div>
					<label class="wcforge-switch"><input type="checkbox" name="allow_dynamic_registration" value="1" <?php checked( $settings['allow_dynamic_registration'] ); ?> /><span class="wcforge-slider"></span></label>
				</div>
			</div>

			<div class="wcforge-card">
				<div class="wcforge-section-title"><?php WCFORGE_Icons::render( 'shield-check' ); ?> Preferences</div>
				<div class="wcforge-field-row wcforge-toggle-row">
					<div>
						<span class="wcforge-field-label">Keep activity log</span>
						<p class="wcforge-field-desc">Record every AI tool call – what ran, when, and whether it succeeded – on the Activity tab. Turning this off stops recording new calls; entries already saved stay until you clear them.</p>
					</div>
					<label class="wcforge-switch"><input type="checkbox" name="log_ai_activity" value="1" <?php checked( $settings['log_ai_activity'] ?? true ); ?> /><span class="wcforge-slider"></span></label>
				</div>
			</div>

			<div class="wcforge-card">
				<div class="wcforge-section-title"><?php WCFORGE_Icons::render( 'shield' ); ?> Data &amp; Privacy</div>
				<div class="wcforge-field-row wcforge-toggle-row">
					<div>
						<span class="wcforge-field-label">Delete all data on uninstall</span>
						<p class="wcforge-field-desc">
							When off (default), deleting this plugin from the Plugins screen leaves your settings, OAuth clients/tokens, activity log, and saved memories in place – reinstalling later restores everything as it was. Turn this on if you want a full cleanup (settings, database tables, and all) the next time the plugin is deleted.
						</p>
					</div>
					<label class="wcforge-switch"><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $settings['delete_data_on_uninstall'] ?? false ); ?> /><span class="wcforge-slider"></span></label>
				</div>
			</div>

			<?php submit_button( 'Save Settings', 'primary wcforge-btn-primary' ); ?>
		</form>

		<div class="wcforge-card">
			<div class="wcforge-section-title"><?php WCFORGE_Icons::render( 'key' ); ?> Authentication</div>

			<div class="wcforge-field-row">
				<span class="wcforge-field-label">Method</span>
				<p class="wcforge-field-desc">OAuth 2.1 with PKCE. Every connected app authenticates as a real WordPress user – there's no separate always-on API key granting blanket access.</p>
			</div>
		</div>

		<div class="wcforge-card">
			<div class="wcforge-section-title"><?php WCFORGE_Icons::render( 'info-circle' ); ?> About</div>
			<div class="wcforge-field-row">
				<span class="wcforge-field-label">Site name</span>
				<p class="wcforge-field-desc" style="margin:0;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
			</div>
			<div class="wcforge-field-row">
				<span class="wcforge-field-label">Plugin version</span>
				<p class="wcforge-field-desc" style="margin:0;"><?php echo esc_html( WCFORGE_VERSION ); ?></p>
			</div>
		</div>
		<?php
	}

	/* -----------------------------------------------------------------
	 * Tab: Activity
	 * ------------------------------------------------------------- */

	private function render_tab_log() {
		$entries = WCFORGE_Activity_Log::get_recent( 30 );
		?>
		<p class="wcforge-lede">Every tool call made through this connector is recorded here, in full detail.</p>

		<?php if ( ! WCFORGE_Settings::instance()->get( 'log_ai_activity', true ) ) : ?>
			<div class="notice notice-warning inline"><p>The activity log is turned off, so new calls are not being recorded. Turn it back on under General &rarr; Preferences.</p></div>
		<?php endif; ?>

		<div class="wcforge-card">
			<div class="wcforge-toggle-row" style="margin-bottom:16px;">
				<div class="wcforge-section-title" style="margin:0;">
					<?php WCFORGE_Icons::render( 'clipboard' ); ?> All calls
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete the entire activity log?');">
					<?php wp_nonce_field( 'wcforge_clear_log' ); ?>
					<input type="hidden" name="action" value="wcforge_clear_log" />
					<button type="submit" class="button wcforge-btn-secondary">Clear Log</button>
				</form>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<div class="wcforge-empty-state">
					<?php WCFORGE_Icons::render( 'clipboard' ); ?>
					<p>No activity yet. Power-tool calls will show up here.</p>
				</div>
			<?php else : ?>
				<div class="wcforge-tool-list" style="margin-bottom:0; max-height:799px; overflow-y:auto;">
					<?php foreach ( $entries as $entry ) : ?>
						<?php $user = get_userdata( $entry->user_id ); ?>
						<div class="wcforge-tool-row">
							<?php WCFORGE_Icons::render( $entry->success ? 'circle-check' : 'alert-triangle', 'wcforge-tool-row-icon' ); ?>
							<div class="wcforge-tool-row-main">
								<div class="wcforge-tool-row-title-line">
									<span class="wcforge-tool-row-title" style="font-family:var(--wcforge-mono);font-size:13px;"><?php echo esc_html( $entry->tool_name ); ?></span>
									<span class="wcforge-badge <?php echo esc_attr( $entry->success ? 'is-success' : 'is-danger' ); ?>"><?php echo esc_html( $entry->success ? 'OK' : 'Failed' ); ?></span>
								</div>
								<p class="wcforge-tool-row-desc"><?php echo esc_html( $entry->created_at ); ?> UTC – <?php echo esc_html( $user ? $user->user_login : "user #{$entry->user_id}" ); ?></p>
								<p class="wcforge-tool-row-desc" style="font-family:var(--wcforge-mono);word-break:break-all;"><?php echo esc_html( mb_substr( $entry->summary, 0, 200 ) ); ?></p>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
