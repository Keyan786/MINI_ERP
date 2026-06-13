<?php
/**
 * Audit Log Helper - Mini ERP System
 * Provides a centralized function to log all system actions.
 */

/**
 * Log an action to the audit trail.
 *
 * @param mysqli    $conn         Database connection
 * @param string    $module       Module name (e.g., 'Authentication', 'User Management')
 * @param string    $action       Action performed (use ACTION_* constants)
 * @param string|null $recordType  Type of record affected (e.g., 'User', 'Role')
 * @param int|null  $recordId     ID of the affected record
 * @param array|null $oldValues   Previous values (for updates)
 * @param array|null $newValues   New values (for creates/updates)
 */
function log_action(
    mysqli $conn,
    string $module,
    string $action,
    ?string $recordType = null,
    ?int $recordId = null,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    $userId = $_SESSION['user_id'] ?? null;
    $userName = $_SESSION['user_name'] ?? 'System';
    $ipAddress = get_client_ip();
    $userAgent = get_user_agent();
    $oldJson = $oldValues ? json_encode($oldValues) : null;
    $newJson = $newValues ? json_encode($newValues) : null;

    $stmt = $conn->prepare("
        INSERT INTO `tbl_audit_log` 
        (`user_id`, `user_name`, `module`, `action`, `record_type`, `record_id`, `old_values`, `new_values`, `ip_address`, `user_agent`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "issssissss",
        $userId, $userName, $module, $action,
        $recordType, $recordId, $oldJson, $newJson,
        $ipAddress, $userAgent
    );
    $stmt->execute();
    $stmt->close();
}
?>
