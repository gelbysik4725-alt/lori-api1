<?php
error_reporting(0);
header('Content-Type: text/html; charset=utf-8');

$file = "database.json";
$db = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($db)) $db = [];

$action = $_GET['action'] ?? '';
$key = $_POST['key'] ?? $_GET['key'] ?? '';
$hwid = $_POST['hwid'] ?? $_GET['hwid'] ?? '';

// --- 1. АВТОРИЗАЦИЯ ДЛЯ СКРИПТА ---
if ($action === "check") {
    if (!isset($db[$key])) { echo "Неверный ключ!"; exit; }
    
    $keyData = $db[$key];
    if ($keyData['expires'] != 0 && time() > $keyData['expires']) { echo "Срок действия истек!"; exit; }

    if (empty($keyData['hwid'])) {
        $db[$key]['hwid'] = $hwid;
        file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
        echo "SUCCESS";
    } else {
        echo ($keyData['hwid'] === $hwid) ? "SUCCESS" : "Ключ привязан к другому HWID!";
    }
    exit;
}

// --- 2. ВЕБ-ПАНЕЛЬ ---
$adminPass = "my_admin_pass"; // Измени на свой
$inputPass = $_GET['pass'] ?? '';

if ($inputPass !== $adminPass) {
    die("Доступ запрещен.");
}

// Функции админки
if (isset($_GET['create'])) {
    $newKey = "LORI-" . strtoupper(substr(md5(rand()), 0, 8));
    $hours = intval($_GET['hours'] ?? 24);
    $db[$newKey] = ["expires" => ($hours == 0) ? 0 : time() + ($hours * 3600), "hwid" => ""];
    file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
    header("Location: ?pass=$adminPass"); exit;
}

if (isset($_GET['delete'])) {
    unset($db[$_GET['delete']]);
    file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
    header("Location: ?pass=$adminPass"); exit;
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Lori Admin</title></head>
<body style="background:#121212; color:#fff; font-family:Arial;">
    <h1>⚡ Панель управления</h1>
    <form method="get" action="">
        <input type="hidden" name="pass" value="<?php echo $adminPass; ?>">
        <input type="hidden" name="create" value="1">
        <select name="hours">
            <option value="24">24 часа</option>
            <option value="168">7 дней</option>
            <option value="0">Бессрочно</option>
        </select>
        <button type="submit">➕ Создать ключ</button>
    </form>
    <table border="1" style="width:100%; border-collapse:collapse; margin-top:20px;">
        <tr><th>Ключ</th><th>До</th><th>HWID</th><th>Действие</th></tr>
        <?php foreach ($db as $k => $data): ?>
        <tr>
            <td><?php echo $k; ?></td>
            <td><?php echo ($data['expires'] == 0) ? "Бессрочно" : date("d.m.H:i", $data['expires']); ?></td>
            <td><?php echo $data['hwid'] ?: "Свободен"; ?></td>
            <td><a href="?pass=<?php echo $adminPass; ?>&delete=<?php echo $k; ?>" style="color:red;">Удалить</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
