# OneClickTranslation

OneClickTranslation is a production-oriented WordPress plugin that sends complete posts to DeepL while leaving the multilingual source of truth to WPML or Polylang. It translates posts, pages and public custom post types, nested postmeta, ACF structures, Gutenberg blocks and taxonomy terms. Translation relations, language routing, permalinks, `hreflang` and language switchers remain owned by the selected multilingual plugin.

## Features

- Adapter-based WPML and Polylang integration; there are no provider checks in the translation engine.
- DeepL API Free and Pro support with usage checks, batching, timeouts, error mapping, retry and exponential backoff.
- One-click translation meta box and translation status columns for all enabled public post types.
- Native WordPress admin pages for translations, content types, fields, languages, queue, cache, logs and settings.
- Recursive translation of PHP-serialized data, JSON, arrays, objects, ACF groups, repeaters, flexible content, clones and links.
- Gutenberg parsing with `parse_blocks()` and reconstruction with `serialize_blocks()` so block comments remain valid.
- Central `FieldPolicy` with Translate, Copy and Ignore strategies. WPML preferences are honored before automatic heuristics; Polylang uses the Fields screen.
- Translation memory table keyed by SHA-256 of languages, text and DeepL options. Identical strings are deduplicated within a post.
- WP-Cron queue with atomic lock, bounded runs, retry, exponential delay and manual “Process queue now”.
- Source hashes and translated, missing, outdated, queued, processing and error statuses.
- Authenticated REST endpoints, WP-CLI commands, structured redacted logs and extensibility hooks.

## Requirements

- WordPress 6.4 or newer.
- PHP 8.2 or newer (the Docker environment uses PHP 8.3).
- WPML or Polylang with at least two configured languages.
- A DeepL API Free or Pro key.
- MySQL 8 or MariaDB 10.6+; Docker uses MariaDB 11.4.

## Docker setup

From the repository root:

```bash
cp .env.example .env
docker compose up -d
./bin/setup.sh
```

On PowerShell:

```powershell
Copy-Item .env.example .env
./bin/setup.ps1
```

WordPress is available at `http://localhost:8080`, Mailpit at `http://localhost:8025`, and Adminer at `http://localhost:8081`. The plugin directory is bind-mounted at `/var/www/html/wp-content/plugins/oneclicktranslation`, so changes are immediately visible. The setup scripts are idempotent: they install WordPress if needed, configure pretty permalinks, activate the plugin, optionally install Polylang, create Polish/English/German/French languages, and add pages, a post, a development `portfolio` type, nested meta and sample portfolio entries. The development CPT is registered only when `OCT_DEBUG` is true.

Useful commands:

```bash
docker compose run --rm wpcli plugin list
docker compose run --rm wpcli plugin activate oneclicktranslation
docker compose run --rm wpcli oct status
```

To start without the setup helper, `docker compose up -d` is sufficient; complete WordPress’s normal web installer and activate the plugin manually.

## Installation

Copy `plugin/` to `wp-content/plugins/oneclicktranslation`, optionally run `composer install --no-dev --optimize-autoloader`, and activate OneClickTranslation. A safe built-in PSR-4 fallback loader allows the distributed plugin to run without `vendor/`. Activation creates `oct_queue`, `oct_logs`, `oct_translation_cache`, and `oct_status` tables using `dbDelta()` and schedules the queue worker.

The plugin never creates its own language relations. Configure translated post types and taxonomies in WPML or Polylang first. If both are active, go to **OneClickTranslation → Settings → General** and select one explicitly.

## DeepL configuration

Enter a key under **Settings → DeepL API**, select Free or Pro, then use **Test connection and show API usage**. Alternatively define the key in `wp-config.php`:

```php
define('OCT_DEEPL_API_KEY', getenv('DEEPL_API_KEY') ?: '');
```

The constant takes precedence, the form displays only a masked value, and keys, authorization headers and credential-shaped log context are redacted. Never commit a real key. Docker passes `DEEPL_API_KEY` into the constant without writing it into the repository.

Supported DeepL options include source and target language, formality, formatting preservation, sentence splitting, HTML tag handling and context. HTTP 400, 403, 429, 456, 5xx, timeouts and DNS/network failures receive explicit handling. Only retryable failures are retried.

## WPML setup

Install and activate WPML, add languages, and mark desired post types and taxonomies as translatable. OneClickTranslation connects posts and terms through WPML element language/trid APIs. WPML custom-field preferences have priority:

- Translate → translate each content value.
- Copy → copy unchanged.
- Copy once → copy only while creating the target.
- Don’t translate → ignore.

Because WPML is commercial, the development setup cannot download it automatically. Place and activate your licensed copy, then select WPML in General settings if Polylang is also active.

## Polylang setup

The Docker helper can install Polylang from WordPress.org and create sample languages. Mark post types and taxonomies as translatable in Polylang. Configure meta behavior under **OneClickTranslation → Fields**, where detected fields show their post type, inferred type, Translate/Copy/Ignore strategy and strategy source.

## Custom fields and ACF support

OneClickTranslation scans actual postmeta keys and hides WordPress internals by default. Automatic policy copies URLs, emails, UUIDs, colors, paths, identifiers, numbers, booleans, dates and coordinates; content-like values are translated. Admin choices and provider settings can override the heuristic.

ACF definitions are used when ACF is active. Text, textarea and WYSIWYG values translate; numeric, date, boolean, color, media, relationship, user and taxonomy fields copy. Link titles translate while URL and target copy. Groups, repeaters, flexible content and clones recurse through values without changing field keys, field names, layout names or technical identifiers. Import `tests/fixtures/acf-field-group.json` with **ACF → Tools → Import Field Groups** to test a group, repeater and flexible content fixture.

## Gutenberg and protected content

Blocks are parsed recursively. User-facing HTML fragments and known text attributes are translated; block names, class names, anchors, IDs, URLs, media identifiers, alignment, layout and style configuration are untouched. `innerContent` is rebuilt with WordPress’s serializer. HTML is sent with `tag_handling=html`. Shortcode tags and attributes, URLs, email addresses, placeholders, and code/pre blocks are tokenized and restored after translation.

## Queue and automation

Automation never performs a DeepL call from `save_post`. It computes the new source hash, marks changed translations outdated, and enqueues configured targets. WP-Cron processes a small configurable number of jobs per run. A durable job records attempts, errors and timestamps; an atomic expiring option lock prevents overlapping workers. Large bulk forms display selected post count and character volume and ask for confirmation above 100,000 estimated characters. The dashboard shows the last cached DeepL quota response.

## WP-CLI

```bash
wp oct status
wp oct translate 123 --to=en
wp oct translate 123 --to=en,de
wp oct translate-missing --to=en
wp oct update-outdated
wp oct queue run
wp oct queue status
wp oct cache clear
wp oct logs clear
```

Hyphenated subcommands are mapped by WP-CLI to the underscore-named command methods.

## REST API

The namespace is `oneclicktranslation/v1`. Available routes are `GET /queue`, `POST /translate/{id}`, `GET /status/{id}`, and `GET /usage`. Every route has a capability callback: translation/status require `edit_post`; queue/usage require `manage_options`. No key or sensitive headers are returned.

## Hooks

Actions:

- `oct_before_translate_post($source_id, $target_language, $extracted)`
- `oct_after_translate_post($source_id, $translated_id, $target_language)`
- `oct_translation_created($source_id, $translated_id, $target_language)`
- `oct_translation_updated($source_id, $translated_id, $target_language)`
- `oct_translation_failed($source_id, $target_language, $exception)`
- `oct_before_translate_field($path, $value, $object_id)`
- `oct_after_translate_field($path, $value, $object_id)`
- `oct_queue_job_created($job_id, $source_id, $target_language)`
- `oct_queue_job_completed($job_id, $result)`

Filters:

- `oct_should_translate_field`
- `oct_field_strategy`
- `oct_translation_context`
- `oct_supported_post_types`
- `oct_deepl_options`
- `oct_translation_batch`

## Troubleshooting

- **No provider:** activate WPML or Polylang. If both are active, make an explicit selection.
- **Post type not translatable:** enable the type in the multilingual plugin and on the Content Types screen.
- **403:** check the API key and ensure Free uses `api-free.deepl.com` while Pro uses `api.deepl.com`.
- **456:** the character quota is exhausted; check the dashboard usage data.
- **Jobs stay pending:** visit **Translation Queue → Process queue now**, verify loopback/WP-Cron, or run `wp oct queue run`.
- **Outdated status does not clear:** translate from the provider’s default-language source post; translated posts are not treated as new sources.
- **Diagnostics:** set `define('OCT_DEBUG', true);` for batch counts, provider diagnostics and extra debug logs. It is false by default outside Docker.

## Development and testing

Install development dependencies inside `plugin/`:

```bash
composer install
composer lint
composer test
composer analyse
```

Unit tests cover provider resolution, field policy, source hashes, cache keys, batch deduplication/chunking, nested arrays, serialized and JSON meta, technical URL/email/ID behavior, DeepL error mapping and queue retry. The integration schema test activates when run inside a bootstrapped WordPress test environment.

Database deletion is disabled by default. Enable **Delete all plugin data on uninstall** only when permanent removal is intended.
