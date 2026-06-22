<?php

session_start();
require_once 'db_config.php';
requireLogin();

$conn = getDB();
$pid  = $_SESSION['passenger_id'];


ensureTransferTable($conn);


$bcount = $conn->prepare("SELECT COUNT(*) as c FROM Booking WHERE Passenger_ID=? AND Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)");
$bcount->bind_param("i", $pid);
$bcount->execute();
$booking_count = $bcount->get_result()->fetch_assoc()['c'];


$tcount = $conn->prepare("SELECT COUNT(*) as c FROM seat_transfer WHERE to_passenger_id=? AND status='Pending'");
$tcount->bind_param("i", $pid);
$tcount->execute();
$transfer_count = $tcount->get_result()->fetch_assoc()['c'];


$recent = $conn->prepare(
    "SELECT b.Booking_ID, b.Source, b.Destination, b.Date, b.time, b.Booked_Seat,
            t.Departure_Time, t.Status as trip_status, bus.Bus_Num,
            p.Status as pay_status, p.Amount
     FROM Booking b
     JOIN Trip t ON b.Trip_ID = t.Trip_ID
     JOIN Bus bus ON t.Bus_ID = bus.Bus_ID
     LEFT JOIN Payment p ON p.Booking_ID = b.Booking_ID
     WHERE b.Passenger_ID = ?
     ORDER BY b.Date DESC, b.time DESC LIMIT 5"
);
$recent->bind_param("i", $pid);
$recent->execute();
$bookings = $recent->get_result()->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BRACU Bus - Dashboard</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; color: #333; }
nav {
    background: linear-gradient(135deg, #1a3a5c, #0d5c2e);
    color: white;
    padding: 0 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 60px;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
}
nav .brand { font-size: 18px; font-weight: 700; }
nav .brand span { font-size: 22px; margin-right: 6px; }
.nav-right { display: flex; align-items: center; gap: 16px; }
.nav-right .user { font-size: 13px; opacity: 0.9; }
.nav-right a {
    color: white; text-decoration: none; font-size: 13px;
    padding: 6px 14px; border: 1px solid rgba(255,255,255,0.4);
    border-radius: 20px; transition: background 0.2s;
}
.nav-right a:hover { background: rgba(255,255,255,0.15); }
.page { max-width: 900px; margin: 0 auto; padding: 28px 20px; }
h2 { font-size: 20px; color: #1a3a5c; margin-bottom: 6px; }
.sub { font-size: 14px; color: #888; margin-bottom: 24px; }
.stats { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px 24px;
    flex: 1; min-width: 140px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    text-align: center;
}
.stat-card .num { font-size: 32px; font-weight: 700; color: #1a3a5c; }
.stat-card .lbl { font-size: 13px; color: #888; margin-top: 4px; }

/* Direction cards */
.direction-section { margin-bottom: 32px; }
.direction-section h3 { font-size: 16px; color: #555; margin-bottom: 14px; font-weight: 600; }
.dir-cards { display: flex; gap: 16px; flex-wrap: wrap; }
.dir-card {
    background: white;
    border-radius: 14px;
    padding: 28px 24px;
    flex: 1; min-width: 240px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    cursor: pointer;
    transition: transform 0.15s, box-shadow 0.15s;
    text-decoration: none;
    color: inherit;
    border: 2px solid transparent;
    display: block;
}
.dir-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    border-color: #1a3a5c;
}
.dir-card .icon { font-size: 40px; margin-bottom: 14px; }
.dir-card h4 { font-size: 18px; font-weight: 700; color: #1a3a5c; margin-bottom: 6px; }
.dir-card .route { font-size: 13px; color: #888; }
.dir-card .badge {
    display: inline-block;
    margin-top: 10px;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
.badge-morning { background: #fff3cd; color: #856404; }
.badge-evening { background: #d4edda; color: #155724; }

/* Quick actions */
.actions { display: flex; gap: 12px; margin-bottom: 28px; flex-wrap: wrap; }
.action-btn {
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: opacity 0.2s;
}
.action-btn:hover { opacity: 0.85; }
.btn-primary { background: #1a3a5c; color: white; }
.btn-outline { background: white; color: #1a3a5c; border: 2px solid #1a3a5c; }
.btn-green  { background: #0d5c2e; color: white; }

/* Recent bookings table */
.card {
    background: white;
    border-radius: 12px;
    padding: 20px 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    margin-bottom: 24px;
}
.card h3 { font-size: 15px; color: #1a3a5c; margin-bottom: 14px; font-weight: 700; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; padding: 8px 10px; color: #888; font-weight: 600; border-bottom: 2px solid #f0f0f0; }
td { padding: 10px 10px; border-bottom: 1px solid #f5f5f5; }
tr:last-child td { border-bottom: none; }
.status { padding: 3px 9px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.st-scheduled { background: #dbeafe; color: #1e40af; }
.st-paid       { background: #d1fae5; color: #065f46; }
.st-pending    { background: #fef3c7; color: #92400e; }
.st-cancelled  { background: #fee2e2; color: #991b1b; }
.empty-msg { text-align: center; color: #bbb; padding: 24px; font-size: 14px; }
</style>
</head>
<body>
<nav>
    <div class="brand"><span>🚌</span> BRACU Bus System</div>
    <div class="nav-right">
        <span class="user">👤 <?= htmlspecialchars($_SESSION['name']) ?> (<?= $_SESSION['type'] ?>)</span>
        <a href="my_bookings.php">My Bookings</a>
        <?php if ($_SESSION['type']==='Faculty'): ?><a href="admin.php">⚙️ Admin</a><?php endif; ?>
        <a href="logout.php">Log Out</a>
    </div>
</nav>

<div class="page">
    <h2>Welcome back, <?= htmlspecialchars(explode(' ', $_SESSION['name'])[0]) ?>! 👋</h2>
    <p class="sub">Where would you like to go today?</p>

    <!-- Incoming seat transfer notification -->
    <?php if ($transfer_count > 0): ?>
    <div style="background:linear-gradient(135deg,#fff3cd,#fef9e7);border:2px solid #f59e0b;border-radius:12px;padding:16px 22px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:32px;">🔄</span>
            <div>
                <div style="font-size:15px;font-weight:800;color:#92400e;">
                    <?= $transfer_count ?> Seat Transfer Request<?= $transfer_count>1?'s':'' ?>!
                </div>
                <div style="font-size:13px;color:#78350f;margin-top:2px;">
                    Someone wants to give you their booked seat. Review and accept or decline.
                </div>
            </div>
        </div>
        <a href="seat_transfer.php" style="padding:10px 22px;background:#1a3a5c;color:white;border-radius:8px;text-decoration:none;font-size:14px;font-weight:700;">
            View Request<?= $transfer_count>1?'s':'' ?> →
        </a>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card">
            <div class="num"><?= $booking_count ?></div>
            <div class="lbl">Total Bookings</div>
        </div>
    </div>

    <!-- Direction Selection -->
    <div class="direction-section">
        <h3>📍 Choose Your Journey Direction</h3>
        <div class="dir-cards">
            <a class="dir-card" href="book_trip.php?direction=home_to_bracu">
                <div class="icon">🏠 → 🎓</div>
                <h4>Home → BRACU</h4>
                <div class="route">Select your boarding stoppage and travel to campus</div>
            </a>
            <a class="dir-card" href="book_trip.php?direction=bracu_to_home">
                <div class="icon">🎓 → 🏠</div>
                <h4>BRACU → Home</h4>
                <div class="route">Travel from campus to your destination area</div>
            </a>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="actions">
        <a href="my_bookings.php" class="action-btn btn-primary">📋 My Bookings</a>
        <a href="seat_transfer.php" class="action-btn btn-outline">🔄 Seat Transfer<?= $transfer_count>0?' ('.$transfer_count.')':'' ?></a>
        <a href="wishlist.php" class="action-btn btn-outline">❤️ Wishlist</a>
        <a href="demand_poll.php" class="action-btn btn-outline">🗳️ Demand Poll</a>
        <a href="analytics.php" class="action-btn btn-green">📊 Analytics</a>
        <a href="live_status.php" class="action-btn btn-green">🟢 Live Status</a>
    </div>

    <!-- Recent Bookings -->
    <div class="card">
        <h3>🕐 Recent Bookings</h3>
        <?php if (empty($bookings)): ?>
            <div class="empty-msg">No bookings yet. Book your first trip above! 🚌</div>
        <?php else: ?>
        <table>
            <tr>
                <th>Route</th><th>Date</th><th>Bus</th>
                <th>Departure</th><th>Status</th><th>Amount</th>
            </tr>
            <?php foreach ($bookings as $b): ?>
            <tr>
                <td><?= htmlspecialchars($b['Source']) ?> → <?= htmlspecialchars($b['Destination']) ?></td>
                <td><?= $b['Date'] ?></td>
                <td><?= htmlspecialchars($b['Bus_Num']) ?></td>
                <td><?= date('g:i A', strtotime($b['Departure_Time'])) ?></td>
                <td><span class="status st-<?= strtolower($b['trip_status']) ?>"><?= $b['trip_status'] ?></span></td>
                <td><?= $b['Amount'] ? '৳'.number_format($b['Amount'],2) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <div style="margin-top:12px; text-align:right;">
            <a href="my_bookings.php" style="font-size:13px;color:#1a3a5c;text-decoration:none;font-weight:600;">View all bookings →</a>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>