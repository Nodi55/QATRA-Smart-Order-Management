<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// إذا كان مسجلاً للدخول مسبقاً يوجه للوحة التحكم
if (isset($_SESSION['customer_national_id'])) {
    header("Location: dashboard.php");
    exit;
}

require_once 'db_connect.php';
$errorMsg = "";

// جلب المدن والمناطق من قاعدة البيانات
$groupedCities = [];
try {
    $stmt = $pdo->query("SELECT c.cty_id, c.cty_name, r.reg_name FROM city c JOIN region r ON c.reg_id = r.reg_id ORDER BY r.reg_id, c.cty_name");
    $citiesWithRegions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($citiesWithRegions as $row) { $groupedCities[$row['reg_name']][] = $row; }
} catch (Exception $e) {
    try {
        $stmt = $pdo->query("SELECT c.cty_id, c.cty_name, r.reg_name FROM City c JOIN Region r ON c.reg_id = r.reg_id ORDER BY r.reg_id, c.cty_name");
        $citiesWithRegions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($citiesWithRegions as $row) { $groupedCities[$row['reg_name']][] = $row; }
    } catch (Exception $e2) {}
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nationalId = trim($_POST['national_id']);
    $fullName = trim($_POST['full_name']);
    $phone = trim($_POST['phone_number']);
    $password = $_POST['password'];
    $cityId = $_POST['cty_id']; 

    if (!preg_match('/^\d{10}$/', $nationalId)) {
        $errorMsg = "رقم الهوية يجب أن يتكون من 10 أرقام.";
    } elseif (!preg_match('/^05\d{8}$/', $phone)) {
        $errorMsg = "رقم الجوال يجب أن يبدأ بـ 05 ويتكون من 10 أرقام.";
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO customer (national_id, full_name, phone_number, password_hash, cty_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nationalId, $fullName, $phone, $hashedPassword, $cityId]);
            header("Location: login.php?registered=success");
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $errorMsg = "عفواً، رقم الهوية مسجل مسبقاً في النظام.";
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO Customer (national_id, full_name, phone_number, password_hash, cty_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$nationalId, $fullName, $phone, $hashedPassword, $cityId]);
                    header("Location: login.php?registered=success");
                    exit;
                } catch (PDOException $e2) {
                    $errorMsg = "حدث خطأ غير متوقع أثناء التسجيل.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء حساب | نظام قطرة</title>
    <!-- الخطوط والمكتبات -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --qatra-blue: #0b457f; /* اللون المستوحى من صورتك */
            --qatra-light-blue: #4492d4; 
            --bg-color: #f8fafc;
        }
        body { 
            font-family: 'Cairo', sans-serif; 
            background-color: var(--bg-color); 
            min-height: 100vh;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        /* --- الهيدر والتموجات العلوية (متطابق مع صورتك) --- */
        .hero-section {
            background: linear-gradient(180deg, #093c6f 0%, #10599c 100%);
            position: relative;
            padding-bottom: 120px;
        }
        
        .custom-navbar {
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* الشعار المستوحى من الهوية */
        .brand-logo {
            text-align: center;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .brand-text {
            line-height: 1.1;
        }
        .brand-text-en { font-size: 1.4rem; font-weight: 900; letter-spacing: 2px; }
        .brand-text-ar { font-size: 0.9rem; font-weight: 600; color: #bae6fd; }
        .brand-mark { width: 34px; height: 40px; flex-shrink: 0; }
        
        /* زر دخول الموظفين */
        .btn-employee {
            background: rgba(255, 255, 255, 0.9);
            color: var(--qatra-blue);
            border-radius: 50px;
            padding: 8px 25px;
            font-weight: 800;
            font-size: 0.95rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: 0.3s;
        }
        .btn-employee:hover { background: white; transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0,0,0,0.15); color: var(--qatra-blue); }

        /* التموج الأبيض أسفل الهيدر (Wave) */
        .wave-bottom {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            overflow: hidden;
            line-height: 0;
            transform: translateY(1px);
        }
        .wave-bottom svg {
            display: block;
            width: calc(100% + 1.3px);
            height: 80px;
        }
        .wave-bottom .shape-fill { fill: var(--bg-color); }

        /* --- بطاقة التسجيل (تطفو فوق التموج) --- */
        .register-container {
            margin-top: -80px; /* لرفع البطاقة فوق التموج */
            position: relative;
            z-index: 10;
            padding: 0 20px 50px;
        }
        
        .register-card { 
            background: white; 
            border-radius: 24px; 
            padding: 45px 50px; 
            box-shadow: 0 20px 50px rgba(11, 69, 127, 0.1); 
            width: 100%; 
            max-width: 650px; 
            margin: 0 auto;
            border: 1px solid #e2e8f0;
            animation: fadeInUp 0.7s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; 
        }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        .card-header-title {
            text-align: center;
            margin-bottom: 35px;
        }
        .badge-system {
            background: rgba(11, 69, 127, 0.08);
            color: var(--qatra-blue);
            padding: 6px 15px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 800;
            display: inline-block;
            margin-bottom: 15px;
        }

        /* --- النماذج والحقول --- */
        .form-label { font-weight: 800; color: var(--qatra-blue); font-size: 0.95rem; margin-bottom: 8px; }
        .input-group-text { background: #f8fafc; border: 2px solid #e2e8f0; border-left: none; color: #64748b; border-radius: 0 12px 12px 0; font-size: 1.1rem; transition: 0.3s; }
        .form-control, .form-select { 
            border-radius: 12px 0 0 12px; border: 2px solid #e2e8f0; border-right: none; 
            padding: 14px 20px; font-weight: 700; color: #1e293b; background: #f8fafc; transition: all 0.3s; 
        }
        .form-select { border-radius: 12px; border-right: 2px solid #e2e8f0; }
        
        .form-control:focus, .form-select:focus { background: white; border-color: var(--qatra-light-blue); outline: none; box-shadow: none; }
        .form-control:focus + .input-group-text, .input-group:focus-within .input-group-text { border-color: var(--qatra-light-blue); color: var(--qatra-light-blue); background: white; }
        .input-group:focus-within { box-shadow: 0 0 0 4px rgba(68, 146, 212, 0.15); border-radius: 12px; }

        optgroup { font-weight: 900; color: var(--qatra-blue); background: #f1f5f9; }

        /* الزر الرئيسي مطابق لتصميم صورتك */
        .btn-brand { 
            background: var(--qatra-light-blue); 
            color: white; border: none; border-radius: 12px; padding: 16px; 
            font-weight: 900; font-size: 1.1rem; width: 100%; margin-top: 15px; 
            transition: all 0.3s; box-shadow: 0 8px 20px rgba(68, 146, 212, 0.3); 
        }
        .btn-brand:hover { background: #3580c0; transform: translateY(-2px); box-shadow: 0 12px 25px rgba(68, 146, 212, 0.4); color: white; }

        /* نافذة روابط إضافية */
        .footer-links { text-align: center; margin-top: 30px; font-weight: 700; color: #64748b; }
        .footer-links a { color: var(--qatra-light-blue); text-decoration: none; transition: 0.3s; }
        .footer-links a:hover { color: var(--qatra-blue); }

        @media (max-width: 768px) {
            .register-card { padding: 30px 20px; }
            .custom-navbar { flex-direction: column; gap: 15px; }
        }
    </style>
</head>
<body>

<!-- القسم العلوي الأزرق (الهيدر) -->
<div class="hero-section">
    <!-- الشريط العلوي متطابق مع صورتك -->
    <div class="custom-navbar">
        <a href="employee_login.php" class="btn-employee">
            <i class="fa-solid fa-arrow-right-to-bracket"></i> دخول الموظفين
        </a>
        
        <!-- روابط المنتصف (اختيارية) -->
        <div class="d-none d-md-flex gap-4 fw-bold" style="color: #bae6fd; font-size: 0.95rem;">
            <a href="#" class="text-white text-decoration-none">الرئيسية</a>
            <a href="#" class="text-white text-decoration-none">البوابات</a>
            <a href="#" class="text-white text-decoration-none">عن النظام</a>
        </div>

        <!-- الشعار -->
        <a href="index.php" class="brand-logo">
            <div class="brand-text text-end">
                <div class="brand-text-en">QATRA</div>
                <div class="brand-text-ar">قطرة</div>
            </div>
            <!-- شعار قطرة الرسمي: قطرة مكونة من نقاط بتدرج لوني -->
            <svg class="brand-mark" viewBox="0 0 60 68" xmlns="http://www.w3.org/2000/svg">
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
        </a>
    </div>

    <!-- التموج الأبيض في أسفل الهيدر -->
    <div class="wave-bottom">
        <svg data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120" preserveAspectRatio="none">
            <path d="M321.39,56.44c58-10.79,114.16-30.13,172-41.86,82.39-16.72,168.19-17.73,250.45-.39C823.78,31,906.67,72,985.66,92.83c70.05,18.48,146.53,26.09,214.34,3V120H0V95.8C59.71,118.08,130.83,119.33,197.8,109.1,239.5,102.73,280.9,82.52,321.39,56.44Z" class="shape-fill"></path>
        </svg>
    </div>
</div>

<!-- بطاقة التسجيل -->
<div class="register-container">
    <div class="register-card">
        
        <div class="card-header-title">
            <span class="badge-system"><i class="fa-solid fa-circle-check"></i> منصة تشغيلية ذكية</span>
            <h2 class="fw-black m-0" style="color: var(--qatra-blue);">تسجيل مستفيد جديد</h2>
            <p class="text-muted fw-bold mt-2 fs-6">رحلة رقمية واحدة تبدأ بإنشاء حسابك لإدارة عقاراتك.</p>
        </div>

        <?php if($errorMsg): ?>
        <div class="alert alert-danger fw-bold rounded-3 mb-4 border-0" style="background: #fef2f2; color: #b91c1c;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= $errorMsg; ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <!-- سطر 1: الاسم -->
            <div class="mb-4">
                <label class="form-label">الاسم الرباعي</label>
                <div class="input-group">
                    <input type="text" name="full_name" class="form-control" placeholder="أدخل اسمك كما في الهوية الوطنية" required>
                    <span class="input-group-text"><i class="fa-regular fa-user"></i></span>
                </div>
            </div>

            <!-- سطر 2: الهوية والجوال -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <label class="form-label">رقم الهوية</label>
                    <div class="input-group" style="direction: ltr;">
                        <input type="text" name="national_id" class="form-control text-end" placeholder="10 أرقام" required maxlength="10" pattern="\d{10}" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <span class="input-group-text"><i class="fa-regular fa-id-card"></i></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">رقم الجوال</label>
                    <div class="input-group" style="direction: ltr;">
                        <input type="text" name="phone_number" class="form-control text-end" placeholder="05XXXXXXXX" required maxlength="10" pattern="05\d{8}" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <span class="input-group-text"><i class="fa-solid fa-mobile-screen"></i></span>
                    </div>
                </div>
            </div>

            <!-- سطر 3: مدينة الإقامة -->
            <div class="mb-4">
                <label class="form-label">مدينة الإقامة (مقر السكن)</label>
                <div class="input-group position-relative">
                    <select name="cty_id" class="form-select" required>
                        <option value="" selected disabled>-- الرجاء تحديد مدينة إقامتك --</option>
                        <?php foreach($groupedCities as $region => $cities): ?>
                            <optgroup label="📍 منطقة <?= htmlspecialchars($region); ?>">
                                <?php foreach($cities as $c): ?>
                                    <option value="<?= $c['cty_id']; ?>">&nbsp;&nbsp;مدينة <?= htmlspecialchars($c['cty_name']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- سطر 4: كلمة المرور -->
            <div class="mb-4">
                <label class="form-label">كلمة المرور</label>
                <div class="input-group" style="direction: ltr;">
                    <input type="password" name="password" class="form-control text-end" placeholder="أدخل كلمة مرور قوية لحسابك" required minlength="6">
                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                </div>
            </div>
            
            <button type="submit" class="btn-brand">
                ابدأ الآن <i class="fa-solid fa-arrow-left ms-2"></i>
            </button>
        </form>
        
        <div class="footer-links">
            لديك حساب مسجل مسبقاً في النظام؟ 
            <a href="login.php"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i> تسجيل الدخول</a>
        </div>
    </div>
</div>

</body>
</html>