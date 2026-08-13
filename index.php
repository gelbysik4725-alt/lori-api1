} elseif ($data === 'admin_panel' && $chatId === intval($adminId)) {
        $text = "👑 Панель администратора\n\nУправление ключами:";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ Создать ключ (24ч)', 'callback_data' => 'adm_create_24']],
                [['text' => '➕ Создать ключ (7 дней)', 'callback_data' => 'adm_create_168']],
                [['text' => '« На главную', 'callback_data' => 'back_home']]
            ]
        ];
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => $keyboard]);
    } elseif ($data === 'adm_create_24' && $chatId === intval($adminId)) {
        $newKey = "LORI-" . strtoupper(substr(md5(rand() . time()), 0, 10));
        sendMessage($chatId, "✅ Сгенерирован ключ (24 часа):\n$newKey");
    } elseif ($data === 'adm_create_168' && $chatId === intval($adminId)) {
        $newKey = "LORI-" . strtoupper(substr(md5(rand() . time()), 0, 10));
        sendMessage($chatId, "✅ Сгенерирован ключ (7 дней):\n$newKey");
    } elseif ($data === 'back_home') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '💳 Купить ключ (24 часа) - 50 ⭐️', 'callback_data' => 'buy_24']],
                [['text' => '💎 Купить ключ (7 дней) - 150 ⭐️', 'callback_data' => 'buy_168']]
            ]
        ];
        if ($chatId === intval($adminId)) {
            $keyboard['inline_keyboard'][] = [['text' => '👑 Админ-панель', 'callback_data' => 'admin_panel']];
        }
        tgRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "👋 Добро пожаловать в магазин Lori Store!\n\nВыберите нужный товар для покупки за Telegram Stars:", 'parse_mode' => 'Markdown', 'reply_markup' => $keyboard]);
    }
}
?>
