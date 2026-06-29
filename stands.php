<?php
require 'auth.php';

// Front-of-house only (matches update_table.php's gate).
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'staff'])) {
    header("Location: dashboard.php?denied=1");
    exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// ── AJAX: release a stand (placard returned) ──
// Clears the stand from any non-cancelled order today, freeing it for reuse.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'release') {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid session token']);
        exit;
    }
    $stand = trim($_POST['stand'] ?? '');
    if ($stand === '') { echo json_encode(['ok' => false, 'error' => 'No stand']); exit; }
    $st = $conn->prepare("UPDATE orders SET table_number = NULL
        WHERE UPPER(table_number) = UPPER(?)
          AND status NOT IN ('Cancelled','Refunded','Void')
          AND business_date = CURDATE()");
    $st->bind_param("s", $stand);
    $st->execute();
    echo json_encode(['ok' => true, 'freed' => $conn->affected_rows]);
    exit;
}

// ── Initial occupancy (token-driven: held until released, today only) ──
$occ = [];
$res = $conn->query("SELECT table_number, daily_order_no, customer_name, status
    FROM orders
    WHERE status NOT IN ('Cancelled','Refunded','Void')
      AND business_date = CURDATE()
      AND table_number IS NOT NULL AND table_number != ''
    ORDER BY order_date DESC");
while ($row = $res->fetch_assoc()) {
    $k = trim($row['table_number']);
    if ($k !== '' && !isset($occ[$k])) $occ[$k] = $row;
}
$STAND_MAX = STAND_COUNT;
// Any occupied stand outside the 1..20 grid (free-form numbers).
// Note: PHP coerces numeric string array keys to int, so test with is_numeric
// (ctype_digit on an int reads it as an ASCII codepoint — wrong).
$extra = [];
foreach ($occ as $k => $v) {
    $n = is_numeric($k) ? (int)$k : -1;
    if ($n < 1 || $n > $STAND_MAX) $extra[$k] = $v;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stand Numbers | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}})();</script>
<style>
:root{
    --bg:#0a0a0a; --card:#131313; --card2:#1a1a1a; --border:#222;
    --text:#f0f0f0; --muted:#888; --accent:#d1904b;
    --free:#55e087; --free-dim:rgba(85,224,135,.12);
    --busy:#ff6b6b; --busy-dim:rgba(255,107,107,.10);
    --r:14px; --transition:all .2s ease;
}
[data-theme="light"]{
    --bg:#ECEEF2; --card:#FFFFFF; --card2:#F5F7FA; --border:#E2E5EA;
    --text:#111827; --muted:#5A6373;
    --free-dim:rgba(34,197,94,.12); --busy-dim:rgba(239,68,68,.08);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding:24px}
.page{max-width:960px;margin:0 auto}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px;flex-wrap:wrap}
.title{display:flex;align-items:center;gap:12px}
.title h1{font-size:22px;font-weight:700}
.title i{color:var(--accent);font-size:20px}
.sub{color:var(--muted);font-size:13px;margin:2px 0 22px}
.back{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:50px;background:var(--card);border:1px solid var(--border);color:var(--accent);text-decoration:none;font-size:13px;font-weight:600;transition:var(--transition)}
.back:hover{filter:brightness(1.1)}
.legend{display:flex;gap:16px;align-items:center;font-size:12px;color:var(--muted);margin-bottom:16px}
.dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle}
.dot.free{background:var(--free)} .dot.busy{background:var(--busy)}
.grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px}
@media(max-width:640px){.grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:400px){.grid{grid-template-columns:repeat(2,1fr)}}
.stand{position:relative;background:var(--card);border:1.5px solid var(--border);border-radius:var(--r);padding:16px 12px;text-align:center;min-height:118px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;transition:var(--transition)}
.stand.free{border-color:rgba(85,224,135,.35);background:var(--free-dim)}
.stand.busy{border-color:rgba(255,107,107,.4);background:var(--busy-dim)}
.stand .num{font-size:26px;font-weight:800;line-height:1}
.stand.free .num{color:var(--free)} .stand.busy .num{color:var(--busy)}
.stand .state{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.stand.free .state{color:var(--free)} .stand.busy .state{color:var(--busy)}
.stand .meta{font-size:10.5px;color:var(--muted);line-height:1.3}
.stand .meta b{color:var(--text);font-weight:600}
.release{margin-top:4px;padding:5px 12px;border-radius:50px;border:1px solid rgba(255,107,107,.35);background:rgba(255,107,107,.12);color:var(--busy);font-size:10.5px;font-weight:700;cursor:pointer;transition:var(--transition);font-family:inherit;display:inline-flex;align-items:center;gap:5px}
.release:hover{background:var(--busy);color:#fff}
.extra{margin-top:22px}
.extra h3{font-size:13px;color:var(--muted);margin-bottom:10px;font-weight:600}
.extra-chip{display:inline-flex;align-items:center;gap:10px;background:var(--busy-dim);border:1px solid rgba(255,107,107,.3);border-radius:50px;padding:7px 8px 7px 16px;margin:0 8px 8px 0}
.extra-chip .k{font-weight:700;color:var(--busy)}
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--card);border:1px solid var(--border);border-radius:10px;padding:12px 22px;font-size:13px;font-weight:600;opacity:0;transition:all .3s;pointer-events:none;z-index:99;box-shadow:0 8px 30px rgba(0,0,0,.3)}
#toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
#toast.ok{color:var(--free)} #toast.err{color:var(--busy)}
.live{display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--free);font-weight:600}
.live .pulse{width:7px;height:7px;border-radius:50%;background:var(--free);animation:pulse 1.6s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
</style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div class="title">
            <i class="fa-solid fa-table-cells-large"></i>
            <div>
                <h1>Stand Numbers</h1>
            </div>
        </div>
        <div style="display:flex;gap:12px;align-items:center">
            <span class="live"><span class="pulse"></span> Live</span>
            <a href="dashboard.php" class="back"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>
    <p class="sub">Tap <b>Release</b> when a customer returns the metal number. A released stand becomes free for the next order.</p>
    <div class="legend">
        <span><span class="dot free"></span> Free</span>
        <span><span class="dot busy"></span> In use</span>
    </div>

    <div class="grid" id="grid">
        <?php for ($i = 1; $i <= $STAND_MAX; $i++):
            $o = $occ[(string)$i] ?? null; ?>
        <div class="stand <?= $o ? 'busy' : 'free' ?>" id="stand-<?= $i ?>" data-stand="<?= $i ?>">
            <div class="num"><?= $i ?></div>
            <?php if ($o): ?>
            <div class="state">In Use</div>
            <div class="meta">#<?= (int)$o['daily_order_no'] ?><?= $o['customer_name'] ? ' &middot; <b>'.h($o['customer_name']).'</b>' : '' ?></div>
            <button class="release" data-stand="<?= $i ?>"><i class="fa-solid fa-rotate-left"></i> Release</button>
            <?php else: ?>
            <div class="state">Free</div>
            <?php endif; ?>
        </div>
        <?php endfor; ?>
    </div>

    <?php if ($extra): ?>
    <div class="extra" id="extraWrap">
        <h3>Other stand numbers in use</h3>
        <?php foreach ($extra as $k => $v): ?>
        <span class="extra-chip" id="extra-<?= h($k) ?>">
            <span class="k"><?= h($k) ?></span>
            <span class="meta">#<?= (int)$v['daily_order_no'] ?><?= $v['customer_name'] ? ' &middot; '.h($v['customer_name']) : '' ?></span>
            <button class="release" data-stand="<?= h($k) ?>"><i class="fa-solid fa-rotate-left"></i> Release</button>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div id="toast"></div>

<script>
const STAND_MAX = <?= $STAND_MAX ?>;
const CSRF = <?= json_encode($_SESSION['csrf_token'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

// Delegated binding — works for server-rendered and live-repainted buttons,
// and avoids interpolating stand values into inline JS.
document.addEventListener('click', function(e){
    const b = e.target.closest('.release');
    if (b && b.dataset.stand) releaseStand(b.dataset.stand);
});

function showToast(msg, type){
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'show ' + (type||'ok');
    clearTimeout(t._t); t._t = setTimeout(()=>{ t.className=''; }, 2600);
}

async function releaseStand(stand){
    const body = new URLSearchParams({ action:'release', stand: stand, csrf_token: CSRF });
    try{
        const r = await fetch('stands.php', { method:'POST', body });
        const j = await r.json();
        if (j.ok){ showToast('Stand ' + stand + ' freed', 'ok'); refresh(); }
        else showToast(j.error || 'Could not release', 'err');
    }catch(e){ showToast('Network error', 'err'); }
}

// Paint a 1..20 cell based on occupancy data
function paintCell(i, info){
    const cell = document.getElementById('stand-' + i);
    if (!cell) return;
    if (info){
        cell.className = 'stand busy';
        const cust = info.customer ? ' &middot; <b>' + escapeHtml(info.customer) + '</b>' : '';
        cell.innerHTML = '<div class="num">' + i + '</div>'
            + '<div class="state">In Use</div>'
            + '<div class="meta">#' + (info.order_no || '') + cust + '</div>'
            + '<button class="release" data-stand="' + i + '"><i class="fa-solid fa-rotate-left"></i> Release</button>';
    } else {
        cell.className = 'stand free';
        cell.innerHTML = '<div class="num">' + i + '</div><div class="state">Free</div>';
    }
}
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function refresh(){
    try{
        const r = await fetch('get_stands.php', { cache:'no-store' });
        const j = await r.json();
        const stands = j.stands || {};
        for (let i = 1; i <= STAND_MAX; i++){
            paintCell(i, stands[String(i)] || null);
        }
        // Drop any released extra chips
        document.querySelectorAll('.extra-chip').forEach(chip => {
            const key = chip.id.replace('extra-','');
            if (!stands[key]) chip.remove();
        });
    }catch(e){}
}

setInterval(refresh, 5000);
</script>
</body>
</html>
