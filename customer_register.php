<?php
/**
 * =====================================================================
 * QATRA (قطرة) - نظام إدارة الطلبات الذكي
 * صفحة تسجيل حساب عميل جديد
 * =====================================================================
 * الجدول: Customer (cust_id PK, national_id UNIQUE, full_name,
 *                    phone_number, password_hash, cty_id FK, created_at)
 * ملاحظة: هذا الملف يعتمد على db_connect.php الذي يُنشئ متغير $pdo
 * =====================================================================
 */

// إجبار المتصفح والخادم على قراءة النصوص العربية
header('Content-Type: text/html; charset=UTF-8');

// استدعاء وحيد لملف الاتصال - يُنشئ متغير $pdo
require_once 'db_connect.php';

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

    // --- التحقق الفعلي من صحة البيانات في PHP ---
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

            // ⚠️ استخدام $pdo (وليس $conn) والعمود الصحيح cty_id
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

            $successMessage = 'تم تسجيل حسابك بنجاح! مرحباً بك في قطرة.';
            $old = ['full_name' => '', 'national_id' => '', 'phone_number' => '', 'cty_id' => ''];

        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $errorMessage = 'رقم الهوية الوطنية مسجل مسبقاً في النظام. يمكنك تسجيل الدخول بدلاً من ذلك.';
            } else {
                $errorMessage = 'حدث خطأ غير متوقع أثناء إنشاء الحساب. الرجاء المحاولة لاحقاً.';
                // للمطورين: سجّلي $e->getMessage() في ملف لوج بدلاً من عرضه للمستخدم
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
        .form-control:focus, .form-select:focus { border-color: #0077b6; box-shadow: none; }
        .btn-register { background: #0077b6; color: white; border-radius: 10px; padding: 12px; font-weight: 800; width: 100%; margin-top: 15px; border: none; transition: 0.3s; }
        .btn-register:hover { background: #005f8f; color: white; transform: translateY(-2px); }
        optgroup { font-weight: bold; color: #003366; }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">

            <?php if ($successMessage): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $successMessage ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $errorMessage ?>
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
                    <form method="POST" action="" novalidate>

                        <div class="mb-3">
                            <label class="form-label">الاسم الرباعي</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                                <input type="text" name="full_name" class="form-control"
                                       value="<?= htmlspecialchars($old['full_name']) ?>" required>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">رقم الهوية</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-id-card"></i></span>
                                    <input type="text" name="national_id" class="form-control"
                                           maxlength="10" pattern="\d{10}" dir="ltr"
                                           value="<?= htmlspecialchars($old['national_id']) ?>"
                                           placeholder="10 أرقام" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">رقم الجوال</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-phone"></i></span>
                                    <input type="text" name="phone_number" class="form-control"
                                           maxlength="10" pattern="05\d{8}" dir="ltr"
                                           value="<?= htmlspecialchars($old['phone_number']) ?>"
                                           placeholder="05xxxxxxxx" required>
                                </div>
                            </div>
                        </div>

                        <!-- قائمة المدن: تُجلب ديناميكياً من City JOIN Region -->
                        <div class="mb-3">
                            <label class="form-label">المنطقة والمدينة</label>
                            <select name="cty_id" class="form-select" required>
                                <option value="" disabled <?= $old['cty_id'] === '' ? 'selected' : '' ?>>اختر مدينتك (القطاع الشمالي)...</option>
                                <?php
                                try {
                                    // الترتيب حسب reg_id ثم cty_id (نفس ترتيب الإدخال في ملف seed_regions_cities.sql)
                                    // وليس ترتيباً أبجدياً، حتى تطابق القائمة نفس ترتيب كودك تماماً
                                    $cityQuery = "SELECT City.cty_id, City.cty_name, Region.reg_name
                                                  FROM City
                                                  JOIN Region ON City.reg_id = Region.reg_id
                                                  ORDER BY Region.reg_id, City.cty_id";

                                    $stmtCities = $pdo->query($cityQuery);
                                    $citiesData = $stmtCities->fetchAll(PDO::FETCH_ASSOC);

                                    $currentRegion = '';
                                    foreach ($citiesData as $row) {
                                        if ($currentRegion !== $row['reg_name']) {
                                            if ($currentRegion !== '') echo '</optgroup>';
                                            $currentRegion = $row['reg_name'];
                                            echo '<optgroup label="' . htmlspecialchars($currentRegion) . '">';
                                        }
                                        $isSelected = ((string) $row['cty_id'] === $old['cty_id']) ? 'selected' : '';
                                        echo '<option value="' . (int) $row['cty_id'] . '" ' . $isSelected . '>'
                                             . htmlspecialchars($row['cty_name']) . '</option>';
                                    }
                                    if ($currentRegion !== '') echo '</optgroup>';

                                    if (empty($citiesData)) {
                                        echo '<option disabled>لا توجد مدن مسجلة حالياً - نفّذي ملف seed_regions_cities.sql</option>';
                                    }
                                } catch (PDOException $e) {
                                    echo '<option disabled>عذراً، لا يمكن جلب المدن حالياً</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">كلمة المرور</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" name="password" class="form-control"
                                       minlength="8" placeholder="أدخل كلمة المرور (8 أحرف على الأقل)" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-register">تسجيل الحساب</button>

                        <a href="index.php" class="d-block text-center mt-4 text-decoration-none fw-bold" style="color: #666; transition: 0.3s;" onmouseover="this.style.color='#003366'" onmouseout="this.style.color='#666'">
                            <i class="fa-solid fa-arrow-right-long me-1"></i> العودة للصفحة الرئيسية
                        </a>

                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>