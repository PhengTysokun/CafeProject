<?php
session_start();
require 'config.php';

// ── CLEAR CART BEFORE ADDING ──
// This ensures only the current item is in the cart
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['cart'] = [];
}

// ── FIX SQL INJECTION ──
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Invalid product ID.");
}

$stmt = $conn->prepare("SELECT * FROM products WHERE product_id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    die("Product not found.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sweetness = $_POST['sweetness'] ?? '';
    $ice = $_POST['ice'] ?? '';
    $milk = $_POST['milk'] ?? '';

    $item = [
        'product_id' => $product['product_id'],
        'product_name' => $product['name'],
        'price' => $product['price'],
        'image' => $product['image'],
        'qty' => 1,
        'sweetness' => $sweetness,
        'ice' => $ice,
        'milk' => $milk
    ];

    // ── REPLACE CART WITH SINGLE ITEM ──
    $_SESSION['cart'] = [$item];

    header("Location: cart.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customize Your Drink | Obsidian Cafe</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #0c0c0c;
            color: #fff;
            margin: 0;
            padding: 40px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .box {
            background: #121212;
            padding: 30px;
            border-radius: 16px;
            width: 400px;
            max-width: 100%;
            box-shadow: 0 0 25px rgba(0,0,0,0.6);
            text-align: center;
        }
        img {
            width: 100%;
            height: 220px;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 15px;
        }
        h2 {
            color: #e3b382;
            margin-bottom: 15px;
        }
        .price {
            color: #d1904b;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin: 10px 0 5px;
            font-weight: 500;
            color: #aaa;
            text-align: left;
        }
        select {
            width: 100%;
            padding: 10px;
            border-radius: 8px;
            border: none;
            background: #1f1f1f;
            color: #fff;
            margin-bottom: 15px;
            font-family: 'Poppins', sans-serif;
        }
        button {
            background: linear-gradient(135deg, #b57b3b, #d1904b);
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            width: 100%;
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
        }
        button:hover {
            background: linear-gradient(135deg, #d1904b, #e3b382);
            transform: translateY(-2px);
        }
        .back-btn {
            display: inline-block;
            margin-top: 15px;
            color: #888;
            text-decoration: none;
            font-size: 14px;
            transition: 0.3s;
        }
        .back-btn:hover {
            color: #d1904b;
        }
        @media (max-width: 480px) {
            body { padding: 20px; }
            .box { padding: 20px; }
            img { height: 160px; }
        }
    </style>
</head>
<body>
    <div class="box">
        <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
        <h2><?php echo htmlspecialchars($product['name']); ?></h2>
        <div class="price">$<?php echo number_format($product['price'], 2); ?></div>

        <form method="POST">
            <label>Sweetness Level</label>
            <select name="sweetness" required>
                <option value="0%">0%</option>
                <option value="25%">25%</option>
                <option value="50%" selected>50%</option>
                <option value="75%">75%</option>
                <option value="100%">100%</option>
            </select>

            <label>Ice Level</label>
            <select name="ice" required>
                <option value="No Ice">No Ice</option>
                <option value="Less Ice">Less Ice</option>
                <option value="Normal Ice" selected>Normal Ice</option>
                <option value="More Ice">More Ice</option>
            </select>

            <label>Milk</label>
            <select name="milk">
                <option value="Fresh Milk" selected>Fresh Milk</option>
                <option value="Almond Milk">Almond Milk</option>
                <option value="Soy Milk">Soy Milk</option>
                <option value="Oat Milk">Oat Milk</option>
            </select>

            <button type="submit">Add to Cart</button>
        </form>

        <a href="menu.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Menu</a>
    </div>
</body>
</html>