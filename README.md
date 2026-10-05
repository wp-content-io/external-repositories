# External Repositories

[![CI](https://github.com/wp-content-io/external-repositories/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/wp-content-io/external-repositories/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/wp-content-io/external-repositories?sort=semver)](https://github.com/wp-content-io/external-repositories/releases/latest)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)](LICENSE)
[![PHP 7.1+](https://img.shields.io/badge/php-7.1%2B-777bb4.svg)](https://www.php.net/)
[![WordPress 6.4+](https://img.shields.io/badge/wordpress-6.4%2B-21759b.svg)](https://wordpress.org/)

A WordPress plugin that installs and updates **private plugins and themes** from external sources instead of the wordpress.org directory.

A source is either:

- a [wp-content.io](https://wp-content.io) account, or
- the URL of any compatible update server (see [Compatible server API](#compatible-server-api)).

Each source gets its own tab in **Plugins → Add New** and **Themes → Add New**, and the plugins and themes it serves receive updates through the standard WordPress update screens, WP-CLI and auto-updates.

User documentation: <https://docs.wp-content.io/>.

## Requirements

- WordPress 6.4 or later
- PHP 7.1 or later, with the OpenSSL extension (API keys are encrypted in the database)
- HTTPS for the sources that use an API key

## Installation

> [!IMPORTANT]
> Install from the **release asset `external-repositories.zip`**, not from the green **Code → Download ZIP** button nor the **Source code** archives of a release. Those archives unpack as `external-repositories-main/` or `external-repositories-1.3.1/`: the plugin then installs under the wrong folder name, and its self-update and some settings stop working.

1. Download `external-repositories.zip` from the [latest release](https://github.com/wp-content-io/external-repositories/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip and activate the plugin. On multisite, **network activate** it.

With WP-CLI:

```bash
wp plugin install https://github.com/wp-content-io/external-repositories/releases/latest/download/external-repositories.zip --activate
# Multisite: --activate-network instead of --activate
```

The plugin then keeps itself up to date (see [Self-update](#self-update)).

## Usage

### Add a source in the admin

Go to **Settings → External Repositories**. On multisite, sources are managed by the network admin only, in **Network Admin → Settings → External Repositories** (capability `manage_network_options`), and they apply to the whole network.

For each source, set a name, choose wp-content.io or a custom URL, enter the API key and choose whether it serves plugins, themes or both. API keys are encrypted in the database with the `SECURE_AUTH_KEY` constant of `wp-config.php`: if that key changes, save the source again with its API key.

### Declare a source in code

Sources can also be declared with the `external_repositories` filter, for example from a mu-plugin. These sources are not stored in the database and do not appear on the settings screen.

```php
add_filter( 'external_repositories', function ( $repositories ) {
	$repositories[] = array(
		'name'             => 'My company',                      // Required. Also used (sanitized) as the tab slug: keep it unique.
		'url'              => 'https://updates.example.com/',    // Defaults to https://registry.wp-content.io/
		'api_key'          => getenv( 'MY_COMPANY_API_KEY' ),    // Stored nowhere: read it from an environment variable or a constant.
		'supports_plugins' => true,                              // Default true.
		'supports_themes'  => false,                             // Default true.
	);

	return $repositories;
} );
```

API keys are only sent over HTTPS. For a local development server on `http://`, allow it explicitly (never in production):

```php
add_filter( 'external_repositories_allow_insecure_url', '__return_true' );
```

### How a plugin or theme is linked to a source

A plugin or theme is updated by a source when the host of its **`Update URI`** header equals the host of the source URL. The slug is the plugin folder name, or the theme stylesheet (folder) name.

```php
/**
 * Plugin Name: My Private Plugin
 * Version:     1.0.0
 * Update URI:  https://updates.example.com/
 */
```

For a theme, add `Update URI: https://updates.example.com/` to the header of `style.css`. If several sources share the same host, the first one that answers wins.

### Other hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `external_repositories` | filter | Add sources from code (see above). Receives the sources saved in the database. |
| `external_repositories_api_key` | filter | Change the API key of a source at runtime. Arguments: `$api_key`, `$config`. |
| `external_repositories_allow_insecure_url` | filter | Allow an `http://` source URL (local development only). Arguments: `$allowed`, `$url`. |
| `external_repositories_allowed_package` | filter | Allow or refuse the package URL announced by the self-update manifest. Arguments: `$allowed`, `$package`, `$update_uri`. |
| `hide_external_repositories_plugin` | filter | Hide this plugin from the plugins list. |

## Compatible server API

Any server implementing these endpoints can be used as a source. All requests are `GET`, with a 10 second timeout. When the source has an API key, it is sent in the **`x-api-key`** header. `{url}` is the source URL with a trailing slash. WordPress adds its own query arguments (for example `fields`, `locale`): servers must ignore the arguments they do not know.

| Request | Response (JSON) |
| --- | --- |
| `GET {url}plugins?browse={source-slug}&page={n}&per_page=36` | `{ "info": { "page", "pages", "results" }, "plugins": [ … ] }`. Each plugin follows the WordPress `query_plugins` shape: `name`, `slug`, `version`, `author`, `short_description`, `icons`, `requires`, `requires_php`, `tested`, `last_updated`, `download_link`… |
| `GET {url}plugins/{slug}?installed=1&active=1` | The WordPress `plugin_information` shape: `name`, `slug`, **`version`**, **`download_link`**, `tested`, `requires`, `requires_php`, `icons`, `banners`, `sections` (`description`, `changelog`…). `active` is `1` when the plugin is active, empty otherwise. |
| `GET {url}themes?browse={source-slug}&page={n}&per_page=100` | `{ "info": { "page", "pages", "results" }, "themes": [ … ] }`. Each theme: `name`, `slug`, `version`, `screenshot_url`, `preview_url` (optional), `author`, `description`… |
| `GET {url}themes/{slug}?installed=1` | The WordPress `theme_information` shape: `name`, `slug`, **`version`**, **`download_link`**, `requires`, `requires_php`, `sections`… |

`{source-slug}` is the sanitized name of the source. `version` and `download_link` are required for updates. `download_link` may be a short-lived URL: the plugin requests the item again right before downloading an update.

Errors: return a non-2xx status with a JSON body `{ "messages": "…" }` or `{ "messages": { "key": "…" } }`; the messages are shown to the admin. In particular:

- `401` / `402`: the messages of the body are shown (for example an invalid key or an expired subscription);
- `403`: the API key lacks read permission (a fixed message is shown);
- `404`: unknown item or source; the messages of the body are shown when present;
- `5xx`, timeouts and network errors: the source is paused for 5 minutes, so a down server never slows down every admin page.

A successful response must be a JSON object. A slow or failing server never breaks the site: the plugin falls back to the data WordPress already has.

## Self-update

This plugin updates itself from its own `Update URI` (`https://downloads.wp-content.io/`), not from wordpress.org:

1. It reads the manifest at <https://downloads.wp-content.io/plugins/external-repositories/manifest.json>.
2. `Version` is the latest version, **without a `v` prefix** (`1.3.1`, never `v1.3.1`: a leading `v` is stripped defensively, because `version_compare()` would rank it below any number).
3. `PackageURI` is the zip to download. It is used only when it is an HTTPS URL on the `Update URI` host or a GitHub release asset of the `wp-content-io` organization (`https://github.com/wp-content-io/*/releases/download/…`); otherwise the plugin falls back to `https://downloads.wp-content.io/plugins/external-repositories/external-repositories.{Version}.zip`. The `external_repositories_allowed_package` filter can change this allow-list.
4. When the manifest has a `Sha256` field, the downloaded zip is checked against it and the update is refused on mismatch.
5. Optional fields: `Tested` (otherwise read from the local `readme.txt`), `Name`, `Author`, `AuthorURI`, `RequiresWP`, `RequiresPHP`.

The details modal ("View details") is built from the bundled `readme.txt`, including its changelog.

Releases are tagged `vX.Y.Z` on GitHub and published by the maintainers; the zip of each release is attached to its [GitHub Release](https://github.com/wp-content-io/external-repositories/releases). A fork that wants to distribute its own build must change the `Update URI` header (and host its own manifest), otherwise it receives the official updates.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md): branch model, DCO sign-off, local checks (PHP syntax, WordPress Coding Standards, PHP 7.1 compatibility), testing with a local WordPress and translations.

## Security

Please do not open public issues for vulnerabilities. See [SECURITY.md](SECURITY.md) for how to report them privately.

## Support

- Bugs and feature requests for this plugin: [GitHub issues](https://github.com/wp-content-io/external-repositories/issues).
- wp-content.io accounts, billing and the service itself: <support@wp-content.io>.

## License

External Repositories is free software, released under the [GNU General Public License v2 or later](LICENSE). Copyright (C) Alexandre Chastan.

It bundles a modified copy of [Slimdown](https://github.com/jbroadway/slimdown) (MIT License, Johnny Broadway). See [NOTICE.txt](NOTICE.txt).
