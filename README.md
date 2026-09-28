# Dr. Speed: AI Assets Scanner

[![CI](https://img.shields.io/github/actions/workflow/status/2slowDD/WP-AI-Assets-Scanner/ci.yml?branch=main&label=CI&style=for-the-badge)](https://github.com/2slowDD/WP-AI-Assets-Scanner/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/LICENSE-GPLv2%2B-blue?style=for-the-badge)](LICENSE)
![Version](https://img.shields.io/badge/VERSION-1.9.3-007cba?style=for-the-badge)
![WordPress](https://img.shields.io/badge/WORDPRESS-6.2%2B-21759b?style=for-the-badge)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4?style=for-the-badge)

Find the CSS and JavaScript each WordPress page does not use. An AI scan renders every page on desktop and mobile and builds per-page rules to unload the rest, by [WPservice.pro](https://wpservice.pro).

This is the WordPress.org edition of the plugin (slug `dr-speed-ai-assets-scanner`). The user-facing description, the external-services disclosure and the changelog live in [`readme.txt`](readme.txt), which is what WordPress.org displays.

## How it works

```
AI Assets Scanner plugin  ──→  wpservice.pro API  ──→  scanning worker        ──→  Code Unloader
(this repo, in wp-admin)       (keys and credits)      (headless Chromium)        (applies the rules)
```

1. The plugin discovers your pages (sitemap, then `WP_Query` fallback).
2. It reserves credits on wpservice.pro and sends the page list to the scanning worker.
3. The worker loads each page with a one-time bypass token, so caching and optimization plugins step aside for that request only.
4. The plugin turns the result into safe and aggressive unload rules, which you download as JSON or push into [Code Unloader](https://github.com/2slowDD/Code-Unloader), with snapshot and undo.

Nothing is sent to wpservice.pro until an administrator saves an API key or clicks **Validate your key**. See the *External services* section of [`readme.txt`](readme.txt) for exactly what is sent and when.

## Switching from the wpservice.pro edition (1.8.9 and earlier)

The WordPress.org edition lives in the folder `dr-speed-ai-assets-scanner`, so WordPress treats it as a separate plugin and the old private updater cannot move a site across. On each site: export the scan history if you want to keep it, **deactivate** the old plugin, install and activate the new one (the API key and settings carry over because the option names are unchanged), then remove `wp-content/plugins/ai-assets-scanner` by FTP. Do not use the Plugins-screen **Delete** link on the old copy: its uninstall routine deletes the scanner secret, the worker address and the scan history that the new plugin is now using.

## Requirements

- WordPress 6.2 or later
- PHP 8.0 or later
- An API key from [wpservice.pro](https://wpservice.pro), or free starter credits from the Settings screen
- Optional: [Code Unloader](https://github.com/2slowDD/Code-Unloader) 1.4.4 or later, to apply rules with one click

## Development

```bash
composer install     # PHPUnit + WP_Mock (dev only, never shipped)
composer test        # PHP unit tests
npm test             # admin JavaScript tests (Node 22+)
bin/build-zip.sh     # builds dist/dr-speed-ai-assets-scanner.zip for WordPress.org
```

CI runs the tests plus the static part of the official [Plugin Check](https://github.com/WordPress/plugin-check) on every push, using the same PHPCS sniffs and prefix rules (`tools/plugin-check.xml`).

The release ZIP is built from an explicit allowlist (`bin/build-zip.sh`): only the plugin's PHP, `admin/`, `includes/`, `readme.txt` and `LICENSE` are shipped. Tests, Composer files and Markdown stay in the repository.

## Security

Please report vulnerabilities privately, as described in [SECURITY.md](SECURITY.md).

## License

GPLv2 or later. See [LICENSE](LICENSE).

The wpservice.pro API and the scanning worker are separate hosted services and are not part of this repository.
