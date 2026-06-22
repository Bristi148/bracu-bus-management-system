<?php


define('DB_HOST', 'localhost');
define('DB_USER', 'root');       
define('DB_PASS', '');           
define('DB_NAME', 'bracu_bus');

function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8");
   
    $conn->query("CREATE TABLE IF NOT EXISTS seat_notification (
        P_ID INT, Trip_ID INT, notified_at DATETIME,
        PRIMARY KEY (P_ID, Trip_ID),
        FOREIGN KEY (P_ID) REFERENCES Passenger(id),
        FOREIGN KEY (Trip_ID) REFERENCES Trip(Trip_ID)
    )");
   
    $conn->query("ALTER TABLE Cancel DROP FOREIGN KEY IF EXISTS cancel_ibfk_2");
    return $conn;
}


function requireLogin() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['passenger_id'])) {
        header("Location: login.php");
        exit();
    }
}


function getRemainingSeats($conn, $trip_id) {
    $sql = "SELECT b.Total_Seat, COALESCE(SUM(bk.Booked_Seat),0) as booked
            FROM Trip t
            JOIN Bus b ON t.Bus_ID = b.Bus_ID
            LEFT JOIN Booking bk ON bk.Trip_ID = t.Trip_ID
            WHERE t.Trip_ID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $trip_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row['Total_Seat'] - $row['booked'];
}


function isOnWishlist($conn, $passenger_id, $trip_id) {
    $stmt = $conn->prepare("SELECT 1 FROM has_wishlist WHERE P_ID=? AND Trip_ID=?");
    $stmt->bind_param("ii", $passenger_id, $trip_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}


function ensureTransferTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS seat_transfer (
        transfer_id INT AUTO_INCREMENT PRIMARY KEY,
        from_passenger_id INT NOT NULL,
        to_passenger_id INT NOT NULL,
        booking_id INT NOT NULL,
        status ENUM('Pending','Accepted','Declined') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        responded_at DATETIME DEFAULT NULL,
        FOREIGN KEY (from_passenger_id) REFERENCES Passenger(id),
        FOREIGN KEY (to_passenger_id)   REFERENCES Passenger(id)
    )");
}
?>