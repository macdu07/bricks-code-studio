<?php
namespace BricksCodeStudio;

final class Plugin {
	private $workspace;

	public function boot(): void {
		$this->workspace = new Workspace();
		$compiler        = new Compiler( $this->workspace );
		$structure       = new StructureService();
		$design          = new DesignSync( $this->workspace );
		$rest            = new RestController( $this->workspace, $compiler, $structure, $design );

		add_action( 'rest_api_init', [ $rest, 'register' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_builder' ], 100 );
		// The mount point must exist before WordPress prints footer scripts (priority 20).
		add_action( 'wp_footer', [ $this, 'render_root' ], 1 );
		add_action( 'wp_enqueue_scripts', [ new FrontendAssets(), 'enqueue' ], 30 );
		add_action( 'admin_notices', [ $this, 'admin_notice' ] );
		add_action( 'admin_init', [ $this, 'enforce_compatibility' ], 1 );
	}

	public function enforce_compatibility(): void {
		if ( Support::is_supported() || ! current_user_can( 'activate_plugins' ) ) { return; }
		deactivate_plugins( plugin_basename( BCS_FILE ), true );
	}

	public function enqueue_builder(): void {
		if ( ! Support::is_supported() ) { return; }
		$post_id = (int) get_the_ID();
		$channel = wp_create_nonce( 'bcs-bridge-' . $post_id );

		if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() && Support::can_use( $post_id ) ) {
			wp_enqueue_style( 'bcs-builder', BCS_URL . 'assets/dist/app.css', [ 'bricks-builder' ], $this->asset_version( 'assets/dist/app.css' ) );
			wp_enqueue_script( 'bcs-builder', BCS_URL . 'assets/dist/app.js', [ 'bricks-builder' ], $this->asset_version( 'assets/dist/app.js' ), true );
			wp_localize_script(
				'bcs-builder',
				'BCS_BOOT',
				[
					'restUrl' => esc_url_raw( rest_url( 'bricks-code-studio/v1' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'postId' => $post_id,
					'channel' => $channel,
					'origin' => wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ),
					'version' => BCS_VERSION,
					'bricksVersion' => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '',
				]
			);
		}

		if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() && Support::can_use( $post_id ) ) {
			wp_enqueue_script( 'bcs-bridge', BCS_URL . 'assets/dist/bridge.js', [ 'bricks-builder' ], $this->asset_version( 'assets/dist/bridge.js' ), true );
			wp_localize_script( 'bcs-bridge', 'BCS_BRIDGE', [ 'channel' => $channel, 'origin' => home_url() ] );
		}
	}

	public function render_root(): void {
		if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() && Support::can_use( (int) get_the_ID() ) ) {
			echo '<div id="bricks-code-studio-root" aria-live="polite"></div>';
		}
	}

	public function admin_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) || Support::is_supported() ) { return; }
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Bricks Code Studio was deactivated because it requires an active Bricks theme version 2.4+ and WordPress 6.9+.', 'bricks-code-studio' ) . '</p></div>';
	}

	private function asset_version( string $relative ): string {
		$modified = @filemtime( BCS_PATH . ltrim( $relative, '/' ) );
		return BCS_VERSION . ( $modified ? '.' . $modified : '' );
	}
}
