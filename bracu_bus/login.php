<?php
// login.php - Signup & Login page
session_start();
if (isset($_SESSION['passenger_id'])) {
    header("Location: dashboard.php");
    exit();
}
require_once 'db_config.php';

$error = '';
$success = '';
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'login';

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'login') {
        $email    = trim($_POST['email']);
        $password = $_POST['password'];

        $conn = getDB();
        $stmt = $conn->prepare("SELECT id, Name, Password, type FROM Passenger WHERE Email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['Password'])) {
            $_SESSION['passenger_id'] = $user['id'];
            $_SESSION['name']         = $user['Name'];
            $_SESSION['type']         = $user['type'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid email or password.";
            $mode  = 'login';
        }
        $conn->close();

    } elseif ($_POST['action'] === 'signup') {
        $name     = trim($_POST['name']);
        $email    = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm  = $_POST['confirm'];
        $type     = $_POST['type'];

        if (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
            $mode  = 'signup';
        } elseif ($password !== $confirm) {
            $error = "Passwords do not match.";
            $mode  = 'signup';
        } else {
            $conn = getDB();
            $check = $conn->prepare("SELECT id FROM Passenger WHERE Email=?");
            $check->bind_param("s", $email);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = "An account with this email already exists.";
                $mode  = 'signup';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins  = $conn->prepare("INSERT INTO Passenger (Name, Email, Password, type) VALUES (?,?,?,?)");
                $ins->bind_param("ssss", $name, $email, $hash, $type);
                if ($ins->execute()) {
                    $success = "Account created! You can now log in.";
                    $mode    = 'login';
                } else {
                    $error = "Registration failed. Please try again.";
                    $mode  = 'signup';
                }
            }
            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BRACU Bus System - Login</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: linear-gradient(135deg, #1a3a5c 0%, #0d5c2e 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.container {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    width: 100%;
    max-width: 440px;
    overflow: hidden;
}
.header {
    background: linear-gradient(135deg, #1a3a5c, #0d5c2e);
    color: white;
    text-align: center;
    padding: 32px 24px 24px;
}
.header img { width: 64px; margin-bottom: 12px; }
.header h1 { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
.header p  { font-size: 13px; opacity: 0.85; }
.tabs {
    display: flex;
    border-bottom: 2px solid #e8e8e8;
}
.tab-btn {
    flex: 1;
    padding: 14px;
    border: none;
    background: #f8f8f8;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    color: #888;
    transition: all 0.2s;
}
.tab-btn.active {
    background: #fff;
    color: #1a3a5c;
    border-bottom: 3px solid #1a3a5c;
}
.form-area { padding: 28px 32px 32px; }
.alert {
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 18px;
}
.alert-error   { background: #ffeaea; color: #c0392b; border-left: 4px solid #e74c3c; }
.alert-success { background: #eafaf1; color: #1a7f45; border-left: 4px solid #27ae60; }
.form-group { margin-bottom: 16px; }
.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #444;
    margin-bottom: 6px;
}
.form-group input,
.form-group select {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid #dde2ea;
    border-radius: 8px;
    font-size: 14px;
    color: #333;
    transition: border-color 0.2s;
    outline: none;
}
.form-group input:focus,
.form-group select:focus { border-color: #1a3a5c; }
.row2 { display: flex; gap: 14px; }
.row2 .form-group { flex: 1; }
.btn-submit {
    width: 100%;
    padding: 13px;
    background: linear-gradient(135deg, #1a3a5c, #0d5c2e);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    margin-top: 6px;
    transition: opacity 0.2s;
}
.btn-submit:hover { opacity: 0.88; }
.switch-text {
    text-align: center;
    margin-top: 18px;
    font-size: 13px;
    color: #888;
}
.switch-text a { color: #1a3a5c; font-weight: 600; text-decoration: none; }
.hint { font-size: 12px; color: #aaa; margin-top: 14px; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div style="font-size:48px;">🚌</div>
        <h1>BRACU Bus System</h1>
        <p>Online bus booking for students &amp; faculty</p>
    </div>
    <div class="tabs">
        <button class="tab-btn <?= $mode==='login'?'active':'' ?>"
                onclick="window.location='login.php?mode=login'">Log In</button>
        <button class="tab-btn <?= $mode==='signup'?'active':'' ?>"
                onclick="window.location='login.php?mode=signup'">Sign Up</button>
    </div>
    <div class="form-area">
        <?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <?php if ($mode === 'login'): ?>
        <form method="POST" autocomplete="on">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="your@bracu.ac.bd" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn-submit">Log In</button>
        </form>
        <div class="switch-text">Don't have an account? <a href="login.php?mode=signup">Sign Up</a></div>
        <p class="hint">Demo: student@bracu.ac.bd / password</p>

        <?php else: ?>
        <form method="POST" autocomplete="on">
            <input type="hidden" name="action" value="signup">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" placeholder="e.g. Rahim Uddin" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="your@bracu.ac.bd" required>
            </div>
            <div class="row2">
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Min 6 chars" required>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm" placeholder="Repeat" required>
                </div>
            </div>
            <div class="form-group">
                <label>I am a</label>
                <select name="type">
                    <option value="Student">Student</option>
                    <option value="Faculty">Faculty</option>
                </select>
            </div>
            <button type="submit" class="btn-submit">Create Account</button>
        </form>
        <div class="switch-text">Already have an account? <a href="login.php?mode=login">Log In</a></div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>