<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

db();

$update = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($update) || empty($update)) {
    exit;
}

if (isset($update['message'])) {
    handleMessage($update['message']);
}

if (isset($update['callback_query'])) {
    handleCallback($update['callback_query']);
}

function navKeyboard(int $backTarget = 0, int $page = 1): array
{
    return ['inline_keyboard' => [[
        navButtonFromSettings('back', 'nav_back_' . $backTarget . '_' . max(1, $page)),
        navButtonFromSettings('home', 'nav_home'),
    ]]];
}

function handleMessage(array $message): void
{
    $chatId = (int) ($message['chat']['id'] ?? 0);
    $userId = (int) ($message['from']['id'] ?? 0);
    $text = trim((string) ($message['text'] ?? ''));
    $messageId = (int) ($message['message_id'] ?? 0);

    if ($chatId === 0 || $userId === 0) {
        return;
    }

    if (isUserBlocked($userId) && $userId !== ADMIN_ID) {
        return;
    }

    if ($userId === ADMIN_ID && isset($message['reply_to_message']['message_id'])) {
        $replyTo = (int) ($message['reply_to_message']['message_id'] ?? 0);
        $targetUserId = getUserIdFromAdminReplyMap($replyTo);
        if ($targetUserId !== null && $targetUserId > 0) {
            copyMessageToUser($targetUserId, $chatId, $messageId);
            return;
        }
    }

    if ($userId === ADMIN_ID && $text === '/admin_help') {
        sendMessage($chatId, "أرسل أي محتوى (نص/صورة/فيديو/ألبوم) وسيتم حفظه ويعطيك Content ID.
الأوامر: /admin_help /myid");
        return;
    }

    if ($userId === ADMIN_ID && $text === '/myid') {
        sendMessage($chatId, 'ADMIN_ID: <code>' . ADMIN_ID . '</code>');
        return;
    }

    if ($userId === ADMIN_ID && $text !== '/start') {
        $detected = detectMessageContent($message);
        if ($detected !== null) {
            $contentId = saveContentItem($chatId, $messageId, $detected);
            sendMessage(
                $chatId,
                "✅ تم حفظ المحتوى
Content ID: <code>{$contentId}</code>
Message ID: <code>{$messageId}</code>
Type: <code>{$detected['content_type']}</code>
MediaGroup: <code>" . ($detected['media_group_id'] ?? '-') . '</code>'
            );
            return;
        }
    }

    if ($text === '/start') {
        $isNew = saveUser($message['from'] ?? []);
        if ($isNew && getSetting('notify_admin_new_user', '0') === '1') {
            $total = count(getUsers());
            $name = (string) (($message['from']['first_name'] ?? ''));
            $uid = (int) (($message['from']['id'] ?? 0));
            sendMessage(ADMIN_ID, "🆕 تم انضمام عضو جديد
الاسم: {$name}
العضو رقم: <code>{$uid}</code>
العدد الكلي: <b>{$total}</b>");
        }
        showRootMenu($chatId, $message['from'] ?? []);
        return;
    }

    if ($text !== '' && str_starts_with($text, '/')) {
        $cmd = explode(' ', $text)[0];
        if (strtolower($cmd) !== '/start') {
            $command = getCommand($cmd);
            if ($command !== null) {
                $cid = (int) ($command['content_id'] ?? 0);
                if ($cid > 0) {
                    sendContentById($chatId, $cid, null, $message['from'] ?? []);
                    return;
                }
            }
        }
    }

    if ($userId !== ADMIN_ID && getSetting('support_mode_enabled', '0') === '1' && getSetting('forward_user_messages_to_admin', '0') === '1' && !str_starts_with($text, '/')) {
        forwardUserMessageToAdmin($message);
        return;
    }

    showRootMenu($chatId, $message['from'] ?? []);
}

function showRootMenu(int $chatId, array $user): void
{
    $userId = (int) ($user['id'] ?? 0);
    $join = isUserJoinedAllChannels($userId);
    if (!$join['joined']) {
        sendMessage($chatId, getSetting('force_join_message'), forceJoinKeyboard($join['missing']));
        return;
    }

    $text = applyTemplate(getSetting('menu_message'), $user);
    $keyboard = buildMenuKeyboard(0, false, false, 0, 1);
    $welcomeContentId = (int) getSetting('welcome_content_id', '0');
    if ($welcomeContentId > 0 && sendContentById($chatId, $welcomeContentId, $keyboard, $user)) {
        return;
    }

    sendMessage($chatId, $text, $keyboard);
}

function handleCallback(array $cb): void
{
    $callbackId = (string) ($cb['id'] ?? '');
    $data = (string) ($cb['data'] ?? '');
    $chatId = (int) ($cb['message']['chat']['id'] ?? 0);
    $messageId = (int) ($cb['message']['message_id'] ?? 0);
    $userId = (int) ($cb['from']['id'] ?? 0);

    if ($callbackId === '' || $chatId === 0 || $messageId === 0 || $userId === 0) {
        return;
    }

    if ($data === 'verify_join') {
        $join = isUserJoinedAllChannels($userId);
        if ($join['joined']) {
            answerCallback($callbackId, 'تم التحقق ✅');
            editMessage($chatId, $messageId, applyTemplate(getSetting('menu_message'), $cb['from'] ?? []), buildMenuKeyboard(0, false, false, 0, 1));
        } else {
            answerCallback($callbackId, 'ما زال الاشتراك مطلوبًا');
            editMessage($chatId, $messageId, getSetting('force_join_message'), forceJoinKeyboard($join['missing']));
        }
        return;
    }

    if ($data === 'nav_home' || $data === 'home_root') {
        answerCallback($callbackId);
        deleteMessage($chatId, $messageId);
        sendMessage($chatId, applyTemplate(getSetting('menu_message'), $cb['from'] ?? []), buildMenuKeyboard(0, false, false, 0, 1));
        return;
    }

    $join = isUserJoinedAllChannels($userId);
    if (!$join['joined']) {
        answerCallback($callbackId, 'اشترك أولًا');
        editMessage($chatId, $messageId, getSetting('force_join_message'), forceJoinKeyboard($join['missing']));
        return;
    }

    if (str_starts_with($data, 'nav_back_')) {
        $parts = explode('_', $data);
        $target = isset($parts[2]) ? (int) $parts[2] : 0;
        $page = isset($parts[3]) ? (int) $parts[3] : 1;
        $parentBtn = $target > 0 ? getButton($target) : null;
        $backTo = $parentBtn ? (int) ($parentBtn['parent_id'] ?? 0) : 0;

        answerCallback($callbackId);
        $text = $target > 0
            ? (trim((string) ($parentBtn['menu_text'] ?? '')) !== '' ? applyTemplate((string) $parentBtn['menu_text'], $cb['from'] ?? []) : applyTemplate(getSetting('menu_message'), $cb['from'] ?? []))
            : applyTemplate(getSetting('menu_message'), $cb['from'] ?? []);

        deleteMessage($chatId, $messageId);
        sendMessage($chatId, $text, buildMenuKeyboard($target, $target > 0, $target > 0, $backTo, $page));
        return;
    }

    if (str_starts_with($data, 'nav_page_')) {
        $parts = explode('_', $data);
        $target = isset($parts[2]) ? (int) $parts[2] : 0;
        $page = isset($parts[3]) ? (int) $parts[3] : 1;
        $backFlag = isset($parts[4]) ? (int) $parts[4] : -1;
        $includeBack = $backFlag >= 0;
        $backTo = $includeBack ? $backFlag : 0;

        $btn = $target > 0 ? getButton($target) : null;
        $text = $target > 0
            ? (trim((string) ($btn['menu_text'] ?? '')) !== '' ? applyTemplate((string) $btn['menu_text'], $cb['from'] ?? []) : applyTemplate(getSetting('menu_message'), $cb['from'] ?? []))
            : applyTemplate(getSetting('menu_message'), $cb['from'] ?? []);

        answerCallback($callbackId);
        editMessage($chatId, $messageId, $text, buildMenuKeyboard($target, $target > 0, $includeBack, $backTo, $page));
        return;
    }

    if ($data === 'noop') {
        answerCallback($callbackId);
        return;
    }

    if (!str_starts_with($data, 'btn_')) {
        answerCallback($callbackId);
        return;
    }

    $buttonId = (int) substr($data, 4);
    $button = getButton($buttonId);
    if ($button === null) {
        answerCallback($callbackId, 'الزر غير موجود');
        return;
    }

    $action = (string) ($button['action_type'] ?? 'menu');
    if ($action === 'edit_message') {
        $action = 'delete_and_send';
    }
    answerCallback($callbackId);

    if ($action === 'popup') {
        answerCallback($callbackId, mb_substr((string) ($button['popup_text'] ?? 'تم.'), 0, 180));
        return;
    }

    if ($action === 'menu') {
        $keyboard = buildMenuKeyboard($buttonId, true, true, (int) ($button['parent_id'] ?? 0), 1);
        $title = trim((string) ($button['menu_text'] ?? '')) !== '' ? applyTemplate((string) $button['menu_text'], $cb['from'] ?? []) : applyTemplate(getSetting('menu_message'), $cb['from'] ?? []);
        $connectId = (int) ($button['content_id'] ?? 0);

        if ($connectId > 0) {
            $content = getContentItem($connectId);
            if ($content !== null && ($content['content_type'] ?? '') === 'text') {
                $title = applyTemplate((string) ($content['text_value'] ?? $title), $cb['from'] ?? []);
                editMessage($chatId, $messageId, $title, $keyboard);
                return;
            }
            deleteMessage($chatId, $messageId);
            sendContentById($chatId, $connectId, $keyboard, $cb['from'] ?? []);
            return;
        }

        editMessage($chatId, $messageId, $title, $keyboard);
        return;
    }

    $parentMenu = (int) ($button['parent_id'] ?? 0);
    $backHomeKeyboard = navKeyboard($parentMenu, 1);

    $ids = parseContentIds((string) ($button['content_ids'] ?? ''));
    if (empty($ids)) {
        $single = (int) ($button['content_id'] ?? 0);
        if ($single > 0) {
            $ids = [$single];
        }
    }

    if (empty($ids)) {
        sendMessage($chatId, '⚠️ هذا الزر لا يحتوي Content ID.');
        return;
    }

    if ($action === 'delete_and_send') {
        deleteMessage($chatId, $messageId);
        sendMultipleContents($chatId, $ids, $backHomeKeyboard, $cb['from'] ?? []);
        return;
    }

    sendMultipleContents($chatId, $ids, null, $cb['from'] ?? []);
}
