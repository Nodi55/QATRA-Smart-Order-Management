<?php
session_start();
require_once 'db_connect.php'; 

// التحقق من تسجيل الدخول (تم التعديل ليتناسب مع جلساتكم وملف الدخول index.php) [1, 2]
if (!isset($_SESSION['emp_id'])) {
    header("Location: index.php");
    exit();
}

$auditor_id = $_SESSION['emp_id']; 
$auditor_name = $_SESSION['emp_name'] ?? 'مدقق النظام';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['app_id'])) {
        $app_id = (int)$_POST['app_id'];
        try {
            $pdo->beginTransaction();
            
            // استخدام أسماء الجداول كما هي في قاعدة بياناتكم بالضبط (application, application_history) [3, 4]
            if ($_POST['action'] === 'approve') {
                $stmt1 = $pdo->prepare("UPDATE application SET app_status = 'Pending_Inspection' WHERE app_id = ?");
                $stmt1->execute([$app_id]);
                
                $stmt2 = $pdo->prepare("INSERT INTO application_history (app_id, status, changed_by, change_date) VALUES (?, 'Pending_Inspection', ?, NOW())");
                $stmt2->execute([$app_id, $auditor_id]);
                
                $msg = "<div class='alert success'><i class='fas fa-check-circle'></i> تم مطابقة الصك بنجاح للطلب رقم #$app_id وتم تحويله للفحص الميداني.</div>";
            } elseif ($_POST['action'] === 'reject') {
                $reason = htmlspecialchars($_POST['rejection_reason'] ?? 'مرفوض لعدم تطابق البيانات');
                
                $stmt1 = $pdo->prepare("UPDATE application SET app_status = 'Rejected' WHERE app_id = ?");
                $stmt1->execute([$app_id]);
                
                $stmt2 = $pdo->prepare("INSERT INTO application_history (app_id, status, rejection_reason, changed_by, change_date) VALUES (?, 'Rejected', ?, ?, NOW())");
                $stmt2->execute([$app_id, $reason, $auditor_id]);
                
                $msg = "<div class='alert error'><i class='fas fa-times-circle'></i> تم رفض الطلب رقم #$app_id وإشعار العميل.</div>";
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "<div class='alert error'><i class='fas fa-exclamation-triangle'></i> خطأ في النظام: " . $e->getMessage() . "</div>";
        }
    }
}

// جلب الطلبات المعلقة للمراجعة بالربط مع الجداول الصحيحة لديكم (application, customer, city) [3, 5, 6]
$query = "SELECT a.app_id, a.deed_no, a.deed_file_url, a.created_at, 
                 c.full_name, c.national_id, ct.cty_name 
          FROM application a 
          JOIN customer c ON a.cust_id = c.cust_id 
          JOIN city ct ON a.cty_id = ct.cty_id 
          WHERE a.app_status = 'Pending_Review' 
          ORDER BY a.created_at ASC";
$apps = $pdo->query($query)->fetchAll();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>بوابة التدقيق | QATRA</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #003366; --secondary: #005b9f; --bg: #f5f7fa; --text: #2c3e50; }
        body { margin: 0; font-family: 'Tajawal', sans-serif; background-color: var(--bg); display: flex; height: 100vh; overflow: hidden; }
        
        .sidebar { width: 260px; background: linear-gradient(180deg, var(--primary) 0%, var(--secondary) 100%); color: white; display: flex; flex-direction: column; box-shadow: 4px 0 15px rgba(0,0,0,0.1); z-index: 10; }
        .brand { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .brand h2 { margin: 0; font-weight: 800; font-size: 32px; letter-spacing: 1px; text-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        .brand p { margin: 5px 0 0; font-size: 13px; opacity: 0.8; }
        .user-info { padding: 20px; text-align: center; background: rgba(0,0,0,0.1); }
        .user-info i { font-size: 40px; margin-bottom: 10px; color: #fff; }
        .nav-links { list-style: none; padding: 0; margin: 20px 0; }
        .nav-links li a { display: block; padding: 15px 25px; color: rgba(255,255,255,0.8); text-decoration: none; font-size: 16px; transition: 0.3s; }
        .nav-links li a:hover, .nav-links li a.active { background: rgba(255,255,255,0.15); color: white; border-right: 4px solid #fff; font-weight: bold; }
        
        .main { flex: 1; padding: 40px; overflow-y: auto; }
        .header-title { color: var(--primary); font-size: 28px; font-weight: 800; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        
        .card { background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); padding: 30px; }
        .alert { padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; font-size: 15px; display: flex; align-items: center; gap: 10px; }
        .success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f8fafc; color: var(--primary); padding: 15px; text-align: right; font-size: 14px; font-weight: 700; border-bottom: 2px solid #e2e8f0; }
        td { padding: 15px; border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 14px; vertical-align: middle; }
        tr:hover { background: #fcfdfe; }
        
        .btn { padding: 8px 15px; border: none; border-radius: 6px; font-family: 'Tajawal'; font-weight: 700; font-size: 13px; cursor: pointer; transition: 0.3s; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
        .btn-view { background: #e0f2fe; color: #0284c7; } .btn-view:hover { background: #0284c7; color: white; }
        .btn-approve { background: #dcfce7; color: #166534; } .btn-approve:hover { background: #166534; color: white; }
        .btn-reject { background: #fee2e2; color: #991b1b; } .btn-reject:hover { background: #991b1b; color: white; }
        
        .action-box { display: flex; gap: 10px; align-items: center; }
        .reject-input { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: 'Tajawal'; display: none; width: 160px; outline: none; }
        .reject-input:focus { border-color: var(--secondary); }
    </style>
    <script>
        function openReject(id) {
            document.getElementById('btn_rej_' + id).style.display = 'none';
            document.getElementById('input_rej_' + id).style.display = 'block';
            document.getElementById('btn_confirm_' + id).style.display = 'inline-flex';
        }
    </script>
</head>
<body>
    <div class="sidebar">
        <div class="brand">
            <h2>قطرة</h2>
            <p>منصة تشغيلية ذكية ومؤتمتة</p>
        </div>
        <div class="user-info">
            <i class="fas fa-user-shield"></i>
            <h3 style="margin:0; font-size:16px;"><?= htmlspecialchars($auditor_name) ?></h3>
            <span style="font-size:12px; color:#93c5fd;">مدقق المستندات</span>
        </div>
        <ul class="nav-links">
            <li><a href="#" class="active"><i class="fas fa-file-signature"></i> مراجعة الصكوك</a></li>
            <li><a href="#"><i class="fas fa-history"></i> السجل التاريخي</a></li>
            <li><a href="index.php"><i class="fas fa-sign-out-alt"></i> تسجيل خروج</a></li>
        </ul>
    </div>

    <div class="main">
        <div class="header-title">
            <span>صندوق مراجعة الصكوك اليدوية (DSS Fallback)</span>
            <span style="font-size: 16px; color: #64748b; font-weight: 500;"><i class="fas fa-clock"></i> <?= date('Y-m-d') ?></span>
        </div>
        
        <?= $msg ?>

        <div class="card">
            <h3 style="margin-top: 0; color: var(--text);"><i class="fas fa-exclamation-circle" style="color: #f59e0b;"></i> طلبات تحتاج إلى مطابقة يدوية (<?= count($apps) ?>)</h3>
            
            <?php if (count($apps) > 0): ?>
                <table>
                    <tr>
                        <th>رقم الطلب</th>
                        <th>العميل</th>
                        <th>المدينة</th>
                        <th>رقم الصك المُدخل</th>
                        <th>المستند المرفق</th>
                        <th>الإجراءات</th>
                    </tr>
                    <?php foreach ($apps as $app): ?>
                        <tr>
                            <td><strong>#<?= $app['app_id'] ?></strong></td>
                            <td><?= htmlspecialchars($app['full_name']) ?><br><small style="color:#94a3b8;"><?= htmlspecialchars($app['national_id']) ?></small></td>
                            <td><?= htmlspecialchars($app['cty_name']) ?></td>
                            <td><span style="background:#fee2e2; color:#b91c1c; padding:3px 8px; border-radius:4px; font-weight:bold;"><?= htmlspecialchars($app['deed_no']) ?></span></td>
                            <td><a href="<?= htmlspecialchars($app['deed_file_url']) ?>" target="_blank" class="btn btn-view"><i class="fas fa-file-pdf"></i> عرض الصك</a></td>
                            <td>
                                <div class="action-box">
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="app_id" value="<?= $app['app_id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-approve" onclick="return confirm('تأكيد مطابقة الصك؟')"><i class="fas fa-check"></i> قبول</button>
                                    </form>
                                    
                                    <form method="POST" style="margin:0; display:flex; gap:5px;">
                                        <input type="hidden" name="app_id" value="<?= $app['app_id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="button" id="btn_rej_<?= $app['app_id'] ?>" class="btn btn-reject" onclick="openReject(<?= $app['app_id'] ?>)"><i class="fas fa-times"></i> رفض</button>
                                        <input type="text" name="rejection_reason" id="input_rej_<?= $app['app_id'] ?>" class="reject-input" placeholder="سبب الرفض..." required>
                                        <button type="submit" id="btn_confirm_<?= $app['app_id'] ?>" class="btn btn-reject" style="display:none;"><i class="fas fa-paper-plane"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #94a3b8;">
                    <i class="fas fa-check-double" style="font-size: 50px; margin-bottom: 15px; color:#cbd5e1;"></i>
                    <h2>صندوق التدقيق فارغ</h2>
                    <p>جميع الطلبات الحالية متطابقة آلياً عبر محرك DSS.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>