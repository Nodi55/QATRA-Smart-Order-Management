<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['temp_cust_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'db_connect.php';

$errorMsg = "";
$custId = $_SESSION['temp_cust_id'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $enteredCode = trim($_POST['otp_code']);
    $validOtp = null;
    
    try {
        $stmt = $pdo->prepare("SELECT otp_id FROM otp_code WHERE cust_id = ? AND code = ? AND is_used = 0 AND expiry_time > NOW() ORDER BY otp_id DESC LIMIT 1");
        $stmt->execute([$custId, $enteredCode]);
        $validOtp = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        try {
            $stmt = $pdo->prepare("SELECT otp_id FROM OTP_Code WHERE cust_id = ? AND code = ? AND is_used = 0 AND expiry_time > NOW() ORDER BY otp_id DESC LIMIT 1");
            $stmt->execute([$custId, $enteredCode]);
            $validOtp = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {}
    }
    
    if ($validOtp) {
        try {
            $pdo->prepare("UPDATE otp_code SET is_used = 1 WHERE otp_id = ?")->execute([$validOtp['otp_id']]);
        } catch(PDOException $e) {
            $pdo->prepare("UPDATE OTP_Code SET is_used = 1 WHERE otp_id = ?")->execute([$validOtp['otp_id']]);
        }
        
        $_SESSION['customer_national_id'] = $_SESSION['temp_national_id'];
        $_SESSION['customer_name'] = $_SESSION['temp_customer_name'];
        
        unset($_SESSION['temp_cust_id'], $_SESSION['temp_national_id'], $_SESSION['temp_customer_name'], $_SESSION['temp_otp']);
        
        header("Location: dashboard.php");
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
    <title>التحقق الثنائي OTP | نظام قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root { --nwc-navy: #002d5c; --nwc-blue: #009FE3; --bg-color: #f4f7f9; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-color); height: 100vh; display: flex; align-items: center; justify-content: center; overflow: hidden; margin: 0; }
        
        /* تصميم الإشعار المنبثق (Toast) */
        .simulation-toast {
            position: fixed;
            top: 25px;
            left: 50%;
            transform: translateX(-50%) translateY(-150%);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 20px 25px;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
            border-right: 6px solid #f59e0b;
            display: flex;
            align-items: center;
            gap: 20px;
            z-index: 9999;
            transition: transform 0.8s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.8s ease;
            opacity: 0;
            width: 90%;
            max-width: 420px;
        }
        .simulation-toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        .toast-icon {
            width: 55px;
            height: 55px;
            background: #fffbeb;
            color: #d97706;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            animation: pulseAlert 2s infinite;
        }
        @keyframes pulseAlert {
            0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.5); }
            70% { box-shadow: 0 0 0 15px rgba(245, 158, 11, 0); }
            100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        }
        .toast-content { flex: 1; text-align: right; }
        .toast-content h6 { margin: 0; font-weight: 900; color: #92400e; font-size: 1.05rem; }
        .toast-content p { margin: 5px 0 0; font-weight: 700; color: #475569; font-size: 0.95rem; }
        .otp-highlight { 
            background: #fef3c7; color: #b45309; padding: 4px 12px; 
            border-radius: 8px; font-size: 1.3rem; letter-spacing: 3px; font-family: monospace; 
        }
        .btn-copy {
            background: #f1f5f9; border: none; color: #64748b; width: 40px; height: 40px;
            border-radius: 12px; display: flex; align-items: center; justify-content: center;
            transition: 0.3s; cursor: pointer;
        }
        .btn-copy:hover { background: #e2e8f0; color: var(--nwc-blue); transform: scale(1.05); }

        /* تصميم البطاقة الأساسية */
        .otp-card { background: white; border-radius: 28px; padding: 50px 40px; box-shadow: 0 20px 40px rgba(0, 45, 92, 0.08); width: 100%; max-width: 480px; text-align: center; position: relative; overflow: hidden; animation: fadeInUp 0.6s ease; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .otp-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px; background: linear-gradient(90deg, var(--nwc-blue), var(--nwc-navy)); }
        
        .icon-circle { width: 90px; height: 90px; background: #e6f5fc; color: var(--nwc-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 20px; box-shadow: 0 10px 20px rgba(0, 159, 227, 0.15); }
        .otp-input { letter-spacing: 15px; font-size: 2.2rem; font-weight: 900; text-align: center; border-radius: 16px; border: 2px solid #e2e8f0; padding: 15px; color: var(--nwc-navy); transition: all 0.3s; direction: ltr; }
        .otp-input:focus { border-color: var(--nwc-blue); box-shadow: 0 0 0 5px rgba(0, 159, 227, 0.15); outline: none; }
        
        .btn-brand { background: linear-gradient(135deg, var(--nwc-blue), var(--nwc-navy)); color: white; border: none; border-radius: 16px; padding: 16px; font-weight: 900; font-size: 1.1rem; width: 100%; transition: all 0.4s; box-shadow: 0 10px 25px rgba(0, 45, 92, 0.2); margin-top: 15px; }
        .btn-brand:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(0, 45, 92, 0.3); color: white; }
    </style>
</head>
<body>

<!-- الإشعار المنبثق المتحرك (Toast Notification) -->
<?php if(isset($_SESSION['temp_otp'])): ?>
<div class="simulation-toast" id="simToast">
    <div class="toast-icon">
        <i class="fa-solid fa-flask"></i>
    </div>
    <div class="toast-content">
        <h6>محاكاة رسالة (SMS)</h6>
        <p>الرمز: <span class="otp-highlight" id="otpText"><?= $_SESSION['temp_otp']; ?></span></p>
    </div>
    <button class="btn-copy" onclick="copyOtp()" title="نسخ الرمز">
        <i class="fa-regular fa-copy"></i>
    </button>
</div>
<?php endif; ?>

<div class="container">
    <div class="d-flex justify-content-center">
        <div class="otp-card">
            <div class="icon-circle">
                <i class="fa-solid fa-mobile-screen-button"></i>
            </div>
            
            <h3 class="fw-black" style="color: var(--nwc-navy);">التحقق الثنائي</h3>
            <p class="text-muted fw-bold mb-4">تم إرسال رمز التحقق لجوالك المسجل، يرجى إدخاله أدناه للمتابعة.</p>

            <?php if($errorMsg): ?>
            <div class="alert alert-danger fw-bold rounded-4 mb-4">
                <i class="fa-solid fa-circle-exclamation me-1"></i> <?= $errorMsg; ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <input type="text" name="otp_code" class="form-control otp-input" placeholder="------" 
                       maxlength="6" required pattern="\d{6}" 
                       oninput="this.value = this.value.replace(/[^0-9]/g, '')" autocomplete="off" autofocus>
                
                <button type="submit" class="btn-brand">
                    تأكيد الدخول <i class="fa-solid fa-arrow-left ms-2"></i>
                </button>
            </form>
            
            <div class="mt-4">
                <a href="login.php" class="text-decoration-none fw-bold text-muted"><i class="fa-solid fa-rotate-left me-1"></i> العودة لتسجيل الدخول</a>
            </div>
        </div>
    </div>
</div>

<script>
    // تفعيل ظهور الإشعار المنبثق بحركة ناعمة بعد تحميل الصفحة
    document.addEventListener("DOMContentLoaded", function() {
        const toast = document.getElementById('simToast');
        if (toast) {
            setTimeout(() => {
                toast.classList.add('show');
            }, 500); // يظهر بعد نصف ثانية من فتح الصفحة بحركة ارتدادية
        }
    });

    // دالة نسخ الرمز الاحترافية
    function copyOtp() {
        const otpCode = document.getElementById('otpText').innerText;
        navigator.clipboard.writeText(otpCode).then(() => {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'تم نسخ الرمز بنجاح!',
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true
            });
        });
    }
</script>

</body>
</html>