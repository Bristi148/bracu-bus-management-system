<?php

session_start();
require_once 'db_config.php';
requireLogin();

$conn      = getDB();
$pid       = $_SESSION['passenger_id'];
$trip_id   = (int)$_GET['trip_id'];
$direction = $_GET['direction'] ?? 'home_to_bracu';
$source    = trim($_GET['source'] ?? '');
$dest      = trim($_GET['dest'] ?? '');
$date      = trim($_GET['date'] ?? date('Y-m-d'));


$stmt = $conn->prepare(
    "SELECT t.*, b.Bus_Num, b.Total_Seat,
            COALESCE(SUM(bk.Booked_Seat),0) as booked_seats
     FROM Trip t
     JOIN Bus b ON t.Bus_ID = b.Bus_ID
     LEFT JOIN Booking bk ON bk.Trip_ID = t.Trip_ID
         AND bk.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
     WHERE t.Trip_ID = ?
     GROUP BY t.Trip_ID, b.Bus_Num, b.Total_Seat"
);
$stmt->bind_param("i", $trip_id);
$stmt->execute();
$trip = $stmt->get_result()->fetch_assoc();

if (!$trip) { header("Location: dashboard.php"); exit(); }

$remaining = $trip['Total_Seat'] - $trip['booked_seats'];
$price = 100; 
$error     = '';
$success   = '';


define('SSL_STORE_ID',   'bracu69f7de7f9476e');
define('SSL_STORE_PASS', 'bracu69f7de7f9476e@ssl');
define('SSL_URL',        'https://sandbox.sslcommerz.com/gwprocess/v4/api.php');


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {

    if ($remaining <= 0) {
        $error = "Sorry, no seats available anymore.";
    } else {
        
        $dup = $conn->prepare("SELECT 1 FROM Booking WHERE Passenger_ID=? AND Trip_ID=?");
        $dup->bind_param("ii", $pid, $trip_id);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $error = "You already have a booking for this trip.";
        } else {
          
            $conn->begin_transaction();
            try {
                $ins = $conn->prepare(
                    "INSERT INTO Booking (Source, Destination, Date, time, Booked_Seat, Passenger_ID, Trip_ID)
                     VALUES (?,?,?,?,1,?,?)"
                );
                $dep_time = $trip['Departure_Time'];
                $ins->bind_param("sssiii", $source, $dest, $date, $dep_time, $pid, $trip_id);
                $ins->execute();
                $booking_id = $conn->insert_id;

               
                $ins2 = $conn->prepare(
                    "INSERT INTO Payment (Booking_ID, Payment_Method, Amount, Status, time, Date)
                     VALUES (?,'SSLCommerz',?,'Pending',CURTIME(),CURDATE())"
                );
                $ins2->bind_param("id", $booking_id, $price);
                $ins2->execute();

               
                $ins3 = $conn->prepare("INSERT INTO Make (P_ID, Bus_ID, Booking_ID) VALUES (?,?,?)");
                $ins3->bind_param("iii", $pid, $trip['Bus_ID'], $booking_id);
                $ins3->execute();

               
                $conn->prepare("DELETE FROM has_wishlist WHERE P_ID=? AND Trip_ID=?")->bind_param("ii", $pid, $trip_id);
                $conn->execute_query("DELETE FROM has_wishlist WHERE P_ID=? AND Trip_ID=?", [$pid, $trip_id]);

                $conn->commit();

               
                $tran_id = 'BRACU-' . $booking_id . '-' . time();
                $base_url = 'http://' . $_SERVER['HTTP_HOST'] . '/bracu_bus';

               
                $pass = $conn->prepare("SELECT Name, Email FROM Passenger WHERE id=?");
                $pass->bind_param("i", $pid);
                $pass->execute();
                $passenger = $pass->get_result()->fetch_assoc();

                $post_data = [
                    'store_id'          => SSL_STORE_ID,
                    'store_passwd'      => SSL_STORE_PASS,
                    'total_amount'      => $price,
                    'currency'          => 'BDT',
                    'tran_id'           => $tran_id,
                    'success_url'       => $base_url . '/payment_success.php',
                    'fail_url'          => $base_url . '/payment_fail.php',
                    'cancel_url'        => $base_url . '/payment_fail.php',
                    'ipn_url'           => $base_url . '/payment_success.php',
                    'cus_name'          => $passenger['Name'],
                    'cus_email'         => $passenger['Email'],
                    'cus_add1'          => 'BRACU, Dhaka',
                    'cus_city'          => 'Dhaka',
                    'cus_country'       => 'Bangladesh',
                    'cus_phone'         => '01700000000',
                    'shipping_method'   => 'NO',
                    'product_name'      => 'BRACU Bus Seat',
                    'product_category'  => 'Transport',
                    'product_profile'   => 'non-physical-goods',
                    'num_of_item'       => 1,
                    'emi_option'        => 0,
                ];

                $handle = curl_init();
                curl_setopt($handle, CURLOPT_URL, SSL_URL);
                curl_setopt($handle, CURLOPT_TIMEOUT, 30);
                curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($handle, CURLOPT_POST, 1);
                curl_setopt($handle, CURLOPT_POSTFIELDS, $post_data);
                curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
                $response = curl_exec($handle);
                $curl_err = curl_error($handle);
                curl_close($handle);

                if ($curl_err) {
                    $error = "Payment gateway connection failed: $curl_err. Please try again.";
                } else {
                    $sslRes = json_decode($response, true);
                    if (isset($sslRes['GatewayPageURL']) && $sslRes['GatewayPageURL']) {
                        
                        header("Location: " . $sslRes['GatewayPageURL']);
                        exit();
                    } else {
                        $error = "Payment gateway error: " . ($sslRes['failedreason'] ?? 'Unknown error');
                    }
                }

            } catch (Exception $e) {
                $conn->rollback();
                $error = "Booking failed. Please try again.";
            }
        }
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirm Booking - BRACU Bus</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; }
nav {
    background: linear-gradient(135deg, #1a3a5c, #0d5c2e);
    color: white; padding: 0 24px;
    display: flex; align-items: center; justify-content: space-between;
    height: 60px; box-shadow: 0 2px 10px rgba(0,0,0,0.2);
}
nav .brand { font-size: 18px; font-weight: 700; color: white; }
nav a { color: white; text-decoration: none; font-size: 13px; padding: 6px 14px; border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; }
.page { max-width: 600px; margin: 32px auto; padding: 0 20px; }
.card {
    background: white; border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    overflow: hidden; margin-bottom: 20px;
}
.card-header {
    background: linear-gradient(135deg, #1a3a5c, #0d5c2e);
    color: white; padding: 20px 24px;
}
.card-header h2 { font-size: 18px; }
.card-header p  { font-size: 13px; opacity: 0.85; margin-top: 4px; }
.card-body { padding: 24px; }
.trip-summary {
    background: #f0f7ff;
    border-radius: 10px; padding: 20px;
    margin-bottom: 20px; border-left: 4px solid #1a3a5c;
}
.trip-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.trip-row:last-child { margin-bottom: 0; }
.trip-row .lbl { font-size: 13px; color: #888; font-weight: 600; }
.trip-row .val { font-size: 14px; color: #333; font-weight: 600; }
.trip-highlight { font-size: 22px; font-weight: 800; color: #1a3a5c; }
.divider { border: none; border-top: 1px solid #eee; margin: 16px 0; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 13px; font-weight: 700; color: #555; margin-bottom: 8px; }
.pay-options { display: flex; gap: 12px; }
.pay-opt {
    flex: 1; border: 2px solid #dde2ea; border-radius: 10px;
    padding: 14px; text-align: center; cursor: pointer;
    transition: all 0.15s;
}
.pay-opt:hover { border-color: #1a3a5c; }
.pay-opt input[type="radio"] { display: none; }
.pay-opt.selected { border-color: #1a3a5c; background: #e8f0fb; }
.pay-opt .icon { font-size: 24px; margin-bottom: 6px; }
.pay-opt .name { font-size: 13px; font-weight: 700; color: #333; }
.price-box {
    background: linear-gradient(135deg, #e8f5e9, #e3f2fd);
    border-radius: 10px; padding: 16px 20px;
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px;
}
.price-box .lbl { font-size: 14px; color: #555; }
.price-box .amount { font-size: 28px; font-weight: 800; color: #1a3a5c; }
.alert-error { background: #ffeaea; color: #c0392b; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; border-left: 4px solid #e74c3c; }
.btn-confirm {
    width: 100%; padding: 14px; background: linear-gradient(135deg, #1a3a5c, #0d5c2e);
    color: white; border: none; border-radius: 10px; font-size: 16px;
    font-weight: 700; cursor: pointer; transition: opacity 0.2s;
}
.btn-confirm:hover { opacity: 0.88; }
.btn-back { display: block; text-align: center; margin-top: 12px; color: #888; font-size: 13px; text-decoration: none; }
.seat-warning { background: #fff3cd; color: #856404; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
</style>
</head>
<body>
<nav>
    <div class="brand">🚌 BRACU Bus System</div>
    <div style="display:flex;gap:10px;">
        <a href="wishlist.php">❤️ Wishlist</a>
        <a href="javascript:history.back()">← Back</a>
    </div>
</nav>

<div class="page">
    <div class="card">
        <div class="card-header">
            <h2>✅ Confirm Your Booking</h2>
            <p>Review the details before confirming</p>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($remaining <= 3 && $remaining > 0): ?>
                <div class="seat-warning">⚡ Only <?= $remaining ?> seat(s) left! Book fast.</div>
            <?php endif; ?>

            <div class="trip-summary">
                <div class="trip-row">
                    <span class="lbl">Route</span>
                    <span class="trip-highlight"><?= htmlspecialchars($source) ?> → <?= htmlspecialchars($dest) ?></span>
                </div>
                <div class="trip-row">
                    <span class="lbl">Date</span>
                    <span class="val"><?= date('D, d M Y', strtotime($date)) ?></span>
                </div>
                <div class="trip-row">
                    <span class="lbl">Departure</span>
                    <span class="val"><?= date('g:i A', strtotime($trip['Departure_Time'])) ?></span>
                </div>
                <div class="trip-row">
                    <span class="lbl">Arrival</span>
                    <span class="val"><?= date('g:i A', strtotime($trip['Arrived_Time'])) ?></span>
                </div>
                <div class="trip-row">
                    <span class="lbl">Bus</span>
                    <span class="val">🚌 <?= htmlspecialchars($trip['Bus_Num']) ?></span>
                </div>
                <div class="trip-row">
                    <span class="lbl">Seats Available</span>
                    <span class="val" style="color:<?= $remaining>5?'#27ae60':($remaining>0?'#e67e22':'#e74c3c') ?>">
                        <?= $remaining ?> / <?= $trip['Total_Seat'] ?>
                    </span>
                </div>
                <div class="trip-row">
                    <span class="lbl">Passenger</span>
                    <span class="val">👤 <?= htmlspecialchars($_SESSION['name']) ?> (<?= $_SESSION['type'] ?>)</span>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="confirm" value="1">

                <div class="price-box">
                    <div class="lbl">Total Amount<br>
                        <small style="color:#aaa;font-size:11px;">1 seat × ৳100</small>
                    </div>
                    <div class="amount">৳100</div>
                </div>

                <?php if ($price > 0): ?>
                <div style="background:#e8f4fd;border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:13px;color:#1a5276;">
                    💳 You will be redirected to <strong>SSLCommerz</strong> to complete payment.<br>
                    Supports: <strong>bKash · Nagad · Rocket · Visa · Mastercard</strong> and more.<br>
                    Your seat is reserved for <strong>10 minutes</strong> while you pay.
                </div>
                <?php endif; ?>

                <button type="submit" class="btn-confirm">Proceed to Payment →</button>
            </form>
            <a href="javascript:history.back()" class="btn-back">← Cancel and go back</a>
        </div>
    </div>
</div>
<script></script>
</body>
</html>