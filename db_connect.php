<?php
$host = 'localhost';
$dbname = 'qatra_db'; 
$username = 'root';   
$password = '';       

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("ÝÔá ÇáÇÊÕÇá ÈÞÇÚÏÉ ÇáÈíÇäÇÊ: " . $e->getMessage());
}
?>
