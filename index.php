<?php
error_reporting(0);
date_default_timezone_set('Europe/Moscow');

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
);
if (!$isHttps && php_sapi_name() !== 'cli') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$botToken = getenv('BOT_TOKEN') ?: '8883380357:AAHrYtiqhcCTBvllozb5m4pMUQIw922a0Oo';
$adminId  = (int)(getenv('ADMIN_ID') ?: 8875180956);
$adminPass = 'LoriElite';

$dbFile = __DIR__ . '/database.json';
$db = file_exists($dbFile) ? json_decode(file_get_contents($dbFile), true) : [];
if (!is_array($db)) $db = [];

// Гарантируем структуру — существующие ключи НЕ трогаем
foreach (['keys', 'blacklist', 'logs', 'online'] as $k) {
    if (!isset($db[$k]) || !is_array($db[$k])) $db[$k] = [];
}
if (!isset($db['settings']) || !is_array($db['settings'])) {
    $db['settings'] = [];
}
$db['settings'] = array_merge([
    'status' => 'online',
    'soft_status' => 'undetected',
    'global_freeze' => false,
    'version' => '2.1.0',
    'checksum' => '5c8714cf5eb4010c2cad5b14ac476f3f3f695d26',
    'download_url' => 'https://example.com/script.lua',
    'emergency_msg' => '',
    'broadcast' => '',
    'panel_bg' => '',
    'panel_accent' => '#22c55e',
    'prices' => [
        '1h'  => ['stars' => 10,  'hours' => 1],
        '24h' => ['stars' => 25,  'hours' => 24],
        '3d'  => ['stars' => 50,  'hours' => 72],
        '7d'  => ['stars' => 75,  'hours' => 168],
        '30d' => ['stars' => 125, 'hours' => 720],
        '90d' => ['stars' => 250, 'hours' => 2160],
        'life'=> ['stars' => 400, 'hours' => 0]
    ]
], $db['settings']);

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
        echo json_encode(['status'=>'detected','message'=>'Софт временно недоступен']); exit;
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
    if ($db['settings']['status'] === 'maintenance') { echo 'Сервер на обслуживании'; exit; }
    if ($db['settings']['status'] === 'killswitch') { echo $db['settings']['emergency_msg'] ?: 'Софт остановлен'; exit; }
    if (!empty($db['settings']['global_freeze'])) { echo 'Все ключи заморожены'; exit; }
    if (($db['settings']['soft_status'] ?? '') === 'detected') { echo 'Софт недоступен'; exit; }
    if (empty($key))  { echo 'Укажите ключ'; exit; }
    if (empty($hwid)) { echo 'HWID не передан'; exit; }
    if (isset($db['blacklist'][$ip]) || isset($db['blacklist'][$hwid])) { echo 'Заблокировано'; exit; }
    if (!isset($db['keys'][$key])) { echo 'Неверный ключ'; exit; }
    $kd = $db['keys'][$key];
    $now = time();
    if (!empty($kd['is_frozen'])) { echo 'Ключ заморожен'; exit; }
    if (($kd['expires'] ?? 0) !== 0 && $now > $kd['expires']) {
        unset($db['keys'][$key]); saveDb(); echo 'Срок истёк'; exit;
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
        addLog("Активация $key | ".substr($hwid,0,12)."... | $ip");
        echo 'SUCCESS';
    } else {
        echo 'Лимит устройств';
    }
    exit;
}

// ====================== АДМИНКА ======================
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
        ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lori</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#050505;font-family:Inter,system-ui;color:#e5e5e5}
.box{width:100%;max-width:360px;padding:48px 36px;background:#0c0c0c;border:1px solid #1a1a1a;border-radius:16px}
.logo{text-align:center;margin-bottom:36px}
.logo span{font-size:13px;letter-spacing:6px;color:#22c55e;font-weight:500}
h1{font-size:22px;font-weight:600;text-align:center;margin-bottom:8px;color:#fff}
.sub{text-align:center;font-size:12px;color:#555;margin-bottom:32px}
input{width:100%;padding:14px 16px;background:#111;border:1px solid #222;border-radius:10px;color:#fff;font-size:14px;outline:none;margin-bottom:12px}
input:focus{border-color:#22c55e}
button{width:100%;padding:14px;background:#22c55e;border:none;border-radius:10px;color:#000;font-size:14px;font-weight:600;cursor:pointer;margin-top:8px}
button:hover{background:#16a34a}
.err{color:#ef4444;font-size:13px;text-align:center;margin-bottom:12px}
</style>
</head>
<body>
<div class="box">
  <div class="logo"><span>LORI</span></div>
  <h1>Control Panel</h1>
  <div class="sub">Private access only</div>
  <?php if (!empty($loginError)): ?><div class="err"><?= $loginError ?></div><?php endif; ?>
  <form method="post">
    <input type="password" name="password" placeholder="Password" required autofocus>
    <button type="submit">Enter</button>
  </form>
</div>
</body>
</html>
        <?php
        exit;
    }

    $msg = '';
    $tab = $_GET['tab'] ?? 'dashboard';
    $viewKey = $_GET['view'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $act = $_POST['action'];
        $k = $_POST['key'] ?? '';

        // Создание именного ключа
        if ($act === 'gen_key') {
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level']??'', ['trial','free','media','premium','elite']) ? $_POST['level'] : 'premium';
            $customName = trim($_POST['custom_name'] ?? '');
            $duration = $hours === 0 ? 0 : $hours * 3600;

            if ($customName !== '') {
                // Именной ключ — используем точное название
                $newKey = $customName;
                if (isset($db['keys'][$newKey])) {
                    $msg = "Ключ с таким именем уже существует";
                } else {
                    $db['keys'][$newKey] = [
                        'duration' => $duration,
                        'expires' => 0,
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
                        'vip' => false,
                        'bg' => '',
                        'named' => true
                    ];
                    saveDb();
                    addLog("Создан именной ключ: $newKey");
                    $msg = "Именной ключ создан: <b>$newKey</b>";
                }
            } else {
                $prefix = strtoupper($level);
                $newKey = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                $db['keys'][$newKey] = [
                    'duration' => $duration,
                    'expires' => 0,
                    'first_use' => 0,
                    'max' => $max,
                    'activations' => [],
                    'owner_tg' => 0,
                    'owner_name' => '',
                    'reset_left' => 3,
                    'is_frozen' => false,
                    'level' => $level,
                    'created' => time(),
                    'warns' => 0,
                    'note' => '',
                    'vip' => false,
                    'bg' => '',
                    'named' => false
                ];
                saveDb();
                addLog("Создан ключ: $newKey");
                $msg = "Ключ создан: <b>$newKey</b>";
            }
        }

        if ($act === 'bulk_generate') {
            $count = max(1, min(50, (int)($_POST['count'] ?? 10)));
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level']??'', ['trial','free','media','premium','elite']) ? $_POST['level'] : 'premium';
            $duration = $hours === 0 ? 0 : $hours * 3600;
            $list = [];
            for ($i = 0; $i < $count; $i++) {
                $newKey = strtoupper($level) . '-' . strtoupper(substr(md5(uniqid(mt_rand().$i, true)), 0, 8));
                $db['keys'][$newKey] = [
                    'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                    'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>2,
                    'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
                    'note'=>'','vip'=>false,'bg'=>'','named'=>false
                ];
                $list[] = $newKey;
            }
            saveDb();
            addLog("Массовая генерация: $count");
            $msg = "Создано <b>$count</b>:<br><code>".implode('<br>',$list)."</code>";
        }

        if ($act === 'give_key') {
            $tgId = (int)($_POST['tg_id'] ?? 0);
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level']??'', ['trial','free','media','premium','elite']) ? $_POST['level'] : 'premium';
            $customName = trim($_POST['custom_name'] ?? '');
            if ($tgId > 0) {
                $duration = $hours === 0 ? 0 : $hours * 3600;
                if ($customName !== '') {
                    $newKey = $customName;
                    if (isset($db['keys'][$newKey])) {
                        $msg = "Имя уже занято";
                    } else {
                        $db['keys'][$newKey] = [
                            'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                            'activations'=>[],'owner_tg'=>$tgId,'owner_name'=>$customName,'reset_left'=>3,
                            'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
                            'note'=>'','vip'=>false,'bg'=>'','named'=>true
                        ];
                        saveDb();
                        addLog("Выдан именной $newKey → $tgId");
                        @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$tgId&text=".urlencode("Вам выдан ключ:\n$newKey"));
                        $msg = "Выдан: <b>$newKey</b> → $tgId";
                    }
                } else {
                    $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                    $db['keys'][$newKey] = [
                        'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                        'activations'=>[],'owner_tg'=>$tgId,'owner_name'=>'','reset_left'=>3,
                        'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
                        'note'=>'','vip'=>false,'bg'=>'','named'=>false
                    ];
                    saveDb();
                    addLog("Выдан $newKey → $tgId");
                    @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$tgId&text=".urlencode("Вам выдан ключ:\n$newKey"));
                    $msg = "Выдан: <b>$newKey</b> → $tgId";
                }
            } else $msg = "Укажите Telegram ID";
        }

        // Действия с ключом
        if ($act === 'freeze_key' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['is_frozen'] = empty($db['keys'][$k]['is_frozen']);
            saveDb();
            $msg = !empty($db['keys'][$k]['is_frozen']) ? "Ключ заморожен" : "Ключ разморожен";
        }
        if ($act === 'reset_hwid' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['activations'] = [];
            saveDb(); addLog("Сброс HWID: $k");
            $msg = "HWID сброшены";
        }
        if ($act === 'delete_key' && $k) {
            unset($db['keys'][$k]); saveDb(); addLog("Удалён: $k");
            header('Location: ?admin&tab=keys'); exit;
        }
        if ($act === 'add_warn' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['warns'] = min(3, ($db['keys'][$k]['warns']??0)+1);
            saveDb(); $msg = "Предупреждение выдано (".$db['keys'][$k]['warns']."/3)";
        }
        if ($act === 'reset_warns' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['warns'] = 0; saveDb(); $msg = "Предупреждения сброшены";
        }
        if ($act === 'regen_key' && $k && isset($db['keys'][$k])) {
            $old = $db['keys'][$k];
            if (!empty($old['named'])) {
                $msg = "Именной ключ нельзя перегенерировать";
            } else {
                $level = $old['level'] ?? 'premium';
                $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                $db['keys'][$newKey] = $old;
                $db['keys'][$newKey]['activations'] = [];
                unset($db['keys'][$k]);
                saveDb(); addLog("Перегенерация $k → $newKey");
                header('Location: ?admin&view='.urlencode($newKey)); exit;
            }
        }
        if ($act === 'set_nick' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['owner_name'] = trim($_POST['nick']??'');
            saveDb(); $msg = "Имя обновлено";
        }
        if ($act === 'set_note' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['note'] = trim($_POST['note']??'');
            saveDb(); $msg = "Заметка сохранена";
        }
        if ($act === 'toggle_vip' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['vip'] = empty($db['keys'][$k]['vip']);
            saveDb(); $msg = !empty($db['keys'][$k]['vip']) ? "VIP включён" : "VIP выключен";
        }
        if ($act === 'extend_key' && $k && isset($db['keys'][$k])) {
            $days = max(1, (int)($_POST['days']??7));
            if (($db['keys'][$k]['expires']??0)==0) {
                $db['keys'][$k]['expires'] = time() + $days*86400;
            } else {
                $db['keys'][$k]['expires'] += $days*86400;
            }
            saveDb(); $msg = "Продлено на $days дней";
        }
        if ($act === 'set_key_bg' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['bg'] = trim($_POST['bg']??'');
            saveDb(); $msg = "Фон ключа обновлён";
        }
        if ($act === 'set_panel_bg') {
            $db['settings']['panel_bg'] = trim($_POST['panel_bg']??'');
            $db['settings']['panel_accent'] = trim($_POST['panel_accent']??'#22c55e');
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $db['settings']['panel_accent'])) {
                $db['settings']['panel_accent'] = '#22c55e';
            }
            saveDb();
            $msg = "Тема обновлена";
        }
        if ($act === 'transfer_key' && $k && isset($db['keys'][$k])) {
            $newTg = (int)($_POST['new_tg']??0);
            if ($newTg > 0) {
                $db['keys'][$k]['owner_tg'] = $newTg;
                saveDb(); addLog("Передан $k → $newTg");
                $msg = "Ключ передан пользователю $newTg";
            }
        }
        if ($act === 'set_max' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['max'] = max(1, (int)($_POST['max']??1));
            saveDb(); $msg = "Лимит устройств обновлён";
        }
        if ($act === 'set_duration' && $k && isset($db['keys'][$k])) {
            $hours = (int)($_POST['hours']??0);
            $db['keys'][$k]['duration'] = $hours === 0 ? 0 : $hours * 3600;
            if (($db['keys'][$k]['first_use']??0) > 0 && $hours > 0) {
                $db['keys'][$k]['expires'] = $db['keys'][$k]['first_use'] + $db['keys'][$k]['duration'];
            } elseif ($hours === 0) {
                $db['keys'][$k]['expires'] = 0;
            }
            saveDb(); $msg = "Срок обновлён";
        }
        if ($act === 'ban_hwid' && $k && isset($db['keys'][$k])) {
            $hwidToBan = trim($_POST['hwid_ban']??'');
            if ($hwidToBan !== '') {
                $db['blacklist'][$hwidToBan] = ['time'=>time(),'reason'=>"Banned from key $k"];
                // убрать из активаций
                $db['keys'][$k]['activations'] = array_values(array_filter($db['keys'][$k]['activations']??[], function($a) use ($hwidToBan) {
                    return ($a['hwid']??'') !== $hwidToBan;
                }));
                saveDb(); addLog("HWID $hwidToBan забанен с ключа $k");
                $msg = "HWID забанен";
            }
        }
        if ($act === 'clear_activations' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['activations'] = [];
            saveDb(); $msg = "Все активации очищены";
        }

        // Глобальные
        if ($act === 'toggle_global_freeze') {
            $db['settings']['global_freeze'] = empty($db['settings']['global_freeze']);
            saveDb(); $msg = !empty($db['settings']['global_freeze']) ? "Global freeze включён" : "Global freeze выключен";
        }
        if ($act === 'set_status') {
            $db['settings']['status'] = $_POST['status'] ?? 'online';
            if ($db['settings']['status'] !== 'killswitch') $db['settings']['emergency_msg'] = '';
            saveDb(); $msg = "Статус: ".$db['settings']['status'];
        }
        if ($act === 'set_soft_status') {
            $db['settings']['soft_status'] = $_POST['soft_status'] ?? 'undetected';
            saveDb(); $msg = "Soft: ".$db['settings']['soft_status'];
        }
        if ($act === 'set_broadcast') {
            $db['settings']['broadcast'] = trim($_POST['broadcast']??'');
            saveDb(); $msg = $db['settings']['broadcast'] !== '' ? "Broadcast установлен" : "Broadcast очищен";
        }
        if ($act === 'add_blacklist') {
            $val = trim($_POST['value']??'');
            if ($val !== '') {
                $db['blacklist'][$val] = ['time'=>time(),'reason'=>trim($_POST['reason']??'')];
                saveDb(); addLog("В ЧС: $val"); $msg = "Добавлено в ЧС";
            }
        }
        if ($act === 'remove_blacklist' && !empty($_POST['value'])) {
            unset($db['blacklist'][$_POST['value']]); saveDb(); $msg = "Удалено из ЧС";
        }
        if ($act === 'save_settings') {
            $db['settings']['version'] = trim($_POST['version']??$db['settings']['version']);
            $db['settings']['checksum'] = trim($_POST['checksum']??$db['settings']['checksum']);
            $db['settings']['download_url'] = trim($_POST['download_url']??$db['settings']['download_url']);
            $db['settings']['emergency_msg'] = trim($_POST['emergency_msg']??'');
            saveDb(); $msg = "Настройки сохранены";
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

    $accent = $db['settings']['panel_accent'] ?? '#22c55e';
    $panelBg = $db['settings']['panel_bg'] ?? '';

    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lori</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');
:root {
  --bg: #050505;
  --card: #0c0c0c;
  --border: #1a1a1a;
  --accent: <?= htmlspecialchars($accent) ?>;
  --text: #e5e5e5;
  --muted: #666;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px}
<?php if ($panelBg): ?>
body{background-image:url('<?= htmlspecialchars($panelBg) ?>');background-size:cover;background-attachment:fixed;background-position:center}
.overlay{position:fixed;inset:0;background:rgba(5,5,5,0.88);z-index:-1;pointer-events:none}
<?php endif; ?>
.header{background:#0a0a0a;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.header h1{font-size:14px;color:var(--accent);letter-spacing:4px;font-weight:600}
.header a{color:var(--muted);text-decoration:none;margin-left:16px;font-size:13px}
.header a:hover{color:var(--accent)}
.layout{display:flex;max-width:1280px;margin:0 auto}
.sidebar{width:180px;padding:16px 10px;border-right:1px solid var(--border);min-height:calc(100vh - 49px)}
.sidebar a{display:block;padding:10px 12px;border-radius:8px;color:var(--muted);text-decoration:none;margin-bottom:2px;font-size:13px}
.sidebar a:hover,.sidebar a.active{background:#111;color:var(--accent)}
.content{flex:1;padding:20px 16px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-bottom:20px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center}
.stat .num{font-size:22px;font-weight:600;color:var(--accent)}
.stat .label{font-size:11px;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:0.5px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:16px}
.card h2{font-size:13px;color:var(--accent);margin-bottom:14px;letter-spacing:0.5px;font-weight:600}
.btn{display:inline-block;padding:8px 14px;border-radius:8px;border:none;font-weight:500;cursor:pointer;font-size:13px;text-decoration:none}
.btn-accent{background:var(--accent);color:#000}
.btn-dark{background:#151515;border:1px solid var(--border);color:var(--text)}
.btn-red{background:#7f1d1d;color:#fff}
.btn-sm{padding:5px 10px;font-size:12px}
.form-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;align-items:center}
input,select,textarea{padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:#111;color:#fff;font-size:13px;outline:none}
input:focus,select:focus,textarea
