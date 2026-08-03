<?php
// ملف setup.php - لتأسيس النظام لمرة واحدة
require_once 'db_connect.php';

try {
    // 1. إنشاء مدينة الرياض (كمقر افتراضي)
    $pdo->exec("INSERT IGNORE INTO region (reg_name) VALUES ('منطقة الرياض')");
    $pdo->exec("INSERT IGNORE INTO city (cty_name, reg_id) VALUES ('مدينة الرياض', 1)");

    // 2. إنشاء الصلاحيات الأساسية
    $roles = ['Admin', 'Auditor', 'Technician'];
    foreach ($roles as $role) {
        $pdo->prepare("INSERT IGNORE INTO system_role (role_name) VALUES (?)")->execute([$role]);
    }

    // 3. إنشاء حساب المدير العام (Super Admin)
    $adminEmail = 'admin@qatra.com';
    $password = password_hash('123456', PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("SELECT emp_id FROM company_employee WHERE emp_email = ?");
    $stmt->execute([$adminEmail]);
    if ($stmt->rowCount() == 0) {
        // إدخال المدير في جدول الموظفين
        $pdo->prepare("INSERT INTO company_employee (emp_name, emp_email, password_hash, cty_id) VALUES ('المدير العام', ?, ?, 1)")->execute([$adminEmail, $password]);
        $adminId = $pdo->lastInsertId();
        
        // ربط المدير بصلاحية Admin
        $stmtRole = $pdo->prepare("SELECT role_id FROM system_role WHERE role_name = 'Admin'");
        $stmtRole->execute();
        $roleId = $stmtRole->fetchColumn();
        
        $pdo->prepare("INSERT INTO employee_roles (emp_id, role_id) VALUES (?, ?)")->execute([$adminId, $roleId]);
        
        echo "<h2 style='color:green; text-align:center; margin-top:50px;'>✅ تم تأسيس النظام بنجاح!</h2>";
        echo "<p style='text-align:center;'>إيميل المدير: <b>admin@qatra.com</b> | الرمز: <b>123456</b></p>";
    } else {
        echo "<h2 style='color:blue; text-align:center; margin-top:50px;'>ℹ️ حساب المدير موجود مسبقاً.</h2>";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>