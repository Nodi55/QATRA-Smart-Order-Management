<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['temp_emp_id'])) {
    header("Location: employee_login.php");
    exit;
}

$errorMsg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $enteredCode = trim($_POST['otp_code']);
    
    if ($enteredCode == $_SESSION['temp_emp_otp']) {
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
        $errorMsg = "رمز التحقق غير صحيح، حاول مرة أخرى.";
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
    <style>
        :root { --qatra-navy: #092e54; --qatra-blue: #0b457f; --qatra-light: #4492d4; }
        body { font-family: 'Cairo', sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background-color: var(--qatra-navy); position: relative; }
        .otp-card { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); border-radius: 24px; padding: 50px 40px; text-align: center; width: 100%; max-width: 480px; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4); border-top: 6px solid var(--qatra-light); }
        .icon-circle { width: 90px; height: 90px; background: #e0f2fe; color: var(--qatra-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 20px; }
        .otp-input { letter-spacing: 15px; font-size: 2.2rem; font-weight: 900; text-align: center; border-radius: 16px; border: 2px solid #e2e8f0; padding: 15px; color: var(--qatra-navy); transition: all 0.3s; direction: ltr; }
        .otp-input:focus { border-color: var(--qatra-light); box-shadow: 0 0 0 5px rgba(68, 146, 212, 0.15); outline: none; }
        .btn-brand { background: linear-gradient(135deg, var(--qatra-blue), var(--qatra-light)); color: white; border: none; border-radius: 14px; padding: 16px; font-weight: 900; font-size: 1.1rem; width: 100%; margin-top: 15px; }
    </style>
</head>
<body>
<div class="otp-card">
    <div class="icon-circle"><i class="fa-solid fa-envelope-open-text"></i></div>
    <h3 class="fw-black" style="color: var(--qatra-navy);">التحقق المؤسسي</h3>
    <p class="text-muted fw-bold mb-4">تم إرسال رمز التحقق إلى (<?= htmlspecialchars($_SESSION['temp_emp_email']); ?>)</p>

    <?php if(isset($_SESSION['temp_emp_otp'])): ?>
    <div class="alert text-center fw-bold mb-4" style="background: #fffbeb; border: 2px dashed #f59e0b; border-radius: 16px; color: #b45309;">
        <i class="fa-solid fa-flask fs-5 mb-2 d-block"></i>
        للمحاكاة: الرمز هو <span class="text-danger fs-3 ms-2"><?= $_SESSION['temp_emp_otp']; ?></span>
    </div>
    <?php endif; ?>

    <?php if($errorMsg): ?>
    <div class="alert alert-danger fw-bold rounded-4 mb-4"><?= $errorMsg; ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="text" name="otp_code" class="form-control otp-input" placeholder="------" maxlength="6" required pattern="\d{6}" oninput="this.value = this.value.replace(/[^0-9]/g, '')" autofocus>
        <button type="submit" class="btn-brand">تأكيد الدخول <i class="fa-solid fa-shield-check ms-2"></i></button>
    </form>
</div>
</body>
</html>
