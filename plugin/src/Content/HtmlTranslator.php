<?php

declare(strict_types=1);

namespace OneClickTranslation\Content;

use OneClickTranslation\DeepL\DeepLClient;

final class HtmlTranslator {

	public function __construct( private readonly DeepLClient $client, private readonly ShortcodeProtector $protector ) {}

	public function translate( string $html, string $targetLanguage, array $options = array() ): string {
		$protected  = $this->protector->protect( $html );
		$translated = $this->client->translateHtml( $protected, $targetLanguage, $options )->first();
		return $this->protector->restore( $translated );
	}
}
