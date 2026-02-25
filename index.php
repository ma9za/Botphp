<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

db();
$update = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($update) || empty($update)) exit;

if (isset($update['pre_checkout_query'])) { handlePreCheckout($update['pre_checkout_query']); exit; }
if (isset($update['message'])) { handleMessage($update['message']); exit; }
if (isset($update['callback_query'])) { handleCallback($update['callback_query']); exit; }

function showCatalog(int $chatId, array $user): void
{
    $products = getProducts(true);
    $text = applyTemplate(getSetting('welcome_text', 'مرحبًا {first_name}'), $user);
    $kb = productKeyboard($products);
    if ($kb === null) {
        sendMessage($chatId, $text . "\n\nلا توجد منتجات متاحة الآن.");
        return;
    }
    sendMessage($chatId, $text, $kb);
}

function handleMessage(array $msg): void
{
    $chatId = (int) ($msg['chat']['id'] ?? 0);
    $userId = (int) ($msg['from']['id'] ?? 0);
    $text = trim((string) ($msg['text'] ?? ''));
    if ($chatId === 0 || $userId === 0) return;

    if (isset($msg['successful_payment'])) {
        $sp = $msg['successful_payment'];
        $payload = (string) ($sp['invoice_payload'] ?? '');
        $chargeId = (string) ($sp['telegram_payment_charge_id'] ?? '');
        $amount = (int) ($sp['total_amount'] ?? 0);
        if (preg_match('/^product_(\d+)_user_(\d+)$/', $payload, $m)) {
            $productId = (int) $m[1];
            $ownerUserId = (int) $m[2];
            if ($ownerUserId === $userId) {
                $p = getProduct($productId);
                if ($p !== null) {
                    addPurchase($userId, $productId, $amount, $chargeId);
                    if ((string)($p['delivery_type'] ?? 'auto') === 'manual') {
                        sendMessage($chatId, "✅ تم الدفع بنجاح\nسيتم التسليم يدويًا قريبًا.");
                        sendMessage(ADMIN_ID, "💳 شراء جديد يحتاج تسليم يدوي\nالمنتج: <b>" . h((string)$p['name']) . "</b>\nUser: <code>{$userId}</code>\nStars: <b>{$amount}</b>");
                        return;
                    }
                    sendMessage($chatId, "✅ تم الدفع بنجاح\n\n🧾 المنتج: <b>" . h((string)$p['name']) . "</b>\n⭐ السعر: <b>{$amount}</b>\n\n📦 التسليم:\n" . (string) $p['product_content']);
                    return;
                }
            }
        }
    }

    if ($userId === ADMIN_ID && $text === '/manage_products') {
        sendMessage($chatId, '🛠️ إدارة ترتيب المنتجات من داخل البوت', productManageKeyboard(getProducts(false)));
        return;
    }

    if ($text === '/start') {
        saveUser($msg['from'] ?? []);
        showCatalog($chatId, $msg['from'] ?? []);
        return;
    }

    showCatalog($chatId, $msg['from'] ?? []);
}

function handleCallback(array $cb): void
{
    $callbackId = (string) ($cb['id'] ?? '');
    $data = (string) ($cb['data'] ?? '');
    $chatId = (int) ($cb['message']['chat']['id'] ?? 0);
    $messageId = (int) ($cb['message']['message_id'] ?? 0);
    $userId = (int) ($cb['from']['id'] ?? 0);
    if ($callbackId === '' || $chatId === 0 || $messageId === 0 || $userId === 0) return;

    if ($userId === ADMIN_ID) {
        if ($data === 'adm_back_list') {
            answerCallback($callbackId);
            editMessage($chatId, $messageId, '🛠️ إدارة ترتيب المنتجات من داخل البوت', productManageKeyboard(getProducts(false)));
            return;
        }
        if (str_starts_with($data, 'adm_view_')) {
            $id = (int) substr($data, 9);
            $p = getProduct($id);
            if ($p) {
                answerCallback($callbackId);
                editMessage($chatId, $messageId, 'المنتج: <b>' . h((string)$p['name']) . "</b>\nالترتيب الحالي: <b>" . (int)$p['sort_order'] . '</b>', productManageItemKeyboard($id));
            }
            return;
        }
        if (str_starts_with($data, 'adm_up_')) {
            $id = (int) substr($data, 7);
            swapProductSort($id, -1);
            answerCallback($callbackId, 'تم رفع المنتج');
            $p = getProduct($id);
            if ($p) editMessage($chatId, $messageId, 'المنتج: <b>' . h((string)$p['name']) . "</b>\nالترتيب الحالي: <b>" . (int)$p['sort_order'] . '</b>', productManageItemKeyboard($id));
            return;
        }
        if (str_starts_with($data, 'adm_down_')) {
            $id = (int) substr($data, 9);
            swapProductSort($id, 1);
            answerCallback($callbackId, 'تم إنزال المنتج');
            $p = getProduct($id);
            if ($p) editMessage($chatId, $messageId, 'المنتج: <b>' . h((string)$p['name']) . "</b>\nالترتيب الحالي: <b>" . (int)$p['sort_order'] . '</b>', productManageItemKeyboard($id));
            return;
        }
    }

    if ($data === 'back_catalog') {
        answerCallback($callbackId);
        editMessage($chatId, $messageId, getSetting('catalog_text', '🛍️ قائمة المنتجات'), productKeyboard(getProducts(true)));
        return;
    }

    if (str_starts_with($data, 'prod_')) {
        $id = (int) substr($data, 5);
        $p = getProduct($id);
        if ($p === null || (int)$p['is_active'] !== 1) { answerCallback($callbackId, 'المنتج غير متاح'); return; }

        if ((string)($p['access_type'] ?? 'all') === 'single' && (int)($p['single_user_id'] ?? 0) !== $userId) {
            answerCallback($callbackId, 'هذا المنتج غير متاح لك');
            return;
        }

        if ((int)($p['allow_repeat'] ?? 1) === 0 && hasUserPurchased($userId, (int)$p['id'])) {
            answerCallback($callbackId, 'تم شراء هذا المنتج سابقًا ولا يقبل تكرار الشراء');
            return;
        }

        answerCallback($callbackId);
        $txt = "🛍️ <b>" . h((string)$p['name']) . "</b>\n\n" . h((string)$p['description']) . "\n\n⭐ السعر: <b>" . (int)$p['price_stars'] . "</b>";
        $kb = ['inline_keyboard' => [
            [[
                'text' => '⭐ شراء الآن',
                'style' => 'success',
                'callback_data' => 'buy_' . (int)$p['id'],
            ]],
            [[
                'text' => '❌ إلغاء',
                'style' => 'danger',
                'callback_data' => 'back_catalog',
            ]],
        ]];

        $site = rtrim(getSetting('site_url', ''), '/');
        $imgFileId = trim((string) ($p['image_file_id'] ?? ''));
        $imgPath = trim((string) ($p['image_path'] ?? ''));
        if ($imgFileId !== '') {
            sendPhoto($chatId, $imgFileId, $txt, $kb);
        } elseif ($imgPath !== '' && $site !== '') {
            sendPhoto($chatId, $site . '/' . ltrim($imgPath, '/'), $txt, $kb);
        } else {
            editMessage($chatId, $messageId, $txt, $kb);
        }
        return;
    }

    if (str_starts_with($data, 'buy_')) {
        $id = (int) substr($data, 4);
        $p = getProduct($id);
        if ($p === null || (int)$p['is_active'] !== 1) { answerCallback($callbackId, 'المنتج غير متاح'); return; }

        if ((string)($p['access_type'] ?? 'all') === 'single' && (int)($p['single_user_id'] ?? 0) !== $userId) {
            answerCallback($callbackId, 'هذا المنتج غير متاح لك');
            return;
        }
        if ((int)($p['allow_repeat'] ?? 1) === 0 && hasUserPurchased($userId, (int)$p['id'])) {
            answerCallback($callbackId, 'هذا المنتج شراء لمرة واحدة فقط');
            return;
        }

        answerCallback($callbackId, 'جاري فتح نافذة الدفع...');
        sendStarsInvoice($chatId, $p, $userId);
        return;
    }

    answerCallback($callbackId);
}

function handlePreCheckout(array $q): void
{
    $id = (string) ($q['id'] ?? '');
    $payload = (string) ($q['invoice_payload'] ?? '');
    $userId = (int) ($q['from']['id'] ?? 0);
    if ($id === '' || $userId === 0) return;

    if (!preg_match('/^product_(\d+)_user_(\d+)$/', $payload, $m)) { answerPreCheckout($id, false, 'بيانات غير صالحة'); return; }
    $productId = (int) $m[1];
    $ownerUserId = (int) $m[2];
    $p = getProduct($productId);
    if ($p === null || (int)$p['is_active'] !== 1) { answerPreCheckout($id, false, 'المنتج غير متاح'); return; }
    if ($ownerUserId !== $userId) { answerPreCheckout($id, false, 'جلسة دفع غير صالحة'); return; }
    if ((string)($p['access_type'] ?? 'all') === 'single' && (int)($p['single_user_id'] ?? 0) !== $userId) { answerPreCheckout($id, false, 'غير متاح لك'); return; }
    if ((int)($p['allow_repeat'] ?? 1) === 0 && hasUserPurchased($userId, (int)$p['id'])) { answerPreCheckout($id, false, 'شراء لمرة واحدة فقط'); return; }
    answerPreCheckout($id, true);
}
