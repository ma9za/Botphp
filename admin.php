<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/functions.php';

db();

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function jsonOut(array $data): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

function renderTree(array $buttons, int $parent = 0, string $path = ''): string
{
    $children = array_values(array_filter($buttons, static fn($b) => (int)$b['parent_id'] === $parent));
    if (empty($children)) return '';
    usort($children, static fn($a,$b) => ((int)$a['row_no'] <=> (int)$b['row_no']) ?: ((int)$a['sort_order'] <=> (int)$b['sort_order']) ?: ((int)$a['id'] <=> (int)$b['id']));

    $html = '<ul class="tree" data-parent="'.$parent.'">';
    $total = count($children);
    foreach ($children as $i => $btn) {
        $id = (int)$btn['id'];
        $nodePath = $path === '' ? (string)($i+1) : ($path.':'.($i+1));
        $payload = h(json_encode($btn, JSON_UNESCAPED_UNICODE));

        $html .= '<li class="tree-item" data-id="'.$id.'">';
        $html .= '<div class="node">'
            . '<span class="drag">⋮⋮</span>'
            . '<div class="txt"><b class="path">'.$nodePath.'</b> #'.$id.' '.h((string)$btn['text']).'<small>row: '.(int)$btn['row_no'].' | sort: '.(int)$btn['sort_order'].' | '.h((string)$btn['action_type']).'</small></div>'
            . '<button class="btn warn" onclick="editBtn(\''.$payload.'\')">تعديل</button>'
            . '<button class="btn danger" onclick="delBtn('.$id.')">حذف</button>'
            . '</div>';
        $html .= renderTree($buttons, $id, $nodePath);
        $html .= '</li>';
    }
    return $html.'</ul>';
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (!isset($_SESSION['ok']) || $_SESSION['ok'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && hash_equals(ADMIN_PASSWORD, (string)$_POST['password'])) {
        $_SESSION['ok'] = true; header('Location: admin.php'); exit;
    }
    ?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.13/dist/css/uikit.min.css"/>
<style>body{background:#0b1020;color:#e6e9f2}.card{background:#121a33;border:1px solid #24335f;border-radius:14px;padding:12px}.path{color:#8cb4ff}.node{display:flex;gap:8px;align-items:center;background:#0f1730;border:1px solid #26355f;border-radius:12px;padding:8px}.txt{flex:1}.txt small{display:block;color:#9aa7cb}.drag{cursor:grab;color:#98a8d4}.btn{border:0;border-radius:8px;padding:6px 8px;cursor:pointer}.btn.warn{background:#f59e0b;color:#111}.btn.danger{background:#e11d48;color:#fff}.chk{font-size:12px;color:#c7d2fe}.tree{list-style:none;padding:0;margin:8px 0}.tree .tree{margin-inline-start:18px}</style></head>
<body class="uk-flex uk-flex-center uk-flex-middle" style="min-height:100vh"><form method="post" class="card" style="min-width:320px"><h3>🔐 دخول المشرف</h3><input class="uk-input" type="password" name="password" placeholder="كلمة المرور" required><button class="uk-button uk-button-primary uk-width-1-1 uk-margin-small-top">دخول</button></form></body></html>
<?php
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'users_txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="users.txt"');
    echo exportUsersTxt();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax'])) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        setSetting('menu_message', (string)($_POST['menu_message'] ?? ''));
        setSetting('force_join_message', (string)($_POST['force_join_message'] ?? ''));
        setSetting('force_join_enabled', isset($_POST['force_join_enabled']) ? '1' : '0');
        setSetting('welcome_content_id', (string)((int)($_POST['welcome_content_id'] ?? 0)));
        setSetting('nav_back_label', (string)($_POST['nav_back_label'] ?? '⬅️ رجوع'));
        setSetting('nav_home_label', (string)($_POST['nav_home_label'] ?? '🏠 الرئيسية'));
        setSetting('nav_back_style', (string)($_POST['nav_back_style'] ?? ''));
        setSetting('nav_home_style', (string)($_POST['nav_home_style'] ?? ''));
        setSetting('nav_back_icon_custom_emoji_id', (string)($_POST['nav_back_icon_custom_emoji_id'] ?? ''));
        setSetting('nav_home_icon_custom_emoji_id', (string)($_POST['nav_home_icon_custom_emoji_id'] ?? ''));
        setSetting('album_nav_message', (string)($_POST['album_nav_message'] ?? '↩️ استخدم الأزرار بالأسفل للتنقل'));
        setSetting('forward_user_messages_to_admin', isset($_POST['forward_user_messages_to_admin']) ? '1' : '0');
        setSetting('notify_admin_new_user', isset($_POST['notify_admin_new_user']) ? '1' : '0');
        setSetting('support_mode_enabled', isset($_POST['support_mode_enabled']) ? '1' : '0');
        jsonOut(['ok'=>true,'message'=>'تم الحفظ']);
    }

    if ($action === 'add_channel') {
        $ok = addForceChannel(trim((string)($_POST['chat_id'] ?? '')), trim((string)($_POST['title'] ?? '')), trim((string)($_POST['link'] ?? '')));
        jsonOut(['ok'=>$ok,'message'=>$ok?'تمت إضافة القناة':'تعذر إضافة القناة']);
    }
    if ($action === 'delete_channel') { deleteForceChannel((int)($_POST['channel_id'] ?? 0)); jsonOut(['ok'=>true,'message'=>'تم حذف القناة']); }

    if ($action === 'add_button' || $action === 'update_button') {
        $buttonType = (string)($_POST['button_type'] ?? 'content');
        $sendMode = (string)($_POST['send_mode'] ?? 'new_message');
        $actionType = in_array($buttonType, ['menu','url','popup','web_app','switch_inline'], true) ? $buttonType : $sendMode;
        $contentIds = trim((string)($_POST['content_ids'] ?? ''));
        if ($buttonType === 'content' && count(parseContentIds($contentIds)) > 1) {
            $actionType = 'multi_content';
        }
        $targetUser = (int)($_POST['target_user_id'] ?? 0);
        $url = trim((string)($_POST['url'] ?? ''));
        if ($buttonType === 'switch_inline' && $targetUser > 0) {
            $url = 'tg://user?id=' . $targetUser;
        }

        $data = [
            'parent_id' => (int)($_POST['parent_id'] ?? 0),
            'text' => (string)($_POST['text'] ?? ''),
            'emoji' => '',
            'icon_custom_emoji_id' => (string)($_POST['icon_custom_emoji_id'] ?? ''),
            'style' => (string)($_POST['style'] ?? ''),
            'action_type' => $actionType,
            'menu_text' => (string)($_POST['menu_text'] ?? ''),
            'content_id' => (string)($_POST['content_id'] ?? ''),
            'content_ids' => $contentIds,
            'url' => $url,
            'popup_text' => (string)($_POST['popup_text'] ?? ''),
            'web_app_url' => (string)($_POST['web_app_url'] ?? ''),
            'row_no' => (int)($_POST['row_no'] ?? 0),
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
        ];
        if ($action === 'add_button') { addButton($data); jsonOut(['ok'=>true,'message'=>'تمت إضافة الزر']); }
        updateButton((int)($_POST['button_id'] ?? 0), $data);
        jsonOut(['ok'=>true,'message'=>'تم تعديل الزر']);
    }

    if ($action === 'delete_button') { deleteButton((int)($_POST['button_id'] ?? 0)); jsonOut(['ok'=>true,'message'=>'تم حذف الزر']); }
    if ($action === 'save_tree') { $items = json_decode((string)($_POST['tree'] ?? '[]'), true); updateButtonsTree(is_array($items)?$items:[]); jsonOut(['ok'=>true,'message'=>'تم حفظ الهيكل']); }
    if ($action === 'delete_content') { deleteContentItem((int)($_POST['content_id'] ?? 0)); jsonOut(['ok'=>true,'message'=>'تم حذف المحتوى']); }

    if ($action === 'search_users') { $rows = searchUsers((string)($_POST['q'] ?? '')); foreach ($rows as &$r) { $r['blocked'] = isUserBlocked((int)$r['user_id']); } jsonOut(['ok'=>true,'users'=>$rows]); }
    if ($action === 'toggle_block') {
        $uid=(int)($_POST['user_id'] ?? 0); $blocked=(string)($_POST['blocked'] ?? '0')==='1'; setUserBlocked($uid,$blocked);
        if ($uid>0) sendMessage($uid, $blocked ? '🚫 تم حظرك من استخدام البوت.' : '✅ تم رفع الحظر عنك.');
        jsonOut(['ok'=>true,'message'=>$blocked?'تم الحظر':'تم رفع الحظر']);
    }
    if ($action === 'send_to_user') { $uid=(int)($_POST['user_id'] ?? 0); $text=trim((string)($_POST['text'] ?? '')); $res=($uid>0 && $text!=='')?sendMessage($uid,$text):['ok'=>false]; jsonOut(['ok'=>(bool)($res['ok']??false),'message'=>(($res['ok']??false)?'تم الإرسال':'فشل الإرسال')]); }

    if ($action === 'save_command') {
        $ok = saveCommand((string)($_POST['command'] ?? ''), 'new_message', (int)($_POST['command_content_id'] ?? 0), '', 0);
        jsonOut(['ok'=>$ok,'message'=>$ok?'تم حفظ الأمر':'أمر غير صالح']);
    }
    if ($action === 'delete_command') { deleteCommand((string)($_POST['command'] ?? '')); jsonOut(['ok'=>true,'message'=>'تم حذف الأمر']); }

    jsonOut(['ok'=>false,'message'=>'إجراء غير معروف']);
}

$stats = getStats();
$channels = getForceChannels();
$users = getUsers();
$buttons = getAllButtons();
$contents = getContentItems();
$commands = getCommands();
$menuButtons = array_values(array_filter($buttons, static fn($b)=>(string)($b['action_type'] ?? '') === 'menu'));
$tree = renderTree($buttons, 0);
?>
<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>لوحة التحكم</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.13/dist/css/uikit.min.css"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<style>body{background:#070c1c;color:#e7ecff}.card{background:#121a33;border:1px solid #24335f;border-radius:14px;padding:12px}.grid{display:grid;grid-template-columns:1fr;gap:12px}@media(min-width:1024px){.grid{grid-template-columns:1fr 1fr}}details summary{cursor:pointer;font-weight:700}.badge{padding:4px 8px;border-radius:10px;background:#1f2b52}.tree{list-style:none;padding:0}.tree .tree{margin-inline-start:18px}.node{display:flex;gap:8px;align-items:center;background:#0f1730;border:1px solid #26355f;border-radius:12px;padding:8px}.drag{cursor:grab}.txt{flex:1}.txt small{display:block;color:#9aa7cb}.path{color:#8cb4ff}.hide{display:none}</style></head>
<body>
<div class="uk-container uk-container-large uk-padding-small">
    <div class="uk-flex uk-flex-between uk-flex-middle uk-margin-small-bottom"><h2>لوحة الإدارة</h2><a href="?logout=1" class="uk-button uk-button-danger">خروج</a></div>
    <div class="uk-grid-small uk-child-width-1-2 uk-child-width-1-4@m" uk-grid>
        <div><div class="card">Users <span class="badge"><?= (int)$stats['users'] ?></span></div></div>
        <div><div class="card">Channels <span class="badge"><?= (int)$stats['channels'] ?></span></div></div>
        <div><div class="card">Buttons <span class="badge"><?= (int)$stats['buttons'] ?></span></div></div>
        <div><div class="card">Contents <span class="badge"><?= (int)$stats['contents'] ?></span></div></div>
    </div>

    <div class="grid uk-margin-small-top">
        <section class="card">
            <details open><summary>الإعدادات</summary>
                <form id="settingsForm" class="uk-margin-small-top uk-grid-small" uk-grid>
                    <input type="hidden" name="action" value="save_settings">
                    <div class="uk-width-1-1"><textarea class="uk-textarea" name="menu_message" rows="2" placeholder="رسالة الرئيسية"><?= h(getSetting('menu_message')) ?></textarea></div>
                    <div class="uk-width-1-1"><textarea class="uk-textarea" name="force_join_message" rows="2" placeholder="رسالة الاشتراك"><?= h(getSetting('force_join_message')) ?></textarea></div>
                    <div class="uk-width-1-1"><textarea class="uk-textarea" name="album_nav_message" rows="2" placeholder="رسالة أزرار الألبوم"><?= h(getSetting('album_nav_message', '↩️ استخدم الأزرار بالأسفل للتنقل')) ?></textarea></div>
                    <div class="uk-width-1-2"><input class="uk-input" type="number" name="welcome_content_id" value="<?= (int)getSetting('welcome_content_id','0') ?>" placeholder="Welcome Content ID"></div>
                    <div class="uk-width-1-2"><input class="uk-input" name="nav_back_label" value="<?= h(getSetting('nav_back_label','⬅️ رجوع')) ?>" placeholder="نص زر الرجوع"></div>
                    <div class="uk-width-1-2"><input class="uk-input" name="nav_home_label" value="<?= h(getSetting('nav_home_label','🏠 الرئيسية')) ?>" placeholder="نص زر الرئيسية"></div>
                    <div class="uk-width-1-2"><select class="uk-select" name="nav_back_style"><?php $v=getSetting('nav_back_style',''); ?><option value="" <?= $v===''?'selected':'' ?>>لون الرجوع default</option><option value="primary" <?= $v==='primary'?'selected':'' ?>>primary</option><option value="success" <?= $v==='success'?'selected':'' ?>>success</option><option value="danger" <?= $v==='danger'?'selected':'' ?>>danger</option></select></div>
                    <div class="uk-width-1-2"><select class="uk-select" name="nav_home_style"><?php $v2=getSetting('nav_home_style',''); ?><option value="" <?= $v2===''?'selected':'' ?>>لون الرئيسية default</option><option value="primary" <?= $v2==='primary'?'selected':'' ?>>primary</option><option value="success" <?= $v2==='success'?'selected':'' ?>>success</option><option value="danger" <?= $v2==='danger'?'selected':'' ?>>danger</option></select></div>
                    <div class="uk-width-1-2"><input class="uk-input" name="nav_back_icon_custom_emoji_id" value="<?= h(getSetting('nav_back_icon_custom_emoji_id','')) ?>" placeholder="emoji id لزر الرجوع"></div>
                    <div class="uk-width-1-2"><input class="uk-input" name="nav_home_icon_custom_emoji_id" value="<?= h(getSetting('nav_home_icon_custom_emoji_id','')) ?>" placeholder="emoji id لزر الرئيسية"></div>
                    <div class="uk-width-1-1 uk-text-small">
                        <label><input class="uk-checkbox" type="checkbox" name="force_join_enabled" <?= isForceJoinEnabled()?'checked':'' ?>> اشتراك إجباري</label>
                        <label><input class="uk-checkbox" type="checkbox" name="forward_user_messages_to_admin" <?= getSetting('forward_user_messages_to_admin','0')==='1'?'checked':'' ?>> تحويل رسائل المستخدمين</label>
                        <label><input class="uk-checkbox" type="checkbox" name="support_mode_enabled" <?= getSetting('support_mode_enabled','0')==='1'?'checked':'' ?>> وضع انتظار رد المشرف</label>
                        <label><input class="uk-checkbox" type="checkbox" name="notify_admin_new_user" <?= getSetting('notify_admin_new_user','0')==='1'?'checked':'' ?>> إشعار عضو جديد</label>
                    </div>
                    <div class="uk-width-1-1"><a class="uk-button uk-button-secondary" href="?export=users_txt">تصدير IDs TXT</a> <button class="uk-button uk-button-primary">حفظ</button></div>
                </form>
            </details>

            <details><summary>القنوات الإجبارية</summary>
                <form id="channelForm" class="uk-grid-small uk-margin-small-top" uk-grid>
                    <input type="hidden" name="action" value="add_channel">
                    <div class="uk-width-1-3"><input class="uk-input" name="chat_id" placeholder="@channel" required></div>
                    <div class="uk-width-1-3"><input class="uk-input" name="title" placeholder="الاسم" required></div>
                    <div class="uk-width-1-3"><input class="uk-input" name="link" placeholder="https://t.me/..." required></div>
                </form>
                <button class="uk-button uk-button-primary uk-margin-small-top" onclick="submitChannel()">إضافة قناة</button>
                <ul class="uk-list uk-list-divider uk-margin-small-top"><?php foreach($channels as $c): ?><li><?= h($c['title']) ?> (<?= h($c['chat_id']) ?>) <button class="uk-button uk-button-danger uk-button-small" onclick="delChannel(<?= (int)$c['id'] ?>)">حذف</button></li><?php endforeach; ?></ul>
            </details>
        </section>

        <section class="card">
            <details open><summary>منشئ الأزرار</summary>
                <form id="buttonForm" class="uk-grid-small uk-margin-small-top" uk-grid>
                    <input type="hidden" name="button_id" value=""><input type="hidden" name="action" value="add_button"><input type="hidden" name="parent_id" value="0">
                    <div class="uk-width-1-1"><input class="uk-input" name="text" placeholder="نص الزر" required></div>
                    <div class="uk-width-1-2"><select class="uk-select" id="targetLocation" name="target_location"><option value="root">زر رئيسي</option><option value="child">داخل قائمة</option></select></div>
                    <div class="uk-width-1-2 hide" id="parentWrap"><select class="uk-select" id="parentMenuSelect" name="parent_menu_id"><option value="0">اختر القائمة الأب</option><?php foreach($menuButtons as $mb): ?><option value="<?= (int)$mb['id'] ?>">#<?= (int)$mb['id'] ?> - <?= h((string)$mb['text']) ?></option><?php endforeach; ?></select></div>
                    <div class="uk-width-1-2"><select class="uk-select" id="buttonType" name="button_type"><option value="content">محتوى</option><option value="menu">قائمة</option><option value="url">رابط</option><option value="popup">منبثقة</option><option value="web_app">Web App</option><option value="switch_inline">مراسلة خاصة</option></select></div>
                    <div class="uk-width-1-2" id="sendModeWrap"><select class="uk-select" id="sendMode" name="send_mode"><option value="new_message">رسالة جديدة</option><option value="delete_and_send">حذف وارسال</option></select></div>
                    <div class="uk-width-1-1"><input class="uk-input" name="icon_custom_emoji_id" placeholder="icon_custom_emoji_id"></div>
                    <div class="uk-width-1-3"><select class="uk-select" name="style"><option value="">default</option><option value="primary">primary</option><option value="success">success</option><option value="danger">danger</option></select></div>
                    <div class="uk-width-1-3"><input class="uk-input" type="number" name="row_no" value="0" min="0" placeholder="row_no"></div>
                    <div class="uk-width-1-3"><input class="uk-input" type="number" name="sort_order" value="0" min="0" placeholder="sort_order"></div>

                    <div id="contentFields" class="uk-width-1-1">
                        <div id="contentIdsList"></div>
                        <button class="uk-button uk-button-default uk-button-small" type="button" onclick="addContentIdInput()">+ إضافة Connect ID</button>
                    </div>
                    <div class="uk-width-1-1 hide" id="urlWrap"><input class="uk-input" name="url" placeholder="https://example.com"></div>
                    <div class="uk-width-1-1 hide" id="popupWrap"><input class="uk-input" name="popup_text" maxlength="180" placeholder="نص منبثق"></div>
                    <div class="uk-width-1-1 hide" id="webAppWrap"><input class="uk-input" name="web_app_url" placeholder="https://webapp.url"></div>
                    <div class="uk-width-1-1 hide" id="privateWrap"><input class="uk-input" type="number" name="target_user_id" placeholder="ID المستخدم للمراسلة الخاصة"></div>
                    <div class="uk-width-1-1 hide" id="menuTextWrap"><textarea class="uk-textarea" name="menu_text" rows="2" placeholder="رسالة القائمة"></textarea></div>

                    <div class="uk-width-1-1"><button id="saveBtn" class="uk-button uk-button-primary">حفظ الزر</button> <button type="button" class="uk-button uk-button-default" onclick="resetBtn()">تفريغ</button></div>
                </form>

                <div class="uk-margin-small-top"><button class="uk-button uk-button-secondary" onclick="saveTree()">حفظ الهيكل</button></div>
                <p class="uk-text-meta">اسحب وافلت، واختر "سطر جديد" لتقسيم الصف. نفس الشكل يُطبق في البوت.</p>
                <div id="treeRoot"><?= $tree !== '' ? $tree : '<p class="uk-text-muted">لا يوجد أزرار بعد.</p>' ?></div>
            </details>
        </section>
    </div>

    <div class="grid uk-margin-small-top">
        <section class="card"><details><summary>إدارة الأوامر /</summary>
            <div class="uk-grid-small uk-margin-small-top" uk-grid>
                <div class="uk-width-1-2"><input id="cmdName" class="uk-input" placeholder="/help (لا يمكن تعديل /start)"></div>
                <div class="uk-width-1-2"><input id="cmdContentId" type="number" class="uk-input" placeholder="content_id"></div>
                <div class="uk-width-1-1"><button class="uk-button uk-button-primary" onclick="saveCommand()">حفظ الأمر</button></div>
            </div>
            <ul class="uk-list uk-list-divider"><?php foreach($commands as $c): ?><li><?= h($c['command']) ?> → content_id: <?= (int)($c['content_id'] ?? 0) ?> <button class="uk-button uk-button-danger uk-button-small" onclick="deleteCommand('<?= h($c['command']) ?>')">حذف</button></li><?php endforeach; ?></ul>
        </details></section>

        <section class="card"><details open><summary>إدارة المستخدمين</summary>
            <div class="uk-grid-small uk-margin-small-top" uk-grid>
                <div class="uk-width-1-2"><input id="userSearch" class="uk-input" placeholder="بحث بالاسم/اليوزر/ID"></div>
                <div class="uk-width-1-2"><button class="uk-button uk-button-primary" onclick="runUserSearch()">بحث</button></div>
                <div class="uk-width-1-2"><input id="directUserId" type="number" class="uk-input" placeholder="ID"></div>
                <div class="uk-width-1-2"><input id="directUserText" class="uk-input" placeholder="رسالة"></div>
                <div class="uk-width-1-1"><button class="uk-button uk-button-secondary" onclick="sendDirectToUser()">إرسال مباشر</button></div>
            </div>
            <div id="searchResults" class="uk-margin-small-top"></div>
            <div class="uk-overflow-auto uk-margin-small-top"><table class="uk-table uk-table-divider uk-table-small"><thead><tr><th>ID</th><th>username</th><th>name</th><th>time</th></tr></thead><tbody><?php foreach($users as $u): ?><tr><td><?= (int)($u['user_id'] ?? 0) ?></td><td><?= h((string)($u['username'] ?? '')) ?></td><td><?= h((string)($u['first_name'] ?? '')) ?></td><td><?= isset($u['time']) ? date('Y-m-d H:i:s',(int)$u['time']) : '' ?></td></tr><?php endforeach; ?></tbody></table></div>
        </details></section>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/uikit@3.21.13/dist/js/uikit.min.js"></script>
<script>
const post = async (fd)=> (await fetch('admin.php?ajax=1',{method:'POST',body:fd})).json();
const toast=(m,ok=true)=>UIkit.notification({message:m,status:ok?'success':'danger',pos:'bottom-left'});

function addContentIdInput(value=''){
  const row=document.createElement('div');
  row.className='uk-margin-small-top';
  row.innerHTML=`<div class="uk-grid-small" uk-grid><div class="uk-width-expand"><input class="uk-input content-id-input" type="number" value="${value}" placeholder="Connect ID"></div><div class="uk-width-auto"><button class="uk-button uk-button-danger" type="button">×</button></div></div>`;
  row.querySelector('button').onclick=()=>row.remove();
  document.getElementById('contentIdsList').appendChild(row);
}
addContentIdInput();

function toggleButtonFields(){
  const type=document.getElementById('buttonType').value;
  document.getElementById('sendModeWrap').classList.toggle('hide', type!=='content');
  document.getElementById('contentFields').classList.toggle('hide', type!=='content');
  document.getElementById('menuTextWrap').classList.toggle('hide', type!=='menu');
  document.getElementById('urlWrap').classList.toggle('hide', type!=='url');
  document.getElementById('popupWrap').classList.toggle('hide', type!=='popup');
  document.getElementById('webAppWrap').classList.toggle('hide', type!=='web_app');
  document.getElementById('privateWrap').classList.toggle('hide', type!=='switch_inline');
}
function toggleParent(){ document.getElementById('parentWrap').classList.toggle('hide', document.getElementById('targetLocation').value!=='child'); }

document.getElementById('buttonType').addEventListener('change',toggleButtonFields);
document.getElementById('targetLocation').addEventListener('change',toggleParent);
toggleButtonFields(); toggleParent();

async function submitChannel(){const out=await post(new FormData(document.getElementById('channelForm')));toast(out.message,out.ok);if(out.ok)location.reload();}

document.getElementById('settingsForm').addEventListener('submit',async e=>{e.preventDefault();const out=await post(new FormData(e.target));toast(out.message,out.ok);});

document.getElementById('buttonForm').addEventListener('submit',async e=>{
  e.preventDefault();
  const f=e.target,fd=new FormData(f);
  fd.set('action', fd.get('button_id') ? 'update_button' : 'add_button');
  fd.set('parent_id', fd.get('target_location')==='child' ? (fd.get('parent_menu_id')||'0') : '0');
  const ids=[...document.querySelectorAll('.content-id-input')].map(i=>i.value.trim()).filter(Boolean);
  fd.set('content_id', ids[0] || fd.get('content_id') || '');
  fd.set('content_ids', ids.join(','));
  const out=await post(fd); toast(out.message,out.ok); if(out.ok)location.reload();
});

function editBtn(payload){
  const b=JSON.parse(payload),f=document.getElementById('buttonForm');
  f.button_id.value=b.id; f.text.value=b.text; f.icon_custom_emoji_id.value=b.icon_custom_emoji_id||''; f.style.value=b.style||'';
  f.target_location.value=(parseInt(b.parent_id,10)>0?'child':'root'); f.parent_menu_id.value=b.parent_id||0; toggleParent();
  let type='content';
  if (['menu','url','popup','web_app','switch_inline'].includes(b.action_type)) type=b.action_type;
  f.button_type.value=type;
  f.send_mode.value=(b.action_type==='delete_and_send'?'delete_and_send':'new_message');
  f.menu_text.value=b.menu_text||''; f.url.value=b.url||''; f.popup_text.value=b.popup_text||''; f.web_app_url.value=b.web_app_url||''; f.row_no.value=b.row_no||0; f.sort_order.value=b.sort_order||0;
  const uid=((b.url||'').match(/tg:\/\/user\?id=(\d+)/)||[])[1]||''; f.target_user_id.value=uid;
  document.getElementById('contentIdsList').innerHTML='';
  const ids=((b.content_ids||'')+'').split(/[,\s;]+/).filter(Boolean); if(ids.length===0 && b.content_id) ids.push(String(b.content_id));
  (ids.length?ids:['']).forEach(v=>addContentIdInput(v));
  toggleButtonFields();
  document.getElementById('saveBtn').textContent='تحديث الزر';
  window.scrollTo({top:0,behavior:'smooth'});
}
function resetBtn(){const f=document.getElementById('buttonForm');f.reset();f.button_id.value='';document.getElementById('contentIdsList').innerHTML='';addContentIdInput();toggleButtonFields();toggleParent();document.getElementById('saveBtn').textContent='حفظ الزر';}

async function delBtn(id){if(!confirm('حذف الزر؟'))return;const fd=new FormData();fd.append('action','delete_button');fd.append('button_id',id);const out=await post(fd);toast(out.message,out.ok);if(out.ok)location.reload();}
async function delChannel(id){if(!confirm('حذف القناة؟'))return;const fd=new FormData();fd.append('action','delete_channel');fd.append('channel_id',id);const out=await post(fd);toast(out.message,out.ok);if(out.ok)location.reload();}
function toggleBreak(el,id){const li=document.querySelector('.tree-item[data-id="'+id+'"]');if(li)li.dataset.breakAfter=el.checked?'1':'0';}

function bindSortables(){document.querySelectorAll('.tree').forEach(ul=>new Sortable(ul,{group:'nested',animation:120,handle:'.drag'}));}
bindSortables();
function collectTree(){
  const rows=[];
  function walk(ul,parent){
    let row=0,sort=0;
    [...ul.children].filter(x=>x.matches('li.tree-item')).forEach(li=>{
      const id=parseInt(li.dataset.id,10);
      rows.push({id,parent_id:parent,row_no:row,sort_order:sort});
      sort++; if(sort>=4){row++;sort=0;}
      const child=li.querySelector(':scope > ul.tree'); if(child) walk(child,id);
    });
  }
  const root=document.querySelector('#treeRoot > ul.tree'); if(root) walk(root,0); return rows;
}
async function saveTree(){const fd=new FormData();fd.append('action','save_tree');fd.append('tree',JSON.stringify(collectTree()));const out=await post(fd);toast(out.message,out.ok);if(out.ok)location.reload();}

async function runUserSearch(){const fd=new FormData();fd.append('action','search_users');fd.append('q',document.getElementById('userSearch').value.trim());const out=await post(fd);const b=document.getElementById('searchResults');if(!out.ok){b.textContent='فشل';return;}b.innerHTML=(out.users||[]).map(u=>`<div class='card uk-margin-small'><b>${u.first_name||''}</b> @${u.username||''} <code>${u.user_id}</code> <button class='uk-button uk-button-small ${u.blocked?'uk-button-secondary':'uk-button-danger'}' onclick='toggleBlock(${u.user_id},${u.blocked?0:1})'>${u.blocked?'رفع':'حظر'}</button> <button class='uk-button uk-button-small uk-button-primary' onclick='fillDirect(${u.user_id})'>مراسلة</button></div>`).join('') || 'لا يوجد نتائج';}
function fillDirect(id){document.getElementById('directUserId').value=id;}
async function toggleBlock(userId,blocked){const fd=new FormData();fd.append('action','toggle_block');fd.append('user_id',userId);fd.append('blocked',blocked);const out=await post(fd);toast(out.message,out.ok);if(out.ok)runUserSearch();}
async function sendDirectToUser(){const fd=new FormData();fd.append('action','send_to_user');fd.append('user_id',document.getElementById('directUserId').value.trim());fd.append('text',document.getElementById('directUserText').value.trim());const out=await post(fd);toast(out.message,out.ok);}

async function saveCommand(){const fd=new FormData();fd.append('action','save_command');fd.append('command',document.getElementById('cmdName').value.trim());fd.append('command_content_id',document.getElementById('cmdContentId').value.trim());const out=await post(fd);toast(out.message,out.ok);if(out.ok)location.reload();}
async function deleteCommand(command){const fd=new FormData();fd.append('action','delete_command');fd.append('command',command);const out=await post(fd);toast(out.message,out.ok);if(out.ok)location.reload();}
</script>
</body></html>
