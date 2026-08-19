<?php
// ====================== БЕЗОПАСНОСТЬ ======================
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
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
$botToken   = getenv('BOT_TOKEN') ?: '8883380357:AAHrYtiqhcCTBvllozb5m4pMUQIw922a0Oo';
$adminId    = (int)(getenv('ADMIN_ID') ?: 8875180956);
$adminPass  = getenv('ADMIN_PASS') ?: 'admin123'; // пароль для веб-админки

if (empty($botToken) || empty($adminId)) {
    http_response_code(500);
    die('Server misconfigured. Set BOT_TOKEN and ADMIN_ID');
}

$dbFile = __DIR__ . '/database.json';
$db = file_exists($dbFile) ? json_decode(file_get_contents($dbFile), true) : [];
if (!is_array($db)) $db = [];

// Инициализация
foreach (['keys', 'blacklist', 'logs', 'online'] as $k) {
    if (!isset($db[$k])) $db[$k] = [];
}
if (!isset($db['settings'])) {
    $db['settings'] = [
        'status' => 'online',
        'version' => '1.1.0',
        'checksum' => '5c8714cf5eb4010c2cad5b14ac476f3f3f695d26',
        'download_url' => 'https://example.com/script.lua',
        'emergency_msg' => '',
        'prices' => [
            'day'   => ['stars' => 25,  'rub' => 49],
            'week'  => ['stars' => 75,  'rub' => 149],
            'month' => ['stars' => 125, 'rub' => 299],
            'life'  => ['stars' => 400, 'rub' => 799]
        ]
    ];
}

function saveDb() {
    global $db, $dbFile;
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function addLog($text) {
    global $db;
    array_unshift($db['logs'], ['time' => time(), 'text' => $text]);
    if (count($db['logs']) > 150) array_pop($db['logs']);
    saveDb();
}

// Чистим старые онлайн
foreach ($db['online'] as $hwid => $data) {
    if (time() - ($data['last_ping'] ?? 0) > 90) unset($db['online'][$hwid]);
}
saveDb();

$action = $_GET['action'] ?? '';
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

// ====================== API ======================
if ($action === 'status_check') {
    header('Content-Type: application/json; charset=utf-8');
    $clientChecksum = $_POST['checksum'] ?? $_GET['checksum'] ?? '';
    $hwid = $_POST['hwid'] ?? $_GET['hwid'] ?? '';
    $key  = $_POST['key'] ?? $_GET['key'] ?? '';

    if (!empty($clientChecksum) && strtolower($clientChecksum) !== strtolower($db['settings']['checksum'])) {
        echo json_encode(['status' => 'error', 'message' => 'Обнаружена модификация скрипта!']);
        exit;
    }
    if ($db['settings']['status'] === 'killswitch') {
        echo json_encode([
            'status' => 'killswitch',
            'message' => $db['settings']['emergency_msg'] ?: 'Софт экстренно остановлен!'
        ]);
        exit;
    }
    if (!empty($hwid)) {
        $db['online'][$hwid] = ['ip' => $ip, 'key' => $key ?: '—', 'last_ping' => time()];
        saveDb();
    }
    echo json_encode([
        'status' => $db['settings']['status'],
        'version' => $db['settings']['version'],
        'checksum' => $db['settings']['checksum'],
        'url' => $db['settings']['download_url'],
        'emergency_msg' => $db['settings']['emergency_msg'] ?? ''
    ]);
    exit;
}

if ($action === 'check') {
    header('Content-Type: text/plain; charset=utf-8');
    $key  = $_POST['key'] ?? $_GET['key'] ?? '';
    $hwid = $_POST['hwid'] ?? $_GET['hwid'] ?? '';

    if ($db['settings']['status'] === 'maintenance') { echo 'Сервер на техническом обслуживании!'; exit; }
    if ($db['settings']['status'] === 'killswitch') { echo $db['settings']['emergency_msg'] ?: 'Софт остановлен!'; exit; }
    if (empty($key)) { echo 'Укажите ключ!'; exit; }
    if (isset($db['blacklist'][$ip]) || isset($db['blacklist'][$hwid])) { echo 'Заблокировано!'; exit; }
    if (!isset($db['keys'][$key])) { echo 'Неверный ключ!'; exit; }

    $kd = $db['keys'][$key];
    $now = time();

    if (!empty($kd['is_frozen'])) { echo 'Ключ заморожен!'; exit; }
    if ($kd['expires'] !== 0 && $now > $kd['expires']) {
        unset($db['keys'][$key]);
        saveDb();
        echo 'Срок действия истёк!';
        exit;
    }

    $acts = $kd['activations'] ?? [];
    $max = (int)($kd['max'] ?? 1);

    foreach ($acts as &$a) {
        if ($a['hwid'] === $hwid) {
            $a['ip'] = $ip;
            $a['last_active'] = $now;
            saveDb();
            echo 'SUCCESS';
            exit;
        }
    }

    if (count($acts) < $max) {
        if (($kd['first_use'] ?? 0) == 0) {
            $db['keys'][$key]['first_use'] = $now;
            if (($kd['duration'] ?? 0) > 0) {
                $db['keys'][$key]['expires'] = $now + $kd['duration'];
            }
        }
        $db['keys'][$key]['activations'][] = [
            'hwid' => $hwid, 'ip' => $ip, 'time' => $now, 'last_active' => $now
        ];
        saveDb();
        addLog("Ключ $key активирован (HWID: $hwid)");
        echo 'SUCCESS';
    } else {
        echo 'Превышен лимит устройств!';
    }
    exit;
}

// ====================== ВЕБ-АДМИНКА ======================
session_start();

if (isset($_GET['admin'])) {
    // Выход
    if (isset($_GET['logout'])) {
        session_destroy();
        header('Location: ?admin');
        exit;
    }

    // Логин
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $adminPass) {
            $_SESSION['admin'] = true;
            header('Location: ?admin');
            exit;
        }
        $loginError = 'Неверный пароль';
    }

    // Если не авторизован — форма входа
    if (empty($_SESSION['admin'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Админ</title>
        <style>
            body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a0a12,#2d0a1a);font-family:system-ui;color:#fff}
            .box{background:rgba(255,255,255,0.05);padding:40px;border-radius:20px;border:1px solid rgba(255,105,180,0.3);width:90%;max-width:360px;text-align:center}
            input{width:100%;padding:14px;border-radius:12px;border:1px solid #ff69b4;background:#1a0a12;color:#fff;font-size:16px;margin:15px 0}
            button{width:100%;padding:14px;border:none;border-radius:12px;background:linear-gradient(90deg,#ff69b4,#ff1493);color:#fff;font-size:16px;font-weight:600;cursor:pointer}
            .err{color:#ff6b9d;margin-bottom:10px}
        </style></head><body><div class="box">
        <h2 style="color:#ff69b4;margin:0 0 10px">Lori Admin</h2>
        '.(!empty($loginError)?'<div class="err">'.$loginError.'</div>':'').'
        <form method="post"><input type="password" name="password" placeholder="Пароль" required autofocus>
        <button type="submit">Войти</button></form></div></body></html>';
        exit;
    }

    // ===== АДМИН-ПАНЕЛЬ =====
    header('Content-Type: text/html; charset=utf-8');

    // Обработка действий
    if (isset($_POST['action'])) {
        $act = $_POST['action'];
        if ($act === 'gen_key') {
            $hours = (int)($_POST['hours'] ?? 24);
            $max = (int)($_POST['max'] ?? 1);
            $duration = $hours === 0 ? 0 : $hours * 3600;
            $newKey = 'LORI-ADM-' . strtoupper(substr(md5(rand().time()), 0, 8));
            $db['keys'][$newKey] = [
                'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                'activations'=>[],'owner_tg'=>0,'reset_left'=>999,'freeze_left'=>999,'is_frozen'=>false
            ];
            saveDb();
            $msg = "Ключ создан: $newKey";
        }
        if ($act === 'toggle_status') {
            $db['settings']['status'] = $db['settings']['status'] === 'online' ? 'maintenance' : 'online';
            saveDb();
            $msg = 'Статус: ' . $db['settings']['status'];
        }
        if ($act === 'killswitch') {
            if ($db['settings']['status'] === 'killswitch') {
                $db['settings']['status'] = 'online';
                $db['settings']['emergency_msg'] = '';
                $msg = 'Killswitch выключен';
            } else {
                $db['settings']['status'] = 'killswitch';
                $db['settings']['emergency_msg'] = '🚨 Софт экстренно остановлен администратором!';
                $msg = 'Killswitch ВКЛЮЧЁН';
            }
            saveDb();
        }
        if ($act === 'delete_key' && !empty($_POST['key'])) {
            unset($db['keys'][$_POST['key']]);
            saveDb();
            $msg = 'Ключ удалён';
        }
        if ($act === 'reset_hwid' && !empty($_POST['key'])) {
            if (isset($db['keys'][$_POST['key']])) {
                $db['keys'][$_POST['key']]['activations'] = [];
                saveDb();
                $msg = 'HWID сброшены';
            }
        }
    }

    $totalKeys = count($db['keys']);
    $onlineCount = count($db['online']);
    $status = $db['settings']['status'];
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lori Admin Panel</title>
<style>
:root {
  --pink: #ff69b4;
  --pink2: #ff1493;
  --bg: #0f0a0d;
  --card: #1a1015;
  --border: rgba(255,105,180,0.25);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:#f0e6eb;min-height:100vh}
.header{background:linear-gradient(90deg,#1a0a12,#2d0a1a);padding:18px 24px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border)}
.header h1{font-size:1.4rem;color:var(--pink)}
.header a{color:#ff9ec7;text-decoration:none;font-size:0.9rem}
.container{max-width:1100px;margin:0 auto;padding:24px 16px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:28px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:18px;text-align:center}
.stat .num{font-size:1.8rem;font-weight:700;color:var(--pink)}
.stat .label{font-size:0.85rem;color:#c4a0b0;margin-top:4px}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:20px}
.card h2{font-size:1.15rem;color:var(--pink);margin-bottom:16px}
.btn{display:inline-block;padding:10px 18px;border-radius:10px;border:none;font-weight:600;cursor:pointer;font-size:0.9rem;text-decoration:none;color:#fff}
.btn-pink{background:linear-gradient(90deg,var(--pink),var(--pink2))}
.btn-dark{background:#2a1520;border:1px solid var(--border)}
.btn-red{background:#c2185b}
.btn-sm{padding:6px 12px;font-size:0.8rem}
.form-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
input,select{padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:#12080c;color:#fff;font-size:0.95rem}
table{width:100%;border-collapse:collapse;font-size:0.9rem}
th,td{padding:10px 8px;text-align:left;border-bottom:1px solid rgba(255,105,180,0.1)}
th{color:var(--pink);font-weight:600}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600}
.badge-green{background:rgba(0,200,100,0.15);color:#4ade80}
.badge-red{background:rgba(255,50,80,0.15);color:#fb7185}
.badge-yellow{background:rgba(250,200,0,0.15);color:#fbbf24}
.msg{background:rgba(255,105,180,0.15);border:1px solid var(--pink);padding:12px 16px;border-radius:12px;margin-bottom:20px;color:#ffb6d9}
@media(max-width:600px){table{font-size:0.8rem}.form-row{flex-direction:column}}
</style>
</head>
<body>
<div class="header">
  <h1>Lori Admin</h1>
  <div>
    <a href="?admin">Обновить</a> ·
    <a href="?admin&logout=1">Выйти</a>
  </div>
</div>
<div class="container">

<?php if (!empty($msg)): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="stats">
  <div class="stat"><div class="num"><?= $totalKeys ?></div><div class="label">Всего ключей</div></div>
  <div class="stat"><div class="num"><?= $onlineCount ?></div><div class="label">Онлайн сейчас</div></div>
  <div class="stat"><div class="num"><?= $status ?></div><div class="label">Статус софта</div></div>
</div>

<!-- Быстрые действия -->
<div class="card">
  <h2>Быстрые действия</h2>
  <form method="post" class="form-row" style="align-items:center">
    <input type="hidden" name="action" value="gen_key">
    <input type="number" name="hours" value="24" placeholder="Часов (0 = навсегда)" style="width:140px">
    <input type="number" name="max" value="1" placeholder="Макс. устройств" style="width:140px">
    <button class="btn btn-pink" type="submit">Создать ключ</button>
  </form>
  <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
    <form method="post"><input type="hidden" name="action" value="toggle_status"><button class="btn btn-dark" type="submit">Переключить статус</button></form>
    <form method="post"><input type="hidden" name="action" value="killswitch"><button class="btn btn-red" type="submit"><?= $status==='killswitch'?'Выключить Killswitch':'Включить Killswitch' ?></button></form>
  </div>
</div>

<!-- Онлайн -->
<div class="card">
  <h2>Сейчас онлайн (<?= $onlineCount ?>)</h2>
  <?php if (empty($db['online'])): ?>
    <p style="color:#c4a0b0">Никого нет</p>
  <?php else: ?>
    <table>
      <tr><th>Ключ</th><th>IP</th><th>HWID</th><th>Пинг</th></tr>
      <?php foreach ($db['online'] as $hwid => $info): ?>
      <tr>
        <td><code><?= htmlspecialchars($info['key'] ?? '—') ?></code></td>
        <td><?= htmlspecialchars($info['ip'] ?? '') ?></td>
        <td style="font-size:0.75rem"><?= htmlspecialchars(substr($hwid,0,16)) ?>…</td>
        <td><?= time() - ($info['last_ping']??0) ?> сек</td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<!-- Ключи -->
<div class="card">
  <h2>Все ключи (<?= $totalKeys ?>)</h2>
  <div style="overflow-x:auto">
  <table>
    <tr><th>Ключ</th><th>Владелец</th><th>Статус</th><th>Устройства</th><th>Действия</th></tr>
    <?php foreach ($db['keys'] as $k => $kd): 
      $used = count($kd['activations'] ?? []);
      $max = $kd['max'] ?? 1;
      if (($kd['first_use']??0)==0) $st = '<span class="badge badge-yellow">Не акт.</span>';
      elseif (($kd['expires']??0)==0) $st = '<span class="badge badge-green">Навсегда</span>';
      elseif (time() > $kd['expires']) $st = '<span class="badge badge-red">Истёк</span>';
      else $st = '<span class="badge badge-green">Активен</span>';
    ?>
    <tr>
      <td><code><?= htmlspecialchars($k) ?></code></td>
      <td><?= $kd['owner_tg'] ? $kd['owner_tg'] : 'Админ' ?></td>
      <td><?= $st ?></td>
      <td><?= $used ?>/<?= $max ?></td>
      <td>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>"><button class="btn btn-dark btn-sm" type="submit">Сброс HWID</button></form>
        <form method="post" style="display:inline" onsubmit="return confirm('Удалить ключ?')"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>"><button class="btn btn-red btn-sm" type="submit">Удалить</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>

<!-- Логи -->
<div class="card">
  <h2>Последние логи</h2>
  <?php foreach (array_slice($db['logs'], 0, 20) as $l): ?>
    <div style="font-size:0.85rem;padding:6px 0;border-bottom:1px solid rgba(255,105,180,0.08)">
      <span style="color:#ff9ec7"><?= date('d.m H:i:s', $l['time']) ?></span> — <?= htmlspecialchars($l['text']) ?>
    </div>
  <?php endforeach; ?>
</div>

</div>
</body>
</html>
<?php
    exit;
}

// ====================== TELEGRAM BOT ======================
$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Lori</title>
    <style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f0a0d;color:#ff69b4;font-family:system-ui}
    h1{font-size:2rem}</style></head><body><h1>Lori Bot & API Active</h1></body></html>';
    exit;
}

function tgRequest($method, $data) {
    global $botToken;
    $opts = ['http'=>['header'=>"Content-Type: application/json\r\n",'method'=>'POST','content'=>json_encode($data, JSON_UNESCAPED_UNICODE),'ignore_errors'=>true]];
    @file_get_contents("https://api.telegram.org/bot$botToken/$method", false, stream_context_create($opts));
}
function sendMessage($chat_id, $text, $kb = null) {
    $d = ['chat_id'=>$chat_id,'text'=>$text,'parse_mode'=>'Markdown'];
    if ($kb) $d['reply_markup'] = $kb;
    tgRequest('sendMessage', $d);
}
function sendInvoice($chat_id, $title, $desc, $payload, $stars) {
    tgRequest('sendInvoice', [
        'chat_id'=>$chat_id,'title'=>$title,'description'=>$desc,'payload'=>$payload,
        'currency'=>'XTR','prices'=>[['label'=>'Stars','amount'=>$stars]]
    ]);
}

// Pre-checkout
if (isset($update['pre_checkout_query'])) {
    tgRequest('answerPreCheckoutQuery', ['pre_checkout_query_id'=>$update['pre_checkout_query']['id'],'ok'=>true]);
    exit;
}

// Успешная оплата Stars
if (isset($update['message']['successful_payment'])) {
    $chatId = $update['message']['chat']['id'];
    $payload = $update['message']['successful_payment']['invoice_payload'];
    $parts = explode('_', $payload);
    $duration = (int)($parts[1] ?? 0);
    $maxLimit = (int)($parts[2] ?? 1);
    $newKey = 'LORI-' . strtoupper(substr(md5(rand().time()), 0, 10));
    $db['keys'][$newKey] = [
        'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$maxLimit,
        'activations'=>[],'owner_tg'=>$chatId,'reset_left'=>2,'freeze_left'=>2,'is_frozen'=>false
    ];
    saveDb();
    addLog("Куплен ключ $newKey (Stars) юзером $chatId");
    sendMessage($chatId, "🎉 **Оплата прошла!**\n\nВаш ключ:\n`$newKey`");
    exit;
}

// Сообщения
if (isset($update['message'])) {
    $chatId = (int)$update['message']['chat']['id'];
    $text = trim($update['message']['text'] ?? '');

    if ($chatId === $adminId) {
        if (strpos($text, '/gen ') === 0) {
            $args = explode(' ', $text);
            $hours = (int)($args[1] ?? 24);
            $max = (int)($args[2] ?? 1);
            $duration = $hours === 0 ? 0 : $hours * 3600;
            $newKey = 'LORI-ADM-' . strtoupper(substr(md5(rand().time()), 0, 8));
            $db['keys'][$newKey] = ['duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,'activations'=>[],'owner_tg'=>0,'reset_left'=>999,'freeze_left'=>999,'is_frozen'=>false];
            saveDb();
            sendMessage($chatId, "✅ Ключ: `$newKey`");
            exit;
        }
        if (strpos($text, '/give ') === 0) {
            $args = explode(' ', $text);
            $target = (int)($args[1] ?? 0);
            $hours = (int)($args[2] ?? 24);
            $max = (int)($args[3] ?? 1);
            if ($target > 0) {
                $duration = $hours === 0 ? 0 : $hours * 3600;
                $newKey = 'LORI-GIFT-' . strtoupper(substr(md5(rand().time()), 0, 8));
                $db['keys'][$newKey] = ['duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,'activations'=>[],'owner_tg'=>$target,'reset_left'=>2,'freeze_left'=>2,'is_frozen'=>false];
                saveDb();
                sendMessage($chatId, "🎁 Выдан `$newKey` → `$target`");
                sendMessage($target, "🎁 Вам выдан ключ:\n`$newKey`");
            }
            exit;
        }
    }

    if ($text === '/start') {
        $kb = ['inline_keyboard'=>[
            [['text'=>'⏳ 24 часа — 25 ⭐','callback_data'=>'buy_86400_1']],
            [['text'=>'📅 7 дней — 75 ⭐','callback_data'=>'buy_604800_1']],
            [['text'=>'🗓 30 дней — 125 ⭐','callback_data'=>'buy_2592000_1']],
            [['text'=>'♾️ Навсегда — 400 ⭐','callback_data'=>'buy_0_1']],
            [['text'=>'🔑 Мои ключи','callback_data'=>'my_keys']]
        ]];
        if ($chatId === $adminId) $kb['inline_keyboard'][] = [['text'=>'👑 Админ-панель','callback_data'=>'admin_panel']];
        sendMessage($chatId, "👋 Добро пожаловать в **Lori Store**!\n\nПокупка за Stars прямо здесь.\nЗа рубли — на сайте.", $kb);
    }
}

// Callbacks
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $chatId = (int)$cq['message']['chat']['id'];
    $data = $cq['data'];
    $msgId = $cq['message']['message_id'];

    if (strpos($data, 'buy_') === 0) {
        $p = explode('_', $data);
        $starsMap = ['86400'=>25,'604800'=>75,'2592000'=>125,'0'=>400];
        $stars = $starsMap[$p[1]] ?? 25;
        sendInvoice($chatId, 'Ключ Lori', 'Доступ к скрипту', "sub_{$p[1]}_{$p[2]}", $stars);
    }
    elseif ($data === 'my_keys') {
        $found = false;
        foreach ($db['keys'] as $k => $kd) {
            if (($kd['owner_tg'] ?? 0) == $chatId) {
                $found = true;
                $used = count($kd['activations'] ?? []);
                $max = $kd['max'] ?? 1;
                if (($kd['first_use']??0)==0) $st = '⏳ Не активирован';
                elseif (($kd['expires']??0)==0) $st = '♾️ Навсегда';
                elseif (time() > $kd['expires']) $st = '🔴 Истёк';
                else {
                    $left = $kd['expires'] - time();
                    $st = '🟢 ' . floor($left/86400) . 'д ' . floor(($left%86400)/3600) . 'ч';
                }
                $kb = ['inline_keyboard'=>[[['text'=>'🔄 Сбросить HWID','callback_data'=>'user_reset_'.$k]],[['text'=>'« Назад','callback_data'=>'back_home']]]];
                sendMessage($chatId, "🔑 `$k`\n$st\nУстройства: $used/$max", $kb);
            }
        }
        if (!$found) sendMessage($chatId, '📭 У вас пока нет ключей.');
    }
    elseif (strpos($data, 'user_reset_') === 0) {
        $key = str_replace('user_reset_', '', $data);
        if (isset($db['keys'][$key]) && ($db['keys'][$key]['owner_tg']??0) == $chatId) {
            if (($db['keys'][$key]['reset_left']??0) > 0) {
                $db['keys'][$key]['activations'] = [];
                $db['keys'][$key]['reset_left']--;
                saveDb();
                sendMessage($chatId, '✅ HWID сброшены');
            } else sendMessage($chatId, '❌ Лимит сбросов закончился');
        }
    }
    elseif ($data === 'admin_panel' && $chatId === $adminId) {
        $text = "👑 **Админ-панель**\n\nКлючей: ".count($db['keys'])."\nОнлайн: ".count($db['online'])."\nСтатус: `{$db['settings']['status']}`\n\nВеб-админка: https://".$_SERVER['HTTP_HOST']."?admin";
        $kb = ['inline_keyboard'=>[
            [['text'=>'🟢 Онлайн','callback_data'=>'adm_online']],
            [['text'=>'📋 Все ключи','callback_data'=>'adm_users_list']],
            [['text'=>'🚨 Killswitch','callback_data'=>'toggle_killswitch']],
            [['text'=>'⚙️ Статус','callback_data'=>'toggle_status']],
            [['text'=>'📜 Логи','callback_data'=>'adm_logs']]
        ]];
        tgRequest('editMessageText', ['chat_id'=>$chatId,'message_id'=>$msgId,'text'=>$text,'parse_mode'=>'Markdown','reply_markup'=>$kb]);
    }
    elseif ($data === 'adm_online' && $chatId === $adminId) {
        $text = "🟢 **Онлайн:**\n\n";
        if (empty($db['online'])) $text .= 'Никого нет';
        else foreach ($db['online'] as $h => $i) $text .= "• `{$i['key']}` | {$i['ip']}\n";
        sendMessage($chatId, $text, ['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]);
    }
    elseif ($data === 'toggle_killswitch' && $chatId === $adminId) {
        if ($db['settings']['status'] === 'killswitch') {
            $db['settings']['status'] = 'online';
            $db['settings']['emergency_msg'] = '';
            sendMessage($chatId, '🟢 Killswitch выключен');
        } else {
            $db['settings']['status'] = 'killswitch';
            $db['settings']['emergency_msg'] = '🚨 Софт экстренно остановлен!';
            sendMessage($chatId, '🚨 Killswitch включён');
        }
        saveDb();
    }
    elseif ($data === 'toggle_status' && $chatId === $adminId) {
        $db['settings']['status'] = $db['settings']['status'] === 'online' ? 'maintenance' : 'online';
        saveDb();
        sendMessage($chatId, 'Статус: `'.$db['settings']['status'].'`');
    }
    elseif ($data === 'adm_logs' && $chatId === $adminId) {
        $text = "📜 **Логи:**\n\n";
        foreach (array_slice($db['logs'],0,12) as $l) $text .= '`'.date('H:i',$l['time']).'` '.$l['text']."\n";
        sendMessage($chatId, $text, ['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]);
    }
    elseif ($data === 'back_home') {
        $kb = ['inline_keyboard'=>[
            [['text'=>'⏳ 24 часа — 25 ⭐','callback_data'=>'buy_86400_1']],
            [['text'=>'🔑 Мои ключи','callback_data'=>'my_keys']]
        ]];
        if ($chatId === $adminId) $kb['inline_keyboard'][] = [['text'=>'👑 Админ','callback_data'=>'admin_panel']];
        tgRequest('editMessageText', ['chat_id'=>$chatId,'message_id'=>$msgId,'text'=>'Главное меню','reply_markup'=>$kb]);
    }
}
