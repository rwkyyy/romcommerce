=== RomCommerce ===
Contributors: rwky
Tags: woocommerce, romania, checkout, gdpr, invoicing
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6
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
* **Price history (Omnibus)**: logs every price change and shows the lowest price of the previous 30 days next to a sale price, to support the EU Omnibus Directive.
* **PF/PJ billing fields**: individual-vs-company billing fields at checkout, written where the Romanian invoicing-plugin ecosystem (SmartBill, Oblio, EasySales) reads them.
* **VAT ID validation**: CUI/CIF format and checksum validation at checkout.
* **Related-orders finder (MAOF)**: flags a customer's possibly-related orders and duplicate active orders for staff review. Decision support only: it never cancels, blocks, or blacklists anyone.

**Checkout & billing**

* **Counties & postcodes**: Romanian county data plus 6-digit postcode validation at checkout.
* **Hide voucher at checkout**: removes the coupon field so shoppers don't leave to hunt for a code.
* **Multiple delivery addresses**: lets customers save more than one delivery address on their account.

**Storefront & merchandising**

* **Free shipping bar**: a "spend X more for free shipping" progress indicator.
* **Sale category sync**: keeps a chosen product category populated with everything currently on sale.
* **Related products**: rule-based control over the related-products block.
* **Multi-product review page**: one page where a customer reviews every product from a past order at once.
* **WhatsApp / contact FAB**: a floating multi-channel contact button with a contextual WhatsApp link.
* **Clear cart**: a one-click "empty cart" action.
* **Coupon cleanup**: automatically moves expired coupons to the trash every day, with an optional setting to permanently empty the coupon trash after a set number of days.

**Admin & branding**

* **Admin & login branding**: replaces the WordPress logo on the login screen with your site logo, and applies one brand colour across wp-admin, the login screen, and buttons.

= RomCommerce Pro =

A separate RomCommerce Pro plugin (sold separately, not installed from here) adds advanced modules such as live VIES VAT validation, phone and email validation, postcode-based address autocomplete, one-click order confirmation, pickup workflows, and a compliance checklist. RomCommerce Lite is complete on its own. Pro is optional.

= External services =

RomCommerce (this free plugin) makes **no external network requests** and sends **no data to any third party**. The SAL pictogram, the EU legal guarantee notice/GARAN label, and the WhatsApp contact button are ordinary outbound links your visitors choose to click; the plugin itself does not transmit anything.

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

No. This free version makes no external requests and integrates with no third-party service.

= Is it compatible with High-Performance Order Storage (HPOS)? =

Yes. RomCommerce declares HPOS compatibility and accesses orders only through WooCommerce's CRUD layer, so it works with both HPOS and the legacy order storage.

== Screenshots ==

1. The RomCommerce admin panel with modules grouped by category.
2. A module settings pane.
3. The "lowest price in the last 30 days" Omnibus notice on a product page.
4. The floating multi-channel contact button.

== Changelog ==

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

= 0.6 =
Adds a Placements screen showing where each module appears on your store, plus improvements to admin & login branding, the WhatsApp/Contact button, and the SAL pictogram. Existing settings keep their current look.

= 0.3 =
Bug fixes and refinements across several modules, including a checkout billing-fields fix and a saved-delivery-address fix. See the changelog for details.

= 0.2 =
First public release on WordPress.org.
