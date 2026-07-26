<?php
require 'auth.php';
require 'config.php';
if (!can('report')) { header("Location: dashboard.php?denied=1"); exit; }

// Which business day are we reading? Defaults to the one in progress.
$today = business_date_today();
$date  = trim($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = $today; }
if ($date > $today) { $date = $today; }
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
$isToday  = ($date === $today);

// Tabs 2-4 ask for their own HTML. Each branch echoes a fragment and exits.
// This MUST run before any HTML output — a fragment response is inlined into
// a tab panel by JS, so it must never carry the page chrome.
$fragment = $_GET['fragment'] ?? '';
if ($fragment !== '') {
    header('Content-Type: text/html; charset=utf-8');
    switch ($fragment) {
        case 'orders': /* Task 6 */ break;
        case 'stock':  /* Task 7 */ break;
        case 'staff':  /* Task 8 */ break;
        default: http_response_code(404); echo 'Unknown tab.';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daily Report | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0a0a;--surface:#111;--surface2:#161616;--border:rgba(255,255,255,.07);
  --amber:#d1904b;--amber-dim:rgba(209,144,75,.12);--amber-border:rgba(209,144,75,.2);
  --text:#f0f0f0;--muted:#555;--muted2:#888;
  --radius:14px;
}
[data-theme="light"]{
  --bg:#F4F1EC;--surface:#FFFFFF;--surface2:#FAF8F5;--border:rgba(0,0,0,.09);
  --text:#1a1410;--muted:#9a8f84;--muted2:#6b6259;
}
body{
  font-family:'Poppins',sans-serif;
  background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(209,144,75,.07) 0%,transparent 100%),var(--bg);
  color:var(--text);min-height:100vh;
}
[data-theme="light"] body{background:var(--bg);}

.wrap{max-width:1180px;margin:0 auto;padding:24px 20px 60px}

.back-btn{
  display:inline-flex;align-items:center;gap:7px;text-decoration:none;color:var(--amber);
  font-size:13px;font-weight:600;padding:7px 14px;border-radius:10px;
  border:1px solid var(--amber-border);background:var(--amber-dim);transition:all .2s;
  margin-bottom:18px;
}
.back-btn:hover{background:rgba(209,144,75,.2)}

/* ── Header ── */
.dr-head{
  display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px;
  margin-bottom:20px;
}
.dr-eyebrow{font-size:11px;font-weight:600;color:var(--muted2);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:4px}
.dr-head h1{font-size:24px;font-weight:700;color:var(--text)}
.dr-head-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.dr-nav{
  display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:9px;
  background:var(--surface);border:1px solid var(--border);color:var(--text);
  font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;font-family:'Poppins',sans-serif;
  transition:all .2s;
}
.dr-nav:hover{border-color:var(--amber);color:var(--amber)}
.dr-nav.is-disabled{pointer-events:none;opacity:.35}

/* ── Tabs ── */
.dr-tabs{
  display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:20px;
  overflow-x:auto;
}
.dr-tab{
  appearance:none;background:none;border:none;border-bottom:2px solid transparent;
  padding:11px 16px;font-size:13.5px;font-weight:600;color:var(--muted2);
  font-family:'Poppins',sans-serif;cursor:pointer;white-space:nowrap;
  display:inline-flex;align-items:center;gap:7px;
}
.dr-tab:hover{color:var(--text)}
.dr-tab.is-on{color:var(--amber);border-bottom-color:var(--amber)}
.dr-badge{
  display:inline-flex;align-items:center;justify-content:center;min-width:16px;height:16px;
  padding:0 5px;border-radius:20px;background:var(--surface2);border:1px solid var(--border);
  color:var(--muted2);font-size:10px;font-weight:700;
}
.dr-badge:empty{display:none}

/* ── Panels ── */
.dr-panel{animation:fadeInUp .3s ease both}
@keyframes fadeInUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.dr-card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:18px 20px;
}
.dr-loading,.dr-error{
  padding:48px 20px;text-align:center;color:var(--muted2);font-size:13.5px;
}
.dr-error button{
  margin-left:8px;padding:6px 12px;border-radius:8px;border:1px solid var(--amber-border);
  background:var(--amber-dim);color:var(--amber);font-size:12.5px;font-weight:600;
  cursor:pointer;font-family:'Poppins',sans-serif;
}

@media print {
    .dr-tabs, .dr-head-actions, .sidebar, .dr-nav, .back-btn { display: none !important; }
    .dr-panel[hidden] { display: none !important; }
    body { background: #fff !important; color: #000 !important; }
    .dr-card { break-inside: avoid; border: 1px solid #ccc !important; }
}
</style>
</head>
<body>

<div class="wrap">

    <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>

    <div class="dr-head">
        <div>
            <div class="dr-eyebrow">DAILY REPORT</div>
            <h1><?= date('l, F j, Y', strtotime($date)) ?></h1>
        </div>
        <div class="dr-head-actions">
            <a class="dr-nav" href="?date=<?= htmlspecialchars($prevDate) ?>"><i class="fa-solid fa-chevron-left"></i> Yesterday</a>
            <a class="dr-nav <?= $isToday ? 'is-disabled' : '' ?>" href="?date=<?= htmlspecialchars($nextDate) ?>">Tomorrow <i class="fa-solid fa-chevron-right"></i></a>
            <button class="dr-nav" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            <a class="dr-nav" href="report.php?mode=daily&date=<?= htmlspecialchars($date) ?>">Full analytics <i class="fa-solid fa-arrow-right"></i></a>
        </div>
    </div>

    <div class="dr-tabs" role="tablist">
        <button class="dr-tab is-on" data-tab="today"  role="tab">Today</button>
        <button class="dr-tab"       data-tab="orders" role="tab">Orders</button>
        <button class="dr-tab"       data-tab="stock"  role="tab">Stock <span class="dr-badge" id="stockBadge"></span></button>
        <button class="dr-tab"       data-tab="staff"  role="tab">Staff</button>
    </div>
    <div class="dr-panel" id="panel-today"><!-- Task 4 + 5 --></div>
    <div class="dr-panel" id="panel-orders" hidden></div>
    <div class="dr-panel" id="panel-stock"  hidden></div>
    <div class="dr-panel" id="panel-staff"  hidden></div>

</div>

<script>
const drLoaded = {};           // tab -> true once its HTML has arrived
const DR_DATE  = <?= json_encode($date) ?>;

document.querySelectorAll('.dr-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        const tab = btn.dataset.tab;
        document.querySelectorAll('.dr-tab').forEach(b => b.classList.toggle('is-on', b === btn));
        document.querySelectorAll('.dr-panel').forEach(p => p.hidden = (p.id !== 'panel-' + tab));
        if (tab !== 'today' && !drLoaded[tab]) { loadFragment(tab); }
    });
});

async function loadFragment(tab) {
    const panel = document.getElementById('panel-' + tab);
    panel.innerHTML = '<div class="dr-loading">Loading…</div>';
    try {
        const res  = await fetch('daily_report.php?fragment=' + tab + '&date=' + encodeURIComponent(DR_DATE));
        if (!res.ok) throw new Error(res.status);
        panel.innerHTML = await res.text();
        drLoaded[tab] = true;
    } catch (e) {
        // Never leave a blank panel — say what happened and offer the retry.
        panel.innerHTML = '<div class="dr-error">Could not load this tab. '
                        + '<button onclick="loadFragment(\'' + tab + '\')">Try again</button></div>';
    }
}

// follows shared theme key (toggled elsewhere)
window.addEventListener('storage', function (e) {
    if (e.key === 'theme') {
        if (e.newValue === 'light') document.documentElement.setAttribute('data-theme', 'light');
        else document.documentElement.removeAttribute('data-theme');
    }
});
</script>
</body>
</html>
