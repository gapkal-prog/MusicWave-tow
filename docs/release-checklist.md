# Release checklist

- Run `php tests/run.php` and the syntax check script.
- Run PHPCS and PHPStan after `composer install` in a supported local/CI environment.
- Run `tests/staging-smoke.php` on a fresh WordPress + WooCommerce staging site.
- Test secure downloads with a file outside the web root; confirm URL tampering, replay, expiry, unauthenticated access, and entitlement changes are denied.
- Test every configured download quality on a purchased release; confirm an issued token cannot be modified to select a different quality.
- Confirm VIP is activated with Core and provider credentials/paths are not committed.
- Regenerate and review all POT files with `composer make-pot`; audit Persian/RTL behavior using `docs/translations.md`.
- Audit licenses, notices, accessibility, responsive layouts, and package contents.
- Build clean archives with `php tools/package-release.php --destination=dist`; verify `checksums.sha256` and the `MANIFEST.sha256` inside every archive before upload.
- Validate one public track, one album, and one podcast page with a structured-data validator. Confirm SEO plugins do not create duplicate Music JSON-LD and no protected asset identifier is present.
- Review the `Release readiness` column and resolve every issue on published releases, especially product mappings, membership levels, collection items, and protected assets.
- On a clean staging site, run the demo importer twice, confirm it does not duplicate content, then remove the demo releases and verify merchant content is untouched.
- Test the public preview player with at least three HTTPS preview files: play/pause, queue order, keyboard focus, previous/next, progress seeking, mobile layout, and Media Session controls where supported. Confirm no private download identifier appears in page markup.
