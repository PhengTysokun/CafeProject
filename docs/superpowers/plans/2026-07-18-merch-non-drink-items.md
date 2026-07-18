# Merch / Non-Drink Items Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sell non-drink items (merch) through the normal order flow — charged, receipted, counted as revenue — with no drink options, no recipe, and no loyalty points.

**Architecture:** A category-level `earns_points` flag (drinks=1, merch=0) is snapshotted onto each cart line and `order_items` row (same pattern as `promo_percent`). Loyalty points then count only `earns_points=1 AND price>0` items. Merch is otherwise a normal product in a category with all drink-options toggled off and no recipe, so it rides the existing cart/checkout/receipt/revenue rails unchanged.

**Tech Stack:** PHP 8 / MySQLi, vanilla JS, XAMPP/MySQL. No automated test framework — verify with `php -l` + browser/DB E2E (project convention).

## Global Constraints
- Schema via the existing idempotent `_migrate($conn,'id',fn)` helper in `config.php`.
- Migrations run `categories.earns_points` before `order_items.earns_points` (no FK dep; ordered for clarity).
- Snapshot the flag — never re-derive loyalty eligibility from the live category at read time.
- Points rule (verbatim): `points_qty = Σ item.qty WHERE item.earns_points = 1 AND item.price > 0`.
- Merch products earn points = 0; existing/pre-migration rows default to `earns_points = 1` (were drinks).
- App forces HTTPS (`https://localhost/Cafe`, curl `-k`); admin login = Sokun; aggressive session idle-timeout — don't dawdle mid-flow. No CLI Apache restart.

---

### Task 1: Schema — earns_points on categories + order_items (config.php)

**Files:** Modify `config.php` (after the `categories_offer_addons_v1` migration, ~line 238).

**Interfaces:**
- Produces: `categories.earns_points` TINYINT(1) NOT NULL DEFAULT 1; `order_items.earns_points` TINYINT(1) NOT NULL DEFAULT 1.

- [ ] **Step 1: Add the two migrations**

After the `categories_offer_addons_v1` block in `config.php`, add:
```php
_migrate($conn, 'categories_earns_points_v1', function($db) {
    $db->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS earns_points TINYINT(1) NOT NULL DEFAULT 1");
});
_migrate($conn, 'order_items_earns_points_v1', function($db) {
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS earns_points TINYINT(1) NOT NULL DEFAULT 1");
});
```

- [ ] **Step 2: Lint + apply + verify**

Run: `php -l config.php`
Expected: `No syntax errors detected in config.php`
Run: `curl -k -s -o /dev/null -w "%{http_code}\n" https://localhost/Cafe/login.php` → `200`
Run: `php -r "require 'config.php'; foreach([['categories','earns_points'],['order_items','earns_points']] as \$c){ \$r=\$conn->query(\"SHOW COLUMNS FROM {\$c[0]} LIKE '{\$c[1]}'\"); echo \$c[0].'.'.\$c[1].': '.(\$r->num_rows?'OK':'MISSING').PHP_EOL; }"`
Expected: both print `OK`.

- [ ] **Step 3: Commit**
```bash
git add config.php
git commit -m "feat(merch): add earns_points column to categories + order_items"
```

---

### Task 2: Category management — Earns-points toggle (manage_categories.php)

**Files:** Modify `manage_categories.php` (add handler ~28-33, update handler ~44-50, list SELECT ~123, add form ~253-256, edit form ~349-352).

**Interfaces:**
- Consumes: `categories.earns_points` (Task 1).
- Produces: categories can be created/edited with `earns_points` 0/1; the list SELECT returns `c.earns_points`.

- [ ] **Step 1: Read + persist on the `add` handler**

In the `case 'add'` block, after `$oa = isset($_POST['offer_addons']) ? 1 : 0;` (~line 31) add:
```php
                $ep = isset($_POST['earns_points']) ? 1 : 0;
```
Change the INSERT (line 32-33) to include the column:
```php
                $ins = $conn->prepare("INSERT INTO categories (slug, name, icon, display_order, is_active, offer_sweetness, offer_ice, offer_milk, offer_addons, earns_points) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->bind_param('sssiiiiiii', $slug, $name, $icon, $ord, $active, $os, $oi, $om, $oa, $ep);
```

- [ ] **Step 2: Read + persist on the `update` handler**

In the `case 'update'` block, after `$oa = isset($_POST['offer_addons']) ? 1 : 0;` (~line 47) add:
```php
                $ep = isset($_POST['earns_points']) ? 1 : 0;
```
Change the UPDATE (line 48-49):
```php
                $u = $conn->prepare("UPDATE categories SET name=?, icon=?, is_active=?, offer_sweetness=?, offer_ice=?, offer_milk=?, offer_addons=?, earns_points=? WHERE category_id=?");
                $u->bind_param('ssiiiiiii', $name, $icon, $active, $os, $oi, $om, $oa, $ep, $id);
```

- [ ] **Step 3: Add `earns_points` to the category-list SELECT**

At ~line 123, add `c.earns_points` to the selected columns:
```php
           c.offer_sweetness, c.offer_ice, c.offer_milk, c.offer_addons, c.earns_points,
```

- [ ] **Step 4: Checkbox on the add form**

After the `offer_addons` label in the add form (~line 256), add:
```php
                <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text-muted);"><input type="checkbox" name="earns_points" checked> Points</label>
```

- [ ] **Step 5: Checkbox on the edit form**

After the `offer_addons` label in the edit form (~line 352), add:
```php
                                <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--text-muted);"><input type="checkbox" name="earns_points" <?= $c['earns_points'] ? 'checked' : '' ?>> Points</label>
```

- [ ] **Step 6: Lint + E2E**

Run: `php -l manage_categories.php` → `No syntax errors detected`.
Browser (admin): open `manage_categories.php`, create category **Merch** with Sweet/Ice/Milk/Add-ons **unchecked** and **Points unchecked**; save. Then:
`php -r "require 'config.php'; \$r=\$conn->query(\"SELECT slug,earns_points,offer_addons FROM categories WHERE slug='Merch'\")->fetch_assoc(); echo 'Merch earns_points='.\$r['earns_points'].' offer_addons='.\$r['offer_addons'].PHP_EOL;"`
Expected: `Merch earns_points=0 offer_addons=0`. Edit a drink category, confirm Points stays checked and persists as 1.

- [ ] **Step 7: Commit**
```bash
git add manage_categories.php
git commit -m "feat(merch): earns-points toggle on category add/edit"
```

---

### Task 3: Snapshot earns_points onto the cart line (add_to_cart.php)

**Files:** Modify `add_to_cart.php` (product SELECT ~line 55; cart-line push ~line 152-167).

**Interfaces:**
- Consumes: `categories.earns_points`.
- Produces: every cart line carries `earns_points` (0/1).

- [ ] **Step 1: LEFT JOIN categories in the product fetch**

Replace the product SELECT (line 55):
```php
$stmt = $conn->prepare("SELECT p.product_id, p.name, p.price, p.image, p.has_sizes, p.promo_percent, COALESCE(c.earns_points,1) AS earns_points FROM products p LEFT JOIN categories c ON c.category_id = p.category_id WHERE p.product_id = ?");
```
(The `$p` array now also has `$p['earns_points']`.)

- [ ] **Step 2: Compute + stamp earns_points on the fresh cart line**

Just above the `if (!$found)` push, derive it:
```php
$earns_points = (int)($p['earns_points'] ?? 1);
```
In the `$_SESSION['cart'][] = [ ... ]` array (line 153-167), add after `'promo_percent'=> $promo_percent,`:
```php
        'earns_points' => $earns_points,
```

- [ ] **Step 3: Lint**

Run: `php -l add_to_cart.php` → `No syntax errors detected in add_to_cart.php`.

- [ ] **Step 4: Commit**
```bash
git add add_to_cart.php
git commit -m "feat(merch): stamp earns_points onto cart lines from category"
```

---

### Task 4: Persist snapshot + points math (confirm_order.php)

**Files:** Modify `confirm_order.php` — NEW-order item INSERT (~415-439) + loyalty block (~519-544); add-to-order existing-items SELECT (~113) + combine loop (~123-135) + item INSERT (~189-215); reward-gift INSERT (~443-462).

**Interfaces:**
- Consumes: cart line `earns_points`; `order_items.earns_points`.
- Produces: `order_items.earns_points` persisted on every row; loyalty points count only earning + priced items.

- [ ] **Step 1: NEW path — persist earns_points + accumulate point_qty**

Change the NEW-order INSERT (line 415-418) to add the column (it already has `promo_percent, orig_price` from the promo feature):
```php
    $stmt_item = $conn->prepare("
        INSERT INTO order_items (order_id, product_id, product_name, price, quantity, sweetness, ice, milk, size_code, size_label, addons_snapshot, promo_percent, orig_price, earns_points)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
```
Before the `foreach ($_SESSION['cart'] as $item)` loop (line 420), add `$point_qty = 0;`. Inside the loop, after `$orig_price = (float)($item['orig_price'] ?? $price);` add:
```php
        $earns_pts   = (int)($item['earns_points'] ?? 1);
        if ($earns_pts === 1 && $price > 0) $point_qty += $qty;
```
Extend the bind (currently ends `...id`):
```php
        $stmt_item->bind_param("iisdissssssidi", $order_id, $product_id, $pname, $price, $qty, $sweet, $ice, $milk, $scode, $slabel, $addons_json, $promo_pct, $orig_price, $earns_pts);
```
(types gain a trailing `i` for earns_pts → `iisdissssssid` + `i` = `iisdissssssidi`.)

- [ ] **Step 2: NEW path — use point_qty for loyalty**

In the loyalty block, change `$points_earned = $total_qty;` (line 522) to:
```php
        $points_earned = $point_qty;
```
And change the counter update (line 531-532) to credit drinks by `$point_qty`:
```php
            $sc = $conn->prepare("UPDATE loyalty_cards SET total_orders = total_orders + 1, total_drinks = total_drinks + ? WHERE card_id = ?");
            if ($sc) { $sc->bind_param("ii", $point_qty, $loyalty_card_id); $sc->execute(); }
```
(`$point_qty` is in scope — it was computed in the item loop before `$_SESSION['cart']` was cleared.)

- [ ] **Step 3: Reward-gift INSERT — earns_points 0**

The reward-gift INSERT (line 443-446) already has `promo_percent, orig_price`. Add `, earns_points` to its column list + one `?`, then before the bind (~line 461) add `$rearn = 0;` and extend:
```php
        $rearn = 0;
        $stmt_reward->bind_param("iisdissssssidi", $order_id, $rid, $rname, $rprice, $rqty, $rempty, $rempty, $rempty, $rempty, $rempty, $addons_json, $rpromo, $rorig, $rearn);
```

- [ ] **Step 4: Add-to-order — persist + gate points**

Add `earns_points` to the existing-items SELECT (line 113):
```php
    $stmt_ei = $conn->prepare("SELECT price, quantity, earns_points FROM order_items WHERE order_id = ?");
```
In the combine loops (line 124-135), gate the points count on earns_points. Change the existing-items loop's `if ($p > 0) $points_qty += $q;` to:
```php
        if ($p > 0 && (int)($ei['earns_points'] ?? 1) === 1) $points_qty += $q;
```
and the cart loop's `if ($p > 0) $points_qty += $q;` to:
```php
        if ($p > 0 && (int)($item['earns_points'] ?? 1) === 1) $points_qty += $q;
```
Add the column to the add-to-order INSERT (line 189-192) — it already has `promo_percent, orig_price`; add `, earns_points` + one `?`. In its loop (line 195-214) add `$earns_pts = (int)($item['earns_points'] ?? 1);` and extend the bind to `iisdissssssidi` with `$earns_pts` last (mirror Step 1).

- [ ] **Step 5: Lint + E2E**

Run: `php -l confirm_order.php` → clean.
Browser E2E (Task 6 covers the full loyalty check). Quick persistence check after any order:
`php -r "require 'config.php'; \$r=\$conn->query('SELECT product_name,price,earns_points FROM order_items ORDER BY item_id DESC LIMIT 3'); while(\$x=\$r->fetch_assoc()) echo \$x['product_name'].' \$'.\$x['price'].' earns='.\$x['earns_points'].PHP_EOL;"`

- [ ] **Step 6: Commit**
```bash
git add confirm_order.php
git commit -m "feat(merch): persist earns_points + exclude non-earning items from loyalty points"
```

---

### Task 5: Edit-order loyalty recompute (edit_order_items.php)

**Files:** Modify `edit_order_items.php` — recompute SELECT (~158) + points loop (~169-176).

**Interfaces:**
- Consumes: `order_items.earns_points`.
- Produces: the loyalty-sync recompute counts only earning + priced items.

- [ ] **Step 1: Add earns_points to the recompute SELECT**

Change line 158:
```php
        $stmt_r = $conn->prepare("SELECT price, quantity, earns_points FROM order_items WHERE order_id = ?");
```

- [ ] **Step 2: Gate the points_qty count**

In the `foreach ($remaining as $row)` loop (line 170-176), change `if ($p > 0) $points_qty += $q;` to:
```php
            if ($p > 0 && (int)($row['earns_points'] ?? 1) === 1) $points_qty += $q;   // earning drinks only
```

- [ ] **Step 3: Lint**

Run: `php -l edit_order_items.php` → `No syntax errors detected in edit_order_items.php`.

- [ ] **Step 4: Commit**
```bash
git add edit_order_items.php
git commit -m "feat(merch): edit-order loyalty recompute excludes non-earning items"
```

---

### Task 6: Best-seller TODO note + full E2E

**Files:** Modify `menu.php` (best-seller query ~line 100).

- [ ] **Step 1: Leave a TODO at the best-seller query**

Above the `$bs = mysqli_query(... GROUP BY product_name ...)` line (~100) in `menu.php`, add:
```php
// TODO(merch): once merch volume is significant, exclude non-drink categories
// (categories.earns_points = 0) from best-seller / top-drinks stats.
```
Run: `php -l menu.php` → clean. Commit:
```bash
git add menu.php
git commit -m "docs(merch): note best-seller stat should exclude merch later"
```

- [ ] **Step 2: E2E — create a merch product**

Admin: under the **Merch** category (Task 2), add a product **T-Shirt**, price `10`, no sizes. Verify it saved:
`php -r "require 'config.php'; \$r=\$conn->query(\"SELECT p.name,p.price,c.earns_points FROM products p JOIN categories c ON c.category_id=p.category_id WHERE p.name='T-Shirt'\")->fetch_assoc(); echo \$r['name'].' \$'.\$r['price'].' cat_earns='.\$r['earns_points'].PHP_EOL;"`
Expected: `T-Shirt $10.00 cat_earns=0`.

- [ ] **Step 2b: Menu shows merch with no drink options**

Open `menu.php`, find T-Shirt, open it → the modal shows only qty + Add to Cart (no Size/Sweet/Ice/Milk). Add it → cart line $10.00.

- [ ] **Step 3: E2E — loyalty excludes merch**

Build a cart with **1 T-Shirt ($10) + 1 drink**, link a loyalty card, checkout Cash. Then verify only the drink earned a point:
`php -r "require 'config.php'; \$o=\$conn->query('SELECT order_id,points_earned FROM orders ORDER BY order_id DESC LIMIT 1')->fetch_assoc(); echo 'order '.\$o['order_id'].' points_earned='.\$o['points_earned'].PHP_EOL; \$r=\$conn->query('SELECT product_name,price,earns_points FROM order_items WHERE order_id='.\$o['order_id']); while(\$x=\$r->fetch_assoc()) echo '  '.\$x['product_name'].' \$'.\$x['price'].' earns='.\$x['earns_points'].PHP_EOL;"`
Expected: `points_earned=1` (the drink); T-Shirt row `earns=0`, drink row `earns=1`. Order total includes the $10 shirt.

- [ ] **Step 4: E2E — revenue + no stock deduction**

Confirm the merch sale counts as revenue (paid gate) and deducted no ingredients:
`php -r "require 'config.php'; \$s=\$conn->query('SELECT IFNULL(SUM(total),0) s FROM orders WHERE DATE(order_date)=CURDATE() AND '.paid_orders_where())->fetch_assoc(); echo 'today paid revenue includes shirt sale: \$'.\$s['s'].PHP_EOL;"`
Confirm no `ingredient_history` `order_deduct` rows reference the merch-only order (T-Shirt has no recipe). Soft-cancel the test order afterward.

- [ ] **Step 5: E2E — drink category regression**

Place a drink-only order with a loyalty card → `points_earned` equals the drink qty (unchanged behaviour).

## Self-Review

**Spec coverage:**
- `categories.earns_points` + `order_items.earns_points` columns → Task 1. ✓
- earns-points toggle in manage_categories (add/edit/list/forms) → Task 2. ✓
- Merch category + products (config, no code) → Task 2 Step 6 + Task 6 Step 2. ✓
- Snapshot onto cart line via LEFT JOIN → Task 3. ✓
- Persist `order_items.earns_points` (3 INSERTs) → Task 4 Steps 1/3/4. ✓
- Points rule `earns_points=1 AND price>0` in all 3 loyalty spots → Task 4 (new + add-to-order) + Task 5 (edit). ✓
- paid_orders_where unchanged / merch-safe → verified in spec; exercised Task 6 Step 4. ✓
- Best-seller TODO → Task 6 Step 1. ✓
- Reuse (menu/receipts/payments) unchanged → exercised in Task 6. ✓

**Placeholder scan:** No TBD/vague steps; every code step shows code. The only `TODO` is an intentional in-code marker (Task 6 Step 1), per spec.

**Type consistency:** `earns_points` is an int 0/1 everywhere; cart field + `order_items` column named identically. All three `order_items` INSERTs extend the bind the same way: `iisdissssssid` → `iisdissssssidi` (append `i`), arg `$earns_pts`/`$rearn`. NEW path uses `$point_qty` computed in the item loop before the cart is cleared; add-to-order/edit use `$points_qty` with the `earns_points` gate added to the existing `price>0` check.

## Manual verification checklist
- [ ] Merch category saves with earns_points=0, options off.
- [ ] T-Shirt sells for $10, modal shows no drink options.
- [ ] Cart with shirt+drink + loyalty → only the drink earns a point.
- [ ] Merch sale counts in paid revenue; no ingredient deduction.
- [ ] Edit a Pay Later order with shirt+drink → points recompute counts drink only.
- [ ] Drink-only order still earns points normally (regression).
