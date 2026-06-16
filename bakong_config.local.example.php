<?php
// Template for bakong_config.local.php.
// Copy this file to bakong_config.local.php and fill in the real values.
// The real file is git-ignored so credentials never enter version control.

use KHQR\Helpers\KHQRData;

return [
    'token' => 'YOUR_BAKONG_JWT_TOKEN',
    'bakong_id' => 'your_account@bank',
    'merchant_name' => 'YOUR MERCHANT NAME',
    'merchant_city' => 'PHNOM PENH',
    'mobile_number' => '855000000000',
    'currency' => KHQRData::CURRENCY_USD,
];
