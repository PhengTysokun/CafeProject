<?php
session_start();
require 'config.php';

header('Content-Type: application/json; charset=utf-8');

function ok($payload){
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

/* =========================
   BEST SELLERS (TOP 5)
   #1 will be recommend = true
========================= */
function top_sellers($conn, $limit = 5){
  $limit = intval($limit);

  $sql = "
    SELECT product_name, SUM(quantity) AS qty
    FROM order_items
    GROUP BY product_name
    ORDER BY qty DESC
    LIMIT $limit
  ";

  $res = mysqli_query($conn, $sql);
  $out = [];
  $rank = 1;

  if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
      $out[] = [
        "name"      => $r["product_name"],
        "reason"    => "Popular choice (top seller).",
        "rank"      => $rank,
        "recommend" => ($rank === 1) // ✅ ONLY TOP 1
      ];
      $rank++;
    }
  }
  return $out;
}

/* =========================
   CUSTOMER NAME
========================= */
$customer = isset($_SESSION['customer_name']) ? trim($_SESSION['customer_name']) : '';
$customer_safe = mysqli_real_escape_string($conn, $customer);

/* =========================
   PRODUCT CATALOG
========================= */
$catalog = [];
$res = mysqli_query($conn, "
  SELECT product_id, name, category, price
  FROM products
  ORDER BY category, name
");

if ($res) {
  while ($row = mysqli_fetch_assoc($res)) {
    $catalog[] = [
      "product_id" => (int)$row["product_id"],
      "name"       => $row["name"],
      "category"   => $row["category"],
      "price"      => (float)$row["price"]
    ];
  }
}

/* =========================
   CUSTOMER HISTORY
========================= */
$history = [];
if ($customer_safe !== '') {
  $sql = "
    SELECT 
      oi.product_id,
      oi.product_name,
      oi.quantity,
      oi.sweetness,
      oi.ice,
      oi.milk,
      o.order_date
    FROM orders o
    JOIN order_items oi ON o.order_id = oi.order_id
    WHERE o.customer_name = '$customer_safe'
    ORDER BY o.order_date DESC
    LIMIT 40
  ";

  $res = mysqli_query($conn, $sql);
  if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
      $history[] = $row;
    }
  }
}

/* =========================
   FALLBACK (NO AI / NO HISTORY)
========================= */
if ($OPENAI_API_KEY === '' || count($history) === 0 || count($catalog) === 0) {
  ok([
    "success" => true,
    "ai_used" => false,
    "recommendations" => top_sellers($conn, 5)
  ]);
}

/* =========================
   OPENAI PROMPT
========================= */
$system_rules =
"You are a recommendation engine for a small cafe menu.
Return ONLY valid JSON in this format:
{ \"recommendations\": [ {\"name\":\"...\",\"reason\":\"...\"} ] }
Rules:
- Max 5 items
- Names MUST match catalog exactly
- One sentence reasons
- No markdown
";

/* =========================
   OPENAI INPUT
========================= */
$input_text =
$system_rules . "\n\n" .
"Customer history:\n" . json_encode($history, JSON_UNESCAPED_UNICODE) . "\n\n" .
"Catalog:\n" . json_encode($catalog, JSON_UNESCAPED_UNICODE);

$payload = [
  "model" => "gpt-5.2",
  "input" => $input_text,
  "temperature" => 0.4
];

/* =========================
   CALL OPENAI
========================= */
$ch = curl_init("https://api.openai.com/v1/responses");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  "Content-Type: application/json",
  "Authorization: Bearer " . $OPENAI_API_KEY
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

/* =========================
   AI FAIL → USE BEST SELLERS
========================= */
if ($response === false || $http < 200 || $http >= 300) {
  ok([
    "success" => true,
    "ai_used" => false,
    "recommendations" => top_sellers($conn, 5)
  ]);
}

/* =========================
   PARSE AI RESPONSE
========================= */
$data = json_decode($response, true);
$out_text = "";

if (isset($data["output"])) {
  foreach ($data["output"] as $o) {
    if (isset($o["content"])) {
      foreach ($o["content"] as $c) {
        if (($c["type"] ?? "") === "output_text") {
          $out_text .= $c["text"];
        }
      }
    }
  }
}

$decoded = json_decode(trim($out_text), true);

/* =========================
   FINAL OUTPUT
   (AI text ignored for recommend logic)
========================= */
ok([
  "success" => true,
  "ai_used" => true,
  "recommendations" => top_sellers($conn, 5)
]);
