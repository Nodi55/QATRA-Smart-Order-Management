<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// حماية من حلقة التوجيه: إذا كان مسجلاً ولكن بدون صلاحيات، ننهي جلسته 
if (isset($_SESSION['emp_id'])) {
    if (empty($_SESSION['emp_roles'])) {
        session_unset();
        session_destroy();
    } else {
        header("Location: employee_dashboard.php");
        exit;
    }
}

require_once 'db_connect.php';
$errorMsg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['emp_email']);
    $password = trim($_POST['password']);
    $employee = null;

    try {
        $stmt = $pdo->prepare("SELECT emp_id, emp_name, emp_email, password_hash, cty_id, active_tasks_count FROM company_employee WHERE emp_email = ?");
        $stmt->execute([$email]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $errorMsg = "حدث خطأ في الاتصال بقاعدة البيانات.";
    }

    if ($employee) {
        if ($password === $employee['password_hash'] || password_verify($password, $employee['password_hash'])) {
            $rolesArray = [];
            try {
                $roleStmt = $pdo->prepare("SELECT sr.role_name FROM employee_roles er JOIN system_role sr ON er.role_id = sr.role_id WHERE er.emp_id = ?");
                $roleStmt->execute([$employee['emp_id']]);
                $rolesArray = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (PDOException $e) {}

            if (empty($rolesArray)) {
                $errorMsg = "حسابك موجود ولكن لا تمتلك أي صلاحيات لدخول النظام.";
            } else {
                $otpCode = rand(100000, 999999);
                
                // --- التعديل الأمني: تهيئة جدول OTP ليقبل الموظفين وتسجيل الرمز في قاعدة البيانات ---
                try {
                    $pdo->query("SELECT emp_id FROM otp_code LIMIT 1");
                } catch (Exception $e) {
                    $pdo->exec("ALTER TABLE otp_code ADD COLUMN emp_id INT NULL, ADD CONSTRAINT fk_otp_emp FOREIGN KEY (emp_id) REFERENCES company_employee(emp_id) ON DELETE CASCADE");
                }

                $stmtOtp = $pdo->prepare("INSERT INTO otp_code (code, expiry_time, is_used, emp_id) VALUES (?, DATE_ADD(NOW(), INTERVAL 2 MINUTE), 0, ?)");
                $stmtOtp->execute([$otpCode, $employee['emp_id']]);
                // -------------------------------------------------------------------------

                $_SESSION['temp_emp_otp'] = $otpCode; // تستخدم للمحاكاة (الإشعار المنبثق) في الواجهة فقط
                $_SESSION['temp_emp_id'] = $employee['emp_id'];
                $_SESSION['temp_emp_name'] = $employee['emp_name'];
                $_SESSION['temp_emp_email'] = $employee['emp_email'];
                $_SESSION['temp_emp_city_id'] = $employee['cty_id'];
                $_SESSION['temp_active_tasks'] = $employee['active_tasks_count'];
                $_SESSION['temp_emp_roles'] = $rolesArray;

                header("Location: employee_verify_otp.php");
                exit;
            }
        } else {
            $errorMsg = "كلمة المرور غير صحيحة.";
        }
    } else {
        $errorMsg = "البريد الإلكتروني غير مسجل في منظومة الموظفين.";
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة الموظفين | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root { --qatra-navy: #092e54; --qatra-blue: #0b457f; --qatra-light: #4492d4; --qatra-cyan: #7dd3fc; }
        body { font-family: 'Cairo', sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background-color: var(--qatra-navy); overflow: hidden; position: relative; }
        
        .bg-animation { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; background: radial-gradient(circle at top right, #10599c 0%, var(--qatra-navy) 70%); }
        .water-drop { position: absolute; bottom: -100px; background: linear-gradient(180deg, rgba(125, 211, 252, 0.1) 0%, rgba(125, 211, 252, 0.4) 100%); border-radius: 50%; animation: floatUp infinite ease-in; backdrop-filter: blur(5px); }
        @keyframes floatUp { 0% { transform: translateY(0) scale(0.8); opacity: 0; } 50% { opacity: 1; } 100% { transform: translateY(-120vh) scale(1.2); opacity: 0; } }
        
        .login-card { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); border-radius: 24px; padding: 45px 50px; width: 100%; max-width: 450px; z-index: 10; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4); }
        .css-logo { text-align: center; margin-bottom: 25px; }
        .css-logo .droplet-icon { font-size: 3.5rem; background: -webkit-linear-gradient(45deg, var(--qatra-cyan), #ffffff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; filter: drop-shadow(0 10px 15px rgba(125, 211, 252, 0.4)); margin-bottom: 10px; }
        .css-logo .brand-name-en { font-size: 1.8rem; font-weight: 900; letter-spacing: 4px; color: white; margin-bottom: -5px; }
        .header-title { text-align: center; margin-bottom: 30px; color: var(--qatra-navy); }
        .badge-system { background: #f1f5f9; color: var(--qatra-blue); padding: 6px 15px; border-radius: 50px; font-size: 0.85rem; font-weight: 800; border: 1px solid #e2e8f0; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 10px; }
        
        .form-control { border-radius: 14px 0 0 14px; border: 2px solid #e2e8f0; border-right: none; padding: 16px 20px; font-weight: 700; background: transparent; transition: 0.3s; }
        .input-group-text { background: transparent; border: 2px solid #e2e8f0; border-left: none; color: #94a3b8; border-radius: 0 14px 14px 0; transition: 0.3s; }
        .form-control:focus { border-color: var(--qatra-light); background: white; box-shadow: none; }
        .form-control:focus + .input-group-text, .input-group:focus-within .input-group-text { border-color: var(--qatra-light); color: var(--qatra-light); background: white; }
        
        .btn-brand { background: linear-gradient(135deg, var(--qatra-blue), var(--qatra-light)); color: white; border: none; border-radius: 14px; padding: 16px; font-weight: 900; font-size: 1.1rem; width: 100%; transition: 0.4s; }
        .btn-brand:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(11, 69, 127, 0.5); color: white; }

        /* --- زر العودة (تمت إضافته هنا) --- */
        .back-link {
            position: absolute; top: 30px; left: 40px; z-index: 10;
            color: white; text-decoration: none; font-weight: 700; font-size: 0.95rem;
            background: rgba(255,255,255,0.1); padding: 8px 20px; border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.2); transition: 0.3s; backdrop-filter: blur(10px);
        }
        .back-link:hover { background: rgba(255,255,255,0.2); transform: translateX(-5px); color: white; }

        @media (max-width: 768px) {
            .login-card { padding: 30px 20px; }
            .back-link { position: static; display: inline-flex; margin-bottom: 20px; }
        }
    </style>
</head>
<body>
    <div class="bg-animation" id="bg-particles"></div>
    <script>
        const container = document.getElementById('bg-particles');
        for (let i = 0; i < 20; i++) {
            let drop = document.createElement('div');
            drop.classList.add('water-drop');
            drop.style.left = Math.random() * 100 + 'vw';
            drop.style.width = Math.random() * 40 + 20 + 'px';
            drop.style.height = drop.style.width;
            drop.style.animationDuration = Math.random() * 5 + 5 + 's';
            drop.style.animationDelay = Math.random() * 5 + 's';
            container.appendChild(drop);
        }
    </script>

    <!-- زر العودة للصفحة الرئيسية -->
    <a href="index.php" class="back-link"><i class="fa-solid fa-house ms-1"></i> الصفحة الرئيسية</a>

    <div class="login-card">
        <div class="css-logo">
            <i class="fa-solid fa-droplet droplet-icon"></i>
            <div class="brand-name-en" style="color: var(--qatra-navy);">QATRA</div>
        </div>
        <div class="header-title">
            <span class="badge-system"><i class="fa-solid fa-id-badge"></i> الدخول المؤسسي</span>
            <h3 class="fw-black m-0 mt-2">تسجيل الدخول للنظام</h3>
        </div>

        <?php if($errorMsg): ?>
            <div class="alert alert-danger fw-bold rounded-3 text-center" style="font-size: 0.9rem;"><i class="fa-solid fa-triangle-exclamation"></i> <?= $errorMsg; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-4">
                <label class="fw-bold mb-2 text-dark">البريد الإلكتروني الرسمي</label>
                <div class="input-group" style="direction: ltr;">
                    <input type="email" name="emp_email" class="form-control text-end" placeholder="name@qatra.com" required>
                    <span class="input-group-text"><i class="fa-regular fa-envelope"></i></span>
                </div>
            </div>
            <div class="mb-4">
                <label class="fw-bold mb-2 text-dark">كلمة المرور</label>
                <div class="input-group" style="direction: ltr;">
                    <input type="password" name="password" class="form-control text-end" placeholder="أدخل كلمة المرور" required>
                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                </div>
            </div>
            <button type="submit" class="btn-brand">متابعة <i class="fa-solid fa-arrow-left ms-2"></i></button>
        </form>
    </div>
</body>
</html>