# OneClickTranslation

Kompletny plugin WordPress integrujący DeepL API z WPML i Polylang. Repozytorium zawiera kod pluginu, środowisko Docker, automatyczny setup danych developerskich, WP-CLI oraz testy.

## Features

- tłumaczenie wpisów, stron, publicznych CPT, Gutenberg, taxonomy terms, postmeta i rekursywnych pól ACF;
- adaptery WPML/Polylang — provider pozostaje właścicielem języków i relacji;
- batching, deduplikacja, cache SHA-256, DeepL context oraz ochrona HTML/shortcodes/URL;
- kolejka WP-Cron z lockingiem i retry, automatyczne aktualizacje, source hash i statusy;
- natywne ekrany wp-admin, bulk actions, REST API, logi, statystyki użycia i WP-CLI.

## Requirements

Do uruchomienia bez Dockera wymagane są WordPress 6.4+, PHP 8.2+, MariaDB/MySQL, WPML lub Polylang oraz klucz DeepL API. Obraz developerski używa PHP 8.3 i MariaDB 11.4.

## Docker setup

```bash
cp .env.example .env
docker compose up -d
bash bin/setup.sh
```

Windows PowerShell:

```powershell
Copy-Item .env.example .env
./bin/setup.ps1
```

Usługi: WordPress `http://localhost:8080`, Mailpit `http://localhost:8025`, Adminer `http://localhost:8081`. Folder `./plugin` jest montowany bezpośrednio do `wp-content/plugins/oneclicktranslation`.

## Installation and DeepL configuration

Przy instalacji tradycyjnej skopiuj `plugin/` do `wp-content/plugins/oneclicktranslation` i aktywuj plugin. Klucz można zapisać w ustawieniach albo zdefiniować bezpieczniej:

```php
define('OCT_DEEPL_API_KEY', getenv('DEEPL_API_KEY') ?: '');
```

Stała ma pierwszeństwo. W repozytorium nie ma prawdziwego klucza.

## WPML and Polylang setup

Języki, post types i taxonomies należy skonfigurować w WPML lub Polylang. Jeśli oba są aktywne, wybierz provider w **OneClickTranslation → Settings → General**. WPML custom-field preferences mają pierwszeństwo; dla Polylang strategie pól ustawia ekran **Fields**.

## Custom fields, ACF and Gutenberg

Strategie Translate/Copy/Ignore działają również dla PHP serialization, JSON, tablic i obiektów. ACF obsługuje group, repeater, flexible content, clone, link i pola relacyjne. Fixture do importu znajduje się w `plugin/tests/fixtures/acf-field-group.json`. Bloki Gutenberg są parsowane i serializowane funkcjami WordPress, bez modyfikacji identyfikatorów technicznych.

## WP-CLI

```bash
wp oct status
wp oct translate 123 --to=en,de
wp oct translate-missing --to=en
wp oct update-outdated
wp oct queue run
wp oct queue status
wp oct cache clear
wp oct logs clear
```

## Hooks

Plugin udostępnia wszystkie opisane hooki `oct_before_translate_*`, `oct_after_translate_*`, `oct_translation_*`, `oct_queue_job_*` oraz filtry `oct_should_translate_field`, `oct_field_strategy`, `oct_translation_context`, `oct_supported_post_types`, `oct_deepl_options`, `oct_translation_batch`. Pełne sygnatury są opisane w [dokumentacji pluginu](plugin/README.md#hooks).

## Troubleshooting and development

Brak providera oznacza, że WPML/Polylang nie jest aktywny albo oba wymagają jawnego wyboru. HTTP 403 zwykle wskazuje błędny typ endpointu/klucz, 456 wyczerpany limit, a oczekujące zadania można uruchomić przez `wp oct queue run`. `OCT_DEBUG=true` dodaje diagnostykę.

```bash
cd plugin
composer install
composer lint
composer test
composer analyse
```

Szczegółowy opis instalacji, DeepL, WPML, Polylang, pól, ACF, Gutenberg, REST, hooków, troubleshooting i testów znajduje się w [plugin/README.md](plugin/README.md).
