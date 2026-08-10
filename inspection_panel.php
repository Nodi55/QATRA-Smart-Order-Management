<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// التحقق من صلاحية فني الفحص الميداني
if (!isset($_SESSION['emp_id']) || !in_array('Inspection Technician', $_SESSION['emp_roles'])) {
    header("Location: employee_dashboard.php");
    exit;
}

require_once 'db_connect.php';
$msg = ""; $msgType = "";
$empId = $_SESSION['emp_id'];

// =========================================================
// نظام المزامنة والعدادات الذكية: لتحديث العداد الفعلي فوراً ومنع أي أخطاء تراكمية
// =========================================================
try {
    $pdo->exec("
        UPDATE company_employee ce
        SET ce.active_tasks_count = (
            (SELECT COUNT(*) FROM field_inspection fi WHERE fi.emp_id = ce.emp_id AND fi.inspection_result IS NULL) +
            (SELECT COUNT(*) FROM installation_task it WHERE it.emp_id = ce.emp_id AND it.initial_reading IS NULL)
        )
    ");
} catch (Exception $e) {
    // صامتة
}

// تهيئة ذكية: تأكيد وجود جدول الإشعارات والتحذيرات للموظفين
try {
    $pdo->query("SELECT 1 FROM employee_notification LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `employee_notification` (
        `notif_id` int NOT NULL AUTO_INCREMENT,
        `emp_id` int NOT NULL,
        `message_content` text NOT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `is_read` tinyint(1) DEFAULT '0',
        `notif_type` varchar(50) DEFAULT 'info',
        PRIMARY KEY (`notif_id`),
        KEY `emp_id` (`emp_id`),
        CONSTRAINT `employee_notification_ibfk_1` FOREIGN KEY (`emp_id`) REFERENCES `company_employee` (`emp_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
}

// معالجة قراءة إشعارات الموظف
if (isset($_GET['read_notif'])) {
    $notifId = intval($_GET['read_notif']);
    $pdo->prepare("UPDATE employee_notification SET is_read = 1 WHERE notif_id = ? AND emp_id = ?")->execute([$notifId, $empId]);
    header("Location: inspection_panel.php");
    exit;
}

// جلب إشعارات الموظف الحالي غير المقروءة
$empNotifs = [];
try {
    $notifStmt = $pdo->prepare("SELECT * FROM employee_notification WHERE emp_id = ? AND is_read = 0 ORDER BY created_at DESC");
    $notifStmt->execute([$empId]);
    $empNotifs = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // صامتة
}

// =========================================================
// معالجة إرسال تقرير الفحص الميداني وإغلاق الطلب / أو تحويله للفوترة
// =========================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_inspection'])) {
    $inspId = intval($_POST['insp_id']);
    $appId = intval($_POST['app_id']);
    $buildingReadiness = isset($_POST['building_readiness']) ? 1 : 0;
    $doorsWindowsInstalled = isset($_POST['doors_windows_installed']) ? 1 : 0;
    $meterSpotPainted = isset($_POST['meter_spot_painted']) ? 1 : 0;
    $result = $_POST['inspection_result']; // 'Passed' or 'Failed'
    $notes = trim($_POST['inspection_notes'] ?? '');
    
    // رفع ومعالجة صورة الموقع والعداد لتوثيق الفحص
    $sitePhotoUrl = 'uploads/default_site.jpg';
    if (isset($_FILES['site_photo']) && $_FILES['site_photo']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['site_photo']['tmp_name'];
        $originalFileName = $_FILES['site_photo']['name'];
        $fileExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        if (in_array($fileExtension, $allowedExtensions)) {
            $hashedFileName = md5(time() . $inspId) . '.' . $fileExtension;
            $targetDir = "uploads/";
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }
            $targetFilePath = $targetDir . $hashedFileName;
            if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
                $sitePhotoUrl = $targetFilePath;
            }
        }
    }
    
    try {
        $pdo->beginTransaction();
        
        // 1. تحديث جدول تفاصيل تقرير الفحص الميداني
        $stmt = $pdo->prepare("
            UPDATE field_inspection 
            SET building_readiness = ?, doors_windows_installed = ?, meter_spot_painted = ?, site_photos_url = ?, inspection_result = ?
            WHERE insp_id = ?
        ");
        $stmt->execute([$buildingReadiness, $doorsWindowsInstalled, $meterSpotPainted, $sitePhotoUrl, $result, $inspId]);
        
        // 2. إنقاص عداد المهام النشطة للفني
        $pdo->prepare("UPDATE company_employee SET active_tasks_count = GREATEST(0, active_tasks_count - 1) WHERE emp_id = ?")->execute([$empId]);
        
        // 3. معالجة حالة الطلب بناءً على قرار الفني الميداني
        if ($result == 'Passed') {
            // أ. تحويل حالة الطلب إلى "بانتظار سداد الفاتورة" (Pending_Billing)
            $pdo->prepare("UPDATE application SET app_status = 'Pending_Billing' WHERE app_id = ?")->execute([$appId]);
            
            // ب. تسجيل السجل التاريخي للطلب في Application_History
            $pdo->prepare("INSERT INTO application_history (app_id, status, changed_by, change_date) VALUES (?, 'Pending_Billing', ?, NOW())")
                ->execute([$appId, $empId]);
                
            // جـ. قراءة مساحة الأرض من سجل وزارة العدل (MOJ) لاحتساب رسوم الفاتورة آلياً
            $stmtApp = $pdo->prepare("SELECT deed_no, cust_id FROM application WHERE app_id = ?");
            $stmtApp->execute([$appId]);
            $appInfo = $stmtApp->fetch();
            $deedNo = $appInfo['deed_no'];
            $custId = $appInfo['cust_id'];
            
            $stmtMoj = $pdo->prepare("SELECT land_area FROM moj_record WHERE deed_no = ?");
            $stmtMoj->execute([$deedNo]);
            $landArea = $stmtMoj->fetchColumn() ?: 450; // المساحة الافتراضية إذا لم تسجل
            
            // دقة متناهية في التسعير: مساحة <= 675م سعر ثابت 3450 ريال. مساحة > 675م يكون السعر = المساحة * 10 ريال
            $price = 3450;
            if ($landArea > 675) {
                $price = $landArea * 10;
            }
            
            // د. توليد الفاتورة الإلكترونية غير المدفوعة للعميل
            $stmtInv = $pdo->prepare("INSERT INTO invoice (amount, payment_status, app_id) VALUES (?, 'Unpaid', ?)");
            $stmtInv->execute([$price, $appId]);
            
            // هـ. إرسال تنبيه فوري للمستفيد يفيد بصدور الفاتورة جاهزة للسداد
            $notifMsg = "تهانينا! لقد اجتاز عقارك الفحص الميداني بنجاح وموقع العداد جاهز. تم إصدار فاتورة الربط والتركيب بقيمة " . number_format($price, 2) . " ر.س. يرجى المبادرة بالسداد عبر البوابة الإلكترونية لتفعيل العداد الميداني.";
            $pdo->prepare("INSERT INTO notification (message_content, cust_id) VALUES (?, ?)")->execute([$notifMsg, $custId]);
            
            $msg = "تم قبول واعتماد الفحص الميداني للطلب بنجاح! تم احتساب التكلفة آلياً بقيمة (" . number_format($price, 2) . " ر.س) وإصدار الفاتورة وتنبيه المستفيد.";
            $msgType = "success";
        } else {
            // في حال رفض الموقع لعدم جاهزيته الفنية
            // أ. تحديث حالة الطلب إلى "مرفوض" (Rejected)
            $pdo->prepare("UPDATE application SET app_status = 'Rejected' WHERE app_id = ?")->execute([$appId]);
            
            // ب. تسجيل سبب الرفض وهوية الفني في سجل المتابعة التاريخي
            $rejectionReason = !empty($notes) ? "لم يجتز الفحص الميداني: " . $notes : "لم يجتز الفحص الميداني الفني (عدم جاهزية الموقع الفنية).";
            $pdo->prepare("INSERT INTO application_history (app_id, status, rejection_reason, changed_by, change_date) VALUES (?, 'Rejected', ?, ?, NOW())")
                ->execute([$appId, $rejectionReason, $empId]);
                
            // جـ. إرسال إشعار للمستفيد يفيد برفض الطلب مع توضيح الإجراء المطلوب منه لتصحيحه
            $custId = $pdo->query("SELECT cust_id FROM application WHERE app_id = $appId")->fetchColumn();
            if ($custId) {
                $notifMsg = "نأسف لإبلاغك بأن طلبك رقم #" . str_pad($appId, 5, '0', STR_PAD_LEFT) . " تم رفضه لعدم جاهزية الموقع أثناء معاينة الفحص الميداني للسبب التالي: (" . $rejectionReason . "). يرجى تهيئة موقع العداد والصبغ والمحاولة مجدداً.";
                $pdo->prepare("INSERT INTO notification (message_content, cust_id) VALUES (?, ?)")->execute([$notifMsg, $custId]);
            }
            
            $msg = "تم رفض الفحص الميداني للطلب، وتوثيق أسباب عدم الجاهزية وإرسال التقرير والسبب الفني للمستفيد.";
            $msgType = "warning";
        }
        
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "فشلت عملية تحديث وحفظ التقرير الميداني: " . $e->getMessage();
        $msgType = "error";
    }
}

// =========================================================
// جلب استعلامات شاشة العرض والتشغيل
// =========================================================
// 1. قائمة المهام النشطة للفني الحالي (بانتظار الفحص الميداني)
$stmtActiveTasks = $pdo->prepare("
    SELECT fi.insp_id, fi.app_id, a.deed_no, a.latitude, a.longitude,
    c.full_name as customer_name, c.phone_number, cy.cty_name, st.srv_name
    FROM field_inspection fi
    JOIN application a ON fi.app_id = a.app_id
    JOIN customer c ON a.cust_id = c.cust_id
    JOIN city cy ON a.cty_id = cy.cty_id
    JOIN service_type st ON a.srv_id = st.srv_id
    WHERE fi.emp_id = ? AND fi.inspection_result IS NULL
    ORDER BY a.created_at ASC
");
$stmtActiveTasks->execute([$empId]);
$activeTasks = $stmtActiveTasks->fetchAll(PDO::FETCH_ASSOC);

// 2. قائمة التقارير الفنية المكتملة سابقاً بواسطة هذا الفني
$stmtCompletedTasks = $pdo->prepare("
    SELECT fi.insp_id, fi.app_id, fi.building_readiness, fi.doors_windows_installed, fi.meter_spot_painted,
    fi.site_photos_url, fi.inspection_result, a.deed_no, c.full_name as customer_name, cy.cty_name, st.srv_name
    FROM field_inspection fi
    JOIN application a ON fi.app_id = a.app_id
    JOIN customer c ON a.cust_id = c.cust_id
    JOIN city cy ON a.cty_id = cy.cty_id
    JOIN service_type st ON a.srv_id = st.srv_id
    WHERE fi.emp_id = ? AND fi.inspection_result IS NOT NULL
    ORDER BY fi.insp_id DESC
");
$stmtCompletedTasks->execute([$empId]);
$completedTasks = $stmtCompletedTasks->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>بوابة فني الفحص الميداني | قطرة</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root { --navy: #092e54; --blue: #0b457f; --light: #4492d4; --bg: #f8fafc; }
body { font-family: 'Cairo', sans-serif; background-color: var(--bg); margin: 0; padding: 0; display: flex; height: 100vh; overflow: hidden; }
.sidebar { width: 280px; background: var(--navy); color: white; display: flex; flex-direction: column; box-shadow: -4px 0 15px rgba(0,0,0,0.1); z-index: 100; }
.sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
.sidebar-header i { font-size: 2.5rem; color: #7dd3fc; margin-bottom: 10px; }
.sidebar-nav { flex: 1; padding: 20px 0; overflow-y: auto; }
.nav-item { padding: 15px 25px; color: #cbd5e1; display: flex; align-items: center; gap: 15px; text-decoration: none; font-weight: 700; transition: 0.3s; cursor: pointer; border-right: 4px solid transparent; }
.nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.05); color: white; border-right-color: #7dd3fc; }
.main-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.topbar { background: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); z-index: 50; }
.content-area { flex: 1; padding: 30px; overflow-y: auto; background: var(--bg); }
.task-card { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid #e2e8f0; transition: 0.3s; cursor: pointer; }
.task-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(9,46,84,0.08); border-color: var(--light); }
.task-card.active-selection { border-right: 5px solid var(--light); background: #f0f7ff; }
.admin-card { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid #e2e8f0; }
.card-title { color: var(--navy); font-weight: 900; font-size: 1.2rem; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; }
.btn-brand { background: var(--blue); color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 800; width: 100%; transition: 0.3s; }
.btn-brand:hover { background: var(--navy); color: white; }
.map-box { height: 350px; border-radius: 16px; border: 2px solid #e2e8f0; overflow: hidden; }
.form-label { font-weight: 700; color: var(--navy); }
.page-view { display: none; animation: fadeIn 0.4s; }
.page-view.active { display: block; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>
</head>
<body>
<div class="sidebar">
<div class="sidebar-header">
<i class="fa-solid fa-helmet-safety"></i>
<h4 class="fw-black m-0">البوابة الميدانية</h4>
<div class="small mt-1 text-info">فني الفحص والمعاينة الفنية</div>
</div>
<div class="sidebar-nav">
<a class="nav-item active" onclick="openPage('page-active-tasks', this)"><i class="fa-solid fa-clipboard-check"></i> المهام الميدانية النشطة <span class="badge bg-warning text-dark ms-auto"><?= count($activeTasks); ?></span></a>
<a class="nav-item" onclick="openPage('page-completed-tasks', this)"><i class="fa-solid fa-circle-check"></i> المواقع المفحوصة سابقاً <span class="badge bg-success ms-auto"><?= count($completedTasks); ?></span></a>
</div>
<div class="p-3 border-top border-secondary text-center">
<a href="employee_dashboard.php" class="btn btn-outline-light w-100 fw-bold rounded-pill"><i class="fa-solid fa-arrow-right-from-bracket fa-flip-horizontal me-2"></i> شاشة التوجيه</a>
</div>
</div>
<div class="main-wrapper">
<div class="topbar">
<div><h4 class="fw-black text-dark m-0" id="topbar-title">المهام الميدانية النشطة</h4></div>
<div class="d-flex align-items-center gap-3">
<span class="fw-bold text-secondary">الفني: <?= htmlspecialchars($_SESSION['emp_name']); ?></span>
<div class="bg-primary text-white rounded-circle d-flex justify-content-center align-items-center" style="width: 40px; height: 40px;"><i class="fa-solid fa-user-gear"></i></div>
</div>
</div>
<div class="content-area">
<?php if (!empty($empNotifs)): ?>
<div class="row mb-4">
<div class="col-12">
<div class="card border-0 shadow-sm rounded-4" style="background: linear-gradient(135deg, #fffbeb 0%, #fff7ed 100%); border-right: 6px solid #f97316 !important;">
<div class="card-body p-4">
<div class="d-flex justify-content-between align-items-center mb-3">
<h5 class="fw-black text-warning m-0"><i class="fa-solid fa-bell fa-shake me-2"></i> مركز التنبيهات والإنذارات الإدارية الحرج!</h5>
<span class="badge bg-warning text-dark px-3 py-2 fw-bold"><?= count($empNotifs); ?> تنبيهات معلقة</span>
</div>
<div class="space-y-3">
<?php foreach ($empNotifs as $notif):
$isWarning = ($notif['notif_type'] == 'warning');
$iconClass = $isWarning ? 'fa-triangle-exclamation text-danger' : 'fa-map-location-dot text-info';
$badgeClass = $isWarning ? 'bg-danger text-white' : 'bg-info text-dark';
$badgeLabel = $isWarning ? 'إنذار إداري من المدير' : 'مهمة خارج النطاق الجغرافي';
?>
<div class="p-3 bg-white rounded-3 border d-flex justify-content-between align-items-center mb-2 shadow-sm">
<div class="d-flex align-items-center gap-3">
<div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; flex-shrink: 0;">
<i class="fa-solid <?= $iconClass; ?> fs-5"></i>
</div>
<div class="text-start">
<span class="badge <?= $badgeClass; ?> fw-bold mb-1" style="font-size: 0.75rem;"><?= $badgeLabel; ?></span>
<p class="m-0 fw-bold text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($notif['message_content']); ?></p>
<small class="text-muted small"><i class="fa-regular fa-clock me-1"></i> <?= $notif['created_at']; ?></small>
</div>
</div>
<a href="?read_notif=<?= $notif['notif_id']; ?>" class="btn btn-sm btn-outline-secondary rounded-pill fw-bold" style="white-space: nowrap;"><i class="fa-solid fa-check me-1"></i> تحديد كمقروء</a>
</div>
<?php endforeach; ?>
</div>
</div>
</div>
</div>
</div>
<?php endif; ?>

<?php if($msg): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
Swal.fire({ icon: '<?= $msgType ?>', title: 'إشعار الميدان', text: '<?= addslashes($msg) ?>', confirmButtonColor: '#0b457f' });
});
</script>
<?php endif; ?>

<!-- صفحة 1: المهام النشطة -->
<div id="page-active-tasks" class="page-view active">
<?php if(empty($activeTasks)): ?>
<div class="text-center py-5 bg-white rounded-3 shadow-sm border">
<i class="fa-solid fa-mug-hot text-success fs-1 mb-3"></i>
<h4 class="fw-bold text-success">عمل رائع! تم إنجاز كافة الفحوصات الميدانية</h4>
<p class="text-muted fw-bold mb-0">لا توجد أي مهام فحص معلقة بملفك التشغيلي حالياً.</p>
</div>
<?php else: ?>
<div class="row g-4">
<!-- القائمة الميدانية للمهام -->
<div class="col-lg-5" style="max-height: calc(100vh - 180px); overflow-y: auto;">
<?php foreach($activeTasks as $index => $task): ?>
<div class="task-card <?= $index === 0 ? 'active-selection' : '' ?>" id="card-<?= $task['insp_id'] ?>" onclick="selectTask(<?= htmlspecialchars(json_encode($task), ENT_QUOTES, 'UTF-8') ?>, this)">
<div class="d-flex justify-content-between align-items-start mb-2">
<span class="badge bg-light text-dark border fw-black">طلب رقم #<?= str_pad($task['app_id'], 5, '0', STR_PAD_LEFT); ?></span>
<span class="badge bg-primary fw-bold"><i class="fa-solid fa-droplet me-1"></i> <?= htmlspecialchars($task['srv_name']) ?></span>
</div>
<h5 class="fw-black text-dark mb-1"><?= htmlspecialchars($task['customer_name']) ?></h5>
<p class="small text-muted fw-bold mb-2"><i class="fa-solid fa-location-dot text-danger"></i> العقار في: <?= htmlspecialchars($task['cty_name']) ?></p>
<div class="d-flex justify-content-between text-secondary small fw-bold">
<span>رقم الصك: <?= htmlspecialchars($task['deed_no']) ?></span>
<span class="text-primary"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($task['phone_number']) ?></span>
</div>
</div>
<?php endforeach; ?>
</div>

<!-- نموذج إدخال تقرير الفحص الفني والملاحة الجغرافية -->
<div class="col-lg-7">
<div class="admin-card">
<div class="card-title text-primary"><i class="fa-solid fa-location-crosshairs"></i> موقع العقار وتفاصيل الملاحة الجغرافية</div>
<div class="map-box mb-3" id="taskMap"></div>
<div class="d-flex gap-2 mb-4">
<a id="btnGoogleMap" href="#" target="_blank" class="btn btn-outline-danger w-100 fw-bold rounded-pill"><i class="fa-solid fa-map-location-dot me-2"></i> فتح اتجاهات الملاحة في خرائط جوجل</a>
</div>

<div class="card-title text-success"><i class="fa-solid fa-file-shield"></i> استمارة الفحص الميداني والجاهزية الفنية</div>
<form method="POST" id="installForm" enctype="multipart/form-data">
<input type="hidden" name="insp_id" id="form_insp_id" value="<?= $activeTasks['insp_id'] ?>">
<input type="hidden" name="app_id" id="form_app_id" value="<?= $activeTasks['app_id'] ?>">

<div class="mb-4 bg-light p-3 rounded-3 border">
<label class="form-label d-block mb-3 text-navy"><i class="fa-solid fa-square-check text-primary me-1"></i> قائمة المتطلبات التشغيلية لتركيب العداد:</label>
<div class="form-check mb-2">
<input class="form-check-input" type="checkbox" name="building_readiness" id="building_readiness" value="1" required>
<label class="form-check-label fw-bold" for="building_readiness">مبنى جاهز ومكتمل (لا توجد عمليات بناء حية تعيق التركيب)</label>
</div>
<div class="form-check mb-2">
<input class="form-check-input" type="checkbox" name="doors_windows_installed" id="doors_windows_installed" value="1" required>
<label class="form-check-label fw-bold" for="doors_windows_installed">الأبواب والنوافذ الخارجية للمبنى مركبة بالكامل</label>
</div>
<div class="form-check mb-2">
<input class="form-check-input" type="checkbox" name="meter_spot_painted" id="meter_spot_painted" value="1" required>
<label class="form-check-label fw-bold" for="meter_spot_painted">موقع العداد محدد ومصبوغ باللون الأزرق المعتمد رسمياً</label>
</div>
</div>

<div class="mb-3">
<label class="form-label">إرفاق صورة حية وميدانية للموقع لتوثيق الجاهزية والعداد <span class="text-danger">*</span></label>
<input type="file" name="site_photo" class="form-control" accept="image/*" required>
<small class="text-muted small">يرجى رفع لقطة حية وواضحة لموقع التوصيلة الفنية.</small>
</div>

<div class="mb-3">
<label class="form-label">قرار تقرير المعاينة والمطابقة الفنية <span class="text-danger">*</span></label>
<select name="inspection_result" class="form-select" required>
<option value="Passed">مطابق ومؤهل - اجتياز الفحص الميداني وتوجيهه للفوترة</option>
<option value="Failed">غير مطابق ومرفوض - لعدم الجاهزية أو تعذر الوصول للموقع</option>
</select>
</div>

<div class="mb-4">
<label class="form-label">ملاحظات الفحص الميداني التفصيلية (تكتب للمشترك في حال الرفض)</label>
<textarea name="inspection_notes" class="form-control" rows="2" placeholder="اكتب أسباب عدم الجاهزية الفنية بالكامل وبشكل مفسر للعميل لكي يقوم بتعديلها..."></textarea>
</div>

<button type="submit" name="submit_inspection" class="btn-brand bg-success py-3"><i class="fa-solid fa-cloud-arrow-up me-2"></i> حفظ وإرسال تقرير المعاينة الميدانية والاعتماد</button>
</form>
</div>
</div>
</div>
<?php endif; ?>
</div>

<!-- صفحة 2: المهام المفحوصة سابقاً -->
<div id="page-completed-tasks" class="page-view">
<div class="admin-card">
<div class="card-title text-success"><i class="fa-solid fa-clock-rotate-left"></i> سجل التقارير الميدانية والمواقع المفحوصة سابقاً</div>
<?php if(empty($completedTasks)): ?>
<div class="text-center py-5"><i class="fa-solid fa-folder-open text-muted fs-1 mb-3"></i><h5 class="fw-bold text-muted">لا توجد أي تقارير فحص مسجلة باسمك بعد</h5></div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover">
<thead>
<tr>
<th>رقم الطلب</th>
<th>اسم المشترك</th>
<th>الخدمة</th>
<th>المدينة</th>
<th>حالة الجاهزية</th>
<th>النتيجة الفنية</th>
<th>الصورة الميدانية</th>
</tr>
</thead>
<tbody>
<?php foreach($completedTasks as $comp):
$resColor = ($comp['inspection_result'] == 'Passed') ? 'success' : 'danger';
$resText = ($comp['inspection_result'] == 'Passed') ? 'مطابق ومؤهل' : 'مرفوض وغير جاهز';
?>
<tr>
<td class="fw-bold text-muted">#<?= str_pad($comp['app_id'], 5, '0', STR_PAD_LEFT); ?></td>
<td class="fw-bold text-dark"><?= htmlspecialchars($comp['customer_name']); ?></td>
<td><span class="badge bg-light text-primary border"><?= htmlspecialchars($comp['srv_name']); ?></span></td>
<td><?= htmlspecialchars($comp['cty_name']); ?></td>
<td class="small text-muted">
البناء: <?= $comp['building_readiness'] ? 'جاهز ✓' : 'لا ✗' ?><br>
النوافذ: <?= $comp['doors_windows_installed'] ? 'مكتمل ✓' : 'لا ✗' ?><br>
الصبغ: <?= $comp['meter_spot_painted'] ? 'محدد ✓' : 'لا ✗' ?>
</td>
<td><span class="badge bg-<?= $resColor ?> fs-7"><?= $resText; ?></span></td>
<td>
<a href="<?= htmlspecialchars($comp['site_photos_url']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill fw-bold"><i class="fa-solid fa-image me-1"></i> عرض الصورة</a>
</td>
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
let currentMap = null;
let currentMarker = null;

function openPage(pageId, element) {
document.querySelectorAll('.page-view').forEach(p => p.classList.remove('active'));
document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
document.getElementById(pageId).classList.add('active');
element.classList.add('active');
document.getElementById('topbar-title').innerText = element.innerText;
if (pageId === 'page-active-tasks') {
setTimeout(() => { if(currentMap) currentMap.invalidateSize(); }, 300);
}
}

function selectTask(task, element) {
document.querySelectorAll('.task-card').forEach(c => c.classList.remove('active-selection'));
element.classList.add('active-selection');

document.getElementById('form_insp_id').value = task.insp_id;
document.getElementById('form_app_id').value = task.app_id;

if (currentMap && task.latitude && task.longitude) {
let coords = [parseFloat(task.latitude), parseFloat(task.longitude)];
currentMap.flyTo(coords, 15, { animate: true, duration: 1.5 });
if (currentMarker) {
currentMarker.setLatLng(coords);
} else {
currentMarker = L.marker(coords).addTo(currentMap);
}
document.getElementById('btnGoogleMap').href = `https://www.google.com/maps/dir/?api=1&destination=${task.latitude},${task.longitude}`;
}
}

document.addEventListener("DOMContentLoaded", function() {
<?php if(!empty($activeTasks)): ?>
let firstTask = <?= json_encode($activeTasks) ?>;
let initialCoords = [parseFloat(firstTask.latitude) || 24.7136, parseFloat(firstTask.longitude) || 46.6753];
currentMap = L.map('taskMap').setView(initialCoords, 14);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
attribution: '© Qatra Smart Systems'
}).addTo(currentMap);
currentMarker = L.marker(initialCoords).addTo(currentMap);
document.getElementById('btnGoogleMap').href = `https://www.google.com/maps/dir/?api=1&destination=${initialCoords},${initialCoords[2]}`;
<?php endif; ?>
});
</script>
</body>
</html>
