=== OdooWoo Portal ===
Contributors: beltoft
Tags: odoo, woocommerce, licenses, portal, bricks
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The OdooWoo customer app: dashboards, connections, mappings and syncs for licensed customers, on your own WordPress site.

== Description ==

Built on Beltoft Licenses and Beltoft Services. It needs WooCommerce, Beltoft Licenses 0.7.0 or newer and Beltoft Services 0.1.1 or newer; if one is missing or too old it shows a notice and does nothing else.

* **Dashboards.** Customers create, rename, pause and delete dashboards within their plan.
* **Connections and settings.** WooCommerce and Odoo connections with verification, and the sync settings of each side.
* **Mappings.** Payments, taxes, prices, stock, order routes and order pricelists.
* **Gift cards, manual syncs and sync logs.**
* **Safe by design.** The browser never talks to the OdooWoo service and never sees a secret. Every request is logged-in only, nonce-checked and checked against the license owner. The app is read-only when the license is not active or the service is not ready.
* **My Account.** An "Open OdooWoo" button on each OdooWoo license.
* **Bricks.** Fifteen elements (category OdooWoo), five dynamic data tags and an element condition. The same cards are available with the shortcode `[odoowoo_card name="dashboard-list"]`.

= Hooks =

* Filters: `odoowoo_portal_dependencies`, `odoowoo_portal_cards`, `odoowoo_portal_rate_limit`.
* Actions: `odoowoo_portal_log`, `odoowoo_portal_dashboard_deleted`.

== Installation ==

1. Install and activate WooCommerce, Beltoft Licenses and Beltoft Services, then this plugin.
2. In Beltoft Services, add an HTTP connection to the OdooWoo service.
3. Go to Settings → OdooWoo and choose the connection, the license software slug, the list page and the dashboard page.
4. Put the cards on those pages with the Bricks elements or the shortcode.

== Frequently Asked Questions ==

= Where are the secrets kept? =

In Beltoft Services, encrypted. This plugin stores none and never sends one to the browser.

= What happens to my data if I delete the plugin? =

Settings and the saved task memory are deleted only if "Delete the data when the plugin is deleted" is on. Nothing on the OdooWoo side is ever deleted by uninstalling.

== Changelog ==

= 0.0.1 =
* First version.
