# OdooWoo Portal

The OdooWoo customer app for WordPress. Customers with an OdooWoo license manage their dashboards, WooCommerce and Odoo connections, mappings, gift cards, manual syncs and sync logs from pages on your own site. It is built on [Beltoft Licenses](https://github.com/beltoftandersen/beltoft-licenses) and Beltoft Services, and it talks to the OdooWoo service through the signed connection that Beltoft Services stores.

Author: beltoft.net. License: GPL-2.0-or-later. Requires WordPress 6.5, PHP 8.1, WooCommerce, Beltoft Licenses 0.7.0 or newer and Beltoft Services 0.1.1 or newer. Without them (or with older ones) the plugin shows an admin notice and does nothing.

## How it works

- The browser never talks to OdooWoo and never receives a secret. Every request goes to this plugin's REST routes (`odoowoo-portal/v1`), which need a logged-in user and the WordPress REST nonce, name a license, and check that the user owns it before the plugin calls the service.
- The license is the entitlement and the Beltoft Services resource holds the tenant. Nothing is stored twice: no second secret, no second signer.
- The app is read-only while the license is not active, the service is not ready, or the viewer is a support user ("View as support" for shop managers).
- Cards are small vanilla JavaScript files, loaded only on pages that contain that card. No build step.
- My Account → Licenses gets an "Open OdooWoo" button on each of the user's OdooWoo licenses.

## Setup

1. Install and activate the plugin and its dependencies.
2. In Beltoft Services, add an HTTP connection to the OdooWoo service (base URL, HMAC secret).
3. Go to Settings → OdooWoo and choose the connection, the license software slug (default `odoowoo`), the list page and the dashboard page.
4. Put the cards on those two pages, either with the Bricks elements (category `odoowoo`) or the shortcode `[odoowoo_card name="dashboard-list"]`.

Card names: `dashboard-list`, `dashboard-header`, `woo-connection`, `woo-settings`, `odoo-connection`, `odoo-settings`, `payment-mappings`, `tax-mappings`, `price-mappings`, `stock-mappings`, `order-route-mappings`, `order-pricelist-mappings`, `gift-cards`, `manual-sync`, `sync-logs`.

## Bricks

When Bricks is active the plugin registers fifteen elements (one per card), five dynamic data tags (`{odoowoo_dashboard_name}`, `{odoowoo_dashboard_state}`, `{odoowoo_plan_dashboards}`, `{odoowoo_plan_used}`, `{odoowoo_has_plan}`) and an element condition, "Has an OdooWoo plan".

## Inside My Account

When beltoft-services draws the customer's apps in My Account (Apps, `/my-account/apps/<service>/`), the app lives there too, with the dashboard's own shell: the service page lists the store dashboards, `/my-account/apps/<service>/<dashboard>/` and `/<dashboard>/<tab>/` (connections, mappings, gift-cards, sync, logs) show one of them. The dashboard part is a slug of its name, unique within the tenant and remembered in the service's resource; a number in the address is sent on to the slug (301). The Open button of the Apps list and the Open OdooWoo link go there, and a request for the old list or dashboard page (the pages stay) is sent to the matching address for the license's owner. Without that area, or for a support viewer, the two Bricks pages and their `?license=&dashboard=&tab=` addresses are used as before. The cards of each tab can be changed with the `odoowoo_portal_account_tab_cards` filter.

## Order currencies

Under **Order pricelist mappings**, the optional **Order currencies** table maps
Woo currency codes (EUR, DKK, USD, etc.) to Odoo pricelists of the same currency.
Load the pricelists from Odoo, add one row per currency, then save. The service
checks the currency when saving and before creating each order.

Leave the table empty to keep the existing meta-rule/default-pricelist behaviour.
With rows saved, currency mappings take priority and every order currency must
be mapped. Removing all rows restores the previous behaviour. Woo's amounts and
Odoo's company/default currency are unchanged. No mappings are added automatically.

## Hooks

- Filters: `odoowoo_portal_dependencies`, `odoowoo_portal_cards`, `odoowoo_portal_account_tab_cards`, `odoowoo_portal_rate_limit( $limit, $bucket )`.
- Actions: `odoowoo_portal_log( $line )`, `odoowoo_portal_dashboard_deleted`.

## Development

```bash
# PHP suite: run the PHPUnit suite (phpunit.xml.dist) in a WordPress test environment
node --test tests/js                                   # JavaScript logic
wp i18n make-pot . languages/odoowoo-portal.pot --exclude=tests,.internal
```

The service is always faked in tests; no test makes a network call.
