<?php

session_start();
require_once 'db_config.php';
requireLogin();

$conn = getDB();
$pid  = $_SESSION['passenger_id'];
$booking_id = (int)($_GET['booking_id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT b.*, t.Departure_Time, t.Arrived_Time, t.Status as trip_status, t.Date as trip_date,
            bus.Bus_Num, r.Covered_Area, r.Stops,
            p.Amount, p.Status as pay_status, p.Payment_Method
     FROM Booking b
     JOIN Trip t ON b.Trip_ID = t.Trip_ID
     JOIN Bus bus ON t.Bus_ID = bus.Bus_ID
     JOIN Route r ON bus.Route_ID = r.Route_ID
     LEFT JOIN Payment p ON p.Booking_ID = b.Booking_ID
     WHERE b.Booking_ID = ? AND b.Passenger_ID = ?"
);
$stmt->bind_param("ii", $booking_id, $pid);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$conn->close();

if (!$booking) { header("Location: dashboard.php"); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking Confirmed! - BRACU Bus</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8;
       display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
.card { background: white; border-radius: 16px; box-shadow: 0 8px 32px rgba(0,0,0,0.12);
        max-width: 500px; width: 100%; overflow: hidden; }
.card-top { background: linear-gradient(135deg, #27ae60, #0d5c2e); color: white; text-align: center; padding: 36px 24px 28px; }
.check { font-size: 56px; margin-bottom: 12px; }
.card-top h1 { font-size: 24px; font-weight: 800; }
.card-top p  { font-size: 14px; opacity: 0.85; margin-top: 6px; }
.bid-box { text-align: center; background: #f0f7ff; border-radius: 10px; padding: 16px; margin: 20px 24px 0; }
.bid-box .num { font-size: 30px; font-weight: 800; color: #1a3a5c; letter-spacing: 3px; }
.bid-box .lbl { font-size: 12px; color: #aaa; margin-top: 4px; }
.card-body { padding: 20px 24px 28px; }
.detail-row { display: flex; justify-content: space-between; align-items: flex-start;
              padding: 11px 0; border-bottom: 1px solid #f0f0f0; }
.detail-row:last-of-type { border-bottom: none; }
.detail-row .lbl { font-size: 13px; color: #888; display: flex; align-items: center; gap: 6px; }
.detail-row .val { font-size: 13px; font-weight: 700; color: #333; text-align: right; max-width: 60%; }
.pay-badge { background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 8px;
             font-size: 12px; font-weight: 700; }
.actions { display: flex; gap: 10px; margin-top: 22px; flex-wrap: wrap; }
.btn { flex: 1; padding: 12px; border-radius: 8px; text-align: center; text-decoration: none;
       font-weight: 700; font-size: 14px; min-width: 120px; }
.btn-primary { background: linear-gradient(135deg,#1a3a5c,#0d5c2e); color: white; }
.btn-outline  { background: white; color: #1a3a5c; border: 2px solid #1a3a5c; }
.divider { border: none; border-top: 1px solid #f0f0f0; margin: 16px 0; }
</style>
</head>
<body>
<div class="card">
    <div class="card-top">
        <div class="check">✅</div>
        <h1>Booking Confirmed!</h1>
        <p>Your seat has been successfully reserved</p>
    </div>

    <div class="bid-box">
        <div class="num">#<?= str_pad($booking_id, 6, '0', STR_PAD_LEFT) ?></div>
        <div class="lbl">Booking Reference</div>
    </div>

    <div class="card-body">
        <div class="detail-row">
            <span class="lbl">🚌 Bus</span>
            <span class="val"><?= htmlspecialchars($booking['Bus_Num']) ?></span>
        </div>
        <div class="detail-row">
            <span class="lbl">📍 Route</span>
            <span class="val"><?= htmlspecialchars($booking['Source']) ?> → <?= htmlspecialchars($booking['Destination']) ?></span>
        </div>
        <div class="detail-row">
            <span class="lbl">📅 Date</span>
            <span class="val"><?= date('D, d M Y', strtotime($booking['trip_date'])) ?></span>
        </div>
        <div class="detail-row">
            <span class="lbl">🕐 Departure</span>
            <span class="val"><?= date('g:i A', strtotime($booking['Departure_Time'])) ?></span>
        </div>
        <div class="detail-row">
            <span class="lbl">🏁 Arrival</span>
            <span class="val"><?= date('g:i A', strtotime($booking['Arrived_Time'])) ?></span>
        </div>
        <div class="detail-row">
            <span class="lbl">💰 Amount</span>
            <span class="val"><?= $booking['Amount'] > 0 ? '৳'.number_format($booking['Amount'],2) : '৳100.00' ?></span>
        </div>
        <div class="detail-row">
            <span class="lbl">💳 Payment</span>
            <span class="val">
                <?= htmlspecialchars($booking['Payment_Method'] ?? '—') ?>
                <span class="pay-badge">✓ <?= $booking['pay_status'] ?></span>
            </span>
        </div>
        <div class="detail-row">
            <span class="lbl">👤 Passenger</span>
            <span class="val"><?= htmlspecialchars($_SESSION['name']) ?></span>
        </div>

        <div class="actions">
            <a href="my_bookings.php" class="btn btn-primary">📋 My Bookings</a>
            <a href="wishlist.php"    class="btn btn-outline">❤️ Wishlist</a>
            <a href="dashboard.php"  class="btn btn-outline">🏠 Dashboard</a>
        </div>
    </div>
</div>
</body>
</html>