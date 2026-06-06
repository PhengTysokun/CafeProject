<?php
require_once 'config.php';
header('Content-Type: application/json');

// Generate a unique loyalty ID
$loyalty_id = generateLoyaltyId();

// Make sure it's unique in the database
$unique = false;
while (!$unique) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM loyalty_cards WHERE loyalty_id = ?");
    $stmt->bind_param("s", $loyalty_id);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_row()[0];
    
    if ($count == 0) {
        $unique = true;
    } else {
        $loyalty_id = generateLoyaltyId();
    }
}

echo json_encode(['loyalty_id' => $loyalty_id]);
?>