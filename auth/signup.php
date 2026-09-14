<?php
require_once __DIR__ . '/../includes/db_connect.php';

$errors = [];
$success = false;

// Prefill values so the form doesn't clear on error
$name = $username = $email = $phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone_number'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // --- Server-side validation (the real enforcement; JS is just convenience) ---

    if ($name === '') {
        $errors[] = 'Full name is required.';
    }

    if (!preg_match('/^[A-Za-z0-9]+$/', $username)) {
        $errors[] = 'Username must be alphanumeric with no spaces.';
    }

    // Any real email provider is fine (Gmail is only required for the
    // SENDING account configured separately in mail_config.php).
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Nepali mobile numbers only: starts with 97 or 98, 10 digits total.
    if (!preg_match('/^(97|98)[0-9]{8}$/', $phone)) {
        $errors[] = 'Phone number must be a valid Nepali number (starts with 97 or 98, 10 digits).';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if ($password !== $confirm) {
        $errors[] = 'Password and confirm password do not match.';
    }

    // Uniqueness checks (only run if basic format checks passed, to avoid noisy queries)
    if (empty($errors)) {
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE username = ? OR email = ?");
        mysqli_stmt_bind_param($stmt, "ss", $username, $email);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);

        if (mysqli_stmt_num_rows($stmt) > 0) {
            $errors[] = 'That username or email is already registered.';
        }
        mysqli_stmt_close($stmt);
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO users (name, username, email, password, phone_number) VALUES (?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, "sssss", $name, $username, $email, $hashed, $phone);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            require_once __DIR__ . '/../includes/send_email.php';
            require_once __DIR__ . '/../includes/mail_config.php';

            $subject = 'Welcome to Futsal Booking System';
            $userBody =
                "Hi {$name},\n\n" .
                "Your account has been created successfully. You can now log in and start booking courts.\n\n" .
                "- Futsal Booking System";
            $adminBody =
                "Hi Admin,\n\n" .
                "A new user has registered: {$name} ({$email}).\n\n" .
                "- Futsal Booking System";

            sendEmail($email, $name, $subject, $userBody);
            sendEmail(ADMIN_NOTIFICATION_EMAIL, 'Admin', '[Admin Copy] ' . $subject, $adminBody);

            header('Location: user_login.php?signup=success');
            exit;
        } else {
            $errors[] = 'Something went wrong while creating your account. Please try again.';
        }
        mysqli_stmt_close($stmt);
    }
}

$page_title = 'Sign Up - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card">
        <h1>Create an Account</h1>
        <p class="form-subtitle">Sign up to start booking futsal courts.</p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="signup.php" id="signupForm" novalidate>
            <div class="form-group">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" placeholder = "Please Enter your full name" :value="<?php echo htmlspecialchars($name); ?>" required>
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder = eg.Krishna98 pattern="^[A-Za-z0-9]+$"
                       value="<?php echo htmlspecialchars($username); ?>" required>
                <small>Letters and numbers only, no spaces.</small>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
            </div>

            <div class="form-group">
                <label for="phone_number">Phone Number</label>
                <input type="text" id="phone_number" name="phone_number" pattern="^(97|98)[0-9]{8}$"
                       value="<?php echo htmlspecialchars($phone); ?>" placeholder="98XXXXXXXX" required>
                
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" minlength="6" required>
                <small>Combination of Uppercase, Lowercase , numbers and special symbols</small>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="6" required>
            </div>

            <span id="passwordError" class="field-error"></span>

            <button type="submit" class="btn btn-primary btn-block">Sign Up</button>
        </form>

        <p class="form-footer">Already have an account? <a href="user_login.php">Log in</a></p>
    </div>
</div>

<script>
// Client-side convenience check only - PHP above is the real enforcement.
document.getElementById('signupForm').addEventListener('submit', function (e) {
    var password = document.getElementById('password').value;
    var confirm = document.getElementById('confirm_password').value;
    var errorSpan = document.getElementById('passwordError');

    if (password !== confirm) {
        e.preventDefault();
        errorSpan.textContent = 'Password and confirm password do not match.';
    } else {
        errorSpan.textContent = '';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
