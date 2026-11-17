=== RomCommerce ===
Contributors: rwky
Tags: woocommerce, romania, checkout, gdpr, invoicing
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.9
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modular Romanian commerce layer for WooCommerce: compliance helpers, localized checkout, and Romanian-market essentials in one plugin.

== Description ==

RomCommerce adapts WooCommerce for the Romanian market. Instead of a dozen single-purpose plugins, you switch on only the modules you need. Every module is optional and fully functional in this free version.

**Important:** RomCommerce gives you tools that *help* you meet Romanian e-commerce obligations. It does not guarantee that your shop is legally compliant; that depends on how your shop is set up and run.

= Modules =

**Compliance & legal**

* **SAL pictogram**: the ANPC SAL pictogram, linking to the SAL dispute-resolution platform.
* **EU legal guarantee notice**: the EU-harmonised notice on the legal guarantee of conformity (mandatory from 27 September 2026), plus the optional EU GARAN label.
* **Price history (Omnibus)**: logs price changes, shows the lowest price from the 30 days before a sale and crosses it out instead of the regular price. The history starts when you switch the module on.

**Checkout & billing**

* **PF/PJ billing fields**: individual or company billing fields, saved where SmartBill, Oblio and EasySales read them.
* **VAT ID validation**: CUI/CIF format and checksum check for Romanian billing addresses.
* **Counties & postcodes**: Romanian counties, postcode validation and an optional locality list.
* **Phone validation**: checks phone numbers at checkout, with a country picker. Works offline.
* **EU VAT rates import**: imports EU countries' VAT rates into WooCommerce's tax tables, for OSS.
* **EU countries**: checks your selling and shipping locations and shipping zones against the EU list, and adds what's missing.

**Cart & fees**

* **Cash on delivery fee**: a fee for cash on delivery orders, optionally waived from an order value.
* **Hide coupon at checkout**: removes the coupon field from checkout.
* **Coupon cleanup**: trashes expired coupons daily and can empty the coupon trash.
* **Free shipping bar**: shows how much more the shopper needs to spend for free shipping.
* **Clear cart**: a one-click button that empties the cart.

**Orders & fulfilment**

* **Related-orders finder (MAOF)**: flags possibly related orders and duplicate active orders for staff review. It never cancels, blocks or blacklists anyone.
* **Multiple delivery addresses**: customers can save more than one delivery address.
* **Payment method filter**: a payment method dropdown on the orders list.
* **Weekly store report**: a weekly email with orders, sales, top products and categories, payment methods and order sources. Totals only, no customer details.

**Products & catalogue**

* **Catalog sorting**: out-of-stock products at the end of shop listings, keeping your sort order, and a "Sort by discount" order with the biggest discounts first. Each has its own switch.
* **Out-of-stock product cleanup**: trashes products out of stock and unchanged for the months you choose.
* **SKU generator**: fills in missing SKUs and never changes an existing one.
* **Category sync**: keeps one category filled with products on sale and another with newly published products.
* **Recommended retail price (PRP)**: the manufacturer's recommended price as its own line on the product page, never crossed out.
* **Related products**: rules for which related products show.
* **Frequently bought together**: a hand-picked bundle on the product page with one Add to Cart button.

**Reviews & trust**

* **Multi-product review page**: customers review every product from an order on one page.
* **Reviews showcase**: a grid of recent product reviews on the shop page or anywhere by shortcode.
* **Reviewer name privacy**: reviewer names show as first name and initials; the full name never reaches the page.
* **Perks shop**: trust badges under Add to Cart and a payment-methods widget.
* **Google Merchant Center widget**: Google's store rating badge and the optional Google Customer Reviews survey opt-in. Needs a verified Merchant Center account.
* **Floating contact FAB**: a floating contact button for WhatsApp, Waze, maps and other channels, plus an optional WhatsApp button under Add to Cart.

**Admin & branding**

* **Admin & login branding**: your logo and brand colour on the login screen and across wp-admin.
* **Show some love**: a small "Comerț online mai simplu cu RomCommerce" credit in the site footer, on the login page, or both. Off unless you switch it on.

= RomCommerce Pro =

RomCommerce Pro is a separate plugin, sold separately and never installed from here. It adds live VIES VAT validation, data updates between releases, email validation, postcode address autocomplete, one-click order confirmation, pickup workflows and a compliance checklist. RomCommerce Lite is complete on its own.

= External services =

RomCommerce's own server-side code makes **no external network requests** and sends **no data to any third party**. The SAL pictogram, the EU legal guarantee notice/GARAN label, and the contact buttons (WhatsApp, Waze, maps and the rest) are ordinary outbound links your visitors choose to click.

One module, off by default, loads third-party services in your visitors' browsers:

* **Google Merchant Center widget** (Google Merchant Center store widget, provided by Google LLC). Only when you enable this module, the visitor's browser loads `https://www.gstatic.com/shopping/merchant/merchantwidget.js` from Google on the pages you choose (every page by default, none on phones if you hide the badge there), and the script shows your store's rating from Google. Google receives the request as from any page that loads a Google-hosted file (including the visitor's IP address, browser details and the page address). The plugin itself sends nothing to Google. [Google Terms of Service](https://policies.google.com/terms), [Google Privacy Policy](https://policies.google.com/privacy).
* **Google Customer Reviews survey opt-in** (part of the same module, provided by Google LLC). Only when you switch it on, and only on the order confirmation page shown right after an order, the customer's browser loads `https://apis.google.com/js/platform.js` and Google's dialog asks whether they want a survey by e-mail after delivery. To show it, the browser sends Google the order number, the customer's e-mail address, the delivery country, the estimated delivery date and the products' GTINs (when set). The plugin's server sends nothing. [Google Customer Reviews Terms of Service](https://support.google.com/merchants/answer/160173), [Google Privacy Policy](https://policies.google.com/privacy).

= Third-party libraries =

* **Romanian localities list** (GPLv2, by Razvan Farte, from States, Cities, and Places for WooCommerce, https://github.com/chitezh/woocommerce_states_places), bundled in `data/ro-localities` for the Counties & postcodes locality list.
* **intl-tel-input** (MIT, https://github.com/jackocnr/intl-tel-input), bundled in `assets/lib/intl-tel-input` for phone validation. Its validation part is compiled from Google's libphonenumber (Apache-2.0, https://github.com/google/libphonenumber); the human-readable source of both is at those links.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the `romcommerce` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
3. Activate RomCommerce through the **Plugins** screen.
4. Open the **RomCommerce** menu in wp-admin and enable the modules you want, configuring each from its settings pane.

== Frequently Asked Questions ==

= Does this make my shop legally compliant? =

No. RomCommerce helps you meet Romanian e-commerce requirements, but compliance depends on your shop and how you run it. The plugin assists; it does not certify.

= Do I need WooCommerce? =

Yes. RomCommerce is a WooCommerce extension and doesn't run without it.

= Is everything really free? =

Yes. No license key, trial or usage limit. RomCommerce Pro is a separate, optional plugin.

= Does the plugin send my data anywhere? =

No. The weekly store report goes out through your site's own mail setup. The one exception is the optional Google Merchant Center widget, which loads Google's script in your visitors' browsers (see External services).

= Does it work with the block cart and checkout? =

Yes, on both the classic and the block cart and checkout. On the block checkout, the PF/PJ fields need WooCommerce 8.9 or newer.

= Can I manage it from the command line or AI tools? =

Yes. `wp romcommerce` (WP-CLI) shows the status, switches modules, runs background jobs and imports EU VAT rates. On WordPress 6.9 or newer the same actions are WordPress abilities, so MCP clients can use them through the WordPress MCP Adapter. The "Show some love" credit can only be switched on from its own settings pane.

= Some RomCommerce elements don't show on my Elementor product pages =

Elementor Pro product templates skip most of WooCommerce's product page hooks, so modules placed on those positions don't show. With Elementor active, RomCommerce marks those positions with *. These positions work: top and bottom of the page, the four Add to Cart positions, product meta and below the tabs. Modules with a shortcode, such as [romcommerce_bought_together], can go in an Elementor Shortcode widget. Elementor Pro's Cart and Checkout widgets show every position. Full list: https://github.com/rwkyyy/romcommerce/wiki/Placement-hook-map

= Is it compatible with High-Performance Order Storage (HPOS)? =

Yes. RomCommerce reads orders only through WooCommerce's CRUD layer, so it works with HPOS and the legacy order storage.

== Screenshots ==

1. The RomCommerce admin panel with modules grouped by category.
2. A module settings pane.
3. The "lowest price in the last 30 days" Omnibus notice on a product page.
4. The floating multi-channel contact button.

== Changelog ==

Each version groups its changes under these labels, in this order:

* **New**: new modules and tools.
* **Added**: new features and settings in existing modules.
* **Fixed**: bug fixes.
* **Updated**: changes to existing behaviour, defaults or wording.
* **Removed**: features taken out.

= 0.9 =

**New**

* SKU generator: fills in missing product and variation SKUs and never changes an existing one.
* EU countries: checks your selling and shipping locations and shipping zones against the EU list, and adds what's missing.
* Cash on delivery fee: a fee for cash on delivery orders, optionally waived from an order value.
* Out-of-stock product cleanup: trashes products out of stock and unchanged for the months you choose, once a day.

**Added**

* Floating contact FAB: Waze and map location channels; channels can be reordered.
* Phone validation: optional delivery phone field on the classic checkout.
* Google Merchant Center widget: choose the pages that show the badge, hide it on phones, and load Google's script after the first scroll or tap (on by default).
* Google Merchant Center widget: offers the Merchant Center ID from Google for WooCommerce.
* Counties & postcodes: optional locality list for the chosen county; postcode validation has its own switch.
* Catalog sorting: a "Sort by discount" order, biggest discount first, worked out once a day at the hour you choose.
* Category sync: optional new products category, filled with products published in the last 30 days (or the days you choose), optionally only those in stock.
* Payment method filter: setting to also list the methods used on past orders.
* Show some love: the credit can also show at the bottom of the login page.
* Admin: "Show only active modules" switch.

**Fixed**

* VAT ID validation: company orders from other EU countries are no longer rejected.
* Google Merchant Center widget: the survey opt-in now shows for card payments awaiting confirmation and on HTTPS sites behind a proxy.
* Welcome notice: goes away once a module is saved.

**Updated**

* Admin: modules regrouped into seven categories; related modules link to each other.
* Payment method filter: lists only switched-on payment methods by default.
* Floating contact FAB: WhatsApp's default colour is #0C7A5F, with white text, also for stores still on the old #25D366.
* Floating contact FAB: sits at the same distance from the screen edges as the Google Merchant Center badge at its default margins.
* Hide coupon at checkout: renamed from Hide voucher.
* Category sync: renamed from Sale category sync; the sale part has its own switch.
* Catalog sorting: renamed from Availability sorting; out-of-stock sorting has its own switch.
* MAOF: the order list column is now "Alte comenzi active", with a tooltip.
* Price history: the settings say the history starts when the module is switched on.
* Google Merchant Center widget: survey opt-in settings moved above Advanced.
* Position settings list left before right.
* Show some love: the credit now reads "Comerț online mai simplu cu RomCommerce".

= 0.8 =

**New**

* Availability sorting: out-of-stock products go to the end of shop listings, keeping the sort order.
* Recommended retail price (PRP): a recommended price per product and variation, shown as its own line, never crossed out.
* Google Merchant Center widget: Google's store rating badge and the optional Google Customer Reviews survey opt-in.
* Phone validation: checks phone numbers at checkout, with a country picker.
* EU VAT rates import: imports EU VAT rates into WooCommerce's tax tables.
* Weekly store report: a weekly email with orders, sales, top products, payment methods and order sources.
* Frequently bought together: a hand-picked bundle on the product page with one Add to Cart button.
* Payment method filter: a payment method dropdown on the orders list.
* Reviews showcase: a grid of recent product reviews on the shop page.
* Reviewer name privacy: storefront reviewer names cut to first name and initials.
* Perks shop: trust badges under Add to Cart and a payment-methods widget.
* Show some love: an optional footer credit, off by default.
* WP-CLI commands (`wp romcommerce`) and WordPress abilities.

**Added**

* Romanian (ro_RO) translation; WordPress.org language packs take priority.
* Block cart and checkout: PF/PJ billing, VAT ID validation, Counties & postcodes (WooCommerce 8.9+), Hide coupon at checkout, Clear cart, Legal guarantee notice, Free shipping bar, Multiple delivery addresses.
* Related products: settings apply to the Related Products block.
* Price history: switch off the crossed-out price or add a discount percentage; [romcommerce_lowest_price] shortcode.
* Floating contact FAB: WhatsApp button under Add to Cart; official logos for Messenger, Facebook, Instagram, YouTube, TikTok and Telegram; button shape setting; searchable dashicon picker.
* Placements: 15 new positions, block cart and checkout positions, Elementor-hidden positions marked *.
* Settings: click a shortcode to copy it.

**Fixed**

* Price history: the lowest price uses the 30 days before the sale started and leaves out the current sale price.
* Price history: products already on sale at activation no longer show an unrecorded regular price as their lowest price.
* Legal guarantee notice: no longer overwrites WooCommerce's global product.
* Legal guarantee notice: invalid EU GARAN durations are rejected instead of failing the save.
* Sale category sync: a changed sale category takes effect immediately.
* Admin & login branding: the brand colour wins over WordPress 7's Modern colour scheme.
* Floating contact FAB: shape and colour apply on Elementor sites.
* Placements: product page positions land where labelled on block themes.
* Free shipping bar, Perks shop, WhatsApp button: each takes a full-width line in row-style Add to Cart forms (Elementor, many themes).

**Updated**

* Price history: crosses out the 30-day pre-sale lowest price instead of the regular price, and none when the current price isn't lower. New default wording; custom wording is kept.
* MAOF: finds related orders whatever the phone number format; the past 12 months are indexed in the background.
* Floating contact FAB: renamed from WhatsApp/Contact FAB.
* Free shipping bar: corner radius replaced by a shape (rounded or square) and a thickness (1–5 px, default 5 px).
* Settings: Pro options sit in a marked section at the bottom of each pane.

= 0.6 =

**New**

* Placements screen: shows where active modules appear, links to their settings and sets which footer module shows first.

**Added**

* Admin: one-time welcome notice after activation.
* SAL pictogram: [romcommerce_sal_pictogram] shortcode and a "None" alignment.
* Admin & login branding: custom login logo, login field style (rounded, sharp or underline), button shape, brand colours on WooCommerce dropdowns.
* Floating contact FAB: greeting message for WhatsApp, Telegram and e-mail, optionally with the page link; toggle button colour; rotating channel icons.
* Price history: editable text before the lowest price.

**Fixed**

* Admin & login branding: no more doubled border on focused login fields.
* Floating contact FAB: the toggle button stays with the channel list at bottom right; themes no longer recolour its icons on hover.

**Updated**

* Floating contact FAB: redesigned channel settings.
* Free shipping bar: colours set with colour pickers.

= 0.5 =

**New**

* Admin & login branding: your site logo on the login screen and one brand colour across wp-admin.
* Coupon cleanup: trashes expired coupons daily and can empty the coupon trash after a set number of days (default 30).

**Added**

* EU legal guarantee notice: EU GARAN full-label reveal, product exclusions by taxonomy, your own label image, brand and model fields (including WooCommerce Brands), half-year durations, custom trigger link, links to the EU law text.

= 0.4 =

**New**

* EU legal guarantee notice: the EU-harmonised notice on the legal guarantee of conformity (Directive (EU) 2024/825, Regulation (EU) 2025/1960), mandatory from 27 September 2026. Sitewide, optionally on checkout and product pages, always in the order confirmation email. Optional per-product EU GARAN label, off by default.

= 0.3 =

**Added**

* SAL pictogram: left, centre or right alignment.
* Price history: optional bold or underline for the lowest-price line.
* Free shipping bar: active and inactive colours, corner radius.
* Floating contact FAB: colour and icon per channel (default, image, SVG or dashicon).

**Fixed**

* SAL pictogram: shows inside the theme footer, not after it.
* Multiple delivery addresses: required fields are enforced on save; a saved address picked at checkout becomes the shipping address.
* Sale category sync: the "create category" and "resync" buttons work.
* Multi-product review page: the "create review page" button works.

**Updated**

* PF/PJ billing fields: grouped at the top of the billing form; CUI and company name required for company orders.
* MAOF: shorter order-review notice.
* Sale category sync: clearer explanation of automatic sync.

= 0.2 =

**New**

* First public release on WordPress.org.

= 0.1.0 =

**New**

* First module set: SAL pictogram, price history, PF/PJ billing, VAT ID validation, MAOF, counties & postcodes, hide coupon at checkout, multiple delivery addresses, free shipping bar, sale category sync, related products, multi-product review page, floating contact FAB and clear cart.

== Upgrade Notice ==

= 0.9 =
Four new modules, Sort by discount, Waze and map channels, and fixes. Existing stores: the Merchant Center badge loads after the first scroll or tap, the payment filter lists only switched-on methods, the contact button lines up with the badge, and WhatsApp's #25D366 becomes #0C7A5F.

= 0.8 =
Price history now crosses out the 30-day pre-sale lowest price, not the regular price. On block themes, product page modules move to their set position. Adds Romanian translation.

= 0.6 =
Adds the Placements screen. Existing settings keep their current look.

= 0.3 =
Fixes for the PF/PJ billing fields and saved delivery addresses.

= 0.2 =
First public release on WordPress.org.
