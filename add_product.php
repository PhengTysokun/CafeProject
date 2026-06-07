<?php
require 'admin_only.php';

/* ================= ADD PRODUCT ONLY ================= */
if (isset($_POST['add_product'])) {
    $name        = $_POST['name']        ?? '';
    $description = $_POST['description'] ?? '';
    $price       = (float)($_POST['price'] ?? 0);
    $category    = $_POST['category']    ?? '';
    $badge_text  = substr(trim($_POST['badge_text'] ?? ''), 0, 40) ?: null;

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

    $stmt = $conn->prepare("INSERT INTO products (name, description, price, category, image, badge_text) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdsss", $name, $description, $price, $category, $image_path, $badge_text);
    if ($stmt->execute()) {
        header("Location: products.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Product | Obsidian Cafe</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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
    --shadow-accent: 0 4px 20px rgba(209, 144, 75, 0.15);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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
    background: #181818;
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
    background: #1e1e1e;
}

.file-input-wrapper {
    position: relative;
    border: 1px dashed var(--border);
    border-radius: 10px;
    padding: 16px;
    text-align: center;
    transition: var(--transition);
    cursor: pointer;
    background: #181818;
    margin-bottom: 14px;
}

.file-input-wrapper:hover {
    border-color: var(--accent);
    background: #1e1e1e;
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

            <div class="input-group">
                <i class="fa-solid fa-list"></i>
                <select name="category" required>
                    <option value="">Select category</option>
                    <?php
                    $_cats = $conn->query("SELECT slug, name FROM categories WHERE is_active = 1 ORDER BY display_order");
                    while ($_c = $_cats->fetch_assoc()):
                    ?>
                    <option value="<?= htmlspecialchars($_c['slug']) ?>"><?= htmlspecialchars($_c['name']) ?></option>
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
</script>

</body>
</html>