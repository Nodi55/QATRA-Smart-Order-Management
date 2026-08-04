<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// =====================================================================
// نظام تسجيل الخروج المدمج
// =====================================================================
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

header('Content-Type: text/html; charset=utf-8');

// الاتصال بقاعدة البيانات
if (!file_exists('db_connect.php')) {
    die("ملف db_connect.php غير موجود.");
}
require_once 'db_connect.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['customer_national_id'])) {
    header("Location: login.php");
    exit;
}

$nationalId = $_SESSION['customer_national_id'];
$customerName = $_SESSION['customer_name'] ?? 'عميلنا العزيز';

// جلب بيانات العميل الأساسية
$stmt = $pdo->prepare("SELECT cust_id, full_name, phone_number FROM customer WHERE national_id = ?");
$stmt->execute([$nationalId]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die("<h3 style='text-align:center; color:red; margin-top:50px; font-family:Cairo'>عفواً، لم يتم العثور على بيانات العميل.</h3>");
}
$custId = $customer['cust_id'];

// =====================================================================
// إضافة مدينة "الربيعية" لقاعدة البيانات برمجياً (تنفذ مرة واحدة فقط)
// =====================================================================
try {
    $checkCity = $pdo->query("SELECT COUNT(*) FROM city WHERE cty_name = 'الربيعية'")->fetchColumn();
    if ($checkCity == 0) {
        $pdo->exec("INSERT INTO city (cty_name, reg_id) VALUES ('الربيعية', 1)"); 
    }
} catch (Exception $e) {}


// =====================================================================
// معالجة إرسال الطلب (DSS، منع التكرار، ثبات الموقع، والتوزيع الذكي)
// =====================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['is_ajax'])) {
    header('Content-Type: application/json');

    $originalSrvId = $_POST['srv_id']; // 1=مياه, 2=صرف, 3=مياه وصرف
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
        // --- 1. التحقق من ثبات الموقع ومنع التكرار (Location & Duplication Check) ---
        $servicesToCreate = [];
        if ($originalSrvId == 3) {
            $servicesToCreate = [1, 2]; // 1: مياه، 2: صرف صحي
        } else {
            $servicesToCreate = [$originalSrvId];
        }

        // جلب الطلبات السابقة لنفس الصك لمعرفة المدينة والخدمات المطلوبة
        $checkExisting = $pdo->prepare("SELECT a.srv_id, a.cty_id, c.cty_name FROM application a JOIN city c ON a.cty_id = c.cty_id WHERE a.deed_no = ? AND a.app_status != 'Rejected'");
        $checkExisting->execute([$deedNumber]);
        $existingRecords = $checkExisting->fetchAll(PDO::FETCH_ASSOC);

        $existingSrvIds = [];
        
        foreach ($existingRecords as $rec) {
            // التحقق من ثبات الموقع (إذا اختلف موقع الصك المدخل عن المسجل سابقاً يتم الرفض فوراً)
            if ($rec['cty_id'] != $cityId) {
                echo json_encode(['status' => 'error', 'message' => 'عفواً، تم رفض الطلب آلياً. هذا الصك مسجل مسبقاً في النظام لمدينة ('. htmlspecialchars($rec['cty_name']) .'). لا يمكن تقديم طلب لنفس العقار في مدينة أخرى، لأن موقع الصك لا يتغير.']);
                exit;
            }
            $existingSrvIds[] = $rec['srv_id'];
        }

        $finalServicesToCreate = [];
        $duplicateServicesCount = 0;

        foreach ($servicesToCreate as $sId) {
            if (!in_array($sId, $existingSrvIds)) {
                $finalServicesToCreate[] = $sId;
            } else {
                $duplicateServicesCount++;
            }
        }

        // إذا كانت جميع الخدمات التي اختارها موجودة مسبقاً
        if (empty($finalServicesToCreate)) {
            echo json_encode(['status' => 'error', 'message' => 'عفواً، يوجد طلب سابق (نشط أو مكتمل) لنفس الخدمة على هذا الصك. لا يمكن تكرار الطلب.']);
            exit;
        }

        // --- 2. التحقق من وزارة العدل (DSS) ---
        $checkMoj = $pdo->prepare("SELECT owner_national_id, owner_name FROM moj_record WHERE deed_no = ?");
        $checkMoj->execute([$deedNumber]);
        $mojData = $checkMoj->fetch(PDO::FETCH_ASSOC);

        if (!$mojData) {
            echo json_encode(['status' => 'error', 'message' => 'عفواً، رقم الصك المدخل غير موجود في سجلات وزارة العدل.']);
            exit;
        }

        // ====================================================================
        // خوارزمية التطابق الذكي للأسماء (Smart Name Normalization)
        // لتخفيف العبء عن المدققين في حالات المسافات و (بن/بنت) والأخطاء الإملائية الشائعة
        // ====================================================================
        function normalizeName($name) {
            // 1. توحيد الحروف الشائعة التي يكثر فيها الخطأ
            $name = str_replace(['أ', 'إ', 'آ'], 'ا', $name);
            $name = str_replace('ة', 'ه', $name);
            $name = str_replace('ي', 'ى', $name);
            
            // 2. إزالة كلمات (بن) و (بنت) إذا كانت بين مسافات
            $name = preg_replace('/\s+بن\s+/', ' ', $name);
            $name = preg_replace('/\s+بنت\s+/', ' ', $name);
            
            // 3. دمج كلمة (عبد) لتلافي مشكلة (عبد الله) و (عبدالله)
            $name = str_replace('عبد ', 'عبد', $name);
            
            // 4. إزالة جميع المسافات والفراغات الزائدة نهائياً
            $name = preg_replace('/\s+/', '', $name);
            
            return $name;
        }

        // تطبيق الخوارزمية على الاسمين قبل المقارنة
        $normalizedMojName = normalizeName($mojData['owner_name']);
        $normalizedCustName = normalizeName($customer['full_name']);
        // ====================================================================

        $appStatus = ''; 
        $statusMessage = '';
        $rejectionReason = null;

        if ($mojData['owner_national_id'] !== $nationalId) {
            // الرفض لا يزال صارماً إذا اختلفت الهوية
            $appStatus = 'Rejected';
            $statusMessage = 'تم رفض الطلب آلياً: رقم الهوية الخاص بك لا يطابق رقم هوية مالك الصك في سجلات وزارة العدل.';
            $rejectionReason = 'رفض آلي عبر DSS: عدم تطابق الهوية الوطنية.';
        } elseif ($normalizedMojName !== $normalizedCustName) {
            // الإحالة للمدقق تتم فقط إذا كان الاختلاف جذرياً ولا يمكن ترقيعه بالخوارزمية
            $appStatus = 'Pending_Review';
            $statusMessage = 'تم استلام طلبك بنجاح. نظراً لوجود اختلاف كبير في الاسم بين حسابك والصك، تمت إحالة الطلب للمراجعة اليدوية.';
        } else {
            // قبول آلي وتوجيه لفني الفحص الميداني
            $appStatus = 'Pending_Inspection';
            $statusMessage = 'تطابق آلي 100%! تم التحقق من الصك والملكية بنجاح، وتحويل طلبك مباشرة للفحص الميداني.';
        }

        // --- 3. معالجة الملف المرفق والحفظ ---
        $fileTmpPath = $_FILES['deed_file']['tmp_name'];
        $hashedFileName = md5(time() . $custId) . '.' . pathinfo($_FILES['deed_file']['name'], PATHINFO_EXTENSION);
        $targetDir = "uploads/";
        if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);
        $targetFilePath = $targetDir . $hashedFileName;

        if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
            
            foreach ($finalServicesToCreate as $currentSrvId) {
                // حفظ الطلب في قاعدة البيانات 
                $q = "INSERT INTO application (cty_id, latitude, longitude, deed_no, deed_file_url, app_status, cust_id, srv_id) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($q)->execute([$cityId, $lat, $lng, $deedNumber, $targetFilePath, $appStatus, $custId, $currentSrvId]);
                $newAppId = $pdo->lastInsertId();

                // تسجيل الحركة في جدول التتبع
                $histQ = "INSERT INTO application_history (app_id, status, change_date, rejection_reason) VALUES (?, ?, NOW(), ?)";
                $pdo->prepare($histQ)->execute([$newAppId, $appStatus, $rejectionReason]);

                // ====================================================================
                // نظام التعيين الذكي (Smart Dispatching) لفنيي الفحص [إذا تم القبول الآلي]
                // ====================================================================
                if ($appStatus == 'Pending_Inspection') {
                    // البحث عن فني فحص نشط في نفس مدينة العقار، يمتلك أقل عدد من المهام الحالية
                    try {
                        // إضافة عمود is_active في حال لم يكن موجوداً لتفادي الأخطاء
                        $pdo->query("SELECT is_active FROM company_employee LIMIT 1");
                    } catch (Exception $e) {
                        $pdo->exec("ALTER TABLE company_employee ADD COLUMN is_active BOOLEAN DEFAULT 1");
                    }

                    $findTechStmt = $pdo->prepare("
                        SELECT ce.emp_id 
                        FROM company_employee ce
                        JOIN employee_roles er ON ce.emp_id = er.emp_id
                        JOIN system_role sr ON er.role_id = sr.role_id
                        WHERE ce.cty_id = ? 
                          AND ce.is_active = 1 
                          AND sr.role_name = 'Inspection Technician'
                        ORDER BY ce.active_tasks_count ASC 
                        LIMIT 1
                    ");
                    $findTechStmt->execute([$cityId]);
                    $assignedTechId = $findTechStmt->fetchColumn();

                    if ($assignedTechId) {
                        // إسناد المهمة للفني بإنشاء سجل في جدول الفحص الميداني
                        $pdo->prepare("INSERT INTO field_inspection (app_id, emp_id) VALUES (?, ?)")->execute([$newAppId, $assignedTechId]);
                        
                        // تحديث عبء العمل: زيادة عدد المهام النشطة للفني بمقدار 1
                        $pdo->prepare("UPDATE company_employee SET active_tasks_count = active_tasks_count + 1 WHERE emp_id = ?")->execute([$assignedTechId]);
                    }
                }
                // ====================================================================
            }

            // إعداد رسالة النجاح المناسبة للعميل
            if($appStatus == 'Rejected') {
                 echo json_encode(['status' => 'error', 'message' => $statusMessage]);
            } else {
                 $finalMsg = $statusMessage;
                 
                 if (count($finalServicesToCreate) > 1) {
                     $finalMsg = 'تم إنشاء الطلبات بنجاح. لفصل المهام وتسريع الإنجاز، تم تقسيم الخدمة إلى (طلب مياه) و (طلب صرف صحي) منفصلين. ' . $statusMessage;
                 } 
                 elseif ($duplicateServicesCount > 0) {
                     $finalMsg = 'تم استبعاد الخدمة المكررة التي تم التقديم عليها سابقاً، وتم إنشاء طلب للخدمة الجديدة فقط. ' . $statusMessage;
                 }

                 echo json_encode(['status' => 'success', 'message' => $finalMsg]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'فشل في رفع المرفقات، يرجى المحاولة لاحقاً.']);
        }
        exit;

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ في النظام أثناء معالجة الطلب.']);
        exit;
    }
}

// =====================================================================
// جلب البيانات للواجهة
// =====================================================================
$citiesWithRegions = []; $services = []; $myApplications = []; $stats = ['total' => 0, 'completed' => 0, 'in_progress' => 0];

try {
    $citiesWithRegions = $pdo->query("SELECT c.cty_id, c.cty_name, r.reg_name FROM city c JOIN region r ON c.reg_id = r.reg_id WHERE r.reg_name IN ('منطقة القصيم', 'منطقة حائل', 'منطقة الحدود الشمالية', 'منطقة الجوف') ORDER BY r.reg_id, c.cty_name")->fetchAll(PDO::FETCH_ASSOC);
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
        'Rejected' => '<span class="status-badge badge-danger"><i class="fa-solid fa-circle-xmark"></i> مرفوض آلياً</span>'
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --nwc-navy: #092e54; --nwc-blue: #4492d4; --nwc-light: #eaf3fb; --bg-color: #092e54; --card-shadow: 0 25px 60px rgba(0,0,0,0.25); }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-color); color: #334155; overflow-x: hidden; position: relative; }
        .bg-animation { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; background: radial-gradient(circle at top right, #10599c 0%, var(--nwc-navy) 70%); pointer-events: none; }
        .water-drop { position: absolute; bottom: -100px; background: linear-gradient(180deg, rgba(125, 211, 252, 0.1) 0%, rgba(125, 211, 252, 0.4) 100%); border-radius: 50%; animation: floatUp infinite ease-in; backdrop-filter: blur(5px); }
        @keyframes floatUp { 0% { transform: translateY(0) scale(0.8); opacity: 0; } 50% { opacity: 1; } 100% { transform: translateY(-120vh) scale(1.2); opacity: 0; } }
        .container { position: relative; z-index: 10; }
        .fade-in-up { animation: fadeInUp 0.7s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; opacity: 0; transform: translateY(20px); }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }
        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
        .hero-plain { padding: 10px 5px 25px; } .hero-name { color: #7dd3fc; font-weight: 900; }
        .hero-flex-row { display: flex; align-items: stretch; flex-wrap: wrap; gap: 18px; }
        .hero-text-box { flex: 1 1 400px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 20px; padding: 24px 28px; backdrop-filter: blur(8px); }
        .hero-count-pill { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; flex: 0 0 auto; min-width: 160px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 20px; padding: 20px 28px; backdrop-filter: blur(8px); }
        .hero-count-pill i { color: #7dd3fc; font-size: 1.6rem; } .hero-count-pill .hero-count-number { color: #ffffff; font-weight: 900; font-size: 2.3rem; line-height: 1; } .hero-count-pill .hero-count-label { color: #cbd5e1; font-weight: 700; font-size: 0.9rem; }
        @media (max-width: 576px) { .hero-text-box h1 { font-size: 1.5rem !important; } .hero-count-pill { flex-direction: row; width: 100%; min-width: 0; } }
        .navbar-luxury { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); padding: 15px 0; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25); border-bottom: 1px solid rgba(255, 255, 255, 0.2); position: sticky; top: 0; z-index: 1050; }
        .navbar-luxury::after { content: ''; position: absolute; bottom: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, var(--nwc-blue), transparent); }
        .brand-icon { background: linear-gradient(135deg, var(--nwc-navy), #0a1128); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 16px; box-shadow: 0 8px 20px rgba(9, 46, 84, 0.3); transition: transform 0.3s; }
        .brand-icon:hover { transform: scale(1.05) rotate(-5deg); } .brand-icon svg { width: 26px; height: 30px; }
        .user-profile-badge { background: white; padding: 6px 20px 6px 6px; border-radius: 50px; font-weight: 700; color: var(--nwc-navy); border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.02); cursor: pointer; transition: 0.3s; }
        .user-profile-badge:hover { background: #f8fafc; border-color: var(--nwc-blue); } .user-avatar { width: 38px; height: 38px; background: var(--nwc-light); color: var(--nwc-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .btn-logout { transition: all 0.3s; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; } .btn-logout:hover { background-color: #ef4444 !important; color: white !important; transform: rotate(90deg); }
        .premium-card { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); border-radius: 24px; padding: 40px; box-shadow: var(--card-shadow); border: 1px solid rgba(255,255,255,0.2); height: 100%; position: relative; }
        .premium-card::before { content: ''; position: absolute; top: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, var(--nwc-blue), transparent); border-radius: 0 0 10px 10px; }
        .premium-card.card-plain { background: transparent; backdrop-filter: none; box-shadow: none; border: none; height: auto; padding: 40px 0; } .premium-card.card-plain::before { display: none; }
        .premium-card.card-plain .card-header-title { color: #ffffff; border-bottom-color: rgba(255,255,255,0.15); } .premium-card.card-plain .card-header-title i { background: rgba(255,255,255,0.1); color: #7dd3fc; }
        .premium-card.card-plain .table-custom th { color: #93c5fd; border-bottom-color: rgba(255,255,255,0.15); } .premium-card.card-plain .table-custom td { color: #eaf3fb; border-bottom-color: rgba(255,255,255,0.08); }
        .card-header-title { color: var(--nwc-navy); font-weight: 900; font-size: 1.4rem; margin-bottom: 30px; display: flex; align-items: center; gap: 12px; border-bottom: 2px solid var(--nwc-light); padding-bottom: 15px; }
        .card-header-title i { background: var(--nwc-light); color: var(--nwc-blue); width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.2rem; }
        .form-label { font-weight: 800; color: #334155; font-size: 0.95rem; margin-bottom: 10px; } .required-mark { color: #b91c1c; font-weight: 800; margin-inline-start: 2px; }
        .form-control, .form-select { border-radius: 16px; border: 2px solid #e2e8f0; padding: 16px 20px; font-weight: 700; color: #1e293b; background: #f8fafc; transition: all 0.3s; font-size: 1rem; }
        .form-control:focus, .form-select:focus { border-color: var(--nwc-blue); background: white; box-shadow: 0 0 0 5px rgba(68, 146, 212, 0.15); outline: none; }
        .map-container { border: 2px solid #e2e8f0; border-radius: 20px; overflow: hidden; position: relative; height: 300px; box-shadow: inset 0 4px 10px rgba(0,0,0,0.05); }
        .map-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(248, 250, 252, 0.85); backdrop-filter: blur(8px); z-index: 1000; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--nwc-navy); transition: 0.4s; }
        .btn-gps { position: absolute; bottom: 20px; right: 20px; z-index: 1001; background: white; color: var(--nwc-navy); border: 2px solid white; padding: 12px 20px; border-radius: 16px; font-weight: 800; font-size: 0.95rem; box-shadow: 0 8px 25px rgba(0,0,0,0.15); transition: all 0.3s; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .btn-gps:hover { background: var(--nwc-navy); color: white; transform: translateY(-3px); }
        .upload-box { border: 2px dashed #cbd5e1; border-radius: 20px; padding: 35px 20px; text-align: center; background: #f8fafc; cursor: pointer; transition: 0.3s; display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .upload-box:hover { border-color: var(--nwc-blue); background: var(--nwc-light); } .upload-box i { color: var(--nwc-blue); font-size: 2.5rem; } .upload-box h6 { font-weight: 800; margin: 0; color: var(--nwc-navy); font-size: 1.1rem; } .upload-box span { font-weight: 600; color: #64748b; font-size: 0.85rem; }
        .file-status { display: none; background: #ecfdf5; color: #059669; padding: 12px; border-radius: 12px; font-weight: 800; text-align: center; margin-top: 15px; border: 1px solid #a7f3d0; }
        .btn-brand { background: linear-gradient(135deg, var(--nwc-navy), var(--nwc-blue)); color: white; border: none; border-radius: 16px; padding: 18px; font-weight: 900; font-size: 1.2rem; width: 100%; transition: all 0.4s; box-shadow: 0 10px 25px rgba(9, 46, 84, 0.2); display: flex; justify-content: center; align-items: center; gap: 10px; }
        .btn-brand:hover { transform: translateY(-4px); box-shadow: 0 15px 35px rgba(9, 46, 84, 0.3); color: white; }
        .table-custom th { border-bottom: 2px solid var(--nwc-light); color: #64748b; font-weight: 800; padding: 18px 15px; font-size: 0.95rem; } .table-custom td { padding: 20px 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-weight: 700; color: #1e293b; }
        .status-badge { padding: 8px 15px; border-radius: 12px; font-weight: 800; display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; }
        .badge-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; } .badge-info { background: var(--nwc-light); color: var(--nwc-blue); border: 1px solid #bae6fd; } .badge-primary { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; } .badge-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; } .badge-dark { background: #f8fafc; color: #334155; border: 1px solid #e2e8f0; } .badge-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .empty-state { text-align: center; padding: 60px 20px; } .empty-state-icon { width: 100px; height: 100px; background: var(--nwc-light); color: var(--nwc-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 3rem; margin: 0 auto 20px; }
    </style>
</head>
<body>

<div class="bg-animation" id="bg-particles"></div>
<script>
    const bgContainer = document.getElementById('bg-particles');
    for (let i = 0; i < 20; i++) {
        let drop = document.createElement('div');
        drop.classList.add('water-drop');
        drop.style.left = Math.random() * 100 + 'vw';
        drop.style.width = Math.random() * 40 + 20 + 'px';
        drop.style.height = drop.style.width;
        drop.style.animationDuration = Math.random() * 5 + 5 + 's';
        drop.style.animationDelay = Math.random() * 5 + 's';
        bgContainer.appendChild(drop);
    }
</script>

<nav class="navbar navbar-luxury fade-in-up">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="#" class="d-flex align-items-center gap-3 text-decoration-none">
            <div class="brand-icon">
                <svg viewBox="0 0 60 68" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="30" cy="6"  r="2.1" fill="#bae6fd"/>
                    <circle cx="26.3" cy="13" r="2.3" fill="#bae6fd"/>
                    <circle cx="33.7" cy="13" r="2.3" fill="#93c5fd"/>
                    <circle cx="22.6" cy="20" r="2.6" fill="#93c5fd"/>
                    <circle cx="30"   cy="20" r="2.6" fill="#7dd3fc"/>
                    <circle cx="37.4" cy="20" r="2.6" fill="#60a5fa"/>
                    <circle cx="18.9" cy="27" r="2.9" fill="#7dd3fc"/>
                    <circle cx="26.3" cy="27" r="2.9" fill="#60a5fa"/>
                    <circle cx="33.7" cy="27" r="2.9" fill="#4492d4"/>
                    <circle cx="41.1" cy="27" r="2.9" fill="#3b82f6"/>
                    <circle cx="15.2" cy="34" r="3.3" fill="#60a5fa"/>
                    <circle cx="22.6" cy="34" r="3.3" fill="#4492d4"/>
                    <circle cx="30"   cy="34" r="3.3" fill="#3b82f6"/>
                    <circle cx="37.4" cy="34" r="3.3" fill="#2563eb"/>
                    <circle cx="44.8" cy="34" r="3.3" fill="#1d4ed8"/>
                    <circle cx="18.9" cy="41" r="3.6" fill="#2563eb"/>
                    <circle cx="26.3" cy="41" r="3.6" fill="#1d4ed8"/>
                    <circle cx="33.7" cy="41" r="3.6" fill="#bae6fd"/>
                    <circle cx="41.1" cy="41" r="3.6" fill="#bae6fd"/>
                    <circle cx="22.6" cy="48" r="3.8" fill="#7dd3fc"/>
                    <circle cx="30"   cy="48" r="3.8" fill="#e0f2fe"/>
                    <circle cx="37.4" cy="48" r="3.8" fill="#7dd3fc"/>
                    <circle cx="26.3" cy="55" r="3.9" fill="#e0f2fe"/>
                    <circle cx="33.7" cy="55" r="3.9" fill="#e0f2fe"/>
                </svg>
            </div>
            <div>
                <div class="fw-black fs-4" style="color: var(--nwc-navy); line-height: 1.1;">قطــرة</div>
                <div class="text-muted" style="font-size: 0.85rem; font-weight: 800;">بوابة الخدمات الموحدة</div>
            </div>
        </a>
        <div class="d-flex align-items-center gap-3">
            <div class="user-profile-badge" data-bs-toggle="modal" data-bs-target="#profileModal" title="عرض بياناتي">
                <span class="ms-2"><?= htmlspecialchars($customer['full_name'] ?? $customerName); ?></span>
                <div class="user-avatar"><i class="fa-solid fa-user-tie"></i></div>
            </div>
            <a href="?logout=1" class="btn btn-light text-danger rounded-circle shadow-sm border btn-logout" title="تسجيل الخروج">
                <i class="fa-solid fa-power-off"></i>
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5 mt-4">
    <div class="row mb-5 fade-in-up delay-1">
        <div class="col-12">
            <div class="hero-plain">
                <div class="hero-flex-row">
                    <div class="hero-text-box">
                        <h1 class="fw-black m-0" style="color: white; font-size: 2.2rem;">
                            أهلاً بك، <span class="hero-name"><?= htmlspecialchars($customer['full_name'] ?? $customerName); ?></span>
                        </h1>
                        <p class="mb-0 fs-5 mt-3" style="color: #cbd5e1; line-height: 1.8; font-weight: 500;">
                            نحن هنا لخدمتك! <span style="color: #93c5fd;">قطرة</span> تقدم لك تجربة رقمية استثنائية لطلب وإدارة خدمات المياه والصرف الصحي لعقاراتك بكل سهولة وشفافية.
                        </p>
                    </div>
                    <div class="hero-count-pill">
                        <i class="fa-solid fa-layer-group"></i>
                        <span class="hero-count-number"><?= $stats['total']; ?></span>
                        <span class="hero-count-label">إجمالي الطلبات</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
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
                        <label class="form-label">الخدمة المطلوبة <span class="required-mark">*</span></label>
                        <select name="srv_id" class="form-select" required>
                            <option value="" selected disabled>-- اختر نوع الخدمة --</option>
                            <?php foreach($services as $srv): ?>
                                <option value="<?= $srv['srv_id']; ?>"><?= htmlspecialchars($srv['srv_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">المدينة المرتبطة بالعقار <span class="required-mark">*</span></label>
                        <select name="cty_id" id="citySelect" class="form-select" required onchange="unlockMap()">
                            <option value="" selected disabled>-- اختر المدينة لتفعيل الخريطة --</option>
                            <?php 
                            $currentRegion = '';
                            foreach($citiesWithRegions as $city): 
                                if ($currentRegion != $city['reg_name']) {
                                    if ($currentRegion != '') echo '</optgroup>';
                                    $currentRegion = $city['reg_name'];
                                    echo '<optgroup label="' . htmlspecialchars($currentRegion) . '">';
                                }
                            ?>
                                <option value="<?= $city['cty_id']; ?>" data-city="<?= htmlspecialchars($city['cty_name']); ?>">&nbsp;&nbsp;&nbsp; مدينة <?= htmlspecialchars($city['cty_name']); ?></option>
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
                        <div id="gpsStatus" class="small text-muted mt-2 fw-bold text-center"><i class="fa-solid fa-circle-info" style="color:#3b82f6;"></i> انقر للتحديد داخل نطاق الدائرة الزرقاء فقط.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">رقم صك الملكية (إلزامي) <span class="required-mark">*</span></label>
                        <div class="input-group" style="direction: ltr;">
                            <input type="text" name="deed_no" id="deedInput" class="form-control" style="text-align: right; border-radius: 0 16px 16px 0;" placeholder="أدخل 12 رقماً" required minlength="12" maxlength="12" pattern="\d{12}" title="يجب أن يتكون رقم الصك من 12 رقماً تماماً" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            <span class="input-group-text bg-light text-muted fw-bold" style="border-radius: 16px 0 0 16px; border: 2px solid #e2e8f0; border-right: none;">
                                <i class="fa-solid fa-hashtag" style="color: #8b5cf6;"></i>
                            </span>
                        </div>
                        <div class="small text-muted mt-1 fw-bold text-end">يتكون رقم الصك الإلكتروني من 12 رقماً (مثال: 711029485736)</div>
                    </div>

                    <div class="mb-5">
                        <label class="form-label">نسخة من الصك (إلزامي) <span class="required-mark">*</span></label>
                        <div class="upload-box" id="uploadBox" onclick="document.getElementById('fileInput').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <h6>انقر هنا لإرفاق ملف الصك</h6>
                            <span>الحد الأقصى 5MB (PDF, JPG, PNG)</span>
                            <input type="file" id="fileInput" name="deed_file" class="d-none" accept=".pdf, .jpg, .jpeg, .png" required>
                        </div>
                        <div id="fileNameDisplay" class="file-status"></div>
                    </div>

                    <button type="submit" class="btn-brand" id="submitBtn">
                        إرسال الطلب <i class="fa-solid fa-paper-plane text-info"></i>
                    </button>
                </form>
            </div>
        </div>

        <div class="col-xl-7 col-lg-7 fade-in-up delay-3">
            <div class="premium-card card-plain">
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
                                    <th>رقم الصك</th>
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

<!-- Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 24px; border: none; box-shadow: 0 25px 50px rgba(0,0,0,0.15);">
            <div class="modal-header border-0 pb-0 position-relative">
                <button type="button" class="btn-close ms-auto mt-2 me-2" data-bs-dismiss="modal" aria-label="Close" style="z-index: 10;"></button>
            </div>
            <div class="modal-body text-center px-4 pb-5 pt-0">
                <div class="user-avatar mx-auto mb-3" style="width: 90px; height: 90px; font-size: 3rem; background: linear-gradient(135deg, var(--nwc-navy), var(--nwc-blue)); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 25px rgba(9,46,84,0.3);">
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
    // برمجة الخريطة الذكية (نظام الحصار الجغرافي Geofencing مخصص لنطاق العمل)
    // ==========================================
    let map = L.map('propertyMap').setView([26.3260, 43.9390], 7); 
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© Qatra Smart Systems' }).addTo(map);
    
    let marker = null;
    let cityCircle = null;
    let currentValidCenter = null;
    let currentValidRadius = null;

    const cityData = {
        // منطقة القصيم
        'بريدة': { coords: [26.3260, 43.9390], radius: 25000 },
        'الربيعية': { coords: [26.2750, 44.0700], radius: 10000 },
        'الشماسية': { coords: [26.3000, 44.1300], radius: 10000 },
        'عنيزة': { coords: [26.0855, 43.9760], radius: 15000 },
        'الرس': { coords: [25.8694, 43.4973], radius: 15000 },
        'البكيرية': { coords: [26.1442, 43.6593], radius: 10000 },
        'المذنب': { coords: [25.8643, 44.2006], radius: 10000 },
        'عيون الجواء': { coords: [26.5000, 43.6167], radius: 10000 },
        'ضرية': { coords: [24.9667, 43.0167], radius: 10000 },
        
        // منطقة حائل
        'حائل': { coords: [27.5114, 41.7208], radius: 25000 },
        'بقعاء': { coords: [28.0333, 42.6167], radius: 15000 },
        'الشنان': { coords: [27.1333, 42.3833], radius: 10000 },
        'الحائط': { coords: [25.9667, 40.4833], radius: 10000 },

        // منطقة الجوف
        'سكاكا': { coords: [29.9697, 40.2064], radius: 20000 },
        'القريات': { coords: [31.3333, 37.3667], radius: 15000 },
        'دومة الجندل': { coords: [29.8167, 39.8667], radius: 15000 },
        'طبرجل': { coords: [30.4833, 38.2000], radius: 15000 },

        // منطقة الحدود الشمالية
        'عرعر': { coords: [30.9833, 41.0167], radius: 20000 },
        'رفحاء': { coords: [29.6333, 43.5000], radius: 15000 },
        'طريف': { coords: [31.6667, 38.6667], radius: 15000 }
    };

    function isWithinBounds(lat, lng) {
        if (!currentValidCenter) return false;
        let pt = L.latLng(lat, lng);
        return currentValidCenter.distanceTo(pt) <= currentValidRadius;
    }

    function unlockMap() {
        let select = document.getElementById('citySelect');
        let cityName = select.options[select.selectedIndex].getAttribute('data-city');
        
        document.getElementById('mapLock').style.opacity = '0';
        setTimeout(() => { document.getElementById('mapLock').style.display = 'none'; }, 400);
        document.getElementById('btnGps').classList.remove('d-none');
        
        document.getElementById('latitude').value = '';
        document.getElementById('longitude').value = '';
        if(marker) map.removeLayer(marker);
        marker = null;
        document.getElementById('gpsStatus').innerHTML = `<span class="text-primary fw-bold"><i class="fa-solid fa-hand-pointer me-1"></i> انقر للتحديد داخل نطاق الدائرة الزرقاء فقط.</span>`;

        let coords = [26.3260, 43.9390];
        let radius = 15000; 

        for (let key in cityData) { 
            if (cityName && cityName.includes(key)) { 
                coords = cityData[key].coords; 
                radius = cityData[key].radius;
                break; 
            } 
        }
        
        currentValidCenter = L.latLng(coords, coords);
        currentValidRadius = radius;

        if (cityCircle) map.removeLayer(cityCircle);
        cityCircle = L.circle(coords, {
            color: '#4492d4',
            fillColor: '#4492d4',
            fillOpacity: 0.15,
            radius: radius
        }).addTo(map);

        setTimeout(() => { map.invalidateSize(); map.flyTo(coords, 11, { animate: true, duration: 1.5 }); }, 300);
    }

    function getCurrentLocation() {
        if (!currentValidCenter) {
            Swal.fire('تنبيه', 'يرجى اختيار المدينة أولاً.', 'warning');
            return;
        }

        if (navigator.geolocation) {
            let btn = document.getElementById('btnGps');
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري التحديد...';
            
            navigator.geolocation.getCurrentPosition(function(pos) {
                let lat = pos.coords.latitude, lng = pos.coords.longitude;
                
                if (!isWithinBounds(lat, lng)) {
                    btn.innerHTML = '<i class="fa-solid fa-location-crosshairs text-warning"></i> موقعي الحالي';
                    Swal.fire('خارج نطاق الخدمة', 'موقعك الحالي يقع خارج حدود المدينة التي قمت باختيارها! يرجى اختيار المدينة الصحيحة أو تحديد الموقع يدوياً داخل الدائرة المظللة.', 'error');
                    return;
                }

                map.flyTo([lat, lng], 16, { animate: true, duration: 1.5 });
                if (marker) marker.setLatLng([lat, lng]); else marker = L.marker([lat, lng]).addTo(map);
                
                document.getElementById('latitude').value = lat;
                document.getElementById('longitude').value = lng;
                
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
        
        if (!isWithinBounds(lat, lng)) {
            Swal.fire({
                icon: 'error',
                title: 'خارج النطاق',
                text: 'عفواً، الموقع الذي حددته يقع خارج حدود المدينة المسموح بها. الرجاء التحديد داخل المساحة الزرقاء المظللة فقط.',
                confirmButtonColor: '#092e54'
            });
            return;
        }

        if (marker) marker.setLatLng(e.latlng); else marker = L.marker(e.latlng).addTo(map);
        document.getElementById('latitude').value = lat;
        document.getElementById('longitude').value = lng;
        document.getElementById('gpsStatus').innerHTML = `<span class="text-success fw-bold"><i class="fa-solid fa-check-circle me-1"></i> تم تحديد موقع العقار بدقة.</span>`;
    });

    // ==========================================
    // التفاعلات وإرسال الطلب 
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
            Swal.fire({icon: 'warning', title: 'خطوة مفقودة', text: 'يرجى تحديد موقع العقار على الخريطة أولاً.', confirmButtonColor: '#092e54'});
            return;
        }

        let submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;

        Swal.fire({title: 'جاري فحص الطلب...', text: 'يتم الآن مطابقة الصك والهوية مع سجلات وزارة العدل..', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});

        fetch('dashboard.php', { method: 'POST', body: new FormData(this) })
        .then(r => r.json()).then(data => {
            if(data.status === 'error') {
                Swal.fire({icon: 'error', title: 'تنبيه نظام (DSS)', text: data.message, confirmButtonColor: '#092e54'}).then(() => { submitBtn.disabled = false; });
            } else {
                Swal.fire({icon: 'success', title: 'عملية ناجحة', text: data.message, confirmButtonColor: '#10b981'}).then(() => { window.location.reload(); });
            }
        }).catch(error => {
            submitBtn.disabled = false;
            Swal.fire('خطأ تقني', 'حدث خطأ في الاتصال بالخادم، يرجى التحقق من الشبكة.', 'error');
        });
    });
</script>
</body>
</html>