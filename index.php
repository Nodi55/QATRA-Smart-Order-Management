&lt;?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "qatra_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn-&gt;connect_error) {
    die("Database connection failed: " . $conn-&gt;connect_error);
}
?&gt;
&lt;!DOCTYPE html&gt;
&lt;html&gt;
&lt;head&gt;
&lt;title&gt;QATRA - Customer Portal&lt;/title&gt;
&lt;style&gt;
body { font-family: Arial, sans-serif; background-color: #f4f7f6; text-align: center; margin-top: 50px; }
.container { background: white; padding: 20px; border-radius: 10px; width: 350px; margin: auto; box-shadow: 0px 0px 10px #ccc; }
input, select, button { width: 90%; margin: 10px 0; padding: 10px; border: 1px solid #ccc; border-radius: 5px; }
button { background-color: #0056b3; color: white; border: none; cursor: pointer; font-weight: bold; padding: 15px; margin-top: 15px;}
button:hover { background-color: #003d82; }
h2 { color: #0056b3; }
h3 { color: #333; font-size: 14px; text-align: left; margin-left: 5%; }
&lt;/style&gt;
&lt;/head&gt;
&lt;body&gt;
&lt;div class="container"&gt;
&lt;h2&gt;QATRA System&lt;/h2&gt;
&lt;p style="color: green; font-size: 12px;"&gt;Database Connected Successfully ✓&lt;/p&gt;
&lt;form action="process.php" method="POST"&gt;

&lt;h3&gt;1. Identity Details&lt;/h3&gt;
&lt;input type="text" name="national_id" placeholder="National ID (e.g. 10xxxxxx)" required&gt;
&lt;input type="text" name="full_name" placeholder="Full Name" required&gt;
&lt;input type="text" name="phone_number" placeholder="Phone Number (e.g. 05xxxxxx)" required&gt;

&lt;h3&gt;2. Application Details&lt;/h3&gt;
&lt;select name="service_type" required&gt;
&lt;option value=""&gt;Select Service...&lt;/option&gt;
&lt;option value="Water"&gt;Water Service&lt;/option&gt;
&lt;option value="Sewage"&gt;Sewage Service&lt;/option&gt;
&lt;/select&gt;
&lt;input type="text" name="deed_number" placeholder="Deed Number" required&gt;
&lt;input type="text" name="region" placeholder="Region (e.g. Riyadh)" required&gt;
&lt;input type="text" name="city" placeholder="City" required&gt;

&lt;button type="submit"&gt;Submit Application&lt;/button&gt;
&lt;/form&gt;
&lt;/div&gt;
&lt;/body&gt;
&lt;/html&gt;
