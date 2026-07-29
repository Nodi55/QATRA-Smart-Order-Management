<?php
// استدعاء ملف الاتصال بقاعدة البيانات
require_once 'db_connect.php'; 
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة نظام قطرة - QATRA</title>
    <style>
        /* تنسيق مبدئي بسيط جداً يمكنكم تطويره لاحقاً */
        body { font-family: Arial, sans-serif; text-align: center; background-color: #f4f7f6; margin-top: 50px; }
        .container { background: #fff; padding: 30px; border-radius: 10px; width: 50%; margin: auto; box-shadow: 0px 4px 8px rgba(0,0,0,0.1); }
        .btn { display: inline-block; padding: 10px 20px; margin: 10px; text-decoration: none; color: white; border-radius: 5px; }
        .btn-customer { background-color: #0056b3; }
        .btn-employee { background-color: #28a745; }
    </style>
</head>
<body>

    <div class="container">
        <h1>مرحباً بك في نظام إدارة الطلبات الذكي (قطرة)</h1>
        <p>يرجى اختيار بوابة الدخول المناسبة:</p>

        <!-- مسار العملاء -->
        <div style="margin-bottom: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
            <h3>👨‍💼 بوابة العملاء</h3>
            <p>لتقديم طلبات المياه والصرف الصحي ومتابعتها</p>
            <a href="customer_login.php" class="btn btn-customer">تسجيل الدخول (OTP)</a>
            <a href="customer_register.php" class="btn btn-customer" style="background-color: #17a2b8;">تسجيل حساب جديد</a>
        </div>

        <!-- مسار الموظفين -->
        <div style="padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
            <h3>🛠️ بوابة فريق العمل</h3>
            <p>للمدققين، وإدارة المهام، والفنيين الميدانيين</p>
            <a href="employee_login.php" class="btn btn-employee">دخول الموظفين (Email)</a>
        </div>
    </div>

</body>
</html>