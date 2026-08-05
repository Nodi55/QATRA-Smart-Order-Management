<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// التحقق من صلاحية فني التركيبات
if (!isset($_SESSION['emp_id']) || !in_array('Installation Technician', $_SESSION['emp_roles'])) {
    header("Location: employee_dashboard.php");
    exit;
}

require_once 'db_connect.php';
$empId = $_SESSION['emp_id'];

if (!isset($_GET['task_id']) || !ctype_digit((string)$_GET['task_id'])) {
    die("<div style='text-align:center; padding:50px; font-family:tahoma;'><h2>معرّف التقرير غير صالح.</h2><a href='installation_panel.php'>العودة للوحة التركيب</a></div>");
}
$taskId = $_GET['task_id'];

// تهيئة الجدول ليقبل عمود الملاحظات إذا لم يكن موجوداً بعد
try {
    $pdo->query("SELECT installer_notes FROM installation_task LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE installation_task ADD COLUMN installer_notes TEXT NULL");
}

// جلب بيانات التقرير - يتم التأكد أن التقرير يخص الفني الحالي فقط ومكتمل
$stmt = $pdo->prepare("
    SELECT it.task_id, it.app_id, it.pipe_length, it.pipe_diameter, it.initial_reading, it.installer_notes,
           m.mtr_serial, m.mtr_type,
           a.deed_no, a.created_at,
           cy.cty_name, st.srv_name,
           c.full_name AS customer_name, c.phone_number AS customer_phone,
           ce.emp_name AS technician_name
    FROM installation_task it
    JOIN application a ON it.app_id = a.app_id
    JOIN customer c ON a.cust_id = c.cust_id
    JOIN city cy ON a.cty_id = cy.cty_id
    JOIN service_type st ON a.srv_id = st.srv_id
    JOIN company_employee ce ON it.emp_id = ce.emp_id
    LEFT JOIN meter m ON it.task_id = m.task_id
    WHERE it.task_id = ? AND it.emp_id = ? AND it.initial_reading IS NOT NULL
");
$stmt->execute([$taskId, $empId]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("<div style='text-align:center; padding:50px; font-family:tahoma;'><h2>لا يوجد تقرير مكتمل بهذا المعرّف أو ليس لديك صلاحية عرضه.</h2><a href='installation_panel.php'>العودة للوحة التركيب</a></div>");
}

$printDate = date('Y/m/d - h:i A');
$mtrTypeText = $report['mtr_type'] == 'Smart' ? 'عداد ذكي إلكتروني (Smart)' : 'عداد ميكانيكي (Mechanical)';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير التركيب #<?= str_pad($report['app_id'], 5, '0', STR_PAD_LEFT) ?> | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root { --navy: #092e54; --blue: #0b457f; --light: #4492d4; }
        body { font-family: 'Cairo', sans-serif; background: #eef2f6; color: #1e293b; margin: 0; padding: 30px 0; }

        .report-sheet { max-width: 850px; margin: 0 auto; background: white; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.08); padding: 45px 50px; }

        .report-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid var(--navy); padding-bottom: 20px; margin-bottom: 30px; }
        .brand-block { display: flex; align-items: center; gap: 14px; }
        .brand-block svg { width: 40px; height: 46px; }
        .brand-block .brand-title { font-weight: 900; font-size: 1.5rem; color: var(--navy); line-height: 1.1; }
        .brand-block .brand-sub { font-size: 0.8rem; color: #64748b; font-weight: 700; }
        .report-meta { text-align: left; font-size: 0.85rem; color: #64748b; font-weight: 700; }
        .report-meta .app-no { font-size: 1.1rem; font-weight: 900; color: var(--navy); }

        .report-title-bar { text-align: center; margin-bottom: 35px; }
        .report-title-bar h2 { font-weight: 900; color: var(--navy); margin: 0; }
        .status-badge { display: inline-flex; align-items: center; gap: 8px; padding: 8px 22px; border-radius: 50px; font-weight: 900; font-size: 1rem; margin-top: 12px; background: #dcfce7; color: #15803d; border: 1px solid #86efac; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px 30px; margin-bottom: 30px; }
        .info-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 18px; }
        .info-item .label { font-size: 0.78rem; color: #94a3b8; font-weight: 800; margin-bottom: 4px; }
        .info-item .value { font-size: 1rem; color: #1e293b; font-weight: 800; }

        .section-title { font-weight: 900; color: var(--navy); font-size: 1.05rem; margin: 30px 0 15px; padding-bottom: 8px; border-bottom: 2px solid #f1f5f9; display: flex; align-items: center; gap: 8px; }

        .spec-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        .spec-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; text-align: center; }
        .spec-box i { color: var(--light); font-size: 1.4rem; margin-bottom: 8px; display: block; }
        .spec-box .spec-label { font-size: 0.78rem; color: #94a3b8; font-weight: 800; margin-bottom: 4px; }
        .spec-box .spec-value { font-size: 1.1rem; color: var(--navy); font-weight: 900; }

        .notes-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 16px 20px; font-weight: 600; color: #78350f; line-height: 1.9; font-size: 0.95rem; white-space: pre-line; }

        .signature-area { display: flex; justify-content: space-between; margin-top: 50px; padding-top: 20px; }
        .sign-box { text-align: center; width: 220px; }
        .sign-line { border-top: 2px solid #cbd5e1; margin-top: 45px; padding-top: 8px; font-weight: 800; color: #475569; font-size: 0.85rem; }

        .actions-bar { max-width: 850px; margin: 0 auto 20px; display: flex; justify-content: space-between; gap: 12px; }
        .btn-brand { background: var(--blue); color: white; border: none; padding: 12px 26px; border-radius: 10px; font-weight: 800; }
        .btn-brand:hover { background: var(--navy); color: white; }

        @media print {
            body { background: white; padding: 0; }
            .actions-bar { display: none !important; }
            .report-sheet { box-shadow: none; padding: 20px; max-width: 100%; }
        }
    </style>
</head>
<body>

    <div class="actions-bar">
        <a href="installation_panel.php" class="btn btn-outline-secondary fw-bold rounded-3"><i class="fa-solid fa-arrow-right ms-1"></i> العودة للوحة التركيب</a>
        <button onclick="window.print()" class="btn-brand rounded-3"><i class="fa-solid fa-print ms-1"></i> طباعة / حفظ PDF</button>
    </div>

    <div class="report-sheet">
        <div class="report-header">
            <div class="brand-block">
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
                <div>
                    <div class="brand-title">قطــرة</div>
                    <div class="brand-sub">تقرير التركيب الميداني الرسمي</div>
                </div>
            </div>
            <div class="report-meta">
                <div class="app-no">طلب #<?= str_pad($report['app_id'], 5, '0', STR_PAD_LEFT) ?></div>
                <div>تاريخ التركيب: <?= date('Y/m/d', strtotime($report['created_at'])) ?></div>
                <div>تاريخ الطباعة: <?= $printDate ?></div>
            </div>
        </div>

        <div class="report-title-bar">
            <h2>تقرير تركيب وتفعيل العداد</h2>
            <div class="status-badge"><i class="fa-solid fa-circle-check"></i> تم التركيب والتفعيل بنجاح</div>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <div class="label">اسم العميل</div>
                <div class="value"><?= htmlspecialchars($report['customer_name']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">رقم جوال العميل</div>
                <div class="value"><?= htmlspecialchars($report['customer_phone']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">رقم الصك</div>
                <div class="value"><?= htmlspecialchars($report['deed_no']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">نوع الخدمة</div>
                <div class="value"><?= htmlspecialchars($report['srv_name']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">المدينة</div>
                <div class="value"><?= htmlspecialchars($report['cty_name']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">فني التركيب</div>
                <div class="value"><?= htmlspecialchars($report['technician_name']) ?></div>
            </div>
        </div>

        <div class="section-title"><i class="fa-solid fa-gauge-high text-primary"></i> بيانات العداد المركّب</div>
        <div class="spec-grid mb-3">
            <div class="spec-box">
                <i class="fa-solid fa-hashtag"></i>
                <div class="spec-label">الرقم التسلسلي</div>
                <div class="spec-value"><?= htmlspecialchars($report['mtr_serial'] ?? '—') ?></div>
            </div>
            <div class="spec-box">
                <i class="fa-solid fa-microchip"></i>
                <div class="spec-label">نوع العداد</div>
                <div class="spec-value" style="font-size: 0.95rem;"><?= $mtrTypeText ?></div>
            </div>
            <div class="spec-box">
                <i class="fa-solid fa-water"></i>
                <div class="spec-label">القراءة الافتتاحية</div>
                <div class="spec-value"><?= htmlspecialchars($report['initial_reading']) ?> م³</div>
            </div>
        </div>

        <div class="section-title"><i class="fa-solid fa-ruler-combined text-primary"></i> مواصفات خط التوصيل</div>
        <div class="spec-grid">
            <div class="spec-box">
                <i class="fa-solid fa-road"></i>
                <div class="spec-label">طول الأنبوب</div>
                <div class="spec-value"><?= htmlspecialchars($report['pipe_length']) ?> متر</div>
            </div>
            <div class="spec-box">
                <i class="fa-solid fa-circle-notch"></i>
                <div class="spec-label">قطر الأنبوب</div>
                <div class="spec-value"><?= htmlspecialchars($report['pipe_diameter']) ?> بوصة</div>
            </div>
            <div class="spec-box">
                <i class="fa-solid fa-plug-circle-check"></i>
                <div class="spec-label">حالة التفعيل</div>
                <div class="spec-value" style="color:#16a34a;">مفعّلة</div>
            </div>
        </div>

        <?php if (!empty($report['installer_notes'])): ?>
        <div class="section-title"><i class="fa-solid fa-note-sticky text-primary"></i> ملاحظات فني التركيب</div>
        <div class="notes-box"><?= nl2br(htmlspecialchars($report['installer_notes'])) ?></div>
        <?php endif; ?>

        <div class="signature-area">
            <div class="sign-box">
                <div class="sign-line">توقيع فني التركيب</div>
            </div>
            <div class="sign-box">
                <div class="sign-line">اعتماد المشرف المختص</div>
            </div>
        </div>
    </div>

</body>
</html>