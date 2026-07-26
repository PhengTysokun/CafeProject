# Daily Report — Design

**Date:** 2026-07-26
**Branch:** `feat/product-addons`
**New file:** `daily_report.php`
**Status:** approved design, not yet implemented

---

## Problem

`report.php` is 2,921 lines: nine KPI cards, six chart/table sections, daily/weekly/monthly
filters, CSV and PDF export. A manager opening it cannot tell where to look. It presents
data but answers no question.

The three questions a manager actually arrives with:

1. Did we earn more?
2. Did we lose money?
3. Is stock going up or down?

None of them can be answered from the current page without reading and comparing several
numbers by hand.

A second constraint: this is a graded school project judged by people who do not read
English fluently. Business vocabulary — *margin*, *revenue*, *COGS*, *outstanding*,
*variance* — is a barrier, not a signal of rigour.

## Goal

A manager gets all three answers in about five seconds, in words a non-native English
speaker understands, without clicking anything.

## Non-goals

- Replacing or deleting `report.php`. It stays exactly as it is, linked from the new page.
- Week/month/range reporting. The new page is one business day at a time.
- Any new chart library, framework, or build step.
- Inventing metrics the shop does not have (terminal counts, card payments, delivery).

---

## Structure

New page `daily_report.php`, gated on `can('report')` — the same permission
`report.php` uses (slug is `report`, singular). Becomes the sidebar's "Report" destination;
`report.php` is reached from it via a "Full analytics →" link.

Four tabs, in a shell modelled on a reference design the user provided:

| Tab | Purpose |
|---|---|
| **Today** | The three verdicts plus the day's money. Default tab. |
| **Orders** | Every order for the day, filterable by payment method. |
| **Stock** *(badge)* | Per-ingredient levels, what was used, what needs buying. |
| **Staff** | Who worked, and what they served. |

Tab 1 must stand alone. A manager who never touches tabs 2–4 has still had every question
answered. Tabs 2–4 exist for follow-up and to show depth to judges.

Header: shop name, the business date in full (`Saturday, July 25, 2026`),
`← Yesterday` / `Today →` arrows, and a **Print** button at top right. No date-range picker,
no calendar widget — two arrows.

Print is a print stylesheet over the current tab, not a generated PDF. A judge or manager
who wants paper presses Print. `report_pdf.php` remains the route for a generated document
and is unchanged.

### Tab loading

Tab 1 renders server-side on page load. Tabs 2–4 load their content by AJAX on first click
and are then cached client-side for the session. Switching tabs does not reload the page and
does not change the selected date.

### Live refresh

Tab 1 only, every 30 seconds, using the md5-signature change-detection pattern already used
by `find_order.php?action=poll` — the poll returns a signature and the client only re-renders
when it changes. This avoids the known problem where `dashboard.php` re-runs full KPI queries
every 5 seconds for every open browser.

Polling stops when the viewed date is not today.

---

## Tab 1 — Today

### The verdict row

Three boxes across the top. These are the only coloured elements on the page.

```
┌─ DID WE EARN MORE? ────┐ ┌─ DID WE KEEP MORE? ────┐ ┌─ CAN WE OPEN TOMORROW? ─┐
│ យើងរកបានច្រើនជាងមុនទេ?      │ │ យើងសល់ប្រាក់ច្រើនជាងមុនទេ?    │ │ ស្អែកយើងបើកបានទេ?           │
│                        │ │                        │ │                          │
│   $237.50              │ │   $142.50              │ │   🔴 NO                  │
│   money we got today   │ │   money we keep        │ │   brown sugar syrup      │
│                        │ │                        │ │   runs out mid-shift     │
│ 🔴 $30.50 LESS than    │ │ 🟢 $12.00 MORE than    │ │                          │
│    a normal Saturday   │ │    a normal Saturday   │ │ stock we have $526.48    │
│ yesterday $268.00      │ │ we keep 60¢ of each $1 │ │ used today $48.20        │
└────────────────────────┘ └────────────────────────┘ └──────────────────────────┘
```

**Colour rule: a colour must imply an action, or it is decoration.** Only these three boxes
carry red or green. Everything else on the page is neutral. This is what makes red mean
something.

**No percentages anywhere on tab 1.** Differences are shown in dollars. `9.1% less` is a
maths sentence; `$30.50 less` is a money sentence.

#### Box 1 — Did we earn more?

Money actually collected today.

```sql
SELECT COALESCE(SUM(total),0) FROM orders
WHERE business_date = ? AND <paid_orders_where()>
```

`paid_orders_where()` already exists in `config.php:243` and is the app-wide definition of
collected money (`is_open = 0 AND status NOT IN ('PendingPayment','Cancelled','Refunded','Void')`).
Reuse it. Do not write a new revenue condition.

**Open tabs are never counted here.** Unpaid pay-later money appears only in its own neutral
card. This matches the revenue-recognition rule already applied across the app.

#### Box 2 — Did we keep more?

Money we got, minus what the drinks cost us.

Cost comes from the ingredient cost map that `report.php:90-121` already builds: prefer
`ingredients.cost_per_unit`, fall back to `cost_price / purchase_qty` when it is zero. That
logic is correct and should be extracted to a shared helper in `config.php` rather than
copy-pasted, so the two pages cannot drift.

The small line renders as **"we keep 60¢ of each $1"** — never "60% margin".

Ingredient costs were repriced on 2026-07-26 (all 50 ingredients, per 1 kg / 1 L, no
loss-making recipes). These are documented estimates, not the shop's invoices. If a judge
asks where the costs come from, say so.

#### Box 3 — Can we open tomorrow?

**This box deliberately does not report whether stock went up or down.** Stock going down
means drinks were sold, which is good. Colouring that red would teach the manager to ignore
red. The box answers the question the owner is really asking when they ask about stock:
will anything stop service?

- **Red** when any ingredient has `stock_quantity <= minimum_stock`. Names up to three;
  if more, appends "and N more".
- **Green** when none are.

Stock movement still appears, as the two neutral lines beneath: **stock we have**, the money
value of everything on the shelf (`SUM(stock_quantity × cost_per_unit)`), and **used today**,
the money value of the ingredients consumed today (quantity × `cost_per_unit`). Both are in
dollars so they can be read against each other. Movement is derived from
`ingredient_history` for the business day — `order_deduct` and `count_adjust` as usage,
`po_received` and `quick_restock` as received. Note `manual_adjust` and `count_adjust`
amounts are **signed**.

### The baseline: a normal Saturday

Comparing today to yesterday is wrong for a café. Trade is weekly-seasonal; Saturday against
Friday is not a fair comparison, and a Saturday down 9% on Friday may still be the best
Saturday on record.

**Baseline = the average of the last 4 same-weekday business dates that have at least one
paid order.** Label: "than a normal Saturday".

**Fallback:** if fewer than 2 such days exist, compare against yesterday instead and change
the label to "than yesterday". Never show a comparison against a day with no data — some
weekdays are thin (as of this writing Friday has 4 days of history, none since 19 June,
while Saturday has 8).

If neither baseline is available, show the number with no verdict and no colour, and the
line "first day — nothing to compare yet."

Yesterday's figure remains visible as a small grey line in box 1 regardless of which
baseline drove the colour.

### Below the verdicts (all neutral grey)

- Four money cards: **cash**, **bakong**, **pay later — paid**, **not paid yet**.
- **Cups sold** and **orders**, each with the day's simple average
  ("each customer buys 2.0 cups").
- A single stacked bar showing how the day's money split across the four, with the note:
  **"$46.50 not paid yet. We count it only when the customer pays."**
- **Best seller** — top product by cups, with its cups and money.

---

## Tab 2 — Orders

The day's orders, newest last: time, order number, customer, total, how they paid.

- Order number is `daily_order_no`, never the raw `order_id` — this matches how the app
  already displays order numbers everywhere else.
- Filter pills: All / Cash / Bakong / Pay later.
- Pay-later rows show whether they are **paid** or **not paid yet**, and unpaid rows are
  visually distinct (amber tint, as in the user's mockup).
- Money given back and drinks made again appear as small counts above the table, not as
  red cards. One refund on a twenty-order day is normal and should not read as an alarm.
- Footer: order count, cup count, day total.
- Paginated at 25 rows.

### Pay-later status handling (critical)

For `payment_method='paylater'`, `status='Completed'` means **the drinks were made and the
customer still owes money** (`is_open=1`). `status='Paid'` with `is_open=0` means settled.

This distinction has caused three separate money bugs in this codebase. Any query on this
page that branches on `status` must go through `paid_orders_where()` or check `is_open`
explicitly. The tab must never describe an unsettled tab as paid.

---

## Tab 3 — Stock

Tab label carries a badge with the count of items at or below their buy-more level.

Table: item, how much we have, buy-more level, used today, what it costs us. Status pill
(OK / low / buy now) and a proportional bar, as in the reference design.

Filter pills: All / Need buying / OK. Search box over item name.

Three small counts above the table: items we track, items to buy, items that will run out.

Ordered so that items needing attention sort first.

---

## Tab 4 — Staff

Who worked today and what they served.

- Attendance from `attendance` (clocked in, clocked out, hours) for the business date.
- Orders and money from `orders`, joined on `employee_id`.

**Join gotcha:** `orders.employee_id` is a foreign key to `employees.employee_id`, **not** to
`users.user_id`, and the two never coincide. `attendance` keys on `user_id`. The join must go
`attendance.user_id → employees.user_id → employees.employee_id → orders.employee_id`.
Getting this wrong has silently produced empty and wrong staff figures before.

Staff who worked but served nothing show a dash, not a zero-ranked row. No leaderboard,
trophies, or ranking — this is a table of who did what.

---

## Language

### Rule

If a word only exists in a business class, it is banned.

| Banned | Used instead |
|---|---|
| Collected revenue | money we got |
| Profit | money we keep |
| Margin 60% | we keep 60¢ of each $1 |
| COGS / food cost | what the drinks cost us |
| Outstanding | not paid yet |
| PL Settled / PL Open | pay later — paid / pay later — not paid yet |
| Drinks out | cups sold |
| Top product | best seller |
| Stock on hand | stock we have |
| Reorder level | buy more when it drops below this |
| Peak hour | busiest hour |
| Refund | money given back |
| Remake | drink made again |
| Variance | difference |
| Avg order value | each customer spends |

This applies to sentences as well as labels. `$46.50 in open tabs — not counted as collected
revenue until settled` becomes **"$46.50 not paid yet. We count it only when the customer
pays."**

### Khmer

A small Khmer line under the English on the three verdict boxes and the four money cards —
about ten strings total. Nothing else on the page is translated. This is a fixed set of
literals, not a translation system: no language column, no toggle, no locale files.

Strings, **reviewed and corrected by the user 2026-07-26** — use these exactly:

| English | Khmer |
|---|---|
| Did we earn more? | តើយើងរកចំណូលបានច្រើនជាងមុនទេ? |
| Did we keep more? | តើយើងរកប្រាក់ចំណេញបានច្រើនជាងមុនទេ? |
| Can we open tomorrow? | តើយើងអាចបើកហាងស្អែកបានទេ? |
| money we got today | ប្រាក់ចំណូលថ្ងៃនេះ |
| money we keep | ប្រាក់ចំណេញ |
| cash | សាច់ប្រាក់ |
| bakong | បាគង |
| pay later — paid | បង់ក្រោយ — បង់រួច |
| not paid yet | មិនទាន់បង់ |
| stock we have | ស្តុកដែលមាន |

The three verdict questions take the `តើ … ទេ?` interrogative frame. Without `តើ` they read as
statements with a question mark attached, which is what the first draft got wrong.

The page is already UTF-8MB4 throughout, so no encoding work is needed. Khmer text needs a
slightly larger line-height than Latin to avoid clipped diacritics.

---

## Shared code

Three pieces of logic will exist in both `daily_report.php` and `report.php` unless they are
extracted. Put them in `config.php` alongside `paid_orders_where()`:

- **`ingredient_cost_map($conn)`** — the cost-per-unit map from `report.php:90-121`.
- **`business_date_today()`** — currently a private function inside `report.php:15`.
- **`order_cogs($conn, $order_ids)`** — the per-order ingredient cost sum.

`report.php` is then edited to call these instead of its local copies. This is the only
change made to `report.php`. No other refactoring of that file is in scope.

## Errors and empty states

- **No orders yet today** (early morning): the verdict boxes render with `$0.00`, no colour,
  and "no sales yet today". Not an error, not an empty page.
- **No baseline:** covered above — number shown, no verdict, no colour.
- **An ingredient with no cost:** treated as `$0` cost and listed in a small footnote on
  tab 3 so the manager knows the profit figure excludes it. Currently zero ingredients are
  in this state, but a newly added one would be.
- **Failed AJAX tab load:** the tab shows a short message and a retry link. It never shows
  a blank panel.

## Testing

No automated test suite exists in this project; verification is manual and by browser, as
with previous features.

1. Tab 1 figures reconcile against `report.php` for the same day — money we got must equal
   report.php's collected total exactly.
2. A pay-later tab that is made but unpaid appears in "not paid yet", never in "money we
   got", and shows as unpaid on tab 2.
3. Settling that tab moves the money into "money we got" without editing `status` semantics.
4. The weekday baseline picks the right four dates; forcing a thin weekday triggers the
   yesterday fallback with the right label.
5. Box 3 turns red when an ingredient is pushed below `minimum_stock` and green when it is
   restocked.
6. Tab 4 figures match `employees.php` for the same day (guards against the employee/user
   id join bug).
7. `← Yesterday` loads the previous business day and stops the poll.
8. A non-manager role without `report` is redirected.
9. Khmer renders without clipped diacritics in both light and dark themes.

## Risks

- **Baseline confusion.** "A normal Saturday" is a computed average; a judge may ask what it
  means. The label must be self-explanatory and the tooltip should say "average of your last
  4 Saturdays".
- **Cost figures are estimates.** Profit is only as good as the ingredient prices, which were
  set by judgement, not invoices. Do not present them as accounting.
- **Two report pages.** Some duplication between the new tab 3 and `ingredients.php`, and
  between tab 4 and `employees.php`. Accepted: those pages are for managing, this one is for
  reading a day.

## Answering a judge

Two questions are likely, and both are answerable from this spec:

**"How do you know what a normal Saturday is?"** — it is the average of the last four
Saturdays that had sales. The page says which baseline it used, and falls back to yesterday
when a weekday has too little history to average.

**"Where do the profit numbers come from?"** — ingredient prices set per 1 kg / 1 L at
market-typical Cambodian wholesale, with cost per unit derived from them. They are a
directional guide, not audited accounting. Say this plainly rather than letting it be found.

## Open questions

None. Khmer strings were reviewed and corrected on 2026-07-26; the design is ready to plan.
