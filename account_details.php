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

$accId = isset($_GET['acc_id']) ? (int)$_GET['acc_id'] : 0;
if ($accId <= 0) {
    header("Location: dashboard.php");
    exit;
}

function cleanServiceName($name) {
    if (strpos($name, 'مياه وصرف') !== false) return 'مياه وصرف';
    if (strpos($name, 'مياه') !== false) return 'مياه';
    if (strpos($name, 'صرف') !== false) return 'صرف';
    return $name;
}

// يحدد اسم الخدمة الخاصة بعداد معيّن دون افتراض وجود عمود srv_id في جدول meter
// (بعض قواعد البيانات لا تربط العداد بالخدمة مباشرة) - في حال توفر العمود يُستخدم
// للمطابقة الدقيقة، وإلا: إذا كانت هناك خدمة واحدة مفعّلة فقط على الحساب تُستخدم
// تلقائياً، وإلا يُعرض اسم عام دون تخمين.
function resolveMeterServiceName($meter, $activeServices) {
    if (isset($meter['srv_id'])) {
        foreach ($activeServices as $s) {
            if ($s['srv_id'] == $meter['srv_id']) return cleanServiceName($s['srv_name']);
        }
    }
    if (count($activeServices) === 1) {
        return cleanServiceName($activeServices[0]['srv_name']);
    }
    return 'غير محددة';
}

function getStatusBadge($status) {
    $badges = [
        'Pending_Review' => '<span class="status-badge badge-warning"><i class="fa-solid fa-file-signature"></i> قيد المراجعة</span>',
        'Pending_Inspection' => '<span class="status-badge badge-info"><i class="fa-solid fa-helmet-safety"></i> جاري جدولة الفحص</span>',
        'Pending_Billing' => '<span class="status-badge badge-dark"><i class="fa-solid fa-file-invoice-dollar"></i> بانتظار سداد الفاتورة</span>',
        'In_Progress' => '<span class="status-badge badge-primary"><i class="fa-solid fa-person-digging"></i> جاري التركيب</span>',
        'Completed' => '<span class="status-badge badge-success"><i class="fa-solid fa-circle-check"></i> مكتمل ومفعّل بالكامل</span>',
        'Rejected' => '<span class="status-badge badge-danger"><i class="fa-solid fa-circle-xmark"></i> تم رفض الطلب</span>'
    ];
    return $badges[$status] ?? '<span class="status-badge bg-secondary">' . $status . '</span>';
}

// =========================================================
// جلب بيانات الحساب الموحد، والتأكد أنه يخص العميل الحالي فقط (حماية من IDOR)
// =========================================================
$accStmt = $pdo->prepare("
    SELECT ua.acc_id, ua.deed_no, ua.creation_date, moj.land_area, moj.owner_name
    FROM unified_account ua
    JOIN moj_record moj ON ua.deed_no = moj.deed_no
    WHERE ua.acc_id = ? AND ua.cust_id = ?
");
$accStmt->execute([$accId, $custId]);
$account = $accStmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    die("<div style='text-align:center; color:red; margin-top:50px; font-family:Cairo'>عفواً، هذا الحساب غير موجود أو لا يخصك.</div>");
}

// الخدمات المفعّلة على هذا الحساب (DISTINCT لتفادي ظهور نفس الخدمة مكررة
// في حال وجود أكثر من سجل تفعيل لنفس الخدمة على نفس الحساب)
$srvStmt = $pdo->prepare("
    SELECT DISTINCT st.srv_id, st.srv_name
    FROM activated_service acs
    JOIN service_type st ON acs.srv_id = st.srv_id
    WHERE acs.acc_id = ?
");
$srvStmt->execute([$accId]);
$activeServices = $srvStmt->fetchAll(PDO::FETCH_ASSOC);

// العدادات المرتبطة بالحساب
$meterStmt = $pdo->prepare("SELECT * FROM meter WHERE acc_id = ?");
$meterStmt->execute([$accId]);
$meters = $meterStmt->fetchAll(PDO::FETCH_ASSOC);

// جميع الطلبات المرتبطة بنفس رقم الصك، مع تفاصيل الفواتير الخاصة بكل طلب
$appsStmt = $pdo->prepare("
    SELECT a.app_id, a.app_status, a.created_at, s.srv_name, c.cty_name,
           i.inv_id, i.amount, i.payment_status,
           (SELECT ah.rejection_reason FROM application_history ah 
            WHERE ah.app_id = a.app_id AND ah.status = 'Rejected' 
            ORDER BY ah.change_date DESC LIMIT 1) AS rejection_reason
    FROM application a
    JOIN city c ON a.cty_id = c.cty_id
    JOIN service_type s ON a.srv_id = s.srv_id
    LEFT JOIN invoice i ON a.app_id = i.app_id
    WHERE a.deed_no = ? AND a.cust_id = ?
    ORDER BY a.created_at DESC
");
$appsStmt->execute([$account['deed_no'], $custId]);
$applications = $appsStmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات مالية إجمالية لهذا الحساب
$totalPaid = 0.0; $totalUnpaid = 0.0; $paidCount = 0; $unpaidCount = 0;
foreach ($applications as $app) {
    if ($app['payment_status'] == 'Paid') { $totalPaid += (float)$app['amount']; $paidCount++; }
    elseif ($app['payment_status'] == 'Unpaid') { $totalUnpaid += (float)$app['amount']; $unpaidCount++; }
}

// =========================================================
// بيانات الاستهلاك الشهري لكل عداد
// نحاول القراءة من جدول قراءات العدادات (meter_reading) إن وُجد في قاعدة البيانات.
// إن لم يكن الجدول موجوداً بعد، نعرض رسالة توضيحية بدلاً من كسر الصفحة أو
// اختلاق بيانات وهمية.
// =========================================================
$consumptionByMeter = [];
$consumptionTableExists = true;
try {
    $probe = $pdo->query("SELECT 1 FROM meter_reading LIMIT 1");
} catch (PDOException $e) {
    $consumptionTableExists = false;
}

if ($consumptionTableExists) {
    foreach ($meters as $meter) {
        try {
            $readStmt = $pdo->prepare("
                SELECT reading_date, consumption_value, amount
                FROM meter_reading
                WHERE mtr_id = ?
                ORDER BY reading_date DESC
                LIMIT 12
            ");
            $readStmt->execute([$meter['mtr_id']]);
            $rows = $readStmt->fetchAll(PDO::FETCH_ASSOC);
            $consumptionByMeter[$meter['mtr_id']] = array_reverse($rows);
        } catch (PDOException $e) {
            $consumptionByMeter[$meter['mtr_id']] = [];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفاصيل الحساب #ACC-<?= str_pad($accId, 5, '0', STR_PAD_LEFT); ?> | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --nwc-navy: #092e54;
            --nwc-blue: #4492d4;
            --nwc-light: #eaf3fb;
            --bg-color: #092e54;
            --card-shadow: 0 25px 60px rgba(0,0,0,0.25);
        }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-color); color: #334155; }
        .bg-animation { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; background: radial-gradient(circle at top right, #10599c 0%, var(--nwc-navy) 70%); pointer-events: none; }
        .container { position: relative; z-index: 10; }
        .fade-in-up { animation: fadeInUp 0.7s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; opacity: 0; transform: translateY(20px); }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }
        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }

        .navbar-luxury { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); padding: 15px 0; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25); border-bottom: 1px solid rgba(255, 255, 255, 0.2); position: relative; }
        .navbar-luxury::after { content: ''; position: absolute; bottom: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, var(--nwc-blue), transparent); }
        .brand-icon { background: linear-gradient(135deg, var(--nwc-navy), #0a1128); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 16px; box-shadow: 0 8px 20px rgba(9, 46, 84, 0.3); }
        .btn-back { background: white; color: var(--nwc-navy); border: 1px solid #e2e8f0; border-radius: 50px; padding: 10px 22px; font-weight: 800; transition: 0.3s; }
        .btn-back:hover { background: var(--nwc-light); color: var(--nwc-navy); transform: translateX(4px); }

        .premium-card { background: rgba(255, 255, 255, 0.97); backdrop-filter: blur(20px); border-radius: 24px; padding: 35px; box-shadow: var(--card-shadow); border: 1px solid rgba(255,255,255,0.2); position: relative; margin-bottom: 25px; }
        .premium-card::before { content: ''; position: absolute; top: 0; left: 10%; width: 80%; height: 4px; background: linear-gradient(90deg, transparent, var(--nwc-blue), transparent); border-radius: 0 0 10px 10px; }

        .card-header-title { color: var(--nwc-navy); font-weight: 900; font-size: 1.3rem; margin-bottom: 25px; display: flex; align-items: center; gap: 12px; border-bottom: 2px solid var(--nwc-light); padding-bottom: 15px; }
        .card-header-title i { background: var(--nwc-light); color: var(--nwc-blue); width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.1rem; }

        .stat-box { background: white; border: 1px solid #e2e8f0; border-radius: 18px; padding: 22px; text-align: center; height: 100%; }
        .stat-box .stat-icon { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-size: 1.2rem; }
        .stat-box h3 { font-weight: 900; margin: 0; }
        .stat-box small { font-weight: 700; color: #64748b; }

        .info-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #64748b; font-weight: 700; }
        .info-value { color: #1e293b; font-weight: 800; }

        .service-pill { display: inline-flex; align-items: center; gap: 8px; background: var(--nwc-light); color: var(--nwc-blue); border: 1px solid #bae6fd; border-radius: 50px; padding: 8px 16px; font-weight: 800; margin: 4px; }

        .meter-card { background: white; border: 1px solid #e2e8f0; border-radius: 18px; padding: 20px; margin-bottom: 18px; border-right: 4px solid #10b981; }
        .meter-card .meter-serial { font-family: monospace; font-weight: 900; color: var(--nwc-navy); font-size: 1.05rem; }

        .table-custom th { color: var(--nwc-navy); font-weight: 800; padding: 16px 14px; border-bottom: 2px solid var(--nwc-light); }
        .table-custom td { padding: 18px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-weight: 700; color: #1e293b; }

        .status-badge { padding: 8px 15px; border-radius: 12px; font-weight: 800; display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; }
        .badge-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .badge-info { background: var(--nwc-light); color: var(--nwc-blue); border: 1px solid #bae6fd; }
        .badge-primary { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .badge-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .badge-dark { background: #f8fafc; color: #334155; border: 1px solid #e2e8f0; }
        .badge-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        .rejection-reason-inline { font-size: 0.78rem; font-weight: 700; color: #dc2626; margin-top: 6px; max-width: 260px; display: flex; align-items: flex-start; gap: 5px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 6px 10px; }

        .chart-wrap { background: white; border: 1px solid #e2e8f0; border-radius: 18px; padding: 20px; margin-bottom: 18px; }
        .empty-consumption { text-align: center; padding: 30px 15px; color: #94a3b8; font-weight: 700; }

        .btn-brand-outline { background: white; color: var(--nwc-navy); border: 2px solid var(--nwc-blue); border-radius: 50px; padding: 10px 22px; font-weight: 800; transition: 0.3s; }
        .btn-brand-outline:hover { background: var(--nwc-blue); color: white; }
    </style>
</head>
<body>

    <div class="bg-animation"></div>

    <nav class="navbar navbar-luxury fade-in-up">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="#" class="d-flex align-items-center gap-3 text-decoration-none">
                <div class="brand-icon">
                    <svg viewBox="0 0 60 68" width="26" height="28" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="30" cy="6" r="2.1" fill="#bae6fd"/>
                        <circle cx="26.3" cy="13" r="2.3" fill="#bae6fd"/>
                        <circle cx="33.7" cy="13" r="2.3" fill="#93c5fd"/>
                        <circle cx="22.6" cy="20" r="2.6" fill="#93c5fd"/>
                        <circle cx="30" cy="20" r="2.6" fill="#7dd3fc"/>
                        <circle cx="37.4" cy="20" r="2.6" fill="#60a5fa"/>
                    </svg>
                </div>
                <div>
                    <div class="fw-black fs-4 text-dark m-0">قــطــرة</div>
                    <div class="text-muted small fw-bold">تفاصيل الحساب الموحد</div>
                </div>
            </a>
            <a href="dashboard.php" class="btn-back"><i class="fa-solid fa-arrow-right me-1"></i> العودة للوحة التحكم</a>
        </div>
    </nav>

    <div class="container pb-5 mt-4">

        <div class="row mb-4 fade-in-up delay-1 align-items-center">
            <div class="col-md-8 text-white">
                <h1 class="fw-black m-0">حساب #ACC-<?= str_pad($accId, 5, '0', STR_PAD_LEFT); ?></h1>
                <p class="fs-6 text-light opacity-75 m-0">تفاصيل شاملة عن الخدمات، العدادات، الاستهلاك الشهري، والفواتير المرتبطة بهذا الحساب.</p>
            </div>
            <div class="col-md-4 text-md-start text-white">
                <a href="dashboard.php" class="new-request-hero-btn btn-brand-outline" style="background:white;"><i class="fa-solid fa-circle-plus me-1"></i> طلب خدمة جديدة</a>
            </div>
        </div>

        <!-- الإحصائيات المالية الإجمالية -->
        <div class="row g-3 fade-in-up delay-2 mb-1">
            <div class="col-6 col-md-3">
                <div class="stat-box">
                    <div class="stat-icon" style="background:#ecfdf5;color:#059669;"><i class="fa-solid fa-circle-check"></i></div>
                    <h3 class="text-success"><?= number_format($totalPaid, 2); ?></h3>
                    <small>إجمالي المدفوع (ر.س) — <?= $paidCount; ?> فاتورة</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-box">
                    <div class="stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <h3 class="text-danger"><?= number_format($totalUnpaid, 2); ?></h3>
                    <small>مستحقات غير مسددة (ر.س) — <?= $unpaidCount; ?> فاتورة</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-box">
                    <div class="stat-icon" style="background:var(--nwc-light);color:var(--nwc-blue);"><i class="fa-solid fa-droplet"></i></div>
                    <h3 style="color:var(--nwc-navy);"><?= count($activeServices); ?></h3>
                    <small>خدمة مفعّلة على الحساب</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-box">
                    <div class="stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fa-solid fa-gauge-high"></i></div>
                    <h3 style="color:var(--nwc-navy);"><?= count($meters); ?></h3>
                    <small>عداد مركّب على العقار</small>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- بيانات العقار والحساب -->
            <div class="col-lg-4">
                <div class="premium-card fade-in-up delay-3">
                    <div class="card-header-title"><i class="fa-solid fa-hotel"></i> بيانات العقار والحساب</div>
                    <div class="info-row"><span class="info-label">رقم الصك</span><span class="info-value font-monospace"><?= htmlspecialchars($account['deed_no']); ?></span></div>
                    <div class="info-row"><span class="info-label">المالك المسجل</span><span class="info-value"><?= htmlspecialchars($account['owner_name']); ?></span></div>
                    <div class="info-row"><span class="info-label">المساحة</span><span class="info-value"><?= htmlspecialchars($account['land_area']); ?> م²</span></div>
                    <div class="info-row"><span class="info-label">تاريخ تفعيل الحساب</span><span class="info-value"><?= htmlspecialchars($account['creation_date']); ?></span></div>
                    <div class="mt-3">
                        <div class="info-label mb-2">الخدمات المفعّلة</div>
                        <?php if (empty($activeServices)): ?>
                            <span class="text-muted">لا توجد خدمات مفعّلة بعد</span>
                        <?php else: ?>
                            <?php foreach ($activeServices as $s): ?>
                                <span class="service-pill"><i class="fa-solid fa-droplet"></i> <?= htmlspecialchars(cleanServiceName($s['srv_name'])); ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- بيانات العدادات -->
                <div class="premium-card fade-in-up delay-3">
                    <div class="card-header-title"><i class="fa-solid fa-microchip"></i> العدادات الذكية</div>
                    <?php if (empty($meters)): ?>
                        <div class="alert alert-warning text-center fw-bold mb-0"><i class="fa-solid fa-spinner fa-spin"></i> جاري تركيب وتفعيل العدادات الميدانية...</div>
                    <?php else: ?>
                        <?php foreach ($meters as $meter):
                            $srvName = resolveMeterServiceName($meter, $activeServices);
                        ?>
                            <div class="meter-card">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small text-muted fw-bold">عداد خدمة <span class="text-primary"><?= htmlspecialchars($srvName); ?></span></span>
                                    <span class="badge bg-light text-dark border"><?= ($meter['mtr_type'] ?? '') == 'Smart' ? 'ذكي' : 'ميكانيكي' ?></span>
                                </div>
                                <div class="meter-serial"><?= htmlspecialchars($meter['mtr_serial']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- الاستهلاك الشهري والفواتير -->
            <div class="col-lg-8">
                <!-- الاستهلاك الشهري -->
                <div class="premium-card fade-in-up delay-3">
                    <div class="card-header-title"><i class="fa-solid fa-chart-line"></i> الاستهلاك الشهري</div>
                    <?php if (empty($meters)): ?>
                        <div class="empty-consumption"><i class="fa-solid fa-gauge-high fs-2 d-block mb-2"></i> لا توجد عدادات مركّبة بعد لعرض بيانات الاستهلاك.</div>
                    <?php elseif (!$consumptionTableExists): ?>
                        <div class="empty-consumption">
                            <i class="fa-solid fa-chart-simple fs-2 d-block mb-2"></i>
                            بيانات الاستهلاك الشهري التفصيلية ستكون متاحة هنا فور ربط نظام قراءة العدادات عن بُعد.
                        </div>
                    <?php else: ?>
                        <?php foreach ($meters as $meter):
                            $srvName = resolveMeterServiceName($meter, $activeServices);
                            $data = $consumptionByMeter[$meter['mtr_id']] ?? [];
                        ?>
                            <div class="chart-wrap">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark"><i class="fa-solid fa-droplet text-primary me-1"></i> عداد <?= htmlspecialchars($srvName); ?> - <span class="font-monospace small text-muted"><?= htmlspecialchars($meter['mtr_serial']); ?></span></span>
                                </div>
                                <?php if (empty($data)): ?>
                                    <div class="empty-consumption py-3">لا توجد قراءات استهلاك مسجلة لهذا العداد حتى الآن.</div>
                                <?php else: ?>
                                    <canvas id="chart-<?= (int)$meter['mtr_id']; ?>" height="90"></canvas>
                                    <script>
                                        (function(){
                                            const ctx = document.getElementById('chart-<?= (int)$meter['mtr_id']; ?>');
                                            new Chart(ctx, {
                                                type: 'bar',
                                                data: {
                                                    labels: <?= json_encode(array_map(fn($r) => $r['reading_date'], $data)); ?>,
                                                    datasets: [{
                                                        label: 'الاستهلاك',
                                                        data: <?= json_encode(array_map(fn($r) => (float)$r['consumption_value'], $data)); ?>,
                                                        backgroundColor: '#4492d4',
                                                        borderRadius: 8
                                                    }]
                                                },
                                                options: {
                                                    responsive: true,
                                                    plugins: { legend: { display: false } },
                                                    scales: { y: { beginAtZero: true } }
                                                }
                                            });
                                        })();
                                    </script>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- الطلبات والفواتير المرتبطة -->
                <div class="premium-card fade-in-up delay-3">
                    <div class="card-header-title"><i class="fa-solid fa-file-invoice-dollar"></i> الطلبات والفواتير ومبالغ الخدمات</div>
                    <?php if (empty($applications)): ?>
                        <div class="text-muted text-center py-4 fw-bold">لا توجد طلبات أو فواتير مرتبطة بهذا الحساب حتى الآن.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>رقم الطلب</th>
                                        <th>الخدمة</th>
                                        <th>المدينة</th>
                                        <th>الحالة</th>
                                        <th>المبلغ والسداد</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($applications as $app): ?>
                                        <tr>
                                            <td><span class="badge bg-light text-secondary border">#<?= str_pad($app['app_id'], 5, '0', STR_PAD_LEFT); ?></span></td>
                                            <td class="fw-bold"><?= htmlspecialchars(cleanServiceName($app['srv_name'])); ?></td>
                                            <td class="small text-muted"><i class="fa-solid fa-location-dot text-danger"></i> <?= htmlspecialchars(str_replace('مدينة ', '', $app['cty_name'])); ?></td>
                                            <td>
                                                <?= getStatusBadge($app['app_status']); ?>
                                                <?php if ($app['app_status'] == 'Rejected' && !empty($app['rejection_reason'])): ?>
                                                    <div class="rejection-reason-inline">
                                                        <i class="fa-solid fa-circle-info mt-1"></i>
                                                        <span><?= htmlspecialchars($app['rejection_reason']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($app['inv_id'] === null): ?>
                                                    <span class="text-muted">لم تُصدر فاتورة بعد</span>
                                                <?php elseif ($app['payment_status'] == 'Unpaid'): ?>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge bg-danger rounded-pill px-3 py-2 fw-bold">غير مدفوعة: <?= number_format($app['amount'], 2); ?> ر.س</span>
                                                        <button class="btn btn-sm btn-success fw-bold px-3 rounded-pill"
                                                            onclick="payFromAccountPage(<?= (int)$app['app_id']; ?>)">
                                                            <i class="fa-solid fa-credit-card"></i> سداد سريع
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge bg-success rounded-pill px-3 py-2"><i class="fa-solid fa-circle-check"></i> مدفوعة: <?= number_format($app['amount'], 2); ?> ر.س</span>
                                                        <a href="invoice_print.php?app_id=<?= (int)$app['app_id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary fw-bold px-3 rounded-pill"><i class="fa-solid fa-print"></i> طباعة</a>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4" class="text-start fw-bold">الإجمالي</td>
                                        <td class="fw-black text-dark"><?= number_format($totalPaid + $totalUnpaid, 2); ?> ر.س</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // سداد سريع من صفحة تفاصيل الحساب مباشرة عبر نفس نقطة النهاية في dashboard.php
        function payFromAccountPage(appId) {
            Swal.fire({
                title: 'تأكيد السداد',
                text: 'هل ترغب بتأكيد سداد هذه الفاتورة الآن؟',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'نعم، ادفع الآن',
                cancelButtonText: 'إلغاء',
                confirmButtonColor: '#092e54'
            }).then((result) => {
                if (!result.isConfirmed) return;

                Swal.fire({ title: 'جاري تأكيد السداد...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                let fd = new FormData();
                fd.append('pay_invoice', '1');
                fd.append('app_id', appId);

                fetch('dashboard.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'error') {
                            Swal.fire({ icon: 'error', title: 'تعذر السداد', text: data.message, confirmButtonColor: '#092e54' });
                        } else {
                            Swal.fire({ icon: 'success', title: 'تم السداد بنجاح', text: data.message, confirmButtonColor: '#10b981' })
                                .then(() => window.location.reload());
                        }
                    })
                    .catch(() => {
                        Swal.fire({ icon: 'error', title: 'خطأ تقني', text: 'فشل الاتصال بالخادم، يرجى المحاولة لاحقاً.' });
                    });
            });
        }
    </script>
</body>
</html>