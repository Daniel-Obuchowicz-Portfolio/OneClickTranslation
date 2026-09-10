<?php

declare(strict_types=1);

namespace OneClickTranslation\Support;

final class Arr {

	/** @param array<string|int, mixed> $array */
	public static function get( array $array, string $path, mixed $default = null ): mixed {
		if ( $path === '' ) {
			return $array;
		}
		$value = $array;
		foreach ( explode( '.', $path ) as $segment ) {
			$segment = rawurldecode( $segment );
			$key     = ctype_digit( $segment ) ? (int) $segment : $segment;
			if ( is_object( $value ) ) {
				if ( ! property_exists( $value, (string) $key ) ) {
					return $default; }
				$value = $value->{ (string) $key};
			} else {
				if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
					return $default; }
				$value = $value[ $key ];
			}
		}
		return $value;
	}

	/** @param array<string|int, mixed> $array */
	public static function set( array &$array, string $path, mixed $value ): void {
		$segments = explode( '.', $path );
		$cursor   =& $array;
		foreach ( $segments as $index => $segment ) {
			$segment = rawurldecode( $segment );
			$key     = ctype_digit( $segment ) ? (int) $segment : $segment;
			if ( is_object( $cursor ) ) {
				$property = (string) $key;
				if ( $index === count( $segments ) - 1 ) {
					$cursor->{$property} = $value;
					return; }
				if ( ! property_exists( $cursor, $property ) || ( ! is_array( $cursor->{$property} ) && ! is_object( $cursor->{$property} ) ) ) {
					$cursor->{$property} = array(); }
				$cursor =& $cursor->{$property};
			} else {
				if ( $index === count( $segments ) - 1 ) {
					$cursor[ $key ] = $value;
					return; }
				if ( ! isset( $cursor[ $key ] ) || ( ! is_array( $cursor[ $key ] ) && ! is_object( $cursor[ $key ] ) ) ) {
					$cursor[ $key ] = array(); }
				$cursor =& $cursor[ $key ];
			}
		}
	}

	/** @return array<string, mixed> */
	public static function flatten( mixed $value, string $prefix = '' ): array {
		$result = array();
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			if ( $prefix !== '' ) {
				$result[ $prefix ] = $value;
			}
			return $result;
		}
		foreach ( $value as $key => $item ) {
			$segment = self::encodeSegment( (string) $key );
			$path    = $prefix === '' ? $segment : $prefix . '.' . $segment;
			if ( is_array( $item ) || is_object( $item ) ) {
				$result += self::flatten( $item, $path );
			} else {
				$result[ $path ] = $item;
			}
		}
		return $result;
	}

	public static function encodeSegment( string $segment ): string {
		return str_replace( '.', '%2E', rawurlencode( $segment ) );
	}

	public static function canonicalize( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}
}
