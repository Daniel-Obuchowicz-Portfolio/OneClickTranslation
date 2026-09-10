<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value ); } }
if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( mixed $data ): bool {
		if ( ! is_string( $data ) ) {
			return false;
		} $data = trim( $data );
		return $data === 'N;' || (bool) preg_match( '/^[aObisCd]:/', $data ); }
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( mixed $value ): mixed {
		return is_serialized( $value ) ? @unserialize( trim( $value ) ) : $value; } }
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) ); } }
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $value ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ); } }
