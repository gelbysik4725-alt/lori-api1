<?php
$keysFile = "keys_db.json";
$activationsFile = "activations.json";

$keysDatabase = file_exists($keysFile) ? json_decode(file_get_contents($keysFile), true) : array();
$activations = file_exists($activationsFile) ? json_decode(file_get_contents($activationsFile), true) : array();

$inputKey = isset($_POST['key']) ? trim($_POST['key']) : '';
$inputHwid = isset($_POST['hwid']) ? trim($_POST['hwid']) : '';

if (empty($inputKey) || empty($inputHwid)) {
    echo "Ошибка: Пустые данные.";
    exit;
}

if (!array_key_exists($inputKey, $keysDatabase)) {
    echo "Неверный или несуществующий ключ!";
    exit;
}

$keyConfig = $keysDatabase[$inputKey];
$currentTime = time();

if (!isset($activations[$inputKey])) {
    $activations[$inputKey] = array(
        "hwid_list" => array($inputHwid),
        "first_used" => $currentTime
    );
    file_put_contents($activationsFile, json_encode($activations));
    echo "SUCCESS";
    exit;
}

$keyData = $activations[$inputKey];

if (in_array($inputHwid, $keyData['hwid_list'])) {
    $expirationTime = $keyData['first_used'] + $keyConfig['duration'];
    if ($currentTime > $expirationTime) {
        echo "Срок действия вашего ключа истек!";
        exit;
    }
    echo "SUCCESS";
    exit;
} else {
    if (count($keyData['hwid_list']) < $keyConfig['max_uses']) {
        $activations[$inputKey]['hwid_list'][] = $inputHwid;
        file_put_contents($activationsFile, json_encode($activations));
        echo "SUCCESS";
        exit;
    } else {
        echo "Ошибка: Превышен лимит активаций (чужое устройство)!";
        exit;
    }
}
?>

