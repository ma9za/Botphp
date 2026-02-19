<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    initDatabase($pdo);

    return $pdo;
}

function initDatabase(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS force_channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        link TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS buttons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER NOT NULL DEFAULT 0,
        text TEXT NOT NULL,
        emoji TEXT NOT NULL DEFAULT "",
        icon_custom_emoji_id TEXT NOT NULL DEFAULT "",
        style TEXT NOT NULL DEFAULT "",
        action_type TEXT NOT NULL DEFAULT "menu",
        menu_text TEXT NOT NULL DEFAULT "",
        content_id INTEGER NULL,
        content_ids TEXT NOT NULL DEFAULT "",
        url TEXT NOT NULL DEFAULT "",
        popup_text TEXT NOT NULL DEFAULT "",
        web_app_url TEXT NOT NULL DEFAULT "",
        row_no INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0
    )');

    migrateButtonsTable($pdo);
    migrateButtonsExtras($pdo);

    $pdo->exec('CREATE TABLE IF NOT EXISTS content_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_chat_id INTEGER NOT NULL,
        admin_message_id INTEGER NOT NULL,
        media_group_id TEXT NULL,
        content_type TEXT NOT NULL,
        file_id TEXT NULL,
        text_value TEXT NULL,
        text_entities TEXT NULL,
        caption TEXT NULL,
        caption_entities TEXT NULL,
        created_at INTEGER NOT NULL,
        UNIQUE(admin_chat_id, admin_message_id)
    )');

    migrateContentTable($pdo);


    $pdo->exec('CREATE TABLE IF NOT EXISTS blocked_users (
        user_id INTEGER PRIMARY KEY,
        blocked_at INTEGER NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_reply_map (
        admin_message_id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        created_at INTEGER NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS bot_commands (
        command TEXT PRIMARY KEY,
        action_type TEXT NOT NULL DEFAULT "new_message",
        content_id INTEGER NULL,
        content_ids TEXT NOT NULL DEFAULT "",
        menu_target_id INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL
    )');
    $defaults = [
        'menu_message' => "مرحبًا بك 👋\nاختر من القائمة:",
        'force_join_message' => 'يجب الاشتراك في القنوات أولًا ثم اضغط تحقق.',
        'force_join_enabled' => '0',
        'welcome_content_id' => '0',
        'nav_back_label' => '⬅️ رجوع',
        'nav_home_label' => '🏠 الرئيسية',
        'nav_back_style' => '',
        'nav_home_style' => '',
        'nav_back_icon_custom_emoji_id' => '',
        'nav_home_icon_custom_emoji_id' => '',
        'album_nav_message' => '↩️ استخدم الأزرار بالأسفل للتنقل',
        'forward_user_messages_to_admin' => '0',
        'notify_admin_new_user' => '0',
        'support_mode_enabled' => '0',
    ];

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key,value) VALUES (:key,:value)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([':key' => $k, ':value' => $v]);
    }

    if (!file_exists(USERS_FILE)) {
        file_put_contents(USERS_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function migrateButtonsTable(PDO $pdo): void
{
    $cols = $pdo->query('PRAGMA table_info(buttons)')->fetchAll();
    $names = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $cols);
    $need = ['emoji', 'icon_custom_emoji_id', 'style', 'action_type', 'menu_text', 'content_id', 'content_ids', 'url', 'popup_text', 'web_app_url', 'row_no'];
    $ok = true;
    foreach ($need as $n) {
        if (!in_array($n, $names, true)) {
            $ok = false;
            break;
        }
    }
    if ($ok) {
        return;
    }

    $pdo->exec('ALTER TABLE buttons RENAME TO buttons_old');
    $pdo->exec('CREATE TABLE buttons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER NOT NULL DEFAULT 0,
        text TEXT NOT NULL,
        emoji TEXT NOT NULL DEFAULT "",
        icon_custom_emoji_id TEXT NOT NULL DEFAULT "",
        style TEXT NOT NULL DEFAULT "",
        action_type TEXT NOT NULL DEFAULT "menu",
        menu_text TEXT NOT NULL DEFAULT "",
        content_id INTEGER NULL,
        content_ids TEXT NOT NULL DEFAULT "",
        url TEXT NOT NULL DEFAULT "",
        popup_text TEXT NOT NULL DEFAULT "",
        web_app_url TEXT NOT NULL DEFAULT "",
        row_no INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0
    )');

    $old = array_flip($names);
    $typeExpr = isset($old['action_type']) ? 'action_type' : (isset($old['type']) ? 'CASE WHEN type="submenu" THEN "menu" ELSE "new_message" END' : '"menu"');
    $emojiExpr = isset($old['emoji']) ? 'emoji' : '""';
    $iconExpr = isset($old['icon_custom_emoji_id']) ? 'icon_custom_emoji_id' : '""';
    $styleExpr = isset($old['style']) ? 'style' : '""';
    $menuTextExpr = isset($old['menu_text']) ? 'menu_text' : (isset($old['message_text']) ? 'message_text' : '""');
    $contentExpr = isset($old['content_id']) ? 'content_id' : 'NULL';
    $contentIdsExpr = isset($old['content_ids']) ? 'content_ids' : '""';
    $urlExpr = isset($old['url']) ? 'url' : '""';
    $popupExpr = isset($old['popup_text']) ? 'popup_text' : '""';
    $webAppExpr = isset($old['web_app_url']) ? 'web_app_url' : '""';
    $rowExpr = isset($old['row_no']) ? 'row_no' : '0';

    $pdo->exec("INSERT INTO buttons (id,parent_id,text,emoji,icon_custom_emoji_id,style,action_type,menu_text,content_id,content_ids,url,popup_text,web_app_url,row_no,sort_order)
      SELECT id,parent_id,text,$emojiExpr,$iconExpr,$styleExpr,$typeExpr,$menuTextExpr,$contentExpr,$contentIdsExpr,$urlExpr,$popupExpr,$webAppExpr,$rowExpr,sort_order FROM buttons_old");

    $pdo->exec('DROP TABLE buttons_old');
}


function migrateButtonsExtras(PDO $pdo): void
{
    $cols = $pdo->query('PRAGMA table_info(buttons)')->fetchAll();
    $names = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $cols);
    $extras = [
        'content_ids' => 'TEXT NOT NULL DEFAULT ""',
        'url' => 'TEXT NOT NULL DEFAULT ""',
        'popup_text' => 'TEXT NOT NULL DEFAULT ""',
        'web_app_url' => 'TEXT NOT NULL DEFAULT ""',
    ];
    foreach ($extras as $name => $def) {
        if (!in_array($name, $names, true)) {
            $pdo->exec('ALTER TABLE buttons ADD COLUMN ' . $name . ' ' . $def);
        }
    }
}

function migrateContentTable(PDO $pdo): void
{
    $cols = $pdo->query('PRAGMA table_info(content_items)')->fetchAll();
    $names = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $cols);

    if (!in_array('media_group_id', $names, true)) {
        $pdo->exec('ALTER TABLE content_items ADD COLUMN media_group_id TEXT NULL');
    }
    if (!in_array('text_entities', $names, true)) {
        $pdo->exec('ALTER TABLE content_items ADD COLUMN text_entities TEXT NULL');
    }
    if (!in_array('caption_entities', $names, true)) {
        $pdo->exec('ALTER TABLE content_items ADD COLUMN caption_entities TEXT NULL');
    }
}

function telegramApi(string $method, array $data = []): array
{
    if (BOT_TOKEN === '') {
        return ['ok' => false, 'description' => 'BOT_TOKEN is empty'];
    }

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 25,
        ],
    ];

    $res = @file_get_contents(BOT_API_URL . $method, false, stream_context_create($opts));
    if ($res === false) {
        return ['ok' => false, 'description' => 'Request failed'];
    }
    $decoded = json_decode($res, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'Invalid response'];
}

function sendMessage(
    int $chatId,
    string $text,
    ?array $replyMarkup = null,
    ?string $parseMode = 'HTML',
    ?array $entities = null
): array {
    $payload = ['chat_id' => $chatId, 'text' => $text];
    if ($entities !== null) {
        $payload['entities'] = json_encode($entities, JSON_UNESCAPED_UNICODE);
    } elseif ($parseMode !== null && $parseMode !== '') {
        $payload['parse_mode'] = $parseMode;
    }
    if ($replyMarkup !== null) {
        $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }
    return telegramApi('sendMessage', $payload);
}

function editMessage(
    int $chatId,
    int $messageId,
    string $text,
    ?array $replyMarkup = null,
    ?string $parseMode = 'HTML',
    ?array $entities = null
): array {
    $payload = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
    if ($entities !== null) {
        $payload['entities'] = json_encode($entities, JSON_UNESCAPED_UNICODE);
    } elseif ($parseMode !== null && $parseMode !== '') {
        $payload['parse_mode'] = $parseMode;
    }
    if ($replyMarkup !== null) {
        $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }
    return telegramApi('editMessageText', $payload);
}

function deleteMessage(int $chatId, int $messageId): void
{
    telegramApi('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
}

function forwardMessageToUser(int $targetChatId, int $fromChatId, int $messageId): array
{
    return telegramApi('forwardMessage', ['chat_id' => $targetChatId, 'from_chat_id' => $fromChatId, 'message_id' => $messageId]);
}

function copyMessageToUser(int $targetChatId, int $fromChatId, int $messageId, ?array $replyMarkup = null): array
{
    $payload = ['chat_id' => $targetChatId, 'from_chat_id' => $fromChatId, 'message_id' => $messageId];
    if ($replyMarkup !== null) {
        $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }
    return telegramApi('copyMessage', $payload);
}

function answerCallback(string $callbackId, string $text = ''): void
{
    telegramApi('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text]);
}

function getSetting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key=:key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (string) $v : $default;
}

function setSetting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings(key,value) VALUES(:key,:value)
      ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    $stmt->execute([':key' => $key, ':value' => $value]);
}

function getForceChannels(): array
{
    return db()->query('SELECT * FROM force_channels ORDER BY id DESC')->fetchAll();
}

function addForceChannel(string $chatId, string $title, string $link): bool
{
    try {
        $stmt = db()->prepare('INSERT INTO force_channels(chat_id,title,link) VALUES(:chat,:title,:link)');
        return $stmt->execute([':chat' => $chatId, ':title' => $title, ':link' => $link]);
    } catch (Throwable $e) {
        return false;
    }
}

function deleteForceChannel(int $id): void
{
    $stmt = db()->prepare('DELETE FROM force_channels WHERE id=:id');
    $stmt->execute([':id' => $id]);
}

function isForceJoinEnabled(): bool
{
    return getSetting('force_join_enabled', '0') === '1';
}

function saveUser(array $from): bool
{
    if (!isset($from['id'])) {
        return false;
    }
    if (!file_exists(USERS_FILE)) {
        file_put_contents(USERS_FILE, '[]');
    }
    $users = json_decode((string) file_get_contents(USERS_FILE), true);
    if (!is_array($users)) {
        $users = [];
    }
    $id = (int) $from['id'];
    foreach ($users as $u) {
        if ((int) ($u['user_id'] ?? 0) === $id) {
            return false;
        }
    }
    $users[] = [
        'user_id' => $id,
        'username' => (string) ($from['username'] ?? ''),
        'first_name' => (string) ($from['first_name'] ?? ''),
        'time' => time(),
    ];
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return true;
}

function getUsers(): array
{
    if (!file_exists(USERS_FILE)) {
        return [];
    }
    $users = json_decode((string) file_get_contents(USERS_FILE), true);
    return is_array($users) ? $users : [];
}

function detectMessageContent(array $message): ?array
{
    $mediaGroupId = isset($message['media_group_id']) ? (string) $message['media_group_id'] : null;

    if (isset($message['text']) && trim((string) $message['text']) !== '') {
        return [
            'content_type' => 'text',
            'file_id' => null,
            'text_value' => (string) $message['text'],
            'text_entities' => isset($message['entities']) && is_array($message['entities']) ? $message['entities'] : [],
            'caption' => null,
            'caption_entities' => [],
            'media_group_id' => $mediaGroupId,
        ];
    }
    if (isset($message['photo']) && is_array($message['photo']) && !empty($message['photo'])) {
        $last = end($message['photo']);
        return [
            'content_type' => 'photo',
            'file_id' => (string) ($last['file_id'] ?? ''),
            'text_value' => null,
            'text_entities' => [],
            'caption' => (string) ($message['caption'] ?? ''),
            'caption_entities' => isset($message['caption_entities']) && is_array($message['caption_entities']) ? $message['caption_entities'] : [],
            'media_group_id' => $mediaGroupId,
        ];
    }
    foreach (['video', 'document', 'audio', 'voice', 'animation', 'sticker'] as $t) {
        if (isset($message[$t]['file_id'])) {
            return [
                'content_type' => $t,
                'file_id' => (string) $message[$t]['file_id'],
                'text_value' => null,
                'text_entities' => [],
                'caption' => (string) ($message['caption'] ?? ''),
                'caption_entities' => isset($message['caption_entities']) && is_array($message['caption_entities']) ? $message['caption_entities'] : [],
                'media_group_id' => $mediaGroupId,
            ];
        }
    }
    return null;
}

function saveContentItem(int $adminChatId, int $adminMessageId, array $content): int
{
    $stmt = db()->prepare('INSERT OR REPLACE INTO content_items
      (id,admin_chat_id,admin_message_id,media_group_id,content_type,file_id,text_value,text_entities,caption,caption_entities,created_at)
      VALUES((SELECT id FROM content_items WHERE admin_chat_id=:chat AND admin_message_id=:msg LIMIT 1),
      :chat,:msg,:mg,:type,:file,:txt,:text_entities,:cap,:caption_entities,:created)');

    $stmt->execute([
        ':chat' => $adminChatId,
        ':msg' => $adminMessageId,
        ':mg' => $content['media_group_id'] ?? null,
        ':type' => (string) ($content['content_type'] ?? 'unknown'),
        ':file' => $content['file_id'] ?? null,
        ':txt' => $content['text_value'] ?? null,
        ':text_entities' => json_encode($content['text_entities'] ?? [], JSON_UNESCAPED_UNICODE),
        ':cap' => $content['caption'] ?? null,
        ':caption_entities' => json_encode($content['caption_entities'] ?? [], JSON_UNESCAPED_UNICODE),
        ':created' => time(),
    ]);

    $idStmt = db()->prepare('SELECT id FROM content_items WHERE admin_chat_id=:chat AND admin_message_id=:msg LIMIT 1');
    $idStmt->execute([':chat' => $adminChatId, ':msg' => $adminMessageId]);
    return (int) ($idStmt->fetchColumn() ?: 0);
}

function getContentItem(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM content_items WHERE id=:id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function getContentItems(): array
{
    return db()->query('SELECT * FROM content_items ORDER BY id DESC')->fetchAll();
}

function getContentAlbumItems(int $adminChatId, string $mediaGroupId): array
{
    $stmt = db()->prepare('SELECT * FROM content_items WHERE admin_chat_id=:chat AND media_group_id=:mg ORDER BY admin_message_id ASC');
    $stmt->execute([':chat' => $adminChatId, ':mg' => $mediaGroupId]);
    return $stmt->fetchAll();
}

function deleteContentItem(int $id): void
{
    $stmt = db()->prepare('DELETE FROM content_items WHERE id=:id');
    $stmt->execute([':id' => $id]);
}

function getButton(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM buttons WHERE id=:id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function getButtonsByParent(int $parentId = 0): array
{
    $stmt = db()->prepare('SELECT * FROM buttons WHERE parent_id=:parent ORDER BY row_no ASC, sort_order ASC, id ASC');
    $stmt->execute([':parent' => $parentId]);
    return $stmt->fetchAll();
}

function getAllButtons(): array
{
    return db()->query('SELECT * FROM buttons ORDER BY parent_id ASC, row_no ASC, sort_order ASC, id ASC')->fetchAll();
}

function addButton(array $data): void
{
    $stmt = db()->prepare('INSERT INTO buttons(parent_id,text,emoji,icon_custom_emoji_id,style,action_type,menu_text,content_id,content_ids,url,popup_text,web_app_url,row_no,sort_order)
      VALUES(:parent,:text,:emoji,:icon,:style,:action,:menu_text,:cid,:cids,:url,:popup,:webapp,:row_no,:sort_order)');
    $stmt->execute([
        ':parent' => (int) ($data['parent_id'] ?? 0),
        ':text' => (string) ($data['text'] ?? ''),
        ':emoji' => (string) ($data['emoji'] ?? ''),
        ':icon' => (string) ($data['icon_custom_emoji_id'] ?? ''),
        ':style' => normalizeButtonStyle((string) ($data['style'] ?? '')),
        ':action' => (string) ($data['action_type'] ?? 'menu'),
        ':menu_text' => (string) ($data['menu_text'] ?? ''),
        ':cid' => ($data['content_id'] ?? '') === '' ? null : (int) $data['content_id'],
        ':cids' => trim((string) ($data['content_ids'] ?? '')),
        ':url' => trim((string) ($data['url'] ?? '')),
        ':popup' => mb_substr(trim((string) ($data['popup_text'] ?? '')), 0, 200),
        ':webapp' => trim((string) ($data['web_app_url'] ?? '')),
        ':row_no' => max(0, (int) ($data['row_no'] ?? 0)),
        ':sort_order' => (int) ($data['sort_order'] ?? 0),
    ]);
}

function updateButton(int $id, array $data): void
{
    $stmt = db()->prepare('UPDATE buttons SET
      parent_id=:parent,text=:text,emoji=:emoji,icon_custom_emoji_id=:icon,style=:style,
      action_type=:action,menu_text=:menu_text,content_id=:cid,content_ids=:cids,url=:url,popup_text=:popup,web_app_url=:webapp,row_no=:row_no,sort_order=:sort_order
      WHERE id=:id');
    $stmt->execute([
        ':id' => $id,
        ':parent' => (int) ($data['parent_id'] ?? 0),
        ':text' => (string) ($data['text'] ?? ''),
        ':emoji' => (string) ($data['emoji'] ?? ''),
        ':icon' => (string) ($data['icon_custom_emoji_id'] ?? ''),
        ':style' => normalizeButtonStyle((string) ($data['style'] ?? '')),
        ':action' => (string) ($data['action_type'] ?? 'menu'),
        ':menu_text' => (string) ($data['menu_text'] ?? ''),
        ':cid' => ($data['content_id'] ?? '') === '' ? null : (int) $data['content_id'],
        ':cids' => trim((string) ($data['content_ids'] ?? '')),
        ':url' => trim((string) ($data['url'] ?? '')),
        ':popup' => mb_substr(trim((string) ($data['popup_text'] ?? '')), 0, 200),
        ':webapp' => trim((string) ($data['web_app_url'] ?? '')),
        ':row_no' => max(0, (int) ($data['row_no'] ?? 0)),
        ':sort_order' => (int) ($data['sort_order'] ?? 0),
    ]);
}

function deleteButton(int $id): void
{
    $stmt = db()->prepare('SELECT id FROM buttons WHERE parent_id=:p');
    $stmt->execute([':p' => $id]);
    foreach ($stmt->fetchAll() as $child) {
        deleteButton((int) $child['id']);
    }
    $d = db()->prepare('DELETE FROM buttons WHERE id=:id');
    $d->execute([':id' => $id]);
}

function updateButtonsTree(array $items): void
{
    $stmt = db()->prepare('UPDATE buttons SET parent_id=:parent,row_no=:row_no,sort_order=:sort WHERE id=:id');
    foreach ($items as $item) {
        $stmt->execute([
            ':id' => (int) ($item['id'] ?? 0),
            ':parent' => (int) ($item['parent_id'] ?? 0),
            ':row_no' => max(0, (int) ($item['row_no'] ?? 0)),
            ':sort' => (int) ($item['sort_order'] ?? 0),
        ]);
    }
}

function normalizeButtonStyle(string $style): string
{
    $style = strtolower(trim($style));
    return in_array($style, ['primary', 'success', 'danger'], true) ? $style : '';
}

function buttonLabel(array $button): string
{
    $emoji = trim((string) ($button['emoji'] ?? ''));
    $text = (string) ($button['text'] ?? '');
    return ($emoji !== '' ? $emoji . ' ' : '') . $text;
}

function navButtonFromSettings(string $type, string $callback): array
{
    $isBack = $type === 'back';
    $label = getSetting($isBack ? 'nav_back_label' : 'nav_home_label', $isBack ? '⬅️ رجوع' : '🏠 الرئيسية');
    $style = normalizeButtonStyle(getSetting($isBack ? 'nav_back_style' : 'nav_home_style', ''));
    $iconId = trim(getSetting($isBack ? 'nav_back_icon_custom_emoji_id' : 'nav_home_icon_custom_emoji_id', ''));

    $btn = ['text' => $label !== '' ? $label : ($isBack ? '⬅️ رجوع' : '🏠 الرئيسية'), 'callback_data' => $callback];
    if ($style !== '') {
        $btn['style'] = $style;
    }
    if ($iconId !== '') {
        $btn['icon_custom_emoji_id'] = $iconId;
    }

    return $btn;
}

function buildMenuKeyboard(
    int $parentId = 0,
    bool $includeHome = false,
    bool $includeBack = false,
    int $backParentId = 0,
    int $page = 1
): ?array {
    $buttons = getButtonsByParent($parentId);
    $perPage = 20;
    $total = count($buttons);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));

    $offset = ($page - 1) * $perPage;
    $slice = array_slice($buttons, $offset, $perPage);

    $rows = [];
    $line = [];
    foreach ($slice as $button) {
        $btn = ['text' => buttonLabel($button)];
        $type = (string) ($button['action_type'] ?? 'menu');
        if ($type === 'url' && trim((string) ($button['url'] ?? '')) !== '') {
            $btn['url'] = trim((string) $button['url']);
        } elseif ($type === 'web_app' && trim((string) ($button['web_app_url'] ?? '')) !== '') {
            $btn['web_app'] = ['url' => trim((string) $button['web_app_url'])];
        } elseif ($type === 'switch_inline') {
            $url = trim((string) ($button['url'] ?? ''));
            if ($url !== '') {
                $btn['url'] = $url;
            } else {
                $btn['switch_inline_query_current_chat'] = trim((string) ($button['popup_text'] ?? ''));
            }
        } else {
            $btn['callback_data'] = 'btn_' . $button['id'];
        }
        $iconId = trim((string) ($button['icon_custom_emoji_id'] ?? ''));
        if ($iconId !== '') {
            $btn['icon_custom_emoji_id'] = $iconId;
        }
        $style = normalizeButtonStyle((string) ($button['style'] ?? ''));
        if ($style !== '') {
            $btn['style'] = $style;
        }

        $line[] = $btn;
        if (count($line) === 4) {
            $rows[] = $line;
            $line = [];
        }
    }

    if (!empty($line)) {
        $rows[] = $line;
    }

    if ($totalPages > 1) {
        $pager = [];
        if ($page > 1) {
            $pager[] = ['text' => '◀️ السابق', 'callback_data' => 'nav_page_' . $parentId . '_' . ($page - 1) . '_' . ($includeBack ? $backParentId : -1)];
        }
        $pager[] = ['text' => "صفحة {$page}/{$totalPages}", 'callback_data' => 'noop'];
        if ($page < $totalPages) {
            $pager[] = ['text' => 'التالي ▶️', 'callback_data' => 'nav_page_' . $parentId . '_' . ($page + 1) . '_' . ($includeBack ? $backParentId : -1)];
        }
        $rows[] = $pager;
    }

    $nav = [];
    if ($includeBack) {
        $nav[] = navButtonFromSettings('back', 'nav_back_' . $backParentId . '_1');
    }
    if ($includeHome) {
        $nav[] = navButtonFromSettings('home', 'nav_home');
    }
    if (!empty($nav)) {
        $rows[] = $nav;
    }

    return empty($rows) ? null : ['inline_keyboard' => $rows];
}

function isUserJoinedAllChannels(int $userId): array
{
    if (!isForceJoinEnabled()) {
        return ['joined' => true, 'missing' => []];
    }

    $channels = getForceChannels();
    if (empty($channels)) {
        return ['joined' => true, 'missing' => []];
    }

    $missing = [];
    foreach ($channels as $channel) {
        $res = telegramApi('getChatMember', ['chat_id' => $channel['chat_id'], 'user_id' => $userId]);
        $status = $res['result']['status'] ?? null;
        if (($res['ok'] ?? false) !== true || in_array($status, ['left', 'kicked', null], true)) {
            $missing[] = $channel;
        }
    }

    return ['joined' => empty($missing), 'missing' => $missing];
}

function forceJoinKeyboard(array $channels): array
{
    $rows = [];
    foreach ($channels as $channel) {
        $rows[] = [[
            'text' => '📢 ' . $channel['title'],
            'url' => $channel['link'],
        ]];
    }
    $rows[] = [[
        'text' => '✅ تحقق',
        'callback_data' => 'verify_join',
    ]];

    return ['inline_keyboard' => $rows];
}



function decodeEntities(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

function applyTemplate(string $text, array $user = []): string
{
    $first = (string) ($user['first_name'] ?? '');
    $last = (string) ($user['last_name'] ?? '');
    $full = trim($first . ' ' . $last);
    $usernameRaw = trim((string) ($user['username'] ?? ''));
    $username = $usernameRaw !== '' ? '@' . ltrim($usernameRaw, '@') : '';
    $id = (string) ($user['id'] ?? '');
    $lang = (string) ($user['language_code'] ?? '');
    $now = time();
    $map = [
        '{name}' => $first !== '' ? $first : $full,
        '{first_name}' => $first,
        '{last_name}' => $last,
        '{full_name}' => $full,
        '{username}' => $username,
        '{username_raw}' => $usernameRaw,
        '{id}' => $id,
        '{user_id}' => $id,
        '{lang}' => $lang,
        '{language_code}' => $lang,
        '{date}' => date('Y-m-d', $now),
        '{time}' => date('H:i:s', $now),
        '{datetime}' => date('Y-m-d H:i:s', $now),
    ];

    return strtr($text, $map);
}

function sendMediaGroupByContent(array $items, int $chatId): bool
{
    $media = [];
    foreach ($items as $idx => $it) {
        $type = (string) ($it['content_type'] ?? '');
        if (!in_array($type, ['photo', 'video', 'document', 'audio'], true)) {
            return false;
        }
        $entry = [
            'type' => $type,
            'media' => (string) ($it['file_id'] ?? ''),
        ];
        if ($idx === 0 && trim((string) ($it['caption'] ?? '')) !== '') {
            $entry['caption'] = (string) $it['caption'];
            $capEntities = decodeEntities((string) ($it['caption_entities'] ?? ''));
            if (!empty($capEntities)) {
                $entry['caption_entities'] = $capEntities;
            }
        }
        $media[] = $entry;
    }

    if (empty($media)) {
        return false;
    }

    $resp = telegramApi('sendMediaGroup', [
        'chat_id' => $chatId,
        'media' => json_encode($media, JSON_UNESCAPED_UNICODE),
    ]);

    return ($resp['ok'] ?? false) === true;
}
function sendContentById(int $chatId, int $contentId, ?array $replyMarkup = null, array $user = []): bool
{
    $content = getContentItem($contentId);
    if ($content === null) {
        return false;
    }

    if (($content['content_type'] ?? '') === 'text') {
        $text = applyTemplate((string) ($content['text_value'] ?? ''), $user);
        $entities = decodeEntities((string) ($content['text_entities'] ?? ''));
        $canUseEntities = $text === (string) ($content['text_value'] ?? '');
        sendMessage($chatId, $text, $replyMarkup, null, $canUseEntities ? $entities : null);
        return true;
    }

    $mg = trim((string) ($content['media_group_id'] ?? ''));
    if ($mg !== '') {
        $items = getContentAlbumItems((int) $content['admin_chat_id'], $mg);
        $sentGrouped = sendMediaGroupByContent($items, $chatId);
        if ($sentGrouped) {
            if ($replyMarkup !== null) {
                sendMessage($chatId, getSetting('album_nav_message', '↩️ استخدم الأزرار بالأسفل للتنقل'), $replyMarkup);
            }
            return true;
        }

        foreach ($items as $it) {
            copyMessageToUser($chatId, (int) $it['admin_chat_id'], (int) $it['admin_message_id']);
        }
        if ($replyMarkup !== null) {
            sendMessage($chatId, getSetting('album_nav_message', '↩️ استخدم الأزرار بالأسفل للتنقل'), $replyMarkup);
        }
        return true;
    }

    copyMessageToUser($chatId, (int) $content['admin_chat_id'], (int) $content['admin_message_id'], $replyMarkup);
    return true;
}


function isUserBlocked(int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM blocked_users WHERE user_id=:id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    return (bool) $stmt->fetchColumn();
}

function setUserBlocked(int $userId, bool $blocked): void
{
    if ($blocked) {
        $stmt = db()->prepare('INSERT OR REPLACE INTO blocked_users(user_id, blocked_at) VALUES(:id, :at)');
        $stmt->execute([':id' => $userId, ':at' => time()]);
        return;
    }
    $stmt = db()->prepare('DELETE FROM blocked_users WHERE user_id=:id');
    $stmt->execute([':id' => $userId]);
}

function findUserById(int $userId): ?array
{
    foreach (getUsers() as $u) {
        if ((int) ($u['user_id'] ?? 0) === $userId) {
            return $u;
        }
    }
    return null;
}

function searchUsers(string $q): array
{
    $q = trim(mb_strtolower($q));
    if ($q === '') {
        return [];
    }
    $out = [];
    foreach (getUsers() as $u) {
        $id = (string) ($u['user_id'] ?? '');
        $name = mb_strtolower((string) ($u['first_name'] ?? ''));
        $username = mb_strtolower((string) ($u['username'] ?? ''));
        if (str_contains($id, $q) || str_contains($name, $q) || str_contains($username, $q)) {
            $out[] = $u;
        }
    }
    return array_slice($out, 0, 100);
}

function exportUsersTxt(): string
{
    $lines = [];
    foreach (getUsers() as $u) {
        $id = (int) ($u['user_id'] ?? 0);
        if ($id > 0) {
            $lines[] = (string) $id;
        }
    }
    return implode("\n", $lines) . "\n";
}

function rememberAdminReplyMap(int $adminMessageId, int $userId): void
{
    $stmt = db()->prepare('INSERT OR REPLACE INTO admin_reply_map(admin_message_id,user_id,created_at) VALUES(:mid,:uid,:at)');
    $stmt->execute([':mid' => $adminMessageId, ':uid' => $userId, ':at' => time()]);
}

function getUserIdFromAdminReplyMap(int $adminMessageId): ?int
{
    $stmt = db()->prepare('SELECT user_id FROM admin_reply_map WHERE admin_message_id=:mid LIMIT 1');
    $stmt->execute([':mid' => $adminMessageId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

function forwardUserMessageToAdmin(array $message): void
{
    $from = $message['from'] ?? [];
    $chatId = (int) ($message['chat']['id'] ?? 0);
    $userId = (int) ($from['id'] ?? 0);
    if ($chatId <= 0 || $userId <= 0 || $chatId !== $userId) {
        return;
    }

    $fwd = forwardMessageToUser(ADMIN_ID, $chatId, (int) ($message['message_id'] ?? 0));
    $adminMid = (int) ($fwd['result']['message_id'] ?? 0);
    if ($adminMid > 0) {
        rememberAdminReplyMap($adminMid, $userId);
    }
}


function parseContentIds(string $raw): array
{
    $parts = preg_split('/[\s,;]+/u', trim($raw));
    $ids = [];
    foreach ($parts ?: [] as $p) {
        $id = (int) $p;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function sendMultipleContents(int $chatId, array $ids, ?array $replyMarkup = null, array $user = []): bool
{
    if (empty($ids)) {
        return false;
    }
    $last = count($ids) - 1;
    foreach ($ids as $i => $id) {
        sendContentById($chatId, (int) $id, $i === $last ? $replyMarkup : null, $user);
    }
    return true;
}

function getCommands(): array
{
    return db()->query('SELECT * FROM bot_commands ORDER BY command ASC')->fetchAll();
}

function saveCommand(string $command, string $actionType, int $contentId, string $contentIds, int $menuTargetId): bool
{
    $command = '/' . ltrim(trim($command), '/');
    if (!preg_match('/^\/[a-zA-Z0-9_]{2,32}$/', $command) || strtolower($command) === '/start') {
        return false;
    }
    $stmt = db()->prepare('INSERT INTO bot_commands(command,action_type,content_id,content_ids,menu_target_id,updated_at)
      VALUES(:cmd,:act,:cid,:cids,:mid,:at)
      ON CONFLICT(command) DO UPDATE SET action_type=excluded.action_type,content_id=excluded.content_id,content_ids=excluded.content_ids,menu_target_id=excluded.menu_target_id,updated_at=excluded.updated_at');
    return $stmt->execute([
        ':cmd' => $command,
        ':act' => 'new_message',
        ':cid' => $contentId > 0 ? $contentId : null,
        ':cids' => trim($contentIds),
        ':mid' => max(0, $menuTargetId),
        ':at' => time(),
    ]);
}

function deleteCommand(string $command): void
{
    $command = '/' . ltrim(trim($command), '/');
    $stmt = db()->prepare('DELETE FROM bot_commands WHERE command=:cmd');
    $stmt->execute([':cmd' => $command]);
}

function getCommand(string $command): ?array
{
    $command = '/' . ltrim(trim($command), '/');
    $stmt = db()->prepare('SELECT * FROM bot_commands WHERE command=:cmd LIMIT 1');
    $stmt->execute([':cmd' => $command]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getStats(): array
{
    return [
        'users' => count(getUsers()),
        'channels' => (int) db()->query('SELECT COUNT(*) FROM force_channels')->fetchColumn(),
        'buttons' => (int) db()->query('SELECT COUNT(*) FROM buttons')->fetchColumn(),
        'contents' => (int) db()->query('SELECT COUNT(*) FROM content_items')->fetchColumn(),
    ];
}
