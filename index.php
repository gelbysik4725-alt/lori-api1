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

    $hwids = $keyData['hwids'] ?? [];
    $maxActivations = intval($keyData['max'] ?? 1);

    // Если устройство уже есть в списке активированных — пускаем сразу
    if (in_array($hwid, $hwids)) {
        echo "SUCCESS";
        exit;
    }

    // Если устройство новое, но лимит еще не исчерпан — добавляем его
    if (count($hwids) < $maxActivations) {
        $db[$key]['hwids'][] = $hwid;
        file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
        echo "SUCCESS";
    } else {
        echo "Превышен лимит активаций для этого ключа!";
    }
    exit;
}

// --- 2. ВЕБ-ПАНЕЛЬ ---
$adminPass = "my_admin_pass"; // Измени на свой
$inputPass = $_GET['pass'] ?? '';

if ($inputPass !== $adminPass) {
    die("Доступ запрещен.");
}

// Создание ключа с выбором лимита
if (isset($_GET['create'])) {
    $newKey = "LORI-" . strtoupper(substr(md5(rand()), 0, 8));
    $hours = intval($_GET['hours'] ?? 24);
    $max = intval($_GET['max'] ?? 1); // Лимит активаций (по умолчанию 1)

    $db[$newKey] = [
        "expires" => ($hours == 0) ? 0 : time() + ($hours * 3600),
        "max" => $max,
        "hwids" => []
    ];
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
<body style="background:#121212; color:#fff; font-family:Arial; padding: 20px;">
    <h1>⚡ Панель управления</h1>
    <form method="get" action="">
        <input type="hidden" name="pass" value="<?php echo $adminPass; ?>">
        <input type="hidden" name="create" value="1">
        
        <label>Срок:</label>
        <select name="hours">
            <option value="24">24 часа</option>
            <option value="168">7 дней</option>
            <option value="720">30 дней</option>
            <option value="0">Бессрочно</option>
        </select>

        <label style="margin-left: 10px;">Лимит устройств:</label>
        <select name="max">
            <option value="1">1 устройство</option>
            <option value="2">2 устройства</option>
            <option value="3">3 устройства</option>
            <option value="5">5 устройств</option>
            <option value="10">10 устройств</option>
        </select>

        <button type="submit" style="margin-left: 10px; padding: 5px 10px;">➕ Создать ключ</button>
    </form>

    <table border="1" style="width:100%; border-collapse:collapse; margin-top:20px; border-color:#333;">
        <tr style="background:#222;">
            <th>Ключ</th>
            <th>Срок действия</th>
            <th>Лимит / Использовано</th>
            <th>Привязанные HWID</th>
            <th>Действие</th>
        </tr>
        <?php foreach ($db as $k => $data): 
            $hwids = $data['hwids'] ?? [];
            $usedCount = count($hwids);
            $maxLimit = $data['max'] ?? 1;
        ?>
        <tr>
            <td style="padding: 8px;"><?php echo $k; ?></td>
            <td style="padding: 8px;"><?php echo ($data['expires'] == 0) ? "Бессрочно" : date("d.m H:i", $data['expires']); ?></td>
    <td style="padding: 8px;"><?php echo "$usedCount из $maxLimit"; ?></td>
            <td style="padding: 8px; font-size: 12px; color: #aaa;"><?php echo empty($hwids) ? "<em>Свободен</em>" : implode("<br>", $hwids); ?></td>
            <td style="padding: 8px;"><a href="?pass=<?php echo $adminPass; ?>&delete=<?php echo $k; ?>" style="color:#ff5555;">Удалить</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
                                          </html>
