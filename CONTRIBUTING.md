# Contributing to External Repositories

Thanks for your interest! Bug reports, fixes, translations and documentation improvements are all welcome.

By participating, you agree to follow our [Code of Conduct](CODE_OF_CONDUCT.md). Security issues must **not** be reported in public issues: see [SECURITY.md](SECURITY.md).

What does not belong here: questions about a wp-content.io account, billing or the hosted service. Write to <support@wp-content.io> instead.

## Developer Certificate of Origin (DCO)

This project uses the [Developer Certificate of Origin 1.1](https://developercertificate.org) instead of a CLA. By signing off a commit, you certify that you wrote the change or otherwise have the right to submit it under the project's license (GPLv2 or later).

Every commit of a pull request must carry a `Signed-off-by:` trailer that matches the commit author (name **and** email). Git adds it for you with `-s`:

```bash
git commit -s -m "Fix the theme preview on multisite"
```

This appends:

```text
Signed-off-by: Jane Doe <jane@example.com>
```

A CI check enforces it. If you forgot, sign off the existing commits and force-push your branch:

```bash
git rebase --signoff origin/develop
git push --force-with-lease
```

Use your real name (or the name you are publicly known by) and an email you control.

## Branches

- **`develop`**: integration branch. **Open pull requests against `develop`.**
- **`main`**: what has been released. Only maintainers merge into it.
- Releases are tagged `vX.Y.Z` on `main` and published by the maintainers as GitHub Releases. Contributors never bump the version, tag or publish.

Work on a topic branch in your fork (`fix/theme-preview`, `feature/default-url`…), keep pull requests small and focused, and rebase on `develop` rather than merging it into your branch.

## Coding guidelines

- **PHP 7.1 compatibility.** The plugin supports PHP 7.1 to the latest PHP version: no typed properties, arrow functions, `match`, union types, named arguments, nullsafe operator, etc. Return types and nullable types (`?string`) are fine.
- **WordPress 6.4+.** Do not use APIs introduced after 6.4 without a fallback.
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/): tabs, spaces inside parentheses, `array()` (not `[]`), Yoda conditions, strict comparisons.
- Every PHP file starts with an `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard.
- Escape on output, sanitize on input, and check **both a nonce and a capability** on every action. On multisite, sources are network-wide and require `manage_network_options`.
- Keep the native WordPress admin look (standard notices, list tables, `WP_Upgrader_Skin` for installs).
- **A failing or slow source must never break the site.** Catch exceptions, return the original `$update` / `$res` value, and avoid PHP warnings and notices.
- No runtime dependency: the plugin ships without Composer packages and without a front-end build. Composer is only used for development tools.
- Code, comments and commit messages are written in English.
- Every user-facing string goes through the `external-repositories` text domain.

## Local checks

The development tools are installed with [Composer](https://getcomposer.org/) (PHP 7.2+ on your machine; the plugin itself still targets 7.1):

```bash
composer install
```

| Command | What it runs |
| --- | --- |
| `composer lint` | `php -l` on every PHP file with your local PHP. |
| `composer phpcs` | PHP_CodeSniffer with `phpcs.xml.dist`: WordPress Coding Standards and PHPCompatibilityWP (`testVersion 7.1-`). |
| `composer phpcbf` | Fixes what PHPCS can fix automatically. Review the diff before committing. |

The PHPCS ruleset already checks PHP 7.1 compatibility. To also run `php -l` on the oldest and the newest supported PHP versions, as the CI does, Docker is the simplest way:

```bash
for v in 7.1 8.4; do
	docker run --rm -v "$PWD":/app:ro -w /app php:$v-cli \
		sh -c "find . -name '*.php' -not -path './vendor/*' -not -path './.git/*' -print0 | xargs -0 -n1 php -l > /dev/null"
done
```

### Plugin Check

The CI also runs [Plugin Check](https://wordpress.org/plugins/plugin-check/) on the tree as it ships (`git archive`, which honours the `export-ignore` rules of `.gitattributes`). One finding is expected and ignored: **`plugin_updater_detected`**. This plugin is an updater by design (`Update URI` header, `update_plugins_{host}`…), which is only forbidden for plugins hosted on wordpress.org. Any other error fails the build; warnings show up as annotations.

To run it locally, install the Plugin Check plugin on your test site, then:

```bash
wp plugin check external-repositories --ignore-codes=plugin_updater_detected
```

Do not silence a finding by widening the CI exclusions. If a finding is a false positive, annotate the line with the exact sniff code and a reason:

```php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter.
```

## Testing locally

This repository does not ship a WordPress environment. Use any local WordPress (the official Docker `wordpress` image, [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/), LocalWP…), with:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Mount or symlink your clone into `wp-content/plugins/external-repositories` (the folder name matters), then activate the plugin (network-activate it to test multisite).

To test against your own server, a mock or a local registry, declare the source from a mu-plugin, for example `wp-content/mu-plugins/external-repositories-dev.php`:

```php
<?php
add_filter( 'external_repositories', function ( $repositories ) {
	$repositories[] = array(
		'name'    => 'Local mock',
		'url'     => 'http://localhost:8080/',
		'api_key' => 'dev-key',
	);

	return $repositories;
} );

// API keys are only sent over HTTPS. Allow plain HTTP for local servers only.
add_filter( 'external_repositories_allow_insecure_url', '__return_true' );
```

From inside a Docker container, `localhost` is the container itself: use a host name the container can resolve (for example `host.docker.internal`).

A small mock API is often the fastest way to test error handling: a PHP built-in server (`php -S 0.0.0.0:8080 mock.php`) returning the JSON shapes described in the [README](README.md#compatible-server-api), with switchable 4xx / 5xx / timeout modes.

Worth checking after a change:

- the source tabs in **Plugins → Add New** and **Themes → Add New**, the plugin details modal and the theme preview;
- updates from the **Updates** screen and with `wp plugin update --all` / `wp theme update --all`;
- that the site and wp-admin stay up, without PHP warnings in `debug.log`, when the source is unreachable, slow or returns errors;
- single site **and** multisite when you touch settings, capabilities or storage.

Never paste a real API key or an unredacted `debug.log` in an issue or a pull request.

## Translations

Translations use the `external-repositories` text domain and live in `languages/`. When you add or change a string, regenerate the template with [WP-CLI](https://wp-cli.org/):

```bash
wp i18n make-pot . languages/external-repositories.pot \
	--slug=external-repositories --domain=external-repositories --exclude=vendor,build,.claude
```

Without a local WP-CLI:

```bash
docker run --rm -v "$PWD":/app -w /app --user "$(id -u):$(id -g)" wordpress:cli \
	wp i18n make-pot . languages/external-repositories.pot \
	--slug=external-repositories --domain=external-repositories --exclude=vendor,build,.claude
```

Commit the updated `.pot` with your change.

## Changelog

`readme.txt` (`== Changelog ==`) is the single source of truth: it is shown in the WordPress "View details" modal and used for the GitHub Release notes. For a user-facing change, add one line at the top of the changelog, under the upcoming version if it already exists, otherwise under a `= Unreleased =` heading (maintainers rename it at release time). Do not change `Version:` or `Stable tag:`.

## Commit messages

- Short imperative summary (`Fix …`, `Add …`), in English, under about 72 characters, with details in the body when useful.
- [gitmoji](https://gitmoji.dev/) prefixes are welcome (the history uses them: `🐛 Fix …`, `✨ Add …`) but not required.
- Signed off (`git commit -s`), see [DCO](#developer-certificate-of-origin-dco).

## Pull request checklist

- [ ] The PR targets `develop` and has a clear description (and links the issue it fixes).
- [ ] Every commit is signed off (`git commit -s`).
- [ ] `composer lint` and `composer phpcs` pass; the code stays PHP 7.1 compatible.
- [ ] Tested on a local WordPress (and on multisite when relevant), including the unreachable-source case.
- [ ] `languages/external-repositories.pot` is regenerated if strings changed.
- [ ] `readme.txt` has a changelog line for user-facing changes.
- [ ] Public hooks and the server API stay backward compatible, or the PR explains why not.

Maintainers review pull requests as time allows. Thank you!
