# Barista Station Redesign — Design Spec

**Date:** 2026-07-12
**Branch:** feat/product-addons (local)
**File touched:** `view_order.php` (barista render path only) + `config.php` (one Setting) + `settings.php` (expose Setting)

## Goal

Replace the barista-role Orders screen with a denser, station-style layout (left sidebar + at-a-glance stats + redesigned cards) inspired by a reference mockup, so a barista can scan the queue and prioritize overdue drinks fast. Cashier and manager keep the current Orders view unchanged.

## Motivation

Current barista view (`view_order.php`, `role === 'barista'`): centered/floating cards waste horizontal space, drink name is buried in a thin strip, elapsed time renders as raw minutes ("520m"), there is no queue-level prioritization. The redesign fixes hierarchy, density, prioritization, and the timer format.

## Scope

- **In scope:** the barista-only render branch of `view_order.php`; a configurable overdue threshold; the derived stats; a category/temp badge.
- **Out of scope:** cashier & manager Orders views (untouched), all payment / cancel / refund / remake flows, the orders-fetch JSON endpoint contract, the socket live-update mechanism. These are REUSED, not modified.
- **Explicitly rejected:** Revenue on the barista screen (manager-only data; violates our role model — barista never sees money, matching `shift_report` which shows baristas no drawer).

## Architecture

`view_order.php` already branches on role. Today the barista sees the same shell as everyone with only the "Preparing" tab. The redesign adds a **barista-only layout branch**: when `role === 'barista'`, render the new sidebar + stat strip + card grid instead of the shared centered shell. All other roles render the existing markup verbatim.

Data plumbing is unchanged: the same AJAX/orders-fetch and socket update that populate cards today feed the new cards. The new work is: (a) markup/CSS for the barista shell + cards, (b) a `products.category` value surfaced per item for the type badge, (c) server-side computation of the 4 stats, (d) an overdue threshold Setting.

**Code isolation:** prefer keeping the new barista markup out of the cashier/manager code path — either a dedicated partial (`include` gated on role) or a clearly-fenced `if ($role === 'barista')` block. The plan decides which: if order cards are built in JS (likely, given the current client-side chip rendering), a PHP partial only covers the static shell (sidebar/header) and the cards need a JS barista-variant renderer. Do NOT introduce a `views/` partial convention if it fights the existing single-file structure — isolation via a fenced block is acceptable.

**Category join is barista-gated:** add `p.category` (and the `LEFT JOIN products p`) to the orders fetch ONLY on the barista path, so the cashier/manager payload stays lean and their endpoint is untouched. If the fetch is a shared endpoint keyed by role, branch the SELECT there; if barista uses its own fetch, add it only there.

### Units

1. **Barista shell (sidebar + header)** — pure markup/CSS, role-gated. Brand, user+role pill, clock-in status, "Today" stat block, nav links. Depends on: existing `$_is_clocked_in`, `$_clock_since`, role metadata, `can('my_profile')`.
2. **Stat computation** — server-side (PHP) counts for In Queue / Overdue / Done Today / Avg Wait, computed on page load and refreshed by the existing live-update tick. Depends on: `orders` (status, order_date, started_at, completed_at, business_date), `OVERDUE_MINUTES`.
3. **Card renderer (barista variant)** — drink name hero, category badge, chips (size/sweetness/ice/milk/add-ons), order#, customer, readable elapsed time, taken-by, overdue/warn state, Call + Complete. Consumes the same order/item data the current cards use, plus `products.category`.
4. **Overdue threshold** — `OVERDUE_MINUTES` Setting (default 10), read in `config.php` like `STAND_COUNT`, editable in `settings.php`.

## Detailed behavior

### Stats (sidebar "Today")
All four, no money:
- **In Queue** — count of Preparing orders (today's business date).
- **Overdue** — count of Preparing orders whose age (now − order start) exceeds `OVERDUE_MINUTES`. Renders red when > 0, muted when 0.
- **Done Today** — count of Completed orders for today's business date.
- **Avg Wait** — average of `(completed_at − COALESCE(started_at, order_date))` over today's Completed orders, shown in minutes (e.g. "4.8m"). If no completed orders yet, show "—". This measures **barista queue/prep time** (time from when the order entered the barista queue to completion), NOT total customer fulfillment time — the metric a barista can actually influence. It intentionally uses the SAME age basis as Overdue so the two numbers never tell different stories.

**"Age" basis (shared by Overdue + Avg Wait):** `COALESCE(started_at, order_date)` — use `started_at` when present, else fall back to `order_date` defensively.

### Card
- **Order #** (daily_order_no) + status/overdue badge top row.
- **Drink name** large as the primary line. Multi-item orders list each item.
- **Category badge** — `products.category` value as-is (e.g. "Iced", "Frappe", "Hot", "Milk Tea", "Juice"). No mapping table, no new field; whatever the product's category is. Hidden if category empty.
- **Chips** per item: Size (size_label), Sweetness, Ice, Milk, Add-ons (from addons_snapshot) — same data the current UI already renders. Chip container is `flex-wrap`. To keep card height uniform across orders, cap visible chips at ~4–5; any beyond that collapse into a muted `+N more` chip (a drink can carry 8+ add-ons). No hard truncation of data — just the visible count.
- **Customer** (customer_name or "Guest"), **elapsed time** in readable units: `<60m → "Xm"`, `<24h → "Xh"` or "Xh Ym", else "Xd". Fixes the "520m" bug.
- **Taken by** (prepared_by / employee_name) as today.
- **Overdue state:** age > `OVERDUE_MINUTES` → subtle **solid left accent bar** (e.g. `border-left:4px solid` red) + an "Overdue" badge. Age > ~70% of threshold and not yet overdue → amber "warn". Keep it restrained: no red card fill, no heavy glow, no pulsing animation (the existing `.age-alert` pulse is too loud for the denser layout). Reuse the age-badge colors but not its animation.
- **Actions:** Call · Complete (existing handlers, unchanged).

### Overdue threshold
- New Setting key `overdue_minutes`, surfaced as `OVERDUE_MINUTES` constant in `config.php` (`max(1, min(120, (int)(... ?? 10)))`, default 10) — same guard pattern as `STAND_COUNT`.
- Editable in `settings.php` alongside other numeric settings.
- Used by both the per-card state and the Overdue stat so they never disagree.

## Data model

No schema changes. Fields already present:
- `orders`: status, order_date, started_at, completed_at, business_date, daily_order_no, customer_name, prepared_by, employee_name.
- `order_items`: product_name, quantity, sweetness, ice, milk, size_label, addons_snapshot.
- `products`: category (join by product_id for the type badge).
- New Setting row only (`settings` table): `overdue_minutes`.

## Testing / verification

No unit-test framework. Verify via:
- `php -l` on changed files.
- Browser (Playwright) as barista **darasokun** (`@Darasokun2026`): sidebar + 4 stats render; cards show drink-name hero + category badge + chips; elapsed time reads "Xh" not "520m"; an order past threshold shows red Overdue border and increments the Overdue stat; Call + Complete still work; live socket update still refreshes.
- Confirm **cashier** (Sok_Dara) and **manager** Orders views are visually unchanged (regression check on the shared file).
- DB check: Avg Wait / Done Today counts match a hand query for today's business date.

## Risks

- Shared 2808-line file — the barista branch must not alter the cashier/manager markup or the shared JS card-render contract. Mitigation: gate all new markup behind `role === 'barista'`; if card HTML is JS-built, add a barista-variant render without changing the existing one.
- Category badge depends on a `products` join in the fetch payload. Add it **barista-path only** (see Architecture) so the cashier/manager endpoint payload is unchanged. Additive + role-gated = safe.

## Open questions

None — scope, stats (all 4), overdue-as-Setting, and no-revenue all confirmed with the user during brainstorming.
