<?php
namespace BricksCodeStudio;

final class Support {
	const MAX_FILE_BYTES       = 1048576;
	const MAX_CONVERSION_BYTES = 2097152;

	public static function is_supported(): bool {
		$bricks_version = defined( 'BRICKS_VERSION' ) ? preg_replace( '/-.*/', '', (string) BRICKS_VERSION ) : '0';
		return version_compare( $bricks_version, '2.4', '>=' ) && function_exists( 'wp_get_ability' );
	}

	public static function can_use( int $post_id = 0 ): bool {
		if ( ! self::is_supported() || ! class_exists( '\\Bricks\\Capabilities' ) ) {
			return false;
		}

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		return \Bricks\Capabilities::current_user_can_use_builder( $post_id ?: null )
			&& \Bricks\Capabilities::current_user_can_execute_code();
	}

	public static function error( string $code, string $message, int $status = 400, array $data = [] ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( [ 'status' => $status ], $data ) );
	}

	public static function canonical_json_hash( $value ): string {
		$value = self::sort_recursive( $value );
		return hash( 'sha256', (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_recursive( $item );
		}
		return $value;
	}

	public static function random_id(): string {
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$id    = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$id .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
		}
		return $id;
	}
}
