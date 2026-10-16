<?php
require __DIR__ . "/_bootstrap.php";

$user = requireEmployee();
$empId = $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

// Get this employee's active QR code
$stmt = $pdo->prepare(
    "SELECT qr_code_id, code_value, issued_date, status
     FROM qr_codes
     WHERE employee_id = ? AND status = 'Active'
     ORDER BY qr_code_id DESC
     LIMIT 1"
);
$stmt->execute([$empId]);
$qr = $stmt->fetch();

if (!$qr) {
    respond(false, "You don't have a QR code yet. Please ask HR to generate one for you.", null, 404);
}

respond(true, "", [
    "qr_code_id"  => (int) $qr["qr_code_id"],
    "code_value"  => $qr["code_value"],
    "issued_date" => $qr["issued_date"],
]);