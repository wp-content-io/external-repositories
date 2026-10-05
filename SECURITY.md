# Security policy

External Repositories installs and updates code on WordPress sites and stores API keys, so we take security reports seriously. Thank you for helping keep its users safe.

## Supported versions

Only the latest release line receives security fixes. Sites update automatically through the plugin's self-update.

| Version | Supported |
| --- | --- |
| 1.3.x (latest) | Yes |
| < 1.3 | No, please update |

## Reporting a vulnerability

**Do not open a public issue, discussion or pull request for a vulnerability.**

Report it privately, by either:

- **GitHub private vulnerability reporting** (preferred): on this repository, open the **Security** tab and click **[Report a vulnerability](https://github.com/wp-content-io/external-repositories/security/advisories/new)**;
- **email**: <support@wp-content.io>, with "Security" in the subject.

Please include:

- the affected version(s) and your WordPress / PHP versions, single site or multisite;
- a description of the issue and its impact (who can exploit it, what they gain);
- steps to reproduce or a proof of concept;
- any suggested fix.

Never include real API keys, credentials or personal data. Redact the `x-api-key` header from logs.

## What to expect

- **Acknowledgement** within 3 business days.
- **Initial assessment** (confirmed or not, severity) within 10 business days.
- **Fix**: we aim to release a fix within 30 days for confirmed issues, faster for critical ones, and keep you informed of progress.
- **Disclosure**: coordinated. We publish a GitHub Security Advisory (with a CVE when relevant) once a fixed version is available, and credit you unless you prefer to stay anonymous. Please keep the details private until then.

## Scope

In scope:

- the code of this plugin (this repository), including the self-update mechanism and the storage of API keys;
- release artifacts published on this repository's GitHub Releases.

The wp-content.io service (registry API, dashboard, downloads) is not part of this repository, but you can report issues about it through the **same channels**; we will route them internally.

Out of scope: vulnerabilities in WordPress core, PHP or third-party plugins and themes (report them to their maintainers), and issues that require an already compromised administrator account or server, unless the plugin makes them worse (for example, a sub-site administrator gaining network-wide powers on multisite is in scope).
