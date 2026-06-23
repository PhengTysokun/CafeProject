# Drink Sizes (S/M/L) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sell drinks in Small/Medium/Large with per-size pricing and per-size stock consumption, opt-in per product; food/unsized items unchanged.

**Architecture:** New normalized `product_sizes` child table + `products.has_sizes` flag. Price is resolved once at add-to-cart and snapshotted on the cart line and `order_items` (with `size_code`+`size_label`). A per-size `size_factor` multiplies recipe deduction. No checkout arithmetic — every price is a stored lookup.

**Tech Stack:** PHP 7+/8 procedural + MySQLi, MySQL `db_coffee`, jQuery/vanilla JS, session-based cart. **No automated test framework exists** — verification per task = `php -l` lint + direct DB assertions via `C:/xampp/php/php.exe` + Playwright browser smoke-test.

## Global Constraints (verbatim from spec)

- Pricing: **full per-product per-size absolute price**, no deltas. Checkout **trusts the cart-line price**; never re-resolve from `product_sizes` at confirm.
- Sizing is **opt-in per product** via `products.has_sizes`. **No category inheritance at runtime** — price lookup never consults categories.
- `products.price` always equals the **Medium** price (keeps legacy single-price paths working).
- `order_items` snapshots **both** `size_code` (stable key, soft reference — no FK) **and** `size_label`.
- Recipe multiplier applies **per ingredient row**, never to a precomputed total. Unsized → factor `1.0`.
- **Every** size listing (admin rows, menu selector, displays) orders by `product_sizes.sort_order ASC`. Never hardcode S→M→L.
- Defensive: `has_sizes=1` with zero size rows → behave as unsized (use `products.price`, factor 1.0) AND show a red "missing size prices" admin badge. POS must never crash.
- CSRF: all POST endpoints validate `$_SESSION['csrf_token']` via `hash_equals` (existing pattern).

## Verification accounts (from project memory)
- Admin: `Sokun` / `@Sokun9811` (admin-gated pages; lands on loading.php → navigate directly).
- Cashier: `Sok_Dara` / `@Sokdara5678` (menu/cart/checkout).
- Login at `http://localhost/Cafe/login.php`.

## Test data pattern
- Seed sizes for a real product by SQL (see Task 2 verification). Clean test orders with `DELETE FROM orders WHERE customer_name='SizeTest';` and matching `order_items`.

---

## File Map

| File | Responsibility | Tasks |
|---|---|---|
| `config.php` | schema migration `drink_sizes_v1` | 1 |
| `add_to_cart.php` | accept/validate size, resolve price+factor, merge key | 2 |
| `confirm_order.php` | persist size on 3 INSERT sites, scale deduction | 3 |
| `edit_product.php`, `add_product.php` | size CRUD UI + save (price sync to Medium) | 4 |
| `products.php` | bulk-enable-by-category + missing-size badge | 5 |
| `menu.php` | size selector in product modal + quickAdd guard | 6 |
| `cart.php`, `cart_refresh.php`, `order.php` | size chip on cart lines + carry on line | 7 |
| `barista_display.php`, `view_order.php`, `receipt_pdf.php`, `receipt_print.php` | show size | 8 |

---

## Task 1: Schema migration

**Files:**
- Modify: `config.php` (append a new migration in the existing `schema_migrations` runner)

**Interfaces:**
- Produces: `products.has_sizes TINYINT`, table `product_sizes(size_id, product_id, size_code, label, price, size_factor, sort_order)`, `order_items.size_code VARCHAR(10) NULL`, `order_items.size_label VARCHAR(20) NULL`.

- [ ] **Step 1: Locate the migration runner.** Find where `config.php` registers migrations (search `schema_migrations` / existing migration keys like `remove_cafe_tables_v1`). Migrations are idempotent keyed entries run on load.

- [ ] **Step 2: Add migration `drink_sizes_v1`** following the existing registration shape. The SQL it must run (adapt to the file's helper style — `$conn->query(...)` guarded by the migration-key check):

```sql
ALTER TABLE products
  ADD COLUMN has_sizes TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS product_sizes (
  size_id     INT(11) NOT NULL AUTO_INCREMENT,
  product_id  INT(11) NOT NULL,
  size_code   VARCHAR(10) NOT NULL,
  label       VARCHAR(20) NOT NULL,
  price       DECIMAL(10,2) NOT NULL,
  size_factor DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  sort_order  INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (size_id),
  UNIQUE KEY uq_product_size (product_id, size_code),
  CONSTRAINT fk_product_sizes_product FOREIGN KEY (product_id)
    REFERENCES products(product_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE order_items
  ADD COLUMN size_code  VARCHAR(10) NULL,
  ADD COLUMN size_label VARCHAR(20) NULL;
```

Guard each `ADD COLUMN` so re-runs don't fail (the codebase's migration helper already gates by key; if it runs raw SQL, wrap column adds with an existence check using `SHOW COLUMNS FROM products LIKE 'has_sizes'`).

- [ ] **Step 3: Trigger + verify.** Load any page (e.g. `http://localhost/Cafe/login.php`) so migrations run, then:

```bash
"C:/xampp/php/php.exe" -r '$m=new mysqli("localhost","root","","db_coffee");
foreach(["products","order_items"] as $t){$r=$m->query("SHOW COLUMNS FROM $t");$c=[];while($x=$r->fetch_assoc())$c[]=$x["Field"];echo "$t: ".implode(",",$c)."\n";}
$r=$m->query("SHOW TABLES LIKE \"product_sizes\"");echo "product_sizes exists: ".($r->num_rows?"YES":"NO")."\n";'
```

Expected: `products:` contains `has_sizes`; `order_items:` contains `size_code,size_label`; `product_sizes exists: YES`.

- [ ] **Step 4: Verify idempotency.** Reload the page again; re-run the Step 3 command. Expected: identical output, no SQL errors in the PHP error log.

- [ ] **Step 5: Commit**

```bash
git add config.php
git commit -m "feat(db): drink_sizes_v1 migration — has_sizes, product_sizes, order_items.size_code/label"
```

---

## Task 2: add_to_cart.php — size handling

**Files:**
- Modify: `add_to_cart.php` (full file read; current logic: CSRF → product_id → qty → validate sweetness/ice/milk → fetch product → merge identical → push line)

**Interfaces:**
- Consumes: `products.has_sizes`, `product_sizes(size_code, label, price, size_factor)`.
- Produces: cart line keys `size_code`, `size_label`, `size_factor` (in addition to existing `price`, `product_name`, `sweetness`, `ice`, `milk`, `qty`).

- [ ] **Step 1: Read the size param** after the existing `$milk` line (~line 33):

```php
$size_code = trim((string)($_POST['size'] ?? ''));
```

- [ ] **Step 2: Change the product fetch to include `has_sizes`** (~line 51):

```php
$stmt = $conn->prepare("SELECT product_id, name, price, image, has_sizes FROM products WHERE product_id = ?");
```

- [ ] **Step 3: Resolve size price + factor after `$p = $res->fetch_assoc();`** (~line 60). Insert:

```php
// ── Resolve size (per-size absolute price; defensive fallback to base) ──
$line_price   = (float)$p['price'];   // products.price == Medium / base
$size_label   = '';
$size_factor  = 1.0;
$resolved_code = '';

if ((int)$p['has_sizes'] === 1) {
    $rows = [];
    $sz = $conn->prepare("SELECT size_code, label, price, size_factor FROM product_sizes WHERE product_id = ?");
    $sz->bind_param("i", $product_id);
    $sz->execute();
    $rs = $sz->get_result();
    while ($r = $rs->fetch_assoc()) { $rows[$r['size_code']] = $r; }

    if (!empty($rows)) {
        // size_code is required for a sized product
        if ($size_code === '' || !isset($rows[$size_code])) {
            json_out(false, 'Please choose a size', 0, null, 400);
        }
        $chosen        = $rows[$size_code];
        $line_price    = (float)$chosen['price'];
        $size_label    = (string)$chosen['label'];
        $size_factor   = (float)$chosen['size_factor'];
        $resolved_code = $size_code;
    }
    // has_sizes=1 but zero rows → fall through as unsized (base price, factor 1.0)
}
```

- [ ] **Step 4: Add `size_code` to the merge-identity loop** (~lines 70-81). Change the condition to also compare size:

```php
foreach ($_SESSION['cart'] as &$item) {
    if (
        $item['product_id'] == $product_id &&
        ($item['size_code'] ?? '') == $resolved_code &&
        $item['sweetness']  == $sweetness  &&
        $item['ice']        == $ice        &&
        $item['milk']       == $milk
    ) {
        $item['qty'] += $qty;
        $found = true;
        break;
    }
}
unset($item);
```

- [ ] **Step 5: Add size fields to the pushed line** (~lines 84-95):

```php
if (!$found) {
    $_SESSION['cart'][] = [
        'product_id'   => $p['product_id'],
        'product_name' => $p['name'],
        'price'        => $line_price,
        'image'        => $p['image'],
        'size_code'    => $resolved_code,
        'size_label'   => $size_label,
        'size_factor'  => $size_factor,
        'sweetness'    => $sweetness,
        'ice'          => $ice,
        'milk'         => $milk,
        'qty'          => $qty,
    ];
}
```

- [ ] **Step 6: Lint.** `"C:/xampp/php/php.exe" -l add_to_cart.php` → Expected: `No syntax errors detected`.

- [ ] **Step 7: Seed a sized product for testing, then verify via direct POST.** Pick a real product id (e.g. `SELECT product_id,name,price FROM products LIMIT 1`). Seed:

```bash
"C:/xampp/php/php.exe" -r '$m=new mysqli("localhost","root","","db_coffee");
$pid=(int)$m->query("SELECT product_id FROM products WHERE is_available=1 LIMIT 1")->fetch_assoc()["product_id"];
$m->query("UPDATE products SET has_sizes=1 WHERE product_id=$pid");
$m->query("DELETE FROM product_sizes WHERE product_id=$pid");
$m->query("INSERT INTO product_sizes (product_id,size_code,label,price,size_factor,sort_order) VALUES
 ($pid,\"S\",\"Small\",2.00,0.80,0),($pid,\"M\",\"Medium\",2.50,1.00,1),($pid,\"L\",\"Large\",3.00,1.30,2)");
echo "seeded pid=$pid\n";'
```

Then in the browser (logged in as cashier `Sok_Dara`), open dev console on `menu.php` and run a fetch, OR verify the server logic by asserting the cart after a UI add (Task 6). For an isolated backend check now, confirm the validation path: a POST without `size` for the seeded product must return `{"success":false,"message":"Please choose a size"}`. Use the browser console:

```js
fetch('add_to_cart.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
 body:new URLSearchParams({id: PID, qty:1, csrf_token: CSRF}).toString()}).then(r=>r.json()).then(console.log)
```

Expected: `success:false, message:"Please choose a size"`. With `size:'L'` appended → `success:true`.

- [ ] **Step 8: Commit**

```bash
git add add_to_cart.php
git commit -m "feat(cart): resolve per-size price/factor, size-aware merge key"
```

---

## Task 3: confirm_order.php — persist size + scale stock

**Files:**
- Modify: `confirm_order.php` — 3 INSERT sites (~160, ~380, ~403) and `_deduct_stock()` (~522-561) and its 2 call sites.

**Interfaces:**
- Consumes: cart line `size_code`, `size_label`, `size_factor` (from Task 2).
- Produces: `order_items.size_code`, `order_items.size_label` populated; ingredient deduction scaled by `size_factor`.

- [ ] **Step 1: Extend `_deduct_stock` signature** (~line 522) to accept the factor:

```php
function _deduct_stock(mysqli $conn, int $product_id, int $qty, string $milk_choice, int $order_id = 0, float $size_factor = 1.0): void {
```

- [ ] **Step 2: Apply the factor per ingredient row** (~line 537), changing only the amount calc:

```php
        $amount   = (float)$row['amount_used'] * $qty * $size_factor;
```

(Leave the milk-substitution and `UPDATE ingredients ... >= ?` logic untouched.)

- [ ] **Step 3: Update the new-order INSERT + loop** (~lines 379-398). New columns + binds + pass factor:

```php
    $stmt_item = $conn->prepare("
        INSERT INTO order_items (order_id, product_id, product_name, price, quantity, sweetness, ice, milk, size_code, size_label)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($_SESSION['cart'] as $item) {
        $qty         = max(1, (int)($item['qty'] ?? 1));
        $price       = (float)($item['price'] ?? 0.0);
        $product_id  = (int)($item['product_id'] ?? 0);
        $pname       = $item['product_name'] ?? '';
        $sweet       = $item['sweetness'] ?? '';
        $ice         = $item['ice'] ?? '';
        $milk        = $item['milk'] ?? '';
        $scode       = $item['size_code'] ?? '';
        $slabel      = $item['size_label'] ?? '';
        $sfactor     = (float)($item['size_factor'] ?? 1.0);

        $stmt_item->bind_param("iisdisssss", $order_id, $product_id, $pname, $price, $qty, $sweet, $ice, $milk, $scode, $slabel);
        $stmt_item->execute();

        if ($product_id > 0) {
            _deduct_stock($conn, $product_id, $qty, $milk, $order_id, $sfactor);
        }
    }
```

The bind type string is exactly `"iisdisssss"` — 10 markers: i,i,s,d,i,s,s,s,s,s. Store empty string for unsized lines (spec allows empty; displays render nothing for it).

- [ ] **Step 4: Update the existing-order append INSERT** (~lines 160-173). Same column/bind extension:

```php
    $stmt_ei = $conn->prepare("
        INSERT INTO order_items (order_id, product_id, product_name, price, quantity, sweetness, ice, milk, size_code, size_label)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // inside the loop, after existing $sweet/$ice/$milk:
    $scode  = $item['size_code'] ?? '';
    $slabel = $item['size_label'] ?? '';
    $sfact  = (float)($item['size_factor'] ?? 1.0);
    $stmt_ei->bind_param("iisdisssss", $existing_order_id, $product_id, $pname, $price, $qty, $sweet, $ice, $milk, $scode, $slabel);
    $stmt_ei->execute();
    if ($product_id > 0) { _deduct_stock($conn, $product_id, $qty, $milk, $existing_order_id, $sfact); }
```

Match the variable names already in that block (check the surrounding code for the existing `$pname`/`$price`/`$qty` names and reuse them).

- [ ] **Step 5: Update the loyalty-reward INSERT** (~lines 403-419). Rewards have no size — append two empty strings:

```php
    $stmt_reward = $conn->prepare("
        INSERT INTO order_items (order_id, product_id, product_name, price, quantity, sweetness, ice, milk, size_code, size_label)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $rempty = '';
    $stmt_reward->bind_param("iisdisssss", $order_id, $rid, $rname, $rprice, $rqty, $rempty, $rempty, $rempty, $rempty, $rempty);
```

- [ ] **Step 6: Lint.** `"C:/xampp/php/php.exe" -l confirm_order.php` → Expected: `No syntax errors detected`.

- [ ] **Step 7: End-to-end verify.** As cashier, add the seeded Large product (Task 2 seed) to cart, set customer `SizeTest`, confirm a cash order. Then:

```bash
"C:/xampp/php/php.exe" -r '$m=new mysqli("localhost","root","","db_coffee");
$r=$m->query("SELECT oi.product_name,oi.price,oi.size_code,oi.size_label FROM order_items oi JOIN orders o ON o.order_id=oi.order_id WHERE o.customer_name=\"SizeTest\" ORDER BY oi.item_id DESC LIMIT 3");
while($x=$r->fetch_assoc()) echo json_encode($x)."\n";'
```

Expected: a row with `size_code:"L"`, `size_label:"Large"`, `price:"3.00"`.

- [ ] **Step 8: Verify stock scaling.** Before/after the order, compare `ingredients.stock_quantity` for one recipe ingredient of the product; the drop must equal `amount_used × qty × 1.30` (Large factor). Spot-check via `ingredient_history` (the `order_deduct` row's `amount`).

- [ ] **Step 9: Clean test data + commit**

```bash
"C:/xampp/php/php.exe" -r '$m=new mysqli("localhost","root","","db_coffee");
$m->query("DELETE oi FROM order_items oi JOIN orders o ON o.order_id=oi.order_id WHERE o.customer_name=\"SizeTest\"");
$m->query("DELETE FROM orders WHERE customer_name=\"SizeTest\"");echo "cleaned\n";'
git add confirm_order.php
git commit -m "feat(orders): persist size_code/label and scale stock by size_factor"
```

---

## Task 4: Admin — size CRUD on product add/edit

**Files:**
- Modify: `edit_product.php` (form + save handler), `add_product.php` (form + insert handler)

**Interfaces:**
- Consumes: `product_sizes`, `products.has_sizes`.
- Produces: admin can toggle `has_sizes` and set S/M/L `label`/`price`/`size_factor`; on save, `product_sizes` rows are upserted and `products.price` is synced to the Medium row.

- [ ] **Step 1: Read `edit_product.php`** to locate the price field (`name="price"`, ~line 578) and the form's POST/save handler. Note the existing CSRF + update pattern.

- [ ] **Step 2: Add the "Has sizes" toggle + S/M/L rows** to the edit form, after the price field. Markup (match existing form-control classes):

```html
<label class="form-check">
  <input type="checkbox" id="has_sizes" name="has_sizes" value="1" onchange="document.getElementById('sizeRows').style.display=this.checked?'block':'none'">
  This product has sizes (S / M / L)
</label>
<div id="sizeRows" style="display:none">
  <!-- Render in sort_order; prefill from product_sizes if present, else defaults -->
  <?php
    $sizeDefaults = [
      ['code'=>'S','label'=>'Small','factor'=>'0.80','sort'=>0],
      ['code'=>'M','label'=>'Medium','factor'=>'1.00','sort'=>1],
      ['code'=>'L','label'=>'Large','factor'=>'1.30','sort'=>2],
    ];
    // $existingSizes = map size_code => row, loaded above from product_sizes ORDER BY sort_order
    foreach ($sizeDefaults as $d):
      $ex = $existingSizes[$d['code']] ?? null;
      $pv = $ex['price'] ?? '';
      $lv = $ex['label'] ?? $d['label'];
      $fv = $ex['size_factor'] ?? $d['factor'];
  ?>
  <div class="size-row">
    <input type="hidden" name="size_code[]" value="<?= $d['code'] ?>">
    <input type="hidden" name="size_sort[]" value="<?= $d['sort'] ?>">
    <input type="text"   name="size_label[]"  value="<?= htmlspecialchars($lv) ?>" placeholder="Label">
    <input type="number" step="0.01" min="0" name="size_price[]"  value="<?= htmlspecialchars($pv) ?>" placeholder="Price ($)">
    <input type="number" step="0.01" min="0" name="size_factor[]" value="<?= htmlspecialchars($fv) ?>" placeholder="Stock ×">
  </div>
  <?php endforeach; ?>
</div>
```

- [ ] **Step 3: Load existing sizes for prefill** near the top of `edit_product.php` where the product is fetched:

```php
$existingSizes = [];
$qs = $conn->prepare("SELECT size_code,label,price,size_factor,sort_order FROM product_sizes WHERE product_id=? ORDER BY sort_order ASC");
$qs->bind_param("i", $product_id);
$qs->execute();
$rs = $qs->get_result();
while ($row = $rs->fetch_assoc()) { $existingSizes[$row['size_code']] = $row; }
$hasSizes = !empty($existingSizes); // also reflect products.has_sizes
```

Set the checkbox `checked` and `#sizeRows` visible when `has_sizes=1`.

- [ ] **Step 4: Save handler — upsert sizes + sync Medium price.** In the POST branch, after the existing product UPDATE:

```php
$has_sizes = isset($_POST['has_sizes']) ? 1 : 0;
$conn->query("UPDATE products SET has_sizes=" . (int)$has_sizes . " WHERE product_id=" . (int)$product_id);

if ($has_sizes) {
    $codes   = $_POST['size_code']   ?? [];
    $labels  = $_POST['size_label']  ?? [];
    $prices  = $_POST['size_price']  ?? [];
    $factors = $_POST['size_factor'] ?? [];
    $sorts   = $_POST['size_sort']   ?? [];
    $up = $conn->prepare("INSERT INTO product_sizes (product_id,size_code,label,price,size_factor,sort_order)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE label=VALUES(label), price=VALUES(price), size_factor=VALUES(size_factor), sort_order=VALUES(sort_order)");
    $mediumPrice = null;
    foreach ($codes as $i => $code) {
        $price  = (float)($prices[$i] ?? 0);
        if ($price <= 0) continue; // skip blank rows
        $label  = trim((string)($labels[$i] ?? $code));
        $factor = (float)($factors[$i] ?? 1.0);
        $sort   = (int)($sorts[$i] ?? 0);
        $up->bind_param("issddi", $product_id, $code, $label, $price, $factor, $sort);
        $up->execute();
        if ($code === 'M') $mediumPrice = $price;
    }
    // Keep products.price == Medium so legacy single-price paths stay correct
    if ($mediumPrice !== null) {
        $sp = $conn->prepare("UPDATE products SET price=? WHERE product_id=?");
        $sp->bind_param("di", $mediumPrice, $product_id);
        $sp->execute();
    }
}
```

- [ ] **Step 5: Mirror the same toggle/rows/save into `add_product.php`** (insert path). After the product INSERT, get the new `$product_id = $conn->insert_id;` and run the same upsert block.

- [ ] **Step 6: Lint both.** `"C:/xampp/php/php.exe" -l edit_product.php` and `... -l add_product.php` → `No syntax errors detected`.

- [ ] **Step 7: Browser verify (admin `Sokun`).** Open `edit_product.php?id=PID` for the seeded product → checkbox is checked, three rows prefilled (Small/Medium/Large with prices). Change Large price to `3.25`, save. Then:

```bash
"C:/xampp/php/php.exe" -r '$m=new mysqli("localhost","root","","db_coffee");
$r=$m->query("SELECT size_code,price,size_factor,sort_order FROM product_sizes WHERE product_id=PID ORDER BY sort_order");
while($x=$r->fetch_assoc())echo json_encode($x)."\n";
echo "products.price=".$m->query("SELECT price FROM products WHERE product_id=PID")->fetch_assoc()["price"]."\n";'
```

Expected: L price `3.25`; `products.price` equals the Medium row price.

- [ ] **Step 8: Commit**

```bash
git add edit_product.php add_product.php
git commit -m "feat(admin): per-product size CRUD with Medium-price sync"
```

---

## Task 5: Admin — bulk enable by category + missing-size badge

**Files:**
- Modify: `products.php` (product list + a category bulk action)

**Interfaces:**
- Consumes: `products.has_sizes`, `product_sizes`.
- Produces: a "missing size prices" badge on rows where `has_sizes=1` and no size rows; a bulk action to enable sizes for a category (sets flag + seeds default rows).

- [ ] **Step 1: Compute missing-sizes set** where the product list is queried in `products.php`. Add a LEFT JOIN count or a per-row check:

```php
// products flagged sized but with zero size rows
$missing = [];
$mr = $conn->query("SELECT p.product_id FROM products p
    LEFT JOIN product_sizes ps ON ps.product_id=p.product_id
    WHERE p.has_sizes=1 GROUP BY p.product_id HAVING COUNT(ps.size_id)=0");
while ($row = $mr->fetch_assoc()) { $missing[(int)$row['product_id']] = true; }
```

- [ ] **Step 2: Render the badge** in the product row markup near the price cell (~line 1478):

```php
<?php if (!empty($missing[(int)$row['product_id']])): ?>
  <span class="badge badge-danger" title="has_sizes is on but no size prices set">missing size prices</span>
<?php endif; ?>
```

Use the existing badge/danger class in the stylesheet (search `.badge` in the page's CSS; reuse the warn/refund red token).

- [ ] **Step 3: Add the bulk action** (a small form: category select + "Enable sizes" button). Handler at the top of `products.php` (CSRF-guarded like other POSTs):

```php
if (($_POST['action'] ?? '') === 'bulk_enable_sizes' && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $cat = trim((string)($_POST['category'] ?? ''));
    if ($cat !== '') {
        $catEsc = $conn->real_escape_string($cat);
        $conn->query("UPDATE products SET has_sizes=1 WHERE category='$catEsc'");

        // seed default rows for products in that category that have none yet
        $seed = $conn->query("SELECT p.product_id, p.price FROM products p
            LEFT JOIN product_sizes ps ON ps.product_id=p.product_id
            WHERE p.category='$catEsc' GROUP BY p.product_id, p.price HAVING COUNT(ps.size_id)=0");

        // one single-row prepared insert, executed three times per product (S/M/L)
        $ins = $conn->prepare("INSERT INTO product_sizes
            (product_id,size_code,label,price,size_factor,sort_order) VALUES (?,?,?,?,?,?)");
        while ($p = $seed->fetch_assoc()) {
            $pid  = (int)$p['product_id'];
            $base = (float)$p['price'];
            $defaults = [
                ['S','Small',  round($base*0.8,2), 0.80, 0],
                ['M','Medium', $base,              1.00, 1],
                ['L','Large',  round($base*1.2,2), 1.30, 2],
            ];
            foreach ($defaults as $d) {
                $ins->bind_param("issddi", $pid, $d[0], $d[1], $d[2], $d[3], $d[4]);
                $ins->execute();
            }
        }
    }
    header('Location: products.php'); exit;
}
```

Seed prices (Small = `base*0.8`, Medium = `base`, Large = `base*1.2`) are starting points the admin then edits. Rows always carry a non-zero price, so the missing-size badge (which fires only on **zero rows**) stays clear after seeding.

- [ ] **Step 4: Lint.** `"C:/xampp/php/php.exe" -l products.php` → `No syntax errors detected`.

- [ ] **Step 5: Browser verify (admin).** Manually set a product `has_sizes=1` with zero rows via SQL, load `products.php` → red "missing size prices" badge shows on that row. Run the bulk action for its category → badge clears, `product_sizes` now has 3 rows for it.

```bash
"C:/xampp/php/php.exe" -r '$m=new mysqli("localhost","root","","db_coffee");
$pid=(int)$m->query("SELECT product_id FROM products WHERE has_sizes=0 LIMIT 1")->fetch_assoc()["product_id"];
$m->query("UPDATE products SET has_sizes=1 WHERE product_id=$pid");echo "flagged $pid (expect badge)\n";'
```

- [ ] **Step 6: Commit**

```bash
git add products.php
git commit -m "feat(admin): category bulk-enable sizes + missing-size-prices badge"
```

---

## Task 6: menu.php — size selector in product modal

**Files:**
- Modify: `menu.php` — product cards (~624-717 data attributes), modal markup (~983), `openModal()` (~1169), `addToCart()` (~1199), `quickAdd()` (~1221)

**Interfaces:**
- Consumes: `add_to_cart.php` `size` param (Task 2); `product_sizes` for embedding.
- Produces: a size pill group that sets the line price; sized products always route through the modal.

- [ ] **Step 1: Load sizes per product in the menu query.** Where products are fetched for rendering, also fetch their sizes into a map `[$pid => [ {code,label,price} ordered by sort_order ]]`. Then add two data attributes to each product card (the `data-product-*` blocks at ~624/666/711):

```php
data-product-has-sizes="<?= (int)($p['has_sizes'] ?? 0) ?>"
data-product-sizes='<?= htmlspecialchars(json_encode($sizesByProduct[(int)$p['product_id']] ?? []), ENT_QUOTES) ?>'
```

- [ ] **Step 2: Add a Size option section** in the modal, immediately BEFORE `#optSweetness` (~line 983):

```html
<div id="optSize" class="option-section" style="display:none">
  <div class="option-label">Size</div>
  <div class="pill-group" id="sizePills"></div>
</div>
```

- [ ] **Step 3: Pass sizes into `openModal`.** Update the card click handler/`onclick` to also pass the parsed sizes + has-sizes (read from the dataset where the card invokes openModal). Extend the signature:

```js
function openModal(id, name, price, img, cat, desc, badge, hasSizes, sizes) {
  var p = Number(price) || 0;
  product = { id: id, name: name, price: p, cat: cat };
  modalQty = 1; modalUnitPrice = p;
  // ... existing image/name/desc/badge code ...

  // ── Size pills (render in given order; default = Medium or first) ──
  var sizeWrap = document.getElementById('optSize');
  var pills = document.getElementById('sizePills');
  pills.innerHTML = '';
  if (hasSizes && Array.isArray(sizes) && sizes.length) {
    sizes.forEach(function(s) {
      var b = document.createElement('button');
      b.className = 'option-pill';
      b.dataset.group = 'size';
      b.dataset.value = s.code;
      b.dataset.price = s.price;
      b.textContent = s.label + ' $' + Number(s.price).toFixed(2);
      b.onclick = function(){ selectSize(b); };
      pills.appendChild(b);
    });
    // default Medium if present else first
    var def = pills.querySelector('[data-value="M"]') || pills.firstChild;
    if (def) { def.classList.add('active'); modalUnitPrice = Number(def.dataset.price) || p; }
    document.getElementById('modalPrice').textContent = '$' + modalUnitPrice.toFixed(2);
    sizeWrap.style.display = 'block';
  } else {
    sizeWrap.style.display = 'none';
  }
  // ... existing sweetness/ice/milk toggles + updateModalTotal + show modal ...
}

function selectSize(pill) {
  pill.closest('.pill-group').querySelectorAll('.option-pill').forEach(function(p){ p.classList.remove('active'); });
  pill.classList.add('active');
  modalUnitPrice = Number(pill.dataset.price) || modalUnitPrice;
  document.getElementById('modalPrice').textContent = '$' + modalUnitPrice.toFixed(2);
  updateModalTotal();
}
```

Update every `openModal(...)` call site to pass `this.dataset.productHasSizes==='1'` and `JSON.parse(this.dataset.productSizes||'[]')`.

- [ ] **Step 4: Send `size` in `addToCart()`** (~line 1206), after the milk append:

```js
  if (document.getElementById('optSize').style.display !== 'none') params.append('size', getPillValue('sizePills'));
```

(`getPillValue` already returns the active pill's `data-value` = size_code.)

- [ ] **Step 5: Guard `quickAdd()`** so sized products open the modal instead of adding a default size. At the top of `quickAdd(productId, price)` — the caller must pass has-sizes, or look it up from the card. Simplest: in the card markup, when `has_sizes=1`, the quick-add button calls `openModal(...)` instead of `quickAdd(...)`. Change the quick-add button's `onclick` conditionally in PHP:

```php
<?php if ((int)($p['has_sizes'] ?? 0) === 1): ?>
  onclick="openModal(<?= (int)$p['product_id'] ?>, '<?= e($p['name']) ?>', <?= e($p['price']) ?>, '<?= e($p['image']) ?>', '<?= e($p['category']) ?>', '<?= e($p['description']) ?>', '<?= e($p['badge_text']??'') ?>', true, <?= htmlspecialchars(json_encode($sizesByProduct[(int)$p['product_id']]??[]), ENT_QUOTES) ?>)"
<?php else: ?>
  onclick="quickAdd(<?= (int)$p['product_id'] ?>, <?= e($p['price']) ?>)"
<?php endif; ?>
```

- [ ] **Step 6: Lint.** `"C:/xampp/php/php.exe" -l menu.php` → `No syntax errors detected`.

- [ ] **Step 7: Browser verify (cashier `Sok_Dara`).** Open the seeded sized product → modal shows a Size group (Small/Medium/Large with prices), Medium preselected, header price = Medium price. Click Large → price updates to Large. Add to cart → cart shows the Large price. Open an unsized product → no Size group, behaves as before. Quick-add on a sized product → opens the modal (does not silently add).

- [ ] **Step 8: Commit**

```bash
git add menu.php
git commit -m "feat(menu): size selector in product modal, price-aware pills, quickAdd guard"
```

---

## Task 7: Cart display — size chip

**Files:**
- Modify: `cart.php` (~1167-1175 options display), `cart_refresh.php` (~67 line mapping), `order.php` (~34-37 line build + display)

**Interfaces:**
- Consumes: cart line `size_label` (Task 2).
- Produces: a "Size: Large" chip on each cart line that has a size.

- [ ] **Step 1: cart.php — render the size chip** before the sweetness span (~line 1167):

```php
<?php if (!empty($item['size_label'])): ?>
    <span>Size: <?= htmlspecialchars($item['size_label']) ?></span>
<?php endif; ?>
```

- [ ] **Step 2: cart_refresh.php — carry size in the line map** (~line 67), alongside `sweetness`:

```php
        'size_code'    => $item['size_code']  ?? '',
        'size_label'   => $item['size_label'] ?? '',
```

And render the chip wherever this file emits the line markup (mirror Step 1).

- [ ] **Step 3: order.php — include size** in the line build (~line 34-37) and display, mirroring sweetness. If `order.php` builds its own cart line, add `'size_code'`/`'size_label'` and a `Size:` span.

- [ ] **Step 4: Lint all three.** `php -l` each → `No syntax errors detected`.

- [ ] **Step 5: Browser verify.** With a Large item in the cart, the cart panel and `cart.php` both show "Size: Large" next to Sweet/Ice/Milk. A re-add of the same size+options increments qty (merge); a different size makes a new line.

- [ ] **Step 6: Commit**

```bash
git add cart.php cart_refresh.php order.php
git commit -m "feat(cart): show size chip on cart lines"
```

---

## Task 8: Downstream display — barista, KDS, receipts

**Files:**
- Modify: `barista_display.php` (~50 line map + render), `view_order.php` (~2600 line map + render), `receipt_pdf.php`, `receipt_print.php`

**Interfaces:**
- Consumes: `order_items.size_label`.
- Produces: size shown on prep displays and receipts.

- [ ] **Step 1: Include `size_label` in each query** that selects order_items for these screens. Add `size_label` (and `size_code` if useful) to the SELECT column lists.

- [ ] **Step 2: barista_display.php** — add to the item array (~line 50) and render it with the existing sweetness/ice/milk line:

```php
'size' => $r['size_label'] ?? null,
```

Render `Size: Large` near the other modifiers.

- [ ] **Step 3: view_order.php (KDS)** — add `"size" => $r["size_label"]` to the item map (~line 2600) and show it with the modifiers.

- [ ] **Step 4: receipt_pdf.php & receipt_print.php** — where each line item prints its name/qty/options, add the size. Keep it compact, e.g. append `(Large)` to the product name line or add a `Size: Large` sub-line consistent with how sweetness prints.

- [ ] **Step 5: Lint all four.** `php -l` each → `No syntax errors detected`.

- [ ] **Step 6: Browser/receipt verify.** Place a Large order (cashier). Confirm: barista_display shows "Size: Large"; the KDS card in view_order.php shows it; the printed/PDF receipt shows the size. Legacy orders (NULL size) show nothing. Clean test data after:

```bash
"C:/xampp/php/php.exe" -r '$m=new mysqli("localhost","root","","db_coffee");
$m->query("DELETE oi FROM order_items oi JOIN orders o ON o.order_id=oi.order_id WHERE o.customer_name=\"SizeTest\"");
$m->query("DELETE FROM orders WHERE customer_name=\"SizeTest\"");echo "cleaned\n";'
```

- [ ] **Step 7: Commit**

```bash
git add barista_display.php view_order.php receipt_pdf.php receipt_print.php
git commit -m "feat(display): show drink size on barista, KDS, and receipts"
```

---

## Final verification (after all tasks)

- [ ] Reset the test product if desired (`UPDATE products SET has_sizes=0; DELETE FROM product_sizes WHERE product_id=...`) or keep a real sized product configured for the user to review.
- [ ] Full smoke: as cashier, order one Small + one Large of the same drink → two cart lines, correct prices, correct totals/tax; confirm; verify `order_items` has both sizes, stock dropped scaled, receipt + barista + KDS all show sizes.
- [ ] Unsized product still adds via quick-add with no size and unchanged behavior.
- [ ] `superpowers:finishing-a-development-branch` to merge/PR `feat/drink-sizes`.

## Spec Coverage Check
- Pricing per-size absolute → Task 2 (resolve), Task 4 (enter). ✅
- has_sizes opt-in, no category runtime inheritance → Task 2 (lookup by product only), Task 5 (bulk = one-time seed, not live). ✅
- products.price == Medium → Task 4 Step 4 sync. ✅
- product_sizes normalized table → Task 1. ✅
- size_factor per-ingredient multiplier → Task 3 Steps 1-2. ✅
- order_items size_code + size_label snapshot, all 3 INSERTs → Task 3 Steps 3-5. ✅
- Merge key includes size → Task 2 Step 4. ✅
- Trust cart-line price at checkout → Task 3 (uses `$item['price']`, no re-resolve). ✅
- Defensive fallback + admin badge → Task 2 Step 3, Task 5 Steps 1-2. ✅
- sort_order ordering everywhere → Task 4 (prefill order), Task 6 (pill render order). ✅
- Displays (cart, barista, KDS, receipts) → Tasks 7-8. ✅
- YAGNI: no report size breakdown, no recipe matrix, S/M/L only → not implemented (correct). ✅
