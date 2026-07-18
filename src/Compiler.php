<?php
namespace BricksCodeStudio;

final class Compiler {
	private $workspace;

	public function __construct( Workspace $workspace ) {
		$this->workspace = $workspace;
	}

	public function compile( string $scope, int $post_id = 0, bool $publish = false, ?string $draft_path = null, ?string $draft_content = null ) {
		$root = $this->workspace->ensure_scope( $scope, $post_id );
		if ( is_wp_error( $root ) ) { return $root; }

		$listing = $this->workspace->list_files( $scope, $post_id );
		if ( is_wp_error( $listing ) ) { return $listing; }
		$manifest = $listing['manifest'];
		$entries  = isset( $manifest['entries'] ) && is_array( $manifest['entries'] ) ? $manifest['entries'] : [ 'scss/main.scss', 'js/main.js' ];
		$build = is_array( $manifest['build'] ?? null ) ? $manifest['build'] : [ 'cssOutput' => 'expanded', 'sourceMaps' => true ];
		$css_output = ( $build['cssOutput'] ?? 'expanded' ) === 'compressed' ? 'compressed' : 'expanded';
		$source_maps_enabled = array_key_exists( 'sourceMaps', $build ) ? (bool) $build['sourceMaps'] : true;
		$compile_root = $root;
		$preview_root = null;
		if ( null !== $draft_content && $draft_path && preg_match( '/\.scss$/i', $draft_path ) ) {
			$preview_root = $this->create_preview_workspace( $scope, $post_id, $listing['files'] ?? [], $draft_path, $draft_content );
			if ( is_wp_error( $preview_root ) ) { return $preview_root; }
			$compile_root = $preview_root;
		}
		$css      = '';
		$js       = '';
		$source_maps = [];
		$diagnostics = [];

		foreach ( $entries as $entry ) {
			$entry = ltrim( wp_normalize_path( (string) $entry ), '/' );
			if ( ! preg_match( '/\.(scss|css|js)$/i', $entry ) ) { continue; }
			$read = $this->workspace->read_file( $scope, $post_id, $entry );
			if ( is_wp_error( $read ) ) {
				$diagnostics[] = [ 'severity' => 'error', 'path' => $entry, 'message' => $read->get_error_message() ];
				continue;
			}
			$content = ( $draft_path === $entry && null !== $draft_content ) ? $draft_content : $read['content'];
			if ( strlen( $content ) > Support::MAX_FILE_BYTES ) {
				$diagnostics[] = [ 'severity' => 'error', 'path' => $entry, 'message' => __( 'Entry exceeds the 1 MB limit.', 'bricks-code-studio' ) ];
				continue;
			}

			$extension = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( $extension === 'js' ) {
				$js .= "\n/* {$entry} */\n" . $content . "\n";
			} elseif ( $extension === 'css' ) {
				$css .= "\n/* {$entry} */\n" . $content . "\n";
			} else {
				$result = $this->compile_scss( $content, $compile_root, $entry, $source_maps_enabled );
				if ( is_wp_error( $result ) ) {
					$data = $result->get_error_data();
					$diagnostics[] = array_merge( [ 'severity' => 'error', 'path' => $entry, 'message' => $result->get_error_message() ], is_array( $data ) ? $data : [] );
				} else {
					$css .= "\n/* {$entry} */\n" . $result['css'] . "\n";
					if ( ! empty( $result['sourceMap'] ) ) {
						$source_maps[ $entry ] = $result['sourceMap'];
					}
				}
			}
		}

		$has_errors = (bool) array_filter( $diagnostics, static function ( $item ) { return ( $item['severity'] ?? '' ) === 'error'; } );
		$response = [
			'css'         => $css,
			'javascript'  => $js,
			'sourceMaps'  => $source_maps,
			'contentHash' => hash( 'sha256', $css . "\0" . $js ),
			'diagnostics' => array_values( $diagnostics ),
			'publishedAssets' => null,
			'build' => [ 'cssOutput' => $css_output, 'sourceMaps' => $source_maps_enabled ],
		];

		if ( $publish && ! $has_errors ) {
			$published_css = $css;
			$published_maps = $source_maps_enabled ? $source_maps : [];
			if ( $css_output === 'compressed' && trim( $css ) !== '' ) {
				$compressed = $this->compress_css( $css, $root, $source_maps_enabled );
				if ( is_wp_error( $compressed ) ) {
					$response['diagnostics'][] = [ 'severity' => 'error', 'path' => 'dist', 'message' => $compressed->get_error_message() ];
				} else {
					$published_css = $compressed['css'];
					$published_maps = ! empty( $compressed['sourceMap'] ) ? [ 'bundle.css' => $compressed['sourceMap'] ] : [];
				}
			}
			if ( ! array_filter( $response['diagnostics'], static function ( $item ) { return ( $item['severity'] ?? '' ) === 'error'; } ) ) {
				$response['publishedAssets'] = $this->publish( $scope, $post_id, $published_css, $js, $published_maps );
			}
		}
		if ( $preview_root ) { $this->remove_preview_workspace( $preview_root ); }

		return $response;
	}

	public function published_output( string $scope, int $post_id = 0 ): array {
		$published = get_option( 'bcs_published_assets', [ 'global' => [], 'documents' => [] ] );
		$assets = $scope === 'global'
			? ( $published['global'] ?? [] )
			: ( $published['documents'][ (string) $post_id ] ?? [] );
		$key = $scope === 'global' ? 'global' : 'document-' . $post_id;
		$hash = sanitize_key( (string) ( $assets['hash'] ?? '' ) );
		$css = $this->read_published_asset( (string) ( $assets['css'] ?? '' ), $key, $hash, 'css' );
		$javascript = $this->read_published_asset( (string) ( $assets['js'] ?? '' ), $key, $hash, 'js' );
		return [
			'available' => $css !== '' || $javascript !== '',
			'css' => $css,
			'javascript' => $javascript,
			'contentHash' => $hash,
			'publishedAssets' => is_array( $assets ) ? $assets : [],
		];
	}

	private function create_preview_workspace( string $scope, int $post_id, array $files, string $draft_path, string $draft_content ) {
		$root = trailingslashit( get_temp_dir() ) . 'bcs-preview-' . wp_generate_uuid4();
		if ( ! wp_mkdir_p( $root ) ) {
			return Support::error( 'bcs_preview_workspace_failed', __( 'Could not prepare the in-memory SCSS preview.', 'bricks-code-studio' ), 500 );
		}
		foreach ( $files as $file ) {
			$relative = ltrim( wp_normalize_path( (string) ( $file['path'] ?? '' ) ), '/' );
			if ( ! preg_match( '/\.(scss|css)$/i', $relative ) ) { continue; }
			$read = $this->workspace->read_file( $scope, $post_id, $relative );
			if ( is_wp_error( $read ) ) {
				$this->remove_preview_workspace( $root );
				return $read;
			}
			$content = $relative === ltrim( wp_normalize_path( $draft_path ), '/' ) ? $draft_content : $read['content'];
			$target = $root . '/' . $relative;
			if ( ! wp_mkdir_p( dirname( $target ) ) || false === file_put_contents( $target, $content, LOCK_EX ) ) {
				$this->remove_preview_workspace( $root );
				return Support::error( 'bcs_preview_workspace_failed', __( 'Could not prepare the in-memory SCSS preview.', 'bricks-code-studio' ), 500 );
			}
		}
		return $root;
	}

	private function remove_preview_workspace( string $root ): void {
		if ( ! is_dir( $root ) ) { return; }
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $root );
	}

	private function compile_scss( string $source, string $root, string $entry, bool $source_maps = true ) {
		if ( ! class_exists( '\\ScssPhp\\ScssPhp\\Compiler' ) ) {
			return Support::error( 'bcs_scss_compiler_missing', __( 'The bundled SCSS compiler is not installed. Run Composer install for Bricks Code Studio.', 'bricks-code-studio' ), 503 );
		}

		try {
			$compiler = new \ScssPhp\ScssPhp\Compiler();
			$compiler->setImportPaths( [ $root, $root . '/scss' ] );
			if ( $source_maps ) {
				$compiler->setSourceMap( \ScssPhp\ScssPhp\Compiler::SOURCE_MAP_FILE );
				$compiler->setSourceMapOptions( [
					'sourceMapBasepath' => $root,
					'sourceMapRootpath' => '',
					'sourceMapURL'      => null,
					'outputSourceFiles' => true,
				] );
			}
			if ( class_exists( '\\ScssPhp\\ScssPhp\\OutputStyle' ) && method_exists( $compiler, 'setOutputStyle' ) ) {
				// Bricks' CSS converter expects declaration delimiters that compressed Sass may omit.
				$compiler->setOutputStyle( \ScssPhp\ScssPhp\OutputStyle::EXPANDED );
			}
			$result = $compiler->compileString( $source, $root . '/' . $entry );
			return [ 'css' => $result->getCss(), 'sourceMap' => $result->getSourceMap() ];
		} catch ( \Throwable $error ) {
			$line = method_exists( $error, 'getSassLine' ) ? (int) $error->getSassLine() : (int) $error->getLine();
			return Support::error( 'bcs_scss_compile_error', $error->getMessage(), 422, [ 'line' => max( 1, $line ) ] );
		}
	}

	private function compress_css( string $css, string $root, bool $source_maps ) {
		try {
			$compiler = new \ScssPhp\ScssPhp\Compiler();
			if ( class_exists( '\\ScssPhp\\ScssPhp\\OutputStyle' ) && method_exists( $compiler, 'setOutputStyle' ) ) {
				$compiler->setOutputStyle( \ScssPhp\ScssPhp\OutputStyle::COMPRESSED );
			}
			if ( $source_maps ) {
				$compiler->setSourceMap( \ScssPhp\ScssPhp\Compiler::SOURCE_MAP_FILE );
				$compiler->setSourceMapOptions( [
					'sourceMapBasepath' => $root,
					'sourceMapRootpath' => '',
					'sourceMapURL' => null,
					'outputSourceFiles' => true,
				] );
			}
			$result = $compiler->compileString( $css, $root . '/bundle.css' );
			return [ 'css' => $result->getCss(), 'sourceMap' => $source_maps ? $result->getSourceMap() : null ];
		} catch ( \Throwable $error ) {
			return Support::error( 'bcs_css_minify_error', $error->getMessage(), 422 );
		}
	}

	private function publish( string $scope, int $post_id, string $css, string $js, array $source_maps = [] ): array {
		$dist = $this->workspace->dist_dir();
		wp_mkdir_p( $dist );
		$key  = $scope === 'global' ? 'global' : 'document-' . $post_id;
		$hash = substr( hash( 'sha256', $css . "\0" . $js ), 0, 12 );
		$assets = [ 'hash' => $hash, 'css' => '', 'js' => '', 'sourceMap' => '' ];

		if ( trim( $css ) !== '' ) {
			$name = $key . '-' . $hash . '.css';
			if ( $source_maps ) {
				$map_name = $name . '.map';
				$map = count( $source_maps ) === 1 ? reset( $source_maps ) : wp_json_encode( [
					'version' => 3,
					'file' => $name,
					'sources' => array_keys( $source_maps ),
					'names' => [],
					'mappings' => '',
				] );
				$this->atomic_write( $dist . '/' . $map_name, (string) $map );
				$css .= "\n/*# sourceMappingURL={$map_name} */\n";
				$assets['sourceMap'] = $this->workspace->dist_url() . '/' . $map_name;
			}
			$this->atomic_write( $dist . '/' . $name, $css );
			$assets['css'] = $this->workspace->dist_url() . '/' . $name;
		}
		if ( trim( $js ) !== '' ) {
			$name = $key . '-' . $hash . '.js';
			$this->atomic_write( $dist . '/' . $name, $js );
			$assets['js'] = $this->workspace->dist_url() . '/' . $name;
		}

		$published = get_option( 'bcs_published_assets', [ 'global' => [], 'documents' => [] ] );
		if ( ! is_array( $published ) ) { $published = [ 'global' => [], 'documents' => [] ]; }
		if ( $scope === 'global' ) {
			$published['global'] = $assets;
		} else {
			$published['documents'][ (string) $post_id ] = $assets;
		}
		update_option( 'bcs_published_assets', $published, false );
		$this->cleanup( $key, $hash );
		return $assets;
	}

	private function atomic_write( string $path, string $content ): void {
		$tmp = $path . '.tmp-' . wp_generate_password( 8, false, false );
		if ( false === file_put_contents( $tmp, $content, LOCK_EX ) || ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			throw new \RuntimeException( __( 'Could not publish the compiled asset atomically.', 'bricks-code-studio' ) );
		}
	}

	private function read_published_asset( string $url, string $key, string $hash, string $extension ): string {
		if ( ! $url || ! $hash ) { return ''; }
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$filename = basename( $path );
		$expected = $key . '-' . $hash . '.' . $extension;
		if ( $filename !== $expected ) { return ''; }
		$file = wp_normalize_path( $this->workspace->dist_dir() . '/' . $filename );
		$dist = trailingslashit( wp_normalize_path( $this->workspace->dist_dir() ) );
		if ( strpos( $file, $dist ) !== 0 || ! is_file( $file ) || is_link( $file ) ) { return ''; }
		$content = file_get_contents( $file );
		return false === $content ? '' : $content;
	}

	private function cleanup( string $key, string $current_hash ): void {
		foreach ( glob( $this->workspace->dist_dir() . '/' . $key . '-*.*' ) ?: [] as $file ) {
			if ( strpos( basename( $file ), '-' . $current_hash . '.' ) === false && is_file( $file ) ) {
				@unlink( $file );
			}
		}
	}
}
