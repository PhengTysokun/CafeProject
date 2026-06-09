<?php
require 'auth.php';
require_once "config.php";

$admin_name = $_SESSION['username'] ?? 'Admin';
$_is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'supervisor']);

// Load roles for badge colours and nav icon lookups
$_roles_db = [];
$_rdb = $conn->query("SELECT slug, name, icon, color FROM roles ORDER BY is_system DESC, id ASC");
while ($_rdbr = $_rdb->fetch_assoc()) $_roles_db[$_rdbr['slug']] = $_rdbr;

$_cur_role = $_SESSION['role'] ?? 'staff';
$_cur_role_info = $_roles_db[$_cur_role] ?? null;
$_cur_role_name = $_cur_role_info['name'] ?? ucwords(str_replace('_', ' ', $_cur_role));
$_cur_role_color = $_cur_role_info['color'] ?? '#d1904b';

// Clock-in status (self-service shift tracking — same check as view_order.php)
$_is_clocked_in = false;
$_clock_since   = null;
$_att_check = $conn->query("SHOW TABLES LIKE 'attendance'");
if ($_att_check && $_att_check->num_rows > 0) {
    $_cs = $conn->prepare("SELECT clock_in FROM attendance WHERE user_id = ? AND date = CURDATE() AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1");
    $_cs->bind_param('i', $_SESSION['user_id']);
    $_cs->execute();
    $_crow = $_cs->get_result()->fetch_assoc();
    if ($_crow) { $_is_clocked_in = true; $_clock_since = date('g:i A', strtotime($_crow['clock_in'])); }
}

$_now = new DateTime();
$business_date = (int)$_now->format("H") < 6
    ? (clone $_now)->modify("-1 day")->format("Y-m-d")
    : $_now->format("Y-m-d");

$sales_result   = mysqli_query($conn, "SELECT IFNULL(SUM(total),0) AS total_sales FROM orders WHERE DATE(order_date)=CURDATE() AND status='Completed'");
$sales          = mysqli_fetch_assoc($sales_result)['total_sales'];

$yesterday_result  = mysqli_query($conn, "SELECT IFNULL(SUM(total),0) AS yesterday_sales FROM orders WHERE DATE(order_date)=CURDATE()-INTERVAL 1 DAY AND status='Completed'");
$yesterday_sales   = mysqli_fetch_assoc($yesterday_result)['yesterday_sales'];
$sales_trend       = $yesterday_sales > 0 ? round(($sales - $yesterday_sales) / $yesterday_sales * 100, 1) : 0;
$trend_class       = $sales_trend >= 0 ? 'up' : 'down';
$trend_icon        = $sales_trend >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';

$order_result  = mysqli_query($conn, "SELECT COUNT(*) AS total_orders FROM orders WHERE DATE(order_date)=CURDATE()");
$total_orders  = mysqli_fetch_assoc($order_result)['total_orders'];

$low_result  = mysqli_query($conn, "SELECT COUNT(*) AS low_count FROM ingredients WHERE stock_quantity < minimum_stock");
$low_stock   = mysqli_fetch_assoc($low_result)['low_count'];

// Recipes whose ingredients are running low — surfaced on the "Drink Recipe" tile for prep-facing roles (e.g. barista)
$low_recipe_result = mysqli_query($conn, "
    SELECT COUNT(DISTINCT pi.product_id) AS low_recipe_count
    FROM product_ingredients pi
    JOIN ingredients i ON pi.ingredient_id = i.ingredient_id
    WHERE pi.amount_used > 0 AND i.stock_quantity < i.minimum_stock
");
$low_recipe_count = mysqli_fetch_assoc($low_recipe_result)['low_recipe_count'];

$unpaid_result = mysqli_query($conn, "SELECT COUNT(*) AS unpaid_count FROM orders WHERE status='PendingPayment'");
$unpaid_count  = mysqli_fetch_assoc($unpaid_result)['unpaid_count'];

$unpaid_orders_result = mysqli_query($conn, "SELECT order_id, daily_order_no, customer_name, total, status, payment_method, order_date, is_open, token_number FROM orders WHERE status='PendingPayment' ORDER BY order_date DESC LIMIT 5");

$paid_open_result = mysqli_query($conn, "SELECT order_id, daily_order_no, customer_name, total, status, payment_method, order_date, is_open, token_number FROM orders WHERE status='Preparing' AND is_open=0 ORDER BY order_date DESC LIMIT 5");

$refund_result = mysqli_query($conn, "SELECT IFNULL(SUM(refund_amount),0) AS total_refunds, COUNT(*) AS refund_count FROM orders WHERE DATE(refunded_at)=CURDATE() AND is_refunded=1");
$refund_data   = mysqli_fetch_assoc($refund_result);
$total_refunds = $refund_data['total_refunds'];
$refund_count  = $refund_data['refund_count'];

$stmt_status = $conn->prepare("SELECT status, COUNT(*) as count FROM orders WHERE business_date=? GROUP BY status");
$stmt_status->bind_param("s", $business_date);
$stmt_status->execute();
$status_result = $stmt_status->get_result();
$status_counts = [];
while ($row = mysqli_fetch_assoc($status_result)) { $status_counts[$row['status']] = $row['count']; }
$pending_count   = $status_counts['PendingPayment'] ?? 0;
$preparing_count = $status_counts['Preparing']      ?? 0;
$completed_count = $status_counts['Completed']      ?? 0;
$cancelled_count = $status_counts['Cancelled']      ?? 0;

$items_sold_result = mysqli_query($conn, "SELECT IFNULL(SUM(oi.quantity),0) AS total_items FROM order_items oi JOIN orders o ON oi.order_id=o.order_id WHERE DATE(o.order_date)=CURDATE() AND o.status='Completed'");
$items_sold = mysqli_fetch_assoc($items_sold_result)['total_items'];

$kitchen_result = mysqli_query($conn, "SELECT order_id, daily_order_no, customer_name, total, order_date, token_number FROM orders WHERE DATE(order_date)=CURDATE() AND status='Preparing' ORDER BY order_date ASC LIMIT 8");

$recent_sql = "SELECT order_id, daily_order_no, customer_name, total, status, order_date FROM orders WHERE DATE(order_date)=CURDATE() ORDER BY order_date DESC LIMIT 20";
$recent_orders = mysqli_query($conn, $recent_sql);

$top_selling_result = mysqli_query($conn, "SELECT p.name, p.image, SUM(oi.quantity) as total_sold, p.price FROM products p JOIN order_items oi ON p.product_id=oi.product_id JOIN orders o ON oi.order_id=o.order_id WHERE o.status='Completed' GROUP BY p.product_id ORDER BY total_sold DESC LIMIT 5");

$activity_result = mysqli_query($conn, "SELECT * FROM (SELECT 'order' as type, order_id as ref_id, customer_name as name, total as amount, status, order_date as date FROM orders UNION ALL SELECT 'stock' as type, ingredient_id as ref_id, ingredient_name as name, purchase_qty as amount, 'restocked' as status, NULL as date FROM ingredients) as activity ORDER BY date DESC LIMIT 5");

$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
if ($filter_status) {
    $stmt_filter = $conn->prepare("SELECT order_id, daily_order_no, customer_name, total, status, order_date FROM orders WHERE DATE(order_date)=CURDATE() AND status=? ORDER BY order_date DESC LIMIT 20");
    $stmt_filter->bind_param("s", $filter_status);
    $stmt_filter->execute();
    $recent_orders = $stmt_filter->get_result();
}

// Flash toasts — only show once (right after login), then clear
$_flash_welcome     = !empty($_SESSION['flash_welcome']);     unset($_SESSION['flash_welcome']);
$_flash_stock_alert = !empty($_SESSION['flash_stock_alert']); unset($_SESSION['flash_stock_alert']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── TOKENS ── */
:root {
    --bg:           #0b0b0b;
    --surface:      #111111;
    --surface-2:    #181818;
    --glass:        rgba(255,255,255,0.04);
    --glass-hi:     rgba(255,255,255,0.07);
    --border:       #1f1f1f;
    --border-hi:    #2a2a2a;

    --amber:        #d1904b;
    --amber-light:  #e8b87a;
    --amber-dim:    rgba(209,144,75,0.15);
    --amber-glow:   rgba(209,144,75,0.25);

    --emerald:      #55e087;
    --emerald-dim:  rgba(85,224,135,0.13);
    --blue:         #3498db;
    --blue-dim:     rgba(52,152,219,0.13);
    --red:          #ff6b6b;
    --red-dim:      rgba(255,107,107,0.13);
    --purple:       #9b59b6;
    --purple-dim:   rgba(155,89,182,0.13);

    --accent:       var(--amber);
    --success:      var(--emerald);
    --warning:      var(--amber);
    --danger:       var(--red);

    --text:         #f5f5f5;
    --text-muted:   #888888;
    --text-xs:      #444444;

    --sidebar-w:    242px;
    --r:            14px;
    --r-sm:         10px;
    --r-xs:         7px;
    --shadow:       0 4px 24px rgba(0,0,0,.45);
    --shadow-lg:    0 8px 40px rgba(0,0,0,.65);
    --ease:         cubic-bezier(.4,0,.2,1);
    --spring:       cubic-bezier(.34,1.56,.64,1);
}

[data-theme="light"] {
    --bg:        #ECEEF2;
    --surface:   #FFFFFF;
    --surface-2: #F5F7FA;
    --glass:     rgba(255,255,255,.90);
    --glass-hi:  rgba(255,255,255,.98);
    --border:    #E2E5EA;
    --border-hi: #CDD0D8;
    --text:      #111827;
    --text-muted:#5A6373;
    --text-xs:   #9CA3AF;
    --shadow:    0 1px 3px rgba(0,0,0,.07), 0 4px 14px rgba(0,0,0,.06);
    --shadow-lg: 0 4px 20px rgba(0,0,0,.09), 0 1px 4px rgba(0,0,0,.05);
}
[data-theme="light"] body {
    -webkit-font-smoothing: subpixel-antialiased;
    -moz-osx-font-smoothing: auto;
}
/* crisp sidebar border in light mode */
[data-theme="light"] .sidebar {
    border-right-color: #D8DCE3;
    box-shadow: 2px 0 12px rgba(0,0,0,.05);
}
/* cards need a resting shadow in light mode so they lift off the page */
[data-theme="light"] .kpi-card,
[data-theme="light"] .panel {
    box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 14px rgba(0,0,0,.05);
    background: #FFFFFF;
    border-color: #E2E5EA;
}
[data-theme="light"] .kpi-card:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,.10), 0 1px 4px rgba(0,0,0,.06);
    transform: translateY(-2px);
}

/* ── RESET ── */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{background:var(--bg);scroll-behavior:smooth;}
body{
    font-family:'Poppins',sans-serif;
    background:var(--bg);
    color:var(--text);
    min-height:100vh;
    overflow-x:hidden;
    line-height:1.5;
    -webkit-font-smoothing:antialiased;
}

/* ambient glow behind main area */
body::before{
    content:'';
    position:fixed;
    top:-160px;
    left:var(--sidebar-w);
    right:0;
    height:480px;
    background:radial-gradient(ellipse at 55% 0%, rgba(209,144,75,.045) 0%, transparent 68%);
    pointer-events:none;
    z-index:0;
}

a{color:inherit;text-decoration:none;}
img{display:block;}
button{font-family:inherit;cursor:pointer;}

/* ── LAYOUT ── */
.layout{display:flex;min-height:100vh;}

.main{
    margin-left:var(--sidebar-w);
    flex:1;
    padding:30px 36px 48px;
    position:relative;
    z-index:1;
    min-width:0;
}

/* collapses the reserved sidebar width for roles without a sidebar */
body.no-sidebar{--sidebar-w:0px;}

/* ── SIDEBAR ── */
.sidebar{
    position:fixed;
    inset:0 auto 0 0;
    width:var(--sidebar-w);
    background:var(--surface);
    border-right:1px solid var(--border);
    display:flex;
    flex-direction:column;
    z-index:100;
    overflow-y:auto;
    overflow-x:hidden;
    scrollbar-width:none;
    transition:transform .3s var(--ease);
}
.sidebar::-webkit-scrollbar{display:none;}

.sidebar-profile{
    display:flex;
    align-items:center;
    gap:10px;
    padding:14px;
    margin:12px 12px 4px;
    border-radius:var(--r-sm);
    background:var(--glass);
    border:1px solid var(--border);
    color:var(--text);
    transition:.2s var(--ease);
}
.sidebar-profile:hover{background:var(--glass-hi);border-color:var(--border-hi);}

.profile-avatar{
    width:34px;height:34px;
    background:var(--amber-dim);
    border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    color:var(--amber);font-size:14px;flex-shrink:0;
}
.profile-info{flex:1;min-width:0;}
.profile-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.profile-role{
    display:flex;align-items:center;gap:5px;
    font-size:10.5px;font-weight:500;
    color:var(--text-muted);
    margin-top:2px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.profile-role::before{
    content:'';flex-shrink:0;
    width:6px;height:6px;border-radius:50%;
    background:var(--role-color,var(--amber));
    box-shadow:0 0 6px var(--role-color,var(--amber));
}
.sidebar-clock{font-size:11px;font-weight:700;color:var(--amber);letter-spacing:.06em;flex-shrink:0;}

.sidebar-header{
    display:flex;align-items:center;gap:9px;
    padding:10px 20px 14px;
}
.sidebar-header i{color:var(--amber);font-size:17px;}
.sidebar-header h2{font-size:16px;font-weight:800;color:var(--text);letter-spacing:-.02em;}

.sidebar-quick-btn{
    display:flex;align-items:center;gap:8px;
    margin:0 12px 6px;
    padding:9px 15px;
    background:var(--amber);
    color:#000;
    border-radius:var(--r-sm);
    font-size:12px;font-weight:700;
    transition:.2s var(--ease);
    box-shadow:0 2px 14px var(--amber-glow);
}
.sidebar-quick-btn:hover{background:var(--amber-light);transform:translateY(-1px);box-shadow:0 4px 18px var(--amber-glow);}

.sidebar-nav{flex:1;padding:6px 12px;}

.nav-group-label{
    font-size:11px;font-weight:700;
    letter-spacing:.08em;text-transform:uppercase;
    color:var(--text-muted);
    padding:14px 10px 6px;
    opacity:.85;
    display:flex;align-items:center;justify-content:space-between;
    cursor:pointer;user-select:none;
    border-radius:var(--r-xs);
    transition:opacity .2s,color .2s;
}
.nav-group-label:hover{opacity:1;color:var(--text);}
.nav-group-label.open{opacity:1;color:var(--amber);}
.nav-chevron{font-size:9px;flex-shrink:0;margin-left:4px;transition:transform .28s var(--ease);}
.nav-group-label.open .nav-chevron{transform:rotate(90deg);}
.nav-group-items{overflow:hidden;max-height:400px;transition:max-height .32s var(--ease),opacity .22s var(--ease);opacity:1;}
.nav-group-items.collapsed{max-height:0!important;opacity:0;pointer-events:none;}

.nav-item{
    display:flex;align-items:center;gap:10px;
    padding:9px 12px;
    border-radius:var(--r-sm);
    color:var(--text-muted);
    font-size:13.5px;font-weight:500;
    transition:background .18s var(--ease),color .18s var(--ease),box-shadow .18s var(--ease),border-color .18s var(--ease);
    margin-bottom:2px;
    border:1px solid transparent;
    position:relative;
}
.nav-item:hover{
    background:linear-gradient(90deg,rgba(209,144,75,.13) 0%,rgba(209,144,75,.04) 100%);
    color:var(--amber);
    border-color:rgba(209,144,75,.22);
    box-shadow:0 2px 16px rgba(209,144,75,.18),inset 0 0 16px rgba(209,144,75,.05);
}
.nav-item.active{
    background:linear-gradient(90deg,rgba(209,144,75,.18) 0%,rgba(209,144,75,.06) 100%);
    color:var(--amber);
    font-weight:600;
    border-color:rgba(209,144,75,.3);
    box-shadow:0 2px 20px rgba(209,144,75,.25),inset 0 0 20px rgba(209,144,75,.07);
}
.nav-item.active i,.nav-item:hover i{color:inherit;}
.nav-item i{width:16px;text-align:center;font-size:13px;flex-shrink:0;}
.nav-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

.order-badge{
    margin-left:auto;flex-shrink:0;
    background:var(--amber);color:#000;
    font-size:9.5px;font-weight:800;
    padding:1px 7px;border-radius:50px;
    min-width:18px;text-align:center;
}

.sidebar-footer{padding:10px 12px;border-top:1px solid var(--border);}
.sidebar-stats{display:flex;gap:6px;margin-bottom:8px;}
.stat-pill{
    flex:1;display:flex;align-items:center;gap:5px;
    background:var(--glass);border:1px solid var(--border);
    border-radius:var(--r-xs);padding:5px 8px;
    font-size:10.5px;color:var(--text-muted);overflow:hidden;
}
.stat-pill i{color:var(--amber);font-size:9px;flex-shrink:0;}
.stat-pill span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

.nav-logout{color:var(--red) !important;border-color:transparent !important;}
.nav-logout:hover{
    background:linear-gradient(90deg,rgba(255,107,107,.13) 0%,rgba(255,107,107,.04) 100%) !important;
    color:var(--red) !important;
    border-color:rgba(255,107,107,.22) !important;
    box-shadow:0 2px 16px rgba(255,107,107,.18),inset 0 0 16px rgba(255,107,107,.05) !important;
}

/* ── TOPBAR ── */
.dash-header{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:22px;
    gap:16px;
}
.dash-header h1{
    font-size:24px;font-weight:800;
    letter-spacing:-.03em;line-height:1.2;
}
.dash-header h1 .name{
    background:linear-gradient(120deg,var(--amber) 0%,var(--amber-light) 100%);
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.header-sub{font-size:12px;color:var(--text-muted);margin-top:3px;display:flex;align-items:center;gap:5px;}

.theme-toggle{
    display:flex;align-items:center;gap:7px;
    background:var(--glass);border:1px solid var(--border);
    color:var(--text-muted);
    padding:8px 14px;border-radius:50px;
    font-size:12px;
    transition:.15s var(--ease);
    flex-shrink:0;
}
.theme-toggle:hover{border-color:var(--border-hi);color:var(--text);}

.header-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;}

.role-badge{
    display:inline-flex;align-items:center;gap:8px;
    background:var(--glass);border:1px solid var(--border);
    border-radius:50px;padding:6px 18px;margin-left:10px;
    font-size:14px;font-weight:600;color:var(--text);
    letter-spacing:.02em;
    vertical-align:middle;
}
.role-badge::before{
    content:'';flex-shrink:0;
    width:9px;height:9px;border-radius:50%;
    background:var(--role-color,var(--amber));
    box-shadow:0 0 9px var(--role-color,var(--amber));
}

.logout-btn{
    display:flex;align-items:center;gap:7px;
    background:var(--glass);border:1px solid var(--border);
    color:var(--text-muted);
    padding:8px 14px;border-radius:50px;
    font-size:12px;font-weight:600;
    transition:.15s var(--ease);
    flex-shrink:0;
}
.logout-btn:hover{background:var(--red-dim);border-color:rgba(255,107,107,.35);color:var(--red);}

/* ── ALERT STRIP ── */
.alert-strip{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:20px;}
.alert-pill{
    display:inline-flex;align-items:center;gap:7px;
    padding:7px 14px;border-radius:50px;
    font-size:12px;font-weight:600;
    border:1px solid transparent;
    transition:.15s var(--ease);
}
.alert-pill.danger{background:var(--red-dim);color:var(--red);border-color:rgba(239,68,68,.22);}
.alert-pill.danger:hover{background:rgba(239,68,68,.22);}
.alert-pill.warning{background:var(--amber-dim);color:var(--amber);border-color:rgba(245,158,11,.22);}
.alert-pill.warning:hover{background:rgba(245,158,11,.22);}

/* ── KPI ROW ── */
.kpi-row{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:16px;
    margin-bottom:18px;
}

.kpi-card{
    background:var(--glass);
    border:1px solid var(--border);
    border-radius:var(--r);
    padding:22px 22px 18px;
    position:relative;overflow:hidden;
    transition:.22s var(--ease);
}
.kpi-card:hover{
    border-color:var(--border-hi);
    background:var(--glass-hi);
    transform:translateY(-3px);
    box-shadow:var(--shadow);
}

/* left accent bar */
.kpi-card::before{
    content:'';
    position:absolute;
    top:20%;bottom:20%;left:0;
    width:3px;border-radius:0 3px 3px 0;
    background:var(--kc,var(--amber));
}
/* corner glow */
.kpi-card::after{
    content:'';
    position:absolute;
    top:-40px;right:-40px;
    width:130px;height:130px;
    background:radial-gradient(circle,var(--kg,var(--amber-dim)) 0%,transparent 70%);
    pointer-events:none;
}

.kpi-card.c-amber { --kc:var(--amber);   --kg:var(--amber-dim);   }
.kpi-card.c-green { --kc:var(--emerald); --kg:var(--emerald-dim); }
.kpi-card.c-blue  { --kc:var(--blue);    --kg:var(--blue-dim);    }

.kpi-watermark{
    position:absolute;right:16px;bottom:10px;
    font-size:54px;color:var(--kc,var(--amber));
    opacity:.07;line-height:1;pointer-events:none;
}

.kpi-label{
    font-size:11px;font-weight:700;
    text-transform:uppercase;letter-spacing:.09em;
    color:var(--text-muted);margin-bottom:9px;
}
.kpi-value{
    font-size:36px;font-weight:800;
    letter-spacing:-.03em;line-height:1;
    color:var(--text);margin-bottom:13px;
}
.kpi-pill{
    display:inline-flex;align-items:center;gap:5px;
    font-size:11px;font-weight:600;
    padding:3px 10px;border-radius:50px;
}
.kpi-pill.up  {background:var(--emerald-dim);color:var(--emerald);}
.kpi-pill.down{background:var(--red-dim);    color:var(--red);}
.kpi-pill.flat{background:var(--glass);      color:var(--text-muted);}

/* ── MID GRID ── */
.mid-grid{
    display:grid;
    grid-template-columns:1fr 330px;
    gap:16px;
    margin-bottom:18px;
    align-items:start;
}

/* ── PANELS ── */
.panel{
    background:var(--glass);
    border:1px solid var(--border);
    border-radius:var(--r);
    overflow:hidden;
}

.panel-head{
    display:flex;align-items:center;justify-content:space-between;
    padding:15px 20px;
    border-bottom:1px solid var(--border);
}
.panel-head h3{
    font-size:13px;font-weight:700;
    color:var(--text);
    display:flex;align-items:center;gap:8px;
}
.panel-head h3 i{color:var(--amber);font-size:13px;}

.panel-link{
    font-size:11px;color:var(--text-muted);
    display:flex;align-items:center;gap:4px;
    transition:.15s;
}
.panel-link:hover{color:var(--amber);}

.cnt-badge{
    font-size:11px;font-weight:700;
    padding:2px 10px;border-radius:50px;
    background:var(--glass);border:1px solid var(--border);
    color:var(--text-muted);
}
.cnt-badge.on{background:var(--emerald-dim);color:var(--emerald);border-color:rgba(16,185,129,.25);}

.live-dot{
    width:7px;height:7px;background:var(--emerald);
    border-radius:50%;display:inline-block;flex-shrink:0;
    animation:pdot 2.2s ease-in-out infinite;
}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.45;transform:scale(.75);}}

/* ── PREMIUM SCROLLBAR ── */
.kitchen-body::-webkit-scrollbar,
.orders-tbl tbody::-webkit-scrollbar { width:4px; }
.kitchen-body::-webkit-scrollbar-track,
.orders-tbl tbody::-webkit-scrollbar-track { background:transparent; }
.kitchen-body::-webkit-scrollbar-thumb,
.orders-tbl tbody::-webkit-scrollbar-thumb {
    background:linear-gradient(180deg,var(--amber),rgba(209,144,75,.35));
    border-radius:99px;
}
.kitchen-body::-webkit-scrollbar-thumb:hover,
.orders-tbl tbody::-webkit-scrollbar-thumb:hover { background:var(--amber-light); }

/* ── KITCHEN ITEMS ── */
.kitchen-body{
    padding:4px 0;
    max-height:245px;
    overflow-y:auto;
    scrollbar-width:thin;
    scrollbar-color:rgba(209,144,75,.35) transparent;
}

.k-item{
    display:flex;align-items:center;gap:12px;
    padding:11px 20px;
    border-bottom:1px solid var(--border);
    transition:.15s var(--ease);
}
.k-item:last-child{border-bottom:none;}
.k-item:hover{background:var(--glass);}

.k-no{
    font-size:14px;font-weight:800;
    color:var(--amber);min-width:42px;
    letter-spacing:-.01em;
}
.k-name{
    flex:1;font-size:13px;font-weight:500;
    color:var(--text);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.k-total{font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;}

.k-timer{
    font-size:10.5px;font-weight:700;
    padding:3px 9px;border-radius:50px;
    min-width:44px;text-align:center;white-space:nowrap;
}
.k-timer.ok    {background:var(--emerald-dim);color:var(--emerald);}
.k-timer.warn  {background:var(--amber-dim);  color:var(--amber);}
.k-timer.urgent{background:var(--red-dim);    color:var(--red);}

.btn-ready{
    display:inline-flex;align-items:center;gap:5px;
    background:var(--amber);color:#000;
    font-size:11px;font-weight:700;
    padding:6px 11px;border-radius:var(--r-xs);
    transition:.15s var(--ease);flex-shrink:0;
}
.btn-ready:hover{background:var(--amber-light);transform:scale(1.05);}

.k-empty{
    display:flex;flex-direction:column;align-items:center;
    gap:9px;padding:40px;color:var(--text-muted);
}
.k-empty i{font-size:26px;color:var(--emerald);opacity:.55;}
.k-empty span{font-size:13px;}

.panel-foot{
    padding:7px 20px;
    border-top:1px solid var(--border);
    display:flex;justify-content:space-between;align-items:center;
    font-size:11px;color:var(--text-muted);
}
.refresh-btn{
    background:var(--glass);border:1px solid var(--border);
    color:var(--text-muted);padding:4px 10px;
    border-radius:var(--r-xs);font-size:11px;
    transition:.15s var(--ease);
}
.refresh-btn:hover{color:var(--text);border-color:var(--border-hi);}

/* ── TOP SELLERS ── */
.sellers-body{padding:4px 0;}
.seller-row{
    display:flex;align-items:center;gap:11px;
    padding:9px 20px;
    transition:.15s var(--ease);
}
.seller-row:hover{background:var(--glass);}

.s-rank{
    font-size:11px;font-weight:800;
    color:var(--text-muted);
    min-width:18px;text-align:center;
}
.s-rank.gold{color:var(--amber);}

.s-img{
    width:38px;height:38px;
    border-radius:var(--r-xs);
    object-fit:cover;
    background:var(--glass);
    border:1px solid var(--border);
    flex-shrink:0;
}

.s-info{flex:1;min-width:0;}
.s-name{
    font-size:12px;font-weight:600;color:var(--text);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.s-track{height:3px;background:var(--border);border-radius:2px;margin-top:5px;}
.s-bar{height:100%;background:var(--amber);border-radius:2px;transition:width .9s var(--ease);}

.s-count{
    font-size:12px;font-weight:700;
    color:var(--text-muted);
    min-width:36px;text-align:right;
}

/* ── ORDERS TABLE ── */
.orders-tbl{width:100%;border-collapse:collapse;table-layout:fixed;}
.orders-tbl thead{display:table;width:100%;table-layout:fixed;}
.orders-tbl tbody{
    display:block;
    max-height:255px;
    overflow-y:auto;
    scrollbar-width:thin;
    scrollbar-color:rgba(209,144,75,.35) transparent;
}
.orders-tbl tbody tr{display:table;width:100%;table-layout:fixed;}
.orders-tbl th{
    font-size:10.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.08em;
    color:var(--text-muted);
    padding:10px 20px;text-align:left;
    border-bottom:1px solid var(--border);
}
.orders-tbl td{
    padding:12px 20px;font-size:13px;
    color:var(--text);
    border-bottom:1px solid var(--border);
    vertical-align:middle;
}
.orders-tbl tr:last-child td{border-bottom:none;}
.orders-tbl tbody tr{transition:.15s var(--ease);}
.orders-tbl tbody tr:hover{background:var(--glass);}

.o-no{font-size:13px;font-weight:800;color:var(--amber);font-variant-numeric:tabular-nums;}

.cust-cell{display:flex;align-items:center;gap:8px;}
.cust-av{
    width:27px;height:27px;flex-shrink:0;
    background:var(--glass);border:1px solid var(--border);
    border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-size:10px;color:var(--text-muted);
}

/* status badges */
.badge{
    display:inline-flex;align-items:center;gap:4px;
    font-size:10.5px;font-weight:700;
    padding:3px 9px;border-radius:50px;white-space:nowrap;
}
.badge.completed,.badge.paid       {background:var(--emerald-dim);color:var(--emerald);}
.badge.preparing                   {background:var(--amber-dim);  color:var(--amber);}
.badge.pendingpayment,.badge.pending{background:var(--purple-dim); color:var(--purple);}
.badge.cancelled                   {background:var(--red-dim);    color:var(--red);}
.badge.refunded                    {background:var(--blue-dim);   color:var(--blue);}

.tbl-empty{text-align:center;padding:46px;}
.tbl-empty i{font-size:26px;display:block;margin-bottom:10px;color:var(--text-muted);opacity:.4;}
.tbl-empty span{font-size:13px;color:var(--text-muted);}

/* ── QUICK ACCESS GRID (non-admin dashboard) ── */
.qa-grid{display:flex;flex-direction:column;gap:32px;max-width:1000px;width:100%;margin:0 auto;}
.qa-group{display:flex;flex-direction:column;gap:16px;}
.qa-group-label{
    font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
    color:var(--text-muted);display:flex;align-items:center;gap:7px;
    padding-bottom:8px;border-bottom:1px solid var(--border);
}
.qa-group-label i{color:var(--accent);font-size:13px;}
.qa-tiles{display:flex;flex-wrap:wrap;justify-content:center;gap:20px;}
.qa-tile{
    position:relative;
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:16px;padding:42px 38px;
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r);color:var(--text);text-decoration:none;
    font-size:16px;font-weight:600;min-width:180px;min-height:150px;
    transition:background .2s,border-color .2s,transform .15s;
}
.qa-tile:hover{background:var(--surface-2);border-color:var(--amber-glow);transform:translateY(-2px);}
.qa-tile i{font-size:44px;color:var(--accent);}
.qa-tile-badge{
    position:absolute;top:14px;right:14px;
    background:var(--purple);color:#fff;
    font-size:12px;font-weight:700;
    min-width:24px;height:24px;padding:0 7px;
    border-radius:50px;
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 2px 8px rgba(0,0,0,.3);
}

.qa-hero-btn{
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    align-self:center;gap:16px;padding:42px 38px;
    background:linear-gradient(135deg,var(--amber-light) 0%,var(--amber) 100%);
    color:#000;text-decoration:none;
    border:1px solid rgba(255,255,255,.22);
    border-radius:calc(var(--r) + 8px);font-size:16px;font-weight:700;letter-spacing:.015em;
    min-width:180px;min-height:150px;
    position:relative;overflow:hidden;
    box-shadow:0 6px 28px var(--amber-glow);
    transition:transform .25s var(--ease),box-shadow .3s var(--ease);
    animation:heroGlow 2.8s ease-in-out infinite;
}
.qa-hero-btn::after{
    content:'';position:absolute;inset:0;
    background:linear-gradient(115deg,transparent 35%,rgba(255,255,255,.45) 50%,transparent 65%);
    background-size:240% 100%;background-position:160% 0;
    transition:background-position .8s ease;pointer-events:none;
}
.qa-hero-btn:hover::after{background-position:-60% 0;}
.qa-hero-btn i{font-size:44px;color:#000;display:inline-block;transition:transform .5s cubic-bezier(.34,1.56,.64,1);}
.qa-hero-btn:hover i{transform:rotate(180deg) scale(1.18);}
.qa-hero-btn:hover{transform:translateY(-3px) scale(1.02);box-shadow:0 14px 42px var(--amber-glow);animation-play-state:paused;}
@keyframes heroGlow{
    0%,100%{box-shadow:0 6px 28px var(--amber-glow),0 0 0 0 rgba(209,144,75,.35);}
    50%{box-shadow:0 6px 28px var(--amber-glow),0 0 0 14px rgba(209,144,75,0);}
}

/* ── TOAST ── */
.toast-container{position:fixed;bottom:22px;right:22px;z-index:9999;display:flex;flex-direction:column;gap:9px;}
.toast{
    display:flex;align-items:center;gap:10px;
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r-sm);
    padding:11px 15px;font-size:12.5px;color:var(--text);
    box-shadow:var(--shadow-lg);min-width:250px;
    animation:toastIn .3s var(--spring);
}
.toast.success{border-left:3px solid var(--emerald);}
.toast.error  {border-left:3px solid var(--red);}
.toast.success i{color:var(--emerald);}
.toast.error   i{color:var(--red);}
.close-toast{margin-left:auto;color:var(--text-muted);cursor:pointer;transition:.15s;}
.close-toast:hover{color:var(--text);}
@keyframes toastIn{from{opacity:0;transform:translateX(18px);}to{opacity:1;transform:translateX(0);}}

/* ── MOBILE ── */
.menu-toggle{
    display:none;position:fixed;top:14px;left:14px;z-index:200;
    background:var(--surface);border:1px solid var(--border);
    color:var(--text);width:40px;height:40px;
    border-radius:var(--r-sm);align-items:center;justify-content:center;
    font-size:15px;transition:.15s var(--ease);
}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);z-index:90;}
.overlay.active{display:block;}

/* ── ANIMATIONS ── */
.fu{animation:fu .45s var(--ease) both;}
@keyframes fu{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}

/* ── RESPONSIVE ── */
@media(max-width:1100px){.mid-grid{grid-template-columns:1fr;}}
@media(max-width:820px) {.kpi-row{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px) {.kpi-row{grid-template-columns:1fr;}}
@media(max-width:768px){
    .sidebar{transform:translateX(-100%);}
    .sidebar.open{transform:translateX(0);}
    .menu-toggle{display:flex;}
    .main{margin-left:0;padding:68px 16px 32px;max-width:100vw;}
    .dash-header h1{font-size:19px;}
    .kpi-value{font-size:28px;}
}
</style>
</head>
<body<?= $_is_mgr ? '' : ' class="no-sidebar"' ?>>

<?php if ($_is_mgr): ?>
<button class="menu-toggle" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
<div class="overlay" onclick="toggleSidebar()"></div>
<?php endif; ?>
<div class="toast-container" id="toastContainer"></div>

<div class="layout">

<?php if ($_is_mgr): ?>
<!-- ═══ SIDEBAR ═══ -->
<div class="sidebar" id="sidebar">

    <a href="profile.php" class="sidebar-profile" title="My Profile">
        <div class="profile-avatar"><i class="fa-solid fa-user"></i></div>
        <div class="profile-info">
            <div class="profile-name"><?= htmlspecialchars($admin_name) ?></div>
            <div class="profile-role" style="--role-color:<?= $_cur_role_color ?>;">
                <?= htmlspecialchars($_cur_role_name) ?>
            </div>
        </div>
        <div class="sidebar-clock" id="sidebarClock">--:--</div>
    </a>

    <div class="sidebar-header">
        <i class="fa-solid fa-mug-hot"></i>
        <h2>Bird's Nest</h2>
    </div>

    <?php if (can('find_orders')): ?>
    <a href="menu.php" class="sidebar-quick-btn">
        <i class="fa-solid fa-plus"></i> Take New Order
    </a>
    <?php endif; ?>

    <div class="sidebar-nav" id="sidebarNav">

        <!-- OVERVIEW — open by default (active page) -->
        <div class="nav-group-label open" onclick="toggleGroup(this)" data-group="overview">
            <span>Overview</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items" id="grp-overview">
            <a class="nav-item active" href="dashboard.php">
                <i class="fa-solid fa-chart-pie"></i>
                <span class="nav-label">Dashboard</span>
                <?php if ($pending_count + $preparing_count > 0): ?>
                <span class="order-badge"><?= $pending_count + $preparing_count ?></span>
                <?php endif; ?>
            </a>
        </div>

        <!-- ORDERS -->
        <?php if (can('find_orders') || can('view_orders') || can('tables')): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="orders">
            <span>Orders</span>
            <i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-orders">
            <?php if (can('find_orders')): ?>
            <a class="nav-item" href="find_order.php">
                <i class="fa-solid fa-magnifying-glass"></i>
                <span class="nav-label">Find Unpaid Orders</span>
                <?php if ($unpaid_count > 0): ?>
                <span class="order-badge" style="background:var(--purple);"><?= $unpaid_count ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (can('view_orders')): ?>
            <a class="nav-item" href="view_order.php">
                <i class="fa-solid fa-receipt"></i>
                <span class="nav-label">Orders</span>
            </a>
            <?php endif; ?>
            <?php if (can('tables')): ?>
            <a class="nav-item" href="tables.php">
                <i class="fa-solid fa-table-cells"></i>
                <span class="nav-label">Tables</span>
                <?php
                $_occ = $conn->query("SELECT COUNT(*) FROM cafe_tables WHERE status='occupied'")->fetch_row()[0];
                if ($_occ > 0): ?>
                <span class="order-badge" style="background:var(--red,#e74c3c);"><?= (int)$_occ ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- LOYALTY -->
        <?php if (can('loyalty')): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="loyalty">
            <span>Loyalty</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-loyalty">
            <a class="nav-item" href="loyalty_dashboard.php">
                <i class="fa-solid fa-star"></i>
                <span class="nav-label">Loyalty Card</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- INVENTORY -->
        <?php if (can('products') || can('ingredients') || can('recipes')): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="inventory">
            <span>Inventory</span>
            <i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-inventory">
            <?php if (can('products')): ?>
            <a class="nav-item" href="products.php">
                <i class="fa-solid fa-cube"></i>
                <span class="nav-label">Products</span>
            </a>
            <?php endif; ?>
            <?php if (can('ingredients')): ?>
            <a class="nav-item" href="ingredients.php">
                <i class="fa-solid fa-boxes-stacked"></i>
                <span class="nav-label">Ingredients</span>
                <?php if ($low_stock > 0): ?><span class="order-badge" style="background:var(--red);margin-left:auto"><?= $low_stock ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (can('recipes')): ?>
            <a class="nav-item" href="recipes_view.php">
                <i class="fa-solid fa-utensils"></i>
                <span class="nav-label">Drink Recipe</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- PROCUREMENT -->
        <?php if (can('suppliers') || can('purchase_orders')): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="procurement">
            <span>Procurement</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-procurement">
            <?php if (can('suppliers')): ?>
            <a class="nav-item" href="suppliers.php">
                <i class="fa-solid fa-truck-ramp-box"></i>
                <span class="nav-label">Suppliers</span>
            </a>
            <?php endif; ?>
            <?php if (can('purchase_orders')): ?>
            <a class="nav-item" href="purchase_orders.php">
                <i class="fa-solid fa-file-invoice"></i>
                <span class="nav-label">Purchase Orders</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ANALYTICS -->
        <?php if (can('report')): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="analytics">
            <span>Analytics</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-analytics">
            <a class="nav-item" href="report.php">
                <i class="fa-solid fa-chart-simple"></i>
                <span class="nav-label">Daily Report</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- STAFF -->
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="staff">
            <span>Staff</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-staff">
            <?php if (can('employees')): ?>
            <a class="nav-item" href="employees.php">
                <i class="fa-solid fa-user-tie"></i>
                <span class="nav-label">Employees</span>
            </a>
            <?php endif; ?>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <a class="nav-item" href="manage_roles.php">
                <i class="fa-solid fa-shield-halved"></i>
                <span class="nav-label">Manage Roles</span>
            </a>
            <?php endif; ?>
            <?php if (can('reset_password')): ?>
            <a class="nav-item" href="admin_reset_password.php">
                <i class="fa-solid fa-key"></i>
                <span class="nav-label">Reset Password</span>
            </a>
            <?php endif; ?>
            <?php if (can('announcements')): ?>
            <a class="nav-item" href="announcements.php">
                <i class="fa-solid fa-bullhorn"></i>
                <span class="nav-label">Announcements</span>
            </a>
            <?php endif; ?>
            <?php if (can('attendance')): ?>
            <a class="nav-item" href="attendance.php">
                <i class="fa-solid fa-fingerprint"></i>
                <span class="nav-label">Attendance</span>
            </a>
            <?php endif; ?>
            <?php if (can('promotions')): ?>
            <a class="nav-item" href="settings.php">
                <i class="fa-solid fa-sliders"></i>
                <span class="nav-label">Promotions</span>
            </a>
            <?php endif; ?>
            <a class="nav-item" href="profile.php">
                <i class="fa-solid fa-circle-user"></i>
                <span class="nav-label">My Profile</span>
            </a>
        </div>

    </div>

    <div class="sidebar-footer">
        <?php if ($_is_mgr): ?>
        <div class="sidebar-stats">
            <div class="stat-pill">
                <i class="fa-solid fa-dollar-sign"></i>
                <span>$<?= number_format($sales, 2) ?></span>
            </div>
            <div class="stat-pill">
                <i class="fa-solid fa-receipt"></i>
                <span><?= (int)$total_orders ?> orders</span>
            </div>
        </div>
        <?php endif; ?>
        <a class="nav-item nav-logout" href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span class="nav-label">Logout</span>
        </a>
    </div>

</div>
<!-- end sidebar -->
<?php endif; ?>

<!-- ═══ MAIN ═══ -->
<div class="main">

    <?php if (isset($_GET['denied'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const t = document.createElement('div');
        t.className = 'toast error';
        t.innerHTML = '<i class="fa-solid fa-lock"></i><span>You don\'t have permission to access that page.</span><span class="close-toast" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></span>';
        document.getElementById('toastContainer').appendChild(t);
        setTimeout(() => { t.style.opacity='0'; t.style.transform='translateX(18px)'; setTimeout(()=>t.remove(),400); }, 4000);
    });
    </script>
    <?php endif; ?>

    <!-- HEADER -->
    <div class="dash-header fu" style="animation-delay:.0s">
        <div>
            <h1>
                Good <span id="timeOfDay">morning</span>, <span class="name"><?= htmlspecialchars($admin_name) ?></span>
                <?php if (!$_is_mgr): ?>
                <span class="role-badge" style="--role-color:<?= htmlspecialchars($_cur_role_color) ?>;"><?= htmlspecialchars($_cur_role_name) ?></span>
                <?php endif; ?>
            </h1>
            <p class="header-sub">
                <i class="fa-regular fa-calendar-days"></i>
                <?= date("l, d F Y") ?>
            </p>
        </div>
        <div class="header-actions">
            <button class="theme-toggle" onclick="toggleTheme()">
                <i class="fa-solid fa-moon" id="themeIcon"></i>
                <span id="themeText">Dark</span>
            </button>
            <?php if (!$_is_mgr): ?>
            <?php
            $clocked  = $_is_clocked_in;
            $clkBg    = $clocked ? 'rgba(255,95,95,.08)'   : 'rgba(85,224,135,.08)';
            $clkBr    = $clocked ? 'rgba(255,95,95,.25)'   : 'rgba(85,224,135,.25)';
            $clkColor = $clocked ? '#ff6b6b'               : '#55e087';
            $clkIcon  = $clocked ? 'right-from-bracket'    : 'fingerprint';
            $clkLabel = $clocked ? 'Clock Out'             : 'Clock In';
            $clkTitle = $clocked ? 'Clocked in at ' . $_clock_since : 'Not clocked in';
            ?>
            <button id="clockBtn" data-clocked="<?= $clocked ? '1' : '0' ?>"
                onclick="toggleClock()"
                title="<?= htmlspecialchars($clkTitle) ?>"
                style="display:inline-flex;align-items:center;gap:7px;
                       padding:9px 16px;border-radius:10px;font-size:13px;font-family:'Poppins',sans-serif;font-weight:500;cursor:pointer;
                       background:<?= $clkBg ?>;border:1px solid <?= $clkBr ?>;color:<?= $clkColor ?>;transition:all .2s;">
                <i class="fa-solid fa-<?= $clkIcon ?>"></i> <?= $clkLabel ?>
            </button>
            <a href="logout.php" class="logout-btn" title="Log out">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ALERT STRIP -->
    <?php if (($low_stock > 0 && can('ingredients')) || ($_is_mgr && $unpaid_count > 0 && can('find_orders'))): ?>
    <div class="alert-strip fu" style="animation-delay:.06s">
        <?php if ($low_stock > 0 && can('ingredients')): ?>
        <a href="ingredients.php" class="alert-pill danger">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <?= $low_stock ?> item<?= $low_stock != 1 ? 's' : '' ?> low on stock — restock needed
        </a>
        <?php endif; ?>
        <?php if ($_is_mgr && $unpaid_count > 0 && can('find_orders')): ?>
        <a href="find_order.php" class="alert-pill warning">
            <i class="fa-solid fa-clock"></i>
            <?= $unpaid_count ?> unpaid order<?= $unpaid_count != 1 ? 's' : '' ?> pending
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($_is_mgr): ?>
    <!-- KPI ROW -->
    <div class="kpi-row fu" style="animation-delay:.1s">

        <!-- Revenue -->
        <div class="kpi-card c-amber">
            <i class="kpi-watermark fa-solid fa-dollar-sign"></i>
            <div class="kpi-label">Today's Revenue</div>
            <div class="kpi-value">$<span id="kpiRevenue"><?= number_format($sales, 2) ?></span></div>
            <?php if ($sales_trend != 0): ?>
            <span class="kpi-pill <?= $trend_class ?>">
                <i class="fa-solid <?= $trend_icon ?>"></i>
                <?= abs($sales_trend) ?>% vs yesterday
            </span>
            <?php else: ?>
            <span class="kpi-pill flat"><i class="fa-solid fa-minus"></i> No data yesterday</span>
            <?php endif; ?>
        </div>

        <!-- Orders -->
        <div class="kpi-card c-green">
            <i class="kpi-watermark fa-solid fa-receipt"></i>
            <div class="kpi-label">Orders Today</div>
            <div class="kpi-value"><span id="kpiOrders"><?= (int)$total_orders ?></span></div>
            <span class="kpi-pill flat">
                <i class="fa-solid fa-circle-check"></i>
                <?= $completed_count ?> completed
            </span>
        </div>

        <!-- Items Sold -->
        <div class="kpi-card c-blue">
            <i class="kpi-watermark fa-solid fa-mug-hot"></i>
            <div class="kpi-label">Items Served</div>
            <div class="kpi-value"><span id="kpiItems"><?= (int)$items_sold ?></span></div>
            <span class="kpi-pill flat">
                <i class="fa-solid fa-box-open"></i>
                from completed orders
            </span>
        </div>

    </div>

    <!-- MID GRID -->
    <div class="mid-grid fu" style="animation-delay:.15s">

        <!-- KITCHEN QUEUE / ACTIVE ORDERS -->
        <div class="panel">
            <div class="panel-head">
                <h3>
                    <span class="live-dot"></span>
                    <i class="fa-solid fa-fire-burner"></i>
                    Active Orders
                </h3>
                <div style="display:flex;align-items:center;gap:8px">
                    <span class="cnt-badge <?= mysqli_num_rows($kitchen_result) > 0 ? 'on' : '' ?>" id="kitchenCount">
                        <?= mysqli_num_rows($kitchen_result) ?> preparing
                    </span>
                    <button class="refresh-btn" onclick="fetchDashboardData()">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                </div>
            </div>

            <div class="kitchen-body" id="kitchenList">
            <?php
            mysqli_data_seek($kitchen_result, 0);
            $krows = [];
            while ($kr = mysqli_fetch_assoc($kitchen_result)) { $krows[] = $kr; }
            if (count($krows) > 0):
                foreach ($krows as $kr):
                    $mins = floor((time() - strtotime($kr['order_date'])) / 60);
                    $tc   = $mins >= 20 ? 'urgent' : ($mins >= 10 ? 'warn' : 'ok');
            ?>
            <div class="k-item">
                <div class="k-no">#<?= (int)$kr['daily_order_no'] ?></div>
                <div class="k-name"><?= htmlspecialchars($kr['customer_name']) ?></div>
                <div class="k-total">$<?= number_format((float)$kr['total'], 2) ?></div>
                <div class="k-timer <?= $tc ?>"><?= $mins ?>m</div>
                <a href="update_status.php?order_id=<?= $kr['order_id'] ?>&status=Completed" class="btn-ready">
                    <i class="fa-solid fa-check"></i> Ready
                </a>
            </div>
            <?php endforeach; else: ?>
            <div class="k-empty">
                <i class="fa-solid fa-circle-check"></i>
                <span>All clear — no orders preparing</span>
            </div>
            <?php endif; ?>
            </div>

            <div class="panel-foot">
                <span>Auto-refreshes every 5 s</span>
                <span id="lastUpdated"><?= date("g:i A") ?></span>
            </div>
        </div>

        <!-- TOP SELLERS -->
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fa-solid fa-trophy"></i> Top Sellers</h3>
                <span style="font-size:11px;color:var(--text-muted)">All time</span>
            </div>
            <div class="sellers-body">
            <?php
            $srows = [];
            while ($sr = mysqli_fetch_assoc($top_selling_result)) { $srows[] = $sr; }
            $maxs = count($srows) > 0 ? (int)max(array_column($srows, 'total_sold')) : 1;
            if (count($srows) > 0):
                foreach ($srows as $si => $sr):
                    $pct = $maxs > 0 ? round($sr['total_sold'] / $maxs * 100) : 0;
            ?>
            <div class="seller-row">
                <div class="s-rank <?= $si === 0 ? 'gold' : '' ?>"><?= $si + 1 ?></div>
                <img class="s-img"
                     src="<?= htmlspecialchars($sr['image']) ?>"
                     alt=""
                     onerror="this.style.visibility='hidden'">
                <div class="s-info">
                    <div class="s-name"><?= htmlspecialchars($sr['name']) ?></div>
                    <div class="s-track"><div class="s-bar" style="width:<?= $pct ?>%"></div></div>
                </div>
                <div class="s-count"><?= (int)$sr['total_sold'] ?></div>
            </div>
            <?php endforeach; else: ?>
            <div class="k-empty">
                <i class="fa-regular fa-chart-bar"></i>
                <span>No sales data yet</span>
            </div>
            <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- RECENT ORDERS -->
    <div class="panel fu" style="animation-delay:.2s">
        <div class="panel-head">
            <h3><i class="fa-solid fa-clock-rotate-left"></i> Recent Orders</h3>
            <a href="view_order.php" class="panel-link">View all <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <table class="orders-tbl">
            <thead>
                <tr>
                    <th style="width:72px">Order</th>
                    <th>Customer</th>
                    <th style="width:100px;text-align:right">Total</th>
                    <th style="width:155px">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (mysqli_num_rows($recent_orders) > 0): ?>
            <?php while ($ro = mysqli_fetch_assoc($recent_orders)):
                $sc = strtolower(str_replace(['payment',' '], '', $ro['status']));
                if (!in_array($sc, ['pending','paid','preparing','completed','cancelled','refunded','pendingpayment'])) $sc = 'pending';
            ?>
            <tr>
                <td><span class="o-no">#<?= (int)$ro['daily_order_no'] ?></span></td>
                <td>
                    <div class="cust-cell">
                        <div class="cust-av"><i class="fa-regular fa-user"></i></div>
                        <?= htmlspecialchars($ro['customer_name']) ?>
                    </div>
                </td>
                <td style="text-align:right;font-weight:700">$<?= number_format((float)$ro['total'], 2) ?></td>
                <td><span class="badge <?= $sc ?>"><?= htmlspecialchars($ro['status']) ?></span></td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr><td colspan="4"><div class="tbl-empty">
                <i class="fa-regular fa-rectangle-list"></i>
                <span>No orders today yet</span>
            </div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php else: /* non-admin/manager: show quick-access tiles */ ?>

    <!-- QUICK ACCESS GRID -->
    <div class="qa-grid fu" style="animation-delay:.1s">
        <?php if (can('find_orders')): ?>
        <a href="menu.php" class="qa-hero-btn">
            <i class="fa-solid fa-plus"></i>
            <span>Take New Order</span>
        </a>
        <?php endif; ?>

        <?php if (can('view_orders') || can('find_orders') || can('tables')): ?>
        <div class="qa-group">
            <div class="qa-group-label"><i class="fa-solid fa-receipt"></i> Orders</div>
            <div class="qa-tiles">
                <?php if (can('view_orders')): ?>
                <a href="view_order.php" class="qa-tile">
                    <i class="fa-solid fa-receipt"></i>
                    <span>Orders</span>
                </a>
                <?php endif; ?>
                <?php if (can('find_orders')): ?>
                <a href="find_order.php" class="qa-tile">
                    <?php if ($unpaid_count > 0): ?>
                    <span class="qa-tile-badge"><?= $unpaid_count ?></span>
                    <?php endif; ?>
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span>Find Order</span>
                </a>
                <?php endif; ?>
                <?php if (can('tables')): ?>
                <a href="tables.php" class="qa-tile">
                    <i class="fa-solid fa-table-cells"></i>
                    <span>Tables</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (can('products') || can('ingredients') || can('recipes')): ?>
        <div class="qa-group">
            <div class="qa-group-label"><i class="fa-solid fa-boxes-stacked"></i> Inventory</div>
            <div class="qa-tiles">
                <?php if (can('products')): ?>
                <a href="products.php" class="qa-tile">
                    <i class="fa-solid fa-cube"></i>
                    <span>Products</span>
                </a>
                <?php endif; ?>
                <?php if (can('ingredients')): ?>
                <a href="ingredients.php" class="qa-tile">
                    <i class="fa-solid fa-flask"></i>
                    <span>Ingredients</span>
                </a>
                <?php endif; ?>
                <?php if (can('recipes')): ?>
                <a href="recipes_view.php" class="qa-tile">
                    <?php if ($low_recipe_count > 0): ?>
                    <span class="qa-tile-badge" title="<?= $low_recipe_count ?> recipe<?= $low_recipe_count == 1 ? '' : 's' ?> low on ingredients"><?= $low_recipe_count ?></span>
                    <?php endif; ?>
                    <i class="fa-solid fa-utensils"></i>
                    <span>Drink Recipe</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (can('suppliers') || can('purchase_orders')): ?>
        <div class="qa-group">
            <div class="qa-group-label"><i class="fa-solid fa-truck-ramp-box"></i> Procurement</div>
            <div class="qa-tiles">
                <?php if (can('suppliers')): ?>
                <a href="suppliers.php" class="qa-tile">
                    <i class="fa-solid fa-truck-ramp-box"></i>
                    <span>Suppliers</span>
                </a>
                <?php endif; ?>
                <?php if (can('purchase_orders')): ?>
                <a href="purchase_orders.php" class="qa-tile">
                    <i class="fa-solid fa-file-invoice"></i>
                    <span>Purchase Orders</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (can('loyalty')): ?>
        <div class="qa-group">
            <div class="qa-group-label"><i class="fa-solid fa-star"></i> Loyalty</div>
            <div class="qa-tiles">
                <a href="loyalty_dashboard.php" class="qa-tile">
                    <i class="fa-solid fa-star"></i>
                    <span>Loyalty</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (can('employees') || can('attendance') || can('announcements')): ?>
        <div class="qa-group">
            <div class="qa-group-label"><i class="fa-solid fa-users"></i> Staff</div>
            <div class="qa-tiles">
                <?php if (can('employees')): ?>
                <a href="employees.php" class="qa-tile">
                    <i class="fa-solid fa-user-tie"></i>
                    <span>Employees</span>
                </a>
                <?php endif; ?>
                <?php if (can('attendance')): ?>
                <a href="attendance.php" class="qa-tile">
                    <i class="fa-solid fa-fingerprint"></i>
                    <span>Attendance</span>
                </a>
                <?php endif; ?>
                <?php if (can('announcements')): ?>
                <a href="announcements.php" class="qa-tile">
                    <i class="fa-solid fa-bullhorn"></i>
                    <span>Announcements</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (can('report')): ?>
        <div class="qa-group">
            <div class="qa-group-label"><i class="fa-solid fa-chart-simple"></i> Analytics</div>
            <div class="qa-tiles">
                <a href="report.php" class="qa-tile">
                    <i class="fa-solid fa-chart-simple"></i>
                    <span>Daily Report</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="qa-group">
            <div class="qa-group-label"><i class="fa-solid fa-circle-user"></i> Account</div>
            <div class="qa-tiles">
                <a href="profile.php" class="qa-tile">
                    <i class="fa-solid fa-circle-user"></i>
                    <span>My Profile</span>
                </a>
            </div>
        </div>
    </div>

    <?php endif; /* end admin/manager vs employee view */ ?>

</div><!-- /main -->
</div><!-- /layout -->

<script>
/* ── Sidebar clock ── */
function updateSidebarClock(){
    const el=document.getElementById('sidebarClock');
    if(!el)return;
    const now=new Date();
    let h=now.getHours();
    const ampm=h>=12?'PM':'AM';
    h=h%12; if(h===0)h=12;
    el.textContent=h+':'+String(now.getMinutes()).padStart(2,'0')+' '+ampm;
}
updateSidebarClock();
setInterval(updateSidebarClock,1000);

/* ── Time of day greeting ── */
(function(){
    const h=new Date().getHours();
    const g=h<12?'morning':h<17?'afternoon':'evening';
    document.getElementById('timeOfDay').textContent=g;
})();

/* ── Mobile sidebar (absent for roles without sidebar access) ── */
function toggleSidebar(){
    const sb = document.getElementById('sidebar');
    const ov = document.querySelector('.overlay');
    if (!sb || !ov) return;
    sb.classList.toggle('open');
    ov.classList.toggle('active');
}
function closeSidebar(){
    const sb = document.getElementById('sidebar');
    const ov = document.querySelector('.overlay');
    if (!sb || !ov) return;
    sb.classList.remove('open');
    ov.classList.remove('active');
}
document.addEventListener('keydown',e=>{
    if(e.key==='Escape') closeSidebar();
});
window.addEventListener('resize',()=>{
    if(window.innerWidth>768) closeSidebar();
});

/* ── Theme toggle ── */
function toggleTheme(){
    const html=document.documentElement;
    const icon=document.getElementById('themeIcon');
    const text=document.getElementById('themeText');
    if(html.getAttribute('data-theme')==='light'){
        html.removeAttribute('data-theme');
        icon.className='fa-solid fa-moon';
        text.textContent='Dark';
        localStorage.setItem('theme','dark');
    } else {
        html.setAttribute('data-theme','light');
        icon.className='fa-solid fa-sun';
        text.textContent='Light';
        localStorage.setItem('theme','light');
    }
}
document.addEventListener('DOMContentLoaded',()=>{
    if(localStorage.getItem('theme')==='light'){
        document.documentElement.setAttribute('data-theme','light');
        document.getElementById('themeIcon').className='fa-solid fa-sun';
        document.getElementById('themeText').textContent='Light';
    }
});

/* ── Toast ── */
function showToast(msg,type='success'){
    const c=document.getElementById('toastContainer');
    const t=document.createElement('div');
    t.className='toast '+type;
    const ic=type==='success'?'fa-check-circle':'fa-exclamation-circle';
    t.innerHTML=`<i class="fa-solid ${ic}"></i><span>${msg}</span><span class="close-toast" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></span>`;
    c.appendChild(t);
    setTimeout(()=>{ if(t.parentElement){ t.style.opacity='0'; t.style.transform='translateX(18px)'; setTimeout(()=>t.remove(),400); }},5000);
}
<?php if ($_flash_welcome): ?>
document.addEventListener('DOMContentLoaded',()=>showToast('Welcome back, <?= htmlspecialchars($admin_name, ENT_QUOTES) ?>!','success'));
<?php endif; ?>

async function toggleClock(){
    var btn = document.getElementById('clockBtn');
    if (!btn) return;
    var clocked = btn.dataset.clocked === '1';
    btn.disabled = true;
    btn.style.opacity = '.6';

    try {
        var resp = await fetch('attendance_action.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=' + (clocked ? 'clock_out' : 'clock_in')
        });
        var data = await resp.json();

        if (data.ok) {
            if (!clocked) {
                btn.dataset.clocked = '1';
                btn.innerHTML = '<i class="fa-solid fa-right-from-bracket"></i> Clock Out';
                btn.style.background = 'rgba(255,95,95,.08)';
                btn.style.borderColor = 'rgba(255,95,95,.25)';
                btn.style.color = '#ff6b6b';
                btn.title = 'Clocked in at ' + (data.time || '');
            } else {
                btn.dataset.clocked = '0';
                btn.innerHTML = '<i class="fa-solid fa-fingerprint"></i> Clock In';
                btn.style.background = 'rgba(85,224,135,.08)';
                btn.style.borderColor = 'rgba(85,224,135,.25)';
                btn.style.color = '#55e087';
                btn.title = 'Not clocked in';
            }
            showToast(data.msg, 'success');
        } else {
            showToast(data.msg, 'error');
        }
    } catch(e) {
        showToast('Connection error.', 'error');
    }

    btn.disabled = false;
    btn.style.opacity = '1';
}
<?php if ($_flash_stock_alert && $low_stock > 0 && can('ingredients')): ?>
document.addEventListener('DOMContentLoaded',()=>showToast('<?= $low_stock ?> ingredient<?= $low_stock!=1?"s are":" is"?> low on stock','error'));
<?php endif; ?>

/* ── AJAX polling (kitchen + KPIs) ── */
function fetchDashboardData(){
    fetch('dashboard_data.php')
        .then(r=>r.json())
        .then(d=>{
            const lu=document.getElementById('lastUpdated');
            if(lu) lu.textContent=new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});

            const rev=document.getElementById('kpiRevenue');
            const ord=document.getElementById('kpiOrders');
            const itm=document.getElementById('kpiItems');
            if(rev) rev.textContent=parseFloat(d.sales).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            if(ord) ord.textContent=d.total_orders;
            if(itm && d.items_sold!==undefined) itm.textContent=d.items_sold;

            const kc=document.getElementById('kitchenCount');
            const kl=document.getElementById('kitchenList');
            if(kc && d.kitchen_orders!==undefined){
                kc.textContent=d.kitchen_orders.length+' preparing';
                kc.className='cnt-badge'+(d.kitchen_orders.length>0?' on':'');
            }
            if(kl && d.kitchen_orders!==undefined){
                if(d.kitchen_orders.length>0){
                    kl.innerHTML=d.kitchen_orders.map(o=>{
                        const mins=Math.floor((Date.now()-new Date(o.order_date.replace(' ','T')))/60000);
                        const tc=mins>=20?'urgent':mins>=10?'warn':'ok';
                        return `<div class="k-item">
                            <div class="k-no">#${o.daily_order_no}</div>
                            <div class="k-name">${o.customer_name}</div>
                            <div class="k-total">$${parseFloat(o.total).toFixed(2)}</div>
                            <div class="k-timer ${tc}">${mins}m</div>
                            <a href="update_status.php?order_id=${o.order_id}&status=Completed" class="btn-ready">
                                <i class="fa-solid fa-check"></i> Ready
                            </a>
                        </div>`;
                    }).join('');
                } else {
                    kl.innerHTML='<div class="k-empty"><i class="fa-solid fa-circle-check"></i><span>All clear — no orders preparing</span></div>';
                }
            }
        })
        .catch(()=>{});
}
setInterval(fetchDashboardData,5000);
fetchDashboardData();

/* ── Collapsible sidebar groups ── */
function toggleGroup(label) {
    const items = label.nextElementSibling;
    if (!items || !items.classList.contains('nav-group-items')) return;
    const isOpen = label.classList.contains('open');
    label.classList.toggle('open', !isOpen);
    items.classList.toggle('collapsed', isOpen);
    const g = label.dataset.group;
    if (g) localStorage.setItem('nav_' + g, isOpen ? '0' : '1');
}
function initNavGroups() {
    document.querySelectorAll('.nav-group-label[data-group]').forEach(label => {
        const stored = localStorage.getItem('nav_' + label.dataset.group);
        if (stored === null) return; // keep PHP default (overview open, rest collapsed)
        const items = label.nextElementSibling;
        if (!items) return;
        label.classList.toggle('open', stored === '1');
        items.classList.toggle('collapsed', stored !== '1');
    });
}
document.addEventListener('DOMContentLoaded', initNavGroups);

/* ── Idle auto-logout (30 min, warn at 25) ── */
(function(){
    const WARN=25*60*1000, OUT=30*60*1000;
    let wt,lt,wel;
    function reset(){
        clearTimeout(wt);clearTimeout(lt);
        if(wel){wel.remove();wel=null;}
        wt=setTimeout(()=>{
            wel=document.createElement('div');
            wel.style.cssText='position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:99999;background:var(--surface);border:1px solid rgba(245,158,11,.4);border-radius:var(--r);padding:13px 18px;font-family:Inter,sans-serif;font-size:12.5px;color:var(--text);display:flex;align-items:center;gap:10px;box-shadow:var(--shadow-lg);white-space:nowrap';
            wel.innerHTML='<i class="fa-solid fa-clock" style="color:var(--amber)"></i><span>Session expires in <strong>5 minutes</strong> due to inactivity.</span><button onclick="this.parentElement.remove()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:15px;margin-left:8px">×</button>';
            document.body.appendChild(wel);
        },WARN);
        lt=setTimeout(()=>{window.location.href='logout.php?timeout=1';},OUT);
    }
    ['mousemove','keydown','click','scroll','touchstart'].forEach(ev=>document.addEventListener(ev,reset,{passive:true}));
    reset();
})();
</script>
</body>
</html>
