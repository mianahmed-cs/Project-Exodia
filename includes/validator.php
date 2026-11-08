<?php
/**
 * Project Exodia — Server-Side Validation & Sanitization Engine
 * 
 * Provides strict validation rules for user input and XSS output escaping.
 */

declare(strict_types=1);

/**
 * Escapes strings for safe HTML rendering to prevent Cross-Site Scripting (XSS).
 */
function sanitize_output(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Validates a username.
 * Rules:
 *  - Must be between 3 and 30 characters.
 *  - May only contain alphanumeric characters and underscores.
 */
function validate_username(string $username): array {
    $username = trim($username);
    if (empty($username)) {
        return ['valid' => false, 'error' => 'Username is required.'];
    }
    if (strlen($username) < 3 || strlen($username) > 30) {
        return ['valid' => false, 'error' => 'Username must be between 3 and 30 characters.'];
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return ['valid' => false, 'error' => 'Username may only contain letters, numbers, and underscores.'];
    }
    return ['valid' => true, 'value' => $username];
}

/**
 * Validates an email address.
 * Rules:
 *  - Must be present and not exceed 255 characters.
 *  - Must satisfy standard email format filter.
 */
function validate_email(string $email): array {
    $email = trim($email);
    if (empty($email)) {
        return ['valid' => false, 'error' => 'Email address is required.'];
    }
    if (strlen($email) > 255) {
        return ['valid' => false, 'error' => 'Email address is too long (maximum 255 characters).'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'error' => 'Please enter a valid email address.'];
    }
    return ['valid' => true, 'value' => strtolower($email)];
}

/**
 * Validates a password.
 * Rules:
 *  - Minimum 8 characters.
 *  - Must contain at least one letter and at least one digit.
 */
function validate_password(string $password): array {
    if (empty($password)) {
        return ['valid' => false, 'error' => 'Password is required.'];
    }
    if (strlen($password) < 8) {
        return ['valid' => false, 'error' => 'Password must be at least 8 characters long.'];
    }
    if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'error' => 'Password must include at least one letter and one number.'];
    }
    return ['valid' => true, 'value' => $password];
}

/**
 * Validates that two passwords match.
 */
function validate_password_confirmation(string $password, string $confirmPassword): array {
    if ($password !== $confirmPassword) {
        return ['valid' => false, 'error' => 'Passwords do not match.'];
    }
    return ['valid' => true];
}
