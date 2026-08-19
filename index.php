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
$botToken  = getenv('BOT_TOKEN') ?: '8883380357:AAHrYtiqhcCTBvllozb5m4pMUQIw922a0Oo';
$adminId   = (int)(getenv('ADMIN_ID') ?: 8875180956);
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
        'status'        => 'online',
        'soft_status'   => 'undetected',
        'global_freeze' => false,
        'version'       => '1.5.0',
        'checksum'      => '5c8714cf5eb4010c2cad5b14ac476f3f3f695d26',
        'download_url'  => 'https://example.com/script.lua',
        'emergency_msg' => '',
        'broadcast'     => '',
        'prices' => [
            'day'   => ['stars' => 25,  'rub' => 49],
            'week'  => ['stars' => 75,  'rub' => 149],
            'month' => ['stars' => 125, 'rub' => 299],
            'life'  => ['stars' => 400, 'rub' => 799]
        ]
    ];
}

function getPrefixByLevel($level) {
    $map = [
        'trial'   => 'TRIAL',
        'free'    => 'FREE',
        'media'   => 'MEDIA',
        'premium' => 'PREMIUM'
    ];
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
    $key  = $_POST['key'] ?? $_GET['key'] ?? '';

    if (!empty($clientChecksum) && strtolower($clientChecksum) !== strtolower($db['settings']['checksum'])) {
        echo json_encode(['status' => 'error', 'message' => 'Обнаружена модификация скрипта!']);
        exit;
    }
    if ($db['settings']['status'] === 'killswitch') {
        echo json_encode(['status' => 'killswitch', 'message' => $db['settings']['emergency_msg'] ?: 'Софт экстренно остановлен!']);
        exit;
    }
    if (!empty($db['settings']['global_freeze'])) {
        echo json_encode(['status' => 'frozen', 'message' => 'Все ключи временно заморожены']);
        exit;
    }
    if (($db['settings']['soft_status'] ?? '') === 'detected') {
        echo json_encode(['status' => 'detected', 'message' => 'Софт временно недоступен (Detected)']);
        exit;
    }

    if (!empty($hwid)) {
        $db['online'][$hwid] = [
            'ip'         => $ip,
            'key'        => $key ?: '—',
            'last_ping'  => time(),
            'first_seen' => $db['online'][$hwid]['first_seen'] ?? time()
        ];
        saveDb();
    }

    echo json_encode([
        'status'        => $db['settings']['status'],
        'soft_status'   => $db['settings']['soft_status'] ?? 'undetected',
        'version'       => $db['settings']['version'],
        'checksum'      => $db['settings']['checksum'],
        'url'           => $db['settings']['download_url'],
        'emergency_msg' => $db['settings']['emergency_msg'] ?? '',
        'broadcast'     => $db['settings']['broadcast'] ?? '',
        'global_freeze' => !empty($db['settings']['global_freeze'])
    ]);
    exit;
}

if ($action === 'check') {
    header('Content-Type: text/plain; charset=utf-8');
    $key  = trim($_POST['key'] ?? $_GET['key'] ?? '');
    $hwid = trim($_POST['hwid'] ?? $_GET['hwid'] ?? '');

    if ($db['settings']['status'] === 'maintenance') { echo 'Сервер на техническом обслуживании!'; exit; }
    if ($db['settings']['status'] === 'killswitch')   { echo $db['settings']['emergency_msg'] ?: 'Софт остановлен!'; exit; }
    if (!empty($db['settings']['global_freeze']))     { echo 'Все ключи временно заморожены!'; exit; }
    if (($db['settings']['soft_status'] ?? '') === 'detected') { echo 'Софт временно недоступен (Detected)'; exit; }

    if (empty($key))  { echo 'Укажите ключ!'; exit; }
    if (empty($hwid)) { echo 'HWID не передан!'; exit; }

    if (isset($db['blacklist'][$ip]) || isset($db['blacklist'][$hwid])) {
        echo 'Заблокировано!';
        exit;
    }
    if (!isset($db['keys'][$key])) {
        echo 'Неверный ключ!';
        exit;
    }

    $kd  = $db['keys'][$key];
    $now = time();

    if (!empty($kd['is_frozen'])) { echo 'Ключ заморожен!'; exit; }
    if (($kd['expires'] ?? 0) !== 0 && $now > $kd['expires']) {
        unset($db['keys'][$key]);
        saveDb();
        echo 'Срок действия истёк!';
        exit;
    }

    $acts = $kd['activations'] ?? [];
    $max  = (int)($kd['max'] ?? 1);

    // Уже есть этот HWID на этом ключе
    foreach ($acts as &$a) {
        if (($a['hwid'] ?? '') === $hwid) {
            $a['ip']          = $ip;
            $a['last_active'] = $now;
            $a['launches']    = ($a['launches'] ?? 0) + 1;
            saveDb();
            echo 'SUCCESS';
            exit;
        }
    }
    unset($a);

    // Новый HWID (можно на бесконечное число ключей, лимит только max на этом ключе)
    if (count($acts) < $max) {
        if (($kd['first_use'] ?? 0) == 0) {
            $db['keys'][$key]['first_use'] = $now;
            if (($kd['duration'] ?? 0) > 0) {
                $db['keys'][$key]['expires'] = $now + $kd['duration'];
            }
        }
        $db['keys'][$key]['activations'][] = [
            'hwid'        => $hwid,
            'ip'          => $ip,
            'time'        => $now,
            'last_active' => $now,
            'launches'    => 1
        ];
        saveDb();
        addLog("Ключ $key активирован | HWID: " . substr($hwid, 0, 12) . "... | IP: $ip");
        echo 'SUCCESS';
    } else {
        echo 'Превышен лимит устройств!';
    }
    exit;
}

// ====================== ВЕБ-АДМИНКА ======================
session_start();

if (isset($_GET['admin'])) {

    if (isset($_GET['logout'])) {
        session_destroy();
        header('Location: ?admin');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $adminPass) {
            $_SESSION['admin'] = true;
            header('Location: ?admin');
            exit;
        }
        $loginError = 'Неверный пароль';
    }

    if (empty($_SESSION['admin'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Lori Admin</title>
        <style>
            body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a0a12,#2d0a1a);font-family:system-ui;color:#fff}
            .box{background:rgba(255,255,255,0.05);padding:40px;border-radius:20px;border:1px solid rgba(255,105,180,0.3);width:90%;max-width:380px;text-align:center}
            input{width:100%;padding:14px;border-radius:12px;border:1px solid #ff69b4;background:#1a0a12;color:#fff;font-size:16px;margin:15px 0}
            button{width:100%;padding:14px;border:none;border-radius:12px;background:linear-gradient(90deg,#ff69b4,#ff1493);color:#fff;font-size:16px;font-weight:600;cursor:pointer}
            .err{color:#ff6b9d;margin-bottom:10px}
        </style></head><body><div class="box">
        <h2 style="color:#ff69b4;margin:0 0 8px">Lori Admin</h2>
        ' . (!empty($loginError) ? '<div class="err">'.$loginError.'</div>' : '') . '
        <form method="post"><input type="password" name="password" placeholder="Пароль" required autofocus>
        <button type="submit">Войти</button></form></div></body></html>';
        exit;
    }

    $msg = '';
    $tab = $_GET['tab'] ?? 'dashboard';
    $viewKey = $_GET['view'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $act = $_POST['action'];

        if ($act === 'gen_key') {
            $hours  = (int)($_POST['hours'] ?? 24);
            $max    = max(1, (int)($_POST['max'] ?? 1));
            $level  = in_array($_POST['level'] ?? '', ['trial','free','media','premium']) ? $_POST['level'] : 'trial';
            $prefix = getPrefixByLevel($level);
            $duration = $hours === 0 ? 0 : $hours * 3600;
            $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $db['keys'][$newKey] = [
                'duration' => $duration, 'expires' => 0, 'first_use' => 0, 'max' => $max,
                'activations' => [], 'owner_tg' => 0, 'owner_name' => '', 'reset_left' => 3,
                'is_frozen' => false, 'level' => $level, 'created' => time()
            ];
            saveDb();
            addLog("Создан $newKey ($level)");
            $msg = "✅ Ключ: <b>$newKey</b>";
        }

        if ($act === 'bulk_generate') {
            $count  = max(1, min(100, (int)($_POST['count'] ?? 10)));
            $hours  = (int)($_POST['hours'] ?? 24);
            $max    = max(1, (int)($_POST['max'] ?? 1));
            $level  = in_array($_POST['level'] ?? '', ['trial','free','media','premium']) ? $_POST['level'] : 'trial';
            $prefix = getPrefixByLevel($level);
            $duration = $hours === 0 ? 0 : $hours * 3600;
            $list = [];
            for ($i = 0; $i < $count; $i++) {
                $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand() . $i, true)), 0, 8));
                $db['keys'][$newKey] = [
                    'duration' => $duration, 'expires' => 0, 'first_use' => 0, 'max' => $max,
                    'activations' => [], 'owner_tg' => 0, 'owner_name' => '', 'reset_left' => 2,
                    'is_frozen' => false, 'level' => $level, 'created' => time()
                ];
                $list[] = $newKey;
            }
            saveDb();
            addLog("Bulk: $count ключей ($level)");
            $msg = "✅ Создано <b>$count</b>:<br><code style='font-size:0.8rem'>" . implode('<br>', $list) . "</code>";
        }

        if ($act === 'give_key') {
            $tgId  = (int)($_POST['tg_id'] ?? 0);
            $hours = (int)($_POST['hours'] ?? 24);
            $max   = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level'] ?? '', ['trial','free','media','premium']) ? $_POST['level'] : 'premium';
            if ($tgId > 0) {
                $prefix = getPrefixByLevel($level);
                $duration = $hours === 0 ? 0 : $hours * 3600;
                $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                $db['keys'][$newKey] = [
                    'duration' => $duration, 'expires' => 0, 'first_use' => 0, 'max' => $max,
                    'activations' => [], 'owner_tg' => $tgId, 'owner_name' => '', 'reset_left' => 3,
                    'is_frozen' => false, 'level' => $level, 'created' => time()
                ];
                saveDb();
                addLog("Выдан $newKey → $tgId");
                $opts = ['http' => ['header' => "Content-Type: application/json\r\n", 'method' => 'POST',
                    'content' => json_encode(['chat_id' => $tgId, 'text' => "🎁 Вам выдан ключ:\n`$newKey`\nТип: $level", 'parse_mode' => 'Markdown'], JSON_UNESCAPED_UNICODE)]];
                @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage", false, stream_context_create($opts));
                $msg = "✅ <b>$newKey</b> → <b>$tgId</b>";
            } else $msg = "❌ Укажите Telegram ID";
        }

        if ($act === 'freeze_key' && !empty($_POST['key'])) {
            $k = $_POST['key'];
            if (isset($db['keys'][$k])) {
                $db['keys'][$k]['is_frozen'] = empty($db['keys'][$k]['is_frozen']);
                saveDb();
                $msg = !empty($db['keys'][$k]['is_frozen']) ? "❄️ Заморожен" : "🔓 Разморожен";
            }
        }

        if ($act === 'reset_hwid' && !empty($_POST['key'])) {
            $k = $_POST['key'];
            if (isset($db['keys'][$k])) {
                $db['keys'][$k]['activations'] = [];
                saveDb();
                addLog("Сброс HWID у $k");
                $msg = "🔄 HWID сброшены";
            }
        }

        if ($act === 'delete_key' && !empty($_POST['key'])) {
            unset($db['keys'][$_POST['key']]);
            saveDb();
            $msg = "🗑 Удалён";
        }

        if ($act === 'toggle_global_freeze') {
            $db['settings']['global_freeze'] = empty($db['settings']['global_freeze']);
            saveDb();
            $msg = !empty($db['settings']['global_freeze']) ? '❄️ Global freeze ВКЛ' : '🔓 Global freeze ВЫКЛ';
        }

        if ($act === 'set_status') {
            $db['settings']['status'] = $_POST['status'] ?? 'online';
            if ($db['settings']['status'] !== 'killswitch') $db['settings']['emergency_msg'] = '';
            saveDb();
            $msg = "Статус: " . $db['settings']['status'];
        }
        if ($act === 'set_soft_status') {
            $db['settings']['soft_status'] = $_POST['soft_status'] ?? 'undetected';
            saveDb();
            $msg = "Soft: " . $db['settings']['soft_status'];
        }
        if ($act === 'set_broadcast') {
            $db['settings']['broadcast'] = trim($_POST['broadcast'] ?? '');
            saveDb();
            $msg = $db['settings']['broadcast'] !== '' ? '📢 Broadcast OK' : '📢 Broadcast очищен';
        }
        if ($act === 'add_blacklist') {
            $val = trim($_POST['value'] ?? '');
            if ($val !== '') {
                $db['blacklist'][$val] = ['time' => time(), 'reason' => trim($_POST['reason'] ?? '')];
                saveDb();
                addLog("В ЧС: $val");
                $msg = "🚫 В ЧС: $val";
            }
        }
        if ($act === 'remove_blacklist' && !empty($_POST['value'])) {
            unset($db['blacklist'][$_POST['value']]);
            saveDb();
            $msg = "✅ Удалено из ЧС";
        }
        if ($act === 'save_settings') {
            $db['settings']['version']       = trim($_POST['version'] ?? $db['settings']['version']);
            $db['settings']['checksum']      = trim($_POST['checksum'] ?? $db['settings']['checksum']);
            $db['settings']['download_url']  = trim($_POST['download_url'] ?? $db['settings']['download_url']);
            $db['settings']['emergency_msg'] = trim($_POST['emergency_msg'] ?? '');
            saveDb();
            $msg = "⚙️ Сохранено";
        }
    }

    $totalKeys   = count($db['keys']);
    $onlineCount = count($db['online']);
    $active = $frozen = $expired = 0;
    foreach ($db['keys'] as $kd) {
        if (!empty($kd['is_frozen'])) $frozen++;
        elseif (($kd['expires'] ?? 0) == 0 || time() < ($kd['expires'] ?? 0)) $active++;
        else $expired++;
    }

    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lori Admin</title>
<style>
:root{--pink:#ff69b4;--pink2:#ff1493;--bg:#0c0709;--card:#160d12;--border:rgba(255,105,180,0.22);--text:#fce7f3;--muted:#d4a5b8}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(90deg,#1a0a12,#2d0a1a);padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.header h1{font-size:1.25rem;color:var(--pink)}
.header a{color:var(--muted);text-decoration:none;margin-left:14px;font-size:0.88rem}
.layout{display:flex;max-width:1400px;margin:0 auto}
.sidebar{width:200px;padding:16px 10px;border-right:1px solid var(--border);min-height:calc(100vh - 55px)}
.sidebar a{display:block;padding:10px 12px;border-radius:10px;color:var(--muted);text-decoration:none;margin-bottom:3px;font-size:0.9rem}
.sidebar a:hover,.sidebar a.active{background:rgba(255,105,180,0.12);color:var(--pink)}
.content{flex:1;padding:20px 16px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:18px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px;text-align:center}
.stat .num{font-size:1.45rem;font-weight:700;color:var(--pink)}
.stat .label{font-size:0.75rem;color:var(--muted);margin-top:2px}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:18px;margin-bottom:16px}
.card h2{font-size:1.05rem;color:var(--pink);margin-bottom:12px}
.btn{display:inline-block;padding:8px 14px;border-radius:9px;border:none;font-weight:600;cursor:pointer;font-size:0.85rem;color:#fff;text-decoration:none}
.btn-pink{background:linear-gradient(90deg,var(--pink),var(--pink2))}
.btn-dark{background:#2a1520;border:1px solid var(--border)}
.btn-red{background:#c2185b}
.btn-green{background:#0d9488}
.btn-sm{padding:5px 9px;font-size:0.75rem}
.form-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;align-items:center}
input,select,textarea{padding:8px 11px;border-radius:8px;border:1px solid var(--border);background:#12080c;color:#fff;font-size:0.88rem}
textarea{width:100%;min-height:60px}
table{width:100%;border-collapse:collapse;font-size:0.84rem}
th,td{padding:8px 6px;text-align:left;border-bottom:1px solid rgba(255,105,180,0.1);vertical-align:top}
th{color:var(--pink);font-weight:600}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:0.7rem;font-weight:600}
.badge-green{background:rgba(0,200,100,0.15);color:#4ade80}
.badge-red{background:rgba(255,50,80,0.15);color:#fb7185}
.badge-yellow{background:rgba(250,200,0,0.15);color:#fbbf24}
.badge-blue{background:rgba(59,130,246,0.15);color:#60a5fa}
.badge-purple{background:rgba(168,85,247,0.15);color:#c084fc}
.msg{background:rgba(255,105,180,0.12);border:1px solid var(--pink);padding:11px 14px;border-radius:11px;margin-bottom:16px;color:#ffb6d9;font-size:0.9rem}
a.keylink{color:#ff9ec7;text-decoration:none}
a.keylink:hover{text-decoration:underline}
@media(max-width:800px){.layout{flex-direction:column}.sidebar{width:100%;border-right:none;border-bottom:1px solid var(--border);display:flex;overflow-x:auto;gap:4px;padding:8px}.sidebar a{white-space:nowrap;margin:0}}
</style>
</head>
<body>
<div class="header">
  <h1>Lori Admin</h1>
  <div>
    <a href="?admin&tab=<?= urlencode($tab) ?>">Обновить</a>
    <a href="?admin&logout=1">Выйти</a>
  </div>
</div>
<div class="layout">
  <div class="sidebar">
    <a href="?admin&tab=dashboard" class="<?= $tab==='dashboard'?'active':'' ?>">📊 Дашборд</a>
    <a href="?admin&tab=keys" class="<?= $tab==='keys'?'active':'' ?>">🔑 Ключи</a>
    <a href="?admin&tab=generate" class="<?= $tab==='generate'?'active':'' ?>">➕ Создать</a>
    <a href="?admin&tab=bulk" class="<?= $tab==='bulk'?'active':'' ?>">📦 Массово</a>
    <a href="?admin&tab=give" class="<?= $tab==='give'?'active':'' ?>">🎁 Выдать</a>
    <a href="?admin&tab=online" class="<?= $tab==='online'?'active':'' ?>">🟢 Онлайн</a>
    <a href="?admin&tab=broadcast" class="<?= $tab==='broadcast'?'active':'' ?>">📢 Broadcast</a>
    <a href="?admin&tab=blacklist" class="<?= $tab==='blacklist'?'active':'' ?>">🚫 ЧС</a>
    <a href="?admin&tab=settings" class="<?= $tab==='settings'?'active':'' ?>">⚙️ Настройки</a>
    <a href="?admin&tab=logs" class="<?= $tab==='logs'?'active':'' ?>">📜 Логи</a>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="msg"><?= $msg ?></div><?php endif; ?>

    <?php if ($viewKey && isset($db['keys'][$viewKey])): 
      $kd = $db['keys'][$viewKey];
      $used = count($kd['activations'] ?? []);
    ?>
      <div class="card">
        <h2>Ключ: <?= htmlspecialchars($viewKey) ?></h2>
        <p>
          Уровень: <b><?= htmlspecialchars($kd['level'] ?? '—') ?></b><br>
          Владелец TG: <b><?= $kd['owner_tg'] ?: '—' ?></b><br>
          Устройств: <?= $used ?>/<?= $kd['max'] ?? 1 ?><br>
          Создан: <?= !empty($kd['created']) ? date('d.m.Y H:i', $kd['created']) : '—' ?><br>
          Первая активация: <?= !empty($kd['first_use']) ? date('d.m.Y H:i', $kd['first_use']) : 'ещё нет' ?><br>
          Истекает: <?= ($kd['expires'] ?? 0) == 0 ? 'навсегда' : date('d.m.Y H:i', $kd['expires']) ?><br>
          Заморожен: <?= !empty($kd['is_frozen']) ? 'да' : 'нет' ?>
        </p>
        <h3 style="margin:14px 0 8px;color:var(--pink)">Активации / HWID</h3>
        <?php if (empty($kd['activations'])): ?>
          <p style="color:var(--muted)">Нет активаций</p>
        <?php else: ?>
          <table>
            <tr><th>HWID</th><th>IP</th><th>Активация</th><th>Последний</th><th>Запусков</th></tr>
            <?php foreach ($kd['activations'] as $a): ?>
            <tr>
              <td><code style="font-size:0.75rem"><?= htmlspecialchars($a['hwid'] ?? '') ?></code></td>
              <td><?= htmlspecialchars($a['ip'] ?? '') ?></td>
              <td><?= !empty($a['time']) ? date('d.m H:i', $a['time']) : '—' ?></td>
              <td><?= !empty($a['last_active']) ? date('d.m H:i', $a['last_active']) : '—' ?></td>
              <td><?= $a['launches'] ?? 1 ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
        <div style="margin-top:14px" class="form-row">
          <form method="post"><input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="btn btn-dark" type="submit">Сброс HWID</button></form>
          <form method="post"><input type="hidden" name="action" value="freeze_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="btn btn-dark" type="submit"><?= !empty($kd['is_frozen'])?'Разморозить':'Заморозить' ?></button></form>
          <form method="post" onsubmit="return confirm('Удалить?')"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="btn btn-red" type="submit">Удалить</button></form>
          <a href="?admin&tab=keys" class="btn btn-dark">← Назад</a>
        </div>
      </div>

    <?php elseif ($tab === 'dashboard'): ?>
      <div class="stats">
        <div class="stat"><div class="num"><?= $totalKeys ?></div><div class="label">Ключей</div></div>
        <div class="stat"><div class="num"><?= $active ?></div><div class="label">Активных</div></div>
        <div class="stat"><div class="num"><?= $onlineCount ?></div><div class="label">Онлайн</div></div>
        <div class="stat"><div class="num"><?= $frozen ?></div><div class="label">Заморожено</div></div>
        <div class="stat"><div class="num"><?= $expired ?></div><div class="label">Истекло</div></div>
        <div class="stat"><div class="num"><?= count($db['blacklist']) ?></div><div class="label">ЧС</div></div>
      </div>
      <div class="card">
        <h2>Быстрые действия</h2>
        <div class="form-row">
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="online"><button class="btn btn-green" type="submit">Online</button></form>
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="maintenance"><button class="btn btn-dark" type="submit">Maintenance</button></form>
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="killswitch"><button class="btn btn-red" type="submit">Killswitch</button></form>
          <form method="post"><input type="hidden" name="action" value="toggle_global_freeze"><button class="btn btn-dark" type="submit"><?= !empty($db['settings']['global_freeze'])?'Снять freeze':'Global freeze' ?></button></form>
        </div>
        <div class="form-row">
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="undetected"><button class="btn btn-green btn-sm" type="submit">Undetected</button></form>
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="updating"><button class="btn btn-dark btn-sm" type="submit">Updating</button></form>
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="detected"><button class="btn btn-red btn-sm" type="submit">Detected</button></form>
        </div>
        <p style="margin-top:10px;color:var(--muted);font-size:0.88rem">
          Статус: <b style="color:var(--pink)"><?= htmlspecialchars($db['settings']['status']) ?></b> ·
          Soft: <b><?= htmlspecialchars($db['settings']['soft_status']??'undetected') ?></b> ·
          Freeze: <b><?= !empty($db['settings']['global_freeze'])?'ДА':'нет' ?></b>
        </p>
      </div>

    <?php elseif ($tab === 'generate'): ?>
      <div class="card">
        <h2>Создать ключ</h2>
        <form method="post">
          <input type="hidden" name="action" value="gen_key">
          <div class="form-row">
            <input type="number" name="hours" value="24" placeholder="Часов (0=∞)" style="width:130px">
            <input type="number" name="max" value="1" min="1" style="width:80px" placeholder="Устройств">
            <select name="level">
              <option value="trial">Trial → TRIAL-</option>
              <option value="free">Free → FREE-</option>
              <option value="media">Media → MEDIA-</option>
              <option value="premium">Premium → PREMIUM-</option>
            </select>
            <button class="btn btn-pink" type="submit">Создать</button>
          </div>
          <div class="form-row">
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=1">1ч</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=24">1д</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=168">7д</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=720">30д</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=0">∞</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'bulk'): ?>
      <div class="card">
        <h2>Массовая генерация (до 100)</h2>
        <form method="post">
          <input type="hidden" name="action" value="bulk_generate">
          <div class="form-row">
            <input type="number" name="count" value="10" min="1" max="100" style="width:80px">
            <input type="number" name="hours" value="24" style="width:100px">
            <input type="number" name="max" value="1" min="1" style="width:70px">
            <select name="level">
              <option value="trial">Trial</option>
              <option value="free">Free</option>
              <option value="media">Media</option>
              <option value="premium">Premium</option>
            </select>
            <button class="btn btn-pink" type="submit">Сгенерировать</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'give'): ?>
      <div class="card">
        <h2>Выдать по Telegram ID</h2>
        <form method="post">
          <input type="hidden" name="action" value="give_key">
          <div class="form-row">
            <input type="number" name="tg_id" placeholder="Telegram ID" required style="width:160px">
            <input type="number" name="hours" value="24" style="width:90px">
            <input type="number" name="max" value="1" style="width:70px">
            <select name="level">
              <option value="premium">Premium</option>
              <option value="media">Media</option>
              <option value="free">Free</option>
              <option value="trial">Trial</option>
            </select>
            <button class="btn btn-pink" type="submit">Выдать</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'keys'): ?>
      <div class="card">
        <h2>Ключи (<?= $totalKeys ?>)</h2>
        <input type="text" id="searchKey" placeholder="Поиск..." onkeyup="filterTable()" style="width:100%;max-width:300px;margin-bottom:12px">
        <div style="overflow-x:auto">
        <table id="keysTable">
          <tr><th>Ключ</th><th>Тип</th><th>Владелец</th><th>Статус</th><th>Устр.</th><th></th></tr>
          <?php foreach ($db['keys'] as $k => $kd):
            $used = count($kd['activations'] ?? []);
            $max  = $kd['max'] ?? 1;
            $lvl  = $kd['level'] ?? 'trial';
            if (!empty($kd['is_frozen'])) $st = '<span class="badge badge-blue">Freeze</span>';
            elseif (($kd['first_use'] ?? 0) == 0) $st = '<span class="badge badge-yellow">Не акт.</span>';
            elseif (($kd['expires'] ?? 0) == 0) $st = '<span class="badge badge-green">∞</span>';
            elseif (time() > $kd['expires']) $st = '<span class="badge badge-red">Истёк</span>';
            else $st = '<span class="badge badge-green">Активен</span>';
          ?>
          <tr>
            <td><a class="keylink" href="?admin&view=<?= urlencode($k) ?>"><code><?= htmlspecialchars($k) ?></code></a></td>
            <td><?= htmlspecialchars($lvl) ?></td>
            <td><?= $kd['owner_tg'] ?: '—' ?></td>
            <td><?= $st ?></td>
            <td><?= $used ?>/<?= $max ?></td>
            <td style="white-space:nowrap">
              <form method="post" style="display:inline"><input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>"><button class="btn btn-dark btn-sm" type="submit">Сброс</button></form>
              <form method="post" style="display:inline"><input type="hidden" name="action" value="freeze_key"><input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>"><button class="btn btn-dark btn-sm" type="submit"><?= !empty($kd['is_frozen'])?'Размор.':'Замор.' ?></button></form>
              <form method="post" style="display:inline" onsubmit="return confirm('Удалить?')"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>"><button class="btn btn-red btn-sm" type="submit">×</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
        </div>
      </div>

    <?php elseif ($tab === 'online'): ?>
      <div class="card">
        <h2>Онлайн (<?= $onlineCount ?>)</h2>
        <?php if (empty($db['online'])): ?>
          <p style="color:var(--muted)">Никого нет</p>
        <?php else: ?>
          <table>
            <tr><th>Ключ</th><th>IP</th><th>HWID</th><th>Пинг</th><th>С</th></tr>
            <?php foreach ($db['online'] as $hwid => $info): ?>
            <tr>
              <td><code><?= htmlspecialchars($info['key'] ?? '—') ?></code></td>
              <td><?= htmlspecialchars($info['ip'] ?? '') ?></td>
              <td style="font-size:0.75rem"><?= htmlspecialchars(substr($hwid,0,20)) ?>…</td>
              <td><?= time() - ($info['last_ping']??0) ?>с</td>
              <td style="font-size:0.78rem"><?= !empty($info['first_seen']) ? date('H:i:s', $info['first_seen']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'broadcast'): ?>
      <div class="card">
        <h2>Broadcast (всем онлайн)</h2>
        <form method="post">
          <input type="hidden" name="action" value="set_broadcast">
          <textarea name="broadcast" placeholder="Сообщение..."><?= htmlspecialchars($db['settings']['broadcast'] ?? '') ?></textarea>
          <div class="form-row" style="margin-top:10px">
            <button class="btn btn-pink" type="submit">Установить</button>
            <button class="btn btn-dark" type="submit" onclick="this.form.broadcast.value=''">Очистить</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'blacklist'): ?>
      <div class="card">
        <h2>Добавить в ЧС</h2>
        <form method="post" class="form-row">
          <input type="hidden" name="action" value="add_blacklist">
          <input type="text" name="value" placeholder="IP или HWID" required style="width:240px">
          <input type="text" name="reason" placeholder="Причина" style="width:160px">
          <button class="btn btn-red" type="submit">Добавить</button>
        </form>
      </div>
      <div class="card">
        <h2>ЧС (<?= count($db['blacklist']) ?>)</h2>
        <?php if (empty($db['blacklist'])): ?>
          <p style="color:var(--muted)">Пусто</p>
        <?php else: ?>
          <table>
            <tr><th>Значение</th><th>Причина</th><th>Дата</th><th></th></tr>
            <?php foreach ($db['blacklist'] as $val => $info): ?>
            <tr>
              <td><code><?= htmlspecialchars($val) ?></code></td>
              <td><?= htmlspecialchars($info['reason'] ?? '') ?></td>
              <td><?= date('d.m H:i', $info['time'] ?? time()) ?></td>
              <td><form method="post"><input type="hidden" name="action" value="remove_blacklist"><input type="hidden" name="value" value="<?= htmlspecialchars($val) ?>"><button class="btn btn-dark btn-sm" type="submit">×</button></form></td>
            </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'settings'): ?>
      <div class="card">
        <h2>Настройки</h2>
        <form method="post">
          <input type="hidden" name="action" value="save_settings">
          <div class="form-row"><label style="width:120px">Версия</label><input type="text" name="version" value="<?= htmlspecialchars($db['settings']['version']) ?>" style="width:160px"></div>
          <div class="form-row"><label style="width:120px">Checksum</label><input type="text" name="checksum" value="<?= htmlspecialchars($db['settings']['checksum']) ?>" style="width:300px"></div>
          <div class="form-row"><label style="width:120px">Download URL</label><input type="text" name="download_url" value="<?= htmlspecialchars($db['settings']['download_url']) ?>" style="width:300px"></div>
          <div class="form-row" style="align-items:flex-start"><label style="width:120px;margin-top:8px">Emergency</label><textarea name="emergency_msg"><?= htmlspecialchars($db['settings']['emergency_msg']) ?></textarea></div>
          <button class="btn btn-pink" type="submit" style="margin-top:10px">Сохранить</button>
        </form>
      </div>

    <?php elseif ($tab === 'logs'): ?>
      <div class="card">
        <h2>Логи</h2>
        <?php foreach (array_slice($db['logs'], 0, 50) as $l): ?>
          <div style="font-size:0.82rem;padding:6px 0;border-bottom:1px solid rgba(255,105,180,0.07)">
            <span style="color:#ff9ec7"><?= date('d.m H:i:s', $l['time']) ?></span> — <?= htmlspecialchars($l['text']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
function filterTable(){
  const q=document.getElementById('searchKey').value.toLowerCase();
  document.querySelectorAll('#keysTable tr').forEach((row,i)=>{if(i===0)return;row.style.display=row.innerText.toLowerCase().includes(q)?'':'none'});
}
</script>
</body>
</html>
<?php
    exit;
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
        'header' => "Content-Type: application/json\r\n",
        'method' => 'POST',
        'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'ignore_errors' => true
    ]];
    @file_get_contents("https://api.telegram.org/bot$botToken/$method", false, stream_context_create($opts));
}
function sendMessage($chat_id, $text, $kb = null) {
    $d = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($kb) $d['reply_markup'] = $kb;
    tgRequest('sendMessage', $d);
}
function sendInvoice($chat_id, $title, $desc, $payload, $stars) {
    tgRequest('sendInvoice', [
        'chat_id' => $chat_id, 'title' => $title, 'description' => $desc,
        'payload' => $payload, 'currency' => 'XTR',
        'prices' => [['label' => 'Stars', 'amount' => $stars]]
    ]);
}

if (isset($update['pre_checkout_query'])) {
    tgRequest('answerPreCheckoutQuery', ['pre_checkout_query_id' => $update['pre_checkout_query']['id'], 'ok' => true]);
    exit;
}

if (isset($update['message']['successful_payment'])) {
    $chatId  = $update['message']['chat']['id'];
    $payload = $update['message']['successful_payment']['invoice_payload'];
    $parts   = explode('_', $payload);
    $duration = (int)($parts[1] ?? 0);
    $maxLimit = (int)($parts[2] ?? 1);
    // Покупки всегда PREMIUM
    $newKey = 'PREMIUM-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    $db['keys'][$newKey] = [
        'duration' => $duration, 'expires' => 0, 'first_use' => 0, 'max' => $maxLimit,
        'activations' => [], 'owner_tg' => $chatId, 'owner_name' => '', 'reset_left' => 2,
        'is_frozen' => false, 'level' => 'premium', 'created' => time()
    ];
    saveDb();
    addLog("Куплен $newKey (Stars) юзером $chatId");
    sendMessage($chatId, "🎉 **Оплата прошла!**\n\nВаш ключ:\n`$newKey`");
    exit;
}

if (isset($update['message'])) {
    $chatId = (int)$update['message']['chat']['id'];
    $text   = trim($update['message']['text'] ?? '');

    if ($chatId === $adminId) {
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
                'duration' => $duration, 'expires' => 0, 'first_use' => 0, 'max' => $max,
                'activations' => [], 'owner_tg' => 0, 'reset_left' => 999, 'is_frozen' => false,
                'level' => $level, 'created' => time()
            ];
            saveDb();
            sendMessage($chatId, "✅ Ключ: `$newKey`\nТип: $level");
            exit;
        }
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
                    'duration' => $duration, 'expires' => 0, 'first_use' => 0, 'max' => $max,
                    'activations' => [], 'owner_tg' => $target, 'reset_left' => 2, 'is_frozen' => false,
                    'level' => $level, 'created' => time()
                ];
                saveDb();
                sendMessage($chatId, "🎁 `$newKey` → `$target`");
                sendMessage($target, "🎁 Вам выдан ключ:\n`$newKey`\nТип: $level");
            }
            exit;
        }
    }

    if ($text === '/start') {
        $kb = ['inline_keyboard' => [
            [['text' => '⏳ 24 часа — 25 ⭐', 'callback_data' => 'buy_86400_1']],
            [['text' => '📅 7 дней — 75 ⭐', 'callback_data' => 'buy_604800_1']],
            [['text' => '🗓 30 дней — 125 ⭐', 'callback_data' => 'buy_2592000_1']],
            [['text' => '♾️ Навсегда — 400 ⭐', 'callback_data' => 'buy_0_1']],
            [['text' => '🔑 Мои ключи', 'callback_data' => 'my_keys']]
        ]];
        if ($chatId === $adminId) {
            $kb['inline_keyboard'][] = [['text' => '👑 Админ-панель', 'callback_data' => 'admin_panel']];
        }
        sendMessage($chatId, "👋 Добро пожаловать в **Lori Store**!", $kb);
    }
}

if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $chatId = (int)$cq['message']['chat']['id'];
    $data = $cq['data'];
    $msgId = $cq['message']['message_id'];

    if (strpos($data, 'buy_') === 0) {
        $p = explode('_', $data);
        $starsMap = ['86400' => 25, '604800' => 75, '2592000' => 125, '0' => 400];
        $stars = $starsMap[$p[1]] ?? 25;
        sendInvoice($chatId, 'Ключ Lori Premium', 'Доступ Premium', "sub_{$p[1]}_{$p[2]}", $stars);
    }
    elseif ($data === 'my_keys') {
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
                    [['text' => '🔄 Сбросить HWID', 'callback_data' => 'user_reset_' . $k]],
                    [['text' => '« Назад', 'callback_data' => 'back_home']]
                ]];
                sendMessage($chatId, "🔑 `$k`\nТип: " . ($kd['level']??'premium') . "\n$st\nУстройства: $used/$max", $kb);
            }
        }
        if (!$found) sendMessage($chatId, '📭 У вас пока нет ключей.');
    }
    elseif (strpos($data, 'user_reset_') === 0) {
        $key = str_replace('user_reset_', '', $data);
        if (isset($db['keys'][$key]) && ($db['keys'][$key]['owner_tg'] ?? 0) == $chatId) {
            if (($db['keys'][$key]['reset_left'] ?? 0) > 0) {
                $db['keys'][$key]['activations'] = [];
                $db['keys'][$key]['reset_left']--;
                saveDb();
                sendMessage($chatId, '✅ HWID сброшены');
            } else sendMessage($chatId, '❌ Лимит сбросов закончился');
        }
    }
    elseif ($data === 'admin_panel' && $chatId === $adminId) {
        $text = "👑 **Админ**\n\nКлючей: *" . count($db['keys']) . "*\nОнлайн: *" . count($db['online']) . "*\nСтатус: `{$db['settings']['status']}`\nSoft: `" . ($db['settings']['soft_status']??'undetected') . "`\n\n🌐 https://" . $_SERVER['HTTP_HOST'] . "/?admin";
        $kb = ['inline_keyboard' => [
            [['text' => '🟢 Онлайн', 'callback_data' => 'adm_online']],
            [['text' => '📊 Статистика', 'callback_data' => 'adm_stats']],
            [['text' => '🚨 Killswitch', 'callback_data' => 'toggle_killswitch']],
            [['text' => '❄️ Global Freeze', 'callback_data' => 'toggle_gfreeze']],
            [['text' => '📜 Логи', 'callback_data' => 'adm_logs']]
        ]];
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $kb]);
    }
    elseif ($data === 'adm_online' && $chatId === $adminId) {
        $text = "🟢 **Онлайн:**\n\n";
        if (empty($db['online'])) $text .= 'Никого нет';
        else foreach ($db['online'] as $h => $i) $text .= "• `{$i['key']}` | {$i['ip']} | " . (time()-($i['last_ping']??0)) . "с\n";
        sendMessage($chatId, $text, ['inline_keyboard' => [[['text' => '« Назад', 'callback_data' => 'admin_panel']]]]);
    }
    elseif ($data === 'adm_stats' && $chatId === $adminId) {
        $a = $f = $e = 0;
        foreach ($db['keys'] as $kd) {
            if (!empty($kd['is_frozen'])) $f++;
            elseif (($kd['expires']??0)==0 || time()<($kd['expires']??0)) $a++;
            else $e++;
        }
        $text = "📊 **Статистика**\n\nВсего: " . count($db['keys']) . "\nАктивных: $a\nЗаморожено: $f\nИстекло: $e\nОнлайн: " . count($db['online']);
        sendMessage($chatId, $text, ['inline_keyboard' => [[['text' => '« Назад', 'callback_data' => 'admin_panel']]]]);
    }
    elseif ($data === 'toggle_killswitch' && $chatId === $adminId) {
        if ($db['settings']['status'] === 'killswitch') {
            $db['settings']['status'] = 'online';
            $db['settings']['emergency_msg'] = '';
            sendMessage($chatId, '🟢 Killswitch выкл');
        } else {
            $db['settings']['status'] = 'killswitch';
            $db['settings']['emergency_msg'] = '🚨 Софт экстренно остановлен!';
            sendMessage($chatId, '🚨 Killswitch вкл');
        }
        saveDb();
    }
    elseif ($data === 'toggle_gfreeze' && $chatId === $adminId) {
        $db['settings']['global_freeze'] = empty($db['settings']['global_freeze']);
        saveDb();
        sendMessage($chatId, !empty($db['settings']['global_freeze']) ? '❄️ Global freeze ВКЛ' : '🔓 Global freeze ВЫКЛ');
    }
    elseif ($data === 'adm_logs' && $chatId === $adminId) {
        $text = "📜 **Логи:**\n\n";
        foreach (array_slice($db['logs'], 0, 12) as $l) $text .= '`' . date('H:i', $l['time']) . '` ' . $l['text'] . "\n";
        sendMessage($chatId, $text, ['inline_keyboard' => [[['text' => '« Назад', 'callback_data' => 'admin_panel']]]]);
    }
    elseif ($data === 'back_home') {
        $kb = ['inline_keyboard' => [
            [['text' => '⏳ 24 часа — 25 ⭐', 'callback_data' => 'buy_86400_1']],
            [['text' => '🔑 Мои ключи', 'callback_data' => 'my_keys']]
        ]];
        if ($chatId === $adminId) $kb['inline_keyboard'][] = [['text' => '👑 Админ', 'callback_data' => 'admin_panel']];
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => 'Главное меню', 'reply_markup' => $kb]);
    }
}
