<?php
namespace BricksCodeStudio;

final class FrontendAssets {
	public function enqueue(): void {
		if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
			return;
		}
		$published = get_option( 'bcs_published_assets', [] );
		if ( ! is_array( $published ) ) { return; }

		$this->enqueue_set( 'global', $published['global'] ?? [], [] );
		$ids = [ (int) get_queried_object_id() ];
		if ( class_exists( '\\Bricks\\Database' ) && isset( \Bricks\Database::$active_templates ) && is_array( \Bricks\Database::$active_templates ) ) {
			array_walk_recursive( \Bricks\Database::$active_templates, static function ( $id ) use ( &$ids ) { if ( is_numeric( $id ) ) { $ids[] = (int) $id; } } );
		}
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$this->enqueue_set( 'document-' . $id, $published['documents'][ (string) $id ] ?? [], [ 'bcs-global' ] );
		}
	}

	private function enqueue_set( string $key, array $assets, array $style_dependencies ): void {
		if ( ! empty( $assets['css'] ) ) {
			wp_enqueue_style( 'bcs-' . $key, esc_url_raw( $assets['css'] ), $style_dependencies, $assets['hash'] ?? BCS_VERSION );
		}
		if ( ! empty( $assets['js'] ) ) {
			wp_enqueue_script( 'bcs-' . $key, esc_url_raw( $assets['js'] ), [], $assets['hash'] ?? BCS_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		}
	}
}

