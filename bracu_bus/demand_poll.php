<?php

session_start();
require_once 'db_config.php';
if (file_exists(__DIR__ . '/mail_config.php')) require_once 'mail_config.php';
if (!function_exists('sendExtraBusNotification')) {
    function sendExtraBusNotification(...$args) { return false; }
}
requireLogin();

$conn = getDB();
$pid  = $_SESSION['passenger_id'];
$msg  = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_extra']) && $_SESSION['type'] === 'Faculty') {
    $bus_id    = (int)$_POST['bus_id'];
    $trip_date = $_POST['trip_date'];
    $dep_time  = $_POST['dep_time'];
    $arr_time  = $_POST['arr_time'];
    $route_id  = (int)($_POST['route_id'] ?? 0);

    
    if ($route_id > 0) {
        $conn->execute_query("UPDATE Bus SET Route_ID=? WHERE Bus_ID=? AND E_Flag=1", [$route_id, $bus_id]);
    }

    $chk = $conn->prepare("SELECT b.Bus_ID, b.Route_ID, b.Bus_Num, r.Covered_Area FROM Bus b JOIN Route r ON b.Route_ID=r.Route_ID WHERE b.Bus_ID=? AND b.E_Flag=1");
    $chk->bind_param("i", $bus_id);
    $chk->execute();
    $bus_row = $chk->get_result()->fetch_assoc();

    if ($bus_row) {
        $ins = $conn->prepare("INSERT INTO Trip (Arrived_Time, Departure_Time, Status, Bus_ID, Date) VALUES (?,?,'Scheduled',?,?)");
        $ins->bind_param("ssis", $arr_time, $dep_time, $bus_id, $trip_date);
        if ($ins->execute()) {
            $trip_id = $conn->insert_id;
            $conn->execute_query("INSERT IGNORE INTO Wishlist (Trip_ID) VALUES (?)", [$trip_id]);

            // Email voters for this route
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
            $note = count($voter_list) > 0 ? " Notified $sent/".count($voter_list)." voters." : "";
            $msg = "success:Extra bus scheduled for {$bus_row['Covered_Area']} on ".date('D d M Y', strtotime($trip_date)).".$note";
        } else {
            $msg = "error:Scheduling failed. Please try again.";
        }
    } else {
        $msg = "error:Invalid bus selected.";
    }
}


$my_voted_poll_id  = null;
$my_voted_route_id = null;
$mv = $conn->prepare("SELECT d.D_ID, dp.Route_ID FROM `Do` d JOIN Demand_Poll dp ON dp.Poll_ID = d.D_ID WHERE d.P_ID=? LIMIT 1");
$mv->bind_param("i", $pid);
$mv->execute();
$mv_row = $mv->get_result()->fetch_assoc();
if ($mv_row) {
    $my_voted_poll_id  = (int)$mv_row['D_ID'];
    $my_voted_route_id = (int)$mv_row['Route_ID'];
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_vote'])) {
    if ($my_voted_poll_id) {
        $del = $conn->prepare("DELETE FROM `Do` WHERE D_ID=? AND P_ID=?");
        $del->bind_param("ii", $my_voted_poll_id, $pid);
        $del->execute();
        $my_voted_poll_id  = null;
        $my_voted_route_id = null;
        $msg = "success:Your vote has been cancelled. You can now vote for any route.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vote'])) {
    if ($my_voted_poll_id) {
        $msg = "error:You already voted. Cancel your current vote first to vote for a different route.";
    } else {
        $route_id = (int)$_POST['route_id'];
        $bus_id   = (int)$_POST['bus_id'];

        
        $chk = $conn->prepare("SELECT Poll_ID FROM Demand_Poll WHERE Route_ID=?");
        $chk->bind_param("i", $route_id);
        $chk->execute();
        $poll = $chk->get_result()->fetch_assoc();
        if (!$poll) {
            $conn->execute_query("INSERT INTO Demand_Poll (Route_ID) VALUES (?)", [$route_id]);
            $poll_id = $conn->insert_id;
        } else {
            $poll_id = $poll['Poll_ID'];
        }
        $ins = $conn->prepare("INSERT INTO `Do` (D_ID, P_ID, Bus_ID, Count) VALUES (?,?,?,1)");
        $ins->bind_param("iii", $poll_id, $pid, $bus_id);
        if ($ins->execute()) {
            $my_voted_poll_id  = $poll_id;
            $my_voted_route_id = $route_id;
            $msg = "success:Vote recorded for this route! Cancel vote anytime to switch routes.";
        }
    }
}


$routes = $conn->query(
    "SELECT r.Route_ID, r.Covered_Area, r.Stops,
            dp.Poll_ID,
            COALESCE(SUM(d.Count),0) as vote_count,
            b.Bus_ID, b.Bus_Num, b.Total_Seat, b.E_Flag
     FROM Route r
     LEFT JOIN Demand_Poll dp ON dp.Route_ID = r.Route_ID
     LEFT JOIN `Do` d ON d.D_ID = dp.Poll_ID
     LEFT JOIN Bus b ON b.Route_ID = r.Route_ID AND b.R_Flag = 1
     GROUP BY r.Route_ID, r.Covered_Area, r.Stops, dp.Poll_ID, b.Bus_ID, b.Bus_Num, b.Total_Seat, b.E_Flag
     ORDER BY vote_count DESC"
)->fetch_all(MYSQLI_ASSOC);


$extra_buses = $conn->query(
    "SELECT b.Bus_Num, b.Bus_ID, b.Total_Seat, r.Covered_Area, t.Departure_Time, t.Date, t.Status
     FROM Bus b
     JOIN Route r ON b.Route_ID = r.Route_ID
     LEFT JOIN Trip t ON t.Bus_ID = b.Bus_ID AND t.Date >= CURDATE() AND t.Status != 'Cancelled'
     WHERE b.E_Flag = 1
     ORDER BY t.Date ASC, t.Departure_Time ASC"
)->fetch_all(MYSQLI_ASSOC);


$extra_buses_available = $conn->query(
    "SELECT b.Bus_ID, b.Bus_Num, b.Total_Seat, r.Covered_Area, r.Route_ID
     FROM Bus b JOIN Route r ON b.Route_ID = r.Route_ID
     WHERE b.E_Flag = 1"
)->fetch_all(MYSQLI_ASSOC);

$conn->close();


[$msg_type, $msg_text] = $msg ? explode(':', $msg, 2) : ['',''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demand Poll - BRACU Bus</title>
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
nav a { color: white; text-decoration: none; font-size: 13px; padding: 6px 14px; border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; transition: background 0.2s; }
nav a:hover { background: rgba(255,255,255,0.15); }
.page { max-width: 860px; margin: 0 auto; padding: 24px 20px; }
.page-title { font-size: 22px; font-weight: 800; color: #1a3a5c; margin-bottom: 4px; }
.page-sub   { font-size: 13px; color: #888; margin-bottom: 22px; }
.alert { padding: 13px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; }
.alert-success { background: #d4edda; color: #155724; border-left: 4px solid #27ae60; }
.alert-error   { background: #ffeaea; color: #c0392b; border-left: 4px solid #e74c3c; }
.info-box { background: #e8f4fd; border-left: 4px solid #3498db; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px; font-size: 13px; color: #1a5276; line-height: 1.6; }
.section-title { font-size: 16px; font-weight: 700; color: #1a3a5c; margin: 24px 0 14px; }
.poll-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media(max-width:600px){ .poll-grid { grid-template-columns: 1fr; } }
.poll-card {
    background: white; border-radius: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    padding: 20px; border: 2px solid transparent;
    transition: border-color 0.2s;
}
.poll-card:hover { border-color: #1a3a5c; }
.poll-card.voted { border-color: #27ae60; background: #f0faf4; }
.poll-card .route-name { font-size: 16px; font-weight: 800; color: #1a3a5c; margin-bottom: 4px; }
.poll-card .route-stops { font-size: 12px; color: #888; margin-bottom: 14px; line-height: 1.4; }
.vote-bar-wrap { margin-bottom: 14px; }
.vote-bar-label { display: flex; justify-content: space-between; font-size: 12px; color: #555; margin-bottom: 5px; }
.vote-bar { height: 10px; background: #eee; border-radius: 5px; overflow: hidden; }
.vote-fill { height: 100%; border-radius: 5px; background: linear-gradient(90deg, #1a3a5c, #0d5c2e); transition: width 0.5s; }
.vote-count { font-size: 24px; font-weight: 800; color: #1a3a5c; }
.vote-lbl   { font-size: 11px; color: #888; }
.btn-vote {
    width: 100%; padding: 10px; border: none; border-radius: 8px;
    font-size: 14px; font-weight: 700; cursor: pointer; transition: opacity 0.2s;
    background: linear-gradient(135deg, #1a3a5c, #0d5c2e); color: white;
}
.btn-vote:hover { opacity: 0.85; }
.btn-voted { background: #e8f5e9; color: #27ae60; border: 2px solid #27ae60; cursor: default; }
.extra-bus-section { margin-top: 28px; }
.extra-card {
    background: white; border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    padding: 16px 20px; margin-bottom: 12px;
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    border-left: 4px solid #e67e22;
}
.extra-card .bus-icon { font-size: 28px; }
.extra-card .bus-info { flex: 1; }
.extra-card .bus-info h4 { font-size: 15px; font-weight: 700; color: #333; }
.extra-card .bus-info p  { font-size: 13px; color: #888; margin-top: 3px; }
.extra-badge { background: #fff3cd; color: #856404; padding: 3px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; }
.empty { text-align: center; color: #bbb; padding: 24px; font-size: 14px; }
/* Admin route cards */
.admin-route-card {
    background: white; border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    padding: 20px 24px; margin-bottom: 16px;
    border-left: 4px solid #1a3a5c;
}
.arc-top { display: flex; align-items: center; gap: 16px; margin-bottom: 10px; flex-wrap: wrap; }
.arc-info { flex: 1; }
.arc-name { font-size: 16px; font-weight: 800; color: #1a3a5c; }
.arc-stops { font-size: 12px; color: #888; margin-top: 3px; }
.arc-votes { text-align: center; min-width: 70px; }
.arc-num { font-size: 26px; font-weight: 800; color: #1a3a5c; display: block; }
.arc-lbl { font-size: 11px; color: #aaa; }
.arc-bar { height: 8px; background: #eee; border-radius: 4px; margin-bottom: 14px; }
.arc-fill { height: 100%; border-radius: 4px; background: linear-gradient(90deg, #1a3a5c, #27ae60); }
.arc-schedule { background: #f8faff; border-radius: 10px; padding: 14px 16px; }
.arc-bus-label { font-size: 13px; font-weight: 700; color: #1a3a5c; margin-bottom: 10px; }
.arc-form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.arc-field { display: flex; flex-direction: column; gap: 4px; }
.arc-field label { font-size: 11px; font-weight: 700; color: #666; }
.arc-field input { padding: 8px 10px; border: 1.5px solid #dde2ea; border-radius: 7px; font-size: 13px; outline: none; }
.arc-field input:focus { border-color: #1a3a5c; }
.btn-schedule-now {
    padding: 9px 18px; background: linear-gradient(135deg, #e67e22, #d35400);
    color: white; border: none; border-radius: 8px; font-size: 13px;
    font-weight: 700; cursor: pointer; white-space: nowrap; transition: opacity 0.2s;
}
.btn-schedule-now:hover { opacity: 0.85; }
.demand-high { display:block; color: #e74c3c; font-size: 11px; font-weight: 700; margin-top: 3px; }
.demand-med  { display:block; color: #e67e22; font-size: 11px; font-weight: 700; margin-top: 3px; }
.demand-low  { display:block; color: #27ae60; font-size: 11px; font-weight: 700; margin-top: 3px; }
</style>
</head>
<body>
<nav>
    <div class="brand">🚌 BRACU Bus System</div>
    <div class="nav-links">
        <a href="wishlist.php">❤️ Wishlist</a>
        <a href="analytics.php">📊 Analytics</a>
        <?php if ($_SESSION['type']==='Faculty'): ?><a href="admin.php" style="background:rgba(230,126,34,0.3);">⚙️ Admin</a><?php endif; ?>
        <a href="dashboard.php">← Dashboard</a>
    </div>
</nav>

<div class="page">

<?php if ($_SESSION['type'] === 'Faculty'): ?>
    <!-- ═══════════════ ADMIN VIEW ═══════════════ -->
    <div class="page-title">📊 Demand Poll — Admin View</div>
    <div class="page-sub">See student demand and schedule extra buses</div>

    <?php if ($msg_text): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($msg_text) ?></div>
    <?php endif; ?>

    <!-- Demand summary bar -->
    <div style="background:white;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.07);padding:18px 22px;margin-bottom:24px;">
        <div style="font-size:14px;font-weight:700;color:#1a3a5c;margin-bottom:14px;">📊 Current Student Demand</div>
        <?php
        $max_votes = max(array_column($routes, 'vote_count') ?: [1]);
        foreach ($routes as $r):
            $pct = $max_votes > 0 ? round(($r['vote_count'] / $max_votes) * 100) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
            <span style="font-size:13px;font-weight:700;min-width:120px;">📍 <?= htmlspecialchars($r['Covered_Area']) ?></span>
            <div style="flex:1;height:10px;background:#eee;border-radius:5px;overflow:hidden;">
                <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,#1a3a5c,#27ae60);border-radius:5px;"></div>
            </div>
            <span style="font-size:13px;font-weight:800;color:#1a3a5c;min-width:55px;text-align:right;"><?= $r['vote_count'] ?> votes</span>
            <?php if ($r['vote_count'] >= 5): ?>
                <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;min-width:42px;text-align:center;">🔴 HIGH</span>
            <?php elseif ($r['vote_count'] >= 2): ?>
                <span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;min-width:42px;text-align:center;">🟡 MED</span>
            <?php else: ?>
                <span style="background:#d4edda;color:#155724;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;min-width:42px;text-align:center;">🟢 LOW</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 2 Extra Bus Cards side by side -->
    <div style="font-size:16px;font-weight:700;color:#1a3a5c;margin-bottom:14px;">⚡ Schedule Extra Bus</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:28px;">
        <?php foreach ($extra_buses_available as $eb): ?>
        <div style="background:white;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,0.08);padding:22px;border-top:4px solid #e67e22;">
            <div style="font-size:20px;font-weight:800;color:#1a3a5c;margin-bottom:4px;">🚌 <?= htmlspecialchars($eb['Bus_Num']) ?></div>
            <div style="font-size:12px;color:#888;margin-bottom:18px;"><?= $eb['Total_Seat'] ?> seats · Extra Bus</div>
            <form method="POST">
                <input type="hidden" name="bus_id" value="<?= $eb['Bus_ID'] ?>">
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:5px;">Destination Route</label>
                    <select name="route_id" style="width:100%;padding:9px 12px;border:1.5px solid #dde2ea;border-radius:8px;font-size:13px;outline:none;background:#fafbfc;">
                        <?php foreach ($routes as $r): ?>
                        <option value="<?= $r['Route_ID'] ?>" <?= $eb['Route_ID']==$r['Route_ID']?'selected':'' ?>>
                            <?= htmlspecialchars($r['Covered_Area']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:5px;">Date</label>
                        <input type="date" name="trip_date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required
                               style="width:100%;padding:9px 10px;border:1.5px solid #dde2ea;border-radius:8px;font-size:13px;outline:none;">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:5px;">Departure</label>
                        <input type="time" name="dep_time" required
                               style="width:100%;padding:9px 10px;border:1.5px solid #dde2ea;border-radius:8px;font-size:13px;outline:none;">
                    </div>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:5px;">Arrival</label>
                    <input type="time" name="arr_time" required
                           style="width:100%;padding:9px 10px;border:1.5px solid #dde2ea;border-radius:8px;font-size:13px;outline:none;">
                </div>
                <button type="submit" name="schedule_extra"
                        style="width:100%;padding:11px;background:linear-gradient(135deg,#e67e22,#d35400);color:white;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;transition:opacity 0.2s;">
                    ⚡ Schedule & Notify Voters
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Currently scheduled extra trips -->
    <div style="font-size:16px;font-weight:700;color:#1a3a5c;margin-bottom:14px;">🚌 Scheduled Extra Trips</div>
    <?php if (empty($extra_buses)): ?>
        <div class="empty">No extra trips scheduled yet.</div>
    <?php else: ?>
        <?php foreach ($extra_buses as $e): ?>
        <div class="extra-card">
            <div class="bus-icon">🚌</div>
            <div class="bus-info">
                <h4><?= htmlspecialchars($e['Bus_Num']) ?> — <?= htmlspecialchars($e['Covered_Area']) ?></h4>
                <p><?= $e['Date'] ? date('D d M Y', strtotime($e['Date'])) : 'TBD' ?>
                <?= $e['Departure_Time'] ? ' · '.date('g:i A', strtotime($e['Departure_Time'])) : '' ?>
                · <?= $e['Total_Seat'] ?> seats</p>
            </div>
            <span class="extra-badge">⚡ Extra Bus</span>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php else: ?>
    <!-- ═══════════════ STUDENT VIEW ═══════════════ -->
    <div class="page-title">📊 Demand Poll</div>
    <div class="page-sub">Vote for routes that need more buses — admin schedules extra buses based on demand</div>

    <?php if ($msg_text): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($msg_text) ?></div>
    <?php endif; ?>

    <div class="info-box">
        🗳️ <strong>How it works:</strong> Vote for the route that needs more buses.
        You can only vote for <strong>one route at a time</strong>. Cancel your vote anytime to switch.
        Admin schedules extra buses based on demand.
    </div>

    <?php if ($my_voted_poll_id): ?>
    <div style="background:#e8f5e9;border:2px solid #27ae60;border-radius:10px;padding:14px 18px;margin-bottom:18px;font-size:14px;color:#155724;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <span>✅ You voted for: <strong><?php
            foreach ($routes as $r) { if ($r['Route_ID'] == $my_voted_route_id) echo htmlspecialchars($r['Covered_Area']); }
        ?></strong></span>
        <form method="POST" style="margin:0;">
            <button type="submit" name="cancel_vote" style="padding:7px 16px;background:#e74c3c;color:white;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;">✕ Cancel Vote</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="section-title">🗺️ Vote for Your Route</div>
    <div class="poll-grid">
        <?php
        $max_votes = max(array_column($routes, 'vote_count') ?: [1]);
        foreach ($routes as $r):
            $voted = ($my_voted_route_id !== null && (int)$r['Route_ID'] === (int)$my_voted_route_id);
            $pct   = $max_votes > 0 ? round(($r['vote_count'] / $max_votes) * 100) : 0;
        ?>
        <div class="poll-card <?= $voted ? 'voted' : '' ?>">
            <div class="route-name">📍 <?= htmlspecialchars($r['Covered_Area']) ?></div>
            <div class="route-stops">🛑 <?= htmlspecialchars($r['Stops']) ?></div>
            <div class="vote-bar-wrap">
                <div class="vote-bar-label">
                    <span>Demand level</span>
                    <span><?= $r['vote_count'] ?> votes</span>
                </div>
                <div class="vote-bar"><div class="vote-fill" style="width:<?= $pct ?>%"></div></div>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                <div><span class="vote-count"><?= $r['vote_count'] ?></span><br><span class="vote-lbl">total votes</span></div>
                <?php if ($r['vote_count'] >= 5): ?>
                    <span style="background:#fee2e2;color:#991b1b;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:700;">🔴 High</span>
                <?php elseif ($r['vote_count'] >= 2): ?>
                    <span style="background:#fff3cd;color:#856404;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:700;">🟡 Medium</span>
                <?php else: ?>
                    <span style="background:#d4edda;color:#155724;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:700;">🟢 Normal</span>
                <?php endif; ?>
            </div>
            <?php if ($voted): ?>
                <button class="btn-vote" style="background:#27ae60;cursor:default;" disabled>✓ Your Vote</button>
            <?php elseif ($my_voted_poll_id): ?>
                <button class="btn-vote" style="background:#ccc;cursor:not-allowed;" disabled>Cancel vote to switch</button>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="route_id" value="<?= $r['Route_ID'] ?>">
                    <input type="hidden" name="bus_id"   value="<?= $r['Bus_ID'] ?? 0 ?>">
                    <button type="submit" name="vote" class="btn-vote">🗳️ Vote for this Route</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Scheduled extra buses (info only for students) -->
    <div class="extra-bus-section">
        <div class="section-title">🚌 Extra Buses Scheduled</div>
        <?php if (empty($extra_buses)): ?>
            <div class="empty">No extra buses scheduled yet.</div>
        <?php else: ?>
            <?php foreach ($extra_buses as $e): ?>
            <div class="extra-card">
                <div class="bus-icon">🚌</div>
                <div class="bus-info">
                    <h4><?= htmlspecialchars($e['Bus_Num']) ?> — <?= htmlspecialchars($e['Covered_Area']) ?></h4>
                    <p><?= $e['Date'] ? date('D d M Y', strtotime($e['Date'])) : 'TBD' ?>
                    <?= $e['Departure_Time'] ? ' · '.date('g:i A', strtotime($e['Departure_Time'])) : '' ?>
                    · <?= $e['Total_Seat'] ?> seats</p>
                </div>
                <span class="extra-badge">⚡ Extra Bus</span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php endif; ?>
</div>
</body>
</html>