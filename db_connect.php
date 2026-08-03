<?php
/**
 * =====================================================================
 * QATRA (قطرة) - ملف الاتصال بقاعدة البيانات السحابية (Clever Cloud)
 * =====================================================================
 */

$host     = 'bsfbmb13afxzn35nolyb-mysql.services.clever-cloud.com';
$port     = '3306';
$dbname   = 'bsfbmb13afxzn35nolyb';
$username = 'ubracuf3anbungl9';
$password = 'YEcp35Qxa68MIhQbUMDN';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT         => false
        ]
    );
} catch (PDOException $e) {
    die("❌ فشل الاتصال بقاعدة البيانات السحابية: " . $e->getMessage());
}
?>