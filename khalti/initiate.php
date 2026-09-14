<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/khalti_config.php';

requireLogin('user');

if (!isset($_SESSION['booking_data']) || !isset($_SESSION['booking_data']['payment_type'])) {
    header('Location: ../user/payment_selection.php');
    exit;
}

$booking = $_SESSION['booking_data'];
$paymentType = $booking['payment_type'];

// PART 3 NOTE: a 'Remaining' payment (see user/remaining_payment.php) pays
// off an EXISTING booking's leftover balance rather than 30%/100% of a
// fresh court price, so its payable amount comes straight from the
// remaining_amount stored in session (itself read from the bookings row).
// Advance/Full still compute off price_per_hour exactly as in Part 2.
if ($paymentType === 'Remaining') {
    $payableAmount = (float) $booking['remaining_amount'];
    $purchaseOrderName = 'Futsal Court Booking - Remaining Payment - ' . $booking['court_name'];
} else {
    $totalAmount = (float) $booking['price_per_hour'];
    $payableAmount = ($paymentType === 'Advance') ? round($totalAmount * 0.30, 2) : $totalAmount;
    $purchaseOrderName = 'Futsal Court Booking - ' . $booking['court_name'];
}

// Khalti expects the amount in paisa (1 rupee = 100 paisa), as an integer.
$amountInPaisa = (int) round($payableAmount * 100);

// Look up the logged-in user's info to send as customer_info.
$stmt = mysqli_prepare($conn, "SELECT name, email, phone_number FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user) {
    die('Could not find your user account. Please log in again.');
}

// A unique-ish order id for this attempt. Doesn't need to be globally
// unique in our DB since no row is created until verify.php succeeds -
// it's just an identifier Khalti includes back to us.
$purchaseOrderId = 'FUTSAL-' . $_SESSION['user_id'] . '-' . time();

$payload = [
    'return_url'         => KHALTI_RETURN_URL,
    'website_url'        => KHALTI_WEBSITE_URL,
    'amount'              => $amountInPaisa,
    'purchase_order_id'   => $purchaseOrderId,
    'purchase_order_name' => $purchaseOrderName,
    'customer_info'       => [
        'name'  => $user['name'],
        'email' => $user['email'],
        'phone' => $user['phone_number'],
    ],
];

$ch = curl_init(KHALTI_BASE_URL . 'epayment/initiate/');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Key ' . KHALTI_SECRET_KEY,
    'Content-Type: application/json',
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    die('Could not reach Khalti: ' . htmlspecialchars($curlError));
}

$data = json_decode($response, true);

if (isset($data['payment_url'])) {
    // Remember the pidx too, in case verify.php's GET param ever gets lost -
    // this is a secondary safety net, verify.php reads Khalti's own redirect param first.
    $_SESSION['booking_data']['pidx'] = $data['pidx'] ?? null;

    header('Location: ' . $data['payment_url']);
    exit;
} else {
    // Something went wrong on Khalti's side (bad key, bad payload, etc).
    echo '<h2>Payment could not be started</h2>';
    echo '<p>Khalti responded with an error. Details:</p>';
    echo '<pre>' . htmlspecialchars($response) . '</pre>';
    echo '<p><a href="../user/payment_selection.php">Go back</a></p>';
}
