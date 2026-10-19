<?php
// =====================================================================
// employee_portal/api/me_qr.php
//
// Returns a short-lived, one-time, purpose-bound QR token for the next
// attendance action. The static qr_codes value is no longer shown to the
// employee; the token is stored only as a SHA-256 hash.
//
// GET -> { token, purpose, expires_at, ttl, issued_date }
//       purpose = time_in | time_out | complete
// =====================================================================
require __DIR__ . "/_bootstrap.php";

$user  = requireEmployee();
$empId = (int) $user["employee_id"];

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

const PORTAL_QR_TOKEN_TTL_SECONDS = 120;

// A deactivated employee must not receive a new token, even with a live session.
$empStmt = $pdo->prepare("SELECT employee_number, is_active FROM employees WHERE employee_id = ?");
$empStmt->execute([$empId]);
$employee = $empStmt->fetch();
if (!$employee) {
    respond(false, "Employee record not found.", null, 404);
}
if ((int) $employee["is_active"] !== 1) {
    respond(false, "Your employee record is inactive. Please contact HR.", null, 403);
}

// QR access requires an active static badge. No badge means no QR shown at
// all; a revoked badge means access is blocked until HR generates one again.
$gate = $pdo->prepare(
    "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'Active'), 0) AS active
     FROM qr_codes WHERE employee_id = ?"
);
$gate->execute([$empId]);
$gateRow = $gate->fetch();
if ((int) $gateRow["active"] === 0) {
    if ((int) $gateRow["total"] > 0) {
        respond(false, "QR access has been revoked. Ask HR to generate a new QR code.", null, 403);
    }
    respond(false, "No active QR code is on file. Ask HR to generate one.", null, 404);
}

$today = date("Y-m-d");
$stmt = $pdo->prepare(
    "SELECT time_in, time_out
     FROM attendance
     WHERE employee_id = ? AND work_date = ?
     ORDER BY attendance_id DESC
     LIMIT 1"
);
$stmt->execute([$empId, $today]);
$attendance = $stmt->fetch();

if (!$attendance || $attendance["time_in"] === null) {
    $purpose = "time_in";
} elseif ($attendance["time_out"] === null) {
    $purpose = "time_out";
} else {
    // The regular shift is complete. Do not turn the attendance QR into an
    // overtime QR; OT is filed from the attendance page's overtime form.
    $purpose = "complete";
}

// Invalidate any unused token for this employee before issuing the next one.
$pdo->prepare("UPDATE qr_tokens SET used_at = NOW() WHERE employee_id = ? AND used_at IS NULL")
    ->execute([$empId]);

// Keep the token table small; the attendance row is the durable record.
$pdo->prepare("DELETE FROM qr_tokens WHERE employee_id = ? AND expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)")
    ->execute([$empId]);

if ($purpose === "complete") {
    respond(true, "Attendance is already complete for today.", [
        "token"       => null,
        "purpose"     => "complete",
        "ttl"         => PORTAL_QR_TOKEN_TTL_SECONDS,
        "issued_date" => $today,
        "employee"    => [
            "employee_id"     => $empId,
            "employee_number" => $employee["employee_number"],
        ],
    ]);
}

$token     = "UNITOP-T-" . bin2hex(random_bytes(24));
$tokenHash = hash("sha256", $token);
$expiresAt = date("Y-m-d H:i:s", time() + PORTAL_QR_TOKEN_TTL_SECONDS);

$pdo->prepare(
    "INSERT INTO qr_tokens (employee_id, token_hash, purpose, expires_at)
     VALUES (?, ?, ?, ?)"
)->execute([$empId, $tokenHash, $purpose, $expiresAt]);

respond(true, "", [
    "token"       => $token,
    "purpose"     => $purpose,
    "expires_at"  => $expiresAt,
    "ttl"         => PORTAL_QR_TOKEN_TTL_SECONDS,
    "issued_date" => $today,
    "employee"    => [
        "employee_id"     => $empId,
        "employee_number" => $employee["employee_number"],
    ],
]);
