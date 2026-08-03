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

// تهيئة ذكية: تأكيد وجود الصلاحيات الأربعة فقط وإضافة عمود الحالة إن لم يوجد
try { $pdo->query("SELECT is_active FROM company_employee LIMIT 1"); } 
catch (Exception $e) { $pdo->exec("ALTER TABLE company_employee ADD COLUMN is_active BOOLEAN DEFAULT 1"); }

$pdo->exec("DELETE FROM system_role WHERE role_name = 'Technician'");
$requiredRoles = ['Admin', 'Auditor', 'Inspection Technician', 'Installation Technician'];
foreach ($requiredRoles as $roleName) {
    $stmtCheck = $pdo->prepare("SELECT role_id FROM system_role WHERE role_name = ?");
    $stmtCheck->execute([$roleName]);
    if ($stmtCheck->rowCount() == 0) {
        $pdo->prepare("INSERT INTO system_role (role_name) VALUES (?)")->execute([$roleName]);
    }
}

// 🌟 معالجة العمليات (إضافة، تعديل، إيقاف، حذف)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. إضافة موظف جديد (شامل جميع الحقول)
    if (isset($_POST['add_employee'])) {
        $empName = trim($_POST['emp_name']);
        $empEmail = trim($_POST['emp_email']);
        $empPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $ctyId = $_POST['cty_id']; // المدينة
        $selectedRoles = $_POST['roles'] ?? [];
        
        if (empty($selectedRoles)) {
            $msg = "خطأ: يجب تحديد صلاحية واحدة على الأقل."; $msgType = "danger";
        } else {
            try {
                // الإدراج في Company_Employee الشامل
                $stmt = $pdo->prepare("INSERT INTO company_employee (emp_name, emp_email, password_hash, cty_id, is_active, active_tasks_count) VALUES (?, ?, ?, ?, 1, 0)");
                $stmt->execute([$empName, $empEmail, $empPassword, $ctyId]);
                $newEmpId = $pdo->lastInsertId();
                
                // إدراج الصلاحيات المتعددة في Employee_Roles
                foreach ($selectedRoles as $rId) {
                    $pdo->prepare("INSERT INTO employee_roles (emp_id, role_id) VALUES (?, ?)")->execute([$newEmpId, $rId]);
                }
                $msg = "تم تسجيل الموظف بنجاح."; $msgType = "success";
            } catch (PDOException $e) { $msg = "البريد الإلكتروني مستخدم مسبقاً."; $msgType = "danger"; }
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
            $msg = "تم التحديث بنجاح."; $msgType = "success";
        } catch (Exception $e) { $msg = "خطأ في التحديث."; $msgType = "danger"; }
    }

    // 3. إيقاف/تفعيل
    if (isset($_POST['toggle_status'])) {
        $eId = $_POST['target_emp_id'];
        $pdo->prepare("UPDATE company_employee SET is_active = NOT is_active WHERE emp_id = ?")->execute([$eId]);
        $msg = "تم تحديث حالة الموظف."; $msgType = "success";
    }

    // 4. حذف
    if (isset($_POST['delete_employee'])) {
        $eId = $_POST['target_emp_id'];
        try {
            $pdo->prepare("DELETE FROM company_employee WHERE emp_id = ?")->execute([$eId]);
            $msg = "تم حذف سجل الموظف."; $msgType = "success";
        } catch (PDOException $e) {
            $pdo->prepare("UPDATE company_employee SET is_active = 0 WHERE emp_id = ?")->execute([$eId]);
            $msg = "تم إيقاف الموظف، لا يمكن حذفه لوجود مهام مرتبطة."; $msgType = "warning";
        }
    }
}

// --- جلب البيانات الشاملة ---
// التأكد من وجود مدينة افتراضية للعمل عليها
try { $pdo->query("SELECT * FROM city LIMIT 1"); } 
catch(Exception $e) { $pdo->exec("CREATE TABLE IF NOT EXISTS city (cty_id INT PRIMARY KEY AUTO_INCREMENT, cty_name VARCHAR(100)); INSERT IGNORE INTO city (cty_id, cty_name) VALUES (1, 'الرياض'), (2, 'جدة'), (3, 'الدمام');"); }

$cities = $pdo->query("SELECT * FROM city")->fetchAll(PDO::FETCH_ASSOC);
$empCount = $pdo->query("SELECT COUNT(*) FROM company_employee")->fetchColumn();

// جلب تفاصيل الموظفين الشاملة (الاسم، الإيميل، المدينة، المهام، الحالة، الصلاحيات)
$employeesData = $pdo->query("
    SELECT ce.emp_id, ce.emp_name, ce.emp_email, ce.active_tasks_count, ce.is_active, ce.cty_id, c.cty_name,
           GROUP_CONCAT(DISTINCT sr.role_name SEPARATOR ',') as roles, GROUP_CONCAT(DISTINCT sr.role_id SEPARATOR ',') as role_ids
    FROM company_employee ce
    LEFT JOIN city c ON ce.cty_id = c.cty_id
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
    <title>إدارة الموظفين | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root { --primary: #092e54; --secondary: #0b457f; --accent: #4492d4; --bg: #f4f6f9; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg); display: flex; min-height: 100vh; margin: 0; }
        
        /* شريط جانبي مؤسسي احترافي */
        .sidebar { width: 260px; background: var(--primary); color: white; display: flex; flex-direction: column; padding: 20px 0; box-shadow: -2px 0 10px rgba(0,0,0,0.1); z-index: 10; }
        .sidebar-brand { text-align: center; font-size: 1.8rem; font-weight: 800; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .nav-link { color: #cbd3da; padding: 12px 25px; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 15px; transition: 0.3s; }
        .nav-link:hover, .nav-link.active { background: var(--secondary); color: white; border-right: 4px solid var(--accent); }
        
        .main-content { flex: 1; padding: 30px; overflow-y: auto; }
        
        /* بطاقات ونماذج */
        .corporate-card { background: white; border-radius: 8px; border: 1px solid #e0e4e8; padding: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); margin-bottom: 25px; }
        .card-header-title { font-weight: 800; color: var(--primary); margin-bottom: 25px; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
        
        .form-label { font-weight: 700; color: #495057; font-size: 0.9rem; }
        .form-control, .form-select { border-radius: 6px; border: 1px solid #ced4da; padding: 10px 15px; font-weight: 600; }
        .form-control:focus, .form-select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(68, 146, 212, 0.15); }
        
        .checkbox-group { display: flex; flex-wrap: wrap; gap: 10px; }
        .checkbox-group label { background: #f8f9fa; border: 1px solid #dee2e6; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 0.85rem; transition: 0.2s; }
        .checkbox-group input[type="checkbox"] { display: none; }
        .checkbox-group input[type="checkbox"]:checked + label { background: #e3f2fd; border-color: var(--accent); color: var(--secondary); }
        
        .btn-brand { background: var(--secondary); color: white; border: none; padding: 12px; border-radius: 6px; font-weight: 800; width: 100%; transition: 0.3s; }
        .btn-brand:hover { background: var(--primary); }

        /* الجدول الشامل */
        .table-responsive { overflow-x: auto; }
        .table th { background: #f8f9fa; color: #495057; font-weight: 800; font-size: 0.85rem; padding: 15px; white-space: nowrap; }
        .table td { padding: 15px; vertical-align: middle; font-weight: 600; border-bottom: 1px solid #f0f0f0; }
        
        .badge-role { padding: 5px 10px; border-radius: 4px; font-size: 0.75rem; background: #e9ecef; color: #495057; margin: 2px; display: inline-block; }
        .status-badge { padding: 5px 12px; border-radius: 50px; font-size: 0.75rem; font-weight: 700; }
        
        .btn-action { background: none; border: none; padding: 5px 10px; border-radius: 4px; font-size: 1rem; transition: 0.2s; }
        .btn-edit { color: var(--accent); } .btn-edit:hover { background: #e3f2fd; }
        .btn-suspend { color: #fd7e14; } .btn-suspend:hover { background: #fff3cd; }
        .btn-delete { color: #dc3545; } .btn-delete:hover { background: #f8d7da; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand"><i class="fa-solid fa-droplet text-info"></i> قطرة</div>
    <a href="employee_dashboard.php" class="nav-link"><i class="fa-solid fa-house"></i> شاشة التوجيه</a>
    <a href="#" class="nav-link active"><i class="fa-solid fa-users-gear"></i> الإدارة الشاملة</a>
    <div style="margin-top: auto;">
        <a href="logout.php" class="nav-link text-danger"><i class="fa-solid fa-power-off"></i> خروج</a>
    </div>
</div>

<div class="main-content">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold" style="color: var(--primary);">إدارة الموارد البشرية</h3>
            <p class="text-muted fw-bold m-0">قاعدة البيانات الشاملة لجميع الموظفين والصلاحيات</p>
        </div>
        <div class="text-end">
            <div class="fw-bold"><?= htmlspecialchars($_SESSION['emp_name']); ?></div>
            <div class="text-info fw-bold" style="font-size: 0.85rem;">مدير النظام</div>
        </div>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-<?= $msgType ?> fw-bold"><i class="fa-solid fa-circle-info me-2"></i><?= $msg; ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- 🌟 نموذج الإضافة الشامل -->
        <div class="col-lg-3">
            <div class="corporate-card h-100">
                <div class="card-header-title">إضافة موظف</div>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">اسم الموظف</label>
                        <input type="text" name="emp_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">البريد الإلكتروني</label>
                        <input type="email" name="emp_email" class="form-control" dir="ltr" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">كلمة المرور</label>
                        <input type="password" name="password" class="form-control" dir="ltr" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المدينة / المنطقة</label>
                        <select name="cty_id" class="form-select" required>
                            <?php foreach($cities as $c): ?>
                                <option value="<?= $c['cty_id'] ?>"><?= $c['cty_name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <label class="form-label">تحديد الصلاحيات</label>
                    <div class="checkbox-group mb-4">
                        <?php foreach($rolesList as $role): 
                            $label = $role['role_name'];
                            if($label == 'Admin') $label = 'مدير'; elseif($label == 'Auditor') $label = 'مدقق';
                            elseif($label == 'Inspection Technician') $label = 'فني فحص'; elseif($label == 'Installation Technician') $label = 'فني تركيب';
                        ?>
                            <div>
                                <input type="checkbox" name="roles[]" id="role_<?= $role['role_id'] ?>" value="<?= $role['role_id'] ?>">
                                <label for="role_<?= $role['role_id'] ?>"><?= $label ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="add_employee" class="btn-brand">حفظ البيانات</button>
                </form>
            </div>
        </div>

        <!-- 🌟 الجدول الشامل لجميع بيانات الموظف -->
        <div class="col-lg-9">
            <div class="corporate-card h-100">
                <div class="card-header-title">
                    سجلات الموظفين (<?= $empCount ?>)
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>الموظف</th>
                                <th>المدينة</th>
                                <th>الصلاحيات</th>
                                <th class="text-center">المهام النشطة</th>
                                <th>الحالة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($employeesData as $emp): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold" style="color: var(--primary);"><?= htmlspecialchars($emp['emp_name']) ?></div>
                                    <div class="text-muted" style="font-size: 0.8rem; font-family: Tahoma;"><?= htmlspecialchars($emp['emp_email']) ?></div>
                                </td>
                                <td class="text-muted"><?= htmlspecialchars($emp['cty_name'] ?? 'غير محدد') ?></td>
                                <td>
                                    <?php 
                                        if ($emp['roles']) {
                                            foreach(explode(',', $emp['roles']) as $r) {
                                                $translated = $r;
                                                if($r == 'Admin') $translated = 'مدير'; elseif($r == 'Auditor') $translated = 'مدقق';
                                                elseif($r == 'Inspection Technician') $translated = 'فحص'; elseif($r == 'Installation Technician') $translated = 'تركيب';
                                                echo "<span class='badge-role'>$translated</span>";
                                            }
                                        } else { echo "<span class='text-muted'>-</span>"; }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <?php if(strpos($emp['roles'], 'Technician') !== false): ?>
                                        <span class="badge bg-<?= $emp['active_tasks_count'] > 3 ? 'danger' : 'info' ?> rounded-pill"><?= $emp['active_tasks_count'] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($emp['is_active']): ?>
                                        <span class="status-badge bg-success text-white">نشط</span>
                                    <?php else: ?>
                                        <span class="status-badge bg-danger text-white">موقوف</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex">
                                        <!-- زر التعديل -->
                                        <button class="btn-action btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?= $emp['emp_id'] ?>" title="تعديل"><i class="fa-solid fa-pen-to-square"></i></button>
                                        <!-- الإيقاف -->
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="target_emp_id" value="<?= $emp['emp_id'] ?>">
                                            <button type="submit" name="toggle_status" class="btn-action btn-suspend" title="تغيير الحالة"><i class="fa-solid fa-power-off"></i></button>
                                        </form>
                                        <!-- الحذف -->
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('تأكيد الحذف؟');">
                                            <input type="hidden" name="target_emp_id" value="<?= $emp['emp_id'] ?>">
                                            <button type="submit" name="delete_employee" class="btn-action btn-delete" title="حذف"><i class="fa-solid fa-trash-can"></i></button>
                                        </form>
                                    </div>

                                    <!-- 🌟 نافذة التعديل الشاملة -->
                                    <div class="modal fade" id="editModal<?= $emp['emp_id'] ?>" tabindex="-1">
                                      <div class="modal-dialog">
                                        <div class="modal-content" style="border-radius: 8px;">
                                          <div class="modal-header">
                                            <h5 class="modal-title fw-bold">تحديث السجل</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                          </div>
                                          <form method="POST">
                                              <div class="modal-body">
                                                  <input type="hidden" name="edit_emp_id" value="<?= $emp['emp_id'] ?>">
                                                  <div class="mb-3">
                                                      <label class="form-label">الاسم</label>
                                                      <input type="text" name="edit_emp_name" class="form-control" value="<?= htmlspecialchars($emp['emp_name']) ?>" required>
                                                  </div>
                                                  <div class="mb-3">
                                                      <label class="form-label">الإيميل</label>
                                                      <input type="email" name="edit_emp_email" class="form-control" dir="ltr" value="<?= htmlspecialchars($emp['emp_email']) ?>" required>
                                                  </div>
                                                  <div class="mb-3">
                                                      <label class="form-label">المدينة</label>
                                                      <select name="edit_cty_id" class="form-select" required>
                                                          <?php foreach($cities as $c): ?>
                                                              <option value="<?= $c['cty_id'] ?>" <?= $emp['cty_id'] == $c['cty_id'] ? 'selected' : '' ?>><?= $c['cty_name'] ?></option>
                                                          <?php endforeach; ?>
                                                      </select>
                                                  </div>
                                                  <label class="form-label">الصلاحيات:</label>
                                                  <div class="checkbox-group">
                                                      <?php 
                                                      $empCurrentRoles = explode(',', $emp['role_ids'] ?? '');
                                                      foreach($rolesList as $role): 
                                                          $isChecked = in_array($role['role_id'], $empCurrentRoles) ? 'checked' : '';
                                                          $label = $role['role_name'];
                                                          if($label == 'Admin') $label = 'مدير'; elseif($label == 'Auditor') $label = 'مدقق';
                                                          elseif($label == 'Inspection Technician') $label = 'فحص'; elseif($label == 'Installation Technician') $label = 'تركيب';
                                                      ?>
                                                          <div>
                                                              <input type="checkbox" name="edit_roles[]" id="edit_role_<?= $emp['emp_id'] ?>_<?= $role['role_id'] ?>" value="<?= $role['role_id'] ?>" <?= $isChecked ?>>
                                                              <label for="edit_role_<?= $emp['emp_id'] ?>_<?= $role['role_id'] ?>"><?= $label ?></label>
                                                          </div>
                                                      <?php endforeach; ?>
                                                  </div>
                                              </div>
                                              <div class="modal-footer">
                                                <button type="submit" name="edit_employee" class="btn-brand">حفظ</button>
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