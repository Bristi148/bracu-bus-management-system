<?php

session_start();
require_once 'db_config.php';
if (file_exists(__DIR__ . '/mail_config.php')) require_once 'mail_config.php';
if (!function_exists('sendCancellationEmail')) {
    function sendCancellationEmail(...$a) { return false; }
}
requireLogin();

$conn = getDB();
$pid  = $_SESSION['passenger_id'];
$tab  = isset($_GET['tab']) ? $_GET['tab'] : 'bookings';
$msg  = '';


if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'cancelled') {
        $bid = (int)($_GET['bid'] ?? 0);
        $msg = "success:Booking #$bid has been cancelled. Refund will be processed within 3–5 working days.";
    } elseif ($_GET['msg'] === 'time_error') {
        $msg = "error:" . urldecode($_GET['detail'] ?? 'Cannot cancel at this time.');
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $bid = (int)$_POST['booking_id'];
    
    $chk = $conn->prepare("SELECT Booking_ID, Trip_ID FROM Booking WHERE Booking_ID=? AND Passenger_ID=?");
    $chk->bind_param("ii", $bid, $pid);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();

    if ($row) {
        
        $trip_stmt = $conn->prepare("SELECT Departure_Time, Date FROM Trip WHERE Trip_ID=?");
        $trip_stmt->bind_param("i", $row['Trip_ID']);
        $trip_stmt->execute();
        $trip_info = $trip_stmt->get_result()->fetch_assoc();
        $dep_datetime = strtotime($trip_info['Date'] . ' ' . $trip_info['Departure_Time']);
        $now = time();
        $mins_until = ($dep_datetime - $now) / 60;

        if ($mins_until < 30 && $mins_until > -60) {
            $detail = urlencode("Cannot cancel within 30 minutes of departure. Departure is at " . date('g:i A', $dep_datetime) . ".");
            header("Location: my_bookings.php?tab=bookings&msg=time_error&detail=$detail");
            exit();
        } else {
        $conn->begin_transaction();
        try {
            
            $delm = $conn->prepare("DELETE FROM Make WHERE Booking_ID=?");
            $delm->bind_param("i", $bid);
            $delm->execute();

            
            $upd = $conn->prepare("UPDATE Payment SET Status='Refund Pending' WHERE Booking_ID=?");
            $upd->bind_param("i", $bid);
            $upd->execute();

           
            $ins = $conn->prepare("INSERT IGNORE INTO Cancel (PID, Booking_ID) VALUES (?,?)");
            $ins->bind_param("ii", $pid, $bid);
            $ins->execute();

            
            $trip_id_for_notif = $row['Trip_ID'];

            $conn->commit();

            
            $binfo = $conn->prepare(
                "SELECT b.Source, b.Destination, b.Date, p.Name, p.Email, pay.Amount
                 FROM Booking b
                 JOIN Passenger p ON b.Passenger_ID = p.id
                 LEFT JOIN Payment pay ON pay.Booking_ID = b.Booking_ID
                 WHERE b.Booking_ID = ?"
            );
            $binfo->bind_param("i", $bid);
            $binfo->execute();
            $bdata = $binfo->get_result()->fetch_assoc();
            if ($bdata) {
                sendCancellationEmail(
                    $bdata['Email'], $bdata['Name'], $bid,
                    $bdata['Source'], $bdata['Destination'],
                    date('D, d M Y', strtotime($bdata['Date'])),
                    $bdata['Amount'] ?? 100
                );
            }

         
            $trip_id_for_notif = $row['Trip_ID'];
            $wq = $conn->prepare(
                "SELECT hw.P_ID, p.Name, p.Email FROM has_wishlist hw
                 JOIN Passenger p ON hw.P_ID = p.id
                 WHERE hw.Trip_ID=? ORDER BY hw.created_at ASC LIMIT 1"
            );
            $wq->bind_param("i", $trip_id_for_notif);
            $wq->execute();
            $notif_row = $wq->get_result()->fetch_assoc();
            if ($notif_row) {
                $conn->execute_query(
                    "INSERT INTO seat_notification (P_ID, Trip_ID, notified_at) VALUES (?,?,NOW())
                     ON DUPLICATE KEY UPDATE notified_at=NOW()",
                    [$notif_row['P_ID'], $trip_id_for_notif]
                );
               
                if (function_exists('sendWishlistNotification')) {
                    $tinfo = $conn->prepare(
                        "SELECT t.Departure_Time, t.Date, bus.Bus_Num, r.Covered_Area
                         FROM Trip t JOIN Bus bus ON t.Bus_ID=bus.Bus_ID
                         JOIN Route r ON bus.Route_ID=r.Route_ID
                         WHERE t.Trip_ID=?"
                    );
                    $tinfo->bind_param("i", $trip_id_for_notif);
                    $tinfo->execute();
                    $tdata = $tinfo->get_result()->fetch_assoc();
                    if ($tdata) {
                        sendWishlistNotification(
                            $notif_row['Email'], $notif_row['Name'],
                            $tdata['Bus_Num'], $tdata['Covered_Area'],
                            date('D, d M Y', strtotime($tdata['Date'])),
                            date('g:i A', strtotime($tdata['Departure_Time'])),
                            'http://' . $_SERVER['HTTP_HOST'] . '/bracu_bus/wishlist.php'
                        );
                    }
                }
            }

            
            header("Location: my_bookings.php?tab=bookings&msg=cancelled&bid=$bid");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $detail = urlencode("Cancellation failed: " . $e->getMessage());
            header("Location: my_bookings.php?tab=bookings&msg=time_error&detail=$detail");
            exit();
        }
        } 
    }
}


$bookings = [];
$b = $conn->prepare(
    "SELECT bk.Booking_ID, bk.Source, bk.Destination, bk.Date, bk.time, bk.Booked_Seat,
            t.Departure_Time, t.Arrived_Time, t.Status as trip_status, t.Trip_ID,
            bus.Bus_Num, p.Amount, p.Status as pay_status, p.Payment_Method,
            c.cancelled_at
     FROM Booking bk
     JOIN Trip t ON bk.Trip_ID = t.Trip_ID
     JOIN Bus bus ON t.Bus_ID = bus.Bus_ID
     LEFT JOIN Payment p ON p.Booking_ID = bk.Booking_ID
     LEFT JOIN Cancel c ON c.Booking_ID = bk.Booking_ID AND c.PID = ?
     WHERE bk.Passenger_ID = ?
     ORDER BY bk.Date DESC, bk.time DESC"
);
$b->bind_param("ii", $pid, $pid);
$b->execute();
$bookings = $b->get_result()->fetch_all(MYSQLI_ASSOC);


$wishlist = [];
$w = $conn->prepare(
    "SELECT hw.*, t.Departure_Time, t.Arrived_Time, t.Status as trip_status, t.Date,
            bus2.Bus_Num, r.Covered_Area, b2.Total_Seat,
            COALESCE(SUM(wbk.Booked_Seat),0) as booked_seats
     FROM has_wishlist hw
     JOIN Trip t ON hw.Trip_ID = t.Trip_ID
     JOIN Bus b2 ON t.Bus_ID = b2.Bus_ID
     JOIN Bus bus2 ON t.Bus_ID = bus2.Bus_ID
     JOIN Route r ON b2.Route_ID = r.Route_ID
     LEFT JOIN Booking wbk ON wbk.Trip_ID = t.Trip_ID
     WHERE hw.P_ID = ?
     GROUP BY hw.Trip_ID, hw.Wishlist_ID, hw.created_at, t.Departure_Time, t.Arrived_Time, t.Status, t.Date, bus2.Bus_Num, r.Covered_Area, b2.Total_Seat
     ORDER BY hw.created_at DESC"
);
$w->bind_param("i", $pid);
$w->execute();
$wishlist = $w->get_result()->fetch_all(MYSQLI_ASSOC);
$conn->close();


$msg_type = '';
$msg_text = '';
if ($msg) {
    [$msg_type, $msg_text] = explode(':', $msg, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Bookings - BRACU Bus</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; color: #333; }
nav {
    background: linear-gradient(135deg, #1a3a5c, #0d5c2e);
    color: white; padding: 0 24px;
    display: flex; align-items: center; justify-content: space-between;
    height: 60px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); position: sticky; top: 0; z-index: 100;
}
nav .brand { font-size: 18px; font-weight: 700; }
nav a { color: white; text-decoration: none; font-size: 13px; padding: 6px 14px; border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; }
.page { max-width: 860px; margin: 0 auto; padding: 24px 20px; }
.page-title { font-size: 22px; font-weight: 800; color: #1a3a5c; margin-bottom: 6px; }
.page-sub   { font-size: 14px; color: #888; margin-bottom: 22px; }
.tabs { display: flex; gap: 0; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.07); margin-bottom: 24px; }
.tab { flex: 1; padding: 14px; text-align: center; cursor: pointer; font-size: 14px; font-weight: 600; color: #888; border-bottom: 3px solid transparent; text-decoration: none; transition: all 0.15s; }
.tab.active { color: #1a3a5c; border-bottom-color: #1a3a5c; background: #f0f7ff; }
.tab:hover:not(.active) { background: #f8f8f8; }
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; }
.alert-success { background: #d4edda; color: #155724; border-left: 4px solid #27ae60; }
.alert-error   { background: #ffeaea; color: #c0392b; border-left: 4px solid #e74c3c; }

/* Booking cards */
.booking-card {
    background: white; border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07); margin-bottom: 14px;
    overflow: hidden; border: 2px solid transparent;
}
.bcard-header {
    padding: 16px 20px; display: flex; justify-content: space-between;
    align-items: center; border-bottom: 1px solid #f0f0f0; flex-wrap: wrap; gap: 8px;
}
.bcard-title { font-size: 16px; font-weight: 800; color: #1a3a5c; }
.bcard-id    { font-size: 12px; color: #aaa; }
.status { padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.st-scheduled { background: #dbeafe; color: #1e40af; }
.st-running   { background: #d1fae5; color: #065f46; }
.st-completed { background: #f3f4f6; color: #666; }
.st-cancelled      { background: #fee2e2; color: #991b1b; }
.st-paid           { background: #d1fae5; color: #065f46; }
.st-pending        { background: #fef3c7; color: #92400e; }
.st-refunded       { background: #fef3c7; color: #92400e; }
.st-refund-pending { background: #fef3c7; color: #92400e; }
.bcard-body { padding: 16px 20px; display: flex; gap: 20px; flex-wrap: wrap; }
.bcard-info { flex: 1; min-width: 200px; }
.bcard-info .detail { font-size: 13px; color: #555; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
.bcard-info .detail .icon { width: 16px; text-align: center; }
.bcard-actions { display: flex; align-items: flex-end; }
.btn-cancel {
    padding: 9px 18px; background: white; color: #e74c3c;
    border: 2px solid #e74c3c; border-radius: 8px; font-size: 13px;
    font-weight: 700; cursor: pointer; transition: background 0.15s;
}
.btn-cancel:hover { background: #ffeaea; }
.cancelled-badge { color: #e74c3c; font-size: 13px; font-weight: 700; }
.empty { text-align: center; padding: 48px; color: #bbb; font-size: 15px; }
.empty a { color: #1a3a5c; text-decoration: none; font-weight: 700; }

/* Wishlist cards */
.wish-card {
    background: white; border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07); margin-bottom: 14px;
    padding: 18px 22px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    border-left: 4px solid #e74c3c;
}
.wish-info { flex: 1; }
.wish-info h4 { font-size: 15px; font-weight: 700; color: #333; margin-bottom: 4px; }
.wish-info p  { font-size: 13px; color: #888; }
.btn-remove {
    padding: 8px 16px; background: white; color: #888;
    border: 1.5px solid #ccc; border-radius: 8px;
    font-size: 13px; font-weight: 600; text-decoration: none;
    transition: background 0.15s;
}
.btn-remove:hover { background: #f5f5f5; }
.available-badge { color: #27ae60; font-weight: 700; font-size: 13px; }
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
    <div class="page-title">📋 My Bookings</div>
    <div class="page-sub">Manage your bus reservations and wishlist</div>

    <?php if ($msg_text): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($msg_text) ?></div>
    <?php endif; ?>

    <div class="tabs">
        <a href="?tab=bookings" class="tab <?= $tab==='bookings'?'active':'' ?>">🎫 Bookings (<?= count($bookings) ?>)</a>
        <a href="?tab=wishlist" class="tab <?= $tab==='wishlist'?'active':'' ?>">❤️ Wishlist (<?= count($wishlist) ?>)</a>
    </div>

    <?php if ($tab === 'bookings'): ?>
        <?php if (empty($bookings)): ?>
            <div class="empty">
                No bookings yet!<br>
                <a href="dashboard.php">Book your first trip →</a>
            </div>
        <?php else: ?>
            <?php foreach ($bookings as $b):
                $is_cancelled = !empty($b['cancelled_at']);
                $today = date('Y-m-d');
                $can_cancel = !$is_cancelled && $b['Date'] >= $today && $b['trip_status'] !== 'Completed';
            ?>
            <div class="booking-card" style="<?= $is_cancelled?'border-color:#fee2e2;opacity:0.75':'' ?>">
                <div class="bcard-header">
                    <div>
                        <div class="bcard-title"><?= htmlspecialchars($b['Source']) ?> → <?= htmlspecialchars($b['Destination']) ?></div>
                        <div class="bcard-id">Booking #<?= str_pad($b['Booking_ID'],6,'0',STR_PAD_LEFT) ?></div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <?php if (!$is_cancelled): ?>
                            <span class="status st-<?= strtolower($b['trip_status']) ?>"><?= $b['trip_status'] ?></span>
                            <span class="status st-<?= strtolower($b['pay_status'] ?? 'pending') ?>"><?= $b['pay_status'] ?? 'Pending' ?></span>
                        <?php else: ?>
                            <span class="status st-cancelled">Cancelled</span>
                            <?php if (($b['pay_status'] ?? '') === 'Refunded' || ($b['Amount'] ?? 0) > 0): ?>
                                <span class="status" style="background:#fff3cd;color:#856404;">⏳ Refund Pending</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="bcard-body">
                    <div class="bcard-info">
                        <div class="detail"><span class="icon">📅</span> <?= date('D, d M Y', strtotime($b['Date'])) ?></div>
                        <div class="detail"><span class="icon">🕐</span> Departure: <?= date('g:i A', strtotime($b['Departure_Time'])) ?> · Arrives: <?= date('g:i A', strtotime($b['Arrived_Time'])) ?></div>
                        <div class="detail"><span class="icon">🚌</span> <?= htmlspecialchars($b['Bus_Num']) ?></div>
                        <div class="detail"><span class="icon">💳</span> <?= $b['Amount'] > 0 ? '৳'.number_format($b['Amount'],2).' via '.htmlspecialchars($b['Payment_Method']) : 'Free' ?></div>
                    </div>
                    <?php if ($can_cancel): ?>
                    <div class="bcard-actions" style="display:flex;gap:10px;flex-wrap:wrap;">
                        <a href="seat_transfer.php" class="btn-cancel"
                           style="background:white;color:#1a3a5c;border:2px solid #1a3a5c;text-decoration:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;">
                            🔄 Transfer Seat
                        </a>
                        <button type="button" class="btn-cancel"
                            onclick="showCancelModal(<?= $b['Booking_ID'] ?>, '<?= htmlspecialchars($b['Source'].' → '.$b['Destination'], ENT_QUOTES) ?>')">
                            ✕ Cancel Booking
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: // wishlist tab ?>
        <?php if (empty($wishlist)): ?>
            <div class="empty">
                Your wishlist is empty.<br>
                <small style="color:#ccc;">When a trip is full, you can join the waitlist to get notified when seats open up.</small>
            </div>
        <?php else: ?>
            <?php foreach ($wishlist as $w):
                $remaining = $w['Total_Seat'] - $w['booked_seats'];
            ?>
            <div class="wish-card">
                <div style="font-size:28px;">❤️</div>
                <div class="wish-info">
                    <h4>🚌 <?= htmlspecialchars($w['Bus_Num'] ?? '') ?> · <?= htmlspecialchars($w['Covered_Area'] ?? '') ?></h4>
                    <p>
                        <?= date('D, d M Y', strtotime($w['Date'])) ?> ·
                        Departure: <?= date('g:i A', strtotime($w['Departure_Time'])) ?>
                        <?php if ($remaining > 0): ?>
                            · <span class="available-badge">✅ Seats now available! Book now</span>
                        <?php else: ?>
                            · <span style="color:#e74c3c;font-size:12px;">Full – you're on the waitlist</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
                    <?php if ($remaining > 0): ?>
                        <a href="confirm_booking.php?trip_id=<?= $w['Trip_ID'] ?>&direction=bracu_to_home&source=BRACU+Campus&dest=<?= urlencode($w['Covered_Area']) ?>&date=<?= $w['Date'] ?>"
                           style="padding:9px 16px;background:#27ae60;color:white;border-radius:8px;text-decoration:none;font-size:13px;font-weight:700;">
                            Book Now
                        </a>
                    <?php endif; ?>
                    <a href="wishlist_action.php?action=remove&trip_id=<?= $w['Trip_ID'] ?>&redirect=<?= urlencode('my_bookings.php?tab=wishlist') ?>"
                       class="btn-remove">Remove</a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Cancel confirmation modal -->
<div id="cancelModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:16px;padding:32px;max-width:400px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="font-size:48px;margin-bottom:12px;">🚫</div>
        <h3 style="font-size:18px;font-weight:800;color:#1a3a5c;margin-bottom:8px;">Cancel Booking?</h3>
        <p id="cancelRoute" style="font-size:14px;color:#666;margin-bottom:6px;"></p>
        <p style="font-size:13px;color:#888;margin-bottom:24px;">If paid via SSLCommerz, your refund will be processed within 3–5 working days.</p>
        <div style="display:flex;gap:12px;justify-content:center;">
            <button onclick="closeModal()" style="padding:11px 24px;background:white;color:#333;border:2px solid #ddd;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;">Keep Booking</button>
            <button onclick="doCancel()" style="padding:11px 24px;background:#e74c3c;color:white;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;">Yes, Cancel</button>
        </div>
    </div>
</div>

<!-- Hidden cancel form -->
<form id="cancelForm" method="POST" style="display:none;">
    <input type="hidden" name="cancel_booking" value="1">
    <input type="hidden" name="booking_id" id="cancelBookingId">
</form>

<script>
function showCancelModal(bookingId, route) {
    document.getElementById('cancelBookingId').value = bookingId;
    document.getElementById('cancelRoute').textContent = route;
    document.getElementById('cancelModal').style.display = 'flex';
}
function closeModal() {
    document.getElementById('cancelModal').style.display = 'none';
}
function doCancel() {
    document.getElementById('cancelModal').style.display = 'none';
    // Show loading state
    document.body.style.opacity = '0.7';
    document.getElementById('cancelForm').submit();
}
// Close modal on backdrop click
document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>