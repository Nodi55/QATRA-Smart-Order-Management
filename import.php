<?php
$host = '127.0.0.1';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // إنشاء قاعدة بيانات قطرة
    $pdo->exec("CREATE DATABASE IF NOT EXISTS qatra_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE qatra_system");

    $sql_file = 'qatra.sql.sql';
    if (!file_exists($sql_file)) {
        die("<h3 style='color:red; text-align:center;'>❌ لم يتم العثور على الملف!</h3>");
    }
    
    $sql_content = file_get_contents($sql_file);

    // 🌟 التنظيف الآلي: إزالة الأسطر المعقدة التي لا يفهمها XAMPP (MariaDB)
    $sql_content = preg_replace('/^\/\*\!50717.*$/m', '', $sql_content);
    $sql_content = preg_replace('/^\/\*\!50112.*$/m', '', $sql_content);

    // تنفيذ أوامر بناء الجداول بعد تنظيفها
    $pdo->exec($sql_content);

    echo "<div style='text-align:center; padding:50px; font-family:tahoma; background:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:10px; margin:50px;'>
            <h1>🎉 اكتمل الاسترجاع بنجاح!</h1>
            <h3>تم التخلص من الأخطاء، وبناء جميع الجداول واسترجاع بيانات العملاء والموظفين.</h3>
          </div>";

} catch (\PDOException $e) {
    echo "<h3 style='color:red; text-align:center;'>حدث خطأ: " . $e->getMessage() . "</h3>";
}
?>