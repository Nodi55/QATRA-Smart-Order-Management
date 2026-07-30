<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once 'db_connect.php';

// التأكد من أنك مسجلة الدخول لربط الصكوك بهويتك
if (!isset($_SESSION['customer_national_id'])) {
    die("<h3 style='color:red; text-align:center; font-family:Cairo;'>الرجاء تسجيل الدخول أولاً في النظام.</h3>");
}

$nationalId = $_SESSION['customer_national_id'];
$customerName = $_SESSION['customer_name'];

// قائمة بصكوك تجريبية ذكية (مرتبطة بهويتك الحالية)
$mockDeeds = [
    [
        'deed_no' => '100100', // صك لمساحة صغيرة (السعر سيكون ثابت 3450)
        'area' => 500.00
    ],
    [
        'deed_no' => '200200', // صك لمساحة كبيرة (السعر سيكون ديناميكي)
        'area' => 850.50
    ],
    [
        'deed_no' => '300300', // صك إضافي للتجربة
        'area' => 675.00
    ]
];

$successCount = 0;
$errors = [];

foreach ($mockDeeds as $deed) {
    try {
        // المحاولة الأولى (الجدول بحروف كبيرة كما في بعض المخططات)
        $stmt = $pdo->prepare("INSERT INTO MOJ_Record (moj_deed_number, owner_national_id, owner_name, land_area) 
                               VALUES (:deed, :nid, :name, :area)");
        $stmt->execute([
            'deed' => $deed['deed_no'],
            'nid' => $nationalId,
            'name' => $customerName,
            'area' => $deed['area']
        ]);
        $successCount++;
    } catch (PDOException $e) {
        // إذا كان الصك موجود مسبقاً، نتجاهل الخطأ
        if ($e->getCode() == 23000) {
            $successCount++;
            continue; 
        }
        
        try {
            // المحاولة الثانية (الجدول بحروف صغيرة للسحابة)
            $stmt2 = $pdo->prepare("INSERT INTO moj_record (moj_deed_number, owner_national_id, owner_name, land_area) 
                                    VALUES (:deed, :nid, :name, :area)");
            $stmt2->execute([
                'deed' => $deed['deed_no'],
                'nid' => $nationalId,
                'name' => $customerName,
                'area' => $deed['area']
            ]);
            $successCount++;
        } catch (PDOException $e2) {
            if ($e2->getCode() != 23000) {
                $errors[] = $e2->getMessage();
            } else {
                $successCount++;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>توليد صكوك وهمية</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f4f8fb; text-align: center; padding: 50px; }
        .card { background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); max-width: 600px; margin: auto; border-top: 5px solid #27ae60; }
        .btn { background: #003366; color: white; padding: 10px 25px; text-decoration: none; border-radius: 10px; font-weight: bold; margin-top: 20px; display: inline-block; }
    </style>
</head>
<body>
    <div class="card">
        <?php if(empty($errors) && $successCount > 0): ?>
            <h2 style="color: #27ae60;">✅ تم إضافة الصكوك بنجاح!</h2>
            <p style="font-size: 1.2rem;">تم حقن قاعدة بيانات (وزارة العدل) بالصكوك التالية باسمك (<strong><?= htmlspecialchars($customerName) ?></strong>):</p>
            
            <table style="width: 100%; border-collapse: collapse; margin-top: 20px;" border="1">
                <tr style="background: #003366; color: white;">
                    <th style="padding: 10px;">رقم الصك (للتجربة)</th>
                    <th style="padding: 10px;">مساحة العقار</th>
                </tr>
                <tr><td style="padding: 10px; font-weight: bold; font-size: 1.2rem;">100100</td><td style="padding: 10px;">500 م²</td></tr>
                <tr><td style="padding: 10px; font-weight: bold; font-size: 1.2rem;">200200</td><td style="padding: 10px;">850.5 م²</td></tr>
                <tr><td style="padding: 10px; font-weight: bold; font-size: 1.2rem;">300300</td><td style="padding: 10px;">675 م²</td></tr>
            </table>

            <p style="margin-top: 20px; color: #666;">يمكنك الآن الذهاب لصفحة تقديم الطلب واستخدام أحد هذه الأرقام ليتم التحقق منها آلياً.</p>
        <?php else: ?>
            <h2 style="color: red;">⚠️ حدث خطأ</h2>
            <p>تأكدي من أن جدول MOJ_Record موجود في قاعدة البيانات.</p>
            <small><?= implode("<br>", $errors) ?></small>
        <?php endif; ?>
        
        <br>
        <a href="dashboard.php" class="btn">العودة للوحة التحكم</a>
    </div>
</body>
</html>