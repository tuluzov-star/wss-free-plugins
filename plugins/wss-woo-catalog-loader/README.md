# WSS Woo Catalog Loader

A lightweight WooCommerce catalog loader for **classic product archive markup**. It adds Infinite Scroll, a Load More button, or AJAX pagination without requiring jQuery.

## Features

- Infinite Scroll powered by `IntersectionObserver`.
- Load More button mode.
- AJAX pagination with crawlable native links as a fallback.
- Optional separate mobile behavior.
- Original catalog URL stays unchanged during Infinite Scroll and Load More.
- History API support for AJAX pagination.
- `sessionStorage` response cache.
- `AbortController`, retry handling, and no destructive error replacement.
- Configurable selectors for custom classic WooCommerce themes.
- JavaScript lifecycle events for integrations.
- No external CDN assets and no jQuery dependency.

## Compatibility

Version 1.1 targets classic WooCommerce product archive markup (`.products`, `.product`, `.woocommerce-pagination`). It intentionally does **not** rewrite WooCommerce Product Collection Blocks. Block-based archives keep WooCommerce's native Interactivity API pagination.

Tested with the production implementation that the plugin was originally built for, and packaged for WordPress 6.5+ / PHP 7.4+ / WooCommerce 8.0+.

## Installation

1. Download `wss-woo-catalog-loader.zip`.
2. In WordPress open **Plugins → Add New → Upload Plugin**.
3. Upload and activate the ZIP.
4. Open **WooCommerce → Catalog Loader**.

The defaults use Infinite Scroll on desktop and mobile.

## JavaScript events

The plugin dispatches events on `document`:

- `wss:catalog:ready`
- `wss:catalog:before-load`
- `wss:catalog:loaded`
- `wss:catalog:error`

Example:

```js
document.addEventListener('wss:catalog:loaded', (event) => {
  console.log(event.detail.items);
});
```

Use `wss:catalog:loaded` to reinitialize theme scripts, analytics, quick-view widgets, sliders, or other behaviors for newly inserted product cards.

## Modes

### Infinite Scroll

The next WooCommerce page is fetched automatically before the user reaches the end of the current product list. The browser URL does not change.

### Load More

The next page is fetched only when the visitor clicks the button. The browser URL does not change.

### AJAX pagination

Native pagination links stay in the document, but normal clicks are handled through `fetch()`. The URL can be updated through the History API.

## Development

There is no build step. The plugin contains plain PHP, JavaScript, and CSS.

Main directory and plugin bootstrap filename are intentionally stable:

```text
wss-woo-catalog-loader/
└── wss-woo-catalog-loader.php
```

This lets WordPress replace older versions instead of installing a duplicate plugin.

## License

GPL-2.0-or-later.
