<?php
// استدعاء ملف الاتصال بقاعدة البيانات لنظام قطرة
require_once 'db_connect.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قطرة | نظام إدارة الطلبات الذكي - شركة المياه الوطنية</title>
    
    <!-- مكتبة Bootstrap 5 (RTL) لدعم اللغة العربية وتناسق الشاشات -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    
    <!-- مكتبة FontAwesome للأيقونات -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- خطوط جوجل (Cairo) الاحترافية -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background-color: #f4f7f6;
            color: #333;
        }
        
        /* شريط التنقل */
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

        /* القسم الترحيبي (Hero) */
        .hero-section {
            background: linear-gradient(135deg, #003366 0%, #0077b6 100%);
            color: white;
            padding: 100px 0 120px;
            border-bottom-left-radius: 50px;
            border-bottom-right-radius: 50px;
            text-align: center;
            position: relative;
        }
        .hero-section h1 { font-weight: 800; font-size: 2.8rem; margin-bottom: 20px; }
        .hero-section p { font-size: 1.2rem; font-weight: 400; opacity: 0.9; }

        /* البطاقات التفاعلية (Portals) */
        .portals-container {
            margin-top: -60px;
            padding-bottom: 50px;
        }
        .portal-card {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
            padding: 40px 30px;
            text-align: center;
            position: relative;
        }
        .portal-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }
        
        /* الأيقونات فوق البطاقات */
        .icon-wrapper {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 35px;
            margin: 0 auto 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .icon-customer { background-color: #e0f2fe; color: #0ea5e9; }
        .icon-employee { background-color: #f1f5f9; color: #003366; }

        .portal-card h3 { font-weight: 800; color: #003366; margin-bottom: 15px; }
        .portal-card p { color: #666; margin-bottom: 30px; line-height: 1.6; }

        /* الأزرار الاحترافية */
        .btn-custom {
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            width: 100%;
            margin-bottom: 10px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary-qatra { background-color: #0077b6; color: white; border: none; }
        .btn-primary-qatra:hover { background-color: #005f8f; color: white; }
        
        .btn-outline-qatra { border: 2px solid #0077b6; color: #0077b6; background: transparent; }
        .btn-outline-qatra:hover { background-color: #0077b6; color: white; }
        
        .btn-dark-qatra { background-color: #003366; color: white; border: none; }
        .btn-dark-qatra:hover { background-color: #001f40; color: white; }

        /* الفوتر */
        footer { text-align: center; padding: 20px 0; color: #888; font-size: 0.9rem; }
    </style>
</head>
<body>

    <!-- شريط التنقل (Navbar) -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="fa-solid fa-droplet me-2" style="color: #0077b6; margin-left: 10px;"></i>
                قطرة <span class="mx-2 text-muted">|</span> <span class="brand-subtitle">شركة المياه الوطنية</span>
            </a>
        </div>
    </nav>

    <!-- القسم الترحيبي -->
    <section class="hero-section">
        <div class="container">
            <h1>نظام إدارة الطلبات الذكي (قطرة)</h1>
            <p>منصة رقمية متكاملة لتقديم ومتابعة خدمات المياه والصرف الصحي بكل سهولة وموثوقية.</p>
        </div>
    </section>

    <!-- قسم بوابات الدخول -->
    <section class="portals-container container">
        <div class="row justify-content-center g-4">
            
            <!-- بوابة العملاء -->
            <div class="col-md-5">
                <div class="portal-card">
                    <div class="icon-wrapper icon-customer">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <h3>بوابة العملاء</h3>
                    <p>الواجهة المخصصة للمستفيدين لتقديم طلبات التأسيس الجديدة، رفع الصكوك، ومتابعة حالة الطلبات والفواتير.</p>
                    <a href="customer_login.php" class="btn-custom btn-primary-qatra">
                        <i class="fa-solid fa-mobile-screen-button me-2"></i> تسجيل الدخول (OTP)
                    </a>
                    <a href="customer_register.php" class="btn-custom btn-outline-qatra">
                        <i class="fa-solid fa-user-plus me-2"></i> تسجيل حساب جديد
                    </a>
                </div>
            </div>

            <!-- بوابة الموظفين -->
            <div class="col-md-5">
                <div class="portal-card">
                    <div class="icon-wrapper icon-employee">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <h3>بوابة فريق العمل</h3>
                    <p>النظام التشغيلي المخصص لمدققي البيانات، الإدارة الميدانية، وفنيي التركيبات لإدارة المهام الذكية.</p>
                    <a href="employee_login.php" class="btn-custom btn-dark-qatra" style="margin-top: 56px;">
                        <i class="fa-solid fa-envelope me-2"></i> دخول الموظفين (Email)
                    </a>
                </div>
            </div>

        </div>
    </section>

    <!-- الفوتر -->
    <footer>
        <div class="container">
            <p>&copy; 2026 جميع الحقوق محفوظة - نظام قطرة | شركة المياه الوطنية (NWC)</p>
        </div>
    </footer>

    <!-- مكتبة Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>