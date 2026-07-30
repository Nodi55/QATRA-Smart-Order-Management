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
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ فشل الاتصال بقاعدة البيانات السحابية: " . $e->getMessage());
}

// =====================================================================
// التعبئة التلقائية للمناطق والمدن 
// =====================================================================
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'Region'")->rowCount();

    if ($tableExists > 0) {
        $regionCount = (int) $pdo->query('SELECT COUNT(*) FROM Region')->fetchColumn();

        if ($regionCount === 0) {

            $regionsAndCities = [
                'منطقة القصيم' => [
                    'بريدة', 'عنيزة', 'الرس', 'الربيعية', 'البكيرية',
                    
                ],
                'منطقة حائل' => [
                    'حائل', 'بقعاء', 'الشنان','الحائط'
                ],
                'الحدود الشمالية' => [
                    'عرعر', 'رفحاء', 'طريف'
                ],
                'منطقة الجوف' => [
                    'سكاكا', 'القريات', 'دومة الجندل', 'طبرجل'
                ]
            ];

            $pdo->beginTransaction();

            $insertRegion = $pdo->prepare('INSERT INTO Region (reg_name) VALUES (:reg_name)');
            $insertCity   = $pdo->prepare('INSERT INTO City (cty_name, reg_id) VALUES (:cty_name, :reg_id)');

            foreach ($regionsAndCities as $regionName => $cities) {
                $insertRegion->execute([':reg_name' => $regionName]);
                $regId = $pdo->lastInsertId();

                foreach ($cities as $cityName) {
                    $insertCity->execute([':cty_name' => $cityName, ':reg_id' => $regId]);
                }
            }

            $pdo->commit();
        }
    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
?>