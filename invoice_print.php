<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once 'db_connect.php';

if (!isset($_SESSION['customer_national_id'])) {
    header("Location: login.php");
    exit;
}

$nationalId = $_SESSION['customer_national_id'];

$stmt = $pdo->prepare("SELECT cust_id, full_name, phone_number FROM customer WHERE national_id = ?");
$stmt->execute([$nationalId]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die("<div style='text-align:center; color:red; margin-top:50px; font-family:Cairo'>عفواً، لم يتم العثور على بيانات المستفيد في النظام.</div>");
}

$custId = $customer['cust_id'];

$appId = isset($_GET['app_id']) ? (int)$_GET['app_id'] : 0;

if (!$appId) {
    die("<div style='text-align:center; color:red; margin-top:50px; font-family:Cairo'>عفواً، رقم الطلب غير صحيح.</div>");
}

function cleanServiceName($name) {
    if (strpos($name, 'مياه وصرف') !== false) return 'مياه وصرف';
    if (strpos($name, 'مياه') !== false) return 'مياه';
    if (strpos($name, 'صرف') !== false) return 'صرف';
    return $name;
}

// جلب بيانات الفاتورة والطلب، مع التأكد من ملكية العميل الحالي لهذا الطلب أمنياً
$stmt = $pdo->prepare("
    SELECT a.app_id, a.deed_no, a.srv_id, a.cty_id, a.cust_id,
           i.inv_id, i.amount, i.payment_status,
           s.srv_name, c.cty_name
    FROM application a
    JOIN invoice i ON a.app_id = i.app_id
    JOIN service_type s ON a.srv_id = s.srv_id
    JOIN city c ON a.cty_id = c.cty_id
    WHERE a.app_id = ?
");
$stmt->execute([$appId]);
$invoiceData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoiceData || $invoiceData['cust_id'] != $custId) {
    die("<div style='text-align:center; color:red; margin-top:50px; font-family:Cairo'>عفواً، لا يمكن الوصول إلى هذه الفاتورة.</div>");
}

// الحساب الموحد المرتبط بنفس الصك (إن وجد)
$stmtAcc = $pdo->prepare("SELECT acc_id FROM unified_account WHERE deed_no = ?");
$stmtAcc->execute([$invoiceData['deed_no']]);
$accId = $stmtAcc->fetchColumn();

// اسم فني التركيب المسنَد لهذا الطلب (إن وجد)
$stmtTech = $pdo->prepare("
    SELECT ce.emp_name
    FROM installation_task it
    JOIN company_employee ce ON it.emp_id = ce.emp_id
    WHERE it.app_id = ?
    LIMIT 1
");
$stmtTech->execute([$appId]);
$techName = $stmtTech->fetchColumn();

$isPaid = ($invoiceData['payment_status'] == 'Paid');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة #<?= str_pad($appId, 5, '0', STR_PAD_LEFT); ?> | نظام قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root { --nwc-navy: #092e54; --nwc-blue: #4492d4; --nwc-light: #eaf3fb; }
        body { font-family: 'Cairo', sans-serif; background: #eef2f7; color: #1e293b; }
        .invoice-wrap { max-width: 720px; margin: 40px auto; }
        .invoice-card { background: white; border-radius: 20px; box-shadow: 0 20px 45px rgba(0,0,0,0.1); overflow: hidden; }
        .invoice-head { background: linear-gradient(135deg, var(--nwc-navy), var(--nwc-blue)); color: white; padding: 35px 40px; }
        .invoice-body { padding: 40px; }
        .row-line { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px dashed #e2e8f0; font-weight: 700; }
        .row-line span:first-child { color: #64748b; }
        .amount-box { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 16px; padding: 25px; text-align: center; margin-top: 20px; }
        .toolbar { max-width: 720px; margin: 0 auto 15px; display: flex; justify-content: flex-end; gap: 10px; }
        .btn-brand { background: linear-gradient(135deg, var(--nwc-navy), var(--nwc-blue)); color: white; border: none; border-radius: 14px; padding: 12px 25px; font-weight: 800; }
        .btn-brand:hover { color: white; opacity: 0.9; }
        .status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 16px; border-radius: 50px; font-weight: 800; font-size: 0.85rem; }
        @media print {
            body { background: white; }
            .toolbar { display: none !important; }
            .invoice-card { box-shadow: none; }
        }
    </style>
</head>
<body>

    <div class="toolbar no-print">
        <button class="btn btn-brand" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> طباعة</button>
        <a href="dashboard.php" class="btn btn-outline-secondary fw-bold" style="border-radius: 14px;"><i class="fa-solid fa-arrow-right me-1"></i> رجوع للوحة التحكم</a>
    </div>

    <div class="invoice-wrap">
        <div class="invoice-card">
            <div class="invoice-head text-center">
                <i class="fa-solid fa-file-invoice-dollar fs-1 mb-2 d-block"></i>
                <h3 class="fw-black m-0">فاتورة سداد - نظام قطرة</h3>
                <small class="opacity-75">إيصال إلكتروني رسمي لبوابة الخدمات الموحدة</small>
            </div>
            <div class="invoice-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <div class="text-muted fw-bold small">رقم الفاتورة</div>
                        <div class="fw-black fs-5">#<?= str_pad($invoiceData['inv_id'], 5, '0', STR_PAD_LEFT); ?></div>
                    </div>
                    <?php if ($isPaid): ?>
                        <span class="status-pill" style="background:#ecfdf5; color:#059669; border:1px solid #a7f3d0;"><i class="fa-solid fa-circle-check"></i> مدفوعة بنجاح</span>
                    <?php else: ?>
                        <span class="status-pill" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca;"><i class="fa-solid fa-circle-exclamation"></i> غير مدفوعة</span>
                    <?php endif; ?>
                </div>

                <div class="row-line"><span>رقم الطلب</span><span>#<?= str_pad($appId, 5, '0', STR_PAD_LEFT); ?></span></div>
                <div class="row-line"><span>اسم العميل</span><span><?= htmlspecialchars($customer['full_name']); ?></span></div>
                <div class="row-line"><span>رقم الهوية</span><span><?= htmlspecialchars($nationalId); ?></span></div>
                <div class="row-line"><span>نوع الخدمة</span><span><?= htmlspecialchars(cleanServiceName($invoiceData['srv_name'])); ?></span></div>
                <div class="row-line"><span>المدينة</span><span><?= htmlspecialchars(str_replace('مدينة ', '', $invoiceData['cty_name'])); ?></span></div>
                <div class="row-line"><span>رقم الصك</span><span class="font-monospace"><?= htmlspecialchars($invoiceData['deed_no']); ?></span></div>
                <?php if ($accId): ?>
                <div class="row-line"><span>الحساب الموحد</span><span>ACC-<?= str_pad($accId, 5, '0', STR_PAD_LEFT); ?></span></div>
                <?php endif; ?>
                <?php if ($techName): ?>
                <div class="row-line"><span>فني التركيب المسنَد</span><span><?= htmlspecialchars($techName); ?></span></div>
                <?php endif; ?>
                <div class="row-line" style="border-bottom: none;"><span>تاريخ الطباعة</span><span><?= date('Y-m-d H:i'); ?></span></div>

                <div class="amount-box">
                    <div class="text-muted fw-bold small mb-1"><?= $isPaid ? 'المبلغ المسدد' : 'المبلغ المستحق'; ?></div>
                    <h2 class="fw-black text-success m-0"><?= number_format($invoiceData['amount'], 2); ?> ر.س</h2>
                </div>

                <div class="text-center text-muted small mt-4">
                    <i class="fa-solid fa-shield-halved text-success"></i> هذا الإيصال صادر آلياً من نظام قطرة ولا يحتاج توقيعاً أو ختماً لاعتماده.
                </div>
            </div>
        </div>
    </div>

</body>
</html>