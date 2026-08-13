<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

// --- НАСТРОЙКИ ---
$botToken = "8883380357:AAHrYtiqhcCTBvllozb5m4pMUQIw922a0Oo";
$adminId  = 8875180956;

$dbFile = "database.json";
$db = file_exists($dbFile) ? json_decode(file_get_contents($dbFile), true) : [];
if (!is_array($db)) $db = [];

// Инициализация структуры базы данных, если она пустая или старая
if (!isset($db['keys'])) $db['keys'] = [];
if (!isset($db['blacklist'])) $db['blacklist'] = [];
if (!isset($db['logs'])) $db['logs'] = [];
if (!isset($db['settings'])) {
    $db['settings'] = [
        "status" => "online", // online, maintenance, update
        "version" => "1.0.0",
        "checksum" => "da39a3ee5e6b4b0d3255bfef95601890afd80709", // Пример SHA1
        "download_url" => "https://example.com/script.lua"
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
    if (count($db['logs']) > 100) array_pop($db['logs']); // Храним последние 100 логов
    saveDb();
}

$action = $_GET['action'] ?? '';

// --- ЛОГИРОВАНИЕ ЗАПРОСОВ К API ---
if (!empty($action)) {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    // Можно включить детальное логирование: addLog("API Request: action=$action from IP $ip");
}

// --- 0. ОБЛАЧНОЕ ОБНОВЛЕНИЕ И СТАТУС (OTA) ---
if ($action === "status_check") {
    echo json_encode([
        "status" => $db['settings']['status'],
        "version" => $db['settings']['version'],
        "checksum" => $db['settings']['checksum'],
        "url" => $db['settings']['download_url']
    ]);
    exit;
}

// --- 1. ПРОВЕРКА КЛЮЧА ИЗ LUA-СКРИПТА ---
if ($action === "check") {
    $key = $_POST['key'] ?? $_GET['key'] ?? '';
    $hwid = $_POST['hwid'] ?? $_GET['hwid'] ?? '';
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    // Проверка статуса софта
    if ($db['settings']['status'] === 'maintenance') {
        echo "Сервер находится на техническом обслуживании!";
        exit;
    }
    if ($db['settings']['status'] === 'update') {
        echo "Требуется обновление скрипта!";
        exit;
    }

    if (empty($key)) {
        echo json_encode(["status" => "error", "message" => "Укажите ключ!"]);
        exit;
    }

    // Проверка черного списка по IP или HWID
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

    // Проверка заморозки
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
            'time' => $currentTime
        ];
        
        saveDb();
        addLog("Ключ $key активирован на устройстве $hwid");
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

// Покупка через магазин (пользователи)
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
    addLog("Куплен ключ $newUserKey пользователем $chatId");

    sendMessage($chatId, "🎉 **Оплата успешно прошла!**\n\nВаш персональный ключ доступа:\n`$newKey`\n\n*Управлять ключом (сброс HWID, заморозка) можно в главном меню в разделе «Мои ключи»*");
    exit;
}

// Обработка текстовых сообщений (Админ-команды / Диалоги)
if (isset($update['message'])) {
    $chatId = intval($update['message']['chat']['id']);
    $text = trim($update['message']['text']);

    // Проверка на админские команды через чат
    if ($chatId === intval($adminId)) {
        if (strpos($text, '/gen ') === 0) {
            // /gen [часы] [лимит] [тип: 1, 7, 30 и т.д.]
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
            addLog("Админ сгенерировал ключ $newKey");
            sendMessage($chatId, "✅ Ключ создан: `$newKey` (Лимит: $maxLimit)");
            exit;
        }
        
        // Выдача ключа пользователю по Telegram ID: /give [tg_id] [часы] [лимит]
        if (strpos($text, '/give ') === 0) {
            $args = explode(" ", $text);
            $targetUser = intval($args[1] ?? 0);
            $hours = intval($args[2] ?? 24);
            $maxLimit = intval($args[3] ?? 1);
            
            if ($targetUser > 0) {
                $duration = ($hours == 0) ? 0 : ($hours * 3600);
                $newKey = "LORI-GIFT-" . strtoupper(substr(md5(rand() . time()), 0, 8));
                
                $db['keys'][$newKey] = [
                    "duration" => $duration,
                    "expires" => 0,
                    "first_use" => 0,
                    "max" => $maxLimit,
                    "activations" => [],
                    "owner_tg" => $targetUser,
                    "reset_left" => 2,
                    "freeze_left" => 2,
                    "freeze_reset_time" => time() + 604800,
                    "is_frozen" => false
                ];
                saveDb();
                sendMessage($chatId, "🎁 Ключ `$newKey` успешно выдан пользователю `$targetUser`!");
                sendMessage($targetUser, "🎁 **Администратор выдал вам персональный ключ!**\n\nКлюч: `$newKey`\nПроверьте раздел «Мои ключи».");
            } else {
                sendMessage($chatId, "❌ Неверный Telegram ID пользователя.");
            }
            exit;
        }

        // Рассылка: /broadcast текст
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
                [['text' => '🗓 90 дней — 200 ⭐️', 'callback_data' => 'buy_7776000_1']],
                [['text' => '♾️ Навсегда — 400 ⭐️', 'callback_data' => 'buy_0_1']],
                [['text' => '🔑 Мои ключи и управление', 'callback_data' => 'my_keys']]
            ]
        ];
        
        if ($chatId === intval($adminId)) {
            $keyboard['inline_keyboard'][] = [['text' => '👑 Админ-панель', 'callback_data' => 'admin_panel']];
        }

        sendMessage($chatId, "👋 Добро пожаловать в магазин **Lori Store**!\n\nВыберите тариф для покупки или управляйте своими ключами:", $keyboard);
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
    elseif ($data === 'my_keys') {
        $userKeys = [];
        foreach ($db['keys'] as $k => $kd) {
            if (isset($kd['owner_tg']) && intval($kd['owner_tg']) === $chatId) {
                $userKeys[$k] = $kd;
            }
        }

        if (empty($userKeys)) {
            sendMessage($chatId, "📭 У вас нет активных ключей. Вы можете купить их в главном меню.");
            return;
        }

        foreach ($userKeys as $k => $d) {
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

            if (!empty($d['is_frozen'])) $status = "❄️ Заморожен";

            $info = "🔑 Ключ: `$k`\n";
            $info .= "📌 Статус: $status\n";
            $info .= "👥 Устройства: {$usedCount}/{$maxLimit}\n";
            $info .= "🔄 Сбросов HWID осталось: {$d['reset_left']}\n";
            $info .= "❄️ Заморозок осталось: {$d['freeze_left']}\n";

            $kb = [
                'inline_keyboard' => [
                    [['text' => '🔄 Сбросить HWID', 'callback_data' => 'user_reset_' . $k]],
                    [['text' => (!empty($d['is_frozen']) ? '🔥 Разморозить' : '❄️ Заморозить'), 'callback_data' => 'user_freeze_' . $k]],
                    [['text' => '« На главную', 'callback_data' => 'back_home']]
                ]
            ];
            sendMessage($chatId, $info, $kb);
        }
    }
    // Сброс HWID пользователем
    elseif (strpos($data, 'user_reset_') === 0) {
        $keyToReset = str_replace('user_reset_', '', $data);
        if (isset($db['keys'][$keyToReset]) && intval($db['keys'][$keyToReset]['owner_tg']) === $chatId) {
            if ($db['keys'][$keyToReset]['reset_left'] > 0) {
                $db['keys'][$keyToReset]['activations'] = [];
                $db['keys'][$keyToReset]['reset_left']--;
                saveDb();
                sendMessage($chatId, "✅ Привязки успешно сброшены! Осталось сбросов: {$db['keys'][$keyToReset]['reset_left']}");
            } else {
                sendMessage($chatId, "❌ У вас закончились бесплатные сбросы HWID для этого ключа.");
            }
        }
    }
    // Заморозка ключа пользователем
    elseif (strpos($data, 'user_freeze_') === 0) {
        $keyToFreeze = str_replace('user_freeze_', '', $data);
        if (isset($db['keys'][$keyToFreeze]) && intval($db['keys'][$keyToFreeze]['owner_tg']) === $chatId) {
            $kd = &$db['keys'][$keyToFreeze];
            // Проверка сброса лимита раз в неделю
            if (time() > $kd['freeze_reset_time']) {
                $kd['freeze_left'] = 2;
                $kd['freeze_reset_time'] = time() + 604800;
            }

            if (empty($kd['is_frozen'])) {
                if ($kd['freeze_left'] > 0) {
                    $kd['is_frozen'] = true;
                    $kd['freeze_left']--;
                    saveDb();
                    sendMessage($chatId, "❄️ Ключ успешно заморожен! Время истечения приостановлено.");
                } else {
                    sendMessage($chatId, "❌ Лимит заморозок на этой неделе исчерпан.");
                }
            } else {
                $kd['is_frozen'] = false;
                saveDb();
                sendMessage($chatId, "🔥 Ключ разморожен и снова активен!");
            }
        }
    }
    // Админ-панель
    elseif ($data === 'admin_panel' && $chatId === intval($adminId)) {
        $totalKeys = count($db['keys']);
        $totalBlacklist = count($db['blacklist']);
        $statusSoft = $db['settings']['status'];

        $text = "👑 **Панель администратора**\n\n";
        $text .= "📊 Всего ключей: $totalKeys\n";
        $text .= "⛔ В черном списке: $totalBlacklist\n";
        $text .= "⚙️ Статус софта: `$statusSoft`\n\n";
        $text .= "Команды для чата:\n• `/gen [часы] [лимит]` — создать ключ\n• `/give [tg_id] [часы] [лимит]` — выдать ключ\n• `/broadcast [текст]` — рассылка";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📋 Управление ключами', 'callback_data' => 'adm_list']],
                [['text' => '⚙️ Статус и OTA настройки', 'callback_data' => 'adm_settings']],
                [['text' => '📜 Логи активности', 'callback_data' => 'adm_logs']],
                [['text' => '« На главную', 'callback_data' => 'back_home']]
            ]
        ];
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $keyboard]);
    } 
    // Настройки статуса и OTA для админа
    elseif ($data === 'adm_settings' && $chatId === intval($adminId)) {
        $st = $db['settings']['status'];
        $ver = $db['settings']['version'];
        $kb = [
            'inline_keyboard' => [
                [['text' => "Статус: " . ($st == 'online' ? '🟢 Online' : ($st == 'maintenance' ? '🛠 Обслуживание' : '🚀 Обновление')), 'callback_data' => 'toggle_status']],
                [['text' => '« В админку', 'callback_data' => 'admin_panel']]
            ]
        ];
        sendMessage($chatId, "⚙️ **Текущие настройки OTA:**\nВерсия: `$ver`\nСтатус: `$st`", $kb);
    }
    elseif ($data === 'toggle_status' && $chatId === intval($adminId)) {
        $current = $db['settings']['status'];
        if ($current === 'online') $db['settings']['status'] = 'maintenance';
        elseif ($current === 'maintenance') $db['settings']['status'] = 'update';
        else $db['settings']['status'] = 'online';
        saveDb();
        sendMessage($chatId, "✅ Статус изменен на: `{$db['settings']['status']}`");
    }
    // Логи активности для админа
    elseif ($data === 'adm_logs' && $chatId === intval($adminId)) {
        $logText = "📜 **Последние логи активности:**\n\n";
        $logs = array_slice($db['logs'], 0, 15);
        foreach ($logs as $l) {
            $logText .= "`" . date("H:i:s", $l['time']) . "` — " . $l['text'] . "\n";
        }
        $kb = [['inline_keyboard' => [[['text' => '« В админку', 'callback_data' => 'admin_panel']]]];
        sendMessage($chatId, $logText, $kb);
    }
    // Список ключей в админке с бесконечным сбросом HWID и баном
    elseif ($data === 'adm_list' && $chatId === intval($adminId)) {
        if (empty($db['keys'])) {
            sendMessage($chatId, "📭 База ключей пуста.");
            return;
        }

        foreach ($db['keys'] as $k => $d) {
            $usedCount = count($d['activations'] ?? []);
            $maxLimit = $d['max'] ?? 1;
            $info = "🔑 `$k` (Лимит: {$usedCount}/{$maxLimit})\nВладелец Telegram ID: `{$d['owner_tg']}`\n";

            $kb = [
                'inline_keyboard' => [
                    [['text' => '🔄 Сбросить HWID (Админ)', 'callback_data' => 'adm_reset_' . $k]],
                    [['text' => '⛔ В черный список (HWID/IP)', 'callback_data' => 'adm_ban_' . $k]],
                    [['text' => '❌ Удалить ключ', 'callback_data' => 'adm_del_' . $k]],
                    [['text' => '« В админку', 'callback_data' => 'admin_panel']]
                ]
            ];
            sendMessage($chatId, $info, $kb);
        }
    }
    // Сброс HWID администратором (неограниченно)
    elseif (strpos($data, 'adm_reset_') === 0 && $chatId === intval($adminId)) {
        $key = str_replace('adm_reset_', '', $data);
        if (isset($db['keys'][$key])) {
            $db['keys'][$key]['activations'] = [];
            saveDb();
            sendMessage($chatId, "✅ Администратор сбросил HWID для ключа `$key`.");
        }
    }
    // Добавление в черный список через ключ
    elseif (strpos($data, 'adm_ban_') === 0 && $chatId === intval($adminId)) {
        $key = str_replace('adm_ban_', '', $data);
        if (isset($db['keys'][$key])) {
            foreach ($db['keys'][$key]['activations'] as $act) {
                if (!empty($act['hwid'])) $db['blacklist'][$act['hwid']] = true;
                if (!empty($act['ip'])) $db['blacklist'][$act['ip']] = true;
            }
            unset($db['keys'][$key]);
            saveDb();
            sendMessage($chatId, "⛔ Ключ удален, а все привязанные HWID/IP добавлены в черный список!");
        }
    }
    elseif (strpos($data, 'adm_del_') === 0 && $chatId === intval($adminId)) {
        $keyToDel = str_replace('adm_del_', '', $data);
        if (isset($db['keys'][$keyToDel])) {
            unset($db['keys'][$keyToDel]);
            saveDb();
            sendMessage($chatId, "🗑 Ключ `$keyToDel` удален!");
        }
    } 
    elseif ($data === 'back_home') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⏳ 24 часа — 25 ⭐️', 'callback_data' => 'buy_86400_1']],
                [['text' => '📅 7 дней — 75 ⭐️', 'callback_data' => 'buy_604800_1']],
                [['text' => '🗓 30 дней — 125 ⭐️', 'callback_data' => 'buy_2592000_1']],
                [['text' => '🗓 90 дней — 200 ⭐️', 'callback_data' => 'buy_7776000_1']],
                [['text' => '♾️ Навсегда — 400 ⭐️', 'callback_data' => 'buy_0_1']],
                [['text' => '🔑 Мои ключи и управление', 'callback_data' => 'my_keys']]
            ]
        ];
        if ($chatId === intval($adminId)) {
            $keyboard['inline_keyboard'][] = [['text' => '👑 Админ-панель', 'callback_data' => 'admin_panel']];
        }
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "👋 Добро пожаловать в магазин **Lori Store**!\n\nВыберите тариф для покупки или управляйте своими ключами:", 'parse_mode' => 'Markdown', 'reply_markup' => $keyboard]);
    }
}
?>

