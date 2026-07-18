<?php
namespace BricksCodeStudio;

final class Workspace {
	private $base_dir;
	private $base_url;

	public function __construct() {
		$uploads        = wp_upload_dir();
		$site           = is_multisite() ? (string) get_current_blog_id() : '1';
		$this->base_dir = trailingslashit( $uploads['basedir'] ) . 'bricks-code-studio/' . $site;
		$this->base_url = trailingslashit( $uploads['baseurl'] ) . 'bricks-code-studio/' . $site;
	}

	public function ensure_scope( string $scope, int $post_id = 0 ) {
		$root = $this->scope_dir( $scope, $post_id );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		foreach ( [ $root, $root . '/scss', $root . '/css', $root . '/js', $this->base_dir . '/dist' ] as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return Support::error( 'bcs_workspace_unwritable', __( 'The Code Studio workspace is not writable.', 'bricks-code-studio' ), 500 );
			}
		}

		$defaults = [
			$root . '/scss/main.scss' => "/* Bricks Code Studio */\n",
			$root . '/js/main.js'     => "// Bricks Code Studio\n",
		];
		foreach ( $defaults as $path => $content ) {
			if ( ! file_exists( $path ) ) {
				$this->atomic_write( $path, $content );
			}
		}

		$manifest_path = $root . '/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			$this->write_manifest(
				$scope,
				$post_id,
				[
					'version' => 1,
					'entries' => [ 'scss/main.scss', 'js/main.js' ],
					'build' => [ 'cssOutput' => 'expanded', 'sourceMaps' => true ],
					'ownedDesignResources' => [ 'classes' => [], 'variables' => [] ],
					'linkedDesignResources' => [ 'classes' => [], 'variables' => [] ],
					'linkedDesignResourceMeta' => [ 'classes' => [], 'variables' => [] ],
				]
			);
		}

		return $root;
	}

	public function scope_dir( string $scope, int $post_id = 0 ) {
		if ( $scope === 'global' ) {
			return $this->base_dir . '/src/global';
		}
		if ( $scope === 'document' && $post_id > 0 ) {
			return $this->base_dir . '/src/documents/' . $post_id;
		}
		return Support::error( 'bcs_invalid_scope', __( 'Choose global or a valid document scope.', 'bricks-code-studio' ) );
	}

	public function list_files( string $scope, int $post_id = 0 ) {
		$root = $this->ensure_scope( $scope, $post_id );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$files = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() || basename( $file->getPathname() ) === 'manifest.json' ) {
				continue;
			}
			$relative = ltrim( str_replace( wp_normalize_path( $root ), '', wp_normalize_path( $file->getPathname() ) ), '/' );
			if ( $this->is_allowed_file( $relative ) ) {
				$files[] = [
					'path'        => $relative,
					'size'        => $file->getSize(),
					'modified'    => $file->getMTime(),
					'contentHash' => hash_file( 'sha256', $file->getPathname() ),
				];
			}
		}
		usort( $files, static function ( $a, $b ) { return strnatcasecmp( $a['path'], $b['path'] ); } );
		$manifest = $this->reconcile_entries( $scope, $post_id, array_column( $files, 'path' ) );
		return [ 'files' => $files, 'manifest' => $manifest ];
	}

	public function read_file( string $scope, int $post_id, string $relative ) {
		$path = $this->resolve_file( $scope, $post_id, $relative, false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! is_file( $path ) ) {
			return Support::error( 'bcs_file_not_found', __( 'File not found.', 'bricks-code-studio' ), 404 );
		}
		$content = file_get_contents( $path );
		if ( false === $content ) {
			return Support::error( 'bcs_file_unreadable', __( 'The file could not be read.', 'bricks-code-studio' ), 500 );
		}
		return [ 'path' => $relative, 'content' => $content, 'contentHash' => hash( 'sha256', $content ) ];
	}

	public function write_file( string $scope, int $post_id, string $relative, string $content, string $expected_hash = '' ) {
		if ( strlen( $content ) > Support::MAX_FILE_BYTES ) {
			return Support::error( 'bcs_file_too_large', __( 'Files are limited to 1 MB.', 'bricks-code-studio' ), 413 );
		}
		$path = $this->resolve_file( $scope, $post_id, $relative, true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( is_file( $path ) && $expected_hash && ! hash_equals( $expected_hash, (string) hash_file( 'sha256', $path ) ) ) {
			return Support::error( 'bcs_edit_conflict', __( 'The file changed after it was opened. Reload it before saving.', 'bricks-code-studio' ), 409 );
		}
		if ( ! wp_mkdir_p( dirname( $path ) ) || ! $this->atomic_write( $path, $content ) ) {
			return Support::error( 'bcs_file_write_failed', __( 'The file could not be saved.', 'bricks-code-studio' ), 500 );
		}
		$this->register_entry( $scope, $post_id, $relative );
		return [ 'path' => $relative, 'contentHash' => hash( 'sha256', $content ) ];
	}

	public function delete_file( string $scope, int $post_id, string $relative ) {
		$path = $this->resolve_file( $scope, $post_id, $relative, false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! is_file( $path ) ) {
			return Support::error( 'bcs_file_not_found', __( 'File not found.', 'bricks-code-studio' ), 404 );
		}
		if ( ! unlink( $path ) ) {
			return Support::error( 'bcs_file_delete_failed', __( 'The file could not be deleted.', 'bricks-code-studio' ), 500 );
		}
		$this->remove_entry( $scope, $post_id, $relative );
		return [ 'deleted' => $relative ];
	}

	public function move_file( string $scope, int $post_id, string $from, string $to ) {
		$source = $this->resolve_file( $scope, $post_id, $from, false );
		$target = $this->resolve_file( $scope, $post_id, $to, true );
		if ( is_wp_error( $source ) ) { return $source; }
		if ( is_wp_error( $target ) ) { return $target; }
		if ( ! is_file( $source ) || file_exists( $target ) ) {
			return Support::error( 'bcs_move_conflict', __( 'The source is missing or the destination already exists.', 'bricks-code-studio' ), 409 );
		}
		if ( ! wp_mkdir_p( dirname( $target ) ) || ! rename( $source, $target ) ) {
			return Support::error( 'bcs_move_failed', __( 'The file could not be moved.', 'bricks-code-studio' ), 500 );
		}
		$this->remove_entry( $scope, $post_id, $from );
		$this->register_entry( $scope, $post_id, $to );
		return [ 'from' => $from, 'path' => $to ];
	}

	public function read_manifest( string $scope, int $post_id = 0 ): array {
		$root = $this->scope_dir( $scope, $post_id );
		if ( is_wp_error( $root ) ) { return []; }
		$data = is_file( $root . '/manifest.json' ) ? json_decode( (string) file_get_contents( $root . '/manifest.json' ), true ) : [];
		return is_array( $data ) ? $data : [];
	}

	public function write_manifest( string $scope, int $post_id, array $manifest ): bool {
		$root = $this->scope_dir( $scope, $post_id );
		if ( is_wp_error( $root ) ) { return false; }
		wp_mkdir_p( $root );
		return $this->atomic_write( $root . '/manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	public function update_build_settings( string $scope, int $post_id, string $css_output, bool $source_maps ) {
		$root = $this->ensure_scope( $scope, $post_id );
		if ( is_wp_error( $root ) ) { return $root; }
		$manifest = $this->read_manifest( $scope, $post_id );
		$manifest['build'] = [
			'cssOutput' => in_array( $css_output, [ 'expanded', 'compressed' ], true ) ? $css_output : 'expanded',
			'sourceMaps' => $source_maps,
		];
		if ( ! $this->write_manifest( $scope, $post_id, $manifest ) ) {
			return Support::error( 'bcs_manifest_write_failed', __( 'The workspace build settings could not be saved.', 'bricks-code-studio' ), 500 );
		}
		return [ 'build' => $manifest['build'], 'manifest' => $manifest ];
	}

	public function dist_dir(): string { return $this->base_dir . '/dist'; }
	public function dist_url(): string { return $this->base_url . '/dist'; }

	private function resolve_file( string $scope, int $post_id, string $relative, bool $allow_new ) {
		$root = $this->ensure_scope( $scope, $post_id );
		if ( is_wp_error( $root ) ) { return $root; }
		$relative = ltrim( wp_normalize_path( $relative ), '/' );
		if ( ! $this->is_allowed_file( $relative ) || strpos( $relative, '..' ) !== false || strpos( $relative, "\0" ) !== false ) {
			return Support::error( 'bcs_invalid_path', __( 'Only safe SCSS, CSS, and JavaScript paths are allowed.', 'bricks-code-studio' ) );
		}
		$path = wp_normalize_path( $root . '/' . $relative );
		if ( strpos( $path, wp_normalize_path( $root ) . '/' ) !== 0 ) {
			return Support::error( 'bcs_path_escape', __( 'The requested path escapes the workspace.', 'bricks-code-studio' ) );
		}
		$cursor = wp_normalize_path( $root );
		$parts = explode( '/', $relative );
		array_pop( $parts );
		foreach ( $parts as $part ) {
			$cursor .= '/' . $part;
			if ( is_link( $cursor ) ) {
				return Support::error( 'bcs_symlink_rejected', __( 'Symbolic links are not allowed.', 'bricks-code-studio' ) );
			}
		}
		if ( is_link( $path ) || ( ! $allow_new && file_exists( $path ) && is_link( $path ) ) ) {
			return Support::error( 'bcs_symlink_rejected', __( 'Symbolic links are not allowed.', 'bricks-code-studio' ) );
		}
		return $path;
	}

	private function is_allowed_file( string $relative ): bool {
		return (bool) preg_match( '/\.(scss|css|js)$/i', $relative ) && ! preg_match( '#(^|/)[.]#', $relative );
	}

	private function is_entry_file( string $relative ): bool {
		return $this->is_allowed_file( $relative ) && strpos( basename( $relative ), '_' ) !== 0;
	}

	private function reconcile_entries( string $scope, int $post_id, array $files ): array {
		$manifest = $this->read_manifest( $scope, $post_id );
		$changed = false;
		if ( ! isset( $manifest['build'] ) || ! is_array( $manifest['build'] ) ) {
			$manifest['build'] = [ 'cssOutput' => 'expanded', 'sourceMaps' => true ];
			$changed = true;
		} else {
			$normalized_build = [
				'cssOutput' => in_array( $manifest['build']['cssOutput'] ?? '', [ 'expanded', 'compressed' ], true ) ? $manifest['build']['cssOutput'] : 'expanded',
				'sourceMaps' => array_key_exists( 'sourceMaps', $manifest['build'] ) ? (bool) $manifest['build']['sourceMaps'] : true,
			];
			if ( $manifest['build'] !== $normalized_build ) { $manifest['build'] = $normalized_build; $changed = true; }
		}
		$entries = [];
		foreach ( $files as $file ) {
			if ( $this->is_entry_file( $file ) ) { $entries[] = $file; }
		}
		$entries = array_values( array_unique( $entries ) );
		sort( $entries, SORT_NATURAL | SORT_FLAG_CASE );
		if ( ( $manifest['entries'] ?? [] ) !== $entries ) {
			$manifest['entries'] = $entries;
			$changed = true;
		}
		if ( $changed ) { $this->write_manifest( $scope, $post_id, $manifest ); }
		return $manifest;
	}

	private function register_entry( string $scope, int $post_id, string $relative ): void {
		if ( ! $this->is_entry_file( $relative ) ) { return; }
		$manifest = $this->read_manifest( $scope, $post_id );
		$entries = is_array( $manifest['entries'] ?? null ) ? $manifest['entries'] : [];
		if ( ! in_array( $relative, $entries, true ) ) {
			$entries[] = $relative;
			sort( $entries, SORT_NATURAL | SORT_FLAG_CASE );
			$manifest['entries'] = array_values( $entries );
			$this->write_manifest( $scope, $post_id, $manifest );
		}
	}

	private function remove_entry( string $scope, int $post_id, string $relative ): void {
		$manifest = $this->read_manifest( $scope, $post_id );
		$entries = array_values( array_filter( $manifest['entries'] ?? [], static function ( $entry ) use ( $relative ) { return $entry !== $relative; } ) );
		if ( ( $manifest['entries'] ?? [] ) !== $entries ) {
			$manifest['entries'] = $entries;
			$this->write_manifest( $scope, $post_id, $manifest );
		}
	}

	private function atomic_write( string $path, string $content ): bool {
		$tmp = $path . '.tmp-' . wp_generate_password( 8, false, false );
		if ( false === file_put_contents( $tmp, $content, LOCK_EX ) ) {
			return false;
		}
		if ( ! rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return false;
		}
		return true;
	}
}
