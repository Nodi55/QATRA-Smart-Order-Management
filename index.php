<?php
/**
 * =====================================================================
 * QATRA (قطرة) - Smart Order Management System
 * National Water Company (NWC) - Landing Page
 * =====================================================================
 */
require_once 'db_connect.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>قطرة QATRA | نظام إدارة الطلبات الذكي - شركة المياه الوطنية</title>

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<!-- FontAwesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<!-- Google Font: Cairo -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

<script>
  tailwind.config = {
    theme: {
      extend: {
        fontFamily: { cairo: ['Cairo', 'sans-serif'] },
        colors: {
          navy:   '#003366',
          water:  '#0077b6',
          cyan:   '#caf0f8',
          offwhite: '#f8f9fa',
        }
      }
    }
  }
</script>

<style>
  * { font-family: 'Cairo', sans-serif; }
  body { background-color: #f8f9fa; overflow-x: hidden; }

  /* ---------- Hero gradient + animated water waves ---------- */
  .hero-gradient {
    background: linear-gradient(135deg, #003366 0%, #0077b6 55%, #0096c7 100%);
    position: relative;
    overflow: hidden;
  }
  .hero-gradient::after {
    content: '';
    position: absolute;
    inset: 0;
    background-image: radial-gradient(circle at 20% 20%, rgba(202,240,248,0.12) 0%, transparent 40%),
                       radial-gradient(circle at 80% 70%, rgba(202,240,248,0.10) 0%, transparent 45%);
    pointer-events: none;
  }
  .wave-divider svg { display: block; width: 100%; height: 80px; }

  /* ---------- Glassmorphism portal cards ---------- */
  .glass-card {
    background: rgba(255, 255, 255, 0.85);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border: 1px solid rgba(255, 255, 255, 0.6);
    box-shadow: 0 10px 40px rgba(0, 51, 102, 0.10);
    transition: transform 0.35s ease, box-shadow 0.35s ease;
  }
  .glass-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 50px rgba(0, 51, 102, 0.18);
  }

  .icon-orb {
    background: linear-gradient(135deg, #0077b6, #00b4d8);
    box-shadow: 0 8px 20px rgba(0, 119, 182, 0.35);
  }

  .btn-primary-water {
    background: linear-gradient(135deg, #0077b6, #0096c7);
    transition: all 0.3s ease;
  }
  .btn-primary-water:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0, 119, 182, 0.4);
  }

  .btn-outline-water {
    border: 2px solid #0077b6;
    color: #0077b6;
    transition: all 0.3s ease;
  }
  .btn-outline-water:hover {
    background: #0077b6;
    color: #fff;
    transform: translateY(-3px);
  }

  .btn-dark-corp {
    background: linear-gradient(135deg, #003366, #001d3d);
    transition: all 0.3s ease;
  }
  .btn-dark-corp:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0, 51, 102, 0.45);
  }

  .navbar-shadow { box-shadow: 0 2px 20px rgba(0,0,0,0.05); }

  .fade-up {
    animation: fadeUp 0.8s ease both;
  }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .delay-1 { animation-delay: .15s; }
  .delay-2 { animation-delay: .3s; }
  .delay-3 { animation-delay: .45s; }
</style>
</head>
<body class="text-navy">

<!-- ================= NAVBAR ================= -->
<nav class="fixed top-0 inset-x-0 z-50 bg-white/90 backdrop-blur-md navbar-shadow">
    <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl icon-orb flex items-center justify-center text-white text-xl">
                <i class="fa-solid fa-droplet"></i>
            </div>
            <div class="leading-tight">
                <div class="text-xl font-extrabold text-navy">QATRA <span class="text-water">| قطرة</span></div>
                <div class="text-[11px] text-gray-500 font-medium tracking-wide">National Water Company</div>
            </div>
        </div>
        <div class="hidden md:flex items-center gap-8 text-sm font-semibold text-gray-600">
            <a href="#home" class="hover:text-water transition">الرئيسية</a>
            <a href="#portals" class="hover:text-water transition">البوابات</a>
            <a href="#about" class="hover:text-water transition">عن النظام</a>
        </div>
        <a href="employee_login.php" class="hidden md:inline-block bg-navy text-white text-sm font-bold px-5 py-2.5 rounded-lg hover:bg-water transition">
            دخول الموظفين
        </a>
    </div>
</nav>

<!-- ================= HERO ================= -->
<section id="home" class="hero-gradient pt-40 pb-24 px-6 text-center text-white relative">
    <div class="max-w-4xl mx-auto relative z-10">
        <span class="fade-up inline-block bg-white/10 border border-white/25 text-cyan text-xs font-bold px-4 py-1.5 rounded-full mb-6 tracking-wide">
            منصة تشغيلية ذكية ومؤتمتة بالكامل
        </span>
        <h1 class="fade-up delay-1 text-4xl md:text-6xl font-extrabold leading-tight mb-6">
            نظام <span class="text-cyan">قطرة</span> 
        </h1>
        <p class="fade-up delay-2 text-lg md:text-xl text-white/85 leading-relaxed max-w-2xl mx-auto mb-10">
            رحلة رقمية لتقديم ومتابعة طلبات خدمات المياه والصرف الصحي،
            بدعم من الذكاء الاصطناعي وتوزيع الفنيين الآلي.
        </p>
        <div class="fade-up delay-3 flex flex-col sm:flex-row gap-4 justify-center">
            <a href="#portals" class="btn-primary-water text-white font-bold px-8 py-3.5 rounded-xl inline-flex items-center justify-center gap-2 shadow-lg">
                <i class="fa-solid fa-arrow-down"></i>
                ابدأ الآن
            </a>
            <a href="#about" class="border-2 border-white/40 text-white font-bold px-8 py-3.5 rounded-xl inline-flex items-center justify-center gap-2 hover:bg-white/10 transition">
                تعرّف على النظام
            </a>
        </div>
    </div>

    <!-- Wave divider -->
    <div class="wave-divider absolute bottom-0 inset-x-0 leading-none">
        <svg viewBox="0 0 1440 100" preserveAspectRatio="none">
            <path d="M0,40 C320,100 1120,0 1440,60 L1440,100 L0,100 Z" fill="#f8f9fa"></path>
        </svg>
    </div>
</section>

<!-- ================= PORTALS ================= -->
<section id="portals" class="max-w-6xl mx-auto px-6 py-24">
    <div class="text-center mb-14">
        <h2 class="text-3xl md:text-4xl font-extrabold text-navy mb-3">اختر بوابتك</h2>
        <p class="text-gray-500 max-w-xl mx-auto">وصول مخصص لكل مستخدم حسب دوره في المنظومة</p>
    </div>

    <div class="grid md:grid-cols-2 gap-8">

        <!-- Card A: Customer Portal -->
        <div class="glass-card rounded-2xl p-8 md:p-10">
            <div class="w-16 h-16 rounded-2xl icon-orb flex items-center justify-center text-white text-2xl mb-6">
                <i class="fa-solid fa-house-user"></i>
            </div>
            <h3 class="text-2xl font-extrabold text-navy mb-2">بوابة العملاء</h3>
            <p class="text-gray-500 leading-loose mb-8">
                لتقديم ومتابعة طلبات المياه والصرف الصحي
            </p>
            <div class="flex flex-col sm:flex-row gap-3">
                <a href="login.php"
                   class="btn-primary-water flex-1 text-white font-bold px-5 py-3 rounded-lg text-center flex items-center justify-center gap-2">
                    <i class="fa-solid fa-key"></i>
                    تسجيل الدخول
                </a>
                <a href="customer_register.php"
                   class="btn-outline-water flex-1 font-bold px-5 py-3 rounded-lg text-center flex items-center justify-center gap-2 bg-white">
                    <i class="fa-solid fa-user-plus"></i>
                    حساب جديد
                </a>
            </div>
        </div>

        <!-- Card B: Employee Portal -->
        <div class="glass-card rounded-2xl p-8 md:p-10">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-navy to-[#001d3d] flex items-center justify-center text-white text-2xl mb-6 shadow-lg">
                <i class="fa-solid fa-gears"></i>
            </div>
            <h3 class="text-2xl font-extrabold text-navy mb-2">بوابة فريق العمل</h3>
            <p class="text-gray-500 leading-loose mb-8">
                للمدققين، وإدارة المهام، والفنيين
            </p>
            <a href="employee_login.php"
               class="btn-dark-corp w-full text-white font-bold px-5 py-3 rounded-lg text-center flex items-center justify-center gap-2">
                <i class="fa-solid fa-right-to-bracket"></i>
                دخول الموظفين
            </a>
        </div>

    </div>
</section>

<!-- ================= ABOUT / STRIP ================= -->
<section id="about" class="bg-cyan/40 py-16 px-6">
    <div class="max-w-5xl mx-auto grid sm:grid-cols-3 gap-8 text-center">
        <div>
            <div class="w-14 h-14 rounded-full bg-white icon-orb text-white flex items-center justify-center mx-auto mb-4 text-xl">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h4 class="font-bold text-navy mb-1">تحقق آلي وآمن</h4>
            <p class="text-sm text-gray-500">مطابقة فورية مع سجلات وزارة العدل</p>
        </div>
        <div>
            <div class="w-14 h-14 rounded-full bg-white icon-orb text-white flex items-center justify-center mx-auto mb-4 text-xl">
                <i class="fa-solid fa-route"></i>
            </div>
            <h4 class="font-bold text-navy mb-1">تعيين ذكي للفنيين</h4>
            <p class="text-sm text-gray-500">توزيع تلقائي حسب الموقع وعبء العمل</p>
        </div>
        <div>
            <div class="w-14 h-14 rounded-full bg-white icon-orb text-white flex items-center justify-center mx-auto mb-4 text-xl">
                <i class="fa-solid fa-file-invoice-dollar"></i>
            </div>
            <h4 class="font-bold text-navy mb-1">فوترة وتسعير آلي</h4>
            <p class="text-sm text-gray-500">حساب دقيق للفواتير دون تدخل بشري</p>
        </div>
    </div>
</section>

<!-- ================= FOOTER ================= -->
<footer class="bg-navy text-white/70 text-center py-6 text-sm">
    جميع الحقوق محفوظة &copy; <?= date('Y') ?> - شركة المياه الوطنية
</footer>

</body>
</html>