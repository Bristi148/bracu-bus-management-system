<?php

session_start();
require_once 'db_config.php';
requireLogin();

$conn = getDB();
ensureTransferTable($conn);

$pid = $_SESSION['passenger_id'];
$msg = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initiate'])) {
    $booking_id  = (int)$_POST['booking_id'];
    $to_email    = trim($_POST['to_email']);

    
    $chk = $conn->prepare(
        "SELECT b.Booking_ID FROM Booking b
         WHERE b.Booking_ID=? AND b.Passenger_ID=?
           AND b.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)"
    );
    $chk->bind_param("ii", $booking_id, $pid);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $msg = "error:Invalid booking or already cancelled.";
    } else {
        
        $rec = $conn->prepare("SELECT id, Name FROM Passenger WHERE Email=? AND id != ?");
        $rec->bind_param("si", $to_email, $pid);
        $rec->execute();
        $recipient = $rec->get_result()->fetch_assoc();

        if (!$recipient) {
            $msg = "error:No student found with that email address.";
        } else {
            
            $dup = $conn->prepare("SELECT 1 FROM seat_transfer WHERE booking_id=? AND status='Pending'");
            $dup->bind_param("i", $booking_id);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $msg = "error:A transfer request for this booking is already pending.";
            } else {
                $ins = $conn->prepare(
                    "INSERT INTO seat_transfer (from_passenger_id, to_passenger_id, booking_id)
                     VALUES (?,?,?)"
                );
                $ins->bind_param("iii", $pid, $recipient['id'], $booking_id);
                $ins->execute();
                $msg = "success:Transfer request sent to {$recipient['Name']}! They will see it on their dashboard.";
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept'])) {
    $transfer_id = (int)$_POST['transfer_id'];

    $chk = $conn->prepare(
        "SELECT * FROM seat_transfer WHERE transfer_id=? AND to_passenger_id=? AND status='Pending'"
    );
    $chk->bind_param("ii", $transfer_id, $pid);
    $chk->execute();
    $transfer = $chk->get_result()->fetch_assoc();

    if (!$transfer) {
        $msg = "error:Transfer not found or already responded.";
    } else {
        $conn->begin_transaction();
        try {
            $booking_id   = $transfer['booking_id'];
            $from_pid     = $transfer['from_passenger_id'];

            
            $conn->execute_query(
                "UPDATE Booking SET Passenger_ID=? WHERE Booking_ID=?",
                [$pid, $booking_id]
            );
            
            $conn->execute_query(
                "UPDATE Make SET P_ID=? WHERE Booking_ID=?",
                [$pid, $booking_id]
            );
            
            $conn->execute_query(
                "UPDATE seat_transfer SET status='Accepted', responded_at=NOW() WHERE transfer_id=?",
                [$transfer_id]
            );
            
            $conn->execute_query(
                "UPDATE seat_transfer SET status='Declined', responded_at=NOW()
                 WHERE booking_id=? AND transfer_id!=? AND status='Pending'",
                [$booking_id, $transfer_id]
            );
            $conn->commit();
            $msg = "success:Seat accepted! The booking is now in your account.";
        } catch (Exception $e) {
            $conn->rollback();
            $msg = "error:Transfer failed. Please try again.";
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decline'])) {
    $transfer_id = (int)$_POST['transfer_id'];
    $conn->execute_query(
        "UPDATE seat_transfer SET status='Declined', responded_at=NOW()
         WHERE transfer_id=? AND to_passenger_id=? AND status='Pending'",
        [$transfer_id, $pid]
    );
    $msg = "success:Transfer declined. The booking stays with the original owner.";
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_transfer'])) {
    $transfer_id = (int)$_POST['transfer_id'];
    $conn->execute_query(
        "UPDATE seat_transfer SET status='Declined', responded_at=NOW()
         WHERE transfer_id=? AND from_passenger_id=? AND status='Pending'",
        [$transfer_id, $pid]
    );
    $msg = "success:Transfer request cancelled.";
}


$my_bookings = $conn->prepare(
    "SELECT b.Booking_ID, b.Source, b.Destination, b.Date, b.time,
            t.Departure_Time, t.Arrived_Time, bus.Bus_Num,
            st.transfer_id, st.status as transfer_status,
            p2.Name as transfer_to
     FROM Booking b
     JOIN Trip t ON b.Trip_ID = t.Trip_ID
     JOIN Bus bus ON t.Bus_ID = bus.Bus_ID
     LEFT JOIN seat_transfer st ON st.booking_id = b.Booking_ID AND st.status='Pending'
     LEFT JOIN Passenger p2 ON st.to_passenger_id = p2.id
     WHERE b.Passenger_ID=?
       AND b.Booking_ID NOT IN (SELECT Booking_ID FROM Cancel)
     ORDER BY b.Date DESC"
);
$my_bookings->bind_param("i", $pid);
$my_bookings->execute();
$bookings = $my_bookings->get_result()->fetch_all(MYSQLI_ASSOC);


$incoming = $conn->prepare(
    "SELECT st.transfer_id, st.created_at,
            b.Booking_ID, b.Source, b.Destination, b.Date,
            t.Departure_Time, t.Arrived_Time, bus.Bus_Num,
            p.Name as from_name, p.Email as from_email
     FROM seat_transfer st
     JOIN Booking b  ON st.booking_id = b.Booking_ID
     JOIN Trip t     ON b.Trip_ID = t.Trip_ID
     JOIN Bus bus    ON t.Bus_ID = bus.Bus_ID
     JOIN Passenger p ON st.from_passenger_id = p.id
     WHERE st.to_passenger_id=? AND st.status='Pending'
     ORDER BY st.created_at DESC"
);
$incoming->bind_param("i", $pid);
$incoming->execute();
$incoming_requests = $incoming->get_result()->fetch_all(MYSQLI_ASSOC);


$history = $conn->prepare(
    "SELECT st.*, st.status, st.created_at, st.responded_at,
            b.Source, b.Destination, b.Date,
            t.Departure_Time, bus.Bus_Num,
            pf.Name as from_name, pt.Name as to_name
     FROM seat_transfer st
     JOIN Booking b   ON st.booking_id = b.Booking_ID
     JOIN Trip t      ON b.Trip_ID = t.Trip_ID
     JOIN Bus bus     ON t.Bus_ID = bus.Bus_ID
     JOIN Passenger pf ON st.from_passenger_id = pf.id
     JOIN Passenger pt ON st.to_passenger_id = pt.id
     WHERE (st.from_passenger_id=? OR st.to_passenger_id=?)
       AND st.status != 'Pending'
     ORDER BY st.responded_at DESC LIMIT 10"
);
$history->bind_param("ii", $pid, $pid);
$history->execute();
$transfer_history = $history->get_result()->fetch_all(MYSQLI_ASSOC);

$conn->close();
[$msg_type, $msg_text] = $msg ? explode(':', $msg, 2) : ['',''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seat Transfer - BRACU Bus</title>
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
nav .nl { display: flex; gap: 10px; }
nav a { color: white; text-decoration: none; font-size: 13px; padding: 6px 14px;
        border: 1px solid rgba(255,255,255,0.4); border-radius: 20px; transition: background 0.2s; }
nav a:hover { background: rgba(255,255,255,0.15); }
.page { max-width: 860px; margin: 0 auto; padding: 24px 20px; }
.page-title { font-size: 22px; font-weight: 800; color: #1a3a5c; margin-bottom: 4px; }
.page-sub   { font-size: 13px; color: #888; margin-bottom: 24px; }
.alert { padding: 13px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; }
.alert-success { background: #d4edda; color: #155724; border-left: 4px solid #27ae60; }
.alert-error   { background: #ffeaea; color: #c0392b; border-left: 4px solid #e74c3c; }
.section-title { font-size: 16px; font-weight: 700; color: #1a3a5c; margin: 24px 0 14px;
                 display: flex; align-items: center; gap: 8px; }
.badge-count { background: #1a3a5c; color: white; font-size: 12px;
               padding: 2px 8px; border-radius: 10px; }

/* Incoming request cards */
.incoming-card {
    background: white; border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    border-left: 5px solid #e67e22;
    padding: 20px 24px; margin-bottom: 14px;
}
.incoming-card .from { font-size: 14px; color: #555; margin-bottom: 12px; }
.incoming-card .from strong { color: #1a3a5c; }
.trip-details { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 16px; }
.trip-detail  { font-size: 13px; color: #555; display: flex; align-items: center; gap: 5px; }
.trip-detail .icon { font-size: 15px; }
.accept-decline { display: flex; gap: 10px; }
.btn-accept {
    padding: 10px 24px; background: linear-gradient(135deg, #27ae60, #1e8449);
    color: white; border: none; border-radius: 8px; font-size: 14px;
    font-weight: 700; cursor: pointer; transition: opacity 0.2s;
}
.btn-accept:hover { opacity: 0.85; }
.btn-decline {
    padding: 10px 24px; background: white; color: #e74c3c;
    border: 2px solid #e74c3c; border-radius: 8px; font-size: 14px;
    font-weight: 700; cursor: pointer; transition: background 0.2s;
}
.btn-decline:hover { background: #ffeaea; }

/* My booking cards for transfer */
.booking-card {
    background: white; border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    padding: 18px 22px; margin-bottom: 12px;
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
}
.booking-info { flex: 1; }
.booking-route { font-size: 15px; font-weight: 800; color: #1a3a5c; margin-bottom: 4px; }
.booking-meta  { font-size: 13px; color: #888; }
.transfer-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.transfer-form input[type="email"] {
    padding: 9px 12px; border: 1.5px solid #dde2ea; border-radius: 8px;
    font-size: 13px; outline: none; min-width: 220px;
}
.transfer-form input[type="email"]:focus { border-color: #1a3a5c; }
.btn-transfer {
    padding: 9px 18px; background: #1a3a5c; color: white;
    border: none; border-radius: 8px; font-size: 13px;
    font-weight: 700; cursor: pointer; white-space: nowrap; transition: opacity 0.2s;
}
.btn-transfer:hover { opacity: 0.85; }
.pending-badge {
    display: flex; align-items: center; gap: 8px;
    background: #fff3cd; color: #856404;
    padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 600;
}
.btn-cancel-transfer {
    padding: 6px 12px; background: white; color: #e74c3c;
    border: 1.5px solid #e74c3c; border-radius: 7px; font-size: 12px;
    font-weight: 700; cursor: pointer;
}

/* History */
.history-card {
    background: white; border-radius: 10px;
    box-shadow: 0 1px 6px rgba(0,0,0,0.06);
    padding: 14px 18px; margin-bottom: 10px;
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
}
.history-info { flex: 1; font-size: 13px; color: #555; }
.history-info strong { color: #333; }
.h-accepted { background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; }
.h-declined { background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; }
.empty { text-align: center; color: #bbb; padding: 28px; font-size: 14px; }
</style>
</head>
<body>
<nav>
    <div class="brand">🚌 BRACU Bus System</div>
    <div class="nl">
        <a href="my_bookings.php">My Bookings</a>
        <a href="wishlist.php">❤️ Wishlist</a>
        <a href="dashboard.php">← Dashboard</a>
    </div>
</nav>

<div class="page">
    <div class="page-title">🔄 Seat Transfer</div>
    <div class="page-sub">Transfer your booked seat to another student, or accept a seat someone offered you</div>

    <?php if ($msg_text): ?>
    <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($msg_text) ?></div>
    <?php endif; ?>

    <!-- ── Incoming Requests ── -->
    <?php if (!empty($incoming_requests)): ?>
    <div class="section-title">
        🔔 Seat Offers For You
        <span class="badge-count"><?= count($incoming_requests) ?></span>
    </div>
    <?php foreach ($incoming_requests as $req): ?>
    <div class="incoming-card">
        <div class="from">
            <strong><?= htmlspecialchars($req['from_name']) ?></strong>
            (<?= htmlspecialchars($req['from_email']) ?>)
            wants to give you their seat:
        </div>
        <div class="trip-details">
            <div class="trip-detail"><span class="icon">🚌</span><?= htmlspecialchars($req['Bus_Num']) ?></div>
            <div class="trip-detail"><span class="icon">📍</span><?= htmlspecialchars($req['Source']) ?> → <?= htmlspecialchars($req['Destination']) ?></div>
            <div class="trip-detail"><span class="icon">📅</span><?= date('D, d M Y', strtotime($req['Date'])) ?></div>
            <div class="trip-detail"><span class="icon">🕐</span>Departs <?= date('g:i A', strtotime($req['Departure_Time'])) ?></div>
            <div class="trip-detail"><span class="icon">🏁</span>Arrives <?= date('g:i A', strtotime($req['Arrived_Time'])) ?></div>
        </div>
        <div class="accept-decline">
            <form method="POST" style="display:inline">
                <input type="hidden" name="transfer_id" value="<?= $req['transfer_id'] ?>">
                <button type="submit" name="accept" class="btn-accept">✓ Accept Seat</button>
            </form>
            <form method="POST" style="display:inline">
                <input type="hidden" name="transfer_id" value="<?= $req['transfer_id'] ?>">
                <button type="submit" name="decline" class="btn-decline">✕ Decline</button>
            </form>
        </div>
        <div style="font-size:12px;color:#aaa;margin-top:10px;">
            Requested <?= date('d M Y, g:i A', strtotime($req['created_at'])) ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ── My Bookings — Transfer ── -->
    <div class="section-title">🎫 Transfer One of Your Seats</div>
    <?php if (empty($bookings)): ?>
        <div class="empty">You have no active bookings to transfer.</div>
    <?php else: ?>
        <?php foreach ($bookings as $b): ?>
        <div class="booking-card">
            <div class="booking-info">
                <div class="booking-route"><?= htmlspecialchars($b['Source']) ?> → <?= htmlspecialchars($b['Destination']) ?></div>
                <div class="booking-meta">
                    🚌 <?= htmlspecialchars($b['Bus_Num']) ?> ·
                    <?= date('D d M Y', strtotime($b['Date'])) ?> ·
                    Departs <?= date('g:i A', strtotime($b['Departure_Time'])) ?> ·
                    #<?= str_pad($b['Booking_ID'],6,'0',STR_PAD_LEFT) ?>
                </div>
            </div>
            <?php if ($b['transfer_id']): ?>
                <!-- Already has pending transfer -->
                <div class="pending-badge">
                    ⏳ Pending — sent to <?= htmlspecialchars($b['transfer_to']) ?>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="transfer_id" value="<?= $b['transfer_id'] ?>">
                        <button type="submit" name="cancel_transfer" class="btn-cancel-transfer">Cancel</button>
                    </form>
                </div>
            <?php else: ?>
                <form method="POST" class="transfer-form">
                    <input type="hidden" name="booking_id" value="<?= $b['Booking_ID'] ?>">
                    <input type="email" name="to_email" placeholder="Enter student's email" required>
                    <button type="submit" name="initiate" class="btn-transfer">🔄 Transfer Seat</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- ── Transfer History ── -->
    <?php if (!empty($transfer_history)): ?>
    <div class="section-title">📋 Transfer History</div>
    <?php foreach ($transfer_history as $h): ?>
    <div class="history-card">
        <div class="history-info">
            <strong><?= htmlspecialchars($h['Source']) ?> → <?= htmlspecialchars($h['Destination']) ?></strong>
            · <?= htmlspecialchars($h['Bus_Num']) ?>
            · <?= date('d M Y', strtotime($h['Date'])) ?>
            · <?= date('g:i A', strtotime($h['Departure_Time'])) ?>
            <div style="margin-top:4px;color:#aaa;">
                <?= htmlspecialchars($h['from_name']) ?> → <?= htmlspecialchars($h['to_name']) ?>
                · <?= $h['responded_at'] ? date('d M Y', strtotime($h['responded_at'])) : '' ?>
            </div>
        </div>
        <span class="h-<?= strtolower($h['status']) ?>"><?= $h['status'] ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>