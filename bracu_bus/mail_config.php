<?php


define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_PORT',     587);
define('MAIL_USERNAME', 'mehnazatika2526@gmail.com');   
define('MAIL_PASSWORD', 'usvn bpdm fhbm wooq');    
define('MAIL_FROM',     'mehnazatika2526@gmail.com');   
define('MAIL_FROMNAME', 'BRACU Bus System');
define('MAIL_ENABLED',  true); 


if (file_exists(__DIR__ . '/PHPMailer/PHPMailer.php')) {
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
}


function sendWishlistNotification($to_email, $to_name, $bus_num, $route, $date, $dep_time, $book_url) {
    if (!MAIL_ENABLED) return false;

    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = MAIL_PORT;

    $mail->setFrom(MAIL_FROM, MAIL_FROMNAME);
    $mail->addAddress($to_email, $to_name);
    $mail->isHTML(true);
    $mail->Subject = '🔔 Seat Available — BRACU Bus ' . $bus_num;

    $mail->Body = "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;'>
        <div style='background:linear-gradient(135deg,#1a3a5c,#0d5c2e);padding:28px 24px;text-align:center;border-radius:12px 12px 0 0;'>
            <div style='font-size:48px;'>🔔</div>
            <h1 style='color:white;font-size:22px;margin:10px 0 4px;'>A Seat Just Opened Up!</h1>
            <p style='color:rgba(255,255,255,0.85);font-size:14px;margin:0;'>Your waitlisted trip has available seats</p>
        </div>
        <div style='background:white;padding:28px 24px;border-radius:0 0 12px 12px;border:1px solid #eee;'>
            <table style='width:100%;border-collapse:collapse;font-size:14px;'>
                <tr><td style='padding:8px 0;color:#888;'>Bus</td><td style='font-weight:700;'>🚌 $bus_num</td></tr>
                <tr><td style='padding:8px 0;color:#888;'>Route</td><td style='font-weight:700;'>$route</td></tr>
                <tr><td style='padding:8px 0;color:#888;'>Date</td><td style='font-weight:700;'>$date</td></tr>
                <tr><td style='padding:8px 0;color:#888;'>Departure</td><td style='font-weight:700;'>$dep_time</td></tr>
            </table>
            <div style='margin:24px 0;text-align:center;'>
                <a href='$book_url' style='background:linear-gradient(135deg,#1a3a5c,#0d5c2e);color:white;
                   padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;
                   display:inline-block;'>Book Seat Now →</a>
            </div>
            <p style='font-size:12px;color:#aaa;text-align:center;margin:0;'>
                Seats fill up fast — book immediately before someone else takes it.
            </p>
        </div>
    </div>";

    return $mail->send();
}


function sendBookingConfirmation($to_email, $to_name, $booking_id, $source, $dest, $date, $dep_time, $bus_num, $amount) {
    if (!MAIL_ENABLED) return false;

    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = MAIL_PORT;

    $mail->setFrom(MAIL_FROM, MAIL_FROMNAME);
    $mail->addAddress($to_email, $to_name);
    $mail->isHTML(true);
    $mail->Subject = '✅ Booking Confirmed — #' . str_pad($booking_id, 6, '0', STR_PAD_LEFT);

    $bid_padded  = str_pad($booking_id, 6, '0', STR_PAD_LEFT);
    $amount_text = $amount > 0 ? '৳' . $amount : 'Free';

    $mail->Body = "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;'>
        <div style='background:linear-gradient(135deg,#27ae60,#0d5c2e);padding:28px 24px;text-align:center;border-radius:12px 12px 0 0;'>
            <div style='font-size:48px;'>✅</div>
            <h1 style='color:white;font-size:22px;margin:10px 0 4px;'>Booking Confirmed!</h1>
            <p style='color:rgba(255,255,255,0.85);font-size:14px;margin:0;'>Your seat has been reserved</p>
        </div>
        <div style='background:white;padding:28px 24px;border-radius:0 0 12px 12px;border:1px solid #eee;'>
            <div style='text-align:center;margin-bottom:20px;'>
                <div style='font-size:32px;font-weight:800;color:#1a3a5c;letter-spacing:2px;'>#$bid_padded</div>
                <div style='font-size:12px;color:#aaa;'>Booking ID</div>
            </div>
            <table style='width:100%;border-collapse:collapse;font-size:14px;'>
                <tr><td style='padding:8px 0;color:#888;border-bottom:1px solid #f5f5f5;'>Route</td><td style='font-weight:700;border-bottom:1px solid #f5f5f5;'>$source → $dest</td></tr>
                <tr><td style='padding:8px 0;color:#888;border-bottom:1px solid #f5f5f5;'>Date</td><td style='font-weight:700;border-bottom:1px solid #f5f5f5;'>$date</td></tr>
                <tr><td style='padding:8px 0;color:#888;border-bottom:1px solid #f5f5f5;'>Departure</td><td style='font-weight:700;border-bottom:1px solid #f5f5f5;'>$dep_time</td></tr>
                <tr><td style='padding:8px 0;color:#888;border-bottom:1px solid #f5f5f5;'>Bus</td><td style='font-weight:700;border-bottom:1px solid #f5f5f5;'>🚌 $bus_num</td></tr>
                <tr><td style='padding:8px 0;color:#888;'>Amount Paid</td><td style='font-weight:700;color:#27ae60;'>$amount_text</td></tr>
            </table>
            <p style='font-size:12px;color:#aaa;text-align:center;margin-top:20px;'>
                Show this email or your Booking ID to the driver. Have a safe trip! 🚌
            </p>
        </div>
    </div>";

    return $mail->send();
}


function sendCancellationEmail($to_email, $to_name, $booking_id, $source, $dest, $date, $amount) {
    if (!MAIL_ENABLED) return false;

    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = MAIL_PORT;

    $mail->setFrom(MAIL_FROM, MAIL_FROMNAME);
    $mail->addAddress($to_email, $to_name);
    $mail->isHTML(true);
    $mail->Subject = '❌ Booking Cancelled — #' . str_pad($booking_id, 6, '0', STR_PAD_LEFT);

    $bid_padded  = str_pad($booking_id, 6, '0', STR_PAD_LEFT);
    $refund_text = $amount > 0
        ? "Your refund of <strong>৳$amount</strong> will be processed within 3–5 working days."
        : "No payment was charged.";

    $mail->Body = "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;'>
        <div style='background:linear-gradient(135deg,#e74c3c,#c0392b);padding:28px 24px;text-align:center;border-radius:12px 12px 0 0;'>
            <div style='font-size:48px;'>❌</div>
            <h1 style='color:white;font-size:22px;margin:10px 0 4px;'>Booking Cancelled</h1>
            <p style='color:rgba(255,255,255,0.85);font-size:14px;margin:0;'>Booking #$bid_padded has been cancelled</p>
        </div>
        <div style='background:white;padding:28px 24px;border-radius:0 0 12px 12px;border:1px solid #eee;'>
            <table style='width:100%;border-collapse:collapse;font-size:14px;'>
                <tr><td style='padding:8px 0;color:#888;'>Route</td><td style='font-weight:700;'>$source → $dest</td></tr>
                <tr><td style='padding:8px 0;color:#888;'>Date</td><td style='font-weight:700;'>$date</td></tr>
            </table>
            <div style='background:#fef3c7;border-radius:8px;padding:14px;margin-top:20px;font-size:13px;color:#78350f;'>
                ⏳ $refund_text
            </div>
        </div>
    </div>";

    return $mail->send();
}


function sendExtraBusNotification($to_email, $to_name, $bus_num, $route, $date, $dep_time, $arr_time, $book_url) {
    if (!MAIL_ENABLED) return false;
    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host = MAIL_HOST; $mail->SMTPAuth = true;
    $mail->Username = MAIL_USERNAME; $mail->Password = MAIL_PASSWORD;
    $mail->SMTPSecure = 'tls'; $mail->Port = MAIL_PORT;
    $mail->setFrom(MAIL_FROM, MAIL_FROMNAME);
    $mail->addAddress($to_email, $to_name);
    $mail->isHTML(true);
    $mail->Subject = '⚡ Extra Bus Scheduled — ' . $route;
    $mail->Body = "<div style='font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;'><div style='background:linear-gradient(135deg,#e67e22,#d35400);padding:28px 24px;text-align:center;border-radius:12px 12px 0 0;'><div style='font-size:48px;'>⚡</div><h1 style='color:white;font-size:22px;margin:10px 0 4px;'>Extra Bus Scheduled!</h1><p style='color:rgba(255,255,255,0.85);font-size:14px;margin:0;'>Your demand vote worked!</p></div><div style='background:white;padding:28px 24px;border-radius:0 0 12px 12px;border:1px solid #eee;'><table style='width:100%;font-size:14px;'><tr><td style='padding:8px 0;color:#888;'>Bus</td><td style='font-weight:700;'>$bus_num</td></tr><tr><td style='padding:8px 0;color:#888;'>Route</td><td style='font-weight:700;'>$route</td></tr><tr><td style='padding:8px 0;color:#888;'>Date</td><td style='font-weight:700;'>$date</td></tr><tr><td style='padding:8px 0;color:#888;'>Departure</td><td style='font-weight:700;'>$dep_time</td></tr></table><div style='text-align:center;margin-top:24px;'><a href='$book_url' style='background:#1a3a5c;color:white;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;display:inline-block;'>Book Your Seat →</a></div></div></div>";
    return $mail->send();
}