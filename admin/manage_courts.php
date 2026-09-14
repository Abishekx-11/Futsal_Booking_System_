<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireLogin('admin');

$errors = [];
$success = '';

// --- Add a new court ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $courtName = trim($_POST['court_name'] ?? '');
    $price = $_POST['price_per_hour'] ?? '';
    $description = trim($_POST['description'] ?? '');

    if ($courtName === '') {
        $errors[] = 'Court name is required.';
    }
    if (!is_numeric($price) || (float) $price <= 0) {
        $errors[] = 'Price per hour must be a positive number.';
    }

    if (empty($errors)) {
        $stmt = mysqli_prepare($conn, "INSERT INTO courts (court_name, price_per_hour, description) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "sds", $courtName, $price, $description);
        if (mysqli_stmt_execute($stmt)) {
            $success = 'Court added successfully.';
        } else {
            $errors[] = 'Could not add the court.';
        }
        mysqli_stmt_close($stmt);
    }
}

// --- Edit an existing court ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $courtId = (int) ($_POST['court_id'] ?? 0);
    $courtName = trim($_POST['court_name'] ?? '');
    $price = $_POST['price_per_hour'] ?? '';
    $description = trim($_POST['description'] ?? '');

    if ($courtName === '') {
        $errors[] = 'Court name is required.';
    }
    if (!is_numeric($price) || (float) $price <= 0) {
        $errors[] = 'Price per hour must be a positive number.';
    }

    if (empty($errors) && $courtId > 0) {
        $stmt = mysqli_prepare($conn, "UPDATE courts SET court_name = ?, price_per_hour = ?, description = ? WHERE court_id = ?");
        mysqli_stmt_bind_param($stmt, "sdsi", $courtName, $price, $description, $courtId);
        if (mysqli_stmt_execute($stmt)) {
            $success = 'Court updated successfully.';
        } else {
            $errors[] = 'Could not update the court.';
        }
        mysqli_stmt_close($stmt);
    }
}

// --- Delete a court ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $courtId = (int) ($_POST['court_id'] ?? 0);
    if ($courtId > 0) {
        // ON DELETE CASCADE in the schema also removes this court's
        // images, and (via bookings' FK) would remove its bookings -
        // in practice you'd usually want to block deleting a court that
        // still has active bookings, but keeping this simple per the
        // "no unnecessary abstraction" brief for this project.
        $stmt = mysqli_prepare($conn, "DELETE FROM courts WHERE court_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $courtId);
        if (mysqli_stmt_execute($stmt)) {
            $success = 'Court deleted.';
        } else {
            $errors[] = 'Could not delete the court.';
        }
        mysqli_stmt_close($stmt);
    }
}

$result = mysqli_query($conn, "SELECT court_id, court_name, price_per_hour, description FROM courts ORDER BY court_name");
$courts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $courts[] = $row;
}

$page_title = 'Manage Courts - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card form-card-wide">
        <h1>Manage Courts</h1>

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

        <h2 class="section-heading">Add a Court</h2>
        <form method="POST" action="manage_courts.php" class="inline-add-form">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label for="court_name">Court Name</label>
                <input type="text" id="court_name" name="court_name" required>
            </div>
            <div class="form-group">
                <label for="price_per_hour">Price per Hour (Rs.)</label>
                <input type="number" id="price_per_hour" name="price_per_hour" step="0.01" min="0.01" required>
            </div>
            <div class="form-group form-group-full">
                <label for="description">Description (3-4 sentences)</label>
                <textarea id="description" name="description" rows="4" placeholder="Describe the court's surface, size, lighting, and what kind of play it's best for..."></textarea>
            </div>
            <div class="form-group-full">
                <button type="submit" class="btn btn-primary">Add Court</button>
            </div>
        </form>

        <h2 class="section-heading">Existing Courts</h2>
        <?php if (empty($courts)): ?>
            <p>No courts yet.</p>
        <?php else: ?>
            <div class="table-responsive">
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th>Court Name</th>
                        <th>Price / Hour</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courts as $court): ?>
                        <?php
                            $editFormId = 'edit-court-' . (int) $court['court_id'];
                            $deleteFormId = 'delete-court-' . (int) $court['court_id'];
                        ?>
                        <!--
                            A <form> element is not valid as a direct child of <tr>/<td> -
                            browsers will silently move it outside the table and break the
                            layout. Instead, each row's <form> lives OUTSIDE the table (below),
                            and its inputs/buttons use the HTML5 form="..." attribute to stay
                            associated with it while visually living inside their <td>.
                        -->
                        <tr>
                            <td>
                                <input type="text" name="court_name" form="<?php echo $editFormId; ?>"
                                       value="<?php echo htmlspecialchars($court['court_name']); ?>" required class="table-inline-input">
                            </td>
                            <td>
                                <input type="number" name="price_per_hour" form="<?php echo $editFormId; ?>" step="0.01" min="0.01"
                                       value="<?php echo htmlspecialchars($court['price_per_hour']); ?>" required class="table-inline-input">
                            </td>
                            <td>
                                <textarea name="description" form="<?php echo $editFormId; ?>" rows="5" class="table-inline-textarea"
                                          placeholder="3-4 sentence description..."><?php echo htmlspecialchars($court['description'] ?? ''); ?></textarea>
                            </td>
                            <td class="bookings-actions">
                                <button type="submit" form="<?php echo $editFormId; ?>" class="btn btn-secondary btn-small">Save</button>
                                <button type="submit" form="<?php echo $deleteFormId; ?>" class="btn btn-danger btn-small"
                                        onclick="return confirm('Delete this court? This also removes its uploaded images.');">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php foreach ($courts as $court): ?>
                <form id="edit-court-<?php echo (int) $court['court_id']; ?>" method="POST" action="manage_courts.php">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="court_id" value="<?php echo (int) $court['court_id']; ?>">
                </form>
                <form id="delete-court-<?php echo (int) $court['court_id']; ?>" method="POST" action="manage_courts.php">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="court_id" value="<?php echo (int) $court['court_id']; ?>">
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
