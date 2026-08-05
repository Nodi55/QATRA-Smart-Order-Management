<?php
// =========================================================================
// poll_updates.php
// نقطة نهاية خفيفة يستدعيها الفرونت إند كل بضع ثوانٍ (Polling) لمحاكاة
// التتبع الحي لدورة حياة الطلب بأسلوب تطبيقات توصيل الطلبات:
// "جاري التحضير" -> "جاري التوصيل" -> "تم التوصيل"
// هنا تقابلها: "قيد المراجعة" -> "جاري الفحص" -> "بانتظار السداد" ->
// "جاري التركيب" -> "مكتمل ومفعّل"
//
// تُرجع هذه النقطة حالة كل طلبات العميل الحالية + أي إشعارات جديدة لم
// يستلمها المتصفح بعد، ليقوم الجافاسكربت بتحديث الواجهة والإشعارات
// فوراً دون الحاجة لتحديث الصفحة يدوياً.
// =========================================================================

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'db_connect.php';

if (!isset($_SESSION['customer_national_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'الجلسة منتهية، يرجى تسجيل الدخول من جديد.']);
    exit;
}

$nationalId = $_SESSION['customer_national_id'];

function cleanServiceName($name) {
    if (strpos($name, 'مياه وصرف') !== false) return 'مياه وصرف';
    if (strpos($name, 'مياه') !== false) return 'مياه';
    if (strpos($name, 'صرف') !== false) return 'صرف';
    return $name;
}

try {
    $custStmt = $pdo->prepare("SELECT cust_id FROM customer WHERE national_id = ?");
    $custStmt->execute([$nationalId]);
    $custId = $custStmt->fetchColumn();

    if (!$custId) {
        echo json_encode(['status' => 'error', 'message' => 'تعذر العثور على بيانات المستفيد.']);
        exit;
    }

    // آخر رقم إشعار وصل للمتصفح مسبقاً؛ نُرجع فقط ما هو أحدث منه
    $lastNotifId = isset($_GET['last_notif_id']) ? (int)$_GET['last_notif_id'] : 0;

    // 1) حالة جميع طلبات العميل الحالية (لمقارنتها في الفرونت إند وتحديد ما تغيّر)
    $appStmt = $pdo->prepare("
        SELECT a.app_id, a.app_status, a.deed_no, a.created_at, s.srv_name, c.cty_name,
               i.amount, i.payment_status
        FROM application a
        JOIN city c ON a.cty_id = c.cty_id
        JOIN service_type s ON a.srv_id = s.srv_id
        LEFT JOIN invoice i ON a.app_id = i.app_id
        WHERE a.cust_id = ?
        ORDER BY a.created_at DESC
    ");
    $appStmt->execute([$custId]);
    $apps = $appStmt->fetchAll(PDO::FETCH_ASSOC);

    $applications = array_map(function ($app) {
        return [
            'app_id'          => (int)$app['app_id'],
            'app_status'      => $app['app_status'],
            'deed_no'         => $app['deed_no'],
            'srv_name_clean'  => cleanServiceName($app['srv_name']),
            'city_clean'      => str_replace('مدينة ', '', $app['cty_name']),
            'amount'          => $app['amount'] !== null ? (float)$app['amount'] : null,
            'payment_status'  => $app['payment_status'],
        ];
    }, $apps);

    // 2) أي إشعارات جديدة وصلت منذ آخر استطلاع للمتصفح
    $notifStmt = $pdo->prepare("
        SELECT notif_id, message_content, created_at
        FROM notification
        WHERE cust_id = ? AND notif_id > ?
        ORDER BY notif_id DESC
    ");
    $notifStmt->execute([$custId, $lastNotifId]);
    $newNotifs = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    $newNotifications = array_map(function ($n) {
        return [
            'notif_id'        => (int)$n['notif_id'],
            'message_content' => $n['message_content'],
            'created_at'      => $n['created_at'],
        ];
    }, $newNotifs);

    echo json_encode([
        'status'             => 'success',
        'applications'       => $applications,
        'new_notifications'  => $newNotifications,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'تعذر جلب التحديثات الحية حالياً.']);
}