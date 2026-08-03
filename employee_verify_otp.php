<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['temp_emp_id'])) {
    header("Location: employee_login.php");
    exit;
}

require_once 'db_connect.php';
$errorMsg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $enteredCode = trim($_POST['otp_code']);
    $empId = $_SESSION['temp_emp_id'];
    $validOtp = null;

    // --- التعديل الأمني: التحقق من صحة الرمز من قاعدة البيانات ---
    try {
        $stmt = $pdo->prepare("SELECT otp_id FROM otp_code WHERE emp_id = ? AND code = ? AND is_used = 0 AND expiry_time > NOW() ORDER BY otp_id DESC LIMIT 1");
        $stmt->execute([$empId, $enteredCode]);
        $validOtp = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $errorMsg = "حدث خطأ في الاتصال بقاعدة البيانات.";
    }

    if ($validOtp) {
        // إبطال الرمز لضمان عدم استخدامه مرة أخرى
        try {
            $pdo->prepare("UPDATE otp_code SET is_used = 1 WHERE otp_id = ?")->execute([$validOtp['otp_id']]);
        } catch(PDOException $e) {}

        $_SESSION['emp_id'] = $_SESSION['temp_emp_id'];
        $_SESSION['emp_name'] = $_SESSION['temp_emp_name'];
        $_SESSION['emp_email'] = $_SESSION['temp_emp_email'];
        $_SESSION['emp_city_id'] = $_SESSION['temp_emp_city_id'];
        $_SESSION['active_tasks'] = $_SESSION['temp_active_tasks'];
        $_SESSION['emp_roles'] = $_SESSION['temp_emp_roles'];

        unset($_SESSION['temp_emp_otp'], $_SESSION['temp_emp_id'], $_SESSION['temp_emp_name'], $_SESSION['temp_emp_email'], $_SESSION['temp_emp_city_id'], $_SESSION['temp_active_tasks'], $_SESSION['temp_emp_roles']);
        
        header("Location: employee_dashboard.php");
        exit;
    } else {
        $errorMsg = "الرمز المدخل غير صحيح أو انتهت صلاحيته (المدة دقيقتين).";
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التحقق الأمني | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --qatra-navy: #092e54; --qatra-blue: #0b457f; --qatra-light: #4492d4; }
        body { font-family: 'Cairo', sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background-color: var(--qatra-navy); position: relative; }
        .otp-card { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); border-radius: 24px; padding: 50px 40px; text-align: center; width: 100%; max-width: 480px; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4); border-top: 6px solid var(--qatra-light); }
        .icon-circle { width: 90px; height: 90px; background: #e0f2fe; color: var(--qatra-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 20px; }
        .otp-input { letter-spacing: 15px; font-size: 2.2rem; font-weight: 900; text-align: center; border-radius: 16px; border: 2px solid #e2e8f0; padding: 15px; color: var(--qatra-navy); transition: all 0.3s; direction: ltr; }
        .otp-input:focus { border-color: var(--qatra-light); box-shadow: 0 0 0 5px rgba(68, 146, 212, 0.15); outline: none; }
        .btn-brand { background: linear-gradient(135deg, var(--qatra-blue), var(--qatra-light)); color: white; border: none; border-radius: 14px; padding: 16px; font-weight: 900; font-size: 1.1rem; width: 100%; margin-top: 15px; }
        
        /* تصميم الإشعار المنبثق للمحاكاة */
        .simulation-toast { position: fixed; top: 25px; left: 50%; transform: translateX(-50%) translateY(-150%); background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(15px); padding: 20px 25px; border-radius: 20px; box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25); border: 1px solid rgba(245, 158, 11, 0.2); border-right: 6px solid #f59e0b; display: flex; align-items: center; gap: 20px; z-index: 9999; transition: transform 0.8s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.8s ease; opacity: 0; width: 90%; max-width: 420px; }
        .simulation-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast-icon { width: 55px; height: 55px; background: #fffbeb; color: #d97706; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; animation: pulseAlert 2s infinite; }
        @keyframes pulseAlert { 0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.5); } 70% { box-shadow: 0 0 0 15px rgba(245, 158, 11, 0); } 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); } }
        .toast-content { flex: 1; text-align: right; }
        .toast-content h6 { margin: 0; font-weight: 900; color: #92400e; font-size: 1.05rem; }
        .toast-content p { margin: 5px 0 0; font-weight: 700; color: #475569; font-size: 0.95rem; }
        .otp-highlight { background: #fef3c7; color: #b45309; padding: 4px 12px; border-radius: 8px; font-size: 1.3rem; letter-spacing: 3px; font-family: monospace; }
        .btn-copy { background: #f1f5f9; border: none; color: #64748b; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; transition: 0.3s; cursor: pointer; }
        .btn-copy:hover { background: #e2e8f0; color: var(--qatra-light); transform: scale(1.05); }
    </style>
</head>
<body>
    <?php if(isset($_SESSION['temp_emp_otp'])): ?>
    <div class="simulation-toast" id="simToast">
        <div class="toast-icon"><i class="fa-solid fa-flask"></i></div>
        <div class="toast-content">
            <h6>محاكاة رسالة (SMS)</h6>
            <p>الرمز: <span class="otp-highlight" id="otpText"><?= $_SESSION['temp_emp_otp']; ?></span></p>
        </div>
        <button class="btn-copy" onclick="copyOtp()" title="نسخ الرمز"><i class="fa-regular fa-copy"></i></button>
    </div>
    <?php endif; ?>

    <div class="otp-card">
        <div class="icon-circle"><i class="fa-solid fa-envelope-open-text"></i></div>
        <h3 class="fw-black" style="color: var(--qatra-navy);">التحقق المؤسسي</h3>
        <p class="text-muted fw-bold mb-4">تم إرسال رمز التحقق إلى (<?= htmlspecialchars($_SESSION['temp_emp_email']); ?>)</p>

        <?php if($errorMsg): ?>
            <div class="alert alert-danger fw-bold rounded-4 mb-4"><?= $errorMsg; ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="text" name="otp_code" class="form-control otp-input" placeholder="------" maxlength="6" required pattern="\d{6}" oninput="this.value = this.value.replace(/[^0-9]/g, '')" autofocus>
            <button type="submit" class="btn-brand">تأكيد الدخول <i class="fa-solid fa-shield-check ms-2"></i></button>
        </form>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const toast = document.getElementById('simToast');
            if (toast) { setTimeout(() => { toast.classList.add('show'); }, 500); }
        });

        function copyOtp() {
            const otpCode = document.getElementById('otpText').innerText;
            navigator.clipboard.writeText(otpCode).then(() => {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'تم نسخ الرمز بنجاح!', showConfirmButton: false, timer: 2000, timerProgressBar: true });
            });
        }
    </script>
</body>
</html>