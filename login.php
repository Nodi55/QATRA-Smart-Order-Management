<?php
// بدء الجلسة (Session) لحفظ بيانات العميل بعد تسجيل الدخول
session_start();

// حل مشكلة الترميز
header('Content-Type: text/html; charset=utf-8');

// استدعاء ملف الاتصال السحابي
require_once 'db_connect.php';

$message = '';

// التحقق عند الضغط على زر "تسجيل الدخول"
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nationalId = trim($_POST['national_id']);
    $password = $_POST['password'];

    try {
        // 🌟 التعديل هنا: تم تغيير Customer إلى customer (بحرف صغير) ليطابق السحابة
        $stmt = $pdo->prepare("SELECT * FROM customer WHERE national_id = :national_id");
        $stmt->execute(['national_id' => $nationalId]);
        // تم تغيير المتغير أيضاً ليطابق السحابة
        $customerData = $stmt->fetch(PDO::FETCH_ASSOC);

        // إذا تم العثور على العميل، نتحقق من صحة كلمة المرور
        if ($customerData && password_verify($password, $customerData['password_hash'])) {
            
            // حفظ بيانات العميل في الجلسة (Session) ليتذكر النظام من هو
            $_SESSION['customer_national_id'] = $customerData['national_id'];
            $_SESSION['customer_name'] = $customerData['full_name'];
            
            // توجيه العميل فوراً إلى لوحة التحكم (صفحة تقديم الطلبات)
            header("Location: dashboard.php");
            exit();
            
        } else {
            // رسالة خطأ في حال كانت الهوية أو الباسورد غير صحيحة
            $message = "رقم الهوية أو كلمة المرور غير صحيحة!";
        }
    } catch (PDOException $e) {
        $message = "خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول | نظام قطرة</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f4f7f6; padding: 60px 0; }
        .login-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); overflow: hidden; border: none; }
        .login-header { background: linear-gradient(135deg, #003366 0%, #0077b6 100%); color: white; padding: 40px 30px; text-align: center; }
        .login-body { padding: 40px; }
        .form-label { font-weight: 700; color: #003366; }
        .input-group-text { background: transparent; border-left: none; color: #0077b6; border-radius: 0 10px 10px 0; }
        .form-control { border-radius: 10px 0 0 10px; border-right: none; }
        .btn-login { background: #0077b6; color: white; border-radius: 10px; padding: 12px; font-weight: 800; width: 100%; margin-top: 15px; transition: 0.3s; }
        .btn-login:hover { background: #005f8f; color: white; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,119,182,0.3); }
        .forgot-password { color: #d9534f; font-size: 0.9rem; font-weight: 600; text-decoration: none; transition: 0.3s; }
        .forgot-password:hover { color: #c9302c; text-decoration: underline; }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            
            <?php if(!empty($message)): ?>
                <div class="alert alert-danger alert-dismissible fade show fw-bold" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="login-card">
                <div class="login-header">
                    <i class="fa-solid fa-droplet fa-4x mb-3 text-info"></i>
                    <h2>نظام قطرة</h2>
                    <p class="mb-0 opacity-75">بوابتك الذكية لإدارة خدمات المياه</p>
                </div>
                
                <div class="login-body">
                    <form method="POST" action="login.php">
                        
                        <div class="mb-4">
                            <label class="form-label">رقم الهوية</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-id-card"></i></span>
                                <input type="text" name="national_id" class="form-control" placeholder="أدخل رقم الهوية (10 أرقام)" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">كلمة المرور</label>
                                <a href="#" class="forgot-password">نسيت كلمة المرور؟</a>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" name="password" class="form-control" placeholder="أدخل كلمة المرور" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-login">
                            تسجيل الدخول <i class="fa-solid fa-arrow-right-to-bracket ms-2"></i>
                        </button>
                    </form>
                    
                    <a href="customer_register.php" class="d-block text-center mt-4 text-decoration-none fw-bold" style="color: #666; transition: 0.3s;" onmouseover="this.style.color='#003366'" onmouseout="this.style.color='#666'">
                        ليس لديك حساب؟ سجل الآن
                    </a>

                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>