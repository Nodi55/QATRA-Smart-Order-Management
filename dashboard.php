<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// 1. نظام تسجيل الخروج المدمج وآلية الأمان
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

require_once 'db_connect.php';

if (!isset($_SESSION['customer_national_id'])) {
    header("Location: login.php");
    exit;
}

$nationalId = $_SESSION['customer_national_id'];
$customerName = $_SESSION['customer_name'] ?? 'عميلنا العزيز';

// جلب بيانات العميل الكاملة من قاعدة البيانات
$stmt = $pdo->prepare("SELECT cust_id, full_name, phone_number FROM customer WHERE national_id = ?");
$stmt->execute([$nationalId]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die("<div style='text-align:center; color:red; margin-top:50px; font-family:Cairo'>عفواً، لم يتم العثور على بيانات المستفيد في النظام.</div>");
}

$custId = $customer['cust_id'];

// دالة تنظيف أسماء الخدمات الاحترافية والذكية للغاية لتبسيط المسميات
function cleanServiceName($name) {
    if (strpos($name, 'مياه وصرف') !== false) return 'مياه وصرف';
    if (strpos($name, 'مياه') !== false) return 'مياه';
    if (strpos($name, 'صرف') !== false) return 'صرف';
    return $name;
}

// =========================================================
// معالجة تقديم طلب جديد ومطابقة الصك الفورية (DSS)
// وتقسيم طلب "مياه وصرف" إلى طلبين منفصلين تشغيلياً آلياً
// =========================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_new_app'])) {
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
        // التحقق من وجود الصك ومطابقته آلياً مع وزارة العدل (DSS validation)
        $checkMoj = $pdo->prepare("SELECT * FROM moj_record WHERE deed_no = ?");
        $checkMoj->execute([$deedNumber]);
        $mojRecord = $checkMoj->fetch(PDO::FETCH_ASSOC);

        if (!$mojRecord) {
            echo json_encode(['status' => 'error', 'message' => 'عفواً، رقم الصك المدخل غير متطابق مع سجلات وزارة العدل.']);
            exit;
        }

        // مطابقة الهوية الوطنية للعميل مع مالك الصك في وزارة العدل
        if ($mojRecord['owner_national_id'] !== $nationalId) {
            $appStatus = 'Pending_Review';
            $notifMsg = "تم تقديم طلبك بنجاح، وتحويله للمدقق لمطابقة اختلاف بيانات المالك.";
        } else {
            $appStatus = 'Pending_Inspection';
            $notifMsg = "تهانينا! تم التحقق من صك الملكية آلياً بنجاح عبر محرك (DSS). تم توجيه طلبك مباشرة إلى مرحلة الفحص الميداني.";
        }

        // رفع ملف الصك وتشفيره لحمايته أمنياً
        $fileTmpPath = $_FILES['deed_file']['tmp_name'];
        $hashedFileName = md5(time() . $custId) . '.' . pathinfo($_FILES['deed_file']['name'], PATHINFO_EXTENSION);
        $targetDir = "uploads/";
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }
        $targetFilePath = $targetDir . $hashedFileName;

        if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
            
            // خوارزمية تقسيم الطلب: إذا تم اختيار "مياه وصرف" (3)، يتم إنشاء طلبين منفصلين: مياه (1) وصرف (2)
            $servicesToInsert = [];
            if ($srvId == 3) {
                $servicesToInsert = [1, 2];
            } else {
                $servicesToInsert = [$srvId];
            }

            $insertedCount = 0;
            $appsDetails = [];

            foreach ($servicesToInsert as $singleSrvId) {
                // التحقق من عدم وجود طلب مكرر نشط لنفس الخدمة على هذا الصك منعاً للسبام
                $stmtCheckDup = $pdo->prepare("SELECT COUNT(*) FROM application WHERE deed_no = ? AND srv_id = ? AND app_status != 'Rejected'");
                $stmtCheckDup->execute([$deedNumber, $singleSrvId]);
                if ($stmtCheckDup->fetchColumn() > 0) {
                    continue; // تجاوز الخدمة المكررة
                }

                // إدراج الطلب الفردي في قاعدة البيانات
                $q = "INSERT INTO application (cty_id, latitude, longitude, deed_no, deed_file_url, app_status, cust_id, srv_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($q)->execute([$cityId, $lat, $lng, $deedNumber, $targetFilePath, $appStatus, $custId, $singleSrvId]);
                $newAppId = $pdo->lastInsertId();
                $insertedCount++;

                $srvNameClean = ($singleSrvId == 1) ? 'مياه' : 'صرف';
                $appsDetails[] = "طلب {$srvNameClean} رقم (#" . str_pad($newAppId, 5, '0', STR_PAD_LEFT) . ")";

                // التوزيع الجغرافي الذكي لفنيي الفحص لكل طلب على حدة لتقليل العبء
                if ($appStatus == 'Pending_Inspection') {
                    $bestTechStmt = $pdo->prepare("
                        SELECT ce.emp_id 
                        FROM company_employee ce
                        JOIN employee_roles er ON ce.emp_id = er.emp_id 
                        JOIN system_role sr ON er.role_id = sr.role_id
                        JOIN city c ON ce.cty_id = c.cty_id
                        WHERE ce.is_active = 1 AND sr.role_name = 'Inspection Technician'
                        ORDER BY 
                            (ce.cty_id = ?) DESC,
                            (c.reg_id = (SELECT reg_id FROM city WHERE cty_id = ?)) DESC,
                            ce.active_tasks_count ASC
                        LIMIT 1
                    ");
                    $bestTechStmt->execute([$cityId, $cityId]);
                    $bestTechId = $bestTechStmt->fetchColumn();

                    if ($bestTechId) {
                        $pdo->prepare("INSERT INTO field_inspection (app_id, emp_id) VALUES (?, ?)")->execute([$newAppId, $bestTechId]);
                        $pdo->prepare("UPDATE company_employee SET active_tasks_count = active_tasks_count + 1 WHERE emp_id = ?")->execute([$bestTechId]);
                    }
                }
            }

            if ($insertedCount == 0) {
                echo json_encode(['status' => 'error', 'message' => 'عفواً، هذه الخدمات مسجلة أو نشطة بالفعل على هذا العقار مسبقاً.']);
                exit;
            }

            // صياغة الإشعار والرد النهائي بناءً على عدد الطلبات التي تم إنشاؤها
            if ($srvId == 3) {
                $finalNotifMsg = "تم إنشاء طلبين منفصلين لعقارك بنجاح لتسريع الإنجاز والتركيب الميداني: " . implode(" و ", $appsDetails) . ". " . ($appStatus == 'Pending_Inspection' ? "تم توجيههما مباشرة للفحص الميداني الموزع." : "بانتظار المراجعة التدقيقية.");
            } else {
                $finalNotifMsg = $notifMsg . " تم إنشاء " . $appsDetails[0];
            }

            // إرسال الإشعار للمستفيد
            $pdo->prepare("INSERT INTO notification (message_content, cust_id) VALUES (?, ?)")->execute([$finalNotifMsg, $custId]);

            echo json_encode(['status' => 'success', 'message' => $finalNotifMsg]);
            exit;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'فشل في رفع ملف الصك.']);
            exit;
        }

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ في النظام أثناء معالجة الطلب: ' . $e->getMessage()]);
        exit;
    }
}

// =========================================================
// معالجة سداد الفاتورة والفوترة (Mock Payment & Provisioning Engine)
// =========================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['pay_invoice'])) {
    header('Content-Type: application/json');
    $appId = $_POST['app_id'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT a.app_id, a.cust_id, a.deed_no, a.srv_id, a.cty_id, i.inv_id, i.amount 
                               FROM application a 
                               JOIN invoice i ON a.app_id = i.app_id 
                               WHERE a.app_id = ? AND i.payment_status = 'Unpaid'");
        $stmt->execute([$appId]);
        $appData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($appData) {
            $pdo->prepare("UPDATE invoice SET payment_status = 'Paid' WHERE inv_id = ?")->execute([$appData['inv_id']]);
            $pdo->prepare("UPDATE application SET app_status = 'In_Progress' WHERE app_id = ?")->execute([$appId]);

            $stmtCheckAcc = $pdo->prepare("SELECT acc_id FROM unified_account WHERE deed_no = ?");
            $stmtCheckAcc->execute([$appData['deed_no']]);
            $accId = $stmtCheckAcc->fetchColumn();

            if (!$accId) {
                $pdo->prepare("INSERT INTO unified_account (cust_id, deed_no) VALUES (?, ?)")->execute([$custId, $appData['deed_no']]);
                $accId = $pdo->lastInsertId();
            }

            $pdo->prepare("INSERT INTO activated_service (acc_id, srv_id) VALUES (?, ?)")->execute([$accId, $appData['srv_id']]);

            // التوجيه التلقائي الجغرافي لأقرب فني تركيبات متاح في نفس المدينة/المنطقة
            $techStmt = $pdo->prepare("
                SELECT ce.emp_id 
                FROM company_employee ce
                JOIN employee_roles er ON ce.emp_id = er.emp_id 
                JOIN system_role sr ON er.role_id = sr.role_id 
                JOIN city c ON ce.cty_id = c.cty_id
                WHERE ce.is_active = 1 AND sr.role_name = 'Installation Technician'
                ORDER BY 
                    (ce.cty_id = ?) DESC,
                    (c.reg_id = (SELECT reg_id FROM city WHERE cty_id = ?)) DESC,
                    ce.active_tasks_count ASC
                LIMIT 1
            ");
            $techStmt->execute([$appData['cty_id'], $appData['cty_id']]);
            $techId = $techStmt->fetchColumn();

            if ($techId) {
                $pdo->prepare("INSERT INTO installation_task (app_id, emp_id) VALUES (?, ?)")->execute([$appId, $techId]);
                $pdo->prepare("UPDATE company_employee SET active_tasks_count = active_tasks_count + 1 WHERE emp_id = ?")->execute([$techId]);
            }

            $notifMsg = "تم تأكيد سداد الفاتورة رقم #" . str_pad($appId, 5, '0', STR_PAD_LEFT) . " بقيمة " . number_format($appData['amount'], 2) . " ريال بنجاح. تم إنشاء حسابك الموحد (ACC-" . str_pad($accId, 5, '0', STR_PAD_LEFT) . ") وإسناد تركيب العداد للفريق الفني.";
            $pdo->prepare("INSERT INTO notification (message_content, cust_id) VALUES (?, ?)")->execute([$notifMsg, $custId]);
            $pdo->prepare("INSERT INTO application_history (app_id, status, change_date) VALUES (?, 'In_Progress', NOW())")->execute([$appId]);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'تم تأكيد السداد بنجاح وتفعيل خطة التركيبات الميدانية!']);
            exit;
        } else {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'عفواً، لم يتم العثور على الفاتورة المستحقة.']);
            exit;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'فشلت العملية الموحدة: ' . $e->getMessage()]);
        exit;
    }
}

// =========================================================
// جلب البيانات اللازمة للواجهة الفاخرة
// =========================================================
$citiesWithRegions = [];
$services = [];
$myApplications = [];
$myProperties = [];
$myNotifications = [];
$stats = ['total' => 0, 'completed' => 0, 'pending_payment' => 0];

try {
    // جلب المدن والمناطق (مع حذف القرى غير الموثقة بالإحداثيات وحذف كلمة "مدينة" من الأسماء)
    $citiesWithRegions = $pdo->query("
        SELECT c.cty_id, c.cty_name, r.reg_name 
        FROM city c 
        JOIN region r ON c.reg_id = r.reg_id 
        WHERE c.cty_name NOT IN ('عيون الجواء', 'ضرية', 'الشماسية', 'بقعاء', 'الشنان', 'الحائط') 
        ORDER BY r.reg_id, c.cty_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $services = $pdo->query("SELECT srv_id, srv_name FROM service_type")->fetchAll(PDO::FETCH_ASSOC);

    $appStmt = $pdo->prepare("
        SELECT a.app_id, s.srv_name, a.deed_no, a.app_status, a.created_at, c.cty_name,
               i.amount, i.payment_status
        FROM application a
        JOIN city c ON a.cty_id = c.cty_id
        JOIN service_type s ON a.srv_id = s.srv_id
        LEFT JOIN invoice i ON a.app_id = i.app_id
        WHERE a.cust_id = ? ORDER BY a.created_at DESC
    ");
    $appStmt->execute([$custId]);
    $myApplications = $appStmt->fetchAll(PDO::FETCH_ASSOC);

    $stats['total'] = count($myApplications);
    foreach ($myApplications as $app) {
        if ($app['app_status'] == 'Completed') $stats['completed']++;
        if ($app['app_status'] == 'Pending_Billing') $stats['pending_payment']++;
    }

    // جلب الحسابات الموحدة للعميل أولاً (حساب مفرّد لكل عقار/صك) لتجنب تكرار الصناديق
    $stmtAcc = $pdo->prepare("
        SELECT ua.acc_id, ua.deed_no, ua.creation_date, moj.land_area, moj.owner_name
        FROM unified_account ua
        JOIN moj_record moj ON ua.deed_no = moj.deed_no
        WHERE ua.cust_id = ? ORDER BY ua.creation_date DESC
    ");
    $stmtAcc->execute([$custId]);
    $accounts = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);

    $myProperties = [];
    foreach ($accounts as $acc) {
        $accId = $acc['acc_id'];

        // جلب الخدمات المفعّلة لهذا الحساب الموحد
        $stmtSrv = $pdo->prepare("
            SELECT st.srv_id, st.srv_name 
            FROM activated_service acs
            JOIN service_type st ON acs.srv_id = st.srv_id
            WHERE acs.acc_id = ?
        ");
        $stmtSrv->execute([$accId]);
        $activeServices = $stmtSrv->fetchAll(PDO::FETCH_ASSOC);

        // جلب العدادات المركبة لهذا الحساب الموحد بالتفصيل
        $stmtMtr = $pdo->prepare("
            SELECT m.mtr_serial, m.mtr_type, app.srv_id
            FROM meter m
            LEFT JOIN installation_task it ON m.task_id = it.task_id
            LEFT JOIN application app ON it.app_id = app.app_id
            WHERE m.acc_id = ?
        ");
        $stmtMtr->execute([$accId]);
        $meters = $stmtMtr->fetchAll(PDO::FETCH_ASSOC);

        $acc['services'] = $activeServices;
        $acc['meters'] = $meters;

        $myProperties[] = $acc;
    }

    $notifStmt = $pdo->prepare("SELECT * FROM notification WHERE cust_id = ? ORDER BY created_at DESC LIMIT 30");
    $notifStmt->execute([$custId]);
    $myNotifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // معالجة صامتة
}

function getStatusBadge($status) {
    $badges = [
        'Pending_Review' => '<span class="status-badge badge-warning"><i class="fa-solid fa-file-signature"></i> قيد المراجعة</span>',
        'Pending_Inspection' => '<span class="status-badge badge-info"><i class="fa-solid fa-helmet-safety"></i> جاري جدولة الفحص</span>',
        'Pending_Billing' => '<span class="status-badge badge-dark"><i class="fa-solid fa-file-invoice-dollar"></i> بانتظار سداد الفاتورة</span>',
        'In_Progress' => '<span class="status-badge badge-primary"><i class="fa-solid fa-person-digging"></i> جاري التركيب</span>',
        'Completed' => '<span class="status-badge badge-success"><i class="fa-solid fa-circle-check"></i> مكتمل ومفعّل بالكامل</span>',
        'Rejected' => '<span class="status-badge badge-danger"><i class="fa-solid fa-circle-xmark"></i> تم رفض الطلب</span>'
    ];
    return $badges[$status] ?? '<span class="status-badge bg-secondary">' . $status . '</span>';
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نظام قطرة | البوابة الذكية للمستفيدين</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --nwc-navy: #092e54;
            --nwc-blue: #4492d4;
            --nwc-light: #eaf3fb;
            --bg-color: #092e54;
            --card-shadow: 0 25px 60px rgba(0,0,0,0.25);
        }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-color); color: #334155; overflow-x: hidden; position: relative; }
        
        .bg-animation { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; background: radial-gradient(circle at top right, #10599c 0%, var(--nwc-navy) 70%); pointer-events: none; }
        .water-drop { position: absolute; bottom: -100px; background: linear-gradient(180deg, rgba(125, 211, 252, 0.1) 0%, rgba(125, 211, 252, 0.4) 100%); border-radius: 50%; animation: floatUp infinite ease-in; backdrop-filter: blur(5px); }
        @keyframes floatUp { 0% { transform: translateY(0) scale(0.8); opacity: 0; } 50% { opacity: 1; } 100% { transform: translateY(-120vh) scale(1.2); opacity: 0; } }
        
        .container { position: relative; z-index: 10; }
        .fade-in-up { animation: fadeInUp 0.7s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; opacity: 0; transform: translateY(20px); }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }
        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
        
        .navbar-luxury { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); padding: 15px 0; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25); border-bottom: 1px solid rgba(255, 255, 255, 0.2); position: relative; }
        .navbar-luxury::after { content: ''; position: absolute; bottom: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, var(--nwc-blue), transparent); }
        .brand-icon { background: linear-gradient(135deg, var(--nwc-navy), #0a1128); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 16px; box-shadow: 0 8px 20px rgba(9, 46, 84, 0.3); transition: transform 0.3s; }
        .brand-icon:hover { transform: scale(1.05) rotate(-5deg); }
        
        .user-profile-badge { background: white; padding: 6px 20px 6px 6px; border-radius: 50px; font-weight: 700; color: var(--nwc-navy); border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 12px; transition: 0.3s; cursor: pointer; }
        .user-profile-badge:hover { background: #f8fafc; border-color: var(--nwc-blue); }
        .user-avatar { width: 38px; height: 38px; background: var(--nwc-light); color: #0b457f; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .btn-logout { transition: all 0.3s; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; }
        .btn-logout:hover { background-color: #ef4444 !important; color: white !important; transform: rotate(90deg); }
        
        .premium-card { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); border-radius: 24px; padding: 40px; box-shadow: var(--card-shadow); border: 1px solid rgba(255,255,255,0.2); position: relative; }
        .premium-card::before { content: ''; position: absolute; top: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, var(--nwc-blue), transparent); border-radius: 0 0 10px 10px; }
        
        .card-header-title { color: var(--nwc-navy); font-weight: 900; font-size: 1.4rem; margin-bottom: 30px; display: flex; align-items: center; gap: 12px; border-bottom: 2px solid var(--nwc-light); padding-bottom: 15px; }
        .card-header-title i { background: var(--nwc-light); color: var(--nwc-blue); width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.2rem; }
        
        .custom-tabs { display: flex; gap: 15px; border-bottom: 2px solid rgba(255, 255, 255, 0.1); padding-bottom: 10px; margin-bottom: 30px; overflow-x: auto; }
        .tab-btn { background: rgba(255, 255, 255, 0.05); color: #cbd5e1; border: 1px solid rgba(255, 255, 255, 0.1); padding: 12px 25px; border-radius: 50px; font-weight: 700; transition: 0.3s; white-space: nowrap; }
        .tab-btn:hover, .tab-btn.active { background: white; color: var(--nwc-navy); border-color: white; transform: translateY(-2px); }
        
        .tab-panel { display: none; }
        .tab-panel.active { display: block; animation: panelFade 0.4s ease-in-out; }
        @keyframes panelFade { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .form-control, .form-select { border-radius: 16px; border: 2px solid #e2e8f0; padding: 16px 20px; font-weight: 700; color: #1e293b; background: #f8fafc; transition: all 0.3s; }
        .form-control:focus, .form-select:focus { border-color: var(--nwc-blue); background: white; box-shadow: 0 0 0 5px rgba(68, 146, 212, 0.15); outline: none; }
        .map-container { border: 2px solid #e2e8f0; border-radius: 20px; overflow: hidden; position: relative; height: 350px; box-shadow: inset 0 4px 10px rgba(0,0,0,0.05); }
        .map-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(248, 250, 252, 0.85); backdrop-filter: blur(8px); z-index: 1000; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--nwc-navy); transition: 0.4s; }
        
        .btn-brand { background: linear-gradient(135deg, var(--nwc-navy), var(--nwc-blue)); color: white; border: none; border-radius: 16px; padding: 18px; font-weight: 900; font-size: 1.1rem; width: 100%; transition: all 0.4s; box-shadow: 0 10px 25px rgba(9, 46, 84, 0.2); }
        .btn-brand:hover { transform: translateY(-4px); box-shadow: 0 15px 35px rgba(9, 46, 84, 0.3); color: white; }
        
        .table-custom th { color: var(--nwc-navy); font-weight: 800; padding: 18px 15px; border-bottom: 2px solid var(--nwc-light); }
        .table-custom td { padding: 20px 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-weight: 700; color: #1e293b; }
        
        .status-badge { padding: 8px 15px; border-radius: 12px; font-weight: 800; display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; }
        .badge-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .badge-info { background: var(--nwc-light); color: var(--nwc-blue); border: 1px solid #bae6fd; }
        .badge-primary { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .badge-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .badge-dark { background: #f8fafc; color: #334155; border: 1px solid #e2e8f0; }
        .badge-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        .property-box { background: white; border: 1px solid #e2e8f0; border-radius: 20px; padding: 25px; transition: 0.3s; margin-bottom: 20px; border-right: 5px solid var(--nwc-blue); }
        .property-box:hover { transform: translateY(-4px); box-shadow: 0 10px 25px rgba(0,0,0,0.05); }

        .notif-box { background: #f8fafc; border-right: 4px solid var(--nwc-blue); border-radius: 12px; padding: 20px; margin-bottom: 15px; font-weight: 700; }
    </style>
</head>
<body>

    <div class="bg-animation" id="bg-particles"></div>

    <nav class="navbar navbar-luxury fade-in-up">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="#" class="d-flex align-items-center gap-3 text-decoration-none">
                <div class="brand-icon">
                    <svg viewBox="0 0 60 68" width="30" height="32" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="30" cy="6" r="2.1" fill="#bae6fd"/>
                        <circle cx="26.3" cy="13" r="2.3" fill="#bae6fd"/>
                        <circle cx="33.7" cy="13" r="2.3" fill="#93c5fd"/>
                        <circle cx="22.6" cy="20" r="2.6" fill="#93c5fd"/>
                        <circle cx="30" cy="20" r="2.6" fill="#7dd3fc"/>
                        <circle cx="37.4" cy="20" r="2.6" fill="#60a5fa"/>
                    </svg>
                </div>
                <div>
                    <div class="fw-black fs-4 text-dark m-0">قــطــرة</div>
                    <div class="text-muted small fw-bold">بوابة الخدمات الموحدة للمستفيدين</div>
                </div>
            </a>
            <div class="d-flex align-items-center gap-3">
                <div class="user-profile-badge" data-bs-toggle="modal" data-bs-target="#profileModal" title="عرض ملفي">
                    <span class="ms-2"><?= htmlspecialchars($customer['full_name']); ?></span>
                    <div class="user-avatar"><i class="fa-solid fa-user-tie"></i></div>
                </div>
                <a href="?logout=1" class="btn btn-light text-danger rounded-circle shadow-sm border btn-logout" title="تسجيل الخروج"><i class="fa-solid fa-power-off"></i></a>
            </div>
        </div>
    </nav>

    <div class="container pb-5 mt-4">
        <div class="row mb-4 fade-in-up delay-1">
            <div class="col-md-8 text-white">
                <h1 class="fw-black">أهلاً بك، <span class="text-info"><?= htmlspecialchars($customer['full_name']); ?></span></h1>
                <p class="fs-5 text-light opacity-75 m-0">شاشة التحكم الموحدة لمطابقة الصكوك، تتبع الفحوصات الميدانية، دفع الفواتير وتفعيل العدادات الذكية.</p>
            </div>
            <div class="col-md-4">
                <div class="row g-2">
                    <div class="col-6">
                        <div class="bg-white bg-opacity-10 border rounded-3 p-3 text-center text-white">
                            <h2 class="fw-black m-0"><?= $stats['total']; ?></h2>
                            <small class="fw-bold">إجمالي طلباتك</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-white bg-opacity-10 border rounded-3 p-3 text-center text-white">
                            <h2 class="fw-black m-0 text-warning"><?= count($myProperties); ?></h2>
                            <small class="fw-bold">الحسابات المفعّلة</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="custom-tabs fade-in-up delay-2">
            <button class="tab-btn active" onclick="switchTab('tab-submit', this)"><i class="fa-solid fa-file-signature me-1"></i> تقديم طلب جديد</button>
            <button class="tab-btn" onclick="switchTab('tab-history', this)"><i class="fa-solid fa-clock-rotate-left me-1"></i> سجل الطلبات والفواتير</button>
            <button class="tab-btn" onclick="switchTab('tab-properties', this)"><i class="fa-solid fa-hotel me-1"></i> العدادات والحسابات المفعّلة</button>
            <button class="tab-btn" onclick="switchTab('tab-notifs', this)"><i class="fa-solid fa-bell me-1"></i> مركز الإشعارات</button>
        </div>

        <div class="premium-card fade-in-up delay-3">
            
            <!-- التبويب 1: تقديم طلب جديد -->
            <div id="tab-submit" class="tab-panel active">
                <div class="card-header-title"><i class="fa-solid fa-file-circle-plus"></i> تسجيل عقار جديد وطلب تفعيل خدمة</div>
                <form id="applicationForm">
                    <input type="hidden" name="submit_new_app" value="1">
                    <input type="hidden" name="latitude" id="latitude" value="">
                    <input type="hidden" name="longitude" id="longitude" value="">

                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="mb-4">
                                <label class="form-label fw-bold">الخدمة المطلوبة <span class="text-danger">*</span></label>
                                <select name="srv_id" class="form-select" required>
                                    <option value="" selected disabled>-- اختر نوع الخدمة المطلوبة --</option>
                                    <?php foreach ($services as $srv): ?>
                                        <option value="<?= $srv['srv_id']; ?>"><?= htmlspecialchars(cleanServiceName($srv['srv_name'])); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-bold">المدينة <span class="text-danger">*</span></label>
                                <select name="cty_id" id="citySelect" class="form-select" required onchange="unlockMap()">
                                    <option value="" selected disabled>-- اختر المدينة لتفعيل تحديد الموقع --</option>
                                    <?php $currentRegion = '';
                                    foreach ($citiesWithRegions as $city):
                                        $cleanCtyName = str_replace('مدينة ', '', $city['cty_name']);
                                        if ($currentRegion != $city['reg_name']) {
                                            if ($currentRegion != '') echo '</optgroup>';
                                            $currentRegion = $city['reg_name'];
                                            echo '<optgroup label="منطقة ' . htmlspecialchars($currentRegion) . '">';
                                        } ?>
                                        <option value="<?= $city['cty_id']; ?>" data-city="<?= htmlspecialchars($cleanCtyName); ?>"><?= htmlspecialchars($cleanCtyName); ?></option>
                                    <?php endforeach; if ($currentRegion != '') echo '</optgroup>'; ?>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-bold">رقم صك الملكية الإلكتروني (12 رقماً) <span class="text-danger">*</span></label>
                                <input type="text" name="deed_no" id="deedInput" class="form-control" placeholder="أدخل رقم صكك المكون من 12 رقماً" required minlength="12" maxlength="12" pattern="\d{12}" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                <small class="text-muted d-block mt-1">مثال مطابق لسجلات العدل: 711029485736</small>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-bold">إرفاق نسخة الصك (PDF, JPG, PNG) <span class="text-danger">*</span></label>
                                <input type="file" name="deed_file" class="form-control" accept=".pdf, .jpg, .jpeg, .png" required>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">تحديد الإحداثيات الجغرافية على الخريطة <span class="text-danger">*</span></label>
                            <div class="map-container" id="mapContainer">
                                <div class="map-overlay" id="mapLock">
                                    <i class="fa-solid fa-map-location-dot fs-1 mb-3 text-muted"></i>
                                    <span class="fw-bold">الرجاء اختيار المدينة أولاً لتفعيل الخريطة</span>
                                </div>
                                <div id="propertyMap" style="height: 100%; width: 100%;"></div>
                            </div>
                            <small class="text-success fw-bold d-block mt-2" id="gpsStatus"><i class="fa-solid fa-circle-info"></i> انقر على الخريطة لتثبيت مؤشر الـ GPS بدقة.</small>
                        </div>
                    </div>
                    <button type="submit" class="btn-brand mt-4" id="submitBtn"><i class="fa-solid fa-paper-plane me-1"></i> إرسال الطلب ومطابقة الصك آلياً</button>
                </form>
            </div>

            <!-- التبويب 2: سجل الطلبات والفواتير المدمج -->
            <div id="tab-history" class="tab-panel">
                <div class="card-header-title"><i class="fa-solid fa-clock-rotate-left"></i> السجل الشامل لطلباتك وحالة الدفع</div>
                <?php if (empty($myApplications)): ?>
                    <div class="text-center py-5">
                        <i class="fa-regular fa-folder-open fs-1 text-muted mb-3 d-block"></i>
                        <h4 class="fw-bold">لا يوجد أي طلبات سابقة مسجلة باسمك</h4>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>نوع الخدمة</th>
                                    <th>رقم الصك</th>
                                    <th>الحالة التشغيلية</th>
                                    <th>الفاتورة والسداد</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myApplications as $app): ?>
                                    <tr>
                                        <td><span class="badge bg-light text-secondary border">#<?= str_pad($app['app_id'], 5, '0', STR_PAD_LEFT); ?></span></td>
                                        <td><div class="fw-bold"><?= htmlspecialchars(cleanServiceName($app['srv_name'])); ?></div><div class="small text-muted"><i class="fa-solid fa-location-dot text-danger"></i> <?= htmlspecialchars(str_replace('مدينة ', '', $app['cty_name'])); ?></div></td>
                                        <td class="font-monospace text-muted"><?= htmlspecialchars($app['deed_no']); ?></td>
                                        <td><?= getStatusBadge($app['app_status']); ?></td>
                                        <td>
                                            <?php if ($app['payment_status'] == 'Unpaid'): ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge bg-danger rounded-pill px-3 py-2 fw-bold">غير مدفوعة: <?= number_format($app['amount']); ?> ر.س</span>
                                                    <button class="btn btn-sm btn-success fw-bold px-3 rounded-pill" onclick="simulatePayment(<?= $app['app_id']; ?>, <?= $app['amount']; ?>)"><i class="fa-solid fa-credit-card"></i> سداد سريع</button>
                                                </div>
                                            <?php elseif ($app['payment_status'] == 'Paid'): ?>
                                                <span class="badge bg-success rounded-pill px-3 py-2"><i class="fa-solid fa-circle-check"></i> مدفوعة بنجاح</span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- التبويب 3: العدادات والحسابات المفعّلة -->
            <div id="tab-properties" class="tab-panel">
                <div class="card-header-title"><i class="fa-solid fa-building-circle-check"></i> سجل الحسابات والعدادات الذكية المفعّلة</div>
                <?php if (empty($myProperties)): ?>
                    <div class="text-center py-5">
                        <i class="fa-solid fa-satellite-dish fs-1 text-muted mb-3 d-block"></i>
                        <h5 class="fw-bold">لا توجد خدمات نشطة أو عدادات مركبة حتى الآن.</h5>
                        <p class="text-muted small">بمجرد سداد الفاتورة وإتمام فني التركيبات لعملية التركيب، ستظهر عقاراتك وعداداتك هنا آلياً.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($myProperties as $prop): ?>
                            <div class="col-md-6">
                                <div class="property-box bg-white">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="fw-black text-dark m-0"><i class="fa-solid fa-hotel text-primary me-2"></i> حساب موحد #ACC-<?= str_pad($prop['acc_id'], 5, '0', STR_PAD_LEFT); ?></h5>
                                        <span class="badge bg-success"><i class="fa-solid fa-check"></i> نشط</span>
                                    </div>
                                    <div class="small text-muted mb-3">تاريخ تفعيل الحساب: <?= $prop['creation_date']; ?></div>
                                    <div class="border-top pt-3 mb-3">
                                        <p class="mb-2"><strong>رقم الصك الموثق:</strong> <span class="font-monospace text-muted"><?= htmlspecialchars($prop['deed_no']); ?></span></p>
                                        <p class="mb-2"><strong>المساحة الإجمالية:</strong> <span class="fw-bold text-dark"><?= htmlspecialchars($prop['land_area']); ?> م²</span></p>
                                        <p class="mb-2"><strong>المالك المسجل لوزارة العدل:</strong> <span class="fw-bold text-dark"><?= htmlspecialchars($prop['owner_name']); ?></span></p>
                                    </div>

                                    <div class="border-top pt-3">
                                        <h6 class="fw-bold text-secondary mb-3"><i class="fa-solid fa-droplet text-primary"></i> الخدمات المفعلة والعدادات المرتبطة:</h6>
                                        <?php if (!empty($prop['services'])): ?>
                                            <?php foreach ($prop['services'] as $service): 
                                                $srvClean = cleanServiceName($service['srv_name']);
                                                // البحث عن عداد مرتبط بهذه الخدمة المحددة
                                                $linkedMeter = null;
                                                foreach ($prop['meters'] as $meter) {
                                                    if ($meter['srv_id'] == $service['srv_id']) {
                                                        $linkedMeter = $meter;
                                                        break;
                                                    }
                                                }
                                            ?>
                                                <div class="p-3 bg-light rounded-3 border mb-2">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <span class="badge bg-primary px-3 py-2 fw-bold"><i class="fa-solid fa-circle-nodes"></i> الخدمة: <?= htmlspecialchars($srvClean); ?></span>
                                                        <?php if ($linkedMeter): ?>
                                                            <span class="badge bg-success"><i class="fa-solid fa-circle-check"></i> تم التركيب</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning text-dark"><i class="fa-solid fa-spinner fa-spin"></i> قيد الجدولة</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <?php if ($linkedMeter): ?>
                                                        <div class="row text-center mt-2 pt-2 border-top">
                                                            <div class="col-6 border-end">
                                                                <div class="small text-muted">الرقم التسلسلي للعداد</div>
                                                                <div class="fw-bold font-monospace text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($linkedMeter['mtr_serial']); ?></div>
                                                            </div>
                                                            <div class="col-6">
                                                                <div class="small text-muted">موديل العداد</div>
                                                                <div class="fw-bold text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($linkedMeter['mtr_type']); ?></div>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="small text-muted text-center pt-1"><i class="fa-solid fa-helmet-safety text-warning"></i> جاري إسناد فني التركيبات لربط العداد وتفعيل الخدمة ميدانياً.</div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="text-muted small">لا توجد خدمات نشطة مدرجة تحت هذا الحساب.</div>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- التبويب 4: مركز الإشعارات -->
            <div id="tab-notifs" class="tab-panel">
                <div class="card-header-title"><i class="fa-solid fa-bell"></i> مركز الإشعارات الأمنية والتشغيلية</div>
                <?php if (empty($myNotifications)): ?>
                    <div class="text-center py-5">
                        <i class="fa-regular fa-bell-slash fs-1 text-muted mb-3 d-block"></i>
                        <h5 class="fw-bold">صندوق الإشعارات فارغ تماماً حالياً</h5>
                    </div>
                <?php else: ?>
                    <?php foreach ($myNotifications as $notif): ?>
                        <div class="notif-box d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-dark fw-bold mb-1"><?= htmlspecialchars($notif['message_content']); ?></div>
                                <div class="small text-muted"><i class="fa-regular fa-clock"></i> <?= $notif['created_at']; ?></div>
                            </div>
                            <span class="badge bg-light text-primary border"><i class="fa-solid fa-envelope-open"></i> مقروء</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- نافذة الملف الشخصي (Profile Modal) -->
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 24px; border: none; box-shadow: 0 25px 50px rgba(0,0,0,0.15);">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close ms-auto mt-2 me-2" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center px-4 pb-5 pt-0">
                    <div class="user-avatar mx-auto mb-3" style="width: 90px; height: 90px; font-size: 3rem; background: linear-gradient(135deg, var(--nwc-navy), var(--nwc-blue)); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 25px rgba(9,46,84,0.3);">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <h3 class="fw-black text-dark mb-1"><?= htmlspecialchars($customer['full_name']); ?></h3>
                    <p class="text-muted fw-bold mb-4"><i class="fa-solid fa-circle-check text-success ms-1"></i> عميل موثّق لدى قطرة</p>
                    
                    <div class="bg-light p-3" style="border-radius: 16px; border: 1px solid #e2e8f0; text-align: right;">
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                            <span class="text-muted fw-bold"><i class="fa-regular fa-id-card me-2"></i> رقم الهوية</span>
                            <span class="fw-black text-dark monospace" style="font-size: 1.1rem;"><?= htmlspecialchars($nationalId); ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                            <span class="text-muted fw-bold"><i class="fa-solid fa-phone me-2"></i> رقم الجوال</span>
                            <span class="fw-black text-primary" style="direction: ltr;"><?= htmlspecialchars($customer['phone_number'] ?? 'غير مسجل'); ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted fw-bold"><i class="fa-solid fa-hashtag me-2"></i> رقم المشترك الموحد</span>
                            <span class="fw-bold badge bg-secondary">CUST-<?= str_pad($custId, 4, '0', STR_PAD_LEFT); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // تفعيل الفقاعات المائية المتحركة
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

        function switchTab(panelId, btnElement) {
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(panelId).classList.add('active');
            btnElement.classList.add('active');
        }

        let map = L.map('propertyMap').setView([24.7136, 46.6753], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© Qatra Smart Systems' }).addTo(map);
        let marker;
        const cityCoords = {
            'الرياض': [24.7136, 46.6753],
            'بريدة': [26.3260, 43.9390],
            'عنيزة': [26.0855, 43.9781],
            'الرس': [25.8679, 43.4975],
            'المذنب': [25.8712, 44.2185],
            'البكيرية': [26.1558, 43.6592],
            'حائل': [27.5114, 41.7208],
            'عرعر': [30.9753, 41.0381],
            'رفحاء': [29.1235, 43.4912],
            'طريف': [31.6725, 38.6631],
            'سكاكا': [29.9697, 40.2064],
            'القريات': [31.3314, 37.3422],
            'دومة الجندل': [29.8115, 39.8718],
            'طبرجل': [30.4915, 38.2115],
            'جدة': [21.4858, 39.1925],
            'مكة': [21.3891, 39.8579],
            'الدمام': [26.4207, 50.0888]
        };

        function unlockMap() {
            let select = document.getElementById('citySelect');
            let cityName = select.options[select.selectedIndex].getAttribute('data-city');
            document.getElementById('mapLock').style.opacity = '0';
            setTimeout(() => { document.getElementById('mapLock').style.display = 'none'; }, 400);
            
            document.getElementById('latitude').value = "";
            document.getElementById('longitude').value = "";
            document.getElementById('gpsStatus').innerHTML = `<span class="text-danger fw-bold"><i class="fa-solid fa-map-pin"></i> يرجى النقر على الخريطة لتحديد موقع عقارك بدقة</span>`;
            
            if (marker) {
                map.removeLayer(marker);
                marker = null;
            }

            let coords = [24.7136, 46.6753];
            for (let key in cityCoords) {
                if (cityName && cityName.includes(key)) {
                    coords = cityCoords[key];
                    break;
                }
            }
            setTimeout(() => { map.invalidateSize(); map.flyTo(coords, 14, { animate: true, duration: 1.5 }); }, 300);
        }

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
            document.getElementById('gpsStatus').innerHTML = `<span class="text-success fw-bold"><i class="fa-solid fa-circle-check"></i> تم التحديد بدقة: ${lat.toFixed(4)}, ${lng.toFixed(4)}</span>`;
        });

        document.getElementById('applicationForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (document.getElementById('latitude').value === "") {
                Swal.fire({ icon: 'warning', title: 'تحديد الموقع مهم!', text: 'يرجى تحديد موقع عقارك على الخريطة أولاً.', confirmButtonColor: '#092e54' });
                return;
            }

            let submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            Swal.fire({
                title: 'جاري مطابقة الصك آلياً...',
                text: 'يقوم محرك (DSS) بمطابقة البيانات مع سجلات وزارة العدل لتسريع الإجراءات وتجنب الأخطاء البصرية.',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            fetch('dashboard.php', { method: 'POST', body: new FormData(this) })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'error') {
                    Swal.fire({ icon: 'error', title: 'فشل التحقق', text: data.message, confirmButtonColor: '#092e54' })
                    .then(() => { submitBtn.disabled = false; });
                } else {
                    Swal.fire({ icon: 'success', title: 'تم استلام طلبك', text: data.message, confirmButtonColor: '#10b981' })
                    .then(() => { window.location.reload(); });
                }
            })
            .catch(error => {
                submitBtn.disabled = false;
                Swal.fire({ icon: 'error', title: 'خطأ تقني', text: 'فشل الاتصال بالخادم، يرجى المحاولة لاحقاً.' });
            });
        });

        function simulatePayment(appId, amount) {
            Swal.fire({
                title: 'بوابة الدفع الإلكتروني الموحدة',
                html: `<div style="text-align: right; font-weight: 700;">
                        <p class="mb-2 text-muted">طلب الخدمة رقم: <span class="text-dark">#${appId}</span></p>
                        <p class="mb-3 text-muted">قيمة الفاتورة المستحقة للربط والتركيب:</p>
                        <h4 class="text-success fw-black text-center mb-4">${amount.toLocaleString()} ريال سعودي</h4>
                        <div class="p-3 bg-light rounded-3 text-center small text-secondary border">
                            <i class="fa-solid fa-shield-halved text-success mb-2 fs-4 d-block"></i>
                            عملية السداد محمية وموثقة وآمنة 100% بنظام قطرة
                        </div>
                       </div>`,
                showCancelButton: true,
                confirmButtonText: 'تأكيد ودفع الفاتورة',
                cancelButtonText: 'إلغاء',
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6c757d',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    let fd = new FormData();
                    fd.append('pay_invoice', '1');
                    fd.append('app_id', appId);

                    return fetch('dashboard.php', { method: 'POST', body: fd })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'error') {
                            throw new Error(data.message);
                        }
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`خطأ: ${error.message}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'تم السداد بنجاح!',
                        text: result.value.message,
                        confirmButtonColor: '#10b981'
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        }
    </script>
</body>
</html>
