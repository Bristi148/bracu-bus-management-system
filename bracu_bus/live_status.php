<?php

session_start();
require_once 'db_config.php';
requireLogin();

$conn = getDB();
$today = date('Y-m-d');

$stmt = $conn->query(
    "SELECT t.Trip_ID, t.Departure_Time, t.Arrived_Time, t.Status, t.Date,
            b.Bus_Num, b.Total_Seat, r.Covered_Area, r.Stops,
            COALESCE(SUM(bk.Booked_Seat),0) as booked_seats
     FROM Trip t
     JOIN Bus b ON t.Bus_ID = b.Bus_ID
     JOIN Route r ON b.Route_ID = r.Route_ID
     LEFT JOIN Booking bk ON bk.Trip_ID = t.Trip_ID
     WHERE t.Date = '$today'
     GROUP BY t.Trip_ID, b.Bus_Num, b.Total_Seat, r.Covered_Area, r.Stops
     ORDER BY t.Departure_Time ASC"
);
$trips = $stmt->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Live Bus Status - BRACU Bus</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; color: #333; }
nav {
    background: linear-gradient(135deg, #1a3a5c, #0d5c2e);
    color: white; padding: 0 24px;
    display: flex; align-items: center; justify-content: space-between;
    height: 60px; box-shadow: 0 2px 10px rgba(0,0,0,0.2);
}
nav .brand { font-size: 18px; font-weight: 700; }
nav a { color: white; text-decoration: none; font-size: 13px; padding: 6px 14px; border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; }
.page { max-width: 860px; margin: 0 auto; padding: 24px 20px; }
.page-title { font-size: 22px; font-weight: 800; color: #1a3a5c; margin-bottom: 4px; }
.page-sub   { font-size: 13px; color: #888; margin-bottom: 22px; }
.live-dot { display: inline-block; width: 10px; height: 10px; background: #27ae60; border-radius: 50%; margin-right: 6px; animation: pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
th { background: #1a3a5c; color: white; text-align: left; padding: 12px 16px; font-size: 13px; font-weight: 700; }
td { padding: 12px 16px; border-bottom: 1px solid #f5f5f5; font-size: 13px; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f9fbff; }
.status { padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.st-scheduled { background: #dbeafe; color: #1e40af; }
.st-running   { background: #d1fae5; color: #065f46; }
.st-completed { background: #f3f4f6; color: #666; }
.st-cancelled { background: #fee2e2; color: #991b1b; }
.occ-bar { height: 6px; background: #eee; border-radius: 3px; margin-top: 4px; width: 80px; }
.occ-fill { height: 100%; border-radius: 3px; }
.refresh { font-size: 12px; color: #aaa; margin-top: 14px; text-align: center; }
</style>
</head>
<body>
<nav>
    <div class="brand">🚌 BRACU Bus System</div>
    <div style="display:flex;gap:10px;">
        <a href="wishlist.php">❤️ Wishlist</a>
        <a href="dashboard.php">← Dashboard</a>
    </div>
</nav>

<div class="page">
    <div class="page-title"><span class="live-dot"></span>Live Bus Status</div>
    <div class="page-sub">Today's schedule · <?= date('D, d M Y') ?></div>

    <table>
        <thead>
            <tr>
                <th>Bus</th>
                <th>Route</th>
                <th>Departure</th>
                <th>Arrival</th>
                <th>Occupancy</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($trips as $t):
                $remaining = $t['Total_Seat'] - $t['booked_seats'];
                $pct = $t['Total_Seat'] > 0 ? round(($t['booked_seats']/$t['Total_Seat'])*100) : 0;
                $bar_color = $pct >= 100 ? '#e74c3c' : ($pct >= 80 ? '#e67e22' : '#27ae60');
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($t['Bus_Num']) ?></strong></td>
                <td><?= htmlspecialchars($t['Covered_Area']) ?></td>
                <td><?= date('g:i A', strtotime($t['Departure_Time'])) ?></td>
                <td><?= date('g:i A', strtotime($t['Arrived_Time'])) ?></td>
                <td>
                    <span><?= $t['booked_seats'] ?>/<?= $t['Total_Seat'] ?></span>
                    <div class="occ-bar"><div class="occ-fill" style="width:<?= $pct ?>%;background:<?= $bar_color ?>"></div></div>
                </td>
                <td><span class="status st-<?= strtolower($t['Status']) ?>"><?= $t['Status'] ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="refresh">🔄 Auto-refreshes every 30 seconds · Last updated: <?= date('H:i:s') ?></div>
<script>setTimeout(()=>location.reload(), 30000);</script>
</div>
</body>
</html>