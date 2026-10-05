# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) and other coding agents when working with code in this repository. Human contributors: see [CONTRIBUTING.md](CONTRIBUTING.md), which this file follows.

## Role

`external-repositories` is a **WordPress plugin** installed on the end user's site. It registers one or more **external sources** ("repositories"): a [wp-content.io](https://wp-content.io) account or the URL of any compatible update server. Through them, the site installs and updates private plugins and themes outside the official wordpress.org directory.

Stack: **PHP 7.1+** (no newer syntax), WordPress 6.4+. No runtime Composer dependencies (Composer only installs dev tools) and no front-end build (static CSS/JS in `assets/`).

## Server API

The plugin talks to a source over a small public HTTP API, documented in [README.md](README.md#compatible-server-api):

- `GET {url}plugins?browse&page&per_page`, `GET {url}plugins/{slug}?installed&active`, `GET {url}themes?browse&page&per_page`, `GET {url}themes/{slug}`, with WordPress `query_plugins` / `plugin_information` (and theme equivalents) response shapes.
- The API key goes in the **`x-api-key`** header, and only over HTTPS (`external_repositories_allow_insecure_url` filter for local development).
- Errors are JSON `{ "messages": … }`; 5xx and network errors pause the source for 5 minutes.
- `download_link` may expire: the plugin requests the item again right before downloading an update (`upgrader_pre_download`).
- Default source URL: `https://registry.wp-content.io/`.

Keep this contract backward compatible: third-party servers implement it. Update the README when it changes.

## Architecture

Entry point: `external-repositories.php` (WordPress header plus activation, enqueue and menu hooks). It loads `src/` then calls `ExternalRepositoryManager::load()`.

- **`src/ExternalRepositoryManager.php`**: central class (singleton). Database table init/migration (one table shared by the whole network on multisite, managed by the network admin with `manage_network_options`), CRUD of sources through the settings screen, admin page rendering (tabs, help), plugin visibility in the plugins list, and **encryption/decryption of the API keys** stored in the database (`encrypt_api_key` / `decrypt_api_key`, based on `SECURE_AUTH_KEY`).
- **`src/ExternalRepository.php`**: model of a source. `call_api()` does every HTTP call (10 s timeout, maps API errors to messages, flags an unreachable source for 5 minutes in a site transient). Sources can also be declared with the `external_repositories` filter.
- **`src/ExternalPluginRepository.php`** / **`src/ExternalThemeRepository.php`**: hook the source into WordPress: `update_plugins_{host}` / `update_themes_{host}`, `plugins_api` / `themes_api`, install tabs, theme preview, `upgrader_pre_download`. A plugin or theme belongs to a source when its `Update URI` header host matches the source URL host.
- **`updater.php`**: self-update of this plugin from `manifest.json` on its `Update URI` (`Version` without `v`, `PackageURI` allow-list, optional `Sha256`), and details modal built from `readme.txt`. Do not change the `Update URI` header nor the `update_plugins_downloads.wp-content.io` hook: every installed site relies on them.
- **`src/Slimdown.php`**: third-party Markdown parser (MIT, see `NOTICE.txt`) used to render `readme.txt` sections. Keep it close to upstream; it is excluded from PHPCS.
- **`views/`**: admin templates. **`assets/`**: admin CSS/JS. **`languages/`**: translation template (text domain `external-repositories`).

## Conventions

- Every PHP file starts with an `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard.
- WordPress coding style (tabs, spaces inside parentheses, `array()`), escaping on output, sanitizing on input, nonce **and** capability checks on every action.
- Respect the native WordPress admin look: no breadcrumb, standard list tables and notices, installs through `WP_Upgrader_Skin`.
- A failing or slow API must **never** break the client's site: catch exceptions, return the original `$update` / `$res` value, and avoid PHP warnings.
- Public hooks (`external_repositories`, `external_repositories_api_key`, `external_repositories_allow_insecure_url`, `external_repositories_allowed_package`, `hide_external_repositories_plugin`…) are a public contract: do not rename or remove them.
- `readme.txt` `== Changelog ==` is the only changelog (rendered in the details modal). `Version:` (header) and `Stable tag:` never carry a `v` prefix.

## Checks

There is no test suite. Before committing, at least:

```bash
composer install
composer lint     # php -l with the local PHP
composer phpcs    # WPCS + PHPCompatibilityWP (testVersion 7.1-), see phpcs.xml.dist
```

Syntax on the oldest and newest supported PHP, as in CI:

```bash
for v in 7.1 8.4; do docker run --rm -v "$PWD":/app:ro -w /app php:$v-cli sh -c "find . -name '*.php' -not -path './vendor/*' -not -path './.git/*' -print0 | xargs -0 -n1 php -l > /dev/null"; done
```

Regenerate the translation template after changing strings:

```bash
wp i18n make-pot . languages/external-repositories.pot --slug=external-repositories --domain=external-repositories --exclude=vendor,build,.claude
```

CI also runs Plugin Check on the shipped tree; `plugin_updater_detected` is expected and ignored (this plugin is an updater by design). Annotate real false positives with `// phpcs:ignore <Exact.Sniff.Code> -- <reason>` instead of widening exclusions.

## Testing locally

The plugin needs a WordPress site; none is provided by this repository. Use any local WordPress (Docker `wordpress` image, wp-env, LocalWP…), PHP 8.x, with `WP_DEBUG` and `WP_DEBUG_LOG` enabled, and mount or symlink this folder into `wp-content/plugins/external-repositories`.

The settings screen always uses the wp-content.io URL for new sources. To target your own server or a mock, register the source from a mu-plugin:

```php
add_filter( 'external_repositories', function ( $repositories ) {
	$repositories[] = array(
		'name'    => 'Local mock',
		'url'     => 'http://localhost:8080/',
		'api_key' => 'dev-key',
	);

	return $repositories;
} );

// API keys are only sent over HTTPS: allow http:// for local servers only.
add_filter( 'external_repositories_allow_insecure_url', '__return_true' );
```

A small mock API (PHP built-in server returning the JSON shapes of the README, with switchable 4xx/5xx/timeout modes) is often the fastest way to test error handling. Things worth checking after a change: the "Add plugins" / "Add themes" tabs of the source, plugin details modal, theme preview, `wp plugin update --all`, single site and multisite, and that the site and wp-admin stay up when the API is unreachable.

## Agent rules

- Code, comments, commit messages and documentation are written in **English**.
- Work on a feature branch; pull requests target **`develop`** (`main` holds released code).
- Every commit is signed off (`git commit -s`, [DCO](https://developercertificate.org)) by the human author who submits it.
- **Never bump the version, create a tag, or publish a release**: maintainers do it (`vX.Y.Z` tags, GitHub Releases). A published release reaches every site using the plugin through self-update.
- Keep PHP 7.1 compatibility and WordPress 6.4+ compatibility; lint before committing. Regenerate the `.pot` when strings change.
- Add a `readme.txt` changelog line for user-facing changes.
- Never write real API keys, credentials or private URLs into code, tests, docs or commit messages.
- gitmoji commit prefixes (e.g. `🐛 Fix …`, `✨ Add …`) are used in the history; welcome but not required.
