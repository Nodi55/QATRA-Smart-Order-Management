<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (isset($_SESSION['customer_national_id'])) {
    header("Location: dashboard.php");
    exit;
}

require_once 'db_connect.php';
$errorMsg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nationalId = trim($_POST['national_id']);
    $password = trim($_POST['password']);
    $customer = null;

    try {
        $stmt = $pdo->prepare("SELECT cust_id, national_id, full_name, password_hash FROM customer WHERE national_id = ?");
        $stmt->execute([$nationalId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        try {
            $stmt = $pdo->prepare("SELECT cust_id, national_id, full_name, password_hash FROM Customer WHERE national_id = ?");
            $stmt->execute([$nationalId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            $errorMsg = "حدث خطأ في الاتصال بقاعدة البيانات.";
        }
    }

    if ($customer) {
        if ($password === $customer['password_hash'] || password_verify($password, $customer['password_hash'])) {
            $custId = $customer['cust_id'];
            $otpCode = rand(100000, 999999);

            try {
                $stmtOtp = $pdo->prepare("INSERT INTO otp_code (code, expiry_time, is_used, cust_id) VALUES (?, DATE_ADD(NOW(), INTERVAL 2 MINUTE), 0, ?)");
                $stmtOtp->execute([$otpCode, $custId]);
            } catch (PDOException $e) {
                try {
                    $stmtOtp = $pdo->prepare("INSERT INTO OTP_Code (code, expiry_time, is_used, cust_id) VALUES (?, DATE_ADD(NOW(), INTERVAL 2 MINUTE), 0, ?)");
                    $stmtOtp->execute([$otpCode, $custId]);
                } catch (PDOException $e2) {}
            }

            $_SESSION['temp_otp'] = $otpCode; 
            $_SESSION['temp_cust_id'] = $custId;
            $_SESSION['temp_national_id'] = $customer['national_id'];
            $_SESSION['temp_customer_name'] = $customer['full_name'];

            header("Location: verify_otp.php");
            exit;
        } else {
            $errorMsg = "كلمة المرور غير صحيحة.";
        }
    } else {
        $errorMsg = "رقم الهوية غير مسجل، يرجى إنشاء حساب جديد.";
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول | نظام قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root { --nwc-navy: #002d5c; --nwc-blue: #009FE3; --bg-color: #f4f7f9; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-color); height: 100vh; display: flex; align-items: center; justify-content: center; overflow: hidden; margin: 0; }
        .bg-shapes { position: absolute; width: 100%; height: 100%; z-index: -1; overflow: hidden; }
        .shape-1 { position: absolute; width: 400px; height: 400px; background: radial-gradient(circle, rgba(0,159,227,0.1) 0%, rgba(0,0,0,0) 70%); top: -100px; right: -100px; border-radius: 50%; }
        .shape-2 { position: absolute; width: 500px; height: 500px; background: radial-gradient(circle, rgba(0,45,92,0.05) 0%, rgba(0,0,0,0) 70%); bottom: -150px; left: -100px; border-radius: 50%; }
        .login-card { background: white; border-radius: 28px; padding: 50px 40px; box-shadow: 0 20px 50px rgba(0, 45, 92, 0.08); width: 100%; max-width: 450px; border: 1px solid rgba(255,255,255,0.8); animation: fadeInUp 0.6s ease forwards; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .brand-icon { width: 80px; height: 80px; background: linear-gradient(135deg, var(--nwc-blue), var(--nwc-navy)); color: white; border-radius: 22px; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 15px; box-shadow: 0 10px 25px rgba(0, 159, 227, 0.3); }
        .form-label { font-weight: 800; color: var(--nwc-navy); font-size: 0.95rem; margin-bottom: 8px; }
        .input-group-text { background: #f8fafc; border: 2px solid #e2e8f0; border-left: none; color: #94a3b8; border-radius: 0 16px 16px 0; }
        .form-control { border-radius: 16px 0 0 16px; border: 2px solid #e2e8f0; border-right: none; padding: 15px 20px; font-weight: 700; background: #f8fafc; transition: 0.3s; }
        .form-control:focus { background: white; border-color: var(--nwc-blue); box-shadow: none; }
        .form-control:focus + .input-group-text, .input-group:focus-within .input-group-text { border-color: var(--nwc-blue); color: var(--nwc-blue); background: white; }
        .btn-brand { background: linear-gradient(135deg, var(--nwc-blue), var(--nwc-navy)); color: white; border: none; border-radius: 16px; padding: 16px; font-weight: 900; font-size: 1.1rem; width: 100%; margin-top: 15px; transition: 0.3s; }
        .btn-brand:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0, 45, 92, 0.2); }
    </style>
</head>
<body>

<div class="bg-shapes"><div class="shape-1"></div><div class="shape-2"></div></div>

<div class="container">
    <div class="d-flex justify-content-center">
        <div class="login-card">
            <div class="text-center mb-4">
                <div class="brand-icon"><i class="fa-solid fa-droplet"></i></div>
                <h3 class="fw-black m-0" style="color: var(--nwc-navy);">تسجيل الدخول</h3>
                <p class="text-muted fw-bold mt-2">نظام قطرة لإدارة الطلبات الذكية</p>
            </div>

            <?php if($errorMsg): ?>
            <div class="alert alert-danger fw-bold rounded-4 mb-4 text-center" style="font-size: 0.9rem;">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $errorMsg; ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-4">
                    <label class="form-label">رقم الهوية الوطنية</label>
                    <div class="input-group" style="direction: ltr;">
                        <input type="text" name="national_id" class="form-control" style="text-align: right;" placeholder="أدخل رقم الهوية" required maxlength="10" pattern="\d{10}">
                        <span class="input-group-text"><i class="fa-regular fa-id-card"></i></span>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label d-flex justify-content-between">
                        <span>كلمة المرور</span>
                    </label>
                    <div class="input-group" style="direction: ltr;">
                        <input type="password" name="password" class="form-control" style="text-align: right;" placeholder="أدخل كلمة المرور" required>
                        <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                    </div>
                </div>

                <button type="submit" class="btn-brand">دخول المنصة <i class="fa-solid fa-arrow-left ms-2"></i></button>
            </form>
            
            <div class="text-center mt-4 fw-bold">
                <span class="text-muted">ليس لديك حساب؟</span> 
                <!-- التعديل هنا: توجيه المستخدم إلى customer_register.php -->
                <a href="customer_register.php" class="text-decoration-none" style="color: var(--nwc-blue);">إنشاء حساب جديد</a>
            </div>
        </div>
    </div>
</div>

<!-- رسالة الترحيب المنبثقة عند النجاح في التسجيل والتحويل لهذه الصفحة -->
<?php if(isset($_GET['registered']) && $_GET['registered'] == 'success'): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'success',
            title: 'مرحباً بك في قطرة!',
            text: 'تم إنشاء حسابك بنجاح، يمكنك الآن تسجيل الدخول بهويتك.',
            confirmButtonColor: '#009FE3',
            backdrop: `rgba(0,45,92,0.4)`
        });
    });
</script>
<?php endif; ?>
