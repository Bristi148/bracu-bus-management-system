<?php

session_start();
require_once 'db_config.php';
requireLogin();

$action   = $_GET['action'] ?? 'add';
$trip_id  = (int)($_GET['trip_id'] ?? 0);
$redirect = urldecode($_GET['redirect'] ?? 'dashboard.php');
$pid      = $_SESSION['passenger_id'];

if ($trip_id > 0) {
    $conn = getDB();

    if ($action === 'add') {
        
        $stmt = $conn->prepare("SELECT Wishlist_ID FROM Wishlist WHERE Trip_ID=?");
        $stmt->bind_param("i", $trip_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            $wid = $row['Wishlist_ID'];
        } else {
            
            $ins = $conn->prepare("INSERT INTO Wishlist (Trip_ID) VALUES (?)");
            $ins->bind_param("i", $trip_id);
            $ins->execute();
            $wid = $conn->insert_id;
        }

        
        $ins2 = $conn->prepare("INSERT IGNORE INTO has_wishlist (P_ID, Trip_ID, Wishlist_ID) VALUES (?,?,?)");
        $ins2->bind_param("iii", $pid, $trip_id, $wid);
        $ins2->execute();

    } elseif ($action === 'remove') {
        $del = $conn->prepare("DELETE FROM has_wishlist WHERE P_ID=? AND Trip_ID=?");
        $del->bind_param("ii", $pid, $trip_id);
        $del->execute();
    }

    $conn->close();
}

header("Location: $redirect");
exit();