<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/functions.php';

db();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (!isset($_SESSION['ok']) || $_SESSION['ok'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && hash_equals(ADMIN_PASSWORD, (string) $_POST['password'])) {
        $_SESSION['ok'] = true;
        header('Location: admin.php');
        exit;
    }
    ?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Login</title>
<style>body{font-family:Arial;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh}form{background:#1e293b;padding:18px;border-radius:12px;min-width:320px}input,button{width:100%;padding:10px;margin-top:8px;border-radius:8px;border:1px solid #334155}button{background:#2563eb;color:#fff;border:0;cursor:pointer}</style></head>
<body><form method="post"><h3>🔐 دخول الأدمن</h3><input type="password" name="password" placeholder="كلمة المرور" required><button>دخول</button></form></body></html>
<?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax'])) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        setSetting('welcome_text', (string) ($_POST['welcome_text'] ?? ''));
        setSetting('catalog_text', (string) ($_POST['catalog_text'] ?? ''));
        setSetting('site_url', trim((string) ($_POST['site_url'] ?? '')));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'تم حفظ الإعدادات'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'add_product' || $action === 'update_product') {
        $imagePath = '';
        if (isset($_FILES['image_file']) && is_array($_FILES['image_file']) && (int)($_FILES['image_file']['error'] ?? 1) === 0) {
            $ext = strtolower(pathinfo((string)($_FILES['image_file']['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';
            if (!is_dir(__DIR__ . '/images')) mkdir(__DIR__ . '/images', 0775, true);
            $fname = 'p_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target = __DIR__ . '/images/' . $fname;
            if (move_uploaded_file((string)$_FILES['image_file']['tmp_name'], $target)) {
                $imagePath = 'images/' . $fname;
            }
        }

        $data = [
            'name' => (string) ($_POST['name'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
            'image_file_id' => (string) ($_POST['image_file_id'] ?? ''),
            'image_path' => $imagePath !== '' ? $imagePath : (string) ($_POST['existing_image_path'] ?? ''),
            'product_content' => (string) ($_POST['product_content'] ?? ''),
            'price_stars' => (int) ($_POST['price_stars'] ?? 1),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'allow_repeat' => isset($_POST['allow_repeat']) ? 1 : 0,
            'access_type' => (string) ($_POST['access_type'] ?? 'all'),
            'single_user_id' => (string) ($_POST['single_user_id'] ?? ''),
            'delivery_type' => (string) ($_POST['delivery_type'] ?? 'auto'),
        ];

        if ($action === 'add_product') {
            addProduct($data);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'message' => 'تمت إضافة المنتج'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        updateProduct((int) ($_POST['product_id'] ?? 0), $data);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'تم تعديل المنتج'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete_product') {
        deleteProduct((int) ($_POST['product_id'] ?? 0));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'تم حذف المنتج'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'إجراء غير معروف'], JSON_UNESCAPED_UNICODE);
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$products = getProducts(false, $search);
$users = getUsers();
$stats = getStats();
$purchases = getPurchases(300);
?>
<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>لوحة المنتجات</title>
<style>
body{font-family:Arial;background:#020617;color:#e2e8f0;margin:0} .wrap{max-width:1200px;margin:auto;padding:16px}
.card{background:#0f172a;border:1px solid #334155;border-radius:12px;padding:14px;margin-bottom:12px}
.grid{display:grid;grid-template-columns:1fr;gap:12px} @media(min-width:980px){.grid{grid-template-columns:1fr 1fr}}
input,textarea,select,button{width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0b1220;color:#fff}
button{background:#2563eb;border:0;cursor:pointer}.danger{background:#e11d48}.muted{color:#94a3b8}
.row{display:grid;grid-template-columns:1fr;gap:8px} @media(min-width:700px){.row{grid-template-columns:1fr 1fr}}
small{color:#94a3b8} table{width:100%;border-collapse:collapse} td,th{border-bottom:1px solid #1e293b;padding:8px;text-align:right;vertical-align:top}
.badge{display:inline-block;background:#1e3a8a;border-radius:999px;padding:4px 10px;margin-left:6px}
</style></head>
<body>
<div class="wrap">
  <div class="card">
    <h2>🛍️ بوت المنتجات - لوحة الإدارة</h2>
    <a href="?logout=1" style="color:#93c5fd">تسجيل خروج</a>
    <p>
      <span class="badge">Users: <?= (int)$stats['users'] ?></span>
      <span class="badge">Purchases: <?= (int)$stats['purchases'] ?></span>
      <span class="badge">Revenue⭐: <?= (int)$stats['revenue'] ?></span>
      <span class="badge">Products: <?= (int)$stats['products'] ?></span>
    </p>
  </div>

  <div class="grid">
    <section class="card">
      <h3>إعدادات البوت</h3>
      <form id="settingsForm" class="row">
        <input type="hidden" name="action" value="save_settings">
        <div style="grid-column:1/-1"><textarea name="welcome_text" rows="3" placeholder="رسالة الترحيب (تدعم {first_name})"><?= h(getSetting('welcome_text')) ?></textarea></div>
        <div style="grid-column:1/-1"><textarea name="catalog_text" rows="2" placeholder="نص كتالوج المنتجات"><?= h(getSetting('catalog_text')) ?></textarea></div>
        <input name="site_url" placeholder="رابط موقعك https://domain.com" value="<?= h(getSetting('site_url')) ?>">
        <div style="grid-column:1/-1"><button>حفظ الإعدادات</button></div>
      </form>
      <small>ضع site_url حتى يعرض البوت صور المنتجات المرفوعة من المجلد images.</small>
    </section>

    <section class="card">
      <h3>إضافة / تعديل منتج</h3>
      <form id="productForm" class="row" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_product">
        <input type="hidden" name="product_id" value="">
        <input type="hidden" name="existing_image_path" value="">
        <input name="name" placeholder="اسم المنتج" required>
        <input name="price_stars" type="number" min="1" value="1" placeholder="السعر بالنجوم" required>
        <input name="sort_order" type="number" min="0" value="0" placeholder="ترتيب المنتج">
        <div style="grid-column:1/-1"><textarea name="description" rows="4" maxlength="1000" placeholder="وصف المنتج (1000 حرف)" required></textarea></div>
        <input name="image_file_id" placeholder="file_id للصورة (اختياري)">
        <input type="file" name="image_file" accept="image/*">
        <div style="grid-column:1/-1"><textarea name="product_content" rows="4" placeholder="محتوى المنتج الذي يتم تسليمه" required></textarea></div>
        <label><input type="checkbox" name="is_active" checked> فعال</label>
        <label><input type="checkbox" name="allow_repeat" checked> يسمح بتكرار الشراء</label>
        <select name="access_type" id="accessType"><option value="all">للجميع</option><option value="single">لمستخدم واحد</option></select>
        <input id="singleUserId" name="single_user_id" type="number" placeholder="ID المستخدم المسموح" style="display:none">
        <select name="delivery_type"><option value="auto">تسليم تلقائي</option><option value="manual">عند الطلب (يدوي)</option></select>
        <div style="grid-column:1/-1"><button id="saveBtn">حفظ المنتج</button></div>
      </form>
    </section>
  </div>

  <section class="card">
    <h3>المنتجات الحالية</h3>
    <form method="get" class="row" style="margin-bottom:10px"><input name="q" placeholder="بحث باسم/وصف المنتج" value="<?= h($search) ?>"><button>بحث</button></form>
    <table>
      <thead><tr><th>#</th><th>الاسم</th><th>النجوم</th><th>خيارات</th><th>حالة</th><th>إجراءات</th></tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
      <tr>
        <td><?= (int)$p['sort_order'] ?></td>
        <td><?= h((string)$p['name']) ?></td>
        <td><?= (int)$p['price_stars'] ?></td>
        <td>
          repeat: <?= (int)$p['allow_repeat'] ? 'yes' : 'no' ?><br>
          access: <?= h((string)$p['access_type']) ?> <?= (int)($p['single_user_id'] ?? 0) > 0 ? ('#'.(int)$p['single_user_id']) : '' ?><br>
          delivery: <?= h((string)$p['delivery_type']) ?>
        </td>
        <td><?= (int)$p['is_active'] ? 'فعال' : 'متوقف' ?></td>
        <td>
          <button style="width:auto" onclick='editProduct(<?= json_encode($p, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS) ?>)'>تعديل</button>
          <button class="danger" style="width:auto" onclick='delProduct(<?= (int)$p['id'] ?>)'>حذف</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <div class="grid">
    <section class="card">
      <h3>المشتريات</h3>
      <table><thead><tr><th>#</th><th>user</th><th>product</th><th>stars</th><th>time</th></tr></thead><tbody>
      <?php foreach($purchases as $r): ?>
        <tr><td><?= (int)$r['id'] ?></td><td><?= (int)$r['user_id'] ?></td><td><?= h((string)($r['product_name'] ?? '')) ?></td><td><?= (int)$r['stars'] ?></td><td><?= date('Y-m-d H:i:s',(int)$r['created_at']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </section>

    <section class="card">
      <h3>المستخدمون</h3>
      <table>
        <thead><tr><th>ID</th><th>username</th><th>name</th><th>time</th></tr></thead>
        <tbody><?php foreach($users as $u): ?><tr><td><?= (int)($u['user_id'] ?? 0) ?></td><td><?= h((string)($u['username'] ?? '')) ?></td><td><?= h((string)($u['first_name'] ?? '')) ?></td><td><?= date('Y-m-d H:i:s',(int)($u['time'] ?? time())) ?></td></tr><?php endforeach; ?></tbody>
      </table>
    </section>
  </div>
</div>
<script>
const post = async (fd) => (await fetch('admin.php?ajax=1',{method:'POST',body:fd})).json();

document.getElementById('accessType').addEventListener('change', e=>{
  document.getElementById('singleUserId').style.display = e.target.value === 'single' ? '' : 'none';
});

document.getElementById('settingsForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const out = await post(new FormData(e.target));
  alert(out.message||'done');
});

document.getElementById('productForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.set('action', fd.get('product_id') ? 'update_product' : 'add_product');
  const out = await post(fd);
  alert(out.message||'done');
  if (out.ok) location.reload();
});

function editProduct(p){
  const f=document.getElementById('productForm');
  f.product_id.value=p.id;
  f.name.value=p.name||'';
  f.price_stars.value=p.price_stars||1;
  f.sort_order.value=p.sort_order||0;
  f.description.value=p.description||'';
  f.image_file_id.value=p.image_file_id||'';
  f.existing_image_path.value=p.image_path||'';
  f.product_content.value=p.product_content||'';
  f.is_active.checked=Number(p.is_active)===1;
  f.allow_repeat.checked=Number(p.allow_repeat)===1;
  f.access_type.value=p.access_type||'all';
  f.single_user_id.value=p.single_user_id||'';
  document.getElementById('singleUserId').style.display = f.access_type.value === 'single' ? '' : 'none';
  f.delivery_type.value=p.delivery_type||'auto';
  document.getElementById('saveBtn').textContent='تحديث المنتج';
  window.scrollTo({top:0,behavior:'smooth'});
}

async function delProduct(id){
  if(!confirm('حذف المنتج؟')) return;
  const fd=new FormData();
  fd.append('action','delete_product');
  fd.append('product_id',id);
  const out=await post(fd);
  alert(out.message||'done');
  if(out.ok) location.reload();
}
</script>
</body></html>
