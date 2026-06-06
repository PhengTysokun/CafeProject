<?php
require 'config.php';

$to = 'darasokun437@gmail.com';
$subject = 'Test from POS - ' . date('H:i:s');
$message = 'This is a test from the POS system at ' . date('H:i:s');
$headers = "From: phengtysokun@gmail.com\r\n";

if (mail($to, $subject, $message, $headers)) {
    echo "✅ Test email sent!";
} else {
    echo "❌ Test failed.";
}
?>