<?php
// Bakong payment config loader.
// Real credentials live in bakong_config.local.php (git-ignored).
// This tracked file only holds safe placeholders as a fallback/template.

use KHQR\Helpers\KHQRData;

$local = __DIR__ . '/bakong_config.local.php';
if (file_exists($local)) {
    return require $local;
}

// Fallback placeholders — create bakong_config.local.php from the example to enable payments.
return [
    'token' => 'YOUR_BAKONG_JWT_TOKEN',
    'bakong_id' => 'your_account@bank',
    'merchant_name' => 'YOUR MERCHANT NAME',
    'merchant_city' => 'PHNOM PENH',
    'mobile_number' => '855000000000',
    'currency' => KHQRData::CURRENCY_USD,
];
