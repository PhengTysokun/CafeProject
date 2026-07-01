# Cashier Dashboard Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Polish the cashier-focus (`_redesign`) landing view in `dashboard.php` — add a real-data "Shift Snapshot" strip to fill the empty space below the quick-access grid, and give the focus banner / CTA / tiles / group labels more visual depth and hierarchy.

**Architecture:** Single file, `dashboard.php`. No new DB queries — `$sales`, `$sales_trend`, `$trend_class`, `$trend_icon`, `$total_orders`, `$items_sold` are already computed near the top of the file (lines 35-112) for the manager KPI row. Add a new `.qx-snapshot` CSS block + markup block that reuses those variables and the existing `.kpi-pill` up/down/flat classes. Then tighten/deepen the existing `.qx-hero`, `.qx-tile`, `.qx-group-label` CSS and the inline-styled focus-banner markup.

**Tech Stack:** PHP 8 + MySQLi, vanilla CSS (custom-property design tokens already defined in the file), Font Awesome 6. No test framework in this repo for frontend code — verification is `php -l` (syntax) + manual browser check (dark/light theme, desktop/mobile) per project convention (see `docs/superpowers/plans/2026-06-17-cashier-inventory-dashboard-redesign.md`).

## Global Constraints

- Touch **only** `dashboard.php`.
- Scope is the `_redesign` (cashier/inventory) landing view only — do not touch `.qa-*` (legacy/barista) rules or markup, and do not touch the manager (`$_is_mgr`) branch or its `.kpi-*` block (read-only reference for tokens/patterns).
- No new DB queries. Reuse `$sales`, `$sales_trend`, `$trend_class`, `$trend_icon`, `$total_orders`, `$items_sold` — all already populated by the top-of-file query block (lines 35-112).
- Snapshot strip renders only when `$_redesign` is true **and** `$_focus === $_focus_cashier` (i.e., the cashier/order-taking focus card is showing) — inventory_clerk's focus view (`$_focus_inventory`) does not get the strip.
- Design tokens to reuse: `--amber`, `--amber-dim`, `--emerald`, `--emerald-dim`, `--blue`, `--blue-dim`, `--red`, `--red-dim`, `--surface`, `--surface-2`, `--border`, `--border-hi`, `--text`, `--text-muted`, `--r`, `--r-sm`, `--ease`, `--spring`, `--glass`.
- Reuse existing `.kpi-pill` / `.kpi-pill.up` / `.kpi-pill.down` classes for the sales-trend badge instead of inventing new trend-color CSS.

---

### Task 1: Shift Snapshot strip — CSS

**Files:**
- Modify: `dashboard.php` — `<style>` block, immediately after the `.qx-tile-badge{...}` rule (ends at line 916), before the `/* ── TOAST ── */` comment (line 918).
- Modify: `dashboard.php` — inside the existing `@media(max-width:768px){...}` block, in the `qx-*` rules group (lines 979-982).

**Interfaces:**
- Consumes: existing tokens listed in Global Constraints; existing `.kpi-pill`/`.kpi-pill.up`/`.kpi-pill.down`/`.kpi-pill.flat` classes (defined at lines 547-554, untouched).
- Produces: CSS classes `.qx-snapshot`, `.qx-snap-card`, `.qx-snap-icon`, `.qx-snap-value`, `.qx-snap-label` — consumed by Task 2's markup.

- [ ] **Step 1: Insert the snapshot CSS block**

Find (end of the existing tile-badge rule, line 909-916):

```css
.qx-tile-badge{
    position:absolute;top:12px;right:12px;
    background:var(--purple);color:#fff;
    font-size:11px;font-weight:700;
    min-width:22px;height:22px;padding:0 6px;border-radius:50px;
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 2px 8px rgba(0,0,0,.3);
}
```

Insert immediately after it (before the blank line + `/* ── TOAST ── */` comment):

```css

.qx-snapshot{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
.qx-snap-card{
    display:flex;align-items:center;gap:12px;
    padding:14px 16px;
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r);
    box-shadow:0 1px 2px rgba(0,0,0,.18);
}
.qx-snap-icon{
    width:38px;height:38px;flex:0 0 auto;border-radius:11px;
    display:flex;align-items:center;justify-content:center;
    font-size:16px;color:var(--sc,var(--amber));
    background:var(--sg,var(--amber-dim));
}
.qx-snap-card.c-amber   { --sc:var(--amber);   --sg:var(--amber-dim);   }
.qx-snap-card.c-emerald { --sc:var(--emerald); --sg:var(--emerald-dim); }
.qx-snap-card.c-blue    { --sc:var(--blue);    --sg:var(--blue-dim);    }
.qx-snap-body{flex:1 1 auto;min-width:0;}
.qx-snap-value{font-size:19px;font-weight:700;color:var(--text);line-height:1.15;font-variant-numeric:tabular-nums;}
.qx-snap-label{font-size:11.5px;color:var(--text-muted);margin-top:1px;}
```

- [ ] **Step 2: Add the mobile rule**

Find (inside `@media(max-width:768px){ … }`, lines 979-982):

```css
    .qx-tiles{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;}
    .qx-tile{min-height:84px;padding:14px 16px;gap:12px;}
    .qx-tile i{width:42px;height:42px;font-size:20px;}
    .qx-hero{font-size:15px;min-height:54px;padding:14px 20px;}
```

Replace with:

```css
    .qx-tiles{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;}
    .qx-tile{min-height:84px;padding:14px 16px;gap:12px;}
    .qx-tile i{width:42px;height:42px;font-size:20px;}
    .qx-hero{font-size:15px;min-height:54px;padding:14px 20px;}
    .qx-snapshot{grid-template-columns:1fr;gap:10px;}
```

- [ ] **Step 3: Verify PHP still parses**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected in dashboard.php`

- [ ] **Step 4: Commit**

```bash
git add dashboard.php
git commit -m "style: add .qx-snapshot CSS for cashier dashboard"
```

---

### Task 2: Shift Snapshot strip — markup

**Files:**
- Modify: `dashboard.php` — non-manager branch, right after the focus-banner `<?php endif; ?>` (line 1601), before the `<!-- QUICK ACCESS GRID -->` comment (line 1603).

**Interfaces:**
- Consumes: `.qx-snapshot`/`.qx-snap-*` classes from Task 1; existing PHP vars `$_redesign`, `$_focus`, `$_focus_cashier`, `$sales`, `$sales_trend`, `$trend_class`, `$trend_icon`, `$total_orders`, `$completed_count`, `$items_sold`.
- Produces: no new PHP vars.

- [ ] **Step 1: Insert the snapshot markup**

Find:

```php
    <?php endif; ?>

    <!-- QUICK ACCESS GRID -->
```

Replace with:

```php
    <?php endif; ?>

    <?php if ($_redesign && $_focus === $_focus_cashier): ?>
    <div class="qx-snapshot fu" style="animation-delay:.08s">
        <div class="qx-snap-card c-amber">
            <div class="qx-snap-icon"><i class="fa-solid fa-dollar-sign"></i></div>
            <div class="qx-snap-body">
                <div class="qx-snap-value">$<?= number_format($sales, 2) ?></div>
                <div class="qx-snap-label">
                    Today's Sales
                    <?php if ($sales_trend != 0): ?>
                    · <span class="kpi-pill <?= $trend_class ?>" style="margin-left:2px;"><i class="fa-solid <?= $trend_icon ?>"></i> <?= abs($sales_trend) ?>%</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="qx-snap-card c-emerald">
            <div class="qx-snap-icon"><i class="fa-solid fa-receipt"></i></div>
            <div class="qx-snap-body">
                <div class="qx-snap-value"><?= (int)$total_orders ?></div>
                <div class="qx-snap-label">Orders Today · <?= (int)$completed_count ?> completed</div>
            </div>
        </div>
        <div class="qx-snap-card c-blue">
            <div class="qx-snap-icon"><i class="fa-solid fa-mug-hot"></i></div>
            <div class="qx-snap-body">
                <div class="qx-snap-value"><?= (int)$items_sold ?></div>
                <div class="qx-snap-label">Items Sold</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- QUICK ACCESS GRID -->
```

- [ ] **Step 2: Verify PHP still parses**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected in dashboard.php`

- [ ] **Step 3: Manual browser check**

Log in as a `staff` (cashier) test account, load `dashboard.php`. Expected: snapshot strip of 3 cards appears between the pending-orders focus banner and the quick-access grid, showing today's sales (with trend badge if `$sales_trend != 0`), orders today, items sold. Log in as `inventory_clerk` — expected: strip does NOT appear (focus is `$_focus_inventory`, not `$_focus_cashier`).

- [ ] **Step 4: Commit**

```bash
git add dashboard.php
git commit -m "feat: add Shift Snapshot strip to cashier dashboard"
```

---

### Task 3: Visual depth & hierarchy pass

**Files:**
- Modify: `dashboard.php` — focus-banner inline styles (lines 1587-1600), `.qx-hero` CSS (lines 863-877), `.qx-tile` CSS (lines 888-916), `.qx-group-label` CSS (lines 880-885).

**Interfaces:**
- Consumes: existing tokens; no new classes.
- Produces: no new interfaces — pure visual refinement of existing elements.

- [ ] **Step 1: Strengthen the focus banner**

Find (focus-banner icon + count block, inside the `<?php if ($_focus): ?>` link):

```php
        <div style="flex:0 0 auto;width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:25px;color:<?= $_focus['color'] ?>;background:<?= $_focus['color'] ?>22;">
            <i class="fa-solid <?= $_focus['icon'] ?>"></i>
        </div>
        <div style="flex:1 1 auto;min-width:0;">
            <div style="font-size:26px;font-weight:700;color:var(--text);font-variant-numeric:tabular-nums;line-height:1.1;">
```

Replace with:

```php
        <div style="flex:0 0 auto;width:64px;height:64px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:28px;color:<?= $_focus['color'] ?>;background:<?= $_focus['color'] ?>2e;box-shadow:0 0 0 1px <?= $_focus['color'] ?>33 inset;">
            <i class="fa-solid <?= $_focus['icon'] ?>"></i>
        </div>
        <div style="flex:1 1 auto;min-width:0;">
            <div style="font-size:29px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;line-height:1.1;">
```

- [ ] **Step 2: Deepen the Take New Order CTA**

Find:

```css
.qx-hero{
    display:flex;align-items:center;justify-content:center;gap:14px;
    width:100%;min-height:60px;padding:16px 26px;
    background:linear-gradient(135deg,var(--amber-light) 0%,var(--amber) 100%);
    color:#000;text-decoration:none;
    border:1px solid rgba(255,255,255,.18);
    border-radius:var(--r);
    font-size:16px;font-weight:700;letter-spacing:.01em;
    box-shadow:0 2px 12px rgba(209,144,75,.18);
    transition:transform .15s var(--ease),box-shadow .2s var(--ease),filter .15s var(--ease);
    -webkit-tap-highlight-color:transparent;
}
```

Replace with:

```css
.qx-hero{
    display:flex;align-items:center;justify-content:center;gap:14px;
    width:100%;min-height:60px;padding:16px 26px;
    background:linear-gradient(135deg,var(--amber-light) 0%,var(--amber) 100%);
    color:#000;text-decoration:none;
    border:1px solid rgba(255,255,255,.22);
    border-radius:var(--r);
    font-size:16px;font-weight:700;letter-spacing:.01em;
    box-shadow:0 4px 18px rgba(209,144,75,.32);
    transition:transform .15s var(--ease),box-shadow .2s var(--ease),filter .15s var(--ease);
    -webkit-tap-highlight-color:transparent;
}
```

- [ ] **Step 3: Add resting depth + gradient icon badge to tiles**

Find:

```css
.qx-tile{
    position:relative;
    display:flex;align-items:center;gap:14px;
    padding:18px 20px;min-height:96px;
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r);color:var(--text);text-decoration:none;
    box-shadow:0 1px 2px rgba(0,0,0,.18);
    transition:background .2s var(--ease),border-color .2s var(--ease),transform .12s var(--ease),box-shadow .2s var(--ease);
    -webkit-tap-highlight-color:transparent;
}
.qx-tile:hover{background:var(--surface-2);border-color:var(--border-hi);transform:translateY(-2px);box-shadow:0 8px 22px rgba(0,0,0,.28);}
.qx-tile:active{transform:scale(.98);filter:brightness(1.06);}
.qx-tile i{
    font-size:22px;color:var(--accent);
    width:48px;height:48px;flex:0 0 auto;
    display:flex;align-items:center;justify-content:center;
    border-radius:13px;background:var(--amber-dim);
    transition:transform .2s var(--spring);
}
```

Replace with:

```css
.qx-tile{
    position:relative;
    display:flex;align-items:center;gap:14px;
    padding:18px 20px;min-height:96px;
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r);color:var(--text);text-decoration:none;
    box-shadow:0 2px 8px rgba(0,0,0,.22);
    transition:background .2s var(--ease),border-color .2s var(--ease),transform .12s var(--ease),box-shadow .2s var(--ease);
    -webkit-tap-highlight-color:transparent;
}
.qx-tile:hover{background:var(--surface-2);border-color:var(--border-hi);transform:translateY(-2px);box-shadow:0 10px 26px rgba(0,0,0,.3);}
.qx-tile:active{transform:scale(.98);filter:brightness(1.06);}
.qx-tile i{
    font-size:22px;color:var(--accent);
    width:48px;height:48px;flex:0 0 auto;
    display:flex;align-items:center;justify-content:center;
    border-radius:13px;background:linear-gradient(135deg,var(--amber-dim) 0%,rgba(209,144,75,.28) 100%);
    transition:transform .2s var(--spring);
}
```

- [ ] **Step 4: Strengthen group labels**

Find:

```css
.qx-group-label{
    font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
    color:var(--text-muted);display:flex;align-items:center;gap:7px;
    padding-bottom:8px;border-bottom:1px solid var(--border);
}
```

Replace with:

```css
.qx-group-label{
    font-size:12px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;
    color:var(--text-muted);display:flex;align-items:center;gap:7px;
    padding-bottom:8px;border-bottom:1px solid var(--border);
}
```

- [ ] **Step 5: Verify PHP still parses**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected in dashboard.php`

- [ ] **Step 6: Manual browser check**

Log in as `staff`. Expected: focus banner icon/count noticeably bigger and bolder than before; "Take New Order" button has a slightly heavier shadow; quick-access tiles show a resting shadow even without hovering, with a subtle gradient on the icon badge; group labels (`ORDERS`, `LOYALTY`) read slightly larger/wider-spaced. Confirm both dark theme (default) and light theme (`[data-theme="light"]`, toggled via the existing theme switcher) still look correct — no low-contrast icon/text combos.

- [ ] **Step 7: Commit**

```bash
git add dashboard.php
git commit -m "style: deepen visual hierarchy on cashier dashboard focus/CTA/tiles"
```

---

### Task 4: Spacing tightening

**Files:**
- Modify: `dashboard.php` — `.qx-grid` CSS (line 861).

**Interfaces:**
- Consumes: none new.
- Produces: none new.

- [ ] **Step 1: Tighten the grid gap**

Find:

```css
.qx-grid{display:flex;flex-direction:column;gap:22px;max-width:1180px;width:100%;margin:0 auto;}
```

Replace with:

```css
.qx-grid{display:flex;flex-direction:column;gap:16px;max-width:1180px;width:100%;margin:0 auto;}
```

- [ ] **Step 2: Verify PHP still parses**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected in dashboard.php`

- [ ] **Step 3: Full manual pass**

Log in as `staff`, view `dashboard.php` at desktop width and at a mobile viewport (<768px). Expected: focus banner → snapshot strip → quick-access grid read as one cohesive block with no large dead space below; sections still have breathing room, just not excess. Repeat for `inventory_clerk` (no snapshot strip, but should still look intentional — snapshot's absence just means the grid gap change applies) and for a `barista`-role login (legacy `.qa-*` layout — confirm it is completely unaffected, since only `.qx-*` rules changed).

- [ ] **Step 4: Commit**

```bash
git add dashboard.php
git commit -m "style: tighten cashier dashboard grid spacing"
```
