# Headline Testing

WordPress plugin for A/B headline experiments on posts and pages: variant assignment, engagement tracking (click, scroll, time on content), reports, CSV export, and automatic or manual winners.

Inspired by the retired [Thrive Headline Optimizer](https://thrivethemes.com/docs/thrive-headline-optimizer-legacy-guide/) workflow, but cache-safe (variants swap in the browser) and SEO-safe (document title and SEO plugins keep the canonical post title until you declare a winner).

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Theme integration: visible headlines need `data-aht-headline` markers (see below)

## Installation

1. Download the latest release ZIP from [GitHub Releases](https://github.com/markfenske84/headline-testing/releases), or clone this repository into `wp-content/plugins/headline-testing`.
2. Activate **Headline Testing** under **Plugins**.

Sites with the plugin already installed receive updates through WordPress when a new GitHub release is published (see **Releases** below).

## Theme integration

Mark each visible headline element with the helper (Andreian theme example):

```php
<h1 class="entry-title"<?php echo function_exists( 'aht_headline_attributes' ) ? aht_headline_attributes( get_the_ID() ) : ''; ?>>
	<?php the_title(); ?>
</h1>
```

Do not filter `the_title` globally; SEO and schema should stay on the stored post title while a test is running.

## Usage

1. Edit a post or page → **Headline A/B Test** meta box → **Create headline test**.
2. Add variations, set thresholds, **Update** the post, then **Start test**.
3. View **Headline Tests** in the admin menu for reports and CSV export.

Force an update check (admin): `/wp-admin/plugins.php?aht_check_updates=1`

## Releases (maintainers)

1. Bump `Version` in `headline-testing.php` and the `AHT_VERSION` constant.
2. Commit and push to `main`.
3. Build the install ZIP:

```bash
chmod +x build-release.sh
./build-release.sh
```

4. Tag and push:

```bash
git tag v1.0.0
git push origin main
git push origin v1.0.0
```

5. Create a GitHub release for the tag and upload `dist/headline-testing.zip` as a release asset (required for automatic updates via Plugin Update Checker).

Or with GitHub CLI:

```bash
gh release create v1.0.0 dist/headline-testing.zip --title v1.0.0 --notes "Release notes here."
```

## License

GPL-2.0-or-later
