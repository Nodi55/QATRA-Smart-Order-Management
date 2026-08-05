<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// التحقق من صلاحية فني التركيبات
if (!isset($_SESSION['emp_id']) || !in_array('Installation Technician', $_SESSION['emp_roles'])) {
    header("Location: employee_dashboard.php");
    exit;
}

require_once 'db_connect.php';
$msg = ""; $msgType = "";
$empId = $_SESSION['emp_id'];

// تهيئة الجدول ليقبل عمود الملاحظات إذا لم يكن موجوداً بعد
try {
    $pdo->query("SELECT installer_notes FROM installation_task LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE installation_task ADD COLUMN installer_notes TEXT NULL");
}

// =========================================================
// معالجة إرسال مهمة التركيب وإغلاق الطلب
// =========================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['complete_task'])) {
    $taskId = $_POST['task_id'];
    $appId = $_POST['app_id'];
    $pipeLength = floatval($_POST['pipe_length']);
    $pipeDiameter = floatval($_POST['pipe_diameter']);
    $initialReading = floatval($_POST['initial_reading']);
    $mtrSerial = trim($_POST['mtr_serial']);
    $mtrType = $_POST['mtr_type'];
    $install_all = isset($_POST['install_all']) ? 1 : 0;

    // ملاحظات الفني الإضافية (اختيارية)
    $installer_notes = null;
    if (isset($_POST['add_notes']) && isset($_POST['installer_notes']) && trim($_POST['installer_notes']) !== '') {
        $installer_notes = trim($_POST['installer_notes']);
    }

    if (empty($mtrSerial)) {
        $msg = "خطأ: يجب إدخال الرقم التسلسلي للعداد.";
        $msgType = "error";
    } else {
        try {
            $pdo->beginTransaction();

            // جلب صك وهويات الطلب الحالي للبحث عن المهام المزدوجة
            $stmtApp = $pdo->prepare("SELECT deed_no, srv_id, cust_id FROM application WHERE app_id = ?");
            $stmtApp->execute([$appId]);
            $currentApp = $stmtApp->fetch();

            if ($currentApp) {
                $deed_no = $currentApp['deed_no'];
                $custId = $currentApp['cust_id'];

                $tasksToProcess = [];
                if ($install_all) {
                    $stmtSearch = $pdo->prepare("
                        SELECT it.task_id, it.app_id, a.srv_id 
                        FROM installation_task it
                        JOIN application a ON it.app_id = a.app_id
                        WHERE a.deed_no = ? AND it.emp_id = ? AND it.initial_reading IS NULL
                    ");
                    $stmtSearch->execute([$deed_no, $empId]);
                    $tasksToProcess = $stmtSearch->fetchAll(PDO::FETCH_ASSOC);
                }

                if (empty($tasksToProcess)) {
                    $tasksToProcess = [[
                        'task_id' => $taskId,
                        'app_id' => $appId,
                        'srv_id' => $currentApp['srv_id']
                    ]];
                }

                foreach ($tasksToProcess as $task) {
                    $currTaskId = $task['task_id'];
                    $currAppId = $task['app_id'];
                    $currSrvId = $task['srv_id'];

                    // 1. تحديث جدول تفاصيل مهمة التركيب
                    $stmtUpdateTask = $pdo->prepare("
                        UPDATE installation_task 
                        SET pipe_length = ?, pipe_diameter = ?, initial_reading = ?, installer_notes = ?
                        WHERE task_id = ?
                    ");
                    $stmtUpdateTask->execute([$pipeLength, $pipeDiameter, $initialReading, $installer_notes, $currTaskId]);

                    // 2. التحقق من وجود الحساب الموحد للعميل، وإنشائه إن لم يكن موجوداً
                    $stmtCheckAcc = $pdo->prepare("SELECT acc_id FROM unified_account WHERE deed_no = ?");
                    $stmtCheckAcc->execute([$deed_no]);
                    $accId = $stmtCheckAcc->fetchColumn();

                    if (!$accId) {
                        $stmtCreateAcc = $pdo->prepare("INSERT INTO unified_account (cust_id, deed_no) VALUES (?, ?)");
                        $stmtCreateAcc->execute([$custId, $deed_no]);
                        $accId = $pdo->lastInsertId();
                    }

                    // 3. تسجيل العداد وربطه باللاحقة المميزة تلافياً لتعارض فرادة الجدول المزدوج
                    $suffix = ($currSrvId == 1) ? "-W" : "-S";
                    $currMtrSerial = $mtrSerial . $suffix;

                    // التحقق من تكرار العداد بقاعدة البيانات
                    $stmtCheckMeter = $pdo->prepare("SELECT COUNT(*) FROM meter WHERE mtr_serial = ?");
                    $stmtCheckMeter->execute([$currMtrSerial]);
                    if ($stmtCheckMeter->fetchColumn() > 0) {
                        // لتفادي التعارض، نقوم بإضافة لاحقة عشوائية مؤقتة
                        $currMtrSerial .= rand(10, 99);
                    }

                    $stmtInsertMeter = $pdo->prepare("
                        INSERT INTO meter (mtr_serial, mtr_type, acc_id, task_id) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmtInsertMeter->execute([$currMtrSerial, $mtrType, $accId, $currTaskId]);

                    // 4. تفعيل الخدمة للعميل (Activated Service)
                    $stmtActivate = $pdo->prepare("INSERT INTO activated_service (acc_id, srv_id) VALUES (?, ?)");
                    $stmtActivate->execute([$accId, $currSrvId]);

                    // 5. تحديث حالة الطلب العام إلى "مكتمل"
                    $stmtUpdateApp = $pdo->prepare("UPDATE application SET app_status = 'Completed' WHERE app_id = ?");
                    $stmtUpdateApp->execute([$currAppId]);

                    // 6. تسجيل العملية في الأرشيف والتاريخ
                    $stmtHistory = $pdo->prepare("
                        INSERT INTO application_history (app_id, status, changed_by, change_date) 
                        VALUES (?, 'Completed', ?, NOW())
                    ");
                    $stmtHistory->execute([$currAppId, $empId]);

                    // 7. إنقاص عداد المهام النشطة للفني الحالي
                    $stmtDecWorkload = $pdo->prepare("
                        UPDATE company_employee 
                        SET active_tasks_count = GREATEST(0, active_tasks_count - 1) 
                        WHERE emp_id = ?
                    ");
                    $stmtDecWorkload->execute([$empId]);

                    // 8. بث الإشعار الترحيبي ورسالة الشكر الفاخرة للعميل
                    $srvNameText = ($currSrvId == 1) ? "المياه" : "الصرف الصحي";
                    $welcomeNotif = "شريكنا العزيز، نود إعلامكم بأنه تم الانتهاء من تركيب عداد خدمة " . $srvNameText . " بنجاح وعقاركم الآن متصل بالشبكة الذكية بالكامل. نحن سعيدون جداً بخدمتكم، وشكراً لتعاونكم مع شركة المياه الوطنية (قطرة)!";
                    $pdo->prepare("INSERT INTO notification (message_content, cust_id) VALUES (?, ?)")->execute([$welcomeNotif, $custId]);
                }

                $pdo->commit();
                $msg = "تم تركيب وتفعيل عدادات الخدمة بنجاح، وإرسال إشعار الترحيب المخصص للعميل!";
                $msgType = "success";
            } else {
                throw new Exception("المهمة المحددة غير صالحة.");
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "حدث خطأ أثناء إغلاق المهمة: " . $e->getMessage();
            $msgType = "error";
        }
    }
}

// =========================================================
// جلب البيانات لشاشة العرض
// =========================================================

// 1. قائمة المهام النشطة للفني الحالي (بانتظار التركيب)
$stmtActiveTasks = $pdo->prepare("
    SELECT it.task_id, it.app_id, a.deed_no, a.latitude, a.longitude, 
           c.full_name as customer_name, c.phone_number, cy.cty_name, st.srv_name
    FROM installation_task it
    JOIN application a ON it.app_id = a.app_id
    JOIN customer c ON a.cust_id = c.cust_id
    JOIN city cy ON a.cty_id = cy.cty_id
    JOIN service_type st ON a.srv_id = st.srv_id
    WHERE it.emp_id = ? AND it.initial_reading IS NULL
");
$stmtActiveTasks->execute([$empId]);
$activeTasks = $stmtActiveTasks->fetchAll(PDO::FETCH_ASSOC);

// 2. قائمة المهام التي تم إنجازها سابقاً من قبل الفني
$stmtCompletedTasks = $pdo->prepare("
    SELECT it.task_id, it.app_id, it.pipe_length, it.pipe_diameter, it.initial_reading,
           m.mtr_serial, m.mtr_type, a.deed_no, c.full_name as customer_name, cy.cty_name, st.srv_name
    FROM installation_task it
    JOIN application a ON it.app_id = a.app_id
    JOIN customer c ON a.cust_id = c.cust_id
    JOIN city cy ON a.cty_id = cy.cty_id
    JOIN service_type st ON a.srv_id = st.srv_id
    LEFT JOIN meter m ON it.task_id = m.task_id
    WHERE it.emp_id = ? AND it.initial_reading IS NOT NULL
    ORDER BY it.task_id DESC
");
$stmtCompletedTasks->execute([$empId]);
$completedTasks = $stmtCompletedTasks->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>بوابة فني التركيبات | قطرة</title>
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
            <i class="fa-solid fa-wrench"></i>
            <h4 class="fw-black m-0">البوابة الميدانية</h4>
            <div class="small mt-1 text-info">فني التركيبات وربط العدادات</div>
        </div>
        <div class="sidebar-nav">
            <a class="nav-item active" onclick="openPage('page-active-tasks', this)"><i class="fa-solid fa-screwdriver-wrench"></i> مهام التركيب النشطة <span class="badge bg-warning text-dark ms-auto"><?= count($activeTasks); ?></span></a>
            <a class="nav-item" onclick="openPage('page-completed-tasks', this)"><i class="fa-solid fa-circle-check"></i> العدادات المركبة سابقاً <span class="badge bg-success ms-auto"><?= count($completedTasks); ?></span></a>
        </div>
        <div class="p-3 border-top border-secondary text-center">
            <a href="employee_dashboard.php" class="btn btn-outline-light w-100 fw-bold rounded-pill"><i class="fa-solid fa-arrow-right-from-bracket fa-flip-horizontal me-2"></i> شاشة التوجيه</a>
        </div>
    </div>

    <div class="main-wrapper">
        <div class="topbar">
            <div><h4 class="fw-black text-dark m-0" id="topbar-title">مهام التركيب النشطة</h4></div>
            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold text-secondary">الفني: <?= htmlspecialchars($_SESSION['emp_name']); ?></span>
                <div class="bg-primary text-white rounded-circle d-flex justify-content-center align-items-center" style="width: 40px; height: 40px;"><i class="fa-solid fa-user-gear"></i></div>
            </div>
        </div>

        <div class="content-area">
            <?php if($msg): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({ icon: '<?= $msgType ?>', title: 'إشعار النظام', text: '<?= $msg ?>', confirmButtonColor: '#0b457f' });
                    });
                </script>
            <?php endif; ?>

            <div id="page-active-tasks" class="page-view active">
                <?php if(empty($activeTasks)): ?>
                    <div class="text-center py-5 bg-white rounded-3 shadow-sm border">
                        <i class="fa-solid fa-mug-hot text-success fs-1 mb-3"></i>
                        <h4 class="fw-bold text-success">عمل رائع! تم إنجاز كافة المهام بالكامل</h4>
                        <p class="text-muted fw-bold">لا توجد أي مهام تركيب معلقة في مدينتك حالياً.</p>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <div class="col-lg-5" style="max-height: calc(100vh - 180px); overflow-y: auto;">
                            <?php foreach($activeTasks as $index => $task): ?>
                                <div class="task-card <?= $index === 0 ? 'active-selection' : '' ?>" id="card-<?= $task['task_id'] ?>" onclick="selectTask(<?= htmlspecialchars(json_encode($task)) ?>, this)">
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

                        <div class="col-lg-7">
                            <div class="admin-card bg-white p-4 border rounded-3">
                                <div class="card-title text-primary"><i class="fa-solid fa-location-crosshairs"></i> موقع العقار وتفاصيل الملاحة</div>
                                <div class="map-box mb-3" id="taskMap"></div>
                                <div class="d-flex gap-2 mb-4">
                                    <a id="btnGoogleMap" href="#" target="_blank" class="btn btn-outline-danger w-100 fw-bold rounded-pill"><i class="fa-solid fa-map-location-dot me-2"></i> فتح اتجاهات الملاحة في خرائط جوجل</a>
                                </div>

                                <div class="card-title text-success"><i class="fa-solid fa-file-invoice"></i> إدخل البيانات الفنية للعداد والتركيب</div>
                                <form method="POST" id="installForm">
                                    <input type="hidden" name="task_id" id="form_task_id" value="<?= $activeTasks[0]['task_id'] ?>">
                                    <input type="hidden" name="app_id" id="form_app_id" value="<?= $activeTasks[0]['app_id'] ?>">
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">طول الأنبوب المستخدم (متر)</label>
                                            <input type="number" step="0.1" name="pipe_length" class="form-control" placeholder="مثال: 12.5" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">قطر الأنبوب (بوصة / Inch)</label>
                                            <select name="pipe_diameter" class="form-select" required>
                                                <option value="0.5">0.5 بوصة (منزلي قياسي)</option>
                                                <option value="0.75">0.75 بوصة</option>
                                                <option value="1.0">1.0 بوصة</option>
                                                <option value="1.5">1.5 بوصة</option>
                                                <option value="2.0">2.0 بوصة (تجاري/صناعي)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">الرقم التسلسلي للعداد الجديد (Serial)</label>
                                            <input type="text" name="mtr_serial" class="form-control" placeholder="أدخل الرقم التسلسلي الفريد للعداد" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">نوع العداد المخصص</label>
                                            <select name="mtr_type" class="form-select" required>
                                                <option value="Smart">عداد مياه ذكي إلكتروني (Smart)</option>
                                                <option value="Mechanical">عداد مياه ميكانيكي (Mechanical)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-12">
                                            <label class="form-label">القراءة الافتتاحية المبدئية للعداد (م³)</label>
                                            <input type="number" step="0.01" name="initial_reading" class="form-control" value="0.00" required>
                                        </div>
                                    </div>

                                    <div class="mt-4">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="add_notes" id="add_notes_check" onchange="toggleNotes(this)">
                                            <label class="form-check-label fw-bold text-dark" for="add_notes_check">
                                                <i class="fa-solid fa-note-sticky text-primary me-1"></i> إضافة ملاحظات تركيب إضافية (اختياري)
                                            </label>
                                        </div>
                                        <textarea name="installer_notes" id="installer_notes_field" class="form-control" rows="3" placeholder="اكتب هنا أي ملاحظات إضافية عن التركيب أو حالة الموقع..." style="display:none;"></textarea>
                                    </div>



                                    <!-- خيار الإنجاز والاعتماد المزدوج للعدادات دفعة واحدة -->
                                    <div class="form-check my-4 text-end">
                                        <input class="form-check-input float-end ms-2" type="checkbox" name="install_all" id="install_all" value="1" checked>
                                        <label class="form-check-label fw-bold text-success" for="install_all">
                                            تفعيل وإنجاز كافة مهام التركيب المترابطة (مياه وصرف) المزدوجة المعلقة لهذا العقار معاً دفعة واحدة
                                        </label>
                                    </div>

                                    <div class="mt-4">
                                        <button type="submit" name="complete_task" class="btn-brand bg-success w-100 py-3 rounded-3 shadow-sm fw-black"><i class="fa-solid fa-circle-check me-2"></i> تأكيد التركيب وتفعيل الخدمة والعداد</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div id="page-completed-tasks" class="page-view">
                <div class="admin-card bg-white p-4 border rounded-3">
                    <div class="card-title text-success"><i class="fa-solid fa-clock-rotate-left"></i> سجل العدادات التي تم تركيبها</div>
                    <?php if(empty($completedTasks)): ?>
                        <div class="text-center py-5"><i class="fa-solid fa-folder-open text-muted fs-1 mb-3"></i><h5 class="fw-bold text-muted">لا توجد أي عدادات مسجلة باسمك بعد</h5></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>رقم الطلب</th>
                                        <th>المشترك</th>
                                        <th>الخدمة</th>
                                        <th>المدينة</th>
                                        <th>الرقم التسلسلي للعداد</th>
                                        <th>نوع العداد</th>
                                        <th>مواصفات التوصيل</th>
                                        <th>التقرير</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($completedTasks as $comp): ?>
                                    <tr>
                                        <td class="fw-bold text-muted">#<?= str_pad($comp['app_id'], 5, '0', STR_PAD_LEFT); ?></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($comp['customer_name']); ?></td>
                                        <td><span class="badge bg-light text-primary border"><?= htmlspecialchars($comp['srv_name']); ?></span></td>
                                        <td><?= htmlspecialchars($comp['cty_name']); ?></td>
                                        <td><span class="badge bg-secondary font-monospace fs-6"><?= htmlspecialchars($comp['mtr_serial']); ?></span></td>
                                        <td><span class="badge bg-success"><?= $comp['mtr_type'] == 'Smart' ? 'ذكي' : 'ميكانيكي' ?></span></td>
                                        <td class="small text-muted fw-bold">
                                            أنبوب: <?= $comp['pipe_length']; ?>م<br>
                                            القطر: <?= $comp['pipe_diameter']; ?> بوصة
                                        </td>
                                        <td>
                                            <a href="installation_report.php?task_id=<?= $comp['task_id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill fw-bold">
                                                <i class="fa-solid fa-file-lines me-1"></i> عرض التقرير
                                            </a>
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
        let currentMap;
        let currentMarker;

        function toggleNotes(checkbox) {
            const field = document.getElementById('installer_notes_field');
            if (checkbox.checked) {
                field.style.display = 'block';
                field.focus();
            } else {
                field.style.display = 'none';
                field.value = '';
            }
        }

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

            document.getElementById('form_task_id').value = task.task_id;
            document.getElementById('form_app_id').value = task.app_id;

            if (currentMap && task.latitude && task.longitude) {
                let coords = [task.latitude, task.longitude];
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
                let firstTask = <?= json_encode($activeTasks[0]) ?>;
                let initialCoords = [firstTask.latitude || 24.7136, firstTask.longitude || 46.6753];
                
                currentMap = L.map('taskMap').setView(initialCoords, 14);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© Qatra Smart Systems'
                }).addTo(currentMap);

                currentMarker = L.marker(initialCoords).addTo(currentMap);
                document.getElementById('btnGoogleMap').href = `https://www.google.com/maps/dir/?api=1&destination=${firstTask.latitude},${firstTask.longitude}`;
            <?php endif; ?>
        });
    </script>
</body>
</html>