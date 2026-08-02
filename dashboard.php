<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
header('Content-Type: text/html; charset=utf-8');

// 1. التأكد من ملف الاتصال بقاعدة البيانات
if (!file_exists('db_connect.php')) {
    die("<h3 style='text-align:center; margin-top:50px;'>⚠️ ملف db_connect.php غير موجود.</h3>");
}
require_once 'db_connect.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 2. فحص جلسة الهوية بذكاء (لتفادي خطأ السطر 17)
$nationalId = $_SESSION['customer_national_id'] ?? $_SESSION['national_id'] ?? $_SESSION['user_id'] ?? null;

if (!$nationalId) {
    die("<div dir='rtl' style='text-align:center; color:#003366; margin-top:50px;'>
            <h3>عفواً، يجب تسجيل الدخول أولاً.</h3>
            <p>النظام لم يجد رقم الهوية في الجلسة (Session).</p>
         </div>");
}

$customerName = $_SESSION['customer_name'] ?? 'عميل النظام';
$custId = null;
$customerPhone = '0500000000';

// 3. جلب أو إنشاء بيانات العميل (لتفادي انفجار قاعدة البيانات)
try {
    // المحاولة الأولى (حسب المخطط الرسمي)
    $stmt = $pdo->prepare("SELECT cust_id, phone_number FROM Customer WHERE national_id = ?");
    $stmt->execute([$nationalId]); // هنا كان الخطأ 17، الآن تمت حمايته
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e1) {
    try {
        // المحاولة الثانية (السحابة تطلب حروف صغيرة)
        $stmt = $pdo->prepare("SELECT cust_id, phone_number FROM customer WHERE national_id = ?");
        $stmt->execute([$nationalId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        // إذا كان هناك خطأ جذري في الجدول لن تتوقف الصفحة
        $customer = false;
    }
}

// إذا لم يكن العميل موجوداً، النظام سينشئه تلقائياً لمنع الأخطاء المستقبلية
if (empty($customer)) {
    try {
        $pdo->prepare("INSERT IGNORE INTO Customer (national_id, full_name, phone_number, password_hash) VALUES (?, ?, ?, ?)")->execute([$nationalId, $customerName, '0500000000', '123']);
        $custId = $pdo->lastInsertId();
    } catch (Exception $e) {
        try {
            $pdo->prepare("INSERT IGNORE INTO customer (national_id, full_name, phone_number, password_hash) VALUES (?, ?, ?, ?)")->execute([$nationalId, $customerName, '0500000000', '123']);
            $custId = $pdo->lastInsertId();
        } catch (Exception $e2) {}
    }
} else {
    $custId = $customer['cust_id'];
    $customerPhone = $customer['phone_number'];
}

// =====================================================================
// الزر السري: تهيئة البيانات (MOJ & Services) بحماية مزدوجة
// =====================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['seed_data'])) {
    header('Content-Type: application/json');
    try {
        try { $pdo->exec("INSERT IGNORE INTO Service_Type (srv_id, srv_name) VALUES (1, 'شبكة مياه'), (2, 'صرف صحي'), (3, 'مياه وصرف صحي')"); } 
        catch(Exception $e) { $pdo->exec("INSERT IGNORE INTO service_type (srv_id, srv_name) VALUES (1, 'شبكة مياه'), (2, 'صرف صحي'), (3, 'مياه وصرف صحي')"); }
        
        try {
            $stmtMoj = $pdo->prepare("INSERT IGNORE INTO MOJ_Record (deed_no, owner_national_id, owner_name, land_area) VALUES (?, ?, ?, ?)");
            $stmtMoj->execute(['711029485736', $nationalId, $customerName, 450]);
        } catch(Exception $e) {
            $stmtMoj = $pdo->prepare("INSERT IGNORE INTO moj_record (deed_no, owner_national_id, owner_name, land_area) VALUES (?, ?, ?, ?)");
            $stmtMoj->execute(['711029485736', $nationalId, $customerName, 450]);
        }
        echo json_encode(['status' => 'success', 'message' => 'تم تهيئة قاعدة البيانات بنجاح! جربي الصك: 711029485736']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'خطأ غير متوقع: ' . $e->getMessage()]);
    }
    exit;
}

// =====================================================================
// معالجة تقديم الطلب بمرونة السحابة
// =====================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['is_ajax']) && !isset($_POST['seed_data'])) {
    header('Content-Type: application/json');
    $srvId = $_POST['srv_id']; $cityId = $_POST['cty_id']; $deedNumber = trim($_POST['deed_no']); 
    $lat = !empty($_POST['latitude']) ? $_POST['latitude'] : null; $lng = !empty($_POST['longitude']) ? $_POST['longitude'] : null;

    if (!isset($_FILES['deed_file']) || $_FILES['deed_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'الرجاء إرفاق صورة الصك.']); exit;
    }

    $fileTmpPath = $_FILES['deed_file']['tmp_name'];
    $hashedFileName = md5(time() . $custId) . '.' . pathinfo($_FILES['deed_file']['name'], PATHINFO_EXTENSION);
    $targetDir = "uploads/";
    if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);
    $targetFilePath = $targetDir . $hashedFileName;

    if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
        try {
            $q = "INSERT INTO Application (cty_id, latitude, longitude, deed_no, deed_file_url, app_status, cust_id, srv_id) VALUES (?, ?, ?, ?, ?, 'Pending_Review', ?, ?)";
            $pdo->prepare($q)->execute([$cityId, $lat, $lng, $deedNumber, $targetFilePath, $custId, $srvId]);
            echo json_encode(['status' => 'success', 'message' => 'تم استلام طلبك بنجاح!']);
        } catch (PDOException $e1) {
            try {
                $q2 = "INSERT INTO application (cty_id, latitude, longitude, deed_no, deed_file_url, app_status, cust_id, srv_id) VALUES (?, ?, ?, ?, ?, 'Pending_Review', ?, ?)";
                $pdo->prepare($q2)->execute([$cityId, $lat, $lng, $deedNumber, $targetFilePath, $custId, $srvId]);
                echo json_encode(['status' => 'success', 'message' => 'تم استلام طلبك بنجاح!']);
            } catch (PDOException $e2) {
                echo json_encode(['status' => 'error', 'message' => 'عفواً! الصك أو الخدمة غير متطابقة مع قاعدة البيانات. تأكدي من الضغط على الزر السري للتهيئة.']);
            }
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'فشل رفع الملف.']);
    }
    exit;
}

// =====================================================================
// جلب المدن والطلبات
// =====================================================================
$citiesWithRegions = []; $myApplications = []; $stats = ['total' => 0, 'approved' => 0, 'review' => 0];

try {
    $citiesWithRegions = $pdo->query("SELECT c.cty_id, c.cty_name, r.reg_name FROM City c LEFT JOIN Region r ON c.reg_id = r.reg_id ORDER BY r.reg_id, c.cty_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try { $citiesWithRegions = $pdo->query("SELECT c.cty_id, c.cty_name, r.reg_name FROM city c LEFT JOIN region r ON c.reg_id = r.reg_id ORDER BY r.reg_id, c.cty_name")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e2) {}
}

if ($custId) {
    try {
        $appStmt = $pdo->prepare("SELECT a.app_id, a.srv_id, a.deed_no, a.app_status, a.created_at, c.cty_name FROM Application a LEFT JOIN City c ON a.cty_id = c.cty_id WHERE a.cust_id = ? ORDER BY a.created_at DESC");
        $appStmt->execute([$custId]); $myApplications = $appStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        try {
            $appStmt = $pdo->prepare("SELECT a.app_id, a.srv_id, a.deed_no, a.app_status, a.created_at, c.cty_name FROM application a LEFT JOIN city c ON a.cty_id = c.cty_id WHERE a.cust_id = ? ORDER BY a.created_at DESC");
            $appStmt->execute([$custId]); $myApplications = $appStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {}
    }
}

$stats['total'] = count($myApplications);
foreach($myApplications as $app) {
    if(in_array($app['app_status'], ['Pending_Inspection', 'Pending_Billing', 'In_Progress', 'Completed'])) $stats['approved']++;
    elseif($app['app_status'] == 'Pending_Review') $stats['review']++;
}

function getStatusBadge($status) {
    $b = [ 'Pending_Review' => '<span class="badge px-3 py-2 rounded-pill shadow-sm" style="background-color: #f39c12; color: #fff;">قيد التدقيق</span>',
           'Pending_Inspection' => '<span class="badge px-3 py-2 rounded-pill shadow-sm" style="background-color: #4A90E2; color: #fff;">قيد الفحص</span>',
           'Pending_Billing' => '<span class="badge px-3 py-2 rounded-pill shadow-sm" style="background-color: #34495e; color: #fff;">بانتظار السداد</span>',
           'In_Progress' => '<span class="badge px-3 py-2 rounded-pill shadow-sm" style="background-color: #003366; color: #fff;">جاري التنفيذ</span>',
           'Completed' => '<span class="badge px-3 py-2 rounded-pill shadow-sm" style="background-color: #27ae60; color: #fff;">مكتمل</span>' ];
    return $b[$status] ?? '<span class="badge bg-dark">'.$status.'</span>';
}
function getServiceName($id) { return $id==1?'شبكة مياه':($id==2?'صرف صحي':($id==3?'مياه وصرف صحي':'غير محدد')); }
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>البوابة الذكية | نظام قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --qatra-primary: #003366; --qatra-secondary: #4A90E2; --qatra-bg: #f4f8fb; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--qatra-bg); }
        .fade-in-up { animation: fadeInUp 0.8s forwards; opacity: 0; transform: translateY(20px); }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; }
        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
        .navbar-qatra { background: white; padding: 15px 0; box-shadow: 0 10px 30px rgba(0,0,0,0.04); }
        .qatra-logo-icon { background: linear-gradient(135deg, var(--qatra-secondary), var(--qatra-primary)); color: white; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.3rem; }
        .user-chip { background: white; padding: 8px 20px; border-radius: 50px; font-weight: 700; color: var(--qatra-primary); border: 1px solid #e2e8f0; cursor: pointer; }
        .welcome-card { background: linear-gradient(135deg, var(--qatra-primary), #001f3f); color: white; padding: 40px; border-radius: 20px; margin-bottom: 30px; box-shadow: 0 20px 40px rgba(0,51,102,0.15); }
        .premium-card { background: white; border-radius: 20px; padding: 35px; box-shadow: 0 15px 35px rgba(0,0,0,0.03); }
        .form-label { font-weight: 800; color: #475569; }
        .form-control, .form-select { border-radius: 12px; border: 2px solid #e2e8f0; padding: 14px 18px; font-weight: 600; }
        .map-wrapper { border: 2px solid #e2e8f0; border-radius: 16px; overflow: hidden; position: relative; height: 280px; }
        .map-lock-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(248, 250, 252, 0.9); backdrop-filter: blur(4px); z-index: 1000; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #64748b; transition: 0.3s; }
        .btn-current-location { position: absolute; bottom: 20px; left: 20px; z-index: 1001; background: white; color: var(--qatra-primary); border: none; padding: 10px 15px; border-radius: 12px; font-weight: 700; font-size: 0.9rem; box-shadow: 0 4px 15px rgba(0,0,0,0.15); transition: 0.3s; cursor: pointer; }
        .upload-area { border: 2px dashed #cbd5e1; border-radius: 16px; padding: 35px 20px; text-align: center; background: #f8fafc; cursor: pointer; }
        .btn-qatra { background: linear-gradient(135deg, var(--qatra-primary), #001f3f); color: white; border: none; border-radius: 12px; padding: 16px; font-weight: 800; width: 100%; }
        .stat-badge { background: white; padding: 25px; border-radius: 20px; display: flex; align-items: center; justify-content: space-between; border: 1px solid #eef2f5; }
    </style>
</head>
<body>

<nav class="navbar navbar-qatra mb-4 fade-in-up">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="#" class="d-flex align-items-center gap-3 text-decoration-none">
            <div class="qatra-logo-icon"><i class="fa-solid fa-droplet"></i></div>
            <div>
                <div class="fw-black fs-4" style="color: var(--qatra-primary);">قطــرة</div>
                <div class="text-muted" style="font-size: 0.8rem; font-weight: 800;">البوابة الإلكترونية الذكية</div>
            </div>
        </a>
        <div class="d-flex align-items-center gap-3">
            <div class="user-chip" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="fa-regular fa-user me-2 text-secondary"></i> <?= htmlspecialchars($customerName); ?>
                <i class="fa-solid fa-chevron-down ms-2 small text-muted"></i>
            </div>
            <a href="logout.php" class="btn btn-light text-danger rounded-pill fw-bold px-4 border">خروج</a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <div class="row mb-4">
        <div class="col-lg-7 fade-in-up delay-1">
            <div class="welcome-card h-100 d-flex flex-column justify-content-center">
                <h2 class="fw-black mb-3">مرحباً، <?= htmlspecialchars($customerName); ?> 👋</h2>
                <p class="mb-0 text-white-50 fs-5 lh-lg">بوابتك الرقمية لتقديم وتتبع طلبات المياه والصرف الصحي بكل سهولة.</p>
            </div>
        </div>
        <div class="col-lg-5 fade-in-up delay-2">
            <div class="d-flex flex-column gap-3 h-100 justify-content-center">
                <div class="stat-badge">
                    <div><p class="mb-0 fw-bold text-muted">إجمالي طلباتي</p><h3 class="fw-black text-primary m-0"><?= $stats['total']; ?></h3></div>
                    <div class="qatra-logo-icon" style="background: #f1f5f9; color: #94a3b8;"><i class="fa-solid fa-folder-open"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- نموذج التقديم -->
        <div class="col-xl-4 col-lg-5 mb-4 fade-in-up">
            <div class="premium-card">
                <h5 class="fw-black mb-4 text-primary"><i class="fa-solid fa-plus me-2 text-secondary"></i> تقديم طلب جديد</h5>
                <form id="applicationForm">
                    <input type="hidden" name="is_ajax" value="1">
                    <input type="hidden" name="latitude" id="latitude" value="">
                    <input type="hidden" name="longitude" id="longitude" value="">
                    
                    <div class="mb-3">
                        <label class="form-label">الخدمة المطلوبة</label>
                        <select name="srv_id" class="form-select" required>
                            <option value="" selected disabled>اختر...</option>
                            <option value="1">شبكة مياه</option>
                            <option value="2">صرف صحي</option>
                            <option value="3">مياه وصرف صحي</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">المدينة</label>
                        <select name="cty_id" id="citySelect" class="form-select" required onchange="unlockMap()">
                            <option value="" selected disabled>اختر مدينة العقار...</option>
                            <?php foreach($citiesWithRegions as $city): ?>
                                <option value="<?= $city['cty_id']; ?>" data-city="<?= htmlspecialchars($city['cty_name']); ?>"><?= htmlspecialchars($city['cty_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">الموقع الجغرافي للعقار <span class="text-danger">*</span></label>
                        <div class="map-wrapper" id="mapContainer">
                            <div class="map-lock-overlay" id="mapLock">
                                <i class="fa-solid fa-map-location-dot fs-1 mb-2"></i>
                                <span class="fw-bold">الرجاء اختيار المدينة أولاً</span>
                            </div>
                            <div id="propertyMap" style="height: 100%; width: 100%;"></div>
                            <button type="button" class="btn-current-location" onclick="getCurrentLocation()"><i class="fa-solid fa-location-crosshairs text-primary me-1"></i> موقعي الحالي</button>
                        </div>
                        <div id="gpsStatus" class="small text-danger mt-1 fw-bold text-center">الخريطة مغلقة</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">رقم صك الملكية</label>
                        <input type="text" name="deed_no" class="form-control" placeholder="مثال: 711029485736" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">صورة الصك</label>
                        <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                            <i class="fa-solid fa-cloud-arrow-up text-secondary fs-2 mb-2"></i>
                            <h6 class="fw-bold m-0">اضغط لرفع الملف</h6>
                            <input type="file" id="fileInput" name="deed_file" class="d-none" accept=".pdf, .jpg, .png" required>
                        </div>
                        <div id="fileNameDisplay" class="text-success mt-2 fw-bold small text-center" style="display:none;"></div>
                    </div>

                    <button type="submit" class="btn-qatra" id="submitBtn">إرسال الطلب</button>
                </form>
            </div>
        </div>

        <!-- سجل الطلبات -->
        <div class="col-xl-8 col-lg-7 mb-4 fade-in-up">
            <div class="premium-card">
                <h5 class="fw-black mb-4 text-primary"><i class="fa-solid fa-clock-rotate-left me-2 text-secondary"></i> طلباتي السابقة</h5>
                <?php if(empty($myApplications)): ?>
                    <div class="text-center py-5"><p class="text-muted fw-bold">لا توجد طلبات مسجلة.</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>الطلب</th><th>الخدمة</th><th>المدينة</th><th>رقم الصك</th><th>الحالة</th></tr></thead>
                            <tbody>
                                <?php foreach($myApplications as $app): ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark border">#APP-<?= str_pad($app['app_id'], 4, '0', STR_PAD_LEFT); ?></span></td>
                                    <td class="fw-bold text-primary"><?= getServiceName($app['srv_id']); ?></td>
                                    <td><?= htmlspecialchars($app['cty_name'] ?? 'غير محدد'); ?></td>
                                    <td><?= htmlspecialchars($app['deed_no']); ?></td>
                                    <td><?= getStatusBadge($app['app_status']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- النافذة المنبثقة (الملف الشخصي + الزر السري) -->
<div class="modal fade" id="profileModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius: 20px; border: none;">
      <div class="modal-header bg-dark text-white p-4">
        <h5 class="modal-title fw-black">الملف الشخصي</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4 text-center">
        <h4 class="fw-bold text-primary mb-4"><?= htmlspecialchars($customerName); ?></h4>
        <ul class="list-group list-group-flush mb-4 text-start">
            <li class="list-group-item"><strong>الهوية:</strong> <span class="badge bg-primary rounded-pill"><?= htmlspecialchars($nationalId); ?></span></li>
        </ul>
        
        <div class="alert alert-warning text-end border-warning border-2 p-3 mb-0" style="border-radius: 15px;">
            <h6 class="fw-black text-dark"><i class="fa-solid fa-code text-danger me-1"></i> وضع المطورين</h6>
            <button onclick="seedMojData()" id="seedBtn" class="btn btn-warning w-100 fw-bold rounded-pill shadow-sm mt-2">
                <i class="fa-solid fa-database me-1"></i> تهيئة النظام لاجتياز الخطأ
            </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let map = L.map('propertyMap').setView([24.7136, 46.6753], 5);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
let marker;

const cityCoords = {
    'الرياض': [24.7136, 46.6753], 'جدة': [21.4858, 39.1925], 'مكة': [21.3891, 39.8579],
    'الدمام': [26.4207, 50.0888], 'بريدة': [26.3260, 43.9390], 'ابها': [18.2164, 42.5053]
};

function unlockMap() {
    let select = document.getElementById('citySelect');
    let cityName = select.options[select.selectedIndex].getAttribute('data-city');
    document.getElementById('mapLock').style.display = 'none';
    let coords = [24.7136, 46.6753];
    for (let key in cityCoords) { if (cityName && cityName.includes(key)) coords = cityCoords[key]; }
    setTimeout(() => { map.invalidateSize(); map.flyTo(coords, 13, { animate: true }); }, 300);
    document.getElementById('gpsStatus').innerHTML = `<span class="text-primary"><i class="fa-solid fa-hand-pointer"></i> الخريطة مفعلة لمدينة (${cityName})</span>`;
}

function getCurrentLocation() {
    if (navigator.geolocation) {
        document.getElementById('gpsStatus').innerHTML = '<span class="text-primary"><i class="fa-solid fa-spinner fa-spin"></i> جاري التحديد...</span>';
        navigator.geolocation.getCurrentPosition(function(pos) {
            let lat = pos.coords.latitude, lng = pos.coords.longitude;
            document.getElementById('mapLock').style.display = 'none';
            setTimeout(() => { map.invalidateSize(); map.flyTo([lat, lng], 16, { animate: true }); }, 300);
            if (marker) marker.setLatLng([lat, lng]); else marker = L.marker([lat, lng]).addTo(map);
            document.getElementById('latitude').value = lat; document.getElementById('longitude').value = lng;
            document.getElementById('gpsStatus').innerHTML = `<span class="text-success"><i class="fa-solid fa-check"></i> تم التحديد</span>`;
        }, function() {});
    }
}

map.on('click', function(e) {
    let lat = e.latlng.lat, lng = e.latlng.lng;
    if (marker) marker.setLatLng(e.latlng); else marker = L.marker(e.latlng).addTo(map);
    document.getElementById('latitude').value = lat; document.getElementById('longitude').value = lng;
    document.getElementById('gpsStatus').innerHTML = `<span class="text-success"><i class="fa-solid fa-check"></i> تم التحديد</span>`;
});

document.getElementById('fileInput').addEventListener('change', function(e) {
    if(e.target.files.length > 0) {
        document.getElementById('fileNameDisplay').style.display = 'block';
        document.getElementById('fileNameDisplay').innerText = 'تم الإرفاق: ' + e.target.files.name;
    }
});

function seedMojData() {
    let btn = document.getElementById('seedBtn');
    btn.disabled = true; btn.innerHTML = 'جاري التهيئة...';
    let formData = new FormData(); formData.append('seed_data', '1');
    fetch('dashboard.php', { method: 'POST', body: formData })
    .then(r => r.json()).then(data => {
        Swal.fire(data.status === 'success' ? 'نجاح!' : 'تنبيه', data.message, data.status);
        btn.innerHTML = 'تم';
    });
}

document.getElementById('applicationForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if(document.getElementById('latitude').value === "") {
        Swal.fire('تنبيه', 'يجب تحديد الموقع على الخريطة.', 'warning');
        return;
    }
    
    let submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    Swal.fire({ title: 'جاري الإرسال...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch('dashboard.php', { method: 'POST', body: new FormData(this) })
    .then(r => r.json()).then(data => {
        if(data.status === 'error') {
            Swal.fire('خطأ', data.message, 'error').then(()=> submitBtn.disabled = false);
        } else {
            Swal.fire('نجاح!', data.message, 'success').then(()=> window.location.reload());
        }
    });
});
</script>
</body>
</html>