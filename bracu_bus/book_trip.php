<?php

session_start();
require_once 'db_config.php';
requireLogin();

$direction = isset($_GET['direction']) ? $_GET['direction'] : 'home_to_bracu';
$conn      = getDB();
$pid       = $_SESSION['passenger_id'];


if ($direction === 'home_to_bracu') {
    $page_title  = "Home → BRACU";
    $sources = [
        'Mohammadpur' => 'Mohammadpur',
        'Uttara'      => 'Uttara',
        'Mirpur'      => 'Mirpur',
    ];
    $destinations = ['BRACU Campus' => 'BRACU Campus'];
    $route_map    = ['Mohammadpur' => 1, 'Uttara' => 2, 'Mirpur' => 3];
} else {
    $page_title   = "BRACU → Home";
    $sources      = ['BRACU Campus' => 'BRACU Campus'];
    $destinations = [
        'Mohammadpur' => 'Mohammadpur',
        'Uttara'      => 'Uttara',
        'Mirpur'      => 'Mirpur',
    ];
    $route_map = ['Mohammadpur' => 1, 'Uttara' => 2, 'Mirpur' => 3];
}


$trips = [];
$selected_source      = '';
$selected_destination = '';
$selected_date        = date('Y-m-d');
$search_done          = false;

if (isset($_GET['search'])) {
    $selected_source      = trim($_GET['source']);
    $selected_destination = trim($_GET['destination']);
    $selected_date        = trim($_GET['date']);
    $search_done          = true;

    
    $route_key = ($direction === 'home_to_bracu') ? $selected_source : $selected_destination;
    $route_id  = isset($route_map[$route_key]) ? $route_map[$route_key] : 0;

    
    $exist = $conn->prepare("SELECT COUNT(*) as c FROM Trip t JOIN Bus b ON t.Bus_ID=b.Bus_ID WHERE t.Date=? AND b.Route_ID=?");
    $exist->bind_param("si", $selected_date, $route_id);
    $exist->execute();
    if ($exist->get_result()->fetch_assoc()['c'] == 0) {
        
        $bus_q = $conn->prepare("SELECT Bus_ID FROM Bus WHERE Route_ID=? AND R_Flag=1 LIMIT 1");
        $bus_q->bind_param("i", $route_id);
        $bus_q->execute();
        $bus_row = $bus_q->get_result()->fetch_assoc();
        if ($bus_row) {
            $bid = $bus_row['Bus_ID'];
            
            $times = [
                ['06:00:00','07:45:00'],
                ['07:45:00','09:30:00'],
                ['09:30:00','11:15:00'],
                ['11:15:00','13:00:00'],
                ['13:00:00','14:45:00'],
                ['14:45:00','16:30:00'],
                ['16:30:00','17:45:00'],
                ['17:45:00','19:30:00'],
                ['19:30:00','21:15:00'],
            ];
            $ins = $conn->prepare("INSERT INTO Trip (Departure_Time,Arrived_Time,Status,Bus_ID,Date) VALUES (?,?,'Scheduled',?,?)");
            foreach ($times as $t) {
                $ins->bind_param("ssis", $t[0], $t[1], $bid, $selected_date);
                $ins->execute();
                $tid = $conn->insert_id;
                $conn->execute_query("INSERT IGNORE INTO Wishlist (Trip_ID) VALUES (?)", [$tid]);
            }
        }
    }

    $sql = "SELECT t.Trip_ID, t.Departure_Time, t.Arrived_Time, t.Status, t.Date,
                   b.Bus_Num, b.Total_Seat, b.Bus_ID,
                   COALESCE(SUM(bk.Booked_Seat),0) as booked_seats,
                   (b.Total_Seat - COALESCE(SUM(bk.Booked_Seat),0)) as remaining
            FROM Trip t
            JOIN Bus b ON t.Bus_ID = b.Bus_ID
            LEFT JOIN Booking bk ON bk.Trip_ID = t.Trip_ID
                AND bk.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
            WHERE t.Date = ?
              AND b.Route_ID = ?
              AND t.Status != 'Cancelled'
            GROUP BY t.Trip_ID, b.Bus_Num, b.Total_Seat, b.Bus_ID, t.Departure_Time, t.Arrived_Time, t.Status, t.Date
            ORDER BY t.Departure_Time ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $selected_date, $route_id);
    $stmt->execute();
    $trips = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}


function getPrice($type) { return 100; }
$price = 100;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book Trip - BRACU Bus</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; color: #333; }
nav {
    background: linear-gradient(135deg, #1a3a5c, #0d5c2e);
    color: white; padding: 0 24px;
    display: flex; align-items: center; justify-content: space-between;
    height: 60px; position: sticky; top: 0; z-index: 100;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
}
nav .brand { font-size: 18px; font-weight: 700; }
nav a { color: white; text-decoration: none; font-size: 13px; padding: 6px 14px; border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; }
.page { max-width: 860px; margin: 0 auto; padding: 24px 20px; }
.breadcrumb { font-size: 13px; color: #888; margin-bottom: 18px; }
.breadcrumb a { color: #1a3a5c; text-decoration: none; }

/* Search form */
.search-card {
    background: white;
    border-radius: 14px;
    padding: 24px 28px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 24px;
}
.search-card h2 {
    font-size: 18px; font-weight: 700; color: #1a3a5c;
    margin-bottom: 20px;
}
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
.form-group { }
.form-group label {
    display: block; font-size: 13px; font-weight: 700;
    color: #444; margin-bottom: 8px;
}
.form-group select,
.form-group input {
    width: 100%; padding: 11px 12px;
    border: 1.5px solid #dde2ea; border-radius: 8px;
    font-size: 14px; color: #333; outline: none;
    transition: border-color 0.2s; background: #fafbfc;
}
.form-group select:focus,
.form-group input:focus { border-color: #1a3a5c; background: white; }
.btn-search {
    width: 100%; padding: 13px;
    background: linear-gradient(135deg, #1a3a5c, #0d5c2e);
    color: white; border: none; border-radius: 8px; font-size: 15px;
    font-weight: 700; cursor: pointer; transition: opacity 0.2s;
}
.btn-search:hover { opacity: 0.88; }

/* Trip list */
.trips-title { font-size: 15px; font-weight: 700; color: #555; margin-bottom: 14px; }
.trip-card {
    background: white;
    border-radius: 12px;
    padding: 20px 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    border: 2px solid transparent;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.trip-card:hover { border-color: #1a3a5c; box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
.trip-time { text-align: center; min-width: 90px; }
.trip-time .dep { font-size: 24px; font-weight: 800; color: #1a3a5c; }
.trip-time .arr { font-size: 13px; color: #888; margin-top: 2px; }
.trip-sep { color: #ccc; font-size: 20px; }
.trip-info { flex: 1; }
.trip-info .bus-name { font-size: 16px; font-weight: 700; color: #333; }
.trip-info .route   { font-size: 13px; color: #888; margin-top: 3px; }
.seats-section { text-align: center; min-width: 90px; }
.seats-num { font-size: 18px; font-weight: 800; }
.seats-full { color: #e74c3c; }
.seats-low  { color: #e67e22; }
.seats-ok   { color: #27ae60; }
.seats-lbl  { font-size: 11px; color: #888; }
.seat-bar { height: 6px; background: #eee; border-radius: 3px; margin-top: 6px; width: 80px; margin-left: auto; margin-right: auto; }
.seat-fill { height: 100%; border-radius: 3px; }
.trip-actions { display: flex; flex-direction: column; gap: 8px; align-items: stretch; min-width: 130px; }
.btn-book {
    padding: 10px 18px; background: #1a3a5c; color: white;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 700;
    cursor: pointer; text-decoration: none; text-align: center;
    display: block; transition: background 0.2s;
}
.btn-book:hover { background: #0d2a45; }
.btn-book.full { background: #ccc; cursor: not-allowed; }
.btn-wish {
    padding: 8px 14px; background: white; color: #e74c3c;
    border: 2px solid #e74c3c; border-radius: 8px; font-size: 12px;
    font-weight: 600; cursor: pointer; text-decoration: none;
    text-align: center; display: block; transition: background 0.2s;
}
.btn-wish:hover { background: #ffeaea; }
.btn-wish.on-list { color: #27ae60; border-color: #27ae60; }
.btn-wish.blurred { opacity: 0.3; pointer-events: none; cursor: not-allowed; filter: blur(0.5px); }
.price-tag { font-size: 12px; color: #27ae60; font-weight: 700; text-align: center; }
.no-trips { text-align: center; padding: 40px; color: #bbb; font-size: 15px; }
.alert-info { background: #e0f0ff; color: #1a3a5c; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; }
.wishlist-tip { background: #fff8e1; border: 1px solid #f59e0b; color: #78350f; padding: 11px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; }
</style>
</head>
<body>
<nav>
    <div class="brand">🚌 BRACU Bus System</div>
    <div style="display:flex;gap:10px;">
        <a href="wishlist.php">❤️ Wishlist</a>
        <a href="my_bookings.php">My Bookings</a>
        <a href="dashboard.php">← Dashboard</a>
    </div>
</nav>

<div class="page">
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a> › Book Trip › <?= htmlspecialchars($page_title) ?>
    </div>

    <div class="search-card">
        <h2><?= $direction === 'home_to_bracu' ? '🏠 → 🎓 Home → BRACU' : '🎓 → 🏠 BRACU → Home' ?></h2>
        <form method="GET" action="book_trip.php">
            <input type="hidden" name="direction" value="<?= htmlspecialchars($direction) ?>">
            <input type="hidden" name="search" value="1">
            <div class="form-grid">
                <div class="form-group">
                    <label>Stoppage</label>
                    <select name="source" required>
                        <?php foreach ($sources as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $selected_source===$val?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Destination</label>
                    <select name="destination" required>
                        <?php foreach ($destinations as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $selected_destination===$val?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>"
                           min="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <button type="submit" class="btn-search">🔍 Search Available Buses</button>
        </form>
    </div>

    <?php if ($search_done): ?>
        <div class="trips-title">
            Available trips on <?= date('D, d M Y', strtotime($selected_date)) ?>
            · <?= htmlspecialchars($selected_source) ?> → <?= htmlspecialchars($selected_destination) ?>
        </div>

        <?php if (empty($trips)): ?>
            <div class="no-trips">
                🚌 No trips found for this route and date.<br>
                <small>Try another date or check the demand poll.</small>
            </div>
        <?php else: ?>
            <?php
            // Check if user already has booking on any of these trips
            $conn = getDB();
            ?>
            <?php foreach ($trips as $trip):
                $remaining = (int)$trip['remaining'];
                $total      = (int)$trip['Total_Seat'];
                $booked     = (int)$trip['booked_seats'];
                $pct        = $total > 0 ? round(($booked/$total)*100) : 0;

                if ($remaining <= 0) {
                    $seat_class = 'seats-full';
                    $bar_color  = '#e74c3c';
                } elseif ($remaining <= 5) {
                    $seat_class = 'seats-low';
                    $bar_color  = '#e67e22';
                } else {
                    $seat_class = 'seats-ok';
                    $bar_color  = '#27ae60';
                }

                $on_wishlist = isOnWishlist($conn, $pid, $trip['Trip_ID']);
            ?>
            <div class="trip-card">
                <div class="trip-time">
                    <div class="dep"><?= date('g:i', strtotime($trip['Departure_Time'])) ?><span style="font-size:13px;font-weight:500;"> <?= date('A', strtotime($trip['Departure_Time'])) ?></span></div>
                    <div class="arr">Arrives: <?= date('g:i A', strtotime($trip['Arrived_Time'])) ?></div>
                </div>
                <div class="trip-sep">→</div>
                <div class="trip-info">
                    <div class="bus-name">🚌 <?= htmlspecialchars($trip['Bus_Num']) ?></div>
                    <div class="route"><?= htmlspecialchars($selected_source) ?> → <?= htmlspecialchars($selected_destination) ?></div>
                    <div style="font-size:12px;color:#aaa;margin-top:4px;">Trip #<?= $trip['Trip_ID'] ?> · <?= $trip['Status'] ?></div>
                </div>
                <div class="seats-section">
                    <div class="seats-num <?= $seat_class ?>"><?= $remaining > 0 ? $remaining : 'Full' ?></div>
                    <div class="seats-lbl"><?= $remaining > 0 ? 'seats left' : '0 seats left' ?></div>
                    <div class="seat-bar">
                        <div class="seat-fill" style="width:<?= $pct ?>%;background:<?= $bar_color ?>"></div>
                    </div>
                </div>
                <div class="trip-actions">
                    <?php if ($remaining > 0): ?>
                        <a href="confirm_booking.php?trip_id=<?= $trip['Trip_ID'] ?>&direction=<?= $direction ?>&source=<?= urlencode($selected_source) ?>&dest=<?= urlencode($selected_destination) ?>&date=<?= $selected_date ?>"
                           class="btn-book">Book — ৳100</a>
                        <?php if ($on_wishlist): ?>
                            <a href="wishlist_action.php?action=remove&trip_id=<?= $trip['Trip_ID'] ?>&redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
                               class="btn-wish on-list">✓ On Wishlist</a>
                        <?php else: ?>
                            <a class="btn-wish blurred" title="Wishlist available when bus is full">❤️ Wishlist</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="btn-book full">Full</div>
                        <?php if ($on_wishlist): ?>
                            <a href="wishlist_action.php?action=remove&trip_id=<?= $trip['Trip_ID'] ?>&redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
                               class="btn-wish on-list">✓ On Wishlist</a>
                        <?php else: ?>
                            <a href="wishlist_action.php?action=add&trip_id=<?= $trip['Trip_ID'] ?>&redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
                               class="btn-wish">❤️ Join Waitlist</a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="price-tag">৳100 per seat</div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php $conn->close(); ?>
        <?php endif; ?>

    <?php elseif ($direction): ?>
        <div class="alert-info">
            👆 Select your stoppage, destination, and date above to see available trips.
        </div>
    <?php endif; ?>
</div>
</body>
</html>