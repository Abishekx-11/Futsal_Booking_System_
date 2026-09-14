<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireLogin('user');

$result = mysqli_query(
    $conn,
    "SELECT ci.image_id, ci.image_path, c.court_name
     FROM court_images ci
     JOIN courts c ON c.court_id = ci.court_id
     ORDER BY c.court_name, ci.uploaded_at DESC"
);
$images = [];
while ($row = mysqli_fetch_assoc($result)) {
    $images[] = $row;
}

$page_title = 'Court Gallery - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="gallery-page">
    <h1>Court Gallery</h1>
    <p class="form-subtitle">Click any photo to see it larger.</p>

    <?php if (empty($images)): ?>
        <p>No court photos have been uploaded yet.</p>
    <?php else: ?>
        <div class="gallery-grid">
            <?php foreach ($images as $image): ?>
                <button type="button" class="gallery-thumb" data-full="../<?php echo htmlspecialchars($image['image_path']); ?>" data-caption="<?php echo htmlspecialchars($image['court_name']); ?>">
                    <img src="../<?php echo htmlspecialchars($image['image_path']); ?>" alt="<?php echo htmlspecialchars($image['court_name']); ?>">
                    <span class="gallery-thumb-caption"><?php echo htmlspecialchars($image['court_name']); ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Lightbox overlay, toggled by assets/js/main.js. Hidden by default. -->
<div id="lightboxOverlay" class="lightbox-overlay">
    <button type="button" id="lightboxClose" class="lightbox-close" aria-label="Close">&times;</button>
    <img id="lightboxImage" src="" alt="">
    <p id="lightboxCaption" class="lightbox-caption"></p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
