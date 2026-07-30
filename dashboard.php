<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once 'db_connect.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['customer_national_id'])) {
    header("Location: login.php");
    exit();
}

$nationalId = $_SESSION['customer_national_id'];
$customerName = $_SESSION['customer_name'];

// =====================================================================
// جلب بيانات العميل للملف الشخصي
// =====================================================================
$customerProfile = [
    'national_id' => $nationalId, 
    'full_name' => $customerName, 
    'phone_number' => 'جاري التحديث...'
];
try {
    $profStmt = $pdo->prepare("SELECT phone_number FROM Customer WHERE national_id = :nid");
    $profStmt->execute(['nid' => $nationalId]);
    if($row = $profStmt->fetch(PDO::FETCH_ASSOC)) {
        $customerProfile['phone_number'] = $row['phone_number'] ?? 'غير متوفر';
    }
} catch (PDOException $e) {
    try {
        $profStmt2 = $pdo->prepare("SELECT phone_number FROM customer WHERE national_id = :nid");
        $profStmt2->execute(['nid' => $nationalId]);
        if($row2 = $profStmt2->fetch(PDO::FETCH_ASSOC)) {
            $customerProfile['phone_number'] = $row2['phone_number'] ?? 'غير متوفر';
        }
    } catch(PDOException $e2) {}
}

// =====================================================================
// 1. معالجة تقديم الطلب (مع كشف أخطاء قاعدة البيانات)
// =====================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['is_ajax'])) {
    header('Content-Type: application/json');
    $typeId = $_POST['type_id']; 
    $cityId = $_POST['cty_id']; 
    $deedNumber = trim($_POST['deed_number']); 
    
    $lat = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
    $lng = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
    
    if (!isset($_FILES['deed_file']) || $_FILES['deed_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'عفواً، يرجى إرفاق ملف الصك.']);
        exit;
    }

    $fileTmpPath = $_FILES['deed_file']['tmp_name'];
    $hashedFileName = md5(time() . $nationalId) . '.' . pathinfo($_FILES['deed_file']['name'], PATHINFO_EXTENSION);
    $targetDir = "uploads/";
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    $targetFilePath = $targetDir . $hashedFileName;

    if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
        try {
            $insertQuery = "INSERT INTO application (national_id, type_id, cty_id, deed_number, deed_file_url, latitude, longitude, application_status) 
                            VALUES (:national_id, :type_id, :cty_id, :deed_number, :deed_file_url, :lat, :lng, 'Pending_Review')";
            $stmt = $pdo->prepare($insertQuery);
            $stmt->execute([
                'national_id' => $nationalId, 'type_id' => $typeId, 'cty_id' => $cityId, 
                'deed_number' => $deedNumber, 'deed_file_url' => $targetFilePath,
                'lat' => $lat, 'lng' => $lng
            ]);
            echo json_encode(['status' => 'success', 'message' => 'تم استلام طلبك بنجاح متضمناً موقع العقار من الخريطة.']);
        } catch (PDOException $e1) {
            try {
                // محاولة أخرى بأسماء المخطط الثاني
                $insertQuery2 = "INSERT INTO Application (national_id, type_id, city_id, deed_number, deed_file_url, latitude, longitude, application_status) 
                                 VALUES (:national_id, :type_id, :cty_id, :deed_number, :deed_file_url, :lat, :lng, 'Pending_Review')";
                $stmt2 = $pdo->prepare($insertQuery2);
                $stmt2->execute([
                    'national_id' => $nationalId, 'type_id' => $typeId, 'cty_id' => $cityId, 
                    'deed_number' => $deedNumber, 'deed_file_url' => $targetFilePath,
                    'lat' => $lat, 'lng' => $lng
                ]);
                echo json_encode(['status' => 'success', 'message' => 'تم استلام طلبك وتحديد الموقع بنجاح.']);
            } catch (PDOException $e2) {
                // 🔥 هنا قمنا بكشف الخطأ لتعرفي سبب الرفض من السحابة
                $errorMsg = "الخطأ الأول: " . $e1->getMessage() . " | الخطأ الثاني: " . $e2->getMessage();
                echo json_encode(['status' => 'error', 'message' => $errorMsg]);
            }
        }
    }
    exit;
}

// =====================================================================
// 2. جلب المدن والمناطق والطلبات السابقة
// =====================================================================
$citiesWithRegions = [];
$myApplications = [];
$stats = ['total' => 0, 'approved' => 0, 'review' => 0];

try {
    $stmt = $pdo->query("SELECT c.cty_id, c.cty_name, r.reg_name FROM city c JOIN region r ON c.reg_id = r.reg_id ORDER BY r.reg_id, c.cty_name");
    $citiesWithRegions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    try { 
        $stmt2 = $pdo->query("SELECT c.city_id AS cty_id, c.city_name AS cty_name, r.region_name AS reg_name FROM City c JOIN Region r ON c.region_id = r.region_id ORDER BY r.region_id, c.city_name");
        $citiesWithRegions = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        try { 
            $stmt3 = $pdo->query("SELECT cty_id, cty_name, 'كافة المناطق' as reg_name FROM city");
            $citiesWithRegions = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e3) {}
    }
}

try {
    $appStmt = $pdo->prepare("SELECT a.application_id, a.type_id, a.deed_number, a.application_status, a.created_at, c.cty_name FROM application a LEFT JOIN city c ON a.cty_id = c.cty_id WHERE a.national_id = :nid ORDER BY a.created_at DESC");
    $appStmt->execute(['nid' => $nationalId]);
    $myApplications = $appStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    try {
        $appStmt2 = $pdo->prepare("SELECT a.application_id, a.type_id, a.deed_number, a.application_status, a.created_at, c.city_name AS cty_name FROM Application a LEFT JOIN City c ON a.city_id = c.city_id WHERE a.national_id = :nid ORDER BY a.created_at DESC");
        $appStmt2->execute(['nid' => $nationalId]);
        $myApplications = $appStmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {}
}

$stats['total'] = count($myApplications);
foreach($myApplications as $app) {
    if(in_array($app['application_status'], ['Pending_Inspection', 'Pending_Billing', 'In_Progress', 'Completed'])) $stats['approved']++;
    elseif($app['application_status'] == 'Pending_Review') $stats['review']++;
}

function getStatusBadge($status) {
    $badges = [
        'Pending_OTP' => '<span class="badge bg-secondary px-3 py-2 rounded-pill"><i class="fa-solid fa-clock-rotate-left me-1"></i> بانتظار التوثيق</span>',
        'Pending_Review' => '<span class="badge px-3 py-2 rounded-pill shadow-sm" style="background-color: #f39c12; color: #fff;"><i class="fa-solid fa-magnifying-glass me-1"></i> قيد التدقيق</span>',
        'Pending_Inspection' => '<span class="badge px-3 py-2 rounded-pill shadow-sm" style="background-color: #4A90E2; color: #fff;"><i class="fa-solid fa-truck-fast me-1"></i> قيد الفحص</span>',
        'Pending_Billing' => '<span class="badge px-3 py-2 rounded-pill shadow-sm" style="background-color: #34495e; color: #fff;"><i class="fa-solid fa-file-invoice-dollar me-1"></i> بانتظار السداد</span>',
        'In_Progress' => '<span class="badge px-3 py-2 rounded-pill shadow-sm" style="background-color: #003366; color: #fff;"><i class="fa-solid fa-person-digging me-1"></i> جاري التنفيذ</span>',
        'Completed' => '<span class="badge px-3 py-2 rounded-pill shadow-sm" style="background-color: #27ae60; color: #fff;"><i class="fa-solid fa-check-double me-1"></i> مكتمل</span>',
        'Rejected' => '<span class="badge bg-danger px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-circle-xmark me-1"></i> مرفوض</span>'
    ];
    return $badges[$status] ?? '<span class="badge bg-dark">'.$status.'</span>';
}

function getServiceName($typeId) {
    if($typeId == 1) return 'شبكة مياه';
    if($typeId == 2) return 'صرف صحي';
    if($typeId == 3) return 'مياه وصرف صحي';
    return 'غير محدد';
}
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
        :root {
            --qatra-primary: #003366; 
            --qatra-secondary: #4A90E2; 
            --qatra-light: #f0f7ff;
            --qatra-bg: #f4f8fb;
        }
        body { font-family: 'Cairo', sans-serif; background-color: var(--qatra-bg); color: #2c3e50; overflow-x: hidden; }
        
        .fade-in-up { animation: fadeInUp 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; opacity: 0; transform: translateY(20px); }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }
        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }

        /* Navbar */
        .navbar-qatra { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); padding: 15px 0; border-bottom: 2px solid #eef2f5; box-shadow: 0 10px 30px rgba(0,51,102,0.04); position: sticky; top: 0; z-index: 1000; }
        .qatra-brand { display: flex; align-items: center; gap: 15px; text-decoration: none; }
        .qatra-logo-icon { background: linear-gradient(135deg, var(--qatra-secondary) 0%, var(--qatra-primary) 100%); color: white; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.3rem; box-shadow: 0 5px 15px rgba(74,144,226,0.3); }
        .qatra-logo-text { color: var(--qatra-primary); font-weight: 900; font-size: 1.5rem; line-height: 1.1; }
        .user-chip { background: white; padding: 8px 20px; border-radius: 50px; font-weight: 700; color: var(--qatra-primary); border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.02); cursor: pointer; transition: all 0.3s; }
        .user-chip:hover { background: var(--qatra-light); border-color: var(--qatra-secondary); transform: translateY(-2px); }
        
        /* Welcome Banner */
        .welcome-card { background: linear-gradient(135deg, var(--qatra-primary) 0%, #001f3f 100%); color: white; padding: 40px; border-radius: 20px; margin-bottom: 30px; position: relative; overflow: hidden; box-shadow: 0 20px 40px rgba(0,51,102,0.15); }
        .welcome-card::after { content: ''; position: absolute; top: -20px; right: -20px; width: 200px; height: 200px; background-image: radial-gradient(rgba(74,144,226,0.2) 20%, transparent 20%); background-size: 15px 15px; border-radius: 50%; }
        
        /* Cards */
        .premium-card { background: white; border-radius: 20px; padding: 35px; border: 1px solid #eef2f5; box-shadow: 0 15px 35px rgba(0,0,0,0.03); height: 100%; transition: transform 0.3s; }
        .premium-card:hover { box-shadow: 0 20px 40px rgba(0,0,0,0.05); }
        .card-title-custom { color: var(--qatra-primary); font-weight: 900; font-size: 1.3rem; margin-bottom: 30px; display: flex; align-items: center; gap: 12px; }
        .card-title-custom i { color: var(--qatra-secondary); background: var(--qatra-light); padding: 12px; border-radius: 12px; font-size: 1.1rem; }

        /* Forms */
        .form-label { font-weight: 800; color: #475569; font-size: 0.95rem; margin-bottom: 8px; }
        .form-control, .form-select { border-radius: 12px; border: 2px solid #e2e8f0; padding: 14px 18px; font-weight: 600; color: #1e293b; background: #f8fafc; transition: all 0.3s ease; }
        .form-control:focus, .form-select:focus { border-color: var(--qatra-secondary); background: white; box-shadow: 0 0 0 4px rgba(74, 144, 226, 0.1); outline: none; }
        optgroup { font-weight: 800; color: var(--qatra-primary); background: #f1f5f9; font-size: 1rem; }
        option { font-weight: 600; color: #334155; background: white; font-size: 0.95rem; }

        /* Map Container Customization */
        .map-wrapper { border: 2px solid #e2e8f0; border-radius: 16px; overflow: hidden; position: relative; transition: all 0.3s ease; box-shadow: 0 5px 15px rgba(0,0,0,0.03); }
        .map-wrapper.active { border-color: #27ae60; box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.15); }
        .map-instruction { position: absolute; top: 15px; right: 15px; z-index: 400; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(5px); color: var(--qatra-primary); padding: 8px 15px; border-radius: 50px; font-weight: 800; font-size: 0.85rem; box-shadow: 0 4px 10px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; pointer-events: none; }
        .btn-current-location { position: absolute; bottom: 20px; left: 20px; z-index: 400; background: white; color: var(--qatra-primary); border: none; padding: 10px 15px; border-radius: 12px; font-weight: 700; font-size: 0.9rem; box-shadow: 0 4px 15px rgba(0,0,0,0.15); transition: 0.3s; }
        .btn-current-location:hover { background: var(--qatra-light); color: var(--qatra-secondary); transform: scale(1.05); }

        .upload-area { border: 2px dashed #cbd5e1; border-radius: 16px; padding: 35px 20px; text-align: center; background: #f8fafc; cursor: pointer; transition: all 0.3s ease; }
        .upload-area:hover { border-color: var(--qatra-secondary); background: var(--qatra-light); }
        .upload-area i { color: #94a3b8; font-size: 2.5rem; margin-bottom: 15px; transition: 0.3s; }
        .upload-area:hover i { color: var(--qatra-secondary); transform: scale(1.1); }
        
        .btn-qatra { background: linear-gradient(135deg, var(--qatra-primary) 0%, #001f3f 100%); color: white; border: none; border-radius: 12px; padding: 16px; font-weight: 800; width: 100%; font-size: 1.1rem; transition: all 0.3s ease; box-shadow: 0 8px 20px rgba(0,51,102,0.2); }
        .btn-qatra:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(0,51,102,0.3); color: white; }
        
        .table-custom th { border-bottom: 2px solid #f1f5f9; color: #64748b; font-weight: 800; padding: 15px; font-size: 0.9rem; text-transform: uppercase; }
        .table-custom td { padding: 18px 15px; border-bottom: 1px solid #f8fafc; vertical-align: middle; font-weight: 700; color: #334155; }
        
        .stat-badge { background: white; padding: 25px; border-radius: 20px; display: flex; align-items: center; justify-content: space-between; border: 1px solid #eef2f5; box-shadow: 0 10px 30px rgba(0,0,0,0.02); transition: 0.3s; }
        .stat-badge:hover { transform: translateY(-3px); }
        .stat-badge h3 { font-weight: 900; color: var(--qatra-primary); margin: 0; font-size: 2.2rem; }
        .stat-badge p { margin: 0; color: #64748b; font-weight: 800; font-size: 0.95rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-qatra mb-4 fade-in-up">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="#" class="qatra-brand">
            <div class="qatra-logo-icon">
                <i class="fa-solid fa-droplet"></i>
            </div>
            <div>
                <div class="qatra-logo-text">قطــرة</div>
                <div class="text-muted" style="font-size: 0.8rem; font-weight: 800;">البوابة الإلكترونية الذكية</div>
            </div>
        </a>
        <div class="d-flex align-items-center gap-3">
            <div class="user-chip d-none d-md-flex" data-bs-toggle="modal" data-bs-target="#profileModal" title="عرض الملف الشخصي">
                <i class="fa-regular fa-user me-2 text-secondary"></i> <?= htmlspecialchars($customerName); ?>
                <i class="fa-solid fa-chevron-down ms-2 small text-muted"></i>
            </div>
            <a href="logout.php" class="btn btn-light text-danger rounded-pill fw-bold px-4 border"><i class="fa-solid fa-arrow-right-from-bracket me-1"></i> خروج</a>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <div class="row mb-4">
        <div class="col-lg-7 fade-in-up delay-1">
            <div class="welcome-card h-100 d-flex flex-column justify-content-center">
                <div class="position-relative" style="z-index: 2;">
                    <!-- تم تثبيت السطر الخاص بكِ كما طلبتِ تماماً -->
                    <h2 class="fw-black mb-3">مرحباً، <?= htmlspecialchars($customerName); ?> 👋</h2>
                    <p class="mb-0 text-white-50 fs-5 lh-lg">من خلال بوابتك الرقمية يمكنك تقديم وتتبع طلبات المياه والصرف الصحي العقارية بكل سهولة واحترافية.</p>
                </div>
            </div>
        </div>
        <div class="col-lg-5 fade-in-up delay-2">
            <div class="d-flex flex-column gap-3 h-100 justify-content-center">
                <div class="stat-badge">
                    <div>
                        <p>إجمالي طلباتي</p>
                        <h3><?= $stats['total']; ?></h3>
                    </div>
                    <div class="qatra-logo-icon" style="background: #f1f5f9; color: #94a3b8; box-shadow: none;"><i class="fa-solid fa-folder-open"></i></div>
                </div>
                <div class="stat-badge" style="border-right: 4px solid #27ae60;">
                    <div>
                        <p>الطلبات النشطة والمكتملة</p>
                        <h3 class="text-success"><?= $stats['approved']; ?></h3>
                    </div>
                    <div class="qatra-logo-icon" style="background: #ecfdf5; color: #27ae60; box-shadow: none;"><i class="fa-solid fa-check-double"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- عمود: نموذج تقديم الطلب -->
        <div class="col-xl-4 col-lg-5 mb-4 fade-in-up delay-3">
            <div class="premium-card">
                <div class="card-title-custom">
                    <i class="fa-solid fa-plus"></i> تقديم طلب جديد
                </div>
                
                <form id="applicationForm">
                    <input type="hidden" name="is_ajax" value="1">
                    <input type="hidden" name="latitude" id="latitude" value="">
                    <input type="hidden" name="longitude" id="longitude" value="">
                    
                    <div class="mb-4">
                        <label class="form-label">الخدمة المطلوبة للموقع</label>
                        <select name="type_id" class="form-select" required>
                            <option value="" selected disabled>اختر نوع الخدمة...</option>
                            <option value="1">توصيل شبكة مياه</option>
                            <option value="2">توصيل شبكة صرف صحي</option>
                            <option value="3">توصيل مياه وصرف صحي معاً</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">المدينة (لتوجيه الخريطة)</label>
                        <select name="cty_id" id="citySelect" class="form-select" required onchange="flyToCity()">
                            <option value="" selected disabled>اختر مدينة العقار...</option>
                            <?php 
                                $currentRegion = '';
                                foreach($citiesWithRegions as $city): 
                                    if ($currentRegion != $city['reg_name']) {
                                        if ($currentRegion != '') echo '</optgroup>';
                                        $currentRegion = $city['reg_name'];
                                        echo '<optgroup label="📍 ' . htmlspecialchars($currentRegion) . '">';
                                    }
                            ?>
                                <option value="<?= $city['cty_id']; ?>" data-city="<?= htmlspecialchars($city['cty_name']); ?>">&nbsp;&nbsp;&nbsp; <?= htmlspecialchars($city['cty_name']); ?></option>
                            <?php 
                                endforeach; 
                                if ($currentRegion != '') echo '</optgroup>'; 
                            ?>
                        </select>
                    </div>

                    <!-- 🌟 الخريطة التفاعلية الفخمة (Interactive Map) -->
                    <div class="mb-4">
                        <label class="form-label">تحديد موقع العقار من الخريطة بدقة <span class="text-danger">*</span></label>
                        <div class="map-wrapper" id="mapContainer">
                            <div class="map-instruction"><i class="fa-solid fa-hand-pointer text-info me-1"></i> انقر لتحديد الموقع</div>
                            <div id="propertyMap" style="height: 280px; width: 100%; z-index: 1;"></div>
                            
                            <button type="button" class="btn-current-location" onclick="getCurrentLocation()" title="استخدم موقعي الحالي">
                                <i class="fa-solid fa-location-crosshairs text-primary"></i>
                            </button>
                        </div>
                        <div id="gpsStatus" class="small text-muted text-center mt-2 fw-bold"><i class="fa-solid fa-map-location-dot me-1"></i> يرجى النقر على مكان العقار في الخريطة</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">رقم صك الملكية</label>
                        <input type="text" name="deed_number" class="form-control" placeholder="أدخل رقم الصك للمطابقة" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">المرفقات (صورة الصك)</label>
                        <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <h6 class="fw-bold mb-2 text-dark">اضغط هنا لرفع الملف</h6>
                            <span class="text-muted small fw-bold">يدعم PDF, JPG, PNG (أقصى حجم 5MB)</span>
                            <input type="file" id="fileInput" name="deed_file" class="d-none" accept=".pdf, .jpg, .jpeg, .png" required>
                        </div>
                        <div id="fileNameDisplay" class="text-success mt-3 fw-bold small text-center" style="display: none; background: #ecfdf5; padding: 12px; border-radius: 10px; border: 1px solid #d1fae5;"></div>
                    </div>

                    <button type="submit" class="btn-qatra" id="submitBtn">
                        إرسال الطلب للاعتماد <i class="fa-solid fa-paper-plane ms-2"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- عمود: سجل الطلبات -->
        <div class="col-xl-8 col-lg-7 mb-4 fade-in-up delay-3">
            <div class="premium-card">
                <div class="card-title-custom">
                    <i class="fa-solid fa-clock-rotate-left"></i> سجل طلباتي السابقة
                </div>
                
                <?php if(empty($myApplications)): ?>
                    <div class="text-center py-5 mt-4">
                        <div class="mx-auto mb-4" style="width: 90px; height: 90px; background: #f1f5f9; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 2.5rem;">
                            <i class="fa-regular fa-folder-open"></i>
                        </div>
                        <h5 class="fw-black text-dark mb-2">لا توجد طلبات مسجلة حالياً</h5>
                        <p class="text-muted">يمكنك البدء بتقديم طلبك الأول من القائمة الجانبية.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive mt-3">
                        <table class="table table-custom table-hover">
                            <thead>
                                <tr>
                                    <th>الطلب</th>
                                    <th>الخدمة</th>
                                    <th>المدينة</th>
                                    <th>رقم الصك</th>
                                    <th>حالة الطلب</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($myApplications as $app): ?>
                                <tr>
                                    <td><span class="badge bg-light text-secondary border px-3 py-2 fs-6 shadow-sm">#APP-<?= str_pad($app['application_id'], 4, '0', STR_PAD_LEFT); ?></span></td>
                                    <td><span class="text-primary fw-bold"><i class="fa-solid fa-droplet me-2"></i><?= htmlspecialchars(getServiceName($app['type_id'] ?? 0)); ?></span></td>
                                    <td><i class="fa-solid fa-location-dot text-danger me-2 opacity-75"></i><?= htmlspecialchars($app['cty_name'] ?? 'غير محدد'); ?></td>
                                    <td class="text-muted"><i class="fa-regular fa-file-lines me-2 opacity-50"></i><?= htmlspecialchars($app['deed_number']); ?></td>
                                    <td><?= getStatusBadge($app['application_status']); ?></td>
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

<!-- ============================================== -->
<!-- النافذة المنبثقة للملف الشخصي -->
<!-- ============================================== -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius: 20px; border: none; overflow: hidden; box-shadow: 0 20px 40px rgba(0,51,102,0.2);">
      <div class="modal-header" style="background: linear-gradient(135deg, var(--qatra-primary) 0%, #001f3f 100%); color: white; border: none; padding: 25px;">
        <h5 class="modal-title fw-black"><i class="fa-regular fa-id-card me-2"></i> الملف الشخصي للعميل</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4" style="background-color: var(--qatra-bg);">
        <div class="text-center mb-4 mt-2">
            <div style="width: 90px; height: 90px; background: white; color: var(--qatra-secondary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 3rem; margin: 0 auto 15px; border: 3px solid #eef2f5; box-shadow: 0 10px 25px rgba(74,144,226,0.15);">
                <i class="fa-solid fa-user-tie"></i>
            </div>
            <h4 class="fw-bold mb-1" style="color: var(--qatra-primary);"><?= htmlspecialchars($customerProfile['full_name']); ?></h4>
            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2 mt-1 border border-success fw-bold">حساب موثق عبر نفاذ <i class="fa-solid fa-shield-check ms-1"></i></span>
        </div>
        <div class="card shadow-sm" style="border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden;">
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between align-items-center p-3 border-bottom">
                    <span class="text-muted fw-bold"><i class="fa-regular fa-address-card me-2 text-secondary"></i> رقم الهوية</span>
                    <span class="fw-black" style="color: var(--qatra-primary);"><?= htmlspecialchars($customerProfile['national_id']); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center p-3 border-bottom">
                    <span class="text-muted fw-bold"><i class="fa-solid fa-mobile-screen me-2 text-secondary"></i> رقم الجوال</span>
                    <span class="fw-black text-dark" dir="ltr"><?= htmlspecialchars($customerProfile['phone_number']); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                    <span class="text-muted fw-bold"><i class="fa-solid fa-clock-rotate-left me-2 text-secondary"></i> الطلبات</span>
                    <span class="badge bg-primary rounded-pill px-3 py-1 fs-6"><?= $stats['total']; ?> طلبات</span>
                </li>
            </ul>
        </div>
      </div>
      <div class="modal-footer p-3" style="border-top: 1px solid #e2e8f0; background: white;">
        <button type="button" class="btn btn-light fw-bold w-100" data-bs-dismiss="modal" style="border-radius: 12px; color: #64748b; padding: 12px;">إغلاق</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ==============================================
// 🌟 برمجة الخريطة التفاعلية (Interactive Map)
// ==============================================
let map = L.map('propertyMap').setView([24.7136, 46.6753], 5);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© Qatra Smart System'
}).addTo(map);

let marker;

map.on('click', function(e) {
    let lat = e.latlng.lat;
    let lng = e.latlng.lng;

    if (marker) {
        marker.setLatLng(e.latlng);
    } else {
        marker = L.marker(e.latlng).addTo(map);
    }

    document.getElementById('latitude').value = lat;
    document.getElementById('longitude').value = lng;
    
    document.getElementById('mapContainer').classList.add('active');
    document.getElementById('gpsStatus').innerHTML = `<span class="text-success"><i class="fa-solid fa-check-circle me-1"></i> تم تحديد الموقع بنجاح: (${lat.toFixed(4)}, ${lng.toFixed(4)})</span>`;
});

const cityCoordinates = {
    'الرياض': [24.7136, 46.6753], 'جدة': [21.4858, 39.1925], 'مكة': [21.3891, 39.8579],
    'المدينة': [24.5247, 39.5692], 'الدمام': [26.4207, 50.0888], 'بريدة': [26.3260, 43.9390],
    'ابها': [18.2164, 42.5053], 'تبوك': [28.3835, 36.5662], 'حائل': [27.5114, 41.7208],
    'الطائف': [21.2703, 40.4158], 'ينبع': [24.0232, 38.0622], 'الخبر': [26.2172, 50.1971]
};

function flyToCity() {
    let select = document.getElementById('citySelect');
    let cityName = select.options[select.selectedIndex].getAttribute('data-city');
    
    let coords = [24.7136, 46.6753];
    for (let key in cityCoordinates) {
        if (cityName && cityName.includes(key)) {
            coords = cityCoordinates[key];
            break;
        }
    }
    
    map.flyTo(coords, 12, { animate: true, duration: 1.5 });
    document.getElementById('gpsStatus').innerHTML = `<span class="text-primary"><i class="fa-solid fa-magnifying-glass-location me-1"></i> تم توجيه الخريطة لمدينة ${cityName}، يرجى النقر لتحديد العقار.</span>`;
}

function getCurrentLocation() {
    if (navigator.geolocation) {
        document.getElementById('gpsStatus').innerHTML = '<span class="text-primary"><i class="fa-solid fa-spinner fa-spin me-1"></i> جاري تحديد موقعك الحالي...</span>';
        navigator.geolocation.getCurrentPosition(function(position) {
            let lat = position.coords.latitude;
            let lng = position.coords.longitude;
            
            map.flyTo([lat, lng], 16, { animate: true, duration: 1.5 });
            
            if (marker) marker.setLatLng([lat, lng]);
            else marker = L.marker([lat, lng]).addTo(map);

            document.getElementById('latitude').value = lat;
            document.getElementById('longitude').value = lng;
            document.getElementById('mapContainer').classList.add('active');
            document.getElementById('gpsStatus').innerHTML = `<span class="text-success"><i class="fa-solid fa-check-circle me-1"></i> تم تحديد موقعك بنجاح.</span>`;
        }, function(error) {
            document.getElementById('gpsStatus').innerHTML = '<span class="text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> يرجى تفعيل الـ GPS أو النقر على الخريطة يدوياً.</span>';
        }, { enableHighAccuracy: true });
    }
}

document.getElementById('fileInput').addEventListener('change', function(e) {
    if(e.target.files.length > 0) {
        let display = document.getElementById('fileNameDisplay');
        display.style.display = 'block';
        display.innerHTML = '<i class="fa-solid fa-circle-check fs-6 me-2 align-middle"></i> تم إرفاق الملف: <span class="text-dark">' + e.target.files.name + '</span>';
        document.querySelector('.upload-area').style.borderColor = '#27ae60';
        document.querySelector('.upload-area').style.background = '#f6fdf9';
        document.querySelector('.upload-area i').style.color = '#27ae60';
    }
});

// ==============================================
// معالجة إرسال النموذج (تم التعديل لكشف الخطأ)
// ==============================================
document.getElementById('applicationForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if(document.getElementById('latitude').value === "") {
        Swal.fire({
            icon: 'warning',
            title: 'تنبيه الموقع',
            text: 'يجب النقر على الخريطة لتحديد موقع العقار الجغرافي قبل إرسال الطلب لتمكين الفني من الوصول لاحقاً.',
            confirmButtonText: 'حسناً، سأحدد الموقع',
            confirmButtonColor: '#003366',
        });
    } else {
        submitApplication(this);
    }
});

function submitApplication(formElement) {
    let formData = new FormData(formElement);
    let submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    
    Swal.fire({
        title: 'جاري الإرسال...',
        text: 'يتم الآن معالجة طلبك عبر منصة قطرة',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    fetch('dashboard.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        // 🔥 سيظهر لك الآن رسالة الخطأ الحقيقية القادمة من قاعدة البيانات لتصويرها لي
        if(data.status === 'error') {
            Swal.fire({
                icon: 'error',
                title: 'خطأ في قاعدة البيانات 🚨',
                text: data.message, // هنا سيطبع الخطأ الفعلي (SQL Error)
                confirmButtonText: 'حسناً',
                confirmButtonColor: '#d33'
            }).then(() => { submitBtn.disabled = false; });
        } else {
            Swal.fire({
                icon: 'success',
                title: 'نجاح!',
                text: data.message,
                confirmButtonText: 'متابعة',
                confirmButtonColor: '#003366'
            }).then(() => { window.location.reload(); });
        }
    })
    .catch(error => {
        submitBtn.disabled = false;
        console.error(error); // لطباعة الخطأ في الكونسول للمبرمج
        Swal.fire('خطأ تقني!', 'السيرفر رفض الطلب. يرجى الضغط على F12 واختيار Network لمعرفة التفاصيل.', 'error');
    });
}
</script>

</body>
</html>