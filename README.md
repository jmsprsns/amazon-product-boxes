# Amazon Product Boxes

Free Amazon affiliate product boxes, comparison tables and bestseller lists for WordPress. Works with Amazon's new Creators API, plus a no-API mode for sites that don't meet Amazon's sales requirement (10 qualifying sales in 30 days) yet.

- **No-API mode**: boxes use the editor's own title and bullet points, the product image linked from Amazon's image servers, and no price.
- **API mode**: Amazon's title, image, features, price, savings, Prime and rating, cached in a table and refreshed by WP-Cron.
- **Automatic switch**: with credentials saved, the plugin asks Amazon daily and moves to API mode once the account qualifies (and back if it stops qualifying).
- **Unavailable products** are flagged on the Products tab, in the admin menu and in an editors-only note on the page.

Blocks: `amazon-product-boxes/box`, `/table`, `/list`. Shortcodes: `[amazon_product_box]`, `[amazon_product_table]`, `[amazon_product_list]`. Template tag: `amazon_product_box( $asin )`.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## Automatic updates

Updates come from https://github.com/jmsprsns/amazon-product-boxes through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `amazon-product-boxes.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `amazon-product-boxes.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   git tag -a v1.0.1 -m "1.0.1" && git push origin v1.0.1
   ```

   (or `gh release create v1.0.1 --title 1.0.1 --generate-notes` for a release with notes)

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'AMAZON_PRODUCT_BOXES_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'AMAZON_PRODUCT_BOXES_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## How it works

- **Creators API** (`class-amazon-product-boxes-api.php`): OAuth 2.0 client credentials. Version 3.x credentials get a token from Login with Amazon (`api.amazon.com`, `.co.uk`, `.co.jp`; JSON body, scope `creatorsapi::default`); older 2.x credentials from Cognito (form body, scope `creatorsapi/default`, and `, Version 2.x` in the Authorization header). Tokens last an hour and are cached encrypted. Catalog calls are `POST https://creatorsapi.amazon/catalog/v1/getItems|searchItems` with an `x-marketplace` header, at most one a second. `403 AssociateNotEligible` means the account doesn't qualify yet.
- **Cache** (`class-amazon-product-boxes-products.php`): `{prefix}apb_products` (one row per ASIN and store: status, normalized data, fetched/checked/seen times) and `{prefix}apb_searches` (keyword lists). API data older than 24 hours is never shown. Post meta `_apb_asin` records which posts use which ASINs.
- **Refresh** (`class-amazon-product-boxes-refresh.php`): hourly WP-Cron event refreshes products due for an update in batches of 10, re-checks eligibility daily, and in no-API mode checks each ASIN still exists (Amazon's image CDN serves a 43-byte GIF for unknown ASINs).
- **Rendering** (`class-amazon-product-boxes-render.php`): blocks and shortcodes, drawn from the cache only. The block editor's preview may fetch missing data, within the user's hourly allowance.
- **Editor REST** (`class-amazon-product-boxes-rest.php`): `amazon-product-boxes/v1/lookup` and `/search`, for users who can edit posts, limited per user per hour.
- **Prime**: OffersV2 has no Prime-eligibility field, so the badge shows for offers sold by Amazon itself and Prime-only deals; editors can force it per box.

## Structure

```
amazon-product-boxes.php                      Plugin header, constants, boot, amazon_product_box()
uninstall.php                                 Deletes the settings, cache tables, post meta and cron events
readme.txt                                    WordPress readme; feeds the "View details" popup
includes/
  class-amazon-product-boxes-plugin.php       Settings, API/no-API mode, stores
  class-amazon-product-boxes-api.php          Creators API client
  class-amazon-product-boxes-products.php     Product cache, ASIN/link/image helpers, usage index
  class-amazon-product-boxes-refresh.php      WP-Cron refresh and checks
  class-amazon-product-boxes-render.php       Blocks, shortcodes and markup
  class-amazon-product-boxes-rest.php         Editor lookup and search endpoints
  class-amazon-product-boxes-crypto.php       Encrypts the credential secret and token (libsodium)
  class-amazon-product-boxes-admin.php        Content Powered > Amazon Product Boxes page
  class-amazon-product-boxes-menu.php         Shared top-level "Content Powered" menu
  class-amazon-product-boxes-feedback.php     Shared Submit Feedback and Report a Bug pages
  class-amazon-product-boxes-updater.php      GitHub update checks (PUC wiring)
  class-amazon-product-boxes-icons.php        Inline Lucide icon helper
assets/
  boxes.css                                   Front-end boxes, tables and lists
  block.js, block-editor.css                  The three blocks (no build step)
  admin.css, admin.js                         Settings page
  sample-product.svg                          Made-up product for the design preview
  icon.svg                                    Icon on the update screens
  content-powered.svg                         Sumo logo for the Content Powered menu
icons/                                        The Lucide icons the plugin uses (plus LICENSE)
plugin-update-checker/                        Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-amazon-product-boxes-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-amazon-product-boxes-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Icons

`icons/` holds the [Lucide](https://lucide.dev/icons) icons the plugin uses (copy more from the skeleton when needed). Print one inline with:

```php
echo Amazon_Product_Boxes_Icons::get( 'package', 20 );           // name, size in px
echo Amazon_Product_Boxes_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
