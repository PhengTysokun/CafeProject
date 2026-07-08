# Product Add-ons / Toppings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins define a reusable library of priced add-ons/toppings, assign them per product, and let customers pick them on the menu so the extras flow through cart, checkout, barista ticket, and receipt.

**Architecture:** Two config tables (`addons` library + `product_addons` mapping) plus a denormalized JSON snapshot column `order_items.addons_snapshot`. Add-on prices are folded into the existing per-unit `price`, so totals/loyalty/stock math is untouched. Admin CRUD clones `manage_categories.php`; per-product assignment clones the `product_sizes` chip pattern; customer menu mirrors the existing `$sizesByProduct` batch-load + `data-product-*` attribute pattern.

**Tech Stack:** PHP 8 + mysqli (prepared statements), vanilla JS/jQuery, no build step. **No PHP unit-test harness exists** — verification is DB queries (mysql CLI) + Playwright browser drives + visual screenshot reads, per project convention.

## Global Constraints

- All POST handlers CSRF-guard with `hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])` — copy the `manage_categories.php` pattern verbatim.
- All admin pages start with `require 'admin_only.php';` (pulls in `config.php` → `$conn`).
- All user-facing strings HTML-escaped via `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` (the `he()`/`e()` helpers).
- Schema changes go in `config.php` bootstrap using idempotent `CREATE TABLE IF NOT EXISTS` / `ALTER TABLE … ADD COLUMN IF NOT EXISTS`; tables `DEFAULT CHARSET=utf8mb4`. Do NOT recreate zombie tables.
- `order_items` column is named `addons_snapshot` (NOT `addons`) to avoid confusion with the `addons` table.
- Add-ons are on/off toggles (no quantity). No hard-delete — archive via `is_active=0`.
- Render order everywhere = `addons.display_order ASC`.
- Money folded into line `price`; the JSON snapshot is display-only.
- Branch: `feat/product-addons` (already checked out). Commit per task. Do NOT push/deploy.
- `config.php` must never be uploaded raw on deploy (DB creds).

---

## File Structure

- `config.php` — MODIFY: add schema bootstrap for `addons`, `product_addons`, `order_items.addons_snapshot` + seed rows.
- `manage_addons.php` — CREATE: add-on library CRUD (clone of `manage_categories.php`).
- `products.php` — MODIFY: add "Manage Add-ons" entry button.
- `add_product.php` — MODIFY: add-on chips markup + `product_addons` write on insert.
- `edit_product.php` — MODIFY: add-on chips markup (pre-checked) + `product_addons` replace on save.
- `menu.php` — MODIFY: `$addonsByProduct` batch query, `data-product-addons` attr, modal Add-ons section, JS toggle/total, addToCart params, cart-panel render.
- `add_to_cart.php` — MODIFY: accept/validate `addons[]`, fold price, store array, extend merge key.
- `confirm_order.php` — MODIFY: 3 `order_items` INSERT sites gain `addons_snapshot`.
- `barista_display.php` — MODIFY: SELECT `addons_snapshot`, decode, prepend to mods.
- `receipt_print.php` — MODIFY: SELECT `addons_snapshot`, render breakdown lines.
- `view_order.php`, `edit_order_items.php` — MODIFY: show add-ons where sweetness/ice/milk render.

---

## Task 1: Schema bootstrap + seed

**Files:**
- Modify: `config.php` (schema bootstrap block, near the other `CREATE TABLE IF NOT EXISTS` calls around line 100–200)

**Interfaces:**
- Produces: table `addons(id, name, price, is_active, display_order)`; table `product_addons(product_id, addon_id)`; column `order_items.addons_snapshot TEXT NULL`.

- [ ] **Step 1: Add the schema block to `config.php`**

Place alongside the existing catalog bootstrap (after the `products.badge_text` ALTER near line 100). Wrap in the same style as neighboring statements:

```php
// ── Add-ons (toppings) library + per-product mapping ──
$conn->query("CREATE TABLE IF NOT EXISTS addons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0
) DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS product_addons (
    product_id INT NOT NULL,
    addon_id INT NOT NULL,
    PRIMARY KEY (product_id, addon_id),
    INDEX idx_pa_addon (addon_id)
) DEFAULT CHARSET=utf8mb4");

$conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS addons_snapshot TEXT NULL");

// Seed a starter set once (only if the library is empty)
$__addon_n = (int)$conn->query("SELECT COUNT(*) AS n FROM addons")->fetch_assoc()['n'];
if ($__addon_n === 0) {
    $conn->query("INSERT INTO addons (name, price, is_active, display_order) VALUES
        ('Boba', 0.50, 1, 1),
        ('Jelly', 0.50, 1, 2),
        ('Tapioca', 0.50, 1, 3),
        ('Whipped Cream', 0.75, 1, 4),
        ('Coffee Jelly', 1.00, 1, 5),
        ('Extra Shot', 1.00, 1, 6)");
}
```

- [ ] **Step 2: Trigger the bootstrap and verify tables exist**

Load any page (bootstrap runs on every `config.php` include), then check:

Run:
```bash
"C:/xampp/mysql/bin/mysql.exe" -u root db_coffee -e "SHOW TABLES LIKE 'addons'; SHOW TABLES LIKE 'product_addons'; SHOW COLUMNS FROM order_items LIKE 'addons_snapshot'; SELECT COUNT(*) AS seeded FROM addons;"
```
Expected: both tables listed, `addons_snapshot` column present, `seeded` = 6.

(If MySQL socket differs, use the project's usual connection; DB is `db_coffee`, mysql binary at `C:/xampp/mysql/bin/mysql.exe`, user `root` no password, per `config.php`.)

- [ ] **Step 3: Verify idempotency**

Reload the page again, re-run the count query. Expected: `seeded` still 6 (no duplicate seed, no error).

- [ ] **Step 4: Commit**

```bash
git add config.php
git commit -m "feat(addons): schema bootstrap for addons library, mapping, order snapshot"
```

---

## Task 2: `manage_addons.php` library CRUD + products.php entry

**Files:**
- Create: `manage_addons.php`
- Modify: `products.php` (topbar / actions area — add a "Manage Add-ons" button next to the existing "Manage Categories" link)

**Interfaces:**
- Consumes: `addons` table from Task 1.
- Produces: admin page at `manage_addons.php` supporting actions `create`, `update`, `reorder`, `archive` (toggles `is_active`).

- [ ] **Step 1: Create `manage_addons.php`**

This is a focused clone of `manage_categories.php` (same CSS block, topbar, theme toggle). Differences: fields are name + price (no icon/slug); the destructive action is **archive** (not delete); an active toggle and a "show archived" filter. Copy the `<style>` block from `manage_categories.php` verbatim, then use this PHP + body:

```php
<?php
require 'admin_only.php';   // admin/manager only; pulls in config.php ($conn)

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please retry.'];
    } else {
        switch ($_POST['action'] ?? '') {
            case 'create': {
                $name  = trim((string)($_POST['name'] ?? ''));
                $price = (float)($_POST['price'] ?? 0);
                if ($name === '') { $flash = ['type'=>'error','msg'=>'Add-on name is required.']; break; }
                if ($price < 0)   { $flash = ['type'=>'error','msg'=>'Price cannot be negative.']; break; }
                $ord = (int)$conn->query("SELECT COALESCE(MAX(display_order),0)+1 AS n FROM addons")->fetch_assoc()['n'];
                $ins = $conn->prepare("INSERT INTO addons (name, price, is_active, display_order) VALUES (?, ?, 1, ?)");
                $ins->bind_param('sdi', $name, $price, $ord);
                $ins->execute();
                $flash = ['type'=>'success','msg'=>"Add-on \"$name\" added."];
                break;
            }
            case 'update': {
                $id    = (int)($_POST['id'] ?? 0);
                $name  = trim((string)($_POST['name'] ?? ''));
                $price = (float)($_POST['price'] ?? 0);
                if ($id <= 0 || $name === '') { $flash = ['type'=>'error','msg'=>'Name is required.']; break; }
                if ($price < 0) { $flash = ['type'=>'error','msg'=>'Price cannot be negative.']; break; }
                $u = $conn->prepare("UPDATE addons SET name=?, price=? WHERE id=?");
                $u->bind_param('sdi', $name, $price, $id);
                $u->execute();
                $flash = ['type'=>'success','msg'=>'Add-on updated.'];
                break;
            }
            case 'archive': {  // toggles is_active both ways
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $conn->query("UPDATE addons SET is_active = 1 - is_active WHERE id = " . $id);
                    $flash = ['type'=>'success','msg'=>'Add-on visibility updated.'];
                }
                break;
            }
            case 'reorder': {
                $id  = (int)($_POST['id'] ?? 0);
                $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
                if ($id > 0) {
                    $cur = $conn->query("SELECT id, display_order FROM addons WHERE id = " . $id)->fetch_assoc();
                    if ($cur) {
                        $cmp = $dir === 'up' ? '<' : '>';
                        $ord = $dir === 'up' ? 'DESC' : 'ASC';
                        $nb = $conn->query("SELECT id, display_order FROM addons WHERE display_order $cmp " . (int)$cur['display_order'] . " ORDER BY display_order $ord LIMIT 1")->fetch_assoc();
                        if ($nb) {
                            $a=(int)$cur['display_order']; $b=(int)$nb['display_order'];
                            $ca=(int)$cur['id']; $cb=(int)$nb['id'];
                            $conn->query("UPDATE addons SET display_order = $b WHERE id = $ca");
                            $conn->query("UPDATE addons SET display_order = $a WHERE id = $cb");
                            $flash = ['type'=>'success','msg'=>'Order updated.'];
                        }
                    }
                }
                break;
            }
        }
    }
}

$showArchived = isset($_GET['archived']);
$where = $showArchived ? '' : 'WHERE is_active = 1';
$addons = [];
$res = $conn->query("
    SELECT a.id, a.name, a.price, a.is_active, a.display_order,
           (SELECT COUNT(*) FROM product_addons pa WHERE pa.addon_id = a.id) AS product_count
    FROM addons a $where
    ORDER BY a.display_order ASC, a.id ASC
");
while ($row = $res->fetch_assoc()) $addons[] = $row;
?>
```

Body (reuse the categories `.cat-table` classes; adjust columns to Order / Name / Price / Products / Active / Actions). Create form:

```html
<form method="POST" style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="create">
    <div style="display:flex;flex-direction:column;gap:5px;">
        <label style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Name</label>
        <input type="text" name="name" required placeholder="e.g. Extra Shot" style="padding:7px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);font-size:13px;font-family:'Poppins',sans-serif;">
    </div>
    <div style="display:flex;flex-direction:column;gap:5px;">
        <label style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Price ($)</label>
        <input type="number" step="0.01" min="0" name="price" value="0.50" style="padding:7px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);font-size:13px;width:120px;">
    </div>
    <button type="submit" class="btn-nav" style="border-color:var(--accent);color:var(--accent);padding-bottom:8px;"><i class="fa-solid fa-plus"></i> Add Add-on</button>
    <a href="?<?= $showArchived ? '' : 'archived=1' ?>" class="btn-nav" style="padding-bottom:8px;margin-left:auto;"><?= $showArchived ? 'Hide archived' : 'Show archived' ?></a>
</form>
```

Rows — reorder up/down forms identical to categories, then Edit toggles an inline update form, then the Active toggle uses `action=toggle`→ rename to `archive`, and the archive/restore control. The **Archive** button must confirm; **Restore** must not:

```html
<td>
    <button type="button" class="act-link" onclick="toggleEdit(<?= (int)$a['id'] ?>)">Edit</button>
    <form method="POST" style="display:inline;" <?= $a['is_active'] ? "onsubmit=\"return confirm('Archive this add-on? It disappears from every product modal until restored.');\"" : '' ?>>
        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <button type="submit" class="act-link <?= $a['is_active'] ? 'danger-link' : '' ?>"><?= $a['is_active'] ? 'Archive' : 'Restore' ?></button>
    </form>
</td>
```

Price cell: `<td>$<?= number_format((float)$a['price'], 2) ?></td>`. Products cell: `<td><?= (int)$a['product_count'] ?></td>`. Inactive rows get class `cat-row inactive`. Reuse the `toggleEdit`, `toggleTheme` JS from categories.

- [ ] **Step 2: Add the entry button to `products.php`**

Find the "Manage Categories" link in `products.php` and add a sibling button right after it:

Run to locate:
```bash
grep -n "manage_categories" products.php
```

Add next to it (match the existing button classes on that line):
```html
<a href="manage_addons.php" class="btn-nav"><i class="fa-solid fa-plus-circle"></i> Manage Add-ons</a>
```

- [ ] **Step 3: Verify CRUD in browser (Playwright)**

Log in as admin (`Sokun` / `@Sokun9811` at `login.php`), navigate to `https://localhost/cafe/manage_addons.php`. Verify:
- Six seeded add-ons list in order.
- Add "Test Topping" $0.25 → appears at bottom.
- Edit it to $0.30 → persists on reload.
- Reorder up → moves.
- Archive it (confirm dialog appears) → row leaves the default list; "Show archived" reveals it with a Restore button; Restore returns it.

Screenshot light + dark theme.

- [ ] **Step 4: Commit**

```bash
git add manage_addons.php products.php
git commit -m "feat(addons): manage_addons library CRUD + products.php entry"
```

---

## Task 3: Per-product add-on assignment (add & edit product)

**Files:**
- Modify: `add_product.php` (markup after the sizes block ~line 373; write handler after size handling ~line 65)
- Modify: `edit_product.php` (markup after sizes block; write handler after size handling ~line 104; prefill query)

**Interfaces:**
- Consumes: `addons` (active list), `product_addons`.
- Produces: on save, `product_addons` rows for the product reflect the ticked chips (delete-all + insert).

- [ ] **Step 1: Load the active add-on library for the chips (both files, near the top after other lookups)**

In `add_product.php` (after the category load, before HTML) and `edit_product.php` (after `$cats` load ~line 119):
```php
$allAddons = [];
$__ar = $conn->query("SELECT id, name, price FROM addons WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
while ($__a = $__ar->fetch_assoc()) $allAddons[] = $__a;
```

In `edit_product.php` also load current assignments for prefill:
```php
$assignedAddons = [];
$__aa = $conn->prepare("SELECT addon_id FROM product_addons WHERE product_id = ?");
$__aa->bind_param('i', $id);
$__aa->execute();
$__ar2 = $__aa->get_result();
while ($__r = $__ar2->fetch_assoc()) $assignedAddons[(int)$__r['addon_id']] = true;
```
(In `add_product.php` there is no product yet, so `$assignedAddons = [];`.)

- [ ] **Step 2: Add the chips markup after the sizes block in both files**

After the `<div id="sizeRows">…</div>` (add_product.php ~line 373; the matching block in edit_product.php), insert:
```html
<?php if (!empty($allAddons)): ?>
<div class="input-group" style="padding-left:0;">
    <label style="display:block;font-size:13px;color:var(--text-muted);margin-bottom:8px;">Available Add-ons</label>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ($allAddons as $ad): $on = !empty($assignedAddons[(int)$ad['id']]); ?>
        <label class="addon-chip<?= $on ? ' on' : '' ?>" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:50px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);cursor:pointer;font-size:13px;">
            <input type="checkbox" name="addon_id[]" value="<?= (int)$ad['id'] ?>" <?= $on ? 'checked' : '' ?> style="display:none;" onchange="this.closest('.addon-chip').classList.toggle('on', this.checked);">
            <?= htmlspecialchars($ad['name'], ENT_QUOTES, 'UTF-8') ?> +$<?= number_format((float)$ad['price'], 2) ?>
        </label>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
```

Add to the page `<style>`:
```css
.addon-chip.on { border-color:var(--accent); background:rgba(209,144,75,.12); color:var(--accent); }
```

- [ ] **Step 3: Persist assignments — add_product.php**

In `add_product.php`, inside the `if ($stmt->execute())` block, after the sizes handling and before `header("Location: products.php")` (~line 65), add:
```php
// ── Add-on assignments ──
$addonIds = array_values(array_unique(array_map('intval', $_POST['addon_id'] ?? [])));
if ($addonIds) {
    $pa = $conn->prepare("INSERT IGNORE INTO product_addons (product_id, addon_id) VALUES (?, ?)");
    foreach ($addonIds as $aid) {
        if ($aid > 0) { $pa->bind_param('ii', $product_id, $aid); $pa->execute(); }
    }
}
```

- [ ] **Step 4: Persist assignments — edit_product.php**

In `edit_product.php`, after the size handling (~line 104, still inside the POST-save branch), add delete-all + insert:
```php
// ── Add-on assignments: replace set ──
$conn->query("DELETE FROM product_addons WHERE product_id = " . (int)$id);
$addonIds = array_values(array_unique(array_map('intval', $_POST['addon_id'] ?? [])));
if ($addonIds) {
    $pa = $conn->prepare("INSERT IGNORE INTO product_addons (product_id, addon_id) VALUES (?, ?)");
    foreach ($addonIds as $aid) {
        if ($aid > 0) { $pa->bind_param('ii', $id, $aid); $pa->execute(); }
    }
}
// refresh prefill
$assignedAddons = [];
foreach ($addonIds as $aid) $assignedAddons[$aid] = true;
```

- [ ] **Step 5: Verify in browser**

As admin, open `edit_product.php?id=<a milk-tea product>`. Tick Boba + Whipped Cream, Save. Reload → both chips pre-highlighted. Verify DB:
```bash
"C:/xampp/mysql/bin/mysql.exe" -u root db_coffee -e "SELECT pa.addon_id, a.name FROM product_addons pa JOIN addons a ON a.id=pa.addon_id WHERE pa.product_id=<ID>;"
```
Expected: the two rows. Untick one, Save → only one row remains.

- [ ] **Step 6: Commit**

```bash
git add add_product.php edit_product.php
git commit -m "feat(addons): per-product add-on assignment chips (add + edit product)"
```

---

## Task 4: Customer menu — deliver, render, price add-ons

**Files:**
- Modify: `menu.php` (batch query near `$sizesByProduct` ~line 143; `data-product-addons` on each card ~lines 656–756; modal Add-ons section after `#optMilk` ~line 1059; JS in the modal block ~lines 1220–1313; cart panel render)

**Interfaces:**
- Consumes: `product_addons ⋈ addons`.
- Produces: `data-product-addons` JSON per card; `params.append('addons[]', id)` per selected chip in the addToCart POST.

- [ ] **Step 1: Batch-load add-ons per product (mirror `$sizesByProduct`)**

After the `$sizesByProduct` build (~line 148), add:
```php
/* ── ADD-ONS PER PRODUCT (active only) ── */
$addonsByProduct = [];
$ad_res = $conn->query("
    SELECT pa.product_id, a.id, a.name, a.price
    FROM product_addons pa
    JOIN addons a ON a.id = pa.addon_id
    WHERE a.is_active = 1
    ORDER BY pa.product_id, a.display_order ASC, a.id ASC
");
if ($ad_res) {
    while ($ad_row = $ad_res->fetch_assoc()) {
        $addonsByProduct[(int)$ad_row['product_id']][] = [
            'id'    => (int)$ad_row['id'],
            'name'  => $ad_row['name'],
            'price' => (float)$ad_row['price'],
        ];
    }
}
```

- [ ] **Step 2: Emit `data-product-addons` on every product card**

Each card already has `data-product-sizes='…'` (lines ~664, ~708, ~756). Immediately after each, add:
```php
data-product-addons='<?= htmlspecialchars(json_encode($addonsByProduct[(int)$p['product_id']] ?? []), ENT_QUOTES) ?>'
```
(For the featured/`$t` card at ~664 use `$t['product_id']`.) There are three card render blocks — add it to all three.

- [ ] **Step 3: Add the modal Add-ons section**

After the `#optMilk` block (closes at ~line 1059), before `<div class="modal-footer">`:
```html
<div id="optAddons" class="option-section" style="display:none">
    <div class="option-label">Add-ons</div>
    <div class="pill-group" id="addonPills"></div>
</div>
```

- [ ] **Step 4: Extend modal JS — state, render, toggle, total**

In `menu.php` modal JS: add `modalAddonTotal` to the state (near `var product = {}, modalQty = 1, modalUnitPrice = 0;` ~line 1220):
```js
var modalAddonTotal = 0;
```

Extend `openModalFromCard` to parse and pass add-ons:
```js
function openModalFromCard(card) {
  if (!card) return;
  var sizes = [], addons = [];
  try { sizes = JSON.parse(card.dataset.productSizes || '[]'); } catch (e) { sizes = []; }
  try { addons = JSON.parse(card.dataset.productAddons || '[]'); } catch (e) { addons = []; }
  openModal(card.dataset.productId, card.dataset.productName||'', Number(card.dataset.productPrice||0), card.dataset.productImage||'', card.dataset.productCategory||'', card.dataset.productDesc||'', card.dataset.productBadge||'', card.dataset.productHasSizes==='1', sizes, addons);
}
```

Add an `addons` param to `openModal` (append to the signature) and render the pills + reset the total. Inside `openModal`, before `updateModalTotal();`:
```js
  // ── Add-on pills (multi-select toggles) ──
  modalAddonTotal = 0;
  var addonWrap = document.getElementById('optAddons');
  var addonBox  = document.getElementById('addonPills');
  addonBox.innerHTML = '';
  if (Array.isArray(addons) && addons.length) {
    addons.forEach(function(a) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'option-pill';
      b.dataset.addonId = a.id;
      b.dataset.addonPrice = a.price;
      b.textContent = a.name + ' +$' + Number(a.price).toFixed(2);
      b.onclick = function(){ toggleAddon(b); };
      addonBox.appendChild(b);
    });
    addonWrap.style.display = 'block';
  } else {
    addonWrap.style.display = 'none';
  }
```

Add the toggle function (near `selectPill`) and update the total calc:
```js
function toggleAddon(pill) {
  pill.classList.toggle('active');
  var t = 0;
  document.querySelectorAll('#addonPills .option-pill.active').forEach(function(p){ t += Number(p.dataset.addonPrice) || 0; });
  modalAddonTotal = t;
  updateModalTotal();
}
```
Change `updateModalTotal` to include add-ons:
```js
function updateModalTotal() { document.getElementById('modalTotalDisplay').textContent = '$' + ((modalUnitPrice + modalAddonTotal) * modalQty).toFixed(2); }
```

- [ ] **Step 5: Send selected add-ons in addToCart**

In `addToCart()` after the size append (~line 1301):
```js
  document.querySelectorAll('#addonPills .option-pill.active').forEach(function(p){ params.append('addons[]', p.dataset.addonId); });
```

- [ ] **Step 6: Verify menu math in browser**

Menu → open a product with add-ons. Base/size total shows. Tick two add-ons → `modalTotalDisplay` rises by their sum; qty 3 → `(base + a1 + a2) × 3`. Untick → drops. Screenshot the modal with add-ons visible.

- [ ] **Step 7: Commit**

```bash
git add menu.php
git commit -m "feat(addons): menu modal renders add-ons, live total, sends selection"
```

---

## Task 5: `add_to_cart.php` — validate, price, store, merge

**Files:**
- Modify: `add_to_cart.php` (options parse ~line 31; fetch/validate; cart item build ~line 99–128)

**Interfaces:**
- Consumes: POST `addons[]` (ids), `product_addons`, `addons`.
- Produces: cart item key `addons` => array of `{id,name,price}`; line `price` includes add-on sum. Merge key includes sorted add-on id signature.

- [ ] **Step 1: Parse + validate posted add-ons (after the sweetness/ice/milk validation ~line 49)**

```php
// ── Add-ons: validate posted ids against this product's active assignments ──
$posted_addons = array_values(array_unique(array_map('intval', $_POST['addons'] ?? [])));
$addons = [];       // ordered [{id,name,price}]
$addon_sum = 0.0;
if ($posted_addons) {
    $in = implode(',', array_fill(0, count($posted_addons), '?'));
    $types = str_repeat('i', count($posted_addons));
    $sql = "SELECT a.id, a.name, a.price
            FROM product_addons pa JOIN addons a ON a.id = pa.addon_id
            WHERE pa.product_id = ? AND a.is_active = 1 AND a.id IN ($in)
            ORDER BY a.display_order ASC, a.id ASC";
    $st = $conn->prepare($sql);
    $st->bind_param('i' . $types, $product_id, ...$posted_addons);
    $st->execute();
    $rs = $st->get_result();
    $valid_ids = [];
    while ($r = $rs->fetch_assoc()) {
        $addons[] = ['id'=>(int)$r['id'], 'name'=>$r['name'], 'price'=>(float)$r['price']];
        $addon_sum += (float)$r['price'];
        $valid_ids[] = (int)$r['id'];
    }
    // reject if any posted id was not a valid assignment
    if (count($valid_ids) !== count($posted_addons)) {
        json_out(false, 'Invalid add-on selection', 0, null, 400);
    }
}
```

**Note:** this block runs after `$product_id` is set (line 24) — the fetch at line 52 confirms the product exists; place this validation *after* that fetch so an invalid product still 404s first. Move the block to just after `$p = $res->fetch_assoc();` (line 61).

- [ ] **Step 2: Fold add-on price into the line price**

After the size resolution sets `$line_price` (~line 89), add:
```php
$line_price += $addon_sum;   // add-ons are per-unit extras
```

- [ ] **Step 3: Extend the merge comparison + stored item**

Add an add-on signature and use it in the merge loop. Before the merge loop (~line 98):
```php
$addon_sig = implode(',', array_map(fn($a) => $a['id'], $addons));  // ordered ids
```
In the merge `if (...)` (~line 100), add a final condition:
```php
        && (implode(',', array_map(fn($x) => $x['id'], $item['addons'] ?? [])) === $addon_sig)
```
In the `$_SESSION['cart'][] = [...]` push (~line 115), add:
```php
        'addons'       => $addons,
```

- [ ] **Step 4: Verify server-side pricing + rejection**

Drive from the menu (Task 4). Add a drink with two add-ons → `cart_total` in the JSON response reflects folded price. Add the *same* drink + same add-ons again → merges (qty 2). Add same drink with *different* add-ons → separate line.

Rejection test (add-on not assigned to the product):
```bash
curl -k -s -X POST https://localhost/cafe/add_to_cart.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data "id=<PID>&qty=1&csrf_token=<TOKEN>&addons[]=999999" | head
```
Expected: `{"success":false,"message":"Invalid add-on selection",...}` (999999 not assigned). (Grab a live CSRF + session cookie from the browser; or assert via the UI that a tampered request fails.)

- [ ] **Step 5: Commit**

```bash
git add add_to_cart.php
git commit -m "feat(addons): validate + price + store add-ons in cart, merge-aware"
```

---

## Task 6: `confirm_order.php` — persist snapshot at all 3 insert sites

**Files:**
- Modify: `confirm_order.php` (INSERT sites ~line 190, ~line 415, ~line 442)

**Interfaces:**
- Consumes: cart item `addons` array.
- Produces: `order_items.addons_snapshot` = `json_encode($item['addons'])`.

- [ ] **Step 1: Update each of the 3 prepared inserts**

For **each** `INSERT INTO order_items (...)` statement, add the column + placeholder:
```php
INSERT INTO order_items (order_id, product_id, product_name, price, quantity, sweetness, ice, milk, size_code, size_label, addons_snapshot)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
```

- [ ] **Step 2: Bind the JSON at each execute site**

At each site, before `->execute()`, add the snapshot var and extend the `bind_param` type string with a trailing `s`. Representative (line ~207):
```php
$addons_json = json_encode($item['addons'] ?? []);
$stmt_item->bind_param("iisdissssss", $existing_order_id, $product_id, $pname, $price, $qty, $sweet, $ice, $milk, $scode, $slabel, $addons_json);
```
Apply the identical change (add `$addons_json` + one more `s`) at the other two insert/execute sites (~415 and ~442). Confirm each type string gains exactly one `s` for the new bind.

- [ ] **Step 3: Verify end-to-end checkout**

Place a real order with add-ons through the UI (cash or paylater). Then:
```bash
"C:/xampp/mysql/bin/mysql.exe" -u root db_coffee -e "SELECT product_name, price, addons_snapshot FROM order_items ORDER BY item_id DESC LIMIT 3;"
```
Expected: newest row's `addons_snapshot` holds valid JSON like `[{"id":1,"name":"Boba","price":0.5}]`; `price` includes the add-on. An order placed with **no** add-ons stores `[]`.

- [ ] **Step 4: Commit**

```bash
git add confirm_order.php
git commit -m "feat(addons): persist add-on JSON snapshot on order_items (all 3 insert paths)"
```

---

## Task 7: Display surfaces — cart, barista, receipt, order views

**Files:**
- Modify: `cart_refresh.php` (`$items_out` build ~line 61) + `menu.php` cart-panel render JS
- Modify: `barista_display.php` (SELECT ~line 24; item build ~line 47; `buildMods` JS ~line 287)
- Modify: `receipt_print.php` (SELECT ~line 41; item loop ~line 224)
- Modify: `view_order.php`, `edit_order_items.php` (wherever sweetness/ice/milk render)

**Interfaces:**
- Consumes: cart item `addons` array; `order_items.addons_snapshot` JSON.

- [ ] **Step 1: Cart panel (live cart)**

In `cart_refresh.php` `$items_out[]` (~line 61), add:
```php
        'addons'       => $item['addons'] ?? [],
```
In `menu.php` `loadCartPanel()` render (where it prints size/sweetness per item), add an add-ons line. After the existing mods rendering for an item, insert:
```js
      (item.addons && item.addons.length
        ? '<div class="cart-item-mod">' + item.addons.map(function(a){ return escH(a.name); }).join(', ') + '</div>'
        : '')
```
(Match the surrounding template's concatenation style; use the existing mod line class.)

- [ ] **Step 2: Barista display — SELECT + decode + prepend**

In `barista_display.php` AJAX SELECT (~line 24), add `oi.addons_snapshot`:
```sql
oi.product_name, oi.quantity, oi.sweetness, oi.ice, oi.milk, oi.size_label, oi.addons_snapshot
```
In the item build (~line 47), decode and pass names:
```php
                'addons'    => array_map(fn($a) => $a['name'], json_decode($r['addons_snapshot'] ?? '[]', true) ?: []),
```
In `buildMods` JS (~line 287), prepend add-ons above size/sweetness, guarded:
```js
function buildMods(item) {
    const mods = [];
    if (item.addons && item.addons.length) item.addons.forEach(a => mods.push(a));
    if (item.size)      mods.push('Size: ' + item.size);
    if (item.sweetness) mods.push(item.sweetness);
    if (item.ice)       mods.push(item.ice + ' ice');
    if (item.milk)      mods.push(item.milk);
    return mods;
}
```

- [ ] **Step 2 verify:** Place an order with add-ons, open `barista_display.php`. Ticket shows add-on names first (e.g. `Boba, Extra Shot, Size: L, 50%, Normal ice`). A no-add-on ticket looks exactly as before (no leading comma).

- [ ] **Step 3: Receipt — SELECT + breakdown lines**

In `receipt_print.php` SELECT (~line 41), add `addons_snapshot`:
```sql
SELECT product_name, price, quantity, sweetness, ice, milk, size_label, addons_snapshot
```
In the item loop (~line 224, after the size line), add:
```php
            <?php $__ad = json_decode($item['addons_snapshot'] ?? '[]', true) ?: []; ?>
            <?php foreach ($__ad as $__a): ?>
            <div class="small">+ <?= htmlspecialchars($__a['name']) ?> +$<?= number_format((float)$__a['price'], 2) ?></div>
            <?php endforeach; ?>
```
(Line total already includes add-ons; this is display only.)

- [ ] **Step 3 verify:** Print/preview the receipt for that order → each add-on shows on its own indented line with price; the line total matches `price × qty`.

- [ ] **Step 3b: Same for the other receipts (`receipt_pdf.php`, `receipt_paylater.php`)**

Both render the same order-item list. Locate their item SELECT and loop:
```bash
grep -n "sweetness\|size_label\|product_name\|addons_snapshot" receipt_pdf.php receipt_paylater.php
```
Add `addons_snapshot` to each SELECT, then in each item loop add the same decode + per-add-on line used in Step 3 (adapt the markup to each file — `receipt_pdf.php` uses dompdf-friendly HTML, `receipt_paylater.php` mirrors `receipt_print.php`). Verify a paylater receipt and the PDF both list add-ons.

- [ ] **Step 4: Order views (`view_order.php`, `edit_order_items.php`)**

Run to find where mods render in each:
```bash
grep -n "sweetness\|size_label\|addons_snapshot" view_order.php edit_order_items.php
```
Ensure each SELECT that reads order items includes `addons_snapshot`, then next to the sweetness/ice/milk output add:
```php
<?php $__ad = json_decode($item['addons_snapshot'] ?? '[]', true) ?: [];
if ($__ad): ?>
  <span class="mod">Add-ons: <?= htmlspecialchars(implode(', ', array_map(fn($a) => $a['name'], $__ad))) ?></span>
<?php endif; ?>
```
(Adapt the variable name and wrapper to each file's existing item loop.)

- [ ] **Step 4 verify:** Open the order in `view_order.php` → add-ons listed. If that order is editable, `edit_order_items.php` shows them too. A legacy order (`addons_snapshot` NULL) renders with no add-on line and no PHP warning.

- [ ] **Step 5: Commit**

```bash
git add cart_refresh.php menu.php barista_display.php receipt_print.php receipt_pdf.php receipt_paylater.php view_order.php edit_order_items.php
git commit -m "feat(addons): show add-ons on cart, barista ticket, receipts, order views"
```

---

## Final verification (whole feature)

- [ ] **End-to-end walkthrough (Playwright, admin + cashier):**
  1. Admin: create an add-on, assign Boba + Extra Shot to a milk tea.
  2. Cashier/menu: open that drink, pick both add-ons, qty 2 → total = `(size + 0.50 + 1.00) × 2`.
  3. Checkout (cash) → order placed.
  4. `order_items.addons_snapshot` holds valid JSON; grand total correct.
  5. Barista ticket shows add-ons first; receipt shows breakdown lines; `view_order` lists them.
  6. Archive Extra Shot → it vanishes from the menu modal; the existing order still shows it (snapshot intact); restore returns it to the modal with the product still assigned.
- [ ] Screenshot light + dark for manage_addons, product editor chips, menu modal.
- [ ] `git log --oneline` shows one focused commit per task.

## Rollout

- Branch `feat/product-addons`. Do NOT push/deploy from here.
- On deploy: schema guards run via `config.php` on target; never upload `config.php` raw (DB creds).
- Set real add-on prices in `manage_addons.php` post-deploy (seed prices are placeholders).
