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
input:focus,select:focus,textarea:focus{border-color:var(--accent)}
textarea{width:100%;min-height:60px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px 6px;text-align:left;border-bottom:1px solid #151515}
th{color:var(--accent);font-weight:500;font-size:12px}
.badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:500}
.badge-green{background:rgba(34,197,94,0.12);color:#4ade80}
.badge-red{background:rgba(239,68,68,0.12);color:#f87171}
.badge-yellow{background:rgba(234,179,8,0.12);color:#facc15}
.badge-blue{background:rgba(59,130,246,0.12);color:#60a5fa}
.msg{background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);padding:12px 14px;border-radius:10px;margin-bottom:16px;color:#86efac;font-size:13px}
a.keylink{color:#86efac;text-decoration:none}
a.keylink:hover{text-decoration:underline}

/* KEY CARD — точная копия стиля скрина */
.key-panel{
  background:#0a0a0a;
  border:1px solid #1a1a1a;
  border-radius:16px;
  max-width:380px;
  margin:0 auto 20px;
  overflow:hidden;
}
.key-panel .top{
  padding:16px 16px 12px;
  display:flex;
  gap:12px;
  align-items:flex-start;
  border-bottom:1px solid #151515;
}
.days-circle{
  width:52px;height:52px;border-radius:50%;
  background:conic-gradient(from -90deg, var(--accent) var(--p), #1a1a1a 0);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.days-inner{
  width:40px;height:40px;border-radius:50%;background:#0a0a0a;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
}
.days-inner .n{font-size:15px;font-weight:600;color:#fff;line-height:1}
.days-inner .l{font-size:9px;color:#555;text-transform:uppercase;margin-top:1px}
.key-info{flex:1;min-width:0}
.key-info .title{font-size:15px;font-weight:600;color:#fff;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.key-info .title .tag{font-size:10px;padding:2px 6px;border-radius:4px;background:rgba(34,197,94,0.1);color:#4ade80;border:1px solid rgba(34,197,94,0.15)}
.key-info .title .vip{font-size:10px;padding:2px 6px;border-radius:4px;background:var(--accent);color:#000;font-weight:600}
.key-info .meta{margin-top:4px;font-size:12px;color:#555;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.key-info .meta .dot{width:6px;height:6px;border-radius:50%;display:inline-block}

.key-details{padding:10px 16px 4px}
.key-details .row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px;border-bottom:1px solid #121212}
.key-details .row:last-child{border-bottom:none}
.key-details .row .lbl{color:#555}
.key-details .row .val{color:#ccc;font-weight:500;text-align:right}
.key-details .row .val.g{color:#4ade80}
.key-details .row .val.r{color:#f87171}

.key-btns{padding:10px 12px 14px;display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.kbtn{
  background:#111;border:1px solid #1a1a1a;border-radius:8px;
  padding:10px 4px;color:#888;font-size:11px;font-weight:500;
  cursor:pointer;transition:all .15s;text-align:center;text-decoration:none;
  display:flex;flex-direction:column;align-items:center;gap:3px;
}
.kbtn:hover{background:#151515;border-color:#222;color:#ccc}
.kbtn .ico{font-size:14px;opacity:0.7}

.key-extra{padding:0 12px 12px;display:grid;grid-template-columns:1fr 1fr;gap:6px}
.key-note{padding:0 16px 14px}

@media(max-width:800px){
  .layout{flex-direction:column}
  .sidebar{width:100%;border-right:none;border-bottom:1px solid var(--border);display:flex;overflow-x:auto;gap:4px;padding:8px}
  .sidebar a{white-space:nowrap;margin:0}
}
</style>
</head>
<body>
<?php if ($panelBg): ?><div class="overlay"></div><?php endif; ?>
<div class="header">
  <h1>LORI</h1>
  <div>
    <a href="?admin&tab=<?= urlencode($tab) ?>">Refresh</a>
    <a href="?admin&logout=1">Exit</a>
  </div>
</div>
<div class="layout">
  <div class="sidebar">
    <a href="?admin&tab=dashboard" class="<?= $tab==='dashboard'?'active':'' ?>">Dashboard</a>
    <a href="?admin&tab=keys" class="<?= $tab==='keys'?'active':'' ?>">Keys</a>
    <a href="?admin&tab=generate" class="<?= $tab==='generate'?'active':'' ?>">Create</a>
    <a href="?admin&tab=bulk" class="<?= $tab==='bulk'?'active':'' ?>">Bulk</a>
    <a href="?admin&tab=give" class="<?= $tab==='give'?'active':'' ?>">Give</a>
    <a href="?admin&tab=online" class="<?= $tab==='online'?'active':'' ?>">Online</a>
    <a href="?admin&tab=broadcast" class="<?= $tab==='broadcast'?'active':'' ?>">Broadcast</a>
    <a href="?admin&tab=blacklist" class="<?= $tab==='blacklist'?'active':'' ?>">Blacklist</a>
    <a href="?admin&tab=settings" class="<?= $tab==='settings'?'active':'' ?>">Settings</a>
    <a href="?admin&tab=logs" class="<?= $tab==='logs'?'active':'' ?>">Logs</a>
    <a href="?admin&tab=theme" class="<?= $tab==='theme'?'active':'' ?>">Theme</a>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="msg"><?= $msg ?></div><?php endif; ?>

    <?php if ($viewKey && isset($db['keys'][$viewKey])):
      $kd = $db['keys'][$viewKey];
      $used = count($kd['activations'] ?? []);
      $max = $kd['max'] ?? 1;
      $warns = $kd['warns'] ?? 0;
      $ownerName = $kd['owner_name'] ?: ($kd['owner_tg'] ? 'ID '.$kd['owner_tg'] : '—');
      $isNamed = !empty($kd['named']) || !empty($kd['owner_name']);
      $namedTag = $isNamed ? 'именной' : 'обычный';
      $tgId = $kd['owner_tg'] ?: '—';
      $android = $kd['android_id'] ?? 'не привязан';
      $isVip = !empty($kd['vip']);
      $note = $kd['note'] ?? '';
      $keyBg = $kd['bg'] ?? '';

      $now = time();
      $daysLeft = '∞';
      $expiresStr = 'навсегда';
      $expiresClass = 'g';
      $circleP = 100;
      if (($kd['expires'] ?? 0) > 0) {
          $left = $kd['expires'] - $now;
          if ($left <= 0) {
              $daysLeft = '0'; $expiresStr = 'истёк'; $expiresClass = 'r'; $circleP = 0;
          } else {
              $daysLeft = (string)ceil($left / 86400);
              $expiresStr = date('d-m-Y H:i', $kd['expires']);
              $circleP = min(100, max(5, ($left / (30*86400))*100));
          }
      } elseif (($kd['duration'] ?? 0) > 0 && ($kd['first_use'] ?? 0) == 0) {
          $daysLeft = (string)ceil($kd['duration'] / 86400);
          $expiresStr = 'после активации';
          $circleP = min(100, max(5, ($kd['duration']/(30*86400))*100));
      }

      $status = 'свободен';
      $statusColor = '#fbbf24';
      if (!empty($kd['is_frozen'])) { $status = 'заморожен'; $statusColor = '#60a5fa'; }
      elseif (($kd['first_use'] ?? 0) == 0) { $status = 'не активирован'; $statusColor = '#fbbf24'; }
      elseif (($kd['expires'] ?? 0) > 0 && $now > $kd['expires']) { $status = 'истёк'; $statusColor = '#f87171'; }
      else { $status = 'активен'; $statusColor = '#4ade80'; }

      $mainHwid = '—';
      if (!empty($kd['activations'])) $mainHwid = substr($kd['activations'][0]['hwid'] ?? '—', 0, 16) . '…';
    ?>

    <div class="key-panel">
      <div class="top">
        <div class="days-circle" style="--p:<?= $circleP ?>%">
          <div class="days-inner">
            <div class="n"><?= $daysLeft ?></div>
            <div class="l">дней</div>
          </div>
        </div>
        <div class="key-info">
          <div class="title">
            <?= htmlspecialchars($viewKey) ?>
            <span class="tag"><?= $namedTag ?></span>
            <?php if ($isVip): ?><span class="vip">VIP</span><?php endif; ?>
          </div>
          <div class="meta">
            <span><?= $tgId ?></span>
            <span><span class="dot" style="background:<?= $statusColor ?>"></span> <?= $status ?></span>
            <span>вход <?= $used ?></span>
          </div>
        </div>
      </div>

      <div class="key-details">
        <div class="row"><span class="lbl">Действует до</span><span class="val <?= $expiresClass ?>"><?= $expiresStr ?></span></div>
        <div class="row"><span class="lbl">Владелец</span><span class="val"><?= htmlspecialchars($ownerName) ?> · <?= $namedTag ?></span></div>
        <div class="row"><span class="lbl">Telegram ID</span><span class="val"><?= $tgId ?></span></div>
        <div class="row"><span class="lbl">Android ID</span><span class="val"><?= htmlspecialchars($android) ?></span></div>
        <div class="row"><span class="lbl">HWID</span><span class="val" style="font-size:11px"><?= htmlspecialchars($mainHwid) ?></span></div>
        <div class="row"><span class="lbl">Входов</span><span class="val"><?= $used ?> / <?= $max ?></span></div>
        <div class="row"><span class="lbl">Предупреждения</span><span class="val <?= $warns>0?'r':'' ?>"><?= $warns ?> / 3</span></div>
        <div class="row"><span class="lbl">Уровень</span><span class="val" style="color:var(--accent)"><?= strtoupper($kd['level'] ?? '—') ?></span></div>
      </div>

      <div class="key-btns">
        <button class="kbtn" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($viewKey) ?>');this.innerText='Скопировано';setTimeout(()=>this.innerText='Копировать',1200)">Копировать</button>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="set_nick"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="kbtn" type="button" onclick="let n=prompt('Имя владельца:','<?= htmlspecialchars($kd['owner_name']??'') ?>');if(n!==null){this.form.nick.value=n;this.form.submit()}">
            <input type="hidden" name="nick" value="">Ник
          </button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="add_warn"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="kbtn" type="submit">Варн <?= $warns ?>/3</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="reset_warns"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="kbtn" type="submit">Сброс варнов</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="kbtn" type="submit">Сброс HWID</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="regen_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="kbtn" type="submit" onclick="return confirm('Перегенерировать ключ?')">Перегенер.</button>
        </form>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="freeze_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="kbtn" type="submit"><?= !empty($kd['is_frozen']) ? 'Разблок' : 'Блок' ?></button>
        </form>

        <a href="?admin&tab=logs&filter=<?= urlencode($viewKey) ?>" class="kbtn">Логи</a>

        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="toggle_vip"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="kbtn" type="submit"><?= $isVip ? 'VIP ✓' : 'VIP' ?></button>
        </form>
      </div>

      <div class="key-extra">
        <form method="post" style="display:contents">
          <input type="hidden" name="action" value="extend_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="hidden" name="days" value="7">
          <button class="kbtn" type="submit" style="color:#4ade80">+7 дней</button>
        </form>
        <form method="post" onsubmit="return confirm('Удалить ключ?')" style="display:contents">
          <input type="hidden" name="action" value="delete_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <button class="kbtn" type="submit" style="color:#f87171">Удалить</button>
        </form>
      </div>

      <div class="key-note">
        <form method="post" class="form-row" style="margin-bottom:8px">
          <input type="hidden" name="action" value="transfer_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <input type="number" name="new_tg" placeholder="Передать → TG ID" style="flex:1">
          <button class="btn btn-dark btn-sm" type="submit">Передать</button>
        </form>
        <form method="post" class="form-row" style="margin-bottom:8px">
          <input type="hidden" name="action" value="set_max"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <input type="number" name="max" value="<?= $max ?>" min="1" style="width:60px">
          <button class="btn btn-dark btn-sm" type="submit">Лимит уст-в</button>
        </form>
        <form method="post" class="form-row" style="margin-bottom:8px">
          <input type="hidden" name="action" value="set_duration"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <input type="number" name="hours" placeholder="Часов (0=∞)" style="width:110px">
          <button class="btn btn-dark btn-sm" type="submit">Срок</button>
        </form>
        <form method="post" class="form-row" style="margin-bottom:8px">
          <input type="hidden" name="action" value="ban_hwid"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <input type="text" name="hwid_ban" placeholder="HWID для бана" style="flex:1">
          <button class="btn btn-dark btn-sm" type="submit">Бан HWID</button>
        </form>
        <form method="post">
          <input type="hidden" name="action" value="set_note"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <textarea name="note" rows="2" placeholder="Заметка..."><?= htmlspecialchars($note) ?></textarea>
          <button class="btn btn-dark btn-sm" type="submit" style="margin-top:6px">Сохранить заметку</button>
        </form>
      </div>
    </div>

    <div style="text-align:center;margin-bottom:16px">
      <a href="?admin&tab=keys" class="btn btn-dark">← Назад</a>
    </div>

    <div class="card" style="max-width:680px;margin:0 auto">
      <h2>Активации</h2>
      <?php if (empty($kd['activations'])): ?>
        <p style="color:var(--muted)">Нет активаций</p>
      <?php else: ?>
        <table>
          <tr><th>HWID</th><th>IP</th><th>Активация</th><th>Последний</th><th>Запусков</th></tr>
          <?php foreach ($kd['activations'] as $a): ?>
          <tr>
            <td><code style="font-size:11px"><?= htmlspecialchars($a['hwid']??'') ?></code></td>
            <td><?= htmlspecialchars($a['ip']??'') ?></td>
            <td><?= !empty($a['time'])?date('d.m H:i',$a['time']):'—' ?></td>
            <td><?= !empty($a['last_active'])?date('d.m H:i',$a['last_active']):'—' ?></td>
            <td><?= $a['launches']??1 ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

    <?php elseif ($tab === 'dashboard'): ?>
      <div class="stats">
        <div class="stat"><div class="num"><?= $totalKeys ?></div><div class="label">Keys</div></div>
        <div class="stat"><div class="num"><?= $active ?></div><div class="label">Active</div></div>
        <div class="stat"><div class="num"><?= $onlineCount ?></div><div class="label">Online</div></div>
        <div class="stat"><div class="num"><?= $frozen ?></div><div class="label">Frozen</div></div>
        <div class="stat"><div class="num"><?= $expired ?></div><div class="label">Expired</div></div>
        <div class="stat"><div class="num"><?= count($db['blacklist']) ?></div><div class="label">Blacklist</div></div>
      </div>
      <div class="card">
        <h2>Quick actions</h2>
        <div class="form-row">
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="online"><button class="btn btn-accent" type="submit">Online</button></form>
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="maintenance"><button class="btn btn-dark" type="submit">Maintenance</button></form>
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="killswitch"><button class="btn btn-red" type="submit">Killswitch</button></form>
          <form method="post"><input type="hidden" name="action" value="toggle_global_freeze"><button class="btn btn-dark" type="submit"><?= !empty($db['settings']['global_freeze'])?'Unfreeze':'Global Freeze' ?></button></form>
        </div>
        <div class="form-row">
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="undetected"><button class="btn btn-accent btn-sm" type="submit">Undetected</button></form>
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="updating"><button class="btn btn-dark btn-sm" type="submit">Updating</button></form>
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="detected"><button class="btn btn-red btn-sm" type="submit">Detected</button></form>
        </div>
        <p style="margin-top:12px;color:var(--muted);font-size:13px">
          Status: <b style="color:var(--accent)"><?= htmlspecialchars($db['settings']['status']) ?></b> ·
          Soft: <b><?= htmlspecialchars($db['settings']['soft_status']??'undetected') ?></b> ·
          Freeze: <b><?= !empty($db['settings']['global_freeze'])?'ON':'off' ?></b>
        </p>
      </div>

    <?php elseif ($tab === 'generate'): ?>
      <div class="card">
        <h2>Create Key</h2>
        <form method="post">
          <input type="hidden" name="action" value="gen_key">
          <div class="form-row">
            <input type="text" name="custom_name" placeholder="Именной ключ (оставьте пустым для обычного)" style="width:260px">
          </div>
          <div class="form-row">
            <input type="number" name="hours" value="24" placeholder="Hours (0=∞)" style="width:110px">
            <input type="number" name="max" value="1" min="1" style="width:60px" placeholder="Devices">
            <select name="level">
              <option value="trial">Trial</option>
              <option value="free">Free</option>
              <option value="media">Media</option>
              <option value="premium" selected>Premium</option>
              <option value="elite">Elite</option>
            </select>
            <button class="btn btn-accent" type="submit">Create</button>
          </div>
          <div class="form-row">
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=1">1h</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=24">1d</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=72">3d</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=168">7d</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=720">30d</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=2160">90d</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=0">∞</button>
          </div>
          <p style="color:var(--muted);font-size:12px;margin-top:8px">Именной ключ — вы сами задаёте название. Обычный генерируется автоматически.</p>
        </form>
      </div>

    <?php elseif ($tab === 'bulk'): ?>
      <div class="card">
        <h2>Bulk Generate</h2>
        <form method="post">
          <input type="hidden" name="action" value="bulk_generate">
          <div class="form-row">
            <input type="number" name="count" value="10" min="1" max="50" style="width:60px">
            <input type="number" name="hours" value="24" style="width:90px">
            <input type="number" name="max" value="1" min="1" style="width:55px">
            <select name="level">
              <option value="trial">Trial</option>
              <option value="free">Free</option>
              <option value="media">Media</option>
              <option value="premium" selected>Premium</option>
              <option value="elite">Elite</option>
            </select>
            <button class="btn btn-accent" type="submit">Generate</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'give'): ?>
      <div class="card">
        <h2>Give Key</h2>
        <form method="post">
          <input type="hidden" name="action" value="give_key">
          <div class="form-row">
            <input type="number" name="tg_id" placeholder="Telegram ID" required style="width:140px">
            <input type="text" name="custom_name" placeholder="Именной (опц.)" style="width:140px">
            <input type="number" name="hours" value="168" style="width:80px">
            <input type="number" name="max" value="1" style="width:55px">
            <select name="level">
              <option value="premium" selected>Premium</option>
              <option value="elite">Elite</option>
              <option value="media">Media</option>
              <option value="free">Free</option>
              <option value="trial">Trial</option>
            </select>
            <button class="btn btn-accent" type="submit">Give</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'keys'): ?>
      <div class="card">
        <h2>Keys (<?= $totalKeys ?>)</h2>
        <input type="text" id="searchKey" placeholder="Search..." onkeyup="filterTable()" style="width:100%;max-width:260px;margin-bottom:12px">
        <div style="overflow-x:auto">
        <table id="keysTable">
          <tr><th>Key</th><th>Type</th><th>Owner</th><th>Status</th><th>Dev</th><th></th></tr>
          <?php foreach ($db['keys'] as $k => $kd):
            $used = count($kd['activations']??[]);
            $max = $kd['max']??1;
            $lvl = $kd['level']??'trial';
            if (!empty($kd['is_frozen'])) $st = '<span class="badge badge-blue">Freeze</span>';
            elseif (($kd['first_use']??0)==0) $st = '<span class="badge badge-yellow">New</span>';
            elseif (($kd['expires']??0)==0) $st = '<span class="badge badge-green">∞</span>';
            elseif (time()>$kd['expires']) $st = '<span class="badge badge-red">Expired</span>';
            else $st = '<span class="badge badge-green">Active</span>';
          ?>
          <tr>
            <td><a class="keylink" href="?admin&view=<?= urlencode($k) ?>"><code><?= htmlspecialchars($k) ?></code></a></td>
            <td><?= htmlspecialchars($lvl) ?><?= !empty($kd['vip'])?' VIP':'' ?><?= !empty($kd['named'])?' named':'' ?></td>
            <td><?= $kd['owner_tg'] ?: ($kd['owner_name']?:'—') ?></td>
            <td><?= $st ?></td>
            <td><?= $used ?>/<?= $max ?></td>
            <td><a href="?admin&view=<?= urlencode($k) ?>" class="btn btn-dark btn-sm">Open</a></td>
          </tr>
          <?php endforeach; ?>
        </table>
        </div>
      </div>

    <?php elseif ($tab === 'online'): ?>
      <div class="card">
        <h2>Online (<?= $onlineCount ?>)</h2>
        <?php if (empty($db['online'])): ?>
          <p style="color:var(--muted)">Empty</p>
        <?php else: ?>
          <table>
            <tr><th>Key</th><th>IP</th><th>HWID</th><th>Ping</th><th>Since</th></tr>
            <?php foreach ($db['online'] as $hwid => $info): ?>
            <tr>
              <td><code><?= htmlspecialchars($info['key']??'—') ?></code></td>
              <td><?= htmlspecialchars($info['ip']??'') ?></td>
              <td style="font-size:11px"><?= htmlspecialchars(substr($hwid,0,18)) ?>…</td>
              <td><?= time()-($info['last_ping']??0) ?>s</td>
              <td style="font-size:12px"><?= !empty($info['first_seen'])?date('H:i:s',$info['first_seen']):'—' ?></td>
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
          <textarea name="broadcast" placeholder="Message..."><?= htmlspecialchars($db['settings']['broadcast']??'') ?></textarea>
          <div class="form-row" style="margin-top:10px">
            <button class="btn btn-accent" type="submit">Set</button>
            <button class="btn btn-dark" type="submit" onclick="this.form.broadcast.value=''">Clear</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'blacklist'): ?>
      <div class="card">
        <h2>Add to Blacklist</h2>
        <form method="post" class="form-row">
          <input type="hidden" name="action" value="add_blacklist">
          <input type="text" name="value" placeholder="IP or HWID" required style="width:200px">
          <input type="text" name="reason" placeholder="Reason" style="width:140px">
          <button class="btn btn-red" type="submit">Add</button>
        </form>
      </div>
      <div class="card">
        <h2>Blacklist (<?= count($db['blacklist']) ?>)</h2>
        <?php if (empty($db['blacklist'])): ?>
          <p style="color:var(--muted)">Empty</p>
        <?php else: ?>
          <table>
            <tr><th>Value</th><th>Reason</th><th>Date</th><th></th></tr>
            <?php foreach ($db['blacklist'] as $val => $info): ?>
            <tr>
              <td><code><?= htmlspecialchars($val) ?></code></td>
              <td><?= htmlspecialchars($info['reason']??'') ?></td>
              <td><?= date('d.m H:i',$info['time']??time()) ?></td>
              <td><form method="post"><input type="hidden" name="action" value="remove_blacklist"><input type="hidden" name="value" value="<?= htmlspecialchars($val) ?>"><button class="btn btn-dark btn-sm" type="submit">×</button></form></td>
            </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'settings'): ?>
      <div class="card">
        <h2>Settings</h2>
        <form method="post">
          <input type="hidden" name="action" value="save_settings">
          <div class="form-row"><label style="width:100px">Version</label><input type="text" name="version" value="<?= htmlspecialchars($db['settings']['version']) ?>" style="width:140px"></div>
          <div class="form-row"><label style="width:100px">Checksum</label><input type="text" name="checksum" value="<?= htmlspecialchars($db['settings']['checksum']) ?>" style="width:260px"></div>
          <div class="form-row"><label style="width:100px">Download</label><input type="text" name="download_url" value="<?= htmlspecialchars($db['settings']['download_url']) ?>" style="width:260px"></div>
          <div class="form-row" style="align-items:flex-start"><label style="width:100px;margin-top:8px">Emergency</label><textarea name="emergency_msg"><?= htmlspecialchars($db['settings']['emergency_msg']) ?></textarea></div>
          <button class="btn btn-accent" type="submit" style="margin-top:10px">Save</button>
        </form>
      </div>

    <?php elseif ($tab === 'theme'): ?>
      <div class="card">
        <h2>Theme</h2>
        <form method="post">
          <input type="hidden" name="action" value="set_panel_bg">
          <div class="form-row">
            <input type="text" name="panel_bg" value="<?= htmlspecialchars($db['settings']['panel_bg']??'') ?>" placeholder="Background image URL" style="flex:1;min-width:200px">
          </div>
          <div class="form-row">
            <label>Accent color:</label>
            <input type="color" name="panel_accent" value="<?= htmlspecialchars($db['settings']['panel_accent']??'#22c55e') ?>" style="width:48px;height:34px;padding:2px;border:1px solid var(--border);background:#111;border-radius:6px">
            <button class="btn btn-accent" type="submit">Apply</button>
            <button class="btn btn-dark" type="submit" onclick="this.form.panel_bg.value='';this.form.panel_accent.value='#22c55e'">Reset</button>
          </div>
        </form>
        <p style="color:var(--muted);font-size:12px;margin-top:10px">Вставьте ссылку на изображение. Акцент применяется ко всей панели.</p>
      </div>

    <?php elseif ($tab === 'logs'): ?>
      <div class="card">
        <h2>Logs</h2>
        <?php
          $filter = $_GET['filter'] ?? '';
          $logs = $db['logs'];
          if ($filter) $logs = array_filter($logs, fn($l) => strpos($l['text'], $filter) !== false);
          foreach (array_slice($logs, 0, 60) as $l):
        ?>
          <div style="font-size:12px;padding:5px 0;border-bottom:1px solid #121212">
            <span style="color:var(--accent)"><?= date('d.m H:i:s', $l['time']) ?></span> — <?= htmlspecialchars($l['text']) ?>
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

// ====================== BOT ======================
$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Lori</title>
    <style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#050505;color:#22c55e;font-family:Inter,system-ui;letter-spacing:3px;font-size:14px}</style>
    </head><body>LORI</body></html>';
    exit;
}

function tgRequest($method, $data) {
    global $botToken;
    $opts = ['http'=>['header'=>"Content-Type: application/json\r\n",'method'=>'POST','content'=>json_encode($data,JSON_UNESCAPED_UNICODE),'ignore_errors'=>true]];
    return @file_get_contents("https://api.telegram.org/bot$botToken/$method", false, stream_context_create($opts));
}
function sendMessage($chat_id, $text, $kb=null) {
    $d = ['chat_id'=>$chat_id,'text'=>$text,'parse_mode'=>'Markdown','disable_web_page_preview'=>true];
    if ($kb) $d['reply_markup'] = $kb;
    return tgRequest('sendMessage', $d);
}
function editMessage($chat_id, $msg_id, $text, $kb=null) {
    $d = ['chat_id'=>$chat_id,'message_id'=>$msg_id,'text'=>$text,'parse_mode'=>'Markdown','disable_web_page_preview'=>true];
    if ($kb) $d['reply_markup'] = $kb;
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

if (isset($update['pre_checkout_query'])) {
    tgRequest('answerPreCheckoutQuery', ['pre_checkout_query_id'=>$update['pre_checkout_query']['id'],'ok'=>true]);
    exit;
}

if (isset($update['message']['successful_payment'])) {
    $chatId = $update['message']['chat']['id'];
    $payload = $update['message']['successful_payment']['invoice_payload'];
    $parts = explode('_', $payload);
    $hours = (int)($parts[1] ?? 24);
    $duration = $hours === 0 ? 0 : $hours * 3600;
    $newKey = 'PREMIUM-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
    $db['keys'][$newKey] = [
        'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>1,
        'activations'=>[],'owner_tg'=>$chatId,'owner_name'=>'','reset_left'=>2,
        'is_frozen'=>false,'level'=>'premium','created'=>time(),'warns'=>0,
        'note'=>'','vip'=>false,'bg'=>'','named'=>false
    ];
    saveDb();
    addLog("Куплен $newKey юзером $chatId");
    sendMessage($chatId, "Оплата прошла.\n\nКлюч:\n`$newKey`");
    exit;
}

if (isset($update['message'])) {
    $chatId = (int)$update['message']['chat']['id'];
    $text = trim($update['message']['text'] ?? '');
    $isAdmin = ($chatId === $adminId);

    if ($isAdmin) {
        if ($text === '/admin' || $text === '/panel') {
            sendMessage($chatId, "Admin\n\nKeys: *".count($db['keys'])."*\nOnline: *".count($db['online'])."*", [
                'inline_keyboard'=>[
                    [['text'=>'Keys','callback_data'=>'adm_keys'],['text'=>'Create','callback_data'=>'adm_gen']],
                    [['text'=>'Online','callback_data'=>'adm_online'],['text'=>'Stats','callback_data'=>'adm_stats']],
                    [['text'=>'Killswitch','callback_data'=>'toggle_kill'],['text'=>'Freeze','callback_data'=>'toggle_gfreeze']],
                    [['text'=>'Logs','callback_data'=>'adm_logs']]
                ]
            ]);
            exit;
        }
        if (strpos($text, '/gen ') === 0) {
            $args = explode(' ', $text);
            $hours = (int)($args[1]??24);
            $max = (int)($args[2]??1);
            $level = $args[3]??'premium';
            $name = $args[4]??'';
            $duration = $hours===0?0:$hours*3600;
            if ($name) {
                $newKey = $name;
                if (isset($db['keys'][$newKey])) {
                    sendMessage($chatId, "Имя занято");
                    exit;
                }
                $db['keys'][$newKey] = [
                    'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                    'activations'=>[],'owner_tg'=>0,'owner_name'=>$name,'reset_left'=>999,
                    'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
                    'note'=>'','vip'=>false,'bg'=>'','named'=>true
                ];
            } else {
                $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                $db['keys'][$newKey] = [
                    'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                    'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>999,
                    'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
                    'note'=>'','vip'=>false,'bg'=>'','named'=>false
                ];
            }
            saveDb();
            sendMessage($chatId, "Ключ: `$newKey`");
            exit;
        }
        if (strpos($text, '/give ') === 0) {
            $args = explode(' ', $text);
            $target = (int)($args[1]??0);
            $hours = (int)($args[2]??24);
            $max = (int)($args[3]??1);
            $level = $args[4]??'premium';
            if ($target>0) {
                $duration = $hours===0?0:$hours*3600;
                $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                $db['keys'][$newKey] = [
                    'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                    'activations'=>[],'owner_tg'=>$target,'owner_name'=>'','reset_left'=>2,
                    'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
                    'note'=>'','vip'=>false,'bg'=>'','named'=>false
                ];
                saveDb();
                sendMessage($chatId, "`$newKey` → `$target`");
                sendMessage($target, "Вам выдан ключ:\n`$newKey`");
            }
            exit;
        }
    }

    if ($text === '/start') {
        $kb = ['inline_keyboard'=>[
            [['text'=>'1 час — 10 Stars','callback_data'=>'buy_1_1']],
            [['text'=>'24 часа — 25 Stars','callback_data'=>'buy_24_1']],
            [['text'=>'3 дня — 50 Stars','callback_data'=>'buy_72_1']],
            [['text'=>'7 дней — 75 Stars','callback_data'=>'buy_168_1']],
            [['text'=>'30 дней — 125 Stars','callback_data'=>'buy_720_1']],
            [['text'=>'90 дней — 250 Stars','callback_data'=>'buy_2160_1']],
            [['text'=>'Навсегда — 400 Stars','callback_data'=>'buy_0_1']],
            [['text'=>'Мои ключи','callback_data'=>'my_keys']]
        ]];
        if ($isAdmin) $kb['inline_keyboard'][] = [['text'=>'Admin','callback_data'=>'admin_panel']];
        sendMessage($chatId, "Lori Elite\n\nВыберите срок:", $kb);
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
        $hours = (int)$p[1];
        $starsMap = [1=>10,24=>25,72=>50,168=>75,720=>125,2160=>250,0=>400];
        $stars = $starsMap[$hours] ?? 25;
        sendInvoice($chatId,'Lori Key','Premium access',"sub_{$hours}_1",$stars);
        answerCallback($cqId);
        exit;
    }

    if ($data === 'my_keys') {
        $found = false;
        foreach ($db['keys'] as $k=>$kd) {
            if (($kd['owner_tg']??0)==$chatId) {
                $found = true;
                $used = count($kd['activations']??[]);
                $max = $kd['max']??1;
                if (!empty($kd['is_frozen'])) $st='Заморожен';
                elseif (($kd['first_use']??0)==0) $st='Не активирован';
                elseif (($kd['expires']??0)==0) $st='Навсегда';
                elseif (time()>$kd['expires']) $st='Истёк';
                else {
                    $left = $kd['expires']-time();
                    $st = floor($left/86400).'д '.floor(($left%86400)/3600).'ч';
                }
                sendMessage($chatId, "`$k`\n$st\nУстройства: $used/$max", [
                    'inline_keyboard'=>[[['text'=>'Сбросить HWID','callback_data'=>'user_reset_'.$k]]]
                ]);
            }
        }
        if (!$found) sendMessage($chatId,'Ключей нет');
        answerCallback($cqId);
        exit;
    }

    if (strpos($data,'user_reset_')===0) {
        $key = str_replace('user_reset_','',$data);
        if (isset($db['keys'][$key]) && ($db['keys'][$key]['owner_tg']??0)==$chatId) {
            if (($db['keys'][$key]['reset_left']??0)>0) {
                $db['keys'][$key]['activations']=[];
                $db['keys'][$key]['reset_left']--;
                saveDb();
                answerCallback($cqId,'HWID сброшены');
            } else answerCallback($cqId,'Лимит сбросов',true);
        }
        exit;
    }

    if (!$isAdmin) { answerCallback($cqId,'Нет доступа',true); exit; }

    if ($data === 'admin_panel') {
        editMessage($chatId,$msgId,"Admin\n\nKeys: *".count($db['keys'])."*\nOnline: *".count($db['online'])."*", [
            'inline_keyboard'=>[
                [['text'=>'Keys','callback_data'=>'adm_keys'],['text'=>'Create','callback_data'=>'adm_gen']],
                [['text'=>'Online','callback_data'=>'adm_online'],['text'=>'Stats','callback_data'=>'adm_stats']],
                [['text'=>'Killswitch','callback_data'=>'toggle_kill'],['text'=>'Freeze','callback_data'=>'toggle_gfreeze']],
                [['text'=>'Logs','callback_data'=>'adm_logs']]
            ]
        ]);
        answerCallback($cqId); exit;
    }

    if ($data === 'adm_keys' || strpos($data,'adm_keys_')===0) {
        $page = strpos($data,'adm_keys_')===0 ? (int)str_replace('adm_keys_','',$data) : 0;
        $keys = array_keys($db['keys']);
        $per = 8; $total = count($keys); $pages = max(1,ceil($total/$per));
        $slice = array_slice($keys,$page*$per,$per);
        $kb = ['inline_keyboard'=>[]];
        foreach ($slice as $k) {
            $kd = $db['keys'][$k];
            $st = !empty($kd['is_frozen'])?'F':((($kd['expires']??0)>0&&time()>$kd['expires'])?'E':'A');
            $kb['inline_keyboard'][] = [['text'=>"[$st] $k",'callback_data'=>'k_view_'.$k]];
        }
        $nav = [];
        if ($page>0) $nav[] = ['text'=>'‹','callback_data'=>'adm_keys_'.($page-1)];
        $nav[] = ['text'=>($page+1)."/$pages",'callback_data'=>'noop'];
        if ($page<$pages-1) $nav[] = ['text'=>'›','callback_data'=>'adm_keys_'.($page+1)];
        if ($nav) $kb['inline_keyboard'][] = $nav;
        $kb['inline_keyboard'][] = [['text'=>'Back','callback_data'=>'admin_panel']];
        editMessage($chatId,$msgId,"Keys ($total)",$kb);
        answerCallback($cqId); exit;
    }

    if (strpos($data,'k_view_')===0) {
        $key = str_replace('k_view_','',$data);
        if (!isset($db['keys'][$key])) { answerCallback($cqId,'Not found',true); exit; }
        $kd = $db['keys'][$key];
        $used = count($kd['activations']??[]);
        $max = $kd['max']??1;
        $warns = $kd['warns']??0;
        $st = !empty($kd['is_frozen'])?'frozen':((($kd['expires']??0)>0&&time()>$kd['expires'])?'expired':'active');
        $text = "`$key`\nStatus: *$st*\nDevices: $used/$max\nWarns: $warns/3";
        $kb = ['inline_keyboard'=>[
            [['text'=>'Reset HWID','callback_data'=>'k_rhwid_'.$key],['text'=>!empty($kd['is_frozen'])?'Unfreeze':'Freeze','callback_data'=>'k_freeze_'.$key]],
            [['text'=>'Warn','callback_data'=>'k_warn_'.$key],['text'=>'Delete','callback_data'=>'k_del_'.$key]],
            [['text'=>'Back','callback_data'=>'adm_keys']]
        ]];
        editMessage($chatId,$msgId,$text,$kb);
        answerCallback($cqId); exit;
    }

    if (strpos($data,'k_rhwid_')===0) {
        $key = str_replace('k_rhwid_','',$data);
        if (isset($db['keys'][$key])) { $db['keys'][$key]['activations']=[]; saveDb(); answerCallback($cqId,'Reset'); }
        exit;
    }
    if (strpos($data,'k_freeze_')===0) {
        $key = str_replace('k_freeze_','',$data);
        if (isset($db['keys'][$key])) {
            $db['keys'][$key]['is_frozen'] = empty($db['keys'][$key]['is_frozen']);
            saveDb();
            answerCallback($cqId, !empty($db['keys'][$key]['is_frozen'])?'Frozen':'Unfrozen');
        }
        exit;
    }
    if (strpos($data,'k_warn_')===0) {
        $key = str_replace('k_warn_','',$data);
        if (isset($db['keys'][$key])) {
            $db['keys'][$key]['warns'] = min(3,($db['keys'][$key]['warns']??0)+1);
            saveDb(); answerCallback($cqId,'Warned');
        }
        exit;
    }
    if (strpos($data,'k_del_')===0) {
        $key = str_replace('k_del_','',$data);
        unset($db['keys'][$key]); saveDb();
        answerCallback($cqId,'Deleted');
        exit;
    }

    if ($data === 'adm_gen') {
        editMessage($chatId,$msgId,"Create\n`/gen hours max level name`\n\nExample:\n`/gen 168 1 premium Dima_Samek`", [
            'inline_keyboard'=>[[['text'=>'Back','callback_data'=>'admin_panel']]]
        ]);
        answerCallback($cqId); exit;
    }
    if ($data === 'adm_online') {
        $text = "Online (".count($db['online']).")\n\n";
        if (empty($db['online'])) $text.='Empty';
        else foreach ($db['online'] as $h=>$i) $text.="`{$i['key']}` | {$i['ip']}\n";
        editMessage($chatId,$msgId,$text,['inline_keyboard'=>[[['text'=>'Back','callback_data'=>'admin_panel']]]]);
        answerCallback($cqId); exit;
    }
    if ($data === 'adm_stats') {
        $a=$f=$e=0;
        foreach ($db['keys'] as $kd) {
            if (!empty($kd['is_frozen'])) $f++;
            elseif (($kd['expires']??0)==0||time()<($kd['expires']??0)) $a++;
            else $e++;
        }
        editMessage($chatId,$msgId,"Total: ".count($db['keys'])."\nActive: $a\nFrozen: $f\nExpired: $e", [
            'inline_keyboard'=>[[['text'=>'Back','callback_data'=>'admin_panel']]]
        ]);
        answerCallback($cqId); exit;
    }
    if ($data === 'adm_logs') {
        $text = "Logs\n\n";
        foreach (array_slice($db['logs'],0,12) as $l) $text.='`'.date('H:i',$l['time']).'` '.$l['text']."\n";
        editMessage($chatId,$msgId,$text,['inline_keyboard'=>[[['text'=>'Back','callback_data'=>'admin_panel']]]]);
        answerCallback($cqId); exit;
    }
    if ($data === 'toggle_kill') {
        if ($db['settings']['status']==='killswitch') {
            $db['settings']['status']='online'; $db['settings']['emergency_msg']='';
            answerCallback($cqId,'Killswitch OFF');
        } else {
            $db['settings']['status']='killswitch'; $db['settings']['emergency_msg']='Software stopped';
            answerCallback($cqId,'Killswitch ON');
        }
        saveDb(); exit;
    }
    if ($data === 'toggle_gfreeze') {
        $db['settings']['global_freeze'] = empty($db['settings']['global_freeze']);
        saveDb();
        answerCallback($cqId, !empty($db['settings']['global_freeze'])?'Freeze ON':'Freeze OFF');
        exit;
    }
    if ($data === 'noop') { answerCallback($cqId); exit; }
}
