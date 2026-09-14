<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireLogin('admin');

define('COURT_UPLOAD_DIR', __DIR__ . '/../assets/uploads/courts/');
define('COURT_UPLOAD_URL_PREFIX', 'assets/uploads/courts/');

$errors = [];
$success = '';

// --- Upload a new image for a court ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $courtId = (int) ($_POST['court_id'] ?? 0);

    if ($courtId <= 0) {
        $errors[] = 'Please choose a court.';
    }

    if (empty($_FILES['court_image']) || $_FILES['court_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please choose an image to upload.';
    } elseif ($_FILES['court_image']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'There was a problem uploading the file. Please try again.';
    } else {
        $file = $_FILES['court_image'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $imageInfo = @getimagesize($file['tmp_name']);

        if (!in_array($extension, $allowedExtensions, true) || $imageInfo === false) {
            $errors[] = 'File must be a JPG, PNG, or GIF image.';
        } elseif ($file['size'] > 4 * 1024 * 1024) {
            $errors[] = 'Image must be smaller than 4MB.';
        }
    }

    if (empty($errors)) {
        // Unique filename so uploads never overwrite each other.
        $uniqueName = 'court' . $courtId . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $destination = COURT_UPLOAD_DIR . $uniqueName;

        if (!is_dir(COURT_UPLOAD_DIR)) {
            mkdir(COURT_UPLOAD_DIR, 0755, true);
        }

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $imagePath = COURT_UPLOAD_URL_PREFIX . $uniqueName;
            $stmt = mysqli_prepare($conn, "INSERT INTO court_images (court_id, image_path) VALUES (?, ?)");
            mysqli_stmt_bind_param($stmt, "is", $courtId, $imagePath);
            if (mysqli_stmt_execute($stmt)) {
                $success = 'Image uploaded successfully.';
            } else {
                $errors[] = 'Could not save the image record.';
            }
            mysqli_stmt_close($stmt);
        } else {
            $errors[] = 'Could not save the uploaded file.';
        }
    }
}

// --- Delete an image ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $imageId = (int) ($_POST['image_id'] ?? 0);

    if ($imageId > 0) {
        $stmt = mysqli_prepare($conn, "SELECT image_path FROM court_images WHERE image_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $imageId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($row) {
            $stmt = mysqli_prepare($conn, "DELETE FROM court_images WHERE image_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $imageId);
            if (mysqli_stmt_execute($stmt)) {
                $filePath = __DIR__ . '/../' . $row['image_path'];
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
                $success = 'Image deleted.';
            } else {
                $errors[] = 'Could not delete the image record.';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

$courtsResult = mysqli_query($conn, "SELECT court_id, court_name FROM courts ORDER BY court_name");
$courts = [];
while ($row = mysqli_fetch_assoc($courtsResult)) {
    $courts[] = $row;
}

$imagesResult = mysqli_query(
    $conn,
    "SELECT ci.image_id, ci.image_path, ci.court_id, c.court_name
     FROM court_images ci
     JOIN courts c ON c.court_id = ci.court_id
     ORDER BY c.court_name, ci.uploaded_at DESC"
);
$images = [];
while ($row = mysqli_fetch_assoc($imagesResult)) {
    $images[] = $row;
}

$page_title = 'Manage Court Images - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card form-card-wide">
        <h1>Manage Court Images</h1>

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

        <?php if (empty($courts)): ?>
            <p>Add a court first (Manage Courts) before uploading images.</p>
        <?php else: ?>
            <h2 class="section-heading">Upload an Image</h2>
            <form method="POST" action="manage_court_images.php" enctype="multipart/form-data" class="inline-add-form">
                <input type="hidden" name="action" value="upload">
                <div class="form-group">
                    <label for="court_id">Court</label>
                    <select id="court_id" name="court_id" required>
                        <?php foreach ($courts as $court): ?>
                            <option value="<?php echo (int) $court['court_id']; ?>"><?php echo htmlspecialchars($court['court_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="court_image">Image</label>
                    <input type="file" id="court_image" name="court_image" accept=".jpg,.jpeg,.png,.gif" required>
                </div>
                <button type="submit" class="btn btn-primary">Upload</button>
            </form>

            <h2 class="section-heading">Uploaded Images</h2>
            <?php if (empty($images)): ?>
                <p>No images uploaded yet.</p>
            <?php else: ?>
                <div class="gallery-grid">
                    <?php foreach ($images as $image): ?>
                        <div class="gallery-thumb gallery-thumb-admin">
                            <img src="../<?php echo htmlspecialchars($image['image_path']); ?>" alt="<?php echo htmlspecialchars($image['court_name']); ?>">
                            <span class="gallery-thumb-caption"><?php echo htmlspecialchars($image['court_name']); ?></span>
                            <form method="POST" action="manage_court_images.php"
                                  onsubmit="return confirm('Delete this image?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="image_id" value="<?php echo (int) $image['image_id']; ?>">
                                <button type="submit" class="btn btn-danger btn-small btn-block">Delete</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
