# Attendance History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a standalone `attendance_history.php` page for querying past attendance across a date range, filterable by employee, with quick presets, range summary stats, server-side pagination, and CSV export.

**Architecture:** One new self-contained PHP page following the existing procedural-PHP/MySQLi pattern used by `attendance.php` and `announcements.php`. All DB access via prepared statements. The page reuses `attendance.php`'s CSS theme and `announcements.php`'s pagination markup. The live `attendance.php` is left untouched except for one "History" link.

**Tech Stack:** PHP 7+ (procedural MySQLi), HTML/CSS (no framework), Font Awesome 6, Poppins. No build step. No automated test harness — verification is `php -l` lint + manual browser checks while logged in as an admin/manager.

## Global Constraints

- Procedural PHP + MySQLi only — no framework, no ORM. Match the style of `attendance.php`.
- Every DB query that takes user input uses **prepared statements** with bound params.
- Permission gate: `if (!can('attendance')) { header("Location: dashboard.php?denied=1"); exit; }` — copied verbatim from `attendance.php`.
- `$per_page = 10`.
- All user-echoed values pass through `htmlspecialchars()`.
- Reuse existing CSS variables/classes from `attendance.php`; do not invent a new theme.
- DB name `db_coffee`; credentials come from `config.php` (already required) — never hardcode.
- The employee dropdown query: `SELECT user_id, name FROM employees WHERE user_id IS NOT NULL ORDER BY name ASC` (`employees.user_id` is nullable).
- Verification per task: `"C:/xampp/php/php.exe" -l <file>` must report "No syntax errors", then a manual browser check. XAMPP MySQL must be running for browser checks — if "connection refused", start MySQL via XAMPP Control Panel first.

---

## File Structure

- **Create:** `attendance_history.php` — the entire feature (auth, filter resolution, CSV export branch, queries, HTML render, pagination). Single file, mirroring how `attendance.php` and `announcements.php` are each one self-contained file.
- **Modify:** `attendance.php` — add one "History" link in the topbar.

Tasks build `attendance_history.php` top-to-bottom: PHP logic block first (Tasks 1–2), then HTML sections (Tasks 3–6), then the cross-file link (Task 7).

---

### Task 1: Scaffold page — auth, filter resolution, URL helper, skeleton

**Files:**
- Create: `attendance_history.php`

**Interfaces:**
- Produces (PHP vars available to later tasks): `$from`, `$to` (validated `Y-m-d` strings), `$emp` (int, 0=all), `$today`, `$default_from`, `$where` (SQL fragment), `$types` (bind type string), `$binds` (array of bind values), and function `qs(array $overrides=[]): string` (builds a URL to this page preserving current filters).

- [ ] **Step 1: Create the file with the PHP logic head + minimal HTML skeleton**

```php
<?php
require 'auth.php';
require 'config.php';
if (!can('attendance')) { header("Location: dashboard.php?denied=1"); exit; }

// ── Resolve filters ──
$today        = date('Y-m-d');
$default_from = date('Y-m-d', strtotime('-30 days'));

$from = $_GET['from'] ?? $default_from;
$to   = $_GET['to']   ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $default_from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;
if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }

$emp = (int)($_GET['emp'] ?? 0);   // 0 = all

// ── Shared WHERE fragment + bind set (reused by count, rows, summary, CSV) ──
$where = "a.date BETWEEN ? AND ?";
$types = "ss";
$binds = [$from, $to];
if ($emp > 0) { $where .= " AND a.user_id = ?"; $types .= "i"; $binds[] = $emp; }

// ── URL helper: preserve current filters, override as needed ──
function qs(array $overrides = []): string {
    global $from, $to, $emp;
    $p = array_merge(['from' => $from, 'to' => $to, 'emp' => $emp ?: 'all'], $overrides);
    return 'attendance_history.php?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance History | Bird's Nest Coffee</title>
</head>
<body>
<h1>Attendance History (scaffold)</h1>
<p>Range: <?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?> | emp: <?= (int)$emp ?></p>
</body>
</html>
```

- [ ] **Step 2: Lint**

Run: `"C:/xampp/php/php.exe" -l attendance_history.php`
Expected: `No syntax errors detected in attendance_history.php`

- [ ] **Step 3: Manual browser check**

Start XAMPP MySQL if not running. Log in as admin (e.g. Sokun). Visit:
- `http://localhost/Cafe/attendance_history.php` → shows scaffold with range = (today−30d) → today, emp: 0.
- `http://localhost/Cafe/attendance_history.php?from=2026-06-01&to=2026-06-15&emp=2` → range reflects params, emp: 2.
- `http://localhost/Cafe/attendance_history.php?from=bad&to=also-bad` → falls back to default range (no error).
Log out / use a no-permission account → redirected to `dashboard.php?denied=1`.

- [ ] **Step 4: Commit**

```bash
git add attendance_history.php
git commit -m "feat(attendance): scaffold history page with filter resolution"
```

---

### Task 2: Queries + summary + table render

**Files:**
- Modify: `attendance_history.php`

**Interfaces:**
- Consumes: `$where`, `$types`, `$binds`, `$per_page`, `$from`, `$to`, `$emp` from Task 1.
- Produces: `$records` (array of rows, current page), `$summary` (assoc: `shifts`, `total_hours`, `staff`, `avg_hours`), `$total_count`, `$total_pages`, `$page`, `$offset`, `$emp_list` (array of `user_id`,`name`).

- [ ] **Step 1: Insert the data-access block** — place it immediately after the `qs()` function and before the closing `?>` of the PHP head (i.e. before `<!DOCTYPE html>`).

```php
// ── Pagination math ──
$per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $conn->prepare("SELECT COUNT(*) FROM attendance a WHERE $where");
$cnt_stmt->bind_param($types, ...$binds);
$cnt_stmt->execute();
$total_count = (int)$cnt_stmt->get_result()->fetch_row()[0];
$total_pages = $total_count > 0 ? (int)ceil($total_count / $per_page) : 1;
$page   = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// ── Page rows ──
$rows_stmt = $conn->prepare(
    "SELECT a.*, e.name AS emp_name
     FROM attendance a LEFT JOIN employees e ON e.user_id = a.user_id
     WHERE $where
     ORDER BY a.date DESC, a.clock_in ASC
     LIMIT ? OFFSET ?"
);
$rt = $types . "ii";
$rb = array_merge($binds, [$per_page, $offset]);
$rows_stmt->bind_param($rt, ...$rb);
$rows_stmt->execute();
$records = $rows_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Range summary (full filtered range, not just page) ──
$sum_stmt = $conn->prepare(
    "SELECT COUNT(*)                      AS shifts,
            COALESCE(SUM(hours_worked),0) AS total_hours,
            COUNT(DISTINCT a.user_id)     AS staff,
            COALESCE(AVG(hours_worked),0) AS avg_hours
     FROM attendance a WHERE $where"
);
$sum_stmt->bind_param($types, ...$binds);
$sum_stmt->execute();
$summary = $sum_stmt->get_result()->fetch_assoc();

// ── Employee dropdown (roster, only those linked to a login) ──
$emp_list = $conn->query(
    "SELECT user_id, name FROM employees WHERE user_id IS NOT NULL ORDER BY name ASC"
)->fetch_all(MYSQLI_ASSOC);
```

- [ ] **Step 2: Replace the `<body>` skeleton** with the summary strip + table. Swap the existing `<h1>…</h1><p>…</p>` for:

```php
<body>
<div class="page-wrap">

    <!-- Summary -->
    <div class="summary">
        <div class="scard c-accent">
            <div class="scard-val"><?= (int)$summary['shifts'] ?></div>
            <div class="scard-lbl"><i class="fa-solid fa-calendar-check"></i> Total Shifts</div>
        </div>
        <div class="scard c-orange">
            <div class="scard-val"><?= number_format((float)$summary['total_hours'], 1) ?>h</div>
            <div class="scard-lbl"><i class="fa-solid fa-clock"></i> Total Hours</div>
        </div>
        <div class="scard c-green">
            <div class="scard-val"><?= (int)$summary['staff'] ?></div>
            <div class="scard-lbl"><i class="fa-solid fa-users"></i> Staff</div>
        </div>
        <div class="scard c-blue">
            <div class="scard-val"><?= number_format((float)$summary['avg_hours'], 2) ?>h</div>
            <div class="scard-lbl"><i class="fa-solid fa-chart-simple"></i> Avg / Shift</div>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div>
                <div class="card-title">Attendance History</div>
                <div class="card-sub"><?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?></div>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Date</th><th>Employee</th><th>Clock In</th>
                    <th>Clock Out</th><th>Hours</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
            <tr><td colspan="6" style="padding:40px;text-align:center;color:var(--text-muted)">
                <i class="fa-solid fa-calendar-xmark" style="display:block;font-size:32px;margin-bottom:12px;opacity:.25"></i>
                No attendance records in this range.
            </td></tr>
            <?php else: foreach ($records as $r):
                $name    = $r['emp_name'] ?: $r['username'];
                $working = is_null($r['clock_out']);
            ?>
            <tr>
                <td><?= date('d M Y', strtotime($r['date'])) ?></td>
                <td>
                    <div style="font-weight:600"><?= htmlspecialchars($name) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($r['username']) ?></div>
                </td>
                <td><?= date('g:i A', strtotime($r['clock_in'])) ?></td>
                <td><?= $working ? '<span style="color:var(--text-muted)">—</span>' : date('g:i A', strtotime($r['clock_out'])) ?></td>
                <td><?= $working ? '<span style="color:var(--text-muted)">—</span>' : number_format((float)$r['hours_worked'], 2) . 'h' ?></td>
                <td>
                    <?php if ($working): ?>
                    <span class="badge badge-working"><span class="live-dot" style="width:6px;height:6px"></span> Active</span>
                    <?php else: ?>
                    <span class="badge badge-done"><i class="fa-solid fa-circle-check"></i> Complete</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
```

- [ ] **Step 3: Add `<head>` assets + theme CSS.** Replace the existing `<head>…</head>` block. Copy the **full** `<style>…</style>` block and the two `<link>` font/icon tags **verbatim from `attendance.php`** (lines ~43–201: the `:root` vars, `.topbar`, `.back-btn`, `.page-wrap`, `.summary`/`.scard*`, `.card`/`.card-header`/`.card-icon`/`.card-title`/`.card-sub`, `table`/`thead`/`tbody`, `.badge`/`.badge-working`/`.badge-done`, `.live-dot`, and the entrance keyframes). Use this head shell and paste attendance.php's style block where marked:

```php
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance History | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* === PASTE attendance.php <style> contents here verbatim === */
</style>
</head>
```

- [ ] **Step 4: Lint**

Run: `"C:/xampp/php/php.exe" -l attendance_history.php`
Expected: `No syntax errors detected in attendance_history.php`

- [ ] **Step 5: Manual browser check**

Visit `http://localhost/Cafe/attendance_history.php` as admin:
- Summary cards show counts/hours for the last 30 days.
- Table lists shifts, newest date first; open shifts show `—` + "Active", closed show hours + "Complete".
- `?from=2030-01-01&to=2030-01-02` (future, no data) → empty state, summary zeros.
- Theme matches `attendance.php` (dark + light via the theme toggle that sets `data-theme`).

- [ ] **Step 6: Commit**

```bash
git add attendance_history.php
git commit -m "feat(attendance): history queries, summary strip, results table"
```

---

### Task 3: Topbar + filters bar (dates + employee dropdown)

**Files:**
- Modify: `attendance_history.php`

**Interfaces:**
- Consumes: `$from`, `$to`, `$emp`, `$emp_list`, `$today` from Tasks 1–2.
- Produces: a GET `<form>` whose submission reloads the page with `from`/`to`/`emp`.

- [ ] **Step 1: Add the topbar** immediately after `<body>`, before `<div class="page-wrap">`:

```php
<div class="topbar">
    <div class="topbar-left">
        <a href="attendance.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Attendance</a>
        <span class="page-title">Attendance <span>History</span></span>
    </div>
</div>
```

- [ ] **Step 2: Add the filters form** as the first child inside `<div class="page-wrap">`, before the summary strip:

```php
<form method="GET" class="filters-bar" id="filtersForm">
    <div class="fb-field">
        <label class="fb-label">From</label>
        <input type="date" name="from" class="date-input" value="<?= htmlspecialchars($from) ?>" max="<?= $today ?>" onchange="document.getElementById('filtersForm').submit()">
    </div>
    <div class="fb-field">
        <label class="fb-label">To</label>
        <input type="date" name="to" class="date-input" value="<?= htmlspecialchars($to) ?>" max="<?= $today ?>" onchange="document.getElementById('filtersForm').submit()">
    </div>
    <div class="fb-field">
        <label class="fb-label">Employee</label>
        <select name="emp" class="date-input" onchange="document.getElementById('filtersForm').submit()">
            <option value="all"<?= $emp === 0 ? ' selected' : '' ?>>All staff</option>
            <?php foreach ($emp_list as $e): ?>
            <option value="<?= (int)$e['user_id'] ?>"<?= $emp === (int)$e['user_id'] ? ' selected' : '' ?>>
                <?= htmlspecialchars($e['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</form>
```

- [ ] **Step 3: Add filters-bar CSS** inside the `<style>` block (just before `</style>`):

```css
/* Filters bar */
.filters-bar { display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; margin-bottom:22px; }
.fb-field { display:flex; flex-direction:column; gap:5px; }
.fb-label { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--text-muted); }
.filters-bar .date-input { min-width:150px; }
```

- [ ] **Step 4: Lint**

Run: `"C:/xampp/php/php.exe" -l attendance_history.php`
Expected: `No syntax errors detected in attendance_history.php`

- [ ] **Step 5: Manual browser check**

As admin on `attendance_history.php`:
- Back button returns to `attendance.php`.
- Changing From/To reloads with the new range (summary + table update).
- Employee dropdown lists roster names; selecting one filters table + summary to that person; "All staff" clears it.
- Selected dropdown value persists after reload.

- [ ] **Step 6: Commit**

```bash
git add attendance_history.php
git commit -m "feat(attendance): history topbar + date/employee filters"
```

---

### Task 4: Quick presets with active highlight + CSV button

**Files:**
- Modify: `attendance_history.php`

**Interfaces:**
- Consumes: `$from`, `$to`, `$today`, `$default_from`, `qs()` from Tasks 1–2.
- Produces: `$presets` (map key→[from,to]), `$active_preset` (string key or '').

- [ ] **Step 1: Compute preset ranges + active key.** Add to the PHP head, right after the `$emp_list` query (Task 2):

```php
// ── Quick-range presets + active detection ──
$presets = [
    'week'  => [date('Y-m-d', strtotime('monday this week')), $today],
    'month' => [date('Y-m-01'),                               $today],
    'm30'   => [$default_from,                                $today],
];
$active_preset = '';
foreach ($presets as $k => $range) {
    if ($from === $range[0] && $to === $range[1]) { $active_preset = $k; break; }
}
```

- [ ] **Step 2: Add the preset + CSV controls** to the filters form, after the Employee `.fb-field`, still inside `<form>`:

```php
    <div class="fb-field">
        <label class="fb-label">Quick range</label>
        <div class="preset-row">
            <a href="<?= htmlspecialchars(qs(['from'=>$presets['week'][0],  'to'=>$presets['week'][1],  'page'=>1])) ?>"  class="preset-btn<?= $active_preset==='week'  ? ' active' : '' ?>">This week</a>
            <a href="<?= htmlspecialchars(qs(['from'=>$presets['month'][0], 'to'=>$presets['month'][1], 'page'=>1])) ?>" class="preset-btn<?= $active_preset==='month' ? ' active' : '' ?>">This month</a>
            <a href="<?= htmlspecialchars(qs(['from'=>$presets['m30'][0],   'to'=>$presets['m30'][1],   'page'=>1])) ?>"   class="preset-btn<?= $active_preset==='m30'   ? ' active' : '' ?>">Last 30 days</a>
        </div>
    </div>
    <div class="fb-field" style="margin-left:auto">
        <label class="fb-label">&nbsp;</label>
        <a href="<?= htmlspecialchars(qs(['export'=>'csv'])) ?>" class="csv-btn"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
    </div>
```

- [ ] **Step 3: Add preset + CSV CSS** before `</style>`:

```css
/* Presets + CSV */
.preset-row { display:flex; gap:6px; flex-wrap:wrap; }
.preset-btn {
    display:inline-flex; align-items:center; height:38px; padding:0 14px;
    border-radius:8px; border:1px solid var(--border); background:rgba(255,255,255,.03);
    color:var(--text-muted); font-size:12.5px; font-weight:600; text-decoration:none; transition:all .2s;
}
.preset-btn:hover { color:var(--accent); border-color:rgba(209,144,75,.3); }
.preset-btn.active { background:var(--accent); border-color:var(--accent); color:#1a1410; }
.csv-btn {
    display:inline-flex; align-items:center; gap:7px; height:38px; padding:0 16px;
    border-radius:8px; border:1px solid rgba(85,224,135,.3); background:rgba(85,224,135,.08);
    color:var(--success); font-size:12.5px; font-weight:600; text-decoration:none; transition:all .2s;
}
.csv-btn:hover { background:rgba(85,224,135,.16); border-color:var(--success); }
```

- [ ] **Step 4: Lint**

Run: `"C:/xampp/php/php.exe" -l attendance_history.php`
Expected: `No syntax errors detected in attendance_history.php`

- [ ] **Step 5: Manual browser check**

As admin:
- Default page (no params) → "Last 30 days" highlighted.
- Click "This week" / "This month" → range changes, that button becomes highlighted.
- Pick custom From/To not matching any preset → no preset highlighted.
- "Export CSV" link present (download verified in Task 6, which wires the export branch).

- [ ] **Step 6: Commit**

```bash
git add attendance_history.php
git commit -m "feat(attendance): quick-range presets with active highlight + CSV button"
```

---

### Task 5: Server-side pagination + result count

**Files:**
- Modify: `attendance_history.php`

**Interfaces:**
- Consumes: `$page`, `$total_pages`, `$total_count`, `$offset`, `$records`, `$per_page`, `qs()`.
- Produces: a pager that preserves all filters in every link.

- [ ] **Step 1: Add the pager block** inside `<div class="card">`, immediately after `</table>` (before the card's closing `</div>`):

```php
<?php if ($total_pages > 1 || $total_count > 0): ?>
<div class="pg-wrap">
    <span class="pg-info">
        <?php $rng_start = $total_count ? $offset + 1 : 0; $rng_end = $offset + count($records); ?>
        <?= $rng_start ?>–<?= $rng_end ?> of <?= number_format($total_count) ?> results
    </span>
    <?php if ($total_pages > 1): ?>
    <nav class="pg-nav">
        <?php if ($page > 1): ?>
        <a href="<?= htmlspecialchars(qs(['page'=>1])) ?>" class="pg-btn">«</a>
        <a href="<?= htmlspecialchars(qs(['page'=>$page-1])) ?>" class="pg-btn">‹</a>
        <?php else: ?>
        <span class="pg-disabled">«</span><span class="pg-disabled">‹</span>
        <?php endif; ?>
        <?php
        $w_start = max(1, $page - 2);
        $w_end   = min($total_pages, $page + 2);
        if ($w_start > 1): ?><span class="pg-ellipsis">…</span><?php endif;
        for ($pg_i = $w_start; $pg_i <= $w_end; $pg_i++): ?>
            <?php if ($pg_i === $page): ?>
            <span class="pg-active"><?= $pg_i ?></span>
            <?php else: ?>
            <a href="<?= htmlspecialchars(qs(['page'=>$pg_i])) ?>" class="pg-btn"><?= $pg_i ?></a>
            <?php endif; ?>
        <?php endfor;
        if ($w_end < $total_pages): ?><span class="pg-ellipsis">…</span><?php endif; ?>
        <?php if ($page < $total_pages): ?>
        <a href="<?= htmlspecialchars(qs(['page'=>$page+1])) ?>" class="pg-btn">›</a>
        <a href="<?= htmlspecialchars(qs(['page'=>$total_pages])) ?>" class="pg-btn">»</a>
        <?php else: ?>
        <span class="pg-disabled">›</span><span class="pg-disabled">»</span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
<?php endif; ?>
```

- [ ] **Step 2: Add the pager CSS** before `</style>` — copied from `announcements.php`:

```css
/* Pagination */
.pg-wrap { padding:14px 18px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.pg-nav { display:flex; gap:4px; flex-wrap:wrap; }
.pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; text-decoration:none; transition:all .2s; }
.pg-btn:hover { border-color:var(--accent); color:var(--accent); }
.pg-active { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; background:var(--accent); border:1px solid var(--accent); color:#000; font-size:13px; font-weight:700; }
.pg-disabled { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); color:var(--text-muted); font-size:13px; opacity:.35; cursor:default; }
.pg-ellipsis { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; color:var(--text-muted); font-size:13px; }
.pg-info { font-size:12px; color:var(--text-muted); }
```

- [ ] **Step 3: Lint**

Run: `"C:/xampp/php/php.exe" -l attendance_history.php`
Expected: `No syntax errors detected in attendance_history.php`

- [ ] **Step 4: Manual browser check**

Need >10 rows in range. If the DB lacks them, temporarily widen the range (e.g. `?from=2020-01-01&to=2026-12-31`) or seed test rows. Then:
- Pager appears; "1–10 of N results" shown.
- Click page 2 (and `›`/`»`) → next rows load; From/To/emp preserved in the URL.
- Apply an employee filter, then page → filter stays applied across pages.
- Range with ≤10 rows → no pager nav, but result count still shows.

- [ ] **Step 5: Commit**

```bash
git add attendance_history.php
git commit -m "feat(attendance): server-side pagination with result count"
```

---

### Task 6: CSV export branch

**Files:**
- Modify: `attendance_history.php`

**Interfaces:**
- Consumes: `$where`, `$types`, `$binds`, `$from`, `$to`, `$conn`.
- Produces: a streamed CSV download when `?export=csv` is present; `exit;` before any HTML.

- [ ] **Step 1: Insert the export branch** in the PHP head, immediately **after** the `$where`/`$types`/`$binds` block and the `qs()` function, but **before** the pagination math (so no rows/HTML are computed for a CSV request):

```php
// ── CSV export (must run before any HTML output) ──
if (($_GET['export'] ?? '') === 'csv') {
    $csv_stmt = $conn->prepare(
        "SELECT a.date, COALESCE(e.name, a.username) AS emp_name, a.username,
                a.clock_in, a.clock_out, a.hours_worked
         FROM attendance a LEFT JOIN employees e ON e.user_id = a.user_id
         WHERE $where
         ORDER BY a.date DESC, a.clock_in ASC"
    );
    $csv_stmt->bind_param($types, ...$binds);
    $csv_stmt->execute();
    $csv_res = $csv_stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"attendance_{$from}_to_{$to}.csv\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Employee', 'Username', 'Clock In', 'Clock Out', 'Hours', 'Status']);
    while ($r = $csv_res->fetch_assoc()) {
        fputcsv($out, [
            $r['date'],
            $r['emp_name'],
            $r['username'],
            $r['clock_in'],
            $r['clock_out'] ?? '',
            is_null($r['hours_worked']) ? '' : number_format((float)$r['hours_worked'], 2),
            is_null($r['clock_out']) ? 'Active' : 'Complete',
        ]);
    }
    fclose($out);
    exit;
}
```

- [ ] **Step 2: Lint**

Run: `"C:/xampp/php/php.exe" -l attendance_history.php`
Expected: `No syntax errors detected in attendance_history.php`

- [ ] **Step 3: Manual browser check**

As admin:
- Click "Export CSV" → a file `attendance_<from>_to_<to>.csv` downloads.
- Open it: header row + one row per shift in the current filtered range (NOT limited to 10). Columns: Date, Employee, Username, Clock In, Clock Out, Hours, Status.
- Apply an employee filter, export again → CSV contains only that employee's rows.
- Confirm no HTML leaks into the CSV (the `exit;` prevents it).

- [ ] **Step 4: Commit**

```bash
git add attendance_history.php
git commit -m "feat(attendance): CSV export of filtered history"
```

---

### Task 7: Link History from the live attendance page

**Files:**
- Modify: `attendance.php` (topbar, around line 205–215)

**Interfaces:**
- Consumes: nothing new.
- Produces: a visible "History" link from `attendance.php` to `attendance_history.php`.

- [ ] **Step 1: Add the History link** in the `attendance.php` topbar. Find the `.topbar-left` block:

```php
    <div class="topbar-left">
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <span class="page-title">Staff <span>Attendance</span></span>
    </div>
```

Replace it with (adds the History link after the title):

```php
    <div class="topbar-left">
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <span class="page-title">Staff <span>Attendance</span></span>
        <a href="attendance_history.php" class="back-btn"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
    </div>
```

- [ ] **Step 2: Lint**

Run: `"C:/xampp/php/php.exe" -l attendance.php`
Expected: `No syntax errors detected in attendance.php`

- [ ] **Step 3: Manual browser check**

Visit `http://localhost/Cafe/attendance.php` as admin → "History" link visible in topbar → clicking it opens `attendance_history.php`. Live today-view still polls/updates normally (unchanged).

- [ ] **Step 4: Commit**

```bash
git add attendance.php
git commit -m "feat(attendance): link History from live attendance topbar"
```

---

## Self-Review Notes

- **Spec coverage:** new page (T1) · auth gate (T1) · entry-point link (T7) · GET params from/to/emp/page/export (T1, T5, T6) · employee dropdown from roster w/ NOT NULL (T2) · paginated rows query (T2/T5) · count (T2) · range summary (T2) · layout order Filters→Summary→Table→Pager (T2–T5) · 2-decimal hours (T2, T6) · Active/Complete badges reusing live classes (T2) · presets + active highlight (T4) · CSV export (T6) · "X of Y results" (T5) · theme reuse (T2) · edge handling: invalid dates & swap (T1), empty state (T2), page clamp (T2). All covered.
- **Placeholder scan:** none — every code step shows full code. The only "paste verbatim" step (T2S3) names the exact source file and line range to copy.
- **Type/name consistency:** `$where`/`$types`/`$binds` defined in T1, consumed unchanged in T2/T5/T6; `qs()` defined T1, used T4/T5/T6; `$presets`/`$active_preset` defined T4, used T4; bind spread `...$binds` used consistently. CSV branch (T6) inserted before pagination math but after `$where`/`qs()` — ordering explicit.
- **Ordering caveat:** Task 6 inserts the export branch into the head built across T1–T2. Implement in order (1→7); if implementing out of order, the export branch only needs `$where/$types/$binds` (T1) + `$conn` (config) — independent of T2's pagination/summary vars.
