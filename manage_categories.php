<?php
require 'admin_only.php';   // admin/manager only; pulls in config.php ($conn)

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function cat_slug(string $name): string { return preg_replace('/\s+/', ' ', trim($name)); }
function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$flash = null;

// ── POST action router (CSRF-guarded). Cases added in later tasks. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please retry.'];
    } else {
        switch ($_POST['action'] ?? '') {
            // create / update / toggle / delete / reorder added in later tasks
            case 'create': {
                $name = trim((string)($_POST['name'] ?? ''));
                $icon = trim((string)($_POST['icon'] ?? '')) ?: 'fa-circle';
                $active = isset($_POST['is_active']) ? 1 : 0;
                $slug = cat_slug($name);
                if ($slug === '') { $flash = ['type'=>'error','msg'=>'Category name is required.']; break; }
                $dup = $conn->prepare("SELECT category_id FROM categories WHERE LOWER(slug) = LOWER(?) LIMIT 1");
                $dup->bind_param('s', $slug); $dup->execute();
                if ($dup->get_result()->fetch_assoc()) { $flash = ['type'=>'error','msg'=>"A category named \"$slug\" already exists."]; break; }
                $ord = (int)$conn->query("SELECT COALESCE(MAX(display_order),0)+1 AS n FROM categories")->fetch_assoc()['n'];
                $ins = $conn->prepare("INSERT INTO categories (slug, name, icon, display_order, is_active) VALUES (?, ?, ?, ?, ?)");
                $ins->bind_param('sssii', $slug, $name, $icon, $ord, $active);
                $ins->execute();
                $flash = ['type'=>'success','msg'=>"Category \"$slug\" added."];
                break;
            }
        }
    }
}

// ── Load categories with product counts ──
$categories = [];
$res = $conn->query("
    SELECT c.category_id, c.slug, c.name, c.icon, c.display_order, c.is_active,
           (SELECT COUNT(*) FROM products p WHERE p.category_id = c.category_id) AS product_count
    FROM categories c
    ORDER BY c.display_order ASC, c.category_id ASC
");
while ($row = $res->fetch_assoc()) $categories[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Categories | Bird's Nest Coffee</title>
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');}());</script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg:#0b0b0b; --bg-card:#131313; --bg-card-hover:#1a1a1a; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --ok:#55e087; --low:#f1c40f; --danger:#ff5f5f; --blue:#3498db; --purple:#9b59b6;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35); --shadow-md:0 4px 20px rgba(0,0,0,.45);
    --shadow-accent:0 0 0 3px rgba(209,144,75,.12);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
}
[data-theme="light"] {
    --bg:#F0F2F5; --bg-card:#FFFFFF; --bg-card-hover:#F5F7FA; --bg-input:#F9FAFB;
    --border:#E5E7EB; --border-hover:#D1D5DB;
    --text:#111827; --text-muted:#6B7280; --text-light:#111827;
    --shadow-sm:0 2px 8px rgba(0,0,0,.06); --shadow-md:0 4px 20px rgba(0,0,0,.08);
}
[data-theme="light"] .topbar { background:rgba(255,255,255,.97); }
[data-theme="light"] thead th { background:#fff; }
[data-theme="light"] tr:hover td { background:rgba(0,0,0,.02); }
[data-theme="light"] input,[data-theme="light"] select { background:var(--bg-input)!important; color:var(--text)!important; border-color:var(--border)!important; color-scheme:light; }

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding-bottom:48px; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-thumb { background:var(--accent); border-radius:10px; }

/* TOPBAR */
.topbar {
    position:sticky; top:0; z-index:200;
    display:flex; align-items:center; gap:10px; padding:10px 24px;
    background:rgba(11,11,11,.97); backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border); flex-wrap:wrap;
}
.brand-icon  { width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,var(--accent-dark),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0; }
.brand-text  { display:flex; flex-direction:column; line-height:1.2; }
.brand-title { font-size:15px; font-weight:700; color:var(--text-light); }
.brand-sub   { font-size:10px; color:var(--text-muted); }
.topbar-sep  { width:1px; height:22px; background:var(--border); flex-shrink:0; }
.topbar-right { display:flex; align-items:center; gap:6px; margin-left:auto; flex-wrap:wrap; }

.btn-nav {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    color:var(--text-muted); text-decoration:none; font-size:12px; font-weight:500;
    transition:var(--transition); cursor:pointer; white-space:nowrap; font-family:'Poppins',sans-serif;
}
.btn-nav:hover { border-color:var(--accent); color:var(--accent); }
.btn-nav.icon-only { padding:7px 10px; }

.wrap { max-width:920px; margin:18px auto 60px; padding:0 20px; }
.flash { padding:11px 15px; border-radius:10px; font-size:13px; margin-bottom:14px; }
.flash.success { background:rgba(85,224,135,.12); color:var(--ok); border:1px solid rgba(85,224,135,.3); }
.flash.error   { background:rgba(255,95,95,.12);  color:var(--danger); border:1px solid rgba(255,95,95,.3); }
.cat-table { width:100%; border-collapse:collapse; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.cat-table th { padding:10px 12px; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.6px; color:var(--text-muted); border-bottom:1px solid var(--border); }
.cat-table td { padding:11px 12px; border-bottom:1px solid var(--border); font-size:13px; vertical-align:middle; }
.cat-table tr:last-child td { border-bottom:none; }
.cat-row.inactive { opacity:.55; background:rgba(255,255,255,.02); }
.cat-icon { width:30px; height:30px; border-radius:8px; background:rgba(209,144,75,.12); color:var(--accent); display:inline-flex; align-items:center; justify-content:center; }
.slug-muted { color:var(--text-muted); font-size:12px; }
.pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:50px; font-size:10px; font-weight:700; }
.pill-inactive { background:rgba(255,95,95,.12); color:var(--danger); }
.icon-btn { background:transparent; border:1px solid var(--border); color:var(--text-muted); border-radius:7px; width:30px; height:30px; cursor:pointer; transition:var(--transition); }
.icon-btn:hover:not(:disabled) { border-color:var(--accent); color:var(--accent); }
.icon-btn:disabled { opacity:.3; cursor:not-allowed; }
.act-link { color:var(--accent); text-decoration:none; font-size:12px; font-weight:600; margin-right:10px; cursor:pointer; background:none; border:none; font-family:inherit; }
.danger-link { color:var(--danger); }
.danger-link:disabled { opacity:.3; cursor:not-allowed; }
</style>
</head>
<body>
<div class="topbar">
    <a href="products.php" class="btn-nav icon-only" title="Back to Products"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="brand-icon"><i class="fa-solid fa-tags"></i></div>
    <div class="brand-text">
        <span class="brand-title">Manage Categories</span>
        <span class="brand-sub">Bird's Nest Coffee &rsaquo; Catalog</span>
    </div>
    <div class="topbar-right">
        <button class="btn-nav icon-only" onclick="toggleTheme()" title="Toggle theme"><i class="fa-solid fa-moon" id="themeIcon"></i></button>
    </div>
</div>

<div class="wrap">
    <?php if ($flash): ?>
    <div class="flash <?= he($flash['type']) ?>"><?= he($flash['msg']) ?></div>
    <?php endif; ?>

    <form method="POST" style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="create">
        <div style="display:flex;flex-direction:column;gap:5px;">
            <label style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Name</label>
            <input type="text" name="name" id="catName" required placeholder="e.g. Smoothies" oninput="updateSlugPreview()" style="padding:7px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);font-size:13px;font-family:'Poppins',sans-serif;">
            <span class="slug-muted" id="slugPreview">Slug will be: —</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:5px;">
            <label style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Icon</label>
            <input type="text" name="icon" value="fa-tag" style="padding:7px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);font-size:13px;font-family:'Poppins',sans-serif;width:150px;">
            <span class="slug-muted">e.g. fa-mug-hot, fa-leaf, fa-blender</span>
        </div>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);padding-bottom:8px;">
            <input type="checkbox" name="is_active" checked> Active
        </label>
        <button type="submit" class="btn-nav" style="border-color:var(--accent);color:var(--accent);padding-bottom:8px;"><i class="fa-solid fa-plus"></i> Add Category</button>
    </form>

    <table class="cat-table">
        <thead>
            <tr>
                <th style="width:70px">Order</th>
                <th style="width:44px"></th>
                <th>Name</th>
                <th>Slug</th>
                <th style="width:90px">Products</th>
                <th style="width:80px">Active</th>
                <th style="width:150px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $i => $c): ?>
            <tr class="cat-row <?= $c['is_active'] ? '' : 'inactive' ?>">
                <td>
                    <button class="icon-btn" title="Move up"   <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button>
                    <button class="icon-btn" title="Move down" <?= $i === count($categories) - 1 ? 'disabled' : '' ?>>&darr;</button>
                </td>
                <td><span class="cat-icon"><i class="fa-solid <?= he($c['icon'] ?: 'fa-circle') ?>"></i></span></td>
                <td><?= he($c['name']) ?><?php if (!$c['is_active']): ?> <span class="pill pill-inactive">Inactive</span><?php endif; ?></td>
                <td class="slug-muted"><?= he($c['slug']) ?></td>
                <td><?= (int)$c['product_count'] ?></td>
                <td><?= $c['is_active'] ? 'Yes' : 'No' ?></td>
                <td><!-- edit/delete/toggle controls added in Tasks 3-5 --></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function updateSlugPreview() {
    const v = document.getElementById('catName').value.trim().replace(/\s+/g, ' ');
    document.getElementById('slugPreview').textContent = 'Slug will be: ' + (v || '—');
}
function toggleTheme() {
    const html = document.documentElement, icon = document.getElementById('themeIcon');
    if (html.getAttribute('data-theme') === 'light') { html.removeAttribute('data-theme'); icon.className = 'fa-solid fa-moon'; localStorage.setItem('theme','dark'); }
    else { html.setAttribute('data-theme','light'); icon.className = 'fa-solid fa-sun'; localStorage.setItem('theme','light'); }
}
document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('theme') === 'light') document.getElementById('themeIcon').className = 'fa-solid fa-sun';
});
</script>
</body>
</html>
