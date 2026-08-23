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

$githubToken  = getenv('GITHUB_TOKEN') ?: '';
$githubRepo   = getenv('GITHUB_REPO') ?: '';
$githubPath   = getenv('GITHUB_PATH') ?: 'database.json';
$githubBranch = getenv('GITHUB_BRANCH') ?: 'main';

$dbFile = __DIR__ . '/database.json';

function githubGet($repo, $path, $branch, $token) {
    if (!$token || !$repo) return null;
    $url = "https://api.github.com/repos/{$repo}/contents/{$path}?ref={$branch}";
    $opts = ['http' => [
        'header' => "User-Agent: LoriPanel\r\nAuthorization: token {$token}\r\nAccept: application/vnd.github.v3+json\r\n",
        'method' => 'GET', 'ignore_errors' => true, 'timeout' => 15
    ]];
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if ($res === false) return null;
    $j = json_decode($res, true);
    if (empty($j['content'])) return null;
    $raw = base64_decode(str_replace("\n", '', $j['content']));
    $data = json_decode($raw, true);
    return is_array($data) ? ['data' => $data, 'sha' => $j['sha'] ?? ''] : null;
}

function githubPut($repo, $path, $branch, $token, $content, $sha = '') {
    if (!$token || !$repo) return false;
    $url = "https://api.github.com/repos/{$repo}/contents/{$path}";
    $body = [
        'message' => 'lori db ' . date('Y-m-d H:i:s'),
        'content' => base64_encode($content),
        'branch'  => $branch
    ];
    if ($sha !== '') $body['sha'] = $sha;
    $opts = ['http' => [
        'header' => "User-Agent: LoriPanel\r\nAuthorization: token {$token}\r\nAccept: application/vnd.github.v3+json\r\nContent-Type: application/json\r\n",
        'method' => 'PUT',
        'content' => json_encode($body),
        'ignore_errors' => true,
        'timeout' => 20
    ]];
    $res = @file_get_contents($url, false, stream_context_create($opts));
    return $res !== false;
}

$db = [];
$githubSha = '';
if (file_exists($dbFile)) {
    $db = json_decode(file_get_contents($dbFile), true);
    if (!is_array($db)) $db = [];
}
if ((empty($db['keys']) || !is_array($db['keys'])) && $githubToken && $githubRepo) {
    $g = githubGet($githubRepo, $githubPath, $githubBranch, $githubToken);
    if ($g && is_array($g['data'])) {
        $db = $g['data'];
        $githubSha = $g['sha'] ?? '';
        @file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

foreach (['keys','blacklist','logs','online','login_log','extend_log','access_log','notes_global'] as $k) {
    if (!isset($db[$k]) || !is_array($db[$k])) $db[$k] = [];
}
if (!isset($db['settings']) || !is_array($db['settings'])) $db['settings'] = [];
if (!isset($db['stats']) || !is_array($db['stats'])) $db['stats'] = ['purchases'=>0,'stars'=>0,'activations'=>0];

$db['settings'] = array_merge([
    'status' => 'online',
    'soft_status' => 'undetected',
    'global_freeze' => false,
    'version' => '5.0.0',
    'checksum' => '5c8714cf5eb4010c2cad5b14ac476f3f3f695d26',
    'download_url' => 'https://example.com/script.lua',
    'emergency_msg' => '',
    'broadcast' => '',
    'panel_bg' => '',
    'panel_bg_color' => '#030303',
    'panel_accent' => '#22c55e',
    'panel_overlay' => '0.75',
    'panel_blur' => '12',
    'bot_welcome' => "LORI\nВыберите срок:",
    'purchases_enabled' => true,
    'user_hwid_resets' => 2,
    'user_freeze_per_week' => 2,
    'aura_hwid_resets' => 6,
    'aura_freeze_per_week' => 6,
    'aura_extra_devices' => 2,
    'maintenance_msg' => 'Сервер на обслуживании',
    'github_sync' => true
], $db['settings']);

function saveDb() {
    global $db, $dbFile, $githubToken, $githubRepo, $githubPath, $githubBranch, $githubSha;
    $json = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($dbFile, $json);
    if (!empty($db['settings']['github_sync']) && $githubToken && $githubRepo) {
        if ($githubSha === '') {
            $g = githubGet($githubRepo, $githubPath, $githubBranch, $githubToken);
            if ($g) $githubSha = $g['sha'] ?? '';
        }
        $ok = githubPut($githubRepo, $githubPath, $githubBranch, $githubToken, $json, $githubSha);
        if ($ok) {
            $g2 = githubGet($githubRepo, $githubPath, $githubBranch, $githubToken);
            if ($g2) $githubSha = $g2['sha'] ?? $githubSha;
        }
    }
}
function addLog($text) {
    global $db;
    array_unshift($db['logs'], ['time'=>time(),'text'=>$text]);
    if (count($db['logs']) > 600) array_pop($db['logs']);
    saveDb();
}
function addAccessLog($key, $hwid, $ip, $ok) {
    global $db;
    array_unshift($db['access_log'], ['time'=>time(),'key'=>$key,'hwid'=>$hwid,'ip'=>$ip,'ok'=>$ok]);
    if (count($db['access_log']) > 800) array_pop($db['access_log']);
    saveDb();
}
function addExtendLog($text, $count=0, $days=0) {
    global $db;
    array_unshift($db['extend_log'], ['time'=>time(),'text'=>$text,'count'=>$count,'days'=>$days]);
    if (count($db['extend_log']) > 200) array_pop($db['extend_log']);
    saveDb();
}
function makeKeyData($duration, $max, $level, $owner_tg=0, $owner_name='', $named=false) {
    global $db;
    return [
        'duration'=>$duration,'expires'=>0,'first_use'=>0,'max'=>$max,
        'activations'=>[],'owner_tg'=>$owner_tg,'owner_name'=>$owner_name,
        'reset_left'=>(int)($db['settings']['user_hwid_resets']??2),
        'is_frozen'=>false,'level'=>$level,'created'=>time(),
        'warns'=>0,'note'=>'','aura'=>false,'named'=>$named,'tag'=>'',
        'soft_ban_until'=>0,'android_id'=>'','freeze_week'=>[],
        'color'=>'','pulse'=>false,'silent'=>false
    ];
}
function redirectAdmin($tab='dashboard', $msg='') {
    $url = '?admin&tab='.urlencode($tab);
    if ($msg !== '') $url .= '&msg='.urlencode($msg);
    header('Location: '.$url); exit;
}
function weekId() { return date('o-W'); }
function canUserFreeze($kd) {
    global $db;
    $limit = !empty($kd['aura'])
        ? (int)($db['settings']['aura_freeze_per_week'] ?? 6)
        : (int)($db['settings']['user_freeze_per_week'] ?? 2);
    $w = weekId();
    $used = (int)($kd['freeze_week'][$w] ?? 0);
    return $used < $limit;
}
function registerUserFreeze(&$kd) {
    $w = weekId();
    if (!isset($kd['freeze_week']) || !is_array($kd['freeze_week'])) $kd['freeze_week'] = [];
    $kd['freeze_week'][$w] = ($kd['freeze_week'][$w] ?? 0) + 1;
}

function ico($name, $size=18) {
    $s = (int)$size;
    $c = 'currentColor';
    $map = [
        'copy'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/></svg>',
        'user'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>',
        'warn'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M12 3L2 21h20L12 3z"/><path d="M12 10v5M12 18h.01"/></svg>',
        'reset'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M3 12a9 9 0 103-6.7"/><path d="M3 4v5h5"/></svg>',
        'link'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M10 13a5 5 0 007 0l2-2a5 5 0 00-7-7l-1 1"/><path d="M14 11a5 5 0 00-7 0l-2 2a5 5 0 007 7l1-1"/></svg>',
        'refresh'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M21 12a9 9 0 11-2.6-6.2"/><path d="M21 3v6h-6"/></svg>',
        'lock'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg>',
        'eye'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>',
        'plane'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>',
        'key'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><circle cx="8" cy="15" r="5"/><path d="M12 12l9-9M17 3l4 4"/></svg>',
        'zap'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/></svg>',
        'clock'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        'star'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>',
        'shield'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/></svg>',
        'trash'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>',
        'plus'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg>',
        'check'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M5 12l5 5L20 7"/></svg>',
        'x'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M6 6l12 12M18 6L6 18"/></svg>',
        'github'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.9a3.4 3.4 0 00-.9-2.6c3-.3 6.1-1.5 6.1-6.6a5 5 0 00-1.4-3.6 4.7 4.7 0 00-.1-3.6s-1.1-.3-3.5 1.3a12 12 0 00-6.2 0C6.5 2.8 5.4 3.1 5.4 3.1a4.7 4.7 0 00-.1 3.6A5 5 0 003.9 10c0 5.1 3.2 6.3 6.1 6.6a3.4 3.4 0 00-.9 2.6V22"/></svg>',
        'pulse'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M3 12h4l2-6 4 12 2-6h6"/></svg>',
        'spark'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M12 2v4M12 18v4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M2 12h4M18 12h4M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8"/></svg>',
        'globe'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18"/></svg>',
        'db'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>',
        'search'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>',
        'download'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M12 3v12M7 10l5 5 5-5M5 19h14"/></svg>',
        'upload'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M12 21V9M7 14l5-5 5 5M5 5h14"/></svg>',
        'settings'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
        'home'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M3 11l9-8 9 8v9a2 2 0 01-2 2H5a2 2 0 01-2-2v-9z"/><path d="M9 22V12h6v10"/></svg>',
        'list'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>',
        'ban'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M5 5l14 14"/></svg>',
        'moon'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M21 14.5A8.5 8.5 0 1110 3a7 7 0 0011 11.5z"/></svg>',
        'sun'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
        'tag'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M20 10L12 2H4v8l8 8 8-8z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>',
        'bell'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M18 8A6 6 0 106 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/></svg>',
        'edit'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>',
    ];
    return $map[$name] ?? '';
}

foreach ($db['online'] as $hwid => $data) {
    if (time() - ($data['last_ping'] ?? 0) > 120) unset($db['online'][$hwid]);
}
saveDb();

$action = $_GET['action'] ?? '';
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

// API
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
    if ($db['settings']['status'] === 'maintenance') { echo $db['settings']['maintenance_msg'] ?? 'Maintenance'; exit; }
    if ($db['settings']['status'] === 'killswitch') { echo $db['settings']['emergency_msg'] ?: 'Stopped'; exit; }
    if (!empty($db['settings']['global_freeze'])) { echo 'Frozen'; exit; }
    if (($db['settings']['soft_status'] ?? '') === 'detected') { echo 'Detected'; exit; }
    if (empty($key) || empty($hwid)) { echo empty($key)?'No key':'No HWID'; exit; }
    if (isset($db['blacklist'][$ip]) || isset($db['blacklist'][$hwid])) {
        addAccessLog($key,$hwid,$ip,false); echo 'Blocked'; exit;
    }
    if (!isset($db['keys'][$key])) { addAccessLog($key,$hwid,$ip,false); echo 'Invalid key'; exit; }
    $kd = $db['keys'][$key];
    $now = time();
    if (!empty($kd['is_frozen'])) { addAccessLog($key,$hwid,$ip,false); echo 'Key frozen'; exit; }
    if (!empty($kd['soft_ban_until']) && $now < $kd['soft_ban_until']) { addAccessLog($key,$hwid,$ip,false); echo 'Temporary ban'; exit; }
    if (($kd['expires'] ?? 0) !== 0 && $now > $kd['expires']) { addAccessLog($key,$hwid,$ip,false); echo 'Expired'; exit; }
    $max = (int)($kd['max'] ?? 1);
    if (!empty($kd['aura'])) $max += (int)($db['settings']['aura_extra_devices'] ?? 2);
    $acts = $kd['activations'] ?? [];
    foreach ($acts as &$a) {
        if (($a['hwid'] ?? '') === $hwid) {
            $a['ip']=$ip; $a['last_active']=$now; $a['launches']=($a['launches']??0)+1;
            saveDb(); addAccessLog($key,$hwid,$ip,true);
            $db['stats']['activations']=($db['stats']['activations']??0)+1; saveDb();
            echo 'SUCCESS'; exit;
        }
    }
    unset($a);
    if (count($acts) < $max) {
        if (($kd['first_use'] ?? 0) == 0) {
            $db['keys'][$key]['first_use'] = $now;
            if (($kd['duration'] ?? 0) > 0) $db['keys'][$key]['expires'] = $now + $kd['duration'];
        }
        $db['keys'][$key]['activations'][] = ['hwid'=>$hwid,'ip'=>$ip,'time'=>$now,'last_active'=>$now,'launches'=>1];
        saveDb(); addLog("Activated $key | ".substr($hwid,0,12)." | $ip");
        addAccessLog($key,$hwid,$ip,true);
        $db['stats']['activations']=($db['stats']['activations']??0)+1; saveDb();
        echo 'SUCCESS';
    } else {
        addAccessLog($key,$hwid,$ip,false); echo 'Device limit';
    }
    exit;
}

if (($action === 'export_keys' || $action === 'export_json') && isset($_GET['admin'])) {
    session_start();
    if (empty($_SESSION['admin'])) { http_response_code(403); exit; }
    if ($action === 'export_keys') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="keys_'.date('Y-m-d').'.txt"');
        foreach ($db['keys'] as $k => $kd) echo $k."\n";
    } else {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="backup_'.date('Y-m-d_H-i').'.json"');
        echo json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

session_start();
if (isset($_GET['admin'])) {

    if (isset($_GET['logout'])) { session_destroy(); header('Location: ?admin'); exit; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $adminPass) {
            $_SESSION['admin'] = true;
            array_unshift($db['login_log'], ['time'=>time(),'ip'=>$ip,'ok'=>true]);
            saveDb(); header('Location: ?admin'); exit;
        }
        array_unshift($db['login_log'], ['time'=>time(),'ip'=>$ip,'ok'=>false]);
        saveDb(); $loginError = 'Неверный пароль';
    }

    if (empty($_SESSION['admin'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LORI</title>
<style>@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap");
*{margin:0;padding:0;box-sizing:border-box}body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#030303;font-family:Inter,system-ui;color:#e5e5e5}
.box{width:100%;max-width:360px;padding:48px 36px;background:#0a0a0c;border:1px solid rgba(34,197,94,.18);border-radius:20px}
.logo{text-align:center;font-size:11px;letter-spacing:10px;color:#22c55e;margin-bottom:24px}
h1{font-size:20px;text-align:center;margin-bottom:6px;color:#fff}
.sub{text-align:center;font-size:10px;color:#444;margin-bottom:28px;letter-spacing:2px}
input{width:100%;padding:14px 16px;background:#08080a;border:1px solid #1a1a1c;border-radius:12px;color:#fff;margin-bottom:12px;outline:none}
input:focus{border-color:#22c55e}
button{width:100%;padding:14px;background:linear-gradient(135deg,#22c55e,#16a34a);border:none;border-radius:12px;color:#000;font-weight:600;cursor:pointer}
.err{color:#ef4444;text-align:center;font-size:12px;margin-bottom:10px}</style></head><body>
<div class="box"><div class="logo">LORI</div><h1>Control Panel</h1><div class="sub">RESTRICTED</div>
'.(!empty($loginError)?'<div class="err">'.$loginError.'</div>':'').'
<form method="post"><input type="password" name="password" placeholder="Password" required autofocus>
<button type="submit">Enter</button></form></div></body></html>';
        exit;
    }

    $tab = $_GET['tab'] ?? 'dashboard';
    $viewKey = $_GET['view'] ?? '';
    $msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $act = $_POST['action'];
        $k = $_POST['key'] ?? '';

        if ($act === 'gen_key') {
            $hours=(int)($_POST['hours']??24); $max=max(1,(int)($_POST['max']??1));
            $level=in_array($_POST['level']??'',['trial','free','media','premium','elite'])?$_POST['level']:'premium';
            $customName=trim($_POST['custom_name']??''); $duration=$hours===0?0:$hours*3600;
            if ($customName!=='') {
                if (isset($db['keys'][$customName])) redirectAdmin('generate','Имя занято');
                $db['keys'][$customName]=makeKeyData($duration,$max,$level,0,$customName,true);
                saveDb(); addLog("Named: $customName"); redirectAdmin('generate',"Создан: $customName");
            }
            $newKey=strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
            $db['keys'][$newKey]=makeKeyData($duration,$max,$level);
            saveDb(); addLog("Key: $newKey"); redirectAdmin('generate',"Создан: $newKey");
        }
        if ($act === 'bulk_generate') {
            $count=max(1,min(50,(int)($_POST['count']??10))); $hours=(int)($_POST['hours']??24);
            $max=max(1,(int)($_POST['max']??1)); $level=in_array($_POST['level']??'',['trial','free','media','premium','elite'])?$_POST['level']:'premium';
            $duration=$hours===0?0:$hours*3600;
            for($i=0;$i<$count;$i++){
                $newKey=strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand().$i,true)),0,8));
                $db['keys'][$newKey]=makeKeyData($duration,$max,$level);
            }
            saveDb(); redirectAdmin('bulk',"Создано $count");
        }
        if ($act === 'give_key') {
            $tgId=(int)($_POST['tg_id']??0); $hours=(int)($_POST['hours']??24); $max=max(1,(int)($_POST['max']??1));
            $level=in_array($_POST['level']??'',['trial','free','media','premium','elite'])?$_POST['level']:'premium';
            $customName=trim($_POST['custom_name']??'');
            if($tgId<=0) redirectAdmin('give','Нужен TG ID');
            $duration=$hours===0?0:$hours*3600;
            if($customName!==''){
                if(isset($db['keys'][$customName])) redirectAdmin('give','Имя занято');
                $db['keys'][$customName]=makeKeyData($duration,$max,$level,$tgId,$customName,true); $newKey=$customName;
            } else {
                $newKey=strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                $db['keys'][$newKey]=makeKeyData($duration,$max,$level,$tgId);
            }
            saveDb();
            @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$tgId&text=".urlencode("LORI\nКлюч:\n$newKey"));
            redirectAdmin('give',"Выдан: $newKey");
        }

        if ($k && isset($db['keys'][$k])) {
            if ($act==='freeze_key') { $db['keys'][$k]['is_frozen']=empty($db['keys'][$k]['is_frozen']); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit; }
            if ($act==='reset_hwid') { $db['keys'][$k]['activations']=[]; saveDb(); addLog("HWID reset $k"); header('Location:?admin&view='.urlencode($k).'&msg=HWID'); exit; }
            if ($act==='delete_key') { unset($db['keys'][$k]); saveDb(); redirectAdmin('keys','Удалён'); }
            if ($act==='add_warn') { $db['keys'][$k]['warns']=min(3,($db['keys'][$k]['warns']??0)+1); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Warn'); exit; }
            if ($act==='reset_warns') { $db['keys'][$k]['warns']=0; saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit; }
            if ($act==='toggle_aura') {
                $db['keys'][$k]['aura']=empty($db['keys'][$k]['aura']);
                if (!empty($db['keys'][$k]['aura'])) {
                    $db['keys'][$k]['reset_left']=max($db['keys'][$k]['reset_left']??0,(int)($db['settings']['aura_hwid_resets']??6));
                }
                saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=AURA'); exit;
            }
            if ($act==='toggle_pulse') { $db['keys'][$k]['pulse']=empty($db['keys'][$k]['pulse']); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Pulse'); exit; }
            if ($act==='toggle_silent') { $db['keys'][$k]['silent']=empty($db['keys'][$k]['silent']); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Silent'); exit; }
            if ($act==='extend_key') {
                $days=max(1,(int)($_POST['days']??7));
                if(($db['keys'][$k]['expires']??0)==0) $db['keys'][$k]['expires']=time()+$days*86400;
                else $db['keys'][$k]['expires']+=$days*86400;
                saveDb(); addExtendLog("Ключ $k +$days д",1,$days);
                header('Location:?admin&view='.urlencode($k).'&msg=+'.$days); exit;
            }
            if ($act==='set_nick') { $db['keys'][$k]['owner_name']=trim($_POST['nick']??''); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Nick'); exit; }
            if ($act==='set_note') { $db['keys'][$k]['note']=trim($_POST['note']??''); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Note'); exit; }
            if ($act==='set_max') { $db['keys'][$k]['max']=max(1,(int)($_POST['max']??1)); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Max'); exit; }
            if ($act==='transfer_key') { $t=(int)($_POST['new_tg']??0); if($t>0){$db['keys'][$k]['owner_tg']=$t;saveDb();} header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit; }
            if ($act==='clear_activations') { $db['keys'][$k]['activations']=[]; saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Clear'); exit; }
            if ($act==='make_lifetime') { $db['keys'][$k]['duration']=0; $db['keys'][$k]['expires']=0; saveDb(); addExtendLog("Ключ $k навсегда",1,0); header('Location:?admin&view='.urlencode($k).'&msg=Life'); exit; }
            if ($act==='clone_key') {
                $old=$db['keys'][$k];
                $newKey=(!empty($old['named'])?$k.'_copy':strtoupper($old['level']??'premium').'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8)));
                if(isset($db['keys'][$newKey])) $newKey.='_'.substr(md5(time()),0,4);
                $db['keys'][$newKey]=$old; $db['keys'][$newKey]['activations']=[]; $db['keys'][$newKey]['first_use']=0; $db['keys'][$newKey]['expires']=0; $db['keys'][$newKey]['created']=time();
                saveDb(); header('Location:?admin&view='.urlencode($newKey).'&msg=Clone'); exit;
            }
            if ($act==='regen_key') {
                if(!empty($db['keys'][$k]['named'])) { header('Location:?admin&view='.urlencode($k).'&msg=Named'); exit; }
                $old=$db['keys'][$k]; $level=$old['level']??'premium';
                $newKey=strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
                $db['keys'][$newKey]=$old; $db['keys'][$newKey]['activations']=[]; unset($db['keys'][$k]);
                saveDb(); header('Location:?admin&view='.urlencode($newKey).'&msg=Regen'); exit;
            }
            if ($act==='set_owner_tg') { $db['keys'][$k]['owner_tg']=(int)($_POST['owner_tg']??0); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=TG'); exit; }
            if ($act==='refill_resets') {
                $n=!empty($db['keys'][$k]['aura'])?(int)($db['settings']['aura_hwid_resets']??6):(int)($db['settings']['user_hwid_resets']??2);
                $db['keys'][$k]['reset_left']=$n; saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Resets'); exit;
            }
            if ($act==='set_tag') { $db['keys'][$k]['tag']=trim($_POST['tag']??''); saveDb(); header('Location:?admin&view='.urlencode($k).'&msg=Tag'); exit; }
        }

        if ($act==='mass_extend_all') {
            $days=max(1,(int)($_POST['mass_days']??30)); $n=0;
            foreach($db['keys'] as &$kd){
                if(($kd['expires']??0)==0) $kd['expires']=time()+$days*86400;
                else $kd['expires']+=$days*86400;
                $n++;
            }
            unset($kd); saveDb(); addExtendLog("Всем +$days д",$n,$days);
            redirectAdmin('tools',"Продлено $n на +$days д");
        }
        if ($act==='mass_lifetime_all') {
            $n=0; foreach($db['keys'] as &$kd){ $kd['duration']=0; $kd['expires']=0; $n++; } unset($kd);
            saveDb(); addExtendLog("Все навсегда",$n,0); redirectAdmin('tools',"Навсегда: $n");
        }
        if ($act==='bulk_freeze') { foreach($db['keys'] as &$kd)$kd['is_frozen']=true; unset($kd); saveDb(); redirectAdmin('tools','Freeze all'); }
        if ($act==='bulk_unfreeze') { foreach($db['keys'] as &$kd)$kd['is_frozen']=false; unset($kd); saveDb(); redirectAdmin('tools','Unfreeze'); }
        if ($act==='mass_aura') { $n=0; foreach($db['keys'] as &$kd){ if(empty($kd['aura'])){$kd['aura']=true;$kd['reset_left']=max($kd['reset_left']??0,(int)$db['settings']['aura_hwid_resets']);$n++;}} unset($kd); saveDb(); redirectAdmin('tools',"AURA: $n"); }
        if ($act==='mass_unaura') { foreach($db['keys'] as &$kd)$kd['aura']=false; unset($kd); saveDb(); redirectAdmin('tools','AURA off'); }
        if ($act==='toggle_global_freeze') { $db['settings']['global_freeze']=empty($db['settings']['global_freeze']); saveDb(); redirectAdmin('dashboard','OK'); }
        if ($act==='set_status') { $db['settings']['status']=$_POST['status']??'online'; saveDb(); redirectAdmin('dashboard','Status'); }
        if ($act==='set_soft_status') { $db['settings']['soft_status']=$_POST['soft_status']??'undetected'; saveDb(); redirectAdmin('dashboard','Soft'); }
        if ($act==='set_broadcast') { $db['settings']['broadcast']=trim($_POST['broadcast']??''); saveDb(); redirectAdmin('broadcast','OK'); }
        if ($act==='add_blacklist') {
            $val=trim($_POST['value']??'');
            if($val!==''){ $db['blacklist'][$val]=['time'=>time(),'reason'=>trim($_POST['reason']??'')]; saveDb(); }
            redirectAdmin('blacklist','OK');
        }
        if ($act==='remove_blacklist') { unset($db['blacklist'][$_POST['value']??'']); saveDb(); redirectAdmin('blacklist','OK'); }
        if ($act==='save_settings') {
            foreach(['version','checksum','download_url','emergency_msg','bot_welcome'] as $f) if(isset($_POST[$f])) $db['settings'][$f]=trim($_POST[$f]);
            $db['settings']['purchases_enabled']=!empty($_POST['purchases_enabled']);
            $db['settings']['github_sync']=!empty($_POST['github_sync']);
            $db['settings']['user_hwid_resets']=max(0,(int)($_POST['user_hwid_resets']??2));
            $db['settings']['user_freeze_per_week']=max(0,(int)($_POST['user_freeze_per_week']??2));
            $db['settings']['aura_hwid_resets']=max(0,(int)($_POST['aura_hwid_resets']??6));
            $db['settings']['aura_freeze_per_week']=max(0,(int)($_POST['aura_freeze_per_week']??6));
            $db['settings']['aura_extra_devices']=max(0,(int)($_POST['aura_extra_devices']??2));
            saveDb(); redirectAdmin('settings','Сохранено');
        }
        if ($act==='set_panel_bg') {
            $db['settings']['panel_bg']=trim($_POST['panel_bg']??'');
            $c=trim($_POST['panel_bg_color']??'#030303'); if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$c))$c='#030303';
            $db['settings']['panel_bg_color']=$c;
            $a=trim($_POST['panel_accent']??'#22c55e'); if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$a))$a='#22c55e';
            $db['settings']['panel_accent']=$a;
            $db['settings']['panel_overlay']=max(0.3,min(0.95,(float)($_POST['panel_overlay']??0.75)));
            $db['settings']['panel_blur']=max(0,min(40,(int)($_POST['panel_blur']??12)));
            saveDb(); redirectAdmin('theme','Тема');
        }
        if ($act==='github_force_push') {
            $json=json_encode($db, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
            file_put_contents($dbFile,$json);
            $g=githubGet($githubRepo,$githubPath,$githubBranch,$githubToken);
            $sha=$g['sha']??'';
            $ok=githubPut($githubRepo,$githubPath,$githubBranch,$githubToken,$json,$sha);
            redirectAdmin('github', $ok?'GitHub: сохранено':'GitHub: ошибка (проверь TOKEN/REPO)');
        }
        if ($act==='github_force_pull') {
            $g=githubGet($githubRepo,$githubPath,$githubBranch,$githubToken);
            if($g && is_array($g['data'])){
                $db=$g['data'];
                foreach(['keys','blacklist','logs','online'] as $kk) if(!isset($db[$kk])) $db[$kk]=[];
                file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
                $githubSha=$g['sha']??'';
                redirectAdmin('github','GitHub: загружено, ключей '.count($db['keys']??[]));
            }
            redirectAdmin('github','GitHub: не удалось загрузить');
        }
        if ($act==='clear_logs') { $db['logs']=[]; saveDb(); redirectAdmin('logs','OK'); }
        if ($act==='clear_access_log') { $db['access_log']=[]; saveDb(); redirectAdmin('access','OK'); }
        if ($act==='clear_online') { $db['online']=[]; saveDb(); redirectAdmin('online','OK'); }
        if ($act==='delete_expired') {
            $n=0; foreach($db['keys'] as $kk=>$kd){ if(($kd['expires']??0)>0&&time()>$kd['expires']){unset($db['keys'][$kk]);$n++;}}
            saveDb(); redirectAdmin('tools',"Удалено $n");
        }
        if ($act==='notify_owners') {
            $text=trim($_POST['notify_text']??''); $sent=0; $ids=[];
            foreach($db['keys'] as $kd){ $tg=(int)($kd['owner_tg']??0); if($tg>0&&!isset($ids[$tg])){ $ids[$tg]=1; @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$tg&text=".urlencode($text)); $sent++; }}
            redirectAdmin('tools',"Отправлено $sent");
        }
        if ($act==='import_keys') {
            $raw=trim($_POST['import_text']??''); $hours=(int)($_POST['import_hours']??24); $duration=$hours===0?0:$hours*3600; $n=0;
            foreach(preg_split('/\r\n|\r|\n/',$raw) as $line){ $line=trim($line); if($line===''||isset($db['keys'][$line]))continue; $db['keys'][$line]=makeKeyData($duration,1,'premium',0,$line,true); $n++; }
            saveDb(); redirectAdmin('tools',"Импорт $n");
        }
        if ($act==='gen_prefix') {
            $prefix=preg_replace('/[^A-Za-z0-9_\-]/','',trim($_POST['prefix']??'LORI'))?:'LORI';
            $hours=(int)($_POST['prefix_hours']??24); $count=max(1,min(20,(int)($_POST['prefix_count']??1)));
            $duration=$hours===0?0:$hours*3600; $list=[];
            for($i=0;$i<$count;$i++){ $newKey=strtoupper($prefix).'-'.strtoupper(substr(md5(uniqid(mt_rand().$i,true)),0,8)); $db['keys'][$newKey]=makeKeyData($duration,1,'premium'); $list[]=$newKey; }
            saveDb(); redirectAdmin('tools','OK: '.implode(', ',$list));
        }
        if ($act==='random_aura') {
            $keys=array_keys($db['keys']); if($keys){ $rk=$keys[array_rand($keys)]; $db['keys'][$rk]['aura']=true; saveDb(); redirectAdmin('tools',"AURA → $rk"); }
            redirectAdmin('tools','Нет ключей');
        }
        if ($act==='strip_all_devices') {
            foreach($db['keys'] as &$kd) $kd['activations']=[]; unset($kd); saveDb(); redirectAdmin('tools','Все HWID сброшены');
        }
        if ($act==='reset_all_warns') {
            foreach($db['keys'] as &$kd) $kd['warns']=0; unset($kd); saveDb(); redirectAdmin('tools','Варны сброшены');
        }
        if ($act==='touch_all_expires') {
            $days=max(1,(int)($_POST['touch_days']??1)); $n=0; $now=time();
            foreach($db['keys'] as &$kd){
                if(($kd['expires']??0)>0 && $kd['expires']<$now){ $kd['expires']=$now+$days*86400; $n++; }
            }
            unset($kd); saveDb(); addExtendLog("Воскрешение истёкших +$days д",$n,$days);
            redirectAdmin('tools',"Воскрешено $n");
        }
    }

    $totalKeys=count($db['keys']); $onlineCount=count($db['online']);
    $active=$frozen=$expired=$auraCount=$namedCount=0;
    foreach($db['keys'] as $kd){
        if(!empty($kd['is_frozen']))$frozen++;
        elseif(($kd['expires']??0)==0||time()<($kd['expires']??0))$active++;
        else $expired++;
        if(!empty($kd['aura']))$auraCount++;
        if(!empty($kd['named']))$namedCount++;
    }
    $githubOk = ($githubToken && $githubRepo);

    $accent=$db['settings']['panel_accent']??'#22c55e';
    $panelBg=$db['settings']['panel_bg']??'';
    $panelBgColor=$db['settings']['panel_bg_color']??'#030303';
    $overlay=$db['settings']['panel_overlay']??'0.75';
    $blur=(int)($db['settings']['panel_blur']??12);
    $rgb=sscanf($accent,"#%02x%02x%02x")?:[34,197,94];

    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LORI</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
:root{--bg:<?= htmlspecialchars($panelBgColor) ?>;--card:#0a0a0c;--border:#161618;--accent:<?= htmlspecialchars($accent) ?>;--accent-rgb:<?= implode(',',$rgb) ?>;--text:#e8e8e8;--muted:#5a5a5a}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:13px}
<?php if($panelBg): ?>
body::before{content:'';position:fixed;inset:0;z-index:-2;background:url('<?= htmlspecialchars($panelBg) ?>') center/cover fixed;filter:blur(<?= $blur ?>px);transform:scale(1.05)}
body::after{content:'';position:fixed;inset:0;z-index:-1;background:rgba(3,3,3,<?= $overlay ?>)}
<?php else: ?>
body::before{content:'';position:fixed;inset:0;z-index:-1;background:radial-gradient(ellipse at 20% 15%,rgba(var(--accent-rgb),.07),transparent 50%)}
<?php endif; ?>
.header{background:rgba(8,8,10,.92);padding:12px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100;backdrop-filter:blur(16px)}
.header h1{font-size:12px;color:var(--accent);letter-spacing:6px;font-weight:600;display:flex;align-items:center;gap:8px}
.header a{color:var(--muted);text-decoration:none;margin-left:14px;font-size:12px}
.layout{display:flex;max-width:1280px;margin:0 auto}
.sidebar{width:180px;padding:14px 8px;border-right:1px solid var(--border);min-height:calc(100vh - 45px)}
.sidebar a{display:flex;align-items:center;gap:8px;padding:9px 12px;border-radius:8px;color:var(--muted);text-decoration:none;margin-bottom:2px;font-size:12px}
.sidebar a svg{opacity:.7;flex-shrink:0}
.sidebar a:hover,.sidebar a.active{background:rgba(var(--accent-rgb),.1);color:var(--accent)}
.sidebar a:hover svg,.sidebar a.active svg{opacity:1}
.content{flex:1;padding:18px 16px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(95px,1fr));gap:10px;margin-bottom:18px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center}
.stat .num{font-size:20px;font-weight:700;color:var(--accent)}
.stat .label{font-size:10px;color:var(--muted);margin-top:3px;text-transform:uppercase}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:14px}
.card h2{font-size:12px;color:var(--accent);margin-bottom:14px;font-weight:600;display:flex;align-items:center;gap:8px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;border:none;font-weight:500;cursor:pointer;font-size:12px;text-decoration:none}
.btn-accent{background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 70%,#000));color:#000}
.btn-dark{background:#121214;border:1px solid var(--border);color:var(--text)}
.btn-red{background:#7f1d1d;color:#fff}
.btn-sm{padding:5px 10px;font-size:11px}
.form-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;align-items:center}
input,select,textarea{padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:#0c0c0e;color:#fff;font-size:12px;outline:none}
textarea{width:100%;min-height:55px}
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{padding:8px 6px;text-align:left;border-bottom:1px solid #121214}
th{color:var(--accent);font-size:11px}
.msg{background:rgba(var(--accent-rgb),.08);border:1px solid rgba(var(--accent-rgb),.25);padding:12px 14px;border-radius:10px;margin-bottom:14px;color:#86efac;font-size:12px}
.tool-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:12px}
.tool-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px}
.tool-card h3{font-size:12px;color:var(--accent);margin-bottom:10px;display:flex;align-items:center;gap:8px}
.keys-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:12px}
.key-mini{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px;text-decoration:none;color:inherit;display:block;transition:.2s}
.key-mini:hover{border-color:rgba(var(--accent-rgb),.4);transform:translateY(-2px)}
.key-mini.aura{box-shadow:0 0 24px rgba(var(--accent-rgb),.15);border-color:rgba(var(--accent-rgb),.35)}
.key-mini .km-top{display:flex;gap:10px;align-items:center;margin-bottom:10px}
.key-mini .km-circle{width:40px;height:40px;border-radius:50%;background:conic-gradient(from -90deg,var(--accent) var(--p),#1a1a1a 0);display:flex;align-items:center;justify-content:center}
.key-mini .km-inner{width:30px;height:30px;border-radius:50%;background:var(--card);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600}
.key-mini .km-name{font-size:13px;font-weight:600;word-break:break-all}
.key-mini .km-meta{font-size:11px;color:var(--muted)}
.key-mini .km-row{display:flex;justify-content:space-between;font-size:11px;padding:3px 0;color:#888}
.key-mini .km-row span:last-child{color:#ccc}
.kp{max-width:400px;margin:0 auto 20px;background:#0a0a0c;border:1px solid #1a1a1c;border-radius:18px;overflow:hidden;box-shadow:0 0 40px rgba(var(--accent-rgb),.08)}
.kp.aura{box-shadow:0 0 50px rgba(var(--accent-rgb),.2);border-color:rgba(var(--accent-rgb),.35)}
.kp-head{padding:16px;display:flex;gap:12px;border-bottom:1px solid #151517}
.kp-avatar{width:36px;height:36px;border-radius:50%;background:var(--accent);color:#000;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0}
.kp-circle{width:52px;height:52px;border-radius:50%;background:conic-gradient(from -90deg,var(--accent) var(--p),#1a1a1a 0);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.kp-circle-in{width:40px;height:40px;border-radius:50%;background:#0a0a0c;display:flex;flex-direction:column;align-items:center;justify-content:center}
.kp-circle-in .n{font-size:15px;font-weight:700;line-height:1}
.kp-circle-in .l{font-size:8px;color:#666;text-transform:uppercase}
.kp-title-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.kp-title{font-size:15px;font-weight:600}
.kp-badge{font-size:10px;padding:2px 7px;border-radius:4px;background:rgba(var(--accent-rgb),.12);color:var(--accent);border:1px solid rgba(var(--accent-rgb),.2)}
.kp-badge.aura{background:var(--accent);color:#000;border:none;font-weight:600}
.kp-sub{margin-top:4px;font-size:12px;color:#666;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.kp-sub svg{vertical-align:middle;opacity:.7}
.kp-sub .ok{color:#fbbf24}
.kp-section{padding:12px 16px;border-bottom:1px solid #151517}
.kp-row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px}
.kp-row .lbl{color:#666}
.kp-row .val{color:#ddd;font-weight:500}
.kp-row .val.g{color:#4ade80}
.kp-row .val.r{color:#f87171}
.kp-btns{padding:12px;display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.kp-btn{background:#111113;border:1px solid #1c1c1e;border-radius:12px;padding:12px 6px;color:#9a9a9a;font-size:10px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:6px}
.kp-btn:hover{background:rgba(var(--accent-rgb),.08);border-color:rgba(var(--accent-rgb),.35);color:var(--accent)}
.kp-extra{padding:0 12px 14px}
.log-item{font-size:12px;padding:8px 0;border-bottom:1px solid #121214;display:flex;gap:10px}
.log-item .t{color:var(--accent);white-space:nowrap;font-size:11px}
.aura-box{background:rgba(var(--accent-rgb),.06);border:1px solid rgba(var(--accent-rgb),.2);border-radius:12px;padding:14px;margin-bottom:14px;font-size:12px;line-height:1.7;color:#aaa}
.aura-box b{color:var(--accent)}
@media(max-width:800px){.layout{flex-direction:column}.sidebar{width:100%;display:flex;overflow-x:auto;border-right:none;border-bottom:1px solid var(--border)}.sidebar a{white-space:nowrap}}
</style>
</head>
<body>
<div class="header">
  <h1><?= ico('zap',16) ?> LORI</h1>
  <div>
    <span style="font-size:11px;color:<?= $githubOk?'#4ade80':'#666' ?>;margin-right:12px"><?= ico('github',14) ?> <?= $githubOk?'GitHub ON':'GitHub OFF' ?></span>
    <a href="?admin&tab=<?= urlencode($tab) ?>">Refresh</a>
    <a href="?admin&logout=1">Exit</a>
  </div>
</div>
<div class="layout">
<div class="sidebar">
<a href="?admin&tab=dashboard" class="<?= $tab==='dashboard'?'active':'' ?>"><?= ico('home',15) ?> Dashboard</a>
<a href="?admin&tab=keys" class="<?= $tab==='keys'?'active':'' ?>"><?= ico('key',15) ?> Ключи</a>
<a href="?admin&tab=generate" class="<?= $tab==='generate'?'active':'' ?>"><?= ico('plus',15) ?> Создать</a>
<a href="?admin&tab=bulk" class="<?= $tab==='bulk'?'active':'' ?>"><?= ico('list',15) ?> Массово</a>
<a href="?admin&tab=give" class="<?= $tab==='give'?'active':'' ?>"><?= ico('plane',15) ?> Выдать</a>
<a href="?admin&tab=online" class="<?= $tab==='online'?'active':'' ?>"><?= ico('globe',15) ?> Онлайн</a>
<a href="?admin&tab=tools" class="<?= $tab==='tools'?'active':'' ?>"><?= ico('zap',15) ?> Инструменты</a>
<a href="?admin&tab=access" class="<?= $tab==='access'?'active':'' ?>"><?= ico('eye',15) ?> Логи входов</a>
<a href="?admin&tab=github" class="<?= $tab==='github'?'active':'' ?>"><?= ico('github',15) ?> GitHub</a>
<a href="?admin&tab=broadcast" class="<?= $tab==='broadcast'?'active':'' ?>"><?= ico('bell',15) ?> Broadcast</a>
<a href="?admin&tab=blacklist" class="<?= $tab==='blacklist'?'active':'' ?>"><?= ico('ban',15) ?> ЧС</a>
<a href="?admin&tab=settings" class="<?= $tab==='settings'?'active':'' ?>"><?= ico('settings',15) ?> Настройки</a>
<a href="?admin&tab=logs" class="<?= $tab==='logs'?'active':'' ?>"><?= ico('list',15) ?> Логи</a>
<a href="?admin&tab=theme" class="<?= $tab==='theme'?'active':'' ?>"><?= ico('spark',15) ?> Тема</a>
<a href="?admin&tab=aurainfo" class="<?= $tab==='aurainfo'?'active':'' ?>"><?= ico('pulse',15) ?> AURA</a>
</div>
<div class="content">
<?php if($msg):?><div class="msg"><?= $msg ?></div><?php endif; ?>

<?php if ($viewKey && isset($db['keys'][$viewKey])):
  $kd=$db['keys'][$viewKey]; $used=count($kd['activations']??[]); $max=(int)($kd['max']??1);
  if(!empty($kd['aura'])) $max += (int)($db['settings']['aura_extra_devices']??2);
  $warns=$kd['warns']??0; $ownerName=$kd['owner_name']?:'—'; $tgId=$kd['owner_tg']?:0;
  $isAura=!empty($kd['aura']); $isNamed=!empty($kd['named']); $isFrozen=!empty($kd['is_frozen']);
  $android=$kd['android_id']?:'не привязан'; $resetLeft=$kd['reset_left']??0;
  $w=weekId(); $freezeUsed=$kd['freeze_week'][$w]??0;
  $freezeLimit=$isAura?(int)$db['settings']['aura_freeze_per_week']:(int)$db['settings']['user_freeze_per_week'];
  $now=time(); $daysLeft='∞'; $expiresStr='навсегда'; $expClass='g'; $circleP=100;
  if(($kd['expires']??0)>0){$left=$kd['expires']-$now;if($left<=0){$daysLeft='0';$expiresStr='истёк';$expClass='r';$circleP=0;}else{$daysLeft=(string)ceil($left/86400);$expiresStr=date('d-m-Y H:i',$kd['expires']);$circleP=min(100,max(5,($left/(30*86400))*100));}}
  elseif(($kd['duration']??0)>0&&($kd['first_use']??0)==0){$daysLeft=(string)ceil($kd['duration']/86400);$expiresStr='после активации';}
  if($isFrozen){$status='заморожен';$sc='#60a5fa';}
  elseif(($kd['first_use']??0)==0){$status='свободен';$sc='#fbbf24';}
  elseif(($kd['expires']??0)>0&&$now>$kd['expires']){$status='истёк';$sc='#f87171';}
  else{$status='активен';$sc='#4ade80';}
  $letter=mb_strtoupper(mb_substr($viewKey,0,1));
?>
<div class="kp<?= $isAura?' aura':'' ?>">
<div class="kp-head">
  <div class="kp-circle" style="--p:<?= $circleP ?>%"><div class="kp-circle-in"><div class="n"><?= $daysLeft ?></div><div class="l">дней</div></div></div>
  <div style="flex:1;min-width:0">
    <div class="kp-title-row">
      <div class="kp-avatar"><?= htmlspecialchars($letter) ?></div>
      <div class="kp-title"><?= htmlspecialchars($viewKey) ?></div>
      <span class="kp-badge"><?= $isNamed?'именной':'обычный' ?></span>
      <?php if($isAura):?><span class="kp-badge aura">AURA</span><?php endif; ?>
      <?php if(!empty($kd['pulse'])):?><span class="kp-badge">PULSE</span><?php endif; ?>
    </div>
    <div class="kp-sub">
      <span><?= ico('plane',14) ?> <?= $tgId?:'—' ?></span>
      <span class="ok"><?= ico('link',14) ?> <?= $status ?></span>
      <span><?= ico('key',14) ?> вход <?= $used ?></span>
    </div>
  </div>
</div>
<div class="kp-section">
  <div class="kp-row"><span class="lbl">Действует до</span><span class="val <?= $expClass ?>"><?= $expiresStr ?></span></div>
  <div class="kp-row"><span class="lbl">Владелец</span><span class="val"><?= htmlspecialchars($ownerName) ?></span></div>
  <div class="kp-row"><span class="lbl">Telegram ID</span><span class="val"><?= $tgId?:'—' ?></span></div>
  <div class="kp-row"><span class="lbl">Android ID</span><span class="val"><?= htmlspecialchars($android) ?></span></div>
  <div class="kp-row"><span class="lbl">Входов</span><span class="val"><?= $used ?> / <?= $max ?></span></div>
  <div class="kp-row"><span class="lbl">Предупреждения</span><span class="val <?= $warns>0?'r':'' ?>"><?= $warns ?> / 3</span></div>
  <div class="kp-row"><span class="lbl">Сбросы HWID</span><span class="val"><?= $resetLeft ?></span></div>
  <div class="kp-row"><span class="lbl">Заморозки / нед</span><span class="val"><?= $freezeUsed ?> / <?= $freezeLimit ?></span></div>
</div>
<div class="kp-btns">
  <button class="kp-btn" type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($viewKey) ?>')"><?= ico('copy') ?><span>Копировать</span></button>
  <form method="post" style="display:contents"><input type="hidden" name="action" value="set_nick"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <button class="kp-btn" type="button" onclick="let n=prompt('Ник:','<?= htmlspecialchars($kd['owner_name']??'') ?>');if(n!==null){this.form.nick.value=n;this.form.submit()}"><?= ico('user') ?><span>Ник</span><input type="hidden" name="nick"></button>
  </form>
  <form method="post" style="display:contents"><input type="hidden" name="action" value="add_warn"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <button class="kp-btn" type="submit"><?= ico('warn') ?><span>Варн <?= $warns ?>/3</span></button></form>
  <form method="post" style="display:contents"><input type="hidden" name="action" value="reset_warns"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <button class="kp-btn" type="submit"><?= ico('reset') ?><span>Сброс варнов</span></button></form>
  <form method="post" style="display:contents"><input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <button class="kp-btn" type="submit"><?= ico('link') ?><span>Сброс HWID</span></button></form>
  <form method="post" style="display:contents"><input type="hidden" name="action" value="regen_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <button class="kp-btn" type="submit" onclick="return confirm('Перегенерировать?')"><?= ico('refresh') ?><span>Перегенер.</span></button></form>
  <form method="post" style="display:contents"><input type="hidden" name="action" value="freeze_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <button class="kp-btn" type="submit"><?= ico('lock') ?><span><?= $isFrozen?'Разблок':'Блок' ?></span></button></form>
  <a class="kp-btn" href="?admin&tab=access&filter=<?= urlencode($viewKey) ?>"><?= ico('eye') ?><span>Логи</span></a>
  <form method="post" style="display:contents"><input type="hidden" name="action" value="toggle_aura"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <button class="kp-btn" type="submit"><?= ico('pulse') ?><span><?= $isAura?'AURA ✓':'AURA' ?></span></button></form>
</div>
<div class="kp-extra">
  <form method="post" class="form-row"><input type="hidden" name="action" value="extend_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="hidden" name="days" value="7">
    <button class="btn btn-dark btn-sm" type="submit"><?= ico('plus',14) ?> +7 дней</button></form>
  <form method="post" class="form-row"><input type="hidden" name="action" value="make_lifetime"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <button class="btn btn-dark btn-sm" type="submit"><?= ico('clock',14) ?> Навсегда</button></form>
  <form method="post" class="form-row"><input type="hidden" name="action" value="refill_resets"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <button class="btn btn-dark btn-sm" type="submit"><?= ico('refresh',14) ?> Пополнить сбросы</button></form>
  <form method="post" class="form-row"><input type="hidden" name="action" value="toggle_pulse"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <button class="btn btn-dark btn-sm" type="submit"><?= ico('spark',14) ?> Pulse</button></form>
  <form method="post" class="form-row"><input type="hidden" name="action" value="transfer_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <input type="number" name="new_tg" placeholder="Передать TG ID" style="flex:1"><button class="btn btn-dark btn-sm" type="submit">OK</button></form>
  <form method="post" onsubmit="return confirm('Удалить?')" class="form-row"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
    <button class="btn btn-red btn-sm" type="submit"><?= ico('trash',14) ?> Удалить</button></form>
</div>
</div>
<div style="text-align:center;margin-bottom:14px"><a href="?admin&tab=keys" class="btn btn-dark"><?= ico('list',14) ?> К списку</a></div>

<?php elseif ($tab==='aurainfo'): ?>
<div class="aura-box">
<b><?= ico('pulse',16) ?> Что такое AURA</b><br>
Вместо VIP — статус <b>AURA</b> на ключе.<br>
• Сбросы HWID: <b><?= (int)$db['settings']['aura_hwid_resets'] ?></b> (обычно <?= (int)$db['settings']['user_hwid_resets'] ?>)<br>
• Заморозки в неделю: <b><?= (int)$db['settings']['aura_freeze_per_week'] ?></b><br>
• Доп. устройства: <b>+<?= (int)$db['settings']['aura_extra_devices'] ?></b><br>
• Свечение карточки в панели<br>
• Метка AURA в боте
</div>

<?php elseif ($tab==='github'): ?>
<div class="card"><h2><?= ico('github') ?> GitHub sync</h2>
<p style="color:#aaa;font-size:12px;line-height:1.7;margin-bottom:12px">
Ключи пишутся в <code>database.json</code> и пушатся в репозиторий.<br>
Статус: <b style="color:<?= $githubOk?'#4ade80':'#f87171' ?>"><?= $githubOk?'настроен':'не настроен' ?></b><br>
Repo: <code><?= htmlspecialchars($githubRepo?:'—') ?></code> · Path: <code><?= htmlspecialchars($githubPath) ?></code>
</p>
<form method="post" class="form-row"><input type="hidden" name="action" value="github_force_push">
<button class="btn btn-accent" type="submit"><?= ico('upload',14) ?> Force push в GitHub</button></form>
<form method="post" class="form-row"><input type="hidden" name="action" value="github_force_pull">
<button class="btn btn-dark" type="submit"><?= ico('download',14) ?> Force pull из GitHub</button></form>
<p style="color:#666;font-size:11px;margin-top:12px">ENV: GITHUB_TOKEN, GITHUB_REPO, GITHUB_PATH, GITHUB_BRANCH</p>
</div>

<?php elseif ($tab==='dashboard'): ?>
<div class="stats">
<div class="stat"><div class="num"><?= $totalKeys ?></div><div class="label">Ключи</div></div>
<div class="stat"><div class="num"><?= $active ?></div><div class="label">Активные</div></div>
<div class="stat"><div class="num"><?= $onlineCount ?></div><div class="label">Онлайн</div></div>
<div class="stat"><div class="num"><?= $frozen ?></div><div class="label">Freeze</div></div>
<div class="stat"><div class="num"><?= $auraCount ?></div><div class="label">AURA</div></div>
<div class="stat"><div class="num"><?= $namedCount ?></div><div class="label">Именные</div></div>
</div>
<div class="card"><h2><?= ico('shield') ?> Статусы</h2>
<div class="form-row">
<form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="online"><button class="btn btn-accent" type="submit"><?= ico('check',14) ?> Online</button></form>
<form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="maintenance"><button class="btn btn-dark" type="submit"><?= ico('settings',14) ?> Maintenance</button></form>
<form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="killswitch"><button class="btn btn-red" type="submit"><?= ico('x',14) ?> Killswitch</button></form>
<form method="post"><input type="hidden" name="action" value="toggle_global_freeze"><button class="btn btn-dark" type="submit"><?= ico('lock',14) ?> Freeze</button></form>
</div></div>

<?php elseif ($tab==='tools'): ?>
<div class="tool-grid">
<div class="tool-card" style="border-color:rgba(var(--accent-rgb),.3)">
<h3><?= ico('clock') ?> Продление</h3>
<form method="post" class="form-row"><input type="hidden" name="action" value="mass_extend_all"><input type="number" name="mass_days" value="30" style="width:70px"><button class="btn btn-accent" type="submit">+Дней всем</button></form>
<form method="post" class="form-row" onsubmit="return confirm('Все навсегда?')"><input type="hidden" name="action" value="mass_lifetime_all"><button class="btn btn-accent" type="submit">Все навсегда</button></form>
<form method="post" class="form-row"><input type="hidden" name="action" value="touch_all_expires"><input type="number" name="touch_days" value="7" style="width:60px"><button class="btn btn-dark btn-sm" type="submit">Воскресить истёкшие</button></form>
</div>
<div class="tool-card"><h3><?= ico('eye') ?> Лог продлений</h3>
<?php if(empty($db['extend_log'])):?><p style="color:var(--muted);font-size:12px">Пусто</p>
<?php else: foreach(array_slice($db['extend_log'],0,10) as $el): ?>
<div class="log-item"><span class="t"><?= date('d.m H:i',$el['time']) ?></span><span><?= htmlspecialchars($el['text']) ?></span></div>
<?php endforeach; endif; ?>
</div>
<div class="tool-card"><h3><?= ico('pulse') ?> AURA / массовые</h3>
<form method="post" class="form-row"><input type="hidden" name="action" value="mass_aura"><button class="btn btn-dark btn-sm" type="submit">AURA всем</button></form>
<form method="post" class="form-row"><input type="hidden" name="action" value="mass_unaura"><button class="btn btn-dark btn-sm" type="submit">Снять AURA</button></form>
<form method="post" class="form-row"><input type="hidden" name="action" value="random_aura"><button class="btn btn-dark btn-sm" type="submit">Случайный AURA</button></form>
<form method="post" class="form-row"><input type="hidden" name="action" value="bulk_freeze"><button class="btn btn-dark btn-sm" type="submit">Freeze all</button></form>
<form method="post" class="form-row"><input type="hidden" name="action" value="bulk_unfreeze"><button class="btn btn-dark btn-sm" type="submit">Unfreeze all</button></form>
<form method="post" class="form-row"><input type="hidden" name="action" value="strip_all_devices"><button class="btn btn-dark btn-sm" type="submit">Сброс всех HWID</button></form>
<form method="post" class="form-row"><input type="hidden" name="action" value="reset_all_warns"><button class="btn btn-dark btn-sm" type="submit">Сброс всех варнов</button></form>
<form method="post" class="form-row"><input type="hidden" name="action" value="delete_expired"><button class="btn btn-dark btn-sm" type="submit">Удалить истёкшие</button></form>
</div>
<div class="tool-card"><h3><?= ico('plane') ?> Рассылка</h3>
<form method="post"><input type="hidden" name="action" value="notify_owners"><textarea name="notify_text"></textarea>
<button class="btn btn-accent btn-sm" type="submit" style="margin-top:8px">Отправить</button></form>
</div>
<div class="tool-card"><h3><?= ico('upload') ?> Импорт</h3>
<form method="post"><input type="hidden" name="action" value="import_keys"><textarea name="import_text" placeholder="Ключ на строку"></textarea>
<div class="form-row" style="margin-top:8px"><input type="number" name="import_hours" value="24" style="width:70px"><button class="btn btn-accent btn-sm" type="submit">Импорт</button></div></form>
</div>
<div class="tool-card"><h3><?= ico('tag') ?> Префикс</h3>
<form method="post" class="form-row">
<input type="hidden" name="action" value="gen_prefix">
<input type="text" name="prefix" value="LORI" style="width:80px">
<input type="number" name="prefix_count" value="1" min="1" max="20" style="width:50px">
<input type="number" name="prefix_hours" value="24" style="width:60px">
<button class="btn btn-accent btn-sm" type="submit">Создать</button>
</form></div>
<div class="tool-card"><h3><?= ico('download') ?> Экспорт</h3>
<a class="btn btn-dark btn-sm" href="?admin&action=export_keys"><?= ico('list',14) ?> TXT</a>
<a class="btn btn-dark btn-sm" href="?admin&action=export_json"><?= ico('db',14) ?> JSON</a>
</div>
<div class="tool-card"><h3><?= ico('trash') ?> Очистка</h3>
<form method="post" class="form-row"><input type="hidden" name="action" value="clear_logs"><button class="btn btn-dark btn-sm" type="submit">Логи</button></form>
<form method="post" class="form-row"><input type="hidden" name="action" value="clear_access_log"><button class="btn btn-dark btn-sm" type="submit">Логи входов</button></form>
<form method="post" class="form-row"><input type="hidden" name="action" value="clear_online"><button class="btn btn-dark btn-sm" type="submit">Онлайн</button></form>
</div>
</div>

<?php elseif ($tab==='access'): ?>
<div class="card"><h2><?= ico('eye') ?> Логи входов</h2>
<?php
$filter=$_GET['filter']??''; $logs=$db['access_log'];
if($filter) $logs=array_filter($logs,fn($l)=>strpos($l['key']??'',$filter)!==false);
foreach(array_slice($logs,0,100) as $l): ?>
<div class="log-item">
<span class="t"><?= date('d.m H:i:s',$l['time']) ?></span>
<span><?= !empty($l['ok'])?'OK':'FAIL' ?> · <code><?= htmlspecialchars($l['key']??'') ?></code> · <?= htmlspecialchars(substr($l['hwid']??'',0,14)) ?> · <?= htmlspecialchars($l['ip']??'') ?></span>
</div>
<?php endforeach; ?></div>

<?php elseif ($tab==='keys'): ?>
<div class="card"><h2><?= ico('key') ?> Ключи (<?= $totalKeys ?>)</h2>
<input type="text" id="searchKey" placeholder="Поиск..." onkeyup="filterKeys()" style="max-width:260px;margin-bottom:14px;width:100%">
<div class="keys-grid" id="keysGrid">
<?php foreach($db['keys'] as $k=>$kd):
$used=count($kd['activations']??[]); $max=$kd['max']??1; $now=time();
$daysLeft='∞'; $circleP=100;
if(($kd['expires']??0)>0){$left=$kd['expires']-$now;if($left<=0){$daysLeft='0';$circleP=0;}else{$daysLeft=(string)ceil($left/86400);$circleP=min(100,max(5,($left/(30*86400))*100));}}
$st='#4ade80'; if(!empty($kd['is_frozen']))$st='#60a5fa'; elseif(($kd['first_use']??0)==0)$st='#facc15';
$isA=!empty($kd['aura']);
?>
<a href="?admin&view=<?= urlencode($k) ?>" class="key-mini<?= $isA?' aura':'' ?>" data-search="<?= htmlspecialchars(strtolower($k.' '.($kd['owner_name']??'').' '.($kd['owner_tg']??''))) ?>">
<div class="km-top"><div class="km-circle" style="--p:<?= $circleP ?>%"><div class="km-inner"><?= $daysLeft ?></div></div>
<div><div class="km-name"><?= htmlspecialchars($k) ?></div>
<div class="km-meta"><span style="color:<?= $st ?>">●</span> <?= htmlspecialchars($kd['level']??'') ?><?= $isA?' · AURA':'' ?></div></div></div>
<div class="km-row"><span>Владелец</span><span><?= $kd['owner_tg']?:($kd['owner_name']?:'—') ?></span></div>
<div class="km-row"><span>Входов</span><span><?= $used ?>/<?= $max ?></span></div>
</a>
<?php endforeach; ?>
</div></div>

<?php elseif ($tab==='generate'): ?>
<div class="card"><h2><?= ico('plus') ?> Создать</h2>
<form method="post"><input type="hidden" name="action" value="gen_key">
<div class="form-row"><input type="text" name="custom_name" placeholder="Именной ключ" style="width:260px"></div>
<div class="form-row">
<input type="number" name="hours" value="24" style="width:90px">
<input type="number" name="max" value="1" min="1" style="width:55px">
<select name="level"><option value="premium" selected>Premium</option><option value="elite">Elite</option><option value="trial">Trial</option></select>
<button class="btn btn-accent" type="submit"><?= ico('check',14) ?> Создать</button>
</div></form></div>

<?php elseif ($tab==='bulk'): ?>
<div class="card"><h2><?= ico('list') ?> Массово</h2>
<form method="post"><input type="hidden" name="action" value="bulk_generate">
<div class="form-row">
<input type="number" name="count" value="10" min="1" max="50" style="width:55px">
<input type="number" name="hours" value="24" style="width:80px">
<button class="btn btn-accent" type="submit">Generate</button>
</div></form></div>

<?php elseif ($tab==='give'): ?>
<div class="card"><h2><?= ico('plane') ?> Выдать</h2>
<form method="post"><input type="hidden" name="action" value="give_key">
<div class="form-row">
<input type="number" name="tg_id" placeholder="TG ID" required style="width:140px">
<input type="text" name="custom_name" placeholder="Именной" style="width:120px">
<input type="number" name="hours" value="168" style="width:70px">
<button class="btn btn-accent" type="submit">Выдать</button>
</div></form></div>

<?php elseif ($tab==='online'): ?>
<div class="card"><h2><?= ico('globe') ?> Онлайн (<?= $onlineCount ?>)</h2>
<?php if(empty($db['online'])):?><p style="color:var(--muted)">Пусто</p><?php else:?>
<table><tr><th>Ключ</th><th>IP</th><th>HWID</th><th>Пинг</th></tr>
<?php foreach($db['online'] as $hwid=>$info):?><tr>
<td><code><?= htmlspecialchars($info['key']??'') ?></code></td>
<td><?= htmlspecialchars($info['ip']??'') ?></td>
<td style="font-size:10px"><?= htmlspecialchars(substr($hwid,0,14)) ?></td>
<td><?= time()-($info['last_ping']??0) ?>с</td>
</tr><?php endforeach;?></table><?php endif;?></div>

<?php elseif ($tab==='broadcast'): ?>
<div class="card"><h2><?= ico('bell') ?> Broadcast</h2>
<form method="post"><input type="hidden" name="action" value="set_broadcast">
<textarea name="broadcast"><?= htmlspecialchars($db['settings']['broadcast']??'') ?></textarea>
<button class="btn btn-accent" type="submit" style="margin-top:8px">OK</button></form></div>

<?php elseif ($tab==='blacklist'): ?>
<div class="card"><h2><?= ico('ban') ?> ЧС</h2>
<form method="post" class="form-row"><input type="hidden" name="action" value="add_blacklist">
<input type="text" name="value" placeholder="IP/HWID" required style="width:180px">
<input type="text" name="reason" style="width:120px">
<button class="btn btn-red" type="submit">Add</button></form>
<?php foreach($db['blacklist'] as $val=>$info): ?>
<div class="log-item"><span class="t"><?= date('d.m H:i',$info['time']??time()) ?></span>
<span><code><?= htmlspecialchars($val) ?></code>
<form method="post" style="display:inline"><input type="hidden" name="action" value="remove_blacklist"><input type="hidden" name="value" value="<?= htmlspecialchars($val) ?>"><button class="btn btn-dark btn-sm" type="submit">×</button></form>
</span></div>
<?php endforeach; ?></div>

<?php elseif ($tab==='settings'): ?>
<div class="card"><h2><?= ico('settings') ?> Настройки</h2>
<form method="post"><input type="hidden" name="action" value="save_settings">
<div class="form-row"><label style="width:160px">Version</label><input type="text" name="version" value="<?= htmlspecialchars($db['settings']['version']) ?>" style="width:120px"></div>
<div class="form-row"><label style="width:160px">Checksum</label><input type="text" name="checksum" value="<?= htmlspecialchars($db['settings']['checksum']) ?>" style="width:240px"></div>
<div class="form-row"><label style="width:160px">Download</label><input type="text" name="download_url" value="<?= htmlspecialchars($db['settings']['download_url']) ?>" style="width:240px"></div>
<div class="form-row"><label style="width:160px">Bot welcome</label><textarea name="bot_welcome" style="width:240px"><?= htmlspecialchars($db['settings']['bot_welcome']??'') ?></textarea></div>
<div class="form-row"><label style="width:160px">Сбросы HWID</label><input type="number" name="user_hwid_resets" value="<?= (int)$db['settings']['user_hwid_resets'] ?>" style="width:70px"></div>
<div class="form-row"><label style="width:160px">Заморозки/нед</label><input type="number" name="user_freeze_per_week" value="<?= (int)$db['settings']['user_freeze_per_week'] ?>" style="width:70px"></div>
<div class="form-row"><label style="width:160px">AURA сбросы</label><input type="number" name="aura_hwid_resets" value="<?= (int)$db['settings']['aura_hwid_resets'] ?>" style="width:70px"></div>
<div class="form-row"><label style="width:160px">AURA freeze/нед</label><input type="number" name="aura_freeze_per_week" value="<?= (int)$db['settings']['aura_freeze_per_week'] ?>" style="width:70px"></div>
<div class="form-row"><label style="width:160px">AURA +устройства</label><input type="number" name="aura_extra_devices" value="<?= (int)$db['settings']['aura_extra_devices'] ?>" style="width:70px"></div>
<div class="form-row"><label><input type="checkbox" name="purchases_enabled" value="1" <?= !empty($db['settings']['purchases_enabled'])?'checked':'' ?>> Покупки в боте</label></div>
<div class="form-row"><label><input type="checkbox" name="github_sync" value="1" <?= !empty($db['settings']['github_sync'])?'checked':'' ?>> GitHub auto-sync</label></div>
<button class="btn btn-accent" type="submit" style="margin-top:10px">Сохранить</button>
</form></div>

<?php elseif ($tab==='theme'): ?>
<div class="card"><h2><?= ico('spark') ?> Тема</h2>
<form method="post"><input type="hidden" name="action" value="set_panel_bg">
<div class="form-row"><label style="width:120px">URL фона</label><input type="text" name="panel_bg" value="<?= htmlspecialchars($panelBg) ?>" style="flex:1"></div>
<div class="form-row"><label style="width:120px">Цвет</label><input type="color" name="panel_bg_color" value="<?= htmlspecialchars($panelBgColor) ?>"></div>
<div class="form-row"><label style="width:120px">Акцент</label><input type="color" name="panel_accent" value="<?= htmlspecialchars($accent) ?>"></div>
<div class="form-row"><label style="width:120px">Blur</label><input type="range" name="panel_blur" min="0" max="40" value="<?= $blur ?>"></div>
<div class="form-row"><label style="width:120px">Overlay</label><input type="range" name="panel_overlay" min="0.3" max="0.95" step="0.01" value="<?= htmlspecialchars($overlay) ?>"></div>
<button class="btn btn-accent" type="submit">Применить</button>
</form></div>

<?php elseif ($tab==='logs'): ?>
<div class="card"><h2><?= ico('list') ?> Логи</h2>
<?php foreach(array_slice($db['logs'],0,80) as $l): ?>
<div class="log-item"><span class="t"><?= date('d.m H:i:s',$l['time']) ?></span><span><?= htmlspecialchars($l['text']) ?></span></div>
<?php endforeach; ?></div>
<?php endif; ?>

</div></div>
<script>
function filterKeys(){const q=document.getElementById('searchKey').value.toLowerCase();document.querySelectorAll('#keysGrid .key-mini').forEach(el=>{el.style.display=el.dataset.search.includes(q)?'':'none'})}
</script>
</body></html>
<?php
    exit;
}

// BOT
$content = file_get_contents('php://input');
$update = json_decode($content, true);
if (!$update) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>LORI</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#030303;color:#22c55e;font-family:Inter,system-ui;letter-spacing:6px}</style></head><body>LORI</body></html>';
    exit;
}

function tgRequest($method,$data){global $botToken;$opts=['http'=>['header'=>"Content-Type: application/json\r\n",'method'=>'POST','content'=>json_encode($data,JSON_UNESCAPED_UNICODE),'ignore_errors'=>true]];return @file_get_contents("https://api.telegram.org/bot$botToken/$method",false,stream_context_create($opts));}
function sendMessage($chat_id,$text,$kb=null){$d=['chat_id'=>$chat_id,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true];if($kb)$d['reply_markup']=$kb;return tgRequest('sendMessage',$d);}
function editMessage($chat_id,$msg_id,$text,$kb=null){$d=['chat_id'=>$chat_id,'message_id'=>$msg_id,'text'=>$text,'parse_mode'=>'HTML'];if($kb)$d['reply_markup']=$kb;return tgRequest('editMessageText',$d);}
function answerCallback($cq_id,$text='',$alert=false){tgRequest('answerCallbackQuery',['callback_query_id'=>$cq_id,'text'=>$text,'show_alert'=>$alert]);}
function sendInvoice($chat_id,$title,$desc,$payload,$stars){tgRequest('sendInvoice',['chat_id'=>$chat_id,'title'=>$title,'description'=>$desc,'payload'=>$payload,'currency'=>'XTR','prices'=>[['label'=>'Stars','amount'=>$stars]]]);}

if (isset($update['pre_checkout_query'])) { tgRequest('answerPreCheckoutQuery',['pre_checkout_query_id'=>$update['pre_checkout_query']['id'],'ok'=>true]); exit; }
if (isset($update['message']['successful_payment'])) {
    if (empty($db['settings']['purchases_enabled'])) exit;
    $chatId=$update['message']['chat']['id']; $parts=explode('_',$update['message']['successful_payment']['invoice_payload']);
    $hours=(int)($parts[1]??24); $duration=$hours===0?0:$hours*3600;
    $newKey='PREMIUM-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8));
    $db['keys'][$newKey]=makeKeyData($duration,1,'premium',$chatId);
    saveDb(); addLog("Bought $newKey by $chatId");
    sendMessage($chatId,"<b>LORI</b>\nОплата OK.\n\n<code>$newKey</code>\n\nСбросы HWID: 2\nЗаморозки: 2 / неделя");
    exit;
}
if (isset($update['message'])) {
    $chatId=(int)$update['message']['chat']['id']; $text=trim($update['message']['text']??''); $isAdmin=($chatId===$adminId);
    if ($isAdmin && strpos($text,'/gen ')===0) {
        $a=explode(' ',$text); $hours=(int)($a[1]??24); $max=(int)($a[2]??1); $level=$a[3]??'premium'; $name=$a[4]??'';
        $duration=$hours===0?0:$hours*3600;
        if($name){ if(isset($db['keys'][$name])){sendMessage($chatId,'Занято');exit;} $db['keys'][$name]=makeKeyData($duration,$max,$level,0,$name,true); $newKey=$name; }
        else { $newKey=strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,8)); $db['keys'][$newKey]=makeKeyData($duration,$max,$level); }
        saveDb(); sendMessage($chatId,"<code>$newKey</code>"); exit;
    }
    if ($text==='/start' || $text==='/menu') {
        $kb=['inline_keyboard'=>[
            [['text'=>'1ч · 10★','callback_data'=>'buy_1_1']],
            [['text'=>'24ч · 25★','callback_data'=>'buy_24_1']],
            [['text'=>'7д · 75★','callback_data'=>'buy_168_1']],
            [['text'=>'30д · 125★','callback_data'=>'buy_720_1']],
            [['text'=>'Навсегда · 400★','callback_data'=>'buy_0_1']],
            [['text'=>'Мои ключи','callback_data'=>'my_keys'],['text'=>'AURA','callback_data'=>'aura_info']],
            [['text'=>'Лимиты','callback_data'=>'limits_info']]
        ]];
        if($isAdmin) $kb['inline_keyboard'][]=[['text'=>'Админ','callback_data'=>'admin_panel']];
        sendMessage($chatId, $db['settings']['bot_welcome'] ?? "<b>LORI</b>\nВыберите срок:", $kb);
    }
}
if (isset($update['callback_query'])) {
    $cq=$update['callback_query']; $chatId=(int)$cq['message']['chat']['id']; $data=$cq['data']; $msgId=$cq['message']['message_id']; $cqId=$cq['id']; $isAdmin=($chatId===$adminId);
    if (strpos($data,'buy_')===0) {
        if(empty($db['settings']['purchases_enabled'])){answerCallback($cqId,'Выкл',true);exit;}
        $h=(int)explode('_',$data)[1]; $m=[1=>10,24=>25,168=>75,720=>125,0=>400];
        sendInvoice($chatId,'LORI','Access',"sub_{$h}_1",$m[$h]??25); answerCallback($cqId); exit;
    }
    if ($data==='aura_info') {
        answerCallback($cqId);
        sendMessage($chatId,"<b>AURA</b>\n\n• ".((int)$db['settings']['aura_hwid_resets'])." сбросов HWID\n• ".((int)$db['settings']['aura_freeze_per_week'])." заморозок / неделя\n• +".((int)$db['settings']['aura_extra_devices'])." устройства\n• Свечение в панели");
        exit;
    }
    if ($data==='limits_info') {
        answerCallback($cqId);
        sendMessage($chatId,"<b>Лимиты</b>\n\nСброс HWID: <b>".((int)$db['settings']['user_hwid_resets'])."</b> на весь срок\nЗаморозка: <b>".((int)$db['settings']['user_freeze_per_week'])."</b> / неделя");
        exit;
    }
    if ($data==='my_keys') {
        $f=false;
        foreach($db['keys'] as $k=>$kd){
            if(($kd['owner_tg']??0)!=$chatId) continue;
            $f=true; $used=count($kd['activations']??[]); $max=$kd['max']??1;
            if(!empty($kd['aura'])) $max+=(int)$db['settings']['aura_extra_devices'];
            $resetLeft=$kd['reset_left']??0;
            $w=weekId(); $fu=$kd['freeze_week'][$w]??0;
            $fl=!empty($kd['aura'])?(int)$db['settings']['aura_freeze_per_week']:(int)$db['settings']['user_freeze_per_week'];
            $st=!empty($kd['is_frozen'])?'Заморожен':((($kd['expires']??0)==0)?'∞':ceil(max(0,$kd['expires']-time())/86400).'д');
            $aura=!empty($kd['aura'])?' · AURA':'';
            sendMessage($chatId,"<b>LORI</b>\n<code>$k</code>$aura\n$st · $used/$max\nСбросы: $resetLeft · Freeze: $fu/$fl",[
                'inline_keyboard'=>[
                    [['text'=>"Сброс HWID ($resetLeft)",'callback_data'=>'user_reset_'.$k]],
                    [['text'=>!empty($kd['is_frozen'])?"Разморозить ($fu/$fl)":"Заморозить ($fu/$fl)",'callback_data'=>'user_freeze_'.$k]]
                ]
            ]);
        }
        if(!$f) sendMessage($chatId,'Нет ключей');
        answerCallback($cqId); exit;
    }
    if (strpos($data,'user_reset_')===0) {
        $key=str_replace('user_reset_','',$data);
        if(isset($db['keys'][$key])&&($db['keys'][$key]['owner_tg']??0)==$chatId){
            if(($db['keys'][$key]['reset_left']??0)>0){
                $db['keys'][$key]['activations']=[]; $db['keys'][$key]['reset_left']--; saveDb();
                answerCallback($cqId,'OK, осталось '.$db['keys'][$key]['reset_left']);
            } else answerCallback($cqId,'Лимит',true);
        }
        exit;
    }
    if (strpos($data,'user_freeze_')===0) {
        $key=str_replace('user_freeze_','',$data);
        if(isset($db['keys'][$key])&&($db['keys'][$key]['owner_tg']??0)==$chatId){
            $kd=&$db['keys'][$key];
            if(!empty($kd['is_frozen'])){ $kd['is_frozen']=false; saveDb(); answerCallback($cqId,'Разморожен'); }
            else {
                if(!canUserFreeze($kd)){ answerCallback($cqId,'Лимит на неделю',true); exit; }
                $kd['is_frozen']=true; registerUserFreeze($kd); saveDb(); answerCallback($cqId,'Заморожен');
            }
        }
        exit;
    }
    if (!$isAdmin) { answerCallback($cqId,'Нет',true); exit; }
    if ($data==='admin_panel') {
        editMessage($chatId,$msgId,"<b>LORI Admin</b>\nКлючи: ".count($db['keys']),['inline_keyboard'=>[
            [['text'=>'Ключи','callback_data'=>'adm_keys']],
            [['text'=>'Killswitch','callback_data'=>'toggle_kill'],['text'=>'Freeze','callback_data'=>'toggle_gfreeze']]
        ]]); answerCallback($cqId); exit;
    }
    if ($data==='adm_keys'||strpos($data,'adm_keys_')===0) {
        $page=strpos($data,'adm_keys_')===0?(int)str_replace('adm_keys_','',$data):0;
        $keys=array_keys($db['keys']); $per=8; $total=count($keys); $pages=max(1,ceil($total/$per)); $slice=array_slice($keys,$page*$per,$per);
        $kb=['inline_keyboard'=>[]];
        foreach($slice as $k) $kb['inline_keyboard'][]=[['text'=>$k,'callback_data'=>'k_view_'.$k]];
        $nav=[]; if($page>0)$nav[]=['text'=>'‹','callback_data'=>'adm_keys_'.($page-1)];
        $nav[]=['text'=>($page+1)."/$pages",'callback_data'=>'noop'];
        if($page<$pages-1)$nav[]=['text'=>'›','callback_data'=>'adm_keys_'.($page+1)];
        if($nav)$kb['inline_keyboard'][]=$nav;
        $kb['inline_keyboard'][]=[['text'=>'Назад','callback_data'=>'admin_panel']];
        editMessage($chatId,$msgId,"Ключи ($total)",$kb); answerCallback($cqId); exit;
    }
    if (strpos($data,'k_view_')===0) {
        $key=str_replace('k_view_','',$data); if(!isset($db['keys'][$key])){answerCallback($cqId,'?',true);exit;}
        $kd=$db['keys'][$key];
        editMessage($chatId,$msgId,"<code>$key</code>\n".(count($kd['activations']??[])).'/'.($kd['max']??1),['inline_keyboard'=>[
            [['text'=>'Сброс HWID','callback_data'=>'k_rhwid_'.$key],['text'=>'Freeze','callback_data'=>'k_freeze_'.$key]],
            [['text'=>'Назад','callback_data'=>'adm_keys']]
        ]]); answerCallback($cqId); exit;
    }
    if (strpos($data,'k_rhwid_')===0){$key=str_replace('k_rhwid_','',$data);if(isset($db['keys'][$key])){$db['keys'][$key]['activations']=[];saveDb();answerCallback($cqId,'OK');}exit;}
    if (strpos($data,'k_freeze_')===0){$key=str_replace('k_freeze_','',$data);if(isset($db['keys'][$key])){$db['keys'][$key]['is_frozen']=empty($db['keys'][$key]['is_frozen']);saveDb();answerCallback($cqId,'OK');}exit;}
    if ($data==='toggle_kill'){if($db['settings']['status']==='killswitch'){$db['settings']['status']='online';$db['settings']['emergency_msg']='';}else{$db['settings']['status']='killswitch';$db['settings']['emergency_msg']='Stopped';}saveDb();answerCallback($cqId,'OK');exit;}
    if ($data==='toggle_gfreeze'){$db['settings']['global_freeze']=empty($db['settings']['global_freeze']);saveDb();answerCallback($cqId,'OK');exit;}
    if ($data==='noop'){answerCallback($cqId);exit;}
}
