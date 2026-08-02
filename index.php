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

<!-- Tailwind CSS (layout utilities only — visual identity is custom below) -->
<script src="https://cdn.tailwindcss.com"></script>
<!-- FontAwesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<!-- Google Fonts: Cormorant Garamond (QATRA wordmark) + Tajawal (Arabic display) + IBM Plex Sans Arabic (body) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Tajawal:wght@700;800;900&display=swap" rel="stylesheet">

<style>
  :root{
    /* every colour below is sampled directly from the QATRA mark itself — no outside accents */
    --navy-deep: #042B52;   /* bottom of the droplet — darkest dot   */
    --navy:      #0C457C;   /* wordmark / heading colour              */
    --blue-mid:  #195FA5;   /* mid dot                                */
    --sky:       #388ADE;   /* upper dot / accent                     */
    --sky-light: #9BC9EC;   /* topmost, smallest dots                 */
    --gray:      #6B7280;   /* "قطرة" caption grey under the wordmark */
    --paper:     #FFFFFF;
    --bg-soft:   #F0F5FA;
    --text:      #0F2233;
    --muted:     #5B6B7A;
    --hair:      rgba(4,43,82,0.10);
  }

  * { font-family: 'IBM Plex Sans Arabic', sans-serif; }
  .q-display { font-family: 'Tajawal', sans-serif; }
  .q-wordmark{ font-family:'Cormorant Garamond', serif; letter-spacing:.24em; }

  body{ background:var(--paper); color:var(--text); overflow-x:hidden; }

  /* faint dot-grid texture — echoes the logo's own material (dots) everywhere */
  .q-dotfield{
    background-image: radial-gradient(var(--hair) 1px, transparent 1px);
    background-size: 22px 22px;
  }

  .q-topbar{ background:var(--navy-deep); color:rgba(255,255,255,.72); font-size:.78rem; }
  .q-topbar a{ color:#fff; }
  .q-accent-line{ height:3px; background:linear-gradient(90deg,var(--navy-deep),var(--blue-mid),var(--sky),var(--sky-light)); }

  /* ---------------- Navbar ---------------- */
  .q-nav{ background:rgba(255,255,255,0.92); backdrop-filter:blur(8px); border-bottom:1px solid var(--hair); }
  .q-nav-link{ position:relative; color:var(--muted); }
  .q-nav-link::after{ content:''; position:absolute; right:0; bottom:-6px; width:0; height:2px; background:var(--sky); transition:width .25s ease; }
  .q-nav-link:hover{ color:var(--navy); }
  .q-nav-link:hover::after{ width:100%; }

  /* ---------------- Hero ---------------- */
  .q-hero{ position:relative; overflow:hidden; }

  .q-hero-water{
    position:relative; overflow:hidden;
    background:linear-gradient(135deg, var(--navy-deep) 0%, var(--navy) 45%, var(--blue-mid) 100%);
  }
  /* very slow, quiet diagonal light sweep — subtle ambient light on the water */
  .q-hero-water::after{
    content:''; position:absolute; inset:-20%; pointer-events:none;
    background:linear-gradient(115deg, transparent 35%, rgba(255,255,255,.06) 48%, rgba(255,255,255,.09) 50%, rgba(255,255,255,.06) 52%, transparent 65%);
    background-size:260% 260%;
    animation:q-shimmer 22s ease-in-out infinite;
  }
  @keyframes q-shimmer{
    0%{ background-position:0% 0%; }
    50%{ background-position:100% 100%; }
    100%{ background-position:0% 0%; }
  }

  /* ---- clean static divider into the white content below ----
     One smooth, full-width curve — no tiling, no seams, no risk of a
     choppy repeat. Calm by design; the movement lives in the droplets. */
  .q-divider{ position:absolute; left:0; right:0; bottom:-1px; line-height:0; z-index:2; pointer-events:none; }
  .q-divider svg{ display:block; width:100%; height:64px; }

  /* ---- rising droplets — the page's real "moving water" moment ----
     Small, soft circles drifting upward and fading, echoing the QATRA
     mark's own material. Randomised timing keeps it from feeling mechanical. */
  .q-droplets{ position:absolute; inset:0; overflow:hidden; z-index:1; pointer-events:none; }
  .q-droplet{ position:absolute; bottom:-10%; border-radius:999px; background:rgba(255,255,255,.55); animation:q-drift-up linear infinite; }
  @keyframes q-drift-up{
    0%{   transform:translateY(0) scale(.8);   opacity:0; }
    12%{  opacity:.7; }
    85%{  opacity:.35; }
    100%{ transform:translateY(-320px) scale(1.15); opacity:0; }
  }
  @media (prefers-reduced-motion: reduce){
    .q-hero-water::after, .q-droplet{ animation:none; }
  }

  .q-orb{ position:absolute; border-radius:9999px; filter:blur(60px); pointer-events:none; background:var(--sky); }
  .q-orb-1{ width:240px; height:240px; opacity:.14; top:-40px; right:8%; }
  .q-orb-2{ width:280px; height:280px; opacity:.10; bottom:0; left:4%; background:var(--blue-mid); }

  .q-mark-breathe{ animation: q-breathe 4.5s ease-in-out infinite; transform-origin:center; }
  @keyframes q-breathe{ 0%,100%{ transform:scale(1);} 50%{ transform:scale(1.035);} }
  @media (prefers-reduced-motion: reduce){ .q-mark-breathe{ animation:none; } }

  .q-mark-panel-dark{
    display:inline-block; position:relative;
    background:rgba(255,255,255,.07);
    border:1px solid rgba(255,255,255,.18);
    box-shadow:0 18px 40px rgba(4,20,40,.25);
    border-radius:26px;
    backdrop-filter:blur(6px);
  }
  .q-eyebrow-dark{
    display:inline-flex; align-items:center; gap:.5rem; border:1px solid rgba(255,255,255,.25); color:#EAF3FB;
    font-weight:600; font-size:.78rem; letter-spacing:.02em; padding:.4rem 1rem; border-radius:999px; background:rgba(255,255,255,.06);
  }
  .q-eyebrow-dark .dot{ width:6px; height:6px; border-radius:999px; background:var(--sky-light); }

  .q-btn-sky{ background:var(--sky); color:#052440; font-weight:700; border-radius:10px; transition:all .25s ease; }
  .q-btn-sky:hover{ background:var(--sky-light); transform:translateY(-3px); box-shadow:0 12px 26px rgba(56,138,222,.35); }
  .q-btn-ghost{ border:1.5px solid rgba(255,255,255,.55); color:#fff; font-weight:700; border-radius:10px; transition:all .25s ease; background:transparent; }
  .q-btn-ghost:hover{ background:rgba(255,255,255,.12); }

  .q-mark-panel{
    display:inline-block; position:relative;
    background:radial-gradient(120% 140% at 50% 0%, #EAF3FB 0%, #F7FAFD 55%, #FFFFFF 100%);
    border:1px solid var(--hair);
    box-shadow:0 18px 40px rgba(4,43,82,.08);
    border-radius:26px;
  }
  .q-mark-panel::before{
    content:''; position:absolute; inset:0; border-radius:26px; padding:1px;
    background:linear-gradient(135deg,var(--sky-light),transparent 40%,transparent 60%,var(--blue-mid));
    -webkit-mask:linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite:xor; mask-composite:exclude; opacity:.5; pointer-events:none;
  }
  .q-eyebrow{
    display:inline-flex; align-items:center; gap:.5rem; border:1px solid var(--hair); color:var(--blue-mid);
    font-weight:600; font-size:.78rem; letter-spacing:.02em; padding:.4rem 1rem; border-radius:999px; background:#fff;
  }
  .q-eyebrow .dot{ width:6px; height:6px; border-radius:999px; background:var(--sky); }

  .q-btn-navy{ background:linear-gradient(135deg,var(--navy-deep),var(--blue-mid)); color:#fff; font-weight:700; border-radius:10px; transition:all .25s ease; }
  .q-btn-navy:hover{ transform:translateY(-3px); box-shadow:0 12px 26px rgba(12,69,124,.30); }
  .q-btn-outline{ border:1.5px solid var(--navy-deep); color:var(--navy-deep); font-weight:700; border-radius:10px; transition:all .25s ease; background:#fff; }
  .q-btn-outline:hover{ background:var(--navy-deep); color:#fff; }

  /* ---------------- Portals ---------------- */
  .q-panel-light{ background:#fff; border:1px solid var(--hair); box-shadow:0 16px 36px rgba(4,43,82,.07); transition:transform .3s ease, box-shadow .3s ease; position:relative; z-index:1; }
  .q-panel-light:hover{ transform:translateY(-3px); box-shadow:0 22px 46px rgba(4,43,82,.12); }

  .q-panel-dark{ background:linear-gradient(160deg,var(--navy) 0%, var(--navy-deep) 100%); color:#fff; box-shadow:0 16px 36px rgba(4,43,82,.28); transition:transform .3s ease, box-shadow .3s ease; position:relative; z-index:1; }
  .q-panel-dark:hover{ transform:translateY(-3px); box-shadow:0 22px 50px rgba(4,43,82,.4); }

  /* badge = a tiny cluster of the brand's own dots, not a generic icon glyph */
  .q-dotbadge{ width:56px; height:56px; }

  .q-cta-primary{ background:var(--navy-deep); color:#fff; font-weight:700; border-radius:10px; transition:all .25s ease; }
  .q-cta-primary:hover{ background:#03203d; transform:translateY(-2px); }
  .q-cta-outline{ border:1.5px solid var(--navy-deep); color:var(--navy-deep); font-weight:700; background:transparent; border-radius:10px; transition:all .25s ease; }
  .q-cta-outline:hover{ background:var(--navy-deep); color:#fff; }
  .q-cta-on-dark{ background:var(--sky); color:#052440; font-weight:700; border-radius:10px; transition:all .25s ease; }
  .q-cta-on-dark:hover{ background:var(--sky-light); transform:translateY(-2px); }

  .fade-up{ animation: fadeUp .7s ease both; }
  @keyframes fadeUp{ from{opacity:0; transform:translateY(10px);} to{opacity:1; transform:translateY(0);} }
  .delay-1{ animation-delay:.12s; } .delay-2{ animation-delay:.24s; } .delay-3{ animation-delay:.36s; }

  /* ---------------- Footer ---------------- */
  .q-footer{ background:var(--navy-deep); }
  .q-footer a{ color:rgba(255,255,255,0.68); transition:color .2s ease; }
  .q-footer a:hover{ color:#fff; }
  .q-footer h5{ color:#fff; }
</style>
</head>
<body>

<div class="q-accent-line fixed top-0 inset-x-0 z-50"></div>

<!-- ================= NAVBAR ================= -->
<nav class="q-nav fixed top-[3px] inset-x-0 z-40">
    <div class="max-w-7xl mx-auto px-6 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <!-- mini dotted droplet, same construction as the full mark -->
            <svg width="30" height="32" viewBox="0 0 40 44">
                <circle cx="20" cy="6"  r="2.2" fill="var(--sky-light)"/>
                <circle cx="14" cy="14" r="2.6" fill="var(--sky-light)"/><circle cx="26" cy="14" r="2.6" fill="var(--sky-light)"/>
                <circle cx="10" cy="23" r="3"   fill="var(--sky)"/><circle cx="20" cy="23" r="3" fill="var(--sky)"/><circle cx="30" cy="23" r="3" fill="var(--sky)"/>
                <circle cx="7" cy="33"  r="3.4" fill="var(--blue-mid)"/><circle cx="16" cy="33" r="3.4" fill="var(--blue-mid)"/><circle cx="24" cy="33" r="3.4" fill="var(--blue-mid)"/><circle cx="33" cy="33" r="3.4" fill="var(--blue-mid)"/>
                <circle cx="12" cy="42" r="3.4" fill="var(--navy-deep)"/><circle cx="20" cy="42" r="3.4" fill="var(--navy-deep)"/><circle cx="28" cy="42" r="3.4" fill="var(--navy-deep)"/>
            </svg>
            <div class="leading-none">
                <div class="q-wordmark text-xl font-semibold" style="color:var(--navy)">QATRA</div>
                <div class="text-[11px] font-medium" style="color:var(--gray)">قطرة</div>
            </div>
        </div>
        <div class="hidden md:flex items-center gap-8 text-sm">
            <a href="#home" class="q-nav-link pb-1">الرئيسية</a>
            <a href="#portals" class="q-nav-link pb-1">البوابات</a>
            <a href="#about" class="q-nav-link pb-1">عن النظام</a>
        </div>
        <a href="employee_login.php" class="hidden md:inline-flex items-center gap-2 text-sm font-bold px-5 py-2.5 rounded-full" style="border:1px solid var(--hair); color:var(--navy)">
            <i class="fa-solid fa-right-to-bracket"></i> دخول الموظفين
        </a>
    </div>
</nav>

<!-- ================= HERO ================= -->
<section id="home" class="q-hero q-hero-water pt-28 pb-24 px-6">
    <!-- rising droplets — the animated moment, on-brand with the QATRA mark -->
    <div class="q-droplets">
        <span class="q-droplet" style="left:6%;  width:6px;  height:6px;  animation-duration:9s;  animation-delay:0s;"></span>
        <span class="q-droplet" style="left:14%; width:4px;  height:4px;  animation-duration:12s; animation-delay:2.5s;"></span>
        <span class="q-droplet" style="left:23%; width:8px;  height:8px;  animation-duration:10.5s; animation-delay:1s;"></span>
        <span class="q-droplet" style="left:34%; width:5px;  height:5px;  animation-duration:13s; animation-delay:4s;"></span>
        <span class="q-droplet" style="left:47%; width:7px;  height:7px;  animation-duration:11s; animation-delay:.5s;"></span>
        <span class="q-droplet" style="left:58%; width:4px;  height:4px;  animation-duration:9.5s; animation-delay:3s;"></span>
        <span class="q-droplet" style="left:68%; width:9px;  height:9px;  animation-duration:14s; animation-delay:1.8s;"></span>
        <span class="q-droplet" style="left:77%; width:5px;  height:5px;  animation-duration:10s; animation-delay:5s;"></span>
        <span class="q-droplet" style="left:86%; width:6px;  height:6px;  animation-duration:12.5s; animation-delay:2s;"></span>
        <span class="q-droplet" style="left:93%; width:4px;  height:4px;  animation-duration:11.5s; animation-delay:3.7s;"></span>
    </div>

    <div class="max-w-3xl mx-auto relative z-10 flex flex-col items-center text-center">

        <!-- the mark itself, in its own dedicated panel, as the page's opening thesis -->
        <div class="q-mark-panel-dark fade-up px-10 py-6 mb-8">
            <svg class="q-mark-breathe block mx-auto" width="88" height="98" viewBox="0 0 300 320">
                <circle cx="150" cy="26"  r="4"  fill="#EAF3FB"/>
                <circle cx="133" cy="62"  r="5"  fill="#EAF3FB"/><circle cx="167" cy="62"  r="5"  fill="#EAF3FB"/>
                <circle cx="116" cy="98"  r="6"  fill="var(--sky-light)"/><circle cx="150" cy="98"  r="6"  fill="var(--sky-light)"/><circle cx="184" cy="98"  r="6"  fill="var(--sky-light)"/>
                <circle cx="99"  cy="134" r="7"  fill="var(--sky-light)"/><circle cx="133" cy="134" r="7"  fill="var(--sky-light)"/><circle cx="167" cy="134" r="7"  fill="var(--sky-light)"/><circle cx="201" cy="134" r="7"  fill="var(--sky-light)"/>
                <circle cx="82"  cy="170" r="8"  fill="var(--sky)"/><circle cx="116" cy="170" r="8"  fill="var(--sky)"/><circle cx="150" cy="170" r="8"  fill="var(--sky)"/><circle cx="184" cy="170" r="8"  fill="var(--sky)"/><circle cx="218" cy="170" r="8"  fill="var(--sky)"/>
                <circle cx="82"  cy="206" r="9"  fill="rgba(255,255,255,.55)"/><circle cx="116" cy="206" r="9"  fill="rgba(255,255,255,.55)"/><circle cx="150" cy="206" r="9"  fill="rgba(255,255,255,.55)"/><circle cx="184" cy="206" r="9"  fill="rgba(255,255,255,.55)"/><circle cx="218" cy="206" r="9"  fill="rgba(255,255,255,.55)"/>
                <circle cx="99"  cy="242" r="10" fill="rgba(255,255,255,.32)"/><circle cx="133" cy="242" r="10" fill="rgba(255,255,255,.32)"/><circle cx="167" cy="242" r="10" fill="rgba(255,255,255,.32)"/><circle cx="201" cy="242" r="10" fill="rgba(255,255,255,.32)"/>
            </svg>
        </div>

        <span class="fade-up delay-1 q-eyebrow-dark mb-6">
            <span class="dot"></span> منصة تشغيلية ذكية ومؤتمتة بالكامل
        </span>
        <h1 class="fade-up delay-1 q-display text-4xl md:text-5xl font-extrabold text-white leading-[1.25] mb-6">
            نظام <span style="color:var(--sky-light)">قطرة</span>
        </h1>
        <p class="fade-up delay-2 text-lg md:text-xl leading-relaxed max-w-2xl mx-auto mb-10 text-white/80">
            رحلة رقمية واحدة تربط تقديم طلب الخدمة، والتحقق الآلي من الملكية، والتسعير، وصولاً لتركيب العداد الذكي — دون ورق ودون انتظار.
        </p>
        <div class="fade-up delay-3 flex flex-col sm:flex-row gap-4 justify-center">
            <a href="#portals" class="q-btn-sky px-8 py-3.5 inline-flex items-center justify-center gap-2">
                ابدأ الآن <i class="fa-solid fa-arrow-down"></i>
            </a>
            <a href="#about" class="q-btn-ghost px-8 py-3.5 inline-flex items-center justify-center gap-2">
                تعرّف على النظام
            </a>
        </div>
    </div>

    <!-- clean, static divider into the white content below -->
    <div class="q-divider">
        <svg viewBox="0 0 1440 100" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M0,58 C360,110 1080,-6 1440,58 L1440,100 L0,100 Z" fill="#FFFFFF"/>
        </svg>
    </div>
</section>

<!-- ================= PORTALS ================= -->
<section id="portals" class="max-w-4xl mx-auto px-6 py-24">
    <div class="text-center mb-14">
        <h2 class="q-display text-3xl md:text-4xl font-extrabold mb-3" style="color:var(--navy)">بوابة العملاء</h2>
        <p style="color:var(--muted)" class="max-w-xl mx-auto">قدّم طلبك وتابع حالته أولاً بأول من مكان واحد</p>
    </div>

    <div class="relative">
        <div class="q-orb" style="width:200px;height:200px;top:-40px;right:8%;opacity:.10;"></div>

        <!-- Customer Portal -->
        <div class="q-panel-light rounded-[28px] p-8 md:p-12 text-center relative z-10">
            <svg class="q-dotbadge mb-6 mx-auto" viewBox="0 0 56 56">
                <circle cx="28" cy="10" r="3" fill="var(--sky-light)"/>
                <circle cx="17" cy="21" r="4" fill="var(--sky)"/><circle cx="28" cy="21" r="4" fill="var(--sky)"/><circle cx="39" cy="21" r="4" fill="var(--sky)"/>
                <circle cx="12" cy="33" r="4.5" fill="var(--blue-mid)"/><circle cx="23" cy="33" r="4.5" fill="var(--blue-mid)"/><circle cx="34" cy="33" r="4.5" fill="var(--blue-mid)"/><circle cx="45" cy="33" r="4.5" fill="var(--blue-mid)"/>
                <circle cx="17" cy="45" r="5" fill="var(--navy-deep)"/><circle cx="28" cy="45" r="5" fill="var(--navy-deep)"/><circle cx="39" cy="45" r="5" fill="var(--navy-deep)"/>
            </svg>
            <h3 class="q-display text-2xl font-extrabold mb-2" style="color:var(--navy)">بوابة العملاء</h3>
            <p class="leading-loose mb-8 max-w-md mx-auto" style="color:var(--muted)">
                لتقديم ومتابعة طلبات المياه والصرف الصحي، ومتابعة حالة الفحص والفوترة أولاً بأول.
            </p>
            <div class="flex flex-col sm:flex-row gap-3 max-w-sm mx-auto">
                <a href="login.php" class="q-cta-primary flex-1 px-5 py-3 text-center flex items-center justify-center gap-2">
                    <i class="fa-solid fa-key"></i> تسجيل الدخول
                </a>
                <a href="customer_register.php" class="q-cta-outline flex-1 px-5 py-3 text-center flex items-center justify-center gap-2">
                    <i class="fa-solid fa-user-plus"></i> حساب جديد
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ================= ABOUT / FEATURE STRIP ================= -->
<section id="about" class="q-dotfield py-16 px-6 relative overflow-hidden" style="background:var(--bg-soft)">
    <div class="max-w-5xl mx-auto grid sm:grid-cols-3 gap-8 text-center relative z-10">
        <div>
            <svg class="mx-auto mb-4" width="40" height="40" viewBox="0 0 40 40">
                <circle cx="20" cy="8" r="3" fill="var(--sky-light)"/><circle cx="12" cy="20" r="3.5" fill="var(--sky)"/><circle cx="28" cy="20" r="3.5" fill="var(--sky)"/><circle cx="20" cy="32" r="4" fill="var(--navy)"/>
            </svg>
            <h4 class="font-bold mb-1" style="color:var(--navy)">تحقق آلي وآمن</h4>
            <p class="text-sm" style="color:var(--muted)">مطابقة فورية مع سجلات وزارة العدل</p>
        </div>
        <div>
            <svg class="mx-auto mb-4" width="40" height="40" viewBox="0 0 40 40">
                <circle cx="20" cy="8" r="3" fill="var(--sky-light)"/><circle cx="12" cy="20" r="3.5" fill="var(--blue-mid)"/><circle cx="28" cy="20" r="3.5" fill="var(--blue-mid)"/><circle cx="20" cy="32" r="4" fill="var(--navy-deep)"/>
            </svg>
            <h4 class="font-bold mb-1" style="color:var(--navy)">تعيين ذكي للفنيين</h4>
            <p class="text-sm" style="color:var(--muted)">توزيع تلقائي حسب الموقع وعبء العمل</p>
        </div>
        <div>
            <svg class="mx-auto mb-4" width="40" height="40" viewBox="0 0 40 40">
                <circle cx="20" cy="8" r="3" fill="var(--sky-light)"/><circle cx="12" cy="20" r="3.5" fill="var(--sky)"/><circle cx="28" cy="20" r="3.5" fill="var(--sky)"/><circle cx="20" cy="32" r="4" fill="var(--navy)"/>
            </svg>
            <h4 class="font-bold mb-1" style="color:var(--navy)">فوترة وتسعير آلي</h4>
            <p class="text-sm" style="color:var(--muted)">حساب دقيق للفواتير دون تدخل بشري</p>
        </div>
    </div>
</section>

<!-- ================= FOOTER ================= -->
<footer class="q-footer text-white/70">
    <div class="max-w-6xl mx-auto px-6 py-14 grid sm:grid-cols-3 gap-10 text-sm">
        <div>
            <div class="flex items-center gap-2 mb-3">
                <svg width="22" height="24" viewBox="0 0 40 44">
                    <circle cx="20" cy="6"  r="2.2" fill="var(--sky-light)"/>
                    <circle cx="14" cy="14" r="2.6" fill="var(--sky-light)"/><circle cx="26" cy="14" r="2.6" fill="var(--sky-light)"/>
                    <circle cx="10" cy="23" r="3"   fill="var(--sky)"/><circle cx="20" cy="23" r="3" fill="var(--sky)"/><circle cx="30" cy="23" r="3" fill="var(--sky)"/>
                    <circle cx="7" cy="33"  r="3.4" fill="var(--blue-mid)"/><circle cx="16" cy="33" r="3.4" fill="var(--blue-mid)"/><circle cx="24" cy="33" r="3.4" fill="var(--blue-mid)"/><circle cx="33" cy="33" r="3.4" fill="var(--blue-mid)"/>
                    <circle cx="12" cy="42" r="3.4" fill="#0B2A4A"/><circle cx="20" cy="42" r="3.4" fill="#0B2A4A"/><circle cx="28" cy="42" r="3.4" fill="#0B2A4A"/>
                </svg>
                <span class="q-wordmark text-lg" style="color:#fff">QATRA</span>
            </div>
            <p class="leading-loose">منصة تشغيلية موحّدة لإدارة طلبات خدمات المياه والصرف الصحي، تابعة لشركة المياه الوطنية.</p>
        </div>
        <div>
            <h5 class="font-bold mb-3">روابط سريعة</h5>
            <ul class="space-y-2">
                <li><a href="#home">الرئيسية</a></li>
                <li><a href="#portals">البوابات</a></li>
                <li><a href="#about">عن النظام</a></li>
                <li><a href="employee_login.php">دخول الموظفين</a></li>
            </ul>
        </div>
        <div>
            <h5 class="font-bold mb-3">عن المنصة</h5>
            <ul class="space-y-2">
                <li>خدمات المياه والصرف الصحي</li>
                <li>تحقق آلي من الملكية</li>
                <li>تعيين ذكي للفنيين</li>
            </ul>
        </div>
    </div>
    <div class="border-t border-white/10 text-center py-4 text-xs text-white/50">
        جميع الحقوق محفوظة &copy; <?= date('Y') ?> - شركة المياه الوطنية
    </div>
</footer>

</body>
</html>