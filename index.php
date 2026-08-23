<?php
// ====================== БЕЗОПАСНОСТЬ ======================
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
);
if (!$isHttps && php_sapi_name() !== 'cli') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
error_reporting(0);
date_default_timezone_set('Europe/Moscow');

// ====================== НАСТРОЙКИ ======================
$botToken = getenv('BOT_TOKEN') ?: '8883380357:AAHrYtiqhcCTBvllozb5m4pMUQIw922a0Oo';
$adminId  = (int)(getenv('ADMIN_ID') ?: 8875180956);
$adminPass = getenv('ADMIN_PASS') ?: 'admin123';

if (empty($botToken) || empty($adminId)) {
    http_response_code(500);
    die('Server misconfigured. Set BOT_TOKEN and ADMIN_ID');
}

$dbFile = __DIR__ . '/database.json';
$db = file_exists($dbFile) ? json_decode(file_get_contents($dbFile), true) : [];
if (!is_array($db)) $db = [];
foreach (['keys', 'blacklist', 'logs', 'online'] as $k) {
    if (!isset($db[$k])) $db[$k] = [];
}
if (!isset($db['settings'])) {
    $db['settings'] = [
        'status' => 'online',
        'soft_status' => 'undetected',
        'global_freeze' => false,
        'version' => '1.5.0',
        'checksum' => '5c8714cf5eb4010c2cad5b14ac476f3f3f695d26',
        'download_url' => 'https://example.com/script.lua',
        'emergency_msg' => '',
        'broadcast' => '',
        'prices' => [
            'day'   => ['stars' => 25,  'rub' => 49],
            'week'  => ['stars' => 75,  'rub' => 149],
            'month' => ['stars' => 125, 'rub' => 299],
            'life'  => ['stars' => 400, 'rub' => 799]
        ]
    ];
}

function getPrefixByLevel($level) {
    $map = ['trial'=>'TRIAL','free'=>'FREE','media'=>'MEDIA','premium'=>'PREMIUM'];
    return $map[$level] ?? 'LORI';
}

function saveDb() {
    global $db, $dbFile;
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function addLog($text) {
    global $db;
    array_unshift($db['logs'], ['time' => time(), 'text' => $text]);
    if (count($db['logs']) > 300) array_pop($db['logs']);
    saveDb();
}

foreach ($db['online'] as $hwid => $data) {
    if (time() - ($data['last_ping'] ?? 0) > 120) unset($db['online'][$hwid]);
}
saveDb();

$action = $_GET['action'] ?? '';
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

// ====================== API ======================
if ($action === 'status_check') {
    header('Content-Type: application/json; charset=utf-8');
    $clientChecksum = $_POST['checksum'] ?? $_GET['checksum'] ?? '';
    $hwid = $_POST['hwid'] ?? $_GET['hwid'] ?? '';
    $key  = $_POST['key']  ?? $_GET['key']  ?? '';
    if (!empty($clientChecksum) && strtolower($clientChecksum) !== strtolower($db['settings']['checksum'])) {
        echo json_encode(['status'=>'error','message'=>'Обнаружена модификация скрипта!']); exit;
    }
    if ($db['settings']['status'] === 'killswitch') {
        echo json_encode(['status'=>'killswitch','message'=>$db['settings']['emergency_msg']?:'Софт экстренно остановлен!']); exit;
    }
    if (!empty($db['settings']['global_freeze'])) {
        echo json_encode(['status'=>'frozen','message'=>'Все ключи временно заморожены']); exit;
    }
    if (($db['settings']['soft_status'] ?? '') === 'detected') {
        echo json_encode(['status'=>'detected','message'=>'Софт временно недоступен (Detected)']); exit;
    }
    if (!empty($hwid)) {
        $db['online'][$hwid] = [
            'ip' => $ip, 'key' => $key ?: '—',
            'last_ping' => time(),
            'first_seen' => $db['online'][$hwid]['first_seen'] ?? time()
        ];
        saveDb();
    }
    echo json_encode([
        'status' => $db['settings']['status'],
        'soft_status' => $db['settings']['soft_status'] ?? 'undetected',
        'version' => $db['settings']['version'],
        'checksum' => $db['settings']['checksum'],
        'url' => $db['settings']['download_url'],
        'emergency_msg' => $db['settings']['emergency_msg'] ?? '',
        'broadcast' => $db['settings']['broadcast'] ?? '',
        'global_freeze' => !empty($db['settings']['global_freeze'])
    ]);
    exit;
}

if ($action === 'check') {
    header('Content-Type: text/plain; charset=utf-8');
    $key  = trim($_POST['key']  ?? $_GET['key']  ?? '');
    $hwid = trim($_POST['hwid'] ?? $_GET['hwid'] ?? '');
    if ($db['settings']['status'] === 'maintenance') { echo 'Сервер на техническом обслуживании!'; exit; }
    if ($db['settings']['status'] === 'killswitch') { echo $db['settings']['emergency_msg'] ?: 'Софт остановлен!'; exit; }
    if (!empty($db['settings']['global_freeze'])) { echo 'Все ключи временно заморожены!'; exit; }
    if (($db['settings']['soft_status'] ?? '') === 'detected') { echo 'Софт временно недоступен (Detected)'; exit; }
    if (empty($key))  { echo 'Укажите ключ!'; exit; }
    if (empty($hwid)) { echo 'HWID не передан!'; exit; }
    if (isset($db['blacklist'][$ip]) || isset($db['blacklist'][$hwid])) {
        echo 'Заблокировано!'; exit;
    }
    if (!isset($db['keys'][$key])) {
        echo 'Неверный ключ!'; exit;
    }
    $kd = $db['keys'][$key];
    $now = time();
    if (!empty($kd['is_frozen'])) { echo 'Ключ заморожен!'; exit; }
    if (($kd['expires'] ?? 0) !== 0 && $now > $kd['expires']) {
        unset($db['keys'][$key]);
        saveDb();
        echo 'Срок действия истёк!'; exit;
    }
    $acts = $kd['activations'] ?? [];
    $max  = (int)($kd['max'] ?? 1);
    foreach ($acts as &$a) {
        if (($a['hwid'] ?? '') === $hwid) {
            $a['ip'] = $ip;
            $a['last_active'] = $now;
            $a['launches'] = ($a['launches'] ?? 0) + 1;
            saveDb();
            echo 'SUCCESS'; exit;
        }
    }
    unset($a);
    if (count($acts) < $max) {
        if (($kd['first_use'] ?? 0) == 0) {
            $db['keys'][$key]['first_use'] = $now;
            if (($kd['duration'] ?? 0) > 0) {
                $db['keys'][$key]['expires'] = $now + $kd['duration'];
            }
        }
        $db['keys'][$key]['activations'][] = [
            'hwid' => $hwid, 'ip' => $ip, 'time' => $now,
            'last_active' => $now, 'launches' => 1
        ];
        saveDb();
        addLog("Ключ $key активирован | HWID: " . substr($hwid,0,12) . "... | IP: $ip");
        echo 'SUCCESS';
    } else {
        echo 'Превышен лимит устройств!';
    }
    exit;
}

// ====================== ВЕБ-АДМИНКА (оставляем) ======================
session_start();
if (isset($_GET['admin'])) {
    // ... (весь твой старый веб-код без изменений, чтобы не ломать сайт)
    // Для краткости я оставляю его как есть — просто скопируй старый блок if (isset($_GET['admin'])) { ... } сюда без изменений.
    // Если нужно — скажи, вынесу полностью.
    // Чтобы код не раздувался здесь, веб-часть остаётся прежней.
}

// ====================== TELEGRAM BOT ======================
$content = file_get_contents('php://input');
$update  = json_decode($content, true);

if (!$update) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Lori</title>
    <style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0c0709;color:#ff69b4;font-family:system-ui}
    h1{font-size:1.8rem}</style></head><body><h1>Lori Bot & API Active</h1></body></html>';
    exit;
}

function tgRequest($method, $data) {
    global $botToken;
    $opts = ['http' => [
        'header'  => "Content-Type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'ignore_errors' => true
    ]];
    return @file_get_contents("https://api.telegram.org/bot$botToken/$method", false, stream_context_create($opts));
}

function sendMessage($chat_id, $text, $kb = null, $parse = 'Markdown') {
    $d = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => $parse, 'disable_web_page_preview' => true];
    if ($kb) $d['reply_markup'] = $kb;
    return tgRequest('sendMessage', $d);
}

function editMessage($chat_id, $msg_id, $text, $kb = null, $parse = 'Markdown') {
    $d = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => $parse, 'disable_web_page_preview' => true];
    if ($kb) $d['reply_markup'] = $kb;
    return tgRequest('editMessageText', $d);
}

function answerCallback($cq_id, $text = '', $alert = false) {
    tgRequest('answerCallbackQuery', [
        'callback_query_id' => $cq_id,
        'text' => $text,
        'show_alert' => $alert
    ]);
}

function sendInvoice($chat_id, $title, $desc, $payload, $stars) {
    tgRequest('sendInvoice', [
        'chat_id' => $chat_id, 'title' => $title, 'description' => $desc,
        'payload' => $payload, 'currency' => 'XTR',
        'prices' => [['label' => 'Stars', 'amount' => $stars]]
    ]);
}

// ========== KEY CARD (как на скрине) ==========
function buildKeyCard($key) {
    global $db;
    if (!isset($db['keys'][$key])) return null;
    $kd = $db['keys'][$key];

    $ownerName = $kd['owner_name'] ?: ($kd['owner_tg'] ? 'ID '.$kd['owner_tg'] : '—');
    $isNamed   = !empty($kd['owner_name']) || ($kd['owner_tg'] ?? 0) > 0;
    $namedTag  = $isNamed ? 'именной' : 'обычный';

    $now = time();
    $daysLeft = '∞';
    $expiresStr = 'навсегда';
    if (($kd['expires'] ?? 0) > 0) {
        $left = $kd['expires'] - $now;
        if ($left <= 0) {
            $daysLeft = '0';
            $expiresStr = 'истёк';
        } else {
            $daysLeft = (string)ceil($left / 86400);
            $expiresStr = date('d-m-Y H:i', $kd['expires']);
        }
    }

    $status = 'свободен';
    if (!empty($kd['is_frozen'])) $status = 'заморожен';
    elseif (($kd['first_use'] ?? 0) == 0) $status = 'не активирован';
    elseif (($kd['expires'] ?? 0) > 0 && $now > $kd['expires']) $status = 'истёк';
    else $status = 'активен';

    $used = count($kd['activations'] ?? []);
    $max  = $kd['max'] ?? 1;
    $warns = $kd['warns'] ?? 0;
    $android = $kd['android_id'] ?? 'не привязан';
    $tgId = $kd['owner_tg'] ?: '—';

    // Главный HWID
    $mainHwid = '—';
    if (!empty($kd['activations'])) {
        $mainHwid = substr($kd['activations'][0]['hwid'] ?? '—', 0, 16) . '…';
    }

    $text = "🟢 *{$key}*  `{$namedTag}`\n\n";
    $text .= "┌ *{$daysLeft}* дней\n";
    $text .= "│ `{$key}`\n";
    $text .= "│ 📡 `{$tgId}`  ⚡ `{$status}`\n";
    $text .= "│ 🔑 вход {$used}\n";
    $text .= "└\n\n";
    $text .= "Действует до     `{$expiresStr}`\n";
    $text .= "Владелец          *{$ownerName}* · `{$namedTag}`\n";
    $text .= "Telegram ID       `{$tgId}`\n";
    $text .= "Android ID        `{$android}`\n";
    $text .= "HWID              `{$mainHwid}`\n";
    $text .= "Входов            `{$used}/{$max}`\n";
    $text .= "Предупреждения    `{$warns} / 3`\n";

    $kb = [
        'inline_keyboard' => [
            [
                ['text' => '📋 Копировать', 'callback_data' => 'k_copy_'.$key],
                ['text' => '👤 Ник',       'callback_data' => 'k_nick_'.$key],
                ['text' => "⚠ Варн {$warns}/3", 'callback_data' => 'k_warn_'.$key]
            ],
            [
                ['text' => '🔄 Сброс варнов', 'callback_data' => 'k_rwarn_'.$key],
                ['text' => '🖥 Сброс HWID',  'callback_data' => 'k_rhwid_'.$key],
                ['text' => '🔁 Перегенер.',  'callback_data' => 'k_regen_'.$key]
            ],
            [
                ['text' => !empty($kd['is_frozen']) ? '🔓 Разблок' : '🔒 Блок', 'callback_data' => 'k_freeze_'.$key],
                ['text' => '📜 Логи', 'callback_data' => 'k_logs_'.$key]
            ],
            [
                ['text' => '🗑 Удалить', 'callback_data' => 'k_del_'.$key],
                ['text' => '« Назад', 'callback_data' => 'adm_keys']
            ]
        ]
    ];

    return [$text, $kb];
}

// ========== ADMIN MENU ==========
function adminMainKb() {
    return ['inline_keyboard' => [
        [['text' => '🔑 Ключи', 'callback_data' => 'adm_keys'], ['text' => '➕ Создать', 'callback_data' => 'adm_gen']],
        [['text' => '📦 Массово', 'callback_data' => 'adm_bulk'], ['text' => '🎁 Выдать', 'callback_data' => 'adm_give']],
        [['text' => '🟢 Онлайн', 'callback_data' => 'adm_online'], ['text' => '📊 Статистика', 'callback_data' => 'adm_stats']],
        [['text' => '🚫 ЧС', 'callback_data' => 'adm_bl'], ['text' => '📢 Broadcast', 'callback_data' => 'adm_bc']],
        [['text' => '⚙️ Настройки', 'callback_data' => 'adm_settings'], ['text' => '📜 Логи', 'callback_data' => 'adm_logs']],
        [['text' => '🚨 Killswitch', 'callback_data' => 'toggle_kill'], ['text' => '❄️ Freeze', 'callback_data' => 'toggle_gfreeze']]
    ]];
}

// ========== ОБРАБОТКА ==========
if (isset($update['pre_checkout_query'])) {
    tgRequest('answerPreCheckoutQuery', ['pre_checkout_query_id' => $update['pre_checkout_query']['id'], 'ok' => true]);
    exit;
}

if (isset($update['message']['successful_payment'])) {
    $chatId = $update['message']['chat']['id'];
    $payload = $update['message']['successful_payment']['invoice_payload'];
    $parts = explode('_', $payload);
    $duration = (int)($parts[1] ?? 0);
    $maxLimit = (int)($parts[2] ?? 1);
    $newKey = 'PREMIUM-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    $db['keys'][$newKey] = [
        'duration' => $duration, 'expires' => 0, 'first_use' => 0, 'max' => $maxLimit,
        'activations' => [], 'owner_tg' => $chatId, 'owner_name' => '', 'reset_left' => 2,
        'is_frozen' => false, 'level' => 'premium', 'created' => time(), 'warns' => 0
    ];
    saveDb();
    addLog("Куплен $newKey (Stars) юзером $chatId");
    sendMessage($chatId, "🎉 *Оплата прошла!*\n\nВаш ключ:\n`$newKey`");
    exit;
}

if (isset($update['message'])) {
    $chatId = (int)$update['message']['chat']['id'];
    $text   = trim($update['message']['text'] ?? '');
    $isAdmin = ($chatId === $adminId);

    // ===== АДМИН КОМАНДЫ =====
    if ($isAdmin) {
        if ($text === '/admin' || $text === '/panel') {
            $cnt = count($db['keys']);
            $onl = count($db['online']);
            sendMessage($chatId, "👑 *Админ-панель*\n\nКлючей: *{$cnt}*\nОнлайн: *{$onl}*\nСтатус: `{$db['settings']['status']}`\nSoft: `".($db['settings']['soft_status']??'undetected')."`", adminMainKb());
            exit;
        }

        // /gen 24 1 premium
        if (strpos($text, '/gen ') === 0) {
            $args = explode(' ', $text);
            $hours = (int)($args[1] ?? 24);
            $max   = (int)($args[2] ?? 1);
            $level = $args[3] ?? 'trial';
            if (!in_array($level, ['trial','free','media','premium'])) $level = 'trial';
            $duration = $hours === 0 ? 0 : $hours * 3600;
            $prefix = getPrefixByLevel($level);
            $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $db['keys'][$newKey] = [
                'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>999,
                'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0
            ];
            saveDb();
            addLog("Создан $newKey ($level)");
            sendMessage($chatId, "✅ Ключ: `$newKey`\nТип: *$level* · {$hours}ч · max {$max}");
            exit;
        }

        // /give 123456 24 1 premium
        if (strpos($text, '/give ') === 0) {
            $args = explode(' ', $text);
            $target = (int)($args[1] ?? 0);
            $hours  = (int)($args[2] ?? 24);
            $max    = (int)($args[3] ?? 1);
            $level  = $args[4] ?? 'premium';
            if (!in_array($level, ['trial','free','media','premium'])) $level = 'premium';
            if ($target > 0) {
                $duration = $hours === 0 ? 0 : $hours * 3600;
                $prefix = getPrefixByLevel($level);
                $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                $db['keys'][$newKey] = [
                    'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                    'activations'=>[],'owner_tg'=>$target,'owner_name'=>'','reset_left'=>2,
                    'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0
                ];
                saveDb();
                addLog("Выдан $newKey → $target");
                sendMessage($chatId, "🎁 `$newKey` → `$target`");
                sendMessage($target, "🎁 Вам выдан ключ:\n`$newKey`\nТип: *$level*");
            }
            exit;
        }

        // /find KEY или /key KEY
        if (preg_match('/^\/(find|key|view)\s+(\S+)/i', $text, $m)) {
            $key = $m[2];
            $card = buildKeyCard($key);
            if ($card) {
                sendMessage($chatId, $card[0], $card[1]);
            } else {
                sendMessage($chatId, "❌ Ключ не найден");
            }
            exit;
        }
    }

    // ===== ОБЫЧНЫЙ ЮЗЕР =====
    if ($text === '/start') {
        $kb = ['inline_keyboard' => [
            [['text' => '⏳ 24 часа — 25 ⭐', 'callback_data' => 'buy_86400_1']],
            [['text' => '📅 7 дней — 75 ⭐',  'callback_data' => 'buy_604800_1']],
            [['text' => '🗓 30 дней — 125 ⭐','callback_data' => 'buy_2592000_1']],
            [['text' => '♾️ Навсегда — 400 ⭐','callback_data' => 'buy_0_1']],
            [['text' => '🔑 Мои ключи', 'callback_data' => 'my_keys']]
        ]];
        if ($isAdmin) $kb['inline_keyboard'][] = [['text' => '👑 Админ-панель', 'callback_data' => 'admin_panel']];
        sendMessage($chatId, "👋 Добро пожаловать в **Lori Store**!", $kb);
    }
}

// ========== CALLBACKS ==========
if (isset($update['callback_query'])) {
    $cq     = $update['callback_query'];
    $chatId = (int)$cq['message']['chat']['id'];
    $data   = $cq['data'];
    $msgId  = $cq['message']['message_id'];
    $cqId   = $cq['id'];
    $isAdmin = ($chatId === $adminId);

    // ---- ПОКУПКИ ----
    if (strpos($data, 'buy_') === 0) {
        $p = explode('_', $data);
        $starsMap = ['86400'=>25,'604800'=>75,'2592000'=>125,'0'=>400];
        $stars = $starsMap[$p[1]] ?? 25;
        sendInvoice($chatId, 'Ключ Lori Premium', 'Доступ Premium', "sub_{$p[1]}_{$p[2]}", $stars);
        answerCallback($cqId);
        exit;
    }

    // ---- МОИ КЛЮЧИ ----
    if ($data === 'my_keys') {
        $found = false;
        foreach ($db['keys'] as $k => $kd) {
            if (($kd['owner_tg'] ?? 0) == $chatId) {
                $found = true;
                $used = count($kd['activations'] ?? []);
                $max  = $kd['max'] ?? 1;
                if (!empty($kd['is_frozen'])) $st = '❄️ Заморожен';
                elseif (($kd['first_use'] ?? 0) == 0) $st = '⏳ Не активирован';
                elseif (($kd['expires'] ?? 0) == 0) $st = '♾️ Навсегда';
                elseif (time() > $kd['expires']) $st = '🔴 Истёк';
                else {
                    $left = $kd['expires'] - time();
                    $st = '🟢 ' . floor($left/86400) . 'д ' . floor(($left%86400)/3600) . 'ч';
                }
                $kb = ['inline_keyboard' => [
                    [['text' => '🔄 Сбросить HWID', 'callback_data' => 'user_reset_'.$k]],
                    [['text' => '« Назад', 'callback_data' => 'back_home']]
                ]];
                sendMessage($chatId, "🔑 `$k`\nТип: ".($kd['level']??'premium')."\n$st\nУстройства: $used/$max", $kb);
            }
        }
        if (!$found) sendMessage($chatId, '📭 У вас пока нет ключей.');
        answerCallback($cqId);
        exit;
    }

    if (strpos($data, 'user_reset_') === 0) {
        $key = str_replace('user_reset_', '', $data);
        if (isset($db['keys'][$key]) && ($db['keys'][$key]['owner_tg'] ?? 0) == $chatId) {
            if (($db['keys'][$key]['reset_left'] ?? 0) > 0) {
                $db['keys'][$key]['activations'] = [];
                $db['keys'][$key]['reset_left']--;
                saveDb();
                answerCallback($cqId, '✅ HWID сброшены');
            } else {
                answerCallback($cqId, '❌ Лимит сбросов закончился', true);
            }
        }
        exit;
    }

    if ($data === 'back_home') {
        $kb = ['inline_keyboard' => [
            [['text' => '⏳ 24 часа — 25 ⭐', 'callback_data' => 'buy_86400_1']],
            [['text' => '🔑 Мои ключи', 'callback_data' => 'my_keys']]
        ]];
        if ($isAdmin) $kb['inline_keyboard'][] = [['text' => '👑 Админ', 'callback_data' => 'admin_panel']];
        editMessage($chatId, $msgId, 'Главное меню', $kb);
        answerCallback($cqId);
        exit;
    }

    // ===================== АДМИН =====================
    if (!$isAdmin) {
        answerCallback($cqId, 'Нет доступа', true);
        exit;
    }

    if ($data === 'admin_panel') {
        $cnt = count($db['keys']);
        $onl = count($db['online']);
        $text = "👑 *Админ-панель*\n\nКлючей: *{$cnt}*\nОнлайн: *{$onl}*\nСтатус: `{$db['settings']['status']}`\nSoft: `".($db['settings']['soft_status']??'undetected')."`";
        editMessage($chatId, $msgId, $text, adminMainKb());
        answerCallback($cqId);
        exit;
    }

    // ---- СПИСОК КЛЮЧЕЙ ----
    if ($data === 'adm_keys' || strpos($data, 'adm_keys_') === 0) {
        $page = 0;
        if (strpos($data, 'adm_keys_') === 0) $page = (int)str_replace('adm_keys_', '', $data);
        $keys = array_keys($db['keys']);
        $perPage = 8;
        $total = count($keys);
        $pages = max(1, ceil($total / $perPage));
        $slice = array_slice($keys, $page * $perPage, $perPage);

        $text = "🔑 *Ключи* ({$total})\n\n";
        $kb = ['inline_keyboard' => []];
        foreach ($slice as $k) {
            $kd = $db['keys'][$k];
            $st = !empty($kd['is_frozen']) ? '❄️' : ((($kd['expires']??0)>0 && time()>$kd['expires']) ? '🔴' : '🟢');
            $kb['inline_keyboard'][] = [['text' => "$st $k", 'callback_data' => 'k_view_'.$k]];
        }
        $nav = [];
        if ($page > 0) $nav[] = ['text' => '◀️', 'callback_data' => 'adm_keys_'.($page-1)];
        $nav[] = ['text' => ($page+1)."/$pages", 'callback_data' => 'noop'];
        if ($page < $pages-1) $nav[] = ['text' => '▶️', 'callback_data' => 'adm_keys_'.($page+1)];
        if ($nav) $kb['inline_keyboard'][] = $nav;
        $kb['inline_keyboard'][] = [['text' => '« Назад', 'callback_data' => 'admin_panel']];
        editMessage($chatId, $msgId, $text, $kb);
        answerCallback($cqId);
        exit;
    }

    // ---- ПРОСМОТР КЛЮЧА (карточка как на скрине) ----
    if (strpos($data, 'k_view_') === 0) {
        $key = str_replace('k_view_', '', $data);
        $card = buildKeyCard($key);
        if ($card) {
            editMessage($chatId, $msgId, $card[0], $card[1]);
        } else {
            answerCallback($cqId, 'Ключ не найден', true);
        }
        answerCallback($cqId);
        exit;
    }

    // ---- ДЕЙСТВИЯ НАД КЛЮЧОМ ----
    if (strpos($data, 'k_copy_') === 0) {
        $key = str_replace('k_copy_', '', $data);
        answerCallback($cqId, $key, true); // просто показывает ключ
        exit;
    }

    if (strpos($data, 'k_nick_') === 0) {
        $key = str_replace('k_nick_', '', $data);
        answerCallback($cqId, 'Отправь новый ник: /nick '.$key.' Имя', true);
        exit;
    }

    if (strpos($data, 'k_warn_') === 0) {
        $key = str_replace('k_warn_', '', $data);
        if (isset($db['keys'][$key])) {
            $db['keys'][$key]['warns'] = min(3, ($db['keys'][$key]['warns'] ?? 0) + 1);
            saveDb();
            $card = buildKeyCard($key);
            editMessage($chatId, $msgId, $card[0], $card[1]);
            answerCallback($cqId, '⚠ Варн выдан');
        }
        exit;
    }

    if (strpos($data, 'k_rwarn_') === 0) {
        $key = str_replace('k_rwarn_', '', $data);
        if (isset($db['keys'][$key])) {
            $db['keys'][$key]['warns'] = 0;
            saveDb();
            $card = buildKeyCard($key);
            editMessage($chatId, $msgId, $card[0], $card[1]);
            answerCallback($cqId, '✅ Варны сброшены');
        }
        exit;
    }

    if (strpos($data, 'k_rhwid_') === 0) {
        $key = str_replace('k_rhwid_', '', $data);
        if (isset($db['keys'][$key])) {
            $db['keys'][$key]['activations'] = [];
            saveDb();
            addLog("Сброс HWID у $key");
            $card = buildKeyCard($key);
            editMessage($chatId, $msgId, $card[0], $card[1]);
            answerCallback($cqId, '🔄 HWID сброшены');
        }
        exit;
    }

    if (strpos($data, 'k_regen_') === 0) {
        $key = str_replace('k_regen_', '', $data);
        if (isset($db['keys'][$key])) {
            $old = $db['keys'][$key];
            $level = $old['level'] ?? 'premium';
            $prefix = getPrefixByLevel($level);
            $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $db['keys'][$newKey] = $old;
            $db['keys'][$newKey]['activations'] = [];
            unset($db['keys'][$key]);
            saveDb();
            addLog("Перегенерация $key → $newKey");
            $card = buildKeyCard($newKey);
            editMessage($chatId, $msgId, $card[0], $card[1]);
            answerCallback($cqId, "🔁 Новый ключ: $newKey", true);
        }
        exit;
    }

    if (strpos($data, 'k_freeze_') === 0) {
        $key = str_replace('k_freeze_', '', $data);
        if (isset($db['keys'][$key])) {
            $db['keys'][$key]['is_frozen'] = empty($db['keys'][$key]['is_frozen']);
            saveDb();
            $card = buildKeyCard($key);
            editMessage($chatId, $msgId, $card[0], $card[1]);
            answerCallback($cqId, !empty($db['keys'][$key]['is_frozen']) ? '🔒 Заблокирован' : '🔓 Разблокирован');
        }
        exit;
    }

    if (strpos($data, 'k_logs_') === 0) {
        $key = str_replace('k_logs_', '', $data);
        $text = "📜 *Логи ключа* `$key`\n\n";
        $found = 0;
        foreach (array_slice($db['logs'], 0, 40) as $l) {
            if (strpos($l['text'], $key) !== false) {
                $text .= '`' . date('d.m H:i', $l['time']) . '` ' . $l['text'] . "\n";
                $found++;
            }
        }
        if (!$found) $text .= 'Нет записей';
        $kb = ['inline_keyboard' => [[['text' => '« Назад', 'callback_data' => 'k_view_'.$key]]]];
        editMessage($chatId, $msgId, $text, $kb);
        answerCallback($cqId);
        exit;
    }

    if (strpos($data, 'k_del_') === 0) {
        $key = str_replace('k_del_', '', $data);
        unset($db['keys'][$key]);
        saveDb();
        addLog("Удалён $key");
        editMessage($chatId, $msgId, "🗑 Ключ `$key` удалён", ['inline_keyboard'=>[[['text'=>'« К списку','callback_data'=>'adm_keys']]]]);
        answerCallback($cqId, 'Удалён');
        exit;
    }

    // ---- СОЗДАТЬ ----
    if ($data === 'adm_gen') {
        $kb = ['inline_keyboard' => [
            [['text'=>'1ч TRIAL','callback_data'=>'do_gen_1_1_trial'],['text'=>'24ч TRIAL','callback_data'=>'do_gen_24_1_trial']],
            [['text'=>'7д PREMIUM','callback_data'=>'do_gen_168_1_premium'],['text'=>'30д PREMIUM','callback_data'=>'do_gen_720_1_premium']],
            [['text'=>'∞ PREMIUM','callback_data'=>'do_gen_0_1_premium']],
            [['text'=>'« Назад','callback_data'=>'admin_panel']]
        ]];
        editMessage($chatId, $msgId, "➕ *Создать ключ*\nВыбери шаблон или используй `/gen часы max уровень`", $kb);
        answerCallback($cqId);
        exit;
    }

    if (strpos($data, 'do_gen_') === 0) {
        $p = explode('_', $data);
        $hours = (int)$p[2]; $max = (int)$p[3]; $level = $p[4] ?? 'trial';
        $duration = $hours === 0 ? 0 : $hours * 3600;
        $prefix = getPrefixByLevel($level);
        $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        $db['keys'][$newKey] = [
            'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
            'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>999,
            'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0
        ];
        saveDb();
        addLog("Создан $newKey ($level)");
        answerCallback($cqId, "✅ $newKey", true);
        $card = buildKeyCard($newKey);
        editMessage($chatId, $msgId, $card[0], $card[1]);
        exit;
    }

    // ---- МАССОВО ----
    if ($data === 'adm_bulk') {
        $kb = ['inline_keyboard' => [
            [['text'=>'10 × 24ч TRIAL','callback_data'=>'do_bulk_10_24_trial']],
            [['text'=>'20 × 7д PREMIUM','callback_data'=>'do_bulk_20_168_premium']],
            [['text'=>'50 × ∞ PREMIUM','callback_data'=>'do_bulk_50_0_premium']],
            [['text'=>'« Назад','callback_data'=>'admin_panel']]
        ]];
        editMessage($chatId, $msgId, "📦 *Массовая генерация*", $kb);
        answerCallback($cqId);
        exit;
    }

    if (strpos($data, 'do_bulk_') === 0) {
        $p = explode('_', $data);
        $count = (int)$p[2]; $hours = (int)$p[3]; $level = $p[4] ?? 'trial';
        $duration = $hours === 0 ? 0 : $hours * 3600;
        $prefix = getPrefixByLevel($level);
        $list = [];
        for ($i=0; $i<$count; $i++) {
            $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand().$i, true)), 0, 8));
            $db['keys'][$newKey] = [
                'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>1,
                'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>2,
                'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0
            ];
            $list[] = $newKey;
        }
        saveDb();
        addLog("Bulk: $count ключей ($level)");
        $text = "✅ Создано *$count*:\n`" . implode("`\n`", $list) . "`";
        editMessage($chatId, $msgId, $text, ['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]);
        answerCallback($cqId);
        exit;
    }

    // ---- ВЫДАТЬ ----
    if ($data === 'adm_give') {
        editMessage($chatId, $msgId, "🎁 *Выдать ключ*\n\nИспользуй команду:\n`/give TG_ID часы max уровень`\n\nПример:\n`/give 123456789 168 1 premium`", ['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]);
        answerCallback($cqId);
        exit;
    }

    // ---- ОНЛАЙН ----
    if ($data === 'adm_online') {
        $text = "🟢 *Онлайн* (" . count($db['online']) . ")\n\n";
        if (empty($db['online'])) $text .= 'Никого нет';
        else {
            foreach ($db['online'] as $h => $i) {
                $text .= "• `{$i['key']}` | {$i['ip']} | " . (time()-($i['last_ping']??0)) . "с\n";
            }
        }
        editMessage($chatId, $msgId, $text, ['inline_keyboard'=>[[['text'=>'🔄 Обновить','callback_data'=>'adm_online'],['text'=>'« Назад','callback_data'=>'admin_panel']]]]);
        answerCallback($cqId);
        exit;
    }

    // ---- СТАТИСТИКА ----
    if ($data === 'adm_stats') {
        $a = $f = $e = 0;
        foreach ($db['keys'] as $kd) {
            if (!empty($kd['is_frozen'])) $f++;
            elseif (($kd['expires']??0)==0 || time()<($kd['expires']??0)) $a++;
            else $e++;
        }
        $text = "📊 *Статистика*\n\nВсего: " . count($db['keys']) . "\nАктивных: $a\nЗаморожено: $f\nИстекло: $e\nОнлайн: " . count($db['online']) . "\nЧС: " . count($db['blacklist']);
        editMessage($chatId, $msgId, $text, ['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]);
        answerCallback($cqId);
        exit;
    }

    // ---- ЧС ----
    if ($data === 'adm_bl') {
        $text = "🚫 *Чёрный список* (" . count($db['blacklist']) . ")\n\n";
        if (empty($db['blacklist'])) $text .= 'Пусто';
        else {
            foreach ($db['blacklist'] as $val => $info) {
                $text .= "• `$val` — " . ($info['reason'] ?? '') . "\n";
            }
        }
        $text .= "\nДобавить: `/ban IP_или_HWID причина`";
        editMessage($chatId, $msgId, $text, ['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]);
        answerCallback($cqId);
        exit;
    }

    // ---- BROADCAST ----
    if ($data === 'adm_bc') {
        $cur = $db['settings']['broadcast'] ?? '';
        $text = "📢 *Broadcast*\n\nТекущий:\n" . ($cur ?: '— пусто —') . "\n\nУстановить: `/bc текст`\nОчистить: `/bc clear`";
        editMessage($chatId, $msgId, $text, ['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]);
        answerCallback($cqId);
        exit;
    }

    // ---- НАСТРОЙКИ ----
    if ($data === 'adm_settings') {
        $s = $db['settings'];
        $text = "⚙️ *Настройки*\n\n";
        $text .= "Статус: `{$s['status']}`\n";
        $text .= "Soft: `".($s['soft_status']??'undetected')."`\n";
        $text .= "Freeze: `" . (!empty($s['global_freeze'])?'ДА':'нет') . "`\n";
        $text .= "Версия: `{$s['version']}`\n";
        $text .= "Checksum: `{$s['checksum']}`\n";
        $text .= "URL: `{$s['download_url']}`\n\n";
        $text .= "Команды:\n`/setstatus online|maintenance|killswitch`\n`/setsoft undetected|updating|detected`\n`/setver 1.5.1`\n`/setcheck HASH`\n`/seturl https://...`";
        editMessage($chatId, $msgId, $text, ['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]);
        answerCallback($cqId);
        exit;
    }

    // ---- ЛОГИ ----
    if ($data === 'adm_logs') {
        $text = "📜 *Логи*\n\n";
        foreach (array_slice($db['logs'], 0, 15) as $l) {
            $text .= '`' . date('d.m H:i', $l['time']) . '` ' . $l['text'] . "\n";
        }
        editMessage($chatId, $msgId, $text, ['inline_keyboard'=>[[['text'=>'🔄','callback_data'=>'adm_logs'],['text'=>'« Назад','callback_data'=>'admin_panel']]]]);
        answerCallback($cqId);
        exit;
    }

    // ---- TOGGLES ----
    if ($data === 'toggle_kill') {
        if ($db['settings']['status'] === 'killswitch') {
            $db['settings']['status'] = 'online';
            $db['settings']['emergency_msg'] = '';
            answerCallback($cqId, '🟢 Killswitch ВЫКЛ');
        } else {
            $db['settings']['status'] = 'killswitch';
            $db['settings']['emergency_msg'] = '🚨 Софт экстренно остановлен!';
            answerCallback($cqId, '🚨 Killswitch ВКЛ');
        }
        saveDb();
        exit;
    }

    if ($data === 'toggle_gfreeze') {
        $db['settings']['global_freeze'] = empty($db['settings']['global_freeze']);
        saveDb();
        answerCallback($cqId, !empty($db['settings']['global_freeze']) ? '❄️ Global freeze ВКЛ' : '🔓 Global freeze ВЫКЛ');
        exit;
    }

    if ($data === 'noop') {
        answerCallback($cqId);
        exit;
    }
}

// Дополнительные админ-команды через текст (для удобства)
if (isset($update['message']) && (int)$update['message']['chat']['id'] === $adminId) {
    $text = trim($update['message']['text'] ?? '');
    $chatId = $adminId;

    if (strpos($text, '/nick ') === 0) {
        $parts = explode(' ', $text, 3);
        if (count($parts) >= 3) {
            $key = $parts[1];
            $name = $parts[2];
            if (isset($db['keys'][$key])) {
                $db['keys'][$key]['owner_name'] = $name;
                saveDb();
                sendMessage($chatId, "✅ Ник `$key` → *$name*");
            }
        }
        exit;
    }

    if (strpos($text, '/ban ') === 0) {
        $parts = explode(' ', $text, 3);
        $val = $parts[1] ?? '';
        $reason = $parts[2] ?? '';
        if ($val) {
            $db['blacklist'][$val] = ['time'=>time(),'reason'=>$reason];
            saveDb();
            addLog("В ЧС: $val");
            sendMessage($chatId, "🚫 В ЧС: `$val`");
        }
        exit;
    }

    if (strpos($text, '/bc ') === 0) {
        $msg = trim(substr($text, 4));
        if ($msg === 'clear') {
            $db['settings']['broadcast'] = '';
            sendMessage($chatId, '📢 Broadcast очищен');
        } else {
            $db['settings']['broadcast'] = $msg;
            sendMessage($chatId, '📢 Broadcast установлен');
        }
        saveDb();
        exit;
    }

    if (strpos($text, '/setstatus ') === 0) {
        $st = trim(substr($text, 11));
        if (in_array($st, ['online','maintenance','killswitch'])) {
            $db['settings']['status'] = $st;
            if ($st !== 'killswitch') $db['settings']['emergency_msg'] = '';
            saveDb();
            sendMessage($chatId, "Статус → `$st`");
        }
        exit;
    }

    if (strpos($text, '/setsoft ') === 0) {
        $st = trim(substr($text, 9));
        if (in_array($st, ['undetected','updating','detected'])) {
            $db['settings']['soft_status'] = $st;
            saveDb();
            sendMessage($chatId, "Soft → `$st`");
        }
        exit;
    }

    if (strpos($text, '/setver ') === 0) {
        $db['settings']['version'] = trim(substr($text, 8));
        saveDb();
        sendMessage($chatId, 'Версия обновлена');
        exit;
    }

    if (strpos($text, '/setcheck ') === 0) {
        $db['settings']['checksum'] = trim(substr($text, 10));
        saveDb();
        sendMessage($chatId, 'Checksum обновлён');
        exit;
    }

    if (strpos($text, '/seturl ') === 0) {
        $db['settings']['download_url'] = trim(substr($text, 8));
        saveDb();
        sendMessage($chatId, 'URL обновлён');
        exit;
    }
}
