# Per-Product Promo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give a product a real promotion percentage that discounts only that product's line in the cart, with an auto-generated "X% OFF" badge.

**Architecture:** A new `products.promo_percent` column drives an auto badge and a per-line discount. The discount is applied once, at `add_to_cart.php`, by storing the **net** (post-promo) unit price as the cart line's `price` (plus `orig_price` for the struck display and `promo_percent` for the tag). Because `price` is net, every existing subtotal/report/receipt path stays correct with no money-logic change; `order_items.price` likewise stores net. Only display (struck price + an "Item Promos" summary row + badge) and one reporting figure (`promotion_discount`) are added.

**Tech Stack:** PHP 8 / MySQLi, vanilla JS + jQuery, XAMPP/Apache, MySQL. No automated test framework — verification is `php -l` lint + manual browser/curl E2E (project convention).

## Global Constraints

- App forces HTTPS; curl must use `https://localhost/Cafe/...` with `-k`.
- Login rate-limit is per-username (`login_attempts.username`); don't trip it during E2E.
- Admin-gated pages (`add_product.php`, `edit_product.php`) require the ADMIN test login (Sokun) — see the test-accounts memory.
- Schema changes go through the existing `_migrate($conn, 'id', fn)` helper in `config.php` (idempotent, tracked in `schema_migrations`). Never mark a migration applied if the last query errored (the helper already guards this).
- Do NOT CLI-restart Apache; the user restarts XAMPP via Control Panel if needed.
- `e()` is menu.php's HTML-escape helper; `htmlspecialchars(...)` is used on the admin pages. Match the file you're editing.
- Money: promo applies to the **drink price only** (base or chosen size), never to add-ons. Net is rounded **per unit**: `round(gross_drink × (1 − promo/100), 2)`.
- Promo range is `0`–`100`; `0` = no promo. Clamp on every write.

---

### Task 1: Schema migrations + shared badge helper

**Files:**
- Modify: `config.php` (after the existing `products_badge_text` migration, ~line 100-102; and in the helper area near the top after settings load)

**Interfaces:**
- Produces: `products.promo_percent` and `order_items.promo_percent` columns (TINYINT UNSIGNED NOT NULL DEFAULT 0). A global function `product_badge_label(array $row): string` returning the badge text to render for a product row (promo wins over free text).

- [ ] **Step 1: Add the two migrations**

In `config.php`, immediately after the existing `products_badge_text` migration block (the one that does `ALTER TABLE products ADD COLUMN IF NOT EXISTS badge_text ...`), add:

```php
_migrate($conn, 'products_promo_percent', function($db) {
    $db->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS promo_percent TINYINT UNSIGNED NOT NULL DEFAULT 0");
});
_migrate($conn, 'order_items_promo_percent', function($db) {
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS promo_percent TINYINT UNSIGNED NOT NULL DEFAULT 0");
});
```

- [ ] **Step 2: Add the shared badge helper**

In `config.php`, after the migrations section (anywhere at top level after `_migrate` calls, before the file's closing logic), add:

```php
/**
 * The badge label to show for a product: a non-zero promo auto-generates "N% OFF"
 * and wins over the free-text badge; otherwise fall back to the manual badge_text.
 */
if (!function_exists('product_badge_label')) {
    function product_badge_label(array $row): string {
        $promo = (int)($row['promo_percent'] ?? 0);
        if ($promo > 0) return $promo . '% OFF';
        return trim((string)($row['badge_text'] ?? ''));
    }
}
```

- [ ] **Step 3: Lint**

Run: `php -l config.php`
Expected: `No syntax errors detected in config.php`

- [ ] **Step 4: Apply migrations by loading any page that requires config**

Run: `curl -k -s -o /dev/null -w "%{http_code}\n" https://localhost/Cafe/login.php`
Expected: `200`

- [ ] **Step 5: Verify columns exist**

Run: `php -r "require 'config.php'; foreach(['products'=>'promo_percent','order_items'=>'promo_percent'] as $t=>$c){ $r=$conn->query(\"SHOW COLUMNS FROM $t LIKE '$c'\"); echo $t.'.'.$c.': '.($r->num_rows?'OK':'MISSING').PHP_EOL; }"`
Expected: both print `OK`

- [ ] **Step 6: Commit**

```bash
git add config.php
git commit -m "feat(promo): add promo_percent columns + badge-label helper"
```

---

### Task 2: Admin — promo_percent input on add + edit forms

**Files:**
- Modify: `add_product.php` (POST handler ~line 10-31; form ~line 467-473; JS ~line 488-494)
- Modify: `edit_product.php` (POST handler ~line 31-63; badge section ~line 773-791; JS ~line 906-927)

**Interfaces:**
- Consumes: `products.promo_percent` column (Task 1).
- Produces: products can be saved with a promo; a non-zero promo greys out the free-text badge and previews "N% OFF".

- [ ] **Step 1: add_product.php — read + clamp promo on insert**

In `add_product.php`, after the `$badge_text` line (~line 10) add:

```php
    $promo_percent = max(0, min(100, (int)($_POST['promo_percent'] ?? 0)));
```

Change the INSERT (line 30-31) to include the column:

```php
    $stmt = $conn->prepare("INSERT INTO products (name, description, price, category, category_id, image, badge_text, promo_percent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdsissi", $name, $description, $price, $category, $category_id, $image_path, $badge_text, $promo_percent);
```

- [ ] **Step 2: add_product.php — add the promo field to the form**

In `add_product.php`, immediately BEFORE the badge input group (the `<div class="input-group">` at ~line 467 containing `name="badge_text"`), insert:

```php
            <div class="input-group">
                <i class="fa-solid fa-percent"></i>
                <input type="number" name="promo_percent" id="promoPercent" min="0" max="100" step="1" value="0"
                       placeholder="Promo % off this product (0 = none)">
            </div>
```

- [ ] **Step 3: add_product.php — badge override JS**

In `add_product.php`, inside the `<script>` block after the existing `badgeText` input listener (~line 494), add:

```javascript
document.getElementById('promoPercent').addEventListener('input', function() {
    var promo = Math.max(0, Math.min(100, parseInt(this.value || '0', 10)));
    var badgeInput = document.getElementById('badgeText');
    var wrap = document.getElementById('badgePreviewWrap');
    var badge = document.getElementById('badgePreview');
    if (promo > 0) {
        badgeInput.disabled = true;
        badgeInput.style.opacity = '0.5';
        badge.textContent = promo + '% OFF';
        wrap.style.display = 'block';
    } else {
        badgeInput.disabled = false;
        badgeInput.style.opacity = '';
        var val = badgeInput.value.trim();
        badge.textContent = val;
        wrap.style.display = val ? 'block' : 'none';
    }
});
```

- [ ] **Step 4: edit_product.php — read + clamp promo, persist in BOTH update branches**

In `edit_product.php`, after the `$badge_text` line (~line 31) add:

```php
    $promo_percent = max(0, min(100, (int)($_POST['promo_percent'] ?? 0)));
```

Update the image-branch UPDATE (line 50-51):

```php
                $stmt = $conn->prepare("UPDATE products SET name=?,description=?,price=?,category=?,category_id=?,image=?,is_available=?,badge_text=?,promo_percent=? WHERE product_id=?");
                $stmt->bind_param("ssdsisisii", $name, $description, $price, $category, $category_id, $image_path, $is_avail, $badge_text, $promo_percent, $id);
```

Update the no-image-branch UPDATE (line 59-60):

```php
        $stmt = $conn->prepare("UPDATE products SET name=?,description=?,price=?,category=?,category_id=?,is_available=?,badge_text=?,promo_percent=? WHERE product_id=?");
        $stmt->bind_param("ssdsiisii", $name, $description, $price, $category, $category_id, $is_avail, $badge_text, $promo_percent, $id);
```

In the `if ($success)` block after `$product['badge_text'] = $badge_text ?: null;` (~line 71) add:

```php
        $product['promo_percent'] = $promo_percent;
```

- [ ] **Step 5: edit_product.php — add promo field to the Promotion Badge section**

In `edit_product.php`, inside the Promotion Badge `section-body` (~line 779), BEFORE the existing `<div class="field">` that holds `name="badge_text"`, insert:

```php
                    <div class="field">
                        <label class="flabel" for="f_promo">Promo % Off <span style="font-weight:400;color:var(--muted)">(0 = none; a non-zero promo replaces the badge with "N% OFF")</span></label>
                        <input type="number" id="f_promo" name="promo_percent" min="0" max="100" step="1"
                            value="<?= (int)($product['promo_percent'] ?? 0) ?>">
                    </div>
```

- [ ] **Step 6: edit_product.php — badge override JS**

In `edit_product.php`, inside the `<script>` block after `function clearBadge() {...}` (~line 927), add:

```javascript
const fPromo = document.getElementById('f_promo');
fPromo.addEventListener('input', applyPromoBadge);
function applyPromoBadge() {
    const promo = Math.max(0, Math.min(100, parseInt(fPromo.value || '0', 10)));
    if (promo > 0) {
        fBadge.disabled = true; fBadge.style.opacity = '0.5';
        const txt = promo + '% OFF';
        badgeLive.textContent = txt; badgeLiveRow.style.display = 'flex';
        ppBadge.textContent   = txt; ppBadgeRow.style.display   = 'flex';
        if (imgBadge) { imgBadge.textContent = txt; imgBadge.style.display = 'flex'; }
    } else {
        fBadge.disabled = false; fBadge.style.opacity = '';
        updateBadge();
    }
}
applyPromoBadge();
```

- [ ] **Step 7: Lint**

Run: `php -l add_product.php && php -l edit_product.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 8: Browser E2E**

Log in as ADMIN. Open `edit_product.php?id=<a product id>`, set Promo % Off to `15`, confirm the badge preview shows "15% OFF" and the badge-text field greys out, Save. Reload and confirm `15` persists.

Run to confirm persistence:
`php -r "require 'config.php'; \$r=\$conn->query('SELECT product_id,promo_percent FROM products WHERE promo_percent>0'); while(\$x=\$r->fetch_assoc()) echo \$x['product_id'].'='.\$x['promo_percent'].PHP_EOL;"`
Expected: your edited product id with `=15`.

- [ ] **Step 9: Commit**

```bash
git add add_product.php edit_product.php
git commit -m "feat(promo): promo_percent input on product add/edit forms"
```

---

### Task 3: Menu — badge derivation + product promo data attribute

**Files:**
- Modify: `menu.php` (top-sellers card ~line 707/715-717; price-sort card ~line 752/760; category card ~line 804/812; modal `openModal` ~line 1279-1348 + `openModalFromCard` ~line 1356)

**Interfaces:**
- Consumes: `product_badge_label()` (Task 1); product rows already carry `promo_percent` via `SELECT p.*`.
- Produces: cards render the promo badge and expose `data-product-promo`; the modal shows the badge and net price.

- [ ] **Step 1: Top-sellers card badge + data attr**

In `menu.php`, in the top-sellers loop, replace the badge line (~line 715-717):

```php
                <?php if (!empty($t['badge_text'])): ?>
                <span class="product-badge seller-badge"><?= e($t['badge_text']) ?></span>
                <?php endif; ?>
```

with:

```php
                <?php $__badge = product_badge_label($t); if ($__badge !== ''): ?>
                <span class="product-badge seller-badge"><?= e($__badge) ?></span>
                <?php endif; ?>
```

And add a promo data attr to the seller-card element — after the `data-product-badge=...` line (~line 707):

```php
                 data-product-promo="<?= (int)($t['promo_percent'] ?? 0) ?>"
```

- [ ] **Step 2: Price-sort card badge + data attr**

In the price-sort product loop, replace (~line 760):

```php
                  <?php if (!empty($p['badge_text'])): ?><span class="product-badge"><?= e($p['badge_text']) ?></span><?php endif; ?>
```

with:

```php
                  <?php $__badge = product_badge_label($p); if ($__badge !== ''): ?><span class="product-badge"><?= e($__badge) ?></span><?php endif; ?>
```

And add after the `data-product-badge=...` line (~line 752):

```php
                   data-product-promo="<?= (int)($p['promo_percent'] ?? 0) ?>"
```

- [ ] **Step 3: Category card badge + data attr**

In the category product loop, replace (~line 812):

```php
                  <?php if (!empty($p['badge_text'])): ?><span class="product-badge"><?= e($p['badge_text']) ?></span><?php endif; ?>
```

with:

```php
                  <?php $__badge = product_badge_label($p); if ($__badge !== ''): ?><span class="product-badge"><?= e($__badge) ?></span><?php endif; ?>
```

And add after the `data-product-badge=...` line (~line 804):

```php
                   data-product-promo="<?= (int)($p['promo_percent'] ?? 0) ?>"
```

- [ ] **Step 4: Thread promo into openModal + show net price**

In `menu.php`, change the `openModal` signature (~line 1279) to accept `promo`:

```javascript
function openModal(id, name, price, img, cat, desc, badge, hasSizes, sizes, addons, promo) {
  var p = Number(price) || 0;
  var promoPct = Math.max(0, Math.min(100, parseInt(promo || 0, 10)));
  product = { id: id, name: name, price: p, cat: cat, promo: promoPct };
```

Add a helper just above `openModal` (after `escH`, ~line 1275):

```javascript
// Net drink price after a per-product promo (rounded per unit, mirrors the server).
function promoNet(gross, promoPct) {
  if (!promoPct) return gross;
  return Math.round(gross * (1 - promoPct / 100) * 100) / 100;
}
```

Inside `openModal`, where the base price is displayed (~line 1288), make it net:

```javascript
  document.getElementById('modalPrice').textContent = '$' + promoNet(p, promoPct).toFixed(2);
```

In the size-pill block, where the default size sets `modalUnitPrice` (~line 1316-1318), keep `modalUnitPrice` as the GROSS size price but show net:

```javascript
    var def = pills.querySelector('[data-value="M"]') || pills.firstChild;
    if (def) { def.classList.add('active'); modalUnitPrice = Number(def.dataset.price) || p; }
    document.getElementById('modalPrice').textContent = '$' + promoNet(modalUnitPrice, promoPct).toFixed(2);
```

- [ ] **Step 5: Make the modal total reflect the promo**

In `menu.php`, update `updateModalTotal` (~line 1361) so the drink portion is discounted but add-ons are not:

```javascript
function updateModalTotal() {
  var net = promoNet(modalUnitPrice, (product.promo || 0));
  document.getElementById('modalTotalDisplay').textContent = '$' + ((net + modalAddonTotal) * modalQty).toFixed(2);
}
```

If `selectSize` sets `modalUnitPrice` and updates `modalPrice` elsewhere, ensure that display also uses `promoNet(...)`. Locate `function selectSize` and, wherever it sets `document.getElementById('modalPrice').textContent`, wrap the value in `promoNet(modalUnitPrice, (product.promo||0))`.

- [ ] **Step 6: Pass promo from the card opener**

In `menu.php`, update `openModalFromCard` (~line 1356) to pass the attr:

```javascript
  openModal(card.dataset.productId, card.dataset.productName||'', Number(card.dataset.productPrice||0), card.dataset.productImage||'', card.dataset.productCategory||'', card.dataset.productDesc||'', card.dataset.productBadge||'', card.dataset.productHasSizes==='1', sizes, addons, Number(card.dataset.productPromo||0));
```

If any other call site calls `openModal(...)` (grep `openModal(`), pass a trailing `0` or the product's promo so the new parameter is defined.

- [ ] **Step 7: Lint + visual**

Run: `php -l menu.php`
Expected: `No syntax errors detected in menu.php`

Browser: reload `menu.php`. The promo product shows a "15% OFF" badge on its card. Opening it shows the badge and a discounted unit price/total; add-ons still add full price.

- [ ] **Step 8: Commit**

```bash
git add menu.php
git commit -m "feat(promo): auto badge on menu cards + net price in product modal"
```

---

### Task 4: add_to_cart.php — compute net, store promo fields on the line

**Files:**
- Modify: `add_to_cart.php` (product SELECT ~line 55; size/price resolution ~line 97-125; cart line push ~line 152-166)

**Interfaces:**
- Consumes: `products.promo_percent`.
- Produces: every cart line carries `price` (net unit), `orig_price` (gross unit), `promo_percent`.

- [ ] **Step 1: Fetch promo_percent with the product**

In `add_to_cart.php`, change the product SELECT (line 55):

```php
$stmt = $conn->prepare("SELECT product_id, name, price, image, has_sizes, promo_percent FROM products WHERE product_id = ?");
```

- [ ] **Step 2: Compute net drink price before add-ons are folded in**

In `add_to_cart.php`, the current flow sets `$line_price` to the size/base price, then at line 125 does `$line_price += $addon_sum;`. Replace that single line (line 125) with:

```php
// ── Per-product promo: discount the drink only, not add-ons. Round per unit. ──
$promo_percent = max(0, min(100, (int)($p['promo_percent'] ?? 0)));
$gross_drink   = $line_price;                                   // size or base price, pre-addons
$net_drink     = $promo_percent > 0 ? round($gross_drink * (1 - $promo_percent / 100), 2) : $gross_drink;
$orig_price    = $gross_drink + $addon_sum;                     // gross unit (for struck display)
$line_price    = $net_drink   + $addon_sum;                     // net unit (charged + summed everywhere)
```

- [ ] **Step 3: Store the new fields on a fresh cart line**

In `add_to_cart.php`, in the `if (!$found)` push (line 153-166), add `orig_price` and `promo_percent` (keep `price` = `$line_price`, now net):

```php
    $_SESSION['cart'][] = [
        'product_id'   => $p['product_id'],
        'product_name' => $p['name'],
        'price'        => $line_price,
        'orig_price'   => $orig_price,
        'promo_percent'=> $promo_percent,
        'image'        => $p['image'],
        'size_code'    => $resolved_code,
        'size_label'   => $size_label,
        'size_factor'  => $size_factor,
        'sweetness'    => $sweetness,
        'ice'          => $ice,
        'milk'         => $milk,
        'addons'       => $addons,
        'qty'          => $qty,
    ];
```

(The merge branch at line 136-149 needs no change: an identical re-add has the same promo, so the same `price`; it just bumps `qty`.)

- [ ] **Step 4: Lint**

Run: `php -l add_to_cart.php`
Expected: `No syntax errors detected in add_to_cart.php`

- [ ] **Step 5: Verify via curl (net price on the line)**

Get a CSRF token + session by loading menu, then add a promo product. Simplest check — after adding through the browser, dump the session cart is not directly accessible; instead verify the math in isolation:

`php -r "\$g=1.50;\$p=15;echo round(\$g*(1-\$p/100),2);"`
Expected: `1.28`

Then in the browser: add the promo product to the cart; the cart panel line should show `$1.28` (net), not `$1.50`.

- [ ] **Step 6: Commit**

```bash
git add add_to_cart.php
git commit -m "feat(promo): store net price + orig_price + promo_percent on cart lines"
```

---

### Task 5: Cart panel — struck price, Item Promos row (server + JS + refresh)

**Files:**
- Modify: `menu.php` (cart calc ~line 24-38; cart-item render ~line 878-895; summary rows ~line 918-935; JS `renderCartPanel` items ~line 1446-1472 and summary ~line 1494-1504)
- Modify: `cart_refresh.php` (subtotal loop ~line 11-18; items_out ~line 57-75; JSON out ~line 78-92)

**Interfaces:**
- Consumes: cart line `price` (net), `orig_price`, `promo_percent` (Task 4).
- Produces: cart shows struck original + net per promo line and an `Item Promos −$X.XX` summary row. `cart_refresh.php` emits `orig_price`, `promo_percent` per item and an `item_promos` total.

- [ ] **Step 1: menu.php — accumulate item-promo total in the cart calc**

In `menu.php`, in the cart-calculation loop (line 31-38), add an accumulator. Add `$cp_item_promos = 0.0;` next to `$cp_subtotal` declaration (line 27), then inside the loop (after line 33) add:

```php
    $cp_item_promos += (max(0, (float)($item['orig_price'] ?? $item['price']) - (float)$item['price'])) * $q;
```

- [ ] **Step 2: menu.php — struck price on promo cart lines**

In `menu.php`, in the server-rendered cart item (line 894), replace:

```php
            <div class="cp-item-price">$<span id="cp-line-<?= $i ?>"><?= number_format((float)($item['price'] ?? 0), 2) ?></span></div>
```

with:

```php
            <?php $__op = (float)($item['orig_price'] ?? $item['price']); $__pp = (int)($item['promo_percent'] ?? 0); ?>
            <div class="cp-item-price">
              <?php if ($__pp > 0 && $__op > (float)$item['price']): ?>
              <s style="color:#aaa;font-size:11px;margin-right:5px;">$<?= number_format($__op, 2) ?></s>
              <?php endif; ?>
              $<span id="cp-line-<?= $i ?>"><?= number_format((float)($item['price'] ?? 0), 2) ?></span>
              <?php if ($__pp > 0): ?><span style="color:#e74c3c;font-size:9px;font-weight:700;margin-left:4px;"><?= $__pp ?>% OFF</span><?php endif; ?>
            </div>
```

- [ ] **Step 3: menu.php — Item Promos summary row (server)**

In `menu.php`, in the summary rows, after the Subtotal row (line 920-923) and before the Buy3 row, insert:

```php
        <div class="cp-sum-row discount" id="cpItemPromoRow" style="<?= $cp_item_promos > 0 ? '' : 'display:none' ?>">
          <span>&#x1F3F7;&#xFE0F; Item Promos</span>
          <span id="cpItemPromoAmt">-$<?= number_format($cp_item_promos, 2) ?></span>
        </div>
```

- [ ] **Step 4: cart_refresh.php — accumulate + emit item promos**

In `cart_refresh.php`, add `$item_promos = 0.0;` next to `$subtotal` (line 6). Inside the loop (after line 13) add:

```php
    $item_promos += (max(0, (float)($item['orig_price'] ?? $item['price']) - (float)$item['price'])) * $q;
```

In the `$items_out[]` push (line 61-74), add two fields:

```php
        'orig_price'   => (float)($item['orig_price'] ?? $p),
        'promo_percent'=> (int)($item['promo_percent'] ?? 0),
```

In the JSON output (line 78-92), add after the `subtotal` line:

```php
    'item_promos'  => number_format($item_promos, 2, '.', ''),
```

- [ ] **Step 5: menu.php JS — struck price in the rendered item**

In `menu.php`, in `renderCartPanel`'s item builder, replace the price line (line 1461):

```javascript
        '<div class="cp-item-price">$<span id="cp-line-' + item.index + '">' + item.price.toFixed(2) + '</span></div>' +
```

with:

```javascript
        '<div class="cp-item-price">' +
          ((item.promo_percent > 0 && item.orig_price > item.price)
            ? '<s style="color:#aaa;font-size:11px;margin-right:5px;">$' + Number(item.orig_price).toFixed(2) + '</s>' : '') +
          '$<span id="cp-line-' + item.index + '">' + item.price.toFixed(2) + '</span>' +
          (item.promo_percent > 0 ? '<span style="color:#e74c3c;font-size:9px;font-weight:700;margin-left:4px;">' + item.promo_percent + '% OFF</span>' : '') +
        '</div>' +
```

- [ ] **Step 6: menu.php JS — Item Promos summary row**

In `menu.php`, in the summary HTML of `renderCartPanel` (after the Subtotal row, line 1495), insert:

```javascript
    '<div class="cp-sum-row discount" id="cpItemPromoRow" style="' + (parseFloat(data.item_promos) > 0 ? '' : 'display:none') + '">' +
      '<span>&#x1F3F7;&#xFE0F; Item Promos</span><span id="cpItemPromoAmt">-$' + data.item_promos + '</span>' +
    '</div>' +
```

- [ ] **Step 7: Lint**

Run: `php -l menu.php && php -l cart_refresh.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 8: Browser E2E — the reported bug**

Add the 15%-promo product AND a non-promo product to the cart. Confirm:
- The promo line shows struck original + net + "15% OFF"; the non-promo line is unchanged.
- The summary shows `Item Promos −$0.23` (for a $1.50 drink) and the subtotal already reflects the discount; the non-promo line is NOT discounted.
- Change the promo line's qty to 2 → Item Promos shows `−$0.46`.

- [ ] **Step 9: Commit**

```bash
git add menu.php cart_refresh.php
git commit -m "feat(promo): struck price + Item Promos row in cart panel (server + live)"
```

---

### Task 6: confirm_order.php — persist promo snapshot + report it

**Files:**
- Modify: `confirm_order.php` (NEW-order item INSERT ~line 415-434 and its subtotal/discount ~line 234-271; add-to-order item INSERT ~line 189-215 and discount ~line 137-164; reward-gift INSERT ~line 443-462)

**Interfaces:**
- Consumes: cart line `price` (net), `orig_price`, `promo_percent`.
- Produces: `order_items.promo_percent` persisted; `orders.promotion_discount` includes item-promo savings. Charged `total` unchanged (already net).

- [ ] **Step 1: NEW order — compute item-promo total for reporting**

In `confirm_order.php`, in the NEW-order subtotal loop (line 238-244), add an accumulator. Before the loop add `$item_promo_total = 0.0;`, and inside the loop add:

```php
    $item_promo_total += (max(0, (float)($item['orig_price'] ?? $price) - $price)) * $qty;
```

Then fold it into the reported discount (line 268):

```php
$total_discount      = $happy_hour_discount + $manual_discount_co + $item_promo_total;
```

(Do NOT change `$subtotal_after`/`$total` — they already reflect the net line prices, so the promo is in the charged total exactly once.)

- [ ] **Step 2: NEW order — persist promo_percent on each item**

In `confirm_order.php`, change the NEW-order item INSERT (line 415-418) to include the column:

```php
    $stmt_item = $conn->prepare("
        INSERT INTO order_items (order_id, product_id, product_name, price, quantity, sweetness, ice, milk, size_code, size_label, addons_snapshot, promo_percent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
```

Inside the loop (line 420-434), add a promo var and extend the bind:

```php
        $promo_pct   = (int)($item['promo_percent'] ?? 0);
        $addons_json = json_encode($item['addons'] ?? []);

        $stmt_item->bind_param("iisdissssssi", $order_id, $product_id, $pname, $price, $qty, $sweet, $ice, $milk, $scode, $slabel, $addons_json, $promo_pct);
```

- [ ] **Step 3: Reward-gift INSERT — bind promo_percent = 0**

In `confirm_order.php`, the reward-gift INSERT (line 443-446) also targets `order_items`. Add the column + a literal 0. Change the INSERT column list to end with `, promo_percent` and `VALUES (..., ?)`, then in the bind (line 461) append a `$rpromo = 0;` and extend types+args:

```php
        $rpromo = 0;
        $stmt_reward->bind_param("iisdissssssi", $order_id, $rid, $rname, $rprice, $rqty, $rempty, $rempty, $rempty, $rempty, $rempty, $addons_json, $rpromo);
```

(Update that INSERT's SQL string the same way as Step 2 — add `promo_percent` to the column list and one more `?`.)

- [ ] **Step 4: Add-to-order — persist promo + report new-item promos**

In `confirm_order.php`, the add-to-order item INSERT (line 189-192): add `promo_percent` to columns + one `?`. In its loop (line 195-214) add:

```php
        $promo_pct   = (int)($item['promo_percent'] ?? 0);
```

and extend the bind (line 208):

```php
            $stmt_item->bind_param("iisdissssssi", $existing_order_id, $product_id, $pname, $price, $qty, $sweet, $ice, $milk, $scode, $slabel, $addons_json, $promo_pct);
```

For reporting, accumulate the new items' promo saving and add it to `$final_discount`. In the combine-new-items loop (line 130-135) add before it `$item_promo_total = 0.0;` and inside add:

```php
        $item_promo_total += (max(0, (float)($item['orig_price'] ?? $p) - $p)) * $q;
```

Then change line 148:

```php
    $final_discount = $buy3 + $happy_hour + $item_promo_total;   // promotions (stored in promotion_discount)
```

(`$after`/`$final_total` already use net item prices — leave them.)

- [ ] **Step 5: Lint**

Run: `php -l confirm_order.php`
Expected: `No syntax errors detected in confirm_order.php`

- [ ] **Step 6: Browser E2E — place a real order**

Place an order containing the promo product + a normal product, pay by Cash. Then verify persistence:

`php -r "require 'config.php'; \$r=\$conn->query('SELECT oi.order_id, oi.product_name, oi.price, oi.promo_percent FROM order_items oi ORDER BY oi.item_id DESC LIMIT 5'); while(\$x=\$r->fetch_assoc()) echo \$x['order_id'].' '.\$x['product_name'].' $'.\$x['price'].' promo='.\$x['promo_percent'].PHP_EOL;"`
Expected: the promo line shows the NET price and `promo=15`; the normal line shows `promo=0`.

`php -r "require 'config.php'; \$r=\$conn->query('SELECT order_id,total,promotion_discount FROM orders ORDER BY order_id DESC LIMIT 1'); \$x=\$r->fetch_assoc(); echo 'total=$'.\$x['total'].' promo_disc=$'.\$x['promotion_discount'].PHP_EOL;"`
Expected: `promotion_discount` ≥ the item-promo saving; `total` matches the net cart total shown at checkout.

- [ ] **Step 7: Commit**

```bash
git add confirm_order.php
git commit -m "feat(promo): persist promo_percent on order items + report item-promo savings"
```

---

### Task 7: Display promo tag on receipts + edit-order screen

**Files:**
- Modify: `receipt_print.php` (item SELECT ~line 41-42 + item render loop)
- Modify: `receipt_pdf.php` (item SELECT ~line 49-50 + item render loop)
- Modify: `receipt_paylater.php` (item SELECT ~line 41-42 + item render loop)
- Modify: `edit_order_items.php` (item SELECT ~line 260-263 + item render ~line 442-462)

**Interfaces:**
- Consumes: `order_items.promo_percent` (Task 6). All line prices are already net → totals need no change.
- Produces: a small "X% OFF" tag next to promo lines. Reporting/totals untouched.

- [ ] **Step 1: receipt_print.php — fetch + show the tag**

In `receipt_print.php`, add `promo_percent` to the item SELECT (line 41):

```php
    SELECT product_name, price, quantity, sweetness, ice, milk, size_label, addons_snapshot, promo_percent
```

In the loop that renders each item name/line, where the product name is printed, append (use the file's existing escaping/markup style):

```php
<?php if ((int)($item['promo_percent'] ?? 0) > 0): ?> <span style="color:#c0392b;font-weight:700;font-size:11px;">(<?= (int)$item['promo_percent'] ?>% OFF)</span><?php endif; ?>
```

(Locate the item name output inside the receipt items loop and place the tag immediately after it.)

- [ ] **Step 2: receipt_pdf.php — fetch + show the tag**

Add `promo_percent` to the item SELECT (line 49). In the PDF item row, append the promo to the product name string (this file builds strings for a PDF lib — match its API):

```php
$nameCell = $item['product_name'] . ((int)($item['promo_percent'] ?? 0) > 0 ? ' (' . (int)$item['promo_percent'] . '% OFF)' : '');
```

Use `$nameCell` where the product name is written into the PDF.

- [ ] **Step 3: receipt_paylater.php — fetch + show the tag**

Add `promo_percent` to the item SELECT (line 41). Append the same inline tag after the product name in its item loop, mirroring Step 1's markup and the file's escaping style.

- [ ] **Step 4: edit_order_items.php — fetch + show the tag**

Add `promo_percent` to the item SELECT (line 261):

```php
    SELECT item_id, product_name, price, quantity, size_label, sweetness, ice, milk, addons_snapshot, promo_percent
```

In the item render (~line 457), after the `item-name` div, add:

```php
                    <?php if ((int)($item['promo_percent'] ?? 0) > 0): ?>
                    <div class="item-custom" style="color:#c0392b;font-weight:600;"><?= (int)$item['promo_percent'] ?>% OFF applied</div>
                    <?php endif; ?>
```

(No JS/recalc change: `data-price` is already the net price, so live totals stay correct.)

- [ ] **Step 5: Lint**

Run: `php -l receipt_print.php && php -l receipt_pdf.php && php -l receipt_paylater.php && php -l edit_order_items.php`
Expected: `No syntax errors detected` for all four.

- [ ] **Step 6: Browser E2E**

Open the print receipt for the order placed in Task 6 (`receipt_print.php?order_id=<id>`): the promo line shows "(15% OFF)" and the net price; the total matches. Open the PDF receipt and confirm the tag renders. For a Pay Later order, open `edit_order_items.php?order_id=<id>` and confirm the promo line shows "15% OFF applied" and that changing quantity keeps totals correct.

- [ ] **Step 7: Commit**

```bash
git add receipt_print.php receipt_pdf.php receipt_paylater.php edit_order_items.php
git commit -m "feat(promo): show per-line promo tag on receipts + edit-order screen"
```

---

## Self-Review

**Spec coverage:**
- Data model (2 columns) → Task 1. ✓
- Net-price storage / promo baked in → Tasks 4 (cart), 6 (order_items). ✓
- Badge auto-derivation (cards, modal) → Tasks 1 (helper), 3. ✓
- Admin promo input + badge-override UX → Task 2. ✓
- Drink-only discount, per-unit rounding → Task 4. ✓
- Cart struck price + Item Promos row (server + live) → Task 5. ✓
- Stacking (Happy Hour on net subtotal, manual unchanged) → automatic (net subtotal); no code needed, verified in Task 5/6 E2E. ✓
- promotion_discount includes item promos → Task 6. ✓
- edit_order_items no money change, tag only → Task 7. ✓
- Receipts show promo, totals already net → Task 7. ✓

**Placeholder scan:** No TBD/TODO; every code step shows the code. Receipt render placement (Tasks 7.1–7.3) references "the file's item loop" because the three receipts differ in markup — the SELECT change and the tag snippet are exact; the insertion point is the product-name output in each loop.

**Type consistency:** `promo_percent` is an int everywhere (clamped 0–100); `orig_price`/`price` are floats. `product_badge_label()` used identically in Tasks 1/3. Cart fields `price`/`orig_price`/`promo_percent` set in Task 4, read in Tasks 5/6 with matching names. order_items INSERT bind strings extended consistently (`...i` appended, arg added) in all three Task 6 INSERTs.

## Manual verification checklist (post-implementation)

- [ ] Promo line discounted, non-promo line untouched (the reported bug).
- [ ] Promo drink + add-ons → drink discounted, add-on full price.
- [ ] Sized promo drink → promo applies to the chosen size.
- [ ] Happy Hour active → computed on the already-net subtotal (stacks).
- [ ] Cart-wide manual discount still works alongside a promo.
- [ ] `promo_percent = 0` → identical to today; free-text badge still shows.
- [ ] Order persists net price + `promo_percent`; `total` and `promotion_discount` correct.
- [ ] Receipts (print + PDF + paylater) show the promo tag; totals match.
- [ ] Edit a Pay Later order with a promo line → totals recompute correctly.
