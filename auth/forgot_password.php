<?php
require_once __DIR__ . '/../includes/db_connect.php';

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT user_id, name FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$user) {
            // Deliberately generic-sounding per the project's requirements,
            // but still tells the user plainly that this email isn't on file.
            $error = "This email isn't registered.";
        } else {
            // random_int() is cryptographically secure - fine for a 6-digit
            // one-time code. str_pad keeps a code like 42 as "000042", not "42".
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            $stmt = mysqli_prepare($conn, "UPDATE users SET reset_code = ?, reset_code_expiry = ? WHERE email = ?");
            mysqli_stmt_bind_param($stmt, "sss", $code, $expiry, $email);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            require_once __DIR__ . '/../includes/send_email.php';
            require_once __DIR__ . '/../includes/mail_config.php';

            $subject = 'Your Password Reset Code';
            $body =
                "Hi {$user['name']},\n\n" .
                "Your password reset code is: {$code}\n\n" .
                "This code expires in 5 minutes. If you didn't request this, you can ignore this email.\n\n" .
                "- Futsal Booking System";
            sendEmail($email, $user['name'], $subject, $body);

            // Carries the email across the next two steps, same pattern as
            // $_SESSION['booking_data'] elsewhere in this project.
            $_SESSION['password_reset'] = ['email' => $email, 'verified' => false];

            header('Location: verify_reset_code.php');
            exit;
        }
    }
}

$page_title = 'Forgot Password - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card">
        <h1>Forgot Password</h1>
        <p class="payment-note">Enter the email you registered with, and we'll send you a 6-digit code to reset your password.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="forgot_password.php">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Send Reset Code</button>
        </form>

        <p class="form-footer"><a href="user_login.php">Back to Login</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
