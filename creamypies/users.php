<?php
require_once 'config.php';
require_once 'auth.php';

$errors = [];
$username = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate empty fields
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        $errors[] = 'All fields are required.';
    }

    if (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters long.';
    }

    // Validate email format
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Validate password length
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }

    // Validate password match
    if ($password !== $confirm_password) {
        $errors[] = 'Password and Confirm Password do not match.';
    }

    // Check if username or email already exists
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_ID FROM users WHERE user_Username = ? OR user_Email = ?");
        $stmt->bind_param('ss', $username, $email);
        $stmt->execute();
        $check = $stmt->get_result();

        if ($check->num_rows > 0) {
            $errors[] = 'That username or email is already registered.';
        }
        $stmt->close();
    }

    // If everything passes, hash the password and insert
    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (user_Username, user_Email, password_hash) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $username, $email, $hashed);

        if ($stmt->execute()) {
            $stmt->close();
            header('Location: users.php?created=1');
            exit;
        } else {
            $errors[] = 'Error: ' . $stmt->error;
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Staff - Tindahan Store System</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>
    <div class="form-container">
        <?php if (!empty($errors)): ?>
            <ul class="errors">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST">
            <label>Username</label>
            <input type="text" name="username" required value="<?= htmlspecialchars($username) ?>" placeholder="e.g. jrosales">

            <label>Email</label>
            <input type="email" name="email" required value="<?= htmlspecialchars($email) ?>" placeholder="e.g. juan@email.com">

            <label>Password</label>
            <input type="password" name="password" required placeholder="At least 6 characters">

            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required placeholder="Re-type password">

            <button type="submit">Create Account</button>
            <a href="users.php" class="cancel">Cancel</a>
        </form>
    </div>
</body>
</html>