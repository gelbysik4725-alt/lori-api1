<?php
error_reporting(0);
date_default_timezone_set('Europe/Moscow');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
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

if (!isset($db['keys']) || !is_array($db['keys'])) $db['keys'] = [];
if (!isset($db['blacklist']) || !is_array($db['blacklist'])) $db['blacklist'] = [];
if (!isset($db['logs']) || !is_array($db['logs'])) $db['logs'] = [];
if (!isset($db['online']) || !is_array($db['online'])) $db['online'] = [];
if (!isset($db['settings']) || !is_array($db['settings'])) $db['settings'] = [];

$db['settings'] = array_merge([
    'status' => 'online',
    'soft_status' => 'undetected',
    'global_freeze' => false,
    'version' => '3.1.0',
    'checksum' => '5c8714cf5eb4010c2cad5b14ac476f3f3f695d26',
    'download_url' => 'https://example.com/script.lua',
    'emergency_msg' => '',
    'broadcast' => '',
    'panel_bg' => '',
    'panel_bg_color' => '#030303',
    'panel_accent' => '#22c55e',
    'panel_overlay' => '0.75',
    'panel_blur' => '12'
], $db['settings']);

function saveDb() {
    global $db, $dbFile;
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function addLog($text) {
    global $db;
    array_unshift($db['logs'], ['time' => time(), 'text' => $text]);
    if (count($db['logs']) > 500) array_pop($db['logs']);
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
        echo json_encode(['status'=>'error','message'=>'Modification detected']); exit;
    }
    if ($db['settings']['status'] === 'killswitch') {
        echo json_encode(['status'=>'killswitch','message'=>$db['settings']['emergency_msg']?:'Stopped']); exit;
    }
    if (!empty($db['settings']['global_freeze'])) {
        echo json_encode(['status'=>'frozen','message'=>'Frozen']); exit;
    }
    if (($db['settings']['soft_status'] ?? '') === 'detected') {
        echo json_encode(['status'=>'detected','message'=>'Detected']); exit;
    }
    if (!empty($hwid)) {
        $db['online'][$hwid] = ['ip'=>$ip,'key'=>$key?:'-','last_ping'=>time(),'first_seen'=>$db['online'][$hwid]['first_seen']??time()];
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
    if ($db['settings']['status'] === 'maintenance') { echo 'Maintenance'; exit; }
    if ($db['settings']['status'] === 'killswitch') { echo $db['settings']['emergency_msg'] ?: 'Stopped'; exit; }
    if (!empty($db['settings']['global_freeze'])) { echo 'Frozen'; exit; }
    if (($db['settings']['soft_status'] ?? '') === 'detected') { echo 'Detected'; exit; }
    if (empty($key))  { echo 'No key'; exit; }
    if (empty($hwid)) { echo 'No HWID'; exit; }
    if (isset($db['blacklist'][$ip]) || isset($db['blacklist'][$hwid])) { echo 'Blocked'; exit; }
    if (!isset($db['keys'][$key])) { echo 'Invalid key'; exit; }
    $kd = $db['keys'][$key];
    $now = time();
    if (!empty($kd['is_frozen'])) { echo 'Key frozen'; exit; }
    if (!empty($kd['soft_ban_until']) && $now < $kd['soft_ban_until']) { echo 'Temporary ban'; exit; }
    if (($kd['expires'] ?? 0) !== 0 && $now > $kd['expires']) {
        unset($db['keys'][$key]); saveDb(); echo 'Expired'; exit;
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
        addLog("Activated $key | ".substr($hwid,0,12)." | $ip");
        echo 'SUCCESS';
    } else {
        echo 'Device limit';
    }
    exit;
}

// ====================== ADMIN ======================
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
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Lori</title>
        <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap");
        *{margin:0;padding:0;box-sizing:border-box}
        body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#030303;font-family:Inter,system-ui;color:#e5e5e5}
        body::before{content:"";position:fixed;inset:0;background:radial-gradient(ellipse at 30% 20%,rgba(34,197,94,0.08),transparent 50%),radial-gradient(ellipse at 70% 80%,rgba(34,197,94,0.05),transparent 40%);pointer-events:none}
        .box{position:relative;width:100%;max-width:360px;padding:48px 36px;background:rgba(10,10,12,0.95);border:1px solid rgba(34,197,94,0.18);border-radius:20px;backdrop-filter:blur(24px);box-shadow:0 0 100px rgba(34,197,94,0.08),0 40px 80px rgba(0,0,0,0.6)}
        .box::before{content:"";position:absolute;top:0;left:20%;right:20%;height:1px;background:linear-gradient(90deg,transparent,#22c55e,transparent)}
        .logo{text-align:center;font-size:11px;letter-spacing:10px;color:#22c55e;font-weight:500;margin-bottom:24px}
        h1{font-size:20px;font-weight:600;text-align:center;margin-bottom:6px;color:#fff}
        .sub{text-align:center;font-size:10px;color:#444;margin-bottom:32px;letter-spacing:2px}
        input{width:100%;padding:14px 16px;background:#08080a;border:1px solid #1a1a1c;border-radius:12px;color:#fff;font-size:13px;outline:none;margin-bottom:12px;transition:.25s}
        input:focus{border-color:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,0.15)}
        button{width:100%;padding:14px;background:linear-gradient(135deg,#22c55e,#16a34a);border:none;border-radius:12px;color:#000;font-size:13px;font-weight:600;cursor:pointer;transition:.25s}
        button:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(34,197,94,0.35)}
        .err{color:#ef4444;font-size:12px;text-align:center;margin-bottom:12px}
        </style></head><body>
        <div class="box">
          <div class="logo">LORI</div>
          <h1>Control Panel</h1>
          <div class="sub">RESTRICTED ACCESS</div>
          '.(!empty($loginError)?'<div class="err">'.$loginError.'</div>':'').'
          <form method="post">
            <input type="password" name="password" placeholder="Password" required autofocus>
            <button type="submit">Enter</button>
          </form>
        </div></body></html>';
        exit;
    }

    $msg = '';
    $tab = $_GET['tab'] ?? 'dashboard';
    $viewKey = $_GET['view'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $act = $_POST['action'];
        $k = $_POST['key'] ?? '';

        // CREATE
        if ($act === 'gen_key') {
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level']??'', ['trial','free','media','premium','elite']) ? $_POST['level'] : 'premium';
            $customName = trim($_POST['custom_name'] ?? '');
            $duration = $hours === 0 ? 0 : $hours * 3600;
            if ($customName !== '') {
                $newKey = $customName;
                if (isset($db['keys'][$newKey])) $msg = 'Такое имя уже есть';
                else {
                    $db['keys'][$newKey] = makeKeyData($duration, $max, $level, 0, $customName, true);
                    saveDb(); addLog("Named: $newKey"); $msg = "Именной ключ: <b>$newKey</b>";
                }
            } else {
                $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                $db['keys'][$newKey] = makeKeyData($duration, $max, $level, 0, '', false);
                saveDb(); addLog("Key: $newKey"); $msg = "Ключ: <b>$newKey</b>";
            }
        }

        if ($act === 'bulk_generate') {
            $count = max(1, min(50, (int)($_POST['count'] ?? 10)));
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level']??'', ['trial','free','media','premium','elite']) ? $_POST['level'] : 'premium';
            $duration = $hours === 0 ? 0 : $hours * 3600;
            for ($i=0;$i<$count;$i++) {
                $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand().$i,true)),0,8));
                $db['keys'][$newKey] = makeKeyData($duration, $max, $level, 0, '', false);
            }
            saveDb(); addLog("Bulk $count"); $msg = "Создано: <b>$count</b>";
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
                    if (isset($db['keys'][$newKey])) $msg = 'Имя занято';
                    else {
                        $db['keys'][$newKey] = makeKeyData($duration, $max, $level, $tgId, $customName, true);
                        saveDb(); addLog("Given $newKey → $tgId");
                        @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$tgId&text=".urlencode("Ваш ключ:\n$newKey"));
                        $msg = "Выдан: <b>$newKey</b>";
                    }
                } else {
                    $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                    $db['keys'][$newKey] = makeKeyData($duration, $max, $level, $tgId, '', false);
                    saveDb(); addLog("Given $newKey → $tgId");
                    @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$tgId&text=".urlencode("Ваш ключ:\n$newKey"));
                    $msg = "Выдан: <b>$newKey</b>";
                }
            } else $msg = 'Укажи Telegram ID';
        }

        // KEY ACTIONS
        if ($act === 'freeze_key' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['is_frozen'] = empty($db['keys'][$k]['is_frozen']);
            saveDb(); $msg = !empty($db['keys'][$k]['is_frozen']) ? 'Ключ заморожен' : 'Ключ разморожен';
        }
        if ($act === 'reset_hwid' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['activations'] = []; saveDb(); addLog("HWID reset $k"); $msg = 'HWID сброшены';
        }
        if ($act === 'delete_key' && $k) {
            unset($db['keys'][$k]); saveDb(); addLog("Deleted $k"); header('Location: ?admin&tab=keys'); exit;
        }
        if ($act === 'add_warn' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['warns'] = min(3, ($db['keys'][$k]['warns']??0)+1); saveDb(); $msg = 'Варн '.($db['keys'][$k]['warns']).'/3';
        }
        if ($act === 'reset_warns' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['warns'] = 0; saveDb(); $msg = 'Варны сброшены';
        }
        if ($act === 'regen_key' && $k && isset($db['keys'][$k])) {
            $old = $db['keys'][$k];
            if (!empty($old['named'])) $msg = 'Именной ключ нельзя перегенерировать';
            else {
                $level = $old['level']??'premium';
                $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                $db['keys'][$newKey] = $old; $db['keys'][$newKey]['activations'] = [];
                unset($db['keys'][$k]); saveDb(); addLog("Regen $k → $newKey");
                header('Location: ?admin&view='.urlencode($newKey)); exit;
            }
        }
        if ($act === 'set_nick' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['owner_name'] = trim($_POST['nick']??''); saveDb(); $msg = 'Имя сохранено';
        }
        if ($act === 'set_note' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['note'] = trim($_POST['note']??''); saveDb(); $msg = 'Заметка сохранена';
        }
        if ($act === 'toggle_vip' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['vip'] = empty($db['keys'][$k]['vip']); saveDb(); $msg = !empty($db['keys'][$k]['vip'])?'VIP включён':'VIP выключен';
        }
        if ($act === 'extend_key' && $k && isset($db['keys'][$k])) {
            $days = max(1,(int)($_POST['days']??7));
            if (($db['keys'][$k]['expires']??0)==0) $db['keys'][$k]['expires'] = time()+$days*86400;
            else $db['keys'][$k]['expires'] += $days*86400;
            saveDb(); $msg = "+$days дней";
        }
        if ($act === 'set_duration' && $k && isset($db['keys'][$k])) {
            $hours = (int)($_POST['hours']??0);
            $db['keys'][$k]['duration'] = $hours===0?0:$hours*3600;
            if (($db['keys'][$k]['first_use']??0)>0 && $hours>0) $db['keys'][$k]['expires'] = $db['keys'][$k]['first_use'] + $db['keys'][$k]['duration'];
            elseif ($hours===0) $db['keys'][$k]['expires'] = 0;
            saveDb(); $msg = 'Срок обновлён';
        }
        if ($act === 'set_max' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['max'] = max(1,(int)($_POST['max']??1)); saveDb(); $msg = 'Лимит устройств: '.$db['keys'][$k]['max'];
        }
        if ($act === 'transfer_key' && $k && isset($db['keys'][$k])) {
            $newTg = (int)($_POST['new_tg']??0);
            if ($newTg>0) { $db['keys'][$k]['owner_tg']=$newTg; saveDb(); addLog("Transfer $k → $newTg"); $msg = "Передан → $newTg"; }
        }
        if ($act === 'ban_hwid' && $k && isset($db['keys'][$k])) {
            $hwidBan = trim($_POST['hwid_ban']??'');
            if ($hwidBan!=='') {
                $db['blacklist'][$hwidBan] = ['time'=>time(),'reason'=>"From $k"];
                $db['keys'][$k]['activations'] = array_values(array_filter($db['keys'][$k]['activations']??[], fn($a)=>($a['hwid']??'')!==$hwidBan));
                saveDb(); addLog("HWID banned $hwidBan"); $msg = 'HWID в ЧС';
            }
        }
        if ($act === 'clear_activations' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['activations']=[]; saveDb(); $msg = 'Активации очищены';
        }
        if ($act === 'set_tag' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['tag'] = trim($_POST['tag']??''); saveDb(); $msg = 'Тег сохранён';
        }
        if ($act === 'soft_ban' && $k && isset($db['keys'][$k])) {
            $hours = max(1,(int)($_POST['ban_hours']??24));
            $db['keys'][$k]['soft_ban_until'] = time() + $hours*3600;
            saveDb(); $msg = "Временный бан на $hours ч";
        }
        if ($act === 'clear_soft_ban' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['soft_ban_until'] = 0; saveDb(); $msg = 'Временный бан снят';
        }
        if ($act === 'clone_key' && $k && isset($db['keys'][$k])) {
            $old = $db['keys'][$k];
            $newKey = (!empty($old['named']) ? $k.'_copy' : strtoupper($old['level']??'premium').'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8)));
            if (isset($db['keys'][$newKey])) $newKey .= '_'.substr(md5(time()),0,4);
            $db['keys'][$newKey] = $old;
            $db['keys'][$newKey]['activations'] = [];
            $db['keys'][$newKey]['first_use'] = 0;
            $db['keys'][$newKey]['expires'] = 0;
            $db['keys'][$newKey]['created'] = time();
            saveDb(); addLog("Clone $k → $newKey"); $msg = "Клон: <b>$newKey</b>";
        }
        if ($act === 'set_level' && $k && isset($db['keys'][$k])) {
            $lvl = in_array($_POST['level']??'', ['trial','free','media','premium','elite']) ? $_POST['level'] : 'premium';
            $db['keys'][$k]['level'] = $lvl; saveDb(); $msg = "Уровень: $lvl";
        }
        if ($act === 'set_expire_date' && $k && isset($db['keys'][$k])) {
            $date = trim($_POST['expire_date']??'');
            if ($date) {
                $ts = strtotime($date);
                if ($ts) { $db['keys'][$k]['expires'] = $ts; saveDb(); $msg = 'Дата окончания установлена'; }
            }
        }
        if ($act === 'reset_first_use' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['first_use'] = 0; $db['keys'][$k]['expires'] = 0; $db['keys'][$k]['activations'] = [];
            saveDb(); $msg = 'Сброс активации (как новый)';
        }
        if ($act === 'add_time_hours' && $k && isset($db['keys'][$k])) {
            $h = max(1,(int)($_POST['add_hours']??1));
            if (($db['keys'][$k]['expires']??0)==0) $db['keys'][$k]['expires'] = time()+$h*3600;
            else $db['keys'][$k]['expires'] += $h*3600;
            saveDb(); $msg = "+$h часов";
        }
        if ($act === 'set_owner_tg' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['owner_tg'] = (int)($_POST['owner_tg']??0); saveDb(); $msg = 'TG ID обновлён';
        }
        if ($act === 'set_android' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['android_id'] = trim($_POST['android_id']??''); saveDb(); $msg = 'Android ID сохранён';
        }
        if ($act === 'set_max_launches' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['max_launches'] = max(0,(int)($_POST['max_launches']??0)); saveDb(); $msg = 'Лимит запусков обновлён';
        }
        if ($act === 'make_lifetime' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['duration'] = 0; $db['keys'][$k]['expires'] = 0; saveDb(); $msg = 'Ключ навсегда';
        }
        if ($act === 'pause_key' && $k && isset($db['keys'][$k])) {
            // пауза = freeze + сохранить остаток
            if (empty($db['keys'][$k]['is_frozen'])) {
                $db['keys'][$k]['is_frozen'] = true;
                $db['keys'][$k]['paused_at'] = time();
                saveDb(); $msg = 'Ключ на паузе';
            } else {
                if (!empty($db['keys'][$k]['paused_at']) && ($db['keys'][$k]['expires']??0)>0) {
                    $paused = time() - $db['keys'][$k]['paused_at'];
                    $db['keys'][$k]['expires'] += $paused;
                }
                $db['keys'][$k]['is_frozen'] = false;
                unset($db['keys'][$k]['paused_at']);
                saveDb(); $msg = 'Ключ снят с паузы (время восстановлено)';
            }
        }

        // Global
        if ($act === 'toggle_global_freeze') {
            $db['settings']['global_freeze'] = empty($db['settings']['global_freeze']);
            saveDb(); $msg = !empty($db['settings']['global_freeze'])?'Глобальная заморозка ВКЛ':'Глобальная заморозка ВЫКЛ';
        }
        if ($act === 'set_status') {
            $db['settings']['status'] = $_POST['status']??'online';
            if ($db['settings']['status']!=='killswitch') $db['settings']['emergency_msg']='';
            saveDb(); $msg = 'Статус: '.$db['settings']['status'];
        }
        if ($act === 'set_soft_status') {
            $db['settings']['soft_status'] = $_POST['soft_status']??'undetected';
            saveDb(); $msg = 'Soft: '.$db['settings']['soft_status'];
        }
        if ($act === 'set_broadcast') {
            $db['settings']['broadcast'] = trim($_POST['broadcast']??'');
            saveDb(); $msg = $db['settings']['broadcast']!==''?'Broadcast установлен':'Broadcast очищен';
        }
        if ($act === 'add_blacklist') {
            $val = trim($_POST['value']??'');
            if ($val!=='') {
                $db['blacklist'][$val] = ['time'=>time(),'reason'=>trim($_POST['reason']??'')];
                saveDb(); addLog("BL: $val"); $msg = 'Добавлено в ЧС';
            }
        }
        if ($act === 'remove_blacklist' && !empty($_POST['value'])) {
            unset($db['blacklist'][$_POST['value']]); saveDb(); $msg = 'Удалено из ЧС';
        }
        if ($act === 'save_settings') {
            $db['settings']['version'] = trim($_POST['version']??$db['settings']['version']);
            $db['settings']['checksum'] = trim($_POST['checksum']??$db['settings']['checksum']);
            $db['settings']['download_url'] = trim($_POST['download_url']??$db['settings']['download_url']);
            $db['settings']['emergency_msg'] = trim($_POST['emergency_msg']??'');
            saveDb(); $msg = 'Настройки сохранены';
        }
        if ($act === 'set_panel_bg') {
            $db['settings']['panel_bg'] = trim($_POST['panel_bg']??'');
            $bgColor = trim($_POST['panel_bg_color']??'#030303');
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $bgColor)) $bgColor = '#030303';
            $db['settings']['panel_bg_color'] = $bgColor;
            $accent = trim($_POST['panel_accent']??'#22c55e');
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) $accent = '#22c55e';
            $db['settings']['panel_accent'] = $accent;
            $db['settings']['panel_overlay'] = max(0.3, min(0.95, (float)($_POST['panel_overlay']??0.75)));
            $db['settings']['panel_blur'] = max(0, min(40, (int)($_POST['panel_blur']??12)));
            saveDb(); $msg = 'Тема применена';
        }
        if ($act === 'bulk_freeze') {
            foreach ($db['keys'] as &$kd) $kd['is_frozen'] = true;
            unset($kd); saveDb(); $msg = 'Все ключи заморожены';
        }
        if ($act === 'bulk_unfreeze') {
            foreach ($db['keys'] as &$kd) $kd['is_frozen'] = false;
            unset($kd); saveDb(); $msg = 'Все ключи разморожены';
        }
    }

    function makeKeyData($duration, $max, $level, $owner_tg, $owner_name, $named) {
        return [
            'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
            'activations'=>[],'owner_tg'=>$owner_tg,'owner_name'=>$owner_name,'reset_left'=>3,
            'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
            'note'=>'','vip'=>false,'named'=>$named,'tag'=>'','soft_ban_until'=>0,
            'max_launches'=>0,'android_id'=>''
        ];
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
    $panelBgColor = $db['settings']['panel_bg_color'] ?? '#030303';
    $overlay = $db['settings']['panel_overlay'] ?? '0.75';
    $blur = (int)($db['settings']['panel_blur'] ?? 12);
    $rgb = sscanf($accent, "#%02x%02x%02x") ?: [34,197,94];

    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lori Elite</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
:root {
  --bg: <?= htmlspecialchars($panelBgColor) ?>;
  --card: #0a0a0c;
  --border: #161618;
  --accent: <?= htmlspecialchars($accent) ?>;
  --accent-rgb: <?= implode(',', $rgb) ?>;
  --text: #e8e8e8;
  --muted: #5a5a5a;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:13px}
<?php if ($panelBg): ?>
body::before{
  content:'';position:fixed;inset:0;z-index:-2;
  background-image:url('<?= htmlspecialchars($panelBg) ?>');
  background-size:cover;background-position:center;background-attachment:fixed;
  filter:blur(<?= $blur ?>px);transform:scale(1.05);
}
body::after{
  content:'';position:fixed;inset:0;z-index:-1;
  background:rgba(3,3,3,<?= $overlay ?>);pointer-events:none;
}
<?php else: ?>
body::before{
  content:'';position:fixed;inset:0;z-index:-1;
  background:radial-gradient(ellipse at 20% 15%,rgba(var(--accent-rgb),0.07),transparent 50%),
             radial-gradient(ellipse at 80% 85%,rgba(var(--accent-rgb),0.04),transparent 40%);
  pointer-events:none;
}
<?php endif; ?>

.header{background:rgba(8,8,10,0.9);padding:12px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100;backdrop-filter:blur(16px)}
.header h1{font-size:12px;color:var(--accent);letter-spacing:6px;font-weight:600}
.header a{color:var(--muted);text-decoration:none;margin-left:14px;font-size:12px;transition:.2s}
.header a:hover{color:var(--accent)}

.layout{display:flex;max-width:1280px;margin:0 auto}
.sidebar{width:168px;padding:14px 8px;border-right:1px solid var(--border);min-height:calc(100vh - 45px)}
.sidebar a{display:block;padding:9px 12px;border-radius:8px;color:var(--muted);text-decoration:none;margin-bottom:2px;font-size:12px;transition:.2s}
.sidebar a:hover,.sidebar a.active{background:rgba(var(--accent-rgb),0.1);color:var(--accent)}

.content{flex:1;padding:18px 16px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:10px;margin-bottom:18px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center;transition:.25s}
.stat:hover{border-color:rgba(var(--accent-rgb),0.3)}
.stat .num{font-size:22px;font-weight:700;color:var(--accent)}
.stat .label{font-size:10px;color:var(--muted);margin-top:3px;text-transform:uppercase;letter-spacing:0.5px}

.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:14px}
.card h2{font-size:12px;color:var(--accent);margin-bottom:14px;letter-spacing:0.5px;font-weight:600}

.btn{display:inline-block;padding:8px 14px;border-radius:9px;border:none;font-weight:500;cursor:pointer;font-size:12px;text-decoration:none;transition:.2s}
.btn-accent{background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 70%,#000));color:#000}
.btn-accent:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(var(--accent-rgb),0.3)}
.btn-dark{background:#121214;border:1px solid var(--border);color:var(--text)}
.btn-dark:hover{border-color:rgba(var(--accent-rgb),0.4);color:var(--accent)}
.btn-red{background:linear-gradient(135deg,#b91c1c,#7f1d1d);color:#fff}
.btn-sm{padding:5px 10px;font-size:11px}

.form-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;align-items:center}
input,select,textarea{padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:#0c0c0e;color:#fff;font-size:12px;outline:none;transition:.2s}
input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(var(--accent-rgb),0.12)}
textarea{width:100%;min-height:55px}
label{font-size:12px;color:var(--muted)}

table{width:100%;border-collapse:collapse;font-size:12px}
th,td{padding:8px 6px;text-align:left;border-bottom:1px solid #121214}
th{color:var(--accent);font-weight:500;font-size:11px}
.badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:500}
.badge-green{background:rgba(34,197,94,0.12);color:#4ade80}
.badge-red{background:rgba(239,68,68,0.12);color:#f87171}
.badge-yellow{background:rgba(234,179,8,0.12);color:#facc15}
.badge-blue{background:rgba(59,130,246,0.12);color:#60a5fa}
.msg{background:rgba(var(--accent-rgb),0.08);border:1px solid rgba(var(--accent-rgb),0.25);padding:12px 14px;border-radius:10px;margin-bottom:14px;color:#86efac;font-size:12px;animation:fadeIn .3s}
@keyframes fadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}

.keys-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:12px}
.key-mini{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px;transition:.25s;cursor:pointer;text-decoration:none;color:inherit;display:block}
.key-mini:hover{border-color:rgba(var(--accent-rgb),0.4);transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.3)}
.key-mini .km-top{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.key-mini .km-circle{width:40px;height:40px;border-radius:50%;background:conic-gradient(from -90deg,var(--accent) var(--p),#1a1a1a 0);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.key-mini .km-inner{width:30px;height:30px;border-radius:50%;background:var(--card);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:#fff}
.key-mini .km-name{font-size:13px;font-weight:600;color:#fff;word-break:break-all}
.key-mini .km-meta{font-size:11px;color:var(--muted);margin-top:2px}
.key-mini .km-row{display:flex;justify-content:space-between;font-size:11px;padding:3px 0;color:#888}
.key-mini .km-row span:last-child{color:#ccc}

/* ========== KEY MANAGEMENT — понятный ========== */
.kp{max-width:420px;margin:0 auto 20px;background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:0 0 50px rgba(var(--accent-rgb),0.06),0 20px 40px rgba(0,0,0,0.4);animation:fadeIn .35s}
.kp-head{padding:16px;display:flex;gap:14px;align-items:center;border-bottom:1px solid #121214;background:linear-gradient(180deg,rgba(var(--accent-rgb),0.06),transparent)}
.kp-circle{width:56px;height:56px;border-radius:50%;background:conic-gradient(from -90deg,var(--accent) var(--p),#1a1a1a 0);display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 0 24px rgba(var(--accent-rgb),0.25)}
.kp-circle-in{width:42px;height:42px;border-radius:50%;background:var(--card);display:flex;flex-direction:column;align-items:center;justify-content:center}
.kp-circle-in .n{font-size:16px;font-weight:700;color:#fff;line-height:1}
.kp-circle-in .l{font-size:8px;color:#666;text-transform:uppercase}
.kp-title{font-size:15px;font-weight:600;color:#fff;word-break:break-all}
.kp-tags{margin-top:4px;display:flex;flex-wrap:wrap;gap:5px}
.kp-tag{font-size:10px;padding:2px 7px;border-radius:4px;background:rgba(var(--accent-rgb),0.12);color:var(--accent);border:1px solid rgba(var(--accent-rgb),0.2)}
.kp-tag.vip{background:var(--accent);color:#000;font-weight:600;border:none}
.kp-status{margin-top:6px;font-size:12px;display:flex;align-items:center;gap:6px}
.kp-status .dot{width:7px;height:7px;border-radius:50%}

.kp-section{padding:12px 16px;border-bottom:1px solid #121214}
.kp-section:last-child{border-bottom:none}
.kp-section-title{font-size:10px;color:var(--accent);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;font-weight:600}
.kp-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px}
.kp-row .lbl{color:#666}
.kp-row .val{color:#ddd;font-weight:500;text-align:right;max-width:60%;word-break:break-all}
.kp-row .val.ok{color:#4ade80}
.kp-row .val.warn{color:#fbbf24}
.kp-row .val.bad{color:#f87171}

.kp-actions{padding:12px 14px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px}
.kp-btn{background:#111113;border:1px solid #1a1a1c;border-radius:9px;padding:11px 6px;color:#999;font-size:11px;font-weight:500;cursor:pointer;transition:.2s;text-align:center;text-decoration:none}
.kp-btn:hover{background:rgba(var(--accent-rgb),0.1);border-color:rgba(var(--accent-rgb),0.4);color:var(--accent)}
.kp-btn.green{color:#4ade80}
.kp-btn.red{color:#f87171}
.kp-btn.full{grid-column:1/-1}

.kp-form{padding:4px 16px 14px}
.kp-form .form-row{margin-bottom:8px}
.kp-form .hint{font-size:10px;color:#555;margin-bottom:6px}

@media(max-width:800px){
  .layout{flex-direction:column}
  .sidebar{width:100%;border-right:none;border-bottom:1px solid var(--border);display:flex;overflow-x:auto;gap:3px;padding:8px}
  .sidebar a{white-space:nowrap;margin:0}
  .keys-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
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
    <a href="?admin&tab=keys" class="<?= $tab==='keys'?'active':'' ?>">Ключи</a>
    <a href="?admin&tab=generate" class="<?= $tab==='generate'?'active':'' ?>">Создать</a>
    <a href="?admin&tab=bulk" class="<?= $tab==='bulk'?'active':'' ?>">Массово</a>
    <a href="?admin&tab=give" class="<?= $tab==='give'?'active':'' ?>">Выдать</a>
    <a href="?admin&tab=online" class="<?= $tab==='online'?'active':'' ?>">Онлайн</a>
    <a href="?admin&tab=broadcast" class="<?= $tab==='broadcast'?'active':'' ?>">Broadcast</a>
    <a href="?admin&tab=blacklist" class="<?= $tab==='blacklist'?'active':'' ?>">Чёрный список</a>
    <a href="?admin&tab=settings" class="<?= $tab==='settings'?'active':'' ?>">Настройки</a>
    <a href="?admin&tab=logs" class="<?= $tab==='logs'?'active':'' ?>">Логи</a>
    <a href="?admin&tab=theme" class="<?= $tab==='theme'?'active':'' ?>">Тема</a>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="msg"><?= $msg ?></div><?php endif; ?>

    <?php if ($viewKey && isset($db['keys'][$viewKey])):
      $kd = $db['keys'][$viewKey];
      $used = count($kd['activations'] ?? []);
      $max = $kd['max'] ?? 1;
      $warns = $kd['warns'] ?? 0;
      $ownerName = $kd['owner_name'] ?: '—';
      $isNamed = !empty($kd['named']);
      $tgId = $kd['owner_tg'] ?: 0;
      $android = $kd['android_id'] ?? '';
      $isVip = !empty($kd['vip']);
      $note = $kd['note'] ?? '';
      $tag = $kd['tag'] ?? '';
      $softBan = !empty($kd['soft_ban_until']) && time() < $kd['soft_ban_until'];
      $isFrozen = !empty($kd['is_frozen']);

      $now = time();
      $daysLeft = '∞'; $expiresStr = 'Навсегда'; $expiresClass = 'ok'; $circleP = 100;
      if (($kd['expires'] ?? 0) > 0) {
          $left = $kd['expires'] - $now;
          if ($left <= 0) { $daysLeft='0'; $expiresStr='Истёк'; $expiresClass='bad'; $circleP=0; }
          else { $daysLeft=(string)ceil($left/86400); $expiresStr=date('d.m.Y H:i',$kd['expires']); $circleP=min(100,max(5,($left/(30*86400))*100)); }
      } elseif (($kd['duration']??0)>0 && ($kd['first_use']??0)==0) {
          $daysLeft=(string)ceil($kd['duration']/86400); $expiresStr='После первой активации'; $circleP=min(100,max(5,($kd['duration']/(30*86400))*100));
      }

      if ($isFrozen) { $status='Заморожен'; $statusColor='#60a5fa'; }
      elseif ($softBan) { $status='Временный бан'; $statusColor='#f97316'; }
      elseif (($kd['first_use']??0)==0) { $status='Не активирован'; $statusColor='#facc15'; }
      elseif (($kd['expires']??0)>0 && $now>$kd['expires']) { $status='Истёк'; $statusColor='#f87171'; }
      else { $status='Активен'; $statusColor='#4ade80'; }

      $mainHwid = '—';
      if (!empty($kd['activations'])) $mainHwid = $kd['activations'][0]['hwid'] ?? '—';
    ?>

    <!-- ========== ПОНЯТНОЕ УПРАВЛЕНИЕ КЛЮЧОМ ========== -->
    <div class="kp">
      <!-- Шапка -->
      <div class="kp-head">
        <div class="kp-circle" style="--p:<?= $circleP ?>%">
          <div class="kp-circle-in"><div class="n"><?= $daysLeft ?></div><div class="l">дней</div></div>
        </div>
        <div style="flex:1;min-width:0">
          <div class="kp-title"><?= htmlspecialchars($viewKey) ?></div>
          <div class="kp-tags">
            <span class="kp-tag"><?= $isNamed ? 'Именной' : 'Обычный' ?></span>
            <?php if ($isVip): ?><span class="kp-tag vip">VIP</span><?php endif; ?>
            <?php if ($tag): ?><span class="kp-tag"><?= htmlspecialchars($tag) ?></span><?php endif; ?>
            <span class="kp-tag"><?= strtoupper($kd['level']??'') ?></span>
          </div>
          <div class="kp-status"><span class="dot" style="background:<?= $statusColor ?>"></span> <?= $status ?></div>
        </div>
      </div>

      <!-- Информация -->
      <div class="kp-section">
        <div class="kp-section-title">Информация</div>
        <div class="kp-row"><span class="lbl">Действует до</span><span class="val <?= $expiresClass ?>"><?= $expiresStr ?></span></div>
        <div class="kp-row"><span class="lbl">Владелец</span><span class="val"><?= htmlspecialchars($ownerName) ?></span></div>
        <div class="kp-row"><span class="lbl">Telegram ID</span><span class="val"><?= $tgId ?: '—' ?></span></div>
        <div class="kp-row"><span class="lbl">Android ID</span><span class="val"><?= $android ? htmlspecialchars($android) : 'не привязан' ?></span></div>
        <div class="kp-row"><span class="lbl">Основной HWID</span><span class="val" style="font-size:11px"><?= htmlspecialchars(strlen($mainHwid)>20?substr($mainHwid,0,18).'…':$mainHwid) ?></span></div>
        <div class="kp-row"><span class="lbl">Устройства</span><span class="val"><?= $used ?> из <?= $max ?></span></div>
        <div class="kp-row"><span class="lbl">Предупреждения</span><span class="val <?= $warns>0?'warn':'' ?>"><?= $warns ?> / 3</span></div>
        <?php if ($softBan): ?>
        <div class="kp-row"><span class="lbl">Бан до</span><span class="val bad"><?= date('d.m H:i',$kd['soft_ban_until']) ?></span></div>
        <?php endif; ?>
        <?php if ($note): ?>
        <div class="kp-row"><span class="lbl">Заметка</span><span class="val" style="font-size:11px"><?= htmlspecialchars($note) ?></span></div>
        <?php endif; ?>
      </div>

      <!-- Быстрые действия -->
      <div class="kp-section">
        <div class="kp-section-title">Быстрые действия</div>
        <div class="kp-actions" style="padding:0">
          <button class="kp-btn" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($viewKey) ?>');this.textContent='Скопировано';setTimeout(()=>this.textContent='Копировать',1000)">Копировать</button>

          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="freeze_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="kp-btn" type="submit"><?= $isFrozen ? 'Разморозить' : 'Заморозить' ?></button>
          </form>

          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="kp-btn" type="submit">Сброс HWID</button>
          </form>

          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="add_warn"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="kp-btn" type="submit">Варн (<?= $warns ?>/3)</button>
          </form>

          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="reset_warns"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="kp-btn" type="submit">Сброс варнов</button>
          </form>

          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="toggle_vip"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="kp-btn" type="submit"><?= $isVip ? 'Убрать VIP' : 'Сделать VIP' ?></button>
          </form>

          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="extend_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="hidden" name="days" value="7">
            <button class="kp-btn green" type="submit">+7 дней</button>
          </form>

          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="clone_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="kp-btn" type="submit">Клонировать</button>
          </form>

          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="pause_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="kp-btn" type="submit"><?= $isFrozen ? 'Снять паузу' : 'Пауза' ?></button>
          </form>

          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="make_lifetime"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="kp-btn green" type="submit">Навсегда</button>
          </form>

          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="clear_activations"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="kp-btn" type="submit">Очистить входы</button>
          </form>

          <form method="post" onsubmit="return confirm('Удалить ключ навсегда?')" style="display:contents">
            <input type="hidden" name="action" value="delete_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="kp-btn red" type="submit">Удалить</button>
          </form>
        </div>
      </div>

      <!-- Дополнительно -->
      <div class="kp-section">
        <div class="kp-section-title">Дополнительно</div>
        <div class="kp-form" style="padding:0">
          <div class="hint">Имя владельца</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="set_nick"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <input type="text" name="nick" value="<?= htmlspecialchars($kd['owner_name']??'') ?>" placeholder="Имя" style="flex:1">
            <button class="btn btn-dark btn-sm" type="submit">OK</button>
          </form>

          <div class="hint">Telegram ID владельца</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="set_owner_tg"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <input type="number" name="owner_tg" value="<?= $tgId?:'' ?>" placeholder="TG ID" style="flex:1">
            <button class="btn btn-dark btn-sm" type="submit">OK</button>
          </form>

          <div class="hint">Передать другому TG</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="transfer_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <input type="number" name="new_tg" placeholder="Новый TG ID" style="flex:1">
            <button class="btn btn-dark btn-sm" type="submit">Передать</button>
          </form>

          <div class="hint">Лимит устройств</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="set_max"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <input type="number" name="max" value="<?= $max ?>" min="1" style="width:60px">
            <button class="btn btn-dark btn-sm" type="submit">OK</button>
          </form>

          <div class="hint">Срок (часов, 0 = навсегда)</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="set_duration"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <input type="number" name="hours" placeholder="Часов" style="width:90px">
            <button class="btn btn-dark btn-sm" type="submit">OK</button>
          </form>

          <div class="hint">Добавить часы</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="add_time_hours"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <input type="number" name="add_hours" value="24" style="width:60px">
            <button class="btn btn-dark btn-sm" type="submit">+Часы</button>
          </form>

          <div class="hint">Точная дата окончания</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="set_expire_date"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <input type="datetime-local" name="expire_date" style="flex:1">
            <button class="btn btn-dark btn-sm" type="submit">OK</button>
          </form>

          <div class="hint">Android ID</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="set_android"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <input type="text" name="android_id" value="<?= htmlspecialchars($android) ?>" placeholder="Android ID" style="flex:1">
            <button class="btn btn-dark btn-sm" type="submit">OK</button>
          </form>

          <div class="hint">Тег</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="set_tag"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <input type="text" name="tag" value="<?= htmlspecialchars($tag) ?>" placeholder="Тег" style="flex:1">
            <button class="btn btn-dark btn-sm" type="submit">OK</button>
          </form>

          <div class="hint">Уровень</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="set_level"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <select name="level">
              <option value="trial" <?= ($kd['level']??'')==='trial'?'selected':'' ?>>Trial</option>
              <option value="free" <?= ($kd['level']??'')==='free'?'selected':'' ?>>Free</option>
              <option value="media" <?= ($kd['level']??'')==='media'?'selected':'' ?>>Media</option>
              <option value="premium" <?= ($kd['level']??'')==='premium'?'selected':'' ?>>Premium</option>
              <option value="elite" <?= ($kd['level']??'')==='elite'?'selected':'' ?>>Elite</option>
            </select>
            <button class="btn btn-dark btn-sm" type="submit">OK</button>
          </form>

          <div class="hint">Временный бан (часы)</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="soft_ban"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <input type="number" name="ban_hours" value="24" style="width:55px">
            <button class="btn btn-dark btn-sm" type="submit">Бан</button>
            <input type="hidden" name="action2" value="">
          </form>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="clear_soft_ban"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="btn btn-dark btn-sm" type="submit">Снять бан</button>
          </form>

          <div class="hint">Забанить HWID</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="ban_hwid"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <input type="text" name="hwid_ban" placeholder="HWID" style="flex:1">
            <button class="btn btn-dark btn-sm" type="submit">В ЧС</button>
          </form>

          <div class="hint">Сброс «как новый» (first use + активации)</div>
          <form method="post" class="form-row">
            <input type="hidden" name="action" value="reset_first_use"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="btn btn-dark btn-sm" type="submit">Сбросить как новый</button>
          </form>

          <?php if (empty($kd['named'])): ?>
          <div class="hint">Перегенерировать ключ</div>
          <form method="post" class="form-row" onsubmit="return confirm('Старый ключ исчезнет. Продолжить?')">
            <input type="hidden" name="action" value="regen_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <button class="btn btn-dark btn-sm" type="submit">Перегенерировать</button>
          </form>
          <?php endif; ?>

          <div class="hint">Заметка</div>
          <form method="post">
            <input type="hidden" name="action" value="set_note"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
            <textarea name="note" rows="2" placeholder="Приватная заметка..."><?= htmlspecialchars($note) ?></textarea>
            <button class="btn btn-dark btn-sm" type="submit" style="margin-top:6px">Сохранить заметку</button>
          </form>
        </div>
      </div>
    </div>

    <div style="text-align:center;margin-bottom:16px">
      <a href="?admin&tab=keys" class="btn btn-dark">← К списку ключей</a>
      <a href="?admin&tab=logs&filter=<?= urlencode($viewKey) ?>" class="btn btn-dark">Логи ключа</a>
    </div>

    <div class="card" style="max-width:640px;margin:0 auto">
      <h2>Активации (устройства)</h2>
      <?php if (empty($kd['activations'])): ?>
        <p style="color:var(--muted)">Нет активаций</p>
      <?php else: ?>
        <table>
          <tr><th>HWID</th><th>IP</th><th>Первый вход</th><th>Последний</th><th>Запусков</th></tr>
          <?php foreach ($kd['activations'] as $a): ?>
          <tr>
            <td><code style="font-size:10px"><?= htmlspecialchars($a['hwid']??'') ?></code></td>
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
        <div class="stat"><div class="num"><?= $totalKeys ?></div><div class="label">Ключи</div></div>
        <div class="stat"><div class="num"><?= $active ?></div><div class="label">Активные</div></div>
        <div class="stat"><div class="num"><?= $onlineCount ?></div><div class="label">Онлайн</div></div>
        <div class="stat"><div class="num"><?= $frozen ?></div><div class="label">Заморожены</div></div>
        <div class="stat"><div class="num"><?= $expired ?></div><div class="label">Истекли</div></div>
        <div class="stat"><div class="num"><?= count($db['blacklist']) ?></div><div class="label">ЧС</div></div>
      </div>
      <div class="card">
        <h2>Быстрые действия</h2>
        <div class="form-row">
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="online"><button class="btn btn-accent" type="submit">Online</button></form>
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="maintenance"><button class="btn btn-dark" type="submit">Maintenance</button></form>
          <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="killswitch"><button class="btn btn-red" type="submit">Killswitch</button></form>
          <form method="post"><input type="hidden" name="action" value="toggle_global_freeze"><button class="btn btn-dark" type="submit"><?= !empty($db['settings']['global_freeze'])?'Снять freeze':'Global Freeze' ?></button></form>
        </div>
        <div class="form-row">
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="undetected"><button class="btn btn-accent btn-sm" type="submit">Undetected</button></form>
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="updating"><button class="btn btn-dark btn-sm" type="submit">Updating</button></form>
          <form method="post"><input type="hidden" name="action" value="set_soft_status"><input type="hidden" name="soft_status" value="detected"><button class="btn btn-red btn-sm" type="submit">Detected</button></form>
          <form method="post"><input type="hidden" name="action" value="bulk_freeze"><button class="btn btn-dark btn-sm" type="submit">Заморозить все</button></form>
          <form method="post"><input type="hidden" name="action" value="bulk_unfreeze"><button class="btn btn-dark btn-sm" type="submit">Разморозить все</button></form>
        </div>
        <p style="margin-top:10px;color:var(--muted);font-size:12px">
          Статус: <b style="color:var(--accent)"><?= htmlspecialchars($db['settings']['status']) ?></b> ·
          Soft: <b><?= htmlspecialchars($db['settings']['soft_status']??'undetected') ?></b> ·
          Freeze: <b><?= !empty($db['settings']['global_freeze'])?'ВКЛ':'выкл' ?></b>
        </p>
      </div>

    <?php elseif ($tab === 'keys'): ?>
      <div class="card">
        <h2>Ключи (<?= $totalKeys ?>)</h2>
        <input type="text" id="searchKey" placeholder="Поиск..." onkeyup="filterKeys()" style="width:100%;max-width:260px;margin-bottom:14px">
        <div class="keys-grid" id="keysGrid">
          <?php foreach ($db['keys'] as $k => $kd):
            $used = count($kd['activations']??[]); $max = $kd['max']??1; $now = time();
            $daysLeft='∞'; $circleP=100;
            if (($kd['expires']??0)>0) {
              $left=$kd['expires']-$now;
              if ($left<=0){$daysLeft='0';$circleP=0;} else {$daysLeft=(string)ceil($left/86400);$circleP=min(100,max(5,($left/(30*86400))*100));}
            } elseif (($kd['duration']??0)>0 && ($kd['first_use']??0)==0) {
              $daysLeft=(string)ceil($kd['duration']/86400); $circleP=min(100,max(5,($kd['duration']/(30*86400))*100));
            }
            $stColor='#4ade80';
            if (!empty($kd['is_frozen'])) $stColor='#60a5fa';
            elseif (($kd['first_use']??0)==0) $stColor='#facc15';
            elseif (($kd['expires']??0)>0 && $now>$kd['expires']) $stColor='#f87171';
          ?>
          <a href="?admin&view=<?= urlencode($k) ?>" class="key-mini" data-search="<?= htmlspecialchars(strtolower($k.' '.($kd['owner_name']??'').' '.($kd['owner_tg']??'').' '.($kd['level']??'').' '.($kd['tag']??''))) ?>">
            <div class="km-top">
              <div class="km-circle" style="--p:<?= $circleP ?>%"><div class="km-inner"><?= $daysLeft ?></div></div>
              <div>
                <div class="km-name"><?= htmlspecialchars($k) ?></div>
                <div class="km-meta"><span style="color:<?= $stColor ?>">●</span> <?= htmlspecialchars($kd['level']??'') ?><?= !empty($kd['vip'])?' VIP':'' ?><?= !empty($kd['named'])?' · именной':'' ?></div>
              </div>
            </div>
            <div class="km-row"><span>Владелец</span><span><?= $kd['owner_tg']?:($kd['owner_name']?:'—') ?></span></div>
            <div class="km-row"><span>Устройства</span><span><?= $used ?>/<?= $max ?></span></div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

    <?php elseif ($tab === 'generate'): ?>
      <div class="card">
        <h2>Создать ключ</h2>
        <form method="post">
          <input type="hidden" name="action" value="gen_key">
          <div class="form-row"><input type="text" name="custom_name" placeholder="Именной ключ (оставь пустым — авто)" style="width:280px"></div>
          <div class="form-row">
            <input type="number" name="hours" value="24" style="width:90px" placeholder="Часов">
            <input type="number" name="max" value="1" min="1" style="width:55px" title="Устройств">
            <select name="level"><option value="trial">Trial</option><option value="free">Free</option><option value="media">Media</option><option value="premium" selected>Premium</option><option value="elite">Elite</option></select>
            <button class="btn btn-accent" type="submit">Создать</button>
          </div>
          <div class="form-row">
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=1">1ч</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=24">1д</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=72">3д</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=168">7д</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=720">30д</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=2160">90д</button>
            <button type="button" class="btn btn-dark btn-sm" onclick="this.form.hours.value=0">∞</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'bulk'): ?>
      <div class="card">
        <h2>Массовая генерация</h2>
        <form method="post">
          <input type="hidden" name="action" value="bulk_generate">
          <div class="form-row">
            <input type="number" name="count" value="10" min="1" max="50" style="width:55px">
            <input type="number" name="hours" value="24" style="width:80px">
            <input type="number" name="max" value="1" min="1" style="width:50px">
            <select name="level"><option value="trial">Trial</option><option value="free">Free</option><option value="media">Media</option><option value="premium" selected>Premium</option><option value="elite">Elite</option></select>
            <button class="btn btn-accent" type="submit">Сгенерировать</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'give'): ?>
      <div class="card">
        <h2>Выдать ключ</h2>
        <form method="post">
          <input type="hidden" name="action" value="give_key">
          <div class="form-row">
            <input type="number" name="tg_id" placeholder="Telegram ID" required style="width:140px">
            <input type="text" name="custom_name" placeholder="Именной (опц.)" style="width:130px">
            <input type="number" name="hours" value="168" style="width:70px">
            <input type="number" name="max" value="1" style="width:50px">
            <select name="level"><option value="premium" selected>Premium</option><option value="elite">Elite</option><option value="media">Media</option><option value="free">Free</option><option value="trial">Trial</option></select>
            <button class="btn btn-accent" type="submit">Выдать</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'online'): ?>
      <div class="card">
        <h2>Онлайн (<?= $onlineCount ?>)</h2>
        <?php if (empty($db['online'])): ?><p style="color:var(--muted)">Никого нет</p>
        <?php else: ?><table><tr><th>Ключ</th><th>IP</th><th>HWID</th><th>Пинг</th><th>С</th></tr>
          <?php foreach ($db['online'] as $hwid=>$info): ?><tr>
            <td><code><?= htmlspecialchars($info['key']??'—') ?></code></td>
            <td><?= htmlspecialchars($info['ip']??'') ?></td>
            <td style="font-size:10px"><?= htmlspecialchars(substr($hwid,0,16)) ?>…</td>
            <td><?= time()-($info['last_ping']??0) ?>с</td>
            <td style="font-size:11px"><?= !empty($info['first_seen'])?date('H:i:s',$info['first_seen']):'—' ?></td>
          </tr><?php endforeach; ?></table>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'broadcast'): ?>
      <div class="card">
        <h2>Broadcast</h2>
        <form method="post">
          <input type="hidden" name="action" value="set_broadcast">
          <textarea name="broadcast" placeholder="Сообщение всем..."><?= htmlspecialchars($db['settings']['broadcast']??'') ?></textarea>
          <div class="form-row" style="margin-top:8px">
            <button class="btn btn-accent" type="submit">Установить</button>
            <button class="btn btn-dark" type="submit" onclick="this.form.broadcast.value=''">Очистить</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'blacklist'): ?>
      <div class="card">
        <h2>Добавить в ЧС</h2>
        <form method="post" class="form-row">
          <input type="hidden" name="action" value="add_blacklist">
          <input type="text" name="value" placeholder="IP или HWID" required style="width:200px">
          <input type="text" name="reason" placeholder="Причина" style="width:140px">
          <button class="btn btn-red" type="submit">Добавить</button>
        </form>
      </div>
      <div class="card">
        <h2>Чёрный список (<?= count($db['blacklist']) ?>)</h2>
        <?php if (empty($db['blacklist'])): ?><p style="color:var(--muted)">Пусто</p>
        <?php else: ?><table><tr><th>Значение</th><th>Причина</th><th>Дата</th><th></th></tr>
          <?php foreach ($db['blacklist'] as $val=>$info): ?><tr>
            <td><code><?= htmlspecialchars($val) ?></code></td>
            <td><?= htmlspecialchars($info['reason']??'') ?></td>
            <td><?= date('d.m H:i',$info['time']??time()) ?></td>
            <td><form method="post"><input type="hidden" name="action" value="remove_blacklist"><input type="hidden" name="value" value="<?= htmlspecialchars($val) ?>"><button class="btn btn-dark btn-sm" type="submit">×</button></form></td>
          </tr><?php endforeach; ?></table>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'settings'): ?>
      <div class="card">
        <h2>Настройки</h2>
        <form method="post">
          <input type="hidden" name="action" value="save_settings">
          <div class="form-row"><label style="width:100px">Версия</label><input type="text" name="version" value="<?= htmlspecialchars($db['settings']['version']) ?>" style="width:130px"></div>
          <div class="form-row"><label style="width:100px">Checksum</label><input type="text" name="checksum" value="<?= htmlspecialchars($db['settings']['checksum']) ?>" style="width:260px"></div>
          <div class="form-row"><label style="width:100px">Ссылка</label><input type="text" name="download_url" value="<?= htmlspecialchars($db['settings']['download_url']) ?>" style="width:260px"></div>
          <div class="form-row" style="align-items:flex-start"><label style="width:100px;margin-top:6px">Emergency</label><textarea name="emergency_msg"><?= htmlspecialchars($db['settings']['emergency_msg']) ?></textarea></div>
          <button class="btn btn-accent" type="submit" style="margin-top:8px">Сохранить</button>
        </form>
      </div>

    <?php elseif ($tab === 'theme'): ?>
      <div class="card">
        <h2>Тема оформления</h2>
        <form method="post">
          <input type="hidden" name="action" value="set_panel_bg">
          <div class="form-row">
            <label style="width:120px">Картинка (URL)</label>
            <input type="text" name="panel_bg" value="<?= htmlspecialchars($db['settings']['panel_bg']??'') ?>" placeholder="https://..." style="flex:1;min-width:180px">
          </div>
          <div class="form-row">
            <label style="width:120px">Цвет фона</label>
            <input type="color" name="panel_bg_color" value="<?= htmlspecialchars($db['settings']['panel_bg_color']??'#030303') ?>" style="width:48px;height:32px;padding:2px;border:1px solid var(--border);background:#111;border-radius:6px">
            <span style="color:var(--muted);font-size:11px">если нет картинки</span>
          </div>
          <div class="form-row">
            <label style="width:120px">Акцент</label>
            <input type="color" name="panel_accent" value="<?= htmlspecialchars($db['settings']['panel_accent']??'#22c55e') ?>" style="width:48px;height:32px;padding:2px;border:1px solid var(--border);background:#111;border-radius:6px">
          </div>
          <div class="form-row">
            <label style="width:120px">Размытие</label>
            <input type="range" name="panel_blur" min="0" max="40" value="<?= (int)($db['settings']['panel_blur']??12) ?>" style="flex:1;max-width:200px" oninput="this.nextElementSibling.textContent=this.value+'px'">
            <span style="color:var(--muted);width:40px"><?= (int)($db['settings']['panel_blur']??12) ?>px</span>
          </div>
          <div class="form-row">
            <label style="width:120px">Затемнение</label>
            <input type="range" name="panel_overlay" min="0.3" max="0.95" step="0.01" value="<?= htmlspecialchars($db['settings']['panel_overlay']??'0.75') ?>" style="flex:1;max-width:200px" oninput="this.nextElementSibling.textContent=this.value">
            <span style="color:var(--muted);width:40px"><?= htmlspecialchars($db['settings']['panel_overlay']??'0.75') ?></span>
          </div>
          <div class="form-row" style="margin-top:12px">
            <button class="btn btn-accent" type="submit">Применить</button>
            <button class="btn btn-dark" type="submit" onclick="this.form.panel_bg.value='';this.form.panel_bg_color.value='#030303';this.form.panel_accent.value='#22c55e';this.form.panel_blur.value=12;this.form.panel_overlay.value=0.75">Сброс</button>
          </div>
        </form>
        <p style="color:var(--muted);font-size:11px;margin-top:12px">
          Картинка — фон с размытием. Цвет фона — если картинки нет. Акцент красит кнопки, круги, свечение. Размытие 0–40px. Затемнение 0.3–0.95.
        </p>
      </div>

    <?php elseif ($tab === 'logs'): ?>
      <div class="card">
        <h2>Логи</h2>
        <?php
          $filter = $_GET['filter'] ?? '';
          $logs = $db['logs'];
          if ($filter) $logs = array_filter($logs, fn($l) => strpos($l['text'], $filter) !== false);
          foreach (array_slice($logs, 0, 80) as $l):
        ?>
          <div style="font-size:11px;padding:4px 0;border-bottom:1px solid #101012">
            <span style="color:var(--accent)"><?= date('d.m H:i:s', $l['time']) ?></span> — <?= htmlspecialchars($l['text']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
function filterKeys(){
  const q=document.getElementById('searchKey').value.toLowerCase();
  document.querySelectorAll('#keysGrid .key-mini').forEach(el=>{
    el.style.display = el.dataset.search.includes(q) ? '' : 'none';
  });
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
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Lori</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#030303;color:#22c55e;font-family:Inter,system-ui;letter-spacing:4px;font-size:13px}</style></head><body>LORI</body></html>';
    exit;
}

function tgRequest($method,$data){global $botToken;$opts=['http'=>['header'=>"Content-Type: application/json\r\n",'method'=>'POST','content'=>json_encode($data,JSON_UNESCAPED_UNICODE),'ignore_errors'=>true]];return @file_get_contents("https://api.telegram.org/bot$botToken/$method",false,stream_context_create($opts));}
function sendMessage($chat_id,$text,$kb=null){$d=['chat_id'=>$chat_id,'text'=>$text,'parse_mode'=>'Markdown','disable_web_page_preview'=>true];if($kb)$d['reply_markup']=$kb;return tgRequest('sendMessage',$d);}
function editMessage($chat_id,$msg_id,$text,$kb=null){$d=['chat_id'=>$chat_id,'message_id'=>$msg_id,'text'=>$text,'parse_mode'=>'Markdown','disable_web_page_preview'=>true];if($kb)$d['reply_markup']=$kb;return tgRequest('editMessageText',$d);}
function answerCallback($cq_id,$text='',$alert=false){tgRequest('answerCallbackQuery',['callback_query_id'=>$cq_id,'text'=>$text,'show_alert'=>$alert]);}
function sendInvoice($chat_id,$title,$desc,$payload,$stars){tgRequest('sendInvoice',['chat_id'=>$chat_id,'title'=>$title,'description'=>$desc,'payload'=>$payload,'currency'=>'XTR','prices'=>[['label'=>'Stars','amount'=>$stars]]]);}

if (isset($update['pre_checkout_query'])) { tgRequest('answerPreCheckoutQuery',['pre_checkout_query_id'=>$update['pre_checkout_query']['id'],'ok'=>true]); exit; }
if (isset($update['message']['successful_payment'])) {
    $chatId=$update['message']['chat']['id']; $parts=explode('_',$update['message']['successful_payment']['invoice_payload']);
    $hours=(int)($parts[1]??24); $duration=$hours===0?0:$hours*3600;
    $newKey='PREMIUM-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
    $db['keys'][$newKey]=['duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>1,'activations'=>[],'owner_tg'=>$chatId,'owner_name'=>'','reset_left'=>2,'is_frozen'=>false,'level'=>'premium','created'=>time(),'warns'=>0,'note'=>'','vip'=>false,'named'=>false,'tag'=>'','soft_ban_until'=>0,'max_launches'=>0,'android_id'=>''];
    saveDb(); addLog("Bought $newKey by $chatId"); sendMessage($chatId,"Оплата прошла.\n\nКлюч:\n`$newKey`"); exit;
}

if (isset($update['message'])) {
    $chatId=(int)$update['message']['chat']['id']; $text=trim($update['message']['text']??''); $isAdmin=($chatId===$adminId);
    if ($isAdmin && ($text==='/admin'||$text==='/panel')) {
        sendMessage($chatId,"Admin\nКлючи: *".count($db['keys'])."*\nОнлайн: *".count($db['online'])."*",['inline_keyboard'=>[[['text'=>'Keys','callback_data'=>'adm_keys']],[['text'=>'Online','callback_data'=>'adm_online']],[['text'=>'Killswitch','callback_data'=>'toggle_kill'],['text'=>'Freeze','callback_data'=>'toggle_gfreeze']]]]); exit;
    }
    if ($isAdmin && strpos($text,'/gen ')===0) {
        $a=explode(' ',$text); $hours=(int)($a[1]??24); $max=(int)($a[2]??1); $level=$a[3]??'premium'; $name=$a[4]??''; $duration=$hours===0?0:$hours*3600;
        if ($name) {
            $newKey=$name; if(isset($db['keys'][$newKey])){sendMessage($chatId,'Имя занято');exit;}
            $db['keys'][$newKey]=['duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,'activations'=>[],'owner_tg'=>0,'owner_name'=>$name,'reset_left'=>999,'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,'note'=>'','vip'=>false,'named'=>true,'tag'=>'','soft_ban_until'=>0,'max_launches'=>0,'android_id'=>''];
        } else {
            $newKey=strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
            $db['keys'][$newKey]=['duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>999,'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,'note'=>'','vip'=>false,'named'=>false,'tag'=>'','soft_ban_until'=>0,'max_launches'=>0,'android_id'=>''];
        }
        saveDb(); sendMessage($chatId,"Ключ: `$newKey`"); exit;
    }
    if ($text==='/start') {
        $kb=['inline_keyboard'=>[[['text'=>'1ч — 10★','callback_data'=>'buy_1_1']],[['text'=>'24ч — 25★','callback_data'=>'buy_24_1']],[['text'=>'3д — 50★','callback_data'=>'buy_72_1']],[['text'=>'7д — 75★','callback_data'=>'buy_168_1']],[['text'=>'30д — 125★','callback_data'=>'buy_720_1']],[['text'=>'90д — 250★','callback_data'=>'buy_2160_1']],[['text'=>'Навсегда — 400★','callback_data'=>'buy_0_1']],[['text'=>'Мои ключи','callback_data'=>'my_keys']]]];
        if($isAdmin)$kb['inline_keyboard'][]=[['text'=>'Admin','callback_data'=>'admin_panel']];
        sendMessage($chatId,"Lori Elite\nВыберите срок:",$kb);
    }
}

if (isset($update['callback_query'])) {
    $cq=$update['callback_query']; $chatId=(int)$cq['message']['chat']['id']; $data=$cq['data']; $msgId=$cq['message']['message_id']; $cqId=$cq['id']; $isAdmin=($chatId===$adminId);
    if (strpos($data,'buy_')===0) { $p=explode('_',$data); $h=(int)$p[1]; $m=[1=>10,24=>25,72=>50,168=>75,720=>125,2160=>250,0=>400]; sendInvoice($chatId,'Lori Key','Access',"sub_{$h}_1",$m[$h]??25); answerCallback($cqId); exit; }
    if ($data==='my_keys') {
        $f=false; foreach($db['keys'] as $k=>$kd){ if(($kd['owner_tg']??0)==$chatId){ $f=true; $u=count($kd['activations']??[]); $mx=$kd['max']??1;
            if(!empty($kd['is_frozen']))$st='Заморожен'; elseif(($kd['first_use']??0)==0)$st='Новый'; elseif(($kd['expires']??0)==0)$st='Навсегда'; elseif(time()>$kd['expires'])$st='Истёк'; else{$l=$kd['expires']-time();$st=floor($l/86400).'д';}
            sendMessage($chatId,"`$k`\n$st\n$u/$mx",['inline_keyboard'=>[[['text'=>'Сброс HWID','callback_data'=>'user_reset_'.$k]]]]); }}
        if(!$f) sendMessage($chatId,'Ключей нет'); answerCallback($cqId); exit;
    }
    if (strpos($data,'user_reset_')===0) { $key=str_replace('user_reset_','',$data); if(isset($db['keys'][$key])&&($db['keys'][$key]['owner_tg']??0)==$chatId){ if(($db['keys'][$key]['reset_left']??0)>0){$db['keys'][$key]['activations']=[];$db['keys'][$key]['reset_left']--;saveDb();answerCallback($cqId,'Сброшено');}else answerCallback($cqId,'Лимит',true);} exit; }
    if (!$isAdmin) { answerCallback($cqId,'Нет доступа',true); exit; }
    if ($data==='admin_panel') { editMessage($chatId,$msgId,"Admin\nКлючи: *".count($db['keys'])."*",['inline_keyboard'=>[[['text'=>'Keys','callback_data'=>'adm_keys']],[['text'=>'Online','callback_data'=>'adm_online']],[['text'=>'Killswitch','callback_data'=>'toggle_kill'],['text'=>'Freeze','callback_data'=>'toggle_gfreeze']]]]); answerCallback($cqId); exit; }
    if ($data==='adm_keys'||strpos($data,'adm_keys_')===0) {
        $page=strpos($data,'adm_keys_')===0?(int)str_replace('adm_keys_','',$data):0; $keys=array_keys($db['keys']); $per=8; $total=count($keys); $pages=max(1,ceil($total/$per)); $slice=array_slice($keys,$page*$per,$per);
        $kb=['inline_keyboard'=>[]]; foreach($slice as $k){ $kd=$db['keys'][$k]; $st=!empty($kd['is_frozen'])?'F':((($kd['expires']??0)>0&&time()>$kd['expires'])?'E':'A'); $kb['inline_keyboard'][]=[['text'=>"[$st] $k",'callback_data'=>'k_view_'.$k]]; }
        $nav=[]; if($page>0)$nav[]=['text'=>'‹','callback_data'=>'adm_keys_'.($page-1)]; $nav[]=['text'=>($page+1)."/$pages",'callback_data'=>'noop']; if($page<$pages-1)$nav[]=['text'=>'›','callback_data'=>'adm_keys_'.($page+1)]; if($nav)$kb['inline_keyboard'][]=$nav; $kb['inline_keyboard'][]=[['text'=>'Назад','callback_data'=>'admin_panel']];
        editMessage($chatId,$msgId,"Ключи ($total)",$kb); answerCallback($cqId); exit;
    }
    if (strpos($data,'k_view_')===0) {
        $key=str_replace('k_view_','',$data); if(!isset($db['keys'][$key])){answerCallback($cqId,'?',true);exit;} $kd=$db['keys'][$key]; $u=count($kd['activations']??[]); $mx=$kd['max']??1; $w=$kd['warns']??0;
        $st=!empty($kd['is_frozen'])?'frozen':((($kd['expires']??0)>0&&time()>$kd['expires'])?'expired':'active');
        editMessage($chatId,$msgId,"`$key`\n$st\n$u/$mx\nWarns $w/3",['inline_keyboard'=>[[['text'=>'Сброс HWID','callback_data'=>'k_rhwid_'.$key],['text'=>!empty($kd['is_frozen'])?'Размор.':'Замор.','callback_data'=>'k_freeze_'.$key]],[['text'=>'Варн','callback_data'=>'k_warn_'.$key],['text'=>'Удалить','callback_data'=>'k_del_'.$key]],[['text'=>'Назад','callback_data'=>'adm_keys']]]]); answerCallback($cqId); exit;
    }
    if (strpos($data,'k_rhwid_')===0){$key=str_replace('k_rhwid_','',$data);if(isset($db['keys'][$key])){$db['keys'][$key]['activations']=[];saveDb();answerCallback($cqId,'OK');}exit;}
    if (strpos($data,'k_freeze_')===0){$key=str_replace('k_freeze_','',$data);if(isset($db['keys'][$key])){$db['keys'][$key]['is_frozen']=empty($db['keys'][$key]['is_frozen']);saveDb();answerCallback($cqId,!empty($db['keys'][$key]['is_frozen'])?'Frozen':'Unfrozen');}exit;}
    if (strpos($data,'k_warn_')===0){$key=str_replace('k_warn_','',$data);if(isset($db['keys'][$key])){$db['keys'][$key]['warns']=min(3,($db['keys'][$key]['warns']??0)+1);saveDb();answerCallback($cqId,'Warned');}exit;}
    if (strpos($data,'k_del_')===0){$key=str_replace('k_del_','',$data);unset($db['keys'][$key]);saveDb();answerCallback($cqId,'Deleted');exit;}
    if ($data==='adm_online'){$t="Онлайн (".count($db['online']).")\n\n";if(empty($db['online']))$t.='Пусто';else foreach($db['online'] as $h=>$i)$t.="`{$i['key']}` | {$i['ip']}\n";editMessage($chatId,$msgId,$t,['inline_keyboard'=>[[['text'=>'Назад','callback_data'=>'admin_panel']]]]);answerCallback($cqId);exit;}
    if ($data==='toggle_kill'){if($db['settings']['status']==='killswitch'){$db['settings']['status']='online';$db['settings']['emergency_msg']='';answerCallback($cqId,'OFF');}else{$db['settings']['status']='killswitch';$db['settings']['emergency_msg']='Stopped';answerCallback($cqId,'ON');}saveDb();exit;}
    if ($data==='toggle_gfreeze'){$db['settings']['global_freeze']=empty($db['settings']['global_freeze']);saveDb();answerCallback($cqId,!empty($db['settings']['global_freeze'])?'Freeze ON':'Freeze OFF');exit;}
    if ($data==='noop'){answerCallback($cqId);exit;}
}
