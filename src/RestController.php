<?php
namespace BricksCodeStudio;

final class RestController {
	private $workspace;
	private $compiler;
	private $structure;
	private $design;

	public function __construct( Workspace $workspace, Compiler $compiler, StructureService $structure, DesignSync $design ) {
		$this->workspace = $workspace;
		$this->compiler  = $compiler;
		$this->structure = $structure;
		$this->design    = $design;
	}

	public function register(): void {
		$routes = [
			'/workspace' => [ 'GET', 'workspace' ],
			'/file' => [ [ 'GET', 'file_read' ], [ 'PUT', 'file_write' ], [ 'DELETE', 'file_delete' ] ],
			'/file/move' => [ 'POST', 'file_move' ],
			'/compile' => [ 'POST', 'compile' ],
			'/structure' => [ 'GET', 'structure' ],
			'/structure/preview' => [ 'POST', 'structure_preview' ],
			'/structure/apply' => [ 'POST', 'structure_apply' ],
			'/structure/restore' => [ 'POST', 'structure_restore' ],
			'/design/preview' => [ 'POST', 'design_preview' ],
			'/design/apply' => [ 'POST', 'design_apply' ],
			'/design/restore' => [ 'POST', 'design_restore' ],
			'/preferences' => [ [ 'GET', 'preferences' ], [ 'POST', 'preferences_save' ] ],
		];

		foreach ( $routes as $path => $definition ) {
			$definitions = isset( $definition[0] ) && is_array( $definition[0] ) ? $definition : [ $definition ];
			$args = [];
			foreach ( $definitions as $route ) {
				$args[] = [
					'methods' => $route[0],
					'callback' => [ $this, $route[1] ],
					'permission_callback' => [ $this, 'permission' ],
				];
			}
			register_rest_route( 'bricks-code-studio/v1', $path, $args );
		}
	}

	public function permission( \WP_REST_Request $request ): bool {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		return (bool) $nonce && (bool) wp_verify_nonce( $nonce, 'wp_rest' )
			&& Support::can_use( absint( $request->get_param( 'postId' ) ) );
	}

	private function scope( \WP_REST_Request $request ): array {
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		return [ in_array( $scope, [ 'global', 'document' ], true ) ? $scope : 'global', absint( $request->get_param( 'postId' ) ) ];
	}

	public function workspace( \WP_REST_Request $request ) { [ $scope, $post_id ] = $this->scope( $request ); return $this->respond( $this->workspace->list_files( $scope, $post_id ) ); }
	public function file_read( \WP_REST_Request $request ) { [ $scope, $post_id ] = $this->scope( $request ); return $this->respond( $this->workspace->read_file( $scope, $post_id, sanitize_text_field( (string) $request['path'] ) ) ); }
	public function file_write( \WP_REST_Request $request ) { [ $scope, $post_id ] = $this->scope( $request ); return $this->respond( $this->workspace->write_file( $scope, $post_id, sanitize_text_field( (string) $request['path'] ), (string) $request['content'], sanitize_text_field( (string) $request['expectedHash'] ) ) ); }
	public function file_delete( \WP_REST_Request $request ) { [ $scope, $post_id ] = $this->scope( $request ); return $this->respond( $this->workspace->delete_file( $scope, $post_id, sanitize_text_field( (string) $request['path'] ) ) ); }
	public function file_move( \WP_REST_Request $request ) { [ $scope, $post_id ] = $this->scope( $request ); return $this->respond( $this->workspace->move_file( $scope, $post_id, sanitize_text_field( (string) $request['from'] ), sanitize_text_field( (string) $request['to'] ) ) ); }

	public function compile( \WP_REST_Request $request ) {
		[ $scope, $post_id ] = $this->scope( $request );
		$draft_content = $request->has_param( 'draftContent' ) ? (string) $request['draftContent'] : null;
		return $this->respond( $this->compiler->compile( $scope, $post_id, rest_sanitize_boolean( $request['publish'] ), sanitize_text_field( (string) $request['draftPath'] ) ?: null, $draft_content ) );
	}

	public function structure( \WP_REST_Request $request ) { return $this->respond( $this->structure->projection( absint( $request['postId'] ) ) ); }
	public function structure_preview( \WP_REST_Request $request ) { return $this->respond( $this->structure->preview( absint( $request['postId'] ), (string) $request['html'], sanitize_text_field( (string) $request['treeHash'] ) ) ); }
	public function structure_apply( \WP_REST_Request $request ) { return $this->respond( $this->structure->apply( absint( $request['postId'] ), (string) $request['html'], sanitize_text_field( (string) $request['treeHash'] ), rest_sanitize_boolean( $request['confirmed'] ) ) ); }
	public function structure_restore( \WP_REST_Request $request ) { return $this->respond( $this->structure->restore( absint( $request['postId'] ), absint( $request['revisionId'] ) ) ); }

	public function design_preview( \WP_REST_Request $request ) { return $this->respond( $this->design->preview( absint( $request['postId'] ), (string) $request['css'] ) ); }
	public function design_apply( \WP_REST_Request $request ) { return $this->respond( $this->design->apply( absint( $request['postId'] ), (string) $request['css'], sanitize_text_field( (string) $request['previewHash'] ), rest_sanitize_boolean( $request['confirmed'] ), rest_sanitize_boolean( $request['linkExisting'] ) ) ); }
	public function design_restore( \WP_REST_Request $request ) { return $this->respond( $this->design->restore( sanitize_text_field( (string) $request['backupId'] ), rest_sanitize_boolean( $request['confirmed'] ) ) ); }

	public function preferences() { return rest_ensure_response( get_user_meta( get_current_user_id(), 'bcs_preferences', true ) ?: [ 'height' => 360, 'open' => true, 'scope' => 'global', 'autoSync' => true ] ); }
	public function preferences_save( \WP_REST_Request $request ) {
		$data = [
			'height' => max( 180, min( 900, absint( $request['height'] ) ) ),
			'open' => rest_sanitize_boolean( $request['open'] ),
			'scope' => in_array( $request['scope'], [ 'global', 'document' ], true ) ? $request['scope'] : 'global',
			'activeFile' => sanitize_text_field( (string) $request['activeFile'] ),
			'autoSync' => $request->has_param( 'autoSync' ) ? rest_sanitize_boolean( $request['autoSync'] ) : true,
		];
		update_user_meta( get_current_user_id(), 'bcs_preferences', $data );
		return rest_ensure_response( $data );
	}

	private function respond( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
