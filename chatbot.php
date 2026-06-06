<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

$rawMessage = trim((string)($_POST['message'] ?? ''));
if ($rawMessage === '') {
    echo json_encode(['reply' => 'Hi! I am your Bird\'s Nest coffee assistant. Ask me for recommendations, prices, or drink ideas.']);
    exit;
}

function normalize_text($text) {
    $text = strtolower(trim((string)$text));
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function contains_any($haystack, $needles) {
    foreach ($needles as $needle) {
        if ($needle !== '' && strpos($haystack, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function contains_phrase($haystack, $phrase) {
    $haystack = ' ' . trim((string)$haystack) . ' ';
    $phrase = trim((string)$phrase);
    if ($phrase === '') {
        return false;
    }
    // Use boundary-like matching for single words to avoid "hi" matching "something".
    if (strpos($phrase, ' ') === false) {
        return strpos($haystack, ' ' . $phrase . ' ') !== false;
    }
    return strpos($haystack, $phrase) !== false;
}

function contains_any_phrase($haystack, $phrases) {
    foreach ($phrases as $phrase) {
        if (contains_phrase($haystack, $phrase)) {
            return true;
        }
    }
    return false;
}

function pick_rotating_reply($key, $messages) {
    if (!isset($_SESSION['chatbot_reply_idx'])) {
        $_SESSION['chatbot_reply_idx'] = [];
    }
    $idx = (int)($_SESSION['chatbot_reply_idx'][$key] ?? 0);
    $reply = $messages[$idx % count($messages)];
    $_SESSION['chatbot_reply_idx'][$key] = $idx + 1;
    return $reply;
}

$message = normalize_text($rawMessage);

if (!isset($_SESSION['chatbot_ctx']) || !is_array($_SESSION['chatbot_ctx'])) {
    $_SESSION['chatbot_ctx'] = [
        'last_product' => null,
        'last_category' => null,
        'last_intent' => null,
        'taste_prefs' => [],
        'last_price_value' => null
    ];
}
$ctx = &$_SESSION['chatbot_ctx'];
if (!isset($ctx['taste_prefs']) || !is_array($ctx['taste_prefs'])) {
    $ctx['taste_prefs'] = [];
}
if (!array_key_exists('last_price_value', $ctx)) {
    $ctx['last_price_value'] = null;
}

$greetings = ['hello', 'hi', 'hey', 'yo', 'sup', 'good morning', 'good afternoon', 'good evening', 'sousdey', 'susdey'];
$farewells = ['bye', 'goodbye', 'see you', 'later', 'take care', 'good night'];
$thanks = ['thank', 'thanks', 'thx', 'appreciate', 'awkun', 'akun', 'arkun'];
$positive = ['nice', 'great', 'good', 'cool', 'awesome', 'yummy', 'love it', 'ok', 'okay', 'alright', 'sure'];
$neutralAcks = ['normal', 'hmm', 'hmmm', 'hmm ok'];
$priceWords = ['price', 'cost', 'how much', 'cheap', 'budget', 'low price', 'affordable'];
$cheapestWords = ['cheapest', 'lowest', 'lowest price', 'least expensive'];
$recommendWords = ['recommend', 'suggest', 'best', 'popular', 'favorite', 'what should i drink'];
$expensiveWords = ['expensive', 'premium', 'high price', 'pricey', 'most expensive'];
$lowerWords = ['lower', 'lower price', 'cheaper', 'less expensive'];
$higherWords = ['higher', 'higher price', 'more expensive', 'pricier'];
$menuWords = ['menu', 'drink', 'coffee', 'what do you have'];
$orderWords = ['order', 'buy', 'checkout', 'cart', 'pay'];
$hoursWords = ['open', 'close', 'hour', 'time'];
$contactWords = ['contact', 'phone', 'location', 'address', 'where'];
$feelingWords = ['how are you', 'how r u', 'how you doing'];
$staffWords = ['staff', 'employee', 'barista', 'cashier', 'admin'];
$sweetTasteWords = ['sweet', 'sweeter', 'sugary', 'dessert'];
$strongTasteWords = ['strong', 'bold', 'kick', 'caffeine'];
$lightTasteWords = ['light', 'less sweet', 'not sweet', 'mild'];
$hotTasteWords = ['hot', 'warm'];
$icedTasteWords = ['iced', 'cold', 'ice'];

$categorySynonyms = [
    'Hot' => ['hot', 'warm'],
    'Iced' => ['iced', 'cold', 'ice'],
    'Frappe' => ['frappe', 'blended'],
    'Juice' => ['juice', 'fresh juice'],
    'Milk Tea' => ['milk tea', 'boba', 'bubble tea', 'taro']
];

// Load products once for entity recognition and contextual answers.
$products = [];
$stmtProducts = $conn->prepare("SELECT product_id, name, description, price, category FROM products");
$stmtProducts->execute();
$resProducts = $stmtProducts->get_result();
while ($row = $resProducts->fetch_assoc()) {
    $row['name_norm'] = normalize_text($row['name']);
    $products[] = $row;
}

$matchedProduct = null;
foreach ($products as $product) {
    if ($product['name_norm'] !== '' && strpos($message, $product['name_norm']) !== false) {
        $matchedProduct = $product;
        break;
    }
}

if (!$matchedProduct) {
    // Fuzzy fallback: only match when message reasonably resembles product name.
    $compactMessage = str_replace(' ', '', $message);
    $messageLen = strlen($compactMessage);
    foreach ($products as $product) {
        $compactName = str_replace(' ', '', $product['name_norm']);
        if ($compactName === '' || $messageLen < 4) {
            continue;
        }
        // Require user message to contain most of the product name; avoids "bro" => "brown sugar".
        if (strpos($compactMessage, $compactName) !== false) {
            $matchedProduct = $product;
            break;
        }
    }
}

$detectedCategory = null;
foreach ($categorySynonyms as $category => $synonyms) {
    if (contains_any($message, $synonyms)) {
        $detectedCategory = $category;
        break;
    }
}

if ($matchedProduct) {
    $ctx['last_product'] = $matchedProduct;
    $ctx['last_category'] = $matchedProduct['category'];
}
if ($detectedCategory) {
    $ctx['last_category'] = $detectedCategory;
}

// Short-term preference memory
$prefUpdated = false;
if (contains_any($message, $sweetTasteWords)) {
    $ctx['taste_prefs']['sweet'] = true;
    $prefUpdated = true;
}
if (contains_any($message, $strongTasteWords)) {
    $ctx['taste_prefs']['strong'] = true;
    $prefUpdated = true;
}
if (contains_any($message, $lightTasteWords)) {
    $ctx['taste_prefs']['light'] = true;
    $prefUpdated = true;
}
if (contains_any($message, $hotTasteWords)) {
    $ctx['taste_prefs']['hot'] = true;
    $ctx['taste_prefs']['iced'] = false;
    $prefUpdated = true;
}
if (contains_any($message, $icedTasteWords)) {
    $ctx['taste_prefs']['iced'] = true;
    $ctx['taste_prefs']['hot'] = false;
    $prefUpdated = true;
}

// Intent handling (ordered by priority).
if (contains_any_phrase($message, $farewells)) {
    echo json_encode(['reply' => pick_rotating_reply('bye', [
        'Bye for now! Hope to see you again soon at Bird\'s Nest. 👋',
        'See you next time! Your next coffee is waiting. ☕',
        'Take care! Drop by anytime for a fresh cup.'
    ])]);
    exit;
}

if (contains_any_phrase($message, $thanks)) {
    echo json_encode(['reply' => pick_rotating_reply('thanks', [
        'You are very welcome! Happy to help.',
        'My pleasure! Need another drink suggestion?',
        'Anytime! I can also suggest something based on your mood.'
    ])]);
    exit;
}

if (contains_any_phrase($message, $greetings)) {
    echo json_encode(['reply' => pick_rotating_reply('greet', [
        'Hey! Welcome to Bird\'s Nest Coffee. What are you craving today?',
        'Hi there! Want something hot, iced, or sweet?',
        'Hello! I can help with prices, recommendations, and menu choices.'
    ])]);
    exit;
}

if (contains_any_phrase($message, $feelingWords)) {
    echo json_encode(['reply' => pick_rotating_reply('feeling', [
        'Doing great, thanks! Ready to help you pick a nice drink today.',
        'I am good and caffeinated. What are you in the mood for?',
        'I am doing well! Want a quick recommendation?'
    ])]);
    exit;
}

if (contains_any_phrase($message, $positive)) {
    echo json_encode(['reply' => pick_rotating_reply('positive', [
        'Love that! Want me to suggest another one you might enjoy?',
        'Awesome! I can pair that with another popular drink too.',
        'Glad you like it! Looking for something similar?'
    ])]);
    exit;
}

if (contains_any_phrase($message, $neutralAcks)) {
    echo json_encode(['reply' => "Got it. Tell me your mood and I will suggest the best match - hot, iced, sweet, or strong coffee."]);
    exit;
}

if (contains_any_phrase($message, $staffWords)) {
    $ctx['last_intent'] = 'staff_help';
    echo json_encode(['reply' => "Staff tip: you can ask me for quick suggestions like '3 cheap hot drinks' or 'best seller under \$3'."]);
    exit;
}

if (
    $prefUpdated &&
    contains_any_phrase($message, ['i like', 'i want', 'prefer', 'love', 'want']) &&
    !contains_any_phrase($message, $priceWords) &&
    !contains_any_phrase($message, $cheapestWords) &&
    !contains_any_phrase($message, $recommendWords)
) {
    $prefs = [];
    if (!empty($ctx['taste_prefs']['sweet'])) $prefs[] = 'sweet';
    if (!empty($ctx['taste_prefs']['strong'])) $prefs[] = 'strong';
    if (!empty($ctx['taste_prefs']['light'])) $prefs[] = 'light';
    if (!empty($ctx['taste_prefs']['hot'])) $prefs[] = 'hot';
    if (!empty($ctx['taste_prefs']['iced'])) $prefs[] = 'iced';
    $prefText = empty($prefs) ? 'your taste' : implode(', ', $prefs);
    echo json_encode(['reply' => "Nice, I will remember you prefer {$prefText} drinks in this chat."]);
    exit;
}

if ($matchedProduct) {
    $ctx['last_intent'] = 'product_info';
    $ctx['last_price_value'] = (float)$matchedProduct['price'];
    $reply = $matchedProduct['name'] . " is a great choice! " .
             ($matchedProduct['description'] !== '' ? $matchedProduct['description'] . ' ' : '') .
             "Price: $" . number_format((float)$matchedProduct['price'], 2) . ". " .
             "Category: " . $matchedProduct['category'] . ".";
    echo json_encode(['reply' => $reply]);
    exit;
}

if (contains_any_phrase($message, $cheapestWords)) {
    $ctx['last_intent'] = 'cheapest';
    if (!empty($ctx['last_category'])) {
        $stmtCheap = $conn->prepare("
            SELECT name, price
            FROM products
            WHERE category = ?
              AND price >= 0.50
            ORDER BY price ASC
            LIMIT 3
        ");
        $stmtCheap->bind_param("s", $ctx['last_category']);
        $stmtCheap->execute();
        $res = $stmtCheap->get_result();
        $list = [];
        while ($row = $res->fetch_assoc()) {
            $list[] = $row['name'] . ' ($' . number_format((float)$row['price'], 2) . ')';
        }
        if (!empty($list)) {
            if (preg_match('/\(\$([0-9]+(?:\.[0-9]+)?)\)/', $list[0], $m)) {
                $ctx['last_price_value'] = (float)$m[1];
            }
            echo json_encode(['reply' => "Cheapest {$ctx['last_category']} options: " . implode(', ', $list) . "."]);
            exit;
        }
    }

    $stmtCheap = $conn->prepare("
        SELECT name, price
        FROM products
        WHERE price >= 0.50
        ORDER BY price ASC
        LIMIT 3
    ");
    $stmtCheap->execute();
    $res = $stmtCheap->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) {
        $list[] = $row['name'] . ' ($' . number_format((float)$row['price'], 2) . ')';
    }
    if (!empty($list)) {
        if (preg_match('/\(\$([0-9]+(?:\.[0-9]+)?)\)/', $list[0], $m)) {
            $ctx['last_price_value'] = (float)$m[1];
        }
        echo json_encode(['reply' => "Our cheapest drinks are: " . implode(', ', $list) . "."]);
        exit;
    }
}

if (contains_any_phrase($message, $priceWords)) {
    $ctx['last_intent'] = 'price';
    if (!empty($ctx['last_product']) && is_array($ctx['last_product'])) {
        $p = $ctx['last_product'];
        $ctx['last_price_value'] = (float)$p['price'];
        echo json_encode(['reply' => $p['name'] . " is $" . number_format((float)$p['price'], 2) . ". Want another option in a similar price range?"]);
        exit;
    }

    if (!empty($ctx['last_category'])) {
        $stmtCheap = $conn->prepare("
            SELECT name, price
            FROM products
            WHERE category = ?
              AND price >= 0.50
            ORDER BY price ASC
            LIMIT 3
        ");
        $stmtCheap->bind_param("s", $ctx['last_category']);
        $stmtCheap->execute();
        $cheapRes = $stmtCheap->get_result();
    } else {
        $stmtCheap = $conn->prepare("
            SELECT name, price
            FROM products
            WHERE price >= 0.50
            ORDER BY price ASC
            LIMIT 3
        ");
        $stmtCheap->execute();
        $cheapRes = $stmtCheap->get_result();
    }

    $cheap = [];
    while ($row = $cheapRes->fetch_assoc()) {
        $cheap[] = $row['name'] . ' ($' . number_format((float)$row['price'], 2) . ')';
    }
    if (!empty($cheap) && preg_match('/\(\$([0-9]+(?:\.[0-9]+)?)\)/', $cheap[0], $m)) {
        $ctx['last_price_value'] = (float)$m[1];
    }
    $msg = empty($cheap)
        ? "I couldn't fetch prices right now, but I can still recommend a drink style for you."
        : (!empty($ctx['last_category'])
            ? "Cheapest {$ctx['last_category']} picks are: " . implode(', ', $cheap) . "."
            : "Our budget-friendly picks are: " . implode(', ', $cheap) . ".");
    echo json_encode(['reply' => $msg]);
    exit;
}

if (contains_any_phrase($message, ['everyone recommend', 'everyone recommends', 'most recommended', 'best seller', 'bestseller', 'popular', 'best drink in this restaurant', 'best in this restaurant', 'overall best'])) {
    $ctx['last_intent'] = 'bestseller';
    $useCategoryScope = $detectedCategory !== null;
    $sqlBest = "
        SELECT oi.product_name, SUM(oi.quantity) AS sold_qty
        FROM order_items oi
        " . ($useCategoryScope ? "JOIN products p ON p.name = oi.product_name AND p.category = ?" : "") . "
        GROUP BY oi.product_name
        ORDER BY sold_qty DESC
        LIMIT 3
    ";
    $stmtBest = $conn->prepare($sqlBest);
    if ($useCategoryScope) {
        $stmtBest->bind_param("s", $detectedCategory);
    }
    $stmtBest->execute();
    $resBest = $stmtBest->get_result();
    $best = [];
    while ($row = $resBest->fetch_assoc()) {
        $best[] = $row['product_name'] . ' (' . (int)$row['sold_qty'] . ' sold)';
    }
    if (!empty($best)) {
        $prefix = $useCategoryScope ? "Most recommended {$detectedCategory} drinks: " : "Most recommended drinks in the restaurant: ";
        echo json_encode(['reply' => $prefix . implode(', ', $best) . "."]);
        exit;
    }
}

if (contains_any_phrase($message, $expensiveWords)) {
    $ctx['last_intent'] = 'expensive';
    $targetCategory = $ctx['last_category'] ?? null;
    if ($targetCategory) {
        $stmtPremium = $conn->prepare("
            SELECT name, price
            FROM products
            WHERE category = ?
            ORDER BY price DESC
            LIMIT 3
        ");
        $stmtPremium->bind_param("s", $targetCategory);
        $stmtPremium->execute();
        $resPremium = $stmtPremium->get_result();
        $top = [];
        while ($row = $resPremium->fetch_assoc()) {
            $top[] = $row['name'] . ' ($' . number_format((float)$row['price'], 2) . ')';
        }
        if (!empty($top)) {
            if (preg_match('/\(\$([0-9]+(?:\.[0-9]+)?)\)/', $top[0], $m)) {
                $ctx['last_price_value'] = (float)$m[1];
            }
            echo json_encode(['reply' => "Premium {$targetCategory} picks: " . implode(', ', $top) . "."]);
            exit;
        }
    }

    $stmtPremium = $conn->prepare("
        SELECT name, price
        FROM products
        ORDER BY price DESC
        LIMIT 3
    ");
    $stmtPremium->execute();
    $resPremium = $stmtPremium->get_result();
    $top = [];
    while ($row = $resPremium->fetch_assoc()) {
        $top[] = $row['name'] . ' ($' . number_format((float)$row['price'], 2) . ')';
    }
    if (!empty($top)) {
        if (preg_match('/\(\$([0-9]+(?:\.[0-9]+)?)\)/', $top[0], $m)) {
            $ctx['last_price_value'] = (float)$m[1];
        }
        echo json_encode(['reply' => "Our most premium drinks are: " . implode(', ', $top) . "."]);
        exit;
    }
}

if (contains_any_phrase($message, $lowerWords) || contains_any_phrase($message, $higherWords)) {
    $isLower = contains_any_phrase($message, $lowerWords);
    $basePrice = isset($ctx['last_price_value']) ? (float)$ctx['last_price_value'] : 0.0;
    $category = !empty($ctx['last_category']) ? $ctx['last_category'] : null;

    if ($basePrice > 0) {
        if ($category) {
            $sql = $isLower
                ? "SELECT name, price FROM products WHERE category = ? AND price < ? AND price >= 0.50 ORDER BY price DESC LIMIT 3"
                : "SELECT name, price FROM products WHERE category = ? AND price > ? ORDER BY price ASC LIMIT 3";
            $stmtRel = $conn->prepare($sql);
            $stmtRel->bind_param("sd", $category, $basePrice);
        } else {
            $sql = $isLower
                ? "SELECT name, price FROM products WHERE price < ? AND price >= 0.50 ORDER BY price DESC LIMIT 3"
                : "SELECT name, price FROM products WHERE price > ? ORDER BY price ASC LIMIT 3";
            $stmtRel = $conn->prepare($sql);
            $stmtRel->bind_param("d", $basePrice);
        }
        $stmtRel->execute();
        $resRel = $stmtRel->get_result();
        $list = [];
        while ($row = $resRel->fetch_assoc()) {
            $list[] = $row['name'] . ' ($' . number_format((float)$row['price'], 2) . ')';
        }
        if (!empty($list)) {
            if (preg_match('/\(\$([0-9]+(?:\.[0-9]+)?)\)/', $list[0], $m)) {
                $ctx['last_price_value'] = (float)$m[1];
            }
            $prefix = $isLower ? "Here are lower-price options" : "Here are higher-price options";
            echo json_encode(['reply' => $prefix . ($category ? " in {$category}" : "") . ": " . implode(', ', $list) . "."]);
            exit;
        }
    }

    echo json_encode(['reply' => $isLower
        ? "Tell me a drink or price first, then I can find lower options."
        : "Tell me a drink or price first, then I can find higher options."]);
    exit;
}

if (contains_any_phrase($message, $recommendWords)) {
    $ctx['last_intent'] = 'recommend';
    $askOverallBest = contains_any_phrase($message, ['best drink', 'best one', 'top drink', 'most recommended', 'overall best']);

    if ($askOverallBest && $detectedCategory === null) {
        $stmtBest = $conn->prepare("
            SELECT oi.product_name, SUM(oi.quantity) AS sold_qty
            FROM order_items oi
            GROUP BY oi.product_name
            ORDER BY sold_qty DESC
            LIMIT 3
        ");
        $stmtBest->execute();
        $resBest = $stmtBest->get_result();
        $best = [];
        while ($row = $resBest->fetch_assoc()) {
            $best[] = $row['product_name'] . ' (' . (int)$row['sold_qty'] . ' sold)';
        }
        if (!empty($best)) {
            echo json_encode(['reply' => "Most recommended drinks in the restaurant: " . implode(', ', $best) . "."]);
            exit;
        }
    }

    // Only use category-focused recommendation if user explicitly asked category this turn.
    $targetCategory = $detectedCategory;
    if ($targetCategory) {
        $stmtRec = $conn->prepare("
            SELECT name, price
            FROM products
            WHERE category = ?
            ORDER BY price DESC
            LIMIT 3
        ");
        $stmtRec->bind_param("s", $targetCategory);
        $stmtRec->execute();
        $resRec = $stmtRec->get_result();
        $list = [];
        while ($row = $resRec->fetch_assoc()) {
            $list[] = $row['name'] . ' ($' . number_format((float)$row['price'], 2) . ')';
        }
        if (!empty($list)) {
            echo json_encode(['reply' => "If you like {$targetCategory}, try: " . implode(', ', $list) . "."]);
            exit;
        }
    }

    $prefFilters = [];
    if (!empty($ctx['taste_prefs']['hot'])) {
        $prefFilters[] = "category = 'Hot'";
    } elseif (!empty($ctx['taste_prefs']['iced'])) {
        $prefFilters[] = "category = 'Iced'";
    }
    if (!empty($ctx['taste_prefs']['sweet'])) {
        $prefFilters[] = "(LOWER(name) LIKE '%caramel%' OR LOWER(name) LIKE '%taro%' OR LOWER(name) LIKE '%chocolate%' OR LOWER(name) LIKE '%mocha%')";
    }
    if (!empty($ctx['taste_prefs']['strong'])) {
        $prefFilters[] = "(LOWER(name) LIKE '%americano%' OR LOWER(name) LIKE '%espresso%')";
    }

    if (!empty($prefFilters)) {
        $sql = "
            SELECT name, price
            FROM products
            WHERE " . implode(' AND ', $prefFilters) . "
            ORDER BY price ASC
            LIMIT 3
        ";
        $resPref = $conn->query($sql);
        $prefList = [];
        if ($resPref) {
            while ($row = $resPref->fetch_assoc()) {
                $prefList[] = $row['name'] . ' ($' . number_format((float)$row['price'], 2) . ')';
            }
        }
        if (!empty($prefList)) {
            echo json_encode(['reply' => "Based on your taste, I recommend: " . implode(', ', $prefList) . "."]);
            exit;
        }
    }

    echo json_encode(['reply' => pick_rotating_reply('recommend', [
        'Top picks today: Iced Americano, Taro Bubble Milk Tea, and Caramel Frappe.',
        'Great choices to start with: Hot Latte, Iced Matcha Latte, and Thai Milk Tea.',
        'Customer favorites: Iced Americano, Taro Bubble Milk Tea, and Mocha Frappe.',
        'If you want something sweet, try Caramel Frappe. If you want stronger coffee, go for Iced Americano.'
    ])]);
    exit;
}

if ($detectedCategory || contains_any($message, $menuWords)) {
    $ctx['last_intent'] = 'menu';
    $category = $detectedCategory ?: ($ctx['last_category'] ?? null);

    if ($category) {
        $stmtMenu = $conn->prepare("
            SELECT name
            FROM products
            WHERE category = ?
            ORDER BY name ASC
            LIMIT 5
        ");
        $stmtMenu->bind_param("s", $category);
        $stmtMenu->execute();
        $menuRes = $stmtMenu->get_result();
        $names = [];
        while ($row = $menuRes->fetch_assoc()) {
            $names[] = $row['name'];
        }
        if (!empty($names)) {
            echo json_encode(['reply' => "Our {$category} drinks include: " . implode(', ', $names) . "."]);
            exit;
        }
    }

    echo json_encode(['reply' => "We serve Iced, Hot, Frappe, Juice, and Milk Tea. Tell me your mood and I will suggest one."]);
    exit;
}

if (contains_any($message, $orderWords)) {
    $ctx['last_intent'] = 'order_help';
    echo json_encode(['reply' => "You can add drinks from the menu, open cart, then checkout. We accept Cash, Bakong, and Pay Later. If you want, I can suggest drinks before you order."]);
    exit;
}

if (contains_any_phrase($message, ['reset preference', 'clear preference', 'reset chat', 'start over'])) {
    $ctx['taste_prefs'] = [];
    $ctx['last_product'] = null;
    $ctx['last_category'] = null;
    $ctx['last_intent'] = null;
    echo json_encode(['reply' => "Done, I cleared your chat preferences. Tell me what taste you want now."]);
    exit;
}

if (contains_any_phrase($message, $hoursWords)) {
    $ctx['last_intent'] = 'hours';
    echo json_encode(['reply' => "We are open daily from 6:00 AM to 8:00 PM."]);
    exit;
}

if (contains_any_phrase($message, $contactWords)) {
    $ctx['last_intent'] = 'contact';
    echo json_encode(['reply' => "We are in Phnom Penh. Phone: +855 123 456 789."]);
    exit;
}

// Context-aware follow-up: "how much is it?" without product name.
if (contains_any_phrase($message, ['it', 'that', 'this']) && contains_any($message, $priceWords) && !empty($ctx['last_product'])) {
    $p = $ctx['last_product'];
    echo json_encode(['reply' => $p['name'] . " is $" . number_format((float)$p['price'], 2) . "."]);
    exit;
}

// Smarter varied fallback
echo json_encode(['reply' => pick_rotating_reply('fallback', [
    "I want to help. You can ask me things like: 'recommend something hot', 'cheapest drink', or a drink name like 'vanilla frappe'.",
    "I did not fully catch that, but I can help with menu, prices, recommendations, and ordering.",
    "Could you rephrase a little? For example: 'what is your best milk tea?' or 'how much is iced americano?'",
    "No worries, let us try again. Tell me your taste: sweet, strong coffee, hot, or iced."
])]);
exit;
?>