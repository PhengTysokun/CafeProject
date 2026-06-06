<?php
require 'config.php';

// ── Configuration ──
$to = 'darasokun437@gmail.com'; // Change to your HR email
$subject = "⚠️ Low Stock Alert - " . date("d M Y H:i");

// ── Check for low stock ──
$low_sql = "SELECT ingredient_name, stock_quantity, minimum_stock, unit FROM ingredients WHERE stock_quantity < minimum_stock";
$low_result = mysqli_query($conn, $low_sql);
$low_items = [];

while ($row = mysqli_fetch_assoc($low_result)) {
    $low_items[] = $row;
}

if (count($low_items) === 0) {
    // No low stock — exit silently
    exit;
}

// ── Build email body ──
$html = "<h3>⚠️ Low Stock Alert — " . date("d M Y H:i") . "</h3>";
$html .= "<p>The following ingredients are below minimum stock levels:</p>";
$html .= "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
$html .= "<thead>
            <tr style='background: #f5f5f5;'>
                <th>Ingredient</th>
                <th>Current Stock</th>
                <th>Minimum Stock</th>
                <th>Needed</th>
            </tr>
          </thead>
          <tbody>";
foreach ($low_items as $item) {
    $needed = $item['minimum_stock'] - $item['stock_quantity'];
    $html .= "<tr>
                <td>{$item['ingredient_name']}</td>
                <td style='color: #e74c3c; font-weight: 600;'>{$item['stock_quantity']} {$item['unit']}</td>
                <td>{$item['minimum_stock']} {$item['unit']}</td>
                <td style='color: #e74c3c; font-weight: 600;'>$needed</td>
            </tr>";
}
$html .= "</tbody></table>";
$html .= "<p>Please order more stock as soon as possible.</p>";

$headers = "From: phengtysokun@gmail.com\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";

mail($to, $subject, $html, $headers);
echo "✅ Stock alert sent automatically.";
?>