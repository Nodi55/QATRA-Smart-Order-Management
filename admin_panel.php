<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['emp_id']) || !in_array('Admin', $_SESSION['emp_roles'])) {
    header("Location: employee_dashboard.php");
    exit;
}

require_once 'db_connect.php';
$msg = ""; $msgType = "";

// =========================================================
// (إصلاح) التأكد من وجود عمود is_active في الجدول قبل أي استعلام
// يمنع ظهور خطأ: Unknown column 'ce.is_active' لو الجدول قديم/ناقص
// =========================================================
try {
    $pdo->query("SELECT is_active FROM company_employee LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE company_employee ADD COLUMN is_active TINYINT(1) DEFAULT 1");
    $pdo->exec("UPDATE company_employee SET is_active = 1 WHERE is_active IS NULL");
}

// (إصلاح إضافي) التأكد من وجود عمود active_tasks_count أيضاً لنفس السبب
try {
    $pdo->query("SELECT active_tasks_count FROM company_employee LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE company_employee ADD COLUMN active_tasks_count INT DEFAULT 0");
    $pdo->exec("UPDATE company_employee SET active_tasks_count = 0 WHERE active_tasks_count IS NULL");
}

// =========================================================
// معالجة العمليات (إضافة، تعديل، إيقاف، التوزيع الجغرافي واليدوي)
// =========================================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. إضافة موظف
    if (isset($_POST['add_employee'])) {
        $empName = trim($_POST['emp_name']); $empEmail = trim($_POST['emp_email']);
        $empPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $ctyId = $_POST['cty_id']; $selectedRoles = $_POST['roles'] ?? [];

        if (empty($selectedRoles)) {
            $msg = "خطأ: يجب تحديد صلاحية واحدة على الأقل."; $msgType = "error";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO company_employee (emp_name, emp_email, password_hash, cty_id, is_active, active_tasks_count) VALUES (?, ?, ?, ?, 1, 0)");
                $stmt->execute([$empName, $empEmail, $empPassword, $ctyId]);
                $newEmpId = $pdo->lastInsertId();
                foreach ($selectedRoles as $rId) { $pdo->prepare("INSERT INTO employee_roles (emp_id, role_id) VALUES (?, ?)")->execute([$newEmpId, $rId]); }
                $msg = "تم تسجيل الموظف بنجاح."; $msgType = "success";
            } catch (PDOException $e) { $msg = "البريد الإلكتروني مستخدم مسبقاً."; $msgType = "error"; }
        }
    }

    // 2. تحديث بيانات الموظف
    if (isset($_POST['edit_employee'])) {
        $eId = $_POST['edit_emp_id']; $eName = trim($_POST['edit_emp_name']);
        $eEmail = trim($_POST['edit_emp_email']); $eCty = $_POST['edit_cty_id'];
        $selectedRoles = $_POST['edit_roles'] ?? [];
        try {
            $pdo->prepare("UPDATE company_employee SET emp_name=?, emp_email=?, cty_id=? WHERE emp_id=?")->execute([$eName, $eEmail, $eCty, $eId]);
            $pdo->prepare("DELETE FROM employee_roles WHERE emp_id=?")->execute([$eId]);
            foreach ($selectedRoles as $rId) { $pdo->prepare("INSERT INTO employee_roles (emp_id, role_id) VALUES (?, ?)")->execute([$eId, $rId]); }
            $msg = "تم تحديث بيانات الموظف بنجاح."; $msgType = "success";
        } catch (Exception $e) { $msg = "خطأ في التحديث."; $msgType = "error"; }
    }

    // 3. المنع الذكي عند الإيقاف
    if (isset($_POST['toggle_status'])) {
        $eId = $_POST['target_emp_id'];
        $stmt = $pdo->prepare("SELECT is_active, active_tasks_count FROM company_employee WHERE emp_id = ?");
        $stmt->execute([$eId]);
        $empInfo = $stmt->fetch();
        if ($empInfo['is_active'] == 1 && $empInfo['active_tasks_count'] > 0) {
            $msg = "لا يمكنك إيقاف الموظف لوجود مهام نشطة لديه. أعد توزيع مهامه أولاً."; $msgType = "warning";
        } else {
            $pdo->prepare("UPDATE company_employee SET is_active = NOT is_active WHERE emp_id = ?")->execute([$eId]);
            $msg = "تم تغيير حالة الموظف بنجاح."; $msgType = "success";
        }
    }

    // 4. توجيه إنذار للموظفين المتأخرين
    if (isset($_POST['send_warning'])) {
        $msg = "تم إرسال إنذار رسمي للموظف المتأخر."; $msgType = "success";
    }

    // 5. التوزيع الجغرافي الإقليمي الآلي
    if (isset($_POST['dispatch_unassigned'])) {
        $appId = $_POST['app_id']; $cityId = $_POST['cty_id']; $reqRole = $_POST['req_role']; 
        $bestTechStmt = $pdo->prepare("
            SELECT ce.emp_id, ce.cty_id, c.cty_name 
            FROM company_employee ce
            JOIN employee_roles er ON ce.emp_id = er.emp_id JOIN system_role sr ON er.role_id = sr.role_id JOIN city c ON ce.cty_id = c.cty_id
            WHERE ce.is_active = 1 AND sr.role_name = ? AND c.reg_id = (SELECT reg_id FROM city WHERE cty_id = ?)
            ORDER BY (ce.cty_id = ?) DESC, ce.active_tasks_count ASC LIMIT 1
        ");
        $bestTechStmt->execute([$reqRole, $cityId, $cityId]);
        $assigned = $bestTechStmt->fetch();

        if ($assigned) {
            if ($reqRole == 'Inspection Technician') { $pdo->prepare("INSERT INTO field_inspection (app_id, emp_id) VALUES (?, ?)")->execute([$appId, $assigned['emp_id']]); } 
            else { $pdo->prepare("INSERT INTO installation_task (app_id, emp_id) VALUES (?, ?)")->execute([$appId, $assigned['emp_id']]); }
            $pdo->prepare("UPDATE company_employee SET active_tasks_count = active_tasks_count + 1 WHERE emp_id = ?")->execute([$assigned['emp_id']]);
            $locationNote = ($assigned['cty_id'] == $cityId) ? "في نفس المدينة" : "في مدينة مجاورة (".$assigned['cty_name'].")";
            $msg = "تم التوزيع الآلي بنجاح! أسندت المهمة لفني " . $locationNote . "."; $msgType = "success";
        } else { 
            $msg = "تنبيه: لم يعثر النظام على أي فني متاح في المنطقة. الرجاء إسناد المهمة يدوياً."; $msgType = "warning"; 
        }
    }

    // 6. الإسناد اليدوي (Manual Assignment) من قبل المدير
    if (isset($_POST['manual_assign'])) {
        $appId = $_POST['app_id'];
        $manualEmpId = $_POST['manual_emp_id'];
        $reqRole = $_POST['req_role'];

        if (empty($manualEmpId)) {
            $msg = "خطأ: الرجاء اختيار الفني من القائمة."; $msgType = "error";
        } else {
            if ($reqRole == 'Inspection Technician') { $pdo->prepare("INSERT INTO field_inspection (app_id, emp_id) VALUES (?, ?)")->execute([$appId, $manualEmpId]); } 
            else { $pdo->prepare("INSERT INTO installation_task (app_id, emp_id) VALUES (?, ?)")->execute([$appId, $manualEmpId]); }
            $pdo->prepare("UPDATE company_employee SET active_tasks_count = active_tasks_count + 1 WHERE emp_id = ?")->execute([$manualEmpId]);
            $msg = "تم إسناد المهمة للفني يدوياً بنجاح."; $msgType = "success";
        }
    }

    // 7. إعادة التوزيع الآلي للمهام المتراكمة
    if (isset($_POST['reassign_tasks'])) {
        $fromEmpId = $_POST['target_emp_id'];
        $stmt = $pdo->prepare("SELECT cty_id FROM company_employee WHERE emp_id = ?");
        $stmt->execute([$fromEmpId]);
        $srcCtyId = $stmt->fetchColumn();

        $fiStmt = $pdo->prepare("SELECT insp_id FROM field_inspection WHERE emp_id = ? AND inspection_result IS NULL");
        $fiStmt->execute([$fromEmpId]);
        $fiTasks = $fiStmt->fetchAll();
        
        foreach($fiTasks as $fi) {
            $bestTechStmt = $pdo->prepare("
                SELECT ce.emp_id FROM company_employee ce JOIN employee_roles er ON ce.emp_id = er.emp_id JOIN system_role sr ON er.role_id = sr.role_id JOIN city c ON ce.cty_id = c.cty_id
                WHERE ce.is_active = 1 AND ce.emp_id != ? AND sr.role_name = 'Inspection Technician'
                ORDER BY (ce.cty_id = ?) DESC, (c.reg_id = (SELECT reg_id FROM city WHERE cty_id = ?)) DESC, ce.active_tasks_count ASC LIMIT 1
            ");
            $bestTechStmt->execute([$fromEmpId, $srcCtyId, $srcCtyId]);
            $bestTech = $bestTechStmt->fetchColumn();

            if($bestTech) {
                $pdo->prepare("UPDATE field_inspection SET emp_id = ? WHERE insp_id = ?")->execute([$bestTech, $fi['insp_id']]);
                $pdo->prepare("UPDATE company_employee SET active_tasks_count = active_tasks_count + 1 WHERE emp_id = ?")->execute([$bestTech]);
                $pdo->prepare("UPDATE company_employee SET active_tasks_count = active_tasks_count - 1 WHERE emp_id = ?")->execute([$fromEmpId]);
            }
        }
        $msg = "تم سحب المهام وإعادة توزيعها آلياً."; $msgType = "success";
    }
}

// =========================================================
// جلب البيانات وإعداد التقارير والإحصائيات الجديدة
// =========================================================
$cities = $pdo->query("SELECT * FROM city")->fetchAll(PDO::FETCH_ASSOC);
$rolesList = $pdo->query("SELECT MIN(role_id) as role_id, role_name FROM system_role GROUP BY role_name")->fetchAll(PDO::FETCH_ASSOC);

// دالة موحّدة لتسمية الصلاحيات بالعربي (تُستخدم في نموذج الإضافة ونافذة التعديل)
function roleLabelAr($roleName) {
    switch ($roleName) {
        case 'Admin': return 'مدير النظام';
        case 'Auditor': return 'مدقق طلبات';
        case 'Inspection Technician': return 'فني فحص';
        case 'Installation Technician': return 'فني تركيب';
        default: return $roleName;
    }
}

// إحصائيات عامة للنظام
$totalApps = $pdo->query("SELECT COUNT(*) FROM application")->fetchColumn();
$completedApps = $pdo->query("SELECT COUNT(*) FROM application WHERE app_status = 'Completed'")->fetchColumn();
$pendingApps = $pdo->query("SELECT COUNT(*) FROM application WHERE app_status != 'Completed' AND app_status != 'Rejected'")->fetchColumn();

// بيانات الموظفين الشاملة
$employeesData = $pdo->query("
    SELECT ce.emp_id, ce.emp_name, ce.emp_email, ce.active_tasks_count, ce.is_active, ce.cty_id, c.cty_name, 
           GROUP_CONCAT(DISTINCT sr.role_name SEPARATOR ',') as roles, GROUP_CONCAT(DISTINCT sr.role_id SEPARATOR ',') as role_ids
    FROM company_employee ce LEFT JOIN city c ON ce.cty_id = c.cty_id
    LEFT JOIN employee_roles er ON ce.emp_id = er.emp_id LEFT JOIN system_role sr ON er.role_id = sr.role_id
    GROUP BY ce.emp_id ORDER BY ce.emp_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// قائمة الفنيين النشطين (تستخدم في الإسناد اليدوي)
$activeTechs = $pdo->query("
    SELECT ce.emp_id, ce.emp_name, c.cty_name, sr.role_name
    FROM company_employee ce JOIN city c ON ce.cty_id = c.cty_id
    JOIN employee_roles er ON ce.emp_id = er.emp_id JOIN system_role sr ON er.role_id = sr.role_id
    WHERE ce.is_active = 1 AND sr.role_name LIKE '%Technician%'
")->fetchAll(PDO::FETCH_ASSOC);

// تقرير الأداء التفصيلي المطور (يشمل تفاصيل كل مهمة)
$detailedPerformance = $pdo->query("
    SELECT ce.emp_id, ce.emp_name, GROUP_CONCAT(DISTINCT sr.role_name SEPARATOR ',') as roles, ce.active_tasks_count,
           (SELECT COUNT(*) FROM field_inspection fi WHERE fi.emp_id = ce.emp_id AND fi.inspection_result IS NOT NULL) as fi_completed,
           (SELECT COUNT(*) FROM installation_task it WHERE it.emp_id = ce.emp_id AND it.initial_reading IS NOT NULL) as it_completed,
           (SELECT COUNT(*) FROM application_history ah WHERE ah.changed_by = ce.emp_id) as audits_completed
    FROM company_employee ce
    JOIN employee_roles er ON ce.emp_id = er.emp_id JOIN system_role sr ON er.role_id = sr.role_id
    WHERE ce.is_active = 1 AND sr.role_name != 'Admin'
    GROUP BY ce.emp_id
")->fetchAll(PDO::FETCH_ASSOC);

$overloadedEmps = array_filter($detailedPerformance, function($e) { return $e['active_tasks_count'] >= 3; });

// المهام غير المسندة (التي فشل النظام في توزيعها)
$unassignedTasks = $pdo->query("
    SELECT a.app_id, a.app_status, a.cty_id, c.cty_name, r.reg_name, s.srv_name
    FROM application a JOIN city c ON a.cty_id = c.cty_id JOIN region r ON c.reg_id = r.reg_id JOIN service_type s ON a.srv_id = s.srv_id
    LEFT JOIN field_inspection fi ON a.app_id = fi.app_id
    WHERE (a.app_status = 'Pending_Inspection' AND fi.insp_id IS NULL)
")->fetchAll(PDO::FETCH_ASSOC);

// المهام التي تعمل خارج المنطقة
$outOfRegionTasks = $pdo->query("
    SELECT a.app_id, s.srv_name, c_app.cty_name as cust_city, r_app.reg_name as cust_region,
           ce.emp_name, c_emp.cty_name as emp_city, r_emp.reg_name as emp_region
    FROM field_inspection fi JOIN application a ON fi.app_id = a.app_id JOIN service_type s ON a.srv_id = s.srv_id
    JOIN city c_app ON a.cty_id = c_app.cty_id JOIN region r_app ON c_app.reg_id = r_app.reg_id
    JOIN company_employee ce ON fi.emp_id = ce.emp_id JOIN city c_emp ON ce.cty_id = c_emp.cty_id JOIN region r_emp ON c_emp.reg_id = r_emp.reg_id
    WHERE r_app.reg_id != r_emp.reg_id AND fi.inspection_result IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

// الأرشيف الأمني (للاطلاع فقط)
$rejectedApps = $pdo->query("
    SELECT ah.app_id, ah.rejection_reason, ah.change_date, ce.emp_name as auditor_name, a.app_status, a.deed_no, c.full_name as customer_name
    FROM application_history ah JOIN application a ON ah.app_id = a.app_id
    LEFT JOIN company_employee ce ON ah.changed_by = ce.emp_id LEFT JOIN customer c ON a.cust_id = c.cust_id
    WHERE ah.status = 'Rejected' OR a.app_status = 'Rejected' ORDER BY ah.change_date DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة المدير | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
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
        
        .admin-card { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid #e2e8f0; }
        .card-title { color: var(--navy); font-weight: 900; font-size: 1.2rem; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; }
        
        .table th { background: #f8fafc; color: #64748b; font-weight: 800; padding: 15px; }
        .table td { padding: 15px; vertical-align: middle; font-weight: 700; color: #334155; }
        
        .page-view { display: none; animation: fadeIn 0.4s; }
        .page-view.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .btn-brand { background: var(--blue); color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 800; width: 100%; transition: 0.3s; }
        .btn-brand:hover { background: var(--navy); color: white; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fa-solid fa-droplet"></i>
            <h4 class="fw-black m-0">نظام الإدارة</h4>
            <div class="small mt-1 text-info">الرقابة والتشغيل الذكي</div>
        </div>
        <div class="sidebar-nav">
            <a class="nav-item active" onclick="openPage('page-stats', this)"><i class="fa-solid fa-chart-pie"></i> نظرة عامة</a>
            <a class="nav-item" onclick="openPage('page-performance', this)"><i class="fa-solid fa-ranking-star"></i> تقارير أداء الموظفين</a>
            <a class="nav-item" onclick="openPage('page-alerts', this)">
                <i class="fa-solid fa-triangle-exclamation"></i> مهام وتوجيه 
                <?php $totalAlerts = count($outOfRegionTasks) + count($unassignedTasks) + count($overloadedEmps); if($totalAlerts > 0): ?><span class="badge bg-danger ms-auto"><?= $totalAlerts; ?></span><?php endif; ?>
            </a>
            <a class="nav-item" onclick="openPage('page-archive', this)"><i class="fa-solid fa-shield-halved"></i> أرشيف المرفوضات</a>
            <a class="nav-item" onclick="openPage('page-hr', this)"><i class="fa-solid fa-users-gear"></i> إدارة شؤون الموظفين</a>
        </div>
        <div class="p-3 border-top border-secondary">
            <a href="employee_dashboard.php" class="btn btn-outline-light w-100 fw-bold rounded-pill"><i class="fa-solid fa-arrow-right-from-bracket fa-flip-horizontal me-2"></i> شاشة التوجيه</a>
        </div>
    </div>

    <div class="main-wrapper">
        <div class="topbar">
            <div><h4 class="fw-black text-dark m-0" id="topbar-title">نظرة عامة والإحصائيات</h4></div>
            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold text-secondary">مرحباً، م. <?= htmlspecialchars($_SESSION['emp_name']); ?></span>
                <div class="bg-primary text-white rounded-circle d-flex justify-content-center align-items-center" style="width: 40px; height: 40px;"><i class="fa-solid fa-user-shield"></i></div>
            </div>
        </div>

        <div class="content-area">
            <?php if($msg): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({ icon: '<?= $msgType ?>', title: 'إشعار النظام', text: '<?= addslashes($msg) ?>', confirmButtonColor: '#0b457f' });
                    });
                </script>
            <?php endif; ?>

            <!-- الصفحة 1: الإحصائيات (تمت زيادة الإحصائيات) -->
            <div id="page-stats" class="page-view active">
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="admin-card text-center" style="border-bottom: 5px solid #0dcaf0;">
                            <i class="fa-solid fa-file-lines fs-1 text-info mb-3"></i>
                            <h2 class="fw-black m-0"><?= $totalApps; ?></h2>
                            <p class="text-muted fw-bold m-0">إجمالي الطلبات المستلمة</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="admin-card text-center" style="border-bottom: 5px solid #198754;">
                            <i class="fa-solid fa-check-double fs-1 text-success mb-3"></i>
                            <h2 class="fw-black text-success m-0"><?= $completedApps; ?></h2>
                            <p class="text-muted fw-bold m-0">طلبات منجزة بالكامل</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="admin-card text-center" style="border-bottom: 5px solid #ffc107;">
                            <i class="fa-solid fa-spinner fs-1 text-warning mb-3"></i>
                            <h2 class="fw-black text-warning m-0"><?= $pendingApps; ?></h2>
                            <p class="text-muted fw-bold m-0">طلبات قيد المعالجة</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="admin-card text-center" style="border-bottom: 5px solid #dc3545;">
                            <i class="fa-solid fa-triangle-exclamation fs-1 text-danger mb-3"></i>
                            <h2 class="fw-black text-danger m-0"><?= count($unassignedTasks); ?></h2>
                            <p class="text-muted fw-bold m-0">مهام تحتاج إسناد</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- الصفحة 2: تقارير الأداء المطور -->
            <div id="page-performance" class="page-view">
                <div class="admin-card">
                    <div class="card-title text-success"><i class="fa-solid fa-ranking-star bg-success text-white rounded p-2"></i> التقرير التشغيلي لأداء الموظفين</div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>اسم الموظف</th><th>المنصب</th><th class="text-center">تفاصيل الإنجاز</th><th class="text-center text-warning">المهام المتراكمة</th><th style="width: 200px;">مؤشر الإنجاز العام</th></tr></thead>
                            <tbody>
                                <?php foreach($detailedPerformance as $perf): 
                                    $totalCompleted = $perf['fi_completed'] + $perf['it_completed'] + $perf['audits_completed'];
                                    $totalHandled = $totalCompleted + $perf['active_tasks_count'];
                                    $completionRate = ($totalHandled > 0) ? round(($totalCompleted / $totalHandled) * 100) : 0;
                                    
                                    // لون المؤشر
                                    $pbColor = 'bg-success';
                                    if ($completionRate < 50) $pbColor = 'bg-danger';
                                    elseif ($completionRate < 80) $pbColor = 'bg-warning';
                                ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($perf['emp_name']); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($perf['roles']); ?></span></td>
                                    <td class="text-center">
                                        <div class="small fw-bold text-muted">
                                            <?= $perf['fi_completed'] > 0 ? "<span class='text-primary'>فحص: {$perf['fi_completed']}</span> | " : "" ?>
                                            <?= $perf['it_completed'] > 0 ? "<span class='text-success'>تركيب: {$perf['it_completed']}</span> | " : "" ?>
                                            <?= $perf['audits_completed'] > 0 ? "<span class='text-info'>تدقيق: {$perf['audits_completed']}</span>" : "" ?>
                                            <?= $totalCompleted == 0 ? "لا يوجد إنجازات" : "" ?>
                                        </div>
                                    </td>
                                    <td class="text-center"><span class="badge bg-<?= $perf['active_tasks_count'] >= 3 ? 'danger' : 'warning text-dark' ?> fs-6"><?= $perf['active_tasks_count']; ?></span></td>
                                    <td>
                                        <div class="d-flex justify-content-between small fw-bold mb-1">
                                            <span>معدل الإنجاز</span><span><?= $completionRate ?>%</span>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar <?= $pbColor ?>" role="progressbar" style="width: <?= $completionRate ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- الصفحة 3: التنبيهات والتوجيه (مع ميزة الاختيار اليدوي) -->
            <div id="page-alerts" class="page-view">
                
                <!-- قسم 1: المهام غير المسندة -->
                <div class="admin-card mb-4">
                    <div class="card-title text-danger"><i class="fa-solid fa-triangle-exclamation bg-danger text-white rounded p-2"></i> الطوارئ: مهام لم تجد فني في مدينتها!</div>
                    <?php if(empty($unassignedTasks)): ?>
                        <div class="text-center py-4"><i class="fa-solid fa-check-circle text-success fs-1 mb-2"></i><p class="fw-bold text-success m-0">نظام التوزيع يعمل 100%. لا يوجد مهام معلقة.</p></div>
                    <?php else: ?>
                        <div class="alert border border-danger fw-bold bg-white text-danger"><i class="fa-solid fa-info-circle me-2"></i> فشل النظام في إسناد هذه الطلبات. يمكنك اختيار البحث الآلي في المنطقة، أو إسنادها يدوياً لأي فني متاح.</div>
                        <div class="table-responsive">
                            <table class="table border">
                                <thead><tr><th>الطلب</th><th>مدينة العميل</th><th>بحث إقليمي آلي</th><th style="width: 300px;">إسناد يدوياً (اختيار مدير)</th></tr></thead>
                                <tbody>
                                    <?php foreach($unassignedTasks as $task): 
                                        $reqRole = ($task['app_status'] == 'Pending_Inspection') ? 'Inspection Technician' : 'Installation Technician';
                                    ?>
                                    <tr>
                                        <td class="fw-bold text-muted">#<?= str_pad($task['app_id'], 5, '0', STR_PAD_LEFT); ?></td>
                                        <td><span class="badge bg-danger"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($task['cty_name']); ?></span></td>
                                        
                                        <!-- زر التوزيع الآلي (يبحث في المدينة ثم المنطقة) -->
                                        <td>
                                            <form method="POST">
                                                <input type="hidden" name="app_id" value="<?= $task['app_id']; ?>">
                                                <input type="hidden" name="cty_id" value="<?= $task['cty_id']; ?>">
                                                <input type="hidden" name="req_role" value="<?= $reqRole; ?>">
                                                <button type="submit" name="dispatch_unassigned" class="btn btn-sm btn-outline-primary fw-bold rounded-pill px-3"><i class="fa-solid fa-satellite-dish"></i> إسناد آلي</button>
                                            </form>
                                        </td>

                                        <!-- الإسناد اليدوي (يختار المدير الفني بنفسه) -->
                                        <td>
                                            <form method="POST" class="d-flex gap-2">
                                                <input type="hidden" name="app_id" value="<?= $task['app_id']; ?>">
                                                <input type="hidden" name="req_role" value="<?= $reqRole; ?>">
                                                <select name="manual_emp_id" class="form-select form-select-sm" required>
                                                    <option value="" disabled selected>-- اختر فني --</option>
                                                    <?php foreach($activeTechs as $tech): 
                                                        // عرض الفنيين من نفس التخصص المطلوب فقط
                                                        if(strpos($tech['role_name'], $reqRole) !== false): ?>
                                                            <option value="<?= $tech['emp_id'] ?>"><?= htmlspecialchars($tech['emp_name']) ?> (<?= htmlspecialchars($tech['cty_name']) ?>)</option>
                                                    <?php endif; endforeach; ?>
                                                </select>
                                                <button type="submit" name="manual_assign" class="btn btn-sm btn-success fw-bold"><i class="fa-solid fa-check"></i></button>
                                            </form>
                                        </td>

                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- قسم 2: ضغط العمل وإعادة التوزيع -->
                <div class="admin-card">
                    <div class="card-title text-warning"><i class="fa-solid fa-triangle-exclamation bg-warning text-dark rounded p-2"></i> موظفون ذوي مهام متراكمة</div>
                    <?php if(empty($overloadedEmps)): ?>
                        <div class="text-center py-4"><i class="fa-solid fa-check-circle text-success fs-1 mb-2"></i><p class="fw-bold text-success m-0">توزيع المهام مثالي ولا يوجد تراكم.</p></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th>اسم الموظف</th><th class="text-center">مهام نشطة</th><th>إعادة توزيع ذكي</th><th>إنذار</th></tr></thead>
                                <tbody>
                                    <?php foreach($overloadedEmps as $emp): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($emp['emp_name']); ?></td>
                                        <td class="text-center"><span class="badge bg-danger fs-6"><?= $emp['active_tasks_count']; ?></span></td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('تأكيد سحب المهام وتوزيعها آلياً حسب الخوارزمية الجغرافية؟');">
                                                <input type="hidden" name="target_emp_id" value="<?= $emp['emp_id']; ?>">
                                                <button type="submit" name="reassign_tasks" class="btn btn-sm btn-warning text-dark fw-bold rounded-pill"><i class="fa-solid fa-shuffle"></i> توزيع آلي</button>
                                            </form>
                                        </td>
                                        <td>
                                            <form method="POST">
                                                <input type="hidden" name="target_emp_id" value="<?= $emp['emp_id']; ?>">
                                                <button type="submit" name="send_warning" class="btn btn-sm btn-outline-danger fw-bold rounded-pill"><i class="fa-solid fa-envelope"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- الصفحة 4: قائمة الطلبات المرفوضة (تم تعديل التحذير ليكون نصياً فقط) -->
            <div id="page-archive" class="page-view">
                <div class="admin-card">
                    <div class="card-title text-secondary"><i class="fa-solid fa-shield-halved bg-secondary text-white rounded p-2"></i> سجل الطلبات المرفوضة</div>
                    <p class="text-muted fw-bold mb-4 small"><i class="fa-solid fa-eye me-1"></i> هذه القائمة للاطلاع الإداري والرقابة لضمان شفافية النظام.</p>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>الطلب</th><th>العميل</th><th>جهة الرفض</th><th>سبب الرفض</th></tr></thead>
                            <tbody>
                                <?php foreach($rejectedApps as $app): ?>
                                <tr>
                                    <td class="fw-bold text-muted">#<?= str_pad($app['app_id'], 5, '0', STR_PAD_LEFT); ?><br><span class="small"><?= $app['change_date']; ?></span></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($app['customer_name'] ?? '-'); ?></td>
                                    <td><?= $app['auditor_name'] ? "<span class='badge bg-secondary'>المدقق: ".htmlspecialchars($app['auditor_name'])."</span>" : "<span class='badge bg-dark'>رفض آلي (DSS)</span>" ?></td>
                                    <td><button class="btn btn-sm btn-outline-primary fw-bold rounded-pill" onclick="showReason('<?= htmlspecialchars($app['deed_no']) ?>', '<?= htmlspecialchars($app['rejection_reason']) ?>')"><i class="fa-solid fa-folder-open me-1"></i> عرض السبب</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- الصفحة 5: إدارة الموارد البشرية -->
            <div id="page-hr" class="page-view">
                <div class="row g-4">
                    <div class="col-lg-3">
                        <div class="admin-card h-100">
                            <div class="card-title"><i class="fa-solid fa-user-plus text-primary"></i> موظف جديد</div>
                            <form method="POST">
                                <div class="mb-3"><label class="fw-bold mb-1">الاسم الرباعي</label><input type="text" name="emp_name" class="form-control bg-light" required></div>
                                <div class="mb-3"><label class="fw-bold mb-1">البريد الإلكتروني</label><input type="email" name="emp_email" class="form-control bg-light" dir="ltr" required></div>
                                <div class="mb-3"><label class="fw-bold mb-1">كلمة المرور</label><input type="password" name="password" class="form-control bg-light" dir="ltr" required></div>
                                <div class="mb-3">
                                    <label class="fw-bold mb-1">المدينة</label>
                                    <select name="cty_id" class="form-select bg-light" required>
                                        <?php foreach($cities as $c): ?><option value="<?= $c['cty_id'] ?>"><?= $c['cty_name'] ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <label class="fw-bold mb-2">تحديد الصلاحيات:</label>
                                <div class="mb-4 p-3 border rounded bg-light">
                                    <?php foreach($rolesList as $role): 
                                        $lbl = roleLabelAr($role['role_name']);
                                    ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="roles[]" value="<?= $role['role_id'] ?>" id="r_<?= $role['role_id'] ?>">
                                            <label class="form-check-label fw-bold" for="r_<?= $role['role_id'] ?>"><?= $lbl ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="add_employee" class="btn-brand">حفظ الموظف</button>
                            </form>
                        </div>
                    </div>

                    <div class="col-lg-9">
                        <div class="admin-card h-100">
                            <div class="card-title"><i class="fa-solid fa-users text-primary"></i> قاعدة بيانات الموظفين الشاملة</div>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead><tr><th>الموظف</th><th>الصلاحية</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                                    <tbody>
                                        <?php foreach($employeesData as $emp): ?>
                                            <tr>
                                                <td><div class="fw-bold text-dark"><?= htmlspecialchars($emp['emp_name']) ?></div><div class="small text-muted" dir="ltr"><?= htmlspecialchars($emp['emp_email']) ?></div></td>
                                                <td>
                                                    <?php foreach(explode(',', $emp['roles']) as $r): 
                                                        $t = $r == 'Admin' ? 'مدير' : ($r == 'Auditor' ? 'مدقق' : ($r == 'Inspection Technician' ? 'فحص' : 'تركيب'));
                                                        echo "<span class='badge bg-light text-dark border me-1'>$t</span>";
                                                    endforeach; ?>
                                                </td>
                                                <td><?= $emp['is_active'] ? "<span class='badge bg-success'>نشط</span>" : "<span class='badge bg-danger'>موقوف</span>" ?></td>
                                                <td>
                                                    <div class="d-flex gap-2">
                                                        <button class="btn btn-sm btn-outline-primary rounded-pill fw-bold" data-bs-toggle="modal" data-bs-target="#editModal<?= $emp['emp_id'] ?>"><i class="fa-solid fa-pen-to-square"></i> تعديل</button>
                                                        <form method="POST">
                                                            <input type="hidden" name="target_emp_id" value="<?= $emp['emp_id'] ?>">
                                                            <button type="submit" name="toggle_status" class="btn btn-sm btn-outline-dark fw-bold rounded-pill">
                                                                <?= $emp['is_active'] ? '<i class="fa-solid fa-ban"></i> إيقاف الموظف' : '<i class="fa-solid fa-check"></i> تفعيل الموظف' ?>
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <div class="modal fade" id="editModal<?= $emp['emp_id'] ?>" tabindex="-1">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content" style="border-radius: 12px; border:none; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
                                                                <div class="modal-header border-0 pb-0">
                                                                    <h5 class="modal-title fw-black text-primary"><i class="fa-solid fa-pen-to-square me-2"></i> تعديل بيانات الموظف</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <form method="POST">
                                                                    <div class="modal-body">
                                                                        <input type="hidden" name="edit_emp_id" value="<?= $emp['emp_id'] ?>">
                                                                        <div class="mb-3"><label class="fw-bold mb-1">الاسم</label><input type="text" name="edit_emp_name" class="form-control" value="<?= htmlspecialchars($emp['emp_name']) ?>" required></div>
                                                                        <div class="mb-3"><label class="fw-bold mb-1">الإيميل</label><input type="email" name="edit_emp_email" class="form-control" dir="ltr" value="<?= htmlspecialchars($emp['emp_email']) ?>" required></div>
                                                                        <div class="mb-3">
                                                                            <label class="fw-bold mb-1">المدينة</label>
                                                                            <select name="edit_cty_id" class="form-select" required>
                                                                                <?php foreach($cities as $c): ?>
                                                                                    <option value="<?= $c['cty_id'] ?>" <?= $emp['cty_id'] == $c['cty_id'] ? 'selected' : '' ?>><?= $c['cty_name'] ?></option>
                                                                                <?php endforeach; ?>
                                                                            </select>
                                                                        </div>
                                                                        <label class="fw-bold mb-2">تحديث الصلاحيات:</label>
                                                                        <div class="border p-3 rounded bg-light">
                                                                            <?php 
                                                                            $empCurrentRoles = explode(',', $emp['role_ids'] ?? '');
                                                                            foreach($rolesList as $role): 
                                                                                $isChecked = in_array($role['role_id'], $empCurrentRoles) ? 'checked' : '';
                                                                                $lbl = roleLabelAr($role['role_name']);
                                                                            ?>
                                                                                <div class="form-check mb-2">
                                                                                    <input class="form-check-input" type="checkbox" name="edit_roles[]" id="edit_role_<?= $emp['emp_id'] ?>_<?= $role['role_id'] ?>" value="<?= $role['role_id'] ?>" <?= $isChecked ?>>
                                                                                    <label class="form-check-label fw-bold" for="edit_role_<?= $emp['emp_id'] ?>_<?= $role['role_id'] ?>"><?= $lbl ?></label>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer border-0">
                                                                        <button type="submit" name="edit_employee" class="btn-brand">حفظ التعديلات</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
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

        function showReason(deedNo, reason) {
            Swal.fire({
                title: 'تفاصيل الرفض',
                html: `<div style="text-align: right; background: #f8fafc; padding: 20px; border-radius: 12px;">
                        <p class="mb-3"><strong>رقم الصك:</strong> <span dir="ltr">${deedNo}</span></p>
                        <p class="text-danger fw-bold m-0">${reason}</p>
                       </div>`,
                confirmButtonColor: '#0b457f',
                confirmButtonText: 'إغلاق'
            });
        }
    </script>
</body>
</html>