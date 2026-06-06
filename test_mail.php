<?php
$to = 'hr@birdsnestcoffee.com'; // Change to your HR email
$subject = 'Test Email from XAMPP';
$message = '<h1>Hello!</h1><p>This is a test email from your XAMPP POS system.</p>';
$headers = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers .= "From: Bird\'s Nest POS <pos@birdsnestcoffee.com>" . "\r\n";

if (mail($to, $subject, $message, $headers)) {
    echo "✅ Email sent successfully!";
} else {
    echo "❌ Failed to send email.";
}
?>