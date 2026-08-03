<?php
// إعدادات الاتصال بالسيرفر المحلي (XAMPP) لجهازك
$host = '127.0.0.1';
$db   = 'qatra_system'; // اسم قاعدة بيانات نظام قطرة التي أنشأناها واسترجعنا بياناتها
$user = 'root';         // اسم المستخدم الافتراضي
$pass = '';             // كلمة المرور (نتركها فارغة دائماً في السيرفر المحلي)

// خيارات الاتصال لضمان الأمان واسترجاع البيانات بشكل صحيح (ولدعم اللغة العربية)
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // إنشاء الاتصال بقاعدة البيانات
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, $options);
} catch (\PDOException $e) {
    // رسالة تنبيه واضحة في حال تعطل السيرفر المحلي
    die("<h3 style='color:red; text-align:center; font-family:tahoma;'>❌ فشل الاتصال بقاعدة البيانات: " . $e->getMessage() . "</h3>");
}
?>