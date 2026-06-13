<?php
/**
 * Master Management Actions - Mini ERP System
 * Handles Add, Edit, and Status Toggles for all Masters.
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/auth_check.php';

require_permission('master-management', 'create');

if (!is_post()) {
    redirect('/dashboard/index.php');
}

if (!csrf_validate()) {
    set_flash('error', 'Invalid security token.');
    redirect('/dashboard/index.php');
}

$action = $_POST['action'] ?? '';
$masterType = $_POST['master_type'] ?? ''; // 'assignee', 'sales_person', 'responsible_person'

$config = [
    'assignee' => [
        'table' => 'tbl_assignees',
        'name_col' => 'assignee_name',
        'redirect' => '/modules/masters/assignees.php',
        'log_entity' => 'Assignee'
    ],
    'sales_person' => [
        'table' => 'tbl_sales_persons',
        'name_col' => 'sales_person_name',
        'redirect' => '/modules/masters/sales_persons.php',
        'log_entity' => 'Sales Person'
    ],
    'responsible_person' => [
        'table' => 'tbl_responsible_persons',
        'name_col' => 'responsible_person_name',
        'redirect' => '/modules/masters/responsible_persons.php',
        'log_entity' => 'Responsible Person'
    ]
];

if (!isset($config[$masterType])) {
    set_flash('error', 'Invalid master type.');
    redirect('/dashboard/index.php');
}

$c = $config[$masterType];
$table = $c['table'];
$nameCol = $c['name_col'];
$redirectUrl = $c['redirect'];
$logEntity = $c['log_entity'];

if (!empty($_POST['return_status'])) {
    $redirectUrl .= '?status=' . urlencode($_POST['return_status']);
}

switch ($action) {
    case 'add':
        $name = trim($_POST['name'] ?? '');
        $mobile = trim($_POST['mobile_number'] ?? '');
        
        if (empty($name)) {
            set_flash('error', 'Name is required.');
            break;
        }

        // Duplicate check
        $stmt = $conn->prepare("SELECT id FROM {$table} WHERE {$nameCol} = ?");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            set_flash('error', "{$logEntity} with this name already exists.");
            break;
        }
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO {$table} ({$nameCol}, mobile_number, status) VALUES (?, ?, 'active')");
        $stmt->bind_param("ss", $name, $mobile);
        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            log_action($conn, 'Master Management', ACTION_CREATE, $logEntity, $newId, null, ['name' => $name, 'mobile' => $mobile]);
            set_flash('success', "{$logEntity} added successfully.");
        } else {
            set_flash('error', 'Database error.');
        }
        $stmt->close();
        break;

    case 'edit':
        require_permission('master-management', 'edit');
        
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $mobile = trim($_POST['mobile_number'] ?? '');

        if ($id <= 0 || empty($name)) {
            set_flash('error', 'Invalid data.');
            break;
        }

        // Duplicate check
        $stmt = $conn->prepare("SELECT id FROM {$table} WHERE {$nameCol} = ? AND id != ?");
        $stmt->bind_param("si", $name, $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            set_flash('error', "Another {$logEntity} with this name already exists.");
            break;
        }
        $stmt->close();

        // Get old data for audit
        $stmt = $conn->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $oldData = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($oldData) {
            $stmt = $conn->prepare("UPDATE {$table} SET {$nameCol} = ?, mobile_number = ? WHERE id = ?");
            $stmt->bind_param("ssi", $name, $mobile, $id);
            if ($stmt->execute()) {
                log_action($conn, 'Master Management', ACTION_UPDATE, $logEntity, $id, 
                    ['name' => $oldData[$nameCol], 'mobile' => $oldData['mobile_number']], 
                    ['name' => $name, 'mobile' => $mobile]);
                set_flash('success', "{$logEntity} updated successfully.");
            }
            $stmt->close();
        }
        break;

    case 'toggle_status':
        require_permission('master-management', 'edit');
        
        $id = intval($_POST['id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        
        if ($id <= 0 || !in_array($newStatus, ['active', 'inactive'])) {
            set_flash('error', 'Invalid status update request.');
            break;
        }

        $stmt = $conn->prepare("UPDATE {$table} SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $newStatus, $id);
        if ($stmt->execute()) {
            $auditAction = ($newStatus === 'active') ? 'Activated' : 'Deactivated';
            log_action($conn, 'Master Management', $auditAction, $logEntity, $id, null, ['status' => $newStatus]);
            set_flash('success', "{$logEntity} {$auditAction} successfully.");
        }
        $stmt->close();
        break;

    default:
        set_flash('error', 'Unknown action.');
        break;
}

redirect($redirectUrl);
