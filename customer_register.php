<?php
/**
 * =====================================================================
 * QATRA (قطرة) - نظام إدارة الطلبات الذكي
 * صفحة تسجيل حساب عميل جديد
 * متوافقة بصرياً مع index.php المعتمدة (Bootstrap 5 RTL)
 * ---------------------------------------------------------------------
 * الجدول: Customer (cust_id PK, national_id UNIQUE, full_name,
 *                    phone_number, password_hash, cty_id FK, created_at)
 * =====================================================================
 */
// إجبار المتصفح على قراءة الصفحة بترميز UTF-8 من خلال هيدر HTTP
// (بعض إعدادات XAMPP/Apache الافتراضية ترسل ترميز مختلف يتجاوز meta charset)
header('Content-Type: text/html; charset=UTF-8');

require_once 'db_connect.php';

// ---------------------------------------------------------------------
// متغيرات الحالة لعرض رسائل النجاح/الخطأ
// ---------------------------------------------------------------------
$successMessage = '';
$errorMessage   = '';

// الاحتفاظ بالقيم المدخلة عند حدوث خطأ (بدون كلمة المرور)
$old = [
    'full_name'    => '',
    'national_id'  => '',
    'phone_number' => '',
    'cty_id'       => '',
];

// ---------------------------------------------------------------------
// معالجة إرسال النموذج
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $fullName   = trim($_POST['full_name']    ?? '');
    $nationalId = trim($_POST['national_id']  ?? '');
    $phone      = trim($_POST['phone_number'] ?? '');
    $password   = $_POST['password']          ?? '';
    $ctyId      = trim($_POST['cty_id']       ?? '');

    $old = [
        'full_name'    => $fullName,
        'national_id'  => $nationalId,
        'phone_number' => $phone,
        'cty_id'       => $ctyId,
    ];

    // --- التحقق من صحة البيانات ---
    $validationErrors = [];

    if ($fullName === '' || mb_strlen($fullName) < 3) {
        $validationErrors[] = 'الرجاء إدخال الاسم الكامل بشكل صحيح.';
    }
    if (!preg_match('/^\d{10}$/', $nationalId)) {
        $validationErrors[] = 'رقم الهوية الوطنية يجب أن يتكون من 10 أرقام بالضبط.';
    }
    if (!preg_match('/^05\d{8}$/', $phone)) {
        $validationErrors[] = 'رقم الجوال غير صحيح، يجب أن يبدأ بـ 05 ويتكون من 10 أرقام.';
    }
    if (strlen($password) < 8) {
        $validationErrors[] = 'كلمة المرور يجب ألا تقل عن 8 أحرف.';
    }
    if ($ctyId === '' || !ctype_digit($ctyId)) {
        $validationErrors[] = 'الرجاء اختيار المدينة.';
    }

    if (empty($validationErrors)) {
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                'INSERT INTO Customer (national_id, full_name, phone_number, password_hash, cty_id)
                 VALUES (:national_id, :full_name, :phone_number, :password_hash, :cty_id)'
            );

            $stmt->execute([
                ':national_id'   => $nationalId,
                ':full_name'     => $fullName,
                ':phone_number'  => $phone,
                ':password_hash' => $passwordHash,
                ':cty_id'        => $ctyId,
            ]);

            $successMessage = 'تم إنشاء حسابك بنجاح! يمكنك الآن تسجيل الدخول عبر رقم جوالك.';
            $old = ['full_name' => '', 'national_id' => '', 'phone_number' => '', 'cty_id' => ''];

        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $errorMessage = 'رقم الهوية الوطنية مسجل مسبقاً في النظام. الرجاء تسجيل الدخول بدلاً من ذلك.';
            } else {
                $errorMessage = 'حدث خطأ غير متوقع أثناء إنشاء الحساب. الرجاء المحاولة لاحقاً.';
                // للمطورين: يمكن تسجيل $e->getMessage() في ملف لوج بدلاً من عرضه للمستخدم
            }
        }
    } else {
        $errorMessage = implode('<br>', $validationErrors);
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء حساب جديد | قطرة QATRA</title>

    <!-- مكتبة Bootstrap 5 (RTL) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- خط Cairo -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background-color: #f4f7f6;
            color: #333;
        }

        /* شريط التنقل - مطابق لصفحة index.php */
        .navbar {
            background-color: white;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
        }
        .navbar-brand {
            font-weight: 800;
            color: #003366 !important;
            font-size: 1.5rem;
        }
        .brand-subtitle {
            font-size: 0.9rem;
            color: #0077b6;
            font-weight: 600;
        }

        /* حاوية النموذج */
        .register-wrapper {
            padding: 60px 0 80px;
        }
        .register-card {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            padding: 45px;
            max-width: 640px;
            margin: 0 auto;
        }
        .register-header {
            text-align: center;
            margin-bottom: 35px;
        }
        .icon-wrapper-form {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            margin: 0 auto 18px;
            background-color: #e0f2fe;
            color: #0ea5e9;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .register-header h1 {
            font-weight: 800;
            color: #003366;
            font-size: 1.7rem;
            margin-bottom: 8px;
        }
        .register-header p { color: #777; font-size: 0.95rem; }

        /* حقول الإدخال */
        .form-label {
            font-weight: 600;
            color: #003366;
            margin-bottom: 8px;
        }
        .input-group-text {
            background-color: #f4f7f6;
            border: 1px solid #dee2e6;
            color: #0077b6;
        }
        .form-control, .form-select {
            border: 1px solid #dee2e6;
            padding: 10px 14px;
            border-radius: 10px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #0077b6;
            box-shadow: 0 0 0 0.2rem rgba(0,119,182,0.15);
        }

        /* الأزرار - نفس هوية index.php */
        .btn-custom {
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            width: 100%;
            text-decoration: none;
            display: inline-block;
            border: none;
        }
        .btn-primary-qatra { background-color: #0077b6; color: white; }
        .btn-primary-qatra:hover { background-color: #005f8f; color: white; }

        footer { text-align: center; padding: 20px 0; color: #888; font-size: 0.9rem; }
    </style>
</head>
<body>

    <!-- شريط التنقل -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <i class="fa-solid fa-droplet me-2" style="color: #0077b6; margin-left: 10px;"></i>
                قطرة <span class="mx-2 text-muted">|</span> <span class="brand-subtitle">شركة المياه الوطنية</span>
            </a>
            <a href="index.php" class="text-decoration-none" style="color: #0077b6; font-weight: 600;">
                <i class="fa-solid fa-arrow-right me-1"></i> العودة للرئيسية
            </a>
        </div>
    </nav>

    <!-- نموذج التسجيل -->
    <section class="register-wrapper container">
        <div class="register-card">

            <div class="register-header">
                <div class="icon-wrapper-form">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
                <h1>إنشاء حساب عميل جديد</h1>
                <p>يرجى تعبئة البيانات التالية لإنشاء حسابك بشكل آمن.</p>
            </div>

            <?php if ($successMessage): ?>
                <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
                    <i class="fa-solid fa-circle-check"></i>
                    <div><?= $successMessage ?></div>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div><?= $errorMessage ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>

                <!-- الاسم الكامل -->
                <div class="mb-3">
                    <label class="form-label">الاسم الكامل</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                        <input type="text" name="full_name" class="form-control" required
                               value="<?= htmlspecialchars($old['full_name']) ?>"
                               placeholder="مثال: عبدالله محمد العتيبي">
                    </div>
                </div>

                <!-- رقم الهوية الوطنية -->
                <div class="mb-3">
                    <label class="form-label">رقم الهوية الوطنية</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-id-card"></i></span>
                        <input type="text" name="national_id" class="form-control" required
                               maxlength="10" pattern="\d{10}" dir="ltr"
                               value="<?= htmlspecialchars($old['national_id']) ?>"
                               placeholder="10 أرقام">
                    </div>
                </div>

                <!-- رقم الجوال -->
                <div class="mb-3">
                    <label class="form-label">رقم الجوال</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-mobile-screen-button"></i></span>
                        <input type="text" name="phone_number" class="form-control" required
                               maxlength="10" pattern="05\d{8}" dir="ltr"
                               value="<?= htmlspecialchars($old['phone_number']) ?>"
                               placeholder="05xxxxxxxx">
                    </div>
                </div>

                <!-- كلمة المرور -->
                <div class="mb-3">
                    <label class="form-label">كلمة المرور</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="password" class="form-control" required
                               minlength="8" placeholder="8 أحرف على الأقل">
                    </div>
                </div>

                <!-- المدينة (بيانات ثابتة مؤقتة - يفضل ربطها بجدول City) -->
                <div class="mb-4">
                    <label class="form-label">المدينة</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-city"></i></span>
                        <select name="cty_id" class="form-select" required>
                            <option value="" disabled <?= $old['cty_id'] === '' ? 'selected' : '' ?>>اختر المدينة</option>
                            <option value="1" <?= $old['cty_id'] === '1' ? 'selected' : '' ?>>الرياض</option>
                            <option value="2" <?= $old['cty_id'] === '2' ? 'selected' : '' ?>>جدة</option>
                            <option value="3" <?= $old['cty_id'] === '3' ? 'selected' : '' ?>>الدمام</option>
                            <option value="4" <?= $old['cty_id'] === '4' ? 'selected' : '' ?>>بريدة</option>
                            <option value="5" <?= $old['cty_id'] === '5' ? 'selected' : '' ?>>مكة المكرمة</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn-custom btn-primary-qatra">
                    <i class="fa-solid fa-user-plus me-2"></i> إنشاء الحساب
                </button>

                <p class="text-center mt-4" style="color: #777;">
                    لديك حساب بالفعل؟
                    <a href="customer_login.php" style="color: #0077b6; font-weight: 700; text-decoration: none;">تسجيل الدخول</a>
                </p>
            </form>
        </div>
    </section>

    <!-- الفوتر -->
    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> جميع الحقوق محفوظة - نظام قطرة | شركة المياه الوطنية (NWC)</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>