<?php
error_reporting(0);
date_default_timezone_set('Europe/Moscow');

// ============================================================
// 1. HTTPS & SECURITY
// ============================================================
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

if (!$isHttps && php_sapi_name() !== 'cli') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ============================================================
// 2. CONFIG
// ============================================================
$adminPass = 'LoriElite';

$ghToken  = getenv('GITHUB_TOKEN') ?: '';
$ghRepo   = getenv('GITHUB_REPO') ?: '';
$ghPath   = getenv('GITHUB_PATH') ?: 'database.json';
$ghBranch = getenv('GITHUB_BRANCH') ?: 'main';

$dbFile = __DIR__ . '/database.json';
$ghShaCacheFile = __DIR__ . '/.gh_sha_cache';

// ============================================================
// 3. SESSION - ИСПРАВЛЕНО
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('LORI_SESSION');
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

// ============================================================
// 4. GITHUB HELPERS
// ============================================================
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
    return [
        'data' => json_decode(base64_decode(str_replace("\n", '', $j['content'])), true),
        'sha'  => $j['sha']
    ];
}

function ghPut($token, $repo, $path, $branch, $content, $sha) {
    if (!$token || !$repo) return false;
    $url = "https://api.github.com/repos/{$repo}/contents/{$path}";
    $body = json_encode([
        'message' => 'LORI sync ' . date('Y-m-d H:i:s'),
        'content' => base64_encode($content),
        'branch'  => $branch,
        'sha'     => $sha
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'PUT',
        'header'  => "Authorization: token {$token}\r\nContent-Type: application/json\r\nUser-Agent: LORI\r\n",
        'content' => $body,
        'timeout' => 15, 'ignore_errors' => true
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return false;
    $j = json_decode($res, true);
    return $j['content']['sha'] ?? false;
}

$currentSha = @file_get_contents($ghShaCacheFile);
if (!$currentSha && $ghToken && $ghRepo) {
    $g = ghGet($ghToken, $ghRepo, $ghPath, $ghBranch);
    if ($g) {
        $currentSha = $g['sha'];
        @file_put_contents($ghShaCacheFile, $currentSha);
    }
}

// ============================================================
// 5. DATABASE
// ============================================================
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
        if ($g['sha']) {
            $currentSha = $g['sha'];
            @file_put_contents($ghShaCacheFile, $currentSha);
        }
    }
}

foreach (['keys','blacklist','logs','online','access_log'] as $k) {
    if (!isset($db[$k])) $db[$k] = [];
}
if (!isset($db['settings'])) $db['settings'] = [];

$db['settings'] = array_merge([
    'status' => 'online',
    'soft_status' => 'undetected',
    'global_freeze' => false,
    'version' => '1.0.0',
    'checksum' => 'lori_v1',
    'download_url' => '',
    'panel_accent' => '#3b82f6',
    'panel_bg_color' => '#0a0a0a',
    'github_sync' => true,
    'user_hwid_resets' => 2,
    'user_freeze_per_week' => 2
], $db['settings']);

// ============================================================
// 6. CORE FUNCTIONS
// ============================================================
function saveDb() {
    global $db, $dbFile, $ghToken, $ghRepo, $ghPath, $ghBranch, $currentSha;
    $json = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($dbFile, $json);
    
    if (!empty($db['settings']['github_sync']) && $ghToken && $ghRepo) {
        $g = ghGet($ghToken, $ghRepo, $ghPath, $ghBranch);
        $shaToSend = $g ? $g['sha'] : $currentSha;
        if ($shaToSend) {
            $newSha = ghPut($ghToken, $ghRepo, $ghPath, $ghBranch, $json, $shaToSend);
            if ($newSha) {
                $currentSha = $newSha;
                @file_put_contents($ghShaCacheFile, $newSha);
            }
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
        'duration'=>$duration,
        'expires'=>0,
        'first_use'=>0,
        'max'=>$max,
        'activations'=>[],
        'owner_tg'=>$owner_tg,
        'owner_name'=>$owner_name,
        'reset_left'=>(int)($db['settings']['user_hwid_resets']??2),
        'is_frozen'=>false,
        'level'=>$level,
        'created'=>time(),
        'warns'=>0,
        'note'=>''
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
    $now = time();
    if (!empty($kd['is_frozen'])) return 'frozen';
    if (($kd['expires'] ?? 0) > 0 && $now > $kd['expires']) return 'expired';
    if (($kd['first_use'] ?? 0) == 0) return 'unused';
    return 'active';
}

function ico($name, $size=18) {
    $s = (int)$size; $c = 'currentColor';
    $map = [
        'home'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'key'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>',
        'settings'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'zap'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
        'shield'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'trash'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        'copy'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
        'plus'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        'lock'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'refresh'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>',
        'download'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'activity'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        'github'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>',
        'eye'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
        'check'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
        'x'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'logout'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'clock'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
    ];
    return $map[$name] ?? '';
}

// Cleanup online
foreach ($db['online'] as $hwid => $data) {
    if (time() - ($data['last_ping'] ?? 0) > 120) unset($db['online'][$hwid]);
}

$action = $_GET['action'] ?? '';
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

// ============================================================
// 7. API ENDPOINTS
// ============================================================
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
    
    if (!empty($hwid)) {
        $db['online'][$hwid] = ['ip'=>$ip,'key'=>$key?:'-','last_ping'=>time()];
    }
    
    echo json_encode([
        'status'=>$db['settings']['status'],
        'soft_status'=>$db['settings']['soft_status']??'undetected',
        'version'=>$db['settings']['version'],
        'checksum'=>$db['settings']['checksum'],
        'url'=>$db['settings']['download_url'],
        'global_freeze'=>!empty($db['settings']['global_freeze'])
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
    
    if (isset($db['blacklist'][$ip]) || isset($db['blacklist'][$hwid])) {
        echo 'Blocked'; exit;
    }
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
    } else {
        echo 'Device limit';
    }
    exit;
}

if ($action === 'export_json' && isset($_GET['admin'])) {
    if (empty($_SESSION['admin'])) { http_response_code(403); exit; }
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="backup_'.date('Y-m-d_H-i').'.json"');
    echo json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 8. ADMIN PANEL
// ============================================================
if (isset($_GET['admin'])) {
    if (isset($_GET['logout'])) { 
        session_destroy(); 
        header('Location: ?admin'); 
        exit; 
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $adminPass) {
            $_SESSION['admin'] = true;
            addLog("Admin login from $ip");
            header('Location: ?admin'); exit;
        }
        $loginError = 'Неверный пароль';
    }

    if (empty($_SESSION['admin'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LORI</title>
<style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0a0a0a 0%,#1a1a2e 100%);font-family:Inter,system-ui;color:#fff;overflow:hidden}
body::before{content:"";position:fixed;inset:0;background:radial-gradient(circle at 20% 50%,rgba(59,130,246,.15),transparent 50%),radial-gradient(circle at 80% 80%,rgba(139,92,246,.1),transparent 50%);z-index:-1}
.box{width:100%;max-width:420px;padding:48px 40px;background:rgba(20,20,30,.8);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.1);border-radius:24px;box-shadow:0 25px 50px -12px rgba(0,0,0,.5)}
.logo{text-align:center;font-size:32px;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:8px;letter-spacing:2px}
.sub{text-align:center;font-size:11px;color:#64748b;margin-bottom:32px;letter-spacing:3px;text-transform:uppercase}
input{width:100%;padding:14px 18px;background:rgba(10,10,20,.6);border:1px solid rgba(255,255,255,.1);border-radius:12px;color:#fff;margin-bottom:16px;outline:none;font-size:14px;transition:.2s}
input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.2)}
button{width:100%;padding:14px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border:none;border-radius:12px;color:#fff;font-weight:600;cursor:pointer;font-size:14px;transition:.2s}
button:hover{transform:translateY(-2px);box-shadow:0 10px 20px -5px rgba(59,130,246,.5)}
.err{color:#ef4444;text-align:center;font-size:13px;margin-bottom:16px;padding:12px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);border-radius:10px}
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

    // ============================================================
    // ADMIN ACTIONS
    // ============================================================
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
            if ($act==='freeze_key') { 
                $db['keys'][$k]['is_frozen']=empty($db['keys'][$k]['is_frozen']); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit; 
            }
            if ($act==='reset_hwid') { 
                $db['keys'][$k]['activations']=[]; 
                saveDb(); addLog("HWID reset $k");
                header('Location:?admin&view='.urlencode($k).'&msg=HWID'); exit; 
            }
            if ($act==='delete_key') { 
                unset($db['keys'][$k]); 
                saveDb();
                redirectAdmin('keys','Удалён'); 
            }
            if ($act==='extend_key') {
                $days=max(1,(int)($_POST['days']??7));
                if(($db['keys'][$k]['expires']??0)==0) $db['keys'][$k]['expires']=time()+$days*86400;
                else $db['keys'][$k]['expires']+=$days*86400;
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=+'.$days); exit;
            }
            if ($act==='set_note') { 
                $db['keys'][$k]['note']=trim($_POST['note']??''); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Note'); exit; 
            }
            if ($act==='make_lifetime') { 
                $db['keys'][$k]['duration']=0; 
                $db['keys'][$k]['expires']=0; 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Life'); exit; 
            }
            if ($act==='add_warn') { 
                $db['keys'][$k]['warns']=min(3,($db['keys'][$k]['warns']??0)+1); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Warn'); exit; 
            }
        }

        if ($act==='toggle_global_freeze') { 
            $db['settings']['global_freeze']=empty($db['settings']['global_freeze']); 
            saveDb();
            redirectAdmin('dashboard','OK'); 
        }
        if ($act==='set_status') { 
            $db['settings']['status']=$_POST['status']??'online'; 
            saveDb();
            redirectAdmin('dashboard','Status'); 
        }
        if ($act==='save_settings') {
            foreach(['version','checksum','download_url'] as $f) 
                if(isset($_POST[$f])) $db['settings'][$f]=trim($_POST[$f]);
            $db['settings']['github_sync']=!empty($_POST['github_sync']);
            $db['settings']['user_hwid_resets']=max(0,(int)($_POST['user_hwid_resets']??2));
            $db['settings']['user_freeze_per_week']=max(0,(int)($_POST['user_freeze_per_week']??2));
            saveDb();
            redirectAdmin('settings','Сохранено');
        }
        if ($act==='set_theme') {
            $c=trim($_POST['panel_bg_color']??'#0a0a0a'); 
            if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$c))$c='#0a0a0a';
            $db['settings']['panel_bg_color']=$c;
            $a=trim($_POST['panel_accent']??'#3b82f6'); 
            if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$a))$a='#3b82f6';
            $db['settings']['panel_accent']=$a;
            saveDb();
            redirectAdmin('theme','Тема обновлена');
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
            saveDb();
            redirectAdmin('tools',"Удалено $n");
        }
    }

    // Stats
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
:root{
    --bg:<?= $panelBgColor ?>;
    --card:rgba(20,20,30,0.8);
    --border:rgba(255,255,255,0.08);
    --accent:<?= $accent ?>;
    --accent-rgb:<?= implode(',',$rgb) ?>;
    --text:#e2e8f0;
    --muted:#64748b;
    --success:#10b981;
    --warning:#f59e0b;
    --danger:#ef4444;
}
*{margin:0;padding:0;box-sizing:border-box}
body{
    font-family:'Inter',system-ui,sans-serif;
    background:var(--bg);
    color:var(--text);
    min-height:100vh;
    font-size:14px;
}
body::before{
    content:'';position:fixed;inset:0;z-index:-1;
    background:
        radial-gradient(circle at 20% 30%,rgba(var(--accent-rgb),.1),transparent 50%),
        radial-gradient(circle at 80% 70%,rgba(139,92,246,.08),transparent 50%);
}

.layout{display:flex;min-height:100vh}
.sidebar{
    width:240px;
    background:rgba(10,10,20,0.95);
    backdrop-filter:blur(20px);
    border-right:1px solid var(--border);
    padding:24px 16px;
    display:flex;flex-direction:column;
    position:fixed;height:100vh;overflow-y:auto;
}
.logo{
    font-size:28px;font-weight:800;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;
    margin-bottom:32px;padding:0 8px;letter-spacing:1px;
}
.nav{display:flex;flex-direction:column;gap:4px;flex:1}
.nav a{
    display:flex;align-items:center;gap:12px;
    padding:12px 14px;color:var(--muted);
    text-decoration:none;border-radius:10px;
    transition:.2s;font-size:13px;font-weight:500;
}
.nav a:hover{background:rgba(255,255,255,0.05);color:var(--text)}
.nav a.active{
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    color:#fff;box-shadow:0 4px 12px rgba(var(--accent-rgb),.3);
}
.nav a svg{opacity:.7;flex-shrink:0}
.nav a.active svg{opacity:1}

.main{flex:1;margin-left:240px;padding:32px}
.header{
    display:flex;justify-content:space-between;align-items:center;
    margin-bottom:32px;
}
.header h2{
    font-size:28px;font-weight:700;
    background:linear-gradient(135deg,var(--text),var(--muted));
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}

.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px}
.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:16px;
    padding:24px;
    backdrop-filter:blur(12px);
    position:relative;overflow:hidden;
    transition:.2s;
}
.card:hover{border-color:rgba(var(--accent-rgb),.3)}
.card h3{color:var(--muted);font-size:12px;margin-bottom:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.card .val{font-size:32px;font-weight:800;color:var(--text)}
.card .icon{position:absolute;right:20px;top:20px;opacity:.15;color:var(--accent)}
.card.accent{background:linear-gradient(135deg,rgba(var(--accent-rgb),.15),rgba(var(--accent-rgb),.05));border-color:rgba(var(--accent-rgb),.3)}

.msg{
    background:rgba(16,185,129,.1);
    border:1px solid rgba(16,185,129,.3);
    color:var(--success);
    padding:14px 18px;border-radius:12px;
    margin-bottom:20px;font-size:13px;
}

.btn{
    padding:10px 18px;border-radius:10px;border:none;
    font-weight:600;cursor:pointer;transition:.2s;
    display:inline-flex;align-items:center;gap:8px;
    font-size:13px;text-decoration:none;
}
.btn-primary{background:linear-gradient(135deg,var(--accent),#8b5cf6);color:#fff}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(var(--accent-rgb),.4)}
.btn-dark{background:var(--card);border:1px solid var(--border);color:var(--text);backdrop-filter:blur(10px)}
.btn-dark:hover{border-color:rgba(var(--accent-rgb),.3)}
.btn-danger{background:rgba(239,68,68,.15);color:var(--danger);border:1px solid rgba(239,68,68,.2)}
.btn-sm{padding:7px 12px;font-size:12px}

input,select,textarea{
    padding:11px 15px;border-radius:10px;
    border:1px solid var(--border);
    background:rgba(10,10,20,.6);
    color:var(--text);font-size:13px;outline:none;
    transition:.2s;font-family:inherit;width:100%;
}
input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(var(--accent-rgb),.1)}
textarea{resize:vertical;min-height:80px}
label{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px;cursor:pointer}
label input[type="checkbox"]{width:auto;margin:0}

.keys-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
.key-card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:14px;
    padding:20px;
    transition:.2s;cursor:pointer;
    text-decoration:none;color:inherit;display:block;
    backdrop-filter:blur(12px);
}
.key-card:hover{transform:translateY(-3px);border-color:rgba(var(--accent-rgb),.4);box-shadow:0 10px 30px -10px rgba(0,0,0,.5)}
.key-header{display:flex;justify-content:space-between;align-items:start;margin-bottom:14px;gap:10px}
.key-name{font-weight:700;font-size:15px;color:var(--text);word-break:break-all;flex:1}
.key-badge{
    font-size:11px;padding:4px 10px;border-radius:8px;
    background:rgba(255,255,255,.08);color:var(--muted);
    font-weight:600;text-transform:uppercase;letter-spacing:.3px;
}
.key-badge.active{background:rgba(16,185,129,.15);color:var(--success)}
.key-badge.frozen{background:rgba(59,130,246,.15);color:#3b82f6}
.key-badge.expired{background:rgba(239,68,68,.15);color:var(--danger)}
.key-badge.unused{background:rgba(245,158,11,.15);color:var(--warning)}
.key-stats{display:flex;gap:16px;font-size:12px;color:var(--muted);margin-top:12px}
.key-stats span{display:flex;align-items:center;gap:4px}

.key-view{
    max-width:700px;margin:0 auto;
    background:var(--card);border:1px solid var(--border);
    border-radius:20px;overflow:hidden;backdrop-filter:blur(20px);
}
.key-view-header{
    padding:28px;
    background:linear-gradient(135deg,rgba(var(--accent-rgb),.15),transparent);
    border-bottom:1px solid var(--border);
}
.key-view-title{font-size:26px;font-weight:800;margin-bottom:10px;word-break:break-all}
.key-view-section{padding:24px;border-bottom:1px solid var(--border)}
.key-view-section h4{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;font-weight:600}
.key-view-row{display:flex;justify-content:space-between;padding:10px 0;font-size:14px}
.key-view-row .lbl{color:var(--muted)}
.key-view-row .val{color:var(--text);font-weight:500}
.key-view-actions{padding:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}
.key-action-btn{
    background:rgba(255,255,255,.03);
    border:1px solid var(--border);
    border-radius:12px;padding:14px 10px;
    color:var(--muted);font-size:12px;font-weight:600;
    cursor:pointer;transition:.2s;
    display:flex;flex-direction:column;align-items:center;gap:8px;
}
.key-action-btn:hover{background:rgba(var(--accent-rgb),.1);border-color:rgba(var(--accent-rgb),.3);color:var(--accent)}

.log-item{
    font-size:13px;padding:12px 0;
    border-bottom:1px solid var(--border);
    display:flex;gap:12px;
}
.log-item .t{color:var(--accent);white-space:nowrap;font-size:12px;font-weight:600;min-width:80px}

@media(max-width:900px){
    .sidebar{transform:translateX(-100%);transition:.3s}
    .sidebar.open{transform:translateX(0)}
    .main{margin-left:0;padding:20px}
    .keys-grid{grid-template-columns:1fr}
}

::-webkit-scrollbar{width:8px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}
</style>
</head>
<body>

<div class="layout">
<aside class="sidebar">
    <div class="logo">LORI</div>
    <nav class="nav">
        <a href="?admin&tab=dashboard" class="<?= $tab==='dashboard'?'active':'' ?>"><?= ico('home',18) ?> <span>Дашборд</span></a>
        <a href="?admin&tab=keys" class="<?= $tab==='keys'?'active':'' ?>"><?= ico('key',18) ?> <span>Ключи</span></a>
        <a href="?admin&tab=generate" class="<?= $tab==='generate'?'active':'' ?>"><?= ico('plus',18) ?> <span>Создать</span></a>
        <a href="?admin&tab=bulk" class="<?= $tab==='bulk'?'active':'' ?>"><?= ico('zap',18) ?> <span>Массово</span></a>
        <a href="?admin&tab=tools" class="<?= $tab==='tools'?'active':'' ?>"><?= ico('shield',18)
}
