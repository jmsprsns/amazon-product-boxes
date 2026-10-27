=== Amazon Product Boxes ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: amazon, affiliate, amazon associates, product box, comparison table
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free Amazon affiliate product boxes, comparison tables and bestseller lists. Works with Amazon's new Creators API, plus a no-API mode for sites that don't meet Amazon's sales requirement yet.

== Description ==

Add good-looking Amazon affiliate boxes to your posts without paying for a plugin, and without waiting for Amazon to approve you for its API.

Amazon replaced the Product Advertising API with the **Creators API**, and only opens it to Associates accounts with 10 qualifying sales in the last 30 days. Most affiliate plugins simply stop working until then. Amazon Product Boxes doesn't:

* **No-API mode** builds each box from the title and bullet points you write, with the product image linked from Amazon's own image servers, and no price (Amazon only allows prices that come from its API).
* **API mode** pulls Amazon's title, image, feature bullets, price, savings, Prime and star rating, cached and refreshed in the background on your schedule.
* **It switches by itself.** Save your Creators API credentials whenever you like. The plugin asks Amazon once a day, moves your boxes to API mode as soon as your account qualifies, and falls back to no-API mode if it stops qualifying.

Three blocks, plus shortcodes for the classic editor:

* **Amazon Product Box**: one product with its image, bullet points, price, Prime badge and a button. Side-by-side, card or compact layout, and an optional badge like "Best overall".
* **Amazon Comparison Table**: two to ten products side by side, with your own spec rows (battery life, weight, warranty...). Scrolls sideways on phones with the labels pinned.
* **Amazon Bestseller List**: a numbered top list, from products you pick or (API mode) Amazon's top results for a search, refreshed automatically.

Paste an ASIN or any Amazon product link into a block (amzn.to short links work too) or, in API mode, search Amazon without leaving the editor.

**Flags dead links.** Products that go out of stock or disappear from Amazon are flagged on the plugin's Products tab (with a count in the admin menu) and in a note only editors see on the page. Visitors see "Currently unavailable", and products Amazon removed link to an Amazon search instead of a dead page. You can also hide them from visitors.

**Built for Amazon's rules:**

* Prices are shown only when they came from the API in the last 24 hours, with the "as of" time and Amazon's disclaimer.
* Images are always linked from Amazon, never downloaded to your site.
* Every link carries your Store ID and rel="sponsored nofollow", and the affiliate disclosure is added for you (under the first box on a page, or under every one).

**Fast and safe:** pages never wait for Amazon (everything is cached and refreshed by WP-Cron), there's no front-end JavaScript, and the small stylesheet (about 3 KB gzipped) loads only on pages with a box. The Credential Secret is stored encrypted.

Find it under Content Powered > Amazon Product Boxes in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= For developers =

* `amazon_product_box( $asin, $args = array(), $display = true )` prints (or returns) a box from a theme template. `$args`: title, description (one point per line), badge, buttonText, layout.
* `[amazon_product_box asin="B0..." title="" description="Point one|Point two" badge="" button="" layout="horizontal|vertical|compact"]`
* `[amazon_product_table asins="B0...,B0..." titles="A|B" badges="Best overall|" rows="Battery|Weight" specs="10 h;1 kg|8 h;2 kg"]`
* `[amazon_product_list asins="B0...,B0..."]` or `[amazon_product_list keywords="air fryer" count="5" category="HomeAndKitchen" sort="AvgCustomerReviews"]`

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.
* Amazon, Amazon Associates and Prime are trademarks of Amazon.com, Inc. or its affiliates. This plugin isn't made or endorsed by Amazon.

== Installation ==

1. Upload the `amazon-product-boxes` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > Amazon Product Boxes and enter your Amazon Store ID (your tracking ID, like mysite-20) and your Amazon store.
4. Optional: in Associates Central, open Tools > Creators API, create an application and a credential, and paste the Credential ID, Secret and Version into the plugin. If your account doesn't qualify yet, the plugin switches to API mode by itself once it does.
5. Add an "Amazon Product Box", "Amazon Comparison Table" or "Amazon Bestseller List" block to a post and paste an ASIN or Amazon link.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Do I need Amazon's API to use it? =

No. Without the API, boxes show your own title and bullet points with Amazon's product image and a "Check price on Amazon" button. Add Creators API credentials later and the same boxes start showing live prices.

= Why doesn't no-API mode show prices? =

Amazon's Operating Agreement only allows prices that come from its API and are less than 24 hours old. A typed-in price breaks those rules and can cost you your Associates account.

= How does it know when my site qualifies for the API? =

Once a day it asks Amazon with your saved credentials. When Amazon stops answering "not eligible", boxes switch to API mode and you get a one-time notice in the dashboard.

= How is Prime status worked out? =

Amazon's new API no longer reports Prime eligibility for each offer, so the badge appears on items sold by Amazon itself (which always ship with Prime) and on Prime-only deals. Each box can force the badge on or off.

= Does it work with page caching? =

Yes. Boxes are part of the page's HTML. Each shows when its price was fetched, as Amazon requires; if your cache keeps pages for more than a day, set its lifespan to 24 hours or less so visitors see current prices.

= Which Amazon stores does it support? =

All 22 stores the Creators API serves, from amazon.com, .ca and .co.uk to .de, .co.jp, .in, .com.au and .com.br.

== Changelog ==

See https://github.com/jmsprsns/amazon-product-boxes/releases for the full release history.

= 1.0.0 =
* First release.
