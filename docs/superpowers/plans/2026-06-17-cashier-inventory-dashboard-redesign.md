# Cashier + Inventory Dashboard Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Cashier (`staff`) and Inventory (`inventory_clerk`) landing dashboard a calmer, more professional look — shrunk primary button, compact icon-left tiles — without changing the Barista view.

**Architecture:** Single file, `dashboard.php`, non-manager branch only. Add a `$_redesign` role flag and a `$G` class-prefix variable. Add new `.qx-*` CSS rules alongside the existing `.qa-*` rules (old rules untouched). The shared tile/group markup swaps its class prefix via `$G` (`qx` for redesign roles, `qa` otherwise), so the barista path keeps emitting `qa-*` classes and renders identically.

**Tech Stack:** PHP 8 + MySQLi, vanilla CSS (CSS custom-property design tokens already defined in the file), Font Awesome 6, Playwright MCP for visual verification.

## Global Constraints

- Touch **only** `dashboard.php` (non-manager `else` branch, ~lines 1456-1672, plus the `<style>` block).
- Do **not** edit any existing `.qa-*` CSS rule or the `.qa-hero-btn`/`heroGlow` rules — barista depends on them.
- No changes to PHP queries, `can()` permission gates, or which groups/tiles a role sees.
- Redesign roles: `staff`, `inventory_clerk`. All other non-manager roles (incl. `barista`) keep the current markup.
- Sizing target (user): "not too big, not too small" — hero `min-height:60px`, tiles `min-height:96px`.
- Design tokens to reuse: `--amber`, `--amber-light`, `--amber-dim`, `--amber-glow`, `--surface`, `--surface-2`, `--border`, `--border-hi`, `--text`, `--text-muted`, `--purple`, `--r`, `--ease`, `--spring`.

---

### Task 1: Add `.qx-*` CSS rules

**Files:**
- Modify: `dashboard.php` — inside the `<style>` block, immediately **after** the existing `.qa-hero-btn` / `@keyframes heroGlow` rules (after line ~851, before the `/* ── TOAST ── */` comment at ~853).

**Interfaces:**
- Consumes: existing CSS custom properties (design tokens) listed in Global Constraints.
- Produces: CSS classes `.qx-grid`, `.qx-group`, `.qx-group-label`, `.qx-tiles`, `.qx-tile`, `.qx-tile-badge`, `.qx-hero` — consumed by Task 2's markup.

- [ ] **Step 1: Insert the new CSS block**

Insert this block right after the `@keyframes heroGlow { … }` rule:

```css
/* ── REDESIGN: compact pro layout (cashier / inventory only) ── */
.qx-grid{display:flex;flex-direction:column;gap:22px;max-width:1180px;width:100%;margin:0 auto;}

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
.qx-hero:hover{transform:translateY(-1px);box-shadow:0 6px 20px var(--amber-glow);}
.qx-hero:active{transform:scale(.99);filter:brightness(1.05);}
.qx-hero i{font-size:17px;width:34px;height:34px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;border-radius:10px;background:rgba(0,0,0,.10);}

.qx-group{display:flex;flex-direction:column;gap:12px;}
.qx-group-label{
    font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
    color:var(--text-muted);display:flex;align-items:center;gap:7px;
    padding-bottom:8px;border-bottom:1px solid var(--border);
}
.qx-group-label i{color:var(--accent);font-size:13px;}

.qx-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;}
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
.qx-tile:hover i{transform:scale(1.06);}
.qx-tile span{font-size:15px;font-weight:600;}
.qx-tile-badge{
    position:absolute;top:12px;right:12px;
    background:var(--purple);color:#fff;
    font-size:11px;font-weight:700;
    min-width:22px;height:22px;padding:0 6px;border-radius:50px;
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 2px 8px rgba(0,0,0,.3);
}
```

- [ ] **Step 2: Add the responsive rules**

Inside the existing `@media(max-width:768px){ … }` block (~line 903-914), add these lines before its closing `}`:

```css
    .qx-tiles{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;}
    .qx-tile{min-height:84px;padding:14px 16px;gap:12px;}
    .qx-tile i{width:42px;height:42px;font-size:20px;}
    .qx-hero{font-size:15px;min-height:54px;padding:14px 20px;}
```

- [ ] **Step 3: Verify PHP still parses (no syntax break in the file)**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected in dashboard.php`

- [ ] **Step 4: Commit**

```bash
git add dashboard.php
git commit -m "style: add compact .qx-* dashboard classes for cashier/inventory"
```

---

### Task 2: Fork the non-manager markup by role

**Files:**
- Modify: `dashboard.php` — non-manager branch: the focus-card setup region (~line 1462) and the quick-access grid markup (~lines 1523-1670).

**Interfaces:**
- Consumes: `.qx-*` classes from Task 1; existing PHP vars `$_role`, `$unpaid_count`, `$paylater_count`, `$low_recipe_count`, `$_unread_ann`; existing `can()` gates.
- Produces: no new functions; emits `qx-*` classes for `staff`/`inventory_clerk`, `qa-*` for everyone else.

- [ ] **Step 1: Add the redesign flag + class prefix**

Find (near line 1462):

```php
    $_role  = $_SESSION['role'] ?? '';
    $_focus = null;
```

Replace with:

```php
    $_role  = $_SESSION['role'] ?? '';
    $_focus = null;

    // Compact redesign (cashier + inventory). Barista & others keep the legacy .qa-* layout.
    $_redesign = in_array($_role, ['staff', 'inventory_clerk'], true);
    $G = $_redesign ? 'qx' : 'qa';
```

- [ ] **Step 2: Swap the grid wrapper class**

Find (line ~1524):

```php
    <div class="qa-grid fu" style="animation-delay:.1s">
```

Replace with:

```php
    <div class="<?= $_redesign ? 'qx-grid' : 'qa-grid' ?> fu" style="animation-delay:.1s">
```

- [ ] **Step 3: Swap the hero button class**

Find (line ~1526):

```php
        <a href="menu.php" class="qa-hero-btn">
```

Replace with:

```php
        <a href="menu.php" class="<?= $_redesign ? 'qx-hero' : 'qa-hero-btn' ?>">
```

- [ ] **Step 4: Swap the group / tile class prefixes**

In the quick-access grid markup (lines ~1532-1669), replace each static prefixed class with the `$G` variable. There are these occurrences to change — replace the literal `qa-` prefix with `<?= $G ?>-` in every one:

- `class="qa-group"` → `class="<?= $G ?>-group"` (7 occurrences: Orders, Inventory, Procurement, Loyalty, Staff, Analytics, Account)
- `class="qa-group-label"` → `class="<?= $G ?>-group-label"` (7 occurrences)
- `class="qa-tiles"` → `class="<?= $G ?>-tiles"` (7 occurrences)
- `class="qa-tile"` → `class="<?= $G ?>-tile"` (every tile link)
- `class="qa-tile" style="position:relative"` (the Announcements tile, ~line 1635) → `class="<?= $G ?>-tile" style="position:relative"`
- `class="qa-tile-badge"` → `class="<?= $G ?>-tile-badge"` (find_order and recipes badges, ~lines 1545,1547,1576)

Leave all `href`, `<i>` icon classes (`fa-*`), `can()` conditions, badge `style=` attributes, and the inline-styled focus card (lines 1506-1521) **unchanged**.

- [ ] **Step 5: Verify PHP still parses**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected in dashboard.php`

- [ ] **Step 6: Verify no stray static `qa-` class remains in the forked block**

Run: `grep -nE 'class="qa-(grid|hero-btn|group|group-label|tiles|tile|tile-badge)"' dashboard.php`
Expected: matches only in the **manager** branch (lines < 1456) if any; **zero** matches between lines 1523 and 1670. (The forked block must use `<?= $G ?>-` / `$_redesign ?` now.)

- [ ] **Step 7: Commit**

```bash
git add dashboard.php
git commit -m "feat: fork cashier+inventory dashboard to compact layout, barista unchanged"
```

---

### Task 3: Visual verification across all three roles

**Files:** none (verification only — fix-and-loop back to Task 1/2 if a defect is found).

**Interfaces:**
- Consumes: running XAMPP Apache + MySQL at the project URL; test accounts from auto-memory `test-accounts.md` (cashier / barista / inventory).

- [ ] **Step 1: Confirm the app is reachable**

Run: `curl -s -o /dev/null -w "%{http_code}" http://localhost/Cafe/dashboard.php`
Expected: `302` (redirect to login when unauthenticated) or `200`. If connection refused, start XAMPP Apache + MySQL first.

- [ ] **Step 2: Log in as cashier and screenshot**

Using Playwright MCP: navigate to the login page, sign in with the cashier test account, navigate to `dashboard.php`, take a full-page screenshot.
Expected: shrunk solid amber "Take New Order" button (no pulsing glow, no shimmer sweep), compact tiles with the icon to the **left** of the label (~96px tall), focus card intact at top.

- [ ] **Step 3: Log in as inventory and screenshot**

Sign in with the inventory test account, open `dashboard.php`, screenshot.
Expected: compact icon-left tiles for the Inventory / Procurement / Account groups; inventory focus card ("items low on stock") at top; no oversized hero (inventory lacks `find_orders`, so no hero button — verify the layout still reads cleanly without it).

- [ ] **Step 4: Log in as barista and confirm UNCHANGED**

Sign in with the barista test account, open `dashboard.php`, screenshot.
Expected: the **legacy** look — big centered 158px tiles and (if present) the original glowing `qa-hero-btn`. Must match the pre-change appearance. Spot-check page source contains `qx-` is **absent** and `qa-tile`/`qa-group` present for barista.

- [ ] **Step 5: Check narrow viewport (cashier)**

Resize the Playwright viewport to 390px wide on the cashier dashboard; screenshot.
Expected: tiles reflow to the smaller grid, hero shrinks, no horizontal overflow.

- [ ] **Step 6: If all four checks pass, done.** If any defect, return to Task 1 (CSS) or Task 2 (markup), fix, re-commit, and re-run this task.

---

## Notes for the implementer

- This is a presentational change with no unit-testable logic; verification is visual (Task 3) rather than assertion-based. That is intentional and correct for this work.
- The `$G` prefix trick keeps the change DRY: one markup block serves both layouts. Do not duplicate the group markup.
- If `grep` in Task 2 Step 6 finds a leftover static `qa-` class inside the forked block, that tile/group will silently render with the old (wrong) style for cashier/inventory — fix before committing.
