<?php
/**
 * Plugin Name: Abilities Audit
 * Plugin URI:  https://github.com/tenacityio/abilities-audit
 * Description: Audit and governance dashboard for the WordPress Abilities API. View, inspect, and toggle registered abilities from a single admin screen.
 * Version:     0.5.1
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Author:      Tenacity
 * Author URI:  https://tenacity.io
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: abilities-audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABILITIES_AUDIT_VERSION', '0.5.1' );

/**
 * Main plugin class.
 *
 * Uses a singleton so the abilities snapshot captured during init
 * is available later when the admin page renders.
 */
final class Abilities_Audit {

	/** Option key for the list of disabled ability names. */
	const OPTION_DISABLED = 'abilities_audit_disabled';

	/** Required capability to access the admin page and toggle abilities. */
	const CAPABILITY = 'manage_options';

	/** Namespace prefixes that identify WordPress core abilities. */
	const CORE_NAMESPACES = array( 'wordpress', 'wp', 'core' );

	/** @var self|null */
	private static $instance = null;

	/** @var array Snapshot of all WP_Ability objects captured before filtering. Keyed by ability name. */
	private $abilities_snapshot = array();

	/** @var string[] List of currently disabled ability names. */
	private $disabled = array();

	/** @var string Admin screen hook suffix for Tools > Abilities Audit. */
	private $admin_page_hook = '';

	/** @var bool Whether capture_and_filter() has successfully run. */
	private $snapshot_captured = false;

	/** @var bool Re-entrance guard for capture_and_filter(). */
	private $is_capturing = false;

	/** @var array|null Cached result of get_plugins() to avoid repeated filesystem scans. */
	private $plugins_cache = null;

	/** @var array|null Cached result of get_mu_plugins() to avoid repeated filesystem scans. */
	private $mu_plugins_cache = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \Exception When unserialization is attempted.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}

	/**
	 * Wire up hooks.
	 */
	private function __construct() {
		$this->disabled = get_option( self::OPTION_DISABLED, array() );

		if ( ! is_array( $this->disabled ) ) {
			$this->disabled = array();
		}

		// Capture all abilities at late priority, then unregister disabled ones.
		add_action( 'wp_abilities_api_init', array( $this, 'capture_and_filter' ), 999 );

		// Fallback: ensure snapshot + disable filter runs even if wp_abilities_api_init
		// fired before abilities were registered (or didn't fire at all).
		add_action( 'wp_loaded', array( $this, 'ensure_capture' ) );

		// Admin page under Tools.
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// AJAX handler for toggling.
		add_action( 'wp_ajax_abilities_audit_toggle', array( $this, 'ajax_toggle' ) );
	}

	/**
	 * Enqueue admin styles and scripts on the Abilities Audit screen only.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->admin_page_hook ) {
			return;
		}

		wp_enqueue_style(
			'abilities-audit-admin',
			plugins_url( 'assets/css/admin.css', __FILE__ ),
			array(),
			ABILITIES_AUDIT_VERSION
		);

		wp_enqueue_script(
			'abilities-audit-admin',
			plugins_url( 'assets/js/admin.js', __FILE__ ),
			array(),
			ABILITIES_AUDIT_VERSION,
			true
		);

		wp_localize_script(
			'abilities-audit-admin',
			'abilitiesAuditAdmin',
			array(
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'summaryTemplate' => __( '%1$d abilities registered. %2$d enabled, %3$d disabled.', 'abilities-audit' ),
				'i18n'            => array(
					'hide'             => __( 'Hide', 'abilities-audit' ),
					'view'             => __( 'View', 'abilities-audit' ),
					'error'            => __( 'Error', 'abilities-audit' ),
					'on'               => __( 'On', 'abilities-audit' ),
					'off'              => __( 'Off', 'abilities-audit' ),
					// These mirror the aria-label strings rendered inline by render_admin_page()
					// for the initial page state; both sets must stay in sync.
					'enableAria'       => __( 'Enable %s', 'abilities-audit' ),
					'disableAria'      => __( 'Disable %s', 'abilities-audit' ),
					'schemaAnnotations' => __( 'Annotations', 'abilities-audit' ),
					'schemaInput'      => __( 'Input Schema', 'abilities-audit' ),
					'schemaOutput'     => __( 'Output Schema', 'abilities-audit' ),
					'schemaRawData'    => __( 'Raw Data', 'abilities-audit' ),
				),
			)
		);
	}

	// ------------------------------------------------------------------
	//  Capture and filter abilities
	// ------------------------------------------------------------------

	/**
	 * Runs at priority 999 on wp_abilities_api_init.
	 *
	 * 1. Snapshot every registered ability (including ones we are about to disable).
	 * 2. Unregister any abilities the admin has toggled off (wp_has_ability guards each call).
	 *
	 * The snapshot is copied into a new array before unregistering. The registry may mutate the
	 * array returned by wp_get_abilities(); without a copy, disabled rows would disappear from the audit UI.
	 *
	 * The disabled list is only persisted via ajax_toggle(); this method does not write the option.
	 * Stale names in the option are harmless: wp_has_ability is false when an ability no longer exists.
	 *
	 * This method is a no-op if it has already run in the same request (see snapshot_captured),
	 * so a second pass cannot overwrite the snapshot after unregistering.
	 */
	public function capture_and_filter() {
		// Run once per request: a second pass would re-snapshot after unregistering.
		if ( $this->snapshot_captured || $this->is_capturing ) {
			return;
		}

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return;
		}

		// Guard against re-entrance: wp_get_abilities() may lazy-fire
		// wp_abilities_api_init, which would invoke this method again at
		// priority 999 and overwrite the snapshot with a post-unregistration
		// registry.
		$this->is_capturing = true;

		$registry = wp_get_abilities();
		if ( ! is_array( $registry ) ) {
			$registry = array();
		}
		// Shallow copy so wp_unregister_ability() cannot remove keys from our admin snapshot.
		$this->abilities_snapshot = array();
		foreach ( $registry as $name => $ability ) {
			$this->abilities_snapshot[ $name ] = $ability;
		}

		foreach ( $this->disabled as $name ) {
			if ( function_exists( 'wp_unregister_ability' ) && wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		$this->is_capturing = false;

		// Mark complete when we have data, or after wp_loaded so ensure_capture() does not loop on empty sites.
		$this->snapshot_captured = ! empty( $this->abilities_snapshot ) || did_action( 'wp_loaded' );
	}

	/**
	 * Runs on wp_loaded if capture_and_filter() did not run earlier (e.g. wp_abilities_api_init
	 * did not fire or fired before abilities were registered).
	 */
	public function ensure_capture() {
		if ( $this->snapshot_captured || ! function_exists( 'wp_get_abilities' ) ) {
			return;
		}

		// wp_abilities_api_init either didn't fire or fired with an empty registry.
		// Capture now — we are past all init-phase ability registrations.
		$this->capture_and_filter();
	}

	// ------------------------------------------------------------------
	//  Private helpers
	// ------------------------------------------------------------------

	/**
	 * Return the wp_kses allowlist used for the Flags column HTML.
	 *
	 * Centralised here so the render path and AJAX path cannot drift.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function flags_kses_allowed_html() {
		return array(
			'div'  => array( 'class' => true ),
			'span' => array(
				'class' => true,
				'title' => true,
			),
		);
	}

	/**
	 * Build the raw-data array for a registered ability.
	 *
	 * Normalises schema/meta return values to arrays so callers do not need
	 * individual null-checks.
	 *
	 * @param  string      $name        Ability name.
	 * @param  \WP_Ability $ability_obj Registered ability object.
	 * @return array<string,mixed>
	 */
	private function build_ability_raw_data( $name, \WP_Ability $ability_obj ) {
		$input_schema  = $ability_obj->get_input_schema();
		$output_schema = $ability_obj->get_output_schema();
		$meta          = $ability_obj->get_meta();
		return array(
			'name'          => $name,
			'label'         => $ability_obj->get_label(),
			'description'   => $ability_obj->get_description(),
			'input_schema'  => is_array( $input_schema ) ? $input_schema : array(),
			'output_schema' => is_array( $output_schema ) ? $output_schema : array(),
			'meta'          => is_array( $meta ) ? $meta : array(),
		);
	}

	/**
	 * Return all installed plugins, caching the result for the request lifetime.
	 *
	 * Avoids repeated filesystem scans when detect_source() is called once per
	 * ability in the audit table.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_all_plugins() {
		if ( null === $this->plugins_cache ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$this->plugins_cache = get_plugins();
		}
		return $this->plugins_cache;
	}

	/**
	 * Return all must-use plugins, caching the result for the request lifetime.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_all_mu_plugins() {
		if ( null === $this->mu_plugins_cache ) {
			$this->mu_plugins_cache = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : array();
		}
		return $this->mu_plugins_cache;
	}

	// ------------------------------------------------------------------
	//  Source detection
	// ------------------------------------------------------------------

	/**
	 * Detect which "provider" an ability comes from (Core, Plugin, Theme).
	 *
	 * Strategy:
	 * 1. If the ability explicitly sets a provider in its meta, use that.
	 * 2. Parse the namespace (text before the first slash).
	 * 3. If namespace matches known core prefixes, mark as Core.
	 * 4. If namespace matches the active theme, mark as Theme.
	 * 5. Otherwise, default to Plugin.
	 *
	 * @param  string $ability_name Full ability name (namespace/ability-name).
	 * @param  array<string,mixed> $meta Optional. Ability meta, if available.
	 * @return array{type: string, label: string}
	 */
	private function detect_source( $ability_name, $meta = array() ) {
		$parts     = explode( '/', $ability_name, 2 );
		$namespace = $parts[0];

		/*
		 * Try to resolve a human-friendly component name for the namespace.
		 *
		 * We still display the namespace in parentheses for disambiguation
		 * (e.g. multiple plugins can have similarly-named brands).
		 *
		 * @return array{type: 'plugin'|'theme', label: string}|null
		 */
		$resolved_component = null;

		// Active/inactive plugins (best-effort slug match).
		foreach ( $this->get_all_plugins() as $plugin_file => $plugin_data ) {
			$slug = dirname( $plugin_file );
			if ( '.' === $slug ) {
				$slug = basename( $plugin_file, '.php' );
			}
			if ( $slug !== $namespace ) {
				continue;
			}

			$active = function_exists( 'is_plugin_active' ) ? is_plugin_active( $plugin_file ) : true;
			$resolved_component = array(
				'type'  => 'plugin',
				/* translators: %s: plugin name */
				'label' => $active
					? $plugin_data['Name']
					: sprintf( __( '%s (inactive)', 'abilities-audit' ), $plugin_data['Name'] ),
			);
			break;
		}

		// Theme (namespace matches active theme).
		if ( null === $resolved_component && ( get_stylesheet() === $namespace || get_template() === $namespace ) ) {
			$theme = wp_get_theme();
			$resolved_component = array(
				'type'  => 'theme',
				'label' => (string) $theme->get( 'Name' ),
			);
		}

		// Must-use plugins (best-effort basename match).
		if ( null === $resolved_component ) {
			foreach ( $this->get_all_mu_plugins() as $mu_file => $mu_data ) {
				$slug = basename( $mu_file, '.php' );
				if ( $slug !== $namespace ) {
					continue;
				}

				$resolved_component = array(
					'type'  => 'plugin',
					'label' => $mu_data['Name'],
				);
				break;
			}
		}

		// Meta override (if provided by the ability).
		// Provider values are normalised to title-case so 'core', 'CORE', and 'Core' all match.
		if ( is_array( $meta ) && isset( $meta['provider'] ) ) {
			$provider = ucfirst( strtolower( (string) $meta['provider'] ) );
			if ( 'Core' === $provider ) {
				return array(
					'type'  => 'core',
					'label' => in_array( $namespace, self::CORE_NAMESPACES, true )
						? __( 'Core', 'abilities-audit' )
						: sprintf( __( 'Core (%s)', 'abilities-audit' ), $namespace ),
				);
			}
			if ( 'Theme' === $provider ) {
				return array(
					'type'  => 'theme',
					'label' => $resolved_component && 'theme' === $resolved_component['type']
						? sprintf( __( 'Theme (%s)', 'abilities-audit' ), $resolved_component['label'] )
						: sprintf( __( 'Theme (%s)', 'abilities-audit' ), $namespace ),
				);
			}
			if ( 'Plugin' === $provider ) {
				return array(
					'type'  => 'plugin',
					'label' => $resolved_component && 'plugin' === $resolved_component['type']
						? sprintf( __( 'Plugin (%s)', 'abilities-audit' ), $resolved_component['label'] )
						: sprintf( __( 'Plugin (%s)', 'abilities-audit' ), $namespace ),
				);
			}

			// Unknown provider slug: still show it, but keep namespace for disambiguation.
			return array(
				'type'  => 'plugin',
				'label' => $provider . ' (' . $namespace . ')',
			);
		}

		// WordPress core abilities (namespace/ability format).
		if ( in_array( $namespace, self::CORE_NAMESPACES, true ) ) {
			return array(
				'type'  => 'core',
				'label' => __( 'Core', 'abilities-audit' ),
			);
		}

		// Theme abilities.
		if ( $resolved_component && 'theme' === $resolved_component['type'] ) {
			return array(
				'type'  => 'theme',
				'label' => sprintf( __( 'Theme (%s)', 'abilities-audit' ), $resolved_component['label'] ),
			);
		}

		// Default to Plugin.
		if ( $resolved_component && 'plugin' === $resolved_component['type'] ) {
			return array(
				'type'  => 'plugin',
				'label' => sprintf( __( 'Plugin (%s)', 'abilities-audit' ), $resolved_component['label'] ),
			);
		}

		return array(
			'type'  => 'plugin',
			'label' => sprintf( __( 'Plugin (%s)', 'abilities-audit' ), $namespace ),
		);
	}

	/**
	 * Build HTML for the Flags column from ability meta (annotations, REST, MCP).
	 *
	 * @param array<string,mixed> $meta Ability meta.
	 * @return string HTML (escaped).
	 */
	private function render_ability_flags_html( array $meta ): string {
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();

		$readonly    = array_key_exists( 'readonly', $annotations ) ? $annotations['readonly'] : null;
		$destructive = array_key_exists( 'destructive', $annotations ) ? $annotations['destructive'] : null;
		$idempotent  = array_key_exists( 'idempotent', $annotations ) ? $annotations['idempotent'] : null;

		$instructions = '';
		if ( isset( $annotations['instructions'] ) && is_string( $annotations['instructions'] ) ) {
			$instructions = $annotations['instructions'];
		}

		$show_in_rest = isset( $meta['show_in_rest'] ) && true === $meta['show_in_rest'];

		$mcp_public = false;
		if ( isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) && isset( $meta['mcp']['public'] ) ) {
			$mcp_public = true === $meta['mcp']['public'];
		}

		$all_annotation_null = ( null === $readonly && null === $destructive && null === $idempotent );

		$pieces = array();

		if ( true === $readonly ) {
			$pieces[] = '<span class="abilities-audit-flag abilities-audit-flag--readonly">' . esc_html__( 'Read-only', 'abilities-audit' ) . '</span>';
		}
		if ( true === $idempotent ) {
			$pieces[] = '<span class="abilities-audit-flag abilities-audit-flag--idempotent">' . esc_html__( 'Idempotent', 'abilities-audit' ) . '</span>';
		}
		if ( $show_in_rest ) {
			$pieces[] = '<span class="abilities-audit-flag abilities-audit-flag--rest">' . esc_html__( 'REST', 'abilities-audit' ) . '</span>';
		}
		if ( $mcp_public ) {
			$pieces[] = '<span class="abilities-audit-flag abilities-audit-flag--mcp">' . esc_html__( 'MCP', 'abilities-audit' ) . '</span>';
		}
		if ( true === $destructive ) {
			$pieces[] = '<span class="abilities-audit-flag abilities-audit-flag--destructive">' . esc_html__( 'Destructive', 'abilities-audit' ) . '</span>';
		}
		if ( $all_annotation_null ) {
			$pieces[] = '<span class="abilities-audit-flag abilities-audit-flag--undeclared">' . esc_html__( 'Undeclared', 'abilities-audit' ) . '</span>';
		}

		if ( '' !== $instructions ) {
			$pieces[] = '<span class="abilities-audit-flag abilities-audit-flag--instructions" title="' . esc_attr( $instructions ) . '">' . esc_html__( 'Instructions', 'abilities-audit' ) . '</span>';
		}

		if ( empty( $pieces ) ) {
			return '<span class="description">&mdash;</span>';
		}

		return '<div class="abilities-audit-flags">' . implode( '', $pieces ) . '</div>';
	}

	// ------------------------------------------------------------------
	//  Admin page
	// ------------------------------------------------------------------

	/**
	 * Register the admin page under Tools.
	 */
	public function register_admin_page() {
		$this->admin_page_hook = add_management_page(
			__( 'Abilities Audit', 'abilities-audit' ),
			__( 'Abilities Audit', 'abilities-audit' ),
			self::CAPABILITY,
			'abilities-audit',
			array( $this, 'render_admin_page' )
		);

		if ( ! $this->admin_page_hook ) {
			return;
		}

		add_action( "load-{$this->admin_page_hook}", array( $this, 'add_help_tabs' ) );
	}

	/**
	 * Add contextual help tabs to the audit screen.
	 *
	 * @since 0.5.0
	 */
	public function add_help_tabs() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-audit-overview',
				'title'   => __( 'Overview', 'abilities-audit' ),
				'content' =>
					'<p>' . esc_html__( 'Abilities Audit lists every ability registered on this site through the WordPress Abilities API (WordPress 6.9+). You can inspect labels, descriptions, source, flags, and JSON schemas, and enable or disable each ability from this screen.', 'abilities-audit' ) . '</p>' .
					'<p>' . esc_html__( 'Find this screen under Tools > Abilities Audit. You need the manage_options capability (typically administrators).', 'abilities-audit' ) . '</p>' .
					'<p>' . esc_html__( 'When you disable an ability, it is unregistered for the rest of the request lifecycle and its disabled state is saved. Disabled abilities are not listed for integrations that rely on registered abilities (for example the REST API).', 'abilities-audit' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-audit-flags',
				'title'   => esc_html__( 'Flags', 'abilities-audit' ),
				'content' =>
					'<p>' . esc_html__( 'The Flags column summarizes annotations and meta that describe how an ability behaves and how it is exposed:', 'abilities-audit' ) . '</p>' .
					'<ul>' .
						'<li><strong>' . esc_html__( 'Read-only', 'abilities-audit' ) . '</strong> &mdash; ' . esc_html__( 'The ability declares it does not modify data (annotations.readonly is true).', 'abilities-audit' ) . '</li>' .
						'<li><strong>' . esc_html__( 'Idempotent', 'abilities-audit' ) . '</strong> &mdash; ' . esc_html__( 'The ability declares that repeated calls have the same effect as a single call (annotations.idempotent is true).', 'abilities-audit' ) . '</li>' .
						'<li><strong>' . esc_html__( 'REST', 'abilities-audit' ) . '</strong> &mdash; ' . esc_html__( 'The ability is exposed via the WordPress REST API (meta.show_in_rest is true).', 'abilities-audit' ) . '</li>' .
						'<li><strong>' . esc_html__( 'MCP', 'abilities-audit' ) . '</strong> &mdash; ' . esc_html__( 'The ability is published on the public MCP surface (meta.mcp.public is true).', 'abilities-audit' ) . '</li>' .
						'<li><strong>' . esc_html__( 'Destructive', 'abilities-audit' ) . '</strong> &mdash; ' . esc_html__( 'The ability declares it can permanently remove or alter data (annotations.destructive is true).', 'abilities-audit' ) . '</li>' .
						'<li><strong>' . esc_html__( 'Undeclared', 'abilities-audit' ) . '</strong> &mdash; ' . esc_html__( 'None of the annotation booleans readonly, idempotent, or destructive are set, so the behavior profile is unknown.', 'abilities-audit' ) . '</li>' .
						'<li><strong>' . esc_html__( 'Instructions', 'abilities-audit' ) . '</strong> &mdash; ' . esc_html__( 'The ability includes free-text agent guidance in annotations.instructions. Hover the badge to read it.', 'abilities-audit' ) . '</li>' .
					'</ul>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-audit-disabling',
				'title'   => esc_html__( 'Disabling Abilities', 'abilities-audit' ),
				'content' =>
					'<p>' . esc_html__( 'This plugin only controls whether an ability remains registered on the site. Another integration may still show a button or menu item using its own feature toggles, user capabilities, or permission callbacks; those layers are independent.', 'abilities-audit' ) . '</p>' .
					'<p>' . esc_html__( 'When an ability is disabled here, attempts to invoke it through paths that require a registered ability will fail. If a control still appears elsewhere, that reflects how that integration builds its UI.', 'abilities-audit' ) . '</p>' .
					'<p>' . esc_html__( 'Integrators should check that an ability is registered (for example with wp_has_ability()) in addition to other checks, so controls can hide when an ability is disabled or unregistered for any reason.', 'abilities-audit' ) . '</p>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'For more information:', 'abilities-audit' ) . '</strong></p>' .
			'<p><a href="https://developer.wordpress.org/apis/abilities/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abilities API Documentation', 'abilities-audit' ) . '</a></p>'
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'abilities-audit' ) );
		}

		$abilities = $this->abilities_snapshot;
		$disabled  = get_option( self::OPTION_DISABLED, array() );
		if ( ! is_array( $disabled ) ) {
			$disabled = array();
		}

		// Last-resort fallback: snapshot still empty at render time (e.g. very early page load).
		// Use ensure_capture() so the snapshot is populated consistently (with unregistrations
		// applied) rather than reading the live registry directly after potential lazy-init.
		if ( empty( $abilities ) ) {
			$this->ensure_capture();
			$abilities = $this->abilities_snapshot;
		}

		// Ensure disabled-but-unregistered abilities still appear (orphans after edge cases).
		foreach ( $disabled as $name ) {
			if ( ! isset( $abilities[ $name ] ) ) {
				$abilities[ $name ] = null;
			}
		}

		// Sort alphabetically by name.
		ksort( $abilities );

		$abilities_api_available = function_exists( 'wp_get_abilities' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Abilities Audit', 'abilities-audit' ); ?></h1>
			<p><?php esc_html_e( 'All abilities registered on this site via the Abilities API. Disabled abilities are unregistered at runtime and will not appear in the REST API or be available to AI agents.', 'abilities-audit' ); ?></p>

			<?php if ( ! $abilities_api_available ) : ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'The WordPress Abilities API is not available on this site. Abilities Audit requires WordPress 6.9 or later.', 'abilities-audit' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $abilities ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No abilities are currently registered. Make sure you are running WordPress 6.9 or later and that at least one component has registered abilities.', 'abilities-audit' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" id="abilities-audit-table">
					<thead>
						<tr>
							<th class="column-status" style="width:70px;"><?php esc_html_e( 'Status', 'abilities-audit' ); ?></th>
							<th class="column-name" style="width:220px;"><?php esc_html_e( 'Name', 'abilities-audit' ); ?></th>
							<th class="column-label" style="width:170px;"><?php esc_html_e( 'Label', 'abilities-audit' ); ?></th>
							<th class="column-source" style="width:200px;"><?php esc_html_e( 'Source', 'abilities-audit' ); ?></th>
							<th class="column-description"><?php esc_html_e( 'Description', 'abilities-audit' ); ?></th>
							<th class="column-flags" style="width:180px;"><?php esc_html_e( 'Flags', 'abilities-audit' ); ?></th>
							<th class="column-schema" style="width:80px;"><?php esc_html_e( 'Schema', 'abilities-audit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $abilities as $name => $ability_obj ) :
							$is_disabled = in_array( $name, $disabled, true );
							if ( $ability_obj instanceof \WP_Ability ) {
								$raw_data      = $this->build_ability_raw_data( $name, $ability_obj );
								$meta          = $raw_data['meta'];
								$label         = $raw_data['label'];
								$description   = $raw_data['description'];
								$input_schema  = $raw_data['input_schema'];
								$output_schema = $raw_data['output_schema'];
								$annotations   = method_exists( $ability_obj, 'get_annotations' ) ? $ability_obj->get_annotations() : array();
							} else {
								$meta        = array();
								$label       = $name;
								$description = __( 'This ability is disabled and not registered in this request. Turn it on to restore it.', 'abilities-audit' );
								$input_schema  = array();
								$output_schema = array();
								$annotations   = array();
								$raw_data      = array();
							}

							$source             = $this->detect_source( $name, $meta );
							$source_badge_class = 'abilities-audit-badge abilities-audit-badge--' . esc_attr( $source['type'] );
							?>
							<tr class="<?php echo $is_disabled ? 'abilities-audit-row--disabled' : ''; ?>">
								<td class="column-status">
									<button
										type="button"
										class="abilities-audit-toggle button <?php echo $is_disabled ? 'button-secondary' : 'button-primary'; ?>"
										data-ability="<?php echo esc_attr( $name ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'abilities_audit_toggle_' . $name ) ); ?>"
										aria-label="<?php echo $is_disabled
											? esc_attr( sprintf( __( 'Enable %s', 'abilities-audit' ), $name ) )
											: esc_attr( sprintf( __( 'Disable %s', 'abilities-audit' ), $name ) ); ?>"
									>
										<?php echo $is_disabled
											? esc_html__( 'Off', 'abilities-audit' )
											: esc_html__( 'On', 'abilities-audit' ); ?>
									</button>
								</td>
								<td class="column-name">
									<code><?php echo esc_html( $name ); ?></code>
								</td>
								<td class="column-label">
									<?php echo esc_html( $label ); ?>
								</td>
								<td class="column-source">
									<span class="<?php echo esc_attr( $source_badge_class ); ?>">
										<?php echo esc_html( $source['label'] ); ?>
									</span>
								</td>
								<td class="column-description">
									<?php echo esc_html( $description ); ?>
								</td>
								<td class="column-flags">
									<?php
									if ( $ability_obj instanceof \WP_Ability ) {
										echo wp_kses(
											$this->render_ability_flags_html( $meta ),
											$this->flags_kses_allowed_html()
										);
									} else {
										echo '<span class="description">&mdash;</span>';
									}
									?>
								</td>
								<td class="column-schema">
									<?php if ( ! empty( $input_schema ) || ! empty( $output_schema ) || ! empty( $annotations ) || ! empty( $raw_data ) ) : ?>
										<button type="button" class="button button-small abilities-audit-schema-toggle" data-target="schema-<?php echo esc_attr( sanitize_title( $name ) ); ?>">
											<?php esc_html_e( 'View', 'abilities-audit' ); ?>
										</button>
									<?php else : ?>
										<span class="description">&mdash;</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php if ( ! empty( $input_schema ) || ! empty( $output_schema ) || ! empty( $annotations ) || ! empty( $raw_data ) ) : ?>
								<tr class="abilities-audit-schema-row" id="schema-<?php echo esc_attr( sanitize_title( $name ) ); ?>" style="display:none;">
									<td colspan="7" style="padding:12px 20px;background:#f9f9f9;">
									<?php
									$raw_json = wp_json_encode( $raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
									if ( false === $raw_json ) {
										$raw_json = '{}';
									}
									?>
										<div class="abilities-audit-schema-section abilities-audit-schema-section--raw">
											<strong><?php esc_html_e( 'Raw Data', 'abilities-audit' ); ?></strong>
											<pre style="margin:4px 0 12px;white-space:pre-wrap;"><?php echo esc_html( $raw_json ); ?></pre>
										</div>
										<?php if ( ! empty( $annotations ) ) : ?>
											<div class="abilities-audit-schema-section">
												<strong><?php esc_html_e( 'Annotations', 'abilities-audit' ); ?></strong>
												<pre style="margin:4px 0 12px;white-space:pre-wrap;"><?php echo esc_html( wp_json_encode( $annotations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
											</div>
										<?php endif; ?>
										<?php if ( ! empty( $input_schema ) ) : ?>
											<div class="abilities-audit-schema-section">
												<strong><?php esc_html_e( 'Input Schema', 'abilities-audit' ); ?></strong>
												<pre style="margin:4px 0 12px;white-space:pre-wrap;"><?php echo esc_html( wp_json_encode( $input_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
											</div>
										<?php endif; ?>
										<?php if ( ! empty( $output_schema ) ) : ?>
											<div class="abilities-audit-schema-section">
												<strong><?php esc_html_e( 'Output Schema', 'abilities-audit' ); ?></strong>
												<pre style="margin:4px 0 12px;white-space:pre-wrap;"><?php echo esc_html( wp_json_encode( $output_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
											</div>
										<?php endif; ?>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
					<?php
					$total = count( $abilities );
					$off   = count( array_intersect( $disabled, array_keys( $abilities ) ) );
					$on    = $total - $off;
					?>
					<tfoot>
						<tr>
							<th
								colspan="7"
								id="abilities-audit-summary"
								data-total="<?php echo esc_attr( (string) $total ); ?>"
								data-on="<?php echo esc_attr( (string) $on ); ?>"
								data-off="<?php echo esc_attr( (string) $off ); ?>"
							>
								<?php
								printf(
									/* translators: 1: total count, 2: enabled count, 3: disabled count */
									esc_html__( '%1$d abilities registered. %2$d enabled, %3$d disabled.', 'abilities-audit' ),
									(int) $total,
									(int) $on,
									(int) $off
								);
								?>
							</th>
						</tr>
					</tfoot>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	//  AJAX toggle handler
	// ------------------------------------------------------------------

	/**
	 * Handle the AJAX request to enable or disable an ability.
	 */
	public function ajax_toggle() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'abilities-audit' ) ) );
		}

		$ability = isset( $_POST['ability'] ) ? sanitize_text_field( wp_unslash( $_POST['ability'] ) ) : '';

		if ( empty( $ability ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing ability name.', 'abilities-audit' ) ) );
		}

		check_ajax_referer( 'abilities_audit_toggle_' . $ability );

		$disabled = get_option( self::OPTION_DISABLED, array() );
		if ( ! is_array( $disabled ) ) {
			$disabled = array();
		}

		$is_currently_disabled = in_array( $ability, $disabled, true );

		if ( $is_currently_disabled ) {
			// Re-enable: remove from disabled list.
			$disabled  = array_values( array_diff( $disabled, array( $ability ) ) );
			$new_state = 'enabled';
		} else {
			// Disable: add to disabled list.
			$disabled[] = $ability;
			$disabled   = array_values( array_unique( $disabled ) );
			$new_state  = 'disabled';
		}

		update_option( self::OPTION_DISABLED, $disabled, true );

		$schema_target_id = sanitize_title( $ability );
		$input_schema     = array();
		$output_schema    = array();
		$annotations      = array();
		$raw_data         = array();
		$description      = '';

		// Snapshot still holds WP_Ability objects captured before unregister; use it when disabled so
		// description/schema/flags stay available in the UI (registry has no entry after disable).
		$ability_obj = isset( $this->abilities_snapshot[ $ability ] ) ? $this->abilities_snapshot[ $ability ] : null;
		if ( 'enabled' === $new_state && ! $ability_obj instanceof \WP_Ability && function_exists( 'wp_get_abilities' ) ) {
			$registry    = wp_get_abilities();
			$ability_obj = is_array( $registry ) && isset( $registry[ $ability ] ) ? $registry[ $ability ] : null;
		}

		if ( $ability_obj instanceof \WP_Ability ) {
			$raw_data      = $this->build_ability_raw_data( $ability, $ability_obj );
			$description   = $raw_data['description'];
			$input_schema  = $raw_data['input_schema'];
			$output_schema = $raw_data['output_schema'];
			$meta          = $raw_data['meta'];
			$annotations   = method_exists( $ability_obj, 'get_annotations' ) ? $ability_obj->get_annotations() : array();
			if ( ! is_array( $annotations ) ) {
				$annotations = array();
			}
		} elseif ( 'disabled' === $new_state ) {
			$description = __( 'This ability is disabled and not registered in this request. Turn it on to restore it.', 'abilities-audit' );
		}

		$has_schema = ! empty( $input_schema ) || ! empty( $output_schema ) || ! empty( $annotations ) || ! empty( $raw_data );

		$flags_html = '<span class="description">&mdash;</span>';
		if ( $ability_obj instanceof \WP_Ability ) {
			$flags_html = wp_kses(
				$this->render_ability_flags_html( $meta ),
				$this->flags_kses_allowed_html()
			);
		}

		wp_send_json_success(
			array(
				'ability'          => $ability,
				'state'            => $new_state,
				'description'      => $description,
				'flags_html'       => $flags_html,
				'has_schema'       => $has_schema,
				'schema_target_id' => $schema_target_id,
				'schema'           => array(
					'input_schema'  => $input_schema,
					'output_schema' => $output_schema,
					'annotations'   => $annotations,
					'raw_data'      => $raw_data,
				),
			)
		);
	}
}

/**
 * Bootstrap the plugin.
 *
 * Runs on plugins_loaded so the Abilities API functions are guaranteed
 * to be available before we try to hook into them.
 */
add_action( 'plugins_loaded', array( 'Abilities_Audit', 'get_instance' ) );
