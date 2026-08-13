<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

// --- НАСТРОЙКИ ---
$botToken = "8883380357:AAHrYtiqhcCTBvllozb5m4pMUQIw922a0Oo";      // Токен от @BotFather
$adminId  = 8875180956;    // Твой числовой ID

$action = $_GET['action'] ?? '';

// --- 1. ПРОВЕРКА КЛЮЧА ИЗ LUA-СКРИПТА ---
if ($action === "check") {
    $key = $_POST['key'] ?? $_GET['key'] ?? '';
    
    if (empty($key)) {
        echo json_encode(["status" => "error", "message" => "Укажите ключ!"]);
        exit;
    }

    if (strpos($key, "LORI-") === 0) {
        echo "SUCCESS";
    } else {
        echo "Неверный ключ!";
    }
    exit;
}

// --- 2. TELEGRAM BOT API ФУНКЦИИ ---
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    header('Content-Type: text/html; charset=utf-8');
    echo "Lori Bot Active";
    exit;
}

function tgRequest($method, $data) {
    global $botToken;
    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
        ],
    ];
    $context  = stream_context_create($options);
    @file_get_contents("https://api.telegram.org/bot$botToken/$method", false, $context);
}

function sendMessage($chat_id, $text, $reply_markup = null) {
    $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($reply_markup) $data['reply_markup'] = $reply_markup;
    tgRequest('sendMessage', $data);
}

function sendInvoice($chat_id, $title, $description, $payload, $stars) {
    $data = [
        'chat_id' => $chat_id,
        'title' => $title,
        'description' => $description,
        'payload' => $payload,
        'currency' => 'XTR', // Валюта Telegram Stars
        'prices' => [['label' => 'Stars', 'amount' => $stars]]
    ];
    tgRequest('sendInvoice', $data);
}

// --- 3. СОБЫТИЯ ТЕЛЕГРАМА ---

// Подтверждение платежа
if (isset($update['pre_checkout_query'])) {
    $pqId = $update['pre_checkout_query']['id'];
    tgRequest('answerPreCheckoutQuery', ['pre_checkout_query_id' => $pqId, 'ok' => true]);
    exit;
}

// Успешная оплата (генерация ключа «на лету»)
if (isset($update['message']['successful_payment'])) {
    $chatId = $update['message']['chat']['id'];
    
    $newKey = "LORI-" . strtoupper(substr(md5(rand() . time()), 0, 10));

    sendMessage($chatId, "🎉 Оплата успешно прошла!\n\nВаш ключ доступа:\n$newKey\n\n*Скопируйте и введите его в скрипте.*");
    exit;
}

// Команды
if (isset($update['message'])) {
    $chatId = intval($update['message']['chat']['id']);
    $text = trim($update['message']['text']);

    if ($text === "/start") {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '💳 Купить ключ (24 часа) - 50 ⭐️', 'callback_data' => 'buy_24']],
                [['text' => '💎 Купить ключ (7 дней) - 150 ⭐️', 'callback_data' => 'buy_168']]
            ]
        ];
        
        // Добавление кнопки админки только для тебя
        if ($chatId === intval($adminId)) {
            $keyboard['inline_keyboard'][] = [['text' => '👑 Админ-панель', 'callback_data' => 'admin_panel']];
        }

        sendMessage($chatId, "👋 Добро пожаловать в магазин Lori Store!\n\nВыберите нужный товар для покупки за Telegram Stars:", $keyboard);
    }
}

// Кнопки магазина и админки
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $chatId = intval($cq['message']['chat']['id']);
    $data = $cq['data'];
    $messageId = $cq['message']['message_id'];

    if ($data === 'buy_24') {
        sendInvoice($chatId, "Ключ Lori (24 часа)", "Доступ к скрипту на 24 часа", "sub_24", 50);
    } elseif ($data === 'buy_168') {
        sendInvoice($chatId, "Ключ Lori (7 дней)", "Доступ к скрипту на 7 дней", "sub_168", 150);} elseif ($data === 'admin_panel' && $chatId === intval($adminId)) {
        $text = "👑 Панель администратора\n\nУправление ключами:";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ Создать ключ (24ч)', 'callback_data' => 'adm_create_24']],
                [['text' => '➕ Создать ключ (7 дней)', 'callback_data' => 'adm_create_168']],
                [['text' => '« На главную', 'callback_data' => 'back_home']]
            ]
        ];
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $keyboard]);
    } elseif ($data === 'adm_create_24' && $chatId === intval($adminId)) {
        $newKey = "LORI-" . strtoupper(substr(md5(rand() . time()), 0, 10));
        sendMessage($chatId, "✅ Сгенерирован ключ (24 часа):\n$newKey");
    } elseif ($data === 'adm_create_168' && $chatId === intval($adminId)) {
        $newKey = "LORI-" . strtoupper(substr(md5(rand() . time()), 0, 10));
        sendMessage($chatId, "✅ Сгенерирован ключ (7 дней):\n$newKey");
    } elseif ($data === 'back_home') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '💳 Купить ключ (24 часа) - 50 ⭐️', 'callback_data' => 'buy_24']],
                [['text' => '💎 Купить ключ (7 дней) - 150 ⭐️', 'callback_data' => 'buy_168']]
            ]
        ];
        if ($chatId === intval($adminId)) {
            $keyboard['inline_keyboard'][] = [['text' => '👑 Админ-панель', 'callback_data' => 'admin_panel']];
        }
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "👋 Добро пожаловать в магазин Lori Store!\n\nВыберите нужный товар для покупки за Telegram Stars:", 'parse_mode' => 'Markdown', 'reply_markup' => $keyboard]);
    }
}
?>
