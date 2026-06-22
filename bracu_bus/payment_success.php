<?php

session_start();
require_once 'db_config.php';
if (file_exists(__DIR__ . '/mail_config.php')) require_once 'mail_config.php';
if (!function_exists('sendBookingConfirmation')) {
    function sendBookingConfirmation(...$a) { return false; }
}

$conn = getDB();

$val_id    = $_POST['val_id']    ?? $_GET['val_id']    ?? '';
$tran_id   = $_POST['tran_id']   ?? $_GET['tran_id']   ?? '';
$amount    = $_POST['amount']    ?? $_GET['amount']    ?? 0;
$card_type = $_POST['card_type'] ?? $_GET['card_type'] ?? '';

$store_id   = '';
$store_pass = '';


$booking_id = 0;
if (preg_match('/BRACU-(\d+)-/', $tran_id, $m)) {
    $booking_id = (int)$m[1];
}


$validated = false;
if ($val_id) {
    $vurl   = "https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php?val_id=$val_id&store_id=$store_id&store_passwd=$store_pass&format=json";
    $ch     = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL=>$vurl, CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>false, CURLOPT_TIMEOUT=>15]);
    $res    = curl_exec($ch);
    curl_close($ch);
    $json   = json_decode($res, true);
    $validated = ($json && $json['status'] === 'VALID');
}

if ($booking_id > 0) {
    
    $pay_method = $validated ? ($card_type ?: 'SSLCommerz') : 'SSLCommerz';
    $conn->execute_query("UPDATE Payment SET Status='Paid', Payment_Method=? WHERE Booking_ID=?", [$pay_method, $booking_id]);

    
    if (!isset($_SESSION['passenger_id'])) {
        $row = $conn->prepare("SELECT Passenger_ID FROM Booking WHERE Booking_ID=?");
        $row->bind_param("i", $booking_id);
        $row->execute();
        $br = $row->get_result()->fetch_assoc();
        if ($br) {
            $pr = $conn->prepare("SELECT id, Name, type, Email FROM Passenger WHERE id=?");
            $pr->bind_param("i", $br['Passenger_ID']);
            $pr->execute();
            $passenger = $pr->get_result()->fetch_assoc();
            if ($passenger) {
                $_SESSION['passenger_id'] = $passenger['id'];
                $_SESSION['name']         = $passenger['Name'];
                $_SESSION['type']         = $passenger['type'];
                $_SESSION['email']        = $passenger['Email'];
            }
        }
    }

    
    $bq = $conn->prepare(
        "SELECT b.*, t.Departure_Time, t.Arrived_Time, bus.Bus_Num,
                p.Name, p.Email, pay.Amount
         FROM Booking b
         JOIN Trip t ON b.Trip_ID = t.Trip_ID
         JOIN Bus bus ON t.Bus_ID = bus.Bus_ID
         JOIN Passenger p ON b.Passenger_ID = p.id
         LEFT JOIN Payment pay ON pay.Booking_ID = b.Booking_ID
         WHERE b.Booking_ID = ?"
    );
    $bq->bind_param("i", $booking_id);
    $bq->execute();
    $bdata = $bq->get_result()->fetch_assoc();

    if ($bdata) {
        sendBookingConfirmation(
            $bdata['Email'],
            $bdata['Name'],
            $booking_id,
            $bdata['Source'],
            $bdata['Destination'],
            date('D, d M Y', strtotime($bdata['Date'])),
            date('g:i A', strtotime($bdata['Departure_Time'])),
            $bdata['Bus_Num'],
            $bdata['Amount'] ?? 100
        );
    }
}
$conn->close();
header("Location: booking_success.php?booking_id=$booking_id");
exit();
