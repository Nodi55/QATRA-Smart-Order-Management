<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// =====================================================================
// نظام تسجيل الخروج المدمج (يعيدك للصفحة الرئيسية)
// =====================================================================
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php"); // يمكنك تغييرها إلى index.php إذا كانت هي الرئيسية لديك
    exit;
}

header('Content-Type: text/html; charset=utf-8');

// الاتصال بقاعدة البيانات
if (!file_exists('db_connect.php')) {
    die("<div style='display:flex; justify-content:center; align-items:center; height:100vh; background:#f8fafc; font-family:Cairo, sans-serif;'>
            <h2 style='color:#ef4444; background:white; padding:30px; border-radius:15px; box-shadow:0 10px 25px rgba(0,0,0,0.1); border-top:5px solid #ef4444;'>
            <i class='fa-solid fa-triangle-exclamation'></i> ملف db_connect.php غير موجود.</h2>
         </div>");
}
require_once 'db_connect.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['customer_national_id'])) {
    die("<div style='display:flex; justify-content:center; align-items:center; height:100vh; background:#f8fafc; font-family:Cairo, sans-serif; direction:rtl;'>
            <div style='text-align:center; background:white; padding:40px; border-radius:20px; box-shadow:0 15px 35px rgba(0,45,92,0.1); border-top:5px solid #002d5c;'>
                <h2 style='color:#002d5c; margin-bottom:15px;'>جلسة غير صالحة</h2>
                <p style='color:#64748b; font-size:1.1rem;'>الرجاء تسجيل الدخول أولاً للوصول إلى البوابة الذكية.</p>
                <a href='login.php' style='display:inline-block; margin-top:20px; padding:12px 30px; background:#009FE3; color:white; text-decoration:none; border-radius:50px; font-weight:bold;'>العودة لتسجيل الدخول</a>
            </div>
         </div>");
}

$nationalId = $_SESSION['customer_national_id'];
$customerName = $_SESSION['customer_name'] ?? 'عميلنا العزيز';

// جلب بيانات العميل (بحروف صغيرة)
$stmt = $pdo->prepare("SELECT cust_id, full_name, phone_number FROM customer WHERE national_id = ?");
$stmt->execute([$nationalId]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die("<h3 style='text-align:center; color:red; margin-top:50px; font-family:Cairo;'>عفواً، لم يتم العثور على بيانات العميل.</h3>");
}
$custId = $customer['cust_id'];

// =====================================================================
// معالجة إرسال الطلب (مع التحقق من الـ 12 رقم والصورة)
// =====================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['is_ajax'])) {
    header('Content-Type: application/json');
    
    $srvId = $_POST['srv_id']; 
    $cityId = $_POST['cty_id']; 
    $deedNumber = trim($_POST['deed_no']); 
    $lat = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
    $lng = !empty($_POST['longitude']) ? $_POST['longitude'] : null;

    if (!preg_match('/^\d{12}$/', $deedNumber)) {
        echo json_encode(['status' => 'error', 'message' => 'عفواً، يجب أن يتكون رقم الصك من 12 رقماً فقط.']);
        exit;
    }

    if (!isset($_FILES['deed_file']) || $_FILES['deed_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'المرفق إجباري! يرجى إرفاق صورة صك الملكية.']);
        exit;
    }

    try {
        $checkMoj = $pdo->prepare("SELECT COUNT(*) FROM moj_record WHERE deed_no = ?");
        $checkMoj->execute([$deedNumber]);
        if ($checkMoj->fetchColumn() == 0) {
            echo json_encode(['status' => 'error', 'message' => 'عفواً، رقم الصك المدخل غير متطابق مع سجلات وزارة العدل.']);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ في الاتصال بسجلات التحقق.']);
        exit;
    }

    $fileTmpPath = $_FILES['deed_file']['tmp_name'];
    $hashedFileName = md5(time() . $custId) . '.' . pathinfo($_FILES['deed_file']['name'], PATHINFO_EXTENSION);
    $targetDir = "uploads/";
    if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);
    $targetFilePath = $targetDir . $hashedFileName;

    if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
        try {
            $q = "INSERT INTO application (cty_id, latitude, longitude, deed_no, deed_file_url, app_status, cust_id, srv_id) 
                  VALUES (?, ?, ?, ?, ?, 'Pending_Review', ?, ?)";
            $pdo->prepare($q)->execute([$cityId, $lat, $lng, $deedNumber, $targetFilePath, $custId, $srvId]);
            
            echo json_encode(['status' => 'success', 'message' => 'تم استلام طلبك بنجاح وجاري مراجعته من قبل المختصين.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء حفظ الطلب في النظام.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'فشل في رفع المرفقات، يرجى المحاولة لاحقاً.']);
    }
    exit;
}

// =====================================================================
// جلب البيانات الأساسية للواجهة
// =====================================================================
$citiesWithRegions = []; $services = []; $myApplications = []; $stats = ['total' => 0, 'completed' => 0, 'in_progress' => 0];

try {
    $citiesWithRegions = $pdo->query("SELECT c.cty_id, c.cty_name, r.reg_name FROM city c JOIN region r ON c.reg_id = r.reg_id ORDER BY r.reg_id, c.cty_name")->fetchAll(PDO::FETCH_ASSOC);
    $services = $pdo->query("SELECT srv_id, srv_name FROM service_type")->fetchAll(PDO::FETCH_ASSOC);

    $appStmt = $pdo->prepare("SELECT a.app_id, s.srv_name, a.deed_no, a.app_status, a.created_at, c.cty_name 
                              FROM application a 
                              JOIN city c ON a.cty_id = c.cty_id 
                              JOIN service_type s ON a.srv_id = s.srv_id
                              WHERE a.cust_id = ? ORDER BY a.created_at DESC");
    $appStmt->execute([$custId]);
    $myApplications = $appStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stats['total'] = count($myApplications);
    foreach($myApplications as $app) {
        if($app['app_status'] == 'Completed') $stats['completed']++;
        if(in_array($app['app_status'], ['Pending_Review', 'Pending_Inspection', 'In_Progress'])) $stats['in_progress']++;
    }
} catch (PDOException $e) {}

function getStatusBadge($status) {
    $badges = [
        'Pending_Review' => '<span class="status-badge badge-warning"><i class="fa-solid fa-file-signature"></i> قيد المراجعة</span>',
        'Pending_Inspection' => '<span class="status-badge badge-info"><i class="fa-solid fa-helmet-safety"></i> الفحص الميداني</span>',
        'Pending_Billing' => '<span class="status-badge badge-dark"><i class="fa-solid fa-file-invoice-dollar"></i> بانتظار السداد</span>',
        'In_Progress' => '<span class="status-badge badge-primary"><i class="fa-solid fa-person-digging"></i> جاري التنفيذ</span>',
        'Completed' => '<span class="status-badge badge-success"><i class="fa-solid fa-circle-check"></i> مكتمل</span>',
        'Rejected' => '<span class="status-badge badge-danger"><i class="fa-solid fa-circle-xmark"></i> مرفوض</span>'
    ];
    return $badges[$status] ?? '<span class="status-badge badge-secondary">'.$status.'</span>';
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نظام قطرة | البوابة الذكية للعملاء</title>
    <!-- الخطوط والمكتبات -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- الخريطة -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root { 
            --nwc-navy: #002d5c;    
            --nwc-blue: #009FE3;    
            --nwc-light: #e6f5fc;   
            --bg-color: #f4f7f9; 
            --card-shadow: 0 12px 24px rgba(0, 45, 92, 0.06);
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-color); color: #334155; overflow-x: hidden; }

        /* --- الأنيميشن --- */
        .fade-in-up { animation: fadeInUp 0.7s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; opacity: 0; transform: translateY(20px); }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }
        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }

        /* --- الأشكال الهندسية العائمة للألوان --- */
        .shape { position: absolute; opacity: 0.6; z-index: 0; animation: floatShape 8s ease-in-out infinite; }
        .shape-1 { width: 100px; height: 100px; background: linear-gradient(135deg, #f59e0b, #ef4444); border-radius: 50%; top: -10%; right: 5%; filter: blur(4px); }
        .shape-2 { width: 70px; height: 70px; background: linear-gradient(135deg, #10b981, #3b82f6); clip-path: polygon(50% 0%, 0% 100%, 100% 100%); bottom: 10%; left: 10%; animation-delay: 2s; }
        .shape-3 { width: 50px; height: 50px; background: linear-gradient(135deg, #8b5cf6, #ec4899); border-radius: 12px; transform: rotate(45deg); top: 20%; left: 40%; animation-delay: 4s; }
        .shape-4 { width: 120px; height: 120px; background: radial-gradient(circle, #38bdf8 0%, rgba(0,0,0,0) 70%); border-radius: 50%; bottom: -10%; right: 30%; animation-delay: 1s; }
        @keyframes floatShape { 0% { transform: translateY(0) rotate(0deg); } 50% { transform: translateY(-25px) rotate(15deg); } 100% { transform: translateY(0) rotate(0deg); } }

        /* --- الشريط العلوي --- */
        .navbar-luxury { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); padding: 15px 0; box-shadow: 0 4px 30px rgba(0, 0, 0, 0.05); border-bottom: 1px solid rgba(0, 159, 227, 0.1); position: sticky; top: 0; z-index: 1050; }
        .brand-icon { background: linear-gradient(135deg, var(--nwc-blue), var(--nwc-navy)); color: white; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 16px; font-size: 1.6rem; box-shadow: 0 8px 20px rgba(0, 159, 227, 0.3); transition: transform 0.3s; }
        .brand-icon:hover { transform: scale(1.05) rotate(-5deg); }
        .user-profile-badge { background: white; padding: 6px 20px 6px 6px; border-radius: 50px; font-weight: 700; color: var(--nwc-navy); border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.02); cursor: pointer; transition: 0.3s; }
        .user-profile-badge:hover { background: #f8fafc; border-color: var(--nwc-blue); }
        .user-avatar { width: 38px; height: 38px; background: var(--nwc-light); color: var(--nwc-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        
        /* تأثيرات زر تسجيل الخروج */
        .btn-logout { transition: all 0.3s; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; }
        .btn-logout:hover { background-color: #ef4444 !important; color: white !important; transform: rotate(90deg); }

        /* --- البطاقة الترحيبية الملونة --- */
        .hero-banner { 
            background: linear-gradient(135deg, var(--nwc-navy) 0%, #0a1128 100%); 
            color: white; padding: 45px; border-radius: 28px; 
            box-shadow: 0 25px 50px rgba(0, 45, 92, 0.2); 
            position: relative; overflow: hidden; display: flex; justify-content: space-between; align-items: center;
        }
        .text-gradient {
            background: linear-gradient(120deg, #6ee7b7, #38bdf8, #818cf8);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            text-shadow: 0 4px 15px rgba(56, 189, 248, 0.3);
        }
        .hero-stats { display: flex; gap: 20px; z-index: 1; }
        .stat-box { background: rgba(255,255,255,0.1); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2); border-radius: 20px; padding: 20px 30px; text-align: center; min-width: 130px; }
        .stat-box h3 { font-weight: 900; margin: 0; font-size: 2.2rem; color: #fff; }
        .stat-box p { margin: 0; font-size: 0.9rem; font-weight: 600; color: var(--nwc-light); }

        /* --- حاويات المحتوى --- */
        .premium-card { background: white; border-radius: 28px; padding: 40px; box-shadow: var(--card-shadow); border: 1px solid rgba(255,255,255,0.8); height: 100%; position: relative; }
        .card-header-title { color: var(--nwc-navy); font-weight: 900; font-size: 1.4rem; margin-bottom: 30px; display: flex; align-items: center; gap: 12px; border-bottom: 2px solid var(--nwc-light); padding-bottom: 15px; }
        .card-header-title i { background: var(--nwc-light); color: var(--nwc-blue); width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.2rem; }

        /* --- النماذج --- */
        .form-label { font-weight: 800; color: #334155; font-size: 0.95rem; margin-bottom: 10px; }
        .form-control, .form-select { border-radius: 16px; border: 2px solid #e2e8f0; padding: 16px 20px; font-weight: 700; color: #1e293b; background: #f8fafc; transition: all 0.3s; font-size: 1rem; }
        .form-control:focus, .form-select:focus { border-color: var(--nwc-blue); background: white; box-shadow: 0 0 0 5px rgba(0, 159, 227, 0.15); outline: none; }
        
        /* --- الخريطة والرفع --- */
        .map-container { border: 2px solid #e2e8f0; border-radius: 20px; overflow: hidden; position: relative; height: 300px; box-shadow: inset 0 4px 10px rgba(0,0,0,0.05); }
        .map-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(248, 250, 252, 0.85); backdrop-filter: blur(8px); z-index: 1000; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--nwc-navy); transition: 0.4s; }
        .btn-gps { position: absolute; bottom: 20px; right: 20px; z-index: 1001; background: white; color: var(--nwc-navy); border: 2px solid white; padding: 12px 20px; border-radius: 16px; font-weight: 800; font-size: 0.95rem; box-shadow: 0 8px 25px rgba(0,0,0,0.15); transition: all 0.3s; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .btn-gps:hover { background: var(--nwc-navy); color: white; transform: translateY(-3px); }
        .upload-box { border: 2px dashed #cbd5e1; border-radius: 20px; padding: 35px 20px; text-align: center; background: #f8fafc; cursor: pointer; transition: 0.3s; display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .upload-box:hover { border-color: var(--nwc-blue); background: var(--nwc-light); }
        .upload-box i { color: var(--nwc-blue); font-size: 2.5rem; }
        .upload-box h6 { font-weight: 800; margin: 0; color: var(--nwc-navy); font-size: 1.1rem; }
        .upload-box span { font-weight: 600; color: #64748b; font-size: 0.85rem; }
        .file-status { display: none; background: #ecfdf5; color: #059669; padding: 12px; border-radius: 12px; font-weight: 800; text-align: center; margin-top: 15px; border: 1px solid #a7f3d0; }

        /* --- الأزرار والشارات --- */
        .btn-brand { background: linear-gradient(135deg, var(--nwc-blue), var(--nwc-navy)); color: white; border: none; border-radius: 16px; padding: 18px; font-weight: 900; font-size: 1.2rem; width: 100%; transition: all 0.4s; box-shadow: 0 10px 25px rgba(0, 45, 92, 0.2); display: flex; justify-content: center; align-items: center; gap: 10px; }
        .btn-brand:hover { transform: translateY(-4px); box-shadow: 0 15px 35px rgba(0, 45, 92, 0.3); color: white; }
        
        .table-custom th { border-bottom: 2px solid var(--nwc-light); color: #64748b; font-weight: 800; padding: 18px 15px; font-size: 0.95rem; }
        .table-custom td { padding: 20px 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-weight: 700; color: #1e293b; }
        .status-badge { padding: 8px 15px; border-radius: 12px; font-weight: 800; display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; }
        
        .badge-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .badge-info { background: var(--nwc-light); color: var(--nwc-blue); border: 1px solid #bae6fd; }
        .badge-primary { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .badge-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .badge-dark { background: #f8fafc; color: #334155; border: 1px solid #e2e8f0; }
        .badge-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        
        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state-icon { width: 100px; height: 100px; background: var(--nwc-light); color: var(--nwc-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 3rem; margin: 0 auto 20px; }
    </style>
</head>
<body>

<!-- شريط الملاحة الفاخر -->
<nav class="navbar navbar-luxury fade-in-up">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="#" class="d-flex align-items-center gap-3 text-decoration-none">
            <div class="brand-icon"><i class="fa-solid fa-droplet"></i></div>
            <div>
                <div class="fw-black fs-4" style="color: var(--nwc-navy); line-height: 1.1;">قطــرة</div>
                <div class="text-muted" style="font-size: 0.85rem; font-weight: 800;">بوابة الخدمات الموحدة</div>
            </div>
        </a>
        <div class="d-flex align-items-center gap-3">
            <!-- زر عرض الملف الشخصي -->
            <div class="user-profile-badge" data-bs-toggle="modal" data-bs-target="#profileModal" title="عرض بياناتي">
                <span class="ms-2"><?= htmlspecialchars($customer['full_name'] ?? $customerName); ?></span>
                <div class="user-avatar"><i class="fa-solid fa-user-tie"></i></div>
            </div>
            <!-- زر تسجيل الخروج (مربوط بالكود العلوي ?logout=1) -->
            <a href="?logout=1" class="btn btn-light text-danger rounded-circle shadow-sm border btn-logout" title="تسجيل الخروج">
                <i class="fa-solid fa-power-off"></i>
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5 mt-4">
    
    <!-- منطقة الترحيب الملونة بالإشكال الهندسية (Hero Banner) -->
    <div class="row mb-5 fade-in-up delay-1">
        <div class="col-12">
            <div class="hero-banner">
                <!-- الأشكال العائمة -->
                <div class="shape shape-1"></div>
                <div class="shape shape-2"></div>
                <div class="shape shape-3"></div>
                <div class="shape shape-4"></div>

                <div style="z-index: 1; position: relative;">
                    <div class="d-flex align-items-center mb-3" style="gap: 15px;">
                        <div style="background: rgba(255,255,255,0.15); padding: 12px 16px; border-radius: 16px; backdrop-filter: blur(5px); box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                            <i class="fa-solid fa-hand-sparkles" style="color: #fde047; font-size: 2rem;"></i>
                        </div>
                        <h1 class="fw-black m-0" style="color: white; font-size: 2.2rem;">
                            أهلاً بك، <span class="text-gradient"><?= htmlspecialchars($customer['full_name'] ?? $customerName); ?></span> 
                            <i class="fa-solid fa-seedling ms-2" style="color: #34d399; font-size: 1.8rem; text-shadow: 0 0 10px rgba(52, 211, 153, 0.4);"></i>
                        </h1>
                    </div>
                    <p class="mb-0 fs-5 mt-3" style="color: #cbd5e1; max-width: 600px; line-height: 1.8; font-weight: 500;">
                        نحن هنا لخدمتك! <span style="color: #93c5fd;">قطرة</span> تقدم لك تجربة رقمية استثنائية لطلب وإدارة خدمات المياه والصرف الصحي لعقاراتك بكل سهولة وشفافية 💧🌍.
                    </p>
                </div>
                
                <div class="hero-stats d-none d-lg-flex">
                    <div class="stat-box">
                        <h3><?= $stats['total']; ?></h3>
                        <p>إجمالي الطلبات <i class="fa-solid fa-chart-pie ms-1" style="color:#fbbf24;"></i></p>
                    </div>
                    <div class="stat-box" style="background: rgba(16, 185, 129, 0.2); border-color: rgba(16, 185, 129, 0.4);">
                        <h3 style="color: #6ee7b7;"><?= $stats['completed']; ?></h3>
                        <p style="color: #d1fae5;">طلبات مكتملة <i class="fa-solid fa-check-double ms-1"></i></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- نموذج التقديم -->
        <div class="col-xl-5 col-lg-5 fade-in-up delay-2">
            <div class="premium-card">
                <div class="card-header-title">
                    <i class="fa-solid fa-file-signature"></i> تقديم طلب جديد
                </div>
                
                <form id="applicationForm">
                    <input type="hidden" name="is_ajax" value="1">
                    <input type="hidden" name="latitude" id="latitude" value="">
                    <input type="hidden" name="longitude" id="longitude" value="">
                    
                    <div class="mb-4">
                        <label class="form-label">الخدمة المطلوبة <span style="color: #009FE3;">●</span></label>
                        <select name="srv_id" class="form-select" required>
                            <option value="" selected disabled>-- اختر نوع الخدمة --</option>
                            <?php foreach($services as $srv): ?>
                                <option value="<?= $srv['srv_id']; ?>"><?= htmlspecialchars($srv['srv_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">المدينة المرتبطة بالعقار <span style="color: #009FE3;">●</span></label>
                        <select name="cty_id" id="citySelect" class="form-select" required onchange="unlockMap()">
                            <option value="" selected disabled>-- اختر المدينة لتفعيل الخريطة --</option>
                            <?php 
                            $currentRegion = '';
                            foreach($citiesWithRegions as $city): 
                                if ($currentRegion != $city['reg_name']) {
                                    if ($currentRegion != '') echo '</optgroup>';
                                    $currentRegion = $city['reg_name'];
                                    echo '<optgroup label="📍 منطقة ' . htmlspecialchars($currentRegion) . '">';
                                }
                            ?>
                                <option value="<?= $city['cty_id']; ?>" data-city="<?= htmlspecialchars($city['cty_name']); ?>">&nbsp;&nbsp;&nbsp;مدينة <?= htmlspecialchars($city['cty_name']); ?></option>
                            <?php endforeach; if ($currentRegion != '') echo '</optgroup>'; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">تحديد الموقع الجغرافي <span class="text-danger">*</span></label>
                        <div class="map-container" id="mapContainer">
                            <div class="map-overlay" id="mapLock">
                                <i class="fa-solid fa-map-location-dot fs-1 mb-3" style="color: #94a3b8;"></i>
                                <span class="fw-bold fs-5 text-muted">الرجاء اختيار المدينة أولاً</span>
                            </div>
                            <div id="propertyMap" style="height: 100%; width: 100%;"></div>
                            <button type="button" class="btn-gps d-none" id="btnGps" onclick="getCurrentLocation()">
                                <i class="fa-solid fa-location-crosshairs" style="color: #f59e0b;"></i> موقعي الحالي
                            </button>
                        </div>
                        <div id="gpsStatus" class="small text-muted mt-2 fw-bold text-center"><i class="fa-solid fa-circle-info" style="color:#3b82f6;"></i> نرجو تحديد الموقع بدقة لضمان سرعة الخدمة.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">رقم صك الملكية (إلزامي) <span style="color: #10b981;">●</span></label>
                        <div class="input-group" style="direction: ltr;">
                            <input type="text" name="deed_no" id="deedInput" class="form-control" style="text-align: right; border-radius: 0 16px 16px 0;" 
                                   placeholder="أدخل 12 رقماً" required minlength="12" maxlength="12" pattern="\d{12}" 
                                   title="يجب أن يتكون رقم الصك من 12 رقماً تماماً" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            <span class="input-group-text bg-light text-muted fw-bold" style="border-radius: 16px 0 0 16px; border: 2px solid #e2e8f0; border-right: none;">
                                <i class="fa-solid fa-hashtag" style="color: #8b5cf6;"></i>
                            </span>
                        </div>
                        <div class="small text-muted mt-1 fw-bold text-end">يتكون رقم الصك الإلكتروني من 12 رقماً (مثال: 711029485736)</div>
                    </div>

                    <div class="mb-5">
                        <label class="form-label">نسخة من الصك (إلزامي) <span style="color: #f59e0b;">●</span></label>
                        <div class="upload-box" id="uploadBox" onclick="document.getElementById('fileInput').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <h6>انقر هنا لإرفاق ملف الصك</h6>
                            <span>(PDF, JPG, PNG) الحد الأقصى 5MB</span>
                            <input type="file" id="fileInput" name="deed_file" class="d-none" accept=".pdf, .jpg, .jpeg, .png" required>
                        </div>
                        <div id="fileNameDisplay" class="file-status"></div>
                    </div>

                    <button type="submit" class="btn-brand" id="submitBtn">
                        إرسال الطلب واعتماده <i class="fa-solid fa-paper-plane text-info"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- سجل الطلبات -->
        <div class="col-xl-7 col-lg-7 fade-in-up delay-3">
            <div class="premium-card">
                <div class="card-header-title">
                    <i class="fa-solid fa-clock-rotate-left"></i> السجل الشامل لطلباتك
                </div>
                
                <?php if(empty($myApplications)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fa-regular fa-folder-open"></i></div>
                        <h4 class="fw-black text-dark mb-2">سجل الطلبات فارغ</h4>
                        <p class="text-muted fw-bold">لم تقم بتقديم أي طلبات حتى الآن. يمكنك البدء من النموذج الجانبي.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive mt-2">
                        <table class="table table-custom table-hover">
                            <thead>
                                <tr>
                                    <th>الرقم المرجعي</th>
                                    <th>نوع الخدمة</th>
                                    <th>رقم الصك (12 رقم)</th>
                                    <th>حالة الطلب</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($myApplications as $app): ?>
                                <tr>
                                    <td><span class="badge bg-light text-secondary border fs-6">#<?= str_pad($app['app_id'], 5, '0', STR_PAD_LEFT); ?></span></td>
                                    <td class="fw-black" style="color: var(--nwc-navy);"><?= htmlspecialchars($app['srv_name']); ?><br><span class="small text-muted fw-bold"><i class="fa-solid fa-location-dot me-1" style="color:#ef4444;"></i> <?= htmlspecialchars($app['cty_name']); ?></span></td>
                                    <td><span class="text-secondary fw-bold" style="letter-spacing: 1px;"><i class="fa-solid fa-file-invoice me-1" style="color:#8b5cf6;"></i> <?= htmlspecialchars($app['deed_no']); ?></span></td>
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

<!-- ========================================== -->
<!-- نافذة الملف الشخصي (Profile Modal) -->
<!-- ========================================== -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 24px; border: none; box-shadow: 0 25px 50px rgba(0,0,0,0.15);">
            
            <div class="modal-header border-0 pb-0 position-relative">
                <button type="button" class="btn-close ms-auto mt-2 me-2" data-bs-dismiss="modal" aria-label="Close" style="z-index: 10;"></button>
            </div>
            
            <div class="modal-body text-center px-4 pb-5 pt-0">
                <div class="user-avatar mx-auto mb-3" style="width: 90px; height: 90px; font-size: 3rem; background: linear-gradient(135deg, var(--nwc-blue), var(--nwc-navy)); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 25px rgba(0, 159, 227, 0.3);">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
                
                <h3 class="fw-black text-dark mb-1"><?= htmlspecialchars($customer['full_name'] ?? $customerName); ?></h3>
                <p class="text-muted fw-bold mb-4">عميل موثق <i class="fa-solid fa-circle-check text-success ms-1"></i></p>
                
                <div class="bg-light p-3" style="border-radius: 16px; border: 1px solid #e2e8f0; text-align: right;">
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                        <span class="text-muted fw-bold"><i class="fa-regular fa-id-card me-2"></i> رقم الهوية</span>
                        <span class="fw-black" style="color: var(--nwc-navy); font-family: monospace; font-size: 1.1rem;"><?= htmlspecialchars($nationalId); ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                        <span class="text-muted fw-bold"><i class="fa-solid fa-phone me-2"></i> رقم الجوال</span>
                        <span class="fw-black text-primary" style="direction: ltr;"><?= htmlspecialchars($customer['phone_number'] ?? 'غير مسجل'); ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted fw-bold"><i class="fa-solid fa-hashtag me-2"></i> رقم المشترك</span>
                        <span class="fw-bold badge bg-secondary">CUST-<?= str_pad($custId, 4, '0', STR_PAD_LEFT); ?></span>
                    </div>
                </div>
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
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© Qatra Smart Systems' }).addTo(map);
let marker;

const cityCoords = {
    'الرياض': [24.7136, 46.6753], 'جدة': [21.4858, 39.1925], 'مكة': [21.3891, 39.8579],
    'المدينة': [24.5247, 39.5692], 'الدمام': [26.4207, 50.0888], 'بريدة': [26.3260, 43.9390],
    'ابها': [18.2164, 42.5053], 'تبوك': [28.3835, 36.5662], 'حائل': [27.5114, 41.7208]
};

function unlockMap() {
    let select = document.getElementById('citySelect');
    let cityName = select.options[select.selectedIndex].getAttribute('data-city');
    
    document.getElementById('mapLock').style.opacity = '0';
    setTimeout(() => { document.getElementById('mapLock').style.display = 'none'; }, 400);
    document.getElementById('btnGps').classList.remove('d-none');
    
    let coords = [24.7136, 46.6753];
    for (let key in cityCoords) { if (cityName && cityName.includes(key)) { coords = cityCoords[key]; break; } }
    
    setTimeout(() => { map.invalidateSize(); map.flyTo(coords, 13, { animate: true, duration: 1.5 }); }, 300);
    document.getElementById('gpsStatus').innerHTML = `<span class="text-primary fw-bold"><i class="fa-solid fa-hand-pointer me-1"></i> انقر على الخريطة أو استخدم زر (موقعي الحالي).</span>`;
}

function getCurrentLocation() {
    if (navigator.geolocation) {
        let btn = document.getElementById('btnGps');
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري التحديد...';
        
        navigator.geolocation.getCurrentPosition(function(pos) {
            let lat = pos.coords.latitude, lng = pos.coords.longitude;
            map.flyTo([lat, lng], 17, { animate: true, duration: 1.5 });
            
            if (marker) marker.setLatLng([lat, lng]); else marker = L.marker([lat, lng]).addTo(map);
            
            document.getElementById('latitude').value = lat; document.getElementById('longitude').value = lng;
            document.getElementById('gpsStatus').innerHTML = `<span class="text-success fw-bold"><i class="fa-solid fa-location-crosshairs me-1"></i> تم التقاط موقعك الحالي بنجاح.</span>`;
            btn.innerHTML = '<i class="fa-solid fa-check text-success"></i> تم التحديد';
            setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-location-crosshairs text-warning"></i> موقعي الحالي'; }, 3000);
        }, function(error) {
            btn.innerHTML = '<i class="fa-solid fa-location-crosshairs text-warning"></i> موقعي الحالي';
            Swal.fire('تنبيه', 'يرجى السماح للمتصفح بالوصول إلى موقعك، أو قم بتحديده يدوياً.', 'warning');
        });
    } else {
        Swal.fire('خطأ', 'متصفحك لا يدعم خاصية تحديد الموقع.', 'error');
    }
}

map.on('click', function(e) {
    let lat = e.latlng.lat, lng = e.latlng.lng;
    if (marker) marker.setLatLng(e.latlng); else marker = L.marker(e.latlng).addTo(map);
    document.getElementById('latitude').value = lat; document.getElementById('longitude').value = lng;
    document.getElementById('gpsStatus').innerHTML = `<span class="text-success fw-bold"><i class="fa-solid fa-check-circle me-1"></i> تم تحديد موقع العقار بنجاح.</span>`;
});

// ==========================================
// التفاعلات والإرسال
// ==========================================
document.getElementById('fileInput').addEventListener('change', function(e) {
    let display = document.getElementById('fileNameDisplay');
    let box = document.getElementById('uploadBox');
    
    if(e.target.files.length > 0) {
        display.style.display = 'block';
        display.innerHTML = '<i class="fa-solid fa-file-circle-check me-1 fs-5"></i> تم إرفاق الملف: ' + e.target.files.name;
        box.style.borderColor = '#10b981';
        box.style.background = '#ecfdf5';
        box.querySelector('i').style.color = '#10b981';
        box.querySelector('i').className = 'fa-solid fa-circle-check';
        box.querySelector('h6').innerText = 'تم الإرفاق بنجاح';
    } else {
        display.style.display = 'none';
        box.style.borderColor = '#cbd5e1';
        box.style.background = '#f8fafc';
        box.querySelector('i').style.color = 'var(--nwc-blue)';
        box.querySelector('i').className = 'fa-solid fa-cloud-arrow-up';
        box.querySelector('h6').innerText = 'انقر هنا لإرفاق ملف الصك';
    }
});

document.getElementById('applicationForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if(document.getElementById('latitude').value === "") {
        Swal.fire({ icon: 'warning', title: 'خطوة مفقودة', text: 'يرجى تحديد موقع العقار على الخريطة.', confirmButtonColor: '#002d5c' });
        return;
    }
    
    let deedVal = document.getElementById('deedInput').value;
    if(deedVal.length !== 12) {
        Swal.fire({ icon: 'error', title: 'إدخال خاطئ', text: 'رقم الصك يجب أن يكون 12 رقماً بالضبط.', confirmButtonColor: '#002d5c' });
        return;
    }
    
    let submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    
    Swal.fire({ title: 'جاري معالجة الطلب...', text: 'يتم الآن مطابقة الصك مع وزارة العدل وتشفير البيانات', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

    fetch('dashboard.php', { method: 'POST', body: new FormData(this) })
    .then(r => r.json()).then(data => {
        if(data.status === 'error') {
            Swal.fire({ icon: 'error', title: 'عذراً', text: data.message, confirmButtonColor: '#002d5c' }).then(() => { submitBtn.disabled = false; });
        } else {
            Swal.fire({ icon: 'success', title: 'عملية ناجحة', text: data.message, confirmButtonColor: '#10b981' }).then(() => { window.location.reload(); });
        }
    }).catch(error => {
        submitBtn.disabled = false;
        Swal.fire('خطأ تقني', 'حدث خطأ في الاتصال بالخادم، يرجى التحقق من الشبكة.', 'error');
    });
});
</script>

</body>
</html>