<?php
require 'admin_only.php';
require 'config.php';

/* ---------------------------
   ADD INGREDIENT
--------------------------- */
if (isset($_POST['add_ingredient'])) {
    $name = trim($_POST['ingredient_name']);
    $unit = trim($_POST['unit']);
    $stock = intval($_POST['stock_quantity']);
    $min = intval($_POST['minimum_stock']);

    if ($name && $unit) {
        $stmt = $conn->prepare("
            INSERT INTO ingredients (ingredient_name, unit, stock_quantity, minimum_stock)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssii", $name, $unit, $stock, $min);
        $stmt->execute();
        header("Location: ingredients.php");
        exit;
    }
}

/* ---------------------------
   FETCH INGREDIENTS
--------------------------- */
$result = $conn->query("
    SELECT * FROM ingredients
    ORDER BY ingredient_name ASC
");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Ingredient Stock | Obsidian Cafe</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">

<style>
:root{
  --bg:#0b0b0b;
  --card:#121212;
  --accent:#d1904b;
  --border:#2a2a2a;
  --text:#ffffff;
  --muted:#9a9a9a;
}

*{box-sizing:border-box}

body{
  margin:0;
  padding:40px;
  background:var(--bg);
  color:var(--text);
  font-family:'Poppins',system-ui;
}

.container{
  max-width:1100px;
  margin:auto;
}

.card{
  background:var(--card);
  border-radius:18px;
  padding:28px;
  box-shadow:0 15px 50px rgba(0,0,0,.65);
}

.top-bar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:20px;
}

.back{
  display:inline-flex;align-items:center;gap:7px;
  color:#d1904b;
  text-decoration:none;
  font-weight:600;font-size:13px;
  padding:7px 14px;border-radius:10px;
  border:1px solid rgba(209,144,75,.35);background:rgba(209,144,75,.08);
  transition:all .2s;
}
.back:hover{background:rgba(209,144,75,.16);border-color:#d1904b;}

h2{
  text-align:center;
  color:var(--accent);
  margin-bottom:25px;
}

/* FORM */
.form-row{
  display:grid;
  grid-template-columns: 2fr 1fr 1fr 1fr auto;
  gap:14px;
  margin-bottom:30px;
}

input{
  padding:12px 14px;
  background:#181818;
  border:1px solid var(--border);
  border-radius:10px;
  color:var(--text);
  outline:none;
}

input::placeholder{color:#777}

button{
  padding:12px 22px;
  background:linear-gradient(135deg,#b57b3b,#d1904b);
  border:none;
  border-radius:10px;
  font-weight:600;
  cursor:pointer;
}

button:hover{opacity:.9}

/* TABLE */
table{
  width:100%;
  border-collapse:collapse;
}

th{
  color:var(--accent);
  font-weight:600;
  padding:14px;
  border-bottom:2px solid var(--border);
  text-align:left;
}

td{
  padding:14px;
  border-bottom:1px solid var(--border);
}

tr:hover{background:#161616}

.low{
  color:#ff5c5c;
  font-weight:700;
}
</style>
</head>

<body>

<div class="container">
  <div class="card">

    <div class="top-bar">
      <a href="dashboard.php" class="back">← Back to Dashboard</a>
    </div>

    <h2>Ingredient Stock</h2>

    <!-- ADD INGREDIENT -->
    <form method="POST" class="form-row">
      <input name="ingredient_name" placeholder="Ingredient name" required>
      <input name="unit" placeholder="Unit (ml, g)" required>
      <input name="stock_quantity" type="number" placeholder="Stock" required>
      <input name="minimum_stock" type="number" placeholder="Min" required>
      <button name="add_ingredient">Add</button>
    </form>

    <!-- INGREDIENT TABLE -->
    <table>
      <tr>
        <th>Name</th>
        <th>Unit</th>
        <th>Stock</th>
        <th>Min</th>
      </tr>

      <?php while($row = $result->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($row['ingredient_name']) ?></td>
        <td><?= htmlspecialchars($row['unit']) ?></td>
        <td class="<?= $row['stock_quantity'] <= $row['minimum_stock'] ? 'low' : '' ?>">
          <?= $row['stock_quantity'] ?>
        </td>
        <td><?= $row['minimum_stock'] ?></td>
      </tr>
      <?php endwhile; ?>
    </table>

  </div>
</div>

</body>
</html>
