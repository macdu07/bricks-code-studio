<?php
namespace BricksCodeStudio;

final class DesignSync {
	private $workspace;

	public function __construct( Workspace $workspace ) { $this->workspace = $workspace; }

	public function preview( int $post_id, string $css ) {
		if ( strlen( $css ) > Support::MAX_CONVERSION_BYTES ) {
			return Support::error( 'bcs_css_too_large', __( 'CSS conversion is limited to 2 MB.', 'bricks-code-studio' ), 413 );
		}
		if ( trim( (string) preg_replace( '#/\*.*?\*/#s', '', $css ) ) === '' ) {
			$payload = [
				'classes' => [], 'variables' => [], 'elementsToUpdate' => [],
				'generatedElements' => [], 'warnings' => [], 'conflicts' => [], 'linkCandidates' => [],
			];
			$payload['previewHash'] = Support::canonical_json_hash( $payload );
			return $payload;
		}
		$context = Ability::run( 'bricks/get-design-context', [ 'responseFormat' => 'summary' ] );
		if ( is_wp_error( $context ) ) { return $context; }
		$conversion = Ability::run(
			'bricks/convert-html-css-to-bricks-data',
			[
				'css' => $css,
				'postId' => $post_id,
				'options' => [ 'create_global_classes' => true, 'extract_variables' => true ],
			]
		);
		if ( is_wp_error( $conversion ) ) { return $conversion; }

		$manifest = $this->workspace->read_manifest( 'global' );
		$owned = $manifest['ownedDesignResources'] ?? [ 'classes' => [], 'variables' => [] ];
		$linked = $manifest['linkedDesignResources'] ?? [ 'classes' => [], 'variables' => [] ];
		$managed = [
			'classes' => array_values( array_unique( array_merge( $owned['classes'] ?? [], $linked['classes'] ?? [] ) ) ),
			'variables' => array_values( array_unique( array_merge( $owned['variables'] ?? [], $linked['variables'] ?? [] ) ) ),
		];
		$classes = get_option( defined( 'BRICKS_DB_GLOBAL_CLASSES' ) ? BRICKS_DB_GLOBAL_CLASSES : 'bricks_global_classes', [] );
		$variables = get_option( defined( 'BRICKS_DB_GLOBAL_VARIABLES' ) ? BRICKS_DB_GLOBAL_VARIABLES : 'bricks_global_variables', [] );
		$class_names = [];
		foreach ( is_array( $classes ) ? $classes : [] as $item ) { $class_names[ $item['name'] ?? '' ] = $item; }
		$variable_names = [];
		foreach ( is_array( $variables ) ? $variables : [] as $item ) { $variable_names[ $item['name'] ?? '' ] = $item; }
		$conflicts = [];
		$link_candidates = [];
		foreach ( $conversion['global_classes'] ?? [] as $item ) {
			$name = $item['name'] ?? '';
			$id = $class_names[ $name ]['id'] ?? '';
			if ( $id && ! in_array( $id, $managed['classes'], true ) ) {
				$link_candidates[] = [ 'type' => 'class', 'name' => $name, 'id' => $id ];
			}
		}
		foreach ( $conversion['global_variables'] ?? [] as $item ) {
			$name = $item['name'] ?? '';
			$id = $variable_names[ $name ]['id'] ?? '';
			if ( $id && ! in_array( $id, $managed['variables'], true ) ) {
				$link_candidates[] = [ 'type' => 'variable', 'name' => $name, 'id' => $id ];
			}
		}

		$payload = [
			'classes' => $conversion['global_classes'] ?? [],
			'variables' => $conversion['global_variables'] ?? [],
			'elementsToUpdate' => $conversion['elements_to_update'] ?? [],
			'generatedElements' => $conversion['generated_elements'] ?? [],
			'warnings' => $conversion['warnings'] ?? [],
			'conflicts' => $conflicts,
			'linkCandidates' => $link_candidates,
		];
		$payload['previewHash'] = Support::canonical_json_hash( $this->stable_preview_payload( $payload ) );
		return $payload;
	}

	public function apply( int $post_id, string $css, string $preview_hash, bool $confirmed, bool $link_existing = false ) {
		if ( ! $confirmed ) { return Support::error( 'bcs_confirmation_required', __( 'Confirm the design resource preview before applying it.', 'bricks-code-studio' ), 409 ); }
		$preview = $this->preview( $post_id, $css );
		if ( is_wp_error( $preview ) ) { return $preview; }
		if ( ! $preview_hash || ! hash_equals( $preview['previewHash'], $preview_hash ) ) {
			return Support::error( 'bcs_stale_design_preview', __( 'The design preview changed. Review it again before applying.', 'bricks-code-studio' ), 409, [ 'previewHash' => $preview['previewHash'] ] );
		}
		if ( ! empty( $preview['conflicts'] ) ) {
			return Support::error( 'bcs_design_conflicts', __( 'Resolve class or variable name conflicts before synchronizing.', 'bricks-code-studio' ), 409, [ 'conflicts' => $preview['conflicts'] ] );
		}
		if ( ! empty( $preview['linkCandidates'] ) && ! $link_existing ) {
			return Support::error( 'bcs_design_link_confirmation_required', __( 'Confirm that Code Studio may link to and update the existing Bricks resources.', 'bricks-code-studio' ), 409, [ 'linkCandidates' => $preview['linkCandidates'] ] );
		}

		$backup_id = $this->backup();
		$manifest = $this->workspace->read_manifest( 'global' );
		$owned = $manifest['ownedDesignResources'] ?? [ 'classes' => [], 'variables' => [] ];
		$linked = $manifest['linkedDesignResources'] ?? [ 'classes' => [], 'variables' => [] ];
		$link_meta = $manifest['linkedDesignResourceMeta'] ?? [ 'classes' => [], 'variables' => [] ];
		$existing_classes = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
		$by_name = [];
		foreach ( is_array( $existing_classes ) ? $existing_classes : [] as $item ) { $by_name[ $item['name'] ?? '' ] = $item; }
		$created = [ 'classes' => [], 'variables' => [] ];
		$newly_linked = [ 'classes' => [], 'variables' => [] ];

		foreach ( $preview['classes'] as $class ) {
			$name = $class['name'] ?? '';
			$existing = $by_name[ $name ] ?? null;
			$existing_id = is_array( $existing ) ? ( $existing['id'] ?? '' ) : '';
			$is_managed = $existing_id && ( in_array( $existing_id, $owned['classes'] ?? [], true ) || in_array( $existing_id, $linked['classes'] ?? [], true ) );
			if ( $existing_id && ( $is_managed || $link_existing ) ) {
				if ( ! $is_managed ) {
					$linked['classes'][] = $existing_id;
					$newly_linked['classes'][] = $existing_id;
					$link_meta['classes'][ $existing_id ] = $this->link_metadata( $name, $existing );
				}
				$result = Ability::run(
					'bricks/update-global-class',
					[
						'classId' => $existing_id,
						// Bricks deep-merges settings. Send deletion sentinels first so stale
						// values (for example color.raw) cannot override the converted CSS.
						'settings' => $this->replacement_settings_overlay( $existing['settings'] ?? [], $class['settings'] ?? [] ),
						'selectors' => $class['selectors'] ?? [],
					]
				);
			} else {
				$result = Ability::run( 'bricks/create-global-class', [ 'name' => $name, 'settings' => $class['settings'] ?? [], 'selectors' => $class['selectors'] ?? [] ] );
			}
			if ( is_wp_error( $result ) ) { return $result; }
			$id = $result['class']['id'] ?? $existing_id;
			if ( $id ) {
				if ( ! $existing_id ) { $owned['classes'][] = $id; }
				$created['classes'][] = $id;
			}
		}

		$existing_variables = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
		$var_by_name = [];
		$merged_variables = is_array( $existing_variables ) ? array_values( $existing_variables ) : [];
		foreach ( $merged_variables as $index => $item ) {
			$var_by_name[ $item['name'] ?? '' ] = [ 'index' => $index, 'item' => $item ];
		}
		$sync_names = [];
		$new_variable_names = [];
		foreach ( $preview['variables'] as $variable ) {
			$row = [ 'name' => $variable['name'] ?? '', 'value' => $variable['value'] ?? '', 'category' => $variable['category'] ?? '' ];
			if ( isset( $var_by_name[ $row['name'] ] ) ) {
				$existing = $var_by_name[ $row['name'] ]['item'];
				$existing_id = $existing['id'] ?? '';
				$is_managed = $existing_id && ( in_array( $existing_id, $owned['variables'] ?? [], true ) || in_array( $existing_id, $linked['variables'] ?? [], true ) );
				if ( $is_managed || $link_existing ) {
					if ( ! $is_managed && $existing_id ) {
						$linked['variables'][] = $existing_id;
						$newly_linked['variables'][] = $existing_id;
						$link_meta['variables'][ $existing_id ] = $this->link_metadata( $row['name'], $existing );
					}
					$row = array_merge( $existing, $row, [ 'id' => $existing['id'] ] );
					$merged_variables[ $var_by_name[ $row['name'] ]['index'] ] = $row;
				}
			} else {
				$merged_variables[] = $row;
				$new_variable_names[] = $row['name'];
			}
			$sync_names[] = $row['name'];
		}
		if ( $sync_names ) {
			// This ability replaces the collection, so submit the preserved full set.
			$result = Ability::run( 'bricks/set-global-variables', [ 'variables' => $merged_variables ] );
			if ( is_wp_error( $result ) ) { return $result; }
			$saved_by_name = [];
			foreach ( $result['variables'] ?? [] as $item ) { $saved_by_name[ $item['name'] ?? '' ] = $item['id'] ?? ''; }
			foreach ( $sync_names as $name ) {
				if ( ! empty( $saved_by_name[ $name ] ) ) {
					if ( in_array( $name, $new_variable_names, true ) ) { $owned['variables'][] = $saved_by_name[ $name ]; }
					$created['variables'][] = $saved_by_name[ $name ];
				}
			}
		}

		$manifest['ownedDesignResources'] = [ 'classes' => array_values( array_unique( $owned['classes'] ?? [] ) ), 'variables' => array_values( array_unique( $owned['variables'] ?? [] ) ) ];
		$manifest['linkedDesignResources'] = [ 'classes' => array_values( array_unique( $linked['classes'] ?? [] ) ), 'variables' => array_values( array_unique( $linked['variables'] ?? [] ) ) ];
		$manifest['linkedDesignResourceMeta'] = $link_meta;
		$this->workspace->write_manifest( 'global', 0, $manifest );
		return [
			'backupId' => $backup_id,
			'synced' => $created,
			'linked' => $newly_linked,
			'elementSuggestions' => $preview['elementsToUpdate'],
			'designState' => $this->current_design_state(),
		];
	}

	public function restore( string $backup_id, bool $confirmed ) {
		if ( ! $confirmed ) { return Support::error( 'bcs_confirmation_required', __( 'Confirm the global design restore.', 'bricks-code-studio' ), 409 ); }
		$backups = get_option( 'bcs_design_backups', [] );
		if ( empty( $backups[ $backup_id ] ) ) { return Support::error( 'bcs_backup_not_found', __( 'Design backup not found.', 'bricks-code-studio' ), 404 ); }
		$backup = $backups[ $backup_id ];
		if ( class_exists( '\\Bricks\\Helpers' ) ) { \Bricks\Helpers::save_global_classes_in_db( $backup['classes'] ); }
		else { update_option( BRICKS_DB_GLOBAL_CLASSES, $backup['classes'], false ); }
		update_option( BRICKS_DB_GLOBAL_VARIABLES, $backup['variables'], false );
		return [ 'restored' => true, 'backupId' => $backup_id ];
	}

	private function backup(): string {
		$id = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
		$backups = get_option( 'bcs_design_backups', [] );
		$backups[ $id ] = [ 'created' => time(), 'userId' => get_current_user_id(), 'classes' => get_option( BRICKS_DB_GLOBAL_CLASSES, [] ), 'variables' => get_option( BRICKS_DB_GLOBAL_VARIABLES, [] ) ];
		if ( count( $backups ) > 10 ) { $backups = array_slice( $backups, -10, null, true ); }
		update_option( 'bcs_design_backups', $backups, false );
		return $id;
	}

	private function link_metadata( string $name, array $existing ): array {
		return [
			'name' => $name,
			'linkedAt' => time(),
			'linkedBy' => get_current_user_id(),
			'baselineHash' => Support::canonical_json_hash( $existing ),
		];
	}

	private function current_design_state(): array {
		return [
			'globalClasses' => array_values( (array) get_option( BRICKS_DB_GLOBAL_CLASSES, [] ) ),
			'globalVariables' => array_values( (array) get_option( BRICKS_DB_GLOBAL_VARIABLES, [] ) ),
			'globalClassesTimestamp' => (int) get_option( BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP, 0 ),
		];
	}

	private function stable_preview_payload( array $payload ): array {
		$stable = [
			'classes' => [],
			'variables' => [],
			'warnings' => $payload['warnings'] ?? [],
			'conflicts' => $payload['conflicts'] ?? [],
			'linkCandidates' => $payload['linkCandidates'] ?? [],
		];
		foreach ( $payload['classes'] ?? [] as $item ) {
			unset( $item['id'] ); // Bricks assigns a new temporary ID on every CSS conversion.
			$stable['classes'][] = $item;
		}
		foreach ( $payload['variables'] ?? [] as $item ) {
			unset( $item['id'] );
			$stable['variables'][] = $item;
		}
		return $stable;
	}

	private function replacement_settings_overlay( array $existing, array $replacement ): array {
		$overlay = [];
		foreach ( $existing as $key => $value ) {
			$overlay[ $key ] = is_array( $value ) && ! wp_is_numeric_array( $value )
				? $this->replacement_settings_overlay( $value, [] )
				: null;
		}
		return array_replace_recursive( $overlay, $replacement );
	}
}
