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
- On **add**, the slug is derived from the entered Name (trimmed; collapse internal
  whitespace to single spaces; reject if the derived slug collides with an existing
  slug, case-insensitive).
- Because slug is immutable and delete is blocked while in use (below), `products.category`
  and `products.category_id` can never be orphaned or desynced by this feature.

## Scope

**In scope:** a new `manage_categories.php` admin page with full CRUD + reorder +
active-toggle over the `categories` table, plus a small change to `products.php` so its
filter bar reads categories from the table.

**Out of scope (later phases):** badge presets, size definitions, price-filter ranges,
per-category colors, bulk operations, slug renaming.

## Components

### 1. `manage_categories.php` (new)

Access gate: `if (!can('products')) { header("Location: dashboard.php?denied=1"); exit; }`
— same permission that guards product management (cashier `staff` cannot; inventory
clerk / admin / manager can).

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
- Name (text, required)
- Icon (text input pre-filled `fa-circle`, with a short hint linking to Font Awesome
  names; free text is acceptable — no icon picker widget in Phase 1)
- Active (checkbox, default checked)

On submit (`action=create`): derive slug from Name; reject empty Name or duplicate slug
(case-insensitive) with an error message; `display_order` = `MAX(display_order)+1`;
`INSERT INTO categories (slug, name, icon, display_order, is_active) VALUES (...)`.

**Edit** (`action=update`) — same fields as Add (Name, Icon, Active); slug shown
read-only. Updates `name`, `icon`, `is_active` for the given `category_id`.

**Delete** (`action=delete`) — first `SELECT COUNT(*) FROM products WHERE category_id = ?`.
If count > 0, refuse with "N product(s) use this category — move or delete them first."
Otherwise `DELETE FROM categories WHERE category_id = ?`. The Delete control is also
visually disabled in the list when the product count > 0, so the block is obvious before
the click.

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
`categories` table (`WHERE is_active = 1 ORDER BY display_order`), falling back to
"Uncategorized" for products whose `category` is empty. Product cards keep their existing
`data-category` (the slug) so client-side filtering is unaffected.

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

1. As inventory-clerk (has `products`): open `manage_categories.php` from the
   "Manage Categories" button on `products.php`.
2. Add "Smoothies" → confirm it appears in `add_product.php` dropdown and `menu.php`
   category nav, and (empty) as a filter chip on `products.php`.
3. Reorder a category up/down → order reflected in add_product dropdown + menu nav.
4. Toggle a category inactive → disappears from add_product / menu / products filter;
   still visible (as inactive) in `manage_categories.php`.
5. Attempt to delete a category that has products → blocked with count message.
6. Delete the empty "Smoothies" → removed.
7. As cashier (`staff`, no `products`): `manage_categories.php` redirects to dashboard.
8. Verify dark + light theme render correctly.

## Entry point

A "Manage Categories" button/link on `products.php` (near the category filter row),
visible to users with `can('products')`.
