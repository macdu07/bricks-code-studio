<?php
use BricksCodeStudio\Compiler;
use BricksCodeStudio\DesignSync;
use BricksCodeStudio\Support;
use BricksCodeStudio\Workspace;
use PHPUnit\Framework\TestCase;

final class WorkspaceTest extends TestCase {
	private $workspace;
	private $post_id = 999991;

	protected function setUp(): void {
		$this->workspace = new Workspace();
		$this->workspace->ensure_scope( 'document', $this->post_id );
		$this->workspace->update_build_settings( 'document', $this->post_id, 'expanded', true );
	}

	public function test_random_bricks_ids_are_valid(): void {
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{6}$/', Support::random_id() );
	}

	public function test_workspace_rejects_path_traversal_and_php(): void {
		$this->assertTrue( is_wp_error( $this->workspace->write_file( 'document', $this->post_id, '../escape.scss', 'x{}' ) ) );
		$this->assertTrue( is_wp_error( $this->workspace->write_file( 'document', $this->post_id, 'php/evil.php', '<?php' ) ) );
	}

	public function test_file_round_trip_and_optimistic_lock(): void {
		$created = $this->workspace->write_file( 'document', $this->post_id, 'scss/test.scss', '$color: red; .test { color: $color; }' );
		$this->assertFalse( is_wp_error( $created ) );
		$read = $this->workspace->read_file( 'document', $this->post_id, 'scss/test.scss' );
		$this->assertSame( $created['contentHash'], $read['contentHash'] );
		$conflict = $this->workspace->write_file( 'document', $this->post_id, 'scss/test.scss', 'changed', str_repeat( '0', 64 ) );
		$this->assertSame( 'bcs_edit_conflict', $conflict->get_error_code() );
		$this->assertFalse( is_wp_error( $this->workspace->delete_file( 'document', $this->post_id, 'scss/test.scss' ) ) );
	}

	public function test_scss_compiles_without_publishing(): void {
		$this->workspace->write_file( 'document', $this->post_id, 'scss/main.scss', '$gap: 12px; .grid { gap: $gap; }' );
		$result = ( new Compiler( $this->workspace ) )->compile( 'document', $this->post_id, false );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertEmpty( array_filter( $result['diagnostics'], static function ( $item ) { return $item['severity'] === 'error'; } ) );
		$this->assertStringContainsString( '.grid', $result['css'] );
		$this->assertStringContainsString( 'gap: 12px;', $result['css'] );
		$this->assertArrayHasKey( 'scss/main.scss', $result['sourceMaps'] );
		$this->assertSame( 3, json_decode( $result['sourceMaps']['scss/main.scss'], true )['version'] );
	}

	public function test_plain_css_is_an_independent_publishable_entry(): void {
		$this->workspace->write_file( 'document', $this->post_id, 'css/plain.css', '.plain-css { display: grid; }' );
		$result = ( new Compiler( $this->workspace ) )->compile( 'document', $this->post_id, false );
		$this->assertStringContainsString( '/* css/plain.css */', $result['css'] );
		$this->assertStringContainsString( '.plain-css { display: grid; }', $result['css'] );
		$this->assertContains( 'css/plain.css', $this->workspace->list_files( 'document', $this->post_id )['manifest']['entries'] );
		$this->workspace->delete_file( 'document', $this->post_id, 'css/plain.css' );
	}

	public function test_build_settings_are_stored_in_the_workspace_manifest(): void {
		$result = $this->workspace->update_build_settings( 'document', $this->post_id, 'compressed', false );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( [ 'cssOutput' => 'compressed', 'sourceMaps' => false ], $result['build'] );
		$this->assertSame( $result['build'], $this->workspace->list_files( 'document', $this->post_id )['manifest']['build'] );
	}

	public function test_compressed_build_only_minifies_the_published_asset(): void {
		$this->workspace->write_file( 'document', $this->post_id, 'css/minified.css', ".minified {\n  color: red;\n}\n" );
		$this->workspace->update_build_settings( 'document', $this->post_id, 'compressed', false );
		$compiler = new Compiler( $this->workspace );
		$result = $compiler->compile( 'document', $this->post_id, true );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertStringContainsString( ".minified {\n  color: red;", $result['css'] );
		$this->assertNotEmpty( $result['publishedAssets']['css'] );
		$this->assertSame( '', $result['publishedAssets']['sourceMap'] );
		$published = $compiler->published_output( 'document', $this->post_id );
		$this->assertStringContainsString( '.minified{color:red}', $published['css'] );
		$this->assertStringNotContainsString( 'sourceMappingURL=', $published['css'] );
		$this->workspace->update_build_settings( 'document', $this->post_id, 'compressed', true );
		$with_map = $compiler->compile( 'document', $this->post_id, true );
		$this->assertNotEmpty( $with_map['publishedAssets']['sourceMap'] );
		$this->assertStringContainsString( 'sourceMappingURL=', $compiler->published_output( 'document', $this->post_id )['css'] );
		$this->workspace->delete_file( 'document', $this->post_id, 'css/minified.css' );
	}

	public function test_published_css_can_be_restored_after_a_new_request(): void {
		$this->workspace->write_file( 'document', $this->post_id, 'scss/persisted.scss', '.persisted-view { color: rebeccapurple; }' );
		$compiler = new Compiler( $this->workspace );
		$published = $compiler->compile( 'document', $this->post_id, true );
		$this->assertFalse( is_wp_error( $published ) );
		$restored = ( new Compiler( new Workspace() ) )->published_output( 'document', $this->post_id );
		$this->assertTrue( $restored['available'] );
		$this->assertStringContainsString( '.persisted-view', $restored['css'] );
		$this->workspace->delete_file( 'document', $this->post_id, 'scss/persisted.scss' );
		$assets = get_option( 'bcs_published_assets', [ 'global' => [], 'documents' => [] ] );
		unset( $assets['documents'][ (string) $this->post_id ] );
		update_option( 'bcs_published_assets', $assets, false );
		foreach ( glob( $this->workspace->dist_dir() . '/document-' . $this->post_id . '-*' ) ?: [] as $file ) { @unlink( $file ); }
	}

	public function test_non_partial_stylesheets_are_registered_as_entries(): void {
		$this->workspace->write_file( 'document', $this->post_id, 'scss/cards.scss', '.card { display: grid; }' );
		$this->workspace->write_file( 'document', $this->post_id, 'scss/_tokens.scss', '$space: 1rem;' );
		$listing = $this->workspace->list_files( 'document', $this->post_id );
		$this->assertContains( 'scss/cards.scss', $listing['manifest']['entries'] );
		$this->assertNotContains( 'scss/_tokens.scss', $listing['manifest']['entries'] );
		$this->workspace->delete_file( 'document', $this->post_id, 'scss/cards.scss' );
		$this->workspace->delete_file( 'document', $this->post_id, 'scss/_tokens.scss' );
	}

	public function test_unsaved_partial_is_used_for_live_preview_without_persisting_it(): void {
		$this->workspace->write_file( 'document', $this->post_id, 'scss/main.scss', "@import 'tokens';\n.preview { gap: \$gap; }" );
		$this->workspace->write_file( 'document', $this->post_id, 'scss/_tokens.scss', '$gap: 12px;' );
		$result = ( new Compiler( $this->workspace ) )->compile( 'document', $this->post_id, false, 'scss/_tokens.scss', '$gap: 24px;' );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertStringContainsString( 'gap: 24px;', $result['css'] );
		$stored = $this->workspace->read_file( 'document', $this->post_id, 'scss/_tokens.scss' );
		$this->assertSame( '$gap: 12px;', $stored['content'] );
		$this->workspace->write_file( 'document', $this->post_id, 'scss/main.scss', "/* Bricks Code Studio */\n" );
		$this->workspace->delete_file( 'document', $this->post_id, 'scss/_tokens.scss' );
	}

	public function test_empty_css_design_sync_is_a_noop(): void {
		$result = ( new DesignSync( $this->workspace ) )->preview( 10, "/* no rules */\n" );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( [], $result['classes'] );
		$this->assertSame( [], $result['variables'] );
		$this->assertSame( [], $result['linkCandidates'] );
	}

	public function test_design_sync_replacement_overlay_removes_stale_nested_values(): void {
		$sync = new DesignSync( $this->workspace );
		$method = new ReflectionMethod( DesignSync::class, 'replacement_settings_overlay' );
		$method->setAccessible( true );
		$overlay = $method->invoke(
			$sync,
			[ '_typography' => [ 'color' => [ 'raw' => 'red', 'hex' => '#ff0000' ], 'font-weight' => 400 ], '_margin' => [ 'top' => '1rem' ] ],
			[ '_typography' => [ 'color' => [ 'hex' => '#ffffff' ], 'font-weight' => 600 ] ]
		);
		$this->assertNull( $overlay['_typography']['color']['raw'] );
		$this->assertSame( '#ffffff', $overlay['_typography']['color']['hex'] );
		$this->assertNull( $overlay['_margin']['top'] );
	}

	public function test_new_manifest_tracks_owned_and_linked_design_resources_separately(): void {
		$post_id = 999992;
		$this->workspace->ensure_scope( 'document', $post_id );
		$manifest = $this->workspace->read_manifest( 'document', $post_id );
		$this->assertSame( [ 'classes' => [], 'variables' => [] ], $manifest['ownedDesignResources'] );
		$this->assertSame( [ 'classes' => [], 'variables' => [] ], $manifest['linkedDesignResources'] );
	}
}
