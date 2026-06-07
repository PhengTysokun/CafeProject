<?php
require 'auth.php';
require 'config.php';
if (!can('loyalty')) { header('Location: dashboard.php?denied=1'); exit; }

$loyalty_id = $_GET['id'] ?? '';

if (empty($loyalty_id)) {
    die("No loyalty ID provided.");
}

$stmt = $conn->prepare("SELECT * FROM loyalty_cards WHERE loyalty_id = ? AND is_active = 1");
$stmt->bind_param("s", $loyalty_id);
$stmt->execute();
$card = $stmt->get_result()->fetch_assoc();

if (!$card) {
    die("Card not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loyalty Card - <?php echo htmlspecialchars($loyalty_id); ?></title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .card {
            width: 300px;
            padding: 30px 20px;
            border-radius: 16px;
            background: linear-gradient(135deg, #d1904b, #e8b87a);
            color: #000;
            text-align: center;
            box-shadow: 0 8px 40px rgba(0,0,0,0.2);
            position: relative;
            overflow: hidden;
        }
        .card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: shimmer 3s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(10%, 10%); }
        }
        .logo {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 4px;
            position: relative;
        }
        .logo span { color: #5a2d0c; }
        .subtitle {
            font-size: 11px;
            opacity: 0.7;
            margin-bottom: 20px;
            position: relative;
        }
        .card-number {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 2px;
            background: rgba(255,255,255,0.3);
            padding: 8px 16px;
            border-radius: 8px;
            display: inline-block;
            margin-bottom: 16px;
            position: relative;
        }
        .points {
            font-size: 48px;
            font-weight: 800;
            position: relative;
        }
        .points-label {
            font-size: 14px;
            opacity: 0.7;
            position: relative;
        }
        .footer {
            margin-top: 16px;
            font-size: 10px;
            opacity: 0.6;
            position: relative;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">☕ <span>Bird's Nest</span></div>
        <div class="subtitle">Loyalty Card</div>
        <div class="card-number"><?php echo htmlspecialchars($card['loyalty_id']); ?></div>
        <div class="points"><?php echo (int)$card['points']; ?></div>
        <div class="points-label">Points Balance</div>
        <div class="footer">Present this card to earn points</div>
    </div>
</body>
</html>