<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// التحقق من تسجيل الدخول وصلاحية مدير النظام (Admin)
if (!isset($_SESSION['emp_id']) || !in_array('Admin', $_SESSION['emp_roles'])) {
    header("Location: employee_dashboard.php");
    exit;
}

require_once 'db_connect.php';
$msg = ""; $msgType = "";

// تهيئة ذكية: تأكيد وجود الصلاحيات الأربعة فقط وإضافة عمود الحالة إن لم يوجد
try { 
    $pdo->query("SELECT is_active FROM company_employee LIMIT 1"); 
} catch (Exception $e) { 
    $pdo->exec("ALTER TABLE company_employee ADD COLUMN is_active BOOLEAN DEFAULT 1"); 
}

// مزامنة الصلاحيات الأربعة المعتمدة للنظام
$requiredRoles = ['Admin', 'Auditor', 'Inspection Technician', 'Installation Technician'];
foreach ($requiredRoles as $roleName) {
    $stmtCheck = $pdo->prepare("SELECT role_id FROM system_role WHERE role_name = ?");
    $stmtCheck->execute([$roleName]);
    if ($stmtCheck->rowCount() == 0) {
        $pdo->prepare("INSERT INTO system_role (role_name) VALUES (?)")->execute([$roleName]);
    }
}

// =========================================================
// معالجة العمليات (إضافة، تعديل، إيقاف، حذف) للموظفين
// =========================================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. إضافة موظف جديد
    if (isset($_POST['add_employee'])) {
        $empName = trim($_POST['emp_name']);
        $empEmail = trim($_POST['emp_email']);
        $empPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $ctyId = $_POST['cty_id'];
        $selectedRoles = $_POST['roles'] ?? [];

        if (empty($selectedRoles)) {
            $msg = "خطأ: يجب تحديد صلاحية واحدة على الأقل.";
            $msgType = "danger";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO company_employee (emp_name, emp_email, password_hash, cty_id, is_active, active_tasks_count) VALUES (?, ?, ?, ?, 1, 0)");
                $stmt->execute([$empName, $empEmail, $empPassword, $ctyId]);
                $newEmpId = $pdo->lastInsertId();

                foreach ($selectedRoles as $rId) {
                    $pdo->prepare("INSERT INTO employee_roles (emp_id, role_id) VALUES (?, ?)")->execute([$newEmpId, $rId]);
                }
                $msg = "تم تسجيل الموظف بنجاح.";
                $msgType = "success";
            } catch (PDOException $e) {
                $msg = "عفواً، البريد الإلكتروني مستخدم مسبقاً.";
                $msgType = "danger";
            }
        }
    }

    // 2. تحديث بيانات الموظف
    if (isset($_POST['edit_employee'])) {
        $eId = $_POST['edit_emp_id'];
        $eName = trim($_POST['edit_emp_name']);
        $eEmail = trim($_POST['edit_emp_email']);
        $eCty = $_POST['edit_cty_id'];
        $selectedRoles = $_POST['edit_roles'] ?? [];

        try {
            $pdo->prepare("UPDATE company_employee SET emp_name=?, emp_email=?, cty_id=? WHERE emp_id=?")->execute([$eName, $eEmail, $eCty, $eId]);
            $pdo->prepare("DELETE FROM employee_roles WHERE emp_id=?")->execute([$eId]);
            foreach ($selectedRoles as $rId) {
                $pdo->prepare("INSERT INTO employee_roles (emp_id, role_id) VALUES (?, ?)")->execute([$eId, $rId]);
            }
            $msg = "تم التحديث بنجاح.";
            $msgType = "success";
        } catch (Exception $e) {
            $msg = "خطأ في التحديث.";
            $msgType = "danger";
        }
    }

    // 3. إيقاف / تفعيل الموظف
    if (isset($_POST['toggle_status'])) {
        $eId = $_POST['target_emp_id'];
        $pdo->prepare("UPDATE company_employee SET is_active = NOT is_active WHERE emp_id = ?")->execute([$eId]);
        $msg = "تم تحديث حالة الموظف بنجاح.";
        $msgType = "success";
    }

    // 4. حذف سجل الموظف
    if (isset($_POST['delete_employee'])) {
        $eId = $_POST['target_emp_id'];
        try {
            $pdo->prepare("DELETE FROM company_employee WHERE emp_id = ?")->execute([$eId]);
            $msg = "تم حذف سجل الموظف بنجاح.";
            $msgType = "success";
        } catch (PDOException $e) {
            // في حال وجود معاملات مرتبطة به، نقوم بتجميد حسابه بأمان دون إفساد قاعدة البيانات
            $pdo->prepare("UPDATE company_employee SET is_active = 0 WHERE emp_id = ?")->execute([$eId]);
            $msg = "تم إيقاف حساب الموظف، لا يمكن حذفه نهائياً لوجود مهام مرتبطة بسجلاته.";
            $msgType = "warning";
        }
    }
}

// =========================================================
// جلب البيانات الشاملة لإدارة الموظفين واللوحة التشغيلية
// =========================================================
try {
    $cities = $pdo->query("SELECT * FROM city WHERE cty_name NOT IN ('الربيعية', 'الشماسية', 'الربيعيه', 'الشماسيه', 'رفحاء')")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS city (cty_id INT PRIMARY KEY AUTO_INCREMENT, cty_name VARCHAR(100)); INSERT IGNORE INTO city (cty_id, cty_name) VALUES (1, 'الرياض'), (2, 'جدة'), (3, 'الدمام');");
    $cities = $pdo->query("SELECT * FROM city WHERE cty_name NOT IN ('الربيعية', 'الشماسية', 'الربيعيه', 'الشماسيه', 'رفحاء')")->fetchAll(PDO::FETCH_ASSOC);
}

$empCount = $pdo->query("SELECT COUNT(*) FROM company_employee")->fetchColumn();

// جلب تفاصيل الموظفين مع أدوارهم
$employeesData = $pdo->query("
    SELECT ce.emp_id, ce.emp_name, ce.emp_email, ce.active_tasks_count, ce.is_active, ce.cty_id, c.cty_name,
           GROUP_CONCAT(DISTINCT sr.role_name SEPARATOR ',') as roles,
           GROUP_CONCAT(DISTINCT sr.role_id SEPARATOR ',') as role_ids
    FROM company_employee ce
    LEFT JOIN city c ON ce.cty_id = c.cty_id
    LEFT JOIN employee_roles er ON ce.emp_id = er.emp_id
    LEFT JOIN system_role sr ON er.role_id = sr.role_id
    GROUP BY ce.emp_id ORDER BY ce.emp_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// جلب الصلاحيات المتاحة
$rolesList = $pdo->query("SELECT MIN(role_id) as role_id, role_name FROM system_role GROUP BY role_name")->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// الرقابة والتقارير المتقدمة (التبويب الجديد)
// =========================================================
$reportStats = [
    'total_paid' => 0,
    'total_meters' => 0,
    'total_unified' => 0,
    'pending_review' => 0,
    'pending_inspection' => 0,
    'pending_billing' => 0,
    'completed' => 0,
];

try {
    $reportStats['total_paid'] = $pdo->query("SELECT SUM(amount) FROM invoice WHERE payment_status = 'Paid'")->fetchColumn() ?? 0;
    $reportStats['total_meters'] = $pdo->query("SELECT COUNT(*) FROM meter")->fetchColumn() ?? 0;
    $reportStats['total_unified'] = $pdo->query("SELECT COUNT(*) FROM unified_account")->fetchColumn() ?? 0;
    
    // إحصائيات الطلبات
    $appStatusCounts = $pdo->query("SELECT app_status, COUNT(*) as count FROM application GROUP BY app_status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $reportStats['pending_review'] = $appStatusCounts['Pending_Review'] ?? 0;
    $reportStats['pending_inspection'] = $appStatusCounts['Pending_Inspection'] ?? 0;
    $reportStats['pending_billing'] = $appStatusCounts['Pending_Billing'] ?? 0;
    $reportStats['completed'] = $appStatusCounts['Completed'] ?? 0;
} catch (Exception $e) {
    // معالجة صامتة
}

// جلب تاريخ العمليات وتتبع التأخير والرقابة (تاريخ تعديل الطلبات)
$auditLog = [];
try {
    $auditLog = $pdo->query("
        SELECT ah.hist_id, ah.status, ah.rejection_reason, ah.change_date, ah.app_id, ce.emp_name
        FROM application_history ah
        LEFT JOIN company_employee ce ON ah.changed_by = ce.emp_id
        ORDER BY ah.change_date DESC LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // معالجة صامتة
}

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الموارد والرقابة الذكية | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --primary: #092e54; 
            --secondary: #0b457f; 
            --accent: #4492d4; 
            --bg: #f4f6f9; 
            --card-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg); display: flex; min-height: 100vh; margin: 0; }
        
        /* تصميم الشريط الجانبي */
        .sidebar { width: 280px; background: var(--primary); color: white; display: flex; flex-direction: column; padding: 20px 0; box-shadow: -2px 0 10px rgba(0,0,0,0.1); z-index: 10; }
        .sidebar-brand { text-align: center; font-size: 1.8rem; font-weight: 800; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar-brand i { color: #7dd3fc; margin-left: 10px; }
        .nav-link-custom { color: #cbd3da; padding: 15px 25px; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 15px; transition: 0.3s; cursor: pointer; border-right: 4px solid transparent; }
        .nav-link-custom:hover, .nav-link-custom.active { background: var(--secondary); color: white; border-right-color: var(--accent); }
        
        .main-content { flex: 1; padding: 35px; overflow-y: auto; }
        
        /* البطاقات التشغيلية */
        .corporate-card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; padding: 25px; box-shadow: var(--card-shadow); margin-bottom: 25px; transition: 0.3s; }
        .corporate-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .card-header-title { font-weight: 900; color: var(--primary); margin-bottom: 25px; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
        
        /* شارات التحذير والتحميل الزائد للفنيين */
        .workload-warning { background: #fff3cd; border: 1px solid #ffe69c; color: #856404; animation: blinkWarning 1.5s infinite; }
        @keyframes blinkWarning { 0% { opacity: 0.8; } 50% { opacity: 1; } 100% { opacity: 0.8; } }
        
        .form-label { font-weight: 800; color: #495057; font-size: 0.9rem; }
        .form-control, .form-select { border-radius: 10px; border: 2px solid #e2e8f0; padding: 12px 15px; font-weight: 600; transition: 0.3s; }
        .form-control:focus, .form-select:focus { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(68,146,212,0.12); outline: none; }
        
        .checkbox-group { display: flex; flex-wrap: wrap; gap: 10px; }
        .checkbox-group label { background: #f8f9fa; border: 2px solid #e2e8f0; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 0.85rem; transition: 0.2s; }
        .checkbox-group input[type="checkbox"] { display: none; }
        .checkbox-group input[type="checkbox"]:checked + label { background: #e3f2fd; border-color: var(--accent); color: var(--secondary); }
        
        .btn-brand { background: var(--secondary); color: white; border: none; padding: 14px 20px; border-radius: 10px; font-weight: 800; width: 100%; transition: 0.3s; }
        .btn-brand:hover { background: var(--primary); }
        
        /* جداول عصرية */
        .table th { background: #f8fafc; color: var(--primary); font-weight: 800; font-size: 0.85rem; padding: 18px 15px; border-bottom: 2px solid #e2e8f0; }
        .table td { padding: 18px 15px; vertical-align: middle; font-weight: 700; border-bottom: 1px solid #f1f5f9; }
        .badge-role { padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; background: #f1f5f9; color: var(--primary); margin: 2px; display: inline-flex; align-items: center; gap: 5px; font-weight: 700; border: 1px solid #e2e8f0; }
        .status-badge { padding: 6px 14px; border-radius: 50px; font-size: 0.75rem; font-weight: 800; }
        
        .btn-action { background: none; border: none; padding: 6px 10px; border-radius: 6px; font-size: 1rem; transition: 0.2s; }
        .btn-edit { color: var(--accent); } .btn-edit:hover { background: #e3f2fd; }
        .btn-suspend { color: #fd7e14; } .btn-suspend:hover { background: #fff3cd; }
        .btn-delete { color: #dc3545; } .btn-delete:hover { background: #fef2f2; }
        
        /* تبويبات لوحة التحكم */
        .tab-panel { display: none; }
        .tab-panel.active { display: block; animation: tabFade 0.4s ease-in-out; }
        @keyframes tabFade { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* مؤشرات إحصائية */
        .kpi-card { border-radius: 16px; padding: 25px; border: 1px solid #e2e8f0; background: white; text-align: right; display: flex; align-items: center; justify-content: space-between; box-shadow: var(--card-shadow); }
        .kpi-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; }
        .kpi-val { font-size: 2rem; font-weight: 900; color: var(--primary); line-height: 1.1; margin-bottom: 5px; }
        .kpi-lbl { font-size: 0.85rem; font-weight: 700; color: #64748b; }
        
        /* أشرطة التقدم */
        .progress-bar-custom { height: 10px; border-radius: 50px; background: #e2e8f0; overflow: hidden; margin-top: 10px; }
        .progress-bar-fill { height: 100%; border-radius: 50px; }
    </style>
</head>
<body>

    <!-- الشريط الجانبي الفخم -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <i class="fa-solid fa-droplet"></i>قــطــرة
            <div class="text-[10px] font-bold text-info opacity-75 mt-1">منصة الرقابة والتشغيل الذكي</div>
        </div>
        
        <a class="nav-link-custom active" onclick="switchPanel('hr-management', this)">
            <i class="fa-solid fa-users-gear"></i> إدارة الموارد البشرية
        </a>
        <a class="nav-link-custom" onclick="switchPanel('supervision-hub', this)">
            <i class="fa-solid fa-chart-line"></i> لوحة التقارير والرقابة
        </a>
        
        <div style="margin-top: auto;">
            <a href="logout.php" class="nav-link-custom text-danger">
                <i class="fa-solid fa-power-off"></i> تسجيل الخروج
            </a>
        </div>
    </div>

    <!-- المحتوى الرئيسي للوحة -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold" style="color: var(--primary);" id="page-title">إدارة الموارد البشرية</h2>
                <p class="text-muted fw-bold m-0" id="page-desc">البوابة التشغيلية الكاملة للتحكم في حسابات الموظفين وصلاحياتهم.</p>
            </div>
            <div class="text-end">
                <div class="fw-bold"><?= htmlspecialchars($_SESSION['emp_name']); ?></div>
                <span class="badge bg-secondary fw-black mt-1">مدير النظام (Admin)</span>
            </div>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-<?= $msgType ?> fw-bold alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i><?= $msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- ========================================================= -->
        <!-- التبويب 1: إدارة الموارد البشرية (CRUD الأصلي) -->
        <!-- ========================================================= -->
        <div id="hr-management" class="tab-panel active">
            <div class="row g-4">
                
                <!-- نموذج إضافة موظف جديد -->
                <div class="col-lg-3">
                    <div class="corporate-card h-100">
                        <div class="card-header-title">
                            <span><i class="fa-solid fa-user-plus me-1"></i> إضافة موظف جديد</span>
                        </div>
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">اسم الموظف رباعياً</label>
                                <input type="text" name="emp_name" class="form-control" placeholder="أدخل اسم الموظف" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">البريد الإلكتروني الرسمي</label>
                                <input type="email" name="emp_email" class="form-control" dir="ltr" placeholder="username@qatra.com" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">كلمة المرور المؤقتة</label>
                                <input type="password" name="password" class="form-control" dir="ltr" placeholder="******" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">مدينة العمل والنطاق</label>
                                <select name="cty_id" class="form-select" required>
                                    <?php foreach($cities as $c): ?>
                                        <option value="<?= $c['cty_id'] ?>"><?= str_replace('مدينة ', '', $c['cty_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label class="form-label">تحديد الصلاحيات الميدانية</label>
                                <div class="checkbox-group">
                                    <?php foreach($rolesList as $role): 
                                        $label = $role['role_name'];
                                        if($label == 'Admin') $label = 'مدير';
                                        elseif($label == 'Auditor') $label = 'مدقق';
                                        elseif($label == 'Inspection Technician') $label = 'فني فحص';
                                        elseif($label == 'Installation Technician') $label = 'فني تركيب';
                                    ?>
                                        <div>
                                            <input type="checkbox" name="roles[]" id="role_<?= $role['role_id'] ?>" value="<?= $role['role_id'] ?>">
                                            <label for="role_<?= $role['role_id'] ?>"><?= $label ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <button type="submit" name="add_employee" class="btn-brand">
                                <i class="fa-solid fa-floppy-disk me-1"></i> حفظ وتسجيل الموظف
                            </button>
                        </form>
                    </div>
                </div>

                <!-- جدول عرض سجلات الموظفين الحالية -->
                <div class="col-lg-9">
                    <div class="corporate-card h-100">
                        <div class="card-header-title">
                            <span><i class="fa-solid fa-users me-1"></i> سجلات الموظفين الفعالة (<?= $empCount; ?>)</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>الموظف</th>
                                        <th>المدينة</th>
                                        <th>الصلاحيات</th>
                                        <th class="text-center">المهام الميدانية</th>
                                        <th>الحالة</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($employeesData as $emp): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($emp['emp_name']) ?></div>
                                                <div class="text-muted small" style="font-family: Tahoma;"><?= htmlspecialchars($emp['emp_email']) ?></div>
                                            </td>
                                            <td><span class="text-muted"><i class="fa-solid fa-location-dot text-danger small me-1"></i> <?= htmlspecialchars(str_replace('مدينة ', '', $emp['cty_name'] ?? 'غير محدد')) ?></span></td>
                                            <td>
                                                <?php 
                                                if ($emp['roles']) {
                                                    foreach(explode(',', $emp['roles']) as $r) {
                                                        $translated = $r;
                                                        if($r == 'Admin') $translated = 'مدير';
                                                        elseif($r == 'Auditor') $translated = 'مدقق';
                                                        elseif($r == 'Inspection Technician') $translated = 'فني فحص';
                                                        elseif($r == 'Installation Technician') $translated = 'فني تركيب';
                                                        echo "<span class='badge-role'>$translated</span>";
                                                    }
                                                } else {
                                                    echo "<span class='text-muted'>-</span>";
                                                }
                                                ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if(strpos($emp['roles'], 'Technician') !== false): 
                                                    $isOverloaded = ($emp['active_tasks_count'] > 3);
                                                ?>
                                                    <span class="badge rounded-pill px-3 py-2 <?= $isOverloaded ? 'bg-danger workload-warning' : 'bg-info text-dark' ?>" title="<?= $isOverloaded ? 'تحذير: تحميل زائد على الفني!' : 'المهام المعلقة طبيعية' ?>">
                                                        <?= $emp['active_tasks_count'] ?> مهام
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= $emp['is_active'] ? 
                                                    '<span class="status-badge bg-success text-white"><i class="fa-solid fa-circle-check"></i> نشط</span>' : 
                                                    '<span class="status-badge bg-danger text-white"><i class="fa-solid fa-circle-xmark"></i> موقوف</span>' 
                                                ?>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <!-- تعديل -->
                                                    <button class="btn-action btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?= $emp['emp_id'] ?>" title="تعديل"><i class="fa-solid fa-pen-to-square"></i></button>
                                                    
                                                    <!-- إيقاف -->
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="target_emp_id" value="<?= $emp['emp_id'] ?>">
                                                        <button type="submit" name="toggle_status" class="btn-action btn-suspend" title="تجميد/تفعيل"><i class="fa-solid fa-power-off"></i></button>
                                                    </form>
                                                    
                                                    <!-- حذف -->
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('تنبيه: هل أنت متأكد من رغبتك في حذف سجل الموظف؟')">
                                                        <input type="hidden" name="target_emp_id" value="<?= $emp['emp_id'] ?>">
                                                        <button type="submit" name="delete_employee" class="btn-action btn-delete" title="حذف نهائي"><i class="fa-solid fa-trash-can"></i></button>
                                                    </form>
                                                </div>

                                                <!-- نافذة تعديل بيانات الموظف (Modal) -->
                                                <div class="modal fade" id="editModal<?= $emp['emp_id'] ?>" tabindex="-1">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 15px 30px rgba(0,0,0,0.1);">
                                                            <div class="modal-header border-0 pb-0">
                                                                <h5 class="modal-title fw-black"><i class="fa-solid fa-user-pen text-primary"></i> تحديث سجل الموظف</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form method="POST">
                                                                <div class="modal-body">
                                                                    <input type="hidden" name="edit_emp_id" value="<?= $emp['emp_id'] ?>">
                                                                    <div class="mb-3 text-start">
                                                                        <label class="form-label">الاسم الكامل</label>
                                                                        <input type="text" name="edit_emp_name" class="form-control" value="<?= htmlspecialchars($emp['emp_name']) ?>" required>
                                                                    </div>
                                                                    <div class="mb-3 text-start">
                                                                        <label class="form-label">البريد الإلكتروني</label>
                                                                        <input type="email" name="edit_emp_email" class="form-control" dir="ltr" value="<?= htmlspecialchars($emp['emp_email']) ?>" required>
                                                                    </div>
                                                                    <div class="mb-3 text-start">
                                                                        <label class="form-label">المدينة</label>
                                                                        <select name="edit_cty_id" class="form-select" required>
                                                                            <?php foreach($cities as $c): ?>
                                                                                <option value="<?= $c['cty_id'] ?>" <?= $emp['cty_id'] == $c['cty_id'] ? 'selected' : '' ?>><?= str_replace('مدينة ', '', $c['cty_name']) ?></option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                    <div class="text-start mb-2">
                                                                        <label class="form-label">صلاحيات الموظف</label>
                                                                        <div class="checkbox-group">
                                                                            <?php 
                                                                            $empCurrentRoles = explode(',', $emp['role_ids'] ?? '');
                                                                            foreach($rolesList as $role): 
                                                                                $isChecked = in_array($role['role_id'], $empCurrentRoles) ? 'checked' : '';
                                                                                $label = $role['role_name'];
                                                                                if($label == 'Admin') $label = 'مدير';
                                                                                elseif($label == 'Auditor') $label = 'مدقق';
                                                                                elseif($label == 'Inspection Technician') $label = 'فني فحص';
                                                                                elseif($label == 'Installation Technician') $label = 'فني تركيب';
                                                                            ?>
                                                                                <div>
                                                                                    <input type="checkbox" name="edit_roles[]" id="edit_role_<?= $emp['emp_id'] ?>_<?= $role['role_id'] ?>" value="<?= $role['role_id'] ?>" <?= $isChecked ?>>
                                                                                    <label for="edit_role_<?= $emp['emp_id'] ?>_<?= $role['role_id'] ?>"><?= $label ?></label>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer border-0">
                                                                    <button type="submit" name="edit_employee" class="btn-brand"><i class="fa-solid fa-circle-check"></i> حفظ التحديثات</button>
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

        <!-- ========================================================= -->
        <!-- التبويب 2: لوحة التقارير والرقابة التشغيلية (الجديد بالكامل) -->
        <!-- ========================================================= -->
        <div id="supervision-hub" class="tab-panel">
            
            <!-- أرقام تشغيلية وKPIs ممتازة -->
            <div class="row g-4 mb-5">
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div>
                            <div class="kpi-val text-success"><?= number_format($reportStats['total_paid']); ?> <span class="fs-6 font-monospace">ريال</span></div>
                            <div class="kpi-lbl">إجمالي التحصيلات والمبيعات الميدانية</div>
                        </div>
                        <div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="fa-solid fa-wallet"></i></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div>
                            <div class="kpi-val text-info"><?= number_format($reportStats['total_meters']); ?> <span class="fs-6 font-monospace">عداد</span></div>
                            <div class="kpi-lbl">العدادات الذكية المربوطة والمقروءة</div>
                        </div>
                        <div class="kpi-icon bg-info bg-opacity-10 text-info"><i class="fa-solid fa-microchip"></i></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div>
                            <div class="kpi-val text-primary"><?= number_format($reportStats['total_unified']); ?> <span class="fs-6 font-monospace">عقار</span></div>
                            <div class="kpi-lbl">الحسابات الموحدة والمفعلة بالشبكة</div>
                        </div>
                        <div class="kpi-icon bg-primary bg-opacity-10 text-primary"><i class="fa-solid fa-building-circle-check"></i></div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                
                <!-- عمود مراقبة الحالات وسير المعاملات (Progress Charts) -->
                <div class="col-lg-4">
                    <div class="corporate-card h-100">
                        <div class="card-header-title">
                            <span><i class="fa-solid fa-chart-pie me-1"></i> تتبع انسيابية دورة حياة الطلبات</span>
                        </div>
                        
                        <div class="mb-4">
                            <div class="d-flex justify-content-between font-bold text-dark">
                                <span><i class="fa-solid fa-file-invoice text-muted me-1"></i> طلبات بانتظار التدقيق</span>
                                <span><?= $reportStats['pending_review']; ?> طلبات</span>
                            </div>
                            <div class="progress-bar-custom">
                                <div class="progress-bar-fill bg-warning" style="width: <?= min(100, $reportStats['pending_review'] * 10); ?>%"></div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between font-bold text-dark">
                                <span><i class="fa-solid fa-helmet-safety text-info me-1"></i> طلبات الفحص الجاري</span>
                                <span><?= $reportStats['pending_inspection']; ?> طلبات</span>
                            </div>
                            <div class="progress-bar-custom">
                                <div class="progress-bar-fill bg-info" style="width: <?= min(100, $reportStats['pending_inspection'] * 10); ?>%"></div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between font-bold text-dark">
                                <span><i class="fa-solid fa-credit-card text-primary me-1"></i> طلبات بانتظار الفوترة والسداد</span>
                                <span><?= $reportStats['pending_billing']; ?> طلبات</span>
                            </div>
                            <div class="progress-bar-custom">
                                <div class="progress-bar-fill bg-primary" style="width: <?= min(100, $reportStats['pending_billing'] * 10); ?>%"></div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between font-bold text-dark">
                                <span><i class="fa-solid fa-circle-check text-success me-1"></i> طلبات منجزة وموصولة بالكامل</span>
                                <span><?= $reportStats['completed']; ?> طلبات</span>
                            </div>
                            <div class="progress-bar-custom">
                                <div class="progress-bar-fill bg-success" style="width: <?= min(100, $reportStats['completed'] * 5); ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="bg-light p-3 rounded-3 text-center border mt-4">
                            <h6 class="fw-bold mb-1"><i class="fa-solid fa-circle-info text-primary"></i> نظام الجدولة والرقابة الذاتية:</h6>
                            <p class="small text-muted m-0">يتم تصفية وحساب الطلبات بناءً على حركات فنيي الفحص والتركيب الميدانيين آلياً في الوقت الفعلي.</p>
                        </div>
                    </div>
                </div>

                <!-- جدول مراقبة تحميل الفنيين (Technician Load Watchdog) -->
                <div class="col-lg-8">
                    <div class="corporate-card">
                        <div class="card-header-title">
                            <span><i class="fa-solid fa-gauge-high me-1"></i> مراقب كفاءة توزيع المهام على الفنيين</span>
                            <span class="badge bg-danger rounded-pill fw-bold" style="font-size:0.75rem;">تنبيه فوري بالضغط</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>الفني الميداني</th>
                                        <th>المدينة</th>
                                        <th>المهام المعلقة</th>
                                        <th>مؤشر الضغط والتحميل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $hasTech = false;
                                    foreach($employeesData as $emp) {
                                        if (strpos($emp['roles'], 'Technician') !== false) {
                                            $hasTech = true;
                                            $loadRatio = min(100, ($emp['active_tasks_count'] / 5) * 100);
                                            $colorClass = ($emp['active_tasks_count'] > 3) ? 'bg-danger' : (($emp['active_tasks_count'] > 1) ? 'bg-warning' : 'bg-success');
                                            ?>
                                            <tr>
                                                <td class="fw-bold"><?= htmlspecialchars($emp['emp_name']); ?></td>
                                                <td><i class="fa-solid fa-location-dot text-danger small"></i> <?= htmlspecialchars(str_replace('مدينة ', '', $emp['cty_name'] ?? 'غير محدد')); ?></td>
                                                <td>
                                                    <span class="badge <?= ($emp['active_tasks_count'] > 3) ? 'bg-danger workload-warning' : 'bg-secondary' ?> px-3 py-1 fw-black">
                                                        <?= $emp['active_tasks_count']; ?> مهام نشطة
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="progress" style="height: 10px; border-radius: 50px;">
                                                        <div class="progress-bar <?= $colorClass; ?>" role="progressbar" style="width: <?= $loadRatio; ?>%"></div>
                                                    </div>
                                                    <small class="text-muted d-block mt-1"><?= ($emp['active_tasks_count'] > 3) ? '⚠️ حمولة تشغيلية زائدة!' : 'توزيع مستقر ومتوازن' ?></small>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    }
                                    if(!$hasTech): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4">لا يوجد أي فني فحص أو تركيب مسجل حالياً بالنظام لتتبع كفاءته.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- الأرشيف الأمني وتاريخ الحركات وتتبع التأخير -->
                    <div class="corporate-card mt-4">
                        <div class="card-header-title">
                            <span><i class="fa-solid fa-clock-rotate-left me-1"></i> أرشيف تتبع الحركات التاريخية للطلبات (Audit Log)</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>رقم الطلب</th>
                                        <th>حركة الطلب</th>
                                        <th>المسؤول</th>
                                        <th>التفاصيل والمبرر</th>
                                        <th>تاريخ التعديل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($auditLog)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-4">لا توجد حركات تاريخية مسجلة في الأرشيف الميداني بعد.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($auditLog as $log): ?>
                                            <tr>
                                                <td class="font-monospace text-muted">#<?= str_pad($log['app_id'], 5, '0', STR_PAD_LEFT); ?></td>
                                                <td><?= getStatusBadge($log['status']); ?></td>
                                                <td class="fw-bold text-dark"><?= htmlspecialchars($log['emp_name'] ?? 'محرك DSS الذكي'); ?></td>
                                                <td class="text-muted small"><?= htmlspecialchars($log['rejection_reason'] ?? 'تحديث الحالة دورياً عبر النظام'); ?></td>
                                                <td class="small text-secondary"><?= $log['change_date']; ?></td>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // التبديل السلس والتفاعلي الفوري بين التبويبات الفاخرة لمدير النظام
        function switchPanel(panelId, btnElement) {
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.nav-link-custom').forEach(b => b.classList.remove('active'));
            
            const targetPanel = document.getElementById(panelId);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
            if (btnElement) {
                btnElement.classList.add('active');
            }
            
            // تحديث العناوين الفرعية
            const titleElement = document.getElementById('page-title');
            const descElement = document.getElementById('page-desc');
            
            if (panelId === 'hr-management') {
                titleElement.innerText = "إدارة الموارد البشرية";
                descElement.innerText = "البوابة التشغيلية الكاملة للتحكم في حسابات الموظفين وصلاحياتهم.";
            } else if (panelId === 'supervision-hub') {
                titleElement.innerText = "لوحة التقارير والرقابة";
                descElement.innerText = "تتبع كفاءة الموظفين، الإيرادات المالية المحصلة، والتحذيرات التشغيلية الحية.";
            }
        }
    </script>
</body>
</html>
