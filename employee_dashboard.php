<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// التحقق من أن الموظف مسجل دخوله فعلاً
if (!isset($_SESSION['emp_id'])) {
    header("Location: employee_login.php");
    exit;
}

// حماية إضافية: إذا لم يكن لديه صلاحيات، ننهي الجلسة
if (empty($_SESSION['emp_roles'])) {
    die("<div style='text-align:center; margin-top:100px; font-family:tahoma;'><h2>عفواً، حسابك لا يمتلك أي صلاحيات حالياً.</h2><a href='logout.php'>تسجيل الخروج</a></div>");
}

$empName = $_SESSION['emp_name'];
$roles = $_SESSION['emp_roles']; // المصفوفة التي تحتوي على الصلاحيات

// إذا اختار الموظف صلاحية معينة، نحفظها ونوجهه للوحة المناسبة
if (isset($_GET['active_role']) && in_array($_GET['active_role'], $roles)) {
    $_SESSION['current_active_role'] = $_GET['active_role'];
    
    if ($_GET['active_role'] == 'Admin') header("Location: admin_panel.php");
    elseif ($_GET['active_role'] == 'Auditor') header("Location: auditor_panel.php");
    elseif ($_GET['active_role'] == 'Technician') header("Location: technician_panel.php");
    exit;
}

// 🌟 الحل الجذري والآمن باستخدام دالة reset() لتجنب أي أخطاء
if (count($roles) === 1 && !isset($_SESSION['current_active_role'])) {
    $singleRole = reset($roles); // دالة reset تستخرج النص الأول من المصفوفة بأمان
    header("Location: ?active_role=" . urlencode($singleRole));
    exit;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اختيار الصلاحية | نظام قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --qatra-navy: #092e54; 
            --qatra-blue: #0b457f; 
            --qatra-light: #4492d4; 
            --bg-color: #f8fafc;
        }
        body { 
            font-family: 'Cairo', sans-serif; 
            background-color: var(--bg-color); 
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
        }

        .hero-section {
            background: linear-gradient(180deg, #093c6f 0%, #10599c 100%);
            padding: 20px 5%;
            position: relative;
            padding-bottom: 80px;
        }
        .user-welcome {
            color: white;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .user-avatar {
            width: 50px; height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; backdrop-filter: blur(5px);
        }
        
        .wave-bottom { position: absolute; bottom: 0; left: 0; width: 100%; overflow: hidden; line-height: 0; transform: translateY(1px); }
        .wave-bottom svg { display: block; width: calc(100% + 1.3px); height: 50px; }
        .wave-bottom .shape-fill { fill: var(--bg-color); }

        .content-container {
            margin-top: -40px;
            position: relative;
            z-index: 10;
            padding: 0 5%;
            flex-grow: 1;
        }
        
        .section-title {
            color: var(--qatra-navy);
            font-weight: 900;
            margin-bottom: 30px;
            text-align: center;
        }

        .roles-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 25px;
            justify-content: center;
            max-width: 900px;
            margin: 0 auto;
        }

        .role-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 40px 30px;
            width: 280px;
            text-align: center;
            text-decoration: none;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 10px 30px rgba(11, 69, 127, 0.05);
            position: relative;
            overflow: hidden;
        }
        .role-card::before {
            content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 5px;
            background: var(--qatra-light);
            transform: scaleX(0); transition: 0.4s; transform-origin: right;
        }
        
        .role-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(11, 69, 127, 0.15);
            border-color: var(--qatra-light);
        }
        .role-card:hover::before { transform: scaleX(1); }

        .role-icon {
            width: 80px; height: 80px;
            background: #f1f5f9;
            color: var(--qatra-blue);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 20px;
            transition: 0.4s;
        }
        .role-card:hover .role-icon { background: var(--qatra-blue); color: white; transform: rotate(-5deg); }

        .role-title { font-weight: 900; color: var(--qatra-navy); font-size: 1.4rem; margin-bottom: 10px; }
        .role-desc { color: #64748b; font-size: 0.95rem; font-weight: 600; line-height: 1.6; }

        .btn-logout {
            position: absolute; left: 5%; top: 25px;
            background: rgba(255,255,255,0.1); color: white;
            border: 1px solid rgba(255,255,255,0.3); padding: 8px 20px;
            border-radius: 50px; text-decoration: none; font-weight: 700;
            transition: 0.3s; backdrop-filter: blur(5px);
        }
        .btn-logout:hover { background: rgba(255,255,255,0.2); color: white; }
    </style>
</head>
<body>

<div class="hero-section">
    <a href="logout.php" class="btn-logout"><i class="fa-solid fa-power-off ms-1"></i> تسجيل الخروج</a>
    
    <div class="user-welcome">
        <div class="user-avatar"><i class="fa-regular fa-user"></i></div>
        <div>
            <h4 class="m-0 fw-black">مرحباً بك، <?= htmlspecialchars($empName); ?></h4>
            <span class="text-info fw-bold" style="font-size: 0.9rem;">لديك (<?= count($roles); ?>) صلاحيات مرتبطة بحسابك</span>
        </div>
    </div>

    <div class="wave-bottom">
        <svg data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120" preserveAspectRatio="none">
            <path d="M321.39,56.44c58-10.79,114.16-30.13,172-41.86,82.39-16.72,168.19-17.73,250.45-.39C823.78,31,906.67,72,985.66,92.83c70.05,18.48,146.53,26.09,214.34,3V120H0V95.8C59.71,118.08,130.83,119.33,197.8,109.1,239.5,102.73,280.9,82.52,321.39,56.44Z" class="shape-fill"></path>
        </svg>
    </div>
</div>

<div class="content-container">
    <h2 class="section-title">يرجى تحديد مساحة العمل (Workspace)</h2>
    
    <div class="roles-grid">
        
        <?php if(in_array('Admin', $roles)): ?>
        <a href="?active_role=Admin" class="role-card">
            <div class="role-icon"><i class="fa-solid fa-user-tie"></i></div>
            <div class="role-title">مدير النظام</div>
            <div class="role-desc">إدارة النظام بالكامل، متابعة الإحصائيات، وإدارة حسابات الموظفين والعملاء.</div>
        </a>
        <?php endif; ?>

        <?php if(in_array('Auditor', $roles)): ?>
        <a href="?active_role=Auditor" class="role-card">
            <div class="role-icon"><i class="fa-solid fa-file-signature"></i></div>
            <div class="role-title">مدقق الطلبات</div>
            <div class="role-desc">مراجعة الطلبات المعلقة يدوياً، تدقيق الصكوك العقارية، وقبول أو رفض الطلبات.</div>
        </a>
        <?php endif; ?>

        <?php if(in_array('Technician', $roles)): ?>
        <a href="?active_role=Technician" class="role-card">
            <div class="role-icon"><i class="fa-solid fa-helmet-safety"></i></div>
            <div class="role-title">الفني الميداني</div>
            <div class="role-desc">استلام مهام الفحص الميداني، رفع صور المواقع، وتوثيق تركيب العدادات.</div>
        </a>
        <?php endif; ?>

    </div>
</div>

</body>
</html>