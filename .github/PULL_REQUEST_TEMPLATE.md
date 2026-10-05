<!--
Thanks for contributing! Pull requests target the `develop` branch.
Security fixes: please report privately first (see SECURITY.md).
-->

## Summary

<!-- What does this change and why? -->

Fixes #

## How it was tested

<!-- WordPress / PHP versions, single site or multisite, steps followed, unreachable-source case… -->

## Checklist

- [ ] The PR targets `develop`.
- [ ] Every commit is signed off by its author (`git commit -s`, see [DCO](https://developercertificate.org) in CONTRIBUTING.md).
- [ ] `composer lint` and `composer phpcs` pass, and the code stays PHP 7.1 compatible.
- [ ] Tested on a local WordPress (and on multisite if settings, capabilities or storage are touched).
- [ ] A failing or slow source still does not break the site (no fatal error, no PHP warning).
- [ ] `languages/external-repositories.pot` is regenerated if strings changed.
- [ ] `readme.txt` has a changelog entry if the change is user-facing (version and `Stable tag` left unchanged).
- [ ] Public hooks and the server API stay backward compatible (or the breaking change is explained above).
