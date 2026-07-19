<?php
// nav_menu.php — canonical permission→navigation registry (single source of truth).
// Access control stays on each page (can() guards); this drives discovery/links only.
// Requires: auth.php already loaded (can() available) and a mysqli $conn.

// Route/icon registry keyed by permission slug: [href, icon, admin_only?].
// Perms absent here are non-navigable by design (manage_recipes, my_profile).
// `dashboard` is intentionally absent — the home link is hardcoded per layout.
$NAV_REGISTRY = [
    'find_orders'         => ['find_order.php',            'fa-magnifying-glass'],
    'view_orders'         => ['view_order.php',            'fa-receipt'],
    'loyalty'             => ['loyalty_dashboard.php',     'fa-star'],
    'products'            => ['products.php',              'fa-cube'],
    'ingredients'         => ['ingredients.php',           'fa-flask'],
    'recipes'             => ['recipes_view.php',          'fa-utensils'],
    'suppliers'           => ['suppliers.php',             'fa-truck-ramp-box'],
    'purchase_orders'     => ['purchase_orders.php',       'fa-file-invoice'],
    'stock_count'         => ['stock_count.php',           'fa-clipboard-list'],
    'cash_reconciliation' => ['reconciliation_report.php', 'fa-cash-register'],
    'report'              => ['report.php',                'fa-chart-column'],
    'barista_station'     => ['barista_display.php',       'fa-mug-hot'],
    'customer_display'    => ['customer_display.php',      'fa-display'],
    'employees'           => ['employees.php',             'fa-user-tie'],
    'attendance'          => ['attendance.php',            'fa-fingerprint'],
    'announcements'       => ['announcements.php',         'fa-bullhorn'],
    'promotions'          => ['settings.php',              'fa-gear'],
    'reset_password'      => ['admin_reset_password.php',  'fa-key'],
    'manage_roles'        => ['manage_roles.php',          'fa-shield-halved', true],
];

// Explicit section display order — never trust DB module/insertion order.
$NAV_SECTION_ORDER = ['Orders','Operations','Inventory','Procurement','Reconciliation','Loyalty','Analytics','Staff','Admin'];

/**
 * Nav items the current user may see. Each: slug,label,href,icon,section,admin_only.
 * Filtered by admin_only + can(slug). Sorted by (section index, sort_order, id).
 */
function nav_items(mysqli $conn): array {
    global $NAV_REGISTRY, $NAV_SECTION_ORDER;
    $isAdmin = (($_SESSION['role'] ?? '') === 'admin');
    $rows = [];
    $res = $conn->query("SELECT id, slug, name, module, sort_order FROM permissions");
    while ($res && $p = $res->fetch_assoc()) {
        $slug = $p['slug'];
        if (!isset($NAV_REGISTRY[$slug])) continue;                 // non-navigable
        [$href, $icon, $adminOnly] = array_pad($NAV_REGISTRY[$slug], 3, false);
        if ($adminOnly && !$isAdmin) continue;
        if (!can($slug)) continue;                                  // grant gate
        $secIdx = array_search($p['module'], $NAV_SECTION_ORDER, true);
        $rows[] = [
            'slug'=>$slug, 'label'=>$p['name'], 'href'=>$href, 'icon'=>$icon,
            'section'=>$p['module'], 'admin_only'=>(bool)$adminOnly,
            '_sec'=>($secIdx===false ? PHP_INT_MAX : $secIdx),
            '_ord'=>(int)$p['sort_order'], '_id'=>(int)$p['id'],
        ];
    }
    usort($rows, fn($a,$b) => [$a['_sec'],$a['_ord'],$a['_id']] <=> [$b['_sec'],$b['_ord'],$b['_id']]);
    return $rows;
}

/** nav_items() grouped by section, groups already in $NAV_SECTION_ORDER. */
function nav_items_grouped(mysqli $conn): array {
    $g = [];
    foreach (nav_items($conn) as $it) $g[$it['section']][] = $it;
    return $g;
}
