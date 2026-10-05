=== External Repositories ===
Tags: updates, private plugins, private themes, repository, deployment
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.1
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Install and update private plugins and themes from external sources such as wp-content.io.

== Description ==
This plugin allows developers to register external sources for WordPress plugins and themes, enabling private deployment and updates without relying on the official WordPress plugin directory.

You can add sources using:
- A [wp-content.io](https://wp-content.io) account
- A fully custom URL pointing to a compatible update server

This plugin is intended for developers who want to deploy and maintain their own plugins or themes across multiple WordPress installations easily and securely.

> ⚠️ This plugin **does not support or encourage** the installation of nulled or pirated versions of commercial plugins. Please support plugin and theme authors by purchasing proper licenses.

Comprehensive documentation is available at [docs.wp-content.io](https://docs.wp-content.io/install/).

== Changelog ==

= 1.3.1 =
- 🎨 Follow the WordPress Coding Standards (strict comparisons, Yoda conditions, no assignments in conditions) and document the remaining exceptions. No functional change.
- 🔧 Add the PHP_CodeSniffer configuration (`phpcs.xml.dist`)

= 1.3.0 =
- 🔒️ Multisite: repositories are now managed by the network admin only, stored in a single network-wide table and require the `manage_network_options` capability (site administrators could previously add sources feeding network-wide updates)
- 🔒️ Refuse to send an API key to a non-HTTPS repository URL (use the `external_repositories_allow_insecure_url` filter for local development servers)
- 🔒️ Check capabilities before any redirection on the settings screen
- 🔒️ Escape repository names and messages inserted by the theme browser script
- ✨ Self-update: download the package from the URL announced by the manifest (HTTPS, wp-content.io or its GitHub releases only) and verify its SHA-256 checksum when provided
- 💄 Load plugin banners from downloads.wp-content.io
- 🔒️ Use safe redirects and a prepared query for the repositories table
- 🐛 Prefix the bundled Markdown parser class to avoid conflicts with other plugins

= 1.2.0 =
- 🐛 Send `page` instead of `paged` to the registry when browsing plugins and themes
- 🐛 Download a fresh package right before an update: signed download links expire before WordPress uses them
- 🐛 Fix a fatal error in the theme preview when the repository returns an error
- 🐛 Stop handling information requests for themes that do not belong to the repository (wordpress.org themes could not be displayed)
- 🐛 Keep themes from previous pages available when scrolling the theme browser
- ⚡️ Add a 10 seconds timeout to repository requests, and pause calls to an unreachable repository for 5 minutes
- ⚡️ Only call the first repository sharing the same host when checking updates
- 🚸 Pass "Requires at least" and "Requires PHP" to WordPress so incompatible updates are flagged
- 🚸 Display the repository error message for "not found" responses
- 🔒️ Check user capabilities when saving or deleting repositories, and use a nonce per repository for deletion
- 🥅 Ignore an invalid self-update manifest instead of raising PHP warnings
- 🐛 Create the database table on first load and fix the schema format (database errors on activation)
- 🥅 Avoid PHP warnings when the repository omits optional fields
- 🔖 Check WordPress 7.1 compatibility
- 👤 Update plugin author

= 1.1.3 =
- 🔖 Check WordPress 6.8.2 compatibility
- 🐛 Fix warning on update screen for plugins with no banners

= 1.1.2 =
- 🚸 Show plugin only in network admin for multisite installation
- 🚸 Add option to hide plugin in Screen Options settings
- 🪝 Improve `external_repositories` filter: you can now register a repository without providing an empty ID and a default URL (https://registry.wp-content.io/ will be used).
- 📝 Add help screen in repositories settings

= 1.1 =
- ✨ Add external themes support
- 🚸 Improve error display
- 💄 Clean error display for plugins
- 🐛 Fix icon display on the plugin update screen
- 🍱 Add plugin icon

= 1.0.8 =
- 🚸 Display first repository settings when clicking on the menu link
- 🚸 Update redirect URL after repository deletion
- 🚸 Display readme.txt content when clicking on the "View details" link in the plugin page
- 🧱 Updated type hints and class properties for compatibility, switched to PHP 7.1 as the minimum version, and improved docblocks for better clarity. Removed redundant syntax, ensuring cleaner and more compliant code.
- 🔒️ Add access control and secure directories with index files

= 1.0.7 =
- 🚸 Display API Key field input in repository settings when the value is empty
- 🐛 Fixed an issue where an empty key would still add the x-api-key header, potentially causing errors with some APIs, including wp-content.io.

= 1.0.6 =
- 🛂 Add encryption for secrets in database

= 1.0.5 =
- 🎉 Initial release with plugin source management
