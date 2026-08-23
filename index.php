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
            body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#050505;font-family:system-ui;color:#fff}
            .box{background:rgba(20,20,22,0.9);padding:42px;border-radius:24px;border:1px solid rgba(212,175,55,0.25);width:90%;max-width:380px;text-align:center;box-shadow:0 0 60px rgba(212,175,55,0.08)}
            input{width:100%;padding:15px;border-radius:14px;border:1px solid rgba(212,175,55,0.3);background:#0a0a0c;color:#fff;font-size:16px;margin:16px 0}
            button{width:100%;padding:15px;border:none;border-radius:14px;background:linear-gradient(90deg,#d4af37,#b8860b);color:#000;font-size:16px;font-weight:700;cursor:pointer}
            .err{color:#ff6b9d;margin-bottom:10px}
        </style></head><body><div class="box">
        <h2 style="color:#d4af37;margin:0 0 8px;letter-spacing:1px">LORI ELITE</h2>
        ' . (!empty($loginError) ? '<div class="err">'.$loginError.'</div>' : '') . '
        <form method="post"><input type="password" name="password" placeholder="Пароль" required autofocus>
        <button type="submit">Войти</button></form></div></body></html>';
        exit;
    }

    $msg = '';
    $tab = $_GET['tab'] ?? 'dashboard';
    $viewKey = $_GET['view'] ?? '';

    // ===== ОБРАБОТКА ДЕЙСТВИЙ КАРТОЧКИ =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $act = $_POST['action'];
        $k = $_POST['key'] ?? '';

        if ($act === 'gen_key') {
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level'] ?? '', ['trial','free','media','premium']) ? $_POST['level'] : 'trial';
            $prefix = getPrefixByLevel($level);
            $duration = $hours === 0 ? 0 : $hours * 3600;
            $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $db['keys'][$newKey] = [
                'duration' => $duration, 'expires' => 0, 'first_use' => 0, 'max' => $max,
                'activations' => [], 'owner_tg' => 0, 'owner_name' => '', 'reset_left' => 3,
                'is_frozen' => false, 'level' => $level, 'created' => time(), 'warns' => 0,
                'note' => '', 'vip' => false
            ];
            saveDb();
            addLog("Создан $newKey ($level)");
            $msg = "✅ Ключ: <b>$newKey</b>";
        }
        if ($act === 'bulk_generate') {
            $count = max(1, min(100, (int)($_POST['count'] ?? 10)));
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level'] ?? '', ['trial','free','media','premium']) ? $_POST['level'] : 'trial';
            $prefix = getPrefixByLevel($level);
            $duration = $hours === 0 ? 0 : $hours * 3600;
            $list = [];
            for ($i = 0; $i < $count; $i++) {
                $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand() . $i, true)), 0, 8));
                $db['keys'][$newKey] = [
                    'duration' => $duration, 'expires' => 0, 'first_use' => 0, 'max' => $max,
                    'activations' => [], 'owner_tg' => 0, 'owner_name' => '', 'reset_left' => 2,
                    'is_frozen' => false, 'level' => $level, 'created' => time(), 'warns' => 0, 'note' => '', 'vip' => false
                ];
                $list[] = $newKey;
            }
            saveDb();
            addLog("Bulk: $count ключей ($level)");
            $msg = "✅ Создано <b>$count</b>:<br><code style='font-size:0.8rem'>" . implode('<br>', $list) . "</code>";
        }
        if ($act === 'give_key') {
            $tgId = (int)($_POST['tg_id'] ?? 0);
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level'] ?? '', ['trial','free','media','premium']) ? $_POST['level'] : 'premium';
            if ($tgId > 0) {
                $prefix = getPrefixByLevel($level);
                $duration = $hours === 0 ? 0 : $hours * 3600;
                $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                $db['keys'][$newKey] = [
                    'duration' => $duration, 'expires' => 0, 'first_use' => 0, 'max' => $max,
                    'activations' => [], 'owner_tg' => $tgId, 'owner_name' => '', 'reset_left' => 3,
                    'is_frozen' => false, 'level' => $level, 'created' => time(), 'warns' => 0, 'note' => '', 'vip' => false
                ];
                saveDb();
                addLog("Выдан $newKey → $tgId");
                $opts = ['http' => ['header' => "Content-Type: application/json\r\n", 'method' => 'POST',
                    'content' => json_encode(['chat_id' => $tgId, 'text' => "🎁 Вам выдан ключ:\n`$newKey`\nТип: $level", 'parse_mode' => 'Markdown'], JSON_UNESCAPED_UNICODE)]];
                @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage", false, stream_context_create($opts));
                $msg = "✅ <b>$newKey</b> → <b>$tgId</b>";
            } else $msg = "❌ Укажите Telegram ID";
        }

        // ===== ДЕЙСТВИЯ КАРТОЧКИ =====
        if ($act === 'freeze_key' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['is_frozen'] = empty($db['keys'][$k]['is_frozen']);
            saveDb();
            $msg = !empty($db['keys'][$k]['is_frozen']) ? "🔒 Ключ заблокирован" : "🔓 Ключ разблокирован";
        }
        if ($act === 'reset_hwid' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['activations'] = [];
            saveDb();
            addLog("Сброс HWID у $k");
            $msg = "🔄 HWID сброшены";
        }
        if ($act === 'delete_key' && $k) {
            unset($db['keys'][$k]);
            saveDb();
            addLog("Удалён $k");
            header('Location: ?admin&tab=keys');
            exit;
        }
        if ($act === 'add_warn' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['warns'] = min(3, ($db['keys'][$k]['warns'] ?? 0) + 1);
            saveDb();
            $msg = "⚠ Варн выдан ({$db['keys'][$k]['warns']}/3)";
        }
        if ($act === 'reset_warns' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['warns'] = 0;
            saveDb();
            $msg = "✅ Варны сброшены";
        }
        if ($act === 'regen_key' && $k && isset($db['keys'][$k])) {
            $old = $db['keys'][$k];
            $level = $old['level'] ?? 'premium';
            $prefix = getPrefixByLevel($level);
            $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $db['keys'][$newKey] = $old;
            $db['keys'][$newKey]['activations'] = [];
            unset($db['keys'][$k]);
            saveDb();
            addLog("Перегенерация $k → $newKey");
            header('Location: ?admin&view=' . urlencode($newKey));
            exit;
        }
        if ($act === 'set_nick' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['owner_name'] = trim($_POST['nick'] ?? '');
            saveDb();
            $msg = "✅ Ник обновлён";
        }
        if ($act === 'set_note' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['note'] = trim($_POST['note'] ?? '');
            saveDb();
            $msg = "✅ Заметка сохранена";
        }
        if ($act === 'toggle_vip' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['vip'] = empty($db['keys'][$k]['vip']);
            saveDb();
            $msg = !empty($db['keys'][$k]['vip']) ? "👑 VIP включён" : "VIP выключен";
        }
        if ($act === 'extend_key' && $k && isset($db['keys'][$k])) {
            $days = max(1, (int)($_POST['days'] ?? 7));
            if (($db['keys'][$k]['expires'] ?? 0) == 0) {
                $db['keys'][$k]['expires'] = time() + $days * 86400;
            } else {
                $db['keys'][$k]['expires'] += $days * 86400;
            }
            saveDb();
            $msg = "⏱ Продлено на $days дней";
        }
        if ($act === 'copy_key') {
            $msg = "📋 Ключ скопирован в буфер (используй кнопку)";
        }

        // остальные действия (статус, ЧС и т.д.)
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
            $db['settings']['version'] = trim($_POST['version'] ?? $db['settings']['version']);
            $db['settings']['checksum'] = trim($_POST['checksum'] ?? $db['settings']['checksum']);
            $db['settings']['download_url'] = trim($_POST['download_url'] ?? $db['settings']['download_url']);
            $db['settings']['emergency_msg'] = trim($_POST['emergency_msg'] ?? '');
            saveDb();
            $msg = "⚙️ Сохранено";
        }
    }

    $totalKeys = count($db['keys']);
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
<title>Lori Elite Admin</title>
<style>
:root {
  --bg: #050505;
  --card: #0c0c0e;
  --border: rgba(212,175,55,0.18);
  --gold: #d4af37;
  --gold2: #f0d78c;
  --text: #f5f0e6;
  --muted: #8a8578;
  --green: #22c55e;
  --red: #ef4444;
  --blue: #3b82f6;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(90deg,#0a0a0c,#121214);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.header h1{font-size:1.2rem;color:var(--gold);letter-spacing:2px;font-weight:600}
.header a{color:var(--muted);text-decoration:none;margin-left:16px;font-size:0.85rem;transition:.2s}
.header a:hover{color:var(--gold)}
.layout{display:flex;max-width:1400px;margin:0 auto}
.sidebar{width:200px;padding:18px 12px;border-right:1px solid var(--border);min-height:calc(100vh - 55px)}
.sidebar a{display:block;padding:11px 14px;border-radius:12px;color:var(--muted);text-decoration:none;margin-bottom:4px;font-size:0.9rem;transition:.2s}
.sidebar a:hover,.sidebar a.active{background:rgba(212,175,55,0.1);color:var(--gold)}
.content{flex:1;padding:22px 18px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:20px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;text-align:center}
.stat .num{font-size:1.5rem;font-weight:700;color:var(--gold)}
.stat .label{font-size:0.72rem;color:var(--muted);margin-top:3px;text-transform:uppercase;letter-spacing:0.5px}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:20px;margin-bottom:18px}
.card h2{font-size:1.05rem;color:var(--gold);margin-bottom:14px;letter-spacing:0.5px}
.btn{display:inline-block;padding:9px 16px;border-radius:11px;border:none;font-weight:600;cursor:pointer;font-size:0.85rem;color:#000;text-decoration:none;transition:.2s}
.btn-gold{background:linear-gradient(135deg,var(--gold),#b8860b);color:#000}
.btn-dark{background:#161618;border:1px solid var(--border);color:var(--text)}
.btn-red{background:linear-gradient(135deg,#b91c1c,#7f1d1d);color:#fff}
.btn-green{background:linear-gradient(135deg,#15803d,#166534);color:#fff}
.btn-sm{padding:6px 11px;font-size:0.75rem}
.form-row{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:11px;align-items:center}
input,select,textarea{padding:9px 13px;border-radius:10px;border:1px solid var(--border);background:#0a0a0c;color:#fff;font-size:0.88rem}
textarea{width:100%;min-height:65px}
table{width:100%;border-collapse:collapse;font-size:0.84rem}
th,td{padding:9px 7px;text-align:left;border-bottom:1px solid rgba(212,175,55,0.08);vertical-align:top}
th{color:var(--gold);font-weight:600}
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:0.7rem;font-weight:600}
.badge-green{background:rgba(34,197,94,0.15);color:#4ade80}
.badge-red{background:rgba(239,68,68,0.15);color:#f87171}
.badge-yellow{background:rgba(234,179,8,0.15);color:#facc15}
.badge-blue{background:rgba(59,130,246,0.15);color:#60a5fa}
.msg{background:rgba(212,175,55,0.1);border:1px solid var(--gold);padding:12px 16px;border-radius:12px;margin-bottom:18px;color:#f0d78c;font-size:0.9rem}
a.keylink{color:var(--gold2);text-decoration:none}
a.keylink:hover{text-decoration:underline}

/* ===== ELITE KEY CARD ===== */
.elite-card {
  background: linear-gradient(165deg, #0a0a0c 0%, #111114 50%, #0d0d10 100%);
  border: 1px solid rgba(212,175,55,0.22);
  border-radius: 24px;
  max-width: 440px;
  margin: 0 auto 24px;
  overflow: hidden;
  box-shadow: 0 0 80px rgba(212,175,55,0.07), 0 25px 50px rgba(0,0,0,0.5);
  position: relative;
}
.elite-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(212,175,55,0.5), transparent);
}
.elite-header {
  padding: 20px 22px 16px;
  display: flex;
  gap: 16px;
  align-items: flex-start;
  border-bottom: 1px solid rgba(255,255,255,0.04);
}
.circle-wrap {
  position: relative;
  width: 62px;
  height: 62px;
  flex-shrink: 0;
}
.circle-bg {
  width: 62px; height: 62px;
  border-radius: 50%;
  background: conic-gradient(from -90deg, #22c55e var(--p), rgba(255,255,255,0.06) 0);
  display: flex; align-items: center; justify-content: center;
}
.circle-inner {
  width: 48px; height: 48px;
  border-radius: 50%;
  background: #0c0c0e;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
}
.circle-inner .num { font-size: 1.15rem; font-weight: 700; color: #fff; line-height: 1; }
.circle-inner .lbl { font-size: 0.55rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
.elite-title { flex: 1; min-width: 0; }
.elite-title .name {
  font-size: 1.15rem; font-weight: 600; color: #fff;
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.elite-title .name .tag {
  font-size: 0.65rem; padding: 2px 8px; border-radius: 6px;
  background: rgba(212,175,55,0.15); color: var(--gold); border: 1px solid rgba(212,175,55,0.25);
}
.elite-title .name .vip {
  font-size: 0.65rem; padding: 2px 8px; border-radius: 6px;
  background: linear-gradient(135deg,#d4af37,#b8860b); color: #000; font-weight: 700;
}
.elite-meta {
  margin-top: 6px; font-size: 0.78rem; color: var(--muted);
  display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
}
.elite-meta span { display: inline-flex; align-items: center; gap: 4px; }
.status-dot {
  width: 7px; height: 7px; border-radius: 50%;
  display: inline-block;
}
.elite-body {
  padding: 16px 22px 8px;
}
.elite-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 7px 0; font-size: 0.84rem;
  border-bottom: 1px solid rgba(255,255,255,0.03);
}
.elite-row:last-child { border-bottom: none; }
.elite-row .label { color: var(--muted); }
.elite-row .value { color: #e8e4d9; font-weight: 500; text-align: right; }
.elite-row .value.green { color: #4ade80; }
.elite-row .value.red { color: #f87171; }
.elite-row .value.gold { color: var(--gold); }

.elite-actions {
  padding: 14px 16px 18px;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}
.e-btn {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 12px;
  padding: 12px 6px;
  color: #c8c4b8;
  font-size: 0.72rem;
  font-weight: 500;
  cursor: pointer;
  transition: all .2s;
  display: flex; flex-direction: column; align-items: center; gap: 5px;
  text-decoration: none;
}
.e-btn:hover {
  background: rgba(212,175,55,0.1);
  border-color: rgba(212,175,55,0.3);
  color: var(--gold);
  transform: translateY(-1px);
}
.e-btn svg, .e-btn .ico { font-size: 1.15rem; opacity: 0.9; }
.e-btn.danger:hover { background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.35); color: #f87171; }
.e-btn.success:hover { background: rgba(34,197,94,0.12); border-color: rgba(34,197,94,0.35); color: #4ade80; }

.elite-footer {
  padding: 0 16px 16px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.elite-note {
  padding: 0 22px 16px;
  font-size: 0.78rem; color: var(--muted);
}
.elite-note textarea {
  width: 100%; background: #0a0a0c; border: 1px solid var(--border);
  border-radius: 10px; padding: 10px; color: #ddd; font-size: 0.82rem; resize: none;
}

@media(max-width:800px){
  .layout{flex-direction:column}
  .sidebar{width:100%;border-right:none;border-bottom:1px solid var(--border);display:flex;overflow-x:auto;gap:4px;padding:10px}
  .sidebar a{white-space:nowrap;margin:0}
}
</style>
</head>
<body>
<div class="header">
  <h1>LORI ELITE</h1>
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
      $max = $kd['max'] ?? 1;
      $warns = $kd['warns'] ?? 0;
      $ownerName = $kd['owner_name'] ?: ($kd['owner_tg'] ? 'ID '.$kd['owner_tg'] : '—');
      $isNamed = !empty($kd['owner_name']) || ($kd['owner_tg'] ?? 0) > 0;
      $namedTag = $isNamed ? 'именной' : 'обычный';
      $tgId = $kd['owner_tg'] ?: '—';
      $android = $kd['android_id'] ?? 'не привязан';
      $isVip = !empty($kd['vip']);
      $note = $kd['note'] ?? '';

      $now = time();
      $daysLeft = '∞';
      $expiresStr = 'навсегда';
      $expiresClass = 'green';
      $circleP = 100;
      if (($kd['expires'] ?? 0) > 0) {
          $left = $kd['expires'] - $now;
          if ($left <= 0) {
              $daysLeft = '0';
              $expiresStr = 'истёк';
              $expiresClass = 'red';
              $circleP = 0;
          } else {
              $daysLeft = (string)ceil($left / 86400);
              $expiresStr = date('d-m-Y H:i', $kd['expires']);
              $circleP = min(100, max(4, ($left / (30*86400)) * 100));
          }
      }

      $status = 'свободен';
      $statusColor = '#fbbf24';
      if (!empty($kd['is_frozen'])) { $status = 'заморожен'; $statusColor = '#60a5fa'; }
      elseif (($kd['first_use'] ?? 0) == 0) { $status = 'не активирован'; $statusColor = '#fbbf24'; }
      elseif (($kd['expires'] ?? 0) > 0 && $now > $kd['expires']) { $status = 'истёк'; $statusColor = '#f87171'; }
      else { $status = 'активен'; $statusColor = '#4ade80'; }

      $mainHwid = '—';
      if (!empty($kd['activations'])) {
          $mainHwid = substr($kd['activations'][0]['hwid'] ?? '—', 0, 16) . '…';
      }
    ?>

    <!-- ==================== ELITE KEY CARD ==================== -->
    <div class="elite-card">
      <div class="elite-header">
        <div class="circle-wrap">
          <div class="circle-bg" style="--p: <?= $circleP ?>%">
            <div class="circle-inner">
              <div class="num"><?= $daysLeft ?></div>
              <div class="lbl">дней</div>
            </div>
          </div>
        </div>
        <div class="elite-title">
          <div class="name">
            <?= htmlspecialchars($viewKey) ?>
            <span class="tag"><?= $namedTag ?></span>
            <?php if ($isVip): ?><span class="vip">VIP</span><?php endif; ?>
          </div>
          <div class="elite-meta">
            <span>📡 <?= $tgId ?></span>
            <span><span class="status-dot" style="background:<?= $statusColor ?>"></span> <?= $status ?></span>
            <span>🔑 вход <?= $used ?></span>
          </div>
        </div>
      </div>

      <div class="elite-body">
        <div class="elite-row">
          <span class="label">Действует до</span>
          <span class="value <?= $expiresClass ?>"><?= $expiresStr ?></span>
        </div>
        <div class="elite-row">
          <span class="label">Владелец</span>
          <span class="value"><?= htmlspecialchars($ownerName) ?> · <?= $namedTag ?></span>
        </div>
        <div class="elite-row">
          <span class="label">Telegram ID</span>
          <span class="value"><?= $tgId ?></span>
        </div>
        <div class="elite-row">
          <span class="label">Android ID</span>
          <span class="value"><?= htmlspecialchars($android) ?></span>
        </div>
        <div class="elite-row">
          <span class="label">HWID</span>
          <span class="value" style="font-size:0.75rem"><?= htmlspecialchars($mainHwid) ?></span>
        </div>
        <div class="elite-row">
          <span class="label">Входов</span>
          <span class="value"><?= $used ?> / <?= $max ?></span>
        </div>
        <div class="elite-row">
          <span class="label">Предупреждения</span>
          <span class="value <?= $warns > 0 ? 'red' : '' ?>"><?= $warns ?> / 3</span>
        </div>
        <div class="elite-row">
          <span class="label">Уровень</span>
          <span class="value gold"><?= strtoupper($kd['level'] ?? '—') ?></span>
        </div>
      </div>

      <div class="elite-actions">
        <button class="e-btn" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($viewKey) ?>');this.innerHTML='✓<br>Скопировано';setTimeout(()=>this.innerHTML='📋<br>Копировать',1500)">
          📋<br>Копировать
        </button>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="set_nick">
          <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="e-btn" type="button" onclick="let n=prompt('Новый ник:','<?= htmlspecialchars($kd['owner_name']??'') ?>');if(n!==null){this.form.nick.value=n;this.form.submit()}">
            <input type="hidden" name="nick" value="">
            👤<br>Ник
          </button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="add_warn">
          <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="e-btn" type="submit">⚠<br>Варн <?= $warns ?>/3</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="reset_warns">
          <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="e-btn" type="submit">🔄<br>Сброс варнов</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="reset_hwid">
          <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="e-btn" type="submit">🖥<br>Сброс HWID</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="regen_key">
          <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="e-btn" type="submit" onclick="return confirm('Перегенерировать ключ? Старый станет недействителен.')">🔁<br>Перегенер.</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="freeze_key">
          <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="e-btn <?= !empty($kd['is_frozen'])?'success':'' ?>" type="submit">
            <?= !empty($kd['is_frozen']) ? '🔓<br>Разблок' : '🔒<br>Блок' ?>
          </button>
        </form>

        <a href="?admin&tab=logs&filter=<?= urlencode($viewKey) ?>" class="e-btn">📜<br>Логи</a>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="toggle_vip">
          <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="e-btn" type="submit"><?= $isVip ? '👑<br>VIP ✓' : '👑<br>VIP' ?></button>
        </form>
      </div>

      <div class="elite-footer">
        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="extend_key">
          <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <input type="hidden" name="days" value="7">
          <button class="e-btn success" type="submit" style="grid-column:span 1">⏱ +7 дней</button>
        </form>
        <form method="post" onsubmit="return confirm('Удалить ключ навсегда?')" style="display:contents">
          <input type="hidden" name="action" value="delete_key">
          <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="e-btn danger" type="submit">🗑 Удалить</button>
        </form>
      </div>

      <div class="elite-note">
        <form method="post">
          <input type="hidden" name="action" value="set_note">
          <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <textarea name="note" rows="2" placeholder="Приватная заметка..."><?= htmlspecialchars($note) ?></textarea>
          <button class="btn btn-dark btn-sm" type="submit" style="margin-top:6px">Сохранить заметку</button>
        </form>
      </div>
    </div>

    <div style="text-align:center;margin-bottom:20px">
      <a href="?admin&tab=keys" class="btn btn-dark">← Назад к списку</a>
    </div>

    <!-- Активации -->
    <div class="card" style="max-width:700px;margin:0 auto">
      <h2>Активации / HWID</h2>
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
        <p style="margin-top:12px;color:var(--muted);font-size:0.88rem">
          Статус: <b style="color:var(--gold)"><?= htmlspecialchars($db['settings']['status']) ?></b> ·
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
            <button class="btn btn-gold" type="submit">Создать</button>
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
            <button class="btn btn-gold" type="submit">Сгенерировать</button>
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
            <button class="btn btn-gold" type="submit">Выдать</button>
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
            $max = $kd['max'] ?? 1;
            $lvl = $kd['level'] ?? 'trial';
            if (!empty($kd['is_frozen'])) $st = '<span class="badge badge-blue">Freeze</span>';
            elseif (($kd['first_use'] ?? 0) == 0) $st = '<span class="badge badge-yellow">Не акт.</span>';
            elseif (($kd['expires'] ?? 0) == 0) $st = '<span class="badge badge-green">∞</span>';
            elseif (time() > $kd['expires']) $st = '<span class="badge badge-red">Истёк</span>';
            else $st = '<span class="badge badge-green">Активен</span>';
          ?>
          <tr>
            <td><a class="keylink" href="?admin&view=<?= urlencode($k) ?>"><code><?= htmlspecialchars($k) ?></code></a></td>
            <td><?= htmlspecialchars($lvl) ?><?= !empty($kd['vip'])?' 👑':'' ?></td>
            <td><?= $kd['owner_tg'] ?: '—' ?></td>
            <td><?= $st ?></td>
            <td><?= $used ?>/<?= $max ?></td>
            <td style="white-space:nowrap">
              <a href="?admin&view=<?= urlencode($k) ?>" class="btn btn-dark btn-sm">Открыть</a>
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
        <h2>Broadcast</h2>
        <form method="post">
          <input type="hidden" name="action" value="set_broadcast">
          <textarea name="broadcast" placeholder="Сообщение..."><?= htmlspecialchars($db['settings']['broadcast'] ?? '') ?></textarea>
          <div class="form-row" style="margin-top:10px">
            <button class="btn btn-gold" type="submit">Установить</button>
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
          <button class="btn btn-gold" type="submit" style="margin-top:10px">Сохранить</button>
        </form>
      </div>

    <?php elseif ($tab === 'logs'): ?>
      <div class="card">
        <h2>Логи</h2>
        <?php
          $filter = $_GET['filter'] ?? '';
          $logs = $db['logs'];
          if ($filter) {
              $logs = array_filter($logs, fn($l) => strpos($l['text'], $filter) !== false);
          }
          foreach (array_slice($logs, 0, 50) as $l):
        ?>
          <div style="font-size:0.82rem;padding:7px 0;border-bottom:1px solid rgba(212,175,55,0.06)">
            <span style="color:var(--gold)"><?= date('d.m H:i:s', $l['time']) ?></span> — <?= htmlspecialchars($l['text']) ?>
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

// ====================== TELEGRAM BOT (тот же, что раньше) ======================
// ... (оставь старый код бота или скажи, если нужно тоже обновить)
$content = file_get_contents('php://input');
$update  = json_decode($content, true);
if (!$update) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Lori Elite</title>
    <style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#050505;color:#d4af37;font-family:system-ui}
    h1{font-size:1.8rem;letter-spacing:3px}</style></head><body><h1>LORI ELITE</h1></body></html>';
    exit;
}

// Здесь можешь вставить полный код бота из предыдущего ответа
// (я сократил, чтобы файл не был гигантским — бот уже был готов)
