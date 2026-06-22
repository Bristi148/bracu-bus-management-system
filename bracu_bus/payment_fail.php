<?php

session_start();
require_once 'db_config.php';

$conn    = getDB();
$tran_id = $_POST['tran_id'] ?? $_GET['tran_id'] ?? '';


$booking_id = 0;
if (preg_match('/BRACU-(\d+)-/', $tran_id, $m)) {
    $booking_id = (int)$m[1];
}


if ($booking_id > 0) {
    $conn->execute_query("DELETE FROM Make    WHERE Booking_ID=?", [$booking_id]);
    $conn->execute_query("DELETE FROM Payment WHERE Booking_ID=?", [$booking_id]);
    $conn->execute_query("DELETE FROM Booking WHERE Booking_ID=?", [$booking_id]);
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment Failed - BRACU Bus</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8;
       display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
.card { background: white; border-radius: 16px; box-shadow: 0 8px 32px rgba(0,0,0,0.12);
        max-width: 440px; width: 100%; overflow: hidden; text-align: center; }
.card-top { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; padding: 36px 24px; }
.card-top .icon { font-size: 56px; margin-bottom: 12px; }
.card-top h1 { font-size: 24px; font-weight: 800; }
.card-top p  { font-size: 14px; opacity: 0.85; margin-top: 6px; }
.card-body { padding: 28px 32px; }
.card-body p { font-size: 14px; color: #555; margin-bottom: 24px; line-height: 1.6; }
.btn { display: block; padding: 13px; border-radius: 8px; text-decoration: none;
       font-weight: 700; font-size: 15px; margin-bottom: 12px; }
.btn-primary { background: linear-gradient(135deg,#1a3a5c,#0d5c2e); color: white; }
.btn-outline  { background: white; color: #1a3a5c; border: 2px solid #1a3a5c; }
</style>
</head>
<body>
<div class="card">
    <div class="card-top">
        <div class="icon">❌</div>
        <h1>Payment Failed</h1>
        <p>Your booking was not completed</p>
    </div>
    <div class="card-body">
        <p>
            Your payment was either cancelled or failed to process.<br>
            No money has been deducted. The seat has been released.
        </p>
        <a href="javascript:history.back()" class="btn btn-primary">Try Again</a>
        <a href="dashboard.php" class="btn btn-outline">Go to Dashboard</a>
    </div>
</div>
</body>
</html>