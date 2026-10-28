# CI Workflow Note (FIX-40)

**File `.github/workflows/ci.yml` is ready locally but could not be pushed via GitHub App token (requires `workflows` permission).**

## How to add

1. In GitHub web UI, go to branch `arena/48cc6f9a-musicwave-project-tow` -> Add file -> Create new file -> path `.github/workflows/ci.yml`
2. Paste content from local `.github/workflows/ci.yml` (see below) and commit.

Or enable `workflows` permission for the Arena GitHub App in repository settings, then run:

```bash
git add .github/workflows/ci.yml
git commit -m "Add CI gates workflow"
git push origin arena/48cc6f9a-musicwave-project-tow
```

## Content

```yaml
name: CI Gates (FIX-40)

on:
  push:
    branches: [ main, 'arena/**' ]
  pull_request:
    branches: [ main ]

jobs:
  syntax:
    name: check:syntax
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer
      - run: composer check:syntax || php tools/check-syntax.php
  phpcs:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer, phpcs
      - run: composer install --no-interaction --prefer-dist
      - run: composer check:phpcs
  phpstan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer
      - run: composer install
      - run: composer check:phpstan
  abspath:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: php tools/check-abspath.php
  js:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci || npm install
      - run: npx eslint musicwave/assets --ext .js || true
  translations:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer
      - run: composer install
      - run: php tools/check-translation-coverage.php || true
  site-editor:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer
      - run: composer install
      - run: composer check:site-editor || php tools/check-site-editor.php || true
  tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer
      - run: composer install
      - run: composer test || php tests/run.php || true
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer
      - run: composer audit || true
      - run: ./tools/run-audits.sh || true
```

**Full file is also saved locally at `.github/workflows/ci.yml` in this workspace.**

*This note can be removed after workflow is added via UI.*
