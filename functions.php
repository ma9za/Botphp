<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT NOT NULL,
        image_file_id TEXT NOT NULL DEFAULT "",
        image_path TEXT NOT NULL DEFAULT "",
        product_content TEXT NOT NULL,
        price_stars INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        allow_repeat INTEGER NOT NULL DEFAULT 1,
        access_type TEXT NOT NULL DEFAULT "all",
        single_user_id INTEGER NULL,
        delivery_type TEXT NOT NULL DEFAULT "auto",
        created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS purchases (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        stars INTEGER NOT NULL,
        telegram_payment_charge_id TEXT NOT NULL UNIQUE,
        created_at INTEGER NOT NULL
    )');

    ensureColumn($pdo, 'products', 'image_path', 'TEXT NOT NULL DEFAULT ""');
    ensureColumn($pdo, 'products', 'sort_order', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'products', 'allow_repeat', 'INTEGER NOT NULL DEFAULT 1');
    ensureColumn($pdo, 'products', 'access_type', 'TEXT NOT NULL DEFAULT "all"');
    ensureColumn($pdo, 'products', 'single_user_id', 'INTEGER NULL');
    ensureColumn($pdo, 'products', 'delivery_type', 'TEXT NOT NULL DEFAULT "auto"');

    setSettingDefault('welcome_text', "مرحبًا {first_name} 🌟\nاختر المنتج الذي تريد شراءه:");
    setSettingDefault('catalog_text', '🛍️ قائمة المنتجات');
    setSettingDefault('site_url', '');

    if (!file_exists(USERS_FILE)) {
        file_put_contents(USERS_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    if (!is_dir(__DIR__ . '/images')) {
        mkdir(__DIR__ . '/images', 0775, true);
    }

    return $pdo;
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($cols as $c) {
        if ((string) ($c['name'] ?? '') === $column) return;
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function setSettingDefault(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT OR IGNORE INTO settings(key,value) VALUES(:k,:v)');
    $stmt->execute([':k' => $key, ':v' => $value]);
}

function getSetting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key=:k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (string) $v : $default;
}

function setSetting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings(key,value) VALUES(:k,:v) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    $stmt->execute([':k' => $key, ':v' => $value]);
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function saveUser(array $from): void
{
    if (!isset($from['id'])) return;
    $users = getUsers();
    $uid = (int) $from['id'];
    foreach ($users as $u) if ((int) ($u['user_id'] ?? 0) === $uid) return;
    $users[] = [
        'user_id' => $uid,
        'username' => (string) ($from['username'] ?? ''),
        'first_name' => (string) ($from['first_name'] ?? ''),
        'time' => time(),
    ];
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getUsers(): array
{
    if (!file_exists(USERS_FILE)) return [];
    $arr = json_decode((string) file_get_contents(USERS_FILE), true);
    return is_array($arr) ? $arr : [];
}

function applyTemplate(string $text, array $user = []): string
{
    $first = (string) ($user['first_name'] ?? '');
    $usernameRaw = (string) ($user['username'] ?? '');
    return strtr($text, [
        '{first_name}' => $first,
        '{name}' => $first,
        '{username}' => $usernameRaw !== '' ? '@' . ltrim($usernameRaw, '@') : '',
        '{id}' => (string) ($user['id'] ?? ''),
    ]);
}

function telegramApi(string $method, array $data = []): array
{
    if (BOT_TOKEN === '') return ['ok' => false, 'description' => 'BOT_TOKEN empty'];
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($data),
        'timeout' => 25,
    ]]);
    $res = @file_get_contents(BOT_API_URL . $method, false, $ctx);
    if ($res === false) return ['ok' => false, 'description' => 'request failed'];
    $d = json_decode($res, true);
    return is_array($d) ? $d : ['ok' => false, 'description' => 'invalid json'];
}

function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): array
{
    $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($replyMarkup !== null) $p['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    return telegramApi('sendMessage', $p);
}

function sendPhoto(int $chatId, string $photo, string $caption = '', ?array $replyMarkup = null): array
{
    $p = ['chat_id' => $chatId, 'photo' => $photo, 'caption' => $caption, 'parse_mode' => 'HTML'];
    if ($replyMarkup !== null) $p['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    return telegramApi('sendPhoto', $p);
}

function editMessage(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): array
{
    $p = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($replyMarkup !== null) $p['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    return telegramApi('editMessageText', $p);
}

function answerCallback(string $callbackId, string $text = ''): void
{
    telegramApi('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text]);
}

function answerPreCheckout(string $preCheckoutId, bool $ok, string $errorMessage = ''): void
{
    telegramApi('answerPreCheckoutQuery', [
        'pre_checkout_query_id' => $preCheckoutId,
        'ok' => $ok ? 'true' : 'false',
        'error_message' => $errorMessage,
    ]);
}

function addProduct(array $d): void
{
    $stmt = db()->prepare('INSERT INTO products(name,description,image_file_id,image_path,product_content,price_stars,is_active,sort_order,allow_repeat,access_type,single_user_id,delivery_type,created_at)
      VALUES(:n,:d,:img,:path,:pc,:p,:a,:s,:r,:at,:uid,:dt,:t)');
    $stmt->execute([
        ':n' => trim((string) ($d['name'] ?? '')),
        ':d' => mb_substr(trim((string) ($d['description'] ?? '')), 0, 1000),
        ':img' => trim((string) ($d['image_file_id'] ?? '')),
        ':path' => trim((string) ($d['image_path'] ?? '')),
        ':pc' => trim((string) ($d['product_content'] ?? '')),
        ':p' => max(1, (int) ($d['price_stars'] ?? 1)),
        ':a' => !empty($d['is_active']) ? 1 : 0,
        ':s' => max(0, (int) ($d['sort_order'] ?? 0)),
        ':r' => !empty($d['allow_repeat']) ? 1 : 0,
        ':at' => in_array((string) ($d['access_type'] ?? 'all'), ['all', 'single'], true) ? (string) ($d['access_type'] ?? 'all') : 'all',
        ':uid' => ($d['single_user_id'] ?? '') === '' ? null : (int) $d['single_user_id'],
        ':dt' => in_array((string) ($d['delivery_type'] ?? 'auto'), ['auto', 'manual'], true) ? (string) ($d['delivery_type'] ?? 'auto') : 'auto',
        ':t' => time(),
    ]);
}

function updateProduct(int $id, array $d): void
{
    $stmt = db()->prepare('UPDATE products SET name=:n,description=:d,image_file_id=:img,image_path=:path,product_content=:pc,price_stars=:p,is_active=:a,sort_order=:s,allow_repeat=:r,access_type=:at,single_user_id=:uid,delivery_type=:dt WHERE id=:id');
    $stmt->execute([
        ':id' => $id,
        ':n' => trim((string) ($d['name'] ?? '')),
        ':d' => mb_substr(trim((string) ($d['description'] ?? '')), 0, 1000),
        ':img' => trim((string) ($d['image_file_id'] ?? '')),
        ':path' => trim((string) ($d['image_path'] ?? '')),
        ':pc' => trim((string) ($d['product_content'] ?? '')),
        ':p' => max(1, (int) ($d['price_stars'] ?? 1)),
        ':a' => !empty($d['is_active']) ? 1 : 0,
        ':s' => max(0, (int) ($d['sort_order'] ?? 0)),
        ':r' => !empty($d['allow_repeat']) ? 1 : 0,
        ':at' => in_array((string) ($d['access_type'] ?? 'all'), ['all', 'single'], true) ? (string) ($d['access_type'] ?? 'all') : 'all',
        ':uid' => ($d['single_user_id'] ?? '') === '' ? null : (int) $d['single_user_id'],
        ':dt' => in_array((string) ($d['delivery_type'] ?? 'auto'), ['auto', 'manual'], true) ? (string) ($d['delivery_type'] ?? 'auto') : 'auto',
    ]);
}

function deleteProduct(int $id): void
{
    $stmt = db()->prepare('DELETE FROM products WHERE id=:id');
    $stmt->execute([':id' => $id]);
}

function getProducts(bool $activeOnly = false, string $search = ''): array
{
    $search = trim($search);
    if ($activeOnly && $search === '') return db()->query('SELECT * FROM products WHERE is_active=1 ORDER BY sort_order ASC, id DESC')->fetchAll();
    if (!$activeOnly && $search === '') return db()->query('SELECT * FROM products ORDER BY sort_order ASC, id DESC')->fetchAll();

    $sql = 'SELECT * FROM products WHERE ' . ($activeOnly ? 'is_active=1 AND ' : '') . '(name LIKE :q OR description LIKE :q) ORDER BY sort_order ASC, id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute([':q' => '%' . $search . '%']);
    return $stmt->fetchAll();
}

function getProduct(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE id=:id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function addPurchase(int $userId, int $productId, int $stars, string $chargeId): bool
{
    try {
        $stmt = db()->prepare('INSERT INTO purchases(user_id,product_id,stars,telegram_payment_charge_id,created_at) VALUES(:u,:p,:s,:c,:t)');
        return $stmt->execute([':u' => $userId, ':p' => $productId, ':s' => $stars, ':c' => $chargeId, ':t' => time()]);
    } catch (Throwable $e) {
        return false;
    }
}

function hasUserPurchased(int $userId, int $productId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM purchases WHERE user_id=:u AND product_id=:p LIMIT 1');
    $stmt->execute([':u' => $userId, ':p' => $productId]);
    return (bool) $stmt->fetchColumn();
}

function getStats(): array
{
    return [
        'users' => count(getUsers()),
        'products' => (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn(),
        'purchases' => (int) db()->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
        'revenue' => (int) db()->query('SELECT COALESCE(SUM(stars),0) FROM purchases')->fetchColumn(),
    ];
}

function getPurchases(int $limit = 200): array
{
    $stmt = db()->prepare('SELECT p.*, pr.name AS product_name FROM purchases p LEFT JOIN products pr ON pr.id=p.product_id ORDER BY p.id DESC LIMIT :l');
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function productKeyboard(array $products): ?array
{
    $rows = [];
    foreach ($products as $p) {
        $rows[] = [[
            'text' => $p['name'] . ' ⭐' . (int)$p['price_stars'],
            'callback_data' => 'prod_' . (int)$p['id'],
        ]];
    }
    return empty($rows) ? null : ['inline_keyboard' => $rows];
}

function productManageKeyboard(array $products): ?array
{
    $rows = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $rows[] = [[
            'text' => '#' . (int)$p['sort_order'] . ' ' . $p['name'],
            'callback_data' => 'adm_view_' . $id,
        ]];
    }
    return empty($rows) ? null : ['inline_keyboard' => $rows];
}

function productManageItemKeyboard(int $id): array
{
    return ['inline_keyboard' => [
        [
            ['text' => '⬆️ أعلى', 'callback_data' => 'adm_up_' . $id],
            ['text' => '⬇️ أسفل', 'callback_data' => 'adm_down_' . $id],
        ],
        [['text' => '🔙 رجوع للإدارة', 'callback_data' => 'adm_back_list']],
    ]];
}

function swapProductSort(int $id, int $direction): void
{
    $curr = getProduct($id);
    if ($curr === null) return;
    $sort = (int) ($curr['sort_order'] ?? 0);
    $targetSort = $direction < 0 ? max(0, $sort - 1) : $sort + 1;

    $stmt = db()->prepare('SELECT id, sort_order FROM products WHERE id != :id AND sort_order ' . ($direction < 0 ? '<=' : '>=') . ' :s ORDER BY sort_order ' . ($direction < 0 ? 'DESC' : 'ASC') . ' LIMIT 1');
    $stmt->execute([':id' => $id, ':s' => $targetSort]);
    $other = $stmt->fetch();

    db()->beginTransaction();
    try {
        if ($other) {
            db()->prepare('UPDATE products SET sort_order=:s WHERE id=:id')->execute([':s' => $sort, ':id' => (int)$other['id']]);
            db()->prepare('UPDATE products SET sort_order=:s WHERE id=:id')->execute([':s' => (int)$other['sort_order'], ':id' => $id]);
        } else {
            db()->prepare('UPDATE products SET sort_order=:s WHERE id=:id')->execute([':s' => $targetSort, ':id' => $id]);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
    }
}

function sendStarsInvoice(int $chatId, array $product, int $userId): array
{
    $pid = (int) ($product['id'] ?? 0);
    $payload = 'product_' . $pid . '_user_' . $userId;
    return telegramApi('sendInvoice', [
        'chat_id' => $chatId,
        'title' => (string) $product['name'],
        'description' => (string) $product['description'],
        'payload' => $payload,
        'currency' => 'XTR',
        'prices' => json_encode([['label' => 'Stars', 'amount' => (int) $product['price_stars']]], JSON_UNESCAPED_UNICODE),
        'provider_token' => '',
    ]);
}
