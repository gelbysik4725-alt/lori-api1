<?php
error_reporting(0);
date_default_timezone_set('Europe/Moscow');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
if (!$isHttps && php_sapi_name() !== 'cli') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$adminPass = 'LoriElite';
$ghToken  = getenv('GITHUB_TOKEN') ?: '';
$ghRepo   = getenv('GITHUB_REPO') ?: '';
$ghPath   = getenv('GITHUB_PATH') ?: 'database.json';
$ghBranch = getenv('GITHUB_BRANCH') ?: 'main';
$dbFile = __DIR__ . '/database.json';
$ghShaCacheFile = __DIR__ . '/.gh_sha_cache';

// СЕССИЯ — исправлено
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_lifetime', 86400 * 30);
    ini_set('session.gc_maxlifetime', 86400 * 30);
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

function ghGet($token, $repo, $path, $branch) {
    if (!$token || !$repo) return null;
    $url = "https://api.github.com/repos/{$repo}/contents/{$path}?ref={$branch}";
    $ctx = stream_context_create(['http' => [
        'header' => "Authorization: token {$token}\r\nAccept: application/vnd.github.v3+json\r\nUser-Agent: LORI\r\n",
        'timeout' => 8, 'ignore_errors' => true
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return null;
    $j = json_decode($res, true);
    if (empty($j['content'])) return null;
    return ['data' => json_decode(base64_decode(str_replace("\n", '', $j['content'])), true), 'sha' => $j['sha']];
}

function ghPut($token, $repo, $path, $branch, $content, $sha) {
    if (!$token || !$repo) return false;
    $url = "https://api.github.com/repos/{$repo}/contents/{$path}";
    $body = json_encode(['message' => 'LORI sync ' . date('Y-m-d H:i:s'), 'content' => base64_encode($content), 'branch' => $branch, 'sha' => $sha]);
    $ctx = stream_context_create(['http' => [
        'method' => 'PUT',
        'header' => "Authorization: token {$token}\r\nContent-Type: application/json\r\nUser-Agent: LORI\r\n",
        'content' => $body, 'timeout' => 15, 'ignore_errors' => true
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return false;
    $j = json_decode($res, true);
    return $j['content']['sha'] ?? false;
}

$currentSha = @file_get_contents($ghShaCacheFile);
if (!$currentSha && $ghToken && $ghRepo) {
    $g = ghGet($ghToken, $ghRepo, $ghPath, $ghBranch);
    if ($g) { $currentSha = $g['sha']; @file_put_contents($ghShaCacheFile, $currentSha); }
}

$db = [];
if (file_exists($dbFile)) {
    $db = json_decode(file_get_contents($dbFile), true);
    if (!is_array($db)) $db = [];
}
if (empty($db['keys']) && $ghToken && $ghRepo) {
    $g = ghGet($ghToken, $ghRepo, $ghPath, $ghBranch);
    if ($g && is_array($g['data'])) {
        $db = $g['data'];
        file_put_contents($dbFile, json_encode($db));
        if ($g['sha']) { $currentSha = $g['sha']; @file_put_contents($ghShaCacheFile, $currentSha); }
    }
}
foreach (['keys','blacklist','logs','online','access_log'] as $k) { if (!isset($db[$k])) $db[$k] = []; }
if (!isset($db['settings'])) $db['settings'] = [];
$db['settings'] = array_merge([
    'status' => 'online', 'soft_status' => 'undetected', 'global_freeze' => false,
    'version' => '1.0.0', 'checksum' => 'lori_v1', 'download_url' => '',
    'panel_accent' => '#3b82f6', 'panel_bg_color' => '#0a0a0a',
    'github_sync' => true, 'user_hwid_resets' => 2, 'user_freeze_per_week' => 2
], $db['settings']);

function saveDb() {
    global $db, $dbFile, $ghToken, $ghRepo, $ghPath, $ghBranch, $currentSha;
    $json = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($dbFile, $json);
    if (!empty($db['settings']['github_sync']) && $ghToken && $ghRepo) {
        $g = ghGet($ghToken, $ghRepo, $ghPath, $ghBranch);
        $shaToSend = $g ? $g['sha'] : $currentSha;
        if ($shaToSend) {
            $newSha = ghPut($ghToken, $ghRepo, $ghPath, $ghBranch, $json, $shaToSend);
            if ($newSha) { $currentSha = $newSha; @file_put_contents($ghShaCacheFile, $newSha); }
        }
    }
}

function addLog($text) {
    global $db;
    array_unshift($db['logs'], ['time'=>time(),'text'=>$text]);
    if (count($db['logs']) > 500) array_pop($db['logs']);
    saveDb();
}

function makeKeyData($duration, $max, $level, $owner_tg=0, $owner_name='') {
    global $db;
    return [
        'duration'=>$duration, 'expires'=>0, 'first_use'=>0, 'max'=>$max,
        'activations'=>[], 'owner_tg'=>$owner_tg, 'owner_name'=>$owner_name,
        'reset_left'=>(int)($db['settings']['user_hwid_resets']??2),
        'is_frozen'=>false, 'level'=>$level, 'created'=>time(), 'warns'=>0, 'note'=>''
    ];
}

function redirectAdmin($tab='dashboard', $msg='') {
    $url = '?admin&tab='.urlencode($tab);
    if ($msg !== '') $url .= '&msg='.urlencode($msg);
    header('Location: '.$url); exit;
}

function daysLeft($kd) {
    if (($kd['expires'] ?? 0) == 0) return '∞';
    $left = $kd['expires'] - time();
    if ($left <= 0) return 0;
    return ceil($left / 86400);
}

function keyStatus($kd) {
    if (!empty($kd['is_frozen'])) return 'frozen';
    if (($kd['expires'] ?? 0) > 0 && time() > $kd['expires']) return 'expired';
    if (($kd['first_use'] ?? 0) == 0) return 'unused';
    return 'active';
}

foreach ($db['online'] as $hwid => $data) {
    if (time() - ($data['last_ping'] ?? 0) > 120) unset($db['online'][$hwid]);
}

$action = $_GET['action'] ?? '';
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

// API
if ($action === 'status_check') {
    header('Content-Type: application/json; charset=utf-8');
    $hwid = $_POST['hwid'] ?? $_GET['hwid'] ?? '';
    $key  = $_POST['key']  ?? $_GET['key']  ?? '';
    if (!empty($hwid)) $db['online'][$hwid] = ['ip'=>$ip,'key'=>$key?:'-','last_ping'=>time()];
    echo json_encode([
        'status'=>$db['settings']['status'], 'soft_status'=>$db['settings']['soft_status']??'undetected',
        'version'=>$db['settings']['version'], 'checksum'=>$db['settings']['checksum'],
        'url'=>$db['settings']['download_url'], 'global_freeze'=>!empty($db['settings']['global_freeze'])
    ]);
    exit;
}

if ($action === 'check') {
    header('Content-Type: text/plain; charset=utf-8');
    $key  = trim($_POST['key']  ?? $_GET['key']  ?? '');
    $hwid = trim($_POST['hwid'] ?? $_GET['hwid'] ?? '');
    if ($db['settings']['status'] === 'killswitch') { echo 'Stopped'; exit; }
    if (!empty($db['settings']['global_freeze'])) { echo 'Frozen'; exit; }
    if (empty($key) || empty($hwid)) { echo empty($key)?'No key':'No HWID'; exit; }
    if (isset($db['blacklist'][$ip]) || isset($db['blacklist'][$hwid])) { echo 'Blocked'; exit; }
    if (!isset($db['keys'][$key])) { echo 'Invalid key'; exit; }
    $kd = &$db['keys'][$key];
    $now = time();
    if (!empty($kd['is_frozen'])) { echo 'Key frozen'; exit; }
    if (($kd['expires'] ?? 0) !== 0 && $now > $kd['expires']) { echo 'Expired'; exit; }
    $max = (int)($kd['max'] ?? 1);
    $acts = $kd['activations'] ?? [];
    foreach ($acts as &$a) {
        if (($a['hwid'] ?? '') === $hwid) {
            $a['ip']=$ip; $a['last_active']=$now;
            saveDb(); echo 'SUCCESS'; exit;
        }
    }
    unset($a);
    if (count($acts) < $max) {
        if (($kd['first_use'] ?? 0) == 0) {
            $kd['first_use'] = $now;
            if (($kd['duration'] ?? 0) > 0) $kd['expires'] = $now + $kd['duration'];
        }
        $kd['activations'][] = ['hwid'=>$hwid,'ip'=>$ip,'time'=>$now,'last_active'=>$now];
        saveDb(); addLog("Activated $key | ".substr($hwid,0,12)." | $ip");
        echo 'SUCCESS';
    } else { echo 'Device limit'; }
    exit;
}

if ($action === 'export_json' && isset($_GET['admin'])) {
    if (empty($_SESSION['admin'])) { http_response_code(403); exit; }
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="backup_'.date('Y-m-d_H-i').'.json"');
    echo json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// АДМИНКА
if (isset($_GET['admin'])) {
    if (isset($_GET['logout'])) { session_destroy(); header('Location: ?admin'); exit; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $adminPass) {
            $_SESSION['admin'] = true;
            addLog("Admin login from $ip");
            header('Location: ?admin'); exit;
        }
        $loginError = 'Неверный пароль';
    }

    if (empty($_SESSION['admin'])) {
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LORI</title>
<style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0a0a;font-family:Inter,system-ui;color:#fff}
body::before{content:"";position:fixed;inset:0;background:radial-gradient(circle at 20% 50%,rgba(59,130,246,.15),transparent 50%),radial-gradient(circle at 80% 80%,rgba(139,92,246,.1),transparent 50%);z-index:-1}
.box{width:100%;max-width:400px;padding:44px 36px;background:rgba(20,20,30,.85);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.1);border-radius:20px}
.logo{text-align:center;font-size:32px;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:6px;letter-spacing:2px}
.sub{text-align:center;font-size:11px;color:#64748b;margin-bottom:28px;letter-spacing:3px;text-transform:uppercase}
input{width:100%;padding:13px 16px;background:rgba(10,10,20,.6);border:1px solid rgba(255,255,255,.1);border-radius:10px;color:#fff;margin-bottom:14px;outline:none;font-size:14px}
input:focus{border-color:#3b82f6}
button{width:100%;padding:13px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border:none;border-radius:10px;color:#fff;font-weight:600;cursor:pointer;font-size:14px}
.err{color:#ef4444;text-align:center;font-size:13px;margin-bottom:14px;padding:10px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);border-radius:8px}
</style></head><body>
<div class="box">
<div class="logo">LORI</div>
<div class="sub">Control Panel</div>
'.(!empty($loginError)?'<div class="err">'.$loginError.'</div>':'').'
<form method="post"><input type="password" name="password" placeholder="Password" required autofocus>
<button type="submit">Enter</button></form>
</div></body></html>';
        exit;
    }

    $tab = $_GET['tab'] ?? 'dashboard';
    $viewKey = $_GET['view'] ?? '';
    $msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $act = $_POST['action'];
        $k = $_POST['key'] ?? '';

        if ($act === 'gen_key') {
            $hours=(int)($_POST['hours']??24);
            $max=max(1,(int)($_POST['max']??1));
            $level=in_array($_POST['level']??'',['trial','free','premium','elite'])?$_POST['level']:'premium';
            $customName=trim($_POST['custom_name']??'');
            $duration=$hours===0?0:$hours*3600;
            if ($customName!=='') {
                if (isset($db['keys'][$customName])) redirectAdmin('generate','Имя занято');
                $db['keys'][$customName]=makeKeyData($duration,$max,$level,0,$customName);
                $newKey = $customName;
            } else {
                $newKey = strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                $db['keys'][$newKey]=makeKeyData($duration,$max,$level);
            }
            saveDb(); addLog("Created: $newKey");
            redirectAdmin('generate',"Создан: $newKey");
        }
        if ($act === 'bulk_generate') {
            $count=max(1,min(50,(int)($_POST['count']??10)));
            $hours=(int)($_POST['hours']??24);
            $max=max(1,(int)($_POST['max']??1));
            $level=in_array($_POST['level']??'',['trial','free','premium','elite'])?$_POST['level']:'premium';
            $duration=$hours===0?0:$hours*3600;
            for($i=0;$i<$count;$i++){
                $newKey=strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand().$i,true)),0,8));
                $db['keys'][$newKey]=makeKeyData($duration,$max,$level);
            }
            saveDb();
            redirectAdmin('bulk',"Создано $count");
        }
        if ($k && isset($db['keys'][$k])) {
            if ($act==='freeze_key') { $db['keys'][$k]['is_frozen']=empty($db['keys'][$k]['is_frozen']); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit; }
            if ($act==='reset_hwid') { $db['keys'][$k]['activations']=[]; saveDb(); addLog("HWID reset $k"); header('Location:?admin&view='.urlencode($k).'&msg=HWID'); exit; }
            if ($act==='delete_key') { unset($db['keys'][$k]); saveDb(); redirectAdmin('keys','Удалён'); }
            if ($act==='extend_key') {
                $days=max(1,(int)($_POST['days']??7));
                if(($db['keys'][$k]['expires']??0)==0) $db['keys'][$k]['expires']=time()+$days*86400;
                else $db['keys'][$k]['expires']+=$days*86400;
                saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=+'.$days); exit;
            }
            if ($act==='set_note') { $db['keys'][$k]['note']=trim($_POST['note']??''); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Note'); exit; }
            if ($act==='make_lifetime') { $db['keys'][$k]['duration']=0; $db['keys'][$k]['expires']=0; saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Life'); exit; }
            if ($act==='add_warn') { $db['keys'][$k]['warns']=min(3,($db['keys'][$k]['warns']??0)+1); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Warn'); exit; }
            if ($act==='reset_warns') { $db['keys'][$k]['warns']=0; saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit; }
        }
        if ($act==='toggle_global_freeze') { $db['settings']['global_freeze']=empty($db['settings']['global_freeze']); saveDb(); redirectAdmin('dashboard','OK'); }
        if ($act==='set_status') { $db['settings']['status']=$_POST['status']??'online'; saveDb(); redirectAdmin('dashboard','Status'); }
        if ($act==='save_settings') {
            foreach(['version','checksum','download_url'] as $f) if(isset($_POST[$f])) $db['settings'][$f]=trim($_POST[$f]);
            $db['settings']['github_sync']=!empty($_POST['github_sync']);
            $db['settings']['user_hwid_resets']=max(0,(int)($_POST['user_hwid_resets']??2));
            $db['settings']['user_freeze_per_week']=max(0,(int)($_POST['user_freeze_per_week']??2));
            saveDb(); redirectAdmin('settings','Сохранено');
        }
        if ($act==='set_theme') {
            $c=trim($_POST['panel_bg_color']??'#0a0a0a'); if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$c))$c='#0a0a0a';
            $db['settings']['panel_bg_color']=$c;
            $a=trim($_POST['panel_accent']??'#3b82f6'); if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$a))$a='#3b82f6';
            $db['settings']['panel_accent']=$a;
            saveDb(); redirectAdmin('theme','Тема обновлена');
        }
        if ($act==='github_force_push') {
            $json=json_encode($db, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
            file_put_contents($dbFile,$json);
            $g=ghGet($ghToken,$ghRepo,$ghPath,$ghBranch);
            $sha=$g['sha']??'';
            $ok=ghPut($ghToken,$ghRepo,$ghPath,$ghBranch,$json,$sha);
            redirectAdmin('github', $ok?'GitHub: сохранено':'GitHub: ошибка');
        }
        if ($act==='github_force_pull') {
            $g=ghGet($ghToken,$ghRepo,$ghPath,$ghBranch);
            if($g && is_array($g['data'])){
                $db=$g['data'];
                foreach(['keys','blacklist','logs','online'] as $kk) if(!isset($db[$kk])) $db[$kk]=[];
                file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
                $currentSha=$g['sha']??'';
                @file_put_contents($ghShaCacheFile, $currentSha);
                redirectAdmin('github','GitHub: загружено');
            }
            redirectAdmin('github','GitHub: ошибка');
        }
        if ($act==='clear_logs') { $db['logs']=[]; saveDb(); redirectAdmin('logs','OK'); }
        if ($act==='delete_expired') {
            $n=0; foreach($db['keys'] as $kk=>$kd){ if(($kd['expires']??0)>0&&time()>$kd['expires']){unset($db['keys'][$kk]);$n++;}}
            saveDb(); redirectAdmin('tools',"Удалено $n");
        }
    }

    $totalKeys=count($db['keys']);
    $onlineCount=count($db['online']);
    $active=$frozen=$expired=0;
    foreach($db['keys'] as $kd){
        $status=keyStatus($kd);
        if($status==='frozen')$frozen++;
        elseif($status==='expired')$expired++;
        else $active++;
    }
    $githubOk = ($ghToken && $ghRepo);
    $accent=$db['settings']['panel_accent']??'#3b82f6';
    $panelBgColor=$db['settings']['panel_bg_color']??'#0a0a0a';
    $rgb=sscanf($accent,"#%02x%02x%02x")?:[59,130,246];

    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LORI</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
:root{--bg:<?= $panelBgColor ?>;--card:rgba(20,20,30,0.8);--border:rgba(255,255,255,0.08);--accent:<?= $accent ?>;--accent-rgb:<?= implode(',',$rgb) ?>;--text:#e2e8f0;--muted:#64748b;--success:#10b981;--warning:#f59e0b;--danger:#ef4444}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px}
body::before{content:'';position:fixed;inset:0;z-index:-1;background:radial-gradient(circle at 20% 30%,rgba(var(--accent-rgb),.1),transparent 50%),radial-gradient(circle at 80% 70%,rgba(139,92,246,.08),transparent 50%)}
.layout{display:flex;min-height:100vh}
.sidebar{width:240px;background:rgba(10,10,20,0.95);backdrop-filter:blur(20px);border-right:1px solid var(--border);padding:24px 16px;display:flex;flex-direction:column;position:fixed;height:100vh;overflow-y:auto}
.logo{font-size:28px;font-weight:800;background:linear-gradient(135deg,var(--accent),#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:32px;padding:0 8px;letter-spacing:1px}
.nav{display:flex;flex-direction:column;gap:4px;flex:1}
.nav a{display:flex;align-items:center;gap:12px;padding:11px 14px;color:var(--muted);text-decoration:none;border-radius:10px;transition:.2s;font-size:13px;font-weight:500}
.nav a:hover{background:rgba(255,255,255,0.05);color:var(--text)}
.nav a.active{background:linear-gradient(135deg,var(--accent),#8b5cf6);color:#fff;box-shadow:0 4px 12px rgba(var(--accent-rgb),.3)}
.main{flex:1;margin-left:240px;padding:32px}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px}
.header h2{font-size:28px;font-weight:700;background:linear-gradient(135deg,var(--text),var(--muted));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:22px;backdrop-filter:blur(12px);position:relative;overflow:hidden;transition:.2s}
.card:hover{border-color:rgba(var(--accent-rgb),.3)}
.card h3{color:var(--muted);font-size:11px;margin-bottom:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.card .val{font-size:30px;font-weight:800;color:var(--text)}
.card.accent{background:linear-gradient(135deg,rgba(var(--accent-rgb),.15),rgba(var(--accent-rgb),.05));border-color:rgba(var(--accent-rgb),.3)}
.msg{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:var(--success);padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:13px}
.btn{padding:10px 16px;border-radius:10px;border:none;font-weight:600;cursor:pointer;transition:.2s;display:inline-flex;align-items:center;gap:6px;font-size:13px;text-decoration:none}
.btn-primary{background:linear-gradient(135deg,var(--accent),#8b5cf6);color:#fff}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(var(--accent-rgb),.4)}
.btn-dark{background:var(--card);border:1px solid var(--border);color:var(--text)}
.btn-dark:hover{border-color:rgba(var(--accent-rgb),.3)}
.btn-danger{background:rgba(239,68,68,.15);color:var(--danger);border:1px solid rgba(239,68,68,.2)}
.btn-sm{padding:7px 12px;font-size:12px}
input,select,textarea{padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:rgba(10,10,20,.6);color:var(--text);font-size:13px;outline:none;transition:.2s;font-family:inherit;width:100%}
input:focus,select:focus,textarea:focus{border-color:var(--accent)}
textarea{resize:vertical;min-height:70px}
label{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px;cursor:pointer;margin-bottom:8px}
label input[type="checkbox"]{width:auto;margin:0}
.form-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;align-items:center}
.keys-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
.key-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;transition:.2s;cursor:pointer;text-decoration:none;color:inherit;display:block}
.key-card:hover{transform:translateY(-2px);border-color:rgba(var(--accent-rgb),.4)}
.key-header{display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;gap:8px}
.key-name{font-weight:700;font-size:14px;color:var(--text);word-break:break-all;flex:1}
.key-badge{font-size:10px;padding:3px 8px;border-radius:6px;background:rgba(255,255,255,.08);color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.key-badge.active{background:rgba(16,185,129,.15);color:var(--success)}
.key-badge.frozen{background:rgba(59,130,246,.15);color:#3b82f6}
.key-badge.expired{background:rgba(239,68,68,.15);color:var(--danger)}
.key-badge.unused{background:rgba(245,158,11,.15);color:var(--warning)}
.key-stats{display:flex;gap:14px;font-size:12px;color:var(--muted);margin-top:10px}
.key-view{max-width:700px;margin:0 auto;background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden}
.key-view-header{padding:24px;background:linear-gradient(135deg,rgba(var(--accent-rgb),.15),transparent);border-bottom:1px solid var(--border)}
.key-view-title{font-size:24px;font-weight:800;margin-bottom:8px;word-break:break-all}
.key-view-section{padding:20px;border-bottom:1px solid var(--border)}
.key-view-section h4{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;font-weight:600}
.key-view-row{display:flex;justify-content:space-between;padding:8px 0;font-size:13px}
.key-view-row .lbl{color:var(--muted)}
.key-view-row .val{color:var(--text);font-weight:500}
.key-view-actions{padding:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px}
.key-action-btn{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:12px 8px;color:var(--muted);font-size:11px;font-weight:600;cursor:pointer;transition:.2s;display:flex;flex-direction:column;align-items:center;gap:6px}
.key-action-btn:hover{background:rgba(var(--accent-rgb),.1);border-color:rgba(var(--accent-rgb),.3);color:var(--accent)}
.log-item{font-size:12px;padding:10px 0;border-bottom:1px solid var(--border);display:flex;gap:10px}
.log-item .t{color:var(--accent);white-space:nowrap;font-size:11px;font-weight:600;min-width:75px}
.tool-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.tool-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px}
.tool-card h3{font-size:12px;color:var(--accent);margin-bottom:12px;font-weight:700}
@media(max-width:900px){.sidebar{transform:translateX(-100%);transition:.3s}.sidebar.open{transform:translateX(0)}.main{margin-left:0;padding:20px}.keys-grid{grid-template-columns:1fr}}
::-webkit-scrollbar{width:8px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="logo">LORI</div>
    <nav class="nav">
        <a href="?admin&tab=dashboard" class="<?= $tab==='dashboard'?'active':'' ?>">🏠 Дашборд</a>
        <a href="?admin&tab=keys" class="<?= $tab==='keys'?'active':'' ?>">🔑 Ключи</a>
        <a href="?admin&tab=generate" class="<?= $tab==='generate'?'active':'' ?>">➕ Создать</a>
        <a href="?admin&tab=bulk" class="<?= $tab==='bulk'?'active':'' ?>">📦 Массово</a>
        <a href="?admin&tab=tools" class="<?= $tab==='tools'?'active':'' ?>">⚙️ Инструменты</a>
        <a href="?admin&tab=logs" class="<?= $tab==='logs'?'active':'' ?>">📋 Логи</a>
        <a href="?admin&tab=github" class="<?= $tab==='github'?'active':'' ?>">🐙 GitHub</a>
        <a href="?admin&tab=settings" class="<?= $tab==='settings'?'active':'' ?>">⚙️ Настройки</a>
        <a href="?admin&tab=theme" class="<?= $tab==='theme'?'active':'' ?>">🎨 Тема</a>
    </nav>
    <div style="padding-top:12px;border-top:1px solid var(--border);margin-top:12px">
        <a href="?admin&logout=1" style="color:var(--muted);text-decoration:none;display:flex;align-items:center;gap:8px;padding:10px">🚪 Выход</a>
    </div>
</aside>
<main class="main">
    <div class="header">
        <h2><?= ucfirst($tab) ?></h2>
        <span style="font-size:11px;color:var(--muted)">🐙 <?= $githubOk?'<span style="color:var(--success)">ON</span>':'<span style="color:var(--danger)">OFF</span>' ?></span>
    </div>
    <?php if($msg): ?><div class="msg">✓ <?= $msg ?></div><?php endif; ?>

    <?php if ($viewKey && isset($db['keys'][$viewKey])):
        $kd=$db['keys'][$viewKey];
        $used=count($kd['activations']??[]);
        $max=(int)($kd['max']??1);
        $status=keyStatus($kd);
        $dl=daysLeft($kd);
    ?>
    <div style="text-align:center;margin-bottom:16px"><a href="?admin&tab=keys" class="btn btn-dark">← К списку</a></div>
    <div class="key-view">
        <div class="key-view-header">
            <div class="key-view-title"><?= htmlspecialchars($viewKey) ?></div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <span class="key-badge"><?= $kd['level'] ?></span>
                <span class="key-badge <?= $status ?>"><?= $status ?></span>
            </div>
        </div>
        <div class="key-view-section">
            <h4>Информация</h4>
            <div class="key-view-row"><span class="lbl">Статус</span><span class="val"><?= $status ?></span></div>
            <div class="key-view-row"><span class="lbl">Дней осталось</span><span class="val"><?= $dl ?></span></div>
            <div class="key-view-row"><span class="lbl">Действует до</span><span class="val"><?= ($kd['expires']??0)>0?date('d.m.Y H:i',$kd['expires']):'∞' ?></span></div>
            <div class="key-view-row"><span class="lbl">Владелец</span><span class="val"><?= htmlspecialchars($kd['owner_name']?:'—') ?></span></div>
            <div class="key-view-row"><span class="lbl">TG ID</span><span class="val"><?= $kd['owner_tg']?:'—' ?></span></div>
            <div class="key-view-row"><span class="lbl">Устройств</span><span class="val"><?= $used ?> / <?= $max ?></span></div>
            <div class="key-view-row"><span class="lbl">Варны</span><span class="val"><?= $kd['warns']??0 ?> / 3</span></div>
            <div class="key-view-row"><span class="lbl">Сбросы HWID</span><span class="val"><?= $kd['reset_left']??0 ?></span></div>
            <div class="key-view-row"><span class="lbl">Заметка</span><span class="val"><?= htmlspecialchars($kd['note']?:'—') ?></span></div>
            <div class="key-view-row"><span class="lbl">Создан</span><span class="val"><?= date('d.m.Y H:i',$kd['created']??time()) ?></span></div>
        </div>
        <?php if($used>0): ?>
        <div class="key-view-section">
            <h4>Устройства</h4>
            <?php foreach($kd['activations'] as $a): ?>
            <div class="log-item">
                <span class="t"><?= date('d.m H:i',$a['time']??0) ?></span>
                <span style="flex:1;font-family:monospace;font-size:11px"><?= htmlspecialchars(substr($a['hwid']??'',0,20)) ?></span>
                <span style="color:var(--muted);font-size:11px"><?= htmlspecialchars($a['ip']??'') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="key-view-actions">
            <button class="key-action-btn" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($viewKey) ?>').then(()=>alert('Скопировано'))">📋<span>Копировать</span></button>
            <form method="post" style="display:contents"><input type="hidden" name="action" value="freeze_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="key-action-btn" type="submit">🔒<span><?= !empty($kd['is_frozen'])?'Разблок':'Блок' ?></span></button></form>
            <form method="post" style="display:contents"><input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="key-action-btn" type="submit">🔄<span>Сброс HWID</span></button></form>
            <form method="post" style="display:contents"><input type="hidden" name="action" value="add_warn"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="key-action-btn" type="submit">⚠️<span>Варн</span></button></form>
            <form method="post" style="display:contents"><input type="hidden" name="action" value="reset_warns"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="key-action-btn" type="submit">✓<span>Сброс варнов</span></button></form>
            <form method="post" style="display:contents"><input type="hidden" name="action" value="make_lifetime"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="key-action-btn" type="submit">♾️<span>Навсегда</span></button></form>
            <a class="key-action-btn" href="?admin&tab=logs">📋<span>Логи</span></a>
            <form method="post" style="display:contents" onsubmit="return confirm('Удалить?')"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="key-action-btn" type="submit" style="color:var(--danger)">🗑️<span>Удалить</span></button></form>
        </div>
        <div class="key-view-section">
            <h4>Продление</h4>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <?php foreach([7,30,90] as $d): ?>
                <form method="post" style="display:contents"><input type="hidden" name="action" value="extend_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="hidden" name="days" value="<?= $d ?>"><button class="btn btn-dark btn-sm" type="submit">+<?= $d ?> дней</button></form>
                <?php endforeach; ?>
            </div>
            <form method="post" style="margin-top:10px;display:flex;gap:8px">
                <input type="hidden" name="action" value="set_note">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <input type="text" name="note" placeholder="Заметка" value="<?= htmlspecialchars($kd['note']??'') ?>">
                <button class="btn btn-primary" type="submit">OK</button>
            </form>
        </div>
    </div>

    <?php elseif ($tab==='dashboard'): ?>
    <div class="grid">
        <div class="card accent"><h3>Всего ключей</h3><div class="val"><?= $totalKeys ?></div></div>
        <div class="card"><h3>Активные</h3><div class="val" style="color:var(--success)"><?= $active ?></div></div>
        <div class="card"><h3>Онлайн</h3><div class="val" style="color:#3b82f6"><?= $onlineCount ?></div></div>
        <div class="card"><h3>Заморожено</h3><div class="val" style="color:#3b82f6"><?= $frozen ?></div></div>
        <div class="card"><h3>Истёкло</h3><div class="val" style="color:var(--danger)"><?= $expired ?></div></div>
    </div>
    <div class="card">
        <h3>Статус системы</h3>
        <div style="margin-top:10px;display:flex;flex-direction:column;gap:6px">
            <div class="key-view-row"><span class="lbl">Статус</span><span class="val"><?= $db['settings']['status'] ?></span></div>
            <div class="key-view-row"><span class="lbl">Софт</span><span class="val"><?= $db['settings']['soft_status'] ?></span></div>
            <div class="key-view-row"><span class="lbl">Версия</span><span class="val"><?= $db['settings']['version'] ?></span></div>
            <div class="key-view-row"><span class="lbl">GitHub</span><span class="val" style="color:<?= $githubOk?'var(--success)':'var(--danger)' ?>"><?= $githubOk?'ON':'OFF' ?></span></div>
        </div>
        <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
            <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="online"><button class="btn btn-primary btn-sm" type="submit">✓ Online</button></form>
            <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="maintenance"><button class="btn btn-dark btn-sm" type="submit">🔧 Maintenance</button></form>
            <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="killswitch"><button class="btn btn-danger btn-sm" type="submit">💀 Killswitch</button></form>
            <form method="post"><input type="hidden" name="action" value="toggle_global_freeze"><button class="btn btn-dark btn-sm" type="submit">🔒 <?= !empty($db['settings']['global_freeze'])?'Unfreeze':'Freeze All' ?></button></form>
        </div>
    </div>

    <?php elseif ($tab==='keys'): ?>
    <div class="keys-grid">
    <?php foreach($db['keys'] as $k=>$kd):
        $used=count($kd['activations']??[]);
        $max=$kd['max']??1;
        $dl=daysLeft($kd);
        $status=keyStatus($kd);
    ?>
        <a href="?admin&view=<?= urlencode($k) ?>" class="key-card">
            <div class="key-header">
                <div class="key-name"><?= htmlspecialchars($k) ?></div>
                <span class="key-badge <?= $status ?>"><?= $status ?></span>
            </div>
            <div style="font-size:11px;color:var(--muted);margin-bottom:6px"><?= $kd['level'] ?> · <?= $kd['owner_name']?:($kd['owner_tg']?:'—') ?></div>
            <div class="key-stats">
                <span>👥 <?= $used ?>/<?= $max ?></span>
                <span>⏱ <?= $dl ?>д</span>
                <?php if(($kd['warns']??0)>0): ?><span style="color:var(--warning)">⚠️ <?= $kd['warns'] ?></span><?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>
    </div>

    <?php elseif ($tab==='generate'): ?>
    <div class="card" style="max-width:500px">
        <h3>Создать ключ</h3>
        <form method="post" style="margin-top:14px;display:flex;flex-direction:column;gap:10px">
            <input type="hidden" name="action" value="gen_key">
            <input type="text" name="custom_name" placeholder="Имя ключа (опционально)">
            <div style="display:flex;gap:8px">
                <input type="number" name="hours" value="24" placeholder="Часы" style="flex:1">
                <input type="number" name="max" value="1" min="1" placeholder="Устройств" style="flex:1">
                <select name="level" style="flex:1">
                    <option value="trial">Trial</option>
                    <option value="free">Free</option>
                    <option value="premium" selected>Premium</option>
                    <option value="elite">Elite</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">➕ Создать</button>
        </form>
    </div>

    <?php elseif ($tab==='bulk'): ?>
    <div class="card" style="max-width:500px">
        <h3>Массовая генерация</h3>
        <form method="post" style="margin-top:14px;display:flex;flex-direction:column;gap:10px">
            <input type="hidden" name="action" value="bulk_generate">
            <div style="display:flex;gap:8px">
                <input type="number" name="count" value="10" min="1" max="50" placeholder="Кол-во" style="flex:1">
                <input type="number" name="hours" value="24" placeholder="Часы" style="flex:1">
                <input type="number" name="max" value="1" min="1" placeholder="Устройств" style="flex:1">
            </div>
            <select name="level">
                <option value="trial">Trial</option>
                <option value="free">Free</option>
                <option value="premium" selected>Premium</option>
                <option value="elite">Elite</option>
            </select>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">➕ Создать</button>
        </form>
    </div>

    <?php elseif ($tab==='tools'): ?>
    <div class="tool-grid">
        <div class="tool-card">
            <h3>🗑️ Очистка</h3>
            <form method="post" onsubmit="return confirm('Удалить истёкшие?')"><input type="hidden" name="action" value="delete_expired"><button class="btn btn-danger btn-sm" type="submit">Удалить истёкшие</button></form>
            <form method="post" style="margin-top:6px"><input type="hidden" name="action" value="clear_logs"><button class="btn btn-dark btn-sm" type="submit">Очистить логи</button></form>
        </div>
        <div class="tool-card">
            <h3>📥 Экспорт</h3>
            <a class="btn btn-dark btn-sm" href="?admin&action=export_json">💾 Скачать JSON</a>
        </div>
        <div class="tool-card">
            <h3>🐙 GitHub</h3>
            <form method="post"><input type="hidden" name="action" value="github_force_push"><button class="btn btn-primary btn-sm" type="submit">Force Push</button></form>
            <form method="post" style="margin-top:6px"><input type="hidden" name="action" value="github_force_pull"><button class="btn btn-dark btn-sm" type="submit">Force Pull</button></form>
        </div>
    </div>

    <?php elseif ($tab==='logs'): ?>
    <div class="card">
        <h3>Логи</h3>
        <div style="margin-top:12px;max-height:600px;overflow-y:auto">
        <?php foreach(array_slice($db['logs'],0,100) as $l): ?>
        <div class="log-item">
            <span class="t"><?= date('d.m H:i',$l['time']) ?></span>
            <span style="flex:1"><?= htmlspecialchars($l['text']) ?></span>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <?php elseif ($tab==='github'): ?>
    <div class="card" style="max-width:600px">
        <h3>🐙 GitHub Sync</h3>
        <div style="margin-top:12px;color:var(--muted);font-size:13px;line-height:1.8">
            Статус: <b style="color:<?= $githubOk?'var(--success)':'var(--danger)' ?>"><?= $githubOk?'Настроен':'Не настроен' ?></b><br>
            Repo: <code><?= htmlspecialchars($ghRepo?:'—') ?></code><br>
            Path: <code><?= htmlspecialchars($ghPath) ?></code><br>
            Branch: <code><?= htmlspecialchars($ghBranch) ?></code>
        </div>
        <div style="display:flex;gap:8px;margin-top:14px">
            <form method="post"><input type="hidden" name="action" value="github_force_push"><button class="btn btn-primary" type="submit">📤 Force Push</button></form>
            <form method="post"><input type="hidden" name="action" value="github_force_pull"><button class="btn btn-dark" type="submit">📥 Force Pull</button></form>
        </div>
    </div>

    <?php elseif ($tab==='settings'): ?>
    <div class="card" style="max-width:600px">
        <h3>Настройки</h3>
        <form method="post" style="margin-top:14px;display:flex;flex-direction:column;gap:10px">
            <input type="hidden" name="action" value="save_settings">
            <div class="key-view-row"><span class="lbl">Version</span><input type="text" name="version" value="<?= htmlspecialchars($db['settings']['version']) ?>" style="width:140px"></div>
            <div class="key-view-row"><span class="lbl">Checksum</span><input type="text" name="checksum" value="<?= htmlspecialchars($db['settings']['checksum']) ?>" style="width:240px"></div>
            <div class="key-view-row"><span class="lbl">Download URL</span><input type="text" name="download_url" value="<?= htmlspecialchars($db['settings']['download_url']) ?>" style="width:240px"></div>
            <div class="key-view-row"><span class="lbl">Сбросы HWID</span><input type="number" name="user_hwid_resets" value="<?= (int)$db['settings']['user_hwid_resets'] ?>" style="width:80px"></div>
            <div class="key-view-row"><span class="lbl">Заморозки/нед</span><input type="number" name="user_freeze_per_week" value="<?= (int)$db['settings']['user_freeze_per_week'] ?>" style="width:80px"></div>
            <label><input type="checkbox" name="github_sync" value="1" <?= !empty($db['settings']['github_sync'])?'checked':'' ?>> GitHub auto-sync</label>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">💾 Сохранить</button>
        </form>
    </div>

    <?php elseif ($tab==='theme'): ?>
    <div class="card" style="max-width:500px">
        <h3>🎨 Тема</h3>
        <form method="post" style="margin-top:14px;display:flex;flex-direction:column;gap:12px">
            <input type="hidden" name="action" value="set_theme">
            <label>Цвет фона <input type="color" name="panel_bg_color" value="<?= htmlspecialchars($panelBgColor) ?>" style="width:100%;height:40px;border:none;background:none"></label>
            <label>Акцент <input type="color" name="panel_accent" value="<?= htmlspecialchars($accent) ?>" style="width:100%;height:40px;border:none;background:none"></label>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">Применить</button>
        </form>
    </div>
    <?php endif; ?>
</main>
</div>
</body>
</html>
<?php
    exit;
}

// Главная страница если нет ?admin
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>LORI</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0a0a;color:#3b82f6;font-family:Inter,system-ui;letter-spacing:6px;font-weight:800;font-size:32px}body::before{content:"";position:fixed;inset:0;background:radial-gradient(circle at 20% 50%,rgba(59,130,246,.15),transparent 50%),radial-gradient(circle at 80% 80%,rgba(139,92,246,.1),transparent 50%);z-index:-1}</style></head><body>LORI</body></html>';
?>
