<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// التحقق من تسجيل الدخول
if (!isset($_SESSION['emp_id'])) {
    header("Location: employee_login.php");
    exit;
}

if (empty($_SESSION['emp_roles'])) {
    die("<div style='text-align:center; padding:50px; font-family:tahoma;'><h2>حسابك لا يمتلك صلاحيات.</h2><a href='logout.php'>تسجيل الخروج</a></div>");
}

$empName = $_SESSION['emp_name'];

// =========================================================
// قراءة صارمة للصلاحيات من قاعدة البيانات (بدون أي توسعة برمجية)
// =========================================================
$roles = array_unique($_SESSION['emp_roles']); 

// =========================================================
// التوجيه عند الضغط على إحدى الصلاحيات
// =========================================================
if (isset($_GET['active_role']) && in_array($_GET['active_role'], $roles)) {
    $_SESSION['current_active_role'] = $_GET['active_role'];
    
    $role = $_GET['active_role'];
    if ($role == 'Admin') header("Location: admin_panel.php");
    elseif ($role == 'Auditor') header("Location: auditor_panel.php");
    elseif ($role == 'Inspection Technician') header("Location: inspection_panel.php");
    elseif ($role == 'Installation Technician') header("Location: installation_panel.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة النظام | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --qatra-navy: #092e54; --qatra-blue: #0b457f; --bg-color: #f4f7f6; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-color); margin: 0; min-height: 100vh; display: flex; flex-direction: column; }
        .header { background: white; border-bottom: 2px solid var(--qatra-blue); padding: 15px 5%; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .header .brand { color: var(--qatra-navy); font-weight: 800; font-size: 1.5rem; display: flex; align-items: center; gap: 10px; }
        .btn-logout { border: 1px solid #dc3545; color: #dc3545; padding: 8px 20px; border-radius: 8px; font-weight: 700; text-decoration: none; transition: 0.3s; }
        .btn-logout:hover { background: #dc3545; color: white; }
        .welcome-section { text-align: center; margin: 50px 0 40px; }
        .welcome-section h1 { color: var(--qatra-navy); font-weight: 800; }
        .roles-grid { display: flex; flex-wrap: wrap; justify-content: center; gap: 20px; max-width: 1000px; margin: 0 auto; padding: 0 15px; }
        .role-card { background: white; border: 1px solid #e0e0e0; border-radius: 12px; padding: 40px 25px; width: 260px; text-align: center; text-decoration: none; transition: 0.3s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .role-card:hover { transform: translateY(-5px); border-color: var(--qatra-blue); box-shadow: 0 10px 20px rgba(11,69,127,0.1); }
        .role-icon { width: 70px; height: 70px; background: #f0f4f8; color: var(--qatra-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 20px; transition: 0.3s; }
        .role-card:hover .role-icon { background: var(--qatra-blue); color: white; }
        .role-title { font-weight: 800; color: var(--qatra-navy); font-size: 1.2rem; margin-bottom: 10px; }
        .role-desc { color: #6c757d; font-size: 0.85rem; font-weight: 600; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand"><i class="fa-solid fa-droplet text-primary"></i> نظام قطرة المؤسسي</div>
        <a href="logout.php" class="btn-logout">خروج <i class="fa-solid fa-arrow-right-from-bracket ms-1"></i></a>
    </div>

    <div class="welcome-section">
        <h1>أهلاً بك، المهندس/ة <?= htmlspecialchars($empName); ?></h1>
        <p class="text-muted fw-bold">الرجاء اختيار مساحة العمل المراد الدخول إليها</p>
    </div>

    <div class="roles-grid">
        <?php if(in_array('Admin', $roles)): ?>
        <a href="?active_role=Admin" class="role-card">
            <div class="role-icon"><i class="fa-solid fa-user-shield"></i></div>
            <div class="role-title">مدير النظام</div>
            <div class="role-desc">إدارة الموظفين، المهام الشاملة، وإحصائيات كادر العمل.</div>
        </a>
        <?php endif; ?>

        <?php if(in_array('Auditor', $roles)): ?>
        <a href="?active_role=Auditor" class="role-card">
            <div class="role-icon"><i class="fa-solid fa-file-signature"></i></div>
            <div class="role-title">مدقق الطلبات</div>
            <div class="role-desc">مراجعة صكوك الملكية والطلبات المعلقة للعملاء.</div>
        </a>
        <?php endif; ?>

        <?php if(in_array('Inspection Technician', $roles)): ?>
        <a href="?active_role=<?= urlencode('Inspection Technician') ?>" class="role-card">
            <div class="role-icon"><i class="fa-solid fa-clipboard-check"></i></div>
            <div class="role-title">فني فحص</div>
            <div class="role-desc">إجراء الفحص الميداني للموقع ورفع الصور للطلبات.</div>
        </a>
        <?php endif; ?>

        <?php if(in_array('Installation Technician', $roles)): ?>
        <a href="?active_role=<?= urlencode('Installation Technician') ?>" class="role-card">
            <div class="role-icon"><i class="fa-solid fa-wrench"></i></div>
            <div class="role-title">فني تركيب</div>
            <div class="role-desc">استلام مهام التركيب للعدادات وتسجيل القراءات.</div>
        </a>
        <?php endif; ?>
    </div>
</body>
</html>