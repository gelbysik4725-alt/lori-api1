<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

// --- НАСТРОЙКИ ---
$botToken = "8883380357:AAHrYtiqhcCTBvllozb5m4pMUQIw922a0Oo";
$adminId  = 8875180956;

// Автоматическое создание/чтение файла базы данных
$file = "database.json";
$db = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($db)) $db = [];

$action = $_GET['action'] ?? '';

// --- 1. ПРОВЕРКА КЛЮЧА ИЗ LUA-СКРИПТА ---
if ($action === "check") {
    $key = $_POST['key'] ?? $_GET['key'] ?? '';
    $hwid = $_POST['hwid'] ?? $_GET['hwid'] ?? '';
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    if (empty($key)) {
        echo json_encode(["status" => "error", "message" => "Укажите ключ!"]);
        exit;
    }

    if (!isset($db[$key])) {
        echo "Неверный ключ!";
        exit;
    }

    $keyData = $db[$key];
    $currentTime = time();

    // Проверка истечения срока
    if ($keyData['expires'] !== 0 && $currentTime > $keyData['expires']) {
        unset($db[$key]);
        file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
        echo "Срок действия истек!";
        exit;
    }

    $activations = $keyData['activations'] ?? [];
    $maxLimit = intval($keyData['max'] ?? 1);

    // Если HWID уже привязан — пускаем и обновляем IP
    foreach ($activations as &$act) {
        if ($act['hwid'] === $hwid) {
            $act['ip'] = $ip;
            file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
            echo "SUCCESS";
            exit;
        }
    }
    unset($act);

    // Если устройство новое
    if (count($activations) < $maxLimit) {
        // Если ключ активируется впервые — запускаем таймер
        if ($keyData['first_use'] == 0) {
            $db[$key]['first_use'] = $currentTime;
            if ($keyData['duration'] > 0) {
                $db[$key]['expires'] = $currentTime + $keyData['duration'];
            }
        }

        $db[$key]['activations'][] = [
            'hwid' => $hwid,
            'ip' => $ip,
            'time' => $currentTime
        ];
        
        file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
        echo "SUCCESS";
    } else {
        echo "Превышен лимит активаций для этого ключа!";
    }
    exit;
}

// --- 2. TELEGRAM BOT API ФУНКЦИИ ---
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    header('Content-Type: text/html; charset=utf-8');
    echo "Lori Bot & API Active";
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
        'currency' => 'XTR',
        'prices' => [['label' => 'Stars', 'amount' => $stars]]
    ];
    tgRequest('sendInvoice', $data);
}

// --- 3. СОБЫТИЯ ТЕЛЕГРАМА ---

if (isset($update['pre_checkout_query'])) {
    $pqId = $update['pre_checkout_query']['id'];
    tgRequest('answerPreCheckoutQuery', ['pre_checkout_query_id' => $pqId, 'ok' => true]);
    exit;
}

// Автоматическая генерация ключа при оплате Звездами
if (isset($update['message']['successful_payment'])) {
    $chatId = $update['message']['chat']['id'];
    $payload = $update['message']['successful_payment']['invoice_payload'];
    
    $parts = explode("_", $payload);
    $duration = intval($parts[1]);
    $maxLimit = intval($parts[2]);

    $newKey = "LORI-" . strtoupper(substr(md5(rand() . time()), 0, 10));
    
    $db[$newKey] = [
        "duration" => $duration,
        "expires" => 0,
        "first_use" => 0,
        "max" => $maxLimit,
        "activations" => []
    ];
    file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));

    sendMessage($chatId, "🎉 **Оплата успешно прошла!**\n\nВаш персональный ключ доступа:\n`$newKey`\n\n*Таймер ключа запустится автоматически при первом вводе в скрипте.*");
    exit;
}

// Команды
if (isset($update['message'])) {
    $chatId = intval($update['message']['chat']['id']);
    $text = trim($update['message']['text']);

    if ($text === "/start") {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⏳ 24 часа — 25 ⭐️', 'callback_data' => 'buy_86400_1']],
                [['text' => '📅 7 дней — 75 ⭐️', 'callback_data' => 'buy_604800_1']],
                [['text' => '🗓 30 дней — 125 ⭐️', 'callback_data' => 'buy_2592000_1']],
                [['text' => '🗓 90 дней — 200 ⭐️', 'callback_data' => 'buy_7776000_1']],
                [['text' => '♾️ Навсегда — 400 ⭐️', 'callback_data' => 'buy_0_1']]
            ]
        ];
        
        if ($chatId === intval($adminId)) {
            $keyboard['inline_keyboard'][] = [['text' => '👑 Админ-панель', 'callback_data' => 'admin_panel']];
        }

        sendMessage($chatId, "👋 Добро пожаловать в магазин **Lori Store**!\n\nВыберите тариф для покупки за Telegram Stars:", $keyboard);
    }
}

// Кнопки и админка
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $chatId = intval($cq['message']['chat']['id']);
    $data = $cq['data'];
    $messageId = $cq['message']['message_id'];

    if (strpos($data, 'buy_') === 0) {
        $parts = explode("_", $data);
        $dur = $parts[1];
        $max = $parts[2];
        
        $titles = [
            '86400' => ['Ключ Lori (24 часа)', 25],
            '604800' => ['Ключ Lori (7 дней)', 75],
            '2592000' => ['Ключ Lori (30 дней)', 125],
            '7776000' => ['Ключ Lori (90 дней)', 200],
            '0' => ['Ключ Lori (Навсегда)', 400]
        ];
        
        if (isset($titles[$dur])) {
            sendInvoice($chatId, $titles[$dur][0], "Доступ к скрипту (лимит: $max устр.)", "sub_{$dur}_{$max}", $titles[$dur][1]);
        }
    } 
    elseif ($data === 'admin_panel' && $chatId === intval($adminId)) {
        $total = count($db);
        $text = "👑 **Панель администратора**\n\nВсего ключей в базе: $total";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📋 Список ключей (Управление)', 'callback_data' => 'adm_list']],
                [['text' => '« На главную', 'callback_data' => 'back_home']]
            ]
        ];
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $keyboard]);
    } 
    elseif ($data === 'adm_list' && $chatId === intval($adminId)) {
        if (empty($db)) {
            sendMessage($chatId, "📭 База данных ключей пуста.");
            return;
        }

        foreach ($db as $k => $d) {
            $usedCount = count($d['activations'] ?? []);
            $maxLimit = $d['max'] ?? 1;
            
            if ($d['first_use'] == 0) {
                $status = "⏳ Не активирован";
            } else {
                $remains = $d['expires'] - time();
                if ($d['expires'] == 0) {
                    $status = "♾️ Бессрочный";
                } elseif ($remains > 0) {
                    $days = floor($remains / 86400);
                    $hours = floor(($remains % 86400) / 3600);
                    $status = "🟢 Активен (осталось {$days}д {$hours}ч)";
                } else {
                    $status = "🔴 Истек";
                }
            }

            $info = "🔑 Ключ: `$k`\n";
            $info .= "📌 Статус: $status\n";
            $info .= "👥 Лимит: {$usedCount}/{$maxLimit}\n";
            
            if ($usedCount > 0) {
                $info .= "💻 Активации:\n";
                foreach ($d['activations'] as $act) {
                    $actDate = date("d.m.Y H:i", $act['time']);
                    $info .= " ├ HWID: `{$act['hwid']}`\n ├ IP: `{$act['ip']}`\n └ Время: {$actDate}\n";
                }
            } else {
                $info .= "💻 Активаций еще не было\n";
            }

            $delKeyboard = [
                'inline_keyboard' => [
                    [['text' => '❌ Удалить этот ключ', 'callback_data' => 'adm_del_' . $k]],
                    [['text' => '« В админку', 'callback_data' => 'admin_panel']]
                ]
            ];
            sendMessage($chatId, $info, $delKeyboard);
        }
    } 
    elseif (strpos($data, 'adm_del_') === 0 && $chatId === intval($adminId)) {
        $keyToDel = str_replace('adm_del_', '', $data);
        if (isset($db[$keyToDel])) {
            unset($db[$keyToDel]);
            file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
            sendMessage($chatId, "🗑 Ключ `$keyToDel` успешно удален!");
        }
    } 
    elseif ($data === 'back_home') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⏳ 24 часа — 25 ⭐️', 'callback_data' => 'buy_86400_1']],
                [['text' => '📅 7 дней — 75 ⭐️', 'callback_data' => 'buy_604800_1']],
                [['text' => '🗓 30 дней — 125 ⭐️', 'callback_data' => 'buy_2592000_1']],
                [['text' => '🗓 90 дней — 200 ⭐️', 'callback_data' => 'buy_7776000_1']],
                [['text' => '♾️ Навсегда — 400 ⭐️', 'callback_data' => 'buy_0_1']]
            ]
        ];
        if ($chatId === intval($adminId)) {
            $keyboard['inline_keyboard'][] = [['text' => '👑 Админ-панель', 'callback_data' => 'admin_panel']];
        }
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "👋 Добро пожаловать в магазин **Lori Store**!\n\nВыберите тариф для покупки за Telegram Stars:", 'parse_mode' => 'Markdown', 'reply_markup' => $keyboard]);
    }
}
?>

