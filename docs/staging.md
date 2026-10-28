# WordPress/WooCommerce staging

The repository includes a repeatable local smoke-test entry point. It uses PHP, WP-CLI, and a private MariaDB data directory under `.staging/` (the directory is git-ignored).

After installing WordPress and WooCommerce into `.staging/wordpress`, run:

```powershell
php .staging/bin/wp-cli.phar eval-file tests/staging-smoke.php --path=.staging/wordpress
```

The smoke suite covers M1-M10: foundation, catalog/REST, schema-driven authoring, restricted rendering, WooCommerce ownership, access decisions, token issuance, VIP provider composition, editorial relationships, and frontend block registration.

The local test credentials are only for the disposable staging install:

- URL: `http://127.0.0.1:8088`
- Administrator: `musicwave_admin`
- Password: `MusicWave-Staging-2026!`
- Database: `musicwave_staging` on `127.0.0.1:33071`
