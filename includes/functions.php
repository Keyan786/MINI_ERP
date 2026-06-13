<?php
/**
 * Utility Functions - Mini ERP System
 * CSRF protection, input sanitization, flash messages, and general helpers.
 */

// ─── CSRF Token Management ─────────────────────────────────────────────────

/**
 * Generate or retrieve the current CSRF token.
 */
function csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Output a hidden CSRF input field for forms.
 */
function csrf_field(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . csrf_token() . '">';
}

/**
 * Validate the submitted CSRF token.
 */
function csrf_validate(): bool {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (empty($token) || !hash_equals(csrf_token(), $token)) {
        return false;
    }
    // Regenerate token after successful validation
    unset($_SESSION[CSRF_TOKEN_NAME]);
    return true;
}

// ─── Input Sanitization ────────────────────────────────────────────────────

/**
 * Sanitize a string input: trim, strip tags, and escape HTML entities.
 */
function sanitize_input(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email format.
 */
function validate_email(string $email): string|false {
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL);
}

/**
 * Escape output for safe HTML rendering.
 */
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// ─── Flash Messages ────────────────────────────────────────────────────────

/**
 * Set a flash message to be displayed on the next page load.
 * @param string $type success|error|warning|info
 * @param string $message The message text
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Retrieve and clear all flash messages.
 */
function get_flash_messages(): array {
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/**
 * Render flash messages as HTML with appropriate styling.
 */
function render_flash_messages(): string {
    $messages = get_flash_messages();
    if (empty($messages)) return '';

    $html = '<div class="flash-container">';
    foreach ($messages as $msg) {
        $iconMap = [
            'success' => 'fa-circle-check',
            'error'   => 'fa-circle-xmark',
            'warning' => 'fa-triangle-exclamation',
            'info'    => 'fa-circle-info'
        ];
        $icon = $iconMap[$msg['type']] ?? 'fa-circle-info';
        $html .= '<div class="flash-message flash-' . e($msg['type']) . '" data-flash>';
        $html .= '<i class="fa-solid ' . $icon . '"></i>';
        $html .= '<span>' . e($msg['message']) . '</span>';
        $html .= '<button class="flash-close" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

// ─── Helper Functions ──────────────────────────────────────────────────────

/**
 * Redirect to a URL and exit.
 */
function redirect(string $path): void {
    header("Location: " . BASE_URL . $path);
    exit;
}

/**
 * Get the client's IP address.
 */
function get_client_ip(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Get the client's user agent string.
 */
function get_user_agent(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    return substr($ua, 0, 255);
}

/**
 * Check if the current request is a POST request.
 */
function is_post(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Format a datetime string for display.
 */
function format_datetime(?string $datetime): string {
    if (empty($datetime)) return '—';
    return date('M d, Y h:i A', strtotime($datetime));
}

/**
 * Format time elapsed since a given datetime.
 */
function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return format_datetime($datetime);
}

/**
 * Generate a status badge HTML.
 */
function status_badge(string $status): string {
    $classes = [
        'pending'   => 'badge-warning',
        'active'    => 'badge-success',
        'rejected'  => 'badge-danger',
        'suspended' => 'badge-secondary',
        'approved'  => 'badge-success',
    ];
    $class = $classes[$status] ?? 'badge-secondary';
    return '<span class="badge ' . $class . '">' . e(ucfirst($status)) . '</span>';
}
?>
