<?php
require 'config.php';
date_default_timezone_set("Asia/Phnom_Penh");

$now = new DateTime();
if ((int)$now->format("H") < 6) {
    $business_date = $now->modify("-1 day")->format("Y-m-d");
} else {
    $business_date = $now->format("Y-m-d");
}

// Insert opening stock if not exists
$ingredients = mysqli_query($conn, "SELECT ingredient_id, stock_quantity FROM ingredients");

while ($i = mysqli_fetch_assoc($ingredients)) {
    $ingredient_id = $i['ingredient_id'];
    $stock = $i['stock_quantity'];

    mysqli_query($conn, "
        INSERT IGNORE INTO ingredient_daily_stock 
        (ingredient_id, business_date, opening_stock)
        VALUES ($ingredient_id, '$business_date', $stock)
    ");
}

echo "Daily opening stock saved";
