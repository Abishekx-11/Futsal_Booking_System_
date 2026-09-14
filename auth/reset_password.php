<?php
require_once __DIR__ . '/../includes/db_connect.php';

// Can't reach this page without having verified the code first.
if (empty($_SESSION['password_reset']['verified']) || $_SESSION['password_reset']['verified'] !== true) {
    header('Location: forgot_password.php');
    exit;
}

$email = $_SESSION['password_reset']['email'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Password and confirm password do not match.';
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE users SET password = ?, reset_code = NULL, reset_code_expiry = NULL WHERE email = ?"
        );
        mysqli_stmt_bind_param($stmt, "ss", $hashed, $email);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        unset($_SESSION['password_reset']);
        header('Location: user_login.php?reset=success');
        exit;
    }
}

$page_title = 'Reset Password - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card">
        <h1>Set New Password</h1>
        <p class="payment-note">Resetting password for <?php echo htmlspecialchars($email); ?>.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="reset_password.php">
            <div class="form-group">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" minlength="6" required autofocus>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="6" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Set New Password</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
