<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['temp_reset_emp_id'])) {
    header("Location: employee_forgot_password.php");
    exit;
}

require_once 'db_connect.php';
$errorMsg = "";
$empId = $_SESSION['temp_reset_emp_id'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $enteredCode = trim($_POST['otp_code']);
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    if (strlen($newPassword) < 6) {
        $errorMsg = "يجب أن تتكون كلمة المرور من 6 أحرف على الأقل.";
    } elseif ($newPassword !== $confirmPassword) {
        $errorMsg = "كلمتا المرور غير متطابقتين.";
    } else {
        $validOtp = null;
        try {
            $stmt = $pdo->prepare("SELECT otp_id FROM otp_code WHERE emp_id = ? AND code = ? AND is_used = 0 AND purpose = 'reset' AND expiry_time > NOW() ORDER BY otp_id DESC LIMIT 1");
            $stmt->execute([$empId, $enteredCode]);
            $validOtp = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $errorMsg = "حدث خطأ في الاتصال بقاعدة البيانات.";
        }

        if ($validOtp) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            try {
                $pdo->prepare("UPDATE company_employee SET password_hash = ? WHERE emp_id = ?")->execute([$hashedPassword, $empId]);
                $pdo->prepare("UPDATE otp_code SET is_used = 1 WHERE otp_id = ?")->execute([$validOtp['otp_id']]);

                unset($_SESSION['temp_reset_emp_id'], $_SESSION['temp_reset_emp_email'], $_SESSION['temp_reset_otp']);

                header("Location: employee_login.php?reset=success");
                exit;
            } catch (PDOException $e) {
                $errorMsg = "حدث خطأ أثناء تحديث كلمة المرور.";
            }
        } else {
            $errorMsg = "الرمز المدخل غير صحيح أو انتهت صلاحيته (المدة 5 دقائق).";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعادة تعيين كلمة المرور | بوابة الموظفين</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --qatra-navy: #092e54; --qatra-blue: #0b457f; --qatra-light: #4492d4; --qatra-cyan: #7dd3fc; }
        body { font-family: 'Cairo', sans-serif; margin: 0; padding: 40px 20px; min-height: 100vh; display: flex; align-items: center; justify-content: center; background-color: var(--qatra-navy); overflow-x: hidden; position: relative; }
        .bg-animation { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; background: radial-gradient(circle at top right, #10599c 0%, var(--qatra-navy) 70%); }
        .water-drop { position: absolute; bottom: -100px; background: linear-gradient(180deg, rgba(125, 211, 252, 0.1) 0%, rgba(125, 211, 252, 0.4) 100%); border-radius: 50%; animation: floatUp infinite ease-in; backdrop-filter: blur(5px); }
        @keyframes floatUp { 0% { transform: translateY(0) scale(0.8); opacity: 0; } 50% { opacity: 1; } 100% { transform: translateY(-120vh) scale(1.2); opacity: 0; } }

        .simulation-toast { position: fixed; top: 25px; left: 50%; transform: translateX(-50%) translateY(-150%); background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(15px); padding: 20px 25px; border-radius: 20px; box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25); border: 1px solid rgba(245, 158, 11, 0.2); border-right: 6px solid #f59e0b; display: flex; align-items: center; gap: 20px; z-index: 9999; transition: transform 0.8s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.8s ease; opacity: 0; width: 90%; max-width: 420px; }
        .simulation-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast-icon { width: 55px; height: 55px; background: #fffbeb; color: #d97706; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; animation: pulseAlert 2s infinite; }
        @keyframes pulseAlert { 0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.5); } 70% { box-shadow: 0 0 0 15px rgba(245, 158, 11, 0); } 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); } }
        .toast-content { flex: 1; text-align: right; }
        .toast-content h6 { margin: 0; font-weight: 900; color: #92400e; font-size: 1.05rem; }
        .toast-content p { margin: 5px 0 0; font-weight: 700; color: #475569; font-size: 0.95rem; }
        .otp-highlight { background: #fef3c7; color: #b45309; padding: 4px 12px; border-radius: 8px; font-size: 1.3rem; letter-spacing: 3px; font-family: monospace; }
        .btn-copy { background: #f1f5f9; border: none; color: #64748b; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .btn-copy:hover { background: #e2e8f0; color: var(--qatra-light); }

        .card-box { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); border-radius: 24px; padding: 45px 50px; width: 100%; max-width: 460px; z-index: 10; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4); }
        .header-title { text-align: center; margin-bottom: 25px; color: var(--qatra-navy); }
        .form-label { font-weight: 800; color: var(--qatra-navy); font-size: 0.95rem; margin-bottom: 8px; }
        .otp-input { letter-spacing: 12px; font-size: 1.8rem; font-weight: 900; text-align: center; border-radius: 14px; border: 2px solid #e2e8f0; padding: 12px; color: var(--qatra-navy); direction: ltr; }
        .otp-input:focus { border-color: var(--qatra-light); box-shadow: 0 0 0 4px rgba(68, 146, 212, 0.15); outline: none; }
        .form-control { border-radius: 14px; border: 2px solid #e2e8f0; padding: 14px 18px; font-weight: 700; color: #1e293b; direction: ltr; text-align: right; }
        .form-control:focus { border-color: var(--qatra-light); outline: none; box-shadow: 0 0 0 4px rgba(68, 146, 212, 0.15); }
        .btn-brand { background: linear-gradient(135deg, var(--qatra-blue), var(--qatra-light)); color: white; border: none; border-radius: 14px; padding: 16px; font-weight: 900; font-size: 1.1rem; width: 100%; margin-top: 15px; }
        .btn-brand:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(11, 69, 127, 0.5); color: white; }
        .footer-links { text-align: center; margin-top: 20px; font-weight: 700; color: #64748b; }
        .footer-links a { color: var(--qatra-light); text-decoration: none; }
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

<?php if(isset($_SESSION['temp_reset_otp'])): ?>
<div class="simulation-toast" id="simToast">
    <div class="toast-icon"><i class="fa-solid fa-flask"></i></div>
    <div class="toast-content">
        <h6>محاكاة رسالة (بريد إلكتروني)</h6>
        <p>رمز إعادة التعيين: <span class="otp-highlight" id="otpText"><?= $_SESSION['temp_reset_otp']; ?></span></p>
    </div>
    <button class="btn-copy" onclick="copyOtp()" title="نسخ الرمز"><i class="fa-regular fa-copy"></i></button>
</div>
<?php endif; ?>

<div class="card-box">
    <div class="header-title">
        <h3 class="fw-black m-0">إعادة تعيين كلمة المرور</h3>
        <p class="text-muted fw-bold mt-2">أدخل رمز التحقق مع كلمة المرور الجديدة</p>
    </div>

    <?php if($errorMsg): ?>
    <div class="alert alert-danger fw-bold rounded-3 mb-4 border-0 text-center" style="background: #fef2f2; color: #b91c1c; font-size: 0.9rem;">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $errorMsg; ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-4">
            <label class="form-label">رمز التحقق</label>
            <input type="text" name="otp_code" class="form-control otp-input" placeholder="------" maxlength="6" required pattern="\d{6}" oninput="this.value = this.value.replace(/[^0-9]/g, '')" autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label">كلمة المرور الجديدة</label>
            <input type="password" name="new_password" class="form-control" placeholder="6 أحرف على الأقل" required minlength="6">
        </div>
        <div class="mb-4">
            <label class="form-label">تأكيد كلمة المرور</label>
            <input type="password" name="confirm_password" class="form-control" placeholder="أعد إدخال كلمة المرور" required minlength="6">
        </div>
        <button type="submit" class="btn-brand">تحديث كلمة المرور <i class="fa-solid fa-check ms-2"></i></button>
    </form>

    <div class="footer-links">
        <a href="employee_forgot_password.php"><i class="fa-solid fa-rotate-left me-1"></i> إعادة إرسال الرمز</a>
    </div>
</div>

<script>
function copyOtp() {
    const otpCode = document.getElementById('otpText').innerText;
    navigator.clipboard.writeText(otpCode).then(() => {
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'تم نسخ الرمز بنجاح!', showConfirmButton: false, timer: 2000, timerProgressBar: true });
    });
}
document.addEventListener("DOMContentLoaded", function() {
    const toast = document.getElementById('simToast');
    if (toast) { setTimeout(() => { toast.classList.add('show'); }, 500); }
});
</script>
</body>
</html>