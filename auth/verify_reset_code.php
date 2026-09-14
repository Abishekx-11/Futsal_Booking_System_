<?php
require_once __DIR__ . '/../includes/db_connect.php';

// Can't reach this page without having gone through forgot_password.php first.
if (empty($_SESSION['password_reset']['email'])) {
    header('Location: forgot_password.php');
    exit;
}

$email = $_SESSION['password_reset']['email'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enteredCode = trim($_POST['code'] ?? '');

    // Server-side enforcement: numeric only, exactly 6 digits. The
    // <input> below also restricts this in the browser, but that's only
    // for a smoother experience - this check is what actually matters.
    if (!ctype_digit($enteredCode) || strlen($enteredCode) !== 6) {
        $error = 'Please enter the 6-digit numeric code exactly as sent.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT reset_code, reset_code_expiry FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row || $row['reset_code'] === null) {
            $error = 'No reset code is pending for this email. Please request a new one.';
        } elseif (strtotime($row['reset_code_expiry']) < time()) {
            $error = 'This code has expired. Please request a new one.';
        } elseif ($enteredCode !== $row['reset_code']) {
            // Per project decision: just show an error, let them retry with
            // the same code until it naturally expires - no auto-regeneration.
            $error = 'Incorrect code. Please try again.';
        } else {
            $_SESSION['password_reset']['verified'] = true;
            header('Location: reset_password.php');
            exit;
        }
    }
}

$page_title = 'Verify Code - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card">
        <h1>Enter Reset Code</h1>
        <p class="payment-note">We sent a 6-digit code to <?php echo htmlspecialchars($email); ?>. It expires 5 minutes after being sent.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="verify_reset_code.php">
            <div class="form-group">
                <label for="code">6-Digit Code</label>
                <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Verify Code</button>
        </form>

        <p class="form-footer"><a href="forgot_password.php">Request a new code</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
