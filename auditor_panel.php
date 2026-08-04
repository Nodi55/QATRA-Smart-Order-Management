<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// التحقق من تسجيل الدخول وصلاحية المدقق (Auditor)
if (!isset($_SESSION['emp_id']) || (!in_array('Auditor', $_SESSION['emp_roles']) && !in_array('Admin', $_SESSION['emp_roles']))) {
    header("Location: employee_login.php");
    exit;
}

require_once 'db_connect.php';
$msg = ""; $msgType = "";
$empId = $_SESSION['emp_id'];

// =========================================================
// معالجة القرارات (اعتماد / رفض الطلب)
// =========================================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. قرار اعتماد وقبول الطلب
    if (isset($_POST['approve_app'])) {
        $appId = $_POST['app_id'];
        $cityId = $_POST['cty_id'];
        
        try {
            $pdo->beginTransaction();

            // أ. تحديث حالة الطلب إلى "بانتظار الفحص الميداني"
            $pdo->prepare("UPDATE application SET app_status = 'Pending_Inspection' WHERE app_id = ?")->execute([$appId]);

            // ب. تسجيل السجل التاريخي للطلب في Application_History
            $pdo->prepare("INSERT INTO application_history (app_id, status, changed_by, change_date) VALUES (?, 'Pending_Inspection', ?, NOW())")
                ->execute([$appId, $empId]);

            // جـ. خوارزمية التوزيع الجغرافي والإقليمي الذكي (أقرب فني فحص ميداني وأقل ضغط مهام)
            $bestTechStmt = $pdo->prepare("
                SELECT ce.emp_id, ce.cty_id, c.cty_name 
                FROM company_employee ce
                JOIN employee_roles er ON ce.emp_id = er.emp_id 
                JOIN system_role sr ON er.role_id = sr.role_id
                JOIN city c ON ce.cty_id = c.cty_id
                WHERE ce.is_active = 1 AND sr.role_name = 'Inspection Technician' 
                AND c.reg_id = (SELECT reg_id FROM city WHERE cty_id = ?)
                ORDER BY (ce.cty_id = ?) DESC, ce.active_tasks_count ASC LIMIT 1
            ");
            $bestTechStmt->execute([$cityId, $cityId]);
            $assigned = $bestTechStmt->fetch();

            if ($assigned) {
                // إدراج سجل الفحص الميداني وإسناده للفني المختار
                $pdo->prepare("INSERT INTO field_inspection (app_id, emp_id) VALUES (?, ?)")->execute([$appId, $assigned['emp_id']]);
                // زيادة عداد مهام الفني النشطة بمقدار 1
                $pdo->prepare("UPDATE company_employee SET active_tasks_count = active_tasks_count + 1 WHERE emp_id = ?")->execute([$assigned['emp_id']]);
                
                $locationNote = ($assigned['cty_id'] == $cityId) ? "في نفس مدينة العقار" : "في مدينة مجاورة (".$assigned['cty_name'].")";
                $notifMsg = "تهانينا! تمت مراجعة صك عقارك يدوياً وقبوله بنجاح. تم توجيه الطلب إلى فني الفحص الميداني المتاح " . $locationNote . ".";
                $msg = "تمت الموافقة على الطلب وإسناده آلياً بنجاح لفني الفحص الميداني."; $msgType = "success";
            } else {
                // إذا لم يوجد أي فني في المنطقة، يترك الطلب معلقاً للمدير ليتدخل يدوياً
                $notifMsg = "تم قبول صك عقارك يدوياً، وجاري جدولة فني الفحص الميداني للزيارة قريباً.";
                $msg = "تمت الموافقة يدوياً، ولكن لم يعثر النظام على أي فني فحص متاح في كامل المنطقة. تم تسجيل الطلب كـ (غير مسند) لينبه الإدارة."; $msgType = "warning";
            }

            // د. إرسال الإشعار للمستفيد
            $stmtCust = $pdo->prepare("SELECT cust_id FROM application WHERE app_id = ?");
            $stmtCust->execute([$appId]);
            $custId = $stmtCust->fetchColumn();
            if ($custId) {
                $pdo->prepare("INSERT INTO notification (message_content, cust_id) VALUES (?, ?)")->execute([$notifMsg, $custId]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "فشلت العملية: " . $e->getMessage(); $msgType = "danger";
        }
    }

    // 2. قرار رفض الطلب
    if (isset($_POST['reject_app'])) {
        $appId = $_POST['app_id'];
        $reason = trim($_POST['rejection_reason']);

        if (empty($reason)) {
            $msg = "خطأ: يجب تحديد وكتابة سبب الرفض بالتفصيل."; $msgType = "danger";
        } else {
            try {
                $pdo->beginTransaction();

                // أ. تحديث حالة الطلب إلى "مرفوض"
                $pdo->prepare("UPDATE application SET app_status = 'Rejected' WHERE app_id = ?")->execute([$appId]);

                // ب. تسجيل سبب الرفض وهوية المدقق في سجل التطوير
                $pdo->prepare("INSERT INTO application_history (app_id, status, rejection_reason, changed_by, change_date) VALUES (?, 'Rejected', ?, ?, NOW())")
                    ->execute([$appId, $reason, $empId]);

                // جـ. إرسال إشعار للمستفيد يفيد برفض الطلب مع السبب لكي يعدل بياناته
                $stmtCust = $pdo->prepare("SELECT cust_id FROM application WHERE app_id = ?");
                $stmtCust->execute([$appId]);
                $custId = $stmtCust->fetchColumn();
                if ($custId) {
                    $notifMsg = "عفواً، تم رفض طلبك رقم # " . str_pad($appId, 5, '0', STR_PAD_LEFT) . " بعد التدقيق والمراجعة اليدوية للسبب التالي: (" . $reason . "). يمكنك مراجعة المستندات وإعادة التقديم.";
                    $pdo->prepare("INSERT INTO notification (message_content, cust_id) VALUES (?, ?)")->execute([$notifMsg, $custId]);
                }

                $pdo->commit();
                $msg = "تم تسجيل رفض الطلب بنجاح وإرسال سبب الرفض للمستفيد."; $msgType = "success";
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg = "فشلت العملية: " . $e->getMessage(); $msgType = "danger";
            }
        }
    }
}

// =========================================================
// جلب البيانات اللازمة للوحة التدقيق اليدوي
// =========================================================

// 1. الطلبات المعلقة التي تحتاج مراجعة وتدقيق يدوي (Pending_Review)
// نقوم بعمل Left Join مع moj_record لعرض تفاصيل الصك المسجل بوزارة العدل ومقارنتها مباشرة ببيانات المستفيد المدخلة لتسهيل التدقيق وكشف التلاعب!
$pendingApps = $pdo->query("
    SELECT a.app_id, a.deed_no, a.deed_file_url, a.created_at, a.latitude, a.longitude, a.cty_id,
           c.full_name as cust_name, c.national_id as cust_nat_id, c.phone_number as cust_phone,
           cty.cty_name, r.reg_name,
           s.srv_name,
           moj.owner_name as moj_owner_name, moj.owner_national_id as moj_owner_id, moj.land_area as moj_land_area
    FROM application a
    JOIN customer c ON a.cust_id = c.cust_id
    JOIN city cty ON a.cty_id = cty.cty_id
    JOIN region r ON cty.reg_id = r.reg_id
    JOIN service_type s ON a.srv_id = s.srv_id
    LEFT JOIN moj_record moj ON a.deed_no = moj.deed_no
    WHERE a.app_status = 'Pending_Review'
    ORDER BY a.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 2. سجل المعاملات السابقة التي تم تدقيقها بواسطة هذا الموظف لضمان الشفافية والرقابة
$auditedHistory = $pdo->prepare("
    SELECT a.app_id, a.deed_no, a.app_status, ah.change_date, ah.status as decision, ah.rejection_reason,
           c.full_name as cust_name, cty.cty_name, s.srv_name
    FROM application_history ah
    JOIN application a ON ah.app_id = a.app_id
    JOIN customer c ON a.cust_id = c.cust_id
    JOIN city cty ON a.cty_id = cty.cty_id
    JOIN service_type s ON a.srv_id = s.srv_id
    WHERE ah.changed_by = ?
    ORDER BY ah.change_date DESC LIMIT 50
");
$auditedHistory->execute([$empId]);
$historyList = $auditedHistory->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة المدقق اليدوي | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Leaflet الخريطة التفاعلية -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --navy: #092e54; --blue: #0b457f; --light: #4492d4; --bg: #f8fafc; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg); margin: 0; padding: 0; display: flex; height: 100vh; overflow: hidden; }
        
        /* شريط الملاحة الجانبي للشركة */
        .sidebar { width: 280px; background: var(--navy); color: white; display: flex; flex-direction: column; box-shadow: -4px 0 15px rgba(0,0,0,0.1); z-index: 100; }
        .sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header i { font-size: 2.5rem; color: #7dd3fc; margin-bottom: 10px; }
        .sidebar-nav { flex: 1; padding: 20px 0; overflow-y: auto; }
        .nav-item { padding: 15px 25px; color: #cbd5e1; display: flex; align-items: center; gap: 15px; text-decoration: none; font-weight: 700; transition: 0.3s; cursor: pointer; border-right: 4px solid transparent; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.05); color: white; border-right-color: #7dd3fc; }
        
        .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { background: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); z-index: 50; }
        .content-area { flex: 1; padding: 30px; overflow-y: auto; background: var(--bg); }
        
        /* تصميم البطاقات المطور والمطابق للهوية */
        .admin-card { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid #e2e8f0; position: relative; }
        .card-title { color: var(--navy); font-weight: 900; font-size: 1.2rem; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; }
        
        .table th { background: #f8fafc; color: #64748b; font-weight: 800; padding: 15px; }
        .table td { padding: 15px; vertical-align: middle; font-weight: 700; color: #334155; }
        
        .page-view { display: none; animation: fadeIn 0.4s; }
        .page-view.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* تباين مميز لعناصر تدقيق الصكوك */
        .comparison-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; margin-bottom: 10px; }
        .comparison-box.match { border-right: 5px solid #10b981; }
        .comparison-box.mismatch { border-right: 5px solid #ef4444; }
    </style>
</head>
<body>

    <!-- القائمة الجانبية للنظام -->
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fa-solid fa-droplet"></i>
            <h4 class="fw-black m-0">نظام قطرة</h4>
            <div class="small mt-1 text-info">بوابة التدقيق والمراجعة اليدوية</div>
        </div>
        <div class="sidebar-nav">
            <a class="nav-item active" onclick="openPage('page-queue', this)">
                <i class="fa-solid fa-clipboard-list"></i> قائمة طلبات المراجعة
                <?php if(count($pendingApps) > 0): ?><span class="badge bg-danger ms-auto"><?= count($pendingApps); ?></span><?php endif; ?>
            </a>
            <a class="nav-item" onclick="openPage('page-history', this)"><i class="fa-solid fa-clock-rotate-left"></i> سجل معاملاتي السابقة</a>
        </div>
        <div class="p-3 border-top border-secondary">
            <a href="employee_dashboard.php" class="btn btn-outline-light w-100 fw-bold rounded-pill"><i class="fa-solid fa-arrow-right-from-bracket fa-flip-horizontal me-2"></i> شاشة التوجيه</a>
        </div>
    </div>

    <div class="main-wrapper">
        <!-- الشريط العلوي -->
        <div class="topbar">
            <div><h4 class="fw-black text-dark m-0" id="topbar-title">قائمة طلبات المراجعة المعلقة</h4></div>
            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold text-secondary">مرحباً، أ. <?= htmlspecialchars($_SESSION['emp_name']); ?></span>
                <div class="bg-primary text-white rounded-circle d-flex justify-content-center align-items-center" style="width: 40px; height: 40px;"><i class="fa-solid fa-user-check"></i></div>
            </div>
        </div>

        <div class="content-area">
            <?php if($msg): ?>
                <div class="alert alert-<?= $msgType ?> fw-bold alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-info me-2"></i> <?= $msg ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- الصفحة 1: قائمة الانتظار للمدقق -->
            <div id="page-queue" class="page-view active">
                <div class="admin-card">
                    <div class="card-title text-primary"><i class="fa-solid fa-shield-halved bg-primary text-white rounded p-2"></i> طلبات بانتظار المطابقة الأمنية (Pending Review)</div>
                    
                    <?php if(empty($pendingApps)): ?>
                        <div class="text-center py-5">
                            <i class="fa-regular fa-circle-check text-success fs-1 mb-3"></i>
                            <h4 class="fw-bold text-success">رائع! قائمة المعاملات فارغة بالكامل</h4>
                            <p class="text-muted fw-bold mb-0">جميع الطلبات تم معالجتها ومطابقتها آلياً بنجاح عبر محرك الـ DSS.</p>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning fw-bold mb-4">
                            <i class="fa-solid fa-circle-exclamation me-2"></i> تم توجيه هذه الطلبات إليك يدوياً لأن رقم الهوية للمستفيد لا يتطابق تماماً مع هوية مالك الصك في سجلات وزارة العدل (مؤشر تزوير أو تغيير ملكية).
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover border">
                                <thead>
                                    <tr>
                                        <th>رقم الطلب</th>
                                        <th>اسم المستفيد والخدمة</th>
                                        <th>رقم الصك</th>
                                        <th>تاريخ التقديم</th>
                                        <th class="text-center">إجراء التدقيق</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($pendingApps as $app): ?>
                                    <tr>
                                        <td class="fw-bold text-muted">#<?= str_pad($app['app_id'], 5, '0', STR_PAD_LEFT); ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($app['cust_name']); ?></div>
                                            <div class="small text-muted"><span class="badge bg-light text-primary border"><?= htmlspecialchars($app['srv_name']); ?></span> في <?= htmlspecialchars($app['cty_name']); ?> (<?= htmlspecialchars($app['reg_name']); ?>)</div>
                                        </td>
                                        <td class="font-monospace text-primary fw-bold"><?= htmlspecialchars($app['deed_no']); ?></td>
                                        <td class="small text-muted fw-bold"><?= $app['created_at']; ?></td>
                                        <td class="text-center">
                                            <!-- زر فتح المراجعة والتدقيق الشامل -->
                                            <button class="btn btn-primary btn-sm rounded-pill px-3 fw-bold shadow-sm" onclick="openAuditModal(<?= htmlspecialchars(json_encode($app)); ?>)"><i class="fa-solid fa-magnifying-glass-chart me-1"></i> مراجعة وتدقيق</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- الصفحة 2: السجل التاريخي لتدقيقات الموظف الحالي -->
            <div id="page-history" class="page-view">
                <div class="admin-card">
                    <div class="card-title text-success"><i class="fa-solid fa-clock-rotate-left bg-success text-white rounded p-2"></i> سجل معاملاتي المكتملة والمرفوضة</div>
                    
                    <?php if(empty($historyList)): ?>
                        <div class="text-center py-5">
                            <i class="fa-regular fa-folder-open text-muted fs-1 mb-3"></i>
                            <h5 class="fw-bold text-muted">لم تقم بإجراء أي عمليات تدقيق سابقة حتى الآن.</h5>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>رقم الطلب</th>
                                        <th>المستفيد والخدمة</th>
                                        <th>رقم الصك</th>
                                        <th>تاريخ المراجعة</th>
                                        <th>القرار المتخذ</th>
                                        <th>التفاصيل / سبب الرفض</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($historyList as $hist): 
                                        $badgeColor = ($hist['decision'] == 'Rejected') ? 'danger' : 'success';
                                        $decisionText = ($hist['decision'] == 'Rejected') ? 'تم رفضه يدوياً' : 'تم اعتماده وقبوله';
                                    ?>
                                    <tr>
                                        <td class="fw-bold text-muted">#<?= str_pad($hist['app_id'], 5, '0', STR_PAD_LEFT); ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($hist['cust_name']); ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($hist['srv_name']); ?> في <?= htmlspecialchars($hist['cty_name']); ?></div>
                                        </td>
                                        <td class="font-monospace text-secondary"><?= htmlspecialchars($hist['deed_no']); ?></td>
                                        <td class="small text-muted fw-bold"><?= $hist['change_date']; ?></td>
                                        <td><span class="badge bg-<?= $badgeColor ?> fs-7"><?= $decisionText; ?></span></td>
                                        <td>
                                            <?php if($hist['decision'] == 'Rejected'): ?>
                                                <span class="small text-danger fw-bold d-inline-block text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($hist['rejection_reason']); ?>"><?= htmlspecialchars($hist['rejection_reason']); ?></span>
                                            <?php else: ?>
                                                <span class="text-success"><i class="fa-solid fa-circle-check"></i> تم التمرير بنجاح</span>
                                            <?php endif; ?>
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

    <!-- نافذة التدقيق الشاملة والمنبثقة (Audit and Comparison Modal) -->
    <div class="modal fade" id="auditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content" style="border-radius: 18px; border:none; box-shadow: 0 15px 40px rgba(0,0,0,0.15);">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-black text-navy"><i class="fa-solid fa-user-shield me-2 text-primary"></i> مركز المقارنة والتحقق الميداني والعدلي</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4">
                        <!-- القسم الأول: مقارنة البيانات جنباً إلى جنب لكشف الخلاف -->
                        <div class="col-lg-6 border-end">
                            <h6 class="fw-bold mb-3 text-secondary"><i class="fa-solid fa-scale-balanced me-1"></i> مطابقة بيانات المستفيد وسجلات وزارة العدل</h6>
                            
                            <div class="row">
                                <div class="col-6">
                                    <div class="comparison-box" id="custBox">
                                        <span class="text-muted d-block small mb-1 fw-bold">البيانات المدخلة بالطلب:</span>
                                        <p class="mb-1"><strong>اسم العميل:</strong> <span id="cust_name_val"></span></p>
                                        <p class="mb-1"><strong>رقم الهوية:</strong> <span id="cust_id_val" class="font-monospace"></span></p>
                                        <p class="mb-0"><strong>رقم الصك:</strong> <span id="deed_no_val" class="font-monospace text-primary"></span></p>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="comparison-box" id="mojBox">
                                        <span class="text-muted d-block small mb-1 fw-bold">البيانات الموثقة بوزارة العدل:</span>
                                        <p class="mb-1"><strong>المالك المسجل:</strong> <span id="moj_name_val"></span></p>
                                        <p class="mb-1"><strong>هوية المالك:</strong> <span id="moj_id_val" class="font-monospace"></span></p>
                                        <p class="mb-0"><strong>المساحة الموثقة:</strong> <span id="moj_area_val" class="fw-bold text-success"></span></p>
                                    </div>
                                </div>
                            </div>

                            <div class="alert text-center fw-bold p-2 mt-3 fs-7" id="matchVerdict"></div>

                            <!-- الخريطة الجغرافية للتأكد من موقع العقار الميداني -->
                            <h6 class="fw-bold my-3 text-secondary"><i class="fa-solid fa-map-location-dot me-1"></i> الموقع الجغرافي المحدد بواسطة العميل</h6>
                            <div style="height: 250px; border-radius: 12px; overflow:hidden; border: 1px solid #e2e8f0;">
                                <div id="auditMap" style="height: 100%; width: 100%;"></div>
                            </div>
                        </div>

                        <!-- القسم الثاني: المرفق وإجراء اتخاذ القرار -->
                        <div class="col-lg-6 d-flex flex-column justify-content-between">
                            <div>
                                <h6 class="fw-bold mb-3 text-secondary"><i class="fa-solid fa-file-pdf me-1"></i> فحص المرفق وصك الملكية المرفوع</h6>
                                <div class="p-3 border rounded text-center bg-light mb-4" style="border-style: dashed !important; border-radius: 12px;">
                                    <i class="fa-solid fa-file-invoice text-primary fs-1 mb-2"></i>
                                    <h6 class="fw-bold mb-2">مستند صك الملكية الإلكتروني</h6>
                                    <a id="deed_file_link" href="#" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill fw-bold"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i> فتح ومعاينة المرفق في نافذة جديدة</a>
                                </div>
                                
                                <h6 class="fw-bold mb-2 text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> في حال رفض الطلب:</h6>
                                <p class="text-muted small fw-bold mb-2">الرجاء إدخال سبب الرفض بوضوح لكي يظهر للمشترك في مركز إشعاراته ويتمكن من تصحيح بياناته:</p>
                                <textarea id="rejection_input" class="form-control mb-3" rows="3" placeholder="اكتب هنا سبب الرفض بالتفصيل (مثل: اسم مالك الصك لا يطابق الهوية، أو المرفق المرفوع غير واضح)..." required></textarea>
                            </div>

                            <!-- الأزرار المخصصة للقرارات -->
                            <div class="d-flex gap-3 mt-4 pt-3 border-top">
                                <!-- نموذج الموافقة والاعتماد -->
                                <form method="POST" id="approveForm" class="flex-fill">
                                    <input type="hidden" name="app_id" id="approve_app_id">
                                    <input type="hidden" name="cty_id" id="approve_cty_id">
                                    <button type="submit" name="approve_app" class="btn btn-success w-100 fw-bold py-3 rounded-3 shadow-sm"><i class="fa-solid fa-circle-check me-1"></i> اعتماد وقبول الصك</button>
                                </form>
                                
                                <!-- زر تفعيل رفض الطلب -->
                                <form method="POST" id="rejectForm" class="flex-fill" onsubmit="return confirmRejection(event)">
                                    <input type="hidden" name="app_id" id="reject_app_id">
                                    <input type="hidden" name="rejection_reason" id="reject_reason_hidden">
                                    <button type="submit" name="reject_app" class="btn btn-danger w-100 fw-bold py-3 rounded-3 shadow-sm"><i class="fa-solid fa-circle-xmark me-1"></i> رفض الطلب</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openPage(pageId, element) {
            document.querySelectorAll('.page-view').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            document.getElementById(pageId).classList.add('active');
            element.classList.add('active');
            document.getElementById('topbar-title').innerText = element.innerText;
        }

        // إعداد خريطة التدقيق التفاعلية خارج الدالة لمنع ازدواجية التهيئة
        let auditMap = L.map('auditMap').setView([24.7136, 46.6753], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© Qatra Smart Systems' }).addTo(auditMap);
        let auditMarker;

        // دالة فتح وعرض نافذة التدقيق الشاملة للطلب المحدد
        function openAuditModal(app) {
            // أ. ملء بيانات العميل والمستندات المدخلة
            document.getElementById('cust_name_val').innerText = app.cust_name;
            document.getElementById('cust_id_val').innerText = app.cust_nat_id;
            document.getElementById('deed_no_val').innerText = app.deed_no;

            // ب. ملء بيانات وزارة العدل المسترجعة (إذا وجدت)
            if(app.moj_owner_name) {
                document.getElementById('moj_name_val').innerText = app.moj_owner_name;
                document.getElementById('moj_id_val').innerText = app.moj_owner_id;
                document.getElementById('moj_area_val').innerText = app.moj_land_area + " م²";
                
                // تحديد شكل صندوق المقارنة بناءً على تطابق البيانات
                let custBox = document.getElementById('custBox');
                let mojBox = document.getElementById('mojBox');
                let verdict = document.getElementById('matchVerdict');

                if(app.cust_nat_id === app.moj_owner_id) {
                    custBox.className = "comparison-box match";
                    mojBox.className = "comparison-box match";
                    verdict.className = "alert alert-success fw-bold p-2 mt-3 fs-7";
                    verdict.innerHTML = "<i class='fa-solid fa-circle-check me-1'></i> هوية المستفيد متطابقة مع هوية مالك الصك في سجلات وزارة العدل.";
                } else {
                    custBox.className = "comparison-box mismatch";
                    mojBox.className = "comparison-box mismatch";
                    verdict.className = "alert alert-danger fw-bold p-2 mt-3 fs-7";
                    verdict.innerHTML = "<i class='fa-solid fa-circle-exclamation me-1'></i> تحذير: الهوية المدخلة بالطلب تختلف تماماً عن هوية المالك الأصلي الصادر من كتابة العدل.";
                }
            } else {
                document.getElementById('moj_name_val').innerText = "الصك غير مسجل!";
                document.getElementById('moj_id_val').innerText = "-";
                document.getElementById('moj_area_val').innerText = "-";
                document.getElementById('matchVerdict').className = "alert alert-danger fw-bold p-2 mt-3 fs-7";
                document.getElementById('matchVerdict').innerHTML = "<i class='fa-solid fa-circle-xmark me-1'></i> الصك الإلكتروني غير مدرج بالكامل في سجلات وزارة العدل.";
            }

            // جـ. إعداد رابط ملف صك الملكية
            document.getElementById('deed_file_link').href = app.deed_file_url;

            // د. تهيئة وتحديث أزرار قرارات النماذج بالقيم
            document.getElementById('approve_app_id').value = app.app_id;
            document.getElementById('approve_cty_id').value = app.cty_id;
            document.getElementById('reject_app_id').value = app.app_id;
            document.getElementById('rejection_input').value = ""; // تصفير حقل الرفض

            // هـ. تهيئة الخريطة وتحديث موقع مؤشر الـ GPS للطلب
            let lat = parseFloat(app.latitude) || 24.7136;
            let lng = parseFloat(app.longitude) || 46.6753;
            
            setTimeout(() => {
                auditMap.invalidateSize();
                auditMap.setView([lat, lng], 15);
                if (auditMarker) {
                    auditMarker.setLatLng([lat, lng]);
                } else {
                    auditMarker = L.marker([lat, lng]).addTo(auditMap);
                }
            }, 300);

            // و. إظهار النافذة المنبثقة تفاعلياً
            let myModal = new bootstrap.Modal(document.getElementById('auditModal'));
            myModal.show();
        }

        // تأكيد إرسال قرار رفض الطلب مع استخلاص سبب الرفض
        function confirmRejection(e) {
            let reason = document.getElementById('rejection_input').value.trim();
            if(reason === "") {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'تنبيه هام!',
                    text: 'يرجى كتابة سبب الرفض لكي يتمكن العميل من تصحيح صك ملكيته.',
                    confirmButtonColor: '#092e54'
                });
                return false;
            }
            document.getElementById('reject_reason_hidden').value = reason;
            return true;
        }
    </script>
</body>
</html>