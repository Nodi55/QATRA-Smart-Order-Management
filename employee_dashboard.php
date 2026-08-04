<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// التحقق من تسجيل الدخول
if (!isset($_SESSION['emp_id'])) {
    header("Location: employee_login.php");
    exit;
}

if (empty($_SESSION['emp_roles'])) {
    die("<div style='text-align:center; padding:50px; font-family:tahoma;'><h2>حسابك لا يمتلك صلاحيات.</h2><a href='logout.php'>تسجيل الخروج</a></div>");
}

$empName = $_SESSION['emp_name'];

// =========================================================
// قراءة صارمة للصلاحيات من قاعدة البيانات (بدون أي توسعة برمجية)
// =========================================================
$roles = array_unique($_SESSION['emp_roles']); 

// =========================================================
// التوجيه عند الضغط على إحدى الصلاحيات
// =========================================================
if (isset($_GET['active_role']) && in_array($_GET['active_role'], $roles)) {
    $_SESSION['current_active_role'] = $_GET['active_role'];
    
    $role = $_GET['active_role'];
    if ($role == 'Admin') header("Location: admin_panel.php");
    elseif ($role == 'Auditor') header("Location: auditor_panel.php");
    elseif ($role == 'Inspection Technician') header("Location: inspection_panel.php");
    elseif ($role == 'Installation Technician') header("Location: installation_panel.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة النظام | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root { --nwc-navy: #092e54; --nwc-blue: #4492d4; --nwc-light: #eaf3fb; --bg-color: #092e54; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-color); color: #334155; overflow-x: hidden; position: relative; margin: 0; min-height: 100vh; }

        .bg-animation { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; background: radial-gradient(circle at top right, #10599c 0%, var(--nwc-navy) 70%); pointer-events: none; }
        .water-drop { position: absolute; bottom: -100px; background: linear-gradient(180deg, rgba(125, 211, 252, 0.1) 0%, rgba(125, 211, 252, 0.4) 100%); border-radius: 50%; animation: floatUp infinite ease-in; backdrop-filter: blur(5px); }
        @keyframes floatUp { 0% { transform: translateY(0) scale(0.8); opacity: 0; } 50% { opacity: 1; } 100% { transform: translateY(-120vh) scale(1.2); opacity: 0; } }

        .container { position: relative; z-index: 10; }
        .fade-in-up { animation: fadeInUp 0.7s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; opacity: 0; transform: translateY(20px); }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }
        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }

        .hero-plain { padding: 10px 5px 25px; } .hero-name { color: #7dd3fc; font-weight: 900; }
        .hero-flex-row { display: flex; align-items: stretch; flex-wrap: wrap; gap: 18px; }
        .hero-text-box { flex: 1 1 400px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 20px; padding: 24px 28px; backdrop-filter: blur(8px); }
        .hero-count-pill { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; flex: 0 0 auto; min-width: 160px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 20px; padding: 20px 28px; backdrop-filter: blur(8px); }
        .hero-count-pill i { color: #7dd3fc; font-size: 1.6rem; } .hero-count-pill .hero-count-number { color: #ffffff; font-weight: 900; font-size: 2.3rem; line-height: 1; } .hero-count-pill .hero-count-label { color: #cbd5e1; font-weight: 700; font-size: 0.9rem; }
        @media (max-width: 576px) { .hero-text-box h1 { font-size: 1.5rem !important; } .hero-count-pill { flex-direction: row; width: 100%; min-width: 0; } }

        .navbar-luxury { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); padding: 15px 0; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25); border-bottom: 1px solid rgba(255, 255, 255, 0.2); position: sticky; top: 0; z-index: 1050; }
        .navbar-luxury::after { content: ''; position: absolute; bottom: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, var(--nwc-blue), transparent); }
        .brand-icon { background: linear-gradient(135deg, var(--nwc-navy), #0a1128); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 16px; box-shadow: 0 8px 20px rgba(9, 46, 84, 0.3); transition: transform 0.3s; }
        .brand-icon:hover { transform: scale(1.05) rotate(-5deg); } .brand-icon svg { width: 26px; height: 30px; }
        .user-profile-badge { background: white; padding: 6px 20px 6px 6px; border-radius: 50px; font-weight: 700; color: var(--nwc-navy); border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.02); }
        .user-avatar { width: 38px; height: 38px; background: var(--nwc-light); color: var(--nwc-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .btn-logout { transition: all 0.3s; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; } .btn-logout:hover { background-color: #ef4444 !important; color: white !important; transform: rotate(90deg); }

        /* ===== بطاقات اختيار مساحة العمل ===== */
        .roles-section { padding-bottom: 60px; }
        .roles-grid { display: flex; flex-wrap: wrap; justify-content: center; gap: 22px; }
        .role-card { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.2); border-radius: 22px; padding: 42px 26px; width: 260px; text-align: center; text-decoration: none; transition: all 0.4s cubic-bezier(0.2, 0.8, 0.2, 1); box-shadow: 0 20px 45px rgba(0,0,0,0.18); position: relative; overflow: hidden; }
        .role-card::before { content: ''; position: absolute; top: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, var(--nwc-blue), transparent); }
        .role-card:hover { transform: translateY(-8px); box-shadow: 0 30px 60px rgba(0,0,0,0.28); }
        .role-icon { width: 72px; height: 72px; background: var(--nwc-light); color: var(--nwc-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 20px; transition: 0.4s; }
        .role-card:hover .role-icon { background: linear-gradient(135deg, var(--nwc-navy), var(--nwc-blue)); color: white; transform: scale(1.08) rotate(-4deg); }
        .role-title { font-weight: 900; color: var(--nwc-navy); font-size: 1.2rem; margin-bottom: 10px; }
        .role-desc { color: #64748b; font-size: 0.85rem; font-weight: 700; line-height: 1.6; }
    </style>
</head>
<body>

<div class="bg-animation" id="bg-particles"></div>
<script>
    const bgContainer = document.getElementById('bg-particles');
    for (let i = 0; i < 20; i++) {
        let drop = document.createElement('div');
        drop.classList.add('water-drop');
        drop.style.left = Math.random() * 100 + 'vw';
        drop.style.width = Math.random() * 40 + 20 + 'px';
        drop.style.height = drop.style.width;
        drop.style.animationDuration = Math.random() * 5 + 5 + 's';
        drop.style.animationDelay = Math.random() * 5 + 's';
        bgContainer.appendChild(drop);
    }
</script>

<nav class="navbar navbar-luxury fade-in-up">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="#" class="d-flex align-items-center gap-3 text-decoration-none">
            <div class="brand-icon">
                <svg viewBox="0 0 60 68" xmlns="http://www.w3.org/2000/svg">
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
                    <circle cx="33.7" cy="41" r="3.6" fill="#bae6fd"/>
                    <circle cx="41.1" cy="41" r="3.6" fill="#bae6fd"/>
                    <circle cx="22.6" cy="48" r="3.8" fill="#7dd3fc"/>
                    <circle cx="30"   cy="48" r="3.8" fill="#e0f2fe"/>
                    <circle cx="37.4" cy="48" r="3.8" fill="#7dd3fc"/>
                    <circle cx="26.3" cy="55" r="3.9" fill="#e0f2fe"/>
                    <circle cx="33.7" cy="55" r="3.9" fill="#e0f2fe"/>
                </svg>
            </div>
            <div>
                <div class="fw-black fs-4 text-end" style="color: var(--nwc-navy); line-height: 1.1;">قطــرة</div>
                <div class="text-muted text-end" style="font-size: 0.85rem; font-weight: 800;">بوابة الخدمات الموحدة</div>
            </div>
        </a>

        <div class="d-flex align-items-center gap-3">
            <div class="user-profile-badge">
                <span class="ms-2"><?= htmlspecialchars($empName); ?></span>
                <div class="user-avatar"><i class="fa-solid fa-user-tie"></i></div>
            </div>
            <a href="?logout=1" class="btn btn-light text-danger rounded-circle shadow-sm border btn-logout" title="تسجيل الخروج">
                <i class="fa-solid fa-power-off"></i>
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5 mt-4">
    <div class="row mb-5 fade-in-up delay-1">
        <div class="col-12">
            <div class="hero-plain">
                <div class="hero-flex-row">
                    <div class="hero-text-box">
                        <h1 class="fw-black m-0" style="color: white; font-size: 2.2rem;">
                            أهلاً بك، <span class="hero-name">الموظف/ة <?= htmlspecialchars($empName); ?></span>
                        </h1>
                        <p class="mb-0 fs-5 mt-3" style="color: #cbd5e1; line-height: 1.8; font-weight: 500;">
                            الرجاء اختيار مساحة العمل المراد الدخول إليها. <span style="color: #93c5fd;">قطرة</span> توفر لك كل أدواتك التشغيلية في مكان واحد بسهولة وشفافية.
                        </p>
                    </div>
                    <div class="hero-count-pill">
                        <i class="fa-solid fa-layer-group"></i>
                        <span class="hero-count-number"><?= count($roles); ?></span>
                        <span class="hero-count-label">مساحات العمل المتاحة</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="roles-section fade-in-up delay-2">
        <div class="roles-grid">
            <?php if(in_array('Admin', $roles)): ?>
            <a href="?active_role=Admin" class="role-card">
                <div class="role-icon"><i class="fa-solid fa-user-shield"></i></div>
                <div class="role-title">مدير النظام</div>
                <div class="role-desc">إدارة الموظفين، المهام الشاملة، وإحصائيات كادر العمل.</div>
            </a>
            <?php endif; ?>

            <?php if(in_array('Auditor', $roles)): ?>
            <a href="?active_role=Auditor" class="role-card">
                <div class="role-icon"><i class="fa-solid fa-file-signature"></i></div>
                <div class="role-title">مدقق الطلبات</div>
                <div class="role-desc">مراجعة صكوك الملكية والطلبات المعلقة للعملاء.</div>
            </a>
            <?php endif; ?>

            <?php if(in_array('Inspection Technician', $roles)): ?>
            <a href="?active_role=<?= urlencode('Inspection Technician') ?>" class="role-card">
                <div class="role-icon"><i class="fa-solid fa-clipboard-check"></i></div>
                <div class="role-title">فني فحص</div>
                <div class="role-desc">إجراء الفحص الميداني للموقع ورفع الصور للطلبات.</div>
            </a>
            <?php endif; ?>

            <?php if(in_array('Installation Technician', $roles)): ?>
            <a href="?active_role=<?= urlencode('Installation Technician') ?>" class="role-card">
                <div class="role-icon"><i class="fa-solid fa-wrench"></i></div>
                <div class="role-title">فني تركيب</div>
                <div class="role-desc">استلام مهام التركيب للعدادات وتسجيل القراءات.</div>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>