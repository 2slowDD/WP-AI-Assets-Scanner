=== Dr. Speed: AI Assets Scanner ===
Contributors: dalibord
Tags: performance, optimization, dequeue, debloat, unused css
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.9.4
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

This plugin connects to two services run by WPservice.pro. Nothing is sent until you either save an API key or click **Validate your key** in Settings.

**wpservice.pro API** (`https://wpservice.pro/wp-json/cu-scanner/v1/`) handles accounts and credits.

* When you click **Validate your key**: your site's domain and the plugin version, to create a free API key (first install) or restore this site's existing one.
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
2. Open **Dr. Speed: AI Assets Scanner > Settings**. Click **Validate your key** to get a free key with starter credits, or paste an API key from wpservice.pro and click **Save**.
3. Open **Dr. Speed: AI Assets Scanner**, click **Discover Pages**, choose the pages to scan, and click **Start Scan**.
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

= I installed AI Assets Scanner from wpservice.pro before it was on WordPress.org. How do I switch? =

The WordPress.org edition lives in a different plugin folder, so WordPress sees it as a new plugin. Switch in this order:

1. If you want to keep your scan history, open **Scan History** and click **Export to ZIP**.
2. **Deactivate** the old AI Assets Scanner. Do not delete it yet.
3. Install and activate **Dr. Speed: AI Assets Scanner** from **Plugins > Add New**. Your API key, credits and settings carry over.
4. Remove the old plugin's folder, `wp-content/plugins/ai-assets-scanner`, with FTP or your host's file manager. Do not use the **Delete** link: the old version's delete routine erases the scanner secret, worker address and scan history that the new plugin now uses.

= I deleted and reinstalled the plugin. Where is my key? =

Deleting the plugin removes the saved key from your site, but the key and its credits stay on wpservice.pro. Open **Settings** and click **Validate your key**: the same key comes back with its remaining credits. Deactivating the plugin does not remove the key.

== Changelog ==

= 1.9.4 =
* Fixed a fatal error on WordPress versions before 7.0 when the scanner loaded a page with its scan token.
* Every setting, option and hook now uses the plugin's own `drspeed_aias_` prefix. Your API key, settings and scan history move to the new names automatically on the first page load after updating.
* Fixed PHP warnings on the history page and in the menu badge for incomplete history records.
* New Dr. Speed header on every screen: "Dr. Speed | AI Assets Scanner" with the tagline "Safely debloat your pages with one push of a button."
* Every screen now fits any window width without a sideways scrollbar. On narrow screens the scan results and the scan history show each URL or scan as a labelled card, and buttons wrap their labels instead of cutting them off.

= 1.9.3 =
* If a site's free key was already upgraded to a paid key or revoked, the free-key button now says so and asks for the paid key, instead of saving a key that cannot be used. The background retry for it stops.
* When the free-key button fails, the error now gives the reason (for example, too many requests in the last hour) instead of always saying the service did not answer.
* A scan started without an API key (for example after the plugin was deleted and reinstalled) now stops before contacting the service and offers to open Settings, instead of failing with "HTTP 401: Invalid API key". The scanner page shows the same guidance up front.
* The free-key button is now called **Validate your key**. On a first install it creates a free key with starter credits; after a reinstall it restores the site's existing key and remaining credits, and Settings says "Welcome back" with the restored balance.
* The check for a purchased paid key now runs at most once a minute instead of on every visit to Settings.

= 1.9.2 =
* New **Replace API key** button in Settings. It warns that credits on the current key are not transferred, and accepts only a paid API key; the new key's credit balance is shown once it is accepted.
* Once a key is saved, the API key field is read-only; a key can be changed only through Replace API key.

= 1.9.1 =
* Renamed to Dr. Speed: AI Assets Scanner; the plugin folder and text domain are now `dr-speed-ai-assets-scanner`. Installs from wpservice.pro: see the FAQ on switching.
* The menu badge style and the scan-time dependency data are now printed through WordPress's own enqueue functions.

= 1.9.0 =
* First release on WordPress.org. The plugin is now licensed under GPLv2 or later.
* Updates now come from WordPress.org. The built-in update checker was removed.
* Free credits are now requested only when you click **Get free credits**. The plugin no longer contacts wpservice.pro on activation.
* No request is made to wpservice.pro until an API key exists.
* Uninstall now removes every option, transient and scheduled task, including the API key, and never touches settings that belong to the wpservice.pro service plugin on a site running both.
* Coding-standard fixes: text domain, translator comments, prefixed names.

== Upgrade Notice ==

= 1.9.4 =
Fixes a fatal error on WordPress 6.x during scans. Settings move to new names automatically.

= 1.9.3 =
Fixes a site getting stuck on a free key that was already upgraded or revoked.

= 1.9.2 =
Adds a Replace API key option for switching to a paid key.

= 1.9.1 =
Renamed to Dr. Speed: AI Assets Scanner. Sites that installed from wpservice.pro should follow the switching steps in the FAQ.

= 1.9.0 =
First WordPress.org release. Future updates are delivered through WordPress.org.
