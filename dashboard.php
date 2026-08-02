<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
header('Content-Type: text/html; charset=utf-8');

// 1. الاتصال بقاعدة البيانات
if (!file_exists('db_connect.php')) {
    die("<h3 style='text-align:center; margin-top:50px;'>⚠️ ملف الاتصال بقاعدة البيانات مفقود.</h3>");
}
require_once 'db_connect.php';

// 2. التحقق من جلسة العميل
if (!isset($_SESSION['customer_national_id'])) {
    die("<div style='text-align:center; font-family:Tahoma; margin-top:50px; color:#003366;'>
            <h3>الرجاء تسجيل الدخول أولاً للوصول إلى البوابة.</h3>
         </div>");
}

$nationalId = $_SESSION['customer_national_id'];
$customerName = $_SESSION['customer_name'] ?? 'عميلنا العزيز';

// 3. جلب بيانات العميل الحقيقية (تم تعديل Customer إلى customer لتجنب الخطأ)
$stmt = $pdo->prepare("SELECT cust_id, full_name, phone_number FROM customer WHERE national_id = ?");
$stmt->execute([$nationalId]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die("<h3 style='text-align:center; color:red; margin-top:50px;'>عفواً، لم يتم العثور على بيانات العميل.</h3>");
}
$custId = $customer['cust_id'];

// =====================================================================
// 4. معالجة إرسال الطلب 
// =====================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['is_ajax'])) {
    header('Content-Type: application/json');
    
    $srvId = $_POST['srv_id']; 
    $cityId = $_POST['cty_id']; 
    $deedNumber = trim($_POST['deed_no']); 
    $lat = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
    $lng = !empty($_POST['longitude']) ? $_POST['longitude'] : null;

    // التأكد من أن الصك مسجل فعلياً (تم التعديل إلى moj_record)
    try {
        $checkMoj = $pdo->prepare("SELECT COUNT(*) FROM moj_record WHERE deed_no = ?");
        $checkMoj->execute([$deedNumber]);
        if ($checkMoj->fetchColumn() == 0) {
            echo json_encode(['status' => 'error', 'message' => 'عفواً، رقم الصك المدخل غير متطابق مع سجلات وزارة العدل. يرجى التأكد من الرقم.']);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ في التحقق من الصك.']);
        exit;
    }

    if (!isset($_FILES['deed_file']) || $_FILES['deed_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'يرجى إرفاق صورة صك الملكية.']);
        exit;
    }

    // رفع الملف
    $fileTmpPath = $_FILES['deed_file']['tmp_name'];
    $hashedFileName = md5(time() . $custId) . '.' . pathinfo($_FILES['deed_file']['name'], PATHINFO_EXTENSION);
    $targetDir = "uploads/";
    if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);
    $targetFilePath = $targetDir . $hashedFileName;

    if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
        try {
            // إدراج الطلب (تم التعديل إلى application)
            $q = "INSERT INTO application (cty_id, latitude, longitude, deed_no, deed_file_url, app_status, cust_id, srv_id) 
                  VALUES (?, ?, ?, ?, ?, 'Pending_Review', ?, ?)";
            $pdo->prepare($q)->execute([$cityId, $lat, $lng, $deedNumber, $targetFilePath, $custId, $srvId]);
            
            echo json_encode(['status' => 'success', 'message' => 'تم استلام طلبك بنجاح وجاري مراجعته.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء حفظ الطلب في النظام.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'فشل في رفع المرفقات، يرجى المحاولة لاحقاً.']);
    }
    exit;
}

// =====================================================================
// 5. جلب البيانات الأساسية للواجهة
// =====================================================================
$citiesWithRegions = [];
$services = [];
$myApplications = [];
$stats = ['total' => 0];

try {
    // جلب المدن (تم تعديل city و region)
    $citiesWithRegions = $pdo->query("SELECT c.cty_id, c.cty_name, r.reg_name FROM city c JOIN region r ON c.reg_id = r.reg_id ORDER BY r.reg_id, c.cty_name")->fetchAll(PDO::FETCH_ASSOC);
    
    // جلب الخدمات الديناميكية (تم تعديل service_type)
    $services = $pdo->query("SELECT srv_id, srv_name FROM service_type")->fetchAll(PDO::FETCH_ASSOC);

    // جلب طلبات العميل
    $appStmt = $pdo->prepare("SELECT a.app_id, s.srv_name, a.deed_no, a.app_status, a.created_at, c.cty_name 
                              FROM application a 
                              JOIN city c ON a.cty_id = c.cty_id 
                              JOIN service_type s ON a.srv_id = s.srv_id
                              WHERE a.cust_id = ? ORDER BY a.created_at DESC");
    $appStmt->execute([$custId]);
    $myApplications = $appStmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['total'] = count($myApplications);
} catch (PDOException $e) {}

function getStatusBadge($status) {
    $badges = [
        'Pending_Review' => '<span class="badge badge-warning"><i class="fa-solid fa-clock me-1"></i> قيد المراجعة</span>',
        'Pending_Inspection' => '<span class="badge badge-info"><i class="fa-solid fa-truck-fast me-1"></i> الفحص الميداني</span>',
        'Pending_Billing' => '<span class="badge badge-dark"><i class="fa-solid fa-file-invoice-dollar me-1"></i> بانتظار السداد</span>',
        'In_Progress' => '<span class="badge badge-primary"><i class="fa-solid fa-person-digging me-1"></i> جاري التنفيذ</span>',
        'Completed' => '<span class="badge badge-success"><i class="fa-solid fa-check-double me-1"></i> مكتمل</span>',
        'Rejected' => '<span class="badge badge-danger"><i class="fa-solid fa-xmark me-1"></i> مرفوض</span>'
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">'.$status.'</span>';
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>البوابة الإلكترونية | نظام إدارة الطلبات</title>
    <!-- الخطوط والتنسيقات -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <!-- الخريطة -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root { 
            --main-dark: #002d5c;    /* أزرق عميق فخم */
            --main-light: #009FE3;   /* سماوي نقي */
            --bg-color: #f5f7fa; 
        }
        body { 
            font-family: 'Cairo', sans-serif; 
            background-color: var(--bg-color); 
            color: #334155; 
        }
        
        /* تأثيرات الظهور */
        .fade-in { animation: fadeIn 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; opacity: 0; transform: translateY(15px); }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; }
        @keyframes fadeIn { to { opacity: 1; transform: translateY(0); } }

        /* الشريط العلوي */
        .navbar-custom { 
            background: white; 
            padding: 15px 0; 
            box-shadow: 0 4px 20px rgba(0, 45, 92, 0.05); 
            border-bottom: 2px solid #eef2f6;
        }
        .logo-icon { 
            background: linear-gradient(135deg, var(--main-light), var(--main-dark)); 
            color: white; 
            width: 48px; height: 48px; 
            display: flex; align-items: center; justify-content: center; 
            border-radius: 14px; font-size: 1.5rem; 
            box-shadow: 0 8px 15px rgba(0, 159, 227, 0.2); 
        }
        .user-chip { 
            background: #f8fafc; padding: 8px 24px; border-radius: 50px; 
            font-weight: 700; color: var(--main-dark); border: 1px solid #e2e8f0; 
            display: flex; align-items: center; gap: 10px;
        }
        
        /* البطاقة الترحيبية */
        .welcome-card { 
            background: linear-gradient(135deg, var(--main-dark) 0%, #001a35 100%); 
            color: white; padding: 40px; border-radius: 24px; 
            box-shadow: 0 20px 40px rgba(0, 45, 92, 0.15); 
            position: relative; overflow: hidden;
        }
        .welcome-card::before {
            content: ''; position: absolute; top: -50px; right: -50px;
            width: 200px; height: 200px; border-radius: 50%;
            background: radial-gradient(circle, rgba(0,159,227,0.2) 0%, rgba(0,0,0,0) 70%);
        }

        /* حاويات المحتوى */
        .glass-card { 
            background: white; border-radius: 24px; padding: 35px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.03); border: 1px solid #edf2f7;
            height: 100%;
        }
        .section-title { 
            color: var(--main-dark); font-weight: 900; font-size: 1.3rem; 
            margin-bottom: 25px; display: flex; align-items: center; gap: 12px; 
        }
        .section-title i { color: var(--main-light); }

        /* النماذج والمدخلات */
        .form-label { font-weight: 700; color: #475569; font-size: 0.95rem; }
        .form-control, .form-select { 
            border-radius: 12px; border: 2px solid #e2e8f0; 
            padding: 14px 18px; font-weight: 600; color: #1e293b; background: #f8fafc; 
            transition: all 0.3s; 
        }
        .form-control:focus, .form-select:focus { 
            border-color: var(--main-light); background: white; 
            box-shadow: 0 0 0 4px rgba(0, 159, 227, 0.1); outline: none; 
        }

        /* الخريطة والرفع */
        .map-wrapper { 
            border: 2px solid #e2e8f0; border-radius: 16px; overflow: hidden; 
            position: relative; height: 250px; 
        }
        .map-lock-overlay { 
            position: absolute; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(5px); 
            z-index: 1000; display: flex; flex-direction: column; 
            align-items: center; justify-content: center; color: var(--main-dark); 
        }
        .upload-area { 
            border: 2px dashed #cbd5e1; border-radius: 16px; padding: 30px 20px; 
            text-align: center; background: #f8fafc; cursor: pointer; transition: 0.3s; 
        }
        .upload-area:hover { border-color: var(--main-light); background: #f0f9ff; }
        .upload-area i { color: var(--main-light); font-size: 2rem; margin-bottom: 10px; }
        
        /* الأزرار والشارات */
        .btn-submit { 
            background: linear-gradient(135deg, var(--main-light), var(--main-dark)); 
            color: white; border: none; border-radius: 12px; padding: 16px; 
            font-weight: 800; font-size: 1.1rem; width: 100%; transition: 0.3s; 
            box-shadow: 0 8px 20px rgba(0, 45, 92, 0.15); 
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(0, 45, 92, 0.25); }
        
        .badge-warning { background-color: #f59e0b; color: white; padding: 8px 12px; border-radius: 8px; font-weight: 700; }
        .badge-info { background-color: var(--main-light); color: white; padding: 8px 12px; border-radius: 8px; font-weight: 700; }
        .badge-primary { background-color: var(--main-dark); color: white; padding: 8px 12px; border-radius: 8px; font-weight: 700; }
        .badge-success { background-color: #10b981; color: white; padding: 8px 12px; border-radius: 8px; font-weight: 700; }
        .badge-dark { background-color: #334155; color: white; padding: 8px 12px; border-radius: 8px; font-weight: 700; }
        .badge-danger { background-color: #ef4444; color: white; padding: 8px 12px; border-radius: 8px; font-weight: 700; }

        .table-modern th { border-bottom: 2px solid #f1f5f9; color: #64748b; font-weight: 800; padding: 15px; font-size: 0.95rem; }
        .table-modern td { padding: 18px 15px; border-bottom: 1px solid #f8fafc; vertical-align: middle; font-weight: 700; color: #1e293b; }
    </style>
</head>
<body>

<!-- الشريط العلوي -->
<nav class="navbar navbar-custom mb-4 fade-in">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="#" class="d-flex align-items-center gap-3 text-decoration-none">
            <div class="logo-icon"><i class="fa-solid fa-droplet"></i></div>
            <div>
                <div class="fw-black fs-4" style="color: var(--main-dark); line-height: 1.2;">البوابة الإلكترونية</div>
                <div class="text-muted" style="font-size: 0.85rem; font-weight: 700;">خدمات المياه والصرف الصحي</div>
            </div>
        </a>
        <div class="d-flex align-items-center gap-3">
            <div class="user-chip">
                <i class="fa-regular fa-user text-muted"></i> <?= htmlspecialchars($customer['full_name'] ?? $customerName); ?>
            </div>
            <a href="logout.php" class="btn btn-light text-danger rounded-circle p-2 shadow-sm border" title="تسجيل الخروج"><i class="fa-solid fa-power-off"></i></a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    
    <!-- الترحيب والإحصائيات -->
    <div class="row mb-4">
        <div class="col-lg-8 fade-in delay-1">
            <div class="welcome-card h-100 d-flex flex-column justify-content-center">
                <h2 class="fw-black mb-2">مرحباً بك، <?= htmlspecialchars($customer['full_name'] ?? $customerName); ?></h2>
                <p class="mb-0 text-white-50 fs-5">من خلال هذه البوابة يمكنك تقديم وتتبع طلبات الخدمات العقارية الخاصة بك بكل يسر وسهولة.</p>
            </div>
        </div>
        <div class="col-lg-4 fade-in delay-2">
            <div class="glass-card d-flex align-items-center justify-content-between p-4">
                <div>
                    <h6 class="text-muted fw-bold mb-1">إجمالي طلباتك</h6>
                    <h2 class="fw-black m-0" style="color: var(--main-dark); font-size: 2.5rem;"><?= $stats['total']; ?></h2>
                </div>
                <div class="logo-icon" style="width: 70px; height: 70px; font-size: 2rem; background: #f0f9ff; color: var(--main-light); box-shadow: none;">
                    <i class="fa-regular fa-folder-open"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- نموذج تقديم الطلب -->
        <div class="col-xl-4 col-lg-5 mb-4 fade-in delay-2">
            <div class="glass-card">
                <div class="section-title"><i class="fa-solid fa-file-pen"></i> تقديم طلب خدمة جديد</div>
                
                <form id="applicationForm">
                    <input type="hidden" name="is_ajax" value="1">
                    <input type="hidden" name="latitude" id="latitude" value="">
                    <input type="hidden" name="longitude" id="longitude" value="">
                    
                    <div class="mb-3">
                        <label class="form-label">نوع الخدمة المطلوبة</label>
                        <select name="srv_id" class="form-select" required>
                            <option value="" selected disabled>الرجاء الاختيار...</option>
                            <?php foreach($services as $srv): ?>
                                <option value="<?= $srv['srv_id']; ?>"><?= htmlspecialchars($srv['srv_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">المدينة</label>
                        <select name="cty_id" id="citySelect" class="form-select" required onchange="unlockMap()">
                            <option value="" selected disabled>اختر المدينة لتحديد الموقع...</option>
                            <?php 
                            $currentRegion = '';
                            foreach($citiesWithRegions as $city): 
                                if ($currentRegion != $city['reg_name']) {
                                    if ($currentRegion != '') echo '</optgroup>';
                                    $currentRegion = $city['reg_name'];
                                    echo '<optgroup label="📍 ' . htmlspecialchars($currentRegion) . '">';
                                }
                            ?>
                                <option value="<?= $city['cty_id']; ?>" data-city="<?= htmlspecialchars($city['cty_name']); ?>">&nbsp;&nbsp;&nbsp;<?= htmlspecialchars($city['cty_name']); ?></option>
                            <?php endforeach; if ($currentRegion != '') echo '</optgroup>'; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">الموقع الجغرافي للعقار <span class="text-danger">*</span></label>
                        <div class="map-wrapper" id="mapContainer">
                            <div class="map-lock-overlay" id="mapLock">
                                <i class="fa-solid fa-map-location-dot fs-2 mb-2 text-muted"></i>
                                <span class="fw-bold small">اختر المدينة لتفعيل الخريطة</span>
                            </div>
                            <div id="propertyMap" style="height: 100%; width: 100%;"></div>
                        </div>
                        <div id="gpsStatus" class="small text-muted mt-2 fw-bold text-center"><i class="fa-solid fa-circle-info"></i> الخريطة بانتظار التحديد</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">رقم صك الملكية</label>
                        <input type="text" name="deed_no" class="form-control" placeholder="أدخل رقم الصك بدقة" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">صورة الصك (مرفق)</label>
                        <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <h6 class="fw-bold m-0 text-dark">انقر لرفع المستند</h6>
                            <span class="small text-muted fw-bold">صيغ PDF أو صور</span>
                            <input type="file" id="fileInput" name="deed_file" class="d-none" accept=".pdf, .jpg, .jpeg, .png" required>
                        </div>
                        <div id="fileNameDisplay" class="text-success mt-2 fw-bold small text-center" style="display:none;"></div>
                    </div>

                    <button type="submit" class="btn-submit" id="submitBtn">
                        إرسال الطلب <i class="fa-solid fa-arrow-left ms-2"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- سجل الطلبات -->
        <div class="col-xl-8 col-lg-7 mb-4 fade-in delay-2">
            <div class="glass-card">
                <div class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> سجل الطلبات السابقة</div>
                
                <?php if(empty($myApplications)): ?>
                    <div class="text-center py-5 mt-4">
                        <div class="mx-auto mb-3" style="width: 80px; height: 80px; background: #f8fafc; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 2rem;">
                            <i class="fa-regular fa-folder-open"></i>
                        </div>
                        <h5 class="fw-black text-dark mb-1">لا توجد طلبات مسجلة</h5>
                        <p class="text-muted small fw-bold">يمكنك البدء بتقديم طلبك الأول من القائمة الجانبية.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive mt-3">
                        <table class="table table-modern table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>نوع الخدمة</th>
                                    <th>المدينة</th>
                                    <th>رقم الصك</th>
                                    <th>حالة الطلب</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($myApplications as $app): ?>
                                <tr>
                                    <td><span class="text-muted fw-bold">#<?= str_pad($app['app_id'], 5, '0', STR_PAD_LEFT); ?></span></td>
                                    <td class="fw-black" style="color: var(--main-dark);"><?= htmlspecialchars($app['srv_name']); ?></td>
                                    <td><?= htmlspecialchars($app['cty_name']); ?></td>
                                    <td><span class="text-muted"><?= htmlspecialchars($app['deed_no']); ?></span></td>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ==========================================
// برمجة الخريطة
// ==========================================
let map = L.map('propertyMap').setView([24.7136, 46.6753], 5);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
let marker;

const cityCoords = {
    'الرياض': [24.7136, 46.6753], 'جدة': [21.4858, 39.1925], 'مكة': [21.3891, 39.8579],
    'المدينة': [24.5247, 39.5692], 'الدمام': [26.4207, 50.0888], 'بريدة': [26.3260, 43.9390],
    'ابها': [18.2164, 42.5053], 'تبوك': [28.3835, 36.5662], 'حائل': [27.5114, 41.7208]
};

function unlockMap() {
    let select = document.getElementById('citySelect');
    let cityName = select.options[select.selectedIndex].getAttribute('data-city');
    
    document.getElementById('mapLock').style.display = 'none';
    
    let coords = [24.7136, 46.6753];
    for (let key in cityCoords) { if (cityName && cityName.includes(key)) { coords = cityCoords[key]; break; } }
    
    setTimeout(() => { map.invalidateSize(); map.flyTo(coords, 13, { animate: true, duration: 1.5 }); }, 200);
    document.getElementById('gpsStatus').innerHTML = `<span class="text-primary fw-bold"><i class="fa-solid fa-hand-pointer me-1"></i> يرجى النقر على الخريطة لتحديد موقع العقار بدقة.</span>`;
}

map.on('click', function(e) {
    let lat = e.latlng.lat;
    let lng = e.latlng.lng;

    if (marker) marker.setLatLng(e.latlng);
    else marker = L.marker(e.latlng).addTo(map);

    document.getElementById('latitude').value = lat;
    document.getElementById('longitude').value = lng;
    
    document.getElementById('gpsStatus').innerHTML = `<span class="text-success fw-bold"><i class="fa-solid fa-check-circle me-1"></i> تم تحديد الموقع بنجاح.</span>`;
});

// ==========================================
// معالجة المرفقات والإرسال
// ==========================================
document.getElementById('fileInput').addEventListener('change', function(e) {
    if(e.target.files.length > 0) {
        document.getElementById('fileNameDisplay').style.display = 'block';
        document.getElementById('fileNameDisplay').innerHTML = '<i class="fa-solid fa-file-circle-check me-1"></i> تم إرفاق: ' + e.target.files.name;
        document.querySelector('.upload-area').style.borderColor = '#10b981';
        document.querySelector('.upload-area i').style.color = '#10b981';
    }
});

document.getElementById('applicationForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if(document.getElementById('latitude').value === "") {
        Swal.fire({
            icon: 'warning',
            title: 'تنبيه',
            text: 'يرجى تحديد موقع العقار على الخريطة قبل الإرسال.',
            confirmButtonColor: '#002d5c'
        });
        return;
    }
    
    let submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    
    Swal.fire({ 
        title: 'جاري معالجة الطلب...', 
        text: 'يتم الآن التحقق من البيانات',
        allowOutsideClick: false, 
        didOpen: () => { Swal.showLoading(); } 
    });

    fetch('dashboard.php', { method: 'POST', body: new FormData(this) })
    .then(response => response.json())
    .then(data => {
        if(data.status === 'error') {
            Swal.fire({
                icon: 'error',
                title: 'عذراً',
                text: data.message,
                confirmButtonColor: '#002d5c'
            }).then(() => { submitBtn.disabled = false; });
        } else {
            Swal.fire({
                icon: 'success',
                title: 'تم بنجاح',
                text: data.message,
                confirmButtonColor: '#10b981'
            }).then(() => { window.location.reload(); });
        }
    })
    .catch(error => {
        submitBtn.disabled = false;
        Swal.fire('خطأ تقني', 'حدث خطأ في الاتصال بالخادم.', 'error');
    });
});
</script>

</body>
</html>