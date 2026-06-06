<?php
date_default_timezone_set('Asia/Phnom_Penh');

// Database connection
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "db_coffee";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ── CRITICAL: Force utf8mb4 so 4-byte emoji are read correctly ──
$conn->set_charset('utf8mb4');

// --- Check if constants are already defined before defining them ---
if (!defined('PAYMENT_API_URL')) {
    define('PAYMENT_API_URL', 'https://api.example.com/payment');
}
if (!defined('PAYMENT_API_TOKEN')) {
    define('PAYMENT_API_TOKEN', 'your_token_here');
}

// ── LOAD SETTINGS FROM DB ──
$_cafe_settings = [];
$_sr = $conn->query("SELECT setting_key, setting_value FROM settings");
if ($_sr) { while ($row = $_sr->fetch_assoc()) $_cafe_settings[$row['setting_key']] = $row['setting_value']; }

// ── Date-range check for promotions ──
$_today = date('Y-m-d');
$_hh_sd = $_cafe_settings['happy_hour_start_date'] ?? '';
$_hh_ed = $_cafe_settings['happy_hour_end_date']   ?? '';
$_hh_in_range = (($_hh_sd === '' || $_today >= $_hh_sd) && ($_hh_ed === '' || $_today <= $_hh_ed));
$_bx_sd = $_cafe_settings['buy_x_start_date'] ?? '';
$_bx_ed = $_cafe_settings['buy_x_end_date']   ?? '';
$_bx_in_range = (($_bx_sd === '' || $_today >= $_bx_sd) && ($_bx_ed === '' || $_today <= $_bx_ed));

if (!defined('HAPPY_HOUR_ENABLED'))  define('HAPPY_HOUR_ENABLED',  (bool)(int)($_cafe_settings['happy_hour_enabled']  ?? 1) && $_hh_in_range);
if (!defined('HAPPY_HOUR_START'))    define('HAPPY_HOUR_START',    (int)($_cafe_settings['happy_hour_start']    ?? 14));
if (!defined('HAPPY_HOUR_END'))      define('HAPPY_HOUR_END',      (int)($_cafe_settings['happy_hour_end']      ?? 16));
if (!defined('HAPPY_HOUR_DISCOUNT')) define('HAPPY_HOUR_DISCOUNT', (int)($_cafe_settings['happy_hour_discount'] ?? 20));
if (!defined('BUY_X_GET_1_ENABLED')) define('BUY_X_GET_1_ENABLED',(bool)(int)($_cafe_settings['buy_x_get_1_enabled'] ?? 1) && $_bx_in_range);
if (!defined('BUY_X_COUNT'))         define('BUY_X_COUNT',         (int)($_cafe_settings['buy_x_count']         ?? 3));
if (!defined('KHR_RATE'))            define('KHR_RATE',             (int)($_cafe_settings['khr_exchange_rate']   ?? 4100));
if (!defined('FREE_ITEM_PRODUCT_ID')) define('FREE_ITEM_PRODUCT_ID', (int)($_cafe_settings['free_item_product_id'] ?? 0));
unset($_cafe_settings, $_sr, $_today, $_hh_sd, $_hh_ed, $_hh_in_range, $_bx_sd, $_bx_ed, $_bx_in_range);

// ── One-time schema migrations ──
$conn->query("ALTER TABLE employees ADD COLUMN IF NOT EXISTS user_id INT NULL");
$conn->query("CREATE TABLE IF NOT EXISTS login_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_ip_time (ip, attempted_at)) DEFAULT CHARSET=utf8mb4");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS badge_text VARCHAR(40) NULL DEFAULT NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS table_number VARCHAR(10) NULL DEFAULT NULL");

// ── New tables: categories, customers, cafe_tables ──
$conn->query("CREATE TABLE IF NOT EXISTS categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) DEFAULT 'fa-circle',
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1
) DEFAULT CHARSET=utf8mb4");

if ((int)$conn->query("SELECT COUNT(*) FROM categories")->fetch_row()[0] === 0) {
    $conn->query("INSERT INTO categories (slug, name, icon, display_order) VALUES
        ('Iced','Iced Beverages','fa-snowflake',1),
        ('Hot','Hot Beverages','fa-mug-hot',2),
        ('Frappe','Frappes','fa-blender',3),
        ('Juice','Juices','fa-lemon',4),
        ('Milk Tea','Milk Tea','fa-circle-dot',5)");
}

$conn->query("CREATE TABLE IF NOT EXISTS customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4");

$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_id INT NULL");

$conn->query("CREATE TABLE IF NOT EXISTS cafe_tables (
    table_id INT AUTO_INCREMENT PRIMARY KEY,
    table_number VARCHAR(10) NOT NULL UNIQUE,
    capacity INT DEFAULT 4,
    status ENUM('available','occupied') DEFAULT 'available'
) DEFAULT CHARSET=utf8mb4");

if ((int)$conn->query("SELECT COUNT(*) FROM cafe_tables")->fetch_row()[0] === 0) {
    $conn->query("INSERT INTO cafe_tables (table_number, capacity) VALUES
        ('T1',2),('T2',2),('T3',4),('T4',4),('T5',4),('T6',6),('T7',6),('VIP',8)");
}

// ── RBAC: create tables ──
$conn->query("CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    module VARCHAR(50) NOT NULL,
    sort_order INT DEFAULT 0
) DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS role_permissions (
    role VARCHAR(50) NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role, permission_id)
) DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) DEFAULT 'fa-user',
    color VARCHAR(20) DEFAULT '#888888',
    description VARCHAR(200) DEFAULT '',
    is_system TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4");

if ((int)$conn->query("SELECT COUNT(*) FROM roles")->fetch_row()[0] === 0) {
    $conn->query("INSERT INTO roles (slug, name, icon, color, description, is_system) VALUES
        ('admin',   'Admin',   'fa-user-shield',  '#d1904b', 'Full system access — cannot be restricted', 1),
        ('manager', 'Manager', 'fa-user-tie',     '#3498db', 'Operational access — configure below',     1),
        ('staff',   'Staff',   'fa-user',         '#55e087', 'Limited access — configure below',         1)");
}

// ── RBAC: seed permissions + defaults (runs once) ──
if ((int)$conn->query("SELECT COUNT(*) FROM permissions")->fetch_row()[0] === 0) {
    $perms = [
        ['Dashboard',          'dashboard',       'Overview',    1],
        ['Find Unpaid Orders', 'find_orders',     'Orders',      2],
        ['View Orders',        'view_orders',     'Orders',      3],
        ['Loyalty Card',       'loyalty',         'Loyalty',     4],
        ['Products',           'products',        'Inventory',   5],
        ['Ingredients',        'ingredients',     'Inventory',   6],
        ['Drink Recipe',       'recipes',         'Inventory',   7],
        ['Suppliers',          'suppliers',       'Procurement', 8],
        ['Purchase Orders',    'purchase_orders', 'Procurement', 9],
        ['Daily Report',       'report',          'Analytics',   10],
        ['Employees',          'employees',       'Staff',       11],
        ['Announcements',      'announcements',   'Staff',       12],
        ['Attendance',         'attendance',      'Staff',       13],
        ['Promotions',         'promotions',      'Staff',       14],
        ['Manage Roles',       'manage_roles',    'Admin',       15],
    ];
    $ps = $conn->prepare("INSERT IGNORE INTO permissions (name,slug,module,sort_order) VALUES (?,?,?,?)");
    foreach ($perms as $p) { $ps->bind_param("sssi",$p[0],$p[1],$p[2],$p[3]); $ps->execute(); }

    // Default manager permissions
    foreach (['dashboard','find_orders','view_orders','loyalty','products','ingredients',
              'recipes','suppliers','purchase_orders','report','announcements','attendance','promotions'] as $slug)
        $conn->query("INSERT IGNORE INTO role_permissions (role,permission_id) SELECT 'manager',id FROM permissions WHERE slug='$slug'");

    // Default staff permissions
    foreach (['dashboard','find_orders','view_orders','loyalty','announcements','attendance'] as $slug)
        $conn->query("INSERT IGNORE INTO role_permissions (role,permission_id) SELECT 'staff',id FROM permissions WHERE slug='$slug'");
}

// ── SANITIZE FUNCTION ──
if (!function_exists('sanitizeForReceipt')) {
    function sanitizeForReceipt(string $text): string {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = iconv('UTF-8', 'ASCII//IGNORE', $text);
        $text = preg_replace('/\s{2,}/', ' ', $text ?? '');
        return trim($text);
    }
}

// ── LOYALTY SYSTEM FUNCTIONS ──

if (!function_exists('generateLoyaltyId')) {
    function generateLoyaltyId() {
        return 'CARD-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('getLoyaltyCard')) {
    function getLoyaltyCard($conn, $loyalty_id) {
        $stmt = $conn->prepare("
            SELECT * FROM loyalty_cards
            WHERE loyalty_id = ? AND is_active = 1
        ");
        $stmt->bind_param("s", $loyalty_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('getLoyaltyHistory')) {
    function getLoyaltyHistory($conn, $card_id, $limit = 10) {
        $stmt = $conn->prepare("
            SELECT * FROM loyalty_history
            WHERE card_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $card_id, $limit);
        $stmt->execute();
        return $stmt->get_result();
    }
}

if (!function_exists('getAvailableRewards')) {
    function getAvailableRewards($conn) {
        $stmt = $conn->prepare("SELECT * FROM rewards WHERE is_active = 1 ORDER BY points_required ASC");
        $stmt->execute();
        return $stmt->get_result();
    }
}

// ── RBAC: can() — check if current session role has a permission ──
if (!function_exists('can')) {
    function can(string $slug): bool {
        global $conn;
        static $perms    = null;
        static $is_admin = null;
        if ($is_admin === null) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $role     = $_SESSION['role'] ?? 'staff';
            $is_admin = ($role === 'admin');
            if (!$is_admin) {
                $perms = [];
                $r = $conn->prepare("SELECT p.slug FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id WHERE rp.role=?");
                $r->bind_param("s", $role);
                $r->execute();
                $res = $r->get_result();
                while ($row = $res->fetch_assoc()) $perms[$row['slug']] = true;
            }
        }
        return $is_admin || isset($perms[$slug]);
    }
}
?>