<?php
error_reporting(0);
header('Content-Type: text/html; charset=utf-8');

$file = "database.json";

// Загружаем базу ключей
$db = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($db)) $db = [];

$action = $_GET['action'] ?? '';
$key = $_POST['key'] ?? $_GET['key'] ?? '';
$hwid = $_POST['hwid'] ?? $_GET['hwid'] ?? '';

// --- 1. АВТОРИЗАЦИЯ ДЛЯ СКРИПТА GAMEGUARDIAN ---
if ($action === "check") {
    if (empty($key)) {
        echo "Введите ключ!";
        exit;
    }

    if (!isset($db[$key])) {
        echo "Неверный ключ!";
        exit;
    }

    $keyData = $db[$key];

    // Проверка срока действия (время в секундах)
    if ($keyData['expires'] != 0 && time() > $keyData['expires']) {
        echo "Срок действия ключа истек!";
        exit;
    }

    // Привязка по HWID
    if (empty($keyData['hwid'])) {
        // Первый вход — привязываем устройство
        $db[$key]['hwid'] = $hwid;
        file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
        echo "SUCCESS";
    } else {
        // Проверяем, совпадает ли HWID
        if ($keyData['hwid'] === $hwid) {
            echo "SUCCESS";
        } else {
            echo "Ключ привязан к другому устройству (HWID)!";
        }
    }
    exit;
}

// --- 2. ВЕБ-ПАНЕЛЬ УПРАВЛЕНИЯ НА САЙТЕ ---
// Простая защита паролем (можешь поменять "my_admin_pass" на свой)
$adminPass = "my_admin_pass";
$inputPass = $_GET['pass'] ?? '';

if ($inputPass !== $adminPass) {
    echo "<h2>🔒 Панель управления lori ultimate</h2>";
    echo "<p>Для доступа укажите пароль в ссылке: <code>?pass=ВАШ_ПАРОЛЬ</code></p>";
    exit;
}

// Обработка создания ключа
if (isset($_GET['create'])) {
    $newKey = "LORI-" . strtoupper(substr(md5(rand()), 0, 8));
    $hours = intval($_GET['hours'] ?? 24); // Время жизни в часах (по умолчанию 24 часа)
    $expires = ($hours == 0) ? 0 : time() + ($hours * 3600); // 0 = вечный

    $db[$newKey] = [
        "expires" => $expires,
        "hwid" => ""
    ];
    file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
    header("Location: api.php?pass=" . $adminPass);
    exit;
}

// Обработка удаления ключа
if (isset($_GET['delete'])) {
    $delKey = $_GET['delete'];
    if (isset($db[$delKey])) {
        unset($db[$delKey]);
        file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
    }
    header("Location: api.php?pass=" . $adminPass);
    exit;
}

// Вывод красивой админ-панели в браузере
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Lori Ultimate Admin</title>
    <style>
        body { font-family: Arial, sans-serif; background: #121212; color: #fff; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: #1e1e1e; }
        th, td { padding: 12px; border: 1px solid #333; text-align: left; }
        th { background: #252525; }
        a.btn { background: #4CAF50; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block; margin-bottom: 15px; }
        a.del { background: #f44336; color: white; padding: 5px 10px; text-decoration: none; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>⚡ Панель управления ключами</h1>
    
    <form method="get" action="api.php" style="margin-bottom: 20px;">
        <input type="hidden" name="pass" value="<?php echo $adminPass; ?>">
        <input type="hidden" name="create" value="1">
        <label>Срок действия:</label>
        <select name="hours" style="padding: 8px; background: #333; color: #fff; border: 1px solid #555;">
            <option value="1">1 час</option>
            <option value="24" selected>24 часа (1 сутки)</option>
            <option value="168">7 дней (Неделя)</option>
            <option value="720">30 дней (Месяц)</option>
            <option value="0">Бессрочный (Вечный)</option>
        </select>
        <button type="submit" style="padding: 9px 15px; background: #2196F3; color: white; border: none; cursor: pointer; border-radius: 4px;">➕ Создать ключ</button>
    </form>
<table>
        <tr>
            <th>Ключ</th>
            <th>Срок действия до</th>
            <th>Привязанный HWID</th>
            <th>Действие</th>
        </tr>
        <?php foreach ($db as $k => $data): ?>
        <tr>
            <td><strong><?php echo $k; ?></strong></td>
            <td><?php echo ($data['expires'] == 0) ? "Бессрочно" : date("d.m.Y H:i", $data['expires']); ?></td>
            <td><?php echo empty($data['hwid']) ? "<em>Не привязан</em>" : $data['hwid']; ?></td>
            <td><a class="del" href="api.php?pass=<?php echo $adminPass; ?>&delete=<?php echo $k; ?>">Удалить</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
