<?php

declare(strict_types=1);

namespace OneClickTranslation\Content;

final class ShortcodeProtector {

	/** @var array<string, string> */
	private array $tokens = array();

	public function protect( string $content, ?array $settings = null ): string {
		$this->tokens = array();
		$settings   ??= \OneClickTranslation\Support\Helpers::settings();
		$patterns     = array();
		if ( ! empty( $settings['preserve_shortcodes'] ) ) {
			$patterns[] = '/\[(?:\/?)[a-zA-Z][^\]\r\n]*\]/'; }
		if ( ! empty( $settings['preserve_urls'] ) ) {
			$patterns[] = '~https?://[^\s<>"\']+~i'; }
		if ( ! empty( $settings['preserve_emails'] ) ) {
			$patterns[] = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i'; }
		if ( ! empty( $settings['preserve_placeholders'] ) ) {
			$patterns[] = '/\{\{[^{}]+\}\}|\{[A-Z0-9_.-]+\}|%[A-Z0-9_.-]+%/i'; }
		if ( ! empty( $settings['preserve_code_blocks'] ) ) {
			$patterns[] = '~<(code|pre)\b[^>]*>.*?</\1>~is'; }
		foreach ( $patterns as $pattern ) {
			$content = (string) preg_replace_callback(
				$pattern,
				function ( array $match ): string {
					$token                  = 'OCTPROTECT' . count( $this->tokens ) . 'TOKEN';
					$this->tokens[ $token ] = $match[0];
					return $token;
				},
				$content
			);
		}
		return $content;
	}

	public function restore( string $content ): string {
		return strtr( $content, $this->tokens );
	}
}
