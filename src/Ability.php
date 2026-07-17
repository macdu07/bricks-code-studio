<?php
namespace BricksCodeStudio;

final class Ability {
	public static function run( string $name, array $input = [] ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return Support::error( 'bcs_abilities_unavailable', __( 'The WordPress Abilities API is unavailable.', 'bricks-code-studio' ), 503 );
		}

		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			return Support::error( 'bcs_ability_unavailable', sprintf( __( 'Required Bricks ability is unavailable: %s', 'bricks-code-studio' ), $name ), 503 );
		}

		return $ability->execute( $input );
	}
}

