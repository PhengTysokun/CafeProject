# Category Management (Catalog Settings — Phase 1)

## Problem

Product categories live in a `categories` table (columns: `category_id`, `slug`,
`name`, `icon`, `display_order`, `is_active`) that is already the source of truth for
every category picker in the app — `add_product.php`, `edit_product.php`, and `menu.php`
all read `SELECT slug, name, icon FROM categories WHERE is_active = 1 ORDER BY
display_order`. But there is **no UI to insert, edit, delete, reorder, or deactivate**
rows in that table. The 5 seeded categories (Iced, Hot, Frappe, Juice, Milk Tea) are
effectively fixed — a user who wants to add "Smoothies" cannot.

This is Phase 1 of a larger "make the catalog customizable" effort. Later phases
(badge presets, size definitions) are out of scope here but the page is structured so
they can slot in as new tabs later.

## Identity model (critical)

`products.category` stores the category **slug** (e.g. `"Iced"`, `"Milk Tea"`), and
`products.category_id` is the FK to `categories.category_id`. The slug is the stable
key wired across the app. Therefore:

- **slug is immutable after creation.** Renaming a category changes only its display
  `name` / `icon` / `display_order` / `is_active` — never the slug — so no product link
  can break.
- On **add**, the slug is derived from the entered Name by trimming and collapsing
  internal whitespace to single spaces **only** — it is NOT lowercased and NOT
  hyphenated. This is deliberate: existing slugs are Title Case with spaces (`Iced`,
  `Hot`, `Milk Tea`), `products.category` stores that exact string, and it is rendered
  to users as the category badge (`products.php`, the `.category-badge` span). A web-style
  `milk-tea` slug would both mismatch existing data and show ugly badges. The "slug" here
  is effectively a stable Title-Case display key, not a URL slug. Reject creation if the
  derived slug collides with an existing slug, compared case-insensitively.
- Because slug is immutable and delete is blocked while in use (below), `products.category`
  and `products.category_id` can never be orphaned or desynced by this feature.
- The slug↔category_id coupling is already enforced on every product save:
  `add_product.php` and `edit_product.php` both `SELECT category_id FROM categories
  WHERE slug = ?` from the selected slug before writing. This feature does not change
  that; it only manages the rows those lookups resolve against.

## Scope

**In scope:** a new `manage_categories.php` admin page with full CRUD + reorder +
active-toggle over the `categories` table, plus a small change to `products.php` so its
filter bar reads categories from the table.

**Out of scope (later phases):** badge presets, size definitions, price-filter ranges,
per-category colors, bulk operations, slug renaming.

## Components

### 1. `manage_categories.php` (new)

Access gate: `require 'admin_only.php';` at the top — the exact same guard used by
`add_product.php` and `edit_product.php`. `admin_only.php` allows **admin and manager
roles only** (it redirects everyone else to `dashboard.php?denied=1`). This is what
"same as product management" actually resolves to in this codebase — product creation is
admin/manager, not a broad `can('products')` permission. Note this means inventory-clerk
(who manages stock, not the product catalog) cannot manage categories, which is correct.

**List view** — one table, ordered by `display_order`:

| Col | Content |
|-----|---------|
| Reorder | up/down arrow buttons (POST `action=reorder`, swaps `display_order` with neighbor) |
| Icon | Font Awesome preview of `icon` |
| Name | `name` |
| Slug | `slug` (muted, secondary text) |
| Products | count of `products` where `category_id = this.category_id` |
| Active | toggle (POST `action=toggle`, flips `is_active`) |
| Actions | Edit, Delete |

**Add** — a form (inline card at top of the list, or a small modal) with:
- Name (text, required). A live, read-only "Slug will be: `<derived>`" preview updates
  as the user types (client-side, mirrors the server derivation) so the permanent key is
  visible before submit.
- Icon (text input pre-filled `fa-tag`, with a short hint showing a few example names —
  e.g. `fa-mug-hot`, `fa-leaf`, `fa-blender` — and linking to the Font Awesome gallery;
  free text is acceptable — no icon picker widget in Phase 1)
- Active (checkbox, default checked)

On submit (`action=create`): derive slug from Name (per Identity model); reject empty
Name or duplicate slug (case-insensitive) with an error message; `display_order` =
`COALESCE(MAX(display_order), 0) + 1` (note: a bare `MAX(...)+1` yields NULL on an empty
table); `INSERT INTO categories (slug, name, icon, display_order, is_active) VALUES (...)`.

**Edit** (`action=update`) — same fields as Add (Name, Icon, Active). The slug is shown
read-only next to a short help note: "Slug is permanent — it links existing products and
cannot be changed." Updates `name`, `icon`, `is_active` for the given `category_id`.

**Delete** (`action=delete`) — first `SELECT COUNT(*) FROM products WHERE category_id = ?`.
If count > 0, refuse with "N product(s) use this category — reassign them (via each
product's Edit page) or delete them first." Otherwise `DELETE FROM categories WHERE
category_id = ?`. The Delete control is also visually disabled in the list when the
product count > 0, with a tooltip stating the blocking count, so the block is obvious
before the click.

**Inactive rows** are rendered visually muted (dimmed text + a subtle grayed row
background + an "Inactive" pill) in the list so it is obvious at a glance which categories
are hidden from the pickers.

All mutating actions are POST and CSRF-guarded using the same pattern as the recently
hardened `stands.php`: at the top of the page,
`if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));`
then, on any POST, `if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']
?? '')) { reject; }`. Each form embeds the token as a hidden `csrf_token` field. All
output escaped with `htmlspecialchars`. Styling reuses the existing admin design tokens (dark default +
`[data-theme="light"]` override) and the `.pg-*`/topbar/table conventions already used
by pages like `ingredients.php` so it visually matches.

### 2. `products.php` filter bar (small change)

Currently `products.php` builds its category filter chips from `array_unique` of the
distinct `products.category` strings (lines ~86-95), so a newly created but still-empty
category would not appear as a filter option. Change the filter source to read from the
`categories` table (`WHERE is_active = 1 ORDER BY display_order`). Each chip shows its
product count, e.g. `Smoothies (0)`, computed from a `category_id → COUNT(*)` map over
`products` — so an empty category is visibly empty rather than mysteriously yielding
nothing. Any product whose `category` slug is empty/unknown is bucketed under an
"Uncategorized" chip (defensive; see Data integrity below). Product cards keep their
existing `data-category` (the slug) so client-side filtering is unaffected.

## Data integrity (verified against live DB, 2026-07-07)

The `products`↔`categories` relationship is currently fully consistent, so this feature
starts from clean data:

- Distinct `products.category` strings `[Frappe, Hot, Iced, Juice, Milk Tea]` match
  `categories.slug` exactly.
- Products with `category_id` NULL: 0. Products with empty/NULL `category`: 0.
- Products whose slug has no matching `categories` row: 0.
- Products where `category_id`'s row slug disagrees with the `category` string
  (desync): 0.

The "Uncategorized" fallback in the filter is therefore a defensive path that no current
row exercises; it exists so the page stays correct if drift is ever introduced elsewhere.

## Data flow

```
manage_categories.php (CRUD) ──writes──▶ categories table
                                              │
                    reads (already wired) ────┼────▶ add_product.php   (dropdown)
                                              ├────▶ edit_product.php  (pills)
                                              ├────▶ menu.php          (nav + grouping)
                                              └────▶ products.php      (filter chips — new read)
```

No change to `products.category` / `products.category_id` semantics; no migration.
The `categories` table already exists with the needed columns.

## Error handling

- Empty Name on create/update → inline error, no write.
- Duplicate slug on create (case-insensitive) → inline error, no write.
- Delete of in-use category → refused with product count, no write.
- Reorder at list boundary (first item up / last item down) → no-op.
- Every action re-renders the list with a success/error flash consistent with the
  toast/flash pattern already used on the page's siblings.

## Testing (manual, browser)

1. As admin (or manager): open `manage_categories.php` from the "Manage Categories"
   button on `products.php`.
2. Add "Smoothies" → confirm it appears in `add_product.php` dropdown and `menu.php`
   category nav, and (empty) as a filter chip on `products.php`.
3. Reorder a category up/down → order reflected in add_product dropdown + menu nav.
4. Toggle a category inactive → disappears from add_product / menu / products filter;
   still visible (as inactive) in `manage_categories.php`.
5. Attempt to delete a category that has products → blocked with count message.
6. Delete the empty "Smoothies" → removed.
7. As cashier (`staff`) and as inventory-clerk: `manage_categories.php` redirects to
   `dashboard.php?denied=1`, and the "Manage Categories" button is not shown on
   `products.php`.
8. Verify dark + light theme render correctly.

## Entry point

A "Manage Categories" button/link on `products.php`, placed next to the existing "Add
Product" button in the topbar and gated by the same `$_can_manage_products`
(admin/manager) flag that already guards "Add Product" — so the entry point and the page
gate match exactly.
