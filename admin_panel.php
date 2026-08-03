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

// 🌟 1. تنظيف قاعدة البيانات وضمان عدم التكرار للـ 4 أدوار
$pdo->exec("DELETE FROM system_role WHERE role_name = 'Technician'");
$requiredRoles = ['Admin', 'Auditor', 'Inspection Technician', 'Installation Technician'];
foreach ($requiredRoles as $roleName) {
    $stmtCheck = $pdo->prepare("SELECT role_id FROM system_role WHERE role_name = ?");
    $stmtCheck->execute([$roleName]);
    if ($stmtCheck->rowCount() == 0) {
        $pdo->prepare("INSERT INTO system_role (role_name) VALUES (?)")->execute([$roleName]);
    }
}

// 🌟 2. العمليات (CRUD)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // إضافة
    if (isset($_POST['add_employee'])) {
        $empName = trim($_POST['emp_name']); $empEmail = trim($_POST['emp_email']);
        $empPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $selectedRoles = $_POST['roles'] ?? [];
        if (empty($selectedRoles)) {
            $msg = "يجب تحديد صلاحية واحدة على الأقل."; $msgType = "danger";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO company_employee (emp_name, emp_email, password_hash, cty_id, is_active) VALUES (?, ?, ?, 1, 1)");
                $stmt->execute([$empName, $empEmail, $empPassword]);
                $newEmpId = $pdo->lastInsertId();
                foreach ($selectedRoles as $rId) {
                    $pdo->prepare("INSERT INTO employee_roles (emp_id, role_id) VALUES (?, ?)")->execute([$newEmpId, $rId]);
                }
                $msg = "تم إنشاء حساب الموظف بنجاح."; $msgType = "success";
            } catch (PDOException $e) { $msg = "البريد الإلكتروني مسجل مسبقاً."; $msgType = "danger"; }
        }
    }
    // تعديل
    if (isset($_POST['edit_employee'])) {
        $eId = $_POST['edit_emp_id']; $eName = trim($_POST['edit_emp_name']); $eEmail = trim($_POST['edit_emp_email']);
        $selectedRoles = $_POST['edit_roles'] ?? [];
        try {
            $pdo->prepare("UPDATE company_employee SET emp_name = ?, emp_email = ? WHERE emp_id = ?")->execute([$eName, $eEmail, $eId]);
            $pdo->prepare("DELETE FROM employee_roles WHERE emp_id = ?")->execute([$eId]);
            foreach ($selectedRoles as $rId) { $pdo->prepare("INSERT INTO employee_roles (emp_id, role_id) VALUES (?, ?)")->execute([$eId, $rId]); }
            $msg = "تم تحديث البيانات."; $msgType = "success";
        } catch (Exception $e) { $msg = "خطأ بالتحديث."; $msgType = "danger"; }
    }
    // إيقاف
    if (isset($_POST['toggle_status'])) {
        $eId = $_POST['target_emp_id'];
        $pdo->prepare("UPDATE company_employee SET is_active = NOT is_active WHERE emp_id = ?")->execute([$eId]);
        $msg = "تم تحديث حالة الحساب."; $msgType = "success";
    }
    // حذف
    if (isset($_POST['delete_employee'])) {
        $eId = $_POST['target_emp_id'];
        try {
            $pdo->prepare("DELETE FROM company_employee WHERE emp_id = ?")->execute([$eId]);
            $msg = "تم الحذف نهائياً."; $msgType = "success";
        } catch (PDOException $e) {
            $pdo->prepare("UPDATE company_employee SET is_active = 0 WHERE emp_id = ?")->execute([$eId]);
            $msg = "تم إيقاف الموظف لعدم إمكانية حذفه (لوجود سجلات)."; $msgType = "warning";
        }
    }
}

// --- جلب البيانات ---
$empCount = $pdo->query("SELECT COUNT(*) FROM company_employee")->fetchColumn();
$employeesData = $pdo->query("
    SELECT ce.*, GROUP_CONCAT(DISTINCT sr.role_name SEPARATOR ',') as roles, GROUP_CONCAT(DISTINCT sr.role_id SEPARATOR ',') as role_ids
    FROM company_employee ce
    LEFT JOIN employee_roles er ON ce.emp_id = er.emp_id
    LEFT JOIN system_role sr ON er.role_id = sr.role_id
    GROUP BY ce.emp_id ORDER BY ce.emp_id DESC
")->fetchAll(PDO::FETCH_ASSOC);
$rolesList = $pdo->query("SELECT MIN(role_id) as role_id, role_name FROM system_role GROUP BY role_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة الإدارة الملونة | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root { --navy: #0f172a; --blue-dark: #1e3a8a; --blue-light: #3b82f6; --bg: #f4f7fb; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg); margin: 0; display: flex; min-height: 100vh; overflow-x: hidden; }
        
        /* القائمة الجانبية الفاخرة */
        .sidebar { width: 280px; background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%); color: white; padding: 30px 20px; display: flex; flex-direction: column; box-shadow: -10px 0 30px rgba(0,0,0,0.15); z-index: 100; }
        .sidebar .brand { text-align: center; margin-bottom: 40px; font-weight: 900; font-size: 2rem; color: white; display: flex; flex-direction: column; align-items: center; }
        .sidebar .brand i { font-size: 3rem; background: linear-gradient(45deg, #38bdf8, #fff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 5px; }
        
        .nav-link { color: #94a3b8; font-weight: 800; padding: 16px 20px; border-radius: 16px; margin-bottom: 12px; transition: all 0.3s; display: flex; align-items: center; gap: 15px; text-decoration: none; }
        .nav-link:hover { background: rgba(255,255,255,0.05); color: white; transform: translateX(-5px); }
        .nav-link.active { background: linear-gradient(90deg, #3b82f6, #2563eb); color: white; box-shadow: 0 10px 20px rgba(59, 130, 246, 0.4); }
        
        .main-content { flex: 1; padding: 40px; overflow-y: auto; }
        
        /* رأس الصفحة الملون */
        .page-header { background: white; padding: 25px 35px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 4px solid #3b82f6; }
        .page-header h2 { font-weight: 900; color: var(--navy); margin: 0; }
        
        /* البطاقات الإحصائية الملونة */
        .stat-card { border-radius: 24px; padding: 30px; color: white; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 15px 35px rgba(0,0,0,0.1); position: relative; overflow: hidden; transition: 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card::after { content: ''; position: absolute; right: -20px; top: -20px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%; }
        .bg-gradient-blue { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .bg-gradient-purple { background: linear-gradient(135deg, #7c3aed, #8b5cf6); }
        
        /* نماذج الإدخال والجداول */
        .card-custom { background: white; border-radius: 24px; padding: 35px; box-shadow: 0 15px 35px rgba(0,0,0,0.04); margin-bottom: 30px; border: none; }
        .form-control { border-radius: 14px; padding: 14px 20px; font-weight: 700; background: #f8fafc; border: 2px solid #e2e8f0; color: #1e293b; }
        .form-control:focus { border-color: #3b82f6; background: white; box-shadow: 0 0 0 5px rgba(59,130,246,0.15); }
        
        /* أزرار الصلاحيات التفاعلية */
        .role-check-group { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .role-check-group input[type="checkbox"] { display: none; }
        .role-check-label { padding: 15px; border-radius: 16px; background: #f8fafc; color: #64748b; font-weight: 800; border: 2px solid #e2e8f0; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 10px; font-size: 0.95rem; }
        .role-check-group input[type="checkbox"]:checked + .role-check-label { background: #eff6ff; border-color: #3b82f6; color: #2563eb; box-shadow: 0 8px 20px rgba(59,130,246,0.15); transform: translateY(-2px); }
        
        .btn-gradient { background: linear-gradient(135deg, #10b981, #059669); color: white; font-weight: 900; font-size: 1.1rem; border-radius: 14px; padding: 16px; border: none; width: 100%; transition: 0.3s; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3); }
        .btn-gradient:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(16, 185, 129, 0.5); color: white; }

        /* الجدول والشارات */
        .table th { color: #94a3b8; font-weight: 800; padding: 15px; border-bottom: 2px solid #f1f5f9; text-transform: uppercase; }
        .table td { padding: 20px 15px; vertical-align: middle; font-weight: 700; border-bottom: 1px solid #f8fafc; color: var(--navy); }
        
        .role-badge { padding: 8px 14px; border-radius: 10px; font-size: 0.8rem; font-weight: 900; margin: 3px; display: inline-flex; align-items: center; gap: 5px; }
        .role-badge.admin { background: #ffe4e6; color: #e11d48; }
        .role-badge.auditor { background: #fef3c7; color: #d97706; }
        .role-badge.insp { background: #dcfce7; color: #16a34a; }
        .role-badge.inst { background: #e0f2fe; color: #0284c7; }
        
        .status-badge { padding: 6px 12px; border-radius: 50px; font-size: 0.8rem; font-weight: 800; display: inline-block; }
        .status-active { background: #10b981; color: white; box-shadow: 0 5px 15px rgba(16,185,129,0.3); }
        .status-suspended { background: #ef4444; color: white; box-shadow: 0 5px 15px rgba(239,68,68,0.3); }
        
        /* أزرار الإجراءات */
        .btn-action { width: 38px; height: 38px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; border: none; transition: 0.3s; margin-left: 5px; font-size: 1rem; color: white; }
        .btn-edit { background: #3b82f6; box-shadow: 0 5px 15px rgba(59,130,246,0.3); } .btn-edit:hover { background: #2563eb; transform: translateY(-2px); }
        .btn-suspend { background: #f59e0b; box-shadow: 0 5px 15px rgba(245,158,11,0.3); } .btn-suspend:hover { background: #d97706; transform: translateY(-2px); }
        .btn-delete { background: #ef4444; box-shadow: 0 5px 15px rgba(239,68,68,0.3); } .btn-delete:hover { background: #dc2626; transform: translateY(-2px); }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="brand"><i class="fa-solid fa-droplet"></i> <span>QATRA</span></div>
    
    <a href="employee_dashboard.php" class="nav-link" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);"><i class="fa-solid fa-arrow-right"></i> العودة لشاشة التوجيه</a>
    <div style="margin: 15px 0; border-bottom: 1px solid rgba(255,255,255,0.1);"></div>
    
    <a href="#" class="nav-link active"><i class="fa-solid fa-users"></i> إدارة الموظفين</a>
    <a href="#" class="nav-link"><i class="fa-solid fa-chart-pie"></i> التقارير الذكية</a>
    <a href="#" class="nav-link"><i class="fa-solid fa-shield-halved"></i> سجل التدقيق</a>
    
    <div style="margin-top: auto;">
        <a href="logout.php" class="nav-link" style="background: rgba(239,68,68,0.15); color: #fca5a5;"><i class="fa-solid fa-power-off"></i> تسجيل الخروج</a>
    </div>
</div>

<div class="main-content">
    <div class="page-header">
        <div>
            <h2>المنصة الإدارية الشاملة</h2>
            <p class="text-muted fw-bold m-0 mt-1">تحكم كامل بصلاحيات وبيانات كادر النظام</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="text-end">
                <div class="fw-black text-dark" style="font-size: 1.1rem;"><?= htmlspecialchars($_SESSION['emp_name']); ?></div>
                <div class="text-primary fw-bold" style="font-size: 0.85rem;">المدير العام للنظام</div>
            </div>
            <div style="width: 55px; height: 55px; background: linear-gradient(135deg, #3b82f6, #1e3a8a); color: white; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; box-shadow: 0 10px 20px rgba(59,130,246,0.4);"><i class="fa-solid fa-user-tie"></i></div>
        </div>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-<?= $msgType ?> fw-bold rounded-4 p-4 shadow-sm" style="font-size: 1.1rem;"><i class="fa-solid fa-bell me-2"></i><?= $msg; ?></div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="stat-card bg-gradient-blue">
                <div>
                    <h5 class="fw-bold opacity-75 mb-2">إجمالي الموظفين</h5>
                    <h2 class="fw-black m-0" style="font-size: 3rem;"><?= $empCount; ?></h2>
                </div>
                <i class="fa-solid fa-users" style="font-size: 4rem; opacity: 0.2;"></i>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stat-card bg-gradient-purple">
                <div>
                    <h5 class="fw-bold opacity-75 mb-2">الصلاحيات المهيأة</h5>
                    <h2 class="fw-black m-0" style="font-size: 3rem;"><?= count($rolesList); ?></h2>
                </div>
                <i class="fa-solid fa-layer-group" style="font-size: 4rem; opacity: 0.2;"></i>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- نموذج الإضافة الاحترافي -->
        <div class="col-lg-4">
            <div class="card-custom h-100">
                <h4 class="fw-black mb-4" style="color: var(--navy);"><i class="fa-solid fa-user-plus me-2" style="color: #3b82f6;"></i> إصدار حساب</h4>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-bold">الاسم الرباعي</label>
                        <input type="text" name="emp_name" class="form-control" required placeholder="أدخل اسم الموظف">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">البريد الإلكتروني الرسمي</label>
                        <input type="email" name="emp_email" class="form-control text-start" dir="ltr" required placeholder="name@qatra.com">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold">كلمة المرور المؤقتة</label>
                        <input type="password" name="password" class="form-control text-start" dir="ltr" required placeholder="*****">
                    </div>
                    
                    <label class="form-label fw-bold mb-3">الصلاحيات (اختر واحدة أو أكثر):</label>
                    <div class="role-check-group mb-5">
                        <?php foreach($rolesList as $role): 
                            $icon = 'fa-check'; $label = $role['role_name'];
                            if($label == 'Admin') { $icon = 'fa-user-tie'; $label = 'مدير'; }
                            elseif($label == 'Auditor') { $icon = 'fa-file-signature'; $label = 'مدقق'; }
                            elseif($label == 'Inspection Technician') { $icon = 'fa-clipboard-check'; $label = 'فني فحص'; }
                            elseif($label == 'Installation Technician') { $icon = 'fa-wrench'; $label = 'فني تركيب'; }
                        ?>
                            <div>
                                <input type="checkbox" name="roles[]" id="role_<?= $role['role_id'] ?>" value="<?= $role['role_id'] ?>">
                                <label class="role-check-label" for="role_<?= $role['role_id'] ?>"><i class="fa-solid <?= $icon ?>"></i> <?= $label ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="add_employee" class="btn-gradient">تسجيل واعتماد <i class="fa-solid fa-check ms-1"></i></button>
                </form>
            </div>
        </div>

        <!-- جدول الموظفين الملون -->
        <div class="col-lg-8">
            <div class="card-custom h-100">
                <h4 class="fw-black mb-4" style="color: var(--navy);"><i class="fa-solid fa-server me-2" style="color: #3b82f6;"></i> قاعدة بيانات النظام</h4>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>اسم الموظف</th>
                                <th>الأدوار المسندة</th>
                                <th>الحالة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($employeesData as $emp): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div style="width: 45px; height: 45px; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 900; color: #3b82f6; font-size: 1.2rem;">
                                            <?= mb_substr($emp['emp_name'], 0, 1, 'UTF-8') ?>
                                        </div>
                                        <div>
                                            <div class="fw-black" style="font-size: 1.1rem;"><?= htmlspecialchars($emp['emp_name']) ?></div>
                                            <div class="text-muted fw-bold" style="font-size: 0.85rem; font-family: Tahoma;"><?= htmlspecialchars($emp['emp_email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                        if ($emp['roles']) {
                                            $empRolesArray = explode(',', $emp['roles']);
                                            foreach($empRolesArray as $r) {
                                                $class = 'role-badge'; $icon = ''; $translated = $r;
                                                if($r == 'Admin') { $class .= ' admin'; $icon = 'fa-user-tie'; $translated = 'مدير'; }
                                                elseif($r == 'Auditor') { $class .= ' auditor'; $icon = 'fa-file-signature'; $translated = 'مدقق'; }
                                                elseif($r == 'Inspection Technician') { $class .= ' insp'; $icon = 'fa-clipboard-check'; $translated = 'فحص'; }
                                                elseif($r == 'Installation Technician') { $class .= ' inst'; $icon = 'fa-wrench'; $translated = 'تركيب'; }
                                                echo "<span class='$class'><i class='fa-solid $icon'></i> $translated</span>";
                                            }
                                        } else { echo "<span class='badge bg-secondary rounded-pill'>-</span>"; }
                                    ?>
                                </td>
                                <td>
                                    <?php if($emp['is_active']): ?>
                                        <span class="status-badge status-active">نشط</span>
                                    <?php else: ?>
                                        <span class="status-badge status-suspended">موقوف</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex">
                                        <!-- زر التعديل -->
                                        <button type="button" class="btn-action btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?= $emp['emp_id'] ?>" title="تعديل"><i class="fa-solid fa-pen"></i></button>
                                        <!-- الإيقاف -->
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="target_emp_id" value="<?= $emp['emp_id'] ?>">
                                            <button type="submit" name="toggle_status" class="btn-action btn-suspend" title="<?= $emp['is_active'] ? 'إيقاف مؤقت' : 'تفعيل' ?>"><i class="fa-solid <?= $emp['is_active'] ? 'fa-ban' : 'fa-play' ?>"></i></button>
                                        </form>
                                        <!-- الحذف -->
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('تأكيد الحذف النهائي؟');">
                                            <input type="hidden" name="target_emp_id" value="<?= $emp['emp_id'] ?>">
                                            <button type="submit" name="delete_employee" class="btn-action btn-delete" title="حذف"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>

                                    <!-- Modal للتعديل -->
                                    <div class="modal fade" id="editModal<?= $emp['emp_id'] ?>" tabindex="-1" aria-hidden="true">
                                      <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content" style="border-radius: 24px; border: none; padding: 15px;">
                                          <div class="modal-header border-0">
                                            <h4 class="modal-title fw-black"><i class="fa-solid fa-pen-to-square text-primary me-2"></i> تحديث البيانات</h4>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                          </div>
                                          <form method="POST">
                                              <div class="modal-body">
                                                  <input type="hidden" name="edit_emp_id" value="<?= $emp['emp_id'] ?>">
                                                  <div class="mb-3">
                                                      <label class="form-label fw-bold">اسم الموظف</label>
                                                      <input type="text" name="edit_emp_name" class="form-control" value="<?= htmlspecialchars($emp['emp_name']) ?>" required>
                                                  </div>
                                                  <div class="mb-4">
                                                      <label class="form-label fw-bold">البريد الإلكتروني</label>
                                                      <input type="email" name="edit_emp_email" class="form-control text-start" dir="ltr" value="<?= htmlspecialchars($emp['emp_email']) ?>" required>
                                                  </div>
                                                  <label class="form-label fw-bold mb-3">تحديث الصلاحيات:</label>
                                                  <div class="role-check-group">
                                                      <?php 
                                                      $empCurrentRoles = explode(',', $emp['role_ids'] ?? '');
                                                      foreach($rolesList as $role): 
                                                          $isChecked = in_array($role['role_id'], $empCurrentRoles) ? 'checked' : '';
                                                          $label = $role['role_name'];
                                                          if($label == 'Admin') $label = 'مدير';
                                                          elseif($label == 'Auditor') $label = 'مدقق';
                                                          elseif($label == 'Inspection Technician') $label = 'فحص';
                                                          elseif($label == 'Installation Technician') $label = 'تركيب';
                                                      ?>
                                                          <div>
                                                              <input type="checkbox" name="edit_roles[]" id="edit_role_<?= $emp['emp_id'] ?>_<?= $role['role_id'] ?>" value="<?= $role['role_id'] ?>" <?= $isChecked ?>>
                                                              <label class="role-check-label" for="edit_role_<?= $emp['emp_id'] ?>_<?= $role['role_id'] ?>"><?= $label ?></label>
                                                          </div>
                                                      <?php endforeach; ?>
                                                  </div>
                                              </div>
                                              <div class="modal-footer border-0">
                                                <button type="submit" name="edit_employee" class="btn-gradient w-100">حفظ التغييرات</button>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>