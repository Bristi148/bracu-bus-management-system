-- ================================================================
-- BRACU SMART BUS — COMPLETE DATABASE SCHEMA
-- CSE370 Database Systems Project | Spring 2026
-- ================================================================
-- This single file creates the full database from scratch:
-- tables, relationships, and sample seed data.
-- Run this once in phpMyAdmin or via: mysql -u root -p < bracu_bus.sql
-- ================================================================

DROP DATABASE IF EXISTS bracu_bus;
CREATE DATABASE bracu_bus;
USE bracu_bus;

-- ================================================================
-- TABLE: Passenger
-- ================================================================
CREATE TABLE Passenger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    Name VARCHAR(100) NOT NULL,
    Email VARCHAR(100) UNIQUE NOT NULL,
    Password VARCHAR(255) NOT NULL,
    type ENUM('Student','Faculty') NOT NULL DEFAULT 'Student',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ================================================================
-- TABLE: Route
-- ================================================================
CREATE TABLE Route (
    Route_ID INT AUTO_INCREMENT PRIMARY KEY,
    Stops VARCHAR(255) NOT NULL,
    Covered_Area VARCHAR(255) NOT NULL
);

-- ================================================================
-- TABLE: Bus
-- ================================================================
CREATE TABLE Bus (
    Bus_ID INT AUTO_INCREMENT PRIMARY KEY,
    Bus_Num VARCHAR(20) NOT NULL,
    Total_Seat INT NOT NULL,
    R_Flag TINYINT(1) DEFAULT 0,   -- 1 = Regular bus
    E_Flag TINYINT(1) DEFAULT 0,   -- 1 = Extra bus
    Route_ID INT,
    FOREIGN KEY (Route_ID) REFERENCES Route(Route_ID)
);

-- ================================================================
-- TABLE: Trip
-- ================================================================
CREATE TABLE Trip (
    Trip_ID INT AUTO_INCREMENT PRIMARY KEY,
    Arrived_Time TIME,
    Departure_Time TIME NOT NULL,
    Status ENUM('Scheduled','Running','Completed','Cancelled') DEFAULT 'Scheduled',
    Bus_ID INT,
    Date DATE NOT NULL,
    FOREIGN KEY (Bus_ID) REFERENCES Bus(Bus_ID)
);

-- ================================================================
-- TABLE: Wishlist
-- ================================================================
CREATE TABLE Wishlist (
    Wishlist_ID INT AUTO_INCREMENT PRIMARY KEY,
    Trip_ID INT,
    FOREIGN KEY (Trip_ID) REFERENCES Trip(Trip_ID)
);

-- ================================================================
-- TABLE: Booking
-- ================================================================
CREATE TABLE Booking (
    Booking_ID INT AUTO_INCREMENT PRIMARY KEY,
    Source VARCHAR(100) NOT NULL,
    Destination VARCHAR(100) NOT NULL,
    Date DATE NOT NULL,
    time TIME NOT NULL,
    Booked_Seat INT NOT NULL DEFAULT 1,
    Passenger_ID INT,
    Trip_ID INT,
    FOREIGN KEY (Passenger_ID) REFERENCES Passenger(id),
    FOREIGN KEY (Trip_ID) REFERENCES Trip(Trip_ID)
);

-- ================================================================
-- TABLE: Payment
-- ================================================================
CREATE TABLE Payment (
    Payment_ID INT AUTO_INCREMENT PRIMARY KEY,
    Booking_ID INT,
    Payment_Method VARCHAR(50) DEFAULT 'SSLCommerz',
    Amount DECIMAL(10,2) NOT NULL,
    Status VARCHAR(30) DEFAULT 'Pending',   -- Pending / Paid / Refund Pending / Refunded
    time TIME,
    Date DATE,
    FOREIGN KEY (Booking_ID) REFERENCES Booking(Booking_ID)
);

-- ================================================================
-- TABLE: Demand_Poll
-- ================================================================
CREATE TABLE Demand_Poll (
    Poll_ID INT AUTO_INCREMENT PRIMARY KEY,
    Route_ID INT,
    FOREIGN KEY (Route_ID) REFERENCES Route(Route_ID)
);

-- ================================================================
-- TABLE: Cancel  (M:N — Passenger cancels Booking)
-- ================================================================
CREATE TABLE Cancel (
    PID INT,
    Booking_ID INT,
    cancelled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (PID, Booking_ID),
    FOREIGN KEY (PID) REFERENCES Passenger(id),
    FOREIGN KEY (Booking_ID) REFERENCES Booking(Booking_ID)
);

-- ================================================================
-- TABLE: Make  (Passenger makes a Booking on a Bus)
-- ================================================================
CREATE TABLE Make (
    P_ID INT,
    Bus_ID INT,
    Booking_ID INT,
    PRIMARY KEY (P_ID, Bus_ID, Booking_ID),
    FOREIGN KEY (P_ID) REFERENCES Passenger(id),
    FOREIGN KEY (Bus_ID) REFERENCES Bus(Bus_ID),
    FOREIGN KEY (Booking_ID) REFERENCES Booking(Booking_ID)
);

-- ================================================================
-- TABLE: Do  (Passenger votes in Demand_Poll for a Bus)
-- ================================================================
CREATE TABLE `Do` (
    D_ID INT,
    P_ID INT,
    Bus_ID INT,
    Count INT DEFAULT 1,
    PRIMARY KEY (D_ID, P_ID),
    FOREIGN KEY (D_ID) REFERENCES Demand_Poll(Poll_ID),
    FOREIGN KEY (P_ID) REFERENCES Passenger(id),
    FOREIGN KEY (Bus_ID) REFERENCES Bus(Bus_ID)
);

-- ================================================================
-- TABLE: has_wishlist  (M:N — Passenger joins Wishlist for a Trip)
-- ================================================================
CREATE TABLE has_wishlist (
    P_ID INT,
    Trip_ID INT,
    Wishlist_ID INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (P_ID, Trip_ID),
    FOREIGN KEY (P_ID) REFERENCES Passenger(id),
    FOREIGN KEY (Trip_ID) REFERENCES Trip(Trip_ID),
    FOREIGN KEY (Wishlist_ID) REFERENCES Wishlist(Wishlist_ID)
);

-- ================================================================
-- TABLE: seat_notification
-- Tracks which passengers were notified that a seat opened up
-- ================================================================
CREATE TABLE seat_notification (
    P_ID INT,
    Trip_ID INT,
    notified_at DATETIME,
    PRIMARY KEY (P_ID, Trip_ID),
    FOREIGN KEY (P_ID) REFERENCES Passenger(id),
    FOREIGN KEY (Trip_ID) REFERENCES Trip(Trip_ID)
);

-- ================================================================
-- TABLE: seat_transfer
-- One passenger offers their booked seat to another passenger
-- ================================================================
CREATE TABLE seat_transfer (
    transfer_id INT AUTO_INCREMENT PRIMARY KEY,
    from_passenger_id INT NOT NULL,
    to_passenger_id INT NOT NULL,
    booking_id INT NOT NULL,
    status ENUM('Pending','Accepted','Declined') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME DEFAULT NULL,
    FOREIGN KEY (from_passenger_id) REFERENCES Passenger(id),
    FOREIGN KEY (to_passenger_id)   REFERENCES Passenger(id),
    FOREIGN KEY (booking_id)        REFERENCES Booking(Booking_ID)
);

-- ================================================================
-- SEED DATA
-- ================================================================

-- ── 3 Routes ─────────────────────────────────────────────────────
INSERT INTO Route (Route_ID, Stops, Covered_Area) VALUES
(1, 'Mohammadpur -> Asad Gate -> Shyamoli -> BRACU', 'Mohammadpur'),
(2, 'Uttara -> Airport Road -> Banani -> BRACU',      'Uttara'),
(3, 'Mirpur-10 -> Kazipara -> Agargaon -> BRACU',     'Mirpur');

-- ── 3 Regular Buses + 2 Extra Buses ─────────────────────────────
INSERT INTO Bus (Bus_ID, Bus_Num, Total_Seat, R_Flag, E_Flag, Route_ID) VALUES
(1, 'BRACU-01', 45, 1, 0, 1),
(2, 'BRACU-02', 45, 1, 0, 2),
(3, 'BRACU-03', 45, 1, 0, 3),
(4, 'BRACU-E1', 45, 0, 1, 1),
(5, 'BRACU-E2', 45, 0, 1, 2);

-- ── 9-Trip Full Daily Cycle for each regular bus (today) ────────
-- Pattern: 06:00-07:45-09:30-11:15-13:00-14:45-16:30-17:45-19:30-21:15

-- Bus 1 (Mohammadpur)
INSERT INTO Trip (Departure_Time, Arrived_Time, Status, Bus_ID, Date) VALUES
('06:00:00','07:45:00','Scheduled',1,CURDATE()),
('07:45:00','09:30:00','Scheduled',1,CURDATE()),
('09:30:00','11:15:00','Scheduled',1,CURDATE()),
('11:15:00','13:00:00','Scheduled',1,CURDATE()),
('13:00:00','14:45:00','Scheduled',1,CURDATE()),
('14:45:00','16:30:00','Scheduled',1,CURDATE()),
('16:30:00','17:45:00','Scheduled',1,CURDATE()),
('17:45:00','19:30:00','Scheduled',1,CURDATE()),
('19:30:00','21:15:00','Scheduled',1,CURDATE());

-- Bus 2 (Uttara)
INSERT INTO Trip (Departure_Time, Arrived_Time, Status, Bus_ID, Date) VALUES
('06:00:00','07:45:00','Scheduled',2,CURDATE()),
('07:45:00','09:30:00','Scheduled',2,CURDATE()),
('09:30:00','11:15:00','Scheduled',2,CURDATE()),
('11:15:00','13:00:00','Scheduled',2,CURDATE()),
('13:00:00','14:45:00','Scheduled',2,CURDATE()),
('14:45:00','16:30:00','Scheduled',2,CURDATE()),
('16:30:00','17:45:00','Scheduled',2,CURDATE()),
('17:45:00','19:30:00','Scheduled',2,CURDATE()),
('19:30:00','21:15:00','Scheduled',2,CURDATE());

-- Bus 3 (Mirpur)
INSERT INTO Trip (Departure_Time, Arrived_Time, Status, Bus_ID, Date) VALUES
('06:00:00','07:45:00','Scheduled',3,CURDATE()),
('07:45:00','09:30:00','Scheduled',3,CURDATE()),
('09:30:00','11:15:00','Scheduled',3,CURDATE()),
('11:15:00','13:00:00','Scheduled',3,CURDATE()),
('13:00:00','14:45:00','Scheduled',3,CURDATE()),
('14:45:00','16:30:00','Scheduled',3,CURDATE()),
('16:30:00','17:45:00','Scheduled',3,CURDATE()),
('17:45:00','19:30:00','Scheduled',3,CURDATE()),
('19:30:00','21:15:00','Scheduled',3,CURDATE());

-- ── Wishlist entry for every trip ───────────────────────────────
INSERT INTO Wishlist (Trip_ID) SELECT Trip_ID FROM Trip;

-- ── Demand polls for the 3 routes ───────────────────────────────
INSERT INTO Demand_Poll (Poll_ID, Route_ID) VALUES (1,1),(2,2),(3,3);

-- ── Default accounts ─────────────────────────────────────────────
-- Password for both accounts = 'password'
INSERT INTO Passenger (Name, Email, Password, type) VALUES
('Admin User',   'admin@bracu.ac.bd',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Faculty'),
('Test Student', 'student@bracu.ac.bd', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Student');

-- ================================================================
-- VERIFY SETUP
-- ================================================================
SELECT 'Routes'      AS tbl, COUNT(*) AS cnt FROM Route
UNION SELECT 'Buses',         COUNT(*) FROM Bus
UNION SELECT 'Trips (today)', COUNT(*) FROM Trip WHERE Date = CURDATE()
UNION SELECT 'Wishlists',     COUNT(*) FROM Wishlist
UNION SELECT 'Demand Polls',  COUNT(*) FROM Demand_Poll
UNION SELECT 'Passengers',    COUNT(*) FROM Passenger;
