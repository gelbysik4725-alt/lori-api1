<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

// --- НАСТРОЙКИ ---
$botToken = "8883380357:AAHrYtiqhcCTBvllozb5m4pMUQIw922a0Oo";
$adminId  = 8875180956;

$dbFile = "database.json";
$db = file_exists($dbFile) ? json_decode(file_get_contents($dbFile), true) : [];
if (!is_array($db)) $db = [];

// Инициализация структуры базы данных
if (!isset($db['keys'])) $db['keys'] = [];
if (!isset($db['blacklist'])) $db['blacklist'] = [];
if (!isset($db['logs'])) $db['logs'] = [];
if (!isset($db['online'])) $db['online'] = [];
if (!isset($db['settings'])) {
    $db['settings'] = [
        "status" => "online", // online, maintenance, update, killswitch
        "version" => "1.0.0",
        "checksum" => "918bb079bf65835b77ee4d684ee725752ddfbf72", // Ваш SHA1 хэш скрипта
        "download_url" => "https://example.com/script.lua",
        "emergency_msg" => ""
    ];
}

function saveDb() {
    global $db, $dbFile;
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT, JSON_UNESCAPED_UNICODE));
}

function addLog($text) {
    global $db;
    $timestamp = time();
    array_unshift($db['logs'], ['time' => $timestamp, 'text' => $text]);
    if (count($db['logs']) > 100) array_pop($db['logs']);
    saveDb();
}

// Очистка старых сессий онлайн (если не было пинга больше 60 секунд)
foreach ($db['online'] as $hwid => $data) {
    if (time() - $data['last_ping'] > 60) {
        unset($db['online'][$hwid]);
    }
}
saveDb();

$action = $_GET['action'] ?? '';
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

// --- 0. ПРОВЕРКА СТАТУСА, ВЕРСИИ И ЦЕЛОСТНОСТИ (OTA & INTEGRITY) ---
if ($action === "status_check") {
    $clientChecksum = $_POST['checksum'] ?? $_GET['checksum'] ?? '';
    $hwid = $_POST['hwid'] ?? $_GET['hwid'] ?? '';
    $key = $_POST['key'] ?? $_GET['key'] ?? '';

    // Проверка целостности файла скрипта
    if (!empty($clientChecksum) && $clientChecksum !== $db['settings']['checksum']) {
        echo json_encode([
            "status" => "error",
            "message" => "Обнаружена модификация скрипта! Доступ заблокирован."
        ]);
        exit;
    }

    // Экстренное отключение (Killswitch)
    if ($db['settings']['status'] === 'killswitch') {
        echo json_encode([
            "status" => "killswitch",
            "message" => $db['settings']['emergency_msg'] ?: "Софт экстренно остановлен администратором!"
        ]);
        exit;
    }

    // Обновление статуса онлайн игрока (Heartbeat)
    if (!empty($hwid)) {
        $db['online'][$hwid] = [
            'ip' => $ip,
            'key' => $key,
            'last_ping' => time()
        ];
        saveDb();
    }

    echo json_encode([
        "status" => $db['settings']['status'],
        "version" => $db['settings']['version'],
        "checksum" => $db['settings']['checksum'],
        "url" => $db['settings']['download_url'],
        "emergency_msg" => $db['settings']['emergency_msg'] ?? ''
    ]);
    exit;
}

// --- 1. ПРОВЕРКА КЛЮЧА ИЗ LUA-СКРИПТА ---
if ($action === "check") {
    $key = $_POST['key'] ?? $_GET['key'] ?? '';
    $hwid = $_POST['hwid'] ?? $_GET['hwid'] ?? '';

    if ($db['settings']['status'] === 'maintenance') {
        echo "Сервер находится на техническом обслуживании!";
        exit;
    }
    if ($db['settings']['status'] === 'killswitch') {
        echo $db['settings']['emergency_msg'] ?: "Софт экстренно остановлен!";
        exit;
    }

    if (empty($key)) {
        echo json_encode(["status" => "error", "message" => "Укажите ключ!"]);
        exit;
    }

    if (isset($db['blacklist'][$ip]) || isset($db['blacklist'][$hwid])) {
        echo "Ваше устройство или IP заблокированы!";
        exit;
    }

    if (!isset($db['keys'][$key])) {
        echo "Неверный ключ!";
        exit;
    }

    $keyData = $db['keys'][$key];
    $currentTime = time();

    if (!empty($keyData['is_frozen'])) {
        echo "Ключ заморожен владельцем!";
        exit;
    }

    if ($keyData['expires'] !== 0 && $currentTime > $keyData['expires']) {
        unset($db['keys'][$key]);
        saveDb();
        echo "Срок действия истек!";
        exit;
    }

    $activations = $keyData['activations'] ?? [];
    $maxLimit = intval($keyData['max'] ?? 1);

    foreach ($activations as &$act) {
        if ($act['hwid'] === $hwid) {
            $act['ip'] = $ip;
            $act['last_active'] = $currentTime;
            saveDb();
            echo "SUCCESS";
            exit;
        }
    }
    unset($act);

    if (count($activations) < $maxLimit) {
        if ($keyData['first_use'] == 0) {
            $db['keys'][$key]['first_use'] = $currentTime;
            if ($keyData['duration'] > 0) {
                $db['keys'][$key]['expires'] = $currentTime + $keyData['duration'];
            }
        }

        $db['keys'][$key]['activations'][] = [
            'hwid' => $hwid,
            'ip' => $ip,
            'time' => $currentTime,
            'last_active' => $currentTime
        ];
        
        saveDb();
        addLog("Ключ $key активирован на устройстве HWID: $hwid (IP: $ip)");
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
            'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
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

if (isset($update['message']['successful_payment'])) {
    $chatId = $update['message']['chat']['id'];
    $payload = $update['message']['successful_payment']['invoice_payload'];
    
    $parts = explode("_", $payload);
    $duration = intval($parts[1]);
    $maxLimit = intval($parts[2]);

    $newKey = "LORI-" . strtoupper(substr(md5(rand() . time()), 0, 10));
    
    $db['keys'][$newKey] = [
        "duration" => $duration,
        "expires" => 0,
        "first_use" => 0,
        "max" => $maxLimit,
        "activations" => [],
        "owner_tg" => $chatId,
        "reset_left" => 2,
        "freeze_left" => 2,
        "freeze_reset_time" => time() + 604800,
        "is_frozen" => false
    ];
    saveDb();
    addLog("Куплен ключ $newKey пользователем $chatId");

    sendMessage($chatId, "🎉 **Оплата успешно прошла!**\n\nВаш персональный ключ доступа:\n`$newKey`");
    exit;
}

if (isset($update['message'])) {
    $chatId = intval($update['message']['chat']['id']);
    $text = trim($update['message']['text']);

    if ($chatId === intval($adminId)) {
        if (strpos($text, '/gen ') === 0) {
            $args = explode(" ", $text);
            $hours = intval($args[1] ?? 24);
            $maxLimit = intval($args[2] ?? 1);
            $duration = ($hours == 0) ? 0 : ($hours * 3600);
            $newKey = "LORI-ADM-" . strtoupper(substr(md5(rand() . time()), 0, 8));

            $db['keys'][$newKey] = [
                "duration" => $duration,
                "expires" => 0,
                "first_use" => 0,
                "max" => $maxLimit,
                "activations" => [],
                "owner_tg" => 0,
                "reset_left" => 999,
                "freeze_left" => 999,
                "is_frozen" => false
            ];
            saveDb();
            sendMessage($chatId, "✅ Ключ создан: `$newKey` (Лимит: $maxLimit)");
            exit;
        }

        if (strpos($text, '/broadcast ') === 0) {
            $msgText = substr($text, 11);
            $usersToNotify = [];
            foreach ($db['keys'] as $k => $kd) {
                if (!empty($kd['owner_tg'])) $usersToNotify[$kd['owner_tg']] = true;
            }
            $count = 0;
            foreach ($usersToNotify as $uId => $val) {
                sendMessage($uId, "📢 **Рассылка от администрации:**\n\n$msgText");
                $count++;
            }
            sendMessage($chatId, "✅ Рассылка отправлена $count пользователям.");
            exit;
        }
    }

    if ($text === "/start") {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⏳ 24 часа — 25 ⭐️', 'callback_data' => 'buy_86400_1']],
                [['text' => '📅 7 дней — 75 ⭐️', 'callback_data' => 'buy_604800_1']],
                [['text' => '🗓 30 дней — 125 ⭐️', 'callback_data' => 'buy_2592000_1']],
                [['text' => '♾️ Навсегда — 400 ⭐️', 'callback_data' => 'buy_0_1']],
                [['text' => '🔑 Мои ключи и управление', 'callback_data' => 'my_keys']]
            ]
        ];
        
        if ($chatId === intval($adminId)) {
            $keyboard['inline_keyboard'][] = [['text' => '👑 Админ-панель', 'callback_data' => 'admin_panel']];
        }

        sendMessage($chatId, "👋 Добро пожаловать в магазин **Lori Store**!", $keyboard);
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
        sendInvoice($chatId, "Ключ Lori", "Доступ к скрипту", "sub_{$parts[1]}_{$parts[2]}", 25);
    } 
    elseif ($data === 'my_keys') {
        $userKeys = [];
        foreach ($db['keys'] as $k => $kd) {
            if (isset($kd['owner_tg']) && intval($kd['owner_tg']) === $chatId) {
                $userKeys[$k] = $kd;
            }
        }

        if (empty($userKeys)) {
            sendMessage($chatId, "📭 У вас нет активных ключей.");
            return;
        }

        foreach ($userKeys as $k => $d) {
            $usedCount = count($d['activations'] ?? []);
            $maxLimit = $d['max'] ?? 1;
            
            if ($d['first_use'] == 0) {
                $status = "⏳ Не активирован";
            } else {
                $remains = $d['expires'] - time();
                if ($d['expires'] == 0) $status = "♾️ Бессрочный";
                elseif ($remains > 0) {
                    $days = floor($remains / 86400);
                    $hours = floor(($remains % 86400) / 3600);
                    $status = "🟢 Активен (осталось {$days}д {$hours}ч)";
                } else $status = "🔴 Истек";
            }

            $info = "🔑 Ключ: `$k`\n📌 Статус: $status\n👥 Устройства: {$usedCount}/{$maxLimit}\n";
            foreach($d['activations'] as $act) {
                $info .= "└ HWID: `{$act['hwid']}` | IP: `{$act['ip']}`\n";
            }

            $kb = [
                'inline_keyboard' => [
                    [['text' => '🔄 Сбросить HWID', 'callback_data' => 'user_reset_' . $k]],
                    [['text' => '« На главную', 'callback_data' => 'back_home']]
                ]
            ];
            sendMessage($chatId, $info, $kb);
        }
    }
    elseif (strpos($data, 'user_reset_') === 0) {
        $keyToReset = str_replace('user_reset_', '', $data);
        if (isset($db['keys'][$keyToReset]) && intval($db['keys'][$keyToReset]['owner_tg']) === $chatId) {
            if ($db['keys'][$keyToReset]['reset_left'] > 0) {
                $db['keys'][$keyToReset]['activations'] = [];
                $db['keys'][$keyToReset]['reset_left']--;
                saveDb();
                sendMessage($chatId, "✅ Привязки успешно сброшены!");
            } else {
                sendMessage($chatId, "❌ Лимит сбросов исчерпан.");
            }
        }
    }
    // Админ-панель
    elseif ($data === 'admin_panel' && $chatId === intval($adminId)) {
        $totalKeys = count($db['keys']);
        $onlineCount = count($db['online']);
        $statusSoft = $db['settings']['status'];

        $text = "👑 **Панель администратора**\n\n";
        $text .= "📊 Всего ключей: $totalKeys\n";
        $text .= "🟢 Онлайн игроков прямо сейчас: **$onlineCount**\n";
        $text .= "⚙️ Статус софта: `$statusSoft`\n";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🟢 Кто в сети (Онлайн)', 'callback_data' => 'adm_online']],
                [['text' => '📋 Список всех пользователей и ключей', 'callback_data' => 'adm_users_list']],
                [['text' => '🚨 Экстренное закрытие софта (Killswitch)', 'callback_data' => 'toggle_killswitch']],
                [['text' => '⚙️ Управление статусом (Online/Maint)', 'callback_data' => 'toggle_status']],
                [['text' => '📜 Логи', 'callback_data' => 'adm_logs']]
            ]
        ];
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $keyboard]);
    }
    // Просмотр кто в сети
    elseif ($data === 'adm_online' && $chatId === intval($adminId)) {
        $text = "🟢 **Игроки в сети прямо сейчас:**\n\n";
        if (empty($db['online'])) {
            $text .= "Никого нет в игре.";
        } else {
            foreach ($db['online'] as $hwid => $info) {
                $text .= "• Ключ: `{$info['key']}`\n  IP: `{$info['ip']}` | HWID: `$hwid`\n  Пиинг: " . (time() - $info['last_ping']) . " сек. назад\n\n";
            }
        }
        $kb = ['inline_keyboard' => [[['text' => '« В админку', 'callback_data' => 'admin_panel']]]];
        sendMessage($chatId, $text, $kb);
    }
    // Полный список пользователей, ключей, айпи и хвидов
    elseif ($data === 'adm_users_list' && $chatId === intval($adminId)) {
        $text = "📋 **База данных пользователей и ключей:**\n\n";
        foreach ($db['keys'] as $key => $kData) {
            $owner = $kData['owner_tg'] ? "TG ID: {$kData['owner_tg']}" : "Админский/Магазин";
            $text .= "🔑 **$key** ($owner)\n";
            $text .= "⏰ Истекает: " . ($kData['expires'] == 0 ? "Навсегда" : date("d.m.Y H:i", $kData['expires'])) . "\n";
            if (!empty($kData['activations'])) {
                foreach ($kData['activations'] as $act) {
                    $text .= "   └ IP: `{$act['ip']}` | HWID: `{$act['hwid']}`\n";
                }
            } else {
                $text .= "   └ Активаций еще нет\n";
            }
            $text .= "-----------------------------------\n";
        }
        $kb = ['inline_keyboard' => [[['text' => '« В админку', 'callback_data' => 'admin_panel']]]];
        sendMessage($chatId, $text, $kb);
    }
    // Переключатель Killswitch (Экстренное закрытие у всех)
    elseif ($data === 'toggle_killswitch' && $chatId === intval($adminId)) {
        if ($db['settings']['status'] === 'killswitch') {
            $db['settings']['status'] = 'online';
            $db['settings']['emergency_msg'] = '';
            saveDb();
            sendMessage($chatId, "🟢 Режим Killswitch снят. Софт снова работает!");
        } else {
            $db['settings']['status'] = 'killswitch';
            $db['settings']['emergency_msg'] = '🚨 ВНИМАНИЕ: Софт был экстренно остановлен администратором!';
            saveDb();
            sendMessage($chatId, "🚨 **Killswitch активирован!** Все активные копии скрипта закроются.");
        }
    }
    elseif ($data === 'toggle_status' && $chatId === intval($adminId)) {
        $current = $db['settings']['status'];
        if ($current === 'online') $db['settings']['status'] = 'maintenance';
        else $db['settings']['status'] = 'online';
        saveDb();
        sendMessage($chatId, "✅ Статус изменен на: `{$db['settings']['status']}`");
    }
    elseif ($data === 'adm_logs' && $chatId === intval($adminId)) {
        $logText = "📜 **Логи:**\n\n";
        foreach (array_slice($db['logs'], 0, 15) as $l) {
            $logText .= "`" . date("H:i:s", $l['time']) . "` — " . $l['text'] . "\n";
        }
        $kb = ['inline_keyboard' => [[['text' => '« В админку', 'callback_data' => 'admin_panel']]]];
        sendMessage($chatId, $logText, $kb);
    }
    elseif ($data === 'back_home') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⏳ 24 часа — 25 ⭐️', 'callback_data' => 'buy_86400_1']],
                [['text' => '🔑 Мои ключи и управление', 'callback_data' => 'my_keys']]
            ]
        ];
        if ($chatId === intval($adminId)) {
            $keyboard['inline_keyboard'][] = [['text' => '👑 Админ-панель', 'callback_data' => 'admin_panel']];
        }
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "Главное меню:", 'reply_markup' => $keyboard]);
    }
}
?>

