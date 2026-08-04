<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once 'db_connect.php';
$errorMsg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['emp_email']);
    $employee = null;

    try {
        $stmt = $pdo->prepare("SELECT emp_id, emp_name, emp_email FROM company_employee WHERE emp_email = ?");
        $stmt->execute([$email]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $errorMsg = "حدث خطأ في الاتصال بقاعدة البيانات.";
    }

    if ($employee) {
        // التأكد من وجود عمود emp_id وعمود purpose في جدول otp_code
        try {
            $pdo->query("SELECT emp_id FROM otp_code LIMIT 1");
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE otp_code ADD COLUMN emp_id INT NULL, ADD CONSTRAINT fk_otp_emp FOREIGN KEY (emp_id) REFERENCES company_employee(emp_id) ON DELETE CASCADE");
        }
        try {
            $pdo->query("SELECT purpose FROM otp_code LIMIT 1");
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE otp_code ADD COLUMN purpose VARCHAR(20) NOT NULL DEFAULT 'login'");
        }

        $otpCode = rand(100000, 999999);
        try {
            $stmtOtp = $pdo->prepare("INSERT INTO otp_code (code, expiry_time, is_used, emp_id, purpose) VALUES (?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, ?, 'reset')");
            $stmtOtp->execute([$otpCode, $employee['emp_id']]);
        } catch (PDOException $e) {
            $errorMsg = "حدث خطأ أثناء إنشاء رمز التحقق.";
        }

        if (!$errorMsg) {
            $_SESSION['temp_reset_emp_id'] = $employee['emp_id'];
            $_SESSION['temp_reset_emp_email'] = $employee['emp_email'];
            $_SESSION['temp_reset_otp'] = $otpCode; // للمحاكاة فقط
            header("Location: employee_reset_password.php");
            exit;
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
    <title>نسيت كلمة المرور | بوابة الموظفين</title>
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
        .css-logo .droplet-icon { font-size: 3.5rem; background: -webkit-linear-gradient(45deg, var(--qatra-cyan), #ffffff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 10px; }
        .css-logo .brand-name-en { font-size: 1.8rem; font-weight: 900; letter-spacing: 4px; color: var(--qatra-navy); margin-bottom: -5px; }
        .header-title { text-align: center; margin-bottom: 30px; color: var(--qatra-navy); }
        .form-control { border-radius: 14px 0 0 14px; border: 2px solid #e2e8f0; border-right: none; padding: 16px 20px; font-weight: 700; background: transparent; }
        .input-group-text { background: transparent; border: 2px solid #e2e8f0; border-left: none; color: #94a3b8; border-radius: 0 14px 14px 0; }
        .form-control:focus { border-color: var(--qatra-light); background: white; box-shadow: none; }
        .form-control:focus + .input-group-text, .input-group:focus-within .input-group-text { border-color: var(--qatra-light); color: var(--qatra-light); background: white; }
        .btn-brand { background: linear-gradient(135deg, var(--qatra-blue), var(--qatra-light)); color: white; border: none; border-radius: 14px; padding: 16px; font-weight: 900; font-size: 1.1rem; width: 100%; margin-top: 10px; }
        .btn-brand:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(11, 69, 127, 0.5); color: white; }
        .footer-links { text-align: center; margin-top: 25px; font-weight: 700; color: #64748b; }
        .footer-links a { color: var(--qatra-light); text-decoration: none; }
        @media (max-width: 768px) { .login-card { padding: 30px 20px; } }
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

    <div class="login-card">
        <div class="css-logo">
            <i class="fa-solid fa-key droplet-icon"></i>
            <div class="brand-name-en">QATRA</div>
        </div>
        <div class="header-title">
            <h3 class="fw-black m-0">نسيت كلمة المرور؟</h3>
            <p class="text-muted fw-bold mt-2">أدخل بريدك الإلكتروني الرسمي لإرسال رمز إعادة التعيين</p>
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
            <button type="submit" class="btn-brand">إرسال رمز التحقق <i class="fa-solid fa-paper-plane ms-2"></i></button>
        </form>

        <div class="footer-links">
            <a href="employee_login.php"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i> العودة لتسجيل الدخول</a>
        </div>
    </div>
</body>
</html>