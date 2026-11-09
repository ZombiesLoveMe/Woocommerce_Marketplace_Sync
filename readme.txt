=== Marketplace Sync for WooCommerce ===
Tags: etsy, ebay, google shopping, facebook shop, product feed
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later

Sell everywhere from WooCommerce. Two-way product and stock sync with Etsy and eBay, plus automatic product feeds for Google Shopping, Facebook & Instagram Shop, Pinterest, TikTok and Microsoft (Bing). Connects directly with your own free keys — no third-party servers.

Open "Marketplace Sync" → All platforms and click Connect on any platform.

== What it does ==

* List WooCommerce products on Etsy (simple and variable, up to 2 variation options), with photos, variation photos, tags, materials, weight and dimensions.
* Keep listings up to date automatically whenever you save a product.
* Two-way stock sync:
  - WooCommerce → Etsy: website orders, refunds and edits update Etsy quantities.
  - Etsy → WooCommerce: Etsy orders are checked every few minutes and the sold quantities are taken off WooCommerce stock.
* Deactivates listings when they sell out and reactivates them when stock returns (optional).
* Link listings that are already on Etsy to your products by SKU (or exact title) — nothing on Etsy is changed.
* Imports your old Platy Syncer product links and category mappings in one click.
* Price rules (add a % or fixed amount, round to .99), title templates, default tags, description footer.
* Map WooCommerce categories to Etsy categories and shop sections, and shipping classes to Etsy shipping profiles.
* Per-product overrides in a new "Etsy" tab on the product editor.
* Dashboard, product status column, bulk actions, preview, and an activity log.

== Getting started ==

1. Upload and activate the plugin (WooCommerce must be active). Your site must use HTTPS — Etsy requires it.
2. Open "Marketplace Sync" in the WordPress menu, then click Connect on Etsy. A short setup wizard walks you through:
   a. Creating a free app at https://www.etsy.com/developers/register
   b. Adding the callback URL shown in the wizard to that app.
   c. Pasting the app's Keystring and Shared secret.
   d. Clicking "Connect my Etsy shop" and approving on Etsy.
   e. Choosing a default Etsy category, shipping profile and processing time.
3. If you used Platy Syncer before, go to "Link existing listings" and click "Import from Platy Syncer".
   Otherwise click "Find matches" to link your existing Etsy listings by SKU.
4. Click "Send all stock to Etsy" once on the dashboard so both sides start with the same numbers.
5. To put new products on Etsy, use the Products tab (or the "Sync to Etsy" bulk action on the normal Products screen).

== Good to know ==

* New listings are created as drafts by default so you can check them on Etsy before publishing (Settings → Listing defaults).
* Etsy allows 2 variation options per listing (e.g. Colour + Size). Products with 3 or more show a clear error.
* Matching Etsy sales to variations uses SKUs first, then option names, so giving every variation a SKU is the most reliable setup.
* Etsy charges its normal listing/renewal fees when listings are published or reactivated.
* Background jobs use WooCommerce's built-in Action Scheduler. Keeping the Etsy Sync screen open also pushes queued jobs along.
* Deleting the plugin removes its settings and log but keeps product ↔ listing links (define WES_REMOVE_ALL_DATA as true in wp-config.php to remove those too). Nothing on Etsy is deleted.

== eBay (new in 1.1) ==

* List products on eBay (simple and variable, up to 250 variations and 5 options), with photos, variation photos, item specifics, business policies and optional GPSR manufacturer details.
* "Check with eBay" validates a listing for free (and shows fees) before you list it.
* Two-way stock: WooCommerce/Etsy sales update eBay; eBay orders are checked regularly and taken off WooCommerce stock (which then updates Etsy).
* Sold-out listings are kept alive at 0 using eBay's out-of-stock option, instead of being ended.
* Works with listings made by WP-Lister: one-click import of WP-Lister's product ↔ listing links, plus SKU/title matching. Old WP-Lister eBay titles, categories, brand and condition are reused.
* While WP-Lister is active, eBay → WooCommerce stock is paused so sales aren't counted twice. Deactivate WP-Lister once your listings are linked.
* Setup: create production keys at developer.ebay.com, add the account-deletion notification endpoint and the sign-in redirect (RuName) shown in the plugin, then paste the App ID, Cert ID and RuName.

== Shopping & social feeds (new in 1.2) ==

* Google Shopping (Merchant Center), Facebook & Instagram Shop (Meta Commerce Manager), Pinterest, TikTok catalogue, Microsoft/Bing and any other site that accepts a product feed.
* Setting one up is: click "Create my feed", copy the private link, paste it into the platform. The plugin shows step-by-step instructions and turns green when the platform has collected the feed.
* Buyers check out on your website, so stock is always right: the feed rebuilds within a couple of minutes of any product or stock change, and hourly.
* Each variation is sent as its own item (colour, size, photo, price and stock), grouped under its product.
* Links and formatting are stripped from descriptions. Products without barcodes are sent as "no identifier", which is correct for handmade items.
* Optional: Google product category per WooCommerce category, leave categories or single products out, per-platform price adjustment, custom feed title per product ("Shopping feeds" tab on the product editor).
* If Google Listings & Ads, Facebook for WooCommerce, Pinterest for WooCommerce or TikTok for WooCommerce is active, the plugin warns you so products aren't sent twice.

== Translating ==

The template is languages/woo-etsy-sync.pot. The easiest way is the free Loco Translate plugin, which also creates the files the app screens need.

== For developers ==

Filters: wes_feed_price_function, wes_ebay_title, wes_ebay_description, wes_ebay_price, wes_ebay_quantity, wes_ebay_specifics, wes_ebay_item, wes_listing_title, wes_listing_description, wes_listing_tags, wes_listing_price, wes_listing_quantity, wes_listing_fields, wes_listing_image_ids.
Actions: wes_product_synced, wes_etsy_sale_applied, wes_settings_updated.

== Changelog ==

= 1.3.0 =
* Works for any shop: uses your store's currency and country everywhere, picks the matching eBay site automatically (13 eBay countries), and shows feed prices without tax in the US and Canada.
* Translatable: every screen and message can be translated (translation template in /languages).
* Updates from GitHub: publish a release and every site shows the normal "Update available" button.
* Hosting fixes: feeds still work if the uploads folder isn't writable, aren't cached by page-cache plugins, and are built after WooCommerce has loaded; clearer error if the host is missing PHP SimpleXML.

= 1.2.0 =
* Now a multi-platform tool: new "All platforms" home with one-click Connect for Etsy, eBay, Google Shopping, Facebook & Instagram, Pinterest, TikTok, Microsoft/Bing and any feed-based site.
* New: automatic product feeds (XML + CSV) with private links, collection tracking, warnings for missing photos/prices, and a preview.
* Renamed to Marketplace Sync for WooCommerce (same folder, so it updates in place; all settings and links are kept).

= 1.1.1 =
* eBay setup: if the full eBay sign-in link is pasted into the RuName box, the RuName is extracted from it automatically.

= 1.1.0 =
* New: eBay integration (listing, two-way stock, linking, WP-Lister import) alongside Etsy, with an Etsy/eBay switch in the app.
* Activity log can be filtered per marketplace.

= 1.0.0 =
* Complete rewrite of the discontinued Platy Syncer for Etsy: direct Etsy Open API v3 connection with your own key (including the 2026 keystring:shared-secret requirement and processing profiles), modern admin, two-way stock sync, listing linker and Platy Syncer import.
