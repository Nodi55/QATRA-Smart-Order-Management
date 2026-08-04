<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once 'db_connect.php';
$errorMsg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nationalId = trim($_POST['national_id']);
    $customer = null;

    try {
        $stmt = $pdo->prepare("SELECT cust_id, national_id, full_name FROM customer WHERE national_id = ?");
        $stmt->execute([$nationalId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $errorMsg = "حدث خطأ في الاتصال بقاعدة البيانات.";
    }

    if ($customer) {
        // التأكد من وجود عمود purpose في جدول otp_code (لتمييز رمز الدخول عن رمز إعادة التعيين)
        try {
            $pdo->query("SELECT purpose FROM otp_code LIMIT 1");
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE otp_code ADD COLUMN purpose VARCHAR(20) NOT NULL DEFAULT 'login'");
        }

        $otpCode = rand(100000, 999999);
        try {
            $stmtOtp = $pdo->prepare("INSERT INTO otp_code (code, expiry_time, is_used, cust_id, purpose) VALUES (?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, ?, 'reset')");
            $stmtOtp->execute([$otpCode, $customer['cust_id']]);
        } catch (PDOException $e) {
            $errorMsg = "حدث خطأ أثناء إنشاء رمز التحقق.";
        }

        if (!$errorMsg) {
            $_SESSION['temp_reset_cust_id'] = $customer['cust_id'];
            $_SESSION['temp_reset_national_id'] = $customer['national_id'];
            $_SESSION['temp_reset_otp'] = $otpCode; // للمحاكاة فقط (عرض الرمز في إشعار)
            header("Location: reset_password.php");
            exit;
        }
    } else {
        $errorMsg = "رقم الهوية غير مسجل في النظام.";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نسيت كلمة المرور | نظام قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root { --qatra-navy: #092e54; --qatra-blue: #0b457f; --qatra-light: #4492d4; --qatra-cyan: #7dd3fc; }
        body { font-family: 'Cairo', sans-serif; margin: 0; padding: 40px 20px; min-height: 100vh; display: flex; align-items: center; justify-content: center; background-color: var(--qatra-navy); overflow-x: hidden; position: relative; }
        .bg-animation { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; background: radial-gradient(circle at top right, #10599c 0%, var(--qatra-navy) 70%); }
        .water-drop { position: absolute; bottom: -100px; background: linear-gradient(180deg, rgba(125, 211, 252, 0.1) 0%, rgba(125, 211, 252, 0.4) 100%); border-radius: 50%; animation: floatUp infinite ease-in; backdrop-filter: blur(5px); }
        @keyframes floatUp { 0% { transform: translateY(0) scale(0.8); opacity: 0; } 50% { opacity: 1; } 100% { transform: translateY(-120vh) scale(1.2); opacity: 0; } }
        .css-logo { text-align: center; margin-bottom: 20px; }
        .css-logo .droplet-icon { font-size: 3.2rem; background: -webkit-linear-gradient(45deg, var(--qatra-cyan), #ffffff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 8px; }
        .css-logo .brand-name-en { font-size: 1.6rem; font-weight: 900; letter-spacing: 4px; color: var(--qatra-navy); margin-bottom: -3px; }
        .login-card { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 24px; padding: 45px 50px; width: 100%; max-width: 450px; z-index: 10; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4); position: relative; }
        .login-card::before { content: ''; position: absolute; top: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, var(--qatra-light), transparent); border-radius: 0 0 10px 10px; }
        .header-title { text-align: center; margin-bottom: 30px; color: var(--qatra-navy); }
        .form-label { font-weight: 800; color: var(--qatra-navy); font-size: 0.95rem; margin-bottom: 8px; }
        .input-group-text { background: transparent; border: 2px solid #e2e8f0; border-left: none; color: #94a3b8; border-radius: 0 14px 14px 0; font-size: 1.1rem; }
        .form-control { border-radius: 14px 0 0 14px; border: 2px solid #e2e8f0; border-right: none; padding: 16px 20px; font-weight: 700; color: #1e293b; background: transparent; }
        .form-control:focus { border-color: var(--qatra-light); outline: none; box-shadow: none; background: white; }
        .form-control:focus + .input-group-text, .input-group:focus-within .input-group-text { border-color: var(--qatra-light); color: var(--qatra-light); background: white; }
        .input-group:focus-within { box-shadow: 0 0 0 4px rgba(68, 146, 212, 0.15); border-radius: 14px; background: white; }
        .btn-brand { background: linear-gradient(135deg, var(--qatra-blue), var(--qatra-light)); color: white; border: none; border-radius: 14px; padding: 16px; font-weight: 900; font-size: 1.1rem; width: 100%; margin-top: 20px; transition: all 0.4s; box-shadow: 0 10px 25px rgba(11, 69, 127, 0.3); }
        .btn-brand:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(11, 69, 127, 0.5); color: white; }
        .footer-links { text-align: center; margin-top: 25px; font-weight: 700; color: #64748b; }
        .footer-links a { color: var(--qatra-light); text-decoration: none; }
        .footer-links a:hover { color: var(--qatra-blue); }
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
        <p class="text-muted fw-bold mt-2">أدخل رقم الهوية الوطنية لإرسال رمز إعادة التعيين</p>
    </div>

    <?php if($errorMsg): ?>
    <div class="alert alert-danger fw-bold rounded-3 mb-4 border-0 text-center" style="background: #fef2f2; color: #b91c1c; font-size: 0.9rem;">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $errorMsg; ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-4">
            <label class="form-label">رقم الهوية الوطنية</label>
            <div class="input-group" style="direction: ltr;">
                <input type="text" name="national_id" class="form-control text-end" placeholder="أدخل رقم الهوية" required maxlength="10" pattern="\d{10}">
                <span class="input-group-text"><i class="fa-regular fa-id-card"></i></span>
            </div>
        </div>
        <button type="submit" class="btn-brand">إرسال رمز التحقق <i class="fa-solid fa-paper-plane ms-2"></i></button>
    </form>

    <div class="footer-links">
        <a href="login.php"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i> العودة لتسجيل الدخول</a>
    </div>
</div>
</body>
</html>