<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "qatra_db";

// Corrected: added '$' to conn and servername, and added space in 'new mysqli'
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>
<!DOCTYPE html>
<html>
<head>
<title>QATRA - Customer Portal</title>
<style>
body { font-family: Arial, sans-serif; background-color: #f4f7f6; text-align: center; margin-top: 50px; }
.container { background: white; padding: 20px; border-radius: 10px; width: 350px; margin: auto; box-shadow: 0px 0px 10px #ccc; }
input, select, button { width: 90%; margin: 10px 0; padding: 10px; border: 1px solid #ccc; border-radius: 5px; }
button { background-color: #0056b3; color: white; border: none; cursor: pointer; font-weight: bold; padding: 15px; margin-top: 15px;}
button:hover { background-color: #003d82; }
h2 { color: #0056b3; }
h3 { color: #333; font-size: 14px; text-align: left; margin-left: 5%; }
</style>
</head>
<body>
<div class="container">
<h2>QATRA System</h2>
<p style="color: green; font-size: 12px;">Database Connected Successfully ✓</p>
<form action="process.php" method="POST">

<h3>1. Identity Details</h3>
<input type="text" name="national_id" placeholder="National ID (e.g. 10xxxxxx)" required>
<input type="text" name="full_name" placeholder="Full Name" required>
<input type="text" name="phone_number" placeholder="Phone Number (e.g. 05xxxxxx)" required>

<h3>2. Application Details</h3>
<select name="service_type" required>
<option value="">Select Service...</option>
<option value="Water">Water Service</option>
<option value="Sewage">Sewage Service</option>
</select>
<input type="text" name="deed_number" placeholder="Deed Number" required>
<input type="text" name="region" placeholder="Region (e.g. Riyadh)" required>
<input type="text" name="city" placeholder="City" required>

<button type="submit">Submit Application</button>
</form>
</div>
</body>
</html>