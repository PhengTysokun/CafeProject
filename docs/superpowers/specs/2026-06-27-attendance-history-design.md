# Attendance History — Design Spec

**Date:** 2026-06-27
**Status:** Approved (pending spec review)
**Author:** Pheng Tysokun + Claude

## Problem

`attendance.php` shows attendance for a single day only (`WHERE a.date = ?`) and live-polls
that day every 10 s. There is no way to view shifts across a date range, filter by employee,
or export historical attendance. Client-side pagination was added to the live page but rarely
fires (few staff per day), so it provides little value. Real paginated history is the missing
piece.

## Goal

A separate **Attendance History** page for querying past attendance across a date range, with
employee filtering, quick presets, server-side pagination, range-level summary stats, and CSV
export. The existing live `attendance.php` is left untouched.

## Non-Goals

- No live polling on the history page (historical data is static).
- No editing/deleting attendance records (read-only view).
- No changes to the live `attendance.php` behaviour beyond adding one "History" link.

## File

**New:** `attendance_history.php`

Gated by `can('attendance')` — same permission as the live page. Redirects to
`dashboard.php?denied=1` on failure, matching existing convention.

## Entry Point

Add a "History" button in the `attendance.php` topbar (next to the existing back button),
visible to anyone who can already see the page. No new dashboard nav entry needed — history is
reached through the live page.

```html
<a href="attendance_history.php" class="back-btn"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
```

## Request Parameters (GET)

| Param   | Meaning                          | Default            | Validation |
|---------|----------------------------------|--------------------|------------|
| `from`  | Range start date (`Y-m-d`)       | today − 30 days    | regex `^\d{4}-\d{2}-\d{2}$`, else default |
| `to`    | Range end date (`Y-m-d`)         | today              | regex; if `to < from`, swap |
| `emp`   | Employee `user_id`, or `all`     | `all`              | cast to int; `all`/0 = no filter |
| `page`  | Page number                      | 1                  | `max(1, (int))`, clamped to `total_pages` |
| `export`| `csv` to stream CSV              | (none)             | only `csv` recognised |

Preset buttons (This week / This month / Last 30 days) are plain links that set `from`/`to`
and reset `page=1`. "Last 30 days" equals the default state.

**Active preset detection:** each preset maps to a computed `(from, to)` pair —
- This week: Monday of current week → today
- This month: first day of current month → today
- Last 30 days: today − 30 days → today

After resolving the effective `from`/`to` for the request, compare against each preset's pair;
the matching button gets an `.active` CSS class. "Last 30 days" is active when no `from`/`to`
params are present (default) or when the present values equal its computed pair. If the
resolved range matches no preset (custom dates), no button is highlighted.

## Data Access

**Employee dropdown** — sourced from the `employees` roster table, not the `attendance` table.
This avoids a full `attendance` scan (which grows unbounded with history) and uses the small,
indexed roster instead:
```sql
SELECT user_id, name FROM employees WHERE user_id IS NOT NULL ORDER BY name ASC
```
(`employees.user_id` is nullable; attendance keys on `user_id`, so rows without a linked login
are excluded from the filter.)
Tradeoff: a user who has attendance rows but no `employees` record won't appear as a filter
option. Such rows still display in the table (their `username` shows); they just can't be
isolated via the dropdown. Acceptable — the roster is the canonical staff list.

**Page rows** (paginated):
```sql
SELECT a.*, e.name AS emp_name
FROM attendance a LEFT JOIN employees e ON e.user_id = a.user_id
WHERE a.date BETWEEN ? AND ?            -- [AND a.user_id = ?]
ORDER BY a.date DESC, a.clock_in ASC
LIMIT ? OFFSET ?
```

**Row count** (for `total_pages`): same `WHERE`, `SELECT COUNT(*)`.

**Range summary** (over the whole filtered range, independent of page):
```sql
SELECT COUNT(*)                       AS shifts,
       COALESCE(SUM(hours_worked),0)  AS total_hours,
       COUNT(DISTINCT a.user_id)      AS staff,
       COALESCE(AVG(hours_worked),0)  AS avg_hours
FROM attendance a
WHERE a.date BETWEEN ? AND ?           -- [AND a.user_id = ?]
```
`hours_worked` is `NULL` for an open (still-clocked-in) shift, so `SUM`/`AVG` exclude open
shifts. Only today can have an open shift; live hours belong on the live page. This is the
intended behaviour, not a bug.

All queries use prepared statements with bound params (`from`, `to`, optional `emp`, plus
`per_page`/`offset` for the page query). `$per_page = 10`.

## Page Layout

Order, top to bottom:

1. **Topbar** — back to dashboard, page title, (mirrors live page).
2. **Filters bar** — `from` date input, `to` date input, employee `<select>`, preset buttons
   (active preset highlighted), CSV export button. Changing a field submits the form (GET).
3. **Summary strip** — 4 cards: Total Shifts · Total Hours · Staff · Avg Hours/Shift. Computed
   over the full filtered range. Placed above the table so it stays visible when paging.
4. **Table** — `Date | Employee | Clock In | Clock Out | Hours | Status`.
   - Hours: `number_format($hours_worked, 2)` (2 decimals); blank/`—` when open.
   - Status badge, reusing live-page classes:
     - open (`clock_out IS NULL`) → `badge-working`, label **Active**
     - closed → `badge-done`, label **Complete**
   - Empty state when no rows in range.
5. **Pagination** — server-side, reusing the `announcements.php` `.pg-*` styles and windowing
   logic. Every pager link preserves `from`/`to`/`emp`. Show "**X of Y results**" next to the
   pager (where X = rows on this page range, Y = total), matching the announcements pattern.

## CSV Export

`attendance_history.php?export=csv&from=…&to=…&emp=…`

- Same `can('attendance')` gate.
- Runs the page query **without** `LIMIT/OFFSET` — all rows in the filtered range.
- Streams with headers:
  ```
  Content-Type: text/csv; charset=utf-8
  Content-Disposition: attachment; filename="attendance_<from>_to_<to>.csv"
  ```
- Columns: Date, Employee, Username, Clock In, Clock Out, Hours, Status.
- Uses `fputcsv` to `php://output`. `exit;` after writing so no HTML follows.

## Styling

Reuse `attendance.php`'s theme verbatim: same `:root` CSS vars, `.topbar`, `.back-btn`,
`.card`, `table`, `.badge-working` / `.badge-done`, `.scard` summary cards. Add the
`.pg-*` pager styles copied from `announcements.php`. Light-theme support comes free from the
shared variable approach (match whatever the live page does).

## Error / Edge Handling

- Invalid/malformed dates → fall back to defaults (no error shown).
- `to < from` → swap so the range is always valid.
- Empty result set → friendly empty state, summary shows zeros, no pager.
- `emp` referencing a non-existent user → query simply returns no rows.
- Page beyond range → clamped to last page (same as announcements).

## Testing

Manual verification (no automated test harness in this project):
1. Open via History button; default = last 30 days, all staff.
2. Seed/confirm >10 rows in range → pager appears, page 2 works, filters preserved in links.
3. Filter by one employee → rows + summary reflect only that employee.
4. Each preset sets the right range and highlights as active.
5. CSV export downloads a file matching the on-screen filtered rows (minus pagination).
6. Range with an open (today) shift → row shows Active/`—`, excluded from hour sums.
7. Empty range (future dates) → empty state, zero summary, no pager.
8. Light + dark theme both render correctly.

## Out of Scope / Future

- Per-employee totals breakdown.
- Charts/trends.
- Editing records from history.
