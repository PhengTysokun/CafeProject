<?php
require 'admin_only.php';

/* ================= ADD PRODUCT ONLY ================= */
if (isset($_POST['add_product'])) {
    $name        = $_POST['name']        ?? '';
    $description = $_POST['description'] ?? '';
    $price       = (float)($_POST['price'] ?? 0);
    $category    = $_POST['category']    ?? '';
    $badge_text  = substr(trim($_POST['badge_text'] ?? ''), 0, 40) ?: null;

    $cat_r = $conn->prepare("SELECT category_id FROM categories WHERE slug = ? LIMIT 1");
    $cat_r->bind_param("s", $category); $cat_r->execute();
    $category_id = ($cat_r->get_result()->fetch_assoc())['category_id'] ?? null;

    $upload_dir = "uploads/";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

    $image_path = '';
    if (!empty($_FILES['image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            die("Invalid file type. Only jpg, jpeg, png, gif, webp allowed.");
        }
        $image_name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $image_path = $upload_dir . $image_name;
        move_uploaded_file($_FILES['image']['tmp_name'], $image_path);
    }

    $stmt = $conn->prepare("INSERT INTO products (name, description, price, category, category_id, image, badge_text) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdsiss", $name, $description, $price, $category, $category_id, $image_path, $badge_text);
    if ($stmt->execute()) {
        $product_id = $conn->insert_id;

        // ── Sizes: toggle + upsert S/M/L rows, sync products.price to Medium ──
        $has_sizes = isset($_POST['has_sizes']) ? 1 : 0;
        $conn->query("UPDATE products SET has_sizes=" . (int)$has_sizes . " WHERE product_id=" . (int)$product_id);

        if ($has_sizes) {
            $codes   = $_POST['size_code']   ?? [];
            $labels  = $_POST['size_label']  ?? [];
            $prices  = $_POST['size_price']  ?? [];
            $factors = $_POST['size_factor'] ?? [];
            $sorts   = $_POST['size_sort']   ?? [];
            $up = $conn->prepare("INSERT INTO product_sizes (product_id,size_code,label,price,size_factor,sort_order)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE label=VALUES(label), price=VALUES(price), size_factor=VALUES(size_factor), sort_order=VALUES(sort_order)");
            $mediumPrice = null;
            $fallbackPrice = null;
            foreach ($codes as $i => $code) {
                $sizePrice = (float)($prices[$i] ?? 0);
                if ($sizePrice <= 0) continue; // skip blank rows
                $sizeLabel  = trim((string)($labels[$i] ?? $code));
                $sizeFactor = (float)($factors[$i] ?? 1.0);
                $sizeSort   = (int)($sorts[$i] ?? 0);
                $up->bind_param("issddi", $product_id, $code, $sizeLabel, $sizePrice, $sizeFactor, $sizeSort);
                $up->execute();
                if ($code === 'M') $mediumPrice = $sizePrice;
                if ($fallbackPrice === null) $fallbackPrice = $sizePrice; // first surviving size (form order S→M→L)
            }
            // Keep products.price synced for legacy single-price paths: Medium if offered, else the first available size (e.g. Large-only)
            $basePrice = $mediumPrice ?? $fallbackPrice;
            if ($basePrice !== null) {
                $sp = $conn->prepare("UPDATE products SET price=? WHERE product_id=?");
                $sp->bind_param("di", $basePrice, $product_id);
                $sp->execute();
            } else {
                // "has sizes" was checked but every row left blank → not actually sized; keep the base Price field
                $conn->query("UPDATE products SET has_sizes=0 WHERE product_id=" . (int)$product_id);
            }
        }

        // ── Add-on assignments (only when the "has add-ons" toggle is on) ──
        $addonIds = isset($_POST['has_addons'])
            ? array_values(array_unique(array_map('intval', $_POST['addon_id'] ?? [])))
            : [];
        if ($addonIds) {
            $pa = $conn->prepare("INSERT IGNORE INTO product_addons (product_id, addon_id) VALUES (?, ?)");
            foreach ($addonIds as $aid) {
                if ($aid > 0) { $pa->bind_param('ii', $product_id, $aid); $pa->execute(); }
            }
        }

        header("Location: products.php");
        exit;
    }
}

$allAddons = [];
$__ar = $conn->query("SELECT id, name, price FROM addons WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
while ($__a = $__ar->fetch_assoc()) $allAddons[] = $__a;
$assignedAddons = [];
$hasAddons = !empty($assignedAddons);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Product | Obsidian Cafe</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){var t=localStorage.getItem('theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})();</script>

<style>
:root {
    --bg: #0b0b0b;
    --bg-card: #121212;
    --border: #1f1f1f;
    --border-hover: #2a2a2a;
    --accent: #d1904b;
    --accent-light: #e8b87a;
    --accent-dark: #a0702a;
    --text: #f5f5f5;
    --text-muted: #888888;
    --bg-input: #181818;
    --bg-input-focus: #1e1e1e;
    --shadow-accent: 0 4px 20px rgba(209, 144, 75, 0.15);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

[data-theme="light"] {
    --bg: #f5f0eb;
    --bg-card: #ffffff;
    --border: #e8ddd2;
    --border-hover: #d4c4b0;
    --text: #1a1008;
    --text-muted: #7a6a58;
    --bg-input: #f0e9e0;
    --bg-input-focus: #fdf8f3;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    color: var(--text);
    padding: 20px;
    min-height: 100vh;
}

.back-link {
    position: fixed;
    top: 20px;
    left: 20px;
    color: var(--accent);
    text-decoration: none;
    font-weight: 600;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 10px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    transition: var(--transition);
    z-index: 100;
}

.back-link:hover {
    background: var(--accent);
    color: #000;
    transform: translateX(-4px);
    border-color: var(--accent);
}

.container {
    max-width: 500px;
    margin: 80px auto 40px;
    padding: 0 20px;
}

.form-container {
    background: var(--bg-card);
    padding: 32px 36px;
    border-radius: 16px;
    box-shadow: var(--shadow-lg);
    border: 1px solid var(--border);
}

.form-container h2 {
    text-align: center;
    color: var(--accent);
    margin-bottom: 24px;
    font-weight: 600;
    font-size: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.input-group {
    position: relative;
    margin-bottom: 14px;
}

.input-group i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 16px;
}

.input-group input,
.input-group textarea,
.input-group select {
    width: 100%;
    padding: 12px 14px 12px 44px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--bg-input);
    color: var(--text);
    font-size: 14px;
    font-family: 'Poppins', sans-serif;
    transition: var(--transition);
    outline: none;
}

.input-group textarea {
    padding-left: 14px;
    min-height: 80px;
    resize: vertical;
}

.input-group input:focus,
.input-group textarea:focus,
.input-group select:focus {
    border-color: var(--accent);
    box-shadow: var(--shadow-accent);
    background: var(--bg-input-focus);
}

.size-row input {
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--bg-input);
    color: var(--text);
    font-size: 14px;
    font-family: 'Poppins', sans-serif;
    transition: var(--transition);
    outline: none;
    min-width: 0;
}

.size-row input:focus {
    border-color: var(--accent);
    box-shadow: var(--shadow-accent);
    background: var(--bg-input-focus);
}

.file-input-wrapper {
    position: relative;
    border: 1px dashed var(--border);
    border-radius: 10px;
    padding: 16px;
    text-align: center;
    transition: var(--transition);
    cursor: pointer;
    background: var(--bg-input);
    margin-bottom: 14px;
}

.file-input-wrapper:hover {
    border-color: var(--accent);
    background: var(--bg-input-focus);
}

.file-input-wrapper input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}

.file-input-wrapper i {
    font-size: 28px;
    color: var(--text-muted);
    display: block;
    margin-bottom: 6px;
}

.file-input-wrapper span {
    font-size: 13px;
    color: var(--text-muted);
}

.file-input-wrapper .file-name {
    display: block;
    margin-top: 6px;
    color: var(--accent);
    font-weight: 500;
    font-size: 13px;
}

.submit-btn {
    width: 100%;
    background: linear-gradient(135deg, var(--accent), var(--accent-light));
    color: #000;
    padding: 14px;
    border: none;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font-family: 'Poppins', sans-serif;
}

.submit-btn:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-accent);
    filter: brightness(1.1);
}

@media (max-width: 480px) {
    .form-container {
        padding: 20px;
    }
}

.product-badge {
    display: inline-flex;
    width: 76px;
    height: 76px;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 14px;
    clip-path: polygon(
        50% 0%, 59.6% 14.3%, 75% 6.7%, 76.2% 23.8%, 93.3% 25%, 85.7% 40.4%,
        100% 50%, 85.7% 59.6%, 93.3% 75%, 76.2% 76.2%, 75% 93.3%, 59.6% 85.7%,
        50% 100%, 40.4% 85.7%, 25% 93.3%, 23.8% 76.2%, 6.7% 75%, 14.3% 59.6%,
        0% 50%, 14.3% 40.4%, 6.7% 25%, 23.8% 23.8%, 25% 6.7%, 40.4% 14.3%
    );
    font-size: 11px;
    font-weight: 800;
    line-height: 1.3;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    background: linear-gradient(135deg, #a81e1e 0%, #e74c3c 50%, #a81e1e 100%);
    color: #fff;
    text-shadow: 0 1px 3px rgba(0,0,0,0.45);
    filter: drop-shadow(0 3px 10px rgba(231,76,60,0.75));
    word-break: break-word;
}

.addon-chip { display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:50px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);cursor:pointer;font-size:13px; }
.addon-chip.on { border-color:var(--accent); background:rgba(209,144,75,.12); color:var(--accent); }
/* Checkboxes must not inherit the full-width text-input styling */
.input-group input[type="checkbox"] { width:auto; padding:0; margin:0; flex:0 0 auto; }
/* ── Page entrance fade-in ── */
@keyframes fadeInUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
.container      { animation: fadeInUp .45s ease both; }
.form-container { animation: fadeInUp .55s .08s ease both; }
@media (prefers-reduced-motion: reduce) { .container, .form-container { animation: none; } }
</style>
</head>

<body>

<!-- BACK LINK -->
<a href="products.php" class="back-link">
    <i class="fa-solid fa-arrow-left"></i> Back to Products
</a>

<!-- MAIN CONTAINER -->
<div class="container">
    <div class="form-container">
        <h2>
            <i class="fa-solid fa-mug-hot"></i>
            Add New Product
        </h2>

        <form method="POST" enctype="multipart/form-data">
            <div class="input-group">
                <i class="fa-solid fa-tag"></i>
                <input type="text" name="name" placeholder="Product name" required>
            </div>

            <div class="input-group">
                <textarea name="description" rows="3" placeholder="Product description" required></textarea>
            </div>

            <div class="input-group">
                <i class="fa-solid fa-dollar-sign"></i>
                <input type="number" step="0.01" name="price" placeholder="Price ($)" required>
            </div>

            <div class="input-group" style="display:flex;align-items:center;gap:8px;padding-left:0;">
                <label class="form-check" for="has_sizes" style="display:flex;align-items:center;gap:8px;cursor:pointer;color:var(--text-muted);font-size:14px;">
                    <input type="checkbox" id="has_sizes" name="has_sizes" value="1"
                        onchange="document.getElementById('sizeRows').style.display=this.checked?'block':'none'">
                    This product has sizes (S / M / L)
                </label>
            </div>
            <div id="sizeRows" style="display:none;flex-direction:column;gap:10px;margin-bottom:14px;">
                <?php
                $sizeDefaults = [
                    ['code'=>'S','label'=>'Small','factor'=>'0.80','sort'=>0],
                    ['code'=>'M','label'=>'Medium','factor'=>'1.00','sort'=>1],
                    ['code'=>'L','label'=>'Large','factor'=>'1.30','sort'=>2],
                ];
                foreach ($sizeDefaults as $d):
                ?>
                <div class="size-row" style="display:flex;gap:8px;align-items:center;">
                    <input type="hidden" name="size_code[]" value="<?= $d['code'] ?>">
                    <input type="hidden" name="size_sort[]" value="<?= $d['sort'] ?>">
                    <input type="text" name="size_label[]" value="<?= htmlspecialchars($d['label']) ?>" placeholder="Label" style="flex:1.3;padding-left:14px;">
                    <input type="number" step="0.01" min="0" name="size_price[]" value="" placeholder="Price ($)" style="flex:1;padding-left:14px;">
                    <input type="number" step="0.01" min="0" name="size_factor[]" value="<?= $d['factor'] ?>" placeholder="Stock ×" style="flex:0.8;padding-left:14px;">
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($allAddons)): ?>
            <div class="input-group" style="display:flex;align-items:center;gap:8px;padding-left:0;margin-bottom:8px;">
                <label class="form-check" for="has_addons" style="display:flex;align-items:center;gap:8px;cursor:pointer;color:var(--text-muted);font-size:14px;">
                    <input type="checkbox" id="has_addons" name="has_addons" value="1" <?= $hasAddons ? 'checked' : '' ?>
                        onchange="var b=document.getElementById('addonRows');b.style.display=this.checked?'block':'none';if(!this.checked){b.querySelectorAll('input[type=checkbox]').forEach(function(c){c.checked=false;c.closest('.addon-chip').classList.remove('on');});}">
                    This product has add-ons
                </label>
            </div>
            <div id="addonRows" class="input-group" style="padding-left:0;<?= $hasAddons ? '' : 'display:none;' ?>">
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    <?php foreach ($allAddons as $ad): $on = !empty($assignedAddons[(int)$ad['id']]); ?>
                    <label class="addon-chip<?= $on ? ' on' : '' ?>">
                        <input type="checkbox" name="addon_id[]" value="<?= (int)$ad['id'] ?>" <?= $on ? 'checked' : '' ?> style="display:none;" onchange="this.closest('.addon-chip').classList.toggle('on', this.checked);">
                        <?= htmlspecialchars($ad['name'], ENT_QUOTES, 'UTF-8') ?> +$<?= number_format((float)$ad['price'], 2) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="input-group">
                <i class="fa-solid fa-list"></i>
                <select name="category" id="categorySelect" required onchange="syncCategoryAddons()">
                    <option value="">Select category</option>
                    <?php
                    $_cats = $conn->query("SELECT slug, name, offer_addons FROM categories WHERE is_active = 1 ORDER BY display_order");
                    while ($_c = $_cats->fetch_assoc()):
                    ?>
                    <option value="<?= htmlspecialchars($_c['slug']) ?>" data-offer-addons="<?= (int)$_c['offer_addons'] ?>"><?= htmlspecialchars($_c['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="file-input-wrapper">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <span>Click or drag to upload image</span>
                <span class="file-name" id="fileName">No file chosen</span>
                <input type="file" name="image" id="imageInput" required>
            </div>

            <div class="input-group">
                <i class="fa-solid fa-certificate"></i>
                <input type="text" name="badge_text" id="badgeText" maxlength="40" placeholder='Badge label (e.g. "50% OFF", "New!", "Limited") — leave blank for none'>
            </div>
            <div id="badgePreviewWrap" style="display:none;margin-bottom:14px;text-align:center;">
                <span id="badgePreview" class="product-badge"></span>
            </div>

            <button type="submit" class="submit-btn" name="add_product">
                <i class="fa-solid fa-plus"></i> Add Product
            </button>
        </form>
    </div>
</div>

<script>
document.getElementById('imageInput').addEventListener('change', function() {
    const fileName = this.files[0] ? this.files[0].name : 'No file chosen';
    document.getElementById('fileName').textContent = fileName;
});

document.getElementById('badgeText').addEventListener('input', function() {
    const val = this.value.trim();
    const wrap = document.getElementById('badgePreviewWrap');
    const badge = document.getElementById('badgePreview');
    if (val) { badge.textContent = val; wrap.style.display = 'block'; }
    else { wrap.style.display = 'none'; }
});

// When a category that offers add-ons is chosen, default the product to that
// category's policy: enable add-ons + pre-tick all of them (still deselectable).
// Categories that don't offer add-ons hide the section.
function syncCategoryAddons() {
    var toggle = document.getElementById('has_addons');
    var rows   = document.getElementById('addonRows');
    if (!toggle || !rows) return; // no active add-ons in the library
    var sel = document.getElementById('categorySelect');
    var opt = sel.options[sel.selectedIndex];
    var offers = opt && opt.dataset.offerAddons === '1';
    toggle.checked = offers;
    rows.style.display = offers ? 'block' : 'none';
    rows.querySelectorAll('input[type=checkbox]').forEach(function (c) {
        c.checked = offers;
        c.closest('.addon-chip').classList.toggle('on', offers);
    });
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