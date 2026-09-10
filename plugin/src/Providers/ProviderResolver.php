<?php

declare(strict_types=1);

namespace OneClickTranslation\Providers;

use OneClickTranslation\Contracts\LanguageProviderInterface;
use OneClickTranslation\Support\Helpers;
use RuntimeException;

final class ProviderResolver {

	/** @var array<string, LanguageProviderInterface> */
	private array $providers;

	/** @param list<LanguageProviderInterface>|null $providers */
	public function __construct( ?array $providers = null ) {
		$providers     ??= array( new WPMLProvider(), new PolylangProvider() );
		$this->providers = array();
		foreach ( $providers as $provider ) {
			$this->providers[ strtolower( $provider->getProviderName() ) ] = $provider;
		}
	}

	public function resolve( ?string $preference = null ): LanguageProviderInterface {
		$preference = strtolower( $preference ?? (string) ( Helpers::settings()['provider'] ?? 'auto' ) );
		$available  = array_filter( $this->providers, static fn ( LanguageProviderInterface $provider ): bool => $provider->isAvailable() );
		if ( $preference !== 'auto' ) {
			if ( isset( $available[ $preference ] ) ) {
				return $available[ $preference ];
			}
			throw new RuntimeException( sprintf( 'Selected language provider "%s" is not available.', $preference ) );
		}
		if ( count( $available ) === 1 ) {
			return reset( $available );
		}
		if ( count( $available ) > 1 ) {
			throw new RuntimeException( 'Both WPML and Polylang are active. Select a provider in OneClickTranslation settings.' );
		}
		throw new RuntimeException( 'OneClickTranslation requires an active WPML or Polylang installation.' );
	}

	/** @return array<string, LanguageProviderInterface> */
	public function available(): array {
		return array_filter( $this->providers, static fn ( LanguageProviderInterface $provider ): bool => $provider->isAvailable() );
	}
}
