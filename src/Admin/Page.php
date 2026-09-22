<?php

declare(strict_types=1);

namespace RomCommerce\Admin;

use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Plugin;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the single custom RomCommerce admin page: a branded navy sidebar
 * rail (Dashboard / General / Modules[category[module]] / License / Status &
 * Tools) beside a paper canvas. The modules screen is a card grid whose
 * toggles enable/disable each live Lite module in place; for any module id, if
 * a real module is registered and implements HasSettingsUi its live pane
 * renders; otherwise a Pro id falls back to a locked teaser and a not-yet-built
 * Lite id falls back to a plain "not built yet" placeholder — Lite ids are
 * never shown as a Pro upsell, since that would misrepresent what's missing.
 */
final class Page {

	private const PRO_URL = 'https://romcommerce.ro/pro';

	/** Transient success message shown once at the top of the canvas. */
	private string $notice = '';

	public function render(): void {
		$this->process_request();

		list( $tab, $category, $module ) = $this->state();

		echo '<div class="wrap romcommerce-admin">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'RomCommerce', 'romcommerce' ) . '</h1>';
		echo '<div class="rc-app" data-rc-app>';

		$this->render_rail( $tab, $category, $module );

		echo '<main class="rc-canvas" tabindex="-1">';
		$this->render_content( $tab, $category, $module );
		echo '</main>';

		echo '</div></div>';
	}

	/**
	 * Rail + canvas HTML for the current request, for the AJAX pane endpoint.
	 * Runs the exact same request handling and rendering as render() — a module
	 * toggle or a settings-form POST is applied here too — so the AJAX path and
	 * a full-page reload can never diverge.
	 *
	 * @return array{rail: string, canvas: string, url: string}
	 */
	public function fragments(): array {
		$this->process_request();

		list( $tab, $category, $module ) = $this->state();

		return array(
			'rail'   => $this->rail_html( $tab, $category, $module ),
			'canvas' => $this->canvas_html( $tab, $category, $module ),
			'url'    => $this->url( $this->nav_args( $tab, $category, $module ) ),
		);
	}

	/**
	 * State-changing work that must run before rendering. Only the module-grid
	 * toggle lives here; settings-form saves run inside render_content() via
	 * each pane's own nonce-verified handler.
	 */
	private function process_request(): void {
		$this->handle_toggle();
	}

	/** @return array{0: string, 1: string, 2: string} tab, category, module */
	private function state(): array {
		return array(
			$this->query_arg( 'tab', 'dashboard' ),
			$this->query_arg( 'category', '' ),
			$this->query_arg( 'module', '' ),
		);
	}

	private function rail_html( string $tab, string $category, string $module ): string {
		ob_start();
		$this->render_rail( $tab, $category, $module );

		return (string) ob_get_clean();
	}

	private function canvas_html( string $tab, string $category, string $module ): string {
		ob_start();
		$this->render_content( $tab, $category, $module );

		return (string) ob_get_clean();
	}

	/** @return array<string, string> Non-empty nav args, for the client's pushState URL. */
	private function nav_args( string $tab, string $category, string $module ): array {
		$args = array( 'tab' => $tab );

		if ( '' !== $category ) {
			$args['category'] = $category;
		}
		if ( '' !== $module ) {
			$args['module'] = $module;
		}

		return $args;
	}

	/**
	 * Flip a single live Lite module's enabled flag. The persistence
	 * (Settings::set_enabled) already exists; this is the grid toggle's write
	 * path, gated by the same capability and its own nonce.
	 */
	private function handle_toggle(): void {
		if ( ! isset( $_POST['romcommerce_toggle'], $_POST['romcommerce_toggle_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['romcommerce_toggle_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'romcommerce_toggle_modules' ) ) {
			return;
		}

		$module_id = sanitize_key( wp_unslash( $_POST['romcommerce_toggle'] ) );
		$meta      = ModuleRegistry::get( $module_id );
		if ( null === $meta || 'lite' !== $meta['tier'] ) {
			return;
		}

		// Only a module that is actually registered can be toggled — a not-yet-built
		// Lite id has no boot path to enable.
		if ( null === Plugin::instance()->modules()->get( $module_id ) ) {
			return;
		}

		$enable = isset( $_POST['romcommerce_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['romcommerce_enabled'] ) );
		Settings::set_enabled( $module_id, $enable );

		$this->notice = $enable
			? __( 'Module enabled.', 'romcommerce' )
			: __( 'Module disabled.', 'romcommerce' );
	}

	private function render_rail( string $tab, string $category, string $module ): void {
		echo '<nav class="rc-rail">';
		echo '<div class="rc-wordmark"><img src="' . esc_url( $this->asset( 'logo-wordmark.svg' ) ) . '" alt="RomCommerce"></div>';
		echo '<ul class="rc-nav">';

		$this->rail_item( 'dashboard', __( 'Dashboard', 'romcommerce' ), 'dashboard' === $tab );
		$this->rail_item( 'general', __( 'General', 'romcommerce' ), 'general' === $tab );

		$modules_open = 'modules' === $tab;
		echo '<li class="' . ( $modules_open ? 'is-open' : '' ) . '">';
		echo '<a href="' . esc_url( $this->url( array( 'tab' => 'modules' ) ) ) . '">' . esc_html__( 'Modules', 'romcommerce' ) . ' <span class="rc-chev" aria-hidden="true">&#9662;</span></a>';
		if ( $modules_open ) {
			echo '<ul class="rc-cats">';
			foreach ( ModuleRegistry::CATEGORIES as $cat_id => $cat_label ) {
				$this->rail_category( $cat_id, $cat_label, $category, $module );
			}
			echo '</ul>';
		}
		echo '</li>';

		$this->rail_item( 'license', __( 'License', 'romcommerce' ), 'license' === $tab, true );
		$this->rail_item( 'status', __( 'Status & Tools', 'romcommerce' ), 'status' === $tab );

		echo '</ul>';

		$version = defined( 'ROMCOMMERCE_VERSION' ) ? ROMCOMMERCE_VERSION : '';
		echo '<div class="rc-rail-foot">' . esc_html(
			sprintf(
				/* translators: %s: plugin version number */
				__( 'RomCommerce Lite %s', 'romcommerce' ),
				$version
			)
		) . '<br>' . esc_html__( 'for WooCommerce', 'romcommerce' ) . '</div>';

		echo '</nav>';
	}

	private function rail_item( string $tab, string $label, bool $active, bool $pro = false ): void {
		echo '<li class="' . ( $active ? 'is-active' : '' ) . '">';
		echo '<a href="' . esc_url( $this->url( array( 'tab' => $tab ) ) ) . '">' . esc_html( $label );
		if ( $pro ) {
			echo ' <span class="rc-mini-pro">PRO</span>';
		}
		echo '</a></li>';
	}

	private function rail_category( string $cat_id, string $cat_label, string $active_category, string $active_module ): void {
		$open = $active_category === $cat_id;
		echo '<li class="rc-cat-' . esc_attr( $cat_id ) . ( $open ? ' is-open' : '' ) . '">';
		echo '<a href="' . esc_url(
			$this->url(
				array(
					'tab'      => 'modules',
					'category' => $cat_id,
				)
			)
		) . '"><span class="rc-tile"></span>' . esc_html( $cat_label ) . '</a>';

		if ( $open ) {
			echo '<ul class="rc-mods">';
			foreach ( ModuleRegistry::in_category( $cat_id ) as $mod_id => $meta ) {
				$this->rail_module( $cat_id, $mod_id, $meta, $active_module );
			}
			echo '</ul>';
		}

		echo '</li>';
	}

	/** @param array{tier: string, category: string, label: string, description: string} $meta */
	private function rail_module( string $cat_id, string $mod_id, array $meta, string $active_module ): void {
		$instance = Plugin::instance()->modules()->get( $mod_id );
		$is_live  = null !== $instance;
		$is_pro   = 'pro' === $meta['tier'];
		$on       = $is_live && $instance->is_enabled();

		echo '<li class="' . ( $active_module === $mod_id ? 'is-active' : '' ) . '">';
		echo '<a href="' . esc_url(
			$this->url(
				array(
					'tab'      => 'modules',
					'category' => $cat_id,
					'module'   => $mod_id,
				)
			)
		) . '">';
		$state = $on ? __( 'enabled', 'romcommerce' ) : __( 'disabled', 'romcommerce' );
		echo '<span class="rc-modname"><span class="rc-dot' . ( $on ? '' : ' is-off' ) . '" title="' . esc_attr( $state ) . '"></span>' . esc_html( $meta['label'] ) . '<span class="screen-reader-text"> (' . esc_html( $state ) . ')</span></span>';
		if ( $is_pro && ! $is_live ) {
			echo '<span class="rc-mini-pro">PRO</span>';
		}
		echo '</a></li>';
	}

	private function render_content( string $tab, string $category, string $module ): void {
		switch ( $tab ) {
			case 'general':
				$this->render_general();
				return;
			case 'modules':
				$this->render_modules_content( $category, $module );
				return;
			case 'license':
				$this->render_license();
				return;
			case 'status':
				$this->render_status();
				return;
			default:
				$this->render_dashboard();
		}
	}

	private function render_dashboard(): void {
		$stats = $this->module_stats();

		$this->canvas_head( __( 'Dashboard', 'romcommerce' ), __( 'Lite: complete & free', 'romcommerce' ) );

		echo '<div class="rc-mission">';
		echo '<img src="' . esc_url( $this->asset( 'logo-mark.svg' ) ) . '" alt="" width="34" height="34">';
		echo '<p><strong>' . esc_html__( 'Reliable, scalable commerce for the Romanian market.', 'romcommerce' ) . '</strong> ';
		echo esc_html__( 'Enable only what your shop needs. Every module is fully functional in Lite: no trial, no usage limit, no upsell logic hidden in the code.', 'romcommerce' ) . '</p>';
		echo '</div>';

		$pct = $stats['total'] > 0 ? (int) round( $stats['enabled'] / $stats['total'] * 100 ) : 0;

		echo '<div class="rc-stats">';

		echo '<div class="rc-stat"><span class="rc-eyebrow">' . esc_html__( 'Modules enabled', 'romcommerce' ) . '</span>';
		echo '<div class="rc-big">' . esc_html( (string) $stats['enabled'] ) . ' <small>' . esc_html(
			sprintf(
				/* translators: %d: total number of Lite modules */
				__( '/ %d Lite modules', 'romcommerce' ),
				$stats['total']
			)
		) . '</small></div>';
		echo '<div class="rc-track"><div class="rc-fill" style="width:' . esc_attr( (string) $pct ) . '%;"></div></div></div>';

		echo '<div class="rc-stat"><span class="rc-eyebrow">' . esc_html__( 'Legal & compliance', 'romcommerce' ) . '</span>';
		echo '<div class="rc-big">' . esc_html( (string) $stats['legal'] ) . ' <small>' . esc_html__( 'active', 'romcommerce' ) . '</small></div></div>';

		echo '<div class="rc-stat rc-upsell"><span class="rc-eyebrow">' . esc_html__( 'RomCommerce Pro', 'romcommerce' ) . '</span>';
		echo '<p>' . esc_html__( 'VIES lookup, address autocomplete, order confirmation and more. One licence, no per-module unlocks.', 'romcommerce' ) . '</p>';
		echo '<a href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( "See what's included", 'romcommerce' ) . '</a></div>';

		echo '</div>';

		echo '<p><a class="rc-unlock" href="' . esc_url( $this->url( array( 'tab' => 'modules' ) ) ) . '">' . esc_html__( 'Manage modules', 'romcommerce' ) . '</a></p>';
	}

	private function render_general(): void {
		if ( SettingsForm::verify( 'general' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_delete_data_on_uninstall( isset( $_POST['romcommerce_delete_data'] ) );
			$this->notice = __( 'Settings saved.', 'romcommerce' );
		}

		$this->canvas_head( __( 'General', 'romcommerce' ) );

		echo '<div class="rc-pane">';
		echo '<form method="post">';
		SettingsForm::nonce_field( 'general' );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Data on uninstall', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="romcommerce_delete_data" value="1"' . checked( Settings::delete_data_on_uninstall(), true, false ) . '> ';
		echo esc_html__( 'Delete all RomCommerce data when the plugin is deleted', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. When enabled, deleting RomCommerce (not merely deactivating it) also removes its settings, the price-history log, and saved delivery addresses. Product reviews are left in place. They are standard WooCommerce reviews. This cannot be undone.', 'romcommerce' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form></div>';
	}

	private function render_modules_content( string $category, string $module_id ): void {
		if ( '' !== $module_id ) {
			$this->render_module_pane( $category, $module_id );
			return;
		}

		$this->canvas_head( __( 'Modules', 'romcommerce' ), __( 'Lite: complete & free', 'romcommerce' ) );

		if ( '' !== $category && isset( ModuleRegistry::CATEGORIES[ $category ] ) ) {
			$this->render_cat_block( $category );
			return;
		}

		foreach ( ModuleRegistry::CATEGORIES as $cat_id => $cat_label ) {
			$this->render_cat_block( $cat_id );
		}
	}

	private function render_cat_block( string $cat_id ): void {
		$label   = ModuleRegistry::CATEGORIES[ $cat_id ];
		$modules = ModuleRegistry::in_category( $cat_id );
		$count   = count( $modules );

		echo '<div class="rc-cat-block rc-cat-' . esc_attr( $cat_id ) . '">';
		echo '<div class="rc-cat-head"><span class="rc-tile"></span><h3>' . esc_html( $label ) . '</h3><div class="rc-rule"></div>';
		echo '<span class="rc-count">' . esc_html(
			sprintf(
				/* translators: %d: number of modules in this category */
				_n( '%d module', '%d modules', $count, 'romcommerce' ),
				$count
			)
		) . '</span></div>';

		echo '<div class="rc-grid">';
		foreach ( $modules as $mod_id => $meta ) {
			$this->module_card( $cat_id, $mod_id, $meta );
		}
		echo '</div></div>';
	}

	/** @param array{tier: string, category: string, label: string, description: string} $meta */
	private function module_card( string $cat_id, string $mod_id, array $meta ): void {
		$instance = Plugin::instance()->modules()->get( $mod_id );
		$is_live  = null !== $instance;
		$is_pro   = 'pro' === $meta['tier'];
		$has_ui   = $instance instanceof HasSettingsUi;
		$pane_url = $this->url(
			array(
				'tab'      => 'modules',
				'category' => $cat_id,
				'module'   => $mod_id,
			)
		);

		$classes = 'rc-card';
		if ( $is_pro && ! $is_live ) {
			$classes .= ' is-locked';
		}

		echo '<div class="' . esc_attr( $classes ) . '">';

		echo '<h4 class="rc-card-title">' . esc_html( $meta['label'] ) . '</h4>';
		echo '<p class="rc-card-desc">' . esc_html( $meta['description'] ) . '</p>';

		echo '<div class="rc-card-actions">';
		if ( $is_live && ! $is_pro ) {
			if ( $has_ui ) {
				echo '<a class="rc-btn" href="' . esc_url( $pane_url ) . '">' . esc_html__( 'Settings', 'romcommerce' ) . '</a>';
			}
			$this->toggle( $mod_id, $instance->is_enabled() );
		} elseif ( $is_pro && ! $is_live ) {
			echo '<span class="rc-pro-badge">' . esc_html__( 'Pro', 'romcommerce' ) . '</span>';
			echo '<a class="rc-unlock" href="' . esc_url( $pane_url ) . '">' . esc_html__( 'Unlock', 'romcommerce' ) . '</a>';
		} else {
			echo '<em class="rc-soon">' . esc_html__( 'Not built yet', 'romcommerce' ) . '</em>';
		}
		echo '</div>';

		echo '</div>';
	}

	private function toggle( string $mod_id, bool $enabled ): void {
		$next  = $enabled ? '0' : '1';
		$label = $enabled ? __( 'Disable module', 'romcommerce' ) : __( 'Enable module', 'romcommerce' );

		echo '<form method="post" class="rc-toggle-form">';
		wp_nonce_field( 'romcommerce_toggle_modules', 'romcommerce_toggle_nonce' );
		echo '<input type="hidden" name="romcommerce_toggle" value="' . esc_attr( $mod_id ) . '">';
		echo '<input type="hidden" name="romcommerce_enabled" value="' . esc_attr( $next ) . '">';
		echo '<button type="submit" class="rc-switch' . ( $enabled ? ' is-on' : '' ) . '" role="switch" aria-checked="' . ( $enabled ? 'true' : 'false' ) . '" aria-label="' . esc_attr( $label ) . '"></button>';
		echo '</form>';
	}

	private function render_module_pane( string $category, string $module_id ): void {
		$meta = ModuleRegistry::get( $module_id );
		if ( null === $meta ) {
			$this->canvas_head( __( 'Modules', 'romcommerce' ) );
			echo '<div class="rc-pane"><p>' . esc_html__( 'Unknown module.', 'romcommerce' ) . '</p></div>';
			return;
		}

		$back = '' !== $category
			? $this->url(
				array(
					'tab'      => 'modules',
					'category' => $category,
				)
			)
			: $this->url( array( 'tab' => 'modules' ) );

		$this->canvas_head( $meta['label'], 'pro' === $meta['tier'] ? __( 'Pro', 'romcommerce' ) : '' );

		echo '<div class="rc-pane rc-cat-' . esc_attr( $meta['category'] ) . '">';
		echo '<a class="rc-back" href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'All modules', 'romcommerce' ) . '</a>';

		$instance = Plugin::instance()->modules()->get( $module_id );
		if ( $instance instanceof HasSettingsUi ) {
			$instance->render_settings();
		} elseif ( 'pro' === $meta['tier'] ) {
			echo '<p>' . esc_html( $meta['description'] ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Unlock with RomCommerce Pro', 'romcommerce' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html( $meta['description'] ) . '</p>';
			echo '<p><em>' . esc_html__( 'This module is not built yet.', 'romcommerce' ) . '</em></p>';
		}

		echo '</div>';
	}

	private function render_license(): void {
		$instance = Plugin::instance()->modules()->get( 'license' );

		$this->canvas_head( __( 'License', 'romcommerce' ) );
		echo '<div class="rc-pane">';

		if ( $instance instanceof HasSettingsUi ) {
			$instance->render_settings();
		} else {
			echo '<p>' . esc_html__( 'Activate your RomCommerce Pro license here once RomCommerce Pro is installed and active.', 'romcommerce' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn about RomCommerce Pro', 'romcommerce' ) . '</a></p>';
		}

		echo '</div>';
	}

	private function render_status(): void {
		$this->canvas_head( __( 'Status & Tools', 'romcommerce' ) );

		echo '<div class="rc-pane">';
		echo '<table class="widefat striped"><tbody>';
		$this->status_row( __( 'RomCommerce version', 'romcommerce' ), defined( 'ROMCOMMERCE_VERSION' ) ? ROMCOMMERCE_VERSION : '-' );
		$this->status_row( __( 'PHP version', 'romcommerce' ), PHP_VERSION );
		$this->status_row( __( 'WooCommerce active', 'romcommerce' ), class_exists( 'WooCommerce' ) ? __( 'Yes', 'romcommerce' ) : __( 'No', 'romcommerce' ) );

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			$this->status_row( __( 'HPOS enabled', 'romcommerce' ), $hpos ? __( 'Yes', 'romcommerce' ) : __( 'No', 'romcommerce' ) );
		}

		echo '</tbody></table></div>';
	}

	private function status_row( string $label, string $value ): void {
		echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( $value ) . '</td></tr>';
	}

	private function canvas_head( string $title, string $pill = '' ): void {
		echo '<div class="rc-head"><h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $pill ) {
			echo '<span class="rc-pill"><span class="rc-pill-dot"></span>' . esc_html( $pill ) . '</span>';
		}
		echo '</div>';

		if ( '' !== $this->notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $this->notice ) . '</p></div>';
			$this->notice = '';
		}
	}

	/** @return array{total: int, enabled: int, legal: int} */
	private function module_stats(): array {
		$total   = 0;
		$enabled = 0;
		$legal   = 0;

		foreach ( Plugin::instance()->modules()->all() as $id => $module ) {
			$meta = ModuleRegistry::get( $id );
			if ( null === $meta || 'lite' !== $meta['tier'] ) {
				continue;
			}

			++$total;
			if ( $module->is_enabled() ) {
				++$enabled;
				if ( 'compliance-legal' === $meta['category'] ) {
					++$legal;
				}
			}
		}

		return array(
			'total'   => $total,
			'enabled' => $enabled,
			'legal'   => $legal,
		);
	}

	private function asset( string $file ): string {
		return plugins_url( 'assets/' . $file, ROMCOMMERCE_FILE );
	}

	private function query_arg( string $key, string $default ): string {
		// $_REQUEST (not just $_GET) so the same resolver serves both the full-page
		// load, where nav state is in the URL query, and the AJAX pane endpoint,
		// where the client posts it as form data.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- read-only nav state (tab/category/module), not a state-changing action; the AJAX path is already nonce-verified in Admin\Ajax::handle() before Page runs.
		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return $default;
		}

		return sanitize_key( wp_unslash( $_REQUEST[ $key ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
	}

	/** @param array<string, string> $args */
	private function url( array $args ): string {
		return add_query_arg( array_merge( array( 'page' => 'romcommerce' ), $args ), admin_url( 'admin.php' ) );
	}
}
