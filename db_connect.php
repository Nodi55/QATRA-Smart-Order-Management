<?php
// =====================================================================
// إعدادات الاتصال بقاعدة البيانات - نظام قطرة
// يعمل تلقائياً في وضعين:
//   1) على استضافة Clever Cloud   -> يقرأ بيانات الاتصال من متغيرات البيئة
//   2) على جهازك المحلي (XAMPP)   -> يستخدم الإعدادات الافتراضية أدناه
// =====================================================================

// محاولة قراءة بيانات الاتصال من متغيرات البيئة (تُضاف تلقائياً من Clever Cloud)
$envHost = getenv('MYSQL_ADDON_HOST');
$envDb   = getenv('MYSQL_ADDON_DB');
$envUser = getenv('MYSQL_ADDON_USER');
$envPass = getenv('MYSQL_ADDON_PASSWORD');
$envPort = getenv('MYSQL_ADDON_PORT') ?: '3306';

if ($envHost && $envDb && $envUser) {
    // -- وضع الإنتاج (Clever Cloud) --
    $host = $envHost;
    $db   = $envDb;
    $user = $envUser;
    $pass = $envPass;
    $port = $envPort;
} else {
    // -- وضع التطوير المحلي (XAMPP) --
    $host = '127.0.0.1';
    $db   = 'qatra_system';
    $user = 'root';
    $pass = '';
    $port = '3306';
}

// خيارات الاتصال لضمان الأمان واسترجاع البيانات بشكل صحيح (ولدعم اللغة العربية)
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // إنشاء الاتصال بقاعدة البيانات
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, $options);
} catch (\PDOException $e) {
    // رسالة تنبيه واضحة في حال تعطل الاتصال
    die("<h3 style='color:red; text-align:center; font-family:tahoma;'>❌ فشل الاتصال بقاعدة البيانات: " . $e->getMessage() . "</h3>");
}
?>