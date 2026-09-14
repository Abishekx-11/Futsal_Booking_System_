<?php
require_once __DIR__ . '/../includes/db_connect.php';

$error = '';
$username = '';
$signupSuccess = isset($_GET['signup']) && $_GET['signup'] === 'success';
$resetSuccess = isset($_GET['reset']) && $_GET['reset'] === 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Invalid username or password.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT user_id, name, username, password FROM users WHERE username = ?");
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        // Generic message either way - never reveal which field was wrong.
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['role']    = 'user';
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['name']    = $user['name'];

            header('Location: ../user/dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$page_title = 'Login - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card">
        <h1>Customer Login</h1>
        <p class="form-subtitle">Log in to book a futsal court.</p>

        <?php if ($signupSuccess): ?>
            <div class="alert alert-success">Account created successfully. Please log in.</div>
        <?php endif; ?>

        <?php if ($resetSuccess): ?>
            <div class="alert alert-success">Password reset successfully. Please log in with your new password.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="user_login.php">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder = eg.Krishna98 value="<?php echo htmlspecialchars($username); ?>" required autofocus>
                
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
                <small><a href="forgot_password.php">Forgot password?</a></small>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Log In</button>
        </form>

        <p class="form-footer">Don't have an account? <a href="signup.php">Sign up</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
