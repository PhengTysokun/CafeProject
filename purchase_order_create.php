<?php
require 'auth.php';
require 'config.php';
if (!can('purchase_orders')) { header("Location: purchase_orders.php"); exit; }

$created_by = $_SESSION['username'] ?? 'Admin';

// Pre-select supplier from URL
$pre_supplier = (int)($_GET['supplier_id'] ?? 0);

// ── HANDLE SUBMIT ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $notes       = trim($_POST['notes'] ?? '');
    $status      = ($_POST['submit_type'] ?? '') === 'order' ? 'Ordered' : 'Draft';
    $items       = $_POST['items'] ?? [];

    $errors = [];
    if ($supplier_id <= 0) $errors[] = 'Select a supplier.';
    $valid_items = [];
    foreach ($items as $item) {
        $iid  = (int)($item['ingredient_id'] ?? 0);
        $qty  = (float)($item['qty'] ?? 0);
        $cost = (float)($item['unit_cost'] ?? 0);
        if ($iid > 0 && $qty > 0) $valid_items[] = ['iid'=>$iid,'qty'=>$qty,'cost'=>$cost];
    }
    if (empty($valid_items)) $errors[] = 'Add at least one item with a quantity.';

    if (empty($errors)) {
        $total = array_sum(array_map(fn($i) => $i['qty'] * $i['cost'], $valid_items));
        $year  = date('Y');
        $cnt   = $conn->query("SELECT COUNT(*)+1 AS n FROM purchase_orders WHERE YEAR(created_at)='$year'")->fetch_assoc()['n'];
        $po_number = 'PO-' . $year . '-' . str_pad($cnt, 3, '0', STR_PAD_LEFT);
        $ordered_at = $status === 'Ordered' ? date('Y-m-d H:i:s') : null;

        $conn->begin_transaction();
        try {
            $s1 = $conn->prepare("INSERT INTO purchase_orders (po_number,supplier_id,status,notes,total_cost,ordered_at,created_by) VALUES (?,?,?,?,?,?,?)");
            $s1->bind_param('sissdss', $po_number, $supplier_id, $status, $notes, $total, $ordered_at, $created_by);
            $s1->execute();
            $po_id = $conn->insert_id;

            $s2 = $conn->prepare("INSERT INTO purchase_order_items (po_id,ingredient_id,qty_ordered,unit_cost) VALUES (?,?,?,?)");
            foreach ($valid_items as $vi) {
                $s2->bind_param('iidd', $po_id, $vi['iid'], $vi['qty'], $vi['cost']);
                $s2->execute();
            }
            $conn->commit();
            header("Location: purchase_order_view.php?po_id=$po_id&created=1");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Load suppliers
$suppliers = [];
$sres = $conn->query("SELECT supplier_id, name FROM suppliers ORDER BY name ASC");
if ($sres) while ($r = $sres->fetch_assoc()) $suppliers[] = $r;

// Load ingredients
$ingredients = [];
$ires = $conn->query("SELECT ingredient_id, ingredient_name, unit, cost_per_unit FROM ingredients ORDER BY ingredient_name ASC");
if ($ires) while ($r = $ires->fetch_assoc()) $ingredients[] = $r;

function he($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>New Purchase Order | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
    --bg:#0b0b0b; --bg-card:#131313; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --success:#55e087; --danger:#ff5f5f; --warning:#f1c40f; --info:#3498db;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35);
    --shadow-lg:0 8px 40px rgba(0,0,0,.55);
    --shadow-accent:0 4px 20px rgba(209,144,75,.18);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

.topbar{display:flex;align-items:center;gap:12px;padding:18px 32px;border-bottom:1px solid var(--border);background:var(--bg-card);position:sticky;top:0;z-index:100;}
.topbar-back{display:flex;align-items:center;gap:6px;color:var(--text-muted);text-decoration:none;font-size:13px;font-weight:500;padding:6px 12px;border-radius:8px;border:1px solid var(--border);transition:var(--transition);}
.topbar-back:hover{color:var(--accent);border-color:var(--accent);}
.topbar-title{font-size:18px;font-weight:700;color:var(--text-light);flex:1;}
.topbar-title i{color:var(--accent);margin-right:8px;}

.content{max-width:960px;margin:0 auto;padding:32px 24px;}

.layout{display:grid;grid-template-columns:320px 1fr;gap:24px;align-items:start;}

.card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:24px;}
.card-title{font-size:13px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.card-title i{color:var(--accent);}

.field{margin-bottom:16px;}
.field label{display:block;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.field select,.field textarea,.field input[type=text],.field input[type=number]{
    width:100%;background:var(--bg-input);border:1px solid var(--border);
    border-radius:8px;padding:10px 14px;color:var(--text);font-family:'Poppins',sans-serif;font-size:13px;
    transition:var(--transition);
}
.field select:focus,.field textarea:focus,.field input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(209,144,75,.12);}
.field select option{background:#1a1a1a;}
.field textarea{resize:vertical;min-height:80px;}

/* Items table */
.items-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.items-header{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid var(--border);}
.items-title{font-size:13px;font-weight:700;color:var(--text-light);display:flex;align-items:center;gap:8px;}
.items-title i{color:var(--accent);}

table.items-tbl{width:100%;border-collapse:collapse;}
table.items-tbl th{padding:10px 14px;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.02);}
table.items-tbl td{padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
table.items-tbl tbody tr:last-child td{border-bottom:none;}

table.items-tbl select,table.items-tbl input{
    background:var(--bg-input);border:1px solid var(--border);border-radius:7px;
    padding:7px 10px;color:var(--text);font-family:'Poppins',sans-serif;font-size:12px;
    width:100%;transition:var(--transition);
}
table.items-tbl select:focus,table.items-tbl input:focus{outline:none;border-color:var(--accent);}
table.items-tbl select option{background:#1a1a1a;}

.btn-remove{background:none;border:none;color:var(--danger);font-size:14px;cursor:pointer;padding:4px 8px;border-radius:6px;transition:var(--transition);}
.btn-remove:hover{background:rgba(255,95,95,.12);}

.add-row-btn{display:flex;align-items:center;gap:7px;margin:12px 16px;padding:9px 14px;border:1px dashed var(--border-hover);border-radius:8px;background:none;color:var(--text-muted);font-family:'Poppins',sans-serif;font-size:12px;font-weight:600;cursor:pointer;transition:var(--transition);}
.add-row-btn:hover{border-color:var(--accent);color:var(--accent);}

.total-row{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-top:2px solid var(--border);background:rgba(255,255,255,.02);}
.total-label{font-size:13px;font-weight:600;color:var(--text-muted);}
.total-value{font-size:22px;font-weight:700;color:var(--accent);}

.actions-bar{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:9px;border:none;font-family:'Poppins',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:var(--transition);}
.btn-accent{background:linear-gradient(135deg,var(--accent),var(--accent-light));color:#000;}
.btn-accent:hover{transform:translateY(-2px);box-shadow:var(--shadow-accent);filter:brightness(1.08);}
.btn-outline{background:transparent;color:var(--text-muted);border:1px solid var(--border);}
.btn-outline:hover{color:var(--accent);border-color:var(--accent);}

.alert-err{background:rgba(255,95,95,.1);border:1px solid rgba(255,95,95,.2);border-radius:10px;padding:12px 16px;margin-bottom:20px;color:var(--danger);font-size:13px;}
.alert-err ul{margin:6px 0 0 18px;}

.unit-pill{display:inline-block;background:rgba(255,255,255,.07);border-radius:5px;padding:3px 8px;font-size:11px;color:var(--text-muted);}
.line-total{font-weight:600;color:var(--success);font-size:13px;}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
.card,.items-card{animation:fadeUp .4s ease both;}
.items-card{animation-delay:.1s;}

@media(max-width:768px){
    .topbar{padding:14px 16px;}
    .content{padding:20px 12px;}
    .layout{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<div class="topbar">
    <a class="topbar-back" href="purchase_orders.php"><i class="fa-solid fa-arrow-left"></i> Purchase Orders</a>
    <div class="topbar-title"><i class="fa-solid fa-plus"></i> New Purchase Order</div>
</div>

<div class="content">
    <?php if (!empty($errors)): ?>
    <div class="alert-err">
        <strong><i class="fa-solid fa-circle-exclamation"></i> Please fix the following:</strong>
        <ul><?php foreach ($errors as $e): ?><li><?= he($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="POST" id="poForm">
    <div class="layout">
        <!-- LEFT: Supplier + Notes -->
        <div>
            <div class="card">
                <div class="card-title"><i class="fa-solid fa-truck-ramp-box"></i> Order Info</div>
                <div class="field">
                    <label>Supplier *</label>
                    <select name="supplier_id" required>
                        <option value="">— Select supplier —</option>
                        <?php foreach ($suppliers as $sup): ?>
                        <option value="<?= $sup['supplier_id'] ?>" <?= $pre_supplier == $sup['supplier_id'] ? 'selected' : '' ?>>
                            <?= he($sup['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($suppliers)): ?>
                    <p style="margin-top:6px;font-size:11px;color:var(--danger)">
                        No suppliers yet. <a href="suppliers.php" style="color:var(--accent)">Add one first →</a>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label>Notes <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
                    <textarea name="notes" placeholder="Delivery instructions, payment terms…"></textarea>
                </div>
            </div>

            <!-- Total summary card -->
            <div class="card" style="margin-top:16px;">
                <div class="card-title"><i class="fa-solid fa-calculator"></i> Summary</div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="color:var(--text-muted);font-size:13px;">Grand Total</span>
                    <span class="total-value" id="grandTotal">$0.00</span>
                </div>
                <div style="margin-top:20px;display:flex;flex-direction:column;gap:10px;">
                    <button type="submit" name="submit_type" value="draft" class="btn btn-outline" style="width:100%;justify-content:center;">
                        <i class="fa-solid fa-floppy-disk"></i> Save as Draft
                    </button>
                    <button type="submit" name="submit_type" value="order" class="btn btn-accent" style="width:100%;justify-content:center;">
                        <i class="fa-solid fa-paper-plane"></i> Place Order
                    </button>
                </div>
            </div>
        </div>

        <!-- RIGHT: Items -->
        <div class="items-card">
            <div class="items-header">
                <div class="items-title"><i class="fa-solid fa-boxes-stacked"></i> Ingredients to Order</div>
            </div>
            <table class="items-tbl">
                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th style="width:80px">Unit</th>
                        <th style="width:100px">Qty</th>
                        <th style="width:110px">Unit Cost ($)</th>
                        <th style="width:90px;text-align:right">Total</th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody id="itemsBody"></tbody>
            </table>
            <button type="button" class="add-row-btn" onclick="addRow()">
                <i class="fa-solid fa-plus"></i> Add Ingredient
            </button>
        </div>
    </div>
    </form>
</div>

<script>
const ingredients = <?= json_encode($ingredients) ?>;
const ingMap = {};
ingredients.forEach(i => ingMap[i.ingredient_id] = i);

let rowCount = 0;

function addRow(iid='', qty='', cost='') {
    rowCount++;
    const n = rowCount;
    const body = document.getElementById('itemsBody');
    const tr = document.createElement('tr');
    tr.id = 'row_'+n;

    const opts = ingredients.map(i =>
        `<option value="${i.ingredient_id}" data-unit="${i.unit}" data-cost="${i.cost_per_unit||0}" ${i.ingredient_id==iid?'selected':''}>${i.ingredient_name}</option>`
    ).join('');

    const selIng = iid ? ingMap[iid] : null;
    const unit   = selIng ? selIng.unit : '';
    const defCost = cost || (selIng ? (selIng.cost_per_unit||0) : 0);

    tr.innerHTML = `
        <td>
            <select name="items[${n}][ingredient_id]" onchange="onIngChange(this,${n})" required>
                <option value="">— select —</option>
                ${opts}
            </select>
        </td>
        <td><span class="unit-pill" id="unit_${n}">${unit}</span></td>
        <td><input type="number" name="items[${n}][qty]" id="qty_${n}" min="0.001" step="any" value="${qty}" placeholder="0" oninput="calcRow(${n})"></td>
        <td><input type="number" name="items[${n}][unit_cost]" id="cost_${n}" min="0" step="0.01" value="${defCost||''}" placeholder="0.00" oninput="calcRow(${n})"></td>
        <td style="text-align:right"><span class="line-total" id="lt_${n}">$0.00</span></td>
        <td><button type="button" class="btn-remove" onclick="removeRow(${n})" title="Remove"><i class="fa-solid fa-trash"></i></button></td>
    `;
    body.appendChild(tr);
    calcRow(n);
}

function onIngChange(sel, n) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('unit_'+n).textContent = opt.dataset.unit || '';
    const costInput = document.getElementById('cost_'+n);
    if (!costInput.value) costInput.value = parseFloat(opt.dataset.cost||0).toFixed(2);
    calcRow(n);
}

function calcRow(n) {
    const qty  = parseFloat(document.getElementById('qty_'+n)?.value||0);
    const cost = parseFloat(document.getElementById('cost_'+n)?.value||0);
    const lt   = document.getElementById('lt_'+n);
    if (lt) lt.textContent = '$'+(qty*cost).toFixed(2);
    calcTotal();
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('[id^="lt_"]').forEach(el => {
        total += parseFloat(el.textContent.replace('$','')) || 0;
    });
    document.getElementById('grandTotal').textContent = '$'+total.toFixed(2);
}

function removeRow(n) {
    document.getElementById('row_'+n)?.remove();
    calcTotal();
}

// Start with one empty row
addRow();
</script>
</body>
</html>
