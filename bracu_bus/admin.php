<?php
// admin.php - Admin Panel
session_start();
require_once 'db_config.php';
if (file_exists(__DIR__ . '/mail_config.php')) require_once 'mail_config.php';
if (!function_exists('sendExtraBusNotification')) {
    function sendExtraBusNotification(...$args) { return false; }
}
requireLogin();

if ($_SESSION['type'] !== 'Faculty') {
    header("Location: dashboard.php"); exit();
}

$conn = getDB();
$msg  = '';

// ── Schedule Extra Bus ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_extra'])) {
    $bus_id    = (int)$_POST['bus_id'];
    $trip_date = $_POST['trip_date'];
    $dep_time  = $_POST['dep_time'];
    $arr_time  = $_POST['arr_time'];

    $chk = $conn->prepare("SELECT b.Bus_ID, b.Route_ID, b.Bus_Num, r.Covered_Area FROM Bus b JOIN Route r ON b.Route_ID=r.Route_ID WHERE b.Bus_ID=? AND b.E_Flag=1");
    $chk->bind_param("i", $bus_id);
    $chk->execute();
    $bus_row = $chk->get_result()->fetch_assoc();

    if ($bus_row) {
        $ins = $conn->prepare("INSERT INTO Trip (Arrived_Time,Departure_Time,Status,Bus_ID,Date) VALUES (?,?,'Scheduled',?,?)");
        $ins->bind_param("ssis", $arr_time, $dep_time, $bus_id, $trip_date);
        if ($ins->execute()) {
            $trip_id  = $conn->insert_id;
            $conn->execute_query("INSERT IGNORE INTO Wishlist (Trip_ID) VALUES (?)", [$trip_id]);

            // Email everyone who voted for this route
            $voters = $conn->prepare(
                "SELECT p.Name, p.Email FROM `Do` d
                 JOIN Demand_Poll dp ON d.D_ID = dp.Poll_ID
                 JOIN Passenger p ON d.P_ID = p.id
                 WHERE dp.Route_ID = ?"
            );
            $voters->bind_param("i", $bus_row['Route_ID']);
            $voters->execute();
            $voter_list = $voters->get_result()->fetch_all(MYSQLI_ASSOC);

            $sent = 0;
            foreach ($voter_list as $voter) {
                $ok = sendExtraBusNotification(
                    $voter['Email'], $voter['Name'],
                    $bus_row['Bus_Num'], $bus_row['Covered_Area'],
                    date('D, d M Y', strtotime($trip_date)),
                    date('g:i A', strtotime($dep_time)),
                    date('g:i A', strtotime($arr_time)),
                    'http://' . $_SERVER['HTTP_HOST'] . '/bracu_bus/book_trip.php?direction=home_to_bracu'
                );
                if ($ok) $sent++;
            }
            $note = count($voter_list) > 0 ? " Notified $sent/".count($voter_list)." voters via email." : " No voters to notify yet.";
            $msg = "success:Extra bus trip scheduled (Trip #$trip_id).$note";
        } else {
            $msg = "error:Scheduling failed. Please try again.";
        }
    } else {
        $msg = "error:Invalid bus selected.";
    }
}

// ── Cancel Extra Bus Trip ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_extra'])) {
    $trip_id = (int)$_POST['trip_id'];
    $chk = $conn->prepare("SELECT t.Trip_ID FROM Trip t JOIN Bus b ON t.Bus_ID=b.Bus_ID WHERE t.Trip_ID=? AND b.E_Flag=1");
    $chk->bind_param("i", $trip_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $conn->execute_query("UPDATE Trip SET Status='Cancelled' WHERE Trip_ID=?", [$trip_id]);
        $msg = "success:Extra bus trip #$trip_id cancelled — removed from booking page.";
    } else {
        $msg = "error:You can only cancel extra bus trips here.";
    }
}

// ── Update Regular Trip Status ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $trip_id    = (int)$_POST['trip_id'];
    $new_status = $_POST['new_status'];
    $conn->execute_query("UPDATE Trip SET Status=? WHERE Trip_ID=?", [$new_status, $trip_id]);
    $msg = "success:Trip #$trip_id updated to $new_status.";
}

// ── Fetch all data ─────────────────────────────────────────────────────────────
$demand = $conn->query(
    "SELECT r.Route_ID, r.Covered_Area, r.Stops, dp.Poll_ID,
            COALESCE(SUM(d.Count),0) as votes
     FROM Route r
     LEFT JOIN Demand_Poll dp ON dp.Route_ID = r.Route_ID
     LEFT JOIN `Do` d ON d.D_ID = dp.Poll_ID
     GROUP BY r.Route_ID, r.Covered_Area, r.Stops, dp.Poll_ID
     ORDER BY votes DESC"
)->fetch_all(MYSQLI_ASSOC);

$all_trips = $conn->query(
    "SELECT t.Trip_ID, t.Departure_Time, t.Arrived_Time, t.Status, t.Date,
            b.Bus_Num, b.Total_Seat, b.E_Flag, b.R_Flag, r.Covered_Area,
            COALESCE(SUM(bk.Booked_Seat),0) as booked
     FROM Trip t
     JOIN Bus b ON t.Bus_ID = b.Bus_ID
     JOIN Route r ON b.Route_ID = r.Route_ID
     LEFT JOIN Booking bk ON bk.Trip_ID = t.Trip_ID
         AND bk.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
     WHERE t.Date >= CURDATE()
     GROUP BY t.Trip_ID, b.Bus_Num, b.Total_Seat, b.E_Flag, b.R_Flag, r.Covered_Area
     ORDER BY b.E_Flag ASC, t.Date ASC, t.Departure_Time ASC"
)->fetch_all(MYSQLI_ASSOC);

$extra_trips   = array_values(array_filter($all_trips, fn($t) => $t['E_Flag'] == 1));
$regular_trips = array_values(array_filter($all_trips, fn($t) => $t['R_Flag'] == 1));

$extra_buses = $conn->query(
    "SELECT b.Bus_ID, b.Bus_Num, b.Total_Seat, r.Covered_Area
     FROM Bus b JOIN Route r ON b.Route_ID=r.Route_ID WHERE b.E_Flag=1"
)->fetch_all(MYSQLI_ASSOC);

$stats = [
    'bookings'   => $conn->query("SELECT COUNT(*) as c FROM Booking WHERE Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)")->fetch_assoc()['c'],
    'passengers' => $conn->query("SELECT COUNT(*) as c FROM Passenger")->fetch_assoc()['c'],
    'trips'      => $conn->query("SELECT COUNT(*) as c FROM Trip WHERE Date>=CURDATE() AND Status!='Cancelled'")->fetch_assoc()['c'],
    'revenue'    => $conn->query("SELECT COALESCE(SUM(Amount),0) as s FROM Payment WHERE Status='Paid'")->fetch_assoc()['s'],
];
$conn->close();
[$msg_type, $msg_text] = $msg ? explode(':', $msg, 2) : ['',''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel - BRACU Bus</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f8;color:#333}
nav{background:linear-gradient(135deg,#1a3a5c,#0d5c2e);color:white;padding:0 24px;display:flex;align-items:center;justify-content:space-between;height:60px;box-shadow:0 2px 10px rgba(0,0,0,0.2);position:sticky;top:0;z-index:100}
nav .brand{font-size:18px;font-weight:700}
nav .nl{display:flex;gap:10px}
nav a{color:white;text-decoration:none;font-size:13px;padding:6px 14px;border:1px solid rgba(255,255,255,0.4);border-radius:20px}
nav a:hover{background:rgba(255,255,255,0.15)}
.page{max-width:1050px;margin:0 auto;padding:24px 20px}
.page-title{font-size:22px;font-weight:800;color:#1a3a5c;margin-bottom:4px}
.page-sub{font-size:13px;color:#888;margin-bottom:22px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:14px;line-height:1.6}
.alert-success{background:#d4edda;color:#155724;border-left:4px solid #27ae60}
.alert-error{background:#ffeaea;color:#c0392b;border-left:4px solid #e74c3c}
.stats{display:flex;gap:14px;margin-bottom:28px;flex-wrap:wrap}
.sc{background:white;border-radius:12px;padding:18px 22px;flex:1;min-width:130px;box-shadow:0 2px 8px rgba(0,0,0,0.07);text-align:center}
.sc .num{font-size:28px;font-weight:800;color:#1a3a5c}
.sc .lbl{font-size:12px;color:#888;margin-top:4px}
.card{background:white;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,0.08);margin-bottom:24px;overflow:hidden}
.ch{padding:16px 22px}
.ch.blue{background:#1a3a5c}
.ch.orange{background:#e67e22}
.ch.red{background:#c0392b}
.ch h3{font-size:15px;font-weight:700;color:white}
.ch p{font-size:12px;color:rgba(255,255,255,0.8);margin-top:2px}
.cb{padding:20px 22px}
.dr{display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid #f5f5f5;flex-wrap:wrap}
.dr:last-child{border-bottom:none}
.di{flex:1}
.di h4{font-size:14px;font-weight:700}
.di p{font-size:12px;color:#888;margin-top:2px}
.dv{text-align:center;min-width:60px}
.dv .n{font-size:22px;font-weight:800;color:#1a3a5c}
.dv .l{font-size:10px;color:#aaa}
.vb{height:8px;background:#eee;border-radius:4px;flex:1;min-width:80px}
.vf{height:100%;border-radius:4px;background:linear-gradient(90deg,#1a3a5c,#27ae60)}
.fr{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
.fg{flex:1;min-width:140px}
.fg label{display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:6px}
.fg select,.fg input{width:100%;padding:10px 12px;border:1.5px solid #dde2ea;border-radius:8px;font-size:13px;outline:none;background:#fafbfc}
.fg select:focus,.fg input:focus{border-color:#1a3a5c}
.btn-sch{padding:10px 22px;background:#e67e22;color:white;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;white-space:nowrap}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:10px 12px;background:#f8f9fa;color:#555;font-weight:700;border-bottom:2px solid #eee}
td{padding:10px 12px;border-bottom:1px solid #f5f5f5;vertical-align:middle}
tr:last-child td{border-bottom:none}
.badge{padding:3px 9px;border-radius:8px;font-size:11px;font-weight:700}
.b-sch{background:#dbeafe;color:#1e40af}
.b-run{background:#d1fae5;color:#065f46}
.b-com{background:#f3f4f6;color:#666}
.b-can{background:#fee2e2;color:#991b1b}
.et{background:#fff3cd;color:#856404;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;margin-left:4px}
.ss{padding:5px 8px;border:1px solid #ddd;border-radius:6px;font-size:12px;cursor:pointer}
.bu{padding:5px 12px;background:#1a3a5c;color:white;border:none;border-radius:6px;font-size:12px;cursor:pointer}
.bc{padding:5px 12px;background:#e74c3c;color:white;border:none;border-radius:6px;font-size:12px;cursor:pointer}
.enote{font-size:11px;color:#27ae60;margin-top:8px}
</style>
</head>
<body>
<nav>
    <div class="brand">🚌 Admin Panel</div>
    <div class="nl">
        <a href="analytics.php">📊 Analytics</a>
        <a href="live_status.php">🟢 Live Status</a>
        <a href="demand_poll.php">🗳️ Demand Poll</a>
        <a href="dashboard.php">← Dashboard</a>
    </div>
</nav>

<div class="page">
    <div class="page-title">⚙️ Admin Panel</div>
    <div class="page-sub">Logged in as <?= htmlspecialchars($_SESSION['name']) ?> (Faculty)</div>

    <?php if ($msg_text): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($msg_text) ?></div>
    <?php endif; ?>

    <div class="stats">
        <div class="sc"><div class="num"><?= $stats['bookings'] ?></div><div class="lbl">Active Bookings</div></div>
        <div class="sc"><div class="num"><?= $stats['passengers'] ?></div><div class="lbl">Passengers</div></div>
        <div class="sc"><div class="num"><?= $stats['trips'] ?></div><div class="lbl">Active Trips</div></div>
        <div class="sc"><div class="num">৳<?= number_format($stats['revenue']) ?></div><div class="lbl">Revenue</div></div>
    </div>

    <!-- Demand Poll + Schedule Extra Bus combined -->
    <div class="card">
        <div class="ch blue"><h3>📊 Demand Poll Results & Schedule Extra Bus</h3><p>View demand per route and schedule an extra bus directly</p></div>
        <div class="cb">
            <?php $mx = max(array_column($demand,'votes') ?: [1]);
            foreach ($demand as $d): $p = $mx>0?round($d['votes']/$mx*100):0; ?>
            <div class="dr" style="align-items:center;">
                <div class="di">
                    <h4>📍 <?= htmlspecialchars($d['Covered_Area']) ?></h4>
                    <p><?= htmlspecialchars($d['Stops']) ?></p>
                </div>
                <div class="vb"><div class="vf" style="width:<?= $p ?>%"></div></div>
                <div class="dv" style="text-align:center;min-width:60px;">
                    <div class="n"><?= $d['votes'] ?></div><div class="l">votes</div>
                    <?php if ($d['votes']>=3): ?><div style="color:#e74c3c;font-size:11px;font-weight:700;">🔴 HIGH</div><?php endif; ?>
                </div>
                <!-- Inline schedule form for this route -->
                <?php
                // Find extra bus for this route
                $eb_for_route = null;
                foreach ($extra_buses as $eb) {
                    if ($eb['Covered_Area'] === $d['Covered_Area']) { $eb_for_route = $eb; break; }
                }
                ?>
                <?php if ($eb_for_route): ?>
                <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;min-width:320px;">
                    <input type="hidden" name="bus_id" value="<?= $eb_for_route['Bus_ID'] ?>">
                    <div style="display:flex;flex-direction:column;gap:2px;">
                        <label style="font-size:11px;font-weight:700;color:#555;">Date</label>
                        <input type="date" name="trip_date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required
                               style="padding:7px 10px;border:1.5px solid #dde2ea;border-radius:7px;font-size:12px;width:120px;">
                    </div>
                    <div style="display:flex;flex-direction:column;gap:2px;">
                        <label style="font-size:11px;font-weight:700;color:#555;">Departure</label>
                        <input type="time" name="dep_time" required
                               style="padding:7px 10px;border:1.5px solid #dde2ea;border-radius:7px;font-size:12px;width:100px;">
                    </div>
                    <div style="display:flex;flex-direction:column;gap:2px;">
                        <label style="font-size:11px;font-weight:700;color:#555;">Arrival</label>
                        <input type="time" name="arr_time" required
                               style="padding:7px 10px;border:1.5px solid #dde2ea;border-radius:7px;font-size:12px;width:100px;">
                    </div>
                    <button type="submit" name="schedule_extra" class="btn-sch" style="padding:8px 16px;font-size:13px;">
                        ⚡ Schedule <?= htmlspecialchars($eb_for_route['Bus_Num']) ?>
                    </button>
                </form>
                <?php else: ?>
                <span style="font-size:12px;color:#aaa;">No extra bus available for this route</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <p class="enote" style="margin-top:12px;">📧 Scheduling automatically emails all voters for that route (requires mail_config.php)</p>
        </div>
    </div>

    <!-- Cancel Extra Trips -->
    <div class="card">
        <div class="ch red"><h3>🚫 Cancel Extra Bus Trips</h3><p>Cancelled trips are instantly hidden from the booking page for all users</p></div>
        <div class="cb" style="overflow-x:auto">
            <?php if (empty($extra_trips)): ?>
                <p style="color:#bbb;text-align:center;padding:20px">No extra bus trips scheduled yet.</p>
            <?php else: ?>
            <table>
                <thead><tr><th>#</th><th>Bus</th><th>Route</th><th>Date</th><th>Departure</th><th>Seats</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($extra_trips as $t): ?>
                <tr style="<?= $t['Status']==='Cancelled'?'opacity:0.45':'' ?>">
                    <td><?= $t['Trip_ID'] ?></td>
                    <td><strong><?= htmlspecialchars($t['Bus_Num']) ?></strong><span class="et">EXTRA</span></td>
                    <td><?= htmlspecialchars($t['Covered_Area']) ?></td>
                    <td><?= date('d M Y', strtotime($t['Date'])) ?></td>
                    <td><?= date('g:i A', strtotime($t['Departure_Time'])) ?></td>
                    <td><?= $t['booked'] ?>/<?= $t['Total_Seat'] ?></td>
                    <td><span class="badge b-<?= strtolower(substr($t['Status'],0,3)) ?>"><?= $t['Status'] ?></span></td>
                    <td>
                        <?php if ($t['Status']!=='Cancelled'): ?>
                        <form method="POST" onsubmit="return confirm('Cancel this extra bus trip? Users won\'t be able to book it anymore.')">
                            <input type="hidden" name="trip_id" value="<?= $t['Trip_ID'] ?>">
                            <button type="submit" name="cancel_extra" class="bc">🚫 Cancel</button>
                        </form>
                        <?php else: ?>
                            <span style="color:#aaa;font-size:12px">Cancelled</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Regular Trips -->
    <div class="card">
        <div class="ch blue"><h3>🗓️ Regular Trips — Update Status</h3><p>Mark trips as Running, Completed, or Cancelled</p></div>
        <div class="cb" style="overflow-x:auto">
            <table>
                <thead><tr><th>#</th><th>Bus</th><th>Route</th><th>Date</th><th>Departure</th><th>Seats</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($regular_trips as $t): ?>
                <tr>
                    <td><?= $t['Trip_ID'] ?></td>
                    <td><strong><?= htmlspecialchars($t['Bus_Num']) ?></strong></td>
                    <td><?= htmlspecialchars($t['Covered_Area']) ?></td>
                    <td><?= date('d M', strtotime($t['Date'])) ?></td>
                    <td><?= date('g:i A', strtotime($t['Departure_Time'])) ?></td>
                    <td><?= $t['booked'] ?>/<?= $t['Total_Seat'] ?></td>
                    <td><span class="badge b-<?= strtolower(substr($t['Status'],0,3)) ?>"><?= $t['Status'] ?></span></td>
                    <td>
                        <form method="POST" style="display:flex;gap:6px;align-items:center">
                            <input type="hidden" name="trip_id" value="<?= $t['Trip_ID'] ?>">
                            <select name="new_status" class="ss">
                                <option value="Scheduled" <?= $t['Status']==='Scheduled'?'selected':''?>>Scheduled</option>
                                <option value="Running"   <?= $t['Status']==='Running'?'selected':''?>>Running</option>
                                <option value="Completed" <?= $t['Status']==='Completed'?'selected':''?>>Completed</option>
                                <option value="Cancelled" <?= $t['Status']==='Cancelled'?'selected':''?>>Cancelled</option>
                            </select>
                            <button type="submit" name="update_status" class="bu">Save</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>