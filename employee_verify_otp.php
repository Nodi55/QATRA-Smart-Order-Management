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
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root { 
            --qatra-navy: #092e54; 
            --qatra-blue: #0b457f; 
            --qatra-light: #4492d4; 
            --qatra-cyan: #7dd3fc;
        }
        body { 
            font-family: 'Cairo', sans-serif; 
            margin: 0;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--qatra-navy);
            overflow-x: hidden;
            position: relative;
        }

        /* --- خلفية متحركة احترافية بفقاعات مائية --- */
        .bg-animation {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            z-index: 0;
            background: radial-gradient(circle at top right, #10599c 0%, var(--qatra-navy) 70%);
        }
        .water-drop {
            position: absolute;
            bottom: -100px;
            background: linear-gradient(180deg, rgba(125, 211, 252, 0.1) 0%, rgba(125, 211, 252, 0.4) 100%);
            border-radius: 50%;
            animation: floatUp infinite ease-in;
            backdrop-filter: blur(5px);
        }
        @keyframes floatUp {
            0% { transform: translateY(0) scale(0.8); opacity: 0; }
            50% { opacity: 1; }
            100% { transform: translateY(-120vh) scale(1.2); opacity: 0; }
        }

        /* تصميم الإشعار المنبثق (Toast) لمحاكاة الرسالة */
        .simulation-toast {
            position: fixed;
            top: 25px;
            left: 50%;
            transform: translateX(-50%) translateY(-150%);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 20px 25px;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25);
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
        .btn-copy:hover { background: #e2e8f0; color: var(--qatra-light); transform: scale(1.05); }

        /* --- الشعار --- */
        .css-logo {
            text-align: center;
            margin-bottom: 20px;
            position: relative;
        }
        .css-logo .droplet-mark {
            width: 50px;
            height: 57px;
            margin: 0 auto 8px;
            filter: drop-shadow(0 10px 15px rgba(125, 211, 252, 0.4));
            animation: pulseGlow 2s infinite alternate;
        }
        @keyframes pulseGlow {
            0% { filter: drop-shadow(0 5px 15px rgba(125, 211, 252, 0.2)); transform: scale(1); }
            100% { filter: drop-shadow(0 15px 25px rgba(125, 211, 252, 0.6)); transform: scale(1.05); }
        }
        .css-logo .brand-name-en { font-size: 1.4rem; font-weight: 900; letter-spacing: 4px; color: var(--qatra-navy); margin-bottom: -3px; }
        .css-logo .brand-name-ar { font-size: 0.9rem; font-weight: 600; color: var(--qatra-light); letter-spacing: 1px; }

        /* --- البطاقة الزجاجية --- */
        .otp-card { 
            background: rgba(255, 255, 255, 0.97); 
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px; 
            padding: 45px 40px; 
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4); 
            width: 100%; 
            max-width: 480px; 
            text-align: center; 
            position: relative; 
            overflow: hidden; 
            z-index: 10;
            animation: slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transform: translateY(40px);
        }
        @keyframes slideUp { to { opacity: 1; transform: translateY(0); } }
        .otp-card::before { content: ''; position: absolute; top: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, var(--qatra-light), transparent); border-radius: 0 0 10px 10px; }
        
        .otp-input { letter-spacing: 15px; font-size: 2.2rem; font-weight: 900; text-align: center; border-radius: 16px; border: 2px solid #e2e8f0; padding: 15px; color: var(--qatra-navy); transition: all 0.3s; direction: ltr; background: transparent; }
        .otp-input:focus { border-color: var(--qatra-light); box-shadow: 0 0 0 5px rgba(68, 146, 212, 0.15); outline: none; background: white; }
        
        .btn-brand { background: linear-gradient(135deg, var(--qatra-blue), var(--qatra-light)); color: white; border: none; border-radius: 16px; padding: 16px; font-weight: 900; font-size: 1.1rem; width: 100%; transition: all 0.4s; box-shadow: 0 10px 25px rgba(11, 69, 127, 0.3); margin-top: 15px; }
        .btn-brand:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(11, 69, 127, 0.5); color: white; }

        @media (max-width: 768px) {
            .otp-card { padding: 30px 20px; }
        }
    </style>
</head>
<body>

<!-- الخلفية المائية المتحركة -->
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

<!-- الإشعار المنبثق المتحرك (Toast Notification) -->
<?php if(isset($_SESSION['temp_emp_otp'])): ?>
<div class="simulation-toast" id="simToast">
    <div class="toast-icon">
        <i class="fa-solid fa-flask"></i>
    </div>
    <div class="toast-content">
        <h6>محاكاة رسالة (SMS)</h6>
        <p>الرمز: <span class="otp-highlight" id="otpText"><?= $_SESSION['temp_emp_otp']; ?></span></p>
    </div>
    <button class="btn-copy" onclick="copyOtp()" title="نسخ الرمز">
        <i class="fa-regular fa-copy"></i>
    </button>
</div>
<?php endif; ?>

<!-- بطاقة التحقق -->
<div class="otp-card">

    <!-- الشعار الرسمي (قطرة منقطة بتدرج لوني) -->
    <div class="css-logo">
        <svg class="droplet-mark" viewBox="0 0 60 68" xmlns="http://www.w3.org/2000/svg">
            <circle cx="30" cy="6"  r="2.1" fill="#bae6fd"/>

            <circle cx="26.3" cy="13" r="2.3" fill="#bae6fd"/>
            <circle cx="33.7" cy="13" r="2.3" fill="#93c5fd"/>

            <circle cx="22.6" cy="20" r="2.6" fill="#93c5fd"/>
            <circle cx="30"   cy="20" r="2.6" fill="#7dd3fc"/>
            <circle cx="37.4" cy="20" r="2.6" fill="#60a5fa"/>

            <circle cx="18.9" cy="27" r="2.9" fill="#7dd3fc"/>
            <circle cx="26.3" cy="27" r="2.9" fill="#60a5fa"/>
            <circle cx="33.7" cy="27" r="2.9" fill="#4492d4"/>
            <circle cx="41.1" cy="27" r="2.9" fill="#3b82f6"/>

            <circle cx="15.2" cy="34" r="3.3" fill="#60a5fa"/>
            <circle cx="22.6" cy="34" r="3.3" fill="#4492d4"/>
            <circle cx="30"   cy="34" r="3.3" fill="#3b82f6"/>
            <circle cx="37.4" cy="34" r="3.3" fill="#2563eb"/>
            <circle cx="44.8" cy="34" r="3.3" fill="#1d4ed8"/>

            <circle cx="18.9" cy="41" r="3.6" fill="#2563eb"/>
            <circle cx="26.3" cy="41" r="3.6" fill="#1d4ed8"/>
            <circle cx="33.7" cy="41" r="3.6" fill="#0b457f"/>
            <circle cx="41.1" cy="41" r="3.6" fill="#0b457f"/>

            <circle cx="22.6" cy="48" r="3.8" fill="#0b457f"/>
            <circle cx="30"   cy="48" r="3.8" fill="#083761"/>
            <circle cx="37.4" cy="48" r="3.8" fill="#0b457f"/>

            <circle cx="26.3" cy="55" r="3.9" fill="#062a4d"/>
            <circle cx="33.7" cy="55" r="3.9" fill="#062a4d"/>
        </svg>
        <div class="brand-name-en">QATRA</div>
        <div class="brand-name-ar">قطرة</div>
    </div>

    <h3 class="fw-black" style="color: var(--qatra-navy);">التحقق الثنائي</h3>
    <p class="text-muted fw-bold mb-4">تم إرسال رمز التحقق إلى (<?= htmlspecialchars($_SESSION['temp_emp_email']); ?>)</p>

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
            تأكيد الدخول <i class="fa-solid fa-shield-check ms-2"></i>
        </button>
    </form>

    <div class="mt-4">
        <a href="employee_login.php" class="text-decoration-none fw-bold text-muted"><i class="fa-solid fa-rotate-left me-1"></i> العودة لتسجيل الدخول</a>
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