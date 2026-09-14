<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireLogin('user');

$userId = $_SESSION['user_id'];
$errors = [];
$success = '';

// Where uploaded profile pictures physically live, and the path we store
// in the DB / use in <img src>. UPLOAD_DIR is a filesystem path (for
// move_uploaded_file / unlink); UPLOAD_URL_PREFIX is what gets saved in
// the database and is relative to the project root (so header.php, which
// is included from different folder depths, can prefix it with $base).
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/profile/');
define('UPLOAD_URL_PREFIX', 'assets/uploads/profile/');

$stmt = mysqli_prepare($conn, "SELECT name, username, email, phone_number, profile_picture FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone_number'] ?? '');

    if ($name === '') {
        $errors[] = 'Name cannot be empty.';
    }
    if (!preg_match('/^(97|98)[0-9]{8}$/', $phone)) {
        $errors[] = 'Phone number must be a valid Nepali number (starts with 97 or 98, 10 digits).';
    }

    $newPicturePath = $user['profile_picture']; // unchanged unless a new file is uploaded

    // --- Profile picture upload (optional on every save) ---
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['profile_picture'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'There was a problem uploading the file. Please try again.';
        } else {
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            // Don't trust the extension alone - confirm it's really an image.
            $imageInfo = @getimagesize($file['tmp_name']);

            if (!in_array($extension, $allowedExtensions, true) || $imageInfo === false) {
                $errors[] = 'Profile picture must be a JPG, PNG, or GIF image.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Profile picture must be smaller than 2MB.';
            } else {
                // Unique filename so two users' uploads never collide.
                $uniqueName = 'user' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
                $destination = UPLOAD_DIR . $uniqueName;

                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0755, true);
                }

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    // Delete the old picture file (if any) now that the new
                    // one is safely saved, so we don't leave orphan files.
                    if (!empty($user['profile_picture'])) {
                        $oldFilePath = __DIR__ . '/../' . $user['profile_picture'];
                        if (is_file($oldFilePath)) {
                            @unlink($oldFilePath);
                        }
                    }
                    $newPicturePath = UPLOAD_URL_PREFIX . $uniqueName;
                } else {
                    $errors[] = 'Could not save the uploaded picture. Please try again.';
                }
            }
        }
    }

    if (empty($errors)) {
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE users SET name = ?, phone_number = ?, profile_picture = ? WHERE user_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "sssi", $name, $phone, $newPicturePath, $userId);

        if (mysqli_stmt_execute($stmt)) {
            $success = 'Profile updated successfully.';
            $user['name'] = $name;
            $user['phone_number'] = $phone;
            $user['profile_picture'] = $newPicturePath;
        } else {
            $errors[] = 'Something went wrong while saving your profile.';
        }
        mysqli_stmt_close($stmt);
    }
}

$page_title = 'My Profile - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card">
        <h1>My Profile</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="profile.php" enctype="multipart/form-data">
            <div class="profile-picture-row">
                <?php if ($user['profile_picture']): ?>
                    <img src="../<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile picture" class="profile-picture-preview">
                <?php else: ?>
                    <span class="profile-picture-preview profile-picture-placeholder">
                        <svg viewBox="0 0 24 24" width="32" height="32" fill="currentColor">
                            <path d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.2c-3.3 0-9.8 1.6-9.8 4.9v2.7h19.6v-2.7c0-3.3-6.5-4.9-9.8-4.9z"/>
                        </svg>
                    </span>
                <?php endif; ?>
                <div class="form-group profile-picture-input">
                    <label for="profile_picture">Change Profile Picture</label>
                    <input type="file" id="profile_picture" name="profile_picture" accept=".jpg,.jpeg,.png,.gif">
                    <small>JPG, PNG, or GIF. Max 2MB.</small>
                </div>
            </div>

            <div class="form-group">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
            </div>

            <div class="form-group">
                <label>Username</label>
                <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                <small>Username cannot be changed.</small>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                <small>Email cannot be changed.</small>
            </div>

            <div class="form-group">
                <label for="phone_number">Phone Number</label>
                <input type="text" id="phone_number" name="phone_number" pattern="^(97|98)[0-9]{8}$"
                       value="<?php echo htmlspecialchars($user['phone_number']); ?>" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
