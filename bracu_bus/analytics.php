<?php

session_start();
require_once 'db_config.php';
requireLogin();

$conn = getDB();


$stats = $conn->query(
    "SELECT
        (SELECT COUNT(*) FROM Booking WHERE Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)) as total_bookings,
        (SELECT COUNT(*) FROM Booking WHERE Booking_ID IN (SELECT Booking_ID FROM Cancel)) as total_cancels,
        (SELECT COUNT(*) FROM Passenger) as total_passengers,
        (SELECT COALESCE(SUM(Amount),0) FROM Payment WHERE Status='Paid') as total_revenue,
        (SELECT COUNT(*) FROM Trip WHERE Date >= CURDATE()) as upcoming_trips,
        (SELECT COUNT(*) FROM has_wishlist) as total_wishlist
    "
)->fetch_assoc();


$route_occ = $conn->query(
    "SELECT r.Covered_Area,
            b.Total_Seat,
            COUNT(DISTINCT t.Trip_ID) as trip_count,
            COALESCE(SUM(bk.Booked_Seat),0) as total_booked,
            (COUNT(DISTINCT t.Trip_ID) * b.Total_Seat) as total_capacity,
            ROUND(COALESCE(SUM(bk.Booked_Seat),0) / NULLIF(COUNT(DISTINCT t.Trip_ID) * b.Total_Seat, 0) * 100, 1) as occ_pct
     FROM Route r
     JOIN Bus b ON b.Route_ID = r.Route_ID AND b.R_Flag = 1
     LEFT JOIN Trip t ON t.Bus_ID = b.Bus_ID
     LEFT JOIN Booking bk ON bk.Trip_ID = t.Trip_ID
         AND bk.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
     GROUP BY r.Covered_Area, b.Total_Seat
     ORDER BY occ_pct DESC"
)->fetch_all(MYSQLI_ASSOC);


$daily = $conn->query(
    "SELECT DATE(b.Date) as day, COUNT(*) as cnt,
            COALESCE(SUM(p.Amount),0) as revenue
     FROM Booking b
     LEFT JOIN Payment p ON p.Booking_ID = b.Booking_ID AND p.Status='Paid'
     WHERE b.Date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
       AND b.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
     GROUP BY DATE(b.Date)
     ORDER BY day ASC"
)->fetch_all(MYSQLI_ASSOC);


$days_map = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days_map[$d] = ['cnt' => 0, 'revenue' => 0];
}
foreach ($daily as $d) $days_map[$d['day']] = ['cnt' => $d['cnt'], 'revenue' => $d['revenue']];


$bus_stats = $conn->query(
    "SELECT b.Bus_Num, b.Total_Seat, b.E_Flag, r.Covered_Area,
            COUNT(DISTINCT t.Trip_ID) as trips,
            COALESCE(SUM(bk.Booked_Seat),0) as total_booked,
            ROUND(COALESCE(SUM(bk.Booked_Seat),0) / NULLIF(COUNT(DISTINCT t.Trip_ID)*b.Total_Seat,0)*100,1) as avg_occ
     FROM Bus b
     JOIN Route r ON b.Route_ID = r.Route_ID
     LEFT JOIN Trip t ON t.Bus_ID = b.Bus_ID
     LEFT JOIN Booking bk ON bk.Trip_ID = t.Trip_ID
         AND bk.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
     GROUP BY b.Bus_Num, b.Total_Seat, b.E_Flag, r.Covered_Area
     ORDER BY avg_occ DESC"
)->fetch_all(MYSQLI_ASSOC);


$types = $conn->query(
    "SELECT p.type, COUNT(DISTINCT b.Passenger_ID) as cnt
     FROM Booking b JOIN Passenger p ON b.Passenger_ID = p.id
     WHERE b.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
     GROUP BY p.type"
)->fetch_all(MYSQLI_ASSOC);


$pay_methods = $conn->query(
    "SELECT Payment_Method, COUNT(*) as cnt, SUM(Amount) as total
     FROM Payment WHERE Status='Paid'
     GROUP BY Payment_Method"
)->fetch_all(MYSQLI_ASSOC);

$conn->close();


$chart_labels  = array_map(fn($d) => date('d M', strtotime($d)), array_keys($days_map));
$chart_bookings = array_column(array_values($days_map), 'cnt');
$chart_revenue  = array_column(array_values($days_map), 'revenue');
$max_daily = max(array_merge($chart_bookings, [1]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics - BRACU Bus</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; color: #333; }
nav {
    background: linear-gradient(135deg, #1a3a5c, #0d5c2e);
    color: white; padding: 0 24px;
    display: flex; align-items: center; justify-content: space-between;
    height: 60px; box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    position: sticky; top: 0; z-index: 100;
}
nav .brand { font-size: 18px; font-weight: 700; }
nav .nav-links { display: flex; gap: 10px; }
nav a { color: white; text-decoration: none; font-size: 13px; padding: 6px 14px; border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; }
.page { max-width: 1000px; margin: 0 auto; padding: 24px 20px; }
.page-title { font-size: 22px; font-weight: 800; color: #1a3a5c; margin-bottom: 4px; }
.page-sub   { font-size: 13px; color: #888; margin-bottom: 22px; }

/* Stat cards */
.stats { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 28px; }
.stat-card {
    background: white; border-radius: 12px; padding: 18px 20px;
    flex: 1; min-width: 130px; box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    border-top: 3px solid #1a3a5c;
}
.stat-card .num { font-size: 28px; font-weight: 800; color: #1a3a5c; }
.stat-card .lbl { font-size: 12px; color: #888; margin-top: 4px; }
.stat-card .delta { font-size: 11px; color: #27ae60; margin-top: 2px; }

/* Grid layout */
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
@media(max-width:680px){ .grid2 { grid-template-columns: 1fr; } }

/* Cards */
.card { background: white; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; overflow: hidden; }
.card-header { padding: 16px 22px; border-bottom: 1px solid #f0f0f0; }
.card-header h3 { font-size: 15px; font-weight: 700; color: #1a3a5c; }
.card-header p  { font-size: 12px; color: #888; margin-top: 2px; }
.card-body { padding: 20px 22px; }

/* Bar chart */
.bar-chart { display: flex; align-items: flex-end; gap: 8px; height: 140px; padding-bottom: 24px; position: relative; }
.bar-wrap { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; }
.bar {
    width: 100%; border-radius: 6px 6px 0 0;
    min-height: 4px; transition: height 0.3s;
    position: relative; cursor: pointer;
}
.bar:hover::after {
    content: attr(data-val);
    position: absolute; top: -24px; left: 50%; transform: translateX(-50%);
    background: #333; color: white; padding: 3px 7px; border-radius: 4px;
    font-size: 11px; white-space: nowrap;
}
.bar-label { font-size: 10px; color: #aaa; margin-top: 6px; text-align: center; }

/* Route occupancy */
.route-row { padding: 12px 0; border-bottom: 1px solid #f5f5f5; }
.route-row:last-child { border-bottom: none; }
.route-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.route-name { font-size: 14px; font-weight: 700; }
.route-pct  { font-size: 14px; font-weight: 800; }
.occ-bar { height: 10px; background: #eee; border-radius: 5px; overflow: hidden; }
.occ-fill { height: 100%; border-radius: 5px; transition: width 0.5s; }

/* Bus table */
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; padding: 10px 12px; background: #f8f9fa; color: #555; font-weight: 700; border-bottom: 2px solid #eee; }
td { padding: 10px 12px; border-bottom: 1px solid #f5f5f5; }
tr:last-child td { border-bottom: none; }
.extra-tag { background: #fff3cd; color: #856404; padding: 2px 7px; border-radius: 5px; font-size: 10px; font-weight: 700; }
.occ-chip { padding: 3px 9px; border-radius: 8px; font-size: 12px; font-weight: 700; }
.occ-high { background: #fee2e2; color: #991b1b; }
.occ-med  { background: #fff3cd; color: #856404; }
.occ-low  { background: #d4edda; color: #155724; }

/* Pie-like donut */
.donut-row { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
.legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; margin-bottom: 8px; }
.legend-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
.big-pct { font-size: 32px; font-weight: 800; color: #1a3a5c; }
</style>
</head>
<body>
<nav>
    <div class="brand">🚌 BRACU Bus System</div>
    <div class="nav-links">
        <a href="demand_poll.php">📊 Demand Poll</a>
        <?php if ($_SESSION['type']==='Faculty'): ?>
        <a href="admin.php">⚙️ Admin</a>
        <?php endif; ?>
        <a href="dashboard.php">← Dashboard</a>
    </div>
</nav>

<div class="page">
    <div class="page-title">📊 Bus Occupancy Analytics</div>
    <div class="page-sub">System-wide stats · Updated on page load</div>

    <!-- Key stats -->
    <div class="stats">
        <div class="stat-card">
            <div class="num"><?= $stats['total_bookings'] ?></div>
            <div class="lbl">Active Bookings</div>
        </div>
        <div class="stat-card">
            <div class="num"><?= $stats['total_cancels'] ?></div>
            <div class="lbl">Cancellations</div>
        </div>
        <div class="stat-card">
            <div class="num"><?= $stats['total_passengers'] ?></div>
            <div class="lbl">Passengers</div>
        </div>
        <div class="stat-card">
            <div class="num">৳<?= number_format($stats['total_revenue']) ?></div>
            <div class="lbl">Revenue (Paid)</div>
        </div>
        <div class="stat-card">
            <div class="num"><?= $stats['upcoming_trips'] ?></div>
            <div class="lbl">Upcoming Trips</div>
        </div>
        <div class="stat-card">
            <div class="num"><?= $stats['total_wishlist'] ?></div>
            <div class="lbl">Waitlist Entries</div>
        </div>
    </div>

    <div class="grid2">
        <!-- Daily bookings chart -->
        <div class="card">
            <div class="card-header">
                <h3>📅 Daily Bookings (Last 7 Days)</h3>
                <p>Number of seats booked per day</p>
            </div>
            <div class="card-body">
                <div class="bar-chart">
                    <?php foreach ($days_map as $day => $d):
                        $h = $max_daily > 0 ? round(($d['cnt']/$max_daily)*110) : 4;
                        $h = max($h, 4);
                    ?>
                    <div class="bar-wrap">
                        <div class="bar" style="height:<?= $h ?>px;background:linear-gradient(180deg,#1a3a5c,#0d5c2e);"
                             data-val="<?= $d['cnt'] ?> bookings"></div>
                        <div class="bar-label"><?= date('d M', strtotime($day)) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Route occupancy -->
        <div class="card">
            <div class="card-header">
                <h3>🗺️ Route Occupancy Rate</h3>
                <p>Average seat fill rate per route</p>
            </div>
            <div class="card-body">
                <?php foreach ($route_occ as $r):
                    $pct = min((float)$r['occ_pct'], 100);
                    $color = $pct >= 80 ? '#e74c3c' : ($pct >= 50 ? '#e67e22' : '#27ae60');
                ?>
                <div class="route-row">
                    <div class="route-top">
                        <span class="route-name">📍 <?= htmlspecialchars($r['Covered_Area']) ?></span>
                        <span class="route-pct" style="color:<?= $color ?>"><?= $pct ?>%</span>
                    </div>
                    <div class="occ-bar">
                        <div class="occ-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                    </div>
                    <div style="font-size:11px;color:#aaa;margin-top:4px;"><?= $r['total_booked'] ?> booked / <?= $r['total_capacity'] ?> capacity across <?= $r['trip_count'] ?> trips</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="grid2">
        <!-- Passenger type -->
        <div class="card">
            <div class="card-header">
                <h3>👥 Passenger Type Breakdown</h3>
                <p>Students vs Faculty using the service</p>
            </div>
            <div class="card-body">
                <?php
                $total_p = array_sum(array_column($types, 'cnt')) ?: 1;
                $colors  = ['Student' => '#1a3a5c', 'Faculty' => '#0d5c2e'];
                foreach ($types as $t):
                    $pct = round($t['cnt']/$total_p*100);
                ?>
                <div style="margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                        <span style="font-size:14px;font-weight:700;"><?= $t['type'] ?></span>
                        <span style="font-size:14px;font-weight:800;color:<?= $colors[$t['type']] ?? '#333' ?>"><?= $pct ?>% · <?= $t['cnt'] ?> users</span>
                    </div>
                    <div class="occ-bar" style="height:14px;">
                        <div class="occ-fill" style="width:<?= $pct ?>%;background:<?= $colors[$t['type']] ?? '#333' ?>"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Payment breakdown -->
        <div class="card">
            <div class="card-header">
                <h3>💳 Payment Method Breakdown</h3>
                <p>Revenue by payment type</p>
            </div>
            <div class="card-body">
                <?php
                $pay_colors = ['Online'=>'#3498db','bKash'=>'#e91e8c','Cash'=>'#27ae60'];
                $total_rev = array_sum(array_column($pay_methods, 'total')) ?: 1;
                foreach ($pay_methods as $pm):
                    $pct = round($pm['total']/$total_rev*100);
                ?>
                <div style="margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                        <span style="font-size:14px;font-weight:700;"><?= $pm['Payment_Method'] ?></span>
                        <span style="font-size:13px;color:#555;">৳<?= number_format($pm['total']) ?> · <?= $pm['cnt'] ?> transactions</span>
                    </div>
                    <div class="occ-bar" style="height:12px;">
                        <div class="occ-fill" style="width:<?= $pct ?>%;background:<?= $pay_colors[$pm['Payment_Method']] ?? '#999' ?>"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($pay_methods)): ?>
                    <p style="color:#bbb;text-align:center;padding:16px;">No paid transactions yet</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Per-bus table -->
    <div class="card">
        <div class="card-header">
            <h3>🚌 Per-Bus Performance</h3>
            <p>Occupancy and booking stats per bus</p>
        </div>
        <div class="card-body" style="overflow-x:auto;">
            <table>
                <thead>
                    <tr><th>Bus</th><th>Route</th><th>Trips</th><th>Seats/Trip</th><th>Total Booked</th><th>Avg Occupancy</th></tr>
                </thead>
                <tbody>
                <?php foreach ($bus_stats as $b):
                    $occ = (float)$b['avg_occ'];
                    $cls = $occ >= 80 ? 'occ-high' : ($occ >= 50 ? 'occ-med' : 'occ-low');
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($b['Bus_Num']) ?></strong>
                        <?php if ($b['E_Flag']): ?><span class="extra-tag">EXTRA</span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($b['Covered_Area']) ?></td>
                    <td><?= $b['trips'] ?></td>
                    <td><?= $b['Total_Seat'] ?></td>
                    <td><?= $b['total_booked'] ?></td>
                    <td><span class="occ-chip <?= $cls ?>"><?= $occ ?>%</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>