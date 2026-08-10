<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// التحقق من تسجيل الدخول وصلاحية فني الفحص
if (!isset($_SESSION['emp_id']) || !in_array('Inspection Technician', $_SESSION['emp_roles'])) {
    header("Location: employee_dashboard.php");
    exit;
}

require_once 'db_connect.php';
$emp_id = $_SESSION['emp_id'];

if (!isset($_GET['insp_id']) || !ctype_digit((string)$_GET['insp_id'])) {
    die("<div style='text-align:center; padding:50px; font-family:tahoma;'><h2>معرّف التقرير غير صالح.</h2><a href='inspection_panel.php'>العودة للوحة الفحص</a></div>");
}
$insp_id = $_GET['insp_id'];

// تهيئة الجدول ليقبل عمود الملاحظات إذا لم يكن موجوداً بعد
try {
    $pdo->query("SELECT inspector_notes FROM field_inspection LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE field_inspection ADD COLUMN inspector_notes TEXT NULL");
}

// جلب بيانات التقرير - يتم التأكد أن التقرير يخص الفني الحالي فقط ومكتمل (له نتيجة)
$stmt = $pdo->prepare("
    SELECT fi.insp_id, fi.app_id, fi.building_readiness, fi.doors_windows_installed,
           fi.meter_spot_painted, fi.site_photos_url, fi.inspection_result, fi.inspector_notes,
           a.deed_no, a.latitude, a.longitude, a.created_at,
           cty.cty_name, s.srv_name,
           c.full_name AS customer_name, c.phone_number AS customer_phone,
           ce.emp_name AS technician_name
    FROM field_inspection fi
    JOIN application a ON fi.app_id = a.app_id
    JOIN customer c ON a.cust_id = c.cust_id
    JOIN city cty ON a.cty_id = cty.cty_id
    JOIN service_type s ON a.srv_id = s.srv_id
    JOIN company_employee ce ON fi.emp_id = ce.emp_id
    WHERE fi.insp_id = ? AND fi.emp_id = ? AND fi.inspection_result IS NOT NULL
");
$stmt->execute([$insp_id, $emp_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("<div style='text-align:center; padding:50px; font-family:tahoma;'><h2>لا يوجد تقرير مكتمل بهذا المعرّف أو ليس لديك صلاحية عرضه.</h2><a href='inspection_panel.php'>العودة للوحة الفحص</a></div>");
}

$isPassed = $report['inspection_result'] == 'Passed';
$reportDate = date('Y/m/d - h:i A', strtotime($report['created_at']));
$printDate = date('Y/m/d - h:i A');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير الفحص الميداني #<?= str_pad($report['app_id'], 5, '0', STR_PAD_LEFT) ?> | قطرة</title>
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
        .status-badge { display: inline-flex; align-items: center; gap: 8px; padding: 8px 22px; border-radius: 50px; font-weight: 900; font-size: 1rem; margin-top: 12px; }
        .status-badge.pass { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .status-badge.fail { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px 30px; margin-bottom: 30px; }
        .info-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 18px; }
        .info-item .label { font-size: 0.78rem; color: #94a3b8; font-weight: 800; margin-bottom: 4px; }
        .info-item .value { font-size: 1rem; color: #1e293b; font-weight: 800; }

        .section-title { font-weight: 900; color: var(--navy); font-size: 1.05rem; margin: 30px 0 15px; padding-bottom: 8px; border-bottom: 2px solid #f1f5f9; display: flex; align-items: center; gap: 8px; }

        .checklist { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .checklist li { display: flex; align-items: center; gap: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; font-weight: 700; font-size: 0.92rem; }
        .checklist li i.ok { color: #16a34a; }
        .checklist li i.no { color: #dc2626; }

        .evidence-photo { width: 100%; max-height: 380px; object-fit: cover; border-radius: 14px; border: 2px solid #e2e8f0; margin-top: 10px; }

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
        <a href="inspection_panel.php" class="btn btn-outline-secondary fw-bold rounded-3"><i class="fa-solid fa-arrow-right ms-1"></i> العودة للوحة الفحص</a>
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
                    <div class="brand-sub">تقرير الفحص الميداني الرسمي</div>
                </div>
            </div>
            <div class="report-meta">
                <div class="app-no">طلب #<?= str_pad($report['app_id'], 5, '0', STR_PAD_LEFT) ?></div>
                <div>تاريخ الفحص: <?= $reportDate ?></div>
                <div>تاريخ الطباعة: <?= $printDate ?></div>
            </div>
        </div>

        <div class="report-title-bar">
            <h2>تقرير جاهزية الموقع الميداني</h2>
            <?php if ($isPassed): ?>
                <div class="status-badge pass"><i class="fa-solid fa-circle-check"></i> جاهز ومطابق — تم تمرير الطلب</div>
            <?php else: ?>
                <div class="status-badge fail"><i class="fa-solid fa-circle-xmark"></i> غير مطابق — تم رفض الطلب</div>
            <?php endif; ?>
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
                <div class="label">الفني القائم بالفحص</div>
                <div class="value"><?= htmlspecialchars($report['technician_name']) ?></div>
            </div>
        </div>

        <div class="section-title"><i class="fa-solid fa-square-poll-horizontal text-primary"></i> معايير الجاهزية الميدانية</div>
        <ul class="checklist">
            <li>
                <i class="fa-solid <?= $report['building_readiness'] ? 'fa-circle-check ok' : 'fa-circle-xmark no' ?>"></i>
                اكتمال البناء وجاهزية الهيكل
            </li>
            <li>
                <i class="fa-solid <?= $report['doors_windows_installed'] ? 'fa-circle-check ok' : 'fa-circle-xmark no' ?>"></i>
                تركيب الأبواب والنوافذ الخارجية
            </li>
            <li>
                <i class="fa-solid <?= $report['meter_spot_painted'] ? 'fa-circle-check ok' : 'fa-circle-xmark no' ?>"></i>
                تجهيز وطلاء موقع العداد
            </li>
        </ul>

        <?php if (!empty($report['site_photos_url'])): ?>
        <div class="section-title"><i class="fa-solid fa-camera text-primary"></i> الصورة الميدانية الموثقة</div>
        <img src="<?= htmlspecialchars($report['site_photos_url']) ?>" class="evidence-photo" alt="صورة الفحص الميداني">
        <?php endif; ?>

        <?php if (!empty($report['inspector_notes'])): ?>
        <div class="section-title"><i class="fa-solid fa-note-sticky text-primary"></i> ملاحظات الفني الميدانية</div>
        <div class="notes-box"><?= nl2br(htmlspecialchars($report['inspector_notes'])) ?></div>
        <?php endif; ?>

        <div class="signature-area">
            <div class="sign-box">
                <div class="sign-line">توقيع الفني الميداني</div>
            </div>
            <div class="sign-box">
                <div class="sign-line">اعتماد المشرف المختص</div>
            </div>
        </div>
    </div>

</body>
</html>