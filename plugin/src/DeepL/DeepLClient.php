<?php

declare(strict_types=1);

namespace OneClickTranslation\DeepL;

use OneClickTranslation\Support\Helpers;

final class DeepLClient {

	private string $baseUrl;

	public function __construct(
		private readonly string $apiKey,
		string $apiType = 'free',
		private readonly int $timeout = 30,
		private readonly int $retries = 2
	) {
		$this->baseUrl = $apiType === 'pro' ? 'https://api.deepl.com/v2' : 'https://api-free.deepl.com/v2';
	}

	/** @param array<string, mixed> $options */
	public function translateText( string $text, string $targetLanguage, array $options = array() ): DeepLResponse {
		unset( $options['tag_handling'] );
		return $this->translateBatch( array( $text ), $targetLanguage, $options );
	}

	/** @param array<string, mixed> $options */
	public function translateHtml( string $html, string $targetLanguage, array $options = array() ): DeepLResponse {
		$options['tag_handling'] = 'html';
		return $this->translateBatch( array( $html ), $targetLanguage, $options );
	}

	/** @param list<string> $texts @param array<string, mixed> $options */
	public function translateBatch( array $texts, string $targetLanguage, array $options = array() ): DeepLResponse {
		if ( $texts === array() ) {
			return new DeepLResponse( array() );
		}
		$totalBytes = array_sum( array_map( 'strlen', $texts ) );
		$maxBytes   = max( array_map( 'strlen', $texts ) );
		if ( count( $texts ) > 50 || $totalBytes > 110000 || $maxBytes > 90000 ) {
			$segments = array();
			foreach ( $texts as $index => $text ) {
				foreach ( $this->splitLargeText( $text, 90000, isset( $options['tag_handling'] ) ) as $part ) {
					$segments[] = array(
						'index' => $index,
						'text'  => $part,
					);
				}
			}
			$output   = array_fill( 0, count( $texts ), '' );
			$detected = null;
			$group    = array();
			$bytes    = 0;
			foreach ( $segments as $position => $segment ) {
				$size = strlen( $segment['text'] );
				if ( $group && ( count( $group ) >= 50 || $bytes + $size > 110000 ) ) {
					$response = $this->translateSegmentGroup( $group, $targetLanguage, $options );
					foreach ( $group as $groupIndex => $groupSegment ) {
						$output[ $groupSegment['index'] ] .= $response->translations[ $groupIndex ]; }
					$detected ??= $response->detectedSourceLanguage;
					$group      = array();
					$bytes      = 0;
				}
				$group[] = $segment;
				$bytes  += $size;
				if ( $position === array_key_last( $segments ) && $group ) {
					$response = $this->translateSegmentGroup( $group, $targetLanguage, $options );
					foreach ( $group as $groupIndex => $groupSegment ) {
						$output[ $groupSegment['index'] ] .= $response->translations[ $groupIndex ]; }
					$detected ??= $response->detectedSourceLanguage;
				}
			}
			return new DeepLResponse( $output, $detected );
		}
		$body = array(
			'text'        => array_values( $texts ),
			'target_lang' => Helpers::deeplLanguage( $targetLanguage ),
		);
		foreach ( array( 'source_lang', 'formality', 'preserve_formatting', 'split_sentences', 'tag_handling', 'context' ) as $option ) {
			if ( isset( $options[ $option ] ) && $options[ $option ] !== '' && $options[ $option ] !== 'default' ) {
				$body[ $option ] = $options[ $option ];
			}
		}
		$body = apply_filters( 'oct_deepl_options', $body, $texts, $targetLanguage );
		$data = $this->request( 'translate', 'POST', $body );
		if ( ! isset( $data['translations'] ) || ! is_array( $data['translations'] ) ) {
			throw new DeepLException( 'DeepL returned an invalid translation response.', 502, true );
		}
		$translations = array();
		$detected     = null;
		foreach ( $data['translations'] as $translation ) {
			if ( ! is_array( $translation ) || ! isset( $translation['text'] ) ) {
				throw new DeepLException( 'DeepL returned an incomplete translation response.', 502, true );
			}
			$translations[] = (string) $translation['text'];
			$detected     ??= isset( $translation['detected_source_language'] ) ? (string) $translation['detected_source_language'] : null;
		}
		if ( count( $translations ) !== count( $texts ) ) {
			throw new DeepLException( 'DeepL response count does not match request count.', 502, true );
		}
		return new DeepLResponse( $translations, $detected, $data );
	}

	/** @param list<array{index:int,text:string}> $group */
	private function translateSegmentGroup( array $group, string $targetLanguage, array $options ): DeepLResponse {
		return $this->translateBatch( array_values( array_map( static fn( array $segment ): string => $segment['text'], $group ) ), $targetLanguage, $options );
	}

	/** @return list<string> */
	private function splitLargeText( string $text, int $limit, bool $html ): array {
		$parts = array();
		while ( strlen( $text ) > $limit ) {
			$window     = substr( $text, 0, $limit );
			$boundaries = $html ? array( '</p>', '</li>', '</div>', '<br>', "\n" ) : array( "\n", '. ', '! ', '? ', ' ' );
			$cut        = 0;
			foreach ( $boundaries as $boundary ) {
				$position = strrpos( $window, $boundary );
				if ( false !== $position && $position > (int) ( $limit * 0.5 ) ) {
					$cut = max( $cut, $position + strlen( $boundary ) );
				}
			}
			if ( 0 === $cut ) {
				$chunk = mb_strcut( $text, 0, $limit, 'UTF-8' );
				$cut   = strlen( $chunk );
			}
			$parts[] = substr( $text, 0, $cut );
			$text    = substr( $text, $cut );
		}
		if ( '' !== $text ) {
			$parts[] = $text; }
		return $parts;
	}

	/** @return array{character_count:int,character_limit:int,api_type:string} */
	public function getUsage(): array {
		$data = $this->request( 'usage', 'GET' );
		return array(
			'character_count' => (int) ( $data['character_count'] ?? 0 ),
			'character_limit' => (int) ( $data['character_limit'] ?? 0 ),
			'api_type'        => str_contains( $this->baseUrl, 'api-free' ) ? 'Free' : 'Pro',
		);
	}

	/** @return array{success:bool,message:string,usage?:array<string,mixed>} */
	public function testConnection(): array {
		if ( $this->apiKey === '' ) {
			return array(
				'success' => false,
				'message' => 'DeepL API key is not configured.',
			);
		}
		try {
			$usage = $this->getUsage();
			return array(
				'success' => true,
				'message' => 'DeepL connection is working.',
				'usage'   => $usage,
			);
		} catch ( DeepLException $exception ) {
			return array(
				'success' => false,
				'message' => $exception->getMessage(),
			);
		}
	}

	/** @param array<string, mixed> $body @return array<string, mixed> */
	private function request( string $endpoint, string $method, array $body = array() ): array {
		if ( $this->apiKey === '' ) {
			throw new DeepLException( 'DeepL API key is not configured.', 403, false );
		}
		$url     = $this->baseUrl . '/' . ltrim( $endpoint, '/' );
		$attempt = 0;
		do {
			++$attempt;
			$args = array(
				'timeout'    => max( 5, $this->timeout ),
				'headers'    => array(
					'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
					'Accept'        => 'application/json',
				),
				'user-agent' => 'OneClickTranslation/' . ( defined( 'OCT_VERSION' ) ? OCT_VERSION : '1.0.0' ) . '; ' . home_url( '/' ),
			);
			if ( $method === 'GET' ) {
				$response = wp_remote_get( $url, $args );
			} else {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$response                        = wp_remote_post( $url, $args );
			}
			if ( is_wp_error( $response ) ) {
				$exception = new DeepLException( 'DeepL network error: ' . $response->get_error_message(), 0, true );
			} else {
				$status  = (int) wp_remote_retrieve_response_code( $response );
				$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				if ( $status >= 200 && $status < 300 && is_array( $decoded ) ) {
					return $decoded;
				}
				$retryAfter = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$message    = is_array( $decoded ) && isset( $decoded['message'] ) ? (string) $decoded['message'] : '';
				$exception  = self::exceptionForStatus( $status, $message, $retryAfter ? $retryAfter : null );
			}
			if ( ! $exception->isRetryable() || $attempt > $this->retries ) {
				throw $exception;
			}
			$delay = $exception->getRetryAfter() ?? min( 8, 2 ** ( $attempt - 1 ) );
			sleep( max( 1, $delay ) );
		} while ( $attempt <= $this->retries + 1 );
		throw new DeepLException( 'DeepL request failed.', 500, true );
	}

	public static function exceptionForStatus( int $status, string $message = '', ?int $retryAfter = null ): DeepLException {
		$suffix = $message !== '' ? ' ' . sanitize_text_field( $message ) : '';
		return match ( true ) {
			$status === 400 => new DeepLException( 'DeepL rejected the request (400).' . $suffix, 400, false ),
			$status === 403 => new DeepLException( 'DeepL authentication failed (403). Check the API key and endpoint type.' . $suffix, 403, false ),
			$status === 429 => new DeepLException( 'DeepL rate limit exceeded (429).' . $suffix, 429, true, $retryAfter ),
			$status === 456 => new DeepLException( 'DeepL character quota exceeded (456).' . $suffix, 456, false ),
			$status >= 500 => new DeepLException( 'DeepL service is temporarily unavailable (' . $status . ').' . $suffix, $status, true, $retryAfter ),
			default => new DeepLException( 'DeepL request failed with HTTP ' . $status . '.' . $suffix, $status, false ),
		};
	}
}
