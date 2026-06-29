<?php
require 'auth.php';
require 'config.php';
if (!can('attendance')) { header("Location: dashboard.php?denied=1"); exit; }

$conn->query("CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    clock_in DATETIME NOT NULL,
    clock_out DATETIME NULL,
    date DATE NOT NULL,
    hours_worked DECIMAL(5,2) NULL
)");

// Auto-close open records from previous days at 23:59:59 of their date
$conn->query("UPDATE attendance SET
    clock_out    = TIMESTAMP(date, '23:59:59'),
    hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, clock_in, TIMESTAMP(date, '23:59:59')) / 60, 2)
WHERE clock_out IS NULL AND date < CURDATE()");

$date = $_GET['date'] ?? date('Y-m-d');
// Clamp to valid date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$stmt = $conn->prepare("SELECT a.*, e.name AS emp_name FROM attendance a LEFT JOIN employees e ON e.user_id = a.user_id WHERE a.date = ? ORDER BY a.clock_in ASC");
$stmt->bind_param('s', $date);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$total_present  = count($records);
$still_working  = count(array_filter($records, fn($r) => is_null($r['clock_out'])));
$done           = $total_present - $still_working;
$total_hours    = array_sum(array_column($records, 'hours_worked'));
$is_today       = $date === date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
:root {
    --accent:       #d1904b;
    --accent-light: #e8b87a;
    --accent-dark:  #a0702a;
    --bg:           #0b0b0b;
    --card:         #111;
    --border:       rgba(255,255,255,0.07);
    --text:         #f5f5f5;
    --text-muted:   #777;
    --success:      #55e087;
    --warning:      #f39c12;
    --danger:       #e74c3c;
}
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }

.topbar {
    position:sticky; top:0; z-index:100;
    background:rgba(10,10,10,.95); border-bottom:1px solid var(--border);
    backdrop-filter:blur(12px);
    display:flex; align-items:center; justify-content:space-between;
    padding:0 28px; height:62px;
}
.topbar-left { display:flex; align-items:center; gap:16px; }
.back-btn {
    display:inline-flex; align-items:center; gap:7px;
    text-decoration:none; color:#d1904b;
    font-size:13px; font-weight:600; padding:7px 14px;
    border-radius:10px; border:1px solid rgba(209,144,75,.35); background:rgba(209,144,75,.08); transition:all .2s;
}
.back-btn:hover { background:rgba(209,144,75,.16); border-color:#d1904b; }
.page-title { font-size:15px; font-weight:700; }
.page-title span { color:var(--accent); }

.page-wrap { max-width:900px; margin:0 auto; padding:32px 20px 60px; }

/* Date nav */
.date-nav {
    display:flex; align-items:center; gap:12px; margin-bottom:24px; flex-wrap:wrap;
}
.date-nav a {
    display:inline-flex; align-items:center; gap:6px;
    padding:7px 14px; border-radius:8px; border:1px solid var(--border);
    background:rgba(255,255,255,.03); color:var(--text-muted);
    text-decoration:none; font-size:13px; transition:all .2s;
}
.date-nav a:hover { color:var(--accent); border-color:rgba(209,144,75,.3); }
.date-input {
    padding:7px 14px; border-radius:8px; border:1px solid var(--border);
    background:rgba(255,255,255,.03); color:var(--text);
    font-size:13px; font-family:'Poppins',sans-serif; outline:none;
    transition:border-color .2s;
}
.date-input:focus { border-color:rgba(209,144,75,.45); }
.date-label {
    font-size:15px; font-weight:700;
    color:<?= $is_today ? 'var(--accent)' : 'var(--text)' ?>;
}

/* Summary cards */
.summary { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
@media(max-width:600px) { .summary { grid-template-columns:1fr 1fr; } }
.scard {
    background:var(--card); border:1px solid var(--border);
    border-radius:14px; padding:18px 20px;
}
.scard-val { font-size:24px; font-weight:800; margin-bottom:4px; }
.scard-lbl { font-size:11px; color:var(--text-muted); font-weight:500; }
.scard.c-accent { border-color:rgba(209,144,75,.2); }
.scard.c-accent .scard-val { color:var(--accent); }
.scard.c-green  { border-color:rgba(85,224,135,.15); }
.scard.c-green  .scard-val { color:var(--success); }
.scard.c-orange { border-color:rgba(243,156,18,.15); }
.scard.c-orange .scard-val { color:var(--warning); }
.scard.c-blue   { border-color:rgba(91,192,222,.15); }
.scard.c-blue   .scard-val { color:#5bc0de; }

/* Table */
.card { background:var(--card); border:1px solid var(--border); border-radius:18px; overflow:hidden; }
.card-header {
    padding:18px 24px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; gap:14px;
}
.card-icon {
    width:38px; height:38px; border-radius:10px; flex-shrink:0;
    background:linear-gradient(135deg,rgba(209,144,75,.2),rgba(209,144,75,.06));
    border:1px solid rgba(209,144,75,.2);
    display:flex; align-items:center; justify-content:center;
    color:var(--accent); font-size:15px;
}
.card-title { font-size:14px; font-weight:700; }
.card-sub   { font-size:12px; color:var(--text-muted); margin-top:2px; }

table { width:100%; border-collapse:collapse; }
thead th {
    padding:11px 20px; text-align:left;
    font-size:10.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.6px; color:var(--text-muted);
    border-bottom:1px solid var(--border);
    background:rgba(255,255,255,.02);
}
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:rgba(255,255,255,.02); }
tbody td { padding:13px 20px; font-size:13px; vertical-align:middle; }

.badge {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600;
}
.badge-working { background:rgba(85,224,135,.1); color:var(--success); border:1px solid rgba(85,224,135,.25); }
.badge-done    { background:rgba(255,255,255,.05); color:var(--text-muted); border:1px solid var(--border); }

.empty-state { padding:48px 20px; text-align:center; color:var(--text-muted); }
.empty-state i { font-size:40px; margin-bottom:14px; opacity:.25; display:block; }
.empty-state p { font-size:13px; }

.live-dot { width:7px; height:7px; border-radius:50%; background:var(--success); display:inline-block; animation:pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── Entrance & motion ─────────────────────────────── */
@keyframes fadeUp    { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
@keyframes slideDown { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }

.topbar   { animation:slideDown .45s cubic-bezier(.22,1,.36,1) both; }
.date-nav { animation:fadeUp .45s cubic-bezier(.22,1,.36,1) both; animation-delay:.08s; }
.card     { animation:fadeUp .45s cubic-bezier(.22,1,.36,1) both; animation-delay:.38s; box-shadow:0 4px 32px rgba(0,0,0,.3); }

.scard {
    animation:fadeUp .4s cubic-bezier(.22,1,.36,1) both;
    transition:transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}
.scard:nth-child(1) { animation-delay:.14s; }
.scard:nth-child(2) { animation-delay:.21s; }
.scard:nth-child(3) { animation-delay:.28s; }
.scard:nth-child(4) { animation-delay:.35s; }

.scard:hover { transform:translateY(-3px); }
.scard.c-accent:hover { box-shadow:0 8px 28px rgba(209,144,75,.18); border-color:rgba(209,144,75,.4); }
.scard.c-green:hover  { box-shadow:0 8px 28px rgba(85,224,135,.14); border-color:rgba(85,224,135,.35); }
.scard.c-blue:hover   { box-shadow:0 8px 28px rgba(91,192,222,.14); border-color:rgba(91,192,222,.35); }
.scard.c-orange:hover { box-shadow:0 8px 28px rgba(243,156,18,.14); border-color:rgba(243,156,18,.35); }

/* Staggered table row entrances */
#attTbody tr { animation:fadeUp .35s cubic-bezier(.22,1,.36,1) both; }
#attTbody tr:nth-child(1)  { animation-delay:.44s; }
#attTbody tr:nth-child(2)  { animation-delay:.48s; }
#attTbody tr:nth-child(3)  { animation-delay:.52s; }
#attTbody tr:nth-child(4)  { animation-delay:.56s; }
#attTbody tr:nth-child(5)  { animation-delay:.60s; }
#attTbody tr:nth-child(6)  { animation-delay:.64s; }
#attTbody tr:nth-child(7)  { animation-delay:.68s; }
#attTbody tr:nth-child(8)  { animation-delay:.72s; }
#attTbody tr:nth-child(9)  { animation-delay:.76s; }
#attTbody tr:nth-child(10) { animation-delay:.80s; }

/* Pagination */
.report-pager {
    display:flex; align-items:center; justify-content:center;
    gap:6px; flex-wrap:wrap; padding:16px 20px 20px;
    border-top:1px solid var(--border);
}
.report-pager .rp-btn {
    min-width:36px; height:36px; padding:0 10px;
    border-radius:9px; border:1px solid var(--border);
    background:rgba(255,255,255,.04); color:var(--text);
    font-size:13px; font-weight:600; cursor:pointer;
    font-family:'Poppins',sans-serif; transition:all .15s;
    display:inline-flex; align-items:center; justify-content:center;
}
.report-pager .rp-btn:hover:not(:disabled):not(.active) { border-color:var(--accent); color:var(--accent); }
.report-pager .rp-btn.active { background:var(--accent); border-color:var(--accent); color:#1a1410; }
.report-pager .rp-btn:disabled { opacity:.35; cursor:not-allowed; }
.report-pager .rp-ellipsis { color:var(--text-muted); padding:0 4px; }
/* Light theme (follows shared localStorage theme) */
[data-theme="light"]{--bg:#ECEEF2;--card:#FFFFFF;--card2:#F5F7FA;--border:#E2E5EA;--text:#111827;--text-muted:#5A6373;}
[data-theme="light"] .topbar{background:rgba(255,255,255,.92);}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <span class="page-title">Staff <span>Attendance</span></span>
        <a href="attendance_history.php" class="back-btn"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
    </div>
    <?php if ($is_today): ?>
    <div id="liveIndicatorWrap" style="display:<?= $still_working > 0 ? 'flex' : 'none' ?>;align-items:center;gap:8px;font-size:13px;color:var(--success)">
        <span class="live-dot"></span> <span id="liveIndicator"><?= $still_working ?> currently working</span>
    </div>
    <?php endif; ?>
</div>

<div class="page-wrap">

    <!-- Date nav -->
    <div class="date-nav">
        <?php
            $prev = date('Y-m-d', strtotime($date . ' -1 day'));
            $next = date('Y-m-d', strtotime($date . ' +1 day'));
        ?>
        <a href="?date=<?= $prev ?>"><i class="fa-solid fa-chevron-left"></i></a>
        <input type="date" class="date-input" value="<?= $date ?>" max="<?= date('Y-m-d') ?>" onchange="location='?date='+this.value">
        <?php if ($date < date('Y-m-d')): ?>
        <a href="?date=<?= $next ?>"><i class="fa-solid fa-chevron-right"></i></a>
        <?php endif; ?>
        <span class="date-label">
            <?= $is_today ? 'Today' : date('d M Y', strtotime($date)) ?>
            <?php if ($is_today): ?><span style="font-size:12px;color:var(--text-muted);font-weight:400">&nbsp;— <?= date('l') ?></span><?php endif; ?>
        </span>
        <?php if (!$is_today): ?>
        <a href="attendance.php"><i class="fa-solid fa-calendar-day"></i> Today</a>
        <?php endif; ?>
    </div>

    <!-- Summary -->
    <div class="summary">
        <div class="scard c-accent">
            <div class="scard-val" id="s-present"><?= $total_present ?></div>
            <div class="scard-lbl"><i class="fa-solid fa-users"></i> Total Present</div>
        </div>
        <div class="scard c-green">
            <div class="scard-val" id="s-working"><?= $still_working ?></div>
            <div class="scard-lbl"><i class="fa-solid fa-circle-dot"></i> Still Working</div>
        </div>
        <div class="scard c-blue">
            <div class="scard-val" id="s-done"><?= $done ?></div>
            <div class="scard-lbl"><i class="fa-solid fa-circle-check"></i> Clocked Out</div>
        </div>
        <div class="scard c-orange">
            <div class="scard-val" id="s-hours"><?= number_format($total_hours, 1) ?>h</div>
            <div class="scard-lbl"><i class="fa-solid fa-clock"></i> Total Hours</div>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon"><i class="fa-solid fa-table-list"></i></div>
            <div>
                <div class="card-title">Attendance Log</div>
                <div class="card-sub"><?= $is_today ? 'Live — updates when staff clock in/out' : date('l, d F Y', strtotime($date)) ?></div>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Clock In</th>
                    <th>Clock Out</th>
                    <th>Hours</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="attTbody">
            <?php if (empty($records)): ?>
            <tr id="emptyRow"><td colspan="5" style="padding:40px;text-align:center;color:var(--text-muted)">
                <i class="fa-solid fa-calendar-xmark" style="display:block;font-size:32px;margin-bottom:12px;opacity:.25"></i>
                No attendance records for <?= $is_today ? 'today' : date('d M Y', strtotime($date)) ?>.
            </td></tr>
            <?php else: ?>
            <?php foreach ($records as $r):
                $name = $r['emp_name'] ?: $r['username'];
                $working = is_null($r['clock_out']);
                $hrs = $working
                    ? round((time() - strtotime($r['clock_in'])) / 3600, 1)
                    : (float)$r['hours_worked'];
            ?>
            <tr>
                <td>
                    <div style="font-weight:600"><?= htmlspecialchars($name) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($r['username']) ?></div>
                </td>
                <td><?= date('g:i A', strtotime($r['clock_in'])) ?></td>
                <td><?= $working ? '<span style="color:var(--text-muted)">—</span>' : date('g:i A', strtotime($r['clock_out'])) ?></td>
                <td>
                    <span style="font-weight:600;color:<?= $working ? 'var(--success)' : 'var(--text)' ?>">
                        <?= $hrs ?>h <?= $working ? '<span style="font-size:11px;font-weight:400;color:var(--text-muted)">(ongoing)</span>' : '' ?>
                    </span>
                </td>
                <td>
                    <?php if ($working && $hrs >= 12): ?>
                    <span class="badge" style="background:rgba(243,156,18,.1);color:#f39c12;border:1px solid rgba(243,156,18,.3)">
                        <i class="fa-solid fa-triangle-exclamation"></i> Forgot to clock out?
                    </span>
                    <?php elseif ($working): ?>
                    <span class="badge badge-working"><span class="live-dot" style="width:6px;height:6px"></span> Working</span>
                    <?php else: ?>
                    <span class="badge badge-done"><i class="fa-solid fa-circle-check"></i> Done</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <div class="report-pager" id="attPager"></div>
    </div>

</div>

<!-- Pagination (client-side; survives live-poll re-render) -->
<script>
const __attPager = { pageSize: 10, page: 1 };
function attRenderPage() {
    const tbody = document.getElementById('attTbody');
    const pager = document.getElementById('attPager');
    if (!tbody || !pager) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    // empty-state placeholder → no pager
    if (rows.length === 0 || tbody.querySelector('td[colspan]')) {
        rows.forEach(r => r.style.display = '');
        pager.innerHTML = '';
        return;
    }
    const st = __attPager;
    const pages = Math.max(1, Math.ceil(rows.length / st.pageSize));
    if (st.page > pages) st.page = pages;
    const start = (st.page - 1) * st.pageSize, end = start + st.pageSize;
    rows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });
    attBuildControls(pages);
}
function attBuildControls(pages) {
    const st = __attPager;
    const pager = document.getElementById('attPager');
    if (pages <= 1) { pager.innerHTML = ''; return; }
    const p = st.page;
    const btn = (label, target, o = {}) => o.ellipsis
        ? '<span class="rp-ellipsis">…</span>'
        : `<button class="rp-btn${o.active ? ' active' : ''}" ${o.disabled ? 'disabled' : ''} data-go="${target}" aria-label="${o.aria || ('Page ' + label)}">${label}</button>`;
    let html = btn('«', 1, { disabled: p === 1, aria: 'First page' }) + btn('‹', p - 1, { disabled: p === 1, aria: 'Previous page' });
    const win = [];
    if (pages <= 7) { for (let i = 1; i <= pages; i++) win.push(i); }
    else {
        win.push(1);
        let s = Math.max(2, p - 1), e = Math.min(pages - 1, p + 1);
        if (s > 2) win.push('...');
        for (let i = s; i <= e; i++) win.push(i);
        if (e < pages - 1) win.push('...');
        win.push(pages);
    }
    win.forEach(n => { html += n === '...' ? btn('', 0, { ellipsis: true }) : btn(n, n, { active: n === p }); });
    html += btn('›', p + 1, { disabled: p === pages, aria: 'Next page' }) + btn('»', pages, { disabled: p === pages, aria: 'Last page' });
    pager.innerHTML = html;
    pager.querySelectorAll('button[data-go]').forEach(b => {
        b.addEventListener('click', () => {
            const g = parseInt(b.dataset.go, 10);
            if (g >= 1 && g <= pages) { st.page = g; attRenderPage(); }
        });
    });
}
attRenderPage();
</script>

<?php if ($is_today): ?>
<script>
var POLL_DATE = '<?= $date ?>';

function renderRow(r) {
    var hoursHtml = r.working
        ? '<span style="font-weight:600;color:#55e087">' + r.hours_display + 'h <span style="font-size:11px;font-weight:400;color:#777">(ongoing)</span></span>'
        : '<span style="font-weight:600">' + r.hours_display + 'h</span>';
    var longShift  = r.working && r.hours_display >= 12;
    var statusHtml = r.working
        ? (longShift
            ? '<span class="badge" style="background:rgba(243,156,18,.1);color:#f39c12;border:1px solid rgba(243,156,18,.3)"><i class="fa-solid fa-triangle-exclamation"></i> Forgot to clock out?</span>'
            : '<span class="badge badge-working"><span class="live-dot" style="width:6px;height:6px"></span> Working</span>')
        : '<span class="badge badge-done"><i class="fa-solid fa-circle-check"></i> Done</span>';
    return '<tr>' +
        '<td><div style="font-weight:600">' + escHtml(r.display_name) + '</div>' +
             '<div style="font-size:11px;color:#777">' + escHtml(r.username) + '</div></td>' +
        '<td>' + r.clock_in_fmt + '</td>' +
        '<td>' + (r.clock_out_fmt || '<span style="color:#777">—</span>') + '</td>' +
        '<td>' + hoursHtml + '</td>' +
        '<td>' + statusHtml + '</td>' +
        '</tr>';
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function renderEmpty() {
    return '<tr id="emptyRow"><td colspan="5" style="padding:40px;text-align:center;color:#777">' +
        '<i class="fa-solid fa-calendar-xmark" style="display:block;font-size:32px;margin-bottom:12px;opacity:.25"></i>' +
        'No attendance records for today.</td></tr>';
}

async function pollAttendance() {
    try {
        var resp = await fetch('attendance_action.php?action=data&date=' + POLL_DATE);
        var data = await resp.json();
        if (!data.ok) return;

        // Update summary cards
        document.getElementById('s-present').textContent = data.total_present;
        document.getElementById('s-working').textContent = data.still_working;
        document.getElementById('s-done').textContent    = data.done;
        document.getElementById('s-hours').textContent   = data.total_hours + 'h';

        // Update topbar live indicator (always present in DOM, toggle visibility)
        var liveWrap = document.getElementById('liveIndicatorWrap');
        var liveEl   = document.getElementById('liveIndicator');
        if (liveWrap) liveWrap.style.display = data.still_working > 0 ? 'flex' : 'none';
        if (liveEl)   liveEl.textContent = data.still_working + ' currently working';

        // Re-render tbody (always present in DOM now)
        var tbody = document.getElementById('attTbody');
        if (!tbody) return;
        tbody.innerHTML = data.records.length === 0
            ? renderEmpty()
            : data.records.map(renderRow).join('');
        if (typeof attRenderPage === 'function') attRenderPage();
    } catch(e) { /* silent fail */ }
}

pollAttendance();
setInterval(pollAttendance, 10000);
</script>
<?php endif; ?>
</body>
</html>
