<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// التحقق من تسجيل الدخول وصلاحية فني الفحص
if (!isset($_SESSION['emp_id']) || !in_array('Inspection Technician', $_SESSION['emp_roles'])) {
    header("Location: employee_dashboard.php");
    exit;
}

require_once 'db_connect.php';
$msg = ""; $msgType = "";

if (isset($_GET['success_msg'])) {
    $msg = $_GET['success_msg'];
    $msgType = "success";
}

$emp_id = $_SESSION['emp_id'];

// تهيئة الجدول ليقبل عمود الملاحظات إذا لم يكن موجوداً بعد
try {
    $pdo->query("SELECT inspector_notes FROM field_inspection LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE field_inspection ADD COLUMN inspector_notes TEXT NULL");
}

// =========================================================
// معالجة تقديم تقرير الفحص الميداني
// =========================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_inspection'])) {
    $insp_id = $_POST['insp_id'];
    $app_id = $_POST['app_id'];
    $building_readiness = isset($_POST['building_readiness']) ? 1 : 0;
    $doors_windows_installed = isset($_POST['doors_windows_installed']) ? 1 : 0;
    $meter_spot_painted = isset($_POST['meter_spot_painted']) ? 1 : 0;
    $inspection_result = $_POST['inspection_result']; // 'Passed' or 'Failed'

    // الملاحظات حقل اختياري بالكامل - لا داعي للتحقق من وجوده
    $inspector_notes = isset($_POST['inspector_notes']) ? trim($_POST['inspector_notes']) : '';
    $inspector_notes = $inspector_notes !== '' ? $inspector_notes : null;

    $site_photos_url = "";
    
    // معالجة رفع الصورة الميدانية
    if (isset($_FILES['site_photo']) && $_FILES['site_photo']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['site_photo']['tmp_name'];
        $fileName = $_FILES['site_photo']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // تسمية فريدة للصورة لتفادي أي تعارض
        $hashedFileName = md5(time() . $emp_id . $insp_id) . '.' . $fileExtension;
        $targetDir = "uploads/";
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }
        $targetFilePath = $targetDir . $hashedFileName;
        
        if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
            $site_photos_url = $targetFilePath;
        } else {
            $msg = "فشل في حفظ الصورة الميدانية بالسيرفر.";
            $msgType = "error";
        }
    } else {
        $msg = "إرفاق الصورة الميدانية إلزامي لتوثيق الفحص.";
        $msgType = "error";
    }

    if (empty($msg) && !empty($site_photos_url)) {
        try {
            $pdo->beginTransaction();

            // 1. تحديث سجل الفحص الميداني (بما في ذلك الملاحظات الاختيارية)
            $stmtUpdate = $pdo->prepare("
                UPDATE field_inspection 
                SET building_readiness = ?, doors_windows_installed = ?, meter_spot_painted = ?, 
                    site_photos_url = ?, inspection_result = ?, inspector_notes = ? 
                WHERE insp_id = ?
            ");
            $stmtUpdate->execute([$building_readiness, $doors_windows_installed, $meter_spot_painted, $site_photos_url, $inspection_result, $inspector_notes, $insp_id]);

            // 2. تحديث الطلب بناءً على النتيجة
            if ($inspection_result == 'Passed') {
                // جلب رقم الصك لتحديد مساحة العقار وحساب الفاتورة
                $stmtApp = $pdo->prepare("SELECT deed_no FROM application WHERE app_id = ?");
                $stmtApp->execute([$app_id]);
                $deed_no = $stmtApp->fetchColumn();

                // جلب المساحة من وزارة العدل
                $stmtMoj = $pdo->prepare("SELECT land_area FROM moj_record WHERE deed_no = ?");
                $stmtMoj->execute([$deed_no]);
                $land_area = $stmtMoj->fetchColumn();

                if (!$land_area) {
                    $land_area = 500; // قيمة افتراضية احتياطية في حال عدم المطابقة
                }

                // حساب تسعيرة الخدمة حسب المساحة آلياً
                // مساحة <= 675 -> 3450 ريال
                // مساحة أكبر من 675 -> المساحة × 10 ريال
                if ($land_area <= 675) {
                    $amount = 3450;
                } else {
                    $amount = $land_area * 10;
                }

                // إنشاء الفاتورة غير مدفوعة
                $stmtInvoice = $pdo->prepare("
                    INSERT INTO invoice (amount, payment_status, app_id) 
                    VALUES (?, 'Unpaid', ?)
                    ON DUPLICATE KEY UPDATE amount = ?, payment_status = 'Unpaid'
                ");
                $stmtInvoice->execute([$amount, $app_id, $amount]);

                // تحديث حالة الطلب لانتظار السداد
                $stmtAppUpdate = $pdo->prepare("UPDATE application SET app_status = 'Pending_Billing' WHERE app_id = ?");
                $stmtAppUpdate->execute([$app_id]);

                // تسجيل الحركات في الأرشيف والتاريخ
                $stmtHist = $pdo->prepare("INSERT INTO application_history (app_id, status, changed_by, change_date) VALUES (?, 'Pending_Billing', ?, NOW())");
                $stmtHist->execute([$app_id, $emp_id]);

            } else {
                // في حال رفض الموقع، يتم رفض الطلب نهائياً بالأرشيف الأمني وتوثيق السبب
                $stmtAppUpdate = $pdo->prepare("UPDATE application SET app_status = 'Rejected' WHERE app_id = ?");
                $stmtAppUpdate->execute([$app_id]);

                $stmtHist = $pdo->prepare("
                    INSERT INTO application_history (app_id, status, rejection_reason, changed_by, change_date) 
                    VALUES (?, 'Rejected', 'فشل في الفحص الميداني: عدم مطابقة الموقع لاشتراطات البلدية أو عدم جاهزية البناء', ?, NOW())
                ");
                $stmtHist->execute([$app_id, $emp_id]);
            }

            // 3. تخفيض عدد المهام النشطة للفني
            $stmtEmpUpdate = $pdo->prepare("UPDATE company_employee SET active_tasks_count = GREATEST(0, active_tasks_count - 1) WHERE emp_id = ?");
            $stmtEmpUpdate->execute([$emp_id]);

            $pdo->commit();
            $msg = "تم تقديم تقرير الفحص بنجاح ونقل الطلب للمرحلة التالية.";
            header("Location: inspection_panel.php?success_msg=" . urlencode($msg));
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "حدث خطأ أثناء حفظ التقرير: " . $e->getMessage();
            $msgType = "error";
        }
    }
}

// =========================================================
// جلب البيانات (المهام النشطة والمهام المكتملة للتاريخ)
// =========================================================
// 1. الموظف الحالي وتفاصيله
$stmtEmp = $pdo->prepare("SELECT ce.*, c.cty_name FROM company_employee ce JOIN city c ON ce.cty_id = c.cty_id WHERE ce.emp_id = ?");
$stmtEmp->execute([$emp_id]);
$currentEmp = $stmtEmp->fetch();

// 2. المهام النشطة (inspection_result is NULL)
$stmtActive = $pdo->prepare("
    SELECT fi.insp_id, fi.app_id, a.deed_no, a.latitude, a.longitude, a.created_at, 
           c.full_name AS customer_name, c.phone_number AS customer_phone, 
           cty.cty_name, s.srv_name
    FROM field_inspection fi
    JOIN application a ON fi.app_id = a.app_id
    JOIN customer c ON a.cust_id = c.cust_id
    JOIN city cty ON a.cty_id = cty.cty_id
    JOIN service_type s ON a.srv_id = s.srv_id
    WHERE fi.emp_id = ? AND fi.inspection_result IS NULL
    ORDER BY a.created_at ASC
");
$stmtActive->execute([$emp_id]);
$activeTasks = $stmtActive->fetchAll(PDO::FETCH_ASSOC);

// 3. المهام المكتملة سابقاً (التاريخ)
$stmtHistory = $pdo->prepare("
    SELECT fi.insp_id, fi.app_id, fi.building_readiness, fi.doors_windows_installed, 
           fi.meter_spot_painted, fi.site_photos_url, fi.inspection_result,
           a.deed_no, cty.cty_name, s.srv_name, c.full_name AS customer_name
    FROM field_inspection fi
    JOIN application a ON fi.app_id = a.app_id
    JOIN customer c ON a.cust_id = c.cust_id
    JOIN city cty ON a.cty_id = cty.cty_id
    JOIN service_type s ON a.srv_id = s.srv_id
    WHERE fi.emp_id = ? AND fi.inspection_result IS NOT NULL
    ORDER BY fi.insp_id DESC
");
$stmtHistory->execute([$emp_id]);
$completedTasks = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة فني الفحص الميداني | قطرة</title>
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
        .content-area { flex: 1; display: flex; overflow: hidden; background: var(--bg); }
        
        /* شاشة العمل المنقسمة لمهام الفني */
        .task-list-column { width: 380px; background: white; border-left: 1px solid #e2e8f0; display: flex; flex-direction: column; overflow-y: auto; }
        .workspace-column { flex: 1; display: flex; flex-direction: column; overflow-y: auto; padding: 30px; }
        
        .task-card { padding: 20px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: 0.2s; position: relative; }
        .task-card:hover { background: #f8fafc; }
        .task-card.active { background: #eaf3fb; border-right: 4px solid var(--light); }
        
        .premium-card { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); margin-bottom: 25px; border: 1px solid #e2e8f0; }
        .card-title { color: var(--navy); font-weight: 900; font-size: 1.2rem; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; }
        
        .map-container { border: 2px solid #e2e8f0; border-radius: 14px; overflow: hidden; height: 260px; margin-bottom: 20px; position: relative; }
        
        .requirement-row { display: flex; align-items: center; justify-content: space-between; padding: 15px; background: #f8fafc; border-radius: 12px; margin-bottom: 12px; border: 1px solid #e2e8f0; }
        .requirement-label { font-weight: 700; color: #334155; }
        
        .upload-area { border: 2px dashed #cbd5e1; border-radius: 14px; padding: 30px; text-align: center; cursor: pointer; transition: 0.3s; background: #f8fafc; }
        .upload-area:hover { border-color: var(--light); background: #f0f7ff; }

        .notes-area { border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px 18px; background: #f8fafc; }
        .notes-area textarea { border: 1px solid #cbd5e1; border-radius: 10px; resize: vertical; }
        .notes-area textarea:focus { border-color: var(--light); box-shadow: 0 0 0 3px rgba(68,146,212,0.15); outline: none; }
        
        .btn-brand { background: var(--blue); color: white; border: none; padding: 14px 24px; border-radius: 10px; font-weight: 800; transition: 0.3s; }
        .btn-brand:hover { background: var(--navy); }
        
        .page-view { display: none; animation: fadeIn 0.4s; height: 100%; width: 100%; }
        .page-view.active { display: flex; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* تجميل شاشة الرفع وصور المعاينة */
        #photo-preview { max-height: 180px; border-radius: 10px; margin-top: 15px; display: none; }
    </style>
</head>
<body>

    <!-- القائمة الجانبية الفاخرة -->
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fa-solid fa-helmet-safety"></i>
            <h4 class="fw-black m-0">الفحص الميداني</h4>
            <div class="small mt-1 text-info">قطرة للميدان والتشغيل</div>
        </div>
        <div class="sidebar-nav">
            <a class="nav-item active" onclick="openPage('page-tasks-view', this)"><i class="fa-solid fa-list-check"></i> المهام الميدانية المعلقة</a>
            <a class="nav-item" onclick="openPage('page-history-view', this)"><i class="fa-solid fa-history"></i> سجل مهامي المكتملة</a>
        </div>
        <div class="p-3 border-top border-secondary">
            <a href="employee_dashboard.php" class="btn btn-outline-light w-100 fw-bold rounded-pill"><i class="fa-solid fa-arrow-right-from-bracket fa-flip-horizontal me-2"></i> شاشة التوجيه</a>
        </div>
    </div>

    <!-- المحتوى الرئيسي للمشغل -->
    <div class="main-wrapper">
        <div class="topbar">
            <div><h4 class="fw-black text-dark m-0" id="topbar-title">المهام الميدانية النشطة</h4></div>
            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold text-secondary">الفني: <?= htmlspecialchars($currentEmp['emp_name']); ?> (<?= htmlspecialchars($currentEmp['cty_name']); ?>)</span>
                <span class="badge bg-danger rounded-pill fw-bold py-2 px-3"><?= count($activeTasks); ?> مهام معلقة</span>
            </div>
        </div>

        <div class="content-area">
            <?php if ($msg): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({ icon: '<?= $msgType ?>', title: 'إشعار الميدان', text: '<?= $msg ?>', confirmButtonColor: '#0b457f' });
                    });
                </script>
            <?php endif; ?>

            <!-- صفحة المهام النشطة مع واجهة SPA الفاخرة -->
            <div id="page-tasks-view" class="page-view active">
                
                <!-- عمود قائمة المهام الجانبية -->
                <div class="task-list-column">
                    <div class="p-3 bg-light border-bottom fw-bold text-secondary text-center">المهام المعلقة في مدينتك</div>
                    <?php if (empty($activeTasks)): ?>
                        <div class="text-center py-5 px-3">
                            <i class="fa-solid fa-circle-check text-success fs-1 mb-3"></i>
                            <h6 class="fw-bold text-success">مكتمل! لا توجد مهام نشطة حالياً</h6>
                            <p class="text-muted small">سيقوم محرك التوزيع الذكي بإشعارك فور تعيين أي مهام جديدة.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($activeTasks as $task): ?>
                            <div class="task-card" id="task-card-<?= $task['insp_id'] ?>" onclick='selectTask(<?= json_encode($task, JSON_UNESCAPED_UNICODE) ?>)'>
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="badge bg-primary">طلب #<?= str_pad($task['app_id'], 5, '0', STR_PAD_LEFT) ?></span>
                                    <span class="small text-muted fw-bold"><?= date('m/d H:i', strtotime($task['created_at'])) ?></span>
                                </div>
                                <h6 class="fw-black text-dark mb-1"><?= htmlspecialchars($task['customer_name']) ?></h6>
                                <p class="text-muted small mb-2"><i class="fa-solid fa-file-invoice text-primary"></i> صك: <?= htmlspecialchars($task['deed_no']) ?></p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-light text-dark border"><i class="fa-solid fa-droplet text-info me-1"></i> <?= htmlspecialchars($task['srv_name']) ?></span>
                                    <span class="small text-danger fw-bold"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($task['cty_name']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- مساحة عمل إعداد الفحص ورفع التقارير -->
                <div class="workspace-column" id="workspace-column">
                    <div id="no-task-selected" class="text-center my-auto py-5">
                        <i class="fa-solid fa-clipboard-check fs-1 text-muted opacity-50 mb-3" style="font-size: 5rem !important;"></i>
                        <h4 class="fw-bold text-secondary">يرجى تحديد مهمة من القائمة للبدء</h4>
                        <p class="text-muted max-w-sm mx-auto">اختر العقار من القائمة اليمنى لرؤية الخريطة والإحداثيات والبدء في رفع تقرير الجاهزية.</p>
                    </div>

                    <div id="task-workspace" style="display: none;">
                        <div class="row g-4">
                            <!-- الجزء الأيمن: الخريطة ومعلومات الموقع العقاري -->
                            <div class="col-lg-6">
                                <div class="premium-card h-100">
                                    <div class="card-title"><i class="fa-solid fa-map-location-dot text-primary"></i> موقع العقار والاتجاهات</div>
                                    <div class="map-container" id="propertyMap"></div>
                                    
                                    <div class="d-grid gap-2">
                                        <a href="#" id="google-maps-btn" target="_blank" class="btn btn-outline-primary fw-bold rounded-3">
                                            <i class="fa-solid fa-compass me-2"></i> فتح اتجاهات الملاحة (Google Maps)
                                        </a>
                                    </div>
                                    
                                    <div class="mt-4 p-3 bg-light rounded-3 border">
                                        <h6 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-circle-info text-info me-1"></i> نصائح فني قطرة الذكي:</h6>
                                        <p class="small text-muted m-0">تأكد من مطابقة إحداثيات GPS المعروضة على الخريطة مع مكان وقوفك الفعلي أمام العقار لمنع أي غش أو تلاعب.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- الجزء الأيسر: نموذج التحقق والتقرير الفني -->
                            <div class="col-lg-6">
                                <div class="premium-card">
                                    <div class="card-title text-success"><i class="fa-solid fa-square-poll-horizontal"></i> استمارة الجاهزية والرفع الميداني</div>
                                    
                                    <form method="POST" enctype="multipart/form-data" id="inspectionForm">
                                        <input type="hidden" name="submit_inspection" value="1">
                                        <input type="hidden" name="insp_id" id="form-insp-id">
                                        <input type="hidden" name="app_id" id="form-app-id">
                                        <input type="hidden" name="inspection_result" id="form-result" value="Passed">

                                        <!-- بند جاهزية البناء -->
                                        <div class="requirement-row">
                                            <div class="d-flex align-items-center gap-3">
                                                <i class="fa-solid fa-building text-primary fs-4"></i>
                                                <div>
                                                    <span class="requirement-label d-block">اكتمال البناء وجاهزية الهيكل</span>
                                                    <small class="text-muted">هل الهيكل الإنشائي للمبنى كامل وجاهز؟</small>
                                                </div>
                                            </div>
                                            <div class="form-check form-switch fs-4">
                                                <input class="form-check-input" type="checkbox" name="building_readiness" role="switch" checked>
                                            </div>
                                        </div>

                                        <!-- بند تركيب الأبواب والشبابيك -->
                                        <div class="requirement-row">
                                            <div class="d-flex align-items-center gap-3">
                                                <i class="fa-solid fa-door-closed text-primary fs-4"></i>
                                                <div>
                                                    <span class="requirement-label d-block">تركيب الأبواب والنوافذ الخارجية</span>
                                                    <small class="text-muted">هل العقار مغلق ومؤمن بالأبواب والنوافذ؟</small>
                                                </div>
                                            </div>
                                            <div class="form-check form-switch fs-4">
                                                <input class="form-check-input" type="checkbox" name="doors_windows_installed" role="switch" checked>
                                            </div>
                                        </div>

                                        <!-- بند طلاء موقع العداد -->
                                        <div class="requirement-row">
                                            <div class="d-flex align-items-center gap-3">
                                                <i class="fa-solid fa-paint-roller text-primary fs-4"></i>
                                                <div>
                                                    <span class="requirement-label d-block">تجهيز وطلاء موقع العداد</span>
                                                    <small class="text-muted">هل تم تخصيص وتلوين صندوق العداد بوضوح؟</small>
                                                </div>
                                            </div>
                                            <div class="form-check form-switch fs-4">
                                                <input class="form-check-input" type="checkbox" name="meter_spot_painted" role="switch" checked>
                                            </div>
                                        </div>

                                        <!-- إرفاق الصورة الميدانية لإثبات الفحص -->
                                        <div class="mb-4">
                                            <label class="fw-bold text-dark mb-2"><i class="fa-solid fa-camera text-primary me-1"></i> إرفاق صورة حية لإثبات الفحص الميداني <span class="text-danger">*</span></label>
                                            <div class="upload-area" onclick="document.getElementById('site-photo-file').click()">
                                                <i class="fa-solid fa-cloud-arrow-up fs-2 text-primary mb-2"></i>
                                                <h6 class="fw-bold text-dark m-0" id="upload-label">انقر لالتقاط أو رفع صورة الموقع العقاري</h6>
                                                <small class="text-muted">صورة حية توضح واجهة العقار وصندوق العداد المخصص</small>
                                                <input type="file" name="site_photo" id="site-photo-file" class="d-none" accept="image/*" onchange="previewImage(this)" required>
                                                <img id="photo-preview" class="img-fluid" alt="معاينة الصورة">
                                            </div>
                                        </div>

                                        <!-- ملاحظات الفني - حقل اختياري بالكامل -->
                                        <div class="mb-4">
                                            <label class="fw-bold text-dark mb-2" for="inspector_notes">
                                                <i class="fa-solid fa-note-sticky text-primary me-1"></i> ملاحظات إضافية
                                                <span class="badge bg-light text-muted border fw-normal ms-1">اختياري</span>
                                            </label>
                                            <div class="notes-area">
                                                <textarea class="form-control" name="inspector_notes" id="inspector_notes" rows="3" placeholder="أضف أي ملاحظات ميدانية إضافية إن وجدت (غير إلزامي)..."></textarea>
                                            </div>
                                        </div>

                                        <!-- قرار الاعتماد الميداني النهائي -->
                                        <div class="mb-4 text-center">
                                            <label class="fw-black text-dark mb-3 d-block"><i class="fa-solid fa-circle-question text-info me-1"></i> قرار الاعتماد الفني النهائي ومطابقة المعايير:</label>
                                            <div class="btn-group w-100" role="group">
                                                <button type="button" class="btn btn-outline-success fw-black py-3 active w-50" id="btn-pass" onclick="setResult('Passed')">
                                                    <i class="fa-solid fa-circle-check me-1"></i> جاهز ومطابق (تمرير الطلب)
                                                </button>
                                                <button type="button" class="btn btn-outline-danger fw-black py-3 w-50" id="btn-fail" onclick="setResult('Failed')">
                                                    <i class="fa-solid fa-circle-xmark me-1"></i> غير مطابق (رفض الطلب)
                                                </button>
                                            </div>
                                        </div>

                                        <button type="submit" name="submit_inspection" class="btn-brand w-100 py-3 rounded-3 shadow-sm" onclick="confirmInspection(event)">
                                            <i class="fa-solid fa-paper-plane me-1"></i> إرسال التقرير واعتماد القرار للخدمة
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- صفحة سجل المهام المكتملة للتاريخ -->
            <div id="page-history-view" class="page-view">
                <div class="workspace-column w-100 h-100">
                    <div class="admin-card w-100">
                        <div class="card-title text-success"><i class="fa-solid fa-history bg-success text-white rounded p-2"></i> أرشيف وسجل المهام المنجزة للموظف</div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>رقم الطلب</th>
                                        <th>العميل</th>
                                        <th>نوع الخدمة</th>
                                        <th>معايير الجاهزية الميدانية المسجلة</th>
                                        <th>إثبات الفحص</th>
                                        <th>القرار الفني النهائي</th>
                                        <th>التقرير الكامل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($completedTasks)): ?>
                                        <tr><td colspan="7" class="text-center py-5 text-muted fw-bold">لم تقم بإتمام أي فحص ميداني بعد.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($completedTasks as $history): ?>
                                            <tr>
                                                <td class="fw-bold text-muted">#<?= str_pad($history['app_id'], 5, '0', STR_PAD_LEFT); ?></td>
                                                <td class="fw-bold text-dark"><?= htmlspecialchars($history['customer_name']); ?></td>
                                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($history['srv_name']); ?> (<?= htmlspecialchars($history['cty_name']); ?>)</span></td>
                                                <td>
                                                    <div class="small fw-bold text-secondary">
                                                        بناء جاهز: <?= $history['building_readiness'] ? "✅" : "❌" ?> | 
                                                        أبواب ونوافذ: <?= $history['doors_windows_installed'] ? "✅" : "❌" ?> | 
                                                        طلاء الصندوق: <?= $history['meter_spot_painted'] ? "✅" : "❌" ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-info rounded-pill fw-bold" onclick="viewPhoto('<?= htmlspecialchars($history['site_photos_url']); ?>')">
                                                        <i class="fa-solid fa-image me-1"></i> عرض الصورة
                                                    </button>
                                                </td>
                                                <td>
                                                    <?= $history['inspection_result'] == 'Passed' ? 
                                                        '<span class="badge bg-success rounded-pill px-3 py-2"><i class="fa-solid fa-circle-check"></i> جاهز ومطابق</span>' : 
                                                        '<span class="badge bg-danger rounded-pill px-3 py-2"><i class="fa-solid fa-circle-xmark"></i> غير مطابق ومرفوض</span>' 
                                                    ?>
                                                </td>
                                                <td>
                                                    <a href="inspection_report.php?insp_id=<?= (int)$history['insp_id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill fw-bold">
                                                        <i class="fa-solid fa-file-lines me-1"></i> فتح التقرير
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- برمجة التفاعلات الذكية والخريطة الجغرافية -->
    <script>
        let map;
        let marker;

        // إعداد خريطة Leaflet الافتراضية
        function initMap(lat, lng) {
            if (!map) {
                map = L.map('propertyMap').setView([lat, lng], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© Qatra Smart Systems'
                }).addTo(map);
                marker = L.marker([lat, lng]).addTo(map);
            } else {
                map.setView([lat, lng], 15);
                marker.setLatLng([lat, lng]);
            }
            setTimeout(() => { map.invalidateSize(); }, 300);
        }

        // تحديد واختيار مهمة معينة وعرض تفاصيلها آلياً
        function selectTask(task) {
            document.querySelectorAll('.task-card').forEach(c => c.classList.remove('active'));
            document.getElementById('task-card-' + task.insp_id).classList.add('active');
            
            document.getElementById('no-task-selected').style.display = 'none';
            document.getElementById('task-workspace').style.display = 'block';
            
            // تعبئة بيانات استمارة التقرير
            document.getElementById('form-insp-id').value = task.insp_id;
            document.getElementById('form-app-id').value = task.app_id;
            
            // إعداد رابط الملاحة والخرائط
            const mapUrl = `https://www.google.com/maps/dir/?api=1&destination=${task.latitude},${task.longitude}`;
            document.getElementById('google-maps-btn').href = mapUrl;

            // تهيئة الخريطة بـ الإحداثيات الفعلية للطلب المعين
            initMap(task.latitude, task.longitude);
        }

        // معاينة الصورة المرفوعة من قبل الفني بشكل فوري ومباشر
        function previewImage(input) {
            const preview = document.getElementById('photo-preview');
            const label = document.getElementById('upload-label');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'inline-block';
                    label.innerText = "تم التقاط الصورة بنجاح: " + input.files[0].name;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // تحديد نتيجة الفحص النهائي
        function setResult(res) {
            document.getElementById('form-result').value = res;
            if (res === 'Passed') {
                document.getElementById('btn-pass').classList.add('active');
                document.getElementById('btn-fail').classList.remove('active');
            } else {
                document.getElementById('btn-fail').classList.add('active');
                document.getElementById('btn-pass').classList.remove('active');
            }
        }

        // تأكيد إرسال التقرير بـ SweetAlert
        function confirmInspection(e) {
            e.preventDefault();
            const res = document.getElementById('form-result').value;
            const titleText = (res === 'Passed') ? "تأكيد جاهزية وتمرير الطلب؟" : "تأكيد رفض وتجميد الطلب الميداني؟";
            const bodyText = (res === 'Passed') ? 
                "بموافقتك سيتم حساب الفاتورة آلياً وإرسال إشعار السداد للعميل." : 
                "بموافقتك سيتم تسجيل الرفض وحفظ الأسباب بالأرشيف الأمني.";

            Swal.fire({
                title: titleText,
                text: bodyText,
                icon: (res === 'Passed') ? 'question' : 'warning',
                showCancelButton: true,
                confirmButtonColor: (res === 'Passed') ? '#198754' : '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'تأكيد وإرسال التقرير',
                cancelButtonText: 'تراجع'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('inspectionForm').submit();
                }
            });
        }

        // عرض صورة الفحص المنجز في سجل التاريخ
        function viewPhoto(url) {
            Swal.fire({
                title: 'إثبات الفحص الميداني الموثق',
                imageUrl: url,
                imageAlt: 'صورة الفحص الميداني',
                confirmButtonColor: '#0b457f',
                confirmButtonText: 'إغلاق'
            });
        }

        // التنقل بين صفحات الواجهة
        function openPage(pageId, element) {
            document.querySelectorAll('.page-view').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            document.getElementById(pageId).classList.add('active');
            element.classList.add('active');
            document.getElementById('topbar-title').innerText = element.innerText;
        }
    </script>
</body>
</html>