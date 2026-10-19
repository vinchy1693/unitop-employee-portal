<?php
// =====================================================================
// employee_portal/api/me_scan_attendance.php
//
// REMOVED: the portal used to let an employee paste their own QR value and
// record attendance from anywhere. That defeats on-site attendance, so the
// endpoint is now a hard refusal. Clocking in/out is only allowed through the
// staff-operated kiosk (api/scan_attendance.php on the admin app), which is
// physically at the office.
//
// The file is kept (rather than deleted) so a cached page gets a clear 403
// instead of a confusing 404.
// =====================================================================
require __DIR__ . "/_bootstrap.php";

requireEmployee();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed.", null, 405);
}

respond(
    false,
    "Attendance can only be recorded at the on-site kiosk. Please scan your QR code at the office scanner.",
    null,
    403
);
