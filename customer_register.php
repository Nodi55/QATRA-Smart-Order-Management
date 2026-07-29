<?php
// حل مشكلة الترميز (اللغة العربية)
header('Content-Type: text/html; charset=utf-8');

// استدعاء ملف الاتصال
require_once 'db_connect.php';

$message = '';
$messageType = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullName = $_POST['full_name'];
    $nationalId = $_POST['national_id'];
    $phone = $_POST['phone_number'];
    $cityId = $_POST['cty_id'];
    $password = $_POST['password']; // استلام الباسورد

    if (strlen($nationalId) != 10) {
        $message = "عفواً، رقم الهوية يجب أن يتكون من 10 أرقام.";
        $messageType = "danger";
    } else {
        try {
            $checkStmt = $conn->prepare("SELECT national_id FROM Customer WHERE national_id = :national_id");
            $checkStmt->execute(['national_id' => $nationalId]);
            
            if ($checkStmt->rowCount() > 0) {
                $message = "رقم الهوية مسجل مسبقاً! يرجى تسجيل الدخول.";
                $messageType = "warning";
            } else {
                // تشفير الباسورد
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $createdAt = date('Y-m-d H:i:s');

                // إدخال البيانات شاملة الباسورد والمدينة
                $insertQuery = "INSERT INTO Customer (national_id, full_name, phone_number, password_hash, city_id, created_at) 
                                VALUES (:national_id, :full_name, :phone, :password_hash, :city_id, :created_at)";
                $stmt = $conn->prepare($insertQuery);
                $stmt->execute([
                    'national_id' => $nationalId,
                    'full_name' => $fullName,
                    'phone' => $phone,
                    'password_hash' => $passwordHash,
                    'city_id' => $cityId,
                    'created_at' => $createdAt
                ]);

                $message = "تم التسجيل بنجاح! مرحباً بك في قطرة.";
                $messageType = "success";
            }
        } catch (PDOException $e) {
            $message = "خطأ في قاعدة البيانات: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل حساب جديد | قطرة</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f4f7f6; padding: 40px 0; }
        .register-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); overflow: hidden; border: none; }
        .register-header { background: linear-gradient(135deg, #003366 0%, #0077b6 100%); color: white; padding: 30px; text-align: center; }
        .register-body { padding: 40px; }
        .form-label { font-weight: 700; color: #003366; }
        .input-group-text { background: transparent; border-left: none; color: #0077b6; border-radius: 0 10px 10px 0; }
        .form-control, .form-select { border-radius: 10px 0 0 10px; border-right: none; }
        .form-select { border-radius: 10px; border-right: 1px solid #dee2e6; }
        .btn-register { background: #0077b6; color: white; border-radius: 10px; padding: 12px; font-weight: 800; width: 100%; margin-top: 15px; }
        .btn-register:hover { background: #005f8f; color: white; transform: translateY(-2px); }
        optgroup { font-weight: bold; color: #003366; }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            
            <?php if(!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="register-card">
                <div class="register-header">
                    <i class="fa-solid fa-user-shield fa-3x mb-2 text-info"></i>
                    <h3>إنشاء حساب العميل</h3>
                    <p class="mb-0">القطاع الشمالي - شركة المياه الوطنية</p>
                </div>
                
                <div class="register-body">
                    <form method="POST" action="customer_register.php">
                        
                        <div class="mb-3">
                            <label class="form-label">الاسم الرباعي</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                                <input type="text" name="full_name" class="form-control" required>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">رقم الهوية</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-id-card"></i></span>
                                    <input type="text" name="national_id" class="form-control" placeholder="10 أرقام" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">رقم الجوال</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-phone"></i></span>
                                    <input type="text" name="phone_number" class="form-control" placeholder="05XXXXXXXX" required>
                                </div>
                            </div>
                        </div>

                        <!-- تم إضافة المدن كقائمة ثابتة لتعمل معك فوراً -->
                        <div class="mb-3">
                            <label class="form-label">المنطقة والمدينة</label>
                            <select name="cty_id" class="form-select" required>
                                <option value="" selected disabled>اختر مدينتك (القطاع الشمالي)...</option>
                                <optgroup label="منطقة القصيم">
                                    <option value="1">بريدة</option>
                                    <option value="2">عنيزة</option>
                                    <option value="3">الرس</option>
                                </optgroup>
                                <optgroup label="منطقة حائل">
                                    <option value="4">حائل</option>
                                    <option value="5">بقعاء</option>
                                </optgroup>
                                <optgroup label="منطقة الجوف">
                                    <option value="6">سكاكا</option>
                                    <option value="7">القريات</option>
                                </optgroup>
                                <optgroup label="الحدود الشمالية">
                                    <option value="8">عرعر</option>
                                    <option value="9">رفحاء</option>
                                </optgroup>
                            </select>
                        </div>

                        <!-- حقل الباسورد متواجد هنا بشكل واضح -->
                        <div class="mb-4">
                            <label class="form-label">كلمة المرور</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" name="password" class="form-control" placeholder="أدخل كلمة المرور" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-register">تسجيل الحساب</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
