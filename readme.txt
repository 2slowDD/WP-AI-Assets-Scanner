=== AI Assets Scanner ===
Contributors: dalibord
Tags: performance, optimization, dequeue, debloat, unused css
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find the CSS and JavaScript each page does not use. An AI scan builds per-page rules to unload them, so pages load less code.

== Description ==

Most WordPress sites load every plugin's CSS and JavaScript on every page, whether the page uses it or not. A contact-form script on the blog, a slider stylesheet on checkout, a page-builder bundle on a plain post: each one costs download and parse time.

AI Assets Scanner finds that dead weight for you. It renders each page in a real browser on both desktop and mobile, records which stylesheets and scripts the page actually needs, and gives you per-page rules to dequeue the rest.

= What you get =

* **Automatic page discovery.** Pages, posts and custom post types, from your sitemap or the database.
* **Safe and aggressive rules.** Safe rules unload assets a page never uses. Aggressive rules also cover assets that are only needed in some situations, so you can review them first.
* **Protected by default.** Payment, form, anti-spam and analytics scripts and WordPress core files are always kept, and the results tell you which were kept.
* **Works with caching and optimization plugins.** WP Rocket, Autoptimize, LiteSpeed Cache, Perfmatters, FlyingPress and others are bypassed during the scan, so the scan sees your real assets.
* **CDN and firewall aware.** Detects Cloudflare and other CDNs and shows how to let the scanner through rate limits.
* **Export or apply.** Download the rules as a JSON file, or push them straight into the companion Code Unloader plugin, with snapshot, versioning and one-click undo.
* **Scan history.** Every scan is kept with its credits, and can be exported to a ZIP file.

= How scanning works =

Scans run on the wpservice.pro scanning service, because rendering pages in a headless browser needs more than a typical web host allows. Each scanned page costs one credit. You can get free starter credits from the Settings screen, and buy more at wpservice.pro. Pages that produce no rules are not charged.

The plugin adds nothing to your public pages. Its code runs in wp-admin, and on the front end only when the scanner itself requests a page with a valid one-time token.

== External services ==

This plugin connects to two services run by WPservice.pro. Nothing is sent until you either save an API key or click **Get free credits** in Settings.

**wpservice.pro API** (`https://wpservice.pro/wp-json/cu-scanner/v1/`) handles accounts and credits.

* When you click **Get free credits**: your site's domain and the plugin version, to create a free API key.
* When you save or refresh your key: the API key and your site's domain, to check the key and read your credit balance.
* When you start a scan: the number of pages, your domain and the API key, to reserve credits. Credits are charged or returned when the scan ends.
* During a scan: status events tied to the scan ID, so the scan can be billed and supported. They contain the names of caching or optimization plugins detected on your site, whether each was paused for the scan, and hashed (unreadable) page paths.
* If someone requests your site with an invalid scan token: one security event with a hashed IP address, user agent and path, sent at most once every 10 minutes.

**Scanning worker** (a server address given by the wpservice.pro API) renders your pages.

* When you start a scan: the page URLs, your API key, a one-time scan token, the scanner secret the plugin generates for CDN rules, a summary of detected caching or optimization plugins, and the plugin version. If you entered an HTTP Basic Auth login for a staging site in Settings, that login is sent too, so the worker can open protected pages.
* The worker then loads those pages from your site in a headless browser, the same way a visitor would.

The **Buy credits** button opens wpservice.pro with your free key and domain in the address, so the purchase is linked to your site.

Service terms: https://wpservice.pro/terms-and-conditions/
Privacy policy: https://wpservice.pro/privacy-policy/

== Installation ==

1. Install the plugin from **Plugins > Add New**, or upload the ZIP file, and activate it.
2. Open **AI Assets Scanner > Settings**. Click **Get free credits**, or paste an API key from wpservice.pro and click **Save**.
3. Open **AI Assets Scanner**, click **Discover Pages**, choose the pages to scan, and click **Start Scan**.
4. When the scan finishes, download the rule file or push it to Code Unloader.

== Frequently Asked Questions ==

= Does this plugin unload anything by itself? =

No. It only produces rules. You apply them by importing the JSON file into an asset manager, or with one click in Code Unloader. Every push can be undone.

= Why do scans need credits? =

Each page is rendered in real desktop and mobile browsers on a remote server. Credits pay for that server time. The plugin itself is free and fully functional; scanning is the paid service.

= Will it break my forms or checkout? =

Scripts for payments, forms, anti-bot and anti-spam protection, analytics, and WordPress core are never unloaded, and the scan reports each one it kept. Aggressive rules are kept separate so you can test them first.

= My site is behind Cloudflare. Will scans be blocked? =

They can be. Settings shows the exact Cloudflare WAF rule that lets the scanner through, using a secret header only your site knows.

= What is removed when I delete the plugin? =

All plugin options, scan history, stored results, scheduled tasks and the saved API key.

== Changelog ==

= 1.9.0 =
* First release on WordPress.org. The plugin is now licensed under GPLv2 or later.
* Updates now come from WordPress.org. The built-in update checker was removed.
* Free credits are now requested only when you click **Get free credits**. The plugin no longer contacts wpservice.pro on activation.
* No request is made to wpservice.pro until an API key exists.
* Uninstall now removes every option, transient and scheduled task, including the API key.
* Coding-standard fixes: text domain, translator comments, prefixed names.

== Upgrade Notice ==

= 1.9.0 =
First WordPress.org release. Future updates are delivered through WordPress.org.
