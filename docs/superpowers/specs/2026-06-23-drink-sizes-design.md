# Drink Sizes (Small / Medium / Large) — Design

**Date:** 2026-06-23
**Branch target:** new feature branch off `main`
**Status:** Approved design, pending implementation plan

## Goal

Let drinks be sold in multiple sizes (Small / Medium / Large) with **per-size pricing** and **per-size stock consumption**. Food and other items stay single-price and unaffected. Sizing is **opt-in per product**.

## Decisions (locked)

| Question | Decision | Why |
|---|---|---|
| Pricing model | **Full per-product per-size price** (absolute price per size, no deltas) | Cafe upcharges vary by drink (Frappe Large > Americano Large); no checkout arithmetic → fewer bug classes |
| Scope / assignment | **Per-product `has_sizes` toggle**; category = bulk-set convenience only, **no live inheritance** | Explicit, debuggable; one-size specialties stay possible; new products don't silently inherit sizing |
| Storage | **Normalized child table `product_sizes`** (not JSON, not 3 columns) | Matches house style (all relations are FK child tables); server-side validation via join; room for a 4th size later with no parse/schema churn |
| Recipe scaling | **Per-size multiplier** (`size_factor`, default 1.0) applied per ingredient row | Keeps the stock_count / reconciliation features believable; reuses existing recipes; one number per size |
| order_items size storage | **Snapshot both `size_code` (stable key) and `size_label`** | Self-contained history matches existing `product_name`/`price` snapshotting; survives label renames; no join needed at receipt/KDS time |

## Data Model

### `products` (alter)
- Add `has_sizes TINYINT(1) NOT NULL DEFAULT 0`. Food = 0. `products.price` continues to mean the **Medium** price so every legacy single-price code path (recommend.php, unsized items, existing orders) keeps working untouched.

### `product_sizes` (new)
| col | type | note |
|---|---|---|
| `size_id` | INT PK AUTO_INCREMENT | |
| `product_id` | INT NOT NULL, FK → products(product_id) ON DELETE CASCADE | |
| `size_code` | VARCHAR(10) NOT NULL | `S` / `M` / `L` |
| `label` | VARCHAR(20) NOT NULL | "Small" / "Medium" / "Large" |
| `price` | DECIMAL(10,2) NOT NULL | absolute price for this size |
| `size_factor` | DECIMAL(4,2) NOT NULL DEFAULT 1.00 | recipe multiplier |
| `sort_order` | INT NOT NULL DEFAULT 0 | display order (S=0,M=1,L=2) |

- Unique key `(product_id, size_code)`.
- `ON DELETE CASCADE` from products is safe — deleting a product should drop its size rows. (Order history is independent; see below.)

### `order_items` (alter)
- Add `size_code VARCHAR(10) NULL` — stable key, **soft reference** to `product_sizes.size_code` (no FK constraint: `size_code` alone is not unique across products; the line also snapshots its own price).
- Add `size_label VARCHAR(20) NULL` — denormalized for display without a join.
- Legacy / unsized lines leave both NULL → render nothing.

### Migrations
- Add via the existing `config.php` + `schema_migrations` pattern (idempotent migration keys), e.g. `drink_sizes_v1` (alters + new table). Runs on next page load.

## Runtime Flow

### `add_to_cart.php`
- Accept new `size` POST param (a `size_code`).
- Fetch product incl. `has_sizes`.
- **If `has_sizes = 1`:**
  - `size_code` is **required**. Validate it belongs to the product via `product_sizes` join.
  - On valid: line `price` = that row's `price`; capture `size_code`, `label`, `size_factor`.
  - **Defensive fallback:** `has_sizes = 1` but **zero** `product_sizes` rows → treat as unsized, use `products.price`, factor 1.0 (POS must never crash on a data-integrity bug). Surfaced to admin via a badge (see Admin UI).
- **If `has_sizes = 0`:** ignore `size`; `price = products.price` (today's behavior). `size_factor = 1.0`.
- **Merge identity:** extend the existing key
  `product_id + sweetness + ice + milk` → **`product_id + size_code + sweetness + ice + milk`**
  (one combined key — Small Latte and Large Latte stay separate lines; two identical Large Lattes still merge).
- Cart line carries: `size_code`, `size_label`, resolved `price`, `size_factor`.

### Cart display
- `cart.php`, `cart_refresh.php`, `order.php`: render a `Size: Large` chip alongside Sweet / Ice / Milk (only when `size_code` set).

### `confirm_order.php`
- **Trust the cart-line price** — never re-resolve from `product_sizes` at checkout (price is snapshotted at add-to-cart, like unsized items today; mid-session admin price edits do not affect lines already in cart).
- Write `order_items.size_code` + `order_items.size_label` into **all three** INSERT sites:
  1. existing-order append (~line 160)
  2. new-order (~line 380)
  3. loyalty reward line (~line 403) — passes empty/NULL size.
  Each currently binds `(order_id, product_id, product_name, price, quantity, sweetness, ice, milk)` `iisdisss`; extend columns + bind types accordingly.
- **Stock deduction:** add a `float $size_factor = 1.0` param to `_deduct_stock(...)`. Inside the per-ingredient loop, line 537 becomes:
  `$amount = (float)$row['amount_used'] * $qty * $size_factor;`
  Applied **per ingredient row**, never to a precomputed total. Caller passes the cart line's `size_factor` (1.0 for unsized).

## Admin UI (`products.php`, `add_product.php`, `edit_product.php`)
- Per-product **"Has sizes"** toggle. When ON, reveal three rows (S / M / L), each with: `label`, `price`, `size_factor`. Prefill on first enable: S `factor 0.8`, M `factor 1.0` (price = current `products.price`), L `factor 1.3`. Admin edits prices.
- On save: upsert `product_sizes` rows; keep `products.price` synced to the Medium row's price (so legacy paths see Medium).
- **Bulk convenience:** a category-filtered action "Enable sizes for all products in category X" — sets `has_sizes = 1` and seeds default rows for each; admin then edits prices. **Not** a live rule — no runtime inheritance; price lookup never consults categories.
- **Data-integrity badge:** product list shows a red **"missing size prices"** badge for any product with `has_sizes = 1` and zero `product_sizes` rows, so the silent fallback never hides a misconfiguration.

## Other Surfaces
- **Barista display** (`barista_display.php`), **KDS** (`view_order.php`), **receipts** (`receipt_pdf.php`, `receipt_print.php`): show the size line from `order_items.size_label`.
- **Reports** (`report.php`, charts): unaffected — size is per-line; product/category aggregation still works. A size breakdown is explicitly out of scope (YAGNI).

## Edge Cases (locked)
1. `has_sizes = 1` + zero size rows → unsized fallback to `products.price`, factor 1.0, **plus** admin red badge.
2. Legacy orders: `size_code` / `size_label` NULL → display nothing.
3. Editing a product's size prices does **not** touch already-placed orders (price snapshotted on the line).
4. Turning `has_sizes` OFF keeps `product_sizes` rows (unused); toggling back ON restores them.
5. Product deletion cascades its `product_sizes` rows; order history is independent (snapshotted code/label/price), so historical lines stay correct.
6. Mid-session price change: cart line keeps its add-time price until re-added (correct; same as unsized today).

## Out of Scope (YAGNI)
- Per-ingredient per-size recipe matrices (multiplier is enough).
- Size breakdown analytics in reports.
- More than 3 sizes (schema supports it, but UI ships S/M/L).
- Customer-facing self-order size picker beyond the existing menu/order flow.

## Touched Files (anticipated)
- `config.php` (migration)
- `add_to_cart.php`
- `cart.php`, `cart_refresh.php`, `order.php` (display + cart line carry)
- `confirm_order.php` (3 INSERT sites + `_deduct_stock` factor)
- `products.php`, `add_product.php`, `edit_product.php` (admin UI + size CRUD + badge)
- `barista_display.php`, `view_order.php`, `receipt_pdf.php`, `receipt_print.php` (display)
- menu option modal markup (size selector, default = Medium) — in `menu.php` / `order.php`
