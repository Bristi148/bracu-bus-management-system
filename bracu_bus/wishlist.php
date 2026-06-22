<?php

session_start();
require_once 'db_config.php';
requireLogin();

$conn = getDB();
$pid  = $_SESSION['passenger_id'];
$msg  = '';


if (isset($_POST['fill_seats'])) {
    $trip_id = (int)$_POST['trip_id'];

    
    $chk = $conn->prepare(
        "SELECT COALESCE(SUM(bk.Booked_Seat),0) as booked, b.Total_Seat
         FROM Trip t JOIN Bus b ON t.Bus_ID=b.Bus_ID
         LEFT JOIN Booking bk ON bk.Trip_ID=t.Trip_ID
             AND bk.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
         WHERE t.Trip_ID=? GROUP BY b.Total_Seat"
    );
    $chk->bind_param("i", $trip_id);
    $chk->execute();
    $row   = $chk->get_result()->fetch_assoc();
    $total = (int)$row['Total_Seat'];
    $booked = (int)$row['booked'];
    $need  = $total - $booked;

    if ($need > 0) {
        
        $demo_email = 'demo_filler@bracu.ac.bd';
        $dp = $conn->prepare("SELECT id FROM Passenger WHERE Email=?");
        $dp->bind_param("s", $demo_email);
        $dp->execute();
        $dr = $dp->get_result()->fetch_assoc();
        if (!$dr) {
            $hash = password_hash('demo123', PASSWORD_DEFAULT);
            $conn->query("INSERT INTO Passenger (Name,Email,Password,type) VALUES ('Demo Filler','$demo_email','$hash','Student')");
            $demo_id = $conn->insert_id;
        } else {
            $demo_id = $dr['id'];
        }

        
        $tb = $conn->prepare("SELECT Bus_ID, Date, Departure_Time FROM Trip WHERE Trip_ID=?");
        $tb->bind_param("i", $trip_id);
        $tb->execute();
        $tr = $tb->get_result()->fetch_assoc();

       
        $ins = $conn->prepare(
            "INSERT INTO Booking (Source,Destination,Date,time,Booked_Seat,Passenger_ID,Trip_ID)
             VALUES ('Demo','Demo',?,?,1,?,?)"
        );
        for ($i = 0; $i < $need; $i++) {
            $ins->bind_param("ssii", $tr['Date'], $tr['Departure_Time'], $demo_id, $trip_id);
            $ins->execute();
        }
        $msg = "success:Trip #$trip_id is now full ($total/$total seats). You can now test the Wishlist!";
    } else {
        $msg = "info:Trip #$trip_id is already full.";
    }
}


if (isset($_POST['release_seat'])) {
    $trip_id = (int)$_POST['trip_id'];

    
    $find = $conn->prepare(
        "SELECT bk.Booking_ID FROM Booking bk
         JOIN Passenger p ON bk.Passenger_ID = p.id
         WHERE bk.Trip_ID=? AND p.Email='demo_filler@bracu.ac.bd'
           AND bk.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
         LIMIT 1"
    );
    $find->bind_param("i", $trip_id);
    $find->execute();
    $fr = $find->get_result()->fetch_assoc();

    if ($fr) {
        $bid = $fr['Booking_ID'];
        
        $demo_p = $conn->prepare("SELECT id FROM Passenger WHERE Email='demo_filler@bracu.ac.bd'");
        $demo_p->execute();
        $demo_id = $demo_p->get_result()->fetch_assoc()['id'];

        $conn->prepare("INSERT IGNORE INTO Cancel (PID,Booking_ID) VALUES (?,?)")
             ->bind_param("ii", $demo_id, $bid);
        $conn->execute_query("INSERT IGNORE INTO Cancel (PID,Booking_ID) VALUES (?,?)", [$demo_id, $bid]);

        
        $wq = $conn->prepare(
            "SELECT hw.P_ID, p.Name, p.Email FROM has_wishlist hw
             JOIN Passenger p ON hw.P_ID = p.id
             WHERE hw.Trip_ID=? ORDER BY hw.created_at ASC LIMIT 1"
        );
        $wq->bind_param("i", $trip_id);
        $wq->execute();
        $notified = $wq->get_result()->fetch_assoc();

        $notif_msg = '';
        if ($notified) {
            
            $conn->query(
                "INSERT IGNORE INTO seat_notification (P_ID, Trip_ID, notified_at)
                 VALUES ({$notified['P_ID']}, $trip_id, NOW())
                 ON DUPLICATE KEY UPDATE notified_at=NOW()"
            );
            $notif_msg = " Notification sent to <strong>{$notified['Name']}</strong> ({$notified['Email']})!";
        }
        $msg = "success:One seat released on Trip #$trip_id.$notif_msg";
    } else {
        $msg = "error:No demo seats to release.";
    }
}


$conn->query(
    "CREATE TABLE IF NOT EXISTS seat_notification (
        P_ID INT, Trip_ID INT, notified_at DATETIME,
        PRIMARY KEY (P_ID, Trip_ID),
        FOREIGN KEY (P_ID) REFERENCES Passenger(id),
        FOREIGN KEY (Trip_ID) REFERENCES Trip(Trip_ID)
    )"
);


$my_notifs = $conn->prepare(
    "SELECT sn.Trip_ID, sn.notified_at, t.Departure_Time, t.Date,
            b.Bus_Num, r.Covered_Area
     FROM seat_notification sn
     JOIN Trip t ON sn.Trip_ID = t.Trip_ID
     JOIN Bus b ON t.Bus_ID = b.Bus_ID
     JOIN Route r ON b.Route_ID = r.Route_ID
     WHERE sn.P_ID = ?
     ORDER BY sn.notified_at DESC"
);
$my_notifs->bind_param("i", $pid);
$my_notifs->execute();
$notifications = $my_notifs->get_result()->fetch_all(MYSQLI_ASSOC);


$wq = $conn->prepare(
    "SELECT hw.Trip_ID, hw.created_at, hw.Wishlist_ID,
            t.Departure_Time, t.Arrived_Time, t.Status as trip_status, t.Date,
            b.Bus_Num, r.Covered_Area, b.Total_Seat,
            COALESCE(SUM(bk.Booked_Seat),0) as booked_seats
     FROM has_wishlist hw
     JOIN Trip t ON hw.Trip_ID = t.Trip_ID
     JOIN Bus b ON t.Bus_ID = b.Bus_ID
     JOIN Route r ON b.Route_ID = r.Route_ID
     LEFT JOIN Booking bk ON bk.Trip_ID = t.Trip_ID
         AND bk.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
     WHERE hw.P_ID = ?
     GROUP BY hw.Trip_ID, hw.Wishlist_ID, hw.created_at, t.Departure_Time, t.Arrived_Time,
              t.Status, t.Date, b.Bus_Num, r.Covered_Area, b.Total_Seat
     ORDER BY hw.created_at DESC"
);
$wq->bind_param("i", $pid);
$wq->execute();
$wishlist = $wq->get_result()->fetch_all(MYSQLI_ASSOC);


$tq = $conn->query(
    "SELECT t.Trip_ID, t.Departure_Time, t.Date, bus.Bus_Num, b.Total_Seat,
            COALESCE(SUM(bk.Booked_Seat),0) as booked_seats,
            (b.Total_Seat - COALESCE(SUM(bk.Booked_Seat),0)) as remaining
     FROM Trip t
     JOIN Bus b ON t.Bus_ID = b.Bus_ID
     JOIN Bus bus ON t.Bus_ID = bus.Bus_ID
     LEFT JOIN Booking bk ON bk.Trip_ID = t.Trip_ID
         AND bk.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
     WHERE t.Date = CURDATE()
     GROUP BY t.Trip_ID, bus.Bus_Num, b.Total_Seat, t.Departure_Time, t.Date
     ORDER BY t.Departure_Time ASC
     LIMIT 5"
);
$demo_trips = $tq->fetch_all(MYSQLI_ASSOC);

$conn->close();


[$msg_type, $msg_text] = $msg ? explode(':', $msg, 2) : ['', ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Wishlist & Waitlist - BRACU Bus</title>
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
nav a { color: white; text-decoration: none; font-size: 13px; padding: 6px 14px; border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; }
.page { max-width: 860px; margin: 0 auto; padding: 24px 20px; }
.page-title { font-size: 22px; font-weight: 800; color: #1a3a5c; margin-bottom: 4px; }
.page-sub   { font-size: 13px; color: #888; margin-bottom: 22px; }

/* Alert */
.alert { padding: 13px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; line-height: 1.5; }
.alert-success { background: #d4edda; color: #155724; border-left: 4px solid #27ae60; }
.alert-error   { background: #ffeaea; color: #c0392b; border-left: 4px solid #e74c3c; }
.alert-info    { background: #dbeafe; color: #1e40af; border-left: 4px solid #3b82f6; }

/* Section headers */
.section-title {
    font-size: 16px; font-weight: 700; color: #1a3a5c;
    margin: 28px 0 14px; display: flex; align-items: center; gap: 8px;
}
.section-title .badge {
    background: #1a3a5c; color: white;
    font-size: 12px; padding: 2px 9px; border-radius: 10px;
}

/* Demo panel */
.demo-panel {
    background: white; border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08); overflow: hidden; margin-bottom: 10px;
}
.demo-panel .demo-header {
    background: linear-gradient(135deg, #1a3a5c, #0d2a45);
    color: white; padding: 16px 22px;
    display: flex; align-items: center; justify-content: space-between;
}
.demo-panel .demo-header h3 { font-size: 15px; font-weight: 700; }
.demo-panel .demo-header p  { font-size: 12px; opacity: 0.75; margin-top: 2px; }
.demo-tag { background: #e67e22; color: white; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 8px; }
.demo-body { padding: 18px 22px; }
.trip-demo-row {
    display: flex; align-items: center; gap: 14px;
    padding: 12px 0; border-bottom: 1px solid #f0f0f0; flex-wrap: wrap;
}
.trip-demo-row:last-child { border-bottom: none; }
.trip-demo-info { flex: 1; }
.trip-demo-info .name { font-size: 14px; font-weight: 700; color: #333; }
.trip-demo-info .time { font-size: 12px; color: #888; margin-top: 2px; }
.seat-indicator { text-align: center; min-width: 80px; }
.seat-indicator .num { font-size: 18px; font-weight: 800; }
.seat-full { color: #e74c3c; }
.seat-ok   { color: #27ae60; }
.seat-low  { color: #e67e22; }
.mini-bar { height: 5px; background: #eee; border-radius: 3px; margin-top: 4px; }
.mini-fill { height: 100%; border-radius: 3px; }
.demo-btns { display: flex; gap: 8px; flex-wrap: wrap; }
.btn-fill {
    padding: 7px 14px; background: #e74c3c; color: white;
    border: none; border-radius: 7px; font-size: 12px; font-weight: 700;
    cursor: pointer; transition: opacity 0.2s;
}
.btn-fill:hover { opacity: 0.85; }
.btn-fill:disabled { background: #ccc; cursor: not-allowed; }
.btn-release {
    padding: 7px 14px; background: #27ae60; color: white;
    border: none; border-radius: 7px; font-size: 12px; font-weight: 700;
    cursor: pointer; transition: opacity 0.2s;
}
.btn-release:hover { opacity: 0.85; }
.full-tag { background: #fee2e2; color: #991b1b; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; }

/* Notification banner */
.notif-card {
    background: linear-gradient(135deg, #fff8e1, #fff3cd);
    border: 2px solid #f59e0b; border-radius: 12px;
    padding: 18px 22px; margin-bottom: 14px;
    display: flex; align-items: flex-start; gap: 14px;
}
.notif-icon { font-size: 28px; flex-shrink: 0; }
.notif-body h4 { font-size: 15px; font-weight: 700; color: #92400e; margin-bottom: 4px; }
.notif-body p  { font-size: 13px; color: #78350f; }
.btn-book-now {
    padding: 9px 18px; background: #1a3a5c; color: white;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 700;
    text-decoration: none; display: inline-block; margin-top: 10px;
    transition: opacity 0.2s;
}
.btn-book-now:hover { opacity: 0.85; }

/* Wishlist cards */
.wish-card {
    background: white; border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07); margin-bottom: 12px;
    border-left: 4px solid #e74c3c; overflow: hidden;
}
.wish-card-top {
    padding: 16px 20px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
}
.wish-icon { font-size: 26px; flex-shrink: 0; }
.wish-info { flex: 1; }
.wish-info h4 { font-size: 15px; font-weight: 700; color: #333; margin-bottom: 3px; }
.wish-info p  { font-size: 13px; color: #888; }
.wish-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.btn-remove {
    padding: 7px 14px; background: white; color: #888;
    border: 1.5px solid #ccc; border-radius: 7px;
    font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.15s;
}
.btn-remove:hover { background: #f5f5f5; }
.available-badge {
    background: #d4edda; color: #155724;
    padding: 4px 10px; border-radius: 8px;
    font-size: 12px; font-weight: 700;
}
.waiting-badge {
    background: #fef3c7; color: #92400e;
    padding: 4px 10px; border-radius: 8px;
    font-size: 12px; font-weight: 700;
}
.wish-card .seat-progress {
    height: 4px; background: #fee2e2;
}
.wish-card .seat-progress-fill {
    height: 100%; background: #e74c3c; transition: width 0.3s;
}

.empty { text-align: center; padding: 40px; color: #bbb; font-size: 14px; }
.how-it-works {
    background: #f0f7ff; border-radius: 12px; padding: 18px 22px;
    margin-bottom: 24px; font-size: 13px; color: #555; line-height: 1.8;
}
.how-it-works strong { color: #1a3a5c; }
.step { display: flex; gap: 10px; margin-bottom: 8px; align-items: flex-start; }
.step-num { background: #1a3a5c; color: white; border-radius: 50%; width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; margin-top: 1px; }
</style>
</head>
<body>
<nav>
    <div class="brand">🚌 BRACU Bus System</div>
    <div style="display:flex;gap:10px;">
        <a href="my_bookings.php">My Bookings</a>
        <a href="dashboard.php">Dashboard</a>
    </div>
</nav>

<div class="page">
    <div class="page-title">❤️ Wishlist & Waitlist</div>
    <div class="page-sub">Join the waitlist when a bus is full — get notified the moment a seat opens up</div>

    <?php if ($msg_text): ?>
    <div class="alert alert-<?= $msg_type ?>">
        <?= $msg_text ?>
    </div>
    <?php endif; ?>

    <!-- Notifications -->
    <?php if (!empty($notifications)): ?>
    <div class="section-title">🔔 Seat Available — Act Now! <span class="badge"><?= count($notifications) ?></span></div>
    <?php foreach ($notifications as $n): ?>
    <div class="notif-card">
        <div class="notif-icon">🔔</div>
        <div class="notif-body">
            <h4>A seat opened up on your waitlisted trip!</h4>
            <p>
                <strong><?= htmlspecialchars($n['Bus_Num']) ?></strong> ·
                <?= htmlspecialchars($n['Covered_Area']) ?> ·
                <?= date('D d M Y', strtotime($n['Date'])) ?> ·
                Departure: <?= date('g:i A', strtotime($n['Departure_Time'])) ?>
            </p>
            <p style="margin-top:4px;font-size:12px;color:#a16207;">Notified at: <?= date('d M Y, g:i A', strtotime($n['notified_at'])) ?></p>
            <a href="book_trip.php?direction=home_to_bracu&source=<?= urlencode($n['Covered_Area']) ?>&destination=BRACU+Campus&date=<?= $n['Date'] ?>&search=1"
               class="btn-book-now">Book Seat Now →</a>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Demo Panel — Admin only -->
    <?php if ($_SESSION['type'] === 'Faculty'): ?>
    <div class="section-title">🧪 Demo: Fill a Trip to 45/45 &amp; Test Wishlist</div>
    <div class="demo-panel">
        <div class="demo-header">
            <div>
                <h3>Seat Management Demo</h3>
                <p>Fill a trip to 45/45, then release a seat to trigger waitlist notification</p>
            </div>
            <span class="demo-tag">DEMO TOOL</span>
        </div>
        <div class="demo-body">
            <?php foreach ($demo_trips as $dt):
                $rem = (int)$dt['remaining'];
                $tot = (int)$dt['Total_Seat'];
                $bkd = (int)$dt['booked_seats'];
                $pct = $tot > 0 ? round(($bkd/$tot)*100) : 0;
                $is_full = $rem <= 0;
                $bar_color = $is_full ? '#e74c3c' : ($rem <= 5 ? '#e67e22' : '#27ae60');
            ?>
            <div class="trip-demo-row">
                <div class="trip-demo-info">
                    <div class="name">🚌 <?= htmlspecialchars($dt['Bus_Num']) ?> · Trip #<?= $dt['Trip_ID'] ?></div>
                    <div class="time"><?= date('g:i A', strtotime($dt['Departure_Time'])) ?> · <?= $dt['Date'] ?></div>
                </div>
                <div class="seat-indicator">
                    <div class="num <?= $is_full?'seat-full':($rem<=5?'seat-low':'seat-ok') ?>"><?= $bkd ?>/<?= $tot ?></div>
                    <div class="mini-bar"><div class="mini-fill" style="width:<?= $pct ?>%;background:<?= $bar_color ?>"></div></div>
                    <?php if ($is_full): ?><div class="full-tag" style="margin-top:4px;">FULL</div><?php endif; ?>
                </div>
                <div class="demo-btns">
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="trip_id" value="<?= $dt['Trip_ID'] ?>">
                        <button type="submit" name="fill_seats" class="btn-fill" <?= $is_full?'disabled':'' ?>>
                            🔴 Fill to <?= $tot ?>/<?= $tot ?>
                        </button>
                    </form>
                    <?php if ($is_full): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="trip_id" value="<?= $dt['Trip_ID'] ?>">
                        <button type="submit" name="release_seat" class="btn-release">
                            🟢 Release 1 Seat
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <p style="font-size:12px;color:#aaa;margin-top:10px;">
                💡 After filling a trip, go to <a href="book_trip.php?direction=home_to_bracu" style="color:#1a3a5c;">Book Trip</a>
                and search that route — the Book button becomes ❤️ Join Waitlist.
                Come back here and click "Release 1 Seat" to trigger the notification.
            </p>
        </div>
    </div>
    <?php endif; // end admin-only demo panel ?>

    <!-- My Wishlist -->
    <div class="section-title">❤️ My Waitlist Entries <span class="badge"><?= count($wishlist) ?></span></div>

    <?php if (empty($wishlist)): ?>
        <div class="empty">
            You haven't joined any waitlists yet.<br>
            <small>When a bus is full (45/45), click ❤️ Join Waitlist on the booking page.</small>
        </div>
    <?php else: ?>
        <?php foreach ($wishlist as $w):
            $remaining = $w['Total_Seat'] - $w['booked_seats'];
            $pct = $w['Total_Seat'] > 0 ? round(($w['booked_seats']/$w['Total_Seat'])*100) : 100;
            $has_notif = false;
            foreach ($notifications as $n) {
                if ($n['Trip_ID'] == $w['Trip_ID']) { $has_notif = true; break; }
            }
        ?>
        <div class="wish-card">
            <div class="seat-progress"><div class="seat-progress-fill" style="width:<?= $pct ?>%"></div></div>
            <div class="wish-card-top">
                <div class="wish-icon">❤️</div>
                <div class="wish-info">
                    <h4>🚌 <?= htmlspecialchars($w['Bus_Num']) ?> · <?= htmlspecialchars($w['Covered_Area']) ?></h4>
                    <p>
                        <?= date('D, d M Y', strtotime($w['Date'])) ?> ·
                        Departure <?= date('g:i A', strtotime($w['Departure_Time'])) ?> ·
                        Arrives <?= date('g:i A', strtotime($w['Arrived_Time'])) ?>
                    </p>
                    <p style="font-size:12px;color:#aaa;margin-top:3px;">
                        Added to waitlist: <?= date('d M Y, g:i A', strtotime($w['created_at'])) ?>
                    </p>
                </div>
                <div class="wish-actions">
                    <?php if ($has_notif): ?>
                        <span class="available-badge">🔔 Seat Available!</span>
                        <a href="book_trip.php?direction=bracu_to_home&source=BRACU+Campus&destination=<?= urlencode($w['Covered_Area']) ?>&date=<?= $w['Date'] ?>&search=1"
                           class="btn-book-now" style="margin-top:0;">Book Now</a>
                    <?php elseif ($remaining > 0): ?>
                        <span class="available-badge">✅ Seats Open!</span>
                        <a href="book_trip.php?direction=home_to_bracu&source=<?= urlencode($w['Covered_Area']) ?>&destination=BRACU+Campus&date=<?= $w['Date'] ?>&search=1"
                           class="btn-book-now" style="margin-top:0;">Book Now</a>
                    <?php else: ?>
                        <span class="waiting-badge">⏳ Waiting</span>
                    <?php endif; ?>
                    <a href="wishlist_action.php?action=remove&trip_id=<?= $w['Trip_ID'] ?>&redirect=<?= urlencode('wishlist.php') ?>"
                       class="btn-remove"
                       onclick="return confirm('Remove from wishlist?')">✕ Remove</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>