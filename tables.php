<?php
require 'auth.php';
if (!can('tables')) { header("Location: dashboard.php?denied=1"); exit; }

// JSON poll endpoint
if (isset($_GET['json'])) {
    $res = $conn->query("SELECT table_id, table_number, capacity, status FROM cafe_tables ORDER BY table_number");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $num = strtoupper(trim($_POST['table_number'] ?? ''));
        $cap = max(1, (int)($_POST['capacity'] ?? 4));
        if ($num !== '') {
            $s = $conn->prepare("INSERT IGNORE INTO cafe_tables (table_number, capacity) VALUES (?, ?)");
            $s->bind_param("si", $num, $cap);
            $s->execute();
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['table_id'] ?? 0);
        $conn->query("UPDATE cafe_tables SET status = IF(status='available','occupied','available') WHERE table_id = $id");
    } elseif ($action === 'free_all') {
        $conn->query("UPDATE cafe_tables SET status = 'available'");
    } elseif ($action === 'delete') {
        $id = (int)($_POST['table_id'] ?? 0);
        $conn->query("DELETE FROM cafe_tables WHERE table_id = $id");
    }

    header("Location: tables.php");
    exit;
}

$tables_res = $conn->query("SELECT * FROM cafe_tables ORDER BY table_number");
$tables = [];
while ($t = $tables_res->fetch_assoc()) $tables[] = $t;

$occupied = count(array_filter($tables, fn($t) => $t['status'] === 'occupied'));
$available = count($tables) - $occupied;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tables | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #0b0b0b; color: #f0f0f0; min-height: 100vh; overflow-x: hidden; }

/* ── Keyframes ── */
@keyframes fadeUp   { from { opacity:0; transform:translateY(22px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeIn   { from { opacity:0; } to { opacity:1; } }
@keyframes pulseRed { 0%,100% { box-shadow:0 0 0 0 rgba(231,76,60,.45); } 60% { box-shadow:0 0 0 8px rgba(231,76,60,0); } }
@keyframes dotPulse { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:.5; transform:scale(1.6); } }
@keyframes spinOnce { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
@keyframes shimmer  { 0% { background-position:-200% 0; } 100% { background-position:200% 0; } }
@keyframes flash    { 0%,100% { opacity:1; } 40% { opacity:.35; } }
@keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }

/* ── Topnav ── */
.topnav {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 24px;
    background: rgba(10,10,10,.96);
    border-bottom: 1px solid #1f1f1f;
    position: sticky; top: 0; z-index: 100;
    backdrop-filter: blur(16px);
    animation: fadeIn .4s ease both;
}
.back-btn {
    display: flex; align-items: center; gap: 7px;
    color: #d1904b; text-decoration: none;
    font-weight: 600; font-size: 13px;
    padding: 7px 14px; border-radius: 10px;
    background: #111; border: 1px solid #2a2a2a;
    transition: all .25s;
}
.back-btn:hover { background: #1a1a1a; border-color: #d1904b; transform: translateX(-3px); }
.back-btn i { transition: transform .25s; }
.back-btn:hover i { transform: translateX(-2px); }
.topnav h1 { font-size: 16px; font-weight: 700; color: #f0f0f0; }
.topnav-icon { color: #d1904b; margin-right: 6px; transition: transform .6s; }
.topnav:hover .topnav-icon { transform: rotate(90deg); }

/* ── Live indicator ── */
.live-badge {
    margin-left: auto; display: flex; align-items: center; gap: 6px;
    font-size: 11px; color: #888; font-weight: 500;
}
.live-dot {
    width: 7px; height: 7px; border-radius: 50%; background: #3ecf70;
    animation: dotPulse 2s ease-in-out infinite;
}

/* ── Page ── */
.page { max-width: 960px; margin: 0 auto; padding: 28px 20px 60px; }

/* ── Stats ── */
.stats { display: flex; gap: 14px; margin-bottom: 32px; }
.stat-card {
    flex: 1; background: #121212; border: 1px solid #1f1f1f;
    border-radius: 16px; padding: 18px 20px;
    display: flex; align-items: center; gap: 14px;
    opacity: 0; animation: fadeUp .5s ease both;
    transition: transform .25s, box-shadow .25s;
    overflow: hidden; position: relative;
}
.stat-card::after {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(105deg, transparent 40%, rgba(255,255,255,.03) 50%, transparent 60%);
    background-size: 200% 100%;
    animation: shimmer 3.5s linear infinite;
}
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,.4); }
.stat-card:nth-child(1) { animation-delay: .05s; }
.stat-card:nth-child(2) { animation-delay: .12s; }
.stat-card:nth-child(3) { animation-delay: .19s; }

.stat-icon { width: 46px; height: 46px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 19px; flex-shrink: 0; transition: transform .3s; }
.stat-card:hover .stat-icon { transform: scale(1.12) rotate(-6deg); }
.stat-icon.green  { background: rgba(62,207,112,.13); color: #3ecf70; box-shadow: 0 0 16px rgba(62,207,112,.12); }
.stat-icon.orange { background: rgba(209,144,75,.13);  color: #d1904b; box-shadow: 0 0 16px rgba(209,144,75,.12); }
.stat-icon.blue   { background: rgba(93,173,226,.13);  color: #5dade2; box-shadow: 0 0 16px rgba(93,173,226,.12); }
.stat-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: .06em; }
.stat-value { font-size: 28px; font-weight: 800; color: #f0f0f0; line-height: 1.1; transition: color .4s; }

/* ── Section head ── */
.section-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 16px;
    opacity: 0; animation: fadeUp .5s .28s ease both;
}
.section-head h2 { font-size: 15px; font-weight: 600; color: #f0f0f0; }
.btn-free-all {
    background: rgba(62,207,112,.1); color: #3ecf70;
    border: 1px solid rgba(62,207,112,.25);
    padding: 7px 16px; border-radius: 9px;
    font-size: 12px; font-weight: 600; cursor: pointer;
    font-family: 'Poppins', sans-serif; transition: all .25s;
    display: flex; align-items: center; gap: 6px;
    animation: slideDown .3s ease both;
}
.btn-free-all:hover { background: rgba(62,207,112,.22); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(62,207,112,.15); }
.btn-free-all i { transition: transform .4s; }
.btn-free-all:hover i { transform: rotate(360deg); }

/* ── Tables grid ── */
.tables-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(162px, 1fr));
    gap: 14px;
    margin-bottom: 36px;
}
.table-card {
    background: #121212; border: 1px solid #222;
    border-radius: 16px; padding: 20px 16px 16px;
    text-align: center; position: relative;
    transition: transform .25s, box-shadow .25s, border-color .4s, background .4s;
    opacity: 0; animation: fadeUp .45s ease both;
    cursor: default;
}
.table-card:hover { transform: translateY(-5px); box-shadow: 0 12px 32px rgba(0,0,0,.45); }
.table-card.available { border-color: rgba(62,207,112,.28); }
.table-card.available:hover { box-shadow: 0 12px 32px rgba(62,207,112,.08); }
.table-card.occupied  { border-color: rgba(231,76,60,.45); background: rgba(231,76,60,.04); animation: fadeUp .45s ease both, pulseRed 2.4s ease-in-out infinite; }
.table-card.occupied:hover { box-shadow: 0 12px 32px rgba(231,76,60,.15); }

/* stagger each card */
.table-card:nth-child(1)  { animation-delay:.08s,.08s }
.table-card:nth-child(2)  { animation-delay:.13s,.13s }
.table-card:nth-child(3)  { animation-delay:.18s,.18s }
.table-card:nth-child(4)  { animation-delay:.23s,.23s }
.table-card:nth-child(5)  { animation-delay:.28s,.28s }
.table-card:nth-child(6)  { animation-delay:.33s,.33s }
.table-card:nth-child(7)  { animation-delay:.38s,.38s }
.table-card:nth-child(8)  { animation-delay:.43s,.43s }
.table-card:nth-child(n+9){ animation-delay:.48s,.48s }

.table-icon { font-size: 26px; margin-bottom: 6px; transition: transform .35s; }
.table-card:hover .table-icon { transform: scale(1.18) rotate(-8deg); }
.table-card.available .table-icon { color: #3ecf70; }
.table-card.occupied  .table-icon { color: #ff6b6b; }

.table-number { font-size: 22px; font-weight: 800; color: #f0f0f0; margin-bottom: 3px; letter-spacing: .02em; }
.table-cap { font-size: 11px; color: #666; margin-bottom: 11px; }

.table-status {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600; padding: 4px 10px;
    border-radius: 20px; margin-bottom: 14px;
    transition: background .4s, color .4s;
}
.table-status.available { background: rgba(62,207,112,.12); color: #3ecf70; }
.table-status.occupied  { background: rgba(231,76,60,.14);  color: #ff6b6b; }
.status-dot {
    width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0;
    transition: background .4s;
}
.table-status.available .status-dot { background: #3ecf70; animation: dotPulse 2.2s ease-in-out infinite; }
.table-status.occupied  .status-dot { background: #ff6b6b; animation: dotPulse 1.4s ease-in-out infinite; }

.table-actions { display: flex; gap: 6px; justify-content: center; }
.btn-toggle {
    flex: 1; padding: 7px 0; border-radius: 8px; border: none;
    font-size: 11px; font-weight: 600; cursor: pointer;
    font-family: 'Poppins', sans-serif; transition: all .22s;
    letter-spacing: .02em;
}
.btn-toggle:active { transform: scale(.95); }
.btn-toggle.free   { background: rgba(62,207,112,.15); color: #3ecf70; }
.btn-toggle.free:hover   { background: rgba(62,207,112,.3); box-shadow: 0 3px 10px rgba(62,207,112,.18); }
.btn-toggle.occupy { background: rgba(231,76,60,.15); color: #ff6b6b; }
.btn-toggle.occupy:hover { background: rgba(231,76,60,.3); box-shadow: 0 3px 10px rgba(231,76,60,.18); }
.btn-delete {
    width: 31px; height: 31px; border-radius: 8px;
    background: rgba(255,77,77,.07); color: #ff4d4d;
    border: 1px solid rgba(255,77,77,.16); cursor: pointer;
    font-size: 11px; display: flex; align-items: center; justify-content: center;
    transition: all .22s; flex-shrink: 0;
}
.btn-delete:hover { background: #ff4d4d; color: #fff; border-color: #ff4d4d; transform: scale(1.08); }
.btn-delete:active { transform: scale(.93); }

/* ── Add card ── */
.add-card {
    background: #111; border: 1px dashed #252525;
    border-radius: 16px; padding: 24px 22px;
    opacity: 0; animation: fadeUp .5s .5s ease both;
    transition: border-color .3s;
}
.add-card:hover { border-color: #d1904b44; }
.add-card h3 { font-size: 14px; font-weight: 600; margin-bottom: 16px; color: #d1904b; display: flex; align-items: center; gap: 8px; }
.add-card h3 i { transition: transform .4s; }
.add-card:hover h3 i { transform: rotate(90deg); }
.add-form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.add-field { display: flex; flex-direction: column; gap: 5px; }
.add-field label { font-size: 11px; color: #777; font-weight: 500; }
.add-field input {
    padding: 9px 12px; border-radius: 9px;
    border: 1px solid #242424; background: #0d0d0d;
    color: #f0f0f0; font-family: 'Poppins', sans-serif;
    font-size: 13px; outline: none; transition: border-color .22s, box-shadow .22s;
}
.add-field input:focus { border-color: #d1904b; box-shadow: 0 0 0 3px rgba(209,144,75,.1); }
.add-field input[name="table_number"] { width: 110px; }
.add-field input[name="capacity"]     { width: 90px; }
.btn-add {
    padding: 9px 20px; background: linear-gradient(135deg,#d1904b,#e8a85d); color: #000;
    border: none; border-radius: 9px; font-size: 13px;
    font-weight: 700; cursor: pointer; font-family: 'Poppins', sans-serif;
    transition: all .22s; display: flex; align-items: center; gap: 6px;
    white-space: nowrap; box-shadow: 0 3px 12px rgba(209,144,75,.25);
}
.btn-add:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(209,144,75,.38); filter: brightness(1.07); }
.btn-add:active { transform: scale(.97); }

/* ── Status-change flash (applied by JS) ── */
.card-flash { animation: flash .5s ease !important; }
</style>
</head>
<body>

<nav class="topnav">
    <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
    <h1><i class="fa-solid fa-table-cells topnav-icon"></i> Table Management</h1>
    <div class="live-badge"><div class="live-dot"></div> Live</div>
</nav>

<div class="page">

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-table-cells"></i></div>
            <div>
                <div class="stat-label">Total Tables</div>
                <div class="stat-value"><?= count($tables) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="stat-label">Available</div>
                <div class="stat-value"><?= $available ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="stat-label">Occupied</div>
                <div class="stat-value"><?= $occupied ?></div>
            </div>
        </div>
    </div>

    <!-- Tables grid -->
    <div class="section-head">
        <h2>All Tables</h2>
        <?php if ($occupied > 0): ?>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="free_all">
            <button type="submit" class="btn-free-all" onclick="return confirm('Mark all tables as available?')">
                <i class="fa-solid fa-circle-check"></i> Free All Tables
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (empty($tables)): ?>
    <p style="color:#888;font-size:13px;margin-bottom:28px;">No tables yet. Add one below.</p>
    <?php else: ?>
    <div class="tables-grid">
        <?php foreach ($tables as $t): ?>
        <div class="table-card <?= $t['status'] ?>" data-id="<?= (int)$t['table_id'] ?>">
            <div class="table-icon"><i class="fa-solid <?= $t['status'] === 'occupied' ? 'fa-users' : 'fa-chair' ?>"></i></div>
            <div class="table-number"><?= htmlspecialchars($t['table_number']) ?></div>
            <div class="table-cap"><?= (int)$t['capacity'] ?> seats</div>
            <div class="table-status <?= $t['status'] ?>">
                <span class="status-dot"></span>
                <?= ucfirst($t['status']) ?>
            </div>
            <div class="table-actions">
                <form method="POST" style="flex:1;display:flex;">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="table_id" value="<?= (int)$t['table_id'] ?>">
                    <button type="submit" class="btn-toggle <?= $t['status'] === 'available' ? 'occupy' : 'free' ?>">
                        <?= $t['status'] === 'available' ? 'Occupy' : 'Free' ?>
                    </button>
                </form>
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="table_id" value="<?= (int)$t['table_id'] ?>">
                    <button type="submit" class="btn-delete" onclick="return confirm('Delete <?= htmlspecialchars($t['table_number']) ?>?')" title="Delete">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Add table form -->
    <div class="add-card">
        <h3><i class="fa-solid fa-plus"></i> Add New Table</h3>
        <form method="POST" class="add-form">
            <input type="hidden" name="action" value="add">
            <div class="add-field">
                <label>Table Number</label>
                <input type="text" name="table_number" placeholder="e.g. T8, VIP2" maxlength="10" required>
            </div>
            <div class="add-field">
                <label>Capacity (seats)</label>
                <input type="number" name="capacity" min="1" max="50" value="4">
            </div>
            <button type="submit" class="btn-add"><i class="fa-solid fa-plus"></i> Add Table</button>
        </form>
    </div>

</div>

<script>
(function() {
  function poll() {
    fetch('tables.php?json=1')
      .then(function(r) { return r.json(); })
      .then(function(tables) {
        var occupied = 0, available = 0;
        tables.forEach(function(t) {
          var card = document.querySelector('.table-card[data-id="' + t.table_id + '"]');
          if (!card) return;
          var wasOccupied = card.classList.contains('occupied');
          var isOccupied  = t.status === 'occupied';
          if (wasOccupied === isOccupied) { isOccupied ? occupied++ : available++; return; }
          // Status changed — flash + update card
          card.classList.add('card-flash');
          card.addEventListener('animationend', function h() { card.classList.remove('card-flash'); card.removeEventListener('animationend',h); });
          card.className = 'table-card ' + t.status;
          var icon = card.querySelector('.table-icon i');
          if (icon) { icon.className = 'fa-solid ' + (isOccupied ? 'fa-users' : 'fa-chair'); }
          var badge = card.querySelector('.table-status');
          badge.className = 'table-status ' + t.status;
          badge.innerHTML = '<span class="status-dot"></span> ' + (isOccupied ? 'Occupied' : 'Available');
          var btn = card.querySelector('.btn-toggle');
          btn.className = 'btn-toggle ' + (isOccupied ? 'free' : 'occupy');
          btn.textContent = isOccupied ? 'Free' : 'Occupy';
          isOccupied ? occupied++ : available++;
        });
        // Update stats
        document.querySelector('.stat-icon.green + div .stat-value').textContent = available;
        document.querySelector('.stat-icon.orange + div .stat-value').textContent = occupied;
        // Show/hide Free All button
        var freeAllForm = document.querySelector('.btn-free-all') ? document.querySelector('.btn-free-all').closest('form') : null;
        var sectionHead = document.querySelector('.section-head');
        if (occupied > 0 && !freeAllForm) {
          var f = document.createElement('form');
          f.method = 'POST'; f.style.display = 'inline';
          f.innerHTML = '<input type="hidden" name="action" value="free_all">' +
            '<button type="submit" class="btn-free-all" onclick="return confirm(\'Mark all tables as available?\')">' +
            '<i class="fa-solid fa-circle-check"></i> Free All Tables</button>';
          sectionHead.appendChild(f);
        } else if (occupied === 0 && freeAllForm) {
          freeAllForm.remove();
        }
      })
      .catch(function() {});
  }
  setInterval(poll, 5000);

  // Stat counter animation on load
  document.querySelectorAll('.stat-value').forEach(function(el) {
    var target = parseInt(el.textContent, 10);
    if (isNaN(target) || target === 0) return;
    var start = 0, dur = 700, startTime = null;
    function step(ts) {
      if (!startTime) startTime = ts;
      var p = Math.min((ts - startTime) / dur, 1);
      var ease = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(ease * target);
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  });
})();
</script>
</body>
</html>
