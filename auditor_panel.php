<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// التحقق من تسجيل الدخول وصلاحية المدقق فقط
if (!isset($_SESSION['emp_id']) || !in_array('Auditor', $_SESSION['emp_roles'])) {
    header("Location: employee_dashboard.php");
    exit;
}

require_once 'db_connect.php';
$msg = ""; $msgType = "";
$empId = $_SESSION['emp_id'];

// =========================================================
// معالجة القرارات (موافقة / رفض)
// =========================================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 1. الموافقة على الطلب يدوياً
    if (isset($_POST['approve_app'])) {
        $appId = $_POST['app_id'];
        $cityId = $_POST['cty_id'];

        try {
            $pdo->beginTransaction();

            // تحديث حالة الطلب
            $pdo->prepare("UPDATE application SET app_status = 'Pending_Inspection' WHERE app_id = ?")->execute([$appId]);

            // تسجيل الحركة بتاريخ الطلب
            $pdo->prepare("INSERT INTO application_history (app_id, status, changed_by, change_date) VALUES (?, 'Pending_Inspection', ?, NOW())")->execute([$appId, $empId]);

            // محاولة التوزيع الذكي التلقائي لفني الفحص (بنفس منطق dashboard.php)
            try {
                $pdo->query("SELECT is_active FROM company_employee LIMIT 1");
            } catch (Exception $e) {
                $pdo->exec("ALTER TABLE company_employee ADD COLUMN is_active BOOLEAN DEFAULT 1");
            }

            $findTechStmt = $pdo->prepare("
                SELECT ce.emp_id 
                FROM company_employee ce
                JOIN employee_roles er ON ce.emp_id = er.emp_id
                JOIN system_role sr ON er.role_id = sr.role_id
                WHERE ce.cty_id = ? AND ce.is_active = 1 AND sr.role_name = 'Inspection Technician'
                ORDER BY ce.active_tasks_count ASC 
                LIMIT 1
            ");
            $findTechStmt->execute([$cityId]);
            $assignedTechId = $findTechStmt->fetchColumn();

            if ($assignedTechId) {
                $pdo->prepare("INSERT INTO field_inspection (app_id, emp_id) VALUES (?, ?)")->execute([$appId, $assignedTechId]);
                $pdo->prepare("UPDATE company_employee SET active_tasks_count = active_tasks_count + 1 WHERE emp_id = ?")->execute([$assignedTechId]);
                $msg = "تمت الموافقة على الطلب #$appId وإسناده تلقائياً لفني الفحص.";
            } else {
                $msg = "تمت الموافقة على الطلب #$appId، لكن لا يوجد فني فحص متاح بمدينة العقار حالياً. يمكن إسناده لاحقاً من لوحة المدير.";
            }

            $pdo->commit();
            $msgType = "success";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "حدث خطأ أثناء معالجة الموافقة."; $msgType = "error";
        }
    }

    // 2. رفض الطلب مع كتابة السبب
    if (isset($_POST['reject_app'])) {
        $appId = $_POST['app_id'];
        $reason = trim($_POST['rejection_reason']);

        if (empty($reason)) {
            $msg = "خطأ: يجب كتابة سبب الرفض."; $msgType = "error";
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE application SET app_status = 'Rejected' WHERE app_id = ?")->execute([$appId]);
                $pdo->prepare("INSERT INTO application_history (app_id, status, rejection_reason, changed_by, change_date) VALUES (?, 'Rejected', ?, ?, NOW())")->execute([$appId, $reason, $empId]);
                $pdo->commit();
                $msg = "تم رفض الطلب #$appId وتسجيل السبب."; $msgType = "success";
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg = "حدث خطأ أثناء معالجة الرفض."; $msgType = "error";
            }
        }
    }
}

// =========================================================
// جلب الطلبات المعلّقة يدوياً (Pending_Review) مع بيانات الصك والعميل
// =========================================================
$pendingReview = $pdo->query("
    SELECT a.app_id, a.deed_no, a.deed_file_url, a.cty_id, a.created_at,
           c.full_name AS customer_name, c.national_id AS customer_national_id, c.phone_number,
           city.cty_name, s.srv_name,
           m.owner_name AS moj_owner_name, m.owner_national_id AS moj_owner_national_id, m.land_area
    FROM application a
    JOIN customer c ON a.cust_id = c.cust_id
    JOIN city ON a.cty_id = city.cty_id
    JOIN service_type s ON a.srv_id = s.srv_id
    LEFT JOIN moj_record m ON a.deed_no = m.deed_no
    WHERE a.app_status = 'Pending_Review'
    ORDER BY a.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات سريعة للمدقق
$myAuditsCount = $pdo->prepare("SELECT COUNT(*) FROM application_history WHERE changed_by = ?");
$myAuditsCount->execute([$empId]);
$myAuditsCount = $myAuditsCount->fetchColumn();

$pendingCount = count($pendingReview);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة المدقق | قطرة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --navy: #092e54; --blue: #0b457f; --light: #4492d4; --bg: #f8fafc; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg); margin: 0; padding: 0; display: flex; height: 100vh; overflow: hidden; }

        .sidebar { width: 280px; background: var(--navy); color: white; display: flex; flex-direction: column; box-shadow: -4px 0 15px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header i { font-size: 2.5rem; color: #7dd3fc; margin-bottom: 10px; }
        .sidebar-stats { padding: 25px 20px; }
        .stat-box { background: rgba(255,255,255,0.06); border-radius: 14px; padding: 18px; text-align: center; margin-bottom: 15px; }
        .stat-box .num { font-size: 1.8rem; font-weight: 900; color: #7dd3fc; }
        .stat-box .label { font-size: 0.85rem; color: #cbd5e1; font-weight: 700; }

        .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { background: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .content-area { flex: 1; padding: 30px; overflow-y: auto; background: var(--bg); }

        .review-card { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); margin-bottom: 25px; border: 1px solid #e2e8f0; }
        .compare-box { background: #f8fafc; border-radius: 12px; padding: 18px; border: 1px solid #e2e8f0; }
        .compare-title { font-weight: 800; color: var(--navy); font-size: 0.9rem; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .compare-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #e2e8f0; font-weight: 700; font-size: 0.95rem; }
        .compare-row:last-child { border-bottom: none; }
        .mismatch { color: #dc2626; }
        .match { color: #16a34a; }

        .btn-approve { background: #16a34a; color: white; border: none; border-radius: 10px; padding: 10px 20px; font-weight: 800; }
        .btn-approve:hover { background: #15803d; color: white; }
        .btn-reject { background: #dc2626; color: white; border: none; border-radius: 10px; padding: 10px 20px; font-weight: 800; }
        .btn-reject:hover { background: #b91c1c; color: white; }

        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state i { font-size: 3.5rem; color: #16a34a; margin-bottom: 15px; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fa-solid fa-file-signature"></i>
            <h4 class="fw-black m-0">لوحة المدقق</h4>
            <div class="small mt-1 text-info">مراجعة صكوك الملكية</div>
        </div>
        <div class="sidebar-stats">
            <div class="stat-box">
                <div class="num"><?= $pendingCount ?></div>
                <div class="label">طلبات بانتظار المراجعة</div>
            </div>
            <div class="stat-box">
                <div class="num"><?= $myAuditsCount ?></div>
                <div class="label">إجمالي قراراتك المسجلة</div>
            </div>
        </div>
        <div class="p-3 border-top border-secondary mt-auto">
            <a href="employee_dashboard.php" class="btn btn-outline-light w-100 fw-bold rounded-pill"><i class="fa-solid fa-arrow-right-from-bracket fa-flip-horizontal me-2"></i> شاشة التوجيه</a>
        </div>
    </div>

    <div class="main-wrapper">
        <div class="topbar">
            <h4 class="fw-black text-dark m-0">مراجعة الطلبات المعلقة يدوياً</h4>
            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold text-secondary">مرحباً، م. <?= htmlspecialchars($_SESSION['emp_name']); ?></span>
                <div class="bg-primary text-white rounded-circle d-flex justify-content-center align-items-center" style="width: 40px; height: 40px;"><i class="fa-solid fa-file-signature"></i></div>
            </div>
        </div>

        <div class="content-area">
            <?php if($msg): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({ icon: '<?= $msgType ?>', title: 'إشعار النظام', text: '<?= addslashes($msg) ?>', confirmButtonColor: '#0b457f' })
                        .then(() => { window.location.href = 'auditor_panel.php'; });
                    });
                </script>
            <?php endif; ?>

            <?php if(empty($pendingReview)): ?>
                <div class="review-card empty-state">
                    <i class="fa-solid fa-circle-check"></i>
                    <h4 class="fw-black text-dark mb-2">لا توجد طلبات بانتظار المراجعة اليدوية</h4>
                    <p class="text-muted fw-bold">جميع الطلبات إما تمت مطابقتها آلياً أو تمت مراجعتها مسبقاً.</p>
                </div>
            <?php else: ?>
                <?php foreach($pendingReview as $app): ?>
                <div class="review-card">
                    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                        <div>
                            <span class="badge bg-light text-dark border fs-6">طلب #<?= str_pad($app['app_id'], 5, '0', STR_PAD_LEFT) ?></span>
                            <span class="badge bg-light text-dark border fs-6"><?= htmlspecialchars($app['srv_name']) ?></span>
                            <span class="badge bg-light text-dark border fs-6"><i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars($app['cty_name']) ?></span>
                        </div>
                        <?php if($app['deed_file_url']): ?>
                            <a href="<?= htmlspecialchars($app['deed_file_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-bold rounded-pill">
                                <i class="fa-solid fa-file-pdf me-1"></i> عرض ملف الصك
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="compare-box">
                                <div class="compare-title"><i class="fa-solid fa-user text-primary"></i> بيانات حساب العميل</div>
                                <div class="compare-row"><span>الاسم</span><span><?= htmlspecialchars($app['customer_name']) ?></span></div>
                                <div class="compare-row"><span>رقم الهوية</span><span dir="ltr"><?= htmlspecialchars($app['customer_national_id']) ?></span></div>
                                <div class="compare-row"><span>الجوال</span><span dir="ltr"><?= htmlspecialchars($app['phone_number']) ?></span></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="compare-box">
                                <div class="compare-title"><i class="fa-solid fa-scale-balanced text-warning"></i> بيانات سجل وزارة العدل (MOJ)</div>
                                <?php $nameMismatch = $app['moj_owner_name'] !== $app['customer_name']; ?>
                                <div class="compare-row <?= $nameMismatch ? 'mismatch' : 'match' ?>">
                                    <span>اسم المالك بالصك</span><span><?= htmlspecialchars($app['moj_owner_name'] ?? 'غير موجود') ?></span>
                                </div>
                                <div class="compare-row"><span>رقم الهوية بالصك</span><span dir="ltr"><?= htmlspecialchars($app['moj_owner_national_id'] ?? '-') ?></span></div>
                                <div class="compare-row"><span>مساحة الأرض</span><span><?= htmlspecialchars($app['land_area'] ?? '-') ?> م²</span></div>
                            </div>
                        </div>
                    </div>

                    <?php if($nameMismatch): ?>
                    <div class="alert alert-warning fw-bold small mb-3"><i class="fa-solid fa-triangle-exclamation me-1"></i> يوجد اختلاف بالاسم بين حساب العميل وسجل وزارة العدل. يرجى المراجعة اليدوية قبل اتخاذ القرار.</div>
                    <?php endif; ?>

                    <div class="d-flex gap-3 flex-wrap align-items-center">
                        <form method="POST" onsubmit="return confirm('تأكيد الموافقة على هذا الطلب وتحويله للفحص الميداني؟');">
                            <input type="hidden" name="app_id" value="<?= $app['app_id'] ?>">
                            <input type="hidden" name="cty_id" value="<?= $app['cty_id'] ?>">
                            <button type="submit" name="approve_app" class="btn-approve"><i class="fa-solid fa-check me-1"></i> موافقة وتحويل للفحص</button>
                        </form>

                        <form method="POST" class="d-flex gap-2 flex-grow-1" style="min-width:300px;">
                            <input type="hidden" name="app_id" value="<?= $app['app_id'] ?>">
                            <input type="text" name="rejection_reason" class="form-control" placeholder="اكتب سبب الرفض هنا..." required>
                            <button type="submit" name="reject_app" class="btn-reject"><i class="fa-solid fa-xmark me-1"></i> رفض</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>