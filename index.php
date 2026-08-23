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
header('Referrer-Policy: strict-origin-when-cross-origin');

// ============================================================
// 2. CONFIG
// ============================================================
$botToken  = getenv('BOT_TOKEN') ?: 'ВАШ_ТОКЕН';
$adminId   = (int)(getenv('ADMIN_ID') ?: 123456789);
$adminPass = 'LoriElite';

$ghToken  = getenv('GITHUB_TOKEN') ?: '';
$ghRepo   = getenv('GITHUB_REPO') ?: '';
$ghPath   = getenv('GITHUB_PATH') ?: 'database.json';
$ghBranch = getenv('GITHUB_BRANCH') ?: 'main';

$dbFile = __DIR__ . '/database.json';
$ghShaCacheFile = __DIR__ . '/.gh_sha_cache';

// ============================================================
// 3. SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('LORI_V8');
    ini_set('session.cookie_lifetime', 86400 * 7);
    ini_set('session.gc_maxlifetime', 86400 * 7);
    session_set_cookie_params([
        'lifetime' => 86400 * 7, 'path' => '/', 'domain' => '',
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax'
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

foreach (['keys','blacklist','whitelist','logs','online','login_log','extend_log','access_log','admin_history','folders','tags','promo_codes','achievements'] as $k) {
    if (!isset($db[$k])) $db[$k] = [];
}
if (!isset($db['settings'])) $db['settings'] = [];
if (!isset($db['stats'])) $db['stats'] = ['purchases'=>0,'stars'=>0,'activations'=>0];

$db['settings'] = array_merge([
    'status' => 'online',
    'soft_status' => 'undetected',
    'global_freeze' => false,
    'version' => '8.0.0',
    'checksum' => 'lori_v8_checksum',
    'download_url' => '',
    'emergency_msg' => '',
    'broadcast' => '',
    'panel_bg' => '',
    'panel_bg_color' => '#0f172a',
    'panel_accent' => '#6366f1',
    'panel_overlay' => '0.75',
    'panel_blur' => '12',
    'bot_welcome' => "👋 <b>LORI v8</b>\nВыберите действие:",
    'purchases_enabled' => true,
    'user_hwid_resets' => 2,
    'user_freeze_per_week' => 2,
    'maintenance_msg' => 'Сервер на обслуживании',
    'github_sync' => true,
    'theme_mode' => 'dark',
    'auto_cleanup' => true,
    'max_login_attempts' => 5,
    'share_detect_limit' => 5,
    'notify_new_activation' => true,
    'grace_period_hours' => 0,
    'warning_days_before' => 3,
    'default_level' => 'premium',
    'default_max_devices' => 1,
    'default_hours' => 24,
    'key_prefix' => 'LORI',
    'show_stats_in_bot' => true,
    'bot_language' => 'ru',
    'allow_self_reset' => true,
    'allow_self_freeze' => true,
    'enable_promo' => true,
    'enable_achievements' => true,
    'enable_folders' => true,
    'enable_tags' => true,
    'enable_qr' => true,
    'enable_export' => true,
    'compact_mode' => false,
    'sounds_enabled' => true,
    'show_online' => true,
    'show_access_log' => true,
    'show_admin_history' => true,
    'enable_whitelist' => false,
    'whitelist_only' => false,
    'auto_ban_on_share' => false,
    'ip_limit_per_key' => 0,
    'country_block' => [],
    'discord_webhook' => '',
    'telegram_notify_admin' => true,
    'maintenance_mode' => false,
    'killswitch' => false,
    'demo_mode' => false
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
    if (count($db['logs']) > 1000) array_pop($db['logs']);
    saveDb();
}

function addAdminHistory($action, $details) {
    global $db, $ip;
    array_unshift($db['admin_history'], ['time'=>time(), 'ip'=>$ip, 'action'=>$action, 'details'=>$details]);
    if (count($db['admin_history']) > 500) array_pop($db['admin_history']);
    saveDb();
}

function addAccessLog($key, $hwid, $ip, $ok) {
    global $db;
    array_unshift($db['access_log'], ['time'=>time(),'key'=>$key,'hwid'=>$hwid,'ip'=>$ip,'ok'=>$ok]);
    if (count($db['access_log']) > 1000) array_pop($db['access_log']);
    saveDb();
}

function addExtendLog($text, $count=0, $days=0) {
    global $db;
    array_unshift($db['extend_log'], ['time'=>time(),'text'=>$text,'count'=>$count,'days'=>$days]);
    if (count($db['extend_log']) > 500) array_pop($db['extend_log']);
    saveDb();
}

function makeKeyData($duration, $max, $level, $owner_tg=0, $owner_name='', $named=false) {
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
        'note'=>'',
        'named'=>$named,
        'tag'=>'',
        'folder'=>'',
        'color'=>'',
        'favorite'=>false,
        'archived'=>false,
        'priority'=>0,
        'launch_limit'=>0,
        'launches_count'=>0,
        'ip_list'=>[],
        'country_list'=>[],
        'time_restrict'=>[],
        'one_time'=>false,
        'used'=>false,
        'grace_until'=>0,
        'warning_sent'=>false,
        'version_lock'=>'',
        'products'=>[],
        'template'=>'',
        'auto_name'=>false,
        'qr_data'=>'',
        'link_key'=>false,
        'share_token'=>'',
        'rent_to'=>0,
        'rent_until'=>0,
        'family_members'=>[],
        'soft_ban_until'=>0,
        'android_id'=>'',
        'freeze_week'=>[],
        'pulse'=>false,
        'silent'=>false,
        'priority_support'=>false,
        'achievements'=>[],
        'promo_used'=>'',
        'last_activity'=>0
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
    $limit = (int)($db['settings']['user_freeze_per_week'] ?? 2);
    $w = weekId();
    $used = (int)($kd['freeze_week'][$w] ?? 0);
    return $used < $limit;
}

function registerUserFreeze(&$kd) {
    $w = weekId();
    if (!isset($kd['freeze_week']) || !is_array($kd['freeze_week'])) $kd['freeze_week'] = [];
    $kd['freeze_week'][$w] = ($kd['freeze_week'][$w] ?? 0) + 1;
}

function generateKey($level, $prefix='') {
    global $db;
    $prefix = $prefix ?: ($db['settings']['key_prefix'] ?? 'LORI');
    return strtoupper($prefix).'-'.strtoupper($level).'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,6));
}

function daysLeft($kd) {
    if (($kd['expires'] ?? 0) == 0) return '∞';
    $left = $kd['expires'] - time();
    if ($left <= 0) return 0;
    return ceil($left / 86400);
}

function keyStatus($kd) {
    $now = time();
    if (!empty($kd['archived'])) return 'archived';
    if (!empty($kd['is_frozen'])) return 'frozen';
    if (!empty($kd['one_time']) && !empty($kd['used'])) return 'used';
    if (($kd['expires'] ?? 0) > 0 && $now > $kd['expires']) {
        if (($kd['grace_until'] ?? 0) > $now) return 'grace';
        return 'expired';
    }
    if (($kd['first_use'] ?? 0) == 0) return 'unused';
    return 'active';
}

function ico($name, $size=18) {
    $s = (int)$size; $c = 'currentColor';
    $map = [
        'home'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'key'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>',
        'users'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>',
        'settings'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'zap'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
        'shield'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'trash'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        'copy'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
        'search'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        'plus'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        'lock'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'refresh'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>',
        'download'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'bell'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/></svg>',
        'tag'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg>',
        'star'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'activity'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        'github'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>',
        'folder'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
        'qr'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><line x1="14" y1="14" x2="14" y2="14"/><line x1="17" y1="14" x2="21" y2="14"/><line x1="14" y1="17" x2="14" y2="21"/><line x1="17" y1="21" x2="21" y2="21"/></svg>',
        'eye'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
        'archive'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
        'heart'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
        'palette'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>',
        'chart'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'clock'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'globe'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
        'send'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
        'edit'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        'check'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
        'x'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'logout'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'list'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
        'grid'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
        'table'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/></svg>',
        'filter'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>',
        'sort'=>'<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="'.$c.'" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>'
    ];
    return $map[$name] ?? '';
}

// Cleanup online
foreach ($db['online'] as $hwid => $data) {
    if (time() - ($data['last_ping'] ?? 0) > 120) unset($db['online'][$hwid]);
}

// Auto cleanup expired
if (!empty($db['settings']['auto_cleanup'])) {
    $cleaned = 0;
    foreach ($db['keys'] as $kk => $kd) {
        if (($kd['expires'] ?? 0) > 0 && time() > $kd['expires'] + 86400) {
            unset($db['keys'][$kk]);
            $cleaned++;
        }
    }
    if ($cleaned > 0) saveDb();
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
    if (empty($key) || empty($hwid)) { echo empty($key)?'No key':'No HWID'; exit; }
    
    if (isset($db['blacklist'][$ip]) || isset($db['blacklist'][$hwid])) {
        addAccessLog($key,$hwid,$ip,false); echo 'Blocked'; exit;
    }
    if (!empty($db['settings']['enable_whitelist']) && !empty($db['settings']['whitelist_only'])) {
        if (!isset($db['whitelist'][$ip]) && !isset($db['whitelist'][$hwid])) {
            addAccessLog($key,$hwid,$ip,false); echo 'Not whitelisted'; exit;
        }
    }
    if (!isset($db['keys'][$key])) { addAccessLog($key,$hwid,$ip,false); echo 'Invalid key'; exit; }
    
    $kd = &$db['keys'][$key];
    $now = time();
    
    if (!empty($kd['archived'])) { addAccessLog($key,$hwid,$ip,false); echo 'Archived'; exit; }
    if (!empty($kd['is_frozen'])) { addAccessLog($key,$hwid,$ip,false); echo 'Key frozen'; exit; }
    if (!empty($kd['soft_ban_until']) && $now < $kd['soft_ban_until']) { addAccessLog($key,$hwid,$ip,false); echo 'Temporary ban'; exit; }
    if (!empty($kd['one_time']) && !empty($kd['used'])) { addAccessLog($key,$hwid,$ip,false); echo 'Already used'; exit; }
    
    // Time restriction
    if (!empty($kd['time_restrict']) && is_array($kd['time_restrict'])) {
        $hour = (int)date('G');
        $allowed = false;
        foreach ($kd['time_restrict'] as $tr) {
            if ($hour >= $tr['from'] && $hour < $tr['to']) { $allowed = true; break; }
        }
        if (!$allowed) { addAccessLog($key,$hwid,$ip,false); echo 'Time restricted'; exit; }
    }
    
    // Country block
    if (!empty($kd['country_list']) && is_array($kd['country_list'])) {
        // Тут нужна гео-lookup библиотека, пока заглушка
    }
    
    // IP limit
    if (!empty($db['settings']['ip_limit_per_key']) && $db['settings']['ip_limit_per_key'] > 0) {
        $ipCount = count(array_unique(array_column($kd['activations'] ?? [], 'ip')));
        if ($ipCount >= $db['settings']['ip_limit_per_key'] && !in_array($ip, array_column($kd['activations'] ?? [], 'ip'))) {
            addAccessLog($key,$hwid,$ip,false); echo 'IP limit'; exit;
        }
    }
    
    // Expiration with grace period
    if (($kd['expires'] ?? 0) !== 0 && $now > $kd['expires']) {
        if (($kd['grace_until'] ?? 0) > $now) {
            // Grace period active
        } else {
            addAccessLog($key,$hwid,$ip,false); echo 'Expired'; exit;
        }
    }
    
    $max = (int)($kd['max'] ?? 1);
    $acts = $kd['activations'] ?? [];
    
    foreach ($acts as &$a) {
        if (($a['hwid'] ?? '') === $hwid) {
            $a['ip']=$ip; $a['last_active']=$now; $a['launches']=($a['launches']??0)+1;
            $kd['launches_count'] = ($kd['launches_count'] ?? 0) + 1;
            $kd['last_activity'] = $now;
            if (!empty($kd['launch_limit']) && $kd['launches_count'] >= $kd['launch_limit']) {
                saveDb(); addAccessLog($key,$hwid,$ip,false); echo 'Launch limit reached'; exit;
            }
            saveDb(); addAccessLog($key,$hwid,$ip,true);
            echo 'SUCCESS'; exit;
        }
    }
    unset($a);
    
    // Share detection
    if (!empty($db['settings']['share_detect_limit']) && count($acts) >= $db['settings']['share_detect_limit']) {
        if (!empty($db['settings']['auto_ban_on_share'])) {
            $kd['is_frozen'] = true;
            addLog("Auto-ban: share detected on $key");
            saveDb();
        }
        addAccessLog($key,$hwid,$ip,false); echo 'Device limit'; exit;
    }
    
    if (count($acts) < $max) {
        if (($kd['first_use'] ?? 0) == 0) {
            $kd['first_use'] = $now;
            if (($kd['duration'] ?? 0) > 0) $kd['expires'] = $now + $kd['duration'];
        }
        $kd['activations'][] = ['hwid'=>$hwid,'ip'=>$ip,'time'=>$now,'last_active'=>$now,'launches'=>1];
        $kd['launches_count'] = ($kd['launches_count'] ?? 0) + 1;
        $kd['last_activity'] = $now;
        
        if (!empty($kd['one_time'])) $kd['used'] = true;
        
        if (!empty($kd['ip_list']) && is_array($kd['ip_list']) && !in_array($ip, $kd['ip_list'])) {
            $kd['ip_list'][] = $ip;
        }
        
        saveDb(); addLog("Activated $key | ".substr($hwid,0,12)." | $ip");
        addAccessLog($key,$hwid,$ip,true);
        
        if (!empty($db['settings']['notify_new_activation']) && !empty($db['settings']['telegram_notify_admin'])) {
            @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$adminId&text=".urlencode("🔑 Новая активация\nКлюч: $key\nIP: $ip\nHWID: ".substr($hwid,0,16)));
        }
        
        echo 'SUCCESS';
    } else {
        addAccessLog($key,$hwid,$ip,false); echo 'Device limit';
    }
    exit;
}

if (($action === 'export_keys' || $action === 'export_json' || $action === 'export_csv') && isset($_GET['admin'])) {
    session_start();
    if (empty($_SESSION['admin'])) { http_response_code(403); exit; }
    if ($action === 'export_keys') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="keys_'.date('Y-m-d').'.txt"');
        foreach ($db['keys'] as $k => $kd) echo $k."\n";
    } elseif ($action === 'export_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="keys_'.date('Y-m-d').'.csv"');
        echo "Key,Level,Owner,Days Left,Status,Activations\n";
        foreach ($db['keys'] as $k => $kd) {
            $days = daysLeft($kd);
            $status = keyStatus($kd);
            $used = count($kd['activations'] ?? []);
            echo "\"$k\",\"{$kd['level']}\",\"{$kd['owner_name']}\",$days,\"$status\",$used\n";
        }
    } else {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="backup_'.date('Y-m-d_H-i').'.json"');
        echo json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================
// 8. ADMIN PANEL
// ============================================================
if (isset($_GET['admin'])) {
    if (isset($_GET['logout'])) { session_destroy(); header('Location: ?admin'); exit; }

    // Brute force protection
    if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
    if (!isset($_SESSION['login_blocked_until'])) $_SESSION['login_blocked_until'] = 0;
    
    if ($_SESSION['login_blocked_until'] > time()) {
        die('Too many attempts. Wait '.ceil(($_SESSION['login_blocked_until']-time())/60).' minutes.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $adminPass) {
            $_SESSION['admin'] = true;
            $_SESSION['login_attempts'] = 0;
            $_SESSION['login_blocked_until'] = 0;
            array_unshift($db['login_log'], ['time'=>time(),'ip'=>$ip,'ok'=>true]);
            addLog("Admin login from $ip");
            header('Location: ?admin'); exit;
        }
        $_SESSION['login_attempts']++;
        array_unshift($db['login_log'], ['time'=>time(),'ip'=>$ip,'ok'=>false]);
        saveDb();
        
        if ($_SESSION['login_attempts'] >= ($db['settings']['max_login_attempts'] ?? 5)) {
            $_SESSION['login_blocked_until'] = time() + 900; // 15 min
            $_SESSION['login_attempts'] = 0;
        }
        $loginError = 'Неверный пароль ('.$_SESSION['login_attempts'].'/'.($db['settings']['max_login_attempts'] ?? 5).')';
    }

    if (empty($_SESSION['admin'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LORI</title>
<style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f172a;font-family:Inter,system-ui;color:#e2e8f0;overflow:hidden}
body::before{content:"";position:fixed;inset:0;background:radial-gradient(circle at 20% 30%,rgba(99,102,241,.15),transparent 50%),radial-gradient(circle at 80% 70%,rgba(139,92,246,.15),transparent 50%);z-index:-1}
.box{width:100%;max-width:400px;padding:48px 36px;background:rgba(30,41,59,.7);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.1);border-radius:24px;box-shadow:0 25px 50px -12px rgba(0,0,0,.5)}
.logo{text-align:center;font-size:32px;font-weight:800;background:linear-gradient(135deg,#6366f1,#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:8px;letter-spacing:2px}
.sub{text-align:center;font-size:11px;color:#64748b;margin-bottom:32px;letter-spacing:3px;text-transform:uppercase}
input{width:100%;padding:14px 18px;background:rgba(15,23,42,.6);border:1px solid rgba(255,255,255,.1);border-radius:12px;color:#fff;margin-bottom:14px;outline:none;font-size:14px;transition:.2s}
input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.2)}
button{width:100%;padding:14px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;border-radius:12px;color:#fff;font-weight:600;cursor:pointer;font-size:14px;transition:.2s}
button:hover{transform:translateY(-2px);box-shadow:0 10px 20px -5px rgba(99,102,241,.5)}
.err{color:#f87171;text-align:center;font-size:13px;margin-bottom:14px;padding:10px;background:rgba(239,68,68,.1);border-radius:8px}
</style></head><body>
<div class="box">
<div class="logo">LORI</div>
<div class="sub">Control Panel v8</div>
'.(!empty($loginError)?'<div class="err">'.$loginError.'</div>':'').'
<form method="post"><input type="password" name="password" placeholder="Access Code" required autofocus>
<button type="submit">Enter System</button></form>
</div></body></html>';
        exit;
    }

    $tab = $_GET['tab'] ?? 'dashboard';
    $viewKey = $_GET['view'] ?? '';
    $msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';
    $viewMode = $_GET['view_mode'] ?? ($_COOKIE['view_mode'] ?? 'grid');
    $sortBy = $_GET['sort'] ?? ($_COOKIE['sort'] ?? 'created_desc');
    $filterStatus = $_GET['filter'] ?? '';
    $filterFolder = $_GET['folder'] ?? '';
    $filterTag = $_GET['tag'] ?? '';
    $searchQ = $_GET['q'] ?? '';

    // ============================================================
    // ADMIN ACTIONS
    // ============================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $act = $_POST['action'];
        $k = $_POST['key'] ?? '';

        // --- KEY GENERATION ---
        if ($act === 'gen_key') {
            $hours=(int)($_POST['hours']??24); 
            $max=max(1,(int)($_POST['max']??1));
            $level=in_array($_POST['level']??'',['trial','free','media','premium','elite'])?$_POST['level']:'premium';
            $customName=trim($_POST['custom_name']??'');
            $duration=$hours===0?0:$hours*3600;
            $ownerTg=(int)($_POST['owner_tg']??0);
            $folder=trim($_POST['folder']??'');
            $tag=trim($_POST['tag']??'');
            $note=trim($_POST['note']??'');
            $oneTime=!empty($_POST['one_time']);
            $launchLimit=(int)($_POST['launch_limit']??0);
            $color=trim($_POST['color']??'');
            $priority=(int)($_POST['priority']??0);
            $versionLock=trim($_POST['version_lock']??'');
            
            if ($customName!=='') {
                if (isset($db['keys'][$customName])) redirectAdmin('generate','Имя занято');
                $db['keys'][$customName]=makeKeyData($duration,$max,$level,$ownerTg,$customName,true);
                $newKey = $customName;
            } else {
                $newKey = generateKey($level);
                $db['keys'][$newKey]=makeKeyData($duration,$max,$level,$ownerTg);
            }
            
            $db['keys'][$newKey]['folder'] = $folder;
            $db['keys'][$newKey]['tag'] = $tag;
            $db['keys'][$newKey]['note'] = $note;
            $db['keys'][$newKey]['one_time'] = $oneTime;
            $db['keys'][$newKey]['launch_limit'] = $launchLimit;
            $db['keys'][$newKey]['color'] = $color;
            $db['keys'][$newKey]['priority'] = $priority;
            $db['keys'][$newKey]['version_lock'] = $versionLock;
            
            saveDb(); addLog("Created: $newKey"); addAdminHistory('Create', $newKey);
            redirectAdmin('generate',"Создан: $newKey");
        }
        
        if ($act === 'bulk_generate') {
            $count=max(1,min(100,(int)($_POST['count']??10)));
            $hours=(int)($_POST['hours']??24);
            $max=max(1,(int)($_POST['max']??1));
            $level=in_array($_POST['level']??'',['trial','free','media','premium','elite'])?$_POST['level']:'premium';
            $duration=$hours===0?0:$hours*3600;
            $folder=trim($_POST['folder']??'');
            $tag=trim($_POST['tag']??'');
            $created=[];
            for($i=0;$i<$count;$i++){
                $newKey=generateKey($level);
                $db['keys'][$newKey]=makeKeyData($duration,$max,$level);
                $db['keys'][$newKey]['folder']=$folder;
                $db['keys'][$newKey]['tag']=$tag;
                $created[]=$newKey;
            }
            saveDb(); addAdminHistory('Bulk Create', "$count keys");
            redirectAdmin('bulk',"Создано $count ключей");
        }
        
        if ($act === 'give_key') {
            $tgId=(int)($_POST['tg_id']??0);
            $hours=(int)($_POST['hours']??24);
            $max=max(1,(int)($_POST['max']??1));
            $level=in_array($_POST['level']??'',['trial','free','media','premium','elite'])?$_POST['level']:'premium';
            $customName=trim($_POST['custom_name']??'');
            if($tgId<=0) redirectAdmin('give','Нужен TG ID');
            $duration=$hours===0?0:$hours*3600;
            if($customName!==''){
                if(isset($db['keys'][$customName])) redirectAdmin('give','Имя занято');
                $db['keys'][$customName]=makeKeyData($duration,$max,$level,$tgId,$customName,true);
                $newKey=$customName;
            } else {
                $newKey=generateKey($level);
                $db['keys'][$newKey]=makeKeyData($duration,$max,$level,$tgId);
            }
            saveDb();
            @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$tgId&text=".urlencode("🔑 LORI\nВаш ключ:\n\n<code>$newKey</code>"));
            addAdminHistory('Give Key', "$newKey → $tgId");
            redirectAdmin('give',"Выдан: $newKey");
        }

        // --- SINGLE KEY ACTIONS ---
        if ($k && isset($db['keys'][$k])) {
            if ($act==='freeze_key') { 
                $db['keys'][$k]['is_frozen']=empty($db['keys'][$k]['is_frozen']); 
                saveDb(); addAdminHistory('Freeze', $k);
                header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit; 
            }
            if ($act==='reset_hwid') { 
                $db['keys'][$k]['activations']=[]; 
                saveDb(); addLog("HWID reset $k"); addAdminHistory('Reset HWID', $k);
                header('Location:?admin&view='.urlencode($k).'&msg=HWID'); exit; 
            }
            if ($act==='delete_key') { 
                unset($db['keys'][$k]); 
                saveDb(); addAdminHistory('Delete', $k);
                redirectAdmin('keys','Удалён'); 
            }
            if ($act==='archive_key') { 
                $db['keys'][$k]['archived']=empty($db['keys'][$k]['archived']); 
                saveDb(); addAdminHistory('Archive', $k);
                header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit; 
            }
            if ($act==='toggle_favorite') { 
                $db['keys'][$k]['favorite']=empty($db['keys'][$k]['favorite']); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit; 
            }
            if ($act==='add_warn') { 
                $db['keys'][$k]['warns']=min(3,($db['keys'][$k]['warns']??0)+1); 
                saveDb(); addAdminHistory('Warn', $k);
                header('Location:?admin&view='.urlencode($k).'&msg=Warn'); exit; 
            }
            if ($act==='reset_warns') { 
                $db['keys'][$k]['warns']=0; 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit; 
            }
            if ($act==='extend_key') {
                $days=max(1,(int)($_POST['days']??7));
                if(($db['keys'][$k]['expires']??0)==0) $db['keys'][$k]['expires']=time()+$days*86400;
                else $db['keys'][$k]['expires']+=$days*86400;
                saveDb(); addExtendLog("$k +$days д",1,$days); addAdminHistory('Extend', "$k +$days");
                header('Location:?admin&view='.urlencode($k).'&msg=+'.$days); exit;
            }
            if ($act==='set_nick') { 
                $db['keys'][$k]['owner_name']=trim($_POST['nick']??''); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Nick'); exit; 
            }
            if ($act==='set_note') { 
                $db['keys'][$k]['note']=trim($_POST['note']??''); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Note'); exit; 
            }
            if ($act==='set_tag') { 
                $db['keys'][$k]['tag']=trim($_POST['tag']??''); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Tag'); exit; 
            }
            if ($act==='set_folder') { 
                $db['keys'][$k]['folder']=trim($_POST['folder']??''); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Folder'); exit; 
            }
            if ($act==='set_color') { 
                $db['keys'][$k]['color']=trim($_POST['color']??''); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Color'); exit; 
            }
            if ($act==='set_priority') { 
                $db['keys'][$k]['priority']=(int)($_POST['priority']??0); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Priority'); exit; 
            }
            if ($act==='set_max') { 
                $db['keys'][$k]['max']=max(1,(int)($_POST['max']??1)); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Max'); exit; 
            }
            if ($act==='set_launch_limit') { 
                $db['keys'][$k]['launch_limit']=max(0,(int)($_POST['launch_limit']??0)); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=LaunchLimit'); exit; 
            }
            if ($act==='set_version_lock') { 
                $db['keys'][$k]['version_lock']=trim($_POST['version_lock']??''); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Version'); exit; 
            }
            if ($act==='transfer_key') { 
                $t=(int)($_POST['new_tg']??0); 
                if($t>0){
                    $db['keys'][$k]['owner_tg']=$t;
                    saveDb();
                }
                header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit; 
            }
            if ($act==='clear_activations') { 
                $db['keys'][$k]['activations']=[]; 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Clear'); exit; 
            }
            if ($act==='make_lifetime') { 
                $db['keys'][$k]['duration']=0; 
                $db['keys'][$k]['expires']=0; 
                saveDb(); addExtendLog("$k навсегда",1,0); addAdminHistory('Lifetime', $k);
                header('Location:?admin&view='.urlencode($k).'&msg=Life'); exit; 
            }
            if ($act==='clone_key') {
                $old=$db['keys'][$k];
                $newKey=(!empty($old['named'])?$k.'_copy':generateKey($old['level']??'premium'));
                if(isset($db['keys'][$newKey])) $newKey.='_'.substr(md5(time()),0,4);
                $db['keys'][$newKey]=$old;
                $db['keys'][$newKey]['activations']=[];
                $db['keys'][$newKey]['first_use']=0;
                $db['keys'][$newKey]['expires']=0;
                $db['keys'][$newKey]['created']=time();
                $db['keys'][$newKey]['used']=false;
                saveDb(); addAdminHistory('Clone', "$k → $newKey");
                header('Location:?admin&view='.urlencode($newKey).'&msg=Clone'); exit;
            }
            if ($act==='regen_key') {
                if(!empty($db['keys'][$k]['named'])) { header('Location:?admin&view='.urlencode($k).'&msg=Named'); exit; }
                $old=$db['keys'][$k];
                $level=$old['level']??'premium';
                $newKey=generateKey($level);
                $db['keys'][$newKey]=$old;
                $db['keys'][$newKey]['activations']=[];
                unset($db['keys'][$k]);
                saveDb(); addAdminHistory('Regen', "$k → $newKey");
                header('Location:?admin&view='.urlencode($newKey).'&msg=Regen'); exit;
            }
            if ($act==='refill_resets') {
                $n=(int)($db['settings']['user_hwid_resets']??2);
                $db['keys'][$k]['reset_left']=$n;
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Resets'); exit;
            }
            if ($act==='toggle_pulse') { 
                $db['keys'][$k]['pulse']=empty($db['keys'][$k]['pulse']); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Pulse'); exit; 
            }
            if ($act==='toggle_silent') { 
                $db['keys'][$k]['silent']=empty($db['keys'][$k]['silent']); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Silent'); exit; 
            }
            if ($act==='toggle_priority_support') { 
                $db['keys'][$k]['priority_support']=empty($db['keys'][$k]['priority_support']); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Priority'); exit; 
            }
            if ($act==='toggle_one_time') { 
                $db['keys'][$k]['one_time']=empty($db['keys'][$k]['one_time']); 
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=OneTime'); exit; 
            }
            if ($act==='set_grace_period') {
                $hours=max(0,(int)($_POST['hours']??24));
                $db['keys'][$k]['grace_until']=time()+$hours*3600;
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Grace'); exit;
            }
            if ($act==='set_time_restrict') {
                $from=(int)($_POST['from']??0);
                $to=(int)($_POST['to']??24);
                $db['keys'][$k]['time_restrict']=[['from'=>$from,'to'=>$to]];
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Time'); exit;
            }
            if ($act==='clear_time_restrict') {
                $db['keys'][$k]['time_restrict']=[];
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=TimeClear'); exit;
            }
            if ($act==='soft_ban') {
                $hours=max(1,(int)($_POST['hours']??24));
                $db['keys'][$k]['soft_ban_until']=time()+$hours*3600;
                saveDb(); addAdminHistory('Soft Ban', "$k $hours h");
                header('Location:?admin&view='.urlencode($k).'&msg=Banned'); exit;
            }
            if ($act==='remove_soft_ban') {
                $db['keys'][$k]['soft_ban_until']=0;
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit;
            }
            if ($act==='generate_qr') {
                $db['keys'][$k]['qr_data']='lori://key/'.urlencode($k);
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=QR'); exit;
            }
            if ($act==='generate_link') {
                $token=substr(md5(uniqid(mt_rand(),true)),0,12);
                $db['keys'][$k]['share_token']=$token;
                $db['keys'][$k]['link_key']=true;
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=Link'); exit;
            }
            if ($act==='reset_share_token') {
                $db['keys'][$k]['share_token']='';
                $db['keys'][$k]['link_key']=false;
                saveDb();
                header('Location:?admin&view='.urlencode($k).'&msg=OK'); exit;
            }
        }

        // --- MASS ACTIONS ---
        if ($act==='mass_extend_all') {
            $days=max(1,(int)($_POST['mass_days']??30)); $n=0;
            foreach($db['keys'] as &$kd){
                if(($kd['expires']??0)==0) $kd['expires']=time()+$days*86400;
                else $kd['expires']+=$days*86400;
                $n++;
            }
            unset($kd); saveDb(); addExtendLog("Всем +$days д",$n,$days); addAdminHistory('Mass Extend', "$n keys +$days");
            redirectAdmin('tools',"Продлено $n на +$days д");
        }
        if ($act==='mass_lifetime_all') {
            $n=0; foreach($db['keys'] as &$kd){ $kd['duration']=0; $kd['expires']=0; $n++; } unset($kd);
            saveDb(); addExtendLog("Все навсегда",$n,0); addAdminHistory('Mass Lifetime', $n);
            redirectAdmin('tools',"Навсегда: $n");
        }
        if ($act==='bulk_freeze') { foreach($db['keys'] as &$kd)$kd['is_frozen']=true; unset($kd); saveDb(); addAdminHistory('Mass Freeze', 'all'); redirectAdmin('tools','Freeze all'); }
        if ($act==='bulk_unfreeze') { foreach($db['keys'] as &$kd)$kd['is_frozen']=false; unset($kd); saveDb(); addAdminHistory('Mass Unfreeze', 'all'); redirectAdmin('tools','Unfreeze'); }
        if ($act==='archive_all_expired') {
            $n=0;
            foreach($db['keys'] as &$kd){
                if(($kd['expires']??0)>0 && time()>$kd['expires'] && empty($kd['archived'])){
                    $kd['archived']=true; $n++;
                }
            }
            unset($kd); saveDb(); addAdminHistory('Archive Expired', $n);
            redirectAdmin('tools',"Архивировано: $n");
        }
        if ($act==='delete_all_archived') {
            $n=0;
            foreach($db['keys'] as $kk=>$kd){
                if(!empty($kd['archived'])){ unset($db['keys'][$kk]); $n++; }
            }
            saveDb(); addAdminHistory('Delete Archived', $n);
            redirectAdmin('tools',"Удалено из архива: $n");
        }
        if ($act==='strip_all_devices') {
            foreach($db['keys'] as &$kd) $kd['activations']=[]; unset($kd); 
            saveDb(); addAdminHistory('Mass HWID Reset', 'all');
            redirectAdmin('tools','Все HWID сброшены');
        }
        if ($act==='reset_all_warns') {
            foreach($db['keys'] as &$kd) $kd['warns']=0; unset($kd); 
            saveDb(); addAdminHistory('Mass Warns Reset', 'all');
            redirectAdmin('tools','Варны сброшены');
        }
        if ($act==='delete_expired') {
            $n=0; foreach($db['keys'] as $kk=>$kd){ if(($kd['expires']??0)>0&&time()>$kd['expires']){unset($db['keys'][$kk]);$n++;}}
            saveDb(); addAdminHistory('Delete Expired', $n);
            redirectAdmin('tools',"Удалено $n");
        }
        if ($act==='mass_set_tag') {
            $tag=trim($_POST['mass_tag']??'');
            $n=0;
            foreach($db['keys'] as &$kd){ $kd['tag']=$tag; $n++; }
            unset($kd); saveDb(); addAdminHistory('Mass Tag', $tag);
            redirectAdmin('tools',"Тег '$tag' установлен на $n");
        }
        if ($act==='mass_set_folder') {
            $folder=trim($_POST['mass_folder']??'');
            $n=0;
            foreach($db['keys'] as &$kd){ $kd['folder']=$folder; $n++; }
            unset($kd); saveDb(); addAdminHistory('Mass Folder', $folder);
            redirectAdmin('tools',"Папка '$folder' для $n");
        }
        if ($act==='mass_set_color') {
            $color=trim($_POST['mass_color']??'');
            $n=0;
            foreach($db['keys'] as &$kd){ $kd['color']=$color; $n++; }
            unset($kd); saveDb(); addAdminHistory('Mass Color', $color);
            redirectAdmin('tools',"Цвет установлен на $n");
        }

        // --- SETTINGS ---
        if ($act==='toggle_global_freeze') { 
            $db['settings']['global_freeze']=empty($db['settings']['global_freeze']); 
            saveDb(); addAdminHistory('Global Freeze', $db['settings']['global_freeze']?'ON':'OFF');
            redirectAdmin('dashboard','OK'); 
        }
        if ($act==='set_status') { 
            $db['settings']['status']=$_POST['status']??'online'; 
            saveDb(); addAdminHistory('Status', $db['settings']['status']);
            redirectAdmin('dashboard','Status'); 
        }
        if ($act==='set_soft_status') { 
            $db['settings']['soft_status']=$_POST['soft_status']??'undetected'; 
            saveDb(); addAdminHistory('Soft Status', $db['settings']['soft_status']);
            redirectAdmin('dashboard','Soft'); 
        }
        if ($act==='set_broadcast') { 
            $db['settings']['broadcast']=trim($_POST['broadcast']??''); 
            saveDb(); addAdminHistory('Broadcast', 'set');
            redirectAdmin('broadcast','OK'); 
        }
        if ($act==='add_blacklist') {
            $val=trim($_POST['value']??'');
            if($val!==''){ 
                $db['blacklist'][$val]=['time'=>time(),'reason'=>trim($_POST['reason']??'')]; 
                saveDb(); addAdminHistory('Blacklist Add', $val);
            }
            redirectAdmin('blacklist','OK');
        }
        if ($act==='remove_blacklist') { 
            unset($db['blacklist'][$_POST['value']??'']); 
            saveDb(); addAdminHistory('Blacklist Remove', $_POST['value']??'');
            redirectAdmin('blacklist','OK'); 
        }
        if ($act==='add_whitelist') {
            $val=trim($_POST['value']??'');
            if($val!==''){ 
                $db['whitelist'][$val]=['time'=>time(),'note'=>trim($_POST['note']??'')]; 
                saveDb(); addAdminHistory('Whitelist Add', $val);
            }
            redirectAdmin('whitelist','OK');
        }
        if ($act==='remove_whitelist') { 
            unset($db['whitelist'][$_POST['value']??'']); 
            saveDb();
            redirectAdmin('whitelist','OK'); 
        }
        if ($act==='save_settings') {
            foreach(['version','checksum','download_url','emergency_msg','bot_welcome','maintenance_msg','key_prefix','discord_webhook'] as $f) 
                if(isset($_POST[$f])) $db['settings'][$f]=trim($_POST[$f]);
            
            foreach(['purchases_enabled','github_sync','auto_cleanup','notify_new_activation','telegram_notify_admin',
                     'allow_self_reset','allow_self_freeze','enable_promo','enable_achievements','enable_folders',
                     'enable_tags','enable_qr','enable_export','sounds_enabled','show_online','show_access_log',
                     'show_admin_history','enable_whitelist','whitelist_only','auto_ban_on_share','maintenance_mode',
                     'killswitch','demo_mode'] as $f) {
                $db['settings'][$f]=!empty($_POST[$f]);
            }
            
            foreach(['user_hwid_resets','user_freeze_per_week','max_login_attempts','share_detect_limit',
                     'grace_period_hours','warning_days_before','default_max_devices','default_hours',
                     'ip_limit_per_key'] as $f) {
                $db['settings'][$f]=max(0,(int)($_POST[$f]??0));
            }
            
            saveDb(); addAdminHistory('Settings', 'saved');
            redirectAdmin('settings','Сохранено');
        }
        if ($act==='set_panel_bg') {
            $db['settings']['panel_bg']=trim($_POST['panel_bg']??'');
            $c=trim($_POST['panel_bg_color']??'#0f172a'); 
            if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$c))$c='#0f172a';
            $db['settings']['panel_bg_color']=$c;
            $a=trim($_POST['panel_accent']??'#6366f1'); 
            if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$a))$a='#6366f1';
            $db['settings']['panel_accent']=$a;
            $db['settings']['panel_overlay']=max(0.3,min(0.95,(float)($_POST['panel_overlay']??0.75)));
            $db['settings']['panel_blur']=max(0,min(40,(int)($_POST['panel_blur']??12)));
            $db['settings']['theme_mode']=$_POST['theme_mode']??'dark';
            $db['settings']['compact_mode']=!empty($_POST['compact_mode']);
            saveDb(); addAdminHistory('Theme', 'updated');
            redirectAdmin('theme','Тема');
        }
        
        // --- GITHUB ---
        if ($act==='github_force_push') {
            $json=json_encode($db, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
            file_put_contents($dbFile,$json);
            $g=ghGet($ghToken,$ghRepo,$ghPath,$ghBranch);
            $sha=$g['sha']??'';
            $ok=ghPut($ghToken,$ghRepo,$ghPath,$ghBranch,$json,$sha);
            addAdminHistory('GitHub Push', $ok?'OK':'FAIL');
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
                addAdminHistory('GitHub Pull', 'OK');
                redirectAdmin('github','GitHub: загружено, ключей '.count($db['keys']??[]));
            }
            addAdminHistory('GitHub Pull', 'FAIL');
            redirectAdmin('github','GitHub: не удалось загрузить');
        }
        
        // --- LOGS ---
        if ($act==='clear_logs') { $db['logs']=[]; saveDb(); addAdminHistory('Clear Logs', ''); redirectAdmin('logs','OK'); }
        if ($act==='clear_access_log') { $db['access_log']=[]; saveDb(); redirectAdmin('access','OK'); }
        if ($act==='clear_online') { $db['online']=[]; saveDb(); redirectAdmin('online','OK'); }
        if ($act==='clear_admin_history') { $db['admin_history']=[]; saveDb(); redirectAdmin('history','OK'); }
        
        // --- IMPORT/EXPORT ---
        if ($act==='import_keys') {
            $raw=trim($_POST['import_text']??'');
            $hours=(int)($_POST['import_hours']??24);
            $duration=$hours===0?0:$hours*3600;
            $folder=trim($_POST['import_folder']??'');
            $tag=trim($_POST['import_tag']??'');
            $n=0;
            foreach(preg_split('/\r\n|\r|\n/',$raw) as $line){
                $line=trim($line);
                if($line===''||isset($db['keys'][$line]))continue;
                $db['keys'][$line]=makeKeyData($duration,1,'premium',0,$line,true);
                $db['keys'][$line]['folder']=$folder;
                $db['keys'][$line]['tag']=$tag;
                $n++;
            }
            saveDb(); addAdminHistory('Import', "$n keys");
            redirectAdmin('tools',"Импорт $n");
        }
        if ($act==='import_csv') {
            $raw=trim($_POST['import_csv']??'');
            $n=0;
            $lines=preg_split('/\r\n|\r|\n/',$raw);
            array_shift($lines); // skip header
            foreach($lines as $line){
                $parts=str_getcsv($line);
                if(count($parts)<2) continue;
                $key=trim($parts[0]);
                if($key===''||isset($db['keys'][$key])) continue;
                $level=trim($parts[1]??'premium');
                $hours=(int)($parts[2]??24);
                $max=(int)($parts[3]??1);
                $owner=trim($parts[4]??'');
                $duration=$hours===0?0:$hours*3600;
                $db['keys'][$key]=makeKeyData($duration,$max,$level,0,$owner,!empty($owner));
                $n++;
            }
            saveDb(); addAdminHistory('CSV Import', "$n keys");
            redirectAdmin('tools',"CSV импорт: $n");
        }
        
        // --- PROMO CODES ---
        if ($act==='create_promo') {
            $code=strtoupper(trim($_POST['promo_code']??''));
            $discount=max(0,min(100,(int)($_POST['promo_discount']??10)));
            $uses=max(1,(int)($_POST['promo_uses']??100));
            $expires=(int)($_POST['promo_expires']??0);
            if($code==='') redirectAdmin('promo','Нужен код');
            $db['promo_codes'][$code]=[
                'discount'=>$discount,
                'max_uses'=>$uses,
                'used'=>0,
                'expires'=>$expires>0?time()+$expires*86400:0,
                'created'=>time()
            ];
            saveDb(); addAdminHistory('Promo Create', $code);
            redirectAdmin('promo',"Создан: $code");
        }
        if ($act==='delete_promo') {
            unset($db['promo_codes'][$_POST['code']??'']);
            saveDb();
            redirectAdmin('promo','Удалён');
        }
        
        // --- FOLDERS ---
        if ($act==='create_folder') {
            $name=trim($_POST['folder_name']??'');
            $color=trim($_POST['folder_color']??'');
            if($name==='') redirectAdmin('folders','Нужно имя');
            $db['folders'][$name]=['color'=>$color,'created'=>time()];
            saveDb(); addAdminHistory('Folder Create', $name);
            redirectAdmin('folders',"Создана: $name");
        }
        if ($act==='delete_folder') {
            $name=$_POST['folder_name']??'';
            unset($db['folders'][$name]);
            foreach($db['keys'] as &$kd){
                if($kd['folder']===$name) $kd['folder']='';
            }
            unset($kd);
            saveDb();
            redirectAdmin('folders','Удалена');
        }
        
        // --- TAGS ---
        if ($act==='create_tag') {
            $name=trim($_POST['tag_name']??'');
            $color=trim($_POST['tag_color']??'');
            if($name==='') redirectAdmin('tags','Нужно имя');
            $db['tags'][$name]=['color'=>$color,'created'=>time()];
            saveDb(); addAdminHistory('Tag Create', $name);
            redirectAdmin('tags',"Создан: $name");
        }
        if ($act==='delete_tag') {
            $name=$_POST['tag_name']??'';
            unset($db['tags'][$name]);
            foreach($db['keys'] as &$kd){
                if($kd['tag']===$name) $kd['tag']='';
            }
            unset($kd);
            saveDb();
            redirectAdmin('tags','Удалён');
        }
        
        // --- NOTIFICATIONS ---
        if ($act==='notify_owners') {
            $text=trim($_POST['notify_text']??'');
            $filterTag=trim($_POST['notify_tag']??'');
            $filterFolder=trim($_POST['notify_folder']??'');
            $sent=0; $ids=[];
            foreach($db['keys'] as $kd){
                if($filterTag!=='' && $kd['tag']!==$filterTag) continue;
                if($filterFolder!=='' && $kd['folder']!==$filterFolder) continue;
                $tg=(int)($kd['owner_tg']??0);
                if($tg>0&&!isset($ids[$tg])){
                    $ids[$tg]=1;
                    @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$tg&text=".urlencode($text));
                    $sent++;
                }
            }
            addAdminHistory('Notify', "$sent users");
            redirectAdmin('tools',"Отправлено $sent");
        }
        
        // --- GEN PREFIX ---
        if ($act==='gen_prefix') {
            $prefix=preg_replace('/[^A-Za-z0-9_\-]/','',trim($_POST['prefix']??'LORI'))?:'LORI';
            $hours=(int)($_POST['prefix_hours']??24);
            $count=max(1,min(100,(int)($_POST['prefix_count']??1)));
            $duration=$hours===0?0:$hours*3600;
            $list=[];
            for($i=0;$i<$count;$i++){
                $newKey=strtoupper($prefix).'-'.strtoupper(substr(md5(uniqid(mt_rand().$i,true)),0,6));
                $db['keys'][$newKey]=makeKeyData($duration,1,'premium');
                $list[]=$newKey;
            }
            saveDb(); addAdminHistory('Prefix Gen', implode(', ',$list));
            redirectAdmin('tools','OK: '.implode(', ',$list));
        }
        
        // --- MISC ---
        if ($act==='random_gift') {
            $keys=array_keys($db['keys']);
            if($keys){
                $rk=$keys[array_rand($keys)];
                $db['keys'][$rk]['priority_support']=true;
                $db['keys'][$rk]['pulse']=true;
                saveDb(); addAdminHistory('Random Gift', $rk);
                redirectAdmin('tools',"Подарок → $rk");
            }
            redirectAdmin('tools','Нет ключей');
        }
        if ($act==='touch_all_expires') {
            $days=max(1,(int)($_POST['touch_days']??1));
            $n=0; $now=time();
            foreach($db['keys'] as &$kd){
                if(($kd['expires']??0)>0 && $kd['expires']<$now){
                    $kd['expires']=$now+$days*86400;
                    $n++;
                }
            }
            unset($kd); saveDb(); addExtendLog("Воскрешение +$days д",$n,$days); addAdminHistory('Resurrect', $n);
            redirectAdmin('tools',"Воскрешено $n");
        }
        
        // --- VIEW MODE ---
        if ($act==='set_view_mode') {
            $mode=$_POST['view_mode']??'grid';
            setcookie('view_mode', $mode, time()+86400*30, '/');
            redirectAdmin($_POST['return_tab']??'keys','OK');
        }
        if ($act==='set_sort') {
            $sort=$_POST['sort']??'created_desc';
            setcookie('sort', $sort, time()+86400*30, '/');
            redirectAdmin($_POST['return_tab']??'keys','OK');
        }
    }

    // ============================================================
    // STATS
    // ============================================================
    $totalKeys=count($db['keys']);
    $onlineCount=count($db['online']);
    $active=$frozen=$expired=$namedCount=$archivedCount=$favoriteCount=$oneTimeCount=0;
    $byLevel=[]; $byFolder=[]; $byTag=[];
    
    foreach($db['keys'] as $kd){
        $status=keyStatus($kd);
        if($status==='frozen')$frozen++;
        elseif($status==='expired')$expired++;
        elseif($status==='archived')$archivedCount++;
        else $active++;
        
        if(!empty($kd['named']))$namedCount++;
        if(!empty($kd['favorite']))$favoriteCount++;
        if(!empty($kd['one_time']))$oneTimeCount++;
        
        $lvl=$kd['level']??'unknown';
        $byLevel[$lvl]=($byLevel[$lvl]??0)+1;
        
        $fld=$kd['folder']??'';
        if($fld!=='') $byFolder[$fld]=($byFolder[$fld]??0)+1;
        
        $tg=$kd['tag']??'';
        if($tg!=='') $byTag[$tg]=($byTag[$tg]??0)+1;
    }
    
    $githubOk = ($ghToken && $ghRepo);
    $accent=$db['settings']['panel_accent']??'#6366f1';
    $panelBg=$db['settings']['panel_bg']??'';
    $panelBgColor=$db['settings']['panel_bg_color']??'#0f172a';
    $overlay=$db['settings']['panel_overlay']??'0.75';
    $blur=(int)($db['settings']['panel_blur']??12);
    $themeMode=$db['settings']['theme_mode']??'dark';
    $compactMode=!empty($db['settings']['compact_mode']);
    $rgb=sscanf($accent,"#%02x%02x%02x")?:[99,102,241];

    // ============================================================
    // FILTER & SORT KEYS
    // ============================================================
    $filteredKeys = $db['keys'];
    
    if ($searchQ !== '') {
        $q = strtolower($searchQ);
        $filteredKeys = array_filter($filteredKeys, function($kd, $k) use ($q) {
            return strpos(strtolower($k), $q) !== false
                || strpos(strtolower($kd['owner_name']??''), $q) !== false
                || strpos((string)($kd['owner_tg']??''), $q) !== false
                || strpos(strtolower($kd['tag']??''), $q) !== false
                || strpos(strtolower($kd['note']??''), $q) !== false;
        }, ARRAY_FILTER_USE_BOTH);
    }
    
    if ($filterStatus !== '') {
        $filteredKeys = array_filter($filteredKeys, function($kd) use ($filterStatus) {
            return keyStatus($kd) === $filterStatus;
        });
    }
    
    if ($filterFolder !== '') {
        $filteredKeys = array_filter($filteredKeys, function($kd) use ($filterFolder) {
            return ($kd['folder']??'') === $filterFolder;
        });
    }
    
    if ($filterTag !== '') {
        $filteredKeys = array_filter($filteredKeys, function($kd) use ($filterTag) {
            return ($kd['tag']??'') === $filterTag;
        });
    }
    
    // Sort
    switch ($sortBy) {
        case 'created_desc':
            uasort($filteredKeys, fn($a,$b)=>($b['created']??0)-($a['created']??0));
            break;
        case 'created_asc':
            uasort($filteredKeys, fn($a,$b)=>($a['created']??0)-($b['created']??0));
            break;
        case 'expires_asc':
            uasort($filteredKeys, function($a,$b){
                $ea=$a['expires']??0; $eb=$b['expires']??0;
                if($ea==0) return 1;
                if($eb==0) return -1;
                return $ea-$eb;
            });
            break;
        case 'expires_desc':
            uasort($filteredKeys, function($a,$b){
                $ea=$a['expires']??0; $eb=$b['expires']??0;
                if($ea==0) return -1;
                if($eb==0) return 1;
                return $eb-$ea;
            });
            break;
        case 'name_asc':
            uksort($filteredKeys, 'strcasecmp');
            break;
        case 'name_desc':
            uksort($filteredKeys, function($a,$b){ return strcasecmp($b,$a); });
            break;
        case 'priority_desc':
            uasort($filteredKeys, fn($a,$b)=>($b['priority']??0)-($a['priority']??0));
            break;
        case 'level_desc':
            $order=['elite'=>5,'premium'=>4,'media'=>3,'free'=>2,'trial'=>1];
            uasort($filteredKeys, function($a,$b) use ($order){
                return ($order[$b['level']??'']??0)-($order[$a['level']??'']??0);
            });
            break;
    }

    // ============================================================
    // RENDER HTML
    // ============================================================
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LORI v8</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
:root{
    --bg:<?= $panelBgColor ?>;
    --card:rgba(30,41,59,0.6);
    --card-solid:#1e293b;
    --border:rgba(255,255,255,0.08);
    --accent:<?= $accent ?>;
    --accent-rgb:<?= implode(',',$rgb) ?>;
    --text:#e2e8f0;
    --muted:#94a3b8;
    --success:#10b981;
    --warning:#f59e0b;
    --danger:#ef4444;
    --info:#3b82f6;
}
<?php if($themeMode==='light'): ?>
:root{
    --bg:#f1f5f9;
    --card:rgba(255,255,255,0.8);
    --card-solid:#ffffff;
    --border:rgba(0,0,0,0.08);
    --text:#0f172a;
    --muted:#64748b;
}
<?php endif; ?>
*{margin:0;padding:0;box-sizing:border-box}
body{
    font-family:'Inter',system-ui,sans-serif;
    background:var(--bg);
    color:var(--text);
    min-height:100vh;
    font-size:<?= $compactMode?'12px':'13px' ?>;
}
<?php if($panelBg): ?>
body::before{
    content:'';position:fixed;inset:0;z-index:-2;
    background:url('<?= htmlspecialchars($panelBg) ?>') center/cover fixed;
    filter:blur(<?= $blur ?>px);transform:scale(1.05);
}
body::after{
    content:'';position:fixed;inset:0;z-index:-1;
    background:rgba(15,23,42,<?= $overlay ?>);
}
<?php else: ?>
body::before{
    content:'';position:fixed;inset:0;z-index:-1;
    background:
        radial-gradient(circle at 20% 30%,rgba(var(--accent-rgb),.15),transparent 50%),
        radial-gradient(circle at 80% 70%,rgba(139,92,246,.1),transparent 50%);
}
<?php endif; ?>

/* LAYOUT */
.layout{display:flex;min-height:100vh}
.sidebar{
    width:240px;
    background:rgba(15,23,42,0.95);
    backdrop-filter:blur(20px);
    border-right:1px solid var(--border);
    padding:20px 12px;
    display:flex;flex-direction:column;
    position:fixed;height:100vh;overflow-y:auto;
    z-index:100;
}
.logo{
    font-size:24px;font-weight:800;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;
    margin-bottom:24px;padding:0 8px;letter-spacing:1px;
    display:flex;align-items:center;gap:10px;
}
.nav{display:flex;flex-direction:column;gap:2px;flex:1}
.nav a{
    display:flex;align-items:center;gap:12px;
    padding:10px 12px;color:var(--muted);
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
.nav-section{
    font-size:10px;color:var(--muted);
    text-transform:uppercase;letter-spacing:1px;
    padding:16px 12px 6px;font-weight:600;
}

.main{flex:1;margin-left:240px;padding:24px 32px;max-width:100%}
.header{
    display:flex;justify-content:space-between;align-items:center;
    margin-bottom:24px;gap:16px;flex-wrap:wrap;
}
.header h2{
    font-size:24px;font-weight:700;
    background:linear-gradient(135deg,var(--text),var(--muted));
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.header-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

.search-bar{
    background:var(--card);
    border:1px solid var(--border);
    padding:10px 16px 10px 40px;
    border-radius:12px;
    color:var(--text);outline:none;
    backdrop-filter:blur(10px);
    width:280px;font-size:13px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E");
    background-repeat:no-repeat;
    background-position:14px center;
    transition:.2s;
}
.search-bar:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(var(--accent-rgb),.1)}

/* CARDS */
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px}
.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:16px;
    padding:20px;
    backdrop-filter:blur(12px);
    position:relative;overflow:hidden;
    transition:.2s;
}
.card:hover{border-color:rgba(var(--accent-rgb),.3)}
.card h3{color:var(--muted);font-size:12px;margin-bottom:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.card .val{font-size:28px;font-weight:800;color:var(--text)}
.card .icon{position:absolute;right:16px;top:16px;opacity:.15;color:var(--accent)}
.card.accent{background:linear-gradient(135deg,rgba(var(--accent-rgb),.15),rgba(var(--accent-rgb),.05));border-color:rgba(var(--accent-rgb),.3)}

.msg{
    background:rgba(16,185,129,.1);
    border:1px solid rgba(16,185,129,.3);
    color:var(--success);
    padding:12px 16px;border-radius:12px;
    margin-bottom:16px;font-size:13px;
    display:flex;align-items:center;gap:8px;
}

/* BUTTONS */
.btn{
    padding:9px 16px;border-radius:10px;border:none;
    font-weight:600;cursor:pointer;transition:.2s;
    display:inline-flex;align-items:center;gap:6px;
    font-size:12px;text-decoration:none;
}
.btn-primary{background:linear-gradient(135deg,var(--accent),#8b5cf6);color:#fff}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(var(--accent-rgb),.4)}
.btn-dark{background:var(--card);border:1px solid var(--border);color:var(--text);backdrop-filter:blur(10px)}
.btn-dark:hover{border-color:rgba(var(--accent-rgb),.3)}
.btn-danger{background:rgba(239,68,68,.15);color:var(--danger);border:1px solid rgba(239,68,68,.2)}
.btn-danger:hover{background:rgba(239,68,68,.25)}
.btn-success{background:rgba(16,185,129,.15);color:var(--success);border:1px solid rgba(16,185,129,.2)}
.btn-sm{padding:6px 10px;font-size:11px}
.btn-icon{padding:8px;width:36px;height:36px;justify-content:center}

/* FORMS */
.form-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:center}
input,select,textarea{
    padding:10px 14px;border-radius:10px;
    border:1px solid var(--border);
    background:rgba(15,23,42,.6);
    color:var(--text);font-size:13px;outline:none;
    transition:.2s;font-family:inherit;
}
input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(var(--accent-rgb),.1)}
textarea{width:100%;min-height:80px;resize:vertical}
label{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px;cursor:pointer}
label input[type="checkbox"]{width:auto;margin:0}

/* KEYS GRID */
.keys-container{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.key-card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:16px;
    padding:18px;
    transition:.2s;cursor:pointer;
    position:relative;overflow:hidden;
    text-decoration:none;color:inherit;display:block;
    backdrop-filter:blur(12px);
}
.key-card:hover{transform:translateY(-3px);border-color:rgba(var(--accent-rgb),.4);box-shadow:0 10px 30px -10px rgba(0,0,0,.5)}
.key-card.favorite{border-color:rgba(251,191,36,.3)}
.key-card.pulse{animation:pulse 2s infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(var(--accent-rgb),.4)}50%{box-shadow:0 0 0 10px rgba(var(--accent-rgb),0)}}
.key-header{display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;gap:8px}
.key-name{font-weight:700;font-size:14px;color:var(--text);word-break:break-all;flex:1}
.key-badges{display:flex;gap:4px;flex-wrap:wrap}
.key-badge{
    font-size:10px;padding:3px 7px;border-radius:6px;
    background:rgba(255,255,255,.08);color:var(--muted);
    font-weight:600;text-transform:uppercase;letter-spacing:.3px;
}
.key-badge.level-trial{background:rgba(148,163,184,.15);color:#94a3b8}
.key-badge.level-free{background:rgba(59,130,246,.15);color:#3b82f6}
.key-badge.level-media{background:rgba(16,185,129,.15);color:#10b981}
.key-badge.level-premium{background:rgba(139,92,246,.15);color:#8b5cf6}
.key-badge.level-elite{background:linear-gradient(135deg,rgba(251,191,36,.2),rgba(245,158,11,.2));color:#fbbf24}
.key-badge.status-frozen{background:rgba(59,130,246,.15);color:#3b82f6}
.key-badge.status-expired{background:rgba(239,68,68,.15);color:#ef4444}
.key-badge.status-unused{background:rgba(245,158,11,.15);color:#f59e0b}
.key-badge.status-grace{background:rgba(245,158,11,.15);color:#f59e0b}
.key-badge.status-archived{background:rgba(100,116,139,.15);color:#64748b}
.key-badge.status-used{background:rgba(100,116,139,.15);color:#64748b}
.key-stats{display:flex;gap:14px;font-size:12px;color:var(--muted);margin-bottom:10px}
.key-stats span{display:flex;align-items:center;gap:4px}
.key-meta{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);padding-top:10px;border-top:1px solid var(--border)}
.key-tag{
    display:inline-block;font-size:10px;padding:2px 8px;border-radius:6px;
    background:rgba(var(--accent-rgb),.15);color:var(--accent);
    margin-top:6px;font-weight:600;
}
.key-folder{
    display:inline-flex;align-items:center;gap:4px;
    font-size:10px;color:var(--muted);margin-top:4px;
}
.progress{height:4px;background:rgba(255,255,255,.08);border-radius:2px;margin-top:10px;overflow:hidden}
.progress-bar{height:100%;background:linear-gradient(90deg,var(--accent),#8b5cf6);transition:width .3s}
.progress-bar.low{background:linear-gradient(90deg,#ef4444,#f59e0b)}
.progress-bar.mid{background:linear-gradient(90deg,#f59e0b,#10b981)}

/* KEY VIEW */
.key-view{
    max-width:700px;margin:0 auto;
    background:var(--card);border:1px solid var(--border);
    border-radius:20px;overflow:hidden;backdrop-filter:blur(20px);
}
.key-view-header{
    padding:24px;
    background:linear-gradient(135deg,rgba(var(--accent-rgb),.15),transparent);
    border-bottom:1px solid var(--border);
}
.key-view-title{font-size:24px;font-weight:800;margin-bottom:8px;word-break:break-all}
.key-view-sub{display:flex;gap:12px;flex-wrap:wrap;color:var(--muted);font-size:13px}
.key-view-section{padding:20px 24px;border-bottom:1px solid var(--border)}
.key-view-section h4{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;font-weight:600}
.key-view-row{display:flex;justify-content:space-between;padding:8px 0;font-size:13px}
.key-view-row .lbl{color:var(--muted)}
.key-view-row .val{color:var(--text);font-weight:500}
.key-view-row .val.g{color:var(--success)}
.key-view-row .val.r{color:var(--danger)}
.key-view-row .val.w{color:var(--warning)}
.key-view-actions{padding:20px 24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px}
.key-action-btn{
    background:rgba(255,255,255,.03);
    border:1px solid var(--border);
    border-radius:12px;padding:12px 8px;
    color:var(--muted);font-size:11px;font-weight:600;
    cursor:pointer;transition:.2s;
    display:flex;flex-direction:column;align-items:center;gap:6px;
}
.key-action-btn:hover{background:rgba(var(--accent-rgb),.1);border-color:rgba(var(--accent-rgb),.3);color:var(--accent)}
.key-action-btn.danger:hover{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:var(--danger)}

/* LOGS */
.log-item{
    font-size:12px;padding:10px 0;
    border-bottom:1px solid var(--border);
    display:flex;gap:12px;align-items:start;
}
.log-item .t{color:var(--accent);white-space:nowrap;font-size:11px;font-weight:600;min-width:70px}
.log-item .ip{color:var(--muted);font-size:11px;font-family:monospace}

/* TABLE */
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{padding:10px 8px;text-align:left;border-bottom:1px solid var(--border)}
th{color:var(--accent);font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:600}
td{color:var(--text)}

/* TOOLS GRID */
.tool-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.tool-card{
    background:var(--card);border:1px solid var(--border);
    border-radius:16px;padding:18px;backdrop-filter:blur(12px);
}
.tool-card h3{
    font-size:13px;color:var(--accent);margin-bottom:14px;
    display:flex;align-items:center;gap:8px;font-weight:700;
}

/* FILTERS */
.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
.filter-btn{
    padding:7px 14px;border-radius:10px;
    background:var(--card);border:1px solid var(--border);
    color:var(--muted);font-size:12px;font-weight:600;
    cursor:pointer;transition:.2s;text-decoration:none;
    display:inline-flex;align-items:center;gap:6px;
}
.filter-btn:hover{border-color:rgba(var(--accent-rgb),.3);color:var(--text)}
.filter-btn.active{background:rgba(var(--accent-rgb),.15);border-color:rgba(var(--accent-rgb),.4);color:var(--accent)}

/* QR */
.qr-container{
    display:flex;justify-content:center;align-items:center;
    padding:20px;background:#fff;border-radius:12px;margin:16px 0;
}

/* MOBILE */
.mobile-menu-btn{display:none}
@media(max-width:900px){
    .sidebar{transform:translateX(-100%);transition:.3s;width:260px}
    .sidebar.open{transform:translateX(0)}
    .main{margin-left:0;padding:16px}
    .mobile-menu-btn{
        display:flex;position:fixed;top:16px;left:16px;z-index:101;
        width:40px;height:40px;border-radius:10px;
        background:var(--card);border:1px solid var(--border);
        align-items:center;justify-content:center;cursor:pointer;
        backdrop-filter:blur(10px);
    }
    .search-bar{width:100%}
    .header{flex-direction:column;align-items:stretch}
    .keys-container{grid-template-columns:1fr}
}

/* SCROLLBAR */
::-webkit-scrollbar{width:8px;height:8px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.2)}

/* ANIMATIONS */
@keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.card,.key-card,.tool-card{animation:fadeIn .3s ease-out}
</style>
</head>
<body>

<div class="mobile-menu-btn" onclick="document.querySelector('.sidebar').classList.toggle('open')"><?= ico('list',20) ?></div>

<div class="layout">
<aside class="sidebar">
    <div class="logo"><?= ico('zap',24) ?> LORI</div>
    <nav class="nav">
        <div class="nav-section">Главное</div>
        <a href="?admin&tab=dashboard" class="<?= $tab==='dashboard'?'active':'' ?>"><?= ico('home',18) ?> <span>Дашборд</span></a>
        <a href="?admin&tab=keys" class="<?= $tab==='keys'?'active':'' ?>"><?= ico('key',18) ?> <span>Ключи</span></a>
        <a href="?admin&tab=generate" class="<?= $tab==='generate'?'active':'' ?>"><?= ico('plus',18) ?> <span>Создать</span></a>
        <a href="?admin&tab=bulk" class="<?= $tab==='bulk'?'active':'' ?>"><?= ico('list',18) ?> <span>Массово</span></a>
        <a href="?admin&tab=give" class="<?= $tab==='give'?'active':'' ?>"><?= ico('send',18) ?> <span>Выдать</span></a>
        
        <div class="nav-section">Аналитика</div>
        <a href="?admin&tab=online" class="<?= $tab==='online'?'active':'' ?>"><?= ico('globe',18) ?> <span>Онлайн</span></a>
        <a href="?admin&tab=access" class="<?= $tab==='access'?'active':'' ?>"><?= ico('eye',18) ?> <span>Логи входов</span></a>
        <a href="?admin&tab=stats" class="<?= $tab==='stats'?'active':'' ?>"><?= ico('chart',18) ?> <span>Статистика</span></a>
        <a href="?admin&tab=logs" class="<?= $tab==='logs'?'active':'' ?>"><?= ico('activity',18) ?> <span>Логи</span></a>
        <a href="?admin&tab=history" class="<?= $tab==='history'?'active':'' ?>"><?= ico('clock',18) ?> <span>История</span></a>
        
        <div class="nav-section">Организация</div>
        <a href="?admin&tab=folders" class="<?= $tab==='folders'?'active':'' ?>"><?= ico('folder',18) ?> <span>Папки</span></a>
        <a href="?admin&tab=tags" class="<?= $tab==='tags'?'active':'' ?>"><?= ico('tag',18) ?> <span>Теги</span></a>
        <a href="?admin&tab=promo" class="<?= $tab==='promo'?'active':'' ?>"><?= ico('star',18) ?> <span>Промокоды</span></a>
        
        <div class="nav-section">Инструменты</div>
        <a href="?admin&tab=tools" class="<?= $tab==='tools'?'active':'' ?>"><?= ico('zap',18) ?> <span>Инструменты</span></a>
        <a href="?admin&tab=broadcast" class="<?= $tab==='broadcast'?'active':'' ?>"><?= ico('bell',18) ?> <span>Рассылка</span></a>
        <a href="?admin&tab=blacklist" class="<?= $tab==='blacklist'?'active':'' ?>"><?= ico('shield',18) ?> <span>Чёрный список</span></a>
        <a href="?admin&tab=whitelist" class="<?= $tab==='whitelist'?'active':'' ?>"><?= ico('check',18) ?> <span>Белый список</span></a>
        
        <div class="nav-section">Система</div>
        <a href="?admin&tab=github" class="<?= $tab==='github'?'active':'' ?>"><?= ico('github',18) ?> <span>GitHub</span></a>
        <a href="?admin&tab=settings" class="<?= $tab==='settings'?'active':'' ?>"><?= ico('settings',18) ?> <span>Настройки</span></a>
        <a href="?admin&tab=theme" class="<?= $tab==='theme'?'active':'' ?>"><?= ico('palette',18) ?> <span>Тема</span></a>
    </nav>
    <div style="padding:12px;border-top:1px solid var(--border);margin-top:12px">
        <a href="?admin&logout=1" style="color:var(--muted);text-decoration:none;display:flex;align-items:center;gap:10px;padding:8px;border-radius:10px;transition:.2s" onmouseover="this.style.background='rgba(255,255,255,.05)'" onmouseout="this.style.background='transparent'">
            <?= ico('logout',18) ?> <span>Выход</span>
        </a>
    </div>
</aside>

<main class="main">
    <div class="header">
        <h2><?= ucfirst($tab) ?></h2>
        <div class="header-actions">
            <?php if(in_array($tab,['keys','dashboard'])): ?>
            <input type="text" class="search-bar" placeholder="Поиск ключей..." value="<?= htmlspecialchars($searchQ) ?>" onkeyup="if(event.key==='Enter'){location.href='?admin&tab=<?= $tab ?>&q='+encodeURIComponent(this.value)}">
            <?php endif; ?>
            <span style="font-size:11px;color:var(--muted);display:flex;align-items:center;gap:6px">
                <?= ico('github',14) ?> 
                <span style="color:<?= $githubOk?'var(--success)':'var(--muted)' ?>"><?= $githubOk?'Sync ON':'Sync OFF' ?></span>
            </span>
        </div>
    </div>

    <?php if($msg): ?><div class="msg"><?= ico('check',16) ?> <?= $msg ?></div><?php endif; ?>

    <?php
    // ============================================================
    // KEY VIEW
    // ============================================================
    if ($viewKey && isset($db['keys'][$viewKey])):
        $kd=$db['keys'][$viewKey];
        $used=count($kd['activations']??[]);
        $max=(int)($kd['max']??1);
        $warns=$kd['warns']??0;
        $ownerName=$kd['owner_name']?:'—';
        $tgId=$kd['owner_tg']?:0;
        $isFrozen=!empty($kd['is_frozen']);
        $isArchived=!empty($kd['archived']);
        $isFavorite=!empty($kd['favorite']);
        $isOneTime=!empty($kd['one_time']);
        $android=$kd['android_id']?:'не привязан';
        $resetLeft=$kd['reset_left']??0;
        $w=weekId();
        $freezeUsed=$kd['freeze_week'][$w]??0;
        $freezeLimit=(int)$db['settings']['user_freeze_per_week'];
        $now=time();
        $daysLeft=daysLeft($kd);
        $status=keyStatus($kd);
        
        $expiresStr='навсегда'; $expClass='g'; $circleP=100;
        if(($kd['expires']??0)>0){
            $left=$kd['expires']-$now;
            if($left<=0){
                if(($kd['grace_until']??0)>$now){$expiresStr='grace period';$expClass='w';$circleP=5;}
                else{$daysLeft='0';$expiresStr='истёк';$expClass='r';$circleP=0;}
            }else{
                $expiresStr=date('d.m.Y H:i',$kd['expires']);
                $circleP=min(100,max(5,($left/(max(1,$kd['duration']?:30*86400)))*100));
            }
        }
        
        $letter=mb_strtoupper(mb_substr($viewKey,0,1));
        $progressClass=$circleP<25?'low':($circleP<50?'mid':'');
    ?>
    <div style="text-align:center;margin-bottom:16px">
        <a href="?admin&tab=keys" class="btn btn-dark"><?= ico('home',14) ?> К списку</a>
    </div>
    
    <div class="key-view">
        <div class="key-view-header">
            <div style="display:flex;gap:16px;align-items:center">
                <div style="width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,var(--accent),#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#fff;flex-shrink:0">
                    <?= htmlspecialchars($letter) ?>
                </div>
                <div style="flex:1;min-width:0">
                    <div class="key-view-title"><?= htmlspecialchars($viewKey) ?></div>
                    <div class="key-view-sub">
                        <span class="key-badge level-<?= $kd['level']??'premium' ?>"><?= $kd['level']??'premium' ?></span>
                        <span class="key-badge status-<?= $status ?>"><?= $status ?></span>
                        <?php if(!empty($kd['named'])): ?><span class="key-badge">именной</span><?php endif; ?>
                        <?php if(!empty($kd['one_time'])): ?><span class="key-badge">1x</span><?php endif; ?>
                        <?php if(!empty($kd['priority_support'])): ?><span class="key-badge" style="background:rgba(251,191,36,.2);color:#fbbf24">VIP</span><?php endif; ?>
                        <?php if(!empty($kd['pulse'])): ?><span class="key-badge" style="background:rgba(var(--accent-rgb),.2);color:var(--accent)">PULSE</span><?php endif; ?>
                    </div>
                </div>
                <form method="post" style="display:contents">
                    <input type="hidden" name="action" value="toggle_favorite">
                    <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                    <button class="btn btn-icon btn-dark" type="submit" title="Избранное" style="color:<?= $isFavorite?'#fbbf24':'var(--muted)' ?>">
                        <?= ico('heart',18) ?>
                    </button>
                </form>
            </div>
        </div>
        
        <div class="key-view-section">
            <h4>Информация</h4>
            <div class="key-view-row"><span class="lbl">Статус</span><span class="val <?= $expClass ?>"><?= $status ?></span></div>
            <div class="key-view-row"><span class="lbl">Действует до</span><span class="val <?= $expClass ?>"><?= $expiresStr ?> (<?= $daysLeft ?> д)</span></div>
            <div class="key-view-row"><span class="lbl">Владелец</span><span class="val"><?= htmlspecialchars($ownerName) ?></span></div>
            <div class="key-view-row"><span class="lbl">Telegram ID</span><span class="val"><?= $tgId?:'—' ?></span></div>
            <div class="key-view-row"><span class="lbl">Android ID</span><span class="val"><?= htmlspecialchars($android) ?></span></div>
            <div class="key-view-row"><span class="lbl">Устройств</span><span class="val"><?= $used ?> / <?= $max ?></span></div>
            <div class="key-view-row"><span class="lbl">Запусков</span><span class="val"><?= $kd['launches_count']??0 ?> <?= ($kd['launch_limit']??0)>0?'/ '.$kd['launch_limit']:'' ?></span></div>
            <div class="key-view-row"><span class="lbl">Предупреждения</span><span class="val <?= $warns>0?'r':'' ?>"><?= $warns ?> / 3</span></div>
            <div class="key-view-row"><span class="lbl">Сбросы HWID</span><span class="val"><?= $resetLeft ?></span></div>
            <div class="key-view-row"><span class="lbl">Заморозки / нед</span><span class="val"><?= $freezeUsed ?> / <?= $freezeLimit ?></span></div>
            <?php if(!empty($kd['folder'])): ?>
            <div class="key-view-row"><span class="lbl">Папка</span><span class="val"><?= htmlspecialchars($kd['folder']) ?></span></div>
            <?php endif; ?>
            <?php if(!empty($kd['tag'])): ?>
            <div class="key-view-row"><span class="lbl">Тег</span><span class="val"><?= htmlspecialchars($kd['tag']) ?></span></div>
            <?php endif; ?>
            <?php if(!empty($kd['note'])): ?>
            <div class="key-view-row"><span class="lbl">Заметка</span><span class="val"><?= htmlspecialchars($kd['note']) ?></span></div>
            <?php endif; ?>
            <?php if(!empty($kd['version_lock'])): ?>
            <div class="key-view-row"><span class="lbl">Версия</span><span class="val"><?= htmlspecialchars($kd['version_lock']) ?></span></div>
            <?php endif; ?>
            <?php if(!empty($kd['time_restrict'])): ?>
            <div class="key-view-row"><span class="lbl">Время</span><span class="val"><?= htmlspecialchars(json_encode($kd['time_restrict'])) ?></span></div>
            <?php endif; ?>
            <?php if(!empty($kd['soft_ban_until']) && $kd['soft_ban_until']>time()): ?>
            <div class="key-view-row"><span class="lbl">Бан до</span><span class="val r"><?= date('d.m.Y H:i',$kd['soft_ban_until']) ?></span></div>
            <?php endif; ?>
            <div class="key-view-row"><span class="lbl">Создан</span><span class="val"><?= date('d.m.Y H:i',$kd['created']??time()) ?></span></div>
            <div class="progress"><div class="progress-bar <?= $progressClass ?>" style="width:<?= $circleP ?>%"></div></div>
        </div>
        
        <?php if($used>0): ?>
        <div class="key-view-section">
            <h4>Устройства (<?= $used ?>)</h4>
            <?php foreach($kd['activations'] as $a): ?>
            <div class="log-item">
                <span class="t"><?= date('d.m H:i',$a['time']??0) ?></span>
                <span style="flex:1;font-family:monospace;font-size:11px"><?= htmlspecialchars(substr($a['hwid']??'',0,20)) ?></span>
                <span class="ip"><?= htmlspecialchars($a['ip']??'') ?></span>
                <span style="color:var(--muted);font-size:11px"><?= $a['launches']??0 ?> запуск.</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div class="key-view-actions">
            <button class="key-action-btn" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($viewKey) ?>').then(()=>alert('Скопировано'))">
                <?= ico('copy',18) ?> <span>Копировать</span>
            </button>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="freeze_key">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('lock',18) ?> <span><?= $isFrozen?'Разблок':'Блок' ?></span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="reset_hwid">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('refresh',18) ?> <span>Сброс HWID</span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="archive_key">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('archive',18) ?> <span><?= $isArchived?'Разархив':'Архив' ?></span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="add_warn">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('zap',18) ?> <span>Варн <?= $warns ?>/3</span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="reset_warns">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('check',18) ?> <span>Сброс варнов</span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="toggle_pulse">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('activity',18) ?> <span>Pulse</span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="toggle_priority_support">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('star',18) ?> <span>VIP</span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="toggle_one_time">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('check',18) ?> <span>1x <?= $isOneTime?'✓':'' ?></span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="make_lifetime">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('clock',18) ?> <span>Навсегда</span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="clone_key">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('copy',18) ?> <span>Клон</span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="regen_key">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit" onclick="return confirm('Перегенерировать?')"><?= ico('refresh',18) ?> <span>Перегенер.</span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="refill_resets">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('refresh',18) ?> <span>Пополнить сбросы</span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="generate_qr">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('qr',18) ?> <span>QR-код</span></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="generate_link">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn" type="submit"><?= ico('send',18) ?> <span>Ссылка</span></button>
            </form>
            <a class="key-action-btn" href="?admin&tab=access&filter=<?= urlencode($viewKey) ?>"><?= ico('eye',18) ?> <span>Логи</span></a>
            <form method="post" style="display:contents" onsubmit="return confirm('Удалить ключ?')">
                <input type="hidden" name="action" value="delete_key">
                <input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>">
                <button class="key-action-btn danger" type="submit"><?= ico('trash',18) ?> <span>Удалить</span></button>
            </form>
        </div>
        
        <div class="key-view-section">
            <h4>Быстрые действия</h4>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <form method="post" class="form-row" style="margin:0"><input type="hidden" name="action" value="extend_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="hidden" name="days" value="7"><button class="btn btn-dark btn-sm" type="submit">+7 дней</button></form>
                <form method="post" class="form-row" style="margin:0"><input type="hidden" name="action" value="extend_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="hidden" name="days" value="30"><button class="btn btn-dark btn-sm" type="submit">+30 дней</button></form>
                <form method="post" class="form-row" style="margin:0"><input type="hidden" name="action" value="set_nick"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="btn btn-dark btn-sm" type="button" onclick="let n=prompt('Ник:','<?= htmlspecialchars($kd['owner_name']??'') ?>');if(n!==null){this.form.querySelector('[name=nick]')||(this.form.insertAdjacentHTML('beforeend','<input type=hidden name=nick>'));this.form.nick.value=n;this.form.submit()}">Ник</button></form>
                <form method="post" class="form-row" style="margin:0"><input type="hidden" name="action" value="set_tag"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="btn btn-dark btn-sm" type="button" onclick="let n=prompt('Тег:','<?= htmlspecialchars($kd['tag']??'') ?>');if(n!==null){this.form.insertAdjacentHTML('beforeend','<input type=hidden name=tag value=\"'+n+'\">');this.form.submit()}">Тег</button></form>
                <form method="post" class="form-row" style="margin:0"><input type="hidden" name="action" value="set_folder"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="btn btn-dark btn-sm" type="button" onclick="let n=prompt('Папка:','<?= htmlspecialchars($kd['folder']??'') ?>');if(n!==null){this.form.insertAdjacentHTML('beforeend','<input type=hidden name=folder value=\"'+n+'\">');this.form.submit()}">Папка</button></form>
                <form method="post" class="form-row" style="margin:0"><input type="hidden" name="action" value="set_note"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><button class="btn btn-dark btn-sm" type="button" onclick="let n=prompt('Заметка:','<?= htmlspecialchars($kd['note']??'') ?>');if(n!==null){this.form.insertAdjacentHTML('beforeend','<input type=hidden name=note value=\"'+n+'\">');this.form.submit()}">Заметка</button></form>
                <form method="post" class="form-row" style="margin:0"><input type="hidden" name="action" value="set_color"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="color" name="color" value="<?= htmlspecialchars($kd['color']??'#6366f1') ?>" onchange="this.form.submit()" style="width:40px;height:30px;padding:2px"></form>
                <form method="post" class="form-row" style="margin:0"><input type="hidden" name="action" value="soft_ban"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="number" name="hours" value="24" style="width:60px" placeholder="Часы"><button class="btn btn-danger btn-sm" type="submit">Бан</button></form>
                <form method="post" class="form-row" style="margin:0"><input type="hidden" name="action" value="set_grace_period"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="number" name="hours" value="24" style="width:60px" placeholder="Часы"><button class="btn btn-dark btn-sm" type="submit">Grace</button></form>
                <form method="post" class="form-row" style="margin:0"><input type="hidden" name="action" value="transfer_key"><input type="hidden" name="key" value="<?= htmlspecialchars($viewKey) ?>"><input type="number" name="new_tg" placeholder="TG ID" style="width:100px"><button class="btn btn-dark btn-sm" type="submit">Передать</button></form>
            </div>
        </div>
    </div>

    <?php
    // ============================================================
    // TABS
    // ============================================================
    elseif ($tab==='dashboard'): ?>
    <div class="grid">
        <div class="card accent"><h3>Всего ключей</h3><div class="val"><?= $totalKeys ?></div><div class="icon"><?= ico('key',40) ?></div></div>
        <div class="card"><h3>Активные</h3><div class="val" style="color:var(--success)"><?= $active ?></div><div class="icon"><?= ico('zap',40) ?></div></div>
        <div class="card"><h3>Онлайн сейчас</h3><div class="val" style="color:var(--info)"><?= $onlineCount ?></div><div class="icon"><?= ico('globe',40) ?></div></div>
        <div class="card"><h3>Заморожено</h3><div class="val" style="color:var(--info)"><?= $frozen ?></div><div class="icon"><?= ico('lock',40) ?></div></div>
        <div class="card"><h3>Истёкло</h3><div class="val" style="color:var(--danger)"><?= $expired ?></div><div class="icon"><?= ico('clock',40) ?></div></div>
        <div class="card"><h3>В архиве</h3><div class="val"><?= $archivedCount ?></div><div class="icon"><?= ico('archive',40) ?></div></div>
        <div class="card"><h3>Избранные</h3><div class="val" style="color:#fbbf24"><?= $favoriteCount ?></div><div class="icon"><?= ico('heart',40) ?></div></div>
        <div class="card"><h3>Одноразовые</h3><div class="val"><?= $oneTimeCount ?></div><div class="icon"><?= ico('check',40) ?></div></div>
    </div>
    
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
        <div class="card">
            <h3>Статус системы</h3>
            <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
                <div class="key-view-row"><span class="lbl">Статус</span><span class="val g"><?= $db['settings']['status'] ?></span></div>
                <div class="key-view-row"><span class="lbl">Софт</span><span class="val"><?= $db['settings']['soft_status'] ?></span></div>
                <div class="key-view-row"><span class="lbl">Версия</span><span class="val"><?= $db['settings']['version'] ?></span></div>
                <div class="key-view-row"><span class="lbl">GitHub</span><span class="val" style="color:<?= $githubOk?'var(--success)':'var(--danger)' ?>"><?= $githubOk?'ON':'OFF' ?></span></div>
                <div class="key-view-row"><span class="lbl">Сервер</span><span class="val"><?= date('H:i:s') ?></span></div>
            </div>
        </div>
        
        <div class="card">
            <h3>Быстрые действия</h3>
            <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
                <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="online"><button class="btn btn-success" type="submit" style="width:100%;justify-content:center"><?= ico('check',14) ?> Online</button></form>
                <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="maintenance"><button class="btn btn-dark" type="submit" style="width:100%;justify-content:center"><?= ico('settings',14) ?> Maintenance</button></form>
                <form method="post"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="killswitch"><button class="btn btn-danger" type="submit" style="width:100%;justify-content:center"><?= ico('x',14) ?> Killswitch</button></form>
                <form method="post"><input type="hidden" name="action" value="toggle_global_freeze"><button class="btn btn-dark" type="submit" style="width:100%;justify-content:center"><?= ico('lock',14) ?> <?= !empty($db['settings']['global_freeze'])?'Unfreeze':'Freeze All' ?></button></form>
            </div>
        </div>
        
        <div class="card">
            <h3>По уровням</h3>
            <div style="margin-top:12px;display:flex;flex-direction:column;gap:6px">
                <?php foreach(['trial','free','media','premium','elite'] as $lvl): ?>
                <div class="key-view-row">
                    <span class="key-badge level-<?= $lvl ?>"><?= $lvl ?></span>
                    <span class="val"><?= $byLevel[$lvl]??0 ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="card">
            <h3>Последние логи</h3>
            <div style="margin-top:12px;max-height:200px;overflow-y:auto">
                <?php foreach(array_slice($db['logs'],0,8) as $log): ?>
                <div class="log-item">
                    <span class="t"><?= date('H:i',$log['time']) ?></span>
                    <span style="flex:1;font-size:12px"><?= htmlspecialchars($log['text']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php elseif ($tab==='keys'): ?>
    <div class="filters">
        <a href="?admin&tab=keys" class="filter-btn <?= $filterStatus===''?'active':'' ?>">Все (<?= $totalKeys ?>)</a>
        <a href="?admin&tab=keys&filter=active" class="filter-btn <?= $filterStatus==='active'?'active':'' ?>">Активные</a>
        <a href="?admin&tab=keys&filter=unused" class="filter-btn <?= $filterStatus==='unused'?'active':'' ?>">Неиспользованные</a>
        <a href="?admin&tab=keys&filter=frozen" class="filter-btn <?= $filterStatus==='frozen'?'active':'' ?>">Замороженные</a>
        <a href="?admin&tab=keys&filter=expired" class="filter-btn <?= $filterStatus==='expired'?'active':'' ?>">Истёкшие</a>
        <a href="?admin&tab=keys&filter=archived" class="filter-btn <?= $filterStatus==='archived'?'active':'' ?>">Архив</a>
        <a href="?admin&tab=keys&filter=grace" class="filter-btn <?= $filterStatus==='grace'?'active':'' ?>">Grace</a>
        
        <div style="margin-left:auto;display:flex;gap:6px">
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="set_view_mode">
                <input type="hidden" name="view_mode" value="grid">
                <input type="hidden" name="return_tab" value="keys">
                <button class="filter-btn <?= $viewMode==='grid'?'active':'' ?>" type="submit"><?= ico('grid',14) ?></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="set_view_mode">
                <input type="hidden" name="view_mode" value="list">
                <input type="hidden" name="return_tab" value="keys">
                <button class="filter-btn <?= $viewMode==='list'?'active':'' ?>" type="submit"><?= ico('list',14) ?></button>
            </form>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="set_view_mode">
                <input type="hidden" name="view_mode" value="table">
                <input type="hidden" name="return_tab" value="keys">
                <button class="filter-btn <?= $viewMode==='table'?'active':'' ?>" type="submit"><?= ico('table',14) ?></button>
            </form>
        </div>
    </div>
    
    <div class="filters">
        <span style="color:var(--muted);font-size:12px">Сортировка:</span>
        <?php foreach([
            'created_desc'=>'Новые',
            'created_asc'=>'Старые',
            'expires_asc'=>'Скоро истекают',
            'expires_desc'=>'Дольше действуют',
            'name_asc'=>'Имя А-Я',
            'name_desc'=>'Имя Я-А',
            'priority_desc'=>'Приоритет',
            'level_desc'=>'Уровень'
        ] as $sv=>$sl): ?>
        <form method="post" style="display:contents">
            <input type="hidden" name="action" value="set_sort">
            <input type="hidden" name="sort" value="<?= $sv ?>">
            <input type="hidden" name="return_tab" value="keys">
            <button class="filter-btn <?= $sortBy===$sv?'active':'' ?>" type="submit"><?= $sl ?></button>
        </form>
        <?php endforeach; ?>
    </div>
    
    <?php if(!empty($byFolder)): ?>
    <div class="filters">
        <span style="color:var(--muted);font-size:12px">Папки:</span>
        <a href="?admin&tab=keys&folder=" class="filter-btn <?= $filterFolder===''?'active':'' ?>">Все</a>
        <?php foreach(array_keys($byFolder) as $f): ?>
        <a href="?admin&tab=keys&folder=<?= urlencode($f) ?>" class="filter-btn <?= $filterFolder===$f?'active':'' ?>"><?= ico('folder',12) ?> <?= htmlspecialchars($f) ?> (<?= $byFolder[$f] ?>)</a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php if(!empty($byTag)): ?>
    <div class="filters">
        <span style="color:var(--muted);font-size:12px">Теги:</span>
        <a href="?admin&tab=keys&tag=" class="filter-btn <?= $filterTag===''?'active':'' ?>">Все</a>
        <?php foreach(array_keys($byTag) as $t): ?>
        <a href="?admin&tab=keys&tag=<?= urlencode($t) ?>" class="filter-btn <?= $filterTag===$t?'active':'' ?>"><?= ico('tag',12) ?> <?= htmlspecialchars($t) ?> (<?= $byTag[$t] ?>)</a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <div style="color:var(--muted);font-size:12px;margin-bottom:12px">Найдено: <?= count($filteredKeys) ?></div>
    
    <?php if($viewMode==='grid'): ?>
    <div class="keys-container">
    <?php foreach($filteredKeys as $k=>$kd):
        $used=count($kd['activations']??[]);
        $max=$kd['max']??1;
        $dl=daysLeft($kd);
        $status=keyStatus($kd);
        $progress=($kd['expires']??0)>0?min(100,max(0,(($kd['expires']-time())/max(1,$kd['duration']?:30*86400))*100)):100;
        $progressClass=$progress<25?'low':($progress<50?'mid':'');
        $isFav=!empty($kd['favorite']);
        $isPulse=!empty($kd['pulse']);
    ?>
        <a href="?admin&view=<?= urlencode($k) ?>" class="key-card <?= $isFav?'favorite':'' ?> <?= $isPulse?'pulse':'' ?>" <?= !empty($kd['color'])?'style="border-color:'.htmlspecialchars($kd['color']).'"':'' ?>>
            <div class="key-header">
                <div class="key-name"><?= htmlspecialchars($k) ?></div>
                <div class="key-badges">
                    <span class="key-badge level-<?= $kd['level']??'premium' ?>"><?= $kd['level']??'premium' ?></span>
                    <span class="key-badge status-<?= $status ?>"><?= $status ?></span>
                </div>
            </div>
            <div class="key-stats">
                <span><?= ico('users',12) ?> <?= $used ?>/<?= $max ?></span>
                <span><?= ico('clock',12) ?> <?= $dl ?>д</span>
                <?php if(!empty($kd['priority_support'])): ?><span style="color:#fbbf24"><?= ico('star',12) ?> VIP</span><?php endif; ?>
                <?php if($isFav): ?><span style="color:#fbbf24"><?= ico('heart',12) ?></span><?php endif; ?>
            </div>
            <?php if(!empty($kd['tag'])): ?><div class="key-tag"><?= ico('tag',10) ?> <?= htmlspecialchars($kd['tag']) ?></div><?php endif; ?>
            <?php if(!empty($kd['folder'])): ?><div class="key-folder"><?= ico('folder',10) ?> <?= htmlspecialchars($kd['folder']) ?></div><?php endif; ?>
            <div class="key-meta">
                <span><?= $kd['owner_name']?:($kd['owner_tg']?:'—') ?></span>
                <span><?= date('d.m',$kd['created']??time()) ?></span>
            </div>
            <div class="progress"><div class="progress-bar <?= $progressClass ?>" style="width:<?= $progress ?>%"></div></div>
        </a>
    <?php endforeach; ?>
    </div>
    
    <?php elseif($viewMode==='list'): ?>
    <div style="display:flex;flex-direction:column;gap:8px">
    <?php foreach($filteredKeys as $k=>$kd):
        $used=count($kd['activations']??[]);
        $max=$kd['max']??1;
        $dl=daysLeft($kd);
        $status=keyStatus($kd);
    ?>
        <a href="?admin&view=<?= urlencode($k) ?>" style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;text-decoration:none;color:inherit;transition:.2s;backdrop-filter:blur(10px)" onmouseover="this.style.borderColor='rgba(<?= implode(',',$rgb) ?>,.3)'" onmouseout="this.style.borderColor='var(--border)'">
            <div style="display:flex;align-items:center;gap:14px;flex:1;min-width:0">
                <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--accent),#8b5cf6);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;flex-shrink:0"><?= mb_strtoupper(mb_substr($k,0,1)) ?></div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:13px;word-break:break-all"><?= htmlspecialchars($k) ?></div>
                    <div style="font-size:11px;color:var(--muted);display:flex;gap:10px;margin-top:2px">
                        <span><?= $kd['owner_name']?:($kd['owner_tg']?:'—') ?></span>
                        <?php if(!empty($kd['tag'])): ?><span><?= ico('tag',10) ?> <?= htmlspecialchars($kd['tag']) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;align-items:center">
                <span class="key-badge level-<?= $kd['level']??'premium' ?>"><?= $kd['level']??'premium' ?></span>
                <span class="key-badge status-<?= $status ?>"><?= $status ?></span>
                <span style="color:var(--muted);font-size:12px"><?= $used ?>/<?= $max ?></span>
                <span style="color:var(--muted);font-size:12px"><?= $dl ?>д</span>
            </div>
        </a>
    <?php endforeach; ?>
    </div>
    
    <?php elseif($viewMode==='table'): ?>
    <div class="card" style="padding:0;overflow-x:auto">
    <table>
        <thead>
            <tr>
                <th>Ключ</th>
                <th>Уровень</th>
                <th>Владелец</th>
                <th>Статус</th>
                <th>Устройств</th>
                <th>Дней</th>
                <th>Создан</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($filteredKeys as $k=>$kd):
            $used=count($kd['activations']??[]);
            $max=$kd['max']??1;
            $dl=daysLeft($kd);
            $status=keyStatus($kd);
        ?>
            <tr>
                <td><a href="?admin&view=<?= urlencode($k) ?>" style="color:var(--text);text-decoration:none;font-weight:600"><?= htmlspecialchars($k) ?></a></td>
                <td><span class="key-badge level-<?= $kd['level']??'premium' ?>"><?= $kd['level']??'premium' ?></span></td>
                <td><?= htmlspecialchars($kd['owner_name']?:($kd['owner_tg']?:'—')) ?></td>
                <td><span class="key-badge status-<?= $status ?>"><?= $status ?></span></td>
                <td><?= $used ?>/<?= $max ?></td>
                <td><?= $dl ?></td>
                <td><?= date('d.m.Y',$kd['created']??time()) ?></td>
                <td><a href="?admin&view=<?= urlencode($k) ?>" class="btn btn-sm btn-dark"><?= ico('eye',12) ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php elseif ($tab==='generate'): ?>
    <div class="card" style="max-width:600px">
        <h3>Создать ключ</h3>
        <form method="post" style="margin-top:16px;display:flex;flex-direction:column;gap:12px">
            <input type="hidden" name="action" value="gen_key">
            <input type="text" name="custom_name" placeholder="Имя ключа (опционально)">
            <div style="display:flex;gap:8px">
                <input type="number" name="hours" value="24" placeholder="Часы" style="flex:1">
                <input type="number" name="max" value="1" min="1" placeholder="Устройств" style="flex:1">
                <select name="level" style="flex:1">
                    <option value="trial">Trial</option>
                    <option value="free">Free</option>
                    <option value="media">Media</option>
                    <option value="premium" selected>Premium</option>
                    <option value="elite">Elite</option>
                </select>
            </div>
            <div style="display:flex;gap:8px">
                <input type="number" name="owner_tg" placeholder="TG ID владельца" style="flex:1">
                <input type="text" name="folder" placeholder="Папка" style="flex:1">
                <input type="text" name="tag" placeholder="Тег" style="flex:1">
            </div>
            <div style="display:flex;gap:8px">
                <input type="number" name="launch_limit" placeholder="Лимит запусков (0=∞)" style="flex:1">
                <input type="number" name="priority" value="0" placeholder="Приоритет" style="flex:1">
                <input type="text" name="version_lock" placeholder="Версия (опц.)" style="flex:1">
            </div>
            <textarea name="note" placeholder="Заметка"></textarea>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="color" name="color" value="#6366f1" style="width:50px;height:40px">
                <label><input type="checkbox" name="one_time" value="1"> Одноразовый</label>
            </div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:12px"><?= ico('plus',16) ?> Создать</button>
        </form>
    </div>

    <?php elseif ($tab==='bulk'): ?>
    <div class="card" style="max-width:600px">
        <h3>Массовая генерация</h3>
        <form method="post" style="margin-top:16px;display:flex;flex-direction:column;gap:12px">
            <input type="hidden" name="action" value="bulk_generate">
            <div style="display:flex;gap:8px">
                <input type="number" name="count" value="10" min="1" max="100" placeholder="Кол-во" style="flex:1">
                <input type="number" name="hours" value="24" placeholder="Часы" style="flex:1">
                <input type="number" name="max" value="1" min="1" placeholder="Устройств" style="flex:1">
            </div>
            <select name="level">
                <option value="trial">Trial</option>
                <option value="free">Free</option>
                <option value="media">Media</option>
                <option value="premium" selected>Premium</option>
                <option value="elite">Elite</option>
            </select>
            <div style="display:flex;gap:8px">
                <input type="text" name="folder" placeholder="Папка" style="flex:1">
                <input type="text" name="tag" placeholder="Тег" style="flex:1">
            </div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:12px"><?= ico('plus',16) ?> Создать</button>
        </form>
    </div>

    <?php elseif ($tab==='give'): ?>
    <div class="card" style="max-width:600px">
        <h3>Выдать ключ</h3>
        <form method="post" style="margin-top:16px;display:flex;flex-direction:column;gap:12px">
            <input type="hidden" name="action" value="give_key">
            <input type="number" name="tg_id" placeholder="Telegram ID" required>
            <input type="text" name="custom_name" placeholder="Имя ключа (опционально)">
            <div style="display:flex;gap:8px">
                <input type="number" name="hours" value="168" placeholder="Часы" style="flex:1">
                <input type="number" name="max" value="1" min="1" placeholder="Устройств" style="flex:1">
                <select name="level" style="flex:1">
                    <option value="trial">Trial</option>
                    <option value="free">Free</option>
                    <option value="media">Media</option>
                    <option value="premium" selected>Premium</option>
                    <option value="elite">Elite</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:12px"><?= ico('send',16) ?> Выдать</button>
        </form>
    </div>

    <?php elseif ($tab==='online'): ?>
    <div class="card">
        <h3>Онлайн (<?= $onlineCount ?>)</h3>
        <?php if(empty($db['online'])): ?>
        <p style="color:var(--muted);margin-top:12px">Никого нет</p>
        <?php else: ?>
        <div style="margin-top:12px;overflow-x:auto">
        <table>
            <thead><tr><th>HWID</th><th>Ключ</th><th>IP</th><th>Пинг</th></tr></thead>
            <tbody>
            <?php foreach($db['online'] as $hwid=>$info): ?>
            <tr>
                <td style="font-family:monospace;font-size:11px"><?= htmlspecialchars(substr($hwid,0,20)) ?></td>
                <td><code><?= htmlspecialchars($info['key']??'') ?></code></td>
                <td><?= htmlspecialchars($info['ip']??'') ?></td>
                <td><?= time()-($info['last_ping']??0) ?>с</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <form method="post" style="margin-top:12px"><input type="hidden" name="action" value="clear_online"><button class="btn btn-danger btn-sm" type="submit">Очистить</button></form>
        <?php endif; ?>
    </div>

    <?php elseif ($tab==='access'): ?>
    <div class="card">
        <h3>Логи входов</h3>
        <?php
        $filter=$_GET['filter']??'';
        $logs=$db['access_log'];
        if($filter) $logs=array_filter($logs,fn($l)=>strpos($l['key']??'',$filter)!==false);
        ?>
        <div style="margin-top:12px;max-height:600px;overflow-y:auto">
        <?php foreach(array_slice($logs,0,200) as $l): ?>
        <div class="log-item">
            <span class="t"><?= date('d.m H:i:s',$l['time']) ?></span>
            <span style="flex:1">
                <span style="color:<?= !empty($l['ok'])?'var(--success)':'var(--danger)' ?>;font-weight:600"><?= !empty($l['ok'])?'OK':'FAIL' ?></span>
                · <code><?= htmlspecialchars($l['key']??'') ?></code>
                · <span style="font-family:monospace;font-size:11px"><?= htmlspecialchars(substr($l['hwid']??'',0,16)) ?></span>
            </span>
            <span class="ip"><?= htmlspecialchars($l['ip']??'') ?></span>
        </div>
        <?php endforeach; ?>
        </div>
        <form method="post" style="margin-top:12px"><input type="hidden" name="action" value="clear_access_log"><button class="btn btn-danger btn-sm" type="submit">Очистить</button></form>
    </div>

    <?php elseif ($tab==='stats'): ?>
    <div class="grid">
        <div class="card accent"><h3>Всего активаций</h3><div class="val"><?= $db['stats']['activations']??0 ?></div></div>
        <div class="card"><h3>Покупок</h3><div class="val"><?= $db['stats']['purchases']??0 ?></div></div>
        <div class="card"><h3>Звёзд</h3><div class="val"><?= $db['stats']['stars']??0 ?></div></div>
    </div>
    
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
        <div class="card">
            <h3>По уровням</h3>
            <div style="margin-top:12px">
            <?php foreach(['trial','free','media','premium','elite'] as $lvl): ?>
            <div style="margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:12px">
                    <span class="key-badge level-<?= $lvl ?>"><?= $lvl ?></span>
                    <span><?= $byLevel[$lvl]??0 ?></span>
                </div>
                <div class="progress"><div class="progress-bar" style="width:<?= $totalKeys>0?(($byLevel[$lvl]??0)/$totalKeys)*100:0 ?>%"></div></div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        
        <div class="card">
            <h3>По папкам</h3>
            <div style="margin-top:12px">
            <?php if(empty($byFolder)): ?>
            <p style="color:var(--muted);font-size:12px">Нет папок</p>
            <?php else: foreach($byFolder as $f=>$c): ?>
            <div class="key-view-row">
                <span><?= ico('folder',14) ?> <?= htmlspecialchars($f) ?></span>
                <span class="val"><?= $c ?></span>
            </div>
            <?php endforeach; endif; ?>
            </div>
        </div>
        
        <div class="card">
            <h3>По тегам</h3>
            <div style="margin-top:12px">
            <?php if(empty($byTag)): ?>
            <p style="color:var(--muted);font-size:12px">Нет тегов</p>
            <?php else: foreach($byTag as $t=>$c): ?>
            <div class="key-view-row">
                <span><?= ico('tag',14) ?> <?= htmlspecialchars($t) ?></span>
                <span class="val"><?= $c ?></span>
            </div>
            <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <?php elseif ($tab==='logs'): ?>
    <div class="card">
        <h3>Логи</h3>
        <div style="margin-top:12px;max-height:600px;overflow-y:auto">
        <?php foreach(array_slice($db['logs'],0,200) as $l): ?>
        <div class="log-item">
            <span class="t"><?= date('d.m H:i:s',$l['time']) ?></span>
            <span style="flex:1"><?= htmlspecialchars($l['text']) ?></span>
        </div>
        <?php endforeach; ?>
        </div>
        <form method="post" style="margin-top:12px"><input type="hidden" name="action" value="clear_logs"><button class="btn btn-danger btn-sm" type="submit">Очистить</button></form>
    </div>

    <?php elseif ($tab==='history'): ?>
    <div class="card">
        <h3>История действий админа</h3>
        <div style="margin-top:12px;max-height:600px;overflow-y:auto">
        <?php foreach(array_slice($db['admin_history'],0,200) as $h): ?>
        <div class="log-item">
            <span class="t"><?= date('d.m H:i',$h['time']) ?></span>
            <span style="flex:1">
                <strong><?= htmlspecialchars($h['action']) ?></strong>
                <?php if(!empty($h['details'])): ?>· <?= htmlspecialchars($h['details']) ?><?php endif; ?>
            </span>
            <span class="ip"><?= htmlspecialchars($h['ip']??'') ?></span>
        </div>
        <?php endforeach; ?>
        </div>
        <form method="post" style="margin-top:12px"><input type="hidden" name="action" value="clear_admin_history"><button class="btn btn-danger btn-sm" type="submit">Очистить</button></form>
    </div>

    <?php elseif ($tab==='folders'): ?>
    <div class="card" style="max-width:600px;margin-bottom:16px">
        <h3>Создать папку</h3>
        <form method="post" style="margin-top:12px;display:flex;gap:8px">
            <input type="hidden" name="action" value="create_folder">
            <input type="text" name="folder_name" placeholder="Имя папки" required style="flex:1">
            <input type="color" name="folder_color" value="#6366f1" style="width:50px">
            <button class="btn btn-primary" type="submit"><?= ico('plus',14) ?></button>
        </form>
    </div>
    
    <div class="card">
        <h3>Папки (<?= count($db['folders']) ?>)</h3>
        <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
        <?php if(empty($db['folders'])): ?>
        <p style="color:var(--muted)">Нет папок</p>
        <?php else: foreach($db['folders'] as $name=>$info): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:rgba(255,255,255,.03);border-radius:10px">
            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:12px;height:12px;border-radius:3px;background:<?= htmlspecialchars($info['color']??'#6366f1') ?>"></div>
                <strong><?= htmlspecialchars($name) ?></strong>
                <span style="color:var(--muted);font-size:12px">(<?= $byFolder[$name]??0 ?> ключей)</span>
            </div>
            <div style="display:flex;gap:6px">
                <a href="?admin&tab=keys&folder=<?= urlencode($name) ?>" class="btn btn-sm btn-dark"><?= ico('eye',12) ?></a>
                <form method="post" style="display:contents" onsubmit="return confirm('Удалить папку?')">
                    <input type="hidden" name="action" value="delete_folder">
                    <input type="hidden" name="folder_name" value="<?= htmlspecialchars($name) ?>">
                    <button class="btn btn-sm btn-danger" type="submit"><?= ico('trash',12) ?></button>
                </form>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>

    <?php elseif ($tab==='tags'): ?>
    <div class="card" style="max-width:600px;margin-bottom:16px">
        <h3>Создать тег</h3>
        <form method="post" style="margin-top:12px;display:flex;gap:8px">
            <input type="hidden" name="action" value="create_tag">
            <input type="text" name="tag_name" placeholder="Имя тега" required style="flex:1">
            <input type="color" name="tag_color" value="#6366f1" style="width:50px">
            <button class="btn btn-primary" type="submit"><?= ico('plus',14) ?></button>
        </form>
    </div>
    
    <div class="card">
        <h3>Теги (<?= count($db['tags']) ?>)</h3>
        <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
        <?php if(empty($db['tags'])): ?>
        <p style="color:var(--muted)">Нет тегов</p>
        <?php else: foreach($db['tags'] as $name=>$info): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:rgba(255,255,255,.03);border-radius:10px">
            <div style="display:flex;align-items:center;gap:10px">
                <span class="key-tag" style="background:<?= htmlspecialchars($info['color']??'#6366f1') ?>20;color:<?= htmlspecialchars($info['color']??'#6366f1') ?>"><?= htmlspecialchars($name) ?></span>
                <span style="color:var(--muted);font-size:12px">(<?= $byTag[$name]??0 ?> ключей)</span>
            </div>
            <div style="display:flex;gap:6px">
                <a href="?admin&tab=keys&tag=<?= urlencode($name) ?>" class="btn btn-sm btn-dark"><?= ico('eye',12) ?></a>
                <form method="post" style="display:contents" onsubmit="return confirm('Удалить тег?')">
                    <input type="hidden" name="action" value="delete_tag">
                    <input type="hidden" name="tag_name" value="<?= htmlspecialchars($name) ?>">
                    <button class="btn btn-sm btn-danger" type="submit"><?= ico('trash',12) ?></button>
                </form>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>

    <?php elseif ($tab==='promo'): ?>
    <div class="card" style="max-width:600px;margin-bottom:16px">
        <h3>Создать промокод</h3>
        <form method="post" style="margin-top:12px;display:flex;flex-direction:column;gap:10px">
            <input type="hidden" name="action" value="create_promo">
            <input type="text" name="promo_code" placeholder="Код (например SUMMER20)" required>
            <div style="display:flex;gap:8px">
                <input type="number" name="promo_discount" value="10" min="1" max="100" placeholder="Скидка %" style="flex:1">
                <input type="number" name="promo_uses" value="100" min="1" placeholder="Макс. использований" style="flex:1">
                <input type="number" name="promo_expires" value="30" min="0" placeholder="Дней (0=∞)" style="flex:1">
            </div>
            <button class="btn btn-primary" type="submit"><?= ico('plus',14) ?> Создать</button>
        </form>
    </div>
    
    <div class="card">
        <h3>Промокоды (<?= count($db['promo_codes']) ?>)</h3>
        <div style="margin-top:12px;overflow-x:auto">
        <table>
            <thead><tr><th>Код</th><th>Скидка</th><th>Использовано</th><th>Истекает</th><th></th></tr></thead>
            <tbody>
            <?php if(empty($db['promo_codes'])): ?>
            <tr><td colspan="5" style="color:var(--muted)">Нет промокодов</td></tr>
            <?php else: foreach($db['promo_codes'] as $code=>$info): ?>
            <tr>
                <td><code style="font-weight:700"><?= htmlspecialchars($code) ?></code></td>
                <td><?= $info['discount']??0 ?>%</td>
                <td><?= $info['used']??0 ?> / <?= $info['max_uses']??0 ?></td>
                <td><?= ($info['expires']??0)>0?date('d.m.Y',$info['expires']):'∞' ?></td>
                <td>
                    <form method="post" style="display:contents" onsubmit="return confirm('Удалить?')">
                        <input type="hidden" name="action" value="delete_promo">
                        <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
                        <button class="btn btn-sm btn-danger" type="submit"><?= ico('trash',12) ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php elseif ($tab==='tools'): ?>
    <div class="tool-grid">
        <div class="tool-card">
            <h3><?= ico('clock',16) ?> Продление</h3>
            <form method="post" class="form-row"><input type="hidden" name="action" value="mass_extend_all"><input type="number" name="mass_days" value="30" style="width:70px"><button class="btn btn-primary" type="submit">+Дней всем</button></form>
            <form method="post" class="form-row" onsubmit="return confirm('Все навсегда?')"><input type="hidden" name="action" value="mass_lifetime_all"><button class="btn btn-primary" type="submit">Все навсегда</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="touch_all_expires"><input type="number" name="touch_days" value="7" style="width:60px"><button class="btn btn-dark btn-sm" type="submit">Воскресить</button></form>
        </div>
        
        <div class="tool-card">
            <h3><?= ico('lock',16) ?> Массовые</h3>
            <form method="post" class="form-row"><input type="hidden" name="action" value="bulk_freeze"><button class="btn btn-dark btn-sm" type="submit">Freeze all</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="bulk_unfreeze"><button class="btn btn-dark btn-sm" type="submit">Unfreeze all</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="strip_all_devices"><button class="btn btn-dark btn-sm" type="submit">Сброс всех HWID</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="reset_all_warns"><button class="btn btn-dark btn-sm" type="submit">Сброс варнов</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="delete_expired"><button class="btn btn-danger btn-sm" type="submit">Удалить истёкшие</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="archive_all_expired"><button class="btn btn-dark btn-sm" type="submit">Архив истёкших</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="delete_all_archived"><button class="btn btn-danger btn-sm" type="submit">Удалить из архива</button></form>
        </div>
        
        <div class="tool-card">
            <h3><?= ico('tag',16) ?> Массовые метки</h3>
            <form method="post" class="form-row"><input type="hidden" name="action" value="mass_set_tag"><input type="text" name="mass_tag" placeholder="Тег" style="flex:1"><button class="btn btn-dark btn-sm" type="submit">Тег всем</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="mass_set_folder"><input type="text" name="mass_folder" placeholder="Папка" style="flex:1"><button class="btn btn-dark btn-sm" type="submit">Папка всем</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="mass_set_color"><input type="color" name="mass_color" value="#6366f1"><button class="btn btn-dark btn-sm" type="submit">Цвет всем</button></form>
        </div>
        
        <div class="tool-card">
            <h3><?= ico('send',16) ?> Рассылка</h3>
            <form method="post"><input type="hidden" name="action" value="notify_owners">
            <textarea name="notify_text" placeholder="Текст сообщения"></textarea>
            <div style="display:flex;gap:6px;margin-top:8px">
                <input type="text" name="notify_tag" placeholder="Тег (опц.)" style="flex:1">
                <input type="text" name="notify_folder" placeholder="Папка (опц.)" style="flex:1">
            </div>
            <button class="btn btn-primary" type="submit" style="margin-top:8px;width:100%;justify-content:center">Отправить</button></form>
        </div>
        
        <div class="tool-card">
            <h3><?= ico('download',16) ?> Импорт</h3>
            <form method="post"><input type="hidden" name="action" value="import_keys">
            <textarea name="import_text" placeholder="Ключ на строку"></textarea>
            <div class="form-row" style="margin-top:8px">
                <input type="number" name="import_hours" value="24" style="width:70px">
                <input type="text" name="import_folder" placeholder="Папка" style="flex:1">
                <input type="text" name="import_tag" placeholder="Тег" style="flex:1">
                <button class="btn btn-primary btn-sm" type="submit">Импорт</button>
            </div></form>
            <hr style="border:0;border-top:1px solid var(--border);margin:12px 0">
            <form method="post"><input type="hidden" name="action" value="import_csv">
            <textarea name="import_csv" placeholder="CSV: key,level,hours,max,owner"></textarea>
            <button class="btn btn-primary btn-sm" type="submit" style="margin-top:8px">CSV импорт</button></form>
        </div>
        
        <div class="tool-card">
            <h3><?= ico('tag',16) ?> Префикс</h3>
            <form method="post" class="form-row">
                <input type="hidden" name="action" value="gen_prefix">
                <input type="text" name="prefix" value="LORI" style="width:80px">
                <input type="number" name="prefix_count" value="1" min="1" max="100" style="width:60px">
                <input type="number" name="prefix_hours" value="24" style="width:60px">
                <button class="btn btn-primary btn-sm" type="submit">Создать</button>
            </form>
        </div>
        
        <div class="tool-card">
            <h3><?= ico('download',16) ?> Экспорт</h3>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a class="btn btn-dark btn-sm" href="?admin&action=export_keys"><?= ico('list',12) ?> TXT</a>
                <a class="btn btn-dark btn-sm" href="?admin&action=export_json"><?= ico('download',12) ?> JSON</a>
                <a class="btn btn-dark btn-sm" href="?admin&action=export_csv"><?= ico('table',12) ?> CSV</a>
            </div>
        </div>
        
        <div class="tool-card">
            <h3><?= ico('trash',16) ?> Очистка</h3>
            <form method="post" class="form-row"><input type="hidden" name="action" value="clear_logs"><button class="btn btn-dark btn-sm" type="submit">Логи</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="clear_access_log"><button class="btn btn-dark btn-sm" type="submit">Логи входов</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="clear_online"><button class="btn btn-dark btn-sm" type="submit">Онлайн</button></form>
            <form method="post" class="form-row"><input type="hidden" name="action" value="clear_admin_history"><button class="btn btn-dark btn-sm" type="submit">История</button></form>
        </div>
        
        <div class="tool-card">
            <h3><?= ico('star',16) ?> Случайное</h3>
            <form method="post" class="form-row"><input type="hidden" name="action" value="random_gift"><button class="btn btn-dark btn-sm" type="submit">Случайный подарок</button></form>
        </div>
    </div>

    <?php elseif ($tab==='broadcast'): ?>
    <div class="card" style="max-width:700px">
        <h3>Глобальная рассылка</h3>
        <form method="post" style="margin-top:12px"><input type="hidden" name="action" value="set_broadcast">
        <textarea name="broadcast" placeholder="Сообщение для всех пользователей"><?= htmlspecialchars($db['settings']['broadcast']??'') ?></textarea>
        <button class="btn btn-primary" type="submit" style="margin-top:10px">Сохранить</button></form>
    </div>

    <?php elseif ($tab==='blacklist'): ?>
    <div class="card" style="max-width:700px;margin-bottom:16px">
        <h3>Добавить в ЧС</h3>
        <form method="post" style="margin-top:12px;display:flex;gap:8px">
            <input type="hidden" name="action" value="add_blacklist">
            <input type="text" name="value" placeholder="IP или HWID" required style="flex:1">
            <input type="text" name="reason" placeholder="Причина" style="flex:1">
            <button class="btn btn-danger" type="submit"><?= ico('plus',14) ?></button>
        </form>
    </div>
    
    <div class="card">
        <h3>Чёрный список (<?= count($db['blacklist']) ?>)</h3>
        <div style="margin-top:12px">
        <?php if(empty($db['blacklist'])): ?>
        <p style="color:var(--muted)">Пусто</p>
        <?php else: foreach($db['blacklist'] as $val=>$info): ?>
        <div class="log-item">
            <span class="t"><?= date('d.m H:i',$info['time']??time()) ?></span>
            <span style="flex:1"><code><?= htmlspecialchars($val) ?></code> <?= !empty($info['reason'])?'· '.htmlspecialchars($info['reason']):'' ?></span>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="remove_blacklist">
                <input type="hidden" name="value" value="<?= htmlspecialchars($val) ?>">
                <button class="btn btn-sm btn-danger" type="submit"><?= ico('x',12) ?></button>
            </form>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>

    <?php elseif ($tab==='whitelist'): ?>
    <div class="card" style="max-width:700px;margin-bottom:16px">
        <h3>Добавить в белый список</h3>
        <form method="post" style="margin-top:12px;display:flex;gap:8px">
            <input type="hidden" name="action" value="add_whitelist">
            <input type="text" name="value" placeholder="IP или HWID" required style="flex:1">
            <input type="text" name="note" placeholder="Заметка" style="flex:1">
            <button class="btn btn-success" type="submit"><?= ico('plus',14) ?></button>
        </form>
    </div>
    
    <div class="card">
        <h3>Белый список (<?= count($db['whitelist']) ?>)</h3>
        <div style="margin-top:12px">
        <?php if(empty($db['whitelist'])): ?>
        <p style="color:var(--muted)">Пусто</p>
        <?php else: foreach($db['whitelist'] as $val=>$info): ?>
        <div class="log-item">
            <span class="t"><?= date('d.m H:i',$info['time']??time()) ?></span>
            <span style="flex:1"><code><?= htmlspecialchars($val) ?></code> <?= !empty($info['note'])?'· '.htmlspecialchars($info['note']):'' ?></span>
            <form method="post" style="display:contents">
                <input type="hidden" name="action" value="remove_whitelist">
                <input type="hidden" name="value" value="<?= htmlspecialchars($val) ?>">
                <button class="btn btn-sm btn-danger" type="submit"><?= ico('x',12) ?></button>
            </form>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>

    <?php elseif ($tab==='github'): ?>
    <div class="card" style="max-width:700px">
        <h3><?= ico('github',18) ?> GitHub Sync</h3>
        <p style="color:var(--muted);font-size:12px;margin:12px 0">
            Статус: <b style="color:<?= $githubOk?'var(--success)':'var(--danger)' ?>"><?= $githubOk?'Настроен':'Не настроен' ?></b><br>
            Repo: <code><?= htmlspecialchars($ghRepo?:'—') ?></code><br>
            Path: <code><?= htmlspecialchars($ghPath) ?></code><br>
            Branch: <code><?= htmlspecialchars($ghBranch) ?></code>
        </p>
        <div style="display:flex;gap:8px;margin-top:12px">
            <form method="post" style="display:contents"><input type="hidden" name="action" value="github_force_push">
            <button class="btn btn-primary" type="submit"><?= ico('download',14) ?> Force Push</button></form>
            <form method="post" style="display:contents"><input type="hidden" name="action" value="github_force_pull">
            <button class="btn btn-dark" type="submit"><?= ico('download',14) ?> Force Pull</button></form>
        </div>
    </div>

    <?php elseif ($tab==='settings'): ?>
    <div class="card" style="max-width:700px">
        <h3>Основные настройки</h3>
        <form method="post" style="margin-top:12px;display:flex;flex-direction:column;gap:10px">
            <input type="hidden" name="action" value="save_settings">
            
            <div class="key-view-row"><span class="lbl">Version</span><input type="text" name="version" value="<?= htmlspecialchars($db['settings']['version']) ?>" style="width:120px"></div>
            <div class="key-view-row"><span class="lbl">Checksum</span><input type="text" name="checksum" value="<?= htmlspecialchars($db['settings']['checksum']) ?>" style="width:240px"></div>
            <div class="key-view-row"><span class="lbl">Download URL</span><input type="text" name="download_url" value="<?= htmlspecialchars($db['settings']['download_url']) ?>" style="width:240px"></div>
            <div class="key-view-row"><span class="lbl">Префикс ключей</span><input type="text" name="key_prefix" value="<?= htmlspecialchars($db['settings']['key_prefix']??'LORI') ?>" style="width:120px"></div>
            <div class="key-view-row"><span class="lbl">Maintenance msg</span><input type="text" name="maintenance_msg" value="<?= htmlspecialchars($db['settings']['maintenance_msg']??'') ?>" style="width:240px"></div>
            <div class="key-view-row"><span class="lbl">Emergency msg</span><input type="text" name="emergency_msg" value="<?= htmlspecialchars($db['settings']['emergency_msg']??'') ?>" style="width:240px"></div>
            <div class="key-view-row"><span class="lbl">Discord webhook</span><input type="text" name="discord_webhook" value="<?= htmlspecialchars($db['settings']['discord_webhook']??'') ?>" style="width:240px"></div>
            
            <hr style="border:0;border-top:1px solid var(--border);margin:8px 0">
            
            <div class="key-view-row"><span class="lbl">Сбросы HWID</span><input type="number" name="user_hwid_resets" value="<?= (int)$db['settings']['user_hwid_resets'] ?>" style="width:70px"></div>
            <div class="key-view-row"><span class="lbl">Заморозки/нед</span><input type="number" name="user_freeze_per_week" value="<?= (int)$db['settings']['user_freeze_per_week'] ?>" style="width:70px"></div>
            <div class="key-view-row"><span class="lbl">Макс. попыток входа</span><input type="number" name="max_login_attempts" value="<?= (int)$db['settings']['max_login_attempts'] ?>" style="width:70px"></div>
            <div class="key-view-row"><span class="lbl">Детектор шаринга (N IP)</span><input type="number" name="share_detect_limit" value="<?= (int)$db['settings']['share_detect_limit'] ?>" style="width:70px"></div>
            <div class="key-view-row"><span class="lbl">Grace период (часы)</span><input type="number" name="grace_period_hours" value="<?= (int)$db['settings']['grace_period_hours'] ?>" style="width:70px"></div>
            <div class="key-view-row"><span class="lbl">Предупреждение за N дней</span><input type="number" name="warning_days_before" value="<?= (int)$db['settings']['warning_days_before'] ?>" style="width:70px"></div>
            <div class="key-view-row"><span class="lbl">Лимит IP на ключ</span><input type="number" name="ip_limit_per_key" value="<?= (int)$db['settings']['ip_limit_per_key'] ?>" style="width:70px"></div>
            <div class="key-view-row"><span class="lbl">Дефолт устройств</span><input type="number" name="default_max_devices" value="<?= (int)$db['settings']['default_max_devices'] ?>" style="width:70px"></div>
            <div class="key-view-row"><span class="lbl">Дефолт часов</span><input type="number" name="default_hours" value="<?= (int)$db['settings']['default_hours'] ?>" style="width:70px"></div>
            
            <hr style="border:0;border-top:1px solid var(--border);margin:8px 0">
            
            <label><input type="checkbox" name="purchases_enabled" value="1" <?= !empty($db['settings']['purchases_enabled'])?'checked':'' ?>> Покупки в боте</label>
            <label><input type="checkbox" name="github_sync" value="1" <?= !empty($db['settings']['github_sync'])?'checked':'' ?>> GitHub auto-sync</label>
            <label><input type="checkbox" name="auto_cleanup" value="1" <?= !empty($db['settings']['auto_cleanup'])?'checked':'' ?>> Авто-очистка истёкших</label>
            <label><input type="checkbox" name="notify_new_activation" value="1" <?= !empty($db['settings']['notify_new_activation'])?'checked':'' ?>> Уведомления о новых активациях</label>
            <label><input type="checkbox" name="telegram_notify_admin" value="1" <?= !empty($db['settings']['telegram_notify_admin'])?'checked':'' ?>> Telegram уведомления админу</label>
            <label><input type="checkbox" name="allow_self_reset" value="1" <?= !empty($db['settings']['allow_self_reset'])?'checked':'' ?>> Самост. сброс HWID</label>
            <label><input type="checkbox" name="allow_self_freeze" value="1" <?= !empty($db['settings']['allow_self_freeze'])?'checked':'' ?>> Самост. заморозка</label>
            <label><input type="checkbox" name="enable_promo" value="1" <?= !empty($db['settings']['enable_promo'])?'checked':'' ?>> Промокоды</label>
            <label><input type="checkbox" name="enable_achievements" value="1" <?= !empty($db['settings']['enable_achievements'])?'checked':'' ?>> Достижения</label>
            <label><input type="checkbox" name="enable_folders" value="1" <?= !empty($db['settings']['enable_folders'])?'checked':'' ?>> Папки</label>
            <label><input type="checkbox" name="enable_tags" value="1" <?= !empty($db['settings']['enable_tags'])?'checked':'' ?>> Теги</label>
            <label><input type="checkbox" name="enable_qr" value="1" <?= !empty($db['settings']['enable_qr'])?'checked':'' ?>> QR-коды</label>
            <label><input type="checkbox" name="enable_export" value="1" <?= !empty($db['settings']['enable_export'])?'checked':'' ?>> Экспорт</label>
            <label><input type="checkbox" name="sounds_enabled" value="1" <?= !empty($db['settings']['sounds_enabled'])?'checked':'' ?>> Звуки</label>
            <label><input type="checkbox" name="show_online" value="1" <?= !empty($db['settings']['show_online'])?'checked':'' ?>> Показывать онлайн</label>
            <label><input type="checkbox" name="show_access_log" value="1" <?= !empty($db['settings']['show_access_log'])?'checked':'' ?>> Показывать логи входов</label>
            <label><input type="checkbox" name="show_admin_history" value="1" <?= !empty($db['settings']['show_admin_history'])?'checked':'' ?>> История действий</label>
            <label><input type="checkbox" name="enable_whitelist" value="1" <?= !empty($db['settings']['enable_whitelist'])?'checked':'' ?>> Белый список</label>
            <label><input type="checkbox" name="whitelist_only" value="1" <?= !empty($db['settings']['whitelist_only'])?'checked':'' ?>> Только белый список</label>
            <label><input type="checkbox" name="auto_ban_on_share" value="1" <?= !empty($db['settings']['auto_ban_on_share'])?'checked':'' ?>> Авто-бан при шаре</label>
            <label><input type="checkbox" name="maintenance_mode" value="1" <?= !empty($db['settings']['maintenance_mode'])?'checked':'' ?>> Режим обслуживания</label>
            <label><input type="checkbox" name="killswitch" value="1" <?= !empty($db['settings']['killswitch'])?'checked':'' ?>> Killswitch</label>
            <label><input type="checkbox" name="demo_mode" value="1" <?= !empty($db['settings']['demo_mode'])?'checked':'' ?>> Демо-режим</label>
            
            <button class="btn btn-primary" type="submit" style="margin-top:10px;width:100%;justify-content:center;padding:12px">Сохранить</button>
        </form>
    </div>

    <?php elseif ($tab==='theme'): ?>
    <div class="card" style="max-width:700px">
        <h3><?= ico('palette',18) ?> Тема</h3>
        <form method="post" style="margin-top:12px;display:flex;flex-direction:column;gap:12px">
            <input type="hidden" name="action" value="set_panel_bg">
            <div class="key-view-row"><span class="lbl">URL фона</span><input type="text" name="panel_bg" value="<?= htmlspecialchars($panelBg) ?>" style="flex:1"></div>
            <div class="key-view-row"><span class="lbl">Цвет фона</span><input type="color" name="panel_bg_color" value="<?= htmlspecialchars($panelBgColor) ?>"></div>
            <div class="key-view-row"><span class="lbl">Акцент</span><input type="color" name="panel_accent" value="<?= htmlspecialchars($accent) ?>"></div>
            <div class="key-view-row"><span class="lbl">Режим</span>
                <select name="theme_mode">
                    <option value="dark" <?= $themeMode==='dark'?'selected':'' ?>>Тёмный</option>
                    <option value="light" <?= $themeMode==='light'?'selected':'' ?>>Светлый</option>
                </select>
            </div>
            <div class="key-view-row"><span class="lbl">Blur</span><input type="range" name="panel_blur" min="0" max="40" value="<?= $blur ?>" style="flex:1"></div>
            <div class="key-view-row"><span class="lbl">Overlay</span><input type="range" name="panel_overlay" min="0.3" max="0.95" step="0.01" value="<?= htmlspecialchars($overlay) ?>" style="flex:1"></div>
            <label><input type="checkbox" name="compact_mode" value="1" <?= $compactMode?'checked':'' ?>> Компактный режим</label>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:12px">Применить</button>
        </form>
    </div>

    <?php endif; ?>
</main>
</div>

<script>
// Mobile menu
document.addEventListener('click',e=>{
    if(window.innerWidth<=900 && !e.target.closest('.sidebar') && !e.target.closest('.mobile-menu-btn')){
        document.querySelector('.sidebar').classList.remove('open');
    }
});

// Keyboard shortcuts
document.addEventListener('keydown',e=>{
    if(e.ctrlKey || e.metaKey){
        if(e.key==='k'){e.preventDefault();document.querySelector('.search-bar')?.focus()}
        if(e.key==='n'){e.preventDefault();location.href='?admin&tab=generate'}
    }
    if(e.key==='/' && !e.target.matches('input,textarea')){e.preventDefault();document.querySelector('.search-bar')?.focus()}
});

// Sound on purchase (заглушка)
<?php if(!empty($db['settings']['sounds_enabled'])): ?>
function playSound(){
    try{
        const ctx=new(window.AudioContext||window.webkitAudioContext)();
        const osc=ctx.createOscillator();
        const gain=ctx.createGain();
        osc.connect(gain);gain.connect(ctx.destination);
        osc.frequency.value=800;gain.gain.value=0.1;
        osc.start();osc.stop(ctx.currentTime+0.1);
    }catch(e){}
}
<?php endif; ?>
</script>
</body></html>
<?php
    exit;
}

// ============================================================
// 9. TELEGRAM BOT
// ============================================================
$content = file_get_contents('php://input');
$update = json_decode($content, true);
if (!$update) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>LORI</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f172a;color:#6366f1;font-family:Inter,system-ui;letter-spacing:6px;font-weight:800;font-size:32px}</style></head><body>LORI</body></html>';
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
    $newKey=generateKey('premium');
    $db['keys'][$newKey]=makeKeyData($duration,1,'premium',$chatId);
    saveDb(); addLog("Bought $newKey by $chatId");
    $db['stats']['purchases']=($db['stats']['purchases']??0)+1;
    $db['stats']['stars']=($db['stats']['stars']??0)+($update['message']['successful_payment']['total_amount']??0);
    saveDb();
    sendMessage($chatId,"<b>LORI</b>\n✅ Оплата OK.\n\n<code>$newKey</code>\n\nСбросы HWID: ".($db['settings']['user_hwid_resets']??2)."\nЗаморозки: ".($db['settings']['user_freeze_per_week']??2)." / неделя");
    if (!empty($db['settings']['telegram_notify_admin'])) {
        @file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$adminId&text=".urlencode("💰 Новая покупка\nКлюч: $newKey\nЮзер: $chatId"));
    }
    exit;
}
if (isset($update['message'])) {
    $chatId=(int)$update['message']['chat']['id'];
    $text=trim($update['message']['text']??'');
    $isAdmin=($chatId===$adminId);
    
    if ($isAdmin && strpos($text,'/gen ')===0) {
        $a=explode(' ',$text);
        $hours=(int)($a[1]??24);
        $max=(int)($a[2]??1);
        $level=$a[3]??'premium';
        $name=$a[4]??'';
        $duration=$hours===0?0:$hours*3600;
        if($name){
            if(isset($db['keys'][$name])){sendMessage($chatId,'Занято');exit;}
            $db['keys'][$name]=makeKeyData($duration,$max,$level,0,$name,true);
            $newKey=$name;
        } else {
            $newKey=generateKey($level);
            $db['keys'][$newKey]=makeKeyData($duration,$max,$level);
        }
        saveDb(); sendMessage($chatId,"<code>$newKey</code>"); exit;
    }
    
    if ($text==='/start' || $text==='/menu') {
        $kb=['inline_keyboard'=>[
            [['text'=>'1ч · 10★','callback_data'=>'buy_1_1']],
            [['text'=>'24ч · 25★','callback_data'=>'buy_24_1']],
            [['text'=>'7д · 75★','callback_data'=>'buy_168_1']],
            [['text'=>'30д · 125★','callback_data'=>'buy_720_1']],
            [['text'=>'Навсегда · 400★','callback_data'=>'buy_0_1']],
            [['text'=>'🔑 Мои ключи','callback_data'=>'my_keys'],['text'=>'✨ AURA','callback_data'=>'aura_info']],
            [['text'=>'📊 Статистика','callback_data'=>'stats_info'],['text'=>'❓ Лимиты','callback_data'=>'limits_info']]
        ]];
        if($isAdmin) $kb['inline_keyboard'][]=[['text'=>'⚙️ Админ','callback_data'=>'admin_panel']];
        sendMessage($chatId, $db['settings']['bot_welcome'] ?? "<b>LORI</b>\nВыберите срок:", $kb);
    }
    
    if ($text==='/stats' && $isAdmin) {
        $total=count($db['keys']);
        $active=0;
        foreach($db['keys'] as $kd) if(keyStatus($kd)==='active') $active++;
        sendMessage($chatId,"<b>📊 Статистика</b>\n\nВсего ключей: $total\nАктивных: $active\nОнлайн: ".count($db['online'])."\nПокупок: ".($db['stats']['purchases']??0)."\nЗвёзд: ".($db['stats']['stars']??0));
        exit;
    }
}
if (isset($update['callback_query'])) {
    $cq=$update['callback_query'];
    $chatId=(int)$cq['message']['chat']['id'];
    $data=$cq['data'];
    $msgId=$cq['message']['message_id'];
    $cqId=$cq['id'];
    $isAdmin=($chatId===$adminId);
    
    if (strpos($data,'buy_')===0) {
        if(empty($db['settings']['purchases_enabled'])){answerCallback($cqId,'Покупки выкл',true);exit;}
        $h=(int)explode('_',$data)[1];
        $m=[1=>10,24=>25,168=>75,720=>125,0=>400];
        sendInvoice($chatId,'LORI','Access',"sub_{$h}_1",$m[$h]??25);
        answerCallback($cqId); exit;
    }
    if ($data==='aura_info') {
        answerCallback($cqId);
        sendMessage($chatId,"<b>✨ AURA</b>\n\nСкоро...");
        exit;
    }
    if ($data==='stats_info') {
        answerCallback($cqId);
        $total=count($db['keys']);
        $active=0;
        foreach($db['keys'] as $kd) if(keyStatus($kd)==='active') $active++;
        sendMessage($chatId,"<b>📊 Статистика</b>\n\nВсего ключей: $total\nАктивных: $active");
        exit;
    }
    if ($data==='limits_info') {
        answerCallback($cqId);
        sendMessage($chatId,"<b>❓ Лимиты</b>\n\nСброс HWID: <b>".((int)$db['settings']['user_hwid_resets'])."</b>\nЗаморозка: <b>".((int)$db['settings']['user_freeze_per_week'])."</b> / неделя");
        exit;
    }
    if ($data==='my_keys') {
        $f=false;
        foreach($db['keys'] as $k=>$kd){
            if(($kd['owner_tg']??0)!=$chatId) continue;
            $f=true;
            $used=count($kd['activations']??[]);
            $max=$kd['max']??1;
            $resetLeft=$kd['reset_left']??0;
            $w=weekId();
            $fu=$kd['freeze_week'][$w]??0;
            $fl=(int)$db['settings']['user_freeze_per_week'];
            $st=!empty($kd['is_frozen'])?'Заморожен':((($kd['expires']??0)==0)?'∞':daysLeft($kd).'д');
            
            $kb=['inline_keyboard'=>[
                [['text'=>"🔄 Сброс HWID ($resetLeft)",'callback_data'=>'user_reset_'.$k]],
                [['text'=>(!empty($kd['is_frozen'])?"🔓 Разморозить":"🔒 Заморозить")." ($fu/$fl)",'callback_data'=>'user_freeze_'.$k]]
            ]];
            sendMessage($chatId,"<b>LORI</b>\n<code>$k</code>\n$st · $used/$max\nСбросы: $resetLeft · Freeze: $fu/$fl", $kb);
        }
        if(!$f) sendMessage($chatId,'У вас нет ключей');
        answerCallback($cqId); exit;
    }
    if (strpos($data,'user_reset_')===0) {
        $key=str_replace('user_reset_','',$data);
        if(isset($db['keys'][$key])&&($db['keys'][$key]['owner_tg']??0)==$chatId){
            if(empty($db['settings']['allow_self_reset'])){answerCallback($cqId,'Функция отключена',true);exit;}
            if(($db['keys'][$key]['reset_left']??0)>0){
                $db['keys'][$key]['activations']=[];
                $db['keys'][$key]['reset_left']--;
                saveDb();
                answerCallback($cqId,'OK, осталось '.$db['keys'][$key]['reset_left']);
            } else answerCallback($cqId,'Лимит исчерпан',true);
        }
        exit;
    }
    if (strpos($data,'user_freeze_')===0) {
        $key=str_replace('user_freeze_','',$data);
        if(isset($db['keys'][$key])&&($db['keys'][$key]['owner_tg']??0)==$chatId){
            if(empty($db['settings']['allow_self_freeze'])){answerCallback($cqId,'Функция отключена',true);exit;}
            $kd=&$db['keys'][$key];
            if(!empty($kd['is_frozen'])){
                $kd['is_frozen']=false;
                saveDb();
                answerCallback($cqId,'Разморожен');
            } else {
                if(!canUserFreeze($kd)){answerCallback($cqId,'Лимит на неделю',true);exit;}
                $kd['is_frozen']=true;
                registerUserFreeze($kd);
                saveDb();
                answerCallback($cqId,'Заморожен');
            }
        }
