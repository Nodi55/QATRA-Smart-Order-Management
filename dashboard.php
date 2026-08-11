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

        // =========================================================
// بوابة DSS الأمنية: مطابقة ثنائية صارمة (الهوية الوطنية + الاسم الرباعي)
// لمنع التزوير وتحويل أي اختلاف في الاسم أو الهوية للمدقق يدوياً
// =========================================================
$dbCustomerName = trim($customer['full_name']);
$mojOwnerName = trim($mojRecord['owner_name']);

if ($mojRecord['owner_national_id'] !== $nationalId || $dbCustomerName !== $mojOwnerName) {
    // في حال اختلاف الهوية أو وجود أي اختلاف في الاسم (حتى لو حرف واحد)
    $appStatus = 'Pending_Review';
    $notifMsg = "تم تقديم طلبك بنجاح، وتحويله للمدقق لمطابقة اختلاف بيانات المالك.";
} else {
    // مطابقة كاملة 100% للهوية والاسم
    $appStatus = 'Pending_Inspection';
    $notifMsg = "تهانينا! تم التحقق من صك الملكية آلياً بنجاح عبر محرك (DSS). تم توجيه طلبك مباشرة إلى مرحلة الفحص الميداني.";
}
        // رفع ملف الصك وتشفيره لحمايته أمنياً
        $fileTmpPath = $_FILES['deed_file']['tmp_name'];
        $originalFileName = $_FILES['deed_file']['name'];
        $fileExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));

        // تحديد الامتدادات الآمنة والمسموحة فقط للرفع
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

        if (!in_array($fileExtension, $allowedExtensions)) {
            echo json_encode(['status' => 'error', 'message' => 'عفواً، صيغة الملف المرفوع غير مسموحة. الرجاء رفع ملف صك بامتداد PDF أو JPG أو PNG فقط لسلامة المنظومة.']);
            exit;
        }

        $hashedFileName = md5(time() . $custId) . '.' . $fileExtension;
        $targetDir = "uploads/";
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }
        $targetFilePath = $targetDir . $hashedFileName;

        if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
            
            // خوارزمية تقسيم الطلب: إذا تم اختيار "مياه وصرف" (3)، يتم إنشاء طلبين منفصلين: مياه (1) وصرف (2)
            $servicesToInsert = [];
            if ($srvId == 3) {
                $servicesToInsert = [1, 2]; // مياه (1) وصرف (2)
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
                $appDetailLine = "طلب {$srvNameClean} رقم (#" . str_pad($newAppId, 5, '0', STR_PAD_LEFT) . ")";

                // إدراج أول سجل في تاريخ الطلب لدعم التتبع الحي لدورة الحياة
                $pdo->prepare("INSERT INTO application_history (app_id, status, change_date) VALUES (?, ?, NOW())")->execute([$newAppId, $appStatus]);

                // التوزيع الجغرافي الذكي لفنيي الفحص لكل طلب على حدة لتقليل العبء
                if ($appStatus == 'Pending_Inspection') {
                    // تقييد البحث بنفس منطقة العميل (reg_id) فقط - لا يوجد إسناد تلقائي خارج المنطقة إطلاقاً
                    $bestTechStmt = $pdo->prepare("
                        SELECT ce.emp_id, ce.emp_name AS emp_name
                        FROM company_employee ce
                        JOIN employee_roles er ON ce.emp_id = er.emp_id 
                        JOIN system_role sr ON er.role_id = sr.role_id
                        JOIN city c ON ce.cty_id = c.cty_id
                        WHERE ce.is_active = 1 AND sr.role_name = 'Inspection Technician'
                          AND c.reg_id = (SELECT reg_id FROM city WHERE cty_id = ?)
                        ORDER BY (ce.cty_id = ?) DESC, ce.active_tasks_count ASC
                        LIMIT 1
                    ");
                    $bestTechStmt->execute([$cityId, $cityId]);
                    $bestTech = $bestTechStmt->fetch(PDO::FETCH_ASSOC);
                    $bestTechId = $bestTech['emp_id'] ?? null;

                    if ($bestTechId) {
                        $pdo->prepare("INSERT INTO field_inspection (app_id, emp_id) VALUES (?, ?)")->execute([$newAppId, $bestTechId]);
                        $pdo->prepare("UPDATE company_employee SET active_tasks_count = active_tasks_count + 1 WHERE emp_id = ?")->execute([$bestTechId]);
                        $appDetailLine .= " - تم إسناد الفحص الميداني للفني: " . $bestTech['emp_name'];

                        // إشعار فوري للفني نفسه
                        try {
                            $tNotif = "تم إسناد مهمة فحص ميداني جديدة إليك رقم #" . str_pad($newAppId, 5, '0', STR_PAD_LEFT) . ".";
                            $pdo->prepare("INSERT INTO employee_notification (emp_id, message_content, notif_type) VALUES (?, ?, 'info')")->execute([$bestTechId, $tNotif]);
                        } catch (Exception $e) {}
                    } else {
                        // لا يوجد فني ضمن نطاق منطقة العميل: يبقى الطلب بلا إسناد ليظهر في "مهام وتوجيه > الطوارئ"
                        // ولا يُذكر أي اسم فني للعميل حتى يتدخل المدير
                        $appDetailLine .= " - لا يوجد حالياً فني فحص متاح ضمن نطاق منطقتك، تم تحويل طلبك لفريق الإدارة لإسناده يدوياً.";
                    }
                }

                $appsDetails[] = $appDetailLine;
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

        $stmt = $pdo->prepare("SELECT a.app_id, a.cust_id, a.deed_no, a.srv_id, a.cty_id, i.inv_id, i.amount,
                               s.srv_name, c.cty_name
                               FROM application a 
                               JOIN invoice i ON a.app_id = i.app_id 
                               JOIN service_type s ON a.srv_id = s.srv_id
                               JOIN city c ON a.cty_id = c.cty_id
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
            // تقييد البحث بنفس منطقة العميل فقط
            $techStmt = $pdo->prepare("
                SELECT ce.emp_id, ce.emp_name AS emp_name
                FROM company_employee ce
                JOIN employee_roles er ON ce.emp_id = er.emp_id 
                JOIN system_role sr ON er.role_id = sr.role_id 
                JOIN city c ON ce.cty_id = c.cty_id
                WHERE ce.is_active = 1 AND sr.role_name = 'Installation Technician'
                  AND c.reg_id = (SELECT reg_id FROM city WHERE cty_id = ?)
                ORDER BY (ce.cty_id = ?) DESC, ce.active_tasks_count ASC
                LIMIT 1
            ");
            $techStmt->execute([$appData['cty_id'], $appData['cty_id']]);
            $tech = $techStmt->fetch(PDO::FETCH_ASSOC);
            $techId = $tech['emp_id'] ?? null;

            if ($techId) {
                $pdo->prepare("INSERT INTO installation_task (app_id, emp_id) VALUES (?, ?)")->execute([$appId, $techId]);
                $pdo->prepare("UPDATE company_employee SET active_tasks_count = active_tasks_count + 1 WHERE emp_id = ?")->execute([$techId]);
                $techLine = "وإسناد تركيب العداد للفني: " . $tech['emp_name'] . ".";

                try {
                    $tNotif = "تم إسناد مهمة تركيب عداد جديدة إليك رقم #" . str_pad($appId, 5, '0', STR_PAD_LEFT) . ".";
                    $pdo->prepare("INSERT INTO employee_notification (emp_id, message_content, notif_type) VALUES (?, ?, 'info')")->execute([$techId, $tNotif]);
                } catch (Exception $e) {}
            } else {
                // لا فني تركيب ضمن نطاق المنطقة: تُحال المهمة للإدارة، ولا يُذكر اسم فني للعميل
                $techLine = "ولا يوجد حالياً فني تركيب متاح ضمن نطاق منطقتك، تم تحويل المهمة لفريق الإدارة لإسنادها يدوياً.";
            }

            $notifMsg = "تم تأكيد سداد الفاتورة رقم #" . str_pad($appId, 5, '0', STR_PAD_LEFT) . " بقيمة " . number_format($appData['amount'], 2) . " ريال بنجاح. تم إنشاء حسابك الموحد (ACC-" . str_pad($accId, 5, '0', STR_PAD_LEFT) . ") " . $techLine;
            $pdo->prepare("INSERT INTO notification (message_content, cust_id) VALUES (?, ?)")->execute([$notifMsg, $custId]);
            $pdo->prepare("INSERT INTO application_history (app_id, status, change_date) VALUES (?, 'In_Progress', NOW())")->execute([$appId]);

            $pdo->commit();
            echo json_encode([
                'status' => 'success',
                'message' => 'تم تأكيد السداد بنجاح وتفعيل خطة التركيبات الميدانية!',
                'invoice' => [
                    'app_id' => str_pad($appId, 5, '0', STR_PAD_LEFT),
                    'inv_id' => str_pad($appData['inv_id'], 5, '0', STR_PAD_LEFT),
                    'acc_id' => str_pad($accId, 5, '0', STR_PAD_LEFT),
                    'service' => cleanServiceName($appData['srv_name']),
                    'city' => str_replace('مدينة ', '', $appData['cty_name']),
                    'deed_no' => $appData['deed_no'],
                    'amount' => number_format($appData['amount'], 2),
                    'customer_name' => $customer['full_name'],
                    'national_id' => $nationalId,
                    'tech_name' => $tech['emp_name'] ?? 'سيتم التعيين لاحقاً',
                    'paid_at' => date('Y-m-d H:i')
                ]
            ]);
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
$groupedProperties = [];
$myNotifications = [];
$stats = ['total' => 0, 'completed' => 0, 'pending_payment' => 0];

try {
    // جلب المدن والمناطق (مع حذف القرى غير الموثقة بالإحداثيات وحذف كلمة "مدينة" من الأسماء)
    $citiesWithRegions = $pdo->query("
        SELECT c.cty_id, c.cty_name, r.reg_name 
        FROM city c 
        JOIN region r ON c.reg_id = r.reg_id 
        WHERE c.cty_name NOT IN ('عيون الجواء', 'ضرية', 'بقعاء', 'الشنان', 'الحائط', 'مدينة الرياض', 'الربيعية', 'الشماسية', 'رفحاء') 
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

    $propStmt = $pdo->prepare("
        SELECT ua.acc_id, ua.deed_no, ua.creation_date, moj.land_area, moj.owner_name,
               st.srv_name as active_service, m.mtr_serial, m.mtr_type
        FROM unified_account ua
        JOIN moj_record moj ON ua.deed_no = moj.deed_no
        LEFT JOIN activated_service acs ON ua.acc_id = acs.acc_id
        LEFT JOIN service_type st ON acs.srv_id = st.srv_id
        LEFT JOIN meter m ON ua.acc_id = m.acc_id
        WHERE ua.cust_id = ? ORDER BY ua.creation_date DESC
    ");
    $propStmt->execute([$custId]);
    $myProperties = $propStmt->fetchAll(PDO::FETCH_ASSOC);

    // تجميع الحسابات الموحدة في بطاقات مجمعة ذكية خالية من التكرار
    foreach ($myProperties as $row) {
        $accId = $row['acc_id'];
        if (!isset($groupedProperties[$accId])) {
            $groupedProperties[$accId] = [
                'acc_id' => $row['acc_id'],
                'deed_no' => $row['deed_no'],
                'creation_date' => $row['creation_date'],
                'land_area' => $row['land_area'],
                'owner_name' => $row['owner_name'],
                'services' => [],
                'meters' => []
            ];
        }
        if ($row['active_service']) {
            $groupedProperties[$accId]['services'][$row['active_service']] = cleanServiceName($row['active_service']);
        }
        if ($row['mtr_serial']) {
            $groupedProperties[$accId]['meters'][$row['mtr_serial']] = [
                'serial' => $row['mtr_serial'],
                'type' => $row['mtr_type'],
                'service' => cleanServiceName($row['active_service'] ?? 'مياه')
            ];
        }
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

// تحديد رقم مرحلة الطلب الحالية ضمن دورة حياته الخمس (لعرض شريط المراحل والتحقق مما اكتمل منها)
// المراحل: 0 تقديم ومطابقة الصك - 1 الفحص الميداني - 2 إصدار وسداد الفاتورة - 3 التركيب الميداني - 4 الاكتمال والتفعيل
function getAppStageIndex($status) {
    $map = [
        'Pending_Review'     => 0,
        'Pending_Inspection' => 1,
        'Pending_Billing'    => 2,
        'In_Progress'        => 3,
        'Completed'          => 4
    ];
    if ($status === 'Rejected') return -1; // حالة خاصة: الطلب مرفوض ولا يكمل بقية المراحل
    return $map[$status] ?? 0;
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

        .notif-bell-wrap { position: relative; }
        .notif-bell-badge { position: absolute; top: -6px; left: -6px; background: #ef4444; color: white; font-size: 0.68rem; font-weight: 800; min-width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; padding: 0 4px; box-shadow: 0 0 0 3px white; animation: bellPop 0.4s ease; }
        @keyframes bellPop { from { transform: scale(0); } to { transform: scale(1); } }
        .btn-bell.ringing { animation: bellRing 0.6s ease-in-out 2; }
        @keyframes bellRing { 0%,100% { transform: rotate(0); } 20% { transform: rotate(15deg); } 40% { transform: rotate(-15deg); } 60% { transform: rotate(8deg); } 80% { transform: rotate(-8deg); } }
        
        .premium-card { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); border-radius: 24px; padding: 40px; box-shadow: var(--card-shadow); border: 1px solid rgba(255,255,255,0.2); position: relative; }
        .premium-card::before { content: ''; position: absolute; top: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, var(--nwc-blue), transparent); border-radius: 0 0 10px 10px; }
        
        .card-header-title { color: var(--nwc-navy); font-weight: 900; font-size: 1.4rem; margin-bottom: 30px; display: flex; align-items: center; gap: 12px; border-bottom: 2px solid var(--nwc-light); padding-bottom: 15px; }
        .card-header-title i { background: var(--nwc-light); color: var(--nwc-blue); width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.2rem; }

        /* ============== شريط التتبع الحي (Live Tracker) ============== */
        .live-tracker-card { background: rgba(255,255,255,0.97); backdrop-filter: blur(20px); border-radius: 24px; padding: 28px 32px; box-shadow: var(--card-shadow); border: 1px solid rgba(255,255,255,0.2); position: relative; overflow: hidden; margin-bottom: 20px; }
        .live-tracker-card::before { content: ''; position: absolute; top: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, #10b981, transparent); }
        .live-pulse-dot { width: 10px; height: 10px; border-radius: 50%; background: #10b981; display: inline-block; position: relative; }
        .live-pulse-dot::after { content: ''; position: absolute; inset: 0; border-radius: 50%; background: #10b981; animation: pulseRing 1.6s infinite; }
        @keyframes pulseRing { 0% { transform: scale(1); opacity: 0.7; } 100% { transform: scale(3.2); opacity: 0; } }
        .lt-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; flex-wrap: wrap; gap: 10px; }
        .lt-title { font-weight: 900; color: var(--nwc-navy); display: flex; align-items: center; gap: 10px; font-size: 1.15rem; }
        .lt-badge-live { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; font-weight: 800; padding: 5px 14px; border-radius: 50px; font-size: 0.8rem; display: flex; align-items: center; gap: 8px; }
        .lt-progress-wrap { position: relative; padding: 0 10px; }
        .lt-progress-track { position: relative; height: 6px; background: #e2e8f0; border-radius: 10px; margin: 0 5px; }
        .lt-progress-fill { position: absolute; top: 0; right: 0; height: 100%; border-radius: 10px; background: linear-gradient(90deg, var(--nwc-blue), #10b981); transition: width 1s cubic-bezier(0.4,0,0.2,1); }
        .lt-progress-fill::after { content: ''; position: absolute; left: -8px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 5px rgba(16,185,129,0.2), 0 4px 10px rgba(0,0,0,0.2); animation: dropBounce 1.4s ease-in-out infinite; }
        @keyframes dropBounce { 0%,100% { transform: translateY(-50%) scale(1); } 50% { transform: translateY(-65%) scale(1.15); } }
        .lt-steps { display: flex; justify-content: space-between; margin-top: 14px; }
        .lt-step { text-align: center; flex: 1; }
        .lt-step-icon { width: 38px; height: 38px; border-radius: 50%; background: #f1f5f9; color: #94a3b8; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-size: 0.95rem; border: 2px solid #e2e8f0; transition: all 0.4s; }
        .lt-step.done .lt-step-icon { background: #10b981; color: white; border-color: #10b981; }
        .lt-step.current .lt-step-icon { background: var(--nwc-blue); color: white; border-color: var(--nwc-blue); box-shadow: 0 0 0 6px rgba(68,146,212,0.18); animation: currentGlow 1.5s infinite; }
        @keyframes currentGlow { 0%,100% { box-shadow: 0 0 0 6px rgba(68,146,212,0.18); } 50% { box-shadow: 0 0 0 10px rgba(68,146,212,0.05); } }
        .lt-step-label { font-size: 0.75rem; font-weight: 800; color: #94a3b8; }
        .lt-step.done .lt-step-label, .lt-step.current .lt-step-label { color: var(--nwc-navy); }
        .lt-msg { text-align: center; margin-top: 18px; font-weight: 800; color: var(--nwc-blue); background: var(--nwc-light); border-radius: 14px; padding: 12px; }
        
        .custom-tabs { display: flex; gap: 15px; border-bottom: 2px solid rgba(255, 255, 255, 0.1); padding-bottom: 10px; margin-bottom: 30px; overflow-x: auto; }
        .tab-btn { background: rgba(255, 255, 255, 0.05); color: #cbd5e1; border: 1px solid rgba(255, 255, 255, 0.1); padding: 12px 25px; border-radius: 50px; font-weight: 700; transition: 0.3s; white-space: nowrap; position: relative; }
        .tab-btn:hover, .tab-btn.active { background: white; color: var(--nwc-navy); border-color: white; transform: translateY(-2px); }
        
        .tab-panel { display: none; }
        .tab-panel.active { display: block; animation: panelFade 0.4s ease-in-out; }
        @keyframes panelFade { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .form-control, .form-select { border-radius: 16px; border: 2px solid #e2e8f0; padding: 16px 20px; font-weight: 700; color: #1e293b; background: #f8fafc; transition: all 0.3s; }
        .form-control:focus, .form-select:focus { border-color: var(--nwc-blue); background: white; box-shadow: 0 0 0 5px rgba(68, 146, 212, 0.15); outline: none; }
        .map-container { border: 2px solid #e2e8f0; border-radius: 20px; overflow: hidden; position: relative; height: 350px; box-shadow: inset 0 4px 10px rgba(0,0,0,0.05); }
        .map-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(248, 250, 252, 0.85); backdrop-filter: blur(8px); z-index: 1000; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--nwc-navy); transition: 0.4s; }
        .btn-locate-me { background: white; color: var(--nwc-navy); border: 2px solid #e2e8f0; border-radius: 50px; padding: 10px 20px; font-weight: 800; font-size: 0.9rem; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-locate-me:hover:not(:disabled) { background: var(--nwc-light); border-color: var(--nwc-blue); color: var(--nwc-blue); transform: translateY(-2px); }
        .btn-locate-me:disabled { opacity: 0.6; cursor: not-allowed; }
        .locate-me-wrap { position: absolute; top: 12px; left: 12px; z-index: 999; }
        
        .btn-brand { background: linear-gradient(135deg, var(--nwc-navy), var(--nwc-blue)); color: white; border: none; border-radius: 16px; padding: 18px; font-weight: 900; font-size: 1.1rem; width: 100%; transition: all 0.4s; box-shadow: 0 10px 25px rgba(9, 46, 84, 0.2); }
        .btn-brand:hover { transform: translateY(-4px); box-shadow: 0 15px 35px rgba(9, 46, 84, 0.3); color: white; }
        
        .table-custom th { color: var(--nwc-navy); font-weight: 800; padding: 18px 15px; border-bottom: 2px solid var(--nwc-light); }
        .table-custom td { padding: 20px 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-weight: 700; color: #1e293b; }
        
        .status-badge { padding: 8px 15px; border-radius: 12px; font-weight: 800; display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; transition: all 0.4s; }
        .status-badge.just-updated { animation: statusFlash 1.4s ease; }
        @keyframes statusFlash { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.6); } 70% { box-shadow: 0 0 0 12px rgba(16,185,129,0); } 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } }
        .badge-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .badge-info { background: var(--nwc-light); color: var(--nwc-blue); border: 1px solid #bae6fd; }
        .badge-primary { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .badge-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .badge-dark { background: #f8fafc; color: #334155; border: 1px solid #e2e8f0; }
        .badge-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        .property-box { background: white; border: 1px solid #e2e8f0; border-radius: 20px; padding: 25px; transition: 0.3s; margin-bottom: 20px; border-right: 5px solid var(--nwc-blue); }
        .property-box:hover { transform: translateY(-4px); box-shadow: 0 10px 25px rgba(0,0,0,0.05); }

        .notif-box { background: #f8fafc; border-right: 4px solid var(--nwc-blue); border-radius: 12px; padding: 20px; margin-bottom: 15px; font-weight: 700; }
        .notif-box.notif-new { animation: notifSlideIn 0.6s cubic-bezier(0.2,0.8,0.2,1); border-right-color: #10b981; background: #f0fdf9; }
        @keyframes notifSlideIn { from { opacity: 0; transform: translateY(-15px); } to { opacity: 1; transform: translateY(0); } }

        .stage-timeline { list-style: none; padding: 0; margin: 0; }
        .stage-item { position: relative; padding-right: 50px; padding-bottom: 30px; }
        .stage-item:last-child { padding-bottom: 0; }
        .stage-item::before { content: ''; position: absolute; right: 15px; top: 34px; bottom: 0; width: 3px; background: #e2e8f0; }
        .stage-item:last-child::before { display: none; }
        .stage-icon { position: absolute; right: 0; top: 0; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; background: #e2e8f0; color: #94a3b8; font-weight: 800; z-index: 2; }
        .stage-item.done .stage-icon { background: #059669; color: white; }
        .stage-item.done::before { background: #059669; }
        .stage-item.current .stage-icon { background: var(--nwc-blue); color: white; box-shadow: 0 0 0 5px rgba(68,146,212,0.2); }
        .stage-item.rejected .stage-icon { background: #dc2626; color: white; }
        .stage-title { font-weight: 800; color: #1e293b; }
        .stage-item.current .stage-title { color: var(--nwc-blue); }
        .stage-item:not(.done):not(.current) .stage-title { color: #94a3b8; }
        .stage-desc { font-size: 0.85rem; color: #64748b; font-weight: 600; margin-top: 2px; }

        #printableInvoice { display: none; }
        @media print {
            body * { visibility: hidden; }
            #printableInvoice, #printableInvoice * { visibility: visible; }
            #printableInvoice { display: block !important; position: absolute; top: 0; right: 0; width: 100%; padding: 30px; }
            .no-print { display: none !important; }
        }
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
                <div class="notif-bell-wrap">
                    <button class="btn btn-light rounded-circle shadow-sm border btn-bell" id="bellBtn" style="width:42px;height:42px;" onclick="switchTab('tab-notifs', document.querySelectorAll('.tab-btn')[3])" title="الإشعارات">
                        <i class="fa-solid fa-bell text-secondary"></i>
                    </button>
                    <span class="notif-bell-badge d-none" id="bellBadge">0</span>
                </div>
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
                            <h2 class="fw-black m-0 text-warning"><?= count($groupedProperties); ?></h2>
                            <small class="fw-bold">العدادات المفعلة</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============== شريط التتبع الحي لأقرب طلب نشط (Live Tracker) ============== -->
        <div class="live-tracker-card fade-in-up delay-2 d-none" id="liveTrackerCard">
            <div class="lt-header">
                <div class="lt-title"><i class="fa-solid fa-satellite-dish text-primary"></i> متابعة طلبك المباشرة <span id="lt-app-id" class="text-muted fw-bold"></span></div>
                <div class="lt-badge-live"><span class="live-pulse-dot"></span> تحديث حي</div>
            </div>
            <div class="lt-progress-wrap">
                <div class="lt-progress-track"><div class="lt-progress-fill" id="ltProgressFill" style="width:10%"></div></div>
                <div class="lt-steps" id="ltSteps"></div>
            </div>
            <div class="lt-msg" id="ltMsg"><i class="fa-solid fa-circle-notch fa-spin"></i> جاري تحميل حالة طلبك...</div>
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
                                <div class="locate-me-wrap">
                                    <button type="button" class="btn-locate-me" id="locateMeBtn" onclick="locateMe()">
                                        <i class="fa-solid fa-location-crosshairs"></i> <span id="locateMeBtnText">حدد موقعي الحالي</span>
                                    </button>
                                </div>
                                <div id="propertyMap" style="height: 100%; width: 100%;"></div>
                            </div>
                            <small class="text-success fw-bold d-block mt-2" id="gpsStatus"><i class="fa-solid fa-circle-info"></i> انقر على الخريطة أو اضغط "حدد موقعي الحالي" لتثبيت مؤشر الـ GPS بدقة.</small>
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
                        <table class="table table-custom" id="appsTable">
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
                                    <tr data-app-id="<?= (int)$app['app_id']; ?>">
                                        <td><span class="badge bg-light text-secondary border">#<?= str_pad($app['app_id'], 5, '0', STR_PAD_LEFT); ?></span></td>
                                        <td><div class="fw-bold"><?= htmlspecialchars(cleanServiceName($app['srv_name'])); ?></div><div class="small text-muted"><i class="fa-solid fa-location-dot text-danger"></i> <?= htmlspecialchars(str_replace('مدينة ', '', $app['cty_name'])); ?></div></td>
                                        <td class="font-monospace text-muted"><?= htmlspecialchars($app['deed_no']); ?></td>
                                        <td>
                                            <span class="status-cell"><?= getStatusBadge($app['app_status']); ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-circle mt-1 stage-btn" title="عرض مراحل الطلب"
                                                data-app-id="<?= (int)$app['app_id']; ?>" data-service="<?= htmlspecialchars(cleanServiceName($app['srv_name']), ENT_QUOTES); ?>"
                                                onclick="openStagesModal(<?= (int)$app['app_id']; ?>, <?= getAppStageIndex($app['app_status']); ?>, '<?= htmlspecialchars(cleanServiceName($app['srv_name']), ENT_QUOTES); ?>')">
                                                <i class="fa-solid fa-timeline"></i>
                                            </button>
                                        </td>
                                        <td class="payment-cell">
                                            <?php if ($app['payment_status'] == 'Unpaid'): ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge bg-danger rounded-pill px-3 py-2 fw-bold">غير مدفوعة: <?= number_format($app['amount']); ?> ر.س</span>
                                                    <button class="btn btn-sm btn-success fw-bold px-3 rounded-pill"
                                                        onclick="openPaymentModal(<?= (int)$app['app_id']; ?>, <?= (float)$app['amount']; ?>, '<?= htmlspecialchars(cleanServiceName($app['srv_name']), ENT_QUOTES); ?>', '<?= htmlspecialchars(str_replace('مدينة ', '', $app['cty_name']), ENT_QUOTES); ?>', '<?= htmlspecialchars($app['deed_no'], ENT_QUOTES); ?>')">
                                                        <i class="fa-solid fa-credit-card"></i> سداد سريع
                                                    </button>
                                                </div>
                                            <?php elseif ($app['payment_status'] == 'Paid'): ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge bg-success rounded-pill px-3 py-2"><i class="fa-solid fa-circle-check"></i> مدفوعة بنجاح</span>
                                                    <a href="invoice_print.php?app_id=<?= (int)$app['app_id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary fw-bold px-3 rounded-pill"><i class="fa-solid fa-print"></i> طباعة</a>
                                                </div>
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
                <div class="card-header-title"><i class="fa-solid fa-building-circle-check"></i> سجل الحسابات والعدادات المفعّلة</div>
                <?php if (empty($groupedProperties)): ?>
                    <div class="text-center py-5">
                        <i class="fa-solid fa-satellite-dish fs-1 text-muted mb-3 d-block"></i>
                        <h5 class="fw-bold">لا توجد خدمات نشطة أو عدادات مركبة حتى الآن.</h5>
                        <p class="text-muted small">بمجرد سداد الفاتورة وإتمام فني التركيبات لعملية التركيب، ستظهر حساباتك هنا آلياً.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($groupedProperties as $prop): ?>
                            <div class="col-md-6">
                                <div class="property-box bg-white">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="fw-black text-dark m-0"><i class="fa-solid fa-hotel text-primary me-2"></i> حساب موحد #ACC-<?= str_pad($prop['acc_id'], 5, '0', STR_PAD_LEFT); ?></h5>
                                        <span class="badge bg-success"><i class="fa-solid fa-check"></i> نشط</span>
                                    </div>
                                    <div class="small text-muted mb-3">تاريخ تفعيل الحساب: <?= $prop['creation_date']; ?></div>
                                    <div class="border-top pt-3">
                                        <p class="mb-2"><strong>رقم الصك الموثق:</strong> <span class="font-monospace text-muted"><?= htmlspecialchars($prop['deed_no']); ?></span></p>
                                        <p class="mb-2"><strong>المساحة الإجمالية:</strong> <span class="fw-bold text-dark"><?= htmlspecialchars($prop['land_area']); ?> م²</span></p>
                                        <p class="mb-2"><strong>المالك المسجل:</strong> <span class="fw-bold text-dark"><?= htmlspecialchars($prop['owner_name']); ?></span></p>
                                        <p class="mb-2"><strong>الخدمات المفعّلة:</strong> 
                                            <?php foreach ($prop['services'] as $srvName): ?>
                                                <span class="badge bg-light text-primary border"><?= htmlspecialchars($srvName); ?></span>
                                            <?php endforeach; ?>
                                        </p>
                                    </div>
                                    
                                    <div class="bg-light rounded p-3 mt-3 border">
                                        <div class="fw-bold text-secondary mb-2"><i class="fa-solid fa-microchip text-success"></i> بيانات العدادات الذكية الميدانية:</div>
                                        <?php if (empty($prop['meters'])): ?>
                                            <div class="alert alert-warning text-center fw-bold mt-2 mb-0"><i class="fa-solid fa-spinner fa-spin"></i> جاري تركيب وتفعيل العدادات الميدانية...</div>
                                        <?php else: ?>
                                            <div class="row g-2">
                                                <?php foreach ($prop['meters'] as $meter): ?>
                                                    <div class="col-12 border-bottom pb-2 mb-2" style="border-style: dashed !important;">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span class="small text-muted fw-bold">عداد الخدمة: <span class="text-primary"><?= $meter['service'] ?></span></span>
                                                            <span class="badge bg-info text-dark font-monospace"><?= htmlspecialchars($meter['serial']); ?></span>
                                                        </div>
                                                        <div class="small text-muted mt-1">موديل العداد: <?= $meter['type'] == 'Smart' ? 'ذكي' : 'ميكانيكي' ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
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
                <div id="notifsList">
                <?php if (empty($myNotifications)): ?>
                    <div class="text-center py-5" id="notifsEmptyState">
                        <i class="fa-regular fa-bell-slash fs-1 text-muted mb-3 d-block"></i>
                        <h5 class="fw-bold">صندوق الإشعارات فارغ تماماً حالياً</h5>
                    </div>
                <?php else: ?>
                    <?php foreach ($myNotifications as $notif): ?>
                        <div class="notif-box d-flex justify-content-between align-items-center" data-notif-id="<?= (int)$notif['notif_id']; ?>">
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

    <!-- نافذة سداد الفاتورة (Payment Modal) -->
    <div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 24px; border: none; box-shadow: 0 25px 50px rgba(0,0,0,0.2); overflow: hidden;">
                <div class="p-4 text-white text-center" style="background: linear-gradient(135deg, var(--nwc-navy), var(--nwc-blue));">
                    <i class="fa-solid fa-file-invoice-dollar fs-1 mb-2 d-block"></i>
                    <h4 class="fw-black m-0">تفاصيل فاتورة الربط والتركيب</h4>
                    <small class="opacity-75">راجع بيانات الفاتورة جيداً قبل تأكيد السداد</small>
                </div>
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" style="z-index: 10;"></button>

                <div class="modal-body p-4">
                    <div class="bg-light p-3 mb-3" style="border-radius: 16px; border: 1px solid #e2e8f0;">
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                            <span class="text-muted fw-bold"><i class="fa-solid fa-hashtag me-1"></i> رقم الطلب</span>
                            <span class="fw-black text-dark" id="pm-app-id">-</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                            <span class="text-muted fw-bold"><i class="fa-solid fa-droplet me-1"></i> نوع الخدمة</span>
                            <span class="fw-bold text-dark" id="pm-service">-</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                            <span class="text-muted fw-bold"><i class="fa-solid fa-location-dot me-1"></i> المدينة</span>
                            <span class="fw-bold text-dark" id="pm-city">-</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted fw-bold"><i class="fa-regular fa-id-card me-1"></i> رقم الصك</span>
                            <span class="fw-bold text-dark font-monospace" id="pm-deed">-</span>
                        </div>
                    </div>

                    <div class="text-center p-3 mb-3" style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 16px;">
                        <div class="text-muted fw-bold small mb-1">إجمالي المبلغ المستحق</div>
                        <h2 class="fw-black text-success m-0" id="pm-amount">0.00 ر.س</h2>
                    </div>

                    <div class="p-3 bg-light rounded-3 text-center small text-secondary border mb-3">
                        <i class="fa-solid fa-shield-halved text-success mb-2 fs-4 d-block"></i>
                        عملية السداد محمية وموثقة وآمنة 100% بنظام قطرة
                    </div>

                    <div id="pm-error" class="alert alert-danger d-none fw-bold text-center"></div>

                    <button type="button" class="btn-brand" id="pm-pay-btn" onclick="confirmPayment()">
                        <i class="fa-solid fa-lock me-1"></i> تأكيد ودفع الفاتورة الآن
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة الفاتورة القابلة للطباعة بعد نجاح السداد -->
    <div class="modal fade" id="invoiceModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 24px; border: none; box-shadow: 0 25px 50px rgba(0,0,0,0.2); overflow: hidden;">
                <div class="p-4 text-white text-center no-print" style="background: linear-gradient(135deg, #059669, #10b981);">
                    <i class="fa-solid fa-circle-check fs-1 mb-2 d-block"></i>
                    <h4 class="fw-black m-0">تم السداد بنجاح!</h4>
                    <small class="opacity-75">يمكنك طباعة نسخة من الفاتورة للاحتفاظ بها</small>
                </div>

                <div class="modal-body p-4">
                    <div id="printableInvoice">
                        <div class="text-center mb-4">
                            <h4 class="fw-black text-dark m-0">فاتورة سداد - نظام قطرة</h4>
                            <small class="text-muted">إيصال إلكتروني رسمي</small>
                        </div>
                        <div class="bg-light p-3 mb-3" style="border-radius: 16px; border: 1px solid #e2e8f0;">
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted fw-bold">رقم الفاتورة</span><span class="fw-black" id="inv-inv-id">-</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted fw-bold">رقم الطلب</span><span class="fw-bold" id="inv-app-id">-</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted fw-bold">اسم العميل</span><span class="fw-bold" id="inv-customer">-</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted fw-bold">رقم الهوية</span><span class="fw-bold" id="inv-national-id">-</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted fw-bold">نوع الخدمة</span><span class="fw-bold" id="inv-service">-</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted fw-bold">المدينة</span><span class="fw-bold" id="inv-city">-</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted fw-bold">رقم الصك</span><span class="fw-bold font-monospace" id="inv-deed">-</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted fw-bold">الحساب الموحد</span><span class="fw-bold" id="inv-acc">-</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted fw-bold">فني التركيب المسنَد</span><span class="fw-bold" id="inv-tech">-</span></div>
                            <div class="d-flex justify-content-between"><span class="text-muted fw-bold">تاريخ ووقت السداد</span><span class="fw-bold" id="inv-date">-</span></div>
                        </div>
                        <div class="text-center p-3" style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 16px;">
                            <div class="text-muted fw-bold small mb-1">المبلغ المسدد</div>
                            <h2 class="fw-black text-success m-0" id="inv-amount">0.00 ر.س</h2>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4 no-print">
                        <button type="button" class="btn btn-brand" style="width: auto; flex: 1;" onclick="goToPrintPage()"><i class="fa-solid fa-print me-1"></i> طباعة الفاتورة</button>
                        <button type="button" class="btn btn-outline-secondary fw-bold" style="border-radius: 16px;" onclick="closeInvoiceModal()">إغلاق ومتابعة</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة مراحل حياة الطلب -->
    <div class="modal fade" id="stagesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 24px; border: none; box-shadow: 0 25px 50px rgba(0,0,0,0.2);">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-black text-dark m-0"><i class="fa-solid fa-timeline text-primary me-2"></i> مراحل الطلب <span id="stg-app-id" class="text-muted"></span></h5>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-muted fw-bold small mb-4" id="stg-service">-</div>
                    <ul class="stage-timeline" id="stg-timeline"></ul>
                    <div id="stg-rejected-msg" class="alert alert-danger fw-bold text-center d-none mt-3">
                        <i class="fa-solid fa-circle-xmark"></i> تم رفض هذا الطلب ولن يكمل بقية المراحل.
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
            if (panelId === 'tab-notifs') {
                document.getElementById('bellBadge').classList.add('d-none');
                unreadNotifCount = 0;
                lastSeenNotifId = lastKnownMaxNotifId;
            }
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
            document.getElementById('gpsStatus').innerHTML = `<span class="text-danger fw-bold"><i class="fa-solid fa-map-pin"></i> يرجى النقر على الخريطة أو الضغط على "حدد موقعي الحالي" لتحديد موقع عقارك بدقة</span>`;
            
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

        // ============== زر "حدد موقعي الحالي" باستخدام GPS المتصفح ==============
        function locateMe() {
            let citySelect = document.getElementById('citySelect');
            if (!citySelect.value) {
                Swal.fire({ icon: 'warning', title: 'اختر المدينة أولاً', text: 'يرجى اختيار المدينة قبل تحديد موقعك الحالي.', confirmButtonColor: '#092e54' });
                return;
            }

            if (!navigator.geolocation) {
                Swal.fire({ icon: 'error', title: 'غير مدعوم', text: 'متصفحك لا يدعم خدمة تحديد الموقع الجغرافي.', confirmButtonColor: '#092e54' });
                return;
            }

            let btn = document.getElementById('locateMeBtn');
            let btnText = document.getElementById('locateMeBtnText');
            btn.disabled = true;
            let originalText = btnText.textContent;
            btnText.textContent = 'جاري تحديد الموقع...';
            btn.querySelector('i').className = 'fa-solid fa-spinner fa-spin';

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    let lat = position.coords.latitude;
                    let lng = position.coords.longitude;
                    let latlng = L.latLng(lat, lng);

                    if (marker) {
                        marker.setLatLng(latlng);
                    } else {
                        marker = L.marker(latlng).addTo(map);
                    }

                    document.getElementById('latitude').value = lat;
                    document.getElementById('longitude').value = lng;
                    document.getElementById('gpsStatus').innerHTML = `<span class="text-success fw-bold"><i class="fa-solid fa-location-crosshairs"></i> تم تحديد موقعك الحالي بدقة: ${lat.toFixed(4)}, ${lng.toFixed(4)}</span>`;

                    map.flyTo(latlng, 17, { animate: true, duration: 1.5 });

                    btn.disabled = false;
                    btnText.textContent = originalText;
                    btn.querySelector('i').className = 'fa-solid fa-location-crosshairs';
                },
                function(error) {
                    btn.disabled = false;
                    btnText.textContent = originalText;
                    btn.querySelector('i').className = 'fa-solid fa-location-crosshairs';

                    let msg = 'تعذر تحديد موقعك الحالي، يرجى التحديد يدوياً بالنقر على الخريطة.';
                    if (error.code === error.PERMISSION_DENIED) {
                        msg = 'تم رفض إذن الوصول للموقع. يرجى تفعيل صلاحية الموقع للمتصفح من إعدادات جهازك ثم إعادة المحاولة.';
                    } else if (error.code === error.TIMEOUT) {
                        msg = 'انتهت مهلة تحديد الموقع، يرجى المحاولة مرة أخرى أو التحديد يدوياً.';
                    }
                    Swal.fire({ icon: 'error', title: 'تعذر تحديد الموقع', text: msg, confirmButtonColor: '#092e54' });
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        }

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

        // ============== نافذة سداد الفاتورة الجديدة ==============
        let currentPaymentAppId = null;

        function openPaymentModal(appId, amount, serviceName, cityName, deedNo) {
            currentPaymentAppId = appId;
            document.getElementById('pm-app-id').textContent = '#' + String(appId).padStart(5, '0');
            document.getElementById('pm-service').textContent = serviceName;
            document.getElementById('pm-city').textContent = cityName;
            document.getElementById('pm-deed').textContent = deedNo;
            document.getElementById('pm-amount').textContent = amount.toLocaleString('ar-SA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ر.س';

            document.getElementById('pm-error').classList.add('d-none');
            document.getElementById('pm-error').textContent = '';
            let payBtn = document.getElementById('pm-pay-btn');
            payBtn.disabled = false;
            payBtn.innerHTML = '<i class="fa-solid fa-lock me-1"></i> تأكيد ودفع الفاتورة الآن';

            let modal = new bootstrap.Modal(document.getElementById('paymentModal'));
            modal.show();
        }

        // ============== نافذة مراحل حياة الطلب ==============
        const stageDefs = [
            { title: 'تقديم الطلب ومطابقة الصك', desc: 'تم إرسال الطلب ومطابقته آلياً عبر محرك (DSS) مع وزارة العدل.', icon: 'fa-file-signature', short: 'تقديم الطلب' },
            { title: 'الفحص الميداني', desc: 'زيارة فني الفحص للموقع والتحقق من جاهزية العقار.', icon: 'fa-helmet-safety', short: 'الفحص الميداني' },
            { title: 'إصدار وسداد الفاتورة', desc: 'إصدار فاتورة الربط وسدادها إلكترونياً.', icon: 'fa-file-invoice-dollar', short: 'الفاتورة' },
            { title: 'التركيب الميداني', desc: 'تركيب وتفعيل العداد الذكي وربط الحساب الموحد.', icon: 'fa-person-digging', short: 'التركيب' },
            { title: 'الاكتمال والتفعيل', desc: 'اكتمال جميع الإجراءات وتفعيل الخدمة بالكامل.', icon: 'fa-circle-check', short: 'التفعيل' }
        ];

        // رسائل حية بأسلوب تتبع طلبات المطاعم لكل حالة
        const liveStageMessages = {
            'Pending_Review':     { text: 'طلبك قيد المراجعة اليدوية من قبل فريقنا للتحقق من بيانات المالك', icon: 'fa-file-signature' },
            'Pending_Inspection': { text: 'جاري تجهيز الفحص الميداني... فني الفحص في طريقه لجدولة الزيارة', icon: 'fa-helmet-safety' },
            'Pending_Billing':    { text: 'اكتمل الفحص الميداني بنجاح! فاتورتك جاهزة الآن للسداد', icon: 'fa-file-invoice-dollar' },
            'In_Progress':        { text: 'جاري الآن تركيب وتفعيل عدادك في الموقع', icon: 'fa-person-digging' },
            'Completed':          { text: 'تم تفعيل خدمتك بالكامل وربط عدادك بنجاح 🎉', icon: 'fa-circle-check' },
            'Rejected':           { text: 'تم رفض هذا الطلب، يمكنك مراجعة السبب من سجل الطلبات', icon: 'fa-circle-xmark' }
        };

        function openStagesModal(appId, stageIndex, serviceName) {
            document.getElementById('stg-app-id').textContent = '#' + String(appId).padStart(5, '0');
            document.getElementById('stg-service').textContent = serviceName;

            let timeline = document.getElementById('stg-timeline');
            let rejectedMsg = document.getElementById('stg-rejected-msg');
            timeline.innerHTML = '';

            if (stageIndex === -1) {
                rejectedMsg.classList.remove('d-none');
            } else {
                rejectedMsg.classList.add('d-none');
            }

            for (let idx = 0; idx < stageDefs.length; idx++) {
                let stage = stageDefs[idx];
                let stateClass = '';
                let iconHtml = `<i class="fa-solid ${stage.icon}"></i>`;
                if (stageIndex !== -1) {
                    if (idx < stageIndex || (idx === stageIndex && stageIndex === 4)) {
                        stateClass = 'done';
                        iconHtml = '<i class="fa-solid fa-check"></i>';
                    } else if (idx === stageIndex) {
                        stateClass = 'current';
                        iconHtml = '<i class="fa-solid fa-spinner fa-spin"></i>';
                    }
                } else if (idx === 0) {
                    stateClass = 'done';
                    iconHtml = '<i class="fa-solid fa-check"></i>';
                } else if (idx === 1) {
                    stateClass = 'rejected';
                    iconHtml = '<i class="fa-solid fa-xmark"></i>';
                }

                let li = document.createElement('li');
                li.className = 'stage-item ' + stateClass;
                li.innerHTML = `
                    <div class="stage-icon">${iconHtml}</div>
                    <div class="stage-title">${stage.title}</div>
                    <div class="stage-desc">${stage.desc}</div>
                `;
                timeline.appendChild(li);

                // نوقف عرض بقية المراحل بعد نقطة الرفض
                if (stageIndex === -1 && idx === 1) break;
            }

            new bootstrap.Modal(document.getElementById('stagesModal')).show();
        }

        function goToPrintPage() {
            if (!currentPaymentAppId) return;
            window.open('invoice_print.php?app_id=' + currentPaymentAppId, '_blank');
        }

        function closeInvoiceModal() {
            bootstrap.Modal.getInstance(document.getElementById('invoiceModal')).hide();
            window.location.reload();
        }

        function confirmPayment() {
            if (!currentPaymentAppId) return;

            let payBtn = document.getElementById('pm-pay-btn');
            let errorBox = document.getElementById('pm-error');
            errorBox.classList.add('d-none');
            payBtn.disabled = true;
            payBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> جاري تأكيد السداد...';

            let fd = new FormData();
            fd.append('pay_invoice', '1');
            fd.append('app_id', currentPaymentAppId);

            fetch('dashboard.php', { method: 'POST', body: fd })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'error') {
                    errorBox.textContent = data.message;
                    errorBox.classList.remove('d-none');
                    payBtn.disabled = false;
                    payBtn.innerHTML = '<i class="fa-solid fa-lock me-1"></i> تأكيد ودفع الفاتورة الآن';
                    return;
                }

                let modalEl = document.getElementById('paymentModal');
                bootstrap.Modal.getInstance(modalEl).hide();

                let inv = data.invoice;
                document.getElementById('inv-inv-id').textContent = '#' + inv.inv_id;
                document.getElementById('inv-app-id').textContent = '#' + inv.app_id;
                document.getElementById('inv-customer').textContent = inv.customer_name;
                document.getElementById('inv-national-id').textContent = inv.national_id;
                document.getElementById('inv-service').textContent = inv.service;
                document.getElementById('inv-city').textContent = inv.city;
                document.getElementById('inv-deed').textContent = inv.deed_no;
                document.getElementById('inv-acc').textContent = 'ACC-' + inv.acc_id;
                document.getElementById('inv-tech').textContent = inv.tech_name;
                document.getElementById('inv-date').textContent = inv.paid_at;
                document.getElementById('inv-amount').textContent = inv.amount + ' ر.س';

                setTimeout(() => {
                    new bootstrap.Modal(document.getElementById('invoiceModal')).show();
                }, 350);
            })
            .catch(error => {
                errorBox.textContent = 'فشل الاتصال بالخادم، يرجى المحاولة لاحقاً.';
                errorBox.classList.remove('d-none');
                payBtn.disabled = false;
                payBtn.innerHTML = '<i class="fa-solid fa-lock me-1"></i> تأكيد ودفع الفاتورة الآن';
            });
        }

        // =========================================================================
        // ============== نظام الإشعارات والتتبع الحي (Live Order-Style Tracking) ==============
        // نفس فكرة تطبيقات توصيل الطلبات: تحديث تلقائي دوري لحالة الطلب + إشعار فوري
        // عند كل تغيّر مرحلة (جاري التحضير -> جاري التوصيل...) دون الحاجة لتحديث الصفحة يدوياً
        // =========================================================================

        // الحالة الأولية المعروفة لكل طلب (لمقارنتها بما يصل عبر الاستطلاع الدوري)
        let knownAppStatuses = {};
        document.querySelectorAll('#appsTable tr[data-app-id]').forEach(tr => {
            knownAppStatuses[tr.getAttribute('data-app-id')] = null; // سيُعبّأ بعد أول استطلاع
        });

        let lastKnownMaxNotifId = <?= (int)($myNotifications[0]['notif_id'] ?? 0); ?>;
        let lastSeenNotifId = lastKnownMaxNotifId;
        let unreadNotifCount = 0;
        let firstPollDone = false;

        function updateBellBadge() {
            let badge = document.getElementById('bellBadge');
            if (unreadNotifCount > 0) {
                badge.textContent = unreadNotifCount > 9 ? '9+' : unreadNotifCount;
                badge.classList.remove('d-none');
                let bell = document.getElementById('bellBtn');
                bell.classList.remove('ringing');
                void bell.offsetWidth;
                bell.classList.add('ringing');
            } else {
                badge.classList.add('d-none');
            }
        }

        function stageIndexOf(status) {
            const map = { 'Pending_Review': 0, 'Pending_Inspection': 1, 'Pending_Billing': 2, 'In_Progress': 3, 'Completed': 4 };
            if (status === 'Rejected') return -1;
            return map[status] ?? 0;
        }

        function statusBadgeHtml(status) {
            const badges = {
                'Pending_Review': '<span class="status-badge badge-warning"><i class="fa-solid fa-file-signature"></i> قيد المراجعة</span>',
                'Pending_Inspection': '<span class="status-badge badge-info"><i class="fa-solid fa-helmet-safety"></i> جاري جدولة الفحص</span>',
                'Pending_Billing': '<span class="status-badge badge-dark"><i class="fa-solid fa-file-invoice-dollar"></i> بانتظار سداد الفاتورة</span>',
                'In_Progress': '<span class="status-badge badge-primary"><i class="fa-solid fa-person-digging"></i> جاري التركيب</span>',
                'Completed': '<span class="status-badge badge-success"><i class="fa-solid fa-circle-check"></i> مكتمل ومفعّل بالكامل</span>',
                'Rejected': '<span class="status-badge badge-danger"><i class="fa-solid fa-circle-xmark"></i> تم رفض الطلب</span>'
            };
            return badges[status] || status;
        }

        // تحديث بطاقة "متابعة طلبك المباشرة" في أعلى الصفحة
        function renderLiveTracker(app) {
            let card = document.getElementById('liveTrackerCard');
            if (!app || app.app_status === 'Completed' || app.app_status === 'Rejected') {
                card.classList.add('d-none');
                return;
            }
            card.classList.remove('d-none');
            document.getElementById('lt-app-id').textContent = '#' + String(app.app_id).padStart(5, '0') + ' - ' + app.srv_name_clean;

            let idx = stageIndexOf(app.app_status);
            let pct = Math.max(10, (idx / 4) * 100);
            document.getElementById('ltProgressFill').style.width = pct + '%';

            let stepsWrap = document.getElementById('ltSteps');
            stepsWrap.innerHTML = '';
            stageDefs.forEach((s, i) => {
                let cls = i < idx ? 'done' : (i === idx ? 'current' : '');
                let icon = i < idx ? '<i class="fa-solid fa-check"></i>' : `<i class="fa-solid ${s.icon}"></i>`;
                stepsWrap.innerHTML += `<div class="lt-step ${cls}"><div class="lt-step-icon">${icon}</div><div class="lt-step-label">${s.short}</div></div>`;
            });

            let msg = liveStageMessages[app.app_status] || { text: 'جاري تحديث حالة طلبك...', icon: 'fa-circle-notch' };
            document.getElementById('ltMsg').innerHTML = `<i class="fa-solid ${msg.icon}"></i> ${msg.text}`;
        }

        function pickTrackedApp(apps) {
            // نختار أقرب طلب نشط (غير مكتمل وغير مرفوض) لعرضه في شريط التتبع الحي
            let active = apps.filter(a => a.app_status !== 'Completed' && a.app_status !== 'Rejected');
            return active.length ? active[0] : null;
        }

        function showLiveToast(app, prevStatus) {
            let msg = liveStageMessages[app.app_status] || { text: 'تحديث جديد على طلبك', icon: 'fa-bell' };
            Swal.fire({
                toast: true,
                position: 'top-start',
                icon: app.app_status === 'Rejected' ? 'error' : (app.app_status === 'Completed' ? 'success' : 'info'),
                title: 'طلب #' + String(app.app_id).padStart(5, '0') + ' (' + app.srv_name_clean + ')',
                html: `<i class="fa-solid ${msg.icon}"></i> ${msg.text}`,
                showConfirmButton: false,
                timer: 6000,
                timerProgressBar: true
            });
        }

        function applyAppUpdate(app, isFirstLoad) {
            let prevStatus = knownAppStatuses[app.app_id];
            knownAppStatuses[app.app_id] = app.app_status;

            // تحديث صف الجدول في سجل الطلبات دون تحديث الصفحة
            let row = document.querySelector('#appsTable tr[data-app-id="' + app.app_id + '"]');
            if (row) {
                let statusCell = row.querySelector('.status-cell');
                if (statusCell) {
                    statusCell.innerHTML = statusBadgeHtml(app.app_status);
                    if (!isFirstLoad && prevStatus !== null && prevStatus !== app.app_status) {
                        statusCell.querySelector('.status-badge')?.classList.add('just-updated');
                    }
                }
                let stageBtn = row.querySelector('.stage-btn');
                if (stageBtn) {
                    stageBtn.setAttribute('onclick', `openStagesModal(${app.app_id}, ${stageIndexOf(app.app_status)}, '${app.srv_name_clean}')`);
                }
                let payCell = row.querySelector('.payment-cell');
                if (payCell && app.payment_status === 'Unpaid' && app.amount) {
                    payCell.innerHTML = `<div class="d-flex align-items-center gap-2">
                        <span class="badge bg-danger rounded-pill px-3 py-2 fw-bold">غير مدفوعة: ${Number(app.amount).toLocaleString('ar-SA')} ر.س</span>
                        <button class="btn btn-sm btn-success fw-bold px-3 rounded-pill" onclick="openPaymentModal(${app.app_id}, ${app.amount}, '${app.srv_name_clean}', '${app.city_clean}', '${app.deed_no}')">
                            <i class="fa-solid fa-credit-card"></i> سداد سريع
                        </button></div>`;
                } else if (payCell && app.payment_status === 'Paid') {
                    payCell.innerHTML = `<div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success rounded-pill px-3 py-2"><i class="fa-solid fa-circle-check"></i> مدفوعة بنجاح</span>
                        <a href="invoice_print.php?app_id=${app.app_id}" target="_blank" class="btn btn-sm btn-outline-secondary fw-bold px-3 rounded-pill"><i class="fa-solid fa-print"></i> طباعة</a></div>`;
                }
            }

            // إشعار حي فوري بأسلوب تطبيقات التوصيل عند تغيّر الحالة فقط (وليس عند أول تحميل)
            if (!isFirstLoad && prevStatus !== null && prevStatus !== app.app_status) {
                showLiveToast(app, prevStatus);
            }
        }

        function appendNewNotifications(list) {
            if (!list.length) return;
            let emptyState = document.getElementById('notifsEmptyState');
            if (emptyState) emptyState.remove();

            let container = document.getElementById('notifsList');
            list.forEach(n => {
                let div = document.createElement('div');
                div.className = 'notif-box d-flex justify-content-between align-items-center notif-new';
                div.setAttribute('data-notif-id', n.notif_id);
                div.innerHTML = `<div>
                        <div class="text-dark fw-bold mb-1">${n.message_content}</div>
                        <div class="small text-muted"><i class="fa-regular fa-clock"></i> ${n.created_at}</div>
                    </div>
                    <span class="badge bg-light text-success border"><i class="fa-solid fa-circle"></i> جديد</span>`;
                container.prepend(div);
            });
        }

        function pollLiveUpdates() {
            fetch('poll_updates.php?last_notif_id=' + lastKnownMaxNotifId)
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') return;

                    // 1) تحديث حالات الطلبات
                    (data.applications || []).forEach(app => applyAppUpdate(app, !firstPollDone));
                    renderLiveTracker(pickTrackedApp(data.applications || []));

                    // 2) الإشعارات الجديدة
                    if (data.new_notifications && data.new_notifications.length) {
                        appendNewNotifications(data.new_notifications);
                        lastKnownMaxNotifId = data.new_notifications[0].notif_id;
                        // إن كان المستخدم ليس في تبويب الإشعارات الآن نزود العداد
                        if (!document.getElementById('tab-notifs').classList.contains('active')) {
                            unreadNotifCount += data.new_notifications.length;
                            updateBellBadge();
                        }
                    }

                    firstPollDone = true;
                })
                .catch(() => { /* تجاهل صامت لأي خطأ شبكة مؤقت */ });
        }

        // أول استطلاع فوري عند تحميل الصفحة، ثم كل 8 ثوانٍ لمحاكاة التتبع الحي لتطبيقات التوصيل
        pollLiveUpdates();
        setInterval(pollLiveUpdates, 8000);
    </script>
</body>
</html>