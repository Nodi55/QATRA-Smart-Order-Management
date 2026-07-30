
<?php
/**
 * =====================================================================
 * QATRA (قطرة) - ملف الاتصال بقاعدة البيانات
 * =====================================================================
 * ✅ هذا الملف يحتوي على تعبئة تلقائية للمناطق والمدن.
 *    ما تحتاجين تسوين أي شيء يدوي على أي جهاز - بمجرد ما أي صفحة
 *    تفتح وتتصل بقاعدة البيانات، النظام يتحقق تلقائياً:
 *    "هل جدول المناطق فاضي؟" ولو فاضي يعبّيه لحاله مرة واحدة فقط،
 *    وبعدها ما يكرر العملية أبداً (حتى لو فُتح الموقع آلاف المرات
 *    من أجهزة مختلفة).
 * =====================================================================
 */

$host     = 'localhost';
$dbname   = 'qatra_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
}

// =====================================================================
// التعبئة التلقائية للمناطق والمدن (تعمل مرة واحدة فقط تلقائياً)
// =====================================================================
try {
    // نتحقق أولاً: هل جدول Region فاضي؟ (يعني المشروع لسا ما انعبّى)
    $regionCount = (int) $pdo->query('SELECT COUNT(*) FROM Region')->fetchColumn();

    if ($regionCount === 0) {

        $regionsAndCities = [
            'منطقة القصيم' => [
                'بريدة', 'عنيزة', 'الرس', 'المذنب', 'البكيرية',
            'الربيعية ',
            ],
            'منطقة حائل' => [
                'حائل', 'بقعاء', 'الشنان', 'الغزالة', 'موقق', 'الحائط',
            ],
            'منطقة الحدود الشمالية' => [
                'عرعر', 'رفحاء', 'طريف',
            ],
            'منطقة الجوف' => [
                'سكاكا', 'القريات', 'دومة الجندل', 'طبرجل',
            ],
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

} catch (PDOException $e) {
    // لو صار خطأ في التعبئة التلقائية، لا نوقف الموقع بالكامل
    // (الصفحات الأخرى غير المرتبطة بالمدن تبقى تعمل بشكل طبيعي)
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
?>