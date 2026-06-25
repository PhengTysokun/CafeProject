<?php
require 'auth.php';
require 'config.php';
if (!can('ingredients')) { header("Location: ingredients.php"); exit; }

$message = "";

// Load suppliers for dropdown
$supplierList = [];
$sr = $conn->query("SELECT supplier_id, name FROM suppliers ORDER BY name ASC");
if ($sr) while ($row = $sr->fetch_assoc()) $supplierList[] = $row;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name        = trim($_POST['ingredient_name'] ?? '');
    $unit        = trim($_POST['unit'] ?? '');
    $cost        = (float)($_POST['cost_price'] ?? 0);
    $buyqty      = (float)($_POST['purchase_qty'] ?? 0);
    $min         = (float)($_POST['minimum_stock'] ?? 0);
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);

    if ($name === '' || $unit === '' || $buyqty <= 0 || $cost < 0) {
        $message = "Please fill all fields correctly.";
    } else {
        $safeName = mysqli_real_escape_string($conn, $name);
        $safeUnit = mysqli_real_escape_string($conn, $unit);

        $new_unit_cost = ($buyqty > 0) ? ($cost / $buyqty) : 0;

        $check = mysqli_query($conn, "
            SELECT ingredient_id, stock_quantity, minimum_stock, cost_per_unit
            FROM ingredients
            WHERE LOWER(ingredient_name) = LOWER('$safeName')
            LIMIT 1
        ");

        if ($check && mysqli_num_rows($check) > 0) {
            // Ingredient exists -> restock using weighted average cost
            $old = mysqli_fetch_assoc($check);

            $ingredient_id = (int)$old['ingredient_id'];
            $old_stock     = (float)$old['stock_quantity'];
            $old_min       = (float)$old['minimum_stock'];
            $old_cpu       = (float)$old['cost_per_unit'];

            $new_stock = $old_stock + $buyqty;
            $final_min = ($old_min > 0) ? $old_min : $min;

            $old_value = $old_stock * $old_cpu;
            $new_value = $buyqty * $new_unit_cost;
            $final_cpu = ($new_stock > 0) ? (($old_value + $new_value) / $new_stock) : 0;

            $supplierSql = $supplier_id > 0 ? ", supplier_id = $supplier_id" : "";
            mysqli_query($conn, "
                UPDATE ingredients
                SET
                    unit = '$safeUnit',
                    stock_quantity = $new_stock,
                    minimum_stock = $final_min,
                    cost_price = $cost,
                    purchase_qty = $buyqty,
                    cost_per_unit = $final_cpu
                    $supplierSql
                WHERE ingredient_id = $ingredient_id
            ");
        } else {
            // New ingredient
            $supplierCol = $supplier_id > 0 ? ", supplier_id" : "";
            $supplierVal = $supplier_id > 0 ? ", $supplier_id" : "";
            mysqli_query($conn, "
                INSERT INTO ingredients
                (ingredient_name, unit, stock_quantity, minimum_stock, cost_price, purchase_qty, cost_per_unit $supplierCol)
                VALUES
                ('$safeName', '$safeUnit', $buyqty, $min, $cost, $buyqty, $new_unit_cost $supplierVal)
            ");
        }

        header("Location: ingredients.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Ingredient | Obsidian Cafe</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* ── RESET & ROOT ── */
:root {
    --bg: #0c0c0c;
    --bg-card: #121212;
    --bg-card-hover: #181818;
    --border: #222;
    --border-hover: #333;
    --accent: #d1904b;
    --accent-light: #e8b87a;
    --accent-dark: #a0702a;
    --text: #ffffff;
    --text-muted: #aaa;
    --text-light: #f5f5f5;
    --danger: #ff7b7b;
    
    /* ── Shadow System ── */
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
    --shadow-accent: 0 4px 20px rgba(209, 144, 75, 0.15);
    
    /* ── Transitions ── */
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ── RESET: Remove all scrollbars ── */
* { 
    box-sizing: border-box; 
    margin: 0; 
    padding: 0; 
}

html, body {
    width: 100%;
    height: 100%;
    overflow: hidden;
    margin: 0;
    padding: 0;
}

body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    position: relative;
}

/* ── Background decorative elements ── */
body::before {
    content: '';
    position: absolute;
    top: -200px;
    right: -200px;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(209, 144, 75, 0.06) 0%, transparent 70%);
    border-radius: 50%;
    z-index: 0;
}

body::after {
    content: '';
    position: absolute;
    bottom: -200px;
    left: -200px;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(209, 144, 75, 0.04) 0%, transparent 70%);
    border-radius: 50%;
    z-index: 0;
}

/* ── Custom Scrollbar ── */
::-webkit-scrollbar { width: 8px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb {
    background: var(--accent);
    border-radius: 10px;
}
::-webkit-scrollbar-thumb:hover { background: var(--accent-dark); }

/* ── Back Button ── */
.back-btn {
    position: fixed;
    top: 24px;
    left: 24px;
    display: flex;
    align-items: center;
    gap: 7px;
    color: #d1904b;
    text-decoration: none;
    font-weight: 600;
    font-size: 15px;
    transition: var(--transition);
    z-index: 10;
    padding: 10px 18px;
    border-radius: 12px;
    background: rgba(209,144,75,.08);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(209,144,75,.35);
}

.back-btn:hover {
    color: var(--accent);
    transform: translateX(-4px);
    border-color: var(--border-hover);
    box-shadow: var(--shadow-sm);
}

.back-btn i {
    font-size: 18px;
}

/* ── Card Container ── */
.card-wrapper {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 480px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 4px;
}

/* ── Hide scrollbar for card wrapper ── */
.card-wrapper::-webkit-scrollbar {
    width: 4px;
}
.card-wrapper::-webkit-scrollbar-thumb {
    background: var(--accent);
    border-radius: 4px;
}

/* ── Card ── */
.card {
    background: rgba(18, 18, 18, 0.95);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 36px 32px;
    box-shadow: var(--shadow-lg);
    transition: var(--transition);
}

.card:hover {
    border-color: var(--border-hover);
    box-shadow: var(--shadow-lg);
}

/* ── Header ── */
.card-header {
    text-align: center;
    margin-bottom: 28px;
}

.card-header .icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 16px;
    background: rgba(209, 144, 75, 0.12);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: var(--accent);
    border: 1px solid rgba(209, 144, 75, 0.15);
}

.card-header h2 {
    margin: 0;
    color: var(--text);
    font-size: 24px;
    font-weight: 700;
}

.card-header h2 span {
    color: var(--accent);
}

.card-header .sub {
    color: var(--text-muted);
    font-size: 13px;
    margin-top: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.card-header .sub i {
    font-size: 12px;
}

/* ── Error Message ── */
.error {
    background: rgba(255, 123, 123, 0.08);
    color: var(--danger);
    padding: 12px 16px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid rgba(255, 123, 123, 0.15);
    animation: shake 0.5s ease;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-8px); }
    40% { transform: translateX(8px); }
    60% { transform: translateX(-8px); }
    80% { transform: translateX(8px); }
}

.error i {
    font-size: 18px;
}

/* ── Form ── */
form {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

/* ── Form Group ── */
.form-group {
    margin-bottom: 6px;
}

/* ── Label ── */
label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--accent-light);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}

label i {
    font-size: 14px;
}

/* ── Input ── */
.input-wrapper {
    position: relative;
}

.input-wrapper input,
.input-wrapper select {
    width: 100%;
    padding: 14px 16px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: #161616;
    color: var(--text);
    font-size: 15px;
    font-family: 'Poppins', sans-serif;
    transition: var(--transition);
    outline: none;
    appearance: none;
}
.input-wrapper select option { background: #1a1a1a; }

.input-wrapper input::placeholder {
    color: #555;
}

.input-wrapper input:focus,
.input-wrapper select:focus {
    border-color: var(--accent);
    background: #1a1a1a;
    box-shadow: var(--shadow-accent);
}

.input-wrapper input:hover,
.input-wrapper select:hover {
    border-color: var(--border-hover);
}

/* ── Help Text ── */
.help {
    color: var(--text-muted);
    font-size: 12px;
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
    line-height: 1.5;
    padding-left: 4px;
}

.help i {
    font-size: 12px;
    color: var(--accent);
}

/* ── Submit Button (FIXED GLOW) ── */
.btn-submit {
    margin-top: 24px;
    width: 100%;
    padding: 16px;
    border: none;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--accent), var(--accent-light));
    color: #000;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: var(--transition);
    font-family: 'Poppins', sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    position: relative;
    overflow: hidden;
}

/* ── FIXED: Full button glow ── */
.btn-submit::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(255, 255, 255, 0.25);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width 0.6s ease, height 0.6s ease;
    pointer-events: none;
}

.btn-submit:hover::after {
    width: 600px;
    height: 600px;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-accent);
    filter: brightness(1.05);
}

.btn-submit:active {
    transform: translateY(0);
}

/* ── ANIMATIONS ── */
@keyframes cardEntrance {
    from { opacity:0; transform:translateY(32px) scale(.96); }
    to   { opacity:1; transform:translateY(0)    scale(1); }
}
@keyframes iconPulse {
    0%,100% { box-shadow: 0 0 0 0   rgba(209,144,75,0); }
    50%     { box-shadow: 0 0 0 10px rgba(209,144,75,.12); }
}
@keyframes fadeUpItem {
    from { opacity:0; transform:translateY(12px); }
    to   { opacity:1; transform:translateY(0); }
}
@keyframes fadeDown {
    from { opacity:0; transform:translateY(-12px); }
    to   { opacity:1; transform:translateY(0); }
}

.back-btn { animation: fadeDown .4s ease both; }
.card { animation: cardEntrance .55s cubic-bezier(0.34,1.56,0.64,1) .05s both; }
.card-header { animation: fadeUpItem .4s .25s ease both; opacity: 0; }
.card-header .icon { animation: iconPulse 2.8s 1s ease-in-out infinite; }

.form-group:nth-child(1) { animation: fadeUpItem .35s .32s ease both; opacity: 0; }
.form-group:nth-child(2) { animation: fadeUpItem .35s .39s ease both; opacity: 0; }
.form-group:nth-child(3) { animation: fadeUpItem .35s .46s ease both; opacity: 0; }
.form-group:nth-child(4) { animation: fadeUpItem .35s .53s ease both; opacity: 0; }
.form-group:nth-child(5) { animation: fadeUpItem .35s .60s ease both; opacity: 0; }
.form-group:nth-child(6) { animation: fadeUpItem .35s .67s ease both; opacity: 0; }
.btn-submit              { animation: fadeUpItem .35s .75s ease both; opacity: 0; }

/* ── Responsive ── */
@media (max-width: 576px) {
    .back-btn {
        top: 16px;
        left: 16px;
        font-size: 13px;
        padding: 8px 14px;
    }
    
    .card {
        padding: 24px 20px;
        border-radius: 16px;
    }
    
    .card-header h2 {
        font-size: 20px;
    }
    
    .card-header .icon {
        width: 52px;
        height: 52px;
        font-size: 22px;
    }
    
    .input-wrapper input {
        padding: 12px 14px;
        font-size: 14px;
    }
    
    .btn-submit {
        padding: 14px;
        font-size: 14px;
    }
}
</style>
</head>

<body>

<!-- Back Button -->
<a class="back-btn" href="ingredients.php">
    <i class="fa-solid fa-arrow-left"></i> Back to Ingredients
</a>

<!-- Card -->
<div class="card-wrapper">
    <div class="card">
        <!-- Header -->
        <div class="card-header">
            <div class="icon">
                <i class="fa-solid fa-box-open"></i>
            </div>
            <h2>Add <span>Ingredient</span></h2>
            <div class="sub">
                <i class="fa-solid fa-circle-info"></i>
                New ingredient = create item • Existing ingredient = add stock
            </div>
        </div>

        <!-- Error -->
        <?php if ($message !== ""): ?>
            <div class="error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="post">
            <!-- Ingredient Name -->
            <div class="form-group">
                <label>
                    <i class="fa-solid fa-tag"></i> Ingredient Name
                </label>
                <div class="input-wrapper">
                    <input name="ingredient_name" required placeholder="e.g. Coffee Bean">
                </div>
            </div>

            <!-- Unit -->
            <div class="form-group">
                <label>
                    <i class="fa-solid fa-weight-scale"></i> Unit
                </label>
                <div class="input-wrapper">
                    <input name="unit" required placeholder="e.g. g, ml, kg, oz">
                </div>
            </div>

            <!-- Cost Price -->
            <div class="form-group">
                <label>
                    <i class="fa-solid fa-dollar-sign"></i> Cost Price ($)
                </label>
                <div class="input-wrapper">
                    <input type="number" step="0.01" name="cost_price" required placeholder="e.g. 120.00">
                </div>
            </div>

            <!-- Purchase Quantity -->
            <div class="form-group">
                <label>
                    <i class="fa-solid fa-cubes"></i> Purchase Quantity
                </label>
                <div class="input-wrapper">
                    <input type="number" step="0.01" name="purchase_qty" required placeholder="e.g. 1000">
                </div>
                <div class="help">
                    <i class="fa-solid fa-lightbulb"></i>
                    This is how much stock you bought this time.
                </div>
            </div>

            <!-- Minimum Stock -->
            <div class="form-group">
                <label>
                    <i class="fa-solid fa-triangle-exclamation"></i> Minimum Stock
                </label>
                <div class="input-wrapper">
                    <input type="number" step="0.01" name="minimum_stock" placeholder="e.g. 100 (only for new ingredients)">
                </div>
                <div class="help">
                    <i class="fa-solid fa-lightbulb"></i>
                    For existing ingredients, the old minimum stock stays the same.
                </div>
            </div>

            <!-- Preferred Supplier -->
            <?php if (!empty($supplierList)): ?>
            <div class="form-group">
                <label>
                    <i class="fa-solid fa-truck-ramp-box"></i> Preferred Supplier <span style="font-size:11px;color:#555;font-weight:400">(optional)</span>
                </label>
                <div class="input-wrapper">
                    <select name="supplier_id">
                        <option value="0">— None —</option>
                        <?php foreach ($supplierList as $sup): ?>
                        <option value="<?= $sup['supplier_id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <!-- Submit -->
            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-plus"></i> Save Ingredient
            </button>
        </form>
    </div>
</div>

</body>
</html>