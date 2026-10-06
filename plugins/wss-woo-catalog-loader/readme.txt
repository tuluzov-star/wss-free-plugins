=== WSS Woo Catalog Loader ===
Contributors: wss
Tags: woocommerce, infinite scroll, load more, ajax pagination, products
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Infinite Scroll, Load More and AJAX pagination for classic WooCommerce product archives without jQuery.

== Description ==

WSS Woo Catalog Loader replaces classic WooCommerce archive pagination with one of three loading modes:

* Infinite Scroll using IntersectionObserver.
* Load More button.
* AJAX pagination while keeping native pagination links crawlable and usable without JavaScript.

The plugin fetches the next regular WooCommerce catalog page and imports the product cards from that HTML. This keeps compatibility with classic WooCommerce templates, URL-based filters, sorting and theme hooks without maintaining a separate products REST query.

Additional features:

* No jQuery dependency.
* Optional separate mobile mode.
* Unlimited Infinite Scroll or an optional switch to Load More after N automatic pages.
* Original URL remains unchanged in Infinite Scroll and Load More modes.
* Optional History API in AJAX pagination mode.
* sessionStorage response cache.
* AbortController and retry handling.
* Existing products are never removed when a request fails.
* Configurable theme selectors.
* Lifecycle events: wss:catalog:ready, wss:catalog:before-load, wss:catalog:loaded, wss:catalog:error.
* No external CDN assets.

= Product Collection Blocks =

Version 1.1 intentionally targets classic WooCommerce product archive markup. WooCommerce Product Collection Blocks keep their native Interactivity API behavior and are not modified by this release.

== Installation ==

1. Upload and activate the plugin ZIP.
2. Open WooCommerce -> Catalog Loader.
3. Choose Infinite Scroll, Load More or AJAX pagination.
4. Keep the default selectors unless your classic WooCommerce theme uses custom archive markup.

== Frequently Asked Questions ==

= Does Infinite Scroll change the browser URL? =

No. Infinite Scroll and Load More keep the original catalog URL. URL updates are available only for AJAX pagination, where a paged URL represents the complete visible catalog state.

= Does the plugin require jQuery? =

No.

= What happens if JavaScript is disabled? =

The original WooCommerce pagination is present in the server-rendered HTML, so the catalog remains navigable.

= Can I use custom theme markup? =

Yes, for classic WooCommerce archives. Product container, product item, pagination, next-link and result-count selectors are configurable in WooCommerce -> Catalog Loader.

= Does it support Product Collection Blocks? =

Version 1.1 does not modify Product Collection Blocks. WooCommerce's native block pagination remains active. A dedicated block adapter is planned separately so block interactivity is not emulated unreliably.

== Changelog ==

= 1.1.0 =
* Prepared the plugin for public distribution under GPL-2.0-or-later.
* Added English source strings and bundled Russian localization.
* Added WooCommerce dependency handling and public plugin metadata.
* Added uninstall cleanup.
* Added safe selector handling so an invalid custom selector does not break the whole script.
* Added a no-IntersectionObserver fallback to the Load More button.
* Added product-search archive detection.
* Added reduced-motion handling for AJAX pagination scrolling.
* Product Collection Block pages are left untouched instead of being partially modified.
* Added public documentation and lifecycle-event examples.

= 1.0.6 =
* Mobile now follows the main catalog mode by default.
* Added an explicit Separate Mobile Mode switch.

= 1.0.5 =
* Removed the native WooCommerce result-count output server-side when possible to prevent a visible flash.

= 1.0.4 =
* Result count is handled even on one-page archives without pagination.

= 1.0.3 =
* Improved result-count handling for themes that render multiple counters.

= 1.0.2 =
* Infinite Scroll and Load More no longer rewrite the browser URL.
* History API is limited to AJAX pagination.

= 1.0.1 =
* Fixed product-grid deformation caused by service nodes in the products container.
* Infinite Scroll is unlimited by default.
* Added optional result-count hiding.

= 1.0.0 =
* Initial release.
