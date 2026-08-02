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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
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

        /* --- الشعار --- */
        .css-logo {
            text-align: center;
            margin-bottom: 25px;
            position: relative;
        }
        .css-logo .droplet-mark {
            width: 54px;
            height: 62px;
            margin: 0 auto 10px;
            filter: drop-shadow(0 10px 15px rgba(125, 211, 252, 0.4));
            animation: pulseGlow 2s infinite alternate;
        }
        @keyframes pulseGlow {
            0% { filter: drop-shadow(0 5px 15px rgba(125, 211, 252, 0.2)); transform: scale(1); }
            100% { filter: drop-shadow(0 15px 25px rgba(125, 211, 252, 0.6)); transform: scale(1.05); }
        }
        .css-logo .brand-name-en { font-size: 1.8rem; font-weight: 900; letter-spacing: 4px; color: white; margin-bottom: -5px; }
        .css-logo .brand-name-ar { font-size: 1.1rem; font-weight: 600; color: var(--qatra-cyan); letter-spacing: 1px; }

        /* --- البطاقة الزجاجية --- */
        .register-card {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            padding: 45px 50px;
            width: 100%;
            max-width: 650px;
            z-index: 10;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4);
            animation: slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transform: translateY(40px);
            position: relative;
        }
        @keyframes slideUp { to { opacity: 1; transform: translateY(0); } }

        .register-card::before {
            content: ''; position: absolute; top: 0; left: 10%; width: 80%; height: 4px;
            background: linear-gradient(90deg, transparent, var(--qatra-light), transparent);
            border-radius: 0 0 10px 10px;
        }

        .card-header-title { text-align: center; margin-bottom: 30px; color: var(--qatra-navy); }
        
        .badge-system {
            background: #f1f5f9; color: var(--qatra-blue); padding: 6px 15px;
            border-radius: 50px; font-size: 0.85rem; font-weight: 800; border: 1px solid #e2e8f0;
            display: inline-flex; align-items: center; gap: 6px; margin-bottom: 10px;
        }

        /* --- الحقول --- */
        .form-label { font-weight: 800; color: var(--qatra-navy); font-size: 0.95rem; margin-bottom: 8px; }
        .input-group-text { background: transparent; border: 2px solid #e2e8f0; border-left: none; color: #94a3b8; border-radius: 0 14px 14px 0; font-size: 1.1rem; transition: 0.3s; }
        .form-control, .form-select { 
            border-radius: 14px 0 0 14px; border: 2px solid #e2e8f0; border-right: none; 
            padding: 14px 20px; font-weight: 700; color: #1e293b; background: transparent; transition: all 0.3s; 
        }
        .form-select { border-radius: 14px; border-right: 2px solid #e2e8f0; }

        .form-control:focus, .form-select:focus { border-color: var(--qatra-light); outline: none; box-shadow: none; background: white; }
        .form-control:focus + .input-group-text, .input-group:focus-within .input-group-text { border-color: var(--qatra-light); color: var(--qatra-light); background: white; }
        .input-group:focus-within { box-shadow: 0 0 0 4px rgba(68, 146, 212, 0.15); border-radius: 14px; background: white; }

        optgroup { font-weight: 900; color: var(--qatra-blue); background: #f1f5f9; }

        .btn-brand { 
            background: linear-gradient(135deg, var(--qatra-blue), var(--qatra-light)); 
            color: white; border: none; border-radius: 14px; padding: 16px; 
            font-weight: 900; font-size: 1.1rem; width: 100%; margin-top: 20px; 
            transition: all 0.4s; box-shadow: 0 10px 25px rgba(11, 69, 127, 0.3); 
        }
        .btn-brand:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(11, 69, 127, 0.5); color: white; }

        .footer-links { text-align: center; margin-top: 30px; font-weight: 700; color: #64748b; }
        .footer-links a { color: var(--qatra-light); text-decoration: none; transition: 0.3s; }
        .footer-links a:hover { color: var(--qatra-blue); }

        .back-link {
            position: absolute; top: 30px; left: 40px; z-index: 10;
            color: white; text-decoration: none; font-weight: 700; font-size: 0.95rem;
            background: rgba(255,255,255,0.1); padding: 8px 20px; border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.2); transition: 0.3s; backdrop-filter: blur(10px);
        }
        .back-link:hover { background: rgba(255,255,255,0.2); transform: translateX(-5px); color: white; }

        @media (max-width: 768px) {
            .register-card { padding: 30px 20px; }
            .back-link { position: static; display: inline-flex; margin-bottom: 20px; }
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

<!-- زر العودة -->
<a href="index.php" class="back-link"><i class="fa-solid fa-house ms-1"></i> الصفحة الرئيسية</a>

<!-- بطاقة التسجيل -->
<div class="register-card">

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

    <div class="card-header-title">
        <span class="badge-system"><i class="fa-solid fa-circle-check"></i> منصة تشغيلية ذكية</span>
        <h2 class="fw-black m-0 mt-2" style="color: var(--qatra-blue);">تسجيل مستفيد جديد</h2>
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

</body>
</html>