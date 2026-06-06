<?php
require 'admin_only.php';
require 'config.php';

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: products.php");
    exit;
}

$id = mysqli_real_escape_string($conn, $_GET['id']);

// Get image path to delete the file
$result = $conn->query("SELECT image FROM products WHERE product_id='$id'");
if ($result->num_rows > 0) {
    $product = $result->fetch_assoc();
    // Delete image file if exists
    if (!empty($product['image']) && file_exists($product['image'])) {
        unlink($product['image']);
    }
}

// Delete product from database
$conn->query("DELETE FROM products WHERE product_id='$id'");

// Redirect back to products page
header("Location: products.php");
exit;
?>