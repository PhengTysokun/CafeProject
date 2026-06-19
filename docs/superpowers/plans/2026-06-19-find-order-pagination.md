# Find Orders Pagination Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Paginate `find_order.php` (10 orders/page, AJAX page switching), scope to today + old unpaid pay-later, and convert the 5s auto-refresh to a silent current-page reload.

**Architecture:** Server-side `LIMIT/OFFSET` paginates a single shared query; one extracted card partial (`_order_card.php`) renders cards for both the initial page and a new `action=list` JSON endpoint; client JS fetches pages, swaps `#orderList`, rebuilds a windowed pagination bar, and rebinds card handlers.

**Tech Stack:** PHP 8.2 (procedural mysqli), vanilla JS (`fetch`), XAMPP/MySQL `db_coffee`. No automated test harness exists — every task ends with concrete **manual** browser/DB/curl verification.

## Global Constraints

- DB: MySQL `db_coffee`; CLI for checks: `/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "..."`.
- Page size is exactly **10** (`$perPage = 10`).
- Date clause applied identically to the list query, the count query, and the poll query: `AND ( business_date = CURDATE() OR payment_method = 'paylater' )`.
- `orders.business_date` is `DATE NOT NULL` (already exists).
- Match existing code style: procedural mysqli, `real_escape_string` for search (no new param-binding refactor).
- Cashier role is forced to `tab=paylater` (existing logic at `find_order.php:52-55`) — do not change.
- Settled pay-later becomes `status='Paid'` (already shipped) — the date clause relies on this to drop old paid orders.
- App is served at `http://localhost/Cafe/`. Cashier test login: `Sok_Dara` / `@Sokdara5678`.

---

### Task 1: Extract card markup into `_order_card.php`

Pure refactor — no behavior change. Establishes the single card-render source both later paths reuse.

**Files:**
- Create: `_order_card.php`
- Modify: `find_order.php:540-676` (the `foreach ($orders as $order)` body)

**Interfaces:**
- Produces: `_order_card.php` — a partial that, given a single `$order` associative row (keys: `order_id, daily_order_no, customer_name, total, status, payment_method, order_date, is_open, token_number, table_number`) in scope, echoes one `.order-card` block. Computes its own locals (`$isPaidOpen, $isPayLater, $canAdd, $cardClass, $statusClass, $timeAgo, $isOverdue, $isPL`).

- [ ] **Step 1: Create `_order_card.php`**

Cut the entire current loop body — the PHP prep block (`find_order.php:540` after `foreach (...):` through line 561) plus the markup (lines 562-675, the full `<div class="order-card ...> ... </div>`). Paste into `_order_card.php` wrapped as:

```php
<?php
/**
 * Renders one order card. Expects $order (associative row from the orders query) in scope.
 * Included by find_order.php (initial render) and the action=list AJAX endpoint.
 */
$isPaidOpen = ($order['status'] === 'Paid' && $order['is_open'] == 1);
$isPayLater = ($order['payment_method'] === 'paylater');
$canAdd     = ($order['is_open'] == 1 && (in_array($order['status'], ['Preparing', 'Paid']) || ($isPayLater && $order['status'] === 'Completed')));
$cardClass  = $isPaidOpen ? 'is-paid-open' : ($canAdd ? 'can-add' : '');
$statusClass = strtolower($order['status']);
$tz   = new DateTimeZone('Asia/Phnom_Penh');
$now  = new DateTime('now', $tz);
$then = new DateTime($order['order_date'], $tz);
$diff = $now->getTimestamp() - $then->getTimestamp();
if ($diff < 0) {
    $absDiff = abs($diff);
    if ($absDiff < 3600)       $timeAgo = 'in ' . floor($absDiff/60) . 'm';
    elseif ($absDiff < 86400)  $timeAgo = 'in ' . floor($absDiff/3600) . 'h';
    else                       $timeAgo = 'in ' . floor($absDiff/86400) . 'd';
} elseif ($diff < 60)          $timeAgo = $diff . 's ago';
elseif ($diff < 3600)          $timeAgo = floor($diff/60) . 'm ago';
elseif ($diff < 86400)         $timeAgo = floor($diff/3600) . 'h ' . floor(($diff%3600)/60) . 'm ago';
else                           $timeAgo = floor($diff/86400) . 'd ago';
$isOverdue = ($order['payment_method'] === 'paylater' && $diff > 1800);
?>
<div class="order-card <?= $cardClass ?> <?= $isOverdue ? 'overdue' : '' ?>"
     data-name="<?= strtolower(htmlspecialchars($order['customer_name'])) ?>"
     data-token="<?= $order['token_number'] ?>"
     data-amount="<?= $order['total'] ?>"
     data-order="<?= $order['daily_order_no'] ?>">
    <!-- ...PASTE the rest of the existing card markup verbatim through the card's closing </div>... -->
</div>
```

Preserve the markup byte-for-byte (including the `$isPL`, `$canAdd`, `$isOverdue` usages and the `<?php if (!$is_cashier): ?>` block — `$is_cashier` remains a page-level global and is still in scope when included).

- [ ] **Step 2: Replace the loop body in `find_order.php`**

The block at `find_order.php:539-677` becomes:

```php
    <!-- Order Cards -->
    <div id="orderList">
    <?php foreach ($orders as $order) include '_order_card.php'; ?>
    </div>
```

(Leave the surrounding `<?php if (count($orders) > 0): ?>` / results-header / `#noFilterResults` / `<?php else: ?>` empty-state structure intact.)

- [ ] **Step 3: Verify render is unchanged**

Log in as cashier, open `http://localhost/Cafe/find_order.php`. Expected: identical 3 pay-later cards (#9, #3, #23), same buttons, same "Unpaid for…" badges. No PHP warnings.

Check error log is clean:
```bash
tail -5 /c/xampp/php/logs/php_error_log 2>/dev/null
```
Expected: no new `_order_card.php` notices/warnings.

- [ ] **Step 4: Commit**

```bash
git add _order_card.php find_order.php
git commit -m "refactor: extract order card markup into _order_card.php partial"
```

---

### Task 2: Add date scoping to all three queries

**Files:**
- Modify: `find_order.php` — main list query (~`58-90`), tab-count query (~`98-107`), poll query (~`25-39`)

**Interfaces:**
- Consumes: `_order_card.php` (Task 1) — unchanged.
- Produces: all order selection now scoped by `AND ( business_date = CURDATE() OR payment_method = 'paylater' )`.

- [ ] **Step 1: Add date clause to the main list query**

In `find_order.php`, the main `$sql` (begins ~line 58) ends its WHERE group with `)` before the search/tab appends. Immediately after that closing `)` of the status group (line ~66), append the date clause so it reads:

```php
$sql = "
SELECT order_id, daily_order_no, customer_name, total, status, payment_method, order_date, is_open, token_number, table_number
FROM orders
WHERE (
    (status NOT IN ('Completed','Cancelled','Refunded') AND status != 'Paid')
    OR (status = 'Paid' AND is_open = 1)
    OR (payment_method = 'paylater' AND status = 'Completed')
)
AND ( business_date = CURDATE() OR payment_method = 'paylater' )
";
```

- [ ] **Step 2: Add date clause to the tab-count query**

The `$count_sql` (begins ~line 98) has the same status group. Append the identical clause after its closing `)`:

```php
$count_sql = "
SELECT status, is_open, payment_method, COUNT(*) as cnt
FROM orders
WHERE (
    (status NOT IN ('Completed','Cancelled','Refunded') AND status != 'Paid')
    OR (status = 'Paid' AND is_open = 1)
    OR (payment_method = 'paylater' AND status = 'Completed')
)
AND ( business_date = CURDATE() OR payment_method = 'paylater' )
GROUP BY status, is_open, payment_method
";
```

- [ ] **Step 3: Add date clause to the poll query**

The `$poll_sql` (begins ~line 25) builds its status group then appends tab filters. After the status group's closing `)` (line ~29, before the `if ($poll_tab...)` appends), add the clause:

```php
$poll_sql = "SELECT order_id FROM orders WHERE (
    (status NOT IN ('Completed','Cancelled','Refunded') AND status != 'Paid')
    OR (status = 'Paid' AND is_open = 1)
    OR (payment_method = 'paylater' AND status = 'Completed')
)
AND ( business_date = CURDATE() OR payment_method = 'paylater' )";
```

- [ ] **Step 4: Verify scoping with a temporary probe order**

Seed one non-pay-later "Preparing" order dated yesterday and confirm it is hidden, while old unpaid pay-later stays:

```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "INSERT INTO orders (daily_order_no,customer_name,total,status,payment_method,order_date,business_date,is_open,token_number) VALUES (9990,'ZZ Probe',1.00,'Preparing','cash',CONCAT(CURDATE()-INTERVAL 1 DAY,' 10:00:00'),CURDATE()-INTERVAL 1 DAY,1,0);"
```

Reload `find_order.php` (as admin so all tabs are visible). Expected: probe #9990 does **not** appear (old non-paylater, hidden); old unpaid pay-later #9/#3/#23 still appear.

Clean up:
```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "DELETE FROM orders WHERE daily_order_no=9990 AND customer_name='ZZ Probe';"
```

- [ ] **Step 5: Commit**

```bash
git add find_order.php
git commit -m "feat: scope find_order to today plus old unpaid pay-later"
```

---

### Task 3: Server-side pagination (LIMIT/OFFSET + total)

**Files:**
- Modify: `find_order.php` — after `$filter_tab` is resolved (~line 55) and around the main query (~line 90)

**Interfaces:**
- Consumes: scoped queries from Task 2.
- Produces: page-level globals available to the rest of the page and (Task 4) the AJAX endpoint:
  - `$perPage` (int, 10), `$page` (int ≥1, clamped to `$totalPages`), `$offset` (int)
  - `$total` (int total matching rows), `$totalPages` (int ≥1)

- [ ] **Step 1: Compute pagination inputs**

Immediately after the cashier tab-force block (`find_order.php:52-55`), add:

```php
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
```

- [ ] **Step 2: Add a COUNT(*) for the filtered total**

Right before `$sql .= " ORDER BY order_date DESC";` (~line 90), the search and tab filters have already been appended to `$sql`. Build a matching count by reusing the same WHERE. Replace the `SELECT ... FROM orders` head with a count head:

```php
// Total matching rows (same filters, for pagination)
$count_main_sql = preg_replace(
    '/^\s*SELECT .*?FROM orders/s',
    'SELECT COUNT(*) AS c FROM orders',
    $sql,
    1
);
$total      = (int)(mysqli_fetch_assoc(mysqli_query($conn, $count_main_sql))['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
```

(Placed after all `$sql .=` filter appends but **before** adding `ORDER BY`/`LIMIT`.)

- [ ] **Step 3: Add ORDER BY + LIMIT/OFFSET to the list query**

Replace the existing `$sql .= " ORDER BY order_date DESC";` + execute block with:

```php
$sql .= " ORDER BY order_date DESC LIMIT $perPage OFFSET $offset";
$result = mysqli_query($conn, $sql);
$orders = [];
while ($row = mysqli_fetch_assoc($result)) {
    $orders[] = $row;
}
```

(`$perPage` and `$offset` are ints — safe to interpolate.)

- [ ] **Step 4: Verify only 10 rows render and paging works by URL**

Temporarily seed 12 pay-later orders today:
```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "INSERT INTO orders (daily_order_no,customer_name,total,status,payment_method,order_date,business_date,is_open,token_number) SELECT 9900+n,'PgTest',2.00,'Completed','paylater',NOW(),CURDATE(),0,0 FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12) t;"
```
As cashier, open `find_order.php` → expect exactly 10 cards. Open `find_order.php?page=2` → expect the remaining cards (12 seeded + the 3 originals = 15 total → page 2 has 5). `find_order.php?page=999` → clamps to last page (no empty list).

Clean up:
```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "DELETE FROM orders WHERE customer_name='PgTest';"
```

- [ ] **Step 5: Commit**

```bash
git add find_order.php
git commit -m "feat: server-side LIMIT/OFFSET pagination for find_order list"
```

---

### Task 4: `action=list` JSON endpoint

**Files:**
- Modify: `find_order.php` — add a new request handler beside the existing `action=poll` branch (~line 22), and refactor so the list/count/pagination logic runs before it.

**Interfaces:**
- Consumes: `$orders`, `$total`, `$page`, `$perPage`, `$totalPages`, `$tab_counts`, `_order_card.php`.
- Produces: JSON response for `GET find_order.php?action=list&tab&search_type&search_value&page`:
  ```json
  { "html": "...", "page": 1, "perPage": 10, "total": 15, "totalPages": 2,
    "sig": "<md5>", "tabCounts": { "all":0,"preparing":0,"pending":0,"paid_open":0,"paylater":0 } }
  ```

- [ ] **Step 1: Ensure query/count/counts run before the endpoint emits**

The `action=list` handler needs `$orders`, `$total`, `$totalPages`, and `$tab_counts`. The simplest structure: keep all the existing top-of-file query building (search/tab/list/count/tab-counts/pagination from Tasks 2–3) where it is, then place the `action=list` emitter **after** `$tab_counts` is built (after ~line 124) but **before** `$total_unpaid` / the `?>` HTML start (line 126-127).

- [ ] **Step 2: Add the endpoint**

Insert immediately after the tab-count loop (after `find_order.php:124`), before line 126:

```php
// ── AJAX: rendered card list + pagination meta for the current page ──
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    header('Content-Type: application/json');
    ob_start();
    foreach ($orders as $order) include '_order_card.php';
    $html = ob_get_clean();

    $sig = '';
    foreach ($orders as $o) $sig .= $o['order_id'] . ':' . $o['status'] . '|';

    echo json_encode([
        'html'       => $html,
        'page'       => $page,
        'perPage'    => $perPage,
        'total'      => $total,
        'totalPages' => $totalPages,
        'sig'        => md5($sig),
        'tabCounts'  => $tab_counts,
    ]);
    exit;
}
```

- [ ] **Step 3: Verify the JSON endpoint**

As cashier in the browser (so the session cookie is set), open:
`http://localhost/Cafe/find_order.php?action=list&tab=paylater&page=1`
Expected: JSON with `"totalPages"`, `"perPage":10`, and `"html"` containing `order-card` markup for up to 10 orders. `&page=2` returns the next slice (or clamps).

- [ ] **Step 4: Commit**

```bash
git add find_order.php
git commit -m "feat: add action=list JSON endpoint for AJAX pagination"
```

---

### Task 5: Client AJAX pagination + handler rebinding

**Files:**
- Modify: `find_order.php` — add `#pagination` container after `#orderList` (~line 677); add JS near the existing poll/table-edit script (~line 860+).

**Interfaces:**
- Consumes: `action=list` JSON (Task 4); existing inline-onclick handlers (`interceptPayLater`, `closeOrder`, `cancelOrderFromFind`).
- Produces (JS globals): `currentPage` (int), `lastSig` (string), `loadPage(n, opts)`, `renderPagination(page, totalPages)`, `bindCardHandlers()`.

- [ ] **Step 1: Add the pagination container**

Immediately after the `</div>` that closes `#orderList` (`find_order.php:677`), add:

```html
    <div id="pagination" class="pagination-bar"></div>
```

Add styling to the page `<style>` block (near the results-header styles):

```css
.pagination-bar{display:flex;justify-content:center;align-items:center;gap:6px;margin:22px 0 8px;flex-wrap:wrap;}
.pagination-bar button{min-width:38px;height:38px;padding:0 10px;border-radius:10px;border:1px solid var(--border);
  background:var(--bg-card);color:var(--text);font-family:inherit;font-size:14px;cursor:pointer;transition:all .15s;}
.pagination-bar button:hover:not(:disabled){border-color:var(--accent);color:var(--accent);}
.pagination-bar button.active{background:var(--accent);border-color:var(--accent);color:#1a1a1a;font-weight:600;}
.pagination-bar button:disabled{opacity:.4;cursor:default;}
.pagination-bar .ellipsis{color:var(--text-muted);padding:0 4px;}
```

- [ ] **Step 2: Move table-edit binding into `bindCardHandlers()`**

The existing table-number edit binder (currently a top-level `document.querySelectorAll('.table-edit-wrap').forEach(...)` block, ~`find_order.php:884` onward) must re-run after each swap. Wrap it:

```javascript
function bindCardHandlers() {
    document.querySelectorAll('.table-edit-wrap').forEach(function(wrap) {
        if (wrap.dataset.bound === '1') return; // idempotent
        wrap.dataset.bound = '1';
        // ...existing binder body verbatim (editBtn/saveBtn/cancelBtn listeners, tableEditOpen toggling)...
    });
}
```

Call it once after definition: `bindCardHandlers();`

- [ ] **Step 3: Add `loadPage` + `renderPagination`**

Add near the poll script:

```javascript
let currentPage = <?= (int)$page ?>;
let lastSig = null;

function currentQuery() {
    const p = new URLSearchParams(location.search);
    return {
        tab: p.get('tab') || <?= json_encode($filter_tab) ?>,
        search_type: p.get('search_type') || '',
        search_value: p.get('search_value') || ''
    };
}

async function loadPage(n, opts = {}) {
    const q = currentQuery();
    const url = 'find_order.php?action=list&tab=' + encodeURIComponent(q.tab)
              + '&search_type=' + encodeURIComponent(q.search_type)
              + '&search_value=' + encodeURIComponent(q.search_value)
              + '&page=' + n;
    let data;
    try { data = await (await fetch(url)).json(); }
    catch (e) { return; }

    if (opts.silent && data.sig === lastSig) { currentPage = data.page; return; }

    const list = document.getElementById('orderList');
    if (list) list.innerHTML = data.html;
    currentPage = data.page;
    lastSig = data.sig;
    renderPagination(data.page, data.totalPages);
    bindCardHandlers();
}

function renderPagination(page, totalPages) {
    const bar = document.getElementById('pagination');
    if (!bar) return;
    if (totalPages <= 1) { bar.innerHTML = ''; return; }
    const btn = (label, target, {active = false, disabled = false} = {}) =>
        `<button ${disabled ? 'disabled' : ''} class="${active ? 'active' : ''}" data-pg="${target}">${label}</button>`;
    let html = '';
    html += btn('&laquo;', 1, {disabled: page === 1});
    html += btn('&lsaquo;', page - 1, {disabled: page === 1});
    const win = [];
    for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) win.push(i);
    if (win[0] > 1) { html += btn('1', 1); if (win[0] > 2) html += '<span class="ellipsis">…</span>'; }
    win.forEach(i => html += btn(i, i, {active: i === page}));
    const last = win[win.length - 1];
    if (last < totalPages) { if (last < totalPages - 1) html += '<span class="ellipsis">…</span>'; html += btn(totalPages, totalPages); }
    html += btn('&rsaquo;', page + 1, {disabled: page === totalPages});
    html += btn('&raquo;', totalPages, {disabled: page === totalPages});
    bar.innerHTML = html;
    bar.querySelectorAll('button[data-pg]').forEach(b =>
        b.addEventListener('click', () => loadPage(parseInt(b.dataset.pg, 10))));
}
```

- [ ] **Step 4: Initialise pagination on load**

At the end of the script (inside the existing `DOMContentLoaded` or after `bindCardHandlers()`), seed the bar from the server-rendered page:

```javascript
renderPagination(currentPage, <?= (int)$totalPages ?>);
lastSig = <?= json_encode(md5(implode('|', array_map(fn($o) => $o['order_id'].':'.$o['status'], $orders)))) ?>;
```

- [ ] **Step 5: Verify AJAX paging + handlers**

Seed 12 pay-later orders (reuse the Task 3 Step 4 INSERT). As cashier:
- Page bar shows `« ‹ 1 2 › »` (2 pages). Click `2` → list swaps without a full reload (no white flash, scroll stays), shows page-2 cards.
- On a page-2 card, click the stand **edit** (pencil), type a value, Save → it persists (table-edit still bound after swap).
- Click a Cash button on a pay-later card → loyalty modal still intercepts (inline onclick survived).

Clean up the seeds:
```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "DELETE FROM orders WHERE customer_name='PgTest';"
```

- [ ] **Step 6: Commit**

```bash
git add find_order.php
git commit -m "feat: AJAX page switching with windowed pagination bar and handler rebinding"
```

---

### Task 6: Silent current-page auto-refresh

**Files:**
- Modify: `find_order.php` — the 5s `setInterval` poll block (~`860-882`).

**Interfaces:**
- Consumes: `loadPage(currentPage, { silent: true })` (Task 5), existing `tableEditOpen` flag.
- Produces: no full-page reloads from the poll.

- [ ] **Step 1: Replace the poll body**

The existing interval fetches `action=poll` and calls `location.reload()` on signature change (it also sets the `skipEntranceAnim` flag). Replace the whole interval with:

```javascript
setInterval(function() {
    if (tableEditOpen) return;            // don't disturb an open edit
    if (document.querySelector('#lpModal.show')) return; // skip while loyalty modal open
    loadPage(currentPage, { silent: true });
}, 5000);
```

(The separate `action=poll` endpoint and the `skipEntranceAnim` flag/`location.reload()` path are no longer used by find_order; leave the poll endpoint in place for now — harmless — but remove the `skipEntranceAnim` `sessionStorage.setItem` line since this page no longer full-reloads.)

- [ ] **Step 2: Verify silent refresh keeps your page**

Seed 12 pay-later orders (Task 3 INSERT). As cashier, go to page 2. In another shell, change one order's status:
```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "UPDATE orders SET status='PendingPayment' WHERE customer_name='PgTest' LIMIT 1;"
```
Within ~5s the list updates **in place** — you stay on page 2, no white flash, no scroll jump. If nothing changed (idle), there is no visible flicker (sig match → no swap).

Clean up:
```bash
/c/xampp/mysql/bin/mysql.exe -u root db_coffee -e "DELETE FROM orders WHERE customer_name='PgTest';"
```

- [ ] **Step 3: Commit**

```bash
git add find_order.php
git commit -m "feat: silent current-page auto-refresh replacing full reload"
```

---

## Self-Review

**Spec coverage:**
- 10/page pagination → Task 3 (server) + Task 5 (UI). ✓
- Server-side LIMIT/OFFSET → Task 3. ✓
- AJAX page switching → Task 4 (endpoint) + Task 5 (client). ✓
- Today + old unpaid pay-later scoping → Task 2 (all three queries). ✓
- Silent current-page auto-refresh → Task 6. ✓
- Card extraction / single source → Task 1. ✓
- Handler rebinding → Task 5 Step 2. ✓
- Windowed pager + hidden when ≤1 page + edge clamps → Task 3 Step 2 (clamp) + Task 5 Step 3. ✓
- Tab-count refresh after AJAX → endpoint returns `tabCounts` (Task 4); wiring counts into pills is optional polish (pills already server-render correctly per page load; AJAX consumers may update later — not required for core behavior).

**Placeholder scan:** the only `...verbatim...` markers (Task 1 Step 1 card body, Task 5 Step 2 binder body) are explicit "paste existing code unchanged" instructions, not unfinished logic — acceptable since the source lines are cited.

**Type/name consistency:** `loadPage`, `renderPagination`, `bindCardHandlers`, `currentPage`, `lastSig`, `$perPage`, `$page`, `$total`, `$totalPages`, `$offset` used consistently across Tasks 3–6. ✓

**Note on `tabCounts` pills:** server-rendered pills stay correct on every full load; updating them live from the AJAX `tabCounts` is a nice-to-have left out of the core tasks to keep scope tight (YAGNI). Add later if stale counts between refreshes prove annoying.
