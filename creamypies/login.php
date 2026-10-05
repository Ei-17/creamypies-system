<?php
require_once 'config.php';
require_once 'auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isLoggedIn()) {
    header('Location: pos.php');
    exit;
}

$error = '';
$justRegistered = isset($_GET['registered']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['user_Username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {

        $stmt = $conn->prepare("SELECT user_ID, user_Username, password_hash FROM users WHERE user_Username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_ID'] = $user['user_ID'];
            $_SESSION['user_Username'] = $user['user_Username'];
            header('Location: pos.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Tindahan Store System</title>
    <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
    <link rel="stylesheet" href="login.css">
</head>
<body>
    <div class="container" style="max-width:420px;">

        <?php if ($justRegistered): ?>
            <p class="success">Account created! You may now log in.</p>
        <?php endif; ?>

        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <img src="Assets/favicon/logo.png" alt="CreamypiesCream Logo" class="logo-image">

        <div class="login-box">
            <form action="login.php" method="POST">
                <h1>Hello CreampiesMaker!</h1>
                <h2>Sign in to continue to your dashboard</h2>

                <div class="email-box">
                    <input type="text"
                           placeholder="Username"
                           name="user_Username"
                           value="<?= htmlspecialchars($_POST['user_Username'] ?? '') ?>"
                           required
                           autofocus>
                    <i class="bx bx-user"></i>
                </div>

                <div class="password-box">
                    <input type="password"
                           placeholder="Password"
                           name="password"
                           required>
                    <i class="bx bx-lock"></i>
                </div>

                <button type="submit" class="button">Login</button>
            </form>
        </div>
    </div>
</body>
</html>