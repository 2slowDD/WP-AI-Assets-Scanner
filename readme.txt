=== Dr. Speed: AI Assets Scanner – Debloat & Dequeue Unused CSS/JS ===
Contributors: dalibord
Tags: performance, page speed, dequeue, unused css, unused javascript
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.9.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Debloat WordPress: find unused CSS and JavaScript per page, dequeue them with verified unload rules, speed up your site. One-click Code Unloader sync.

== Description ==

Dr. Speed: AI Assets Scanner debloats WordPress by automatically finding unused CSS and JavaScript on individual pages. It scans desktop and mobile pages, VERIFIES asset-unloading candidates, and prepares per-page rules to dequeue unnecessary files.

Unload files where they aren't needed to speed up pages and reduce HTTP requests, page weight, bandwidth, and browser work. Fewer scripts and stylesheets per page means better performance and page speed. Review the results before applying rules.

= Debloat, dequeue, unload: what the scanner does for page speed =

Most WordPress sites load every plugin's CSS and JavaScript on every page: a contact-form script on the blog, a slider stylesheet on checkout, a page-builder bundle on a plain post. AI Assets Scanner is an AI-assisted asset manager for exactly that problem. It finds the unused CSS and unused JavaScript files on each page, checks that removing them is safe, and gives you per-page dequeue rules. Applied with Code Unloader, those rules unload the files on the pages that do not need them, which is the fastest way to debloat a site without touching your theme or plugins.

Official plugin homepage, features, and scan credits:
[https://wpservice.pro/our-products/ai-assets-scanner/](https://wpservice.pro/our-products/ai-assets-scanner/)

= Recommended companion: Code Unloader =

I recommend using AI Assets Scanner with Code Unloader, my free WordPress plugin for unloading CSS and JavaScript. Both plugins are developed by Dalibor Druzinec at WPservice.pro.

Code Unloader:
[https://wordpress.org/plugins/code-unloader/](https://wordpress.org/plugins/code-unloader/)

AI Assets Scanner generates the rules; Code Unloader applies them. Install Code Unloader on the scanned site for one-click Push/Sync or manual JSON import.

Use Sync to add recommendations while keeping your existing rules. Push replaces the active setup after taking a snapshot. You can undo the last Push/Sync.

= What the scanner does =

* **Automatic discovery:** choose pages, posts, and custom post types from your sitemap or database.
* **Desktop and mobile scans:** inspect page assets in a real browser and review page-level recommendations.
* **Safe and Aggressive groups:** separate assets not loaded on a page from loaded assets that passed removal checks.
* **Protected assets:** safeguards retain detected payment, form, anti-spam, analytics, and WordPress core assets and report what was kept.
* **Optimizer awareness:** detect supported tools such as WP Rocket, FlyingPress, LiteSpeed Cache, Autoptimize and Perfmatters, and report scan bypass status.
* **History and exports:** review credit usage, re-download JSON rules, and export scan history to ZIP.
* **Access guidance:** configure Cloudflare/firewall access or HTTP Basic Auth for protected staging pages.

= Free plugin, credit-based scanning service =

The plugin is free; browser scans run on the paid WPservice.pro service. An API key and credits are required. New sites can request free starter credits in Settings.

A billable page scan costs one credit. Extra Time (ET) can add one credit when the extended scan runs. Results show credits used and returned. See the official homepage for current packs.

The scanner does not add scripts or styles to ordinary visitor requests. Its frontend scan helpers run only for requests with a valid one-time scan token.

= Help and support =

Support is handled on the WordPress.org forum. For setup or scanning issues, include your plugin version, scan ID, and the displayed error:
[https://wordpress.org/support/plugin/dr-speed-ai-assets-scanner/](https://wordpress.org/support/plugin/dr-speed-ai-assets-scanner/)

If the plugin helps your site, a review helps others find it:
[https://wordpress.org/support/plugin/dr-speed-ai-assets-scanner/reviews/](https://wordpress.org/support/plugin/dr-speed-ai-assets-scanner/reviews/)

== Installation ==

1. Install and activate **Dr. Speed: AI Assets Scanner** from **Plugins > Add New**, or upload its ZIP file.
2. Install and activate **Code Unloader** if you want to apply the generated rules directly.
3. Open **Dr. Speed: AI Assets Scanner > Settings**. Click **Validate your key** to request starter credits or restore an existing free key, or save your purchased API key.
4. Open the scanner, click **Discover Pages**, select the URLs and click **Start Scan**.
5. Review the Safe and Aggressive recommendations. Use **Sync with Code Unloader**, **Push to Code Unloader**, or download the JSON file for manual import.
6. Clear affected page caches and test desktop/mobile layouts, forms, and other interactions. Use **Undo last Push/Sync** if you need to revert the last application.

== Frequently Asked Questions ==

= How do I dequeue unused CSS and JavaScript in WordPress? =

Run a scan, review the Safe and Aggressive recommendations, then apply them: with Code Unloader installed, click **Sync with Code Unloader** or **Push to Code Unloader**; without it, download the JSON rules and import them into your asset manager. The rules dequeue the listed files only on the pages where they are not needed.

= Does AI Assets Scanner unload CSS and JavaScript by itself? =

Scans generate recommendations without applying changes. You can inspect results without Code Unloader; install it on the scanned site to apply rules with Push/Sync or import the exported JSON. Unloading stops files from loading on selected pages; it does not delete them.

= What is the difference between Safe and Aggressive rules? =

Safe rules target assets not loaded on the scanned page. Aggressive rules target loaded assets that passed the scanner's removal checks. Automated checks cannot cover every interaction or visitor state, so review the rules and test your pages after applying them.

= Is this the same as remove unused CSS, caching, or minification? =

No. AI Assets Scanner targets whole files on specific pages. It complements caching, minification, and remove-unused-CSS tools that reduce selectors inside stylesheets. It does not disable entire plugins.

= Will this speed up my site and improve PageSpeed or Core Web Vitals? =

Unloading unnecessary assets can improve frontend performance. Results depend on your theme, plugins, and other bottlenecks. Compare before-and-after tests; no particular PageSpeed or Core Web Vitals result is guaranteed.

= My site is behind Cloudflare. Will scans be blocked? =

A firewall or rate limit can block scans. Settings provides guidance and a Cloudflare WAF rule using a site-specific scanner header. Review access warnings before relying on an incomplete result.

= What is removed when I delete the plugin? =

The plugin's options, scan history, stored results, scheduled tasks, and saved API key are removed from WordPress. Export your history first if you want to keep it.

= I installed AI Assets Scanner from wpservice.pro before it was on WordPress.org. How do I switch? =

The WordPress.org edition lives in a different plugin folder, so WordPress sees it as a new plugin. Switch in this order:

1. If you want to keep your scan history, open **Scan History** and click **Export to ZIP**.
2. **Deactivate** the old AI Assets Scanner. Do not delete it yet.
3. Install and activate **Dr. Speed: AI Assets Scanner** from **Plugins > Add New**. Your API key, credits, and settings carry over.
4. Remove the old plugin's folder, `wp-content/plugins/ai-assets-scanner`, with FTP or your host's file manager. Do not use the **Delete** link: the old version's delete routine erases the scanner secret, worker address, and scan history that the new plugin now uses.

= I deleted and reinstalled the plugin. Where is my key? =

Deleting the plugin removes the saved key from your site, but the key and its credits stay on wpservice.pro. Open **Settings** and click **Validate your key**: the same key comes back with its remaining credits. Deactivating the plugin does not remove the key.

== External services ==

This plugin connects to two services run by WPservice.pro. Nothing is sent until you either save an API key or click **Validate your key** in Settings.

**wpservice.pro API** (`https://wpservice.pro/wp-json/cu-scanner/v1/`) handles accounts and credits.

* When you click **Validate your key**: your site's domain and the plugin version, to create a free API key (first install) or restore this site's existing one.
* When you save or refresh your key: the API key and your site's domain, to check the key and read your credit balance.
* When you start a scan: the number of pages, your domain, and the API key, to reserve credits. Credits are charged or returned when the scan ends.
* During a scan: status events tied to the scan ID, so the scan can be billed and supported. They contain the names of caching or optimization plugins detected on your site, whether each was paused for the scan, and hashed (unreadable) page paths.
* If someone requests your site with an invalid scan token: one security event with a hashed IP address, user agent, and path, sent at most once every 10 minutes.

**Scanning worker** (a server address given by the wpservice.pro API) renders your pages.

* When you start a scan: the page URLs, your API key, a one-time scan token, the scanner secret the plugin generates for CDN rules, a summary of detected caching or optimization plugins, and the plugin version. If you entered an HTTP Basic Auth login for a staging site in Settings, that login is sent too, so the worker can open protected pages.
* The worker then loads those pages from your site in a headless browser, the same way a visitor would.

The **Buy credits** button opens wpservice.pro with your free key and domain in the address, so the purchase is linked to your site.

Service terms: [https://wpservice.pro/terms-and-conditions/](https://wpservice.pro/terms-and-conditions/)
Privacy policy:[https://wpservice.pro/privacy-policy/](https://wpservice.pro/privacy-policy/) 

== Screenshots ==

1. The initial page
2. Scanning selected pages
3. After scan screen with the option to push/sync findings with Code Unloader 
4. New findings synced. 38 aggressive and 1 safe rule were unloaded from the scanned pages
5. Settings - Validate your key before scanning (get a free API key)

== Changelog ==

= 1.9.5 =
* New Ratings & Reviews and Having issues? boxes at the bottom of the scanner sidebar, linking to the plugin's WordPress.org reviews and support forum.
* The "Found a bug? Get in touch" links to wpservice.pro are gone; support is handled on WordPress.org.
* "Dr. Speed" in the header is no longer bold.

= 1.9.4 =
* Fixed a fatal error on WordPress versions before 7.0 when the scanner loaded a page with its scan token.
* Every setting, option, and hook now uses the plugin's own `drspeed_aias_` prefix. Your API key, settings, and scan history move to the new names automatically on the first page load after updating.
* Fixed PHP warnings on the history page and in the menu badge for incomplete history records.
* New Dr. Speed header on every screen: "Dr. Speed | AI Assets Scanner" with the tagline "Safely debloat your pages with one push of a button."
* Every screen now fits any window width without a sideways scrollbar. On narrow screens, the scan results and the scan history show each URL or scan as a labelled card, and buttons wrap their labels instead of cutting them off.

Earlier release history:
[https://github.com/2slowDD/WP-AI-Assets-Scanner/blob/main/CHANGELOG.md](https://github.com/2slowDD/WP-AI-Assets-Scanner/blob/main/CHANGELOG.md)

== Upgrade Notice ==

= 1.9.5 =
Support and reviews now go through WordPress.org; sidebar links added.

= 1.9.4 =
Fixes a fatal error on WordPress 6.x during scans. Settings move to new names automatically.
