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
    'version' => '3.0.0',
    'checksum' => '5c8714cf5eb4010c2cad5b14ac476f3f3f695d26',
    'download_url' => 'https://example.com/script.lua',
    'emergency_msg' => '',
    'broadcast' => '',
    'panel_bg' => '',
    'panel_accent' => '#22c55e',
    'panel_overlay' => '0.88'
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
        $loginError = 'Wrong password';
    }

    if (empty($_SESSION['admin'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Lori</title>
        <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap");
        *{margin:0;padding:0;box-sizing:border-box}
        body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#030303;font-family:Inter,system-ui;color:#e5e5e5;overflow:hidden}
        body::before{content:"";position:fixed;inset:0;background:radial-gradient(ellipse at 20% 30%,rgba(34,197,94,0.07),transparent 50%),radial-gradient(ellipse at 80% 70%,rgba(34,197,94,0.04),transparent 40%);pointer-events:none}
        .box{position:relative;width:100%;max-width:360px;padding:48px 36px;background:rgba(12,12,14,0.95);border:1px solid rgba(34,197,94,0.15);border-radius:20px;backdrop-filter:blur(20px);box-shadow:0 0 80px rgba(34,197,94,0.06),0 30px 60px rgba(0,0,0,0.5)}
        .box::before{content:"";position:absolute;top:0;left:15%;right:15%;height:1px;background:linear-gradient(90deg,transparent,#22c55e,transparent)}
        .logo{text-align:center;font-size:11px;letter-spacing:10px;color:#22c55e;font-weight:500;margin-bottom:24px}
        h1{font-size:20px;font-weight:600;text-align:center;margin-bottom:6px;color:#fff}
        .sub{text-align:center;font-size:10px;color:#444;margin-bottom:32px;letter-spacing:2px}
        input{width:100%;padding:14px 16px;background:#0a0a0c;border:1px solid #1a1a1a;border-radius:12px;color:#fff;font-size:13px;outline:none;margin-bottom:12px;transition:.25s}
        input:focus{border-color:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,0.12)}
        button{width:100%;padding:14px;background:linear-gradient(135deg,#22c55e,#16a34a);border:none;border-radius:12px;color:#000;font-size:13px;font-weight:600;cursor:pointer;transition:.25s;letter-spacing:0.5px}
        button:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(34,197,94,0.3)}
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
                if (isset($db['keys'][$newKey])) $msg = 'Name already exists';
                else {
                    $db['keys'][$newKey] = [
                        'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                        'activations'=>[],'owner_tg'=>0,'owner_name'=>$customName,'reset_left'=>3,
                        'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
                        'note'=>'','vip'=>false,'named'=>true,'tag'=>'','soft_ban_until'=>0,'max_launches'=>0
                    ];
                    saveDb(); addLog("Named key: $newKey"); $msg = "Named key created: <b>$newKey</b>";
                }
            } else {
                $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                $db['keys'][$newKey] = [
                    'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                    'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>3,
                    'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
                    'note'=>'','vip'=>false,'named'=>false,'tag'=>'','soft_ban_until'=>0,'max_launches'=>0
                ];
                saveDb(); addLog("Key: $newKey"); $msg = "Key created: <b>$newKey</b>";
            }
        }

        if ($act === 'bulk_generate') {
            $count = max(1, min(50, (int)($_POST['count'] ?? 10)));
            $hours = (int)($_POST['hours'] ?? 24);
            $max = max(1, (int)($_POST['max'] ?? 1));
            $level = in_array($_POST['level']??'', ['trial','free','media','premium','elite']) ? $_POST['level'] : 'premium';
            $duration = $hours === 0 ? 0 : $hours * 3600;
            $list = [];
            for ($i=0;$i<$count;$i++) {
                $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand().$i,true)),0,8));
                $db['keys'][$newKey] = [
                    'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                    'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>2,
                    'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
                    'note'=>'','vip'=>false,'named'=>false,'tag'=>'','soft_ban_until'=>0,'max_launches'=>0
                ];
                $list[] = $newKey;
            }
            saveDb(); addLog("Bulk $count"); $msg = "Created <b>$count</b>";
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
                    if (isset($db['keys'][$newKey])) $msg = 'Name taken';
                    else {
                        $db['keys'][$newKey] = [
                            'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                            'activations'=>[],'owner_tg'=>$tgId,'owner_name'=>$customName,'reset_left'=>3,
                            'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
                            'note'=>'','vip'=>false,'named'=>true,'tag'=>'','soft_ban_until'=>0,'max_launches'=>0
                        ];
                        saveDb(); addLog("Given named $newKey → $tgId");
                        @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$tgId&text=".urlencode("Your key:\n$newKey"));
                        $msg = "Given: <b>$newKey</b>";
                    }
                } else {
                    $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                    $db['keys'][$newKey] = [
                        'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
                        'activations'=>[],'owner_tg'=>$tgId,'owner_name'=>'','reset_left'=>3,
                        'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,
                        'note'=>'','vip'=>false,'named'=>false,'tag'=>'','soft_ban_until'=>0,'max_launches'=>0
                    ];
                    saveDb(); addLog("Given $newKey → $tgId");
                    @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$tgId&text=".urlencode("Your key:\n$newKey"));
                    $msg = "Given: <b>$newKey</b>";
                }
            } else $msg = 'Enter TG ID';
        }

        // KEY ACTIONS (20+)
        if ($act === 'freeze_key' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['is_frozen'] = empty($db['keys'][$k]['is_frozen']);
            saveDb(); $msg = !empty($db['keys'][$k]['is_frozen']) ? 'Frozen' : 'Unfrozen';
        }
        if ($act === 'reset_hwid' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['activations'] = []; saveDb(); addLog("HWID reset $k"); $msg = 'HWID reset';
        }
        if ($act === 'delete_key' && $k) {
            unset($db['keys'][$k]); saveDb(); addLog("Deleted $k"); header('Location: ?admin&tab=keys'); exit;
        }
        if ($act === 'add_warn' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['warns'] = min(3, ($db['keys'][$k]['warns']??0)+1); saveDb(); $msg = 'Warn '.($db['keys'][$k]['warns']).'/3';
        }
        if ($act === 'reset_warns' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['warns'] = 0; saveDb(); $msg = 'Warns reset';
        }
        if ($act === 'regen_key' && $k && isset($db['keys'][$k])) {
            $old = $db['keys'][$k];
            if (!empty($old['named'])) $msg = 'Named keys cannot be regenerated';
            else {
                $level = $old['level']??'premium';
                $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                $db['keys'][$newKey] = $old; $db['keys'][$newKey]['activations'] = [];
                unset($db['keys'][$k]); saveDb(); addLog("Regen $k → $newKey");
                header('Location: ?admin&view='.urlencode($newKey)); exit;
            }
        }
        if ($act === 'set_nick' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['owner_name'] = trim($_POST['nick']??''); saveDb(); $msg = 'Name updated';
        }
        if ($act === 'set_note' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['note'] = trim($_POST['note']??''); saveDb(); $msg = 'Note saved';
        }
        if ($act === 'toggle_vip' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['vip'] = empty($db['keys'][$k]['vip']); saveDb(); $msg = !empty($db['keys'][$k]['vip'])?'VIP ON':'VIP OFF';
        }
        if ($act === 'extend_key' && $k && isset($db['keys'][$k])) {
            $days = max(1,(int)($_POST['days']??7));
            if (($db['keys'][$k]['expires']??0)==0) $db['keys'][$k]['expires'] = time()+$days*86400;
            else $db['keys'][$k]['expires'] += $days*86400;
            saveDb(); $msg = "+$days days";
        }
        if ($act === 'set_duration' && $k && isset($db['keys'][$k])) {
            $hours = (int)($_POST['hours']??0);
            $db['keys'][$k]['duration'] = $hours===0?0:$hours*3600;
            if (($db['keys'][$k]['first_use']??0)>0 && $hours>0) $db['keys'][$k]['expires'] = $db['keys'][$k]['first_use'] + $db['keys'][$k]['duration'];
            elseif ($hours===0) $db['keys'][$k]['expires'] = 0;
            saveDb(); $msg = 'Duration updated';
        }
        if ($act === 'set_max' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['max'] = max(1,(int)($_POST['max']??1)); saveDb(); $msg = 'Device limit updated';
        }
        if ($act === 'transfer_key' && $k && isset($db['keys'][$k])) {
            $newTg = (int)($_POST['new_tg']??0);
            if ($newTg>0) { $db['keys'][$k]['owner_tg']=$newTg; saveDb(); addLog("Transfer $k → $newTg"); $msg = "Transferred to $newTg"; }
        }
        if ($act === 'ban_hwid' && $k && isset($db['keys'][$k])) {
            $hwidBan = trim($_POST['hwid_ban']??'');
            if ($hwidBan!=='') {
                $db['blacklist'][$hwidBan] = ['time'=>time(),'reason'=>"From $k"];
                $db['keys'][$k]['activations'] = array_values(array_filter($db['keys'][$k]['activations']??[], fn($a)=>($a['hwid']??'')!==$hwidBan));
                saveDb(); addLog("HWID banned $hwidBan"); $msg = 'HWID banned';
            }
        }
        if ($act === 'clear_activations' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['activations']=[]; saveDb(); $msg = 'Activations cleared';
        }
        if ($act === 'set_tag' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['tag'] = trim($_POST['tag']??''); saveDb(); $msg = 'Tag set';
        }
        if ($act === 'soft_ban' && $k && isset($db['keys'][$k])) {
            $hours = max(1,(int)($_POST['ban_hours']??24));
            $db['keys'][$k]['soft_ban_until'] = time() + $hours*3600;
            saveDb(); $msg = "Soft ban $hours h";
        }
        if ($act === 'clear_soft_ban' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['soft_ban_until'] = 0; saveDb(); $msg = 'Soft ban cleared';
        }
        if ($act === 'set_max_launches' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['max_launches'] = max(0,(int)($_POST['max_launches']??0)); saveDb(); $msg = 'Max launches set';
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
            $db['keys'][$newKey]['named'] = !empty($old['named']);
            saveDb(); addLog("Cloned $k → $newKey"); $msg = "Cloned: <b>$newKey</b>";
        }
        if ($act === 'set_level' && $k && isset($db['keys'][$k])) {
            $lvl = in_array($_POST['level']??'', ['trial','free','media','premium','elite']) ? $_POST['level'] : 'premium';
            $db['keys'][$k]['level'] = $lvl; saveDb(); $msg = "Level → $lvl";
        }
        if ($act === 'set_expire_date' && $k && isset($db['keys'][$k])) {
            $date = trim($_POST['expire_date']??'');
            if ($date) {
                $ts = strtotime($date);
                if ($ts) { $db['keys'][$k]['expires'] = $ts; saveDb(); $msg = 'Expire date set'; }
            }
        }
        if ($act === 'reset_first_use' && $k && isset($db['keys'][$k])) {
            $db['keys'][$k]['first_use'] = 0; $db['keys'][$k]['expires'] = 0; $db['keys'][$k]['activations'] = [];
            saveDb(); $msg = 'First use + activations reset';
        }
        if ($act === 'add_time_hours' && $k && isset($db['keys'][$k])) {
            $h = max(1,(int)($_POST['add_hours']??1));
            if (($db['keys'][$k]['expires']??0)==0) $db['keys'][$k]['expires'] = time()+$h*3600;
            else $db['keys'][$k]['expires'] += $h*3600;
            saveDb(); $msg = "+$h hours";
        }

        // Global
        if ($act === 'toggle_global_freeze') {
            $db['settings']['global_freeze'] = empty($db['settings']['global_freeze']);
            saveDb(); $msg = !empty($db['settings']['global_freeze'])?'Global freeze ON':'Global freeze OFF';
        }
        if ($act === 'set_status') {
            $db['settings']['status'] = $_POST['status']??'online';
            if ($db['settings']['status']!=='killswitch') $db['settings']['emergency_msg']='';
            saveDb(); $msg = 'Status: '.$db['settings']['status'];
        }
        if ($act === 'set_soft_status') {
            $db['settings']['soft_status'] = $_POST['soft_status']??'undetected';
            saveDb(); $msg = 'Soft: '.$db['settings']['soft_status'];
        }
        if ($act === 'set_broadcast') {
            $db['settings']['broadcast'] = trim($_POST['broadcast']??'');
            saveDb(); $msg = $db['settings']['broadcast']!==''?'Broadcast set':'Broadcast cleared';
        }
        if ($act === 'add_blacklist') {
            $val = trim($_POST['value']??'');
            if ($val!=='') {
                $db['blacklist'][$val] = ['time'=>time(),'reason'=>trim($_POST['reason']??'')];
                saveDb(); addLog("BL: $val"); $msg = 'Added to blacklist';
            }
        }
        if ($act === 'remove_blacklist' && !empty($_POST['value'])) {
            unset($db['blacklist'][$_POST['value']]); saveDb(); $msg = 'Removed';
        }
        if ($act === 'save_settings') {
            $db['settings']['version'] = trim($_POST['version']??$db['settings']['version']);
            $db['settings']['checksum'] = trim($_POST['checksum']??$db['settings']['checksum']);
            $db['settings']['download_url'] = trim($_POST['download_url']??$db['settings']['download_url']);
            $db['settings']['emergency_msg'] = trim($_POST['emergency_msg']??'');
            saveDb(); $msg = 'Settings saved';
        }
        if ($act === 'set_panel_bg') {
            $db['settings']['panel_bg'] = trim($_POST['panel_bg']??'');
            $accent = trim($_POST['panel_accent']??'#22c55e');
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) $accent = '#22c55e';
            $db['settings']['panel_accent'] = $accent;
            $db['settings']['panel_overlay'] = max(0.5, min(0.95, (float)($_POST['panel_overlay']??0.88)));
            saveDb(); $msg = 'Theme applied';
        }
        if ($act === 'bulk_freeze') {
            foreach ($db['keys'] as &$kd) $kd['is_frozen'] = true;
            unset($kd); saveDb(); $msg = 'All keys frozen';
        }
        if ($act === 'bulk_unfreeze') {
            foreach ($db['keys'] as &$kd) $kd['is_frozen'] = false;
            unset($kd); saveDb(); $msg = 'All keys unfrozen';
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
    $overlay = $db['settings']['panel_overlay'] ?? '0.88';

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
  --bg: #030303;
  --card: #0a0a0c;
  --border: #161618;
  --accent: <?= htmlspecialchars($accent) ?>;
  --accent-rgb: <?= implode(',', sscanf($accent, "#%02x%02x%02x") ?: [34,197,94]) ?>;
  --text: #e8e8e8;
  --muted: #5a5a5a;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:13px}
<?php if ($panelBg): ?>
body{background-image:url('<?= htmlspecialchars($panelBg) ?>');background-size:cover;background-attachment:fixed;background-position:center}
body::before{content:'';position:fixed;inset:0;background:rgba(3,3,3,<?= $overlay ?>);z-index:-1;pointer-events:none}
<?php else: ?>
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 15% 20%,rgba(var(--accent-rgb),0.06),transparent 50%),radial-gradient(ellipse at 85% 80%,rgba(var(--accent-rgb),0.04),transparent 40%);z-index:-1;pointer-events:none}
<?php endif; ?>

.header{background:rgba(8,8,10,0.92);padding:12px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100;backdrop-filter:blur(16px)}
.header h1{font-size:12px;color:var(--accent);letter-spacing:6px;font-weight:600}
.header a{color:var(--muted);text-decoration:none;margin-left:14px;font-size:12px;transition:.2s}
.header a:hover{color:var(--accent)}

.layout{display:flex;max-width:1280px;margin:0 auto}
.sidebar{width:170px;padding:14px 8px;border-right:1px solid var(--border);min-height:calc(100vh - 45px)}
.sidebar a{display:block;padding:9px 12px;border-radius:8px;color:var(--muted);text-decoration:none;margin-bottom:2px;font-size:12px;transition:.2s}
.sidebar a:hover,.sidebar a.active{background:rgba(var(--accent-rgb),0.1);color:var(--accent)}

.content{flex:1;padding:18px 16px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:10px;margin-bottom:18px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center;transition:.25s}
.stat:hover{border-color:rgba(var(--accent-rgb),0.3);transform:translateY(-1px)}
.stat .num{font-size:22px;font-weight:700;color:var(--accent)}
.stat .label{font-size:10px;color:var(--muted);margin-top:3px;text-transform:uppercase;letter-spacing:0.6px}

.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:14px;transition:.25s}
.card:hover{border-color:rgba(var(--accent-rgb),0.2)}
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

a.keylink{color:#86efac;text-decoration:none}
a.keylink:hover{text-decoration:underline}

/* KEY GRID */
.keys-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.key-mini{
  background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px;
  transition:.25s;cursor:pointer;text-decoration:none;color:inherit;display:block;
}
.key-mini:hover{border-color:rgba(var(--accent-rgb),0.4);transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.3)}
.key-mini .km-top{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.key-mini .km-circle{
  width:40px;height:40px;border-radius:50%;
  background:conic-gradient(from -90deg, var(--accent) var(--p), #1a1a1a 0);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.key-mini .km-inner{width:30px;height:30px;border-radius:50%;background:var(--card);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:#fff}
.key-mini .km-name{font-size:13px;font-weight:600;color:#fff;word-break:break-all}
.key-mini .km-meta{font-size:11px;color:var(--muted);margin-top:2px}
.key-mini .km-row{display:flex;justify-content:space-between;font-size:11px;padding:3px 0;color:#888}
.key-mini .km-row span:last-child{color:#ccc}

/* KEY PANEL */
.key-panel{
  background:var(--card);border:1px solid var(--border);border-radius:16px;
  max-width:380px;margin:0 auto 18px;overflow:hidden;
  box-shadow:0 0 40px rgba(var(--accent-rgb),0.06),0 20px 40px rgba(0,0,0,0.4);
  animation:fadeIn .35s;
}
.key-panel .top{padding:16px 16px 12px;display:flex;gap:12px;align-items:flex-start;border-bottom:1px solid #121214}
.days-circle{
  width:52px;height:52px;border-radius:50%;
  background:conic-gradient(from -90deg, var(--accent) var(--p), #1a1a1a 0);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  box-shadow:0 0 20px rgba(var(--accent-rgb),0.2);
}
.days-inner{width:40px;height:40px;border-radius:50%;background:var(--card);display:flex;flex-direction:column;align-items:center;justify-content:center}
.days-inner .n{font-size:15px;font-weight:700;color:#fff;line-height:1}
.days-inner .l{font-size:8px;color:#555;text-transform:uppercase;margin-top:1px}
.key-info{flex:1;min-width:0}
.key-info .title{font-size:14px;font-weight:600;color:#fff;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.key-info .title .tag{font-size:9px;padding:2px 6px;border-radius:4px;background:rgba(var(--accent-rgb),0.12);color:var(--accent);border:1px solid rgba(var(--accent-rgb),0.2)}
.key-info .title .vip{font-size:9px;padding:2px 6px;border-radius:4px;background:var(--accent);color:#000;font-weight:600}
.key-info .meta{margin-top:4px;font-size:11px;color:#555;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.key-info .meta .dot{width:6px;height:6px;border-radius:50%;display:inline-block}

.key-details{padding:10px 16px 4px}
.key-details .row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:12px;border-bottom:1px solid #101012}
.key-details .row:last-child{border-bottom:none}
.key-details .row .lbl{color:#555}
.key-details .row .val{color:#ccc;font-weight:500;text-align:right}
.key-details .row .val.g{color:#4ade80}
.key-details .row .val.r{color:#f87171}

.key-btns{padding:10px 12px 12px;display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.kbtn{
  background:#111113;border:1px solid #1a1a1c;border-radius:9px;
  padding:10px 4px;color:#888;font-size:10px;font-weight:500;
  cursor:pointer;transition:all .2s;text-align:center;text-decoration:none;
}
.kbtn:hover{background:rgba(var(--accent-rgb),0.1);border-color:rgba(var(--accent-rgb),0.35);color:var(--accent);transform:translateY(-1px)}

.key-extra{padding:0 12px 12px;display:grid;grid-template-columns:1fr 1fr;gap:6px}
.key-note{padding:0 16px 14px}

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
      $tag = $kd['tag'] ?? '';
      $softBan = !empty($kd['soft_ban_until']) && time() < $kd['soft_ban_until'];

      $now = time();
      $daysLeft = '∞'; $expiresStr = 'навсегда'; $expiresClass = 'g'; $circleP = 100;
      if (($kd['expires'] ?? 0) > 0) {
          $left = $kd['expires'] - $now;
          if ($left <= 0) { $daysLeft='0'; $expiresStr='истёк'; $expiresClass='r'; $circleP=0; }
          else { $daysLeft=(string)ceil($left/86400); $expiresStr=date('d-m-Y H:i',$kd['expires']); $circleP=min(100,max(5,($left/(30*86400))*100)); }
      } elseif (($kd['duration']??0)>0 && ($kd['first_use']??0)==0) {
          $daysLeft=(string)ceil($kd['duration']/86400); $expiresStr='после активации'; $circleP=min(100,max(5,($kd['duration']/(30*86400))*100));
      }

      $status = 'свободен'; $statusColor = '#fbbf24';
      if (!empty($kd['is_frozen'])) { $status='заморожен'; $statusColor='#60a5fa'; }
      elseif ($softBan) { $status='soft ban'; $statusColor='#f97316'; }
      elseif (($kd['first_use']??0)==0) { $status='не активирован'; $statusColor='#fbbf24'; }
      elseif (($kd['expires']??0)>0 && $now>$kd['expires']) { $status='истёк'; $statusColor='#f87171'; }
      else { $status='активен'; $statusColor='#4ade80'; }

      $mainHwid = '—';
      if (!empty($kd['activations'])) $mainHwid = substr($kd['activations'][0]['hwid']??'—',0,15).'…';
    ?>

    <div class="key-panel">
      <div class="top">
        <div class="days-circle" style="--p:<?= $circleP ?>%"><div class="days-inner"><div class="n"><?= $daysLeft ?></div><div class="l">дней</div></div></div>
        <div class="key-info">
          <div class="title">
            <?= htmlspecialchars($viewKey) ?>
            <span class="tag"><?= $namedTag ?></span>
            <?php if ($isVip): ?><span class="vip">VIP</span><?php endif; ?>
            <?php if ($tag): ?><span class="tag"><?= htmlspecialchars($tag) ?></span><?php endif; ?>
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
        <div class="row"><span class="lbl">Владелец</span><span class="val"><?= htmlspecialchars($ownerName) ?></span></div>
        <div class="row"><span class="lbl">Telegram ID</span><span class="val"><?= $tgId ?></span></div>
        <div class="row"><span class="lbl">Android ID</span><span class="val"><?= htmlspecialchars($android) ?></span></div>
        <div class="row"><span class="lbl">HWID</span><span class="val" style="font-size:10px"><?= htmlspecialchars($mainHwid) ?></span></div>
        <div class="row"><span class="lbl">Входов</span><span class="val"><?= $used ?> / <?= $max ?></span></div>
        <div class="row"><span class="lbl">Предупреждения</span><span class="val <?= $warns>0?'r':'' ?>"><?= $warns ?> / 3</span></div>
        <div class="row"><span class="lbl">Уровень</span><span class="val" style="color:var(--accent)"><?= strtoupper($kd['level']??'—') ?></span></div>
        <?php if ($softBan): ?><div class="row"><span class="lbl">Soft ban до</span><span class="val r"><?= date('d.m H:i',$kd['soft_ban_until']) ?></span></div><?php endif; ?>
      </div>
      <div class="key-btns">
        <button class="kbtn" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($viewKey) ?>');this.innerText='Copied';setTimeout(()=>this.innerText='Copy',900)">Copy</button>
        <form method="post" style="display:contents"><input type="hidden" name="action" value="set_nick"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="kbtn" type="button" onclick="let n=prompt('Name:','<?= htmlspecialchars($kd['owner_name']??'') ?>');if(n!==null){this.form.nick.value=n;this.form.submit()}"><input type="hidden" name="nick" value="">Nick</button></form>
        <form method="post" style="display:contents"><input type="hidden" name="action" value="add_warn"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="kbtn" type="submit">Warn <?= $warns ?>/3</button></form>
        <form method="post" style="display:contents"><input type="hidden" name="action" value="reset_warns"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="kbtn" type="submit">Reset warns</button></form>
        <form method="post" style="display:contents"><input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="kbtn" type="submit">Reset HWID</button></form>
        <form method="post" style="display:contents"><input type="hidden" name="action" value="regen_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="kbtn" type="submit" onclick="return confirm('Regenerate?')">Regen</button></form>
        <form method="post" style="display:contents"><input type="hidden" name="action" value="freeze_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="kbtn" type="submit"><?= !empty($kd['is_frozen'])?'Unfreeze':'Freeze' ?></button></form>
        <a href="?admin&tab=logs&filter=<?= urlencode($viewKey) ?>" class="kbtn">Logs</a>
        <form method="post" style="display:contents"><input type="hidden" name="action" value="toggle_vip"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="kbtn" type="submit"><?= $isVip?'VIP ✓':'VIP' ?></button></form>
      </div>
      <div class="key-extra">
        <form method="post" style="display:contents"><input type="hidden" name="action" value="extend_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="hidden" name="days" value="7"><button class="kbtn" type="submit" style="color:#4ade80">+7 days</button></form>
        <form method="post" style="display:contents"><input type="hidden" name="action" value="clone_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="kbtn" type="submit">Clone</button></form>
        <form method="post" style="display:contents"><input type="hidden" name="action" value="clear_activations"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="kbtn" type="submit">Clear acts</button></form>
        <form method="post" onsubmit="return confirm('Delete?')" style="display:contents"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="kbtn" type="submit" style="color:#f87171">Delete</button></form>
      </div>
      <div class="key-note">
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="transfer_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="number" name="new_tg" placeholder="Transfer TG ID" style="flex:1"><button class="btn btn-dark btn-sm" type="submit">Transfer</button></form>
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="set_max"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="number" name="max" value="<?= $max ?>" min="1" style="width:50px"><button class="btn btn-dark btn-sm" type="submit">Max devices</button></form>
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="set_duration"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="number" name="hours" placeholder="Hours 0=∞" style="width:90px"><button class="btn btn-dark btn-sm" type="submit">Duration</button></form>
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="add_time_hours"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="number" name="add_hours" value="24" style="width:60px"><button class="btn btn-dark btn-sm" type="submit">+Hours</button></form>
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="set_expire_date"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="datetime-local" name="expire_date" style="flex:1"><button class="btn btn-dark btn-sm" type="submit">Set expire</button></form>
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="ban_hwid"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="text" name="hwid_ban" placeholder="HWID ban" style="flex:1"><button class="btn btn-dark btn-sm" type="submit">Ban HWID</button></form>
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="soft_ban"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="number" name="ban_hours" value="24" style="width:55px"><button class="btn btn-dark btn-sm" type="submit">Soft ban h</button></form>
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="clear_soft_ban"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="btn btn-dark btn-sm" type="submit">Clear soft ban</button></form>
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="set_tag"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="text" name="tag" value="<?= htmlspecialchars($tag) ?>" placeholder="Tag" style="flex:1"><button class="btn btn-dark btn-sm" type="submit">Tag</button></form>
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="set_level"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
          <select name="level"><option value="trial">Trial</option><option value="free">Free</option><option value="media">Media</option><option value="premium" selected>Premium</option><option value="elite">Elite</option></select>
          <button class="btn btn-dark btn-sm" type="submit">Level</button>
        </form>
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="set_max_launches"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="number" name="max_launches" value="<?= $kd['max_launches']??0 ?>" style="width:55px"><button class="btn btn-dark btn-sm" type="submit">Max launches</button></form>
        <form method="post" class="form-row" style="margin-bottom:6px"><input type="hidden" name="action" value="reset_first_use"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="btn btn-dark btn-sm" type="submit">Reset first use</button></form>
        <form method="post"><input type="hidden" name="action" value="set_note"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><textarea name="note" rows="2" placeholder="Note..."><?= htmlspecialchars($note) ?></textarea><button class="btn btn-dark btn-sm" type="submit" style="margin-top:5px">Save note</button></form>
      </div>
    </div>
    <div style="text-align:center;margin-bottom:14px"><a href="?admin&tab=keys" class="btn btn-dark">← Back</a></div>
    <div class="card" style="max-width:640px;margin:0 auto">
      <h2>Activations</h2>
      <?php if (empty($kd['activations'])): ?><p style="color:var(--muted)">None</p>
      <?php else: ?><table><tr><th>HWID</th><th>IP</th><th>Activated</th><th>Last</th><th>Launches</th></tr>
        <?php foreach ($kd['activations'] as $a): ?><tr>
          <td><code style="font-size:10px"><?= htmlspecialchars($a['hwid']??'') ?></code></td>
          <td><?= htmlspecialchars($a['ip']??'') ?></td>
          <td><?= !empty($a['time'])?date('d.m H:i',$a['time']):'—' ?></td>
          <td><?= !empty($a['last_active'])?date('d.m H:i',$a['last_active']):'—' ?></td>
          <td><?= $a['launches']??1 ?></td>
        </tr><?php endforeach; ?></table>
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
          <form method="post"><input type="hidden" name="action" value="bulk_freeze"><button class="btn btn-dark btn-sm" type="submit">Freeze all</button></form>
          <form method="post"><input type="hidden" name="action" value="bulk_unfreeze"><button class="btn btn-dark btn-sm" type="submit">Unfreeze all</button></form>
        </div>
        <p style="margin-top:10px;color:var(--muted);font-size:12px">Status: <b style="color:var(--accent)"><?= htmlspecialchars($db['settings']['status']) ?></b> · Soft: <b><?= htmlspecialchars($db['settings']['soft_status']??'undetected') ?></b> · Freeze: <b><?= !empty($db['settings']['global_freeze'])?'ON':'off' ?></b></p>
      </div>

    <?php elseif ($tab === 'keys'): ?>
      <div class="card">
        <h2>Keys (<?= $totalKeys ?>)</h2>
        <input type="text" id="searchKey" placeholder="Search..." onkeyup="filterKeys()" style="width:100%;max-width:240px;margin-bottom:14px">
        <div class="keys-grid" id="keysGrid">
          <?php foreach ($db['keys'] as $k => $kd):
            $used = count($kd['activations']??[]);
            $max = $kd['max']??1;
            $now = time();
            $daysLeft = '∞'; $circleP = 100;
            if (($kd['expires']??0)>0) {
              $left = $kd['expires']-$now;
              if ($left<=0) { $daysLeft='0'; $circleP=0; }
              else { $daysLeft=(string)ceil($left/86400); $circleP=min(100,max(5,($left/(30*86400))*100)); }
            } elseif (($kd['duration']??0)>0 && ($kd['first_use']??0)==0) {
              $daysLeft=(string)ceil($kd['duration']/86400); $circleP=min(100,max(5,($kd['duration']/(30*86400))*100));
            }
            $stColor = '#4ade80';
            if (!empty($kd['is_frozen'])) $stColor='#60a5fa';
            elseif (($kd['first_use']??0)==0) $stColor='#facc15';
            elseif (($kd['expires']??0)>0 && $now>$kd['expires']) $stColor='#f87171';
          ?>
          <a href="?admin&view=<?= urlencode($k) ?>" class="key-mini" data-search="<?= htmlspecialchars(strtolower($k.' '.($kd['owner_name']??'').' '.($kd['owner_tg']??'').' '.($kd['level']??'').' '.($kd['tag']??''))) ?>">
            <div class="km-top">
              <div class="km-circle" style="--p:<?= $circleP ?>%"><div class="km-inner"><?= $daysLeft ?></div></div>
              <div>
                <div class="km-name"><?= htmlspecialchars($k) ?></div>
                <div class="km-meta"><span style="color:<?= $stColor ?>">●</span> <?= htmlspecialchars($kd['level']??'') ?><?= !empty($kd['vip'])?' VIP':'' ?><?= !empty($kd['named'])?' named':'' ?><?= !empty($kd['tag'])?' · '.$kd['tag']:'' ?></div>
              </div>
            </div>
            <div class="km-row"><span>Owner</span><span><?= $kd['owner_tg']?:($kd['owner_name']?:'—') ?></span></div>
            <div class="km-row"><span>Devices</span><span><?= $used ?>/<?= $max ?></span></div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

    <?php elseif ($tab === 'generate'): ?>
      <div class="card">
        <h2>Create Key</h2>
        <form method="post">
          <input type="hidden" name="action" value="gen_key">
          <div class="form-row"><input type="text" name="custom_name" placeholder="Named key (exact name)" style="width:260px"></div>
          <div class="form-row">
            <input type="number" name="hours" value="24" style="width:90px" placeholder="Hours">
            <input type="number" name="max" value="1" min="1" style="width:50px">
            <select name="level"><option value="trial">Trial</option><option value="free">Free</option><option value="media">Media</option><option value="premium" selected>Premium</option><option value="elite">Elite</option></select>
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
        </form>
      </div>

    <?php elseif ($tab === 'bulk'): ?>
      <div class="card">
        <h2>Bulk Generate</h2>
        <form method="post">
          <input type="hidden" name="action" value="bulk_generate">
          <div class="form-row">
            <input type="number" name="count" value="10" min="1" max="50" style="width:55px">
            <input type="number" name="hours" value="24" style="width:80px">
            <input type="number" name="max" value="1" min="1" style="width:50px">
            <select name="level"><option value="trial">Trial</option><option value="free">Free</option><option value="media">Media</option><option value="premium" selected>Premium</option><option value="elite">Elite</option></select>
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
            <input type="number" name="tg_id" placeholder="Telegram ID" required style="width:130px">
            <input type="text" name="custom_name" placeholder="Named (opt)" style="width:120px">
            <input type="number" name="hours" value="168" style="width:70px">
            <input type="number" name="max" value="1" style="width:50px">
            <select name="level"><option value="premium" selected>Premium</option><option value="elite">Elite</option><option value="media">Media</option><option value="free">Free</option><option value="trial">Trial</option></select>
            <button class="btn btn-accent" type="submit">Give</button>
          </div>
        </form>
      </div>

    <?php elseif ($tab === 'online'): ?>
      <div class="card">
        <h2>Online (<?= $onlineCount ?>)</h2>
        <?php if (empty($db['online'])): ?><p style="color:var(--muted)">Empty</p>
        <?php else: ?><table><tr><th>Key</th><th>IP</th><th>HWID</th><th>Ping</th><th>Since</th></tr>
          <?php foreach ($db['online'] as $hwid=>$info): ?><tr>
            <td><code><?= htmlspecialchars($info['key']??'—') ?></code></td>
            <td><?= htmlspecialchars($info['ip']??'') ?></td>
            <td style="font-size:10px"><?= htmlspecialchars(substr($hwid,0,16)) ?>…</td>
            <td><?= time()-($info['last_ping']??0) ?>s</td>
            <td style="font-size:11px"><?= !empty($info['first_seen'])?date('H:i:s',$info['first_seen']):'—' ?></td>
          </tr><?php endforeach; ?></table>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'broadcast'): ?>
      <div class="card">
        <h2>Broadcast</h2>
        <form method="post">
          <input type="hidden" name="action" value="set_broadcast">
          <textarea name="broadcast"><?= htmlspecialchars($db['settings']['broadcast']??'') ?></textarea>
          <div class="form-row" style="margin-top:8px"><button class="btn btn-accent" type="submit">Set</button><button class="btn btn-dark" type="submit" onclick="this.form.broadcast.value=''">Clear</button></div>
        </form>
      </div>

    <?php elseif ($tab === 'blacklist'): ?>
      <div class="card">
        <h2>Add to Blacklist</h2>
        <form method="post" class="form-row">
          <input type="hidden" name="action" value="add_blacklist">
          <input type="text" name="value" placeholder="IP / HWID" required style="width:180px">
          <input type="text" name="reason" placeholder="Reason" style="width:120px">
          <button class="btn btn-red" type="submit">Add</button>
        </form>
      </div>
      <div class="card">
        <h2>Blacklist (<?= count($db['blacklist']) ?>)</h2>
        <?php if (empty($db['blacklist'])): ?><p style="color:var(--muted)">Empty</p>
        <?php else: ?><table><tr><th>Value</th><th>Reason</th><th>Date</th><th></th></tr>
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
        <h2>Settings</h2>
        <form method="post">
          <input type="hidden" name="action" value="save_settings">
          <div class="form-row"><label style="width:90px">Version</label><input type="text" name="version" value="<?= htmlspecialchars($db['settings']['version']) ?>" style="width:120px"></div>
          <div class="form-row"><label style="width:90px">Checksum</label><input type="text" name="checksum" value="<?= htmlspecialchars($db['settings']['checksum']) ?>" style="width:240px"></div>
          <div class="form-row"><label style="width:90px">Download</label><input type="text" name="download_url" value="<?= htmlspecialchars($db['settings']['download_url']) ?>" style="width:240px"></div>
          <div class="form-row" style="align-items:flex-start"><label style="width:90px;margin-top:6px">Emergency</label><textarea name="emergency_msg"><?= htmlspecialchars($db['settings']['emergency_msg']) ?></textarea></div>
          <button class="btn btn-accent" type="submit" style="margin-top:8px">Save</button>
        </form>
      </div>

    <?php elseif ($tab === 'theme'): ?>
      <div class="card">
        <h2>Theme</h2>
        <form method="post">
          <input type="hidden" name="action" value="set_panel_bg">
          <div class="form-row"><input type="text" name="panel_bg" value="<?= htmlspecialchars($db['settings']['panel_bg']??'') ?>" placeholder="Background image URL" style="flex:1;min-width:200px"></div>
          <div class="form-row">
            <label>Accent</label>
            <input type="color" name="panel_accent" value="<?= htmlspecialchars($db['settings']['panel_accent']??'#22c55e') ?>" style="width:42px;height:30px;padding:1px;border:1px solid var(--border);background:#111;border-radius:6px">
            <label>Overlay</label>
            <input type="number" name="panel_overlay" value="<?= htmlspecialchars($db['settings']['panel_overlay']??'0.88') ?>" min="0.5" max="0.95" step="0.01" style="width:60px">
            <button class="btn btn-accent" type="submit">Apply</button>
            <button class="btn btn-dark" type="submit" onclick="this.form.panel_bg.value='';this.form.panel_accent.value='#22c55e';this.form.panel_overlay.value='0.88'">Reset</button>
          </div>
        </form>
        <p style="color:var(--muted);font-size:11px;margin-top:8px">Accent перекрашивает кнопки, круги, свечение, hover. Overlay — прозрачность затемнения фона (0.5–0.95).</p>
      </div>

    <?php elseif ($tab === 'logs'): ?>
      <div class="card">
        <h2>Logs</h2>
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

// ====================== BOT (короткий рабочий) ======================
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
    $db['keys'][$newKey]=['duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>1,'activations'=>[],'owner_tg'=>$chatId,'owner_name'=>'','reset_left'=>2,'is_frozen'=>false,'level'=>'premium','created'=>time(),'warns'=>0,'note'=>'','vip'=>false,'named'=>false,'tag'=>'','soft_ban_until'=>0,'max_launches'=>0];
    saveDb(); addLog("Bought $newKey by $chatId"); sendMessage($chatId,"Payment ok.\n\nKey:\n`$newKey`"); exit;
}

if (isset($update['message'])) {
    $chatId=(int)$update['message']['chat']['id']; $text=trim($update['message']['text']??''); $isAdmin=($chatId===$adminId);
    if ($isAdmin && ($text==='/admin'||$text==='/panel')) {
        sendMessage($chatId,"Admin\nKeys: *".count($db['keys'])."*\nOnline: *".count($db['online'])."*",['inline_keyboard'=>[[['text'=>'Keys','callback_data'=>'adm_keys'],['text'=>'Create','callback_data'=>'adm_gen']],[['text'=>'Online','callback_data'=>'adm_online'],['text'=>'Stats','callback_data'=>'adm_stats']],[['text'=>'Killswitch','callback_data'=>'toggle_kill'],['text'=>'Freeze','callback_data'=>'toggle_gfreeze']]]]); exit;
    }
    if ($isAdmin && strpos($text,'/gen ')===0) {
        $a=explode(' ',$text); $hours=(int)($a[1]??24); $max=(int)($a[2]??1); $level=$a[3]??'premium'; $name=$a[4]??''; $duration=$hours===0?0:$hours*3600;
        if ($name) { $newKey=$name; if(isset($db['keys'][$newKey])){sendMessage($chatId,'Taken');exit;}
            $db['keys'][$newKey]=['duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,'activations'=>[],'owner_tg'=>0,'owner_name'=>$name,'reset_left'=>999,'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,'note'=>'','vip'=>false,'named'=>true,'tag'=>'','soft_ban_until'=>0,'max_launches'=>0];
        } else {
            $newKey=strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
            $db['keys'][$newKey]=['duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,'activations'=>[],'owner_tg'=>0,'owner_name'=>'','reset_left'=>999,'is_frozen'=>false,'level'=>$level,'created'=>time(),'warns'=>0,'note'=>'','vip'=>false,'named'=>false,'tag'=>'','soft_ban_until'=>0,'max_launches'=>0];
        }
        saveDb(); sendMessage($chatId,"Key: `$newKey`"); exit;
    }
    if ($text==='/start') {
        $kb=['inline_keyboard'=>[[['text'=>'1h — 10★','callback_data'=>'buy_1_1']],[['text'=>'24h — 25★','callback_data'=>'buy_24_1']],[['text'=>'3d — 50★','callback_data'=>'buy_72_1']],[['text'=>'7d — 75★','callback_data'=>'buy_168_1']],[['text'=>'30d — 125★','callback_data'=>'buy_720_1']],[['text'=>'90d — 250★','callback_data'=>'buy_2160_1']],[['text'=>'Life — 400★','callback_data'=>'buy_0_1']],[['text'=>'My keys','callback_data'=>'my_keys']]]];
        if($isAdmin)$kb['inline_keyboard'][]=[['text'=>'Admin','callback_data'=>'admin_panel']];
        sendMessage($chatId,"Lori Elite\nSelect:",$kb);
    }
}

if (isset($update['callback_query'])) {
    $cq=$update['callback_query']; $chatId=(int)$cq['message']['chat']['id']; $data=$cq['data']; $msgId=$cq['message']['message_id']; $cqId=$cq['id']; $isAdmin=($chatId===$adminId);
    if (strpos($data,'buy_')===0) { $p=explode('_',$data); $h=(int)$p[1]; $m=[1=>10,24=>25,72=>50,168=>75,720=>125,2160=>250,0=>400]; sendInvoice($chatId,'Lori Key','Access',"sub_{$h}_1",$m[$h]??25); answerCallback($cqId); exit; }
    if ($data==='my_keys') {
        $f=false; foreach($db['keys'] as $k=>$kd){ if(($kd['owner_tg']??0)==$chatId){ $f=true; $u=count($kd['activations']??[]); $mx=$kd['max']??1;
            if(!empty($kd['is_frozen']))$st='Frozen'; elseif(($kd['first_use']??0)==0)$st='New'; elseif(($kd['expires']??0)==0)$st='Life'; elseif(time()>$kd['expires'])$st='Expired'; else{$l=$kd['expires']-time();$st=floor($l/86400).'d';}
            sendMessage($chatId,"`$k`\n$st\n$u/$mx",['inline_keyboard'=>[[['text'=>'Reset HWID','callback_data'=>'user_reset_'.$k]]]]); }}
        if(!$f) sendMessage($chatId,'No keys'); answerCallback($cqId); exit;
    }
    if (strpos($data,'user_reset_')===0) { $key=str_replace('user_reset_','',$data); if(isset($db['keys'][$key])&&($db['keys'][$key]['owner_tg']??0)==$chatId){ if(($db['keys'][$key]['reset_left']??0)>0){$db['keys'][$key]['activations']=[];$db['keys'][$key]['reset_left']--;saveDb();answerCallback($cqId,'Reset');}else answerCallback($cqId,'Limit',true);} exit; }
    if (!$isAdmin) { answerCallback($cqId,'No access',true); exit; }
    if ($data==='admin_panel') { editMessage($chatId,$msgId,"Admin\nKeys: *".count($db['keys'])."*",['inline_keyboard'=>[[['text'=>'Keys','callback_data'=>'adm_keys']],[['text'=>'Online','callback_data'=>'adm_online']],[['text'=>'Killswitch','callback_data'=>'toggle_kill'],['text'=>'Freeze','callback_data'=>'toggle_gfreeze']]]]); answerCallback($cqId); exit; }
    if ($data==='adm_keys'||strpos($data,'adm_keys_')===0) {
        $page=strpos($data,'adm_keys_')===0?(int)str_replace('adm_keys_','',$data):0; $keys=array_keys($db['keys']); $per=8; $total=count($keys); $pages=max(1,ceil($total/$per)); $slice=array_slice($keys,$page*$per,$per);
        $kb=['inline_keyboard'=>[]]; foreach($slice as $k){ $kd=$db['keys'][$k]; $st=!empty($kd['is_frozen'])?'F':((($kd['expires']??0)>0&&time()>$kd['expires'])?'E':'A'); $kb['inline_keyboard'][]=[['text'=>"[$st] $k",'callback_data'=>'k_view_'.$k]]; }
        $nav=[]; if($page>0)$nav[]=['text'=>'‹','callback_data'=>'adm_keys_'.($page-1)]; $nav[]=['text'=>($page+1)."/$pages",'callback_data'=>'noop']; if($page<$pages-1)$nav[]=['text'=>'›','callback_data'=>'adm_keys_'.($page+1)]; if($nav)$kb['inline_keyboard'][]=$nav; $kb['inline_keyboard'][]=[['text'=>'Back','callback_data'=>'admin_panel']];
        editMessage($chatId,$msgId,"Keys ($total)",$kb); answerCallback($cqId); exit;
    }
    if (strpos($data,'k_view_')===0) {
        $key=str_replace('k_view_','',$data); if(!isset($db['keys'][$key])){answerCallback($cqId,'?',true);exit;} $kd=$db['keys'][$key]; $u=count($kd['activations']??[]); $mx=$kd['max']??1; $w=$kd['warns']??0;
        $st=!empty($kd['is_frozen'])?'frozen':((($kd['expires']??0)>0&&time()>$kd['expires'])?'expired':'active');
        editMessage($chatId,$msgId,"`$key`\n$st\n$u/$mx\nWarns $w/3",['inline_keyboard'=>[[['text'=>'Reset HWID','callback_data'=>'k_rhwid_'.$key],['text'=>!empty($kd['is_frozen'])?'Unfreeze':'Freeze','callback_data'=>'k_freeze_'.$key]],[['text'=>'Warn','callback_data'=>'k_warn_'.$key],['text'=>'Delete','callback_data'=>'k_del_'.$key]],[['text'=>'Back','callback_data'=>'adm_keys']]]]); answerCallback($cqId); exit;
    }
    if (strpos($data,'k_rhwid_')===0){$key=str_replace('k_rhwid_','',$data);if(isset($db['keys'][$key])){$db['keys'][$key]['activations']=[];saveDb();answerCallback($cqId,'OK');}exit;}
    if (strpos($data,'k_freeze_')===0){$key=str_replace('k_freeze_','',$data);if(isset($db['keys'][$key])){$db['keys'][$key]['is_frozen']=empty($db['keys'][$key]['is_frozen']);saveDb();answerCallback($cqId,!empty($db['keys'][$key]['is_frozen'])?'Frozen':'Unfrozen');}exit;}
    if (strpos($data,'k_warn_')===0){$key=str_replace('k_warn_','',$data);if(isset($db['keys'][$key])){$db['keys'][$key]['warns']=min(3,($db['keys'][$key]['warns']??0)+1);saveDb();answerCallback($cqId,'Warned');}exit;}
    if (strpos($data,'k_del_')===0){$key=str_replace('k_del_','',$data);unset($db['keys'][$key]);saveDb();answerCallback($cqId,'Deleted');exit;}
    if ($data==='adm_online'){$t="Online (".count($db['online']).")\n\n";if(empty($db['online']))$t.='Empty';else foreach($db['online'] as $h=>$i)$t.="`{$i['key']}` | {$i['ip']}\n";editMessage($chatId,$msgId,$t,['inline_keyboard'=>[[['text'=>'Back','callback_data'=>'admin_panel']]]]);answerCallback($cqId);exit;}
    if ($data==='adm_stats'){$a=$f=$e=0;foreach($db['keys'] as $kd){if(!empty($kd['is_frozen']))$f++;elseif(($kd['expires']??0)==0||time()<($kd['expires']??0))$a++;else$e++;}editMessage($chatId,$msgId,"Total ".count($db['keys'])."\nActive $a\nFrozen $f\nExpired $e",['inline_keyboard'=>[[['text'=>'Back','callback_data'=>'admin_panel']]]]);answerCallback($cqId);exit;}
    if ($data==='toggle_kill'){if($db['settings']['status']==='killswitch'){$db['settings']['status']='online';$db['settings']['emergency_msg']='';answerCallback($cqId,'OFF');}else{$db['settings']['status']='killswitch';$db['settings']['emergency_msg']='Stopped';answerCallback($cqId,'ON');}saveDb();exit;}
    if ($data==='toggle_gfreeze'){$db['settings']['global_freeze']=empty($db['settings']['global_freeze']);saveDb();answerCallback($cqId,!empty($db['settings']['global_freeze'])?'Freeze ON':'Freeze OFF');exit;}
    if ($data==='noop'){answerCallback($cqId);exit;}
}
