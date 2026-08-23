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
$adminPass = 'LoriElite'; // новый пароль

if (empty($botToken) || empty($adminId)) {
    http_response_code(500);
    die('Server misconfigured');
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
        'version' => '2.0.0',
        'checksum' => '5c8714cf5eb4010c2cad5b14ac476f3f3f695d26',
        'download_url' => 'https://example.com/script.lua',
        'emergency_msg' => '',
        'broadcast' => '',
        'bg_url' => '',
        'accent' => '#22c55e',
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
    if (count($db['logs']) > 400) array_pop($db['logs']);
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
        $db['online'][$hwid] = ['ip'=>$ip,'key'=>$key?:'—','last_ping'=>time(),'first_seen'=>$db['online'][$hwid]['first_seen']??time()];
        saveDb();
    }
    echo json_encode([
        'status'=>$db['settings']['status'],
        'soft_status'=>$db['settings']['soft_status']??'undetected',
        'version'=>$db['settings']['version'],
        'checksum'=>$db['settings']['checksum'],
        'url'=>$db['settings']['download_url'],
        'emergency_msg'=>$db['settings']['emergency_msg']??'',
        'broadcast'=>$db['settings']['broadcast']??'',
        'global_freeze'=>!empty($db['settings']['global_freeze'])
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
    if (isset($db['blacklist'][$ip]) || isset($db['blacklist'][$hwid])) { echo 'Заблокировано!'; exit; }
    if (!isset($db['keys'][$key])) { echo 'Неверный ключ!'; exit; }
    $kd = $db['keys'][$key];
    $now = time();
    if (!empty($kd['is_frozen'])) { echo 'Ключ заморожен!'; exit; }
    if (($kd['expires'] ?? 0) !== 0 && $now > $kd['expires']) {
        unset($db['keys'][$key]); saveDb(); echo 'Срок действия истёк!'; exit;
    }
    $acts = $kd['activations'] ?? [];
    $max  = (int)($kd['max'] ?? 1);
    foreach ($acts as &$a) {
        if (($a['hwid'] ?? '') === $hwid) {
            $a['ip'] = $ip; $a['last_active'] = $now; $a['launches'] = ($a['launches'] ?? 0) + 1;
            saveDb(); echo 'SUCCESS'; exit;
        }
    }
    unset($a);
    if (count($acts) < $max) {
        if (($kd['first_use'] ?? 0) == 0) {
            $db['keys'][$key]['first_use'] = $now;
            if (($kd['duration'] ?? 0) > 0) $db['keys'][$key]['expires'] = $now + $kd['duration'];
        }
        $db['keys'][$key]['activations'][] = ['hwid'=>$hwid,'ip'=>$ip,'time'=>$now,'last_active'=>$now,'launches'=>1];
        saveDb();
        addLog("Ключ $key активирован | HWID: ".substr($hwid,0,12)."... | IP: $ip");
        echo 'SUCCESS';
    } else echo 'Превышен лимит устройств!';
    exit;
}

// ====================== ВЕБ-АДМИНКА ======================
session_start();
if (isset($_GET['admin'])) {
    if (isset($_GET['logout'])) { session_destroy(); header('Location: ?admin'); exit; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $adminPass) {
            $_SESSION['admin'] = true;
            header('Location: ?admin'); exit;
        }
        $loginError = 'Неверный пароль';
    }

    if (empty($_SESSION['admin'])) {
        header('Content-Type: text/html; charset=utf-8');
        $bg = !empty($db['settings']['bg_url']) ? "background:url('".htmlspecialchars($db['settings']['bg_url'])."') center/cover fixed;" : "background:#030303;";
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Lori Elite</title>
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            body{'.$bg.' min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui;color:#fff}
            .box{background:rgba(8,8,10,0.85);backdrop-filter:blur(24px);padding:48px 40px;border-radius:28px;border:1px solid rgba(34,197,94,0.2);width:90%;max-width:400px;text-align:center;box-shadow:0 0 100px rgba(34,197,94,0.08)}
            h1{font-size:1.6rem;letter-spacing:4px;color:#22c55e;margin-bottom:6px;font-weight:600}
            .sub{font-size:0.75rem;color:#666;margin-bottom:28px;letter-spacing:2px}
            input{width:100%;padding:16px;border-radius:14px;border:1px solid rgba(34,197,94,0.25);background:rgba(0,0,0,0.5);color:#fff;font-size:15px;margin-bottom:16px}
            button{width:100%;padding:16px;border:none;border-radius:14px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#000;font-size:15px;font-weight:700;cursor:pointer;letter-spacing:1px}
            .err{color:#f87171;margin-bottom:12px;font-size:0.9rem}
        </style></head><body>
        <div class="box">
            <h1>LORI ELITE</h1>
            <div class="sub">PRIVATE ACCESS</div>
            '.(!empty($loginError)?'<div class="err">'.$loginError.'</div>':'').'
            <form method="post">
                <input type="password" name="password" placeholder="Пароль" required autofocus>
                <button type="submit">ВОЙТИ</button>
            </form>
        </div></body></html>';
        exit;
    }

    $msg = '';
    $tab = $_GET['tab'] ?? 'dashboard';
    $viewKey = $_GET['view'] ?? '';

    // ===== ОБРАБОТКА POST =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $act = $_POST['action'];
        $k = $_POST['key'] ?? '';

        if ($act === 'gen_key') {
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level']??'', ['trial','free','media','premium']) ? $_POST['level'] : 'premium';
            $customName = trim($_POST['custom_name'] ?? '');
            $prefix = getPrefixByLevel($level);
            $duration = $hours === 0 ? 0 : $hours * 3600;
            $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $db['keys'][$newKey] = [
                'duration' => $duration,
                'expires' => $duration > 0 ? time() + $duration : 0,
                'first_use' => 0,
                'max' => $max,
                'activations' => [],
                'owner_tg' => 0,
                'owner_name' => $customName,
                'reset_left' => 3,
                'is_frozen' => false,
                'level' => $level,
                'created' => time(),
                'warns' => 0,
                'note' => '',
                'vip' => false
            ];
            saveDb();
            addLog("Создан $newKey ($level)" . ($customName ? " [$customName]" : ''));
            $msg = "✅ Ключ создан: <b>$newKey</b>";
            if ($customName) $msg .= " · именной: <b>$customName</b>";
        }

        if ($act === 'bulk_generate') {
            $count = max(1, min(100, (int)($_POST['count'] ?? 10)));
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level']??'', ['trial','free','media','premium']) ? $_POST['level'] : 'trial';
            $prefix = getPrefixByLevel($level);
            $duration = $hours === 0 ? 0 : $hours * 3600;
            $list = [];
            for ($i = 0; $i < $count; $i++) {
                $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand().$i, true)), 0, 8));
                $db['keys'][$newKey] = [
                    'duration'=>$duration,'expires'=>$duration>0?time()+$duration:0,'first_use'=>0,'max'=>$max,
                    'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>2,
                    'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,'note'=>'','vip'=>false
                ];
                $list[] = $newKey;
            }
            saveDb();
            addLog("Bulk: $count ключей ($level)");
            $msg = "✅ Создано <b>$count</b>:<br><code style='font-size:0.8rem'>".implode('<br>',$list)."</code>";
        }

        if ($act === 'give_key') {
            $tgId = (int)($_POST['tg_id'] ?? 0);
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level']??'', ['trial','free','media','premium']) ? $_POST['level'] : 'premium';
            $customName = trim($_POST['custom_name'] ?? '');
            if ($tgId > 0) {
                $prefix = getPrefixByLevel($level);
                $duration = $hours === 0 ? 0 : $hours * 3600;
                $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                $db['keys'][$newKey] = [
                    'duration'=>$duration,'expires'=>$duration>0?time()+$duration:0,'first_use'=>0,'max'=>$max,
                    'activations'=>[],'owner_tg'=>$tgId,'owner_name'=>$customName,'reset_left'=>3,
                    'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,'note'=>'','vip'=>false
                ];
                saveDb();
                addLog("Выдан $newKey → $tgId");
                $opts = ['http'=>['header'=>"Content-Type: application/json\r\n",'method'=>'POST',
                    'content'=>json_encode(['chat_id'=>$tgId,'text'=>"🎁 Вам выдан ключ:\n`$newKey`\nТип: $level",'parse_mode'=>'Markdown'],JSON_UNESCAPED_UNICODE)]];
                @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage",false,stream_context_create($opts));
                $msg = "✅ <b>$newKey</b> → <b>$tgId</b>";
            } else $msg = "❌ Укажите Telegram ID";
        }

        // Карточка
        if ($act === 'freeze_key' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['is_frozen'] = empty($db['keys'][$k]['is_frozen']);
            saveDb();
            $msg = !empty($db['keys'][$k]['is_frozen']) ? "🔒 Заблокирован" : "🔓 Разблокирован";
        }
        if ($act === 'reset_hwid' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['activations'] = [];
            saveDb(); addLog("Сброс HWID у $k");
            $msg = "🔄 HWID сброшены";
        }
        if ($act === 'delete_key' && $k) {
            unset($db['keys'][$k]); saveDb(); addLog("Удалён $k");
            header('Location: ?admin&tab=keys'); exit;
        }
        if ($act === 'add_warn' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['warns'] = min(3, ($db['keys'][$k]['warns']??0)+1);
            saveDb(); $msg = "⚠ Варн ({$db['keys'][$k]['warns']}/3)";
        }
        if ($act === 'reset_warns' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['warns'] = 0; saveDb(); $msg = "✅ Варны сброшены";
        }
        if ($act === 'regen_key' && $k && isset($db['keys'][$k])) {
            $old = $db['keys'][$k];
            $level = $old['level'] ?? 'premium';
            $prefix = getPrefixByLevel($level);
            $newKey = $prefix.'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
            $db['keys'][$newKey] = $old;
            $db['keys'][$newKey]['activations'] = [];
            unset($db['keys'][$k]);
            saveDb(); addLog("Перегенерация $k → $newKey");
            header('Location: ?admin&view='.urlencode($newKey)); exit;
        }
        if ($act === 'set_nick' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['owner_name'] = trim($_POST['nick']??'');
            saveDb(); $msg = "✅ Ник обновлён";
        }
        if ($act === 'set_note' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['note'] = trim($_POST['note']??'');
            saveDb(); $msg = "✅ Заметка сохранена";
        }
        if ($act === 'toggle_vip' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['vip'] = empty($db['keys'][$k]['vip']);
            saveDb(); $msg = !empty($db['keys'][$k]['vip']) ? "👑 VIP ON" : "VIP OFF";
        }
        if ($act === 'extend_key' && $k && isset($db['keys'][$k])) {
            $days = max(1,(int)($_POST['days']??7));
            if (($db['keys'][$k]['expires']??0)==0) $db['keys'][$k]['expires'] = time()+$days*86400;
            else $db['keys'][$k]['expires'] += $days*86400;
            saveDb(); $msg = "⏱ +$days дней";
        }
        if ($act === 'set_max' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['max'] = max(1,(int)($_POST['max']??1));
            saveDb(); $msg = "Устройств: ".$db['keys'][$k]['max'];
        }
        if ($act === 'transfer' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['owner_tg'] = (int)($_POST['tg']??0);
            saveDb(); $msg = "Владелец изменён";
        }

        if ($act === 'toggle_global_freeze') {
            $db['settings']['global_freeze'] = empty($db['settings']['global_freeze']);
            saveDb(); $msg = !empty($db['settings']['global_freeze'])?'❄️ Global freeze ON':'🔓 Global freeze OFF';
        }
        if ($act === 'set_status') {
            $db['settings']['status'] = $_POST['status']??'online';
            if ($db['settings']['status']!=='killswitch') $db['settings']['emergency_msg']='';
            saveDb(); $msg = "Статус: ".$db['settings']['status'];
        }
        if ($act === 'set_soft_status') {
            $db['settings']['soft_status'] = $_POST['soft_status']??'undetected';
            saveDb(); $msg = "Soft: ".$db['settings']['soft_status'];
        }
        if ($act === 'set_broadcast') {
            $db['settings']['broadcast'] = trim($_POST['broadcast']??'');
            saveDb(); $msg = $db['settings']['broadcast']!==''?'📢 Broadcast OK':'📢 Очищено';
        }
        if ($act === 'add_blacklist') {
            $val = trim($_POST['value']??'');
            if ($val!=='') {
                $db['blacklist'][$val] = ['time'=>time(),'reason'=>trim($_POST['reason']??'')];
                saveDb(); addLog("В ЧС: $val"); $msg = "🚫 $val";
            }
        }
        if ($act === 'remove_blacklist' && !empty($_POST['value'])) {
            unset($db['blacklist'][$_POST['value']]); saveDb(); $msg = "✅ Удалено";
        }
        if ($act === 'save_settings') {
            $db['settings']['version'] = trim($_POST['version']??$db['settings']['version']);
            $db['settings']['checksum'] = trim($_POST['checksum']??$db['settings']['checksum']);
            $db['settings']['download_url'] = trim($_POST['download_url']??$db['settings']['download_url']);
            $db['settings']['emergency_msg'] = trim($_POST['emergency_msg']??'');
            $db['settings']['bg_url'] = trim($_POST['bg_url']??'');
            $db['settings']['accent'] = trim($_POST['accent']??'#22c55e');
            saveDb(); $msg = "⚙️ Сохранено";
        }
    }

    $totalKeys = count($db['keys']);
    $onlineCount = count($db['online']);
    $active = $frozen = $expired = 0;
    foreach ($db['keys'] as $kd) {
        if (!empty($kd['is_frozen'])) $frozen++;
        elseif (($kd['expires']??0)==0 || time()<($kd['expires']??0)) $active++;
        else $expired++;
    }

    $accent = $db['settings']['accent'] ?? '#22c55e';
    $bgUrl = $db['settings']['bg_url'] ?? '';
    $bgCss = $bgUrl ? "background:url('".htmlspecialchars($bgUrl)."') center/cover fixed;" : "background:#030303;";

    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lori Elite</title>
<style>
:root{
  --accent: <?= $accent ?>;
  --bg: #030303;
  --card: rgba(12,12,14,0.92);
  --border: color-mix(in srgb, var(--accent) 25%, transparent);
  --text: #f0f0f0;
  --muted: #777;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,sans-serif;<?= $bgCss ?> color:var(--text);min-height:100vh}
.header{background:rgba(8,8,10,0.9);backdrop-filter:blur(20px);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.header h1{font-size:1.15rem;color:var(--accent);letter-spacing:3px;font-weight:600}
.header a{color:var(--muted);text-decoration:none;margin-left:16px;font-size:0.85rem}
.header a:hover{color:var(--accent)}
.layout{display:flex;max-width:1400px;margin:0 auto}
.sidebar{width:200px;padding:18px 12px;border-right:1px solid var(--border);min-height:calc(100vh - 55px);background:rgba(8,8,10,0.6);backdrop-filter:blur(12px)}
.sidebar a{display:block;padding:11px 14px;border-radius:12px;color:var(--muted);text-decoration:none;margin-bottom:3px;font-size:0.9rem;transition:.2s}
.sidebar a:hover,.sidebar a.active{background:color-mix(in srgb,var(--accent) 12%,transparent);color:var(--accent)}
.content{flex:1;padding:22px 18px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(115px,1fr));gap:12px;margin-bottom:20px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;text-align:center;backdrop-filter:blur(10px)}
.stat .num{font-size:1.45rem;font-weight:700;color:var(--accent)}
.stat .label{font-size:0.7rem;color:var(--muted);margin-top:3px;text-transform:uppercase;letter-spacing:0.5px}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:20px;margin-bottom:18px;backdrop-filter:blur(12px)}
.card h2{font-size:1.05rem;color:var(--accent);margin-bottom:14px;letter-spacing:0.5px}
.btn{display:inline-block;padding:9px 16px;border-radius:11px;border:none;font-weight:600;cursor:pointer;font-size:0.85rem;text-decoration:none;transition:.2s}
.btn-accent{background:var(--accent);color:#000}
.btn-dark{background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--text)}
.btn-red{background:linear-gradient(135deg,#b91c1c,#7f1d1d);color:#fff}
.btn-green{background:linear-gradient(135deg,#15803d,#166534);color:#fff}
.btn-sm{padding:6px 11px;font-size:0.75rem}
.form-row{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:11px;align-items:center}
input,select,textarea{padding:9px 13px;border-radius:10px;border:1px solid var(--border);background:rgba(0,0,0,0.4);color:#fff;font-size:0.88rem}
textarea{width:100%;min-height:60px}
table{width:100%;border-collapse:collapse;font-size:0.84rem}
th,td{padding:9px 7px;text-align:left;border-bottom:1px solid color-mix(in srgb,var(--accent) 8%,transparent);vertical-align:top}
th{color:var(--accent);font-weight:600}
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:0.7rem;font-weight:600}
.badge-green{background:rgba(34,197,94,0.15);color:#4ade80}
.badge-red{background:rgba(239,68,68,0.15);color:#f87171}
.badge-yellow{background:rgba(234,179,8,0.15);color:#facc15}
.badge-blue{background:rgba(59,130,246,0.15);color:#60a5fa}
.msg{background:color-mix(in srgb,var(--accent) 10%,transparent);border:1px solid var(--accent);padding:12px 16px;border-radius:12px;margin-bottom:18px;color:#e0e0e0;font-size:0.9rem}
a.keylink{color:var(--accent);text-decoration:none}
a.keylink:hover{text-decoration:underline}

/* ===== ELITE KEY CARD (точно как на фото + элитнее) ===== */
.elite-card{
  background:rgba(10,10,12,0.95);
  border:1px solid color-mix(in srgb,var(--accent) 22%,transparent);
  border-radius:22px;
  max-width:420px;
  margin:0 auto 24px;
  overflow:hidden;
  box-shadow:0 0 80px color-mix(in srgb,var(--accent) 8%,transparent),0 30px 60px rgba(0,0,0,0.6);
  backdrop-filter:blur(20px);
  position:relative;
}
.elite-card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--accent),transparent);opacity:0.5;
}
.elite-header{padding:18px 20px 14px;display:flex;gap:14px;align-items:flex-start;border-bottom:1px solid rgba(255,255,255,0.04)}
.circle-wrap{width:58px;height:58px;flex-shrink:0;position:relative}
.circle-bg{
  width:58px;height:58px;border-radius:50%;
  background:conic-gradient(from -90deg,var(--accent) var(--p),rgba(255,255,255,0.06) 0);
  display:flex;align-items:center;justify-content:center;
}
.circle-inner{width:46px;height:46px;border-radius:50%;background:#0c0c0e;display:flex;flex-direction:column;align-items:center;justify-content:center}
.circle-inner .num{font-size:1.1rem;font-weight:700;color:#fff;line-height:1}
.circle-inner .lbl{font-size:0.5rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px}
.elite-title{flex:1;min-width:0}
.elite-title .name{font-size:1.1rem;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.elite-title .tag{font-size:0.62rem;padding:2px 7px;border-radius:6px;background:color-mix(in srgb,var(--accent) 15%,transparent);color:var(--accent);border:1px solid color-mix(in srgb,var(--accent) 25%,transparent)}
.elite-title .vip{font-size:0.62rem;padding:2px 7px;border-radius:6px;background:linear-gradient(135deg,#d4af37,#b8860b);color:#000;font-weight:700}
.elite-meta{margin-top:5px;font-size:0.76rem;color:var(--muted);display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.status-dot{width:7px;height:7px;border-radius:50%;display:inline-block}
.elite-body{padding:12px 20px 6px}
.elite-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:0.82rem;border-bottom:1px solid rgba(255,255,255,0.03)}
.elite-row:last-child{border-bottom:none}
.elite-row .label{color:var(--muted)}
.elite-row .value{color:#e0e0e0;font-weight:500;text-align:right}
.elite-row .value.green{color:#4ade80}
.elite-row .value.red{color:#f87171}
.elite-row .value.accent{color:var(--accent)}
.elite-actions{padding:12px 14px 10px;display:grid;grid-template-columns:repeat(3,1fr);gap:7px}
.e-btn{
  background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:12px;
  padding:11px 4px;color:#bbb;font-size:0.7rem;font-weight:500;cursor:pointer;transition:all .2s;
  display:flex;flex-direction:column;align-items:center;gap:4px;text-decoration:none;
}
.e-btn:hover{background:color-mix(in srgb,var(--accent) 12%,transparent);border-color:color-mix(in srgb,var(--accent) 35%,transparent);color:var(--accent);transform:translateY(-1px)}
.e-btn.danger:hover{background:rgba(239,68,68,0.12);border-color:rgba(239,68,68,0.35);color:#f87171}
.e-btn.success:hover{background:rgba(34,197,94,0.12);border-color:rgba(34,197,94,0.35);color:#4ade80}
.elite-footer{padding:0 14px 14px;display:grid;grid-template-columns:1fr 1fr;gap:7px}
.elite-note{padding:0 20px 16px}
.elite-note textarea{width:100%;background:rgba(0,0,0,0.4);border:1px solid var(--border);border-radius:10px;padding:10px;color:#ccc;font-size:0.8rem;resize:none}

@media(max-width:800px){.layout{flex-direction:column}.sidebar{width:100%;border-right:none;border-bottom:1px solid var(--border);display:flex;overflow-x:auto;gap:4px;padding:10px}.sidebar a{white-space:nowrap;margin:0}}
</style>
</head>
<body>
<div class="header">
  <h1>LORI ELITE</h1>
  <div>
    <a href="?admin&tab=<?=urlencode($tab)?>">Обновить</a>
    <a href="?admin&logout=1">Выйти</a>
  </div>
</div>
<div class="layout">
  <div class="sidebar">
    <a href="?admin&tab=dashboard" class="<?=$tab==='dashboard'?'active':''?>">📊 Дашборд</a>
    <a href="?admin&tab=keys" class="<?=$tab==='keys'?'active':''?>">🔑 Ключи</a>
    <a href="?admin&tab=generate" class="<?=$tab==='generate'?'active':''?>">➕ Создать</a>
    <a href="?admin&tab=bulk" class="<?=$tab==='bulk'?'active':''?>">📦 Массово</a>
    <a href="?admin&tab=give" class="<?=$tab==='give'?'active':''?>">🎁 Выдать</a>
    <a href="?admin&tab=online" class="<?=$tab==='online'?'active':''?>">🟢 Онлайн</a>
    <a href="?admin&tab=broadcast" class="<?=$tab==='broadcast'?'active':''?>">📢 Broadcast</a>
    <a href="?admin&tab=blacklist" class="<?=$tab==='blacklist'?'active':''?>">🚫 ЧС</a>
    <a href="?admin&tab=settings" class="<?=$tab==='settings'?'active':''?>">⚙️ Настройки</a>
    <a href="?admin&tab=logs" class="<?=$tab==='logs'?'active':''?>">📜 Логи</a>
  </div>
  <div class="content">
    <?php if($msg):?><div class="msg"><?=$msg?></div><?php endif;?>

    <?php if($viewKey && isset($db['keys'][$viewKey])):
      $kd = $db['keys'][$viewKey];
      $used = count($kd['activations']??[]);
      $max = $kd['max']??1;
      $warns = $kd['warns']??0;
      $ownerName = $kd['owner_name'] ?: ($kd['owner_tg'] ? 'ID '.$kd['owner_tg'] : '—');
      $isNamed = !empty($kd['owner_name']) || ($kd['owner_tg']??0)>0;
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
      if(($kd['expires']??0)>0){
        $left = $kd['expires']-$now;
        if($left<=0){$daysLeft='0';$expiresStr='истёк';$expiresClass='red';$circleP=0;}
        else{$daysLeft=(string)ceil($left/86400);$expiresStr=date('d-m-Y H:i',$kd['expires']);$circleP=min(100,max(4,($left/(30*86400))*100));}
      } elseif(($kd['duration']??0)>0){
        $daysLeft = (string)ceil($kd['duration']/86400);
        $expiresStr = 'после активации';
        $circleP = min(100,max(4,($kd['duration']/(30*86400))*100));
      }

      $status = 'свободен'; $statusColor = '#fbbf24';
      if(!empty($kd['is_frozen'])){$status='заморожен';$statusColor='#60a5fa';}
      elseif(($kd['first_use']??0)==0){$status='не активирован';$statusColor='#fbbf24';}
      elseif(($kd['expires']??0)>0 && $now>$kd['expires']){$status='истёк';$statusColor='#f87171';}
      else{$status='активен';$statusColor='#4ade80';}

      $mainHwid = '—';
      if(!empty($kd['activations'])) $mainHwid = substr($kd['activations'][0]['hwid']??'—',0,16).'…';
    ?>

    <div class="elite-card">
      <div class="elite-header">
        <div class="circle-wrap">
          <div class="circle-bg" style="--p:<?=$circleP?>%">
            <div class="circle-inner">
              <div class="num"><?=$daysLeft?></div>
              <div class="lbl">дней</div>
            </div>
          </div>
        </div>
        <div class="elite-title">
          <div class="name">
            <?=htmlspecialchars($viewKey)?>
            <span class="tag"><?=$namedTag?></span>
            <?php if($isVip):?><span class="vip">VIP</span><?php endif;?>
          </div>
          <div class="elite-meta">
            <span>📡 <?=$tgId?></span>
            <span><span class="status-dot" style="background:<?=$statusColor?>"></span> <?=$status?></span>
            <span>🔑 вход <?=$used?></span>
          </div>
        </div>
      </div>

      <div class="elite-body">
        <div class="elite-row"><span class="label">Действует до</span><span class="value <?=$expiresClass?>"><?=$expiresStr?></span></div>
        <div class="elite-row"><span class="label">Владелец</span><span class="value"><?=htmlspecialchars($ownerName)?> · <?=$namedTag?></span></div>
        <div class="elite-row"><span class="label">Telegram ID</span><span class="value"><?=$tgId?></span></div>
        <div class="elite-row"><span class="label">Android ID</span><span class="value"><?=htmlspecialchars($android)?></span></div>
        <div class="elite-row"><span class="label">HWID</span><span class="value" style="font-size:0.74rem"><?=htmlspecialchars($mainHwid)?></span></div>
        <div class="elite-row"><span class="label">Входов</span><span class="value"><?=$used?> / <?=$max?></span></div>
        <div class="elite-row"><span class="label">Предупреждения</span><span class="value <?=$warns>0?'red':''?>"><?=$warns?> / 3</span></div>
        <div class="elite-row"><span class="label">Уровень</span><span class="value accent"><?=strtoupper($kd['level']??'—')?></span></div>
      </div>

      <div class="elite-actions">
        <button class="e-btn" onclick="navigator.clipboard.writeText('<?=htmlspecialchars($viewKey)?>');this.innerHTML='✓<br>Скопировано';setTimeout(()=>this.innerHTML='📋<br>Копировать',1500)">📋<br>Копировать</button>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="set_nick"><input type="hidden" name="key" value="<?=htmlspecialchars($viewKey)?>">
          <button class="e-btn" type="button" onclick="let n=prompt('Новый ник:','<?=htmlspecialchars($kd['owner_name']??'')?>');if(n!==null){this.form.nick.value=n;this.form.submit()}">
            <input type="hidden" name="nick" value="">👤<br>Ник
          </button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="add_warn"><input type="hidden" name="key" value="<?=htmlspecialchars($viewKey)?>">
          <button class="e-btn" type="submit">⚠<br>Варн <?=$warns?>/3</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="reset_warns"><input type="hidden" name="key" value="<?=htmlspecialchars($viewKey)?>">
          <button class="e-btn" type="submit">🔄<br>Сброс варнов</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="key" value="<?=htmlspecialchars($viewKey)?>">
          <button class="e-btn" type="submit">🖥<br>Сброс HWID</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="regen_key"><input type="hidden" name="key" value="<?=htmlspecialchars($viewKey)?>">
          <button class="e-btn" type="submit" onclick="return confirm('Перегенерировать?')">🔁<br>Перегенер.</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="freeze_key"><input type="hidden" name="key" value="<?=htmlspecialchars($viewKey)?>">
          <button class="e-btn <?=!empty($kd['is_frozen'])?'success':''?>" type="submit"><?=!empty($kd['is_frozen'])?'🔓<br>Разблок':'🔒<br>Блок'?></button>
        </form>

        <a href="?admin&tab=logs&filter=<?=urlencode($viewKey)?>" class="e-btn">📜<br>Логи</a>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="toggle_vip"><input type="hidden" name="key" value="<?=htmlspecialchars($viewKey)?>">
          <button class="e-btn" type="submit"><?=$isVip?'👑<br>VIP ✓':'👑<br>VIP'?></button>
        </form>
      </div>

      <div class="elite-footer">
        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="extend_key"><input type="hidden" name="key" value="<?=htmlspecialchars($viewKey)?>"><input type="hidden" name="days" value="7">
          <button class="e-btn success" type="submit">⏱ +7 дней</button>
        </form>
        <form method="post" onsubmit="return confirm('Удалить навсегда?')" style="display:contents">
          <input type="hidden" name="action" value="delete_key"><input type="hidden" name="key" value="<?=htmlspecialchars($viewKey)?>">
          <button class="e-btn danger" type="submit">🗑 Удалить</button>
        </form>
      </div>

      <div class="elite-note">
        <form method="post">
          <input type="hidden" name="action" value="set_note"><input type="hidden" name="key" value="<?=htmlspecialchars($viewKey)?>">
          <textarea name="note" rows="2" placeholder="Приватная заметка..."><?=htmlspecialchars($note)?></textarea>
          <button class="btn btn-dark btn-sm" type="submit" style="margin-top:6px">Сохранить заметку</button>
        </form>
      </div>
    </div>

    <div style="text-align:center;margin-bottom:20px">
      <a href="?admin&tab=keys" class="btn btn-dark">← Назад к списку</a>
    </div>

    <div class="card" style="max-width:700px;margin:0 auto">
      <h2>Активации / HWID</h2>
      <?php if(empty($kd['activations'])):?>
        <p style="color:var(--muted)">Нет активаций</p>
      <?php else:?>
        <table>
          <tr><th>HWID</th><th>IP</th><th>Активация</th><th>Последний</th><th>Запусков</th></tr>
          <?php foreach($kd['activations'] as $a):?>
          <tr>
            <td><code style="font-size:0.75rem"><?=htmlspecialchars($a['hwid']??'')?></code></td>
            <td><?=htmlspecialchars($a['ip']??'')?></td>
            <td><?=!empty($a['time'])?date('d.m H:i',$a['time']):'—'?></td>
            <td><?=!empty($a['last_active'])?date('d.m H:i',$a['last_active']):'—'?></td>
            <td><?=$a['launches']??1?></td>
          </tr>
          <?php endforeach;?>
        </table>
      <?php endif;?>
    </div>

    <?php elseif($tab==='dashboard'):?>
      <div class="stats">
        <div class="stat"><div class="num"><?=$totalKeys?></div><div class="label">Ключей</div></div>
        <div class="stat"><div class="num"><?=$active?></div><div class="label">Активных</div></div>
        <div class="stat"><div class="num"><?=$onlineCount?></div><div class="label">Онлайн</div></div>
        <div class="stat"><div class="num"><?=$frozen?></div><div class="label">Заморожено</div></div>
        <div class="stat"><div class="num"><?=$expired?></div><div class="label">Истекло</div></div>
        <div class="stat"><div class="num"><?=count($db['blacklist'])?></div><div class="label">ЧС</div></div>
      </div>
      <div class="card">
        <h2>Быстрые действия</h2>
        <div class="form-row">
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="online"><button class="btn btn-green" type="submit">Online</button></form>
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="maintenance"><button class="btn btn-dark" type="submit">Maintenance</button></form>
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="killswitch"><button class="btn btn-red" type="submit">Killswitch</button></form>
          <form method="post"><input type="hidden" name="action" value="toggle_global_freeze"><button class="btn btn-dark" type="submit"><?=!empty($db['settings']['global_freeze'])?'Снять freeze':'Global freeze'?></button></form>
        </div>
        <div class="form-row">
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="undetected"><button class="btn btn-green btn-sm" type="submit">Undetected</button></form>
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="updating"><button class="btn btn-dark btn-sm" type="submit">Updating</button></form>
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="detected"><button class="btn btn-red btn-sm" type="submit">Detected</button></form>
        </div>
        <p style="margin-top:12px;color:var(--muted);font-size:0.88rem">
          Статус: <b style="color:var(--accent)"><?=htmlspecialchars($db['settings']['status'])?></b> ·
          Soft: <b><?=htmlspecialchars($db['settings']['soft_status']??'undetected')?></b> ·
          Freeze: <b><?=!empty($db['settings']['global_freeze'])?'ДА':'нет'?></b>
        </p>
      </div>

    <?php elseif($tab==='generate'):?>
      <div class="card">
        <h2>Создать ключ</h2>
        <form method="post">
          <input type="hidden" name="action" value="gen_key">
          <div class="form-row">
            <input type="number" name="hours" value="24" placeholder="Часов (0=∞)" style="width:130px">
            <input type="number" name="max" value="1" min="1" style="width:80px" placeholder="Устройств">
            <select name="level">
              <option value="premium">Premium</option>
              <option value="media">Media</option>
              <option value="free">Free</option>
              <option value="trial">Trial</option>
            </select>
          </div>
          <div class="form-row">
            <input type="text" name="custom_name" placeholder="Имя (именной ключ)" style="width:220px">
            <button class="btn btn-accent" type="submit">Создать</button>
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

    <?php elseif($tab==='bulk'):?>
      <div class="card">
        <h2>Массовая генерация</h2>
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
            <button class="btn btn-accent" type="submit">Сгенерировать</button>
          </div>
        </form>
      </div>

    <?php elseif($tab==='give'):?>
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
          </div>
          <div class="form-row">
            <input type="text" name="custom_name" placeholder="Имя (именной)" style="width:200px">
            <button class="btn btn-accent" type="submit">Выдать</button>
          </div>
        </form>
      </div>

    <?php elseif($tab==='keys'):?>
      <div class="card">
        <h2>Ключи (<?=$totalKeys?>)</h2>
        <input type="text" id="searchKey" placeholder="Поиск..." onkeyup="filterTable()" style="width:100%;max-width:300px;margin-bottom:12px">
        <div style="overflow-x:auto">
        <table id="keysTable">
          <tr><th>Ключ</th><th>Тип</th><th>Владелец</th><th>Статус</th><th>Устр.</th><th></th></tr>
          <?php foreach($db['keys'] as $k=>$kd):
            $used=count($kd['activations']??[]); $max=$kd['max']??1; $lvl=$kd['level']??'trial';
            if(!empty($kd['is_frozen'])) $st='<span class="badge badge-blue">Freeze</span>';
            elseif(($kd['first_use']??0)==0) $st='<span class="badge badge-yellow">Не акт.</span>';
            elseif(($kd['expires']??0)==0) $st='<span class="badge badge-green">∞</span>';
            elseif(time()>$kd['expires']) $st='<span class="badge badge-red">Истёк</span>';
            else $st='<span class="badge badge-green">Активен</span>';
          ?>
          <tr>
            <td><a class="keylink" href="?admin&view=<?=urlencode($k)?>"><code><?=htmlspecialchars($k)?></code></a></td>
            <td><?=htmlspecialchars($lvl)?><?=!empty($kd['vip'])?' 👑':''?></td>
            <td><?=$kd['owner_name']?:($kd['owner_tg']?:'—')?></td>
            <td><?=$st?></td>
            <td><?=$used?>/<?=$max?></td>
            <td><a href="?admin&view=<?=urlencode($k)?>" class="btn btn-dark btn-sm">Открыть</a></td>
          </tr>
          <?php endforeach;?>
        </table>
        </div>
      </div>

    <?php elseif($tab==='online'):?>
      <div class="card">
        <h2>Онлайн (<?=$onlineCount?>)</h2>
        <?php if(empty($db['online'])):?><p style="color:var(--muted)">Никого нет</p>
        <?php else:?>
          <table>
            <tr><th>Ключ</th><th>IP</th><th>HWID</th><th>Пинг</th><th>С</th></tr>
            <?php foreach($db['online'] as $hwid=>$info):?>
            <tr>
              <td><code><?=htmlspecialchars($info['key']??'—')?></code></td>
              <td><?=htmlspecialchars($info['ip']??'')?></td>
              <td style="font-size:0.75rem"><?=htmlspecialchars(substr($hwid,0,20))?>…</td>
              <td><?=time()-($info['last_ping']??0)?>с</td>
              <td style="font-size:0.78rem"><?=!empty($info['first_seen'])?date('H:i:s',$info['first_seen']):'—'?></td>
            </tr>
            <?php endforeach;?>
          </table>
        <?php endif;?>
      </div>

    <?php elseif($tab==='broadcast'):?>
      <div class="card">
        <h2>Broadcast</h2>
        <form method="post">
          <input type="hidden" name="action" value="set_broadcast">
          <textarea name="broadcast" placeholder="Сообщение..."><?=htmlspecialchars($db['settings']['broadcast']??'')?></textarea>
          <div class="form-row" style="margin-top:10px">
            <button class="btn btn-accent" type="submit">Установить</button>
            <button class="btn btn-dark" type="submit" onclick="this.form.broadcast.value=''">Очистить</button>
          </div>
        </form>
      </div>

    <?php elseif($tab==='blacklist'):?>
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
        <h2>ЧС (<?=count($db['blacklist'])?>)</h2>
        <?php if(empty($db['blacklist'])):?><p style="color:var(--muted)">Пусто</p>
        <?php else:?>
          <table>
            <tr><th>Значение</th><th>Причина</th><th>Дата</th><th></th></tr>
            <?php foreach($db['blacklist'] as $val=>$info):?>
            <tr>
              <td><code><?=htmlspecialchars($val)?></code></td>
              <td><?=htmlspecialchars($info['reason']??'')?></td>
              <td><?=date('d.m H:i',$info['time']??time())?></td>
              <td><form method="post"><input type="hidden" name="action" value="remove_blacklist"><input type="hidden" name="value" value="<?=htmlspecialchars($val)?>"><button class="btn btn-dark btn-sm" type="submit">×</button></form></td>
            </tr>
            <?php endforeach;?>
          </table>
        <?php endif;?>
      </div>

    <?php elseif($tab==='settings'):?>
      <div class="card">
        <h2>Настройки</h2>
        <form method="post">
          <input type="hidden" name="action" value="save_settings">
          <div class="form-row"><label style="width:140px">Версия</label><input type="text" name="version" value="<?=htmlspecialchars($db['settings']['version'])?>" style="width:160px"></div>
          <div class="form-row"><label style="width:140px">Checksum</label><input type="text" name="checksum" value="<?=htmlspecialchars($db['settings']['checksum'])?>" style="width:300px"></div>
          <div class="form-row"><label style="width:140px">Download URL</label><input type="text" name="download_url" value="<?=htmlspecialchars($db['settings']['download_url'])?>" style="width:300px"></div>
          <div class="form-row"><label style="width:140px">Фон (URL картинки)</label><input type="text" name="bg_url" value="<?=htmlspecialchars($db['settings']['bg_url']??'')?>" style="width:300px" placeholder="https://..."></div>
          <div class="form-row"><label style="width:140px">Акцент цвет</label><input type="color" name="accent" value="<?=htmlspecialchars($db['settings']['accent']??'#22c55e')?>" style="width:60px;height:36px;padding:2px"></div>
          <div class="form-row" style="align-items:flex-start"><label style="width:140px;margin-top:8px">Emergency</label><textarea name="emergency_msg"><?=htmlspecialchars($db['settings']['emergency_msg'])?></textarea></div>
          <button class="btn btn-accent" type="submit" style="margin-top:10px">Сохранить</button>
        </form>
      </div>

    <?php elseif($tab==='logs'):?>
      <div class="card">
        <h2>Логи</h2>
        <?php
          $filter = $_GET['filter']??'';
          $logs = $db['logs'];
          if($filter) $logs = array_filter($logs, fn($l)=>strpos($l['text'],$filter)!==false);
          foreach(array_slice($logs,0,50) as $l):
        ?>
          <div style="font-size:0.82rem;padding:7px 0;border-bottom:1px solid color-mix(in srgb,var(--accent) 6%,transparent)">
            <span style="color:var(--accent)"><?=date('d.m H:i:s',$l['time'])?></span> — <?=htmlspecialchars($l['text'])?>
          </div>
        <?php endforeach;?>
      </div>
    <?php endif;?>
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
$update = json_decode($content, true);

if (!$update) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Lori Elite</title>
    <style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#030303;color:#22c55e;font-family:system-ui}
    h1{font-size:1.8rem;letter-spacing:4px}</style></head><body><h1>LORI ELITE</h1></body></html>';
    exit;
}

function tgRequest($method, $data) {
    global $botToken;
    $opts = ['http'=>['header'=>"Content-Type: application/json\r\n",'method'=>'POST','content'=>json_encode($data,JSON_UNESCAPED_UNICODE),'ignore_errors'=>true]];
    return @file_get_contents("https://api.telegram.org/bot$botToken/$method", false, stream_context_create($opts));
}
function sendMessage($chat_id, $text, $kb=null, $parse='Markdown') {
    $d = ['chat_id'=>$chat_id,'text'=>$text,'parse_mode'=>$parse,'disable_web_page_preview'=>true];
    if($kb) $d['reply_markup']=$kb;
    return tgRequest('sendMessage', $d);
}
function editMessage($chat_id, $msg_id, $text, $kb=null, $parse='Markdown') {
    $d = ['chat_id'=>$chat_id,'message_id'=>$msg_id,'text'=>$text,'parse_mode'=>$parse,'disable_web_page_preview'=>true];
    if($kb) $d['reply_markup']=$kb;
    return tgRequest('editMessageText', $d);
}
function answerCallback($cq_id, $text='', $alert=false) {
    tgRequest('answerCallbackQuery', ['callback_query_id'=>$cq_id,'text'=>$text,'show_alert'=>$alert]);
}
function sendInvoice($chat_id, $title, $desc, $payload, $stars) {
    tgRequest('sendInvoice', [
        'chat_id'=>$chat_id,'title'=>$title,'description'=>$desc,
        'payload'=>$payload,'currency'=>'XTR',
        'prices'=>[['label'=>'Stars','amount'=>$stars]]
    ]);
}

function buildKeyCard($key) {
    global $db;
    if (!isset($db['keys'][$key])) return null;
    $kd = $db['keys'][$key];
    $ownerName = $kd['owner_name'] ?: ($kd['owner_tg'] ? 'ID '.$kd['owner_tg'] : '—');
    $isNamed = !empty($kd['owner_name']) || ($kd['owner_tg']??0)>0;
    $namedTag = $isNamed ? 'именной' : 'обычный';
    $now = time();
    $daysLeft = '∞';
    $expiresStr = 'навсегда';
    if (($kd['expires']??0)>0) {
        $left = $kd['expires']-$now;
        if ($left<=0) {$daysLeft='0';$expiresStr='истёк';}
        else {$daysLeft=(string)ceil($left/86400);$expiresStr=date('d-m-Y H:i',$kd['expires']);}
    } elseif (($kd['duration']??0)>0) {
        $daysLeft = (string)ceil($kd['duration']/86400);
        $expiresStr = 'после активации';
    }
    $status = 'свободен';
    if (!empty($kd['is_frozen'])) $status='заморожен';
    elseif (($kd['first_use']??0)==0) $status='не активирован';
    elseif (($kd['expires']??0)>0 && $now>$kd['expires']) $status='истёк';
    else $status='активен';
    $used = count($kd['activations']??[]);
    $max = $kd['max']??1;
    $warns = $kd['warns']??0;
    $tgId = $kd['owner_tg']?:'—';
    $android = $kd['android_id']??'не привязан';
    $mainHwid = '—';
    if (!empty($kd['activations'])) $mainHwid = substr($kd['activations'][0]['hwid']??'—',0,16).'…';

    $text = "🟢 *{$key}*  `{$namedTag}`\n\n";
    $text .= "┌ *{$daysLeft}* дней\n";
    $text .= "│ `{$key}`\n";
    $text .= "│ 📡 `{$tgId}`  ⚡ `{$status}`\n";
    $text .= "│ 🔑 вход {$used}\n└\n\n";
    $text .= "Действует до     `{$expiresStr}`\n";
    $text .= "Владелец          *{$ownerName}* · `{$namedTag}`\n";
    $text .= "Telegram ID       `{$tgId}`\n";
    $text .= "Android ID        `{$android}`\n";
    $text .= "HWID              `{$mainHwid}`\n";
    $text .= "Входов            `{$used}/{$max}`\n";
    $text .= "Предупреждения    `{$warns} / 3`\n";

    $kb = ['inline_keyboard'=>[
        [['text'=>'📋 Копировать','callback_data'=>'k_copy_'.$key],['text'=>'👤 Ник','callback_data'=>'k_nick_'.$key],['text'=>"⚠ Варн {$warns}/3",'callback_data'=>'k_warn_'.$key]],
        [['text'=>'🔄 Сброс варнов','callback_data'=>'k_rwarn_'.$key],['text'=>'🖥 Сброс HWID','callback_data'=>'k_rhwid_'.$key],['text'=>'🔁 Перегенер.','callback_data'=>'k_regen_'.$key]],
        [['text'=>!empty($kd['is_frozen'])?'🔓 Разблок':'🔒 Блок','callback_data'=>'k_freeze_'.$key],['text'=>'📜 Логи','callback_data'=>'k_logs_'.$key]],
        [['text'=>'🗑 Удалить','callback_data'=>'k_del_'.$key],['text'=>'« Назад','callback_data'=>'adm_keys']]
    ]];
    return [$text,$kb];
}

function adminMainKb() {
    return ['inline_keyboard'=>[
        [['text'=>'🔑 Ключи','callback_data'=>'adm_keys'],['text'=>'➕ Создать','callback_data'=>'adm_gen']],
        [['text'=>'📦 Массово','callback_data'=>'adm_bulk'],['text'=>'🎁 Выдать','callback_data'=>'adm_give']],
        [['text'=>'🟢 Онлайн','callback_data'=>'adm_online'],['text'=>'📊 Статистика','callback_data'=>'adm_stats']],
        [['text'=>'🚫 ЧС','callback_data'=>'adm_bl'],['text'=>'📢 Broadcast','callback_data'=>'adm_bc']],
        [['text'=>'⚙️ Настройки','callback_data'=>'adm_settings'],['text'=>'📜 Логи','callback_data'=>'adm_logs']],
        [['text'=>'🚨 Killswitch','callback_data'=>'toggle_kill'],['text'=>'❄️ Freeze','callback_data'=>'toggle_gfreeze']]
    ]];
}

if (isset($update['pre_checkout_query'])) {
    tgRequest('answerPreCheckoutQuery', ['pre_checkout_query_id'=>$update['pre_checkout_query']['id'],'ok'=>true]);
    exit;
}

if (isset($update['message']['successful_payment'])) {
    $chatId = $update['message']['chat']['id'];
    $payload = $update['message']['successful_payment']['invoice_payload'];
    $parts = explode('_', $payload);
    $duration = (int)($parts[1]??0);
    $maxLimit = (int)($parts[2]??1);
    $newKey = 'PREMIUM-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
    $db['keys'][$newKey] = [
        'duration'=>$duration,'expires'=>$duration>0?time()+$duration:0,'first_use'=>0,'max'=>$maxLimit,
        'activations'=>[],'owner_tg'=>$chatId,'owner_name'=>'','reset_left'=>2,
        'is_frozen'=>false,'level'=>'premium','created'=>time(),'warns'=>0,'note'=>'','vip'=>false
    ];
    saveDb();
    addLog("Куплен $newKey (Stars) юзером $chatId");
    sendMessage($chatId, "🎉 *Оплата прошла!*\n\nВаш ключ:\n`$newKey`");
    exit;
}

if (isset($update['message'])) {
    $chatId = (int)$update['message']['chat']['id'];
    $text = trim($update['message']['text']??'');
    $isAdmin = ($chatId === $adminId);

    if ($isAdmin) {
        if ($text === '/admin' || $text === '/panel') {
            $cnt = count($db['keys']); $onl = count($db['online']);
            sendMessage($chatId, "👑 *Админ-панель*\n\nКлючей: *{$cnt}*\nОнлайн: *{$onl}*\nСтатус: `{$db['settings']['status']}`", adminMainKb());
            exit;
        }
        if (strpos($text, '/gen ') === 0) {
            $args = explode(' ', $text);
            $hours = (int)($args[1]??24); $max = (int)($args[2]??1); $level = $args[3]??'premium';
            if (!in_array($level,['trial','free','media','premium'])) $level='premium';
            $duration = $hours===0?0:$hours*3600;
            $prefix = getPrefixByLevel($level);
            $newKey = $prefix.'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
            $db['keys'][$newKey] = [
                'duration'=>$duration,'expires'=>$duration>0?time()+$duration:0,'first_use'=>0,'max'=>$max,
                'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>999,
                'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,'note'=>'','vip'=>false
            ];
            saveDb(); addLog("Создан $newKey ($level)");
            sendMessage($chatId, "✅ Ключ: `$newKey`\nТип: *$level* · {$hours}ч");
            exit;
        }
        if (strpos($text, '/give ') === 0) {
            $args = explode(' ', $text);
            $target = (int)($args[1]??0); $hours = (int)($args[2]??24); $max = (int)($args[3]??1); $level = $args[4]??'premium';
            if (!in_array($level,['trial','free','media','premium'])) $level='premium';
            if ($target>0) {
                $duration = $hours===0?0:$hours*3600;
                $prefix = getPrefixByLevel($level);
                $newKey = $prefix.'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                $db['keys'][$newKey] = [
                    'duration'=>$duration,'expires'=>$duration>0?time()+$duration:0,'first_use'=>0,'max'=>$max,
                    'activations'=>[],'owner_tg'=>$target,'owner_name'=>'','reset_left'=>2,
                    'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,'note'=>'','vip'=>false
                ];
                saveDb(); addLog("Выдан $newKey → $target");
                sendMessage($chatId, "🎁 `$newKey` → `$target`");
                sendMessage($target, "🎁 Вам выдан ключ:\n`$newKey`\nТип: *$level*");
            }
            exit;
        }
        if (preg_match('/^\/(find|key|view)\s+(\S+)/i', $text, $m)) {
            $card = buildKeyCard($m[2]);
            if ($card) sendMessage($chatId, $card[0], $card[1]);
            else sendMessage($chatId, "❌ Ключ не найден");
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
        if ($isAdmin) $kb['inline_keyboard'][] = [['text'=>'👑 Админ-панель','callback_data'=>'admin_panel']];
        sendMessage($chatId, "👋 Добро пожаловать в **Lori Elite Store**!", $kb);
    }
}

if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $chatId = (int)$cq['message']['chat']['id'];
    $data = $cq['data'];
    $msgId = $cq['message']['message_id'];
    $cqId = $cq['id'];
    $isAdmin = ($chatId === $adminId);

    if (strpos($data,'buy_')===0) {
        $p = explode('_',$data);
        $starsMap = ['86400'=>25,'604800'=>75,'2592000'=>125,'0'=>400];
        $stars = $starsMap[$p[1]]??25;
        sendInvoice($chatId,'Ключ Lori Premium','Доступ Premium',"sub_{$p[1]}_{$p[2]}",$stars);
        answerCallback($cqId); exit;
    }

    if ($data==='my_keys') {
        $found=false;
        foreach ($db['keys'] as $k=>$kd) {
            if (($kd['owner_tg']??0)==$chatId) {
                $found=true;
                $used=count($kd['activations']??[]); $max=$kd['max']??1;
                if (!empty($kd['is_frozen'])) $st='❄️ Заморожен';
                elseif (($kd['first_use']??0)==0) $st='⏳ Не активирован';
                elseif (($kd['expires']??0)==0) $st='♾️ Навсегда';
                elseif (time()>$kd['expires']) $st='🔴 Истёк';
                else {$left=$kd['expires']-time(); $st='🟢 '.floor($left/86400).'д '.floor(($left%86400)/3600).'ч';}
                $kb=['inline_keyboard'=>[[['text'=>'🔄 Сбросить HWID','callback_data'=>'user_reset_'.$k]],[['text'=>'« Назад','callback_data'=>'back_home']]]];
                sendMessage($chatId,"🔑 `$k`\nТип: ".($kd['level']??'premium')."\n$st\nУстройства: $used/$max",$kb);
            }
        }
        if (!$found) sendMessage($chatId,'📭 У вас пока нет ключей.');
        answerCallback($cqId); exit;
    }

    if (strpos($data,'user_reset_')===0) {
        $key=str_replace('user_reset_','',$data);
        if (isset($db['keys'][$key]) && ($db['keys'][$key]['owner_tg']??0)==$chatId) {
            if (($db['keys'][$key]['reset_left']??0)>0) {
                $db['keys'][$key]['activations']=[]; $db['keys'][$key]['reset_left']--;
                saveDb(); answerCallback($cqId,'✅ HWID сброшены');
            } else answerCallback($cqId,'❌ Лимит сбросов закончился',true);
        }
        exit;
    }

    if ($data==='back_home') {
        $kb=['inline_keyboard'=>[
            [['text'=>'⏳ 24 часа — 25 ⭐','callback_data'=>'buy_86400_1']],
            [['text'=>'🔑 Мои ключи','callback_data'=>'my_keys']]
        ]];
        if ($isAdmin) $kb['inline_keyboard'][]=[['text'=>'👑 Админ','callback_data'=>'admin_panel']];
        editMessage($chatId,$msgId,'Главное меню',$kb);
        answerCallback($cqId); exit;
    }

    if (!$isAdmin) { answerCallback($cqId,'Нет доступа',true); exit; }

    if ($data==='admin_panel') {
        $cnt=count($db['keys']); $onl=count($db['online']);
        editMessage($chatId,$msgId,"👑 *Админ-панель*\n\nКлючей: *{$cnt}*\nОнлайн: *{$onl}*\nСтатус: `{$db['settings']['status']}`",adminMainKb());
        answerCallback($cqId); exit;
    }

    if ($data==='adm_keys' || strpos($data,'adm_keys_')===0) {
        $page=0; if(strpos($data,'adm_keys_')===0) $page=(int)str_replace('adm_keys_','',$data);
        $keys=array_keys($db['keys']); $perPage=8; $total=count($keys); $pages=max(1,ceil($total/$perPage));
        $slice=array_slice($keys,$page*$perPage,$perPage);
        $text="🔑 *Ключи* ({$total})\n\n";
        $kb=['inline_keyboard'=>[]];
        foreach($slice as $k){
            $kd=$db['keys'][$k];
            $st=!empty($kd['is_frozen'])?'❄️':((($kd['expires']??0)>0&&time()>$kd['expires'])?'🔴':'🟢');
            $kb['inline_keyboard'][]=[['text'=>"$st $k",'callback_data'=>'k_view_'.$k]];
        }
        $nav=[]; if($page>0)$nav[]=['text'=>'◀️','callback_data'=>'adm_keys_'.($page-1)];
        $nav[]=['text'=>($page+1)."/$pages",'callback_data'=>'noop'];
        if($page<$pages-1)$nav[]=['text'=>'▶️','callback_data'=>'adm_keys_'.($page+1)];
        if($nav)$kb['inline_keyboard'][]=$nav;
        $kb['inline_keyboard'][]=[['text'=>'« Назад','callback_data'=>'admin_panel']];
        editMessage($chatId,$msgId,$text,$kb); answerCallback($cqId); exit;
    }

    if (strpos($data,'k_view_')===0) {
        $key=str_replace('k_view_','',$data);
        $card=buildKeyCard($key);
        if($card) editMessage($chatId,$msgId,$card[0],$card[1]);
        else answerCallback($cqId,'Ключ не найден',true);
        answerCallback($cqId); exit;
    }

    if (strpos($data,'k_copy_')===0) { answerCallback($cqId,str_replace('k_copy_','',$data),true); exit; }
    if (strpos($data,'k_nick_')===0) { answerCallback($cqId,'Отправь: /nick '.str_replace('k_nick_','',$data).' Имя',true); exit; }

    if (strpos($data,'k_warn_')===0) {
        $key=str_replace('k_warn_','',$data);
        if(isset($db['keys'][$key])){$db['keys'][$key]['warns']=min(3,($db['keys'][$key]['warns']??0)+1);saveDb();$card=buildKeyCard($key);editMessage($chatId,$msgId,$card[0],$card[1]);answerCallback($cqId,'⚠ Варн');}
        exit;
    }
    if (strpos($data,'k_rwarn_')===0) {
        $key=str_replace('k_rwarn_','',$data);
        if(isset($db['keys'][$key])){$db['keys'][$key]['warns']=0;saveDb();$card=buildKeyCard($key);editMessage($chatId,$msgId,$card[0],$card[1]);answerCallback($cqId,'✅');}
        exit;
    }
    if (strpos($data,'k_rhwid_')===0) {
        $key=str_replace('k_rhwid_','',$data);
        if(isset($db['keys'][$key])){$db['keys'][$key]['activations']=[];saveDb();addLog("Сброс HWID у $key");$card=buildKeyCard($key);editMessage($chatId,$msgId,$card[0],$card[1]);answerCallback($cqId,'🔄');}
        exit;
    }
    if (strpos($data,'k_regen_')===0) {
        $key=str_replace('k_regen_','',$data);
        if(isset($db['keys'][$key])){
            $old=$db['keys'][$key]; $level=$old['level']??'premium'; $prefix=getPrefixByLevel($level);
            $newKey=$prefix.'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
            $db['keys'][$newKey]=$old; $db['keys'][$newKey]['activations']=[]; unset($db['keys'][$key]);
            saveDb(); addLog("Перегенерация $key → $newKey");
            $card=buildKeyCard($newKey); editMessage($chatId,$msgId,$card[0],$card[1]);
            answerCallback($cqId,"🔁 $newKey",true);
        }
        exit;
    }
    if (strpos($data,'k_freeze_')===0) {
        $key=str_replace('k_freeze_','',$data);
        if(isset($db['keys'][$key])){$db['keys'][$key]['is_frozen']=empty($db['keys'][$key]['is_frozen']);saveDb();$card=buildKeyCard($key);editMessage($chatId,$msgId,$card[0],$card[1]);answerCallback($cqId,!empty($db['keys'][$key]['is_frozen'])?'🔒':'🔓');}
        exit;
    }
    if (strpos($data,'k_logs_')===0) {
        $key=str_replace('k_logs_','',$data);
        $text="📜 *Логи* `$key`\n\n"; $found=0;
        foreach(array_slice($db['logs'],0,40) as $l) if(strpos($l['text'],$key)!==false){$text.='`'.date('d.m H:i',$l['time']).'` '.$l['text']."\n";$found++;}
        if(!$found)$text.='Нет записей';
        editMessage($chatId,$msgId,$text,['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'k_view_'.$key]]]]);
        answerCallback($cqId); exit;
    }
    if (strpos($data,'k_del_')===0) {
        $key=str_replace('k_del_','',$data); unset($db['keys'][$key]); saveDb(); addLog("Удалён $key");
        editMessage($chatId,$msgId,"🗑 `$key` удалён",['inline_keyboard'=>[[['text'=>'« К списку','callback_data'=>'adm_keys']]]]);
        answerCallback($cqId,'Удалён'); exit;
    }

    if ($data==='adm_gen') {
        $kb=['inline_keyboard'=>[
            [['text'=>'1ч TRIAL','callback_data'=>'do_gen_1_1_trial'],['text'=>'24ч TRIAL','callback_data'=>'do_gen_24_1_trial']],
            [['text'=>'7д PREMIUM','callback_data'=>'do_gen_168_1_premium'],['text'=>'30д PREMIUM','callback_data'=>'do_gen_720_1_premium']],
            [['text'=>'∞ PREMIUM','callback_data'=>'do_gen_0_1_premium']],
            [['text'=>'« Назад','callback_data'=>'admin_panel']]
        ]];
        editMessage($chatId,$msgId,"➕ *Создать ключ*\n`/gen часы max уровень`",$kb); answerCallback($cqId); exit;
    }
    if (strpos($data,'do_gen_')===0) {
        $p=explode('_',$data); $hours=(int)$p[2]; $max=(int)$p[3]; $level=$p[4]??'trial';
        $duration=$hours===0?0:$hours*3600; $prefix=getPrefixByLevel($level);
        $newKey=$prefix.'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
        $db['keys'][$newKey]=['duration'=>$duration,'expires'=>$duration>0?time()+$duration:0,'first_use'=>0,'max'=>$max,'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>999,'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,'note'=>'','vip'=>false];
        saveDb(); addLog("Создан $newKey ($level)");
        answerCallback($cqId,"✅ $newKey",true); $card=buildKeyCard($newKey); editMessage($chatId,$msgId,$card[0],$card[1]); exit;
    }

    if ($data==='adm_bulk') {
        $kb=['inline_keyboard'=>[
            [['text'=>'10 × 24ч TRIAL','callback_data'=>'do_bulk_10_24_trial']],
            [['text'=>'20 × 7д PREMIUM','callback_data'=>'do_bulk_20_168_premium']],
            [['text'=>'50 × ∞ PREMIUM','callback_data'=>'do_bulk_50_0_premium']],
            [['text'=>'« Назад','callback_data'=>'admin_panel']]
        ]];
        editMessage($chatId,$msgId,"📦 *Массовая генерация*",$kb); answerCallback($cqId); exit;
    }
    if (strpos($data,'do_bulk_')===0) {
        $p=explode('_',$data); $count=(int)$p[2]; $hours=(int)$p[3]; $level=$p[4]??'trial';
        $duration=$hours===0?0:$hours*3600; $prefix=getPrefixByLevel($level); $list=[];
        for($i=0;$i<$count;$i++){
            $newKey=$prefix.'-'.strtoupper(substr(md5(uniqid(mt_rand().$i,true)),0,8));
            $db['keys'][$newKey]=['duration'=>$duration,'expires'=>$duration>0?time()+$duration:0,'first_use'=>0,'max'=>1,'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>2,'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,'note'=>'','vip'=>false];
            $list[]=$newKey;
        }
        saveDb(); addLog("Bulk: $count ($level)");
        editMessage($chatId,$msgId,"✅ *$count*:\n`".implode("`\n`",$list)."`",['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]);
        answerCallback($cqId); exit;
    }

    if ($data==='adm_give') {
        editMessage($chatId,$msgId,"🎁 *Выдать*\n`/give TG_ID часы max уровень`",['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]); answerCallback($cqId); exit;
    }
    if ($data==='adm_online') {
        $text="🟢 *Онлайн* (".count($db['online']).")\n\n";
        if(empty($db['online'])) $text.='Никого нет';
        else foreach($db['online'] as $h=>$i) $text.="• `{$i['key']}` | {$i['ip']} | ".(time()-($i['last_ping']??0))."с\n";
        editMessage($chatId,$msgId,$text,['inline_keyboard'=>[[['text'=>'🔄','callback_data'=>'adm_online'],['text'=>'« Назад','callback_data'=>'admin_panel']]]]); answerCallback($cqId); exit;
    }
    if ($data==='adm_stats') {
        $a=$f=$e=0; foreach($db['keys'] as $kd){if(!empty($kd['is_frozen']))$f++;elseif(($kd['expires']??0)==0||time()<($kd['expires']??0))$a++;else$e++;}
        $text="📊 *Статистика*\n\nВсего: ".count($db['keys'])."\nАктивных: $a\nЗаморожено: $f\nИстекло: $e\nОнлайн: ".count($db['online'])."\nЧС: ".count($db['blacklist']);
        editMessage($chatId,$msgId,$text,['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]); answerCallback($cqId); exit;
    }
    if ($data==='adm_bl') {
        $text="🚫 *ЧС* (".count($db['blacklist']).")\n\n";
        if(empty($db['blacklist'])) $text.='Пусто'; else foreach($db['blacklist'] as $val=>$info) $text.="• `$val` — ".($info['reason']??'')."\n";
        $text.="\n`/ban IP_или_HWID причина`";
        editMessage($chatId,$msgId,$text,['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]); answerCallback($cqId); exit;
    }
    if ($data==='adm_bc') {
        $cur=$db['settings']['broadcast']??'';
        editMessage($chatId,$msgId,"📢 *Broadcast*\n\nТекущий:\n".($cur?:'— пусто —')."\n\n`/bc текст`\n`/bc clear`",['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]); answerCallback($cqId); exit;
    }
    if ($data==='adm_settings') {
        $s=$db['settings'];
        $text="⚙️ *Настройки*\n\nСтатус: `{$s['status']}`\nSoft: `".($s['soft_status']??'undetected')."`\nFreeze: `".(!empty($s['global_freeze'])?'ДА':'нет')."`\nВерсия: `{$s['version']}`";
        editMessage($chatId,$msgId,$text,['inline_keyboard'=>[[['text'=>'« Назад','callback_data'=>'admin_panel']]]]); answerCallback($cqId); exit;
    }
    if ($data==='adm_logs') {
        $text="📜 *Логи*\n\n"; foreach(array_slice($db['logs'],0,15) as $l) $text.='`'.date('d.m H:i',$l['time']).'` '.$l['text']."\n";
        editMessage($chatId,$msgId,$text,['inline_keyboard'=>[[['text'=>'🔄','callback_data'=>'adm_logs'],['text'=>'« Назад','callback_data'=>'admin_panel']]]]); answerCallback($cqId); exit;
    }
    if ($data==='toggle_kill') {
        if($db['settings']['status']==='killswitch'){$db['settings']['status']='online';$db['settings']['emergency_msg']='';answerCallback($cqId,'🟢 OFF');}
        else{$db['settings']['status']='killswitch';$db['settings']['emergency_msg']='🚨 Софт остановлен!';answerCallback($cqId,'🚨 ON');}
        saveDb(); exit;
    }
    if ($data==='toggle_gfreeze') {
        $db['settings']['global_freeze']=empty($db['settings']['global_freeze']); saveDb();
        answerCallback($cqId,!empty($db['settings']['global_freeze'])?'❄️ ON':'🔓 OFF'); exit;
    }
    if ($data==='noop') { answerCallback($cqId); exit; }
}

// Доп. команды админа
if (isset($update['message']) && (int)$update['message']['chat']['id']===$adminId) {
    $text=trim($update['message']['text']??''); $chatId=$adminId;
    if (strpos($text,'/nick ')===0) {
        $parts=explode(' ',$text,3);
        if(count($parts)>=3 && isset($db['keys'][$parts[1]])){$db['keys'][$parts[1]]['owner_name']=$parts[2];saveDb();sendMessage($chatId,"✅ Ник `{$parts[1]}` → *{$parts[2]}*");}
        exit;
    }
    if (strpos($text,'/ban ')===0) {
        $parts=explode(' ',$text,3); $val=$parts[1]??''; $reason=$parts[2]??'';
        if($val){$db['blacklist'][$val]=['time'=>time(),'reason'=>$reason];saveDb();addLog("В ЧС: $val");sendMessage($chatId,"🚫 `$val`");}
        exit;
    }
    if (strpos($text,'/bc ')===0) {
        $msg=trim(substr($text,4));
        if($msg==='clear'){$db['settings']['broadcast']='';sendMessage($chatId,'📢 Очищено');}
        else{$db['settings']['broadcast']=$msg;sendMessage($chatId,'📢 Установлен');}
        saveDb(); exit;
    }
}
