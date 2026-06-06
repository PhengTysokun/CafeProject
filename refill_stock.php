<?php
require 'auth.php';
require 'config.php';
if (!can('ingredients')) { header("Location: ingredients.php"); exit; }

// ── Fetch all ingredients ──
$sql = "SELECT ingredient_id, ingredient_name, stock_quantity, unit, minimum_stock FROM ingredients ORDER BY ingredient_name ASC";
$result = mysqli_query($conn, $sql);

// ── Handle refill submission ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refill'])) {
    $ingredient_id = (int)$_POST['ingredient_id'];
    $refill_qty = (float)$_POST['refill_qty'];
    
    if ($refill_qty > 0) {
        $stmt = $conn->prepare("UPDATE ingredients SET stock_quantity = stock_quantity + ? WHERE ingredient_id = ?");
        $stmt->bind_param("di", $refill_qty, $ingredient_id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $success = "Stock refilled successfully!";
        } else {
            $error = "Failed to refill stock.";
        }
    } else {
        $error = "Please enter a valid quantity.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refill Stock | Bird's Nest Coffee</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── RESET & ROOT ── */
        :root {
            --bg: #0c0c0c;
            --bg-card: #121212;
            --bg-card-hover: #181818;
            --border: #1f1f1f;
            --border-hover: #2a2a2a;
            --accent: #d1904b;
            --accent-light: #e8b87a;
            --accent-dark: #a0702a;
            --text: #f5f5f5;
            --text-muted: #888888;
            --text-light: #ffffff;
            --success: #55e087;
            --danger: #ff6b6b;
            --warning: #f1c40f;
            
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
            --shadow-accent: 0 4px 20px rgba(209, 144, 75, 0.15);
            
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            width: 100%;
            max-width: 800px;
        }

        .card {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 40px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent), var(--accent-light));
        }

        .card:hover {
            border-color: var(--border-hover);
            box-shadow: var(--shadow-lg);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header i {
            font-size: 48px;
            color: var(--accent);
            display: block;
            margin-bottom: 8px;
        }

        .header h1 {
            color: var(--text-light);
            font-size: 28px;
            font-weight: 700;
        }

        .header p {
            color: var(--text-muted);
            font-size: 14px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px 16px;
            color: var(--accent);
            border-bottom: 2px solid var(--border);
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        .low-stock {
            color: var(--danger);
            font-weight: 600;
        }

        .ok-stock {
            color: var(--success);
        }

        .refill-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .refill-form input[type="number"] {
            width: 80px;
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-size: 13px;
            font-family: 'Poppins', sans-serif;
        }

        .refill-form input[type="number"]:focus {
            outline: none;
            border-color: var(--accent);
        }

        .refill-form button {
            padding: 6px 14px;
            border-radius: 8px;
            border: none;
            background: var(--accent);
            color: #000;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
        }

        .refill-form button:hover {
            background: var(--accent-light);
            transform: scale(1.05);
            box-shadow: var(--shadow-accent);
        }

        .alert {
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .alert.success {
            background: rgba(85, 224, 135, 0.15);
            color: var(--success);
            border: 1px solid rgba(85, 224, 135, 0.2);
        }

        .alert.error {
            background: rgba(255, 107, 107, 0.15);
            color: var(--danger);
            border: 1px solid rgba(255, 107, 107, 0.2);
        }

        .back-link {
            display: inline-block;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            margin-top: 20px;
            transition: var(--transition);
        }

        .back-link:hover {
            color: var(--accent);
        }

        @media (max-width: 768px) {
            body {
                padding: 20px;
            }

            .card {
                padding: 24px;
            }

            .header h1 {
                font-size: 22px;
            }

            .refill-form {
                flex-wrap: wrap;
            }

            .refill-form input[type="number"] {
                width: 100%;
            }

            .refill-form button {
                width: 100%;
            }

            th, td {
                padding: 8px 10px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="header">
            <i class="fa-solid fa-boxes-stacked"></i>
            <h1>Refill Stock</h1>
            <p>Add stock to your ingredients</p>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert success">
                <i class="fa-solid fa-check-circle"></i> <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert error">
                <i class="fa-solid fa-exclamation-circle"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th>Current Stock</th>
                        <th>Unit</th>
                        <th>Min Stock</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                        <?php 
                        $is_low = $row['stock_quantity'] < $row['minimum_stock'];
                        $status_class = $is_low ? 'low-stock' : 'ok-stock';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['ingredient_name']) ?></td>
                            <td class="<?= $status_class ?>"><?= number_format($row['stock_quantity'], 0) ?></td>
                            <td><?= htmlspecialchars($row['unit']) ?></td>
                            <td><?= number_format($row['minimum_stock'], 0) ?></td>
                            <td>
                                <form method="POST" class="refill-form">
                                    <input type="hidden" name="ingredient_id" value="<?= $row['ingredient_id'] ?>">
                                    <input type="number" name="refill_qty" min="1" value="10" required>
                                    <button type="submit" name="refill">
                                        <i class="fa-solid fa-plus"></i> Refill
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div style="text-align: center; margin-top: 20px;">
            <a href="dashboard.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</div>

</body>
</html>