# WSS Free Plugins

Public repository for the Free editions of **Website Support / WSS WordPress and WooCommerce plugins**, developed and maintained by **Alex Tuluzov**.

- Portfolio: https://tuluzov.com/
- Plugin catalog: https://website-support.ru/plugins/
- Contact: alex@tuluzov.com

This repository contains public Free/Lite source code only. Commercial Pro editions, licensing infrastructure, private release archives, and customer data are not stored here.

## Plugins

| Plugin | What it does | Source | Product page |
|---|---|---|---|
| Delivery Zones on Map for WooCommerce | Map-based delivery zones and shipping logic for WooCommerce | [`plugins/delivery-zones-free`](plugins/delivery-zones-free) | https://website-support.ru/plugins/delivery-zones-for-woocommerce/ |
| WSS Order Status Colors for WooCommerce | Visual order-status highlighting in WooCommerce admin | [`plugins/order-status-colors-free`](plugins/order-status-colors-free) | https://website-support.ru/plugins/order-status-colors-for-woocommerce/ |
| WSS Bookings Schedule Lite | Front-end availability schedule for WooCommerce Bookings | [`plugins/bookings-schedule-free`](plugins/bookings-schedule-free) | https://website-support.ru/plugins/wss-bookings-schedule/ |
| WSS Yandex Calendar for WooCommerce Bookings | Booking-to-calendar synchronization integration | [`plugins/yandex-calendar-sync-free`](plugins/yandex-calendar-sync-free) | https://website-support.ru/plugins/yandex-calendar-for-woocommerce-bookings/ |
| WSS WooCommerce Bookings | Custom booking functionality for WooCommerce | [`plugins/wss-woocommerce-bookings`](plugins/wss-woocommerce-bookings) | https://website-support.ru/plugins/wss-woocommerce-bookings/ |
| WSS Woo Catalog Loader | Infinite Scroll, Load More and AJAX pagination for classic WooCommerce product archives | [`plugins/wss-woo-catalog-loader`](plugins/wss-woo-catalog-loader) | https://website-support.ru/plugins/ |

## See the plugins in action

The English-language [WordPress and WooCommerce demo site](https://demo.tuluzov.com/) presents five focused examples. The demos use fictional/sample data and are not a live customer store.

| Demo | What to explore | Mode |
| --- | --- | --- |
| [Delivery Zones](https://demo.tuluzov.com/delivery-zones/) | Address-based delivery areas and checkout pricing | Interactive WooCommerce scenario |
| [WSS Bookings](https://demo.tuluzov.com/bookings/) | Tour availability, ticket types and the WooCommerce booking journey | Interactive product flow |
| [Bookings Schedule](https://demo.tuluzov.com/schedule/) | Availability overview and links to bookable products | Interactive schedule |
| [Order Status Colors](https://demo.tuluzov.com/order-status-colors/) | Status highlighting in a fictional order queue | Safe admin-style preview |
| [Yandex Calendar Sync](https://demo.tuluzov.com/calendar-sync/) | One-way WooCommerce Bookings → Yandex Calendar workflow | Read-only integration walkthrough |

**Compatibility note:** Yandex Calendar Sync integrates with the *official WooCommerce Bookings extension*. The interactive tour booking demo uses the separate *WSS WooCommerce Bookings* plugin. Tour bookings on the demo site should not be interpreted as live Yandex Calendar synchronization.

These pages illustrate the product experience; real-world availability of checkout and server-side actions depends on the demo environment. For engineering case studies and contact information, see [tuluzov.com](https://tuluzov.com/).

## Development focus

The WSS plugin family covers practical WooCommerce business workflows such as:

- custom checkout and order-processing logic;
- booking and scheduling;
- delivery-zone calculations;
- calendar synchronization;
- administrative tools and reporting;
- REST APIs, background jobs, imports and integrations;
- separate Free/Pro product architecture and managed updates.

Typical stack: **PHP, WordPress, WooCommerce, MySQL, JavaScript, AJAX, REST API, WP-Cron / Action Scheduler, CalDAV, JSON / CSV / XML**.

## Installation

Use the plugin-specific ZIP packages from `releases/.../free/` or download the relevant plugin from its product page on website-support.ru.

**Do not install the whole repository ZIP as a single WordPress plugin.**

## About the developer

I am a Senior WordPress / WooCommerce / PHP developer with 20+ years of professional web development experience, focused on custom plugins, integrations, e-commerce workflows, production troubleshooting, and long-term maintenance of existing systems.

More selected work: https://tuluzov.com/
