<?php
/**
 * AJAX Vendor Creation Endpoint - Mini ERP System
 * Creates a vendor via AJAX POST and returns JSON.
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/auth_check.php';

header('Content-Type: application/json');

if (!is_post()) {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$vendorName     = trim($_POST['vendor_name'] ?? '');
$contactPerson  = trim($_POST['contact_person'] ?? '');
$email          = trim($_POST['email'] ?? '');
$phone          = trim($_POST['phone'] ?? '');
$address        = trim($_POST['address'] ?? '');
$city           = trim($_POST['city'] ?? '');
$state          = trim($_POST['state'] ?? '');
$country        = trim($_POST['country'] ?? '');

if (empty($vendorName)) {
    echo json_encode(['success' => false, 'message' => 'Vendor name is required.']);
    exit;
}

$userId = $_SESSION['user_id'];
$stmt = $conn->prepare("INSERT INTO tbl_vendors (vendor_name, contact_person, email, phone, address, city, state, country, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
$stmt->bind_param("ssssssssi", $vendorName, $contactPerson, $email, $phone, $address, $city, $state, $country, $userId);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();

    log_action($conn, 'Purchase', ACTION_CREATE, 'Vendor', $newId, null, ['vendor_name' => $vendorName]);

    $fullAddress = implode(', ', array_filter([$address, $city, $state, $country]));

    echo json_encode([
        'success' => true,
        'vendor' => [
            'vendor_id'      => $newId,
            'vendor_name'    => $vendorName,
            'contact_person' => $contactPerson,
            'email'          => $email,
            'phone'          => $phone,
            'address'        => $fullAddress,
        ]
    ]);
} else {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
?>
