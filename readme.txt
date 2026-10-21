=== RomCommerce ===
Contributors: rwky
Tags: woocommerce, romania, checkout, gdpr, invoicing
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.8
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modular Romanian commerce layer for WooCommerce: compliance helpers, localized checkout, and Romanian-market essentials in one plugin.

== Description ==

RomCommerce is a modular set of tools that adapt WooCommerce for the Romanian market. Instead of installing a dozen small single-purpose plugins, you enable only the modules you need from one coherent system. Every module is optional and every module is fully functional in this free version.

**Important:** RomCommerce provides tools that *help* you meet Romanian e-commerce obligations. It does not, and cannot, guarantee that your shop is legally compliant. That always depends on how your shop is set up and operated. Treat the compliance-related modules as assistance, not as a certificate.

= What's included =

**Compliance & legal helpers**

* **SAL pictogram**: displays the official ANPC SAL pictogram on your homepage, linking to the SAL dispute-resolution platform.
* **EU legal guarantee notice**: displays the official EU-harmonised notice on the legal guarantee of conformity (mandatory EU-wide from 27 September 2026), plus an optional EU GARAN label for products with a qualifying producer durability guarantee.
* **Price history (Omnibus)**: logs every price change, shows the lowest price from the 30 days before a reduction next to the sale price, and crosses out that price instead of the regular price, to support the EU Omnibus Directive.
* **PF/PJ billing fields**: individual-vs-company billing fields at checkout, written where the Romanian invoicing-plugin ecosystem (SmartBill, Oblio, EasySales) reads them.
* **VAT ID validation**: CUI/CIF format and checksum validation at checkout.
* **Related-orders finder (MAOF)**: flags a customer's possibly-related orders and duplicate active orders for staff review. Decision support only: it never cancels, blocks, or blacklists anyone.
* **Reviewer name privacy**: shows product reviewers' names on the storefront as the first name plus initials ("Maria E. I."), trimmed before the page is sent, so the full name never reaches visitors.

**Checkout & billing**

* **Counties & postcodes**: Romanian county data plus 6-digit postcode validation at checkout.
* **EU VAT rates import**: imports the VAT rates of the EU countries you pick (standard, reduced, super-reduced and parking) into WooCommerce's tax tables, for cross-border sales under OSS. The rates ship with the plugin; only rows it created are ever updated.
* **Phone validation**: checks billing and shipping phone numbers against international numbering data, with a country picker on the classic checkout. Works offline: the numbering data ships with the plugin.
* **Hide voucher at checkout**: removes the coupon field so shoppers don't leave to hunt for a code.
* **Multiple delivery addresses**: lets customers save more than one delivery address on their account.

**Orders & reports**

* **Weekly store report**: an email on the day and hour you choose with your orders by status (including on-hold and custom statuses), sales and average order value, top 5 products and categories, each payment method's share of orders (top 3, the rest grouped) and, with WooCommerce's order attribution, the top channels and campaigns. Every section optionally compared with the previous week, the same week last month or the same week last year. Totals only, no customer details.
* **Payment method filter**: a payment method dropdown on the WooCommerce orders list, next to the date and customer filters.

**Storefront & merchandising**

* **Free shipping bar**: a "spend X more for free shipping" progress indicator.
* **Perks shop**: trust and service badges (delivery, returns, secure payment) under Add to Cart, and a payment-methods widget for the footer, both also available as shortcodes.
* **Sale category sync**: keeps a chosen product category populated with everything currently on sale.
* **Recommended retail price (PRP)**: an optional manufacturer's recommended price per product and variation, shown on the product page as a separate labelled line, never crossed out.
* **Related products**: rule-based control over the related-products block.
* **Frequently bought together**: products you pick per product, shown as a bundle with the combined price and one button that adds them all to the cart.
* **Availability sorting**: moves out-of-stock products to the end of shop, category and search listings, keeping your sort order (default or the shopper's choice) within in-stock and out-of-stock products. Optionally moves products on backorder too.
* **Reviews showcase**: recent product reviews from across your store as a grid on the shop page, or anywhere with a shortcode; minimum rating and verified-buyers filters, your own colours, card shape and the order of each card's items.
* **Multi-product review page**: one page where a customer reviews every product from a past order at once.
* **Floating contact FAB**: a floating multi-channel contact button with a contextual WhatsApp link, plus an optional WhatsApp button under Add to Cart.
* **Clear cart**: a one-click "empty cart" action.
* **Google Merchant Center widget**: Google's store widget with your Merchant Center store rating, as a corner badge, and optionally Google Customer Reviews' survey opt-in on the order confirmation page. Requires a Merchant Center account with this website claimed and verified.
* **Coupon cleanup**: automatically moves expired coupons to the trash every day, with an optional setting to permanently empty the coupon trash after a set number of days.

**Admin & branding**

* **Show some love** (optional): a small "Magazin realizat cu RomCommerce" credit in your site footer. Off unless you switch it on.
* **Admin & login branding**: replaces the WordPress logo on the login screen with your site logo, and applies one brand colour across wp-admin, the login screen, and buttons.

= RomCommerce Pro =

A separate RomCommerce Pro plugin (sold separately, not installed from here) adds advanced modules such as live VIES VAT validation, phone numbering data and VAT rate updates between releases, email validation, postcode-based address autocomplete, one-click order confirmation, pickup workflows, and a compliance checklist. RomCommerce Lite is complete on its own. Pro is optional.

= External services =

RomCommerce's own server-side code makes **no external network requests** and sends **no data to any third party**. The SAL pictogram, the EU legal guarantee notice/GARAN label, and the WhatsApp contact buttons are ordinary outbound links your visitors choose to click.

One module, off by default, loads third-party services in your visitors' browsers:

* **Google Merchant Center widget** (Google Merchant Center store widget, provided by Google LLC). Only when you enable this module, every visitor's browser loads `https://www.gstatic.com/shopping/merchant/merchantwidget.js` from Google on each page, and the script shows your store's rating from Google. Google receives the request as from any page that loads a Google-hosted file (including the visitor's IP address, browser details and the page address). The plugin itself sends nothing to Google. [Google Terms of Service](https://policies.google.com/terms), [Google Privacy Policy](https://policies.google.com/privacy).
* **Google Customer Reviews survey opt-in** (part of the same module, provided by Google LLC). Only when you switch it on, and only on the order confirmation page shown right after an order, the customer's browser loads `https://apis.google.com/js/platform.js` and Google's dialog asks whether they want a survey by e-mail after delivery. To show it, the browser sends Google the order number, the customer's e-mail address, the delivery country, the estimated delivery date and the products' GTINs (when set). The plugin's server sends nothing. [Google Customer Reviews Terms of Service](https://support.google.com/merchants/answer/160173), [Google Privacy Policy](https://policies.google.com/privacy).

= Third-party libraries =

* **intl-tel-input** (MIT, https://github.com/jackocnr/intl-tel-input), bundled in `assets/lib/intl-tel-input` for phone validation. Its validation part is compiled from Google's libphonenumber (Apache-2.0, https://github.com/google/libphonenumber); the human-readable source of both is at those links.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the `romcommerce` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
3. Activate RomCommerce through the **Plugins** screen.
4. Open the **RomCommerce** menu in wp-admin and enable the modules you want, configuring each from its settings pane.

== Frequently Asked Questions ==

= Does this make my shop legally compliant? =

No. RomCommerce gives you tools that help you meet Romanian e-commerce requirements (the SAL pictogram, Omnibus price history, PF/PJ billing, and more), but legal compliance always depends on your specific shop and how you run it. The plugin assists; it does not certify.

= Do I need WooCommerce? =

Yes. RomCommerce is a WooCommerce extension and will not run without it.

= Is everything really free? =

Yes. Every module in this plugin is fully functional with no license key, trial period, or usage limit. RomCommerce Pro is a separate, optional plugin.

= Does the plugin send my data anywhere? =

The plugin's own code sends nothing to outside services. The weekly store report, if you switch it on, is emailed through your site's own mail setup to the addresses you choose. The one exception is the optional Google Merchant Center widget: when you enable it, your visitors' browsers load Google's widget script (see External services above).

= Does it work with the block cart and checkout? =

Yes. The checkout fields, validation and cart/checkout widgets work on both the classic (shortcode) and the block cart and checkout. On the block checkout, the PF/PJ fields need WooCommerce 8.9 or newer.

= Can I manage it from the command line or AI tools? =

Yes. `wp romcommerce` (WP-CLI) shows the status, lists and switches modules, runs the background jobs on demand and imports EU VAT rates. On WordPress 6.9 or newer the same actions are registered as abilities (WordPress Abilities API) for users who can manage WooCommerce, so MCP clients can use them through the WordPress MCP Adapter. The footer credit can only be switched on from its own settings pane.

= Some RomCommerce elements don't show on my Elementor product pages =

Product pages built with an Elementor Pro Theme Builder template replace WooCommerce's product template with Elementor widgets, and those widgets don't run most of WooCommerce's product page hooks. A module placed on one of those positions (for example "After the price" or "Below the summary, above the tabs") won't show there. When Elementor is active, RomCommerce marks those positions with * in every position list and on the Placements screen. Positions that work on Elementor product pages: top and bottom of the product page, the four Add to Cart form positions (with Elementor's Add to Cart widget in the template), the SKU, categories and tags positions (Product Meta widget) and Below the tabs (Product Data Tabs widget). Modules with a shortcode, such as [romcommerce_bought_together] and [romcommerce_perks], can go in an Elementor Shortcode widget. Cart and checkout pages built with Elementor Pro's Cart and Checkout widgets show every position. Full list: https://github.com/rwkyyy/romcommerce/wiki/Placement-hook-map

= Is it compatible with High-Performance Order Storage (HPOS)? =

Yes. RomCommerce declares HPOS compatibility and accesses orders only through WooCommerce's CRUD layer, so it works with both HPOS and the legacy order storage.

== Screenshots ==

1. The RomCommerce admin panel with modules grouped by category.
2. A module settings pane.
3. The "lowest price in the last 30 days" Omnibus notice on a product page.
4. The floating multi-channel contact button.

== Changelog ==

= 0.8 =
* New module: Availability sorting. Moves out-of-stock products to the end of shop, category, tag and search results while preserving the selected sort order. Optional support for `[products]` shortcodes.
* New: Romanian (`ro_RO`) translation included. WordPress.org language packs take priority when available.
* Legal guarantee notice: fixed a conflict caused by overwriting WooCommerce’s global product.
* Legal guarantee notice: invalid EU GARAN duration values are now rejected instead of causing save failures.
* Sale category sync: changing the sale category now takes effect immediately.
* Price history: the lowest-price line now uses the 30 days before the sale started. The current sale price is excluded throughout the sale. Default wording: “Cel mai mic preț din cele 30 de zile dinaintea acestei reduceri:”. Custom wording is preserved.
* Price history: products already on sale when the module is enabled no longer use an unrecorded regular price as their lowest price. They show the current price until pre-sale history is available. Each product’s current price is recorded in the background to start its history.
* Price history: crossed-out prices on product, shop and category pages now use the lowest price from the 30 days before the sale. If the current price is not below that reference, no crossed-out price or sale badge is shown. Stored prices stay unchanged. Settings let you disable this display or add a discount percentage.
* New module: Recommended retail price (PRP). Adds an optional field for products and variations. Displays “PRP: 150 lei” on the product page when it exceeds the current price, without a strikethrough or discount percentage.
* MAOF: related orders can now be found regardless of phone-number formatting. Orders from the past 12 months are indexed in the background after updating, with progress shown in MAOF settings. Original phone numbers are preserved.
* Floating contact FAB: optional WhatsApp button under Add to Cart, with the product (and chosen variation) in the message.
* New module: Google Merchant Center widget. Shows Google's store widget with your store rating; position and spacing are configurable.
* PF/PJ billing, VAT ID validation, Counties & postcodes: now work on the block checkout (WooCommerce 8.9+).
* Hide voucher, Clear cart, Legal guarantee notice, Free shipping bar, Multiple delivery addresses: now work on the block cart and checkout.
* Related products: settings now apply to the Related Products block on block themes.
* Placements: the map shows module positions on the block cart and checkout.
* New: WP-CLI commands (`wp romcommerce`) and WordPress abilities for status, modules, background jobs and VAT rates import.
* New module: Show some love. An optional footer credit, off unless you switch it on.
* New module: Reviews showcase. A grid of recent product reviews on the shop page.
* New module: Perks shop. Trust badges under Add to Cart and a payment-methods widget.
* New module: EU VAT rates import. Imports EU member states' VAT rates into WooCommerce's tax tables.
* New module: Phone validation. Checks phone numbers at checkout (classic and block), with a country picker on the classic checkout.
* Admin & Login Branding: the brand colour now also wins over WordPress 7's default Modern colour scheme (submenu hover, admin bar, buttons, form fields).
* Floating contact FAB: renamed from WhatsApp/Contact FAB.
* Floating contact FAB: button shape and colour now apply on Elementor sites, where the theme's button style overrode them.
* Floating contact FAB: the WhatsApp button under Add to Cart defaults to WhatsApp green (#25D366) with dark text.
* Perks shop: each badge can use an image, a custom SVG or a dashicon. New text colour setting.
* Settings: Pro-only options sit in a marked section at the bottom of each pane.
* Settings: click a shortcode in a settings pane to copy it.
* Weekly store report: every section compared with the earlier period; shorter email without repeated figures.
* Google Merchant Center widget: optional Merchant Center ID (paste the ID or Google's whole snippet).
* Google Merchant Center widget: Google Customer Reviews survey opt-in on the order confirmation page (off by default); the store badge can be switched off on its own; region defaults to RO.
* Price history, Recommended retail price: [romcommerce_lowest_price] and [romcommerce_recommended_price] shortcodes, for product pages built with a page builder.
* Reviews showcase: colour settings for the heading, card background, review text, reviewer name and product name; stars use the store's own star style; drop shadow, corner radius and card spacing.
* Reviews showcase: card items can be reordered or hidden, each on its own line; the product name links to the product.
* Reviews showcase: word limit for the review text, cut at whole words.
* Reviews showcase: reviewer names shortened to the first name and initials (on by default); e-mail addresses are never shown.
* New module: Reviewer name privacy. Shortens product reviewers' names on the storefront, server-side.
* Placements: 15 new positions on the product page, cart and checkout, and a link to the hook map.
* Placements: product page positions land where labelled on block themes.
* Placements: with Elementor active, positions Elementor Pro product templates don't show are marked *.
* Free shipping bar, Perks shop, WhatsApp button: each takes its own full-width line inside row-style Add to Cart forms (Elementor, many themes).
* Frequently bought together: [romcommerce_bought_together] shortcode; optional fallback to the bottom of the product page when its chosen position never renders (on by default); notice when no product has picks.
* New module: Weekly store report. A weekly email with orders by status, average order value, top products and categories, payment methods and order sources.
* New module: Frequently bought together. A bundle of hand-picked products on the product page with one Add to Cart button.
* New module: Payment method filter. A payment method dropdown on the orders list.
* Floating contact FAB: official logos for Messenger, Facebook, Instagram, YouTube, TikTok and Telegram.
* Floating contact FAB: button shape setting (circle, rounded, square; the Add to Cart button: rounded, pill, square).
* Floating contact FAB: searchable dashicon picker.
* Free shipping bar: the corner radius setting is replaced by a shape (rounded or square) and a thickness of 1–5 px (default 5 px, was 10 px).

= 0.6 =
* New: Placements screen — a visual map of where your active modules appear on the product page, cart, checkout, and site-wide, with links straight to each module's settings. When more than one module sits in the site footer, you can set which one shows first.
* New: a one-time "RomCommerce is active" notice after activation, linking to the module list. Dismiss it once and it stays gone.
* SAL pictogram: new [romcommerce_sal_pictogram] shortcode, plus a "None" alignment option for placing the pictogram yourself.
* Admin & login branding: optional custom login logo (for themes without a site logo setting), a login field style choice (rounded, sharp, or underline), a separate button shape choice, and brand colours on WooCommerce dropdowns.
* Admin & login branding: fixed login fields showing a doubled border on focus.
* WhatsApp/Contact FAB: custom greeting message for WhatsApp, Telegram, and e-mail, with an option to add a link to the current page; toggle button colour; optional rotating channel icons on the closed button; a redesigned channel settings layout.
* WhatsApp/Contact FAB: fixed the toggle button drifting away from the channel list when positioned bottom-right, and themes recolouring its icons on hover.
* Price history: the text before the 30-day lowest price can now be edited.
* Free shipping bar: colours are now set with colour pickers.

= 0.5 =
* New module: Admin & login branding — replace the WordPress logo on the login screen with your site logo, and apply one brand colour across wp-admin, the login screen, and buttons.
* New module: Coupon cleanup — automatically moves expired coupons to the trash every day, with an optional setting to permanently empty the coupon trash after a configurable number of days (default 30).
* EU legal guarantee notice: the EU GARAN label now supports a full-label reveal on click, taxonomy-based product exclusions, a merchant-uploaded label image, Brand/Trademark and Model identifier fields (including pulling the brand from WooCommerce's native Product Brands), half-year guarantee durations, a customisable trigger link (text and colour), and "more info" links to the underlying EU law text.

= 0.4 =
* New module: EU legal guarantee notice — the official EU-harmonised notice on the legal guarantee of conformity (Directive (EU) 2024/825 / Commission Implementing Regulation (EU) 2025/1960), mandatory EU-wide from 27 September 2026. Shown as a sitewide reminder with an official-artwork modal, with optional placements on checkout and product pages, and always included in the order confirmation email.
* New: optional EU GARAN label for products with a qualifying voluntary producer durability guarantee (free, whole-product, over two years) — a per-product setting, off by default.

= 0.3 =
* SAL pictogram: added left/center/right alignment, and it now renders inside the theme's footer instead of after it.
* Price history: added an optional bold/underline emphasis for the 30-day lowest-price line.
* PF/PJ billing fields: the person-type selector and company/CUI fields now appear at the top of the billing form, grouped together; CUI and company name are required for company orders.
* MAOF: simplified the order-review notice text.
* Multiple delivery addresses: required fields are now enforced when saving an address, and selecting a saved address at checkout correctly applies it as the shipping address.
* Free shipping bar: added active/inactive colour customisation and a configurable corner radius.
* Sale category sync: fixed the "create category" and "resync" buttons, and clarified how automatic sync works.
* Review page: fixed the "create review page" button.
* WhatsApp/Contact FAB: added a per-channel colour picker and icon selector (default, media library image, custom SVG, or dashicon).

= 0.2 =
* First public release on WordPress.org.

= 0.1.0 =
* Initial release with the Lite module set: SAL pictogram, price history (Omnibus), PF/PJ billing, VAT ID validation, related-orders finder, counties & postcodes, hide voucher, multiple delivery addresses, free shipping bar, sale category sync, related products, multi-product review page, WhatsApp/contact FAB, and clear cart.

== Upgrade Notice ==

= 0.8 =
Price history now crosses out the lowest pre-sale price instead of the regular price. Block themes only: product page modules now appear where their position says. Adds Romanian translation, PRP and availability sorting.

= 0.6 =
Adds a Placements screen showing where each module appears on your store, plus improvements to admin & login branding, the WhatsApp/Contact button, and the SAL pictogram. Existing settings keep their current look.

= 0.3 =
Bug fixes and refinements across several modules, including a checkout billing-fields fix and a saved-delivery-address fix. See the changelog for details.

= 0.2 =
First public release on WordPress.org.
