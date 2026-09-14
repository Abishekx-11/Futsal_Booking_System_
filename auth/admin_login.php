<?php
require_once __DIR__ . '/../includes/db_connect.php';

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Invalid username or password.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT admin_id, name, username, password FROM admins WHERE username = ?");
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $admin = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        // Generic message either way - never reveal which field was wrong.
        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['role']       = 'admin';
            $_SESSION['admin_id']   = $admin['admin_id'];
            $_SESSION['admin_name'] = $admin['name'];

            header('Location: ../admin/dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$page_title = 'Admin Login - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card">
        <h1>Court Admin Login</h1>
        <p class="form-subtitle">Restricted access for court administrators.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="admin_login.php">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Log In</button>
        </form>
        <!-- Intentionally no sign-up link here: admin accounts are seeded, not self-registered. -->
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
