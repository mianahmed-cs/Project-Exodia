<?php
/**
 * Project Exodia — Registration Page & Handler
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/auth.php';

start_secure_session();

// If already logged in, redirect to dashboard
if (is_authenticated()) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$usernameInput = '';
$emailInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        http_response_code(403);
        $errors['csrf'] = 'Invalid or expired security token. Please refresh and try again.';
    } else {
        $usernameInput = trim($_POST['username'] ?? '');
        $emailInput = trim($_POST['email'] ?? '');
        $passwordInput = $_POST['password'] ?? '';
        $confirmPasswordInput = $_POST['confirm_password'] ?? '';

        // 2. Server-side validation
        $userValidation = validate_username($usernameInput);
        if (!$userValidation['valid']) {
            $errors['username'] = $userValidation['error'];
        }

        $emailValidation = validate_email($emailInput);
        if (!$emailValidation['valid']) {
            $errors['email'] = $emailValidation['error'];
        }

        $passValidation = validate_password($passwordInput);
        if (!$passValidation['valid']) {
            $errors['password'] = $passValidation['error'];
        }

        $confirmValidation = validate_password_confirmation($passwordInput, $confirmPasswordInput);
        if (!$confirmValidation['valid']) {
            $errors['confirm_password'] = $confirmValidation['error'];
        }

        // 3. Database uniqueness checks (if basic validation passed)
        if (empty($errors)) {
            try {
                $pdo = get_db_connection();

                // Check email uniqueness
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $stmt->execute(['email' => $emailValidation['value']]);
                if ($stmt->fetch()) {
                    $errors['email'] = 'This email address is already registered.';
                }

                // Check username uniqueness
                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
                $stmt->execute(['username' => $userValidation['value']]);
                if ($stmt->fetch()) {
                    $errors['username'] = 'This username is already taken. Please choose another.';
                }

                // 4. Secure Insertion
                if (empty($errors)) {
                    $passwordHash = password_hash($passwordInput, PASSWORD_DEFAULT);

                    $insertStmt = $pdo->prepare(
                        'INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)'
                    );
                    $insertStmt->execute([
                        'username'      => $userValidation['value'],
                        'email'         => $emailValidation['value'],
                        'password_hash' => $passwordHash,
                    ]);

                    // Store flash message and redirect to login page (no auto-login)
                    $_SESSION['flash_success'] = 'Account created successfully! Please sign in with your email and password.';
                    header('Location: login.php', true, 303);
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Registration Database Error: ' . $e->getMessage());
                $errors['general'] = 'An unexpected system error occurred. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create an account on Project Exodia — Secure Next-Generation Messaging.">
    <title>Create Account | Project Exodia</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/auth.css">
</head>
<body class="auth-body">
    <div class="auth-bg-glow" aria-hidden="true"></div>

    <main class="auth-container">
        <header class="auth-header">
            <div class="brand-badge">
                <span class="badge-dot"></span>
                <span>PROJECT EXODIA</span>
            </div>
            <h1 class="auth-title">Initialize Account</h1>
            <p class="auth-subtitle">Join the secure communications network</p>
        </header>

        <section class="auth-card" aria-label="Registration Form">
            <?php if (!empty($errors['general'])): ?>
                <div class="alert alert-error" role="alert" id="general-error">
                    <span class="alert-icon" aria-hidden="true">&#9888;</span>
                    <span><?= sanitize_output($errors['general']) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors['csrf'])): ?>
                <div class="alert alert-error" role="alert" id="csrf-error">
                    <span class="alert-icon" aria-hidden="true">&#9888;</span>
                    <span><?= sanitize_output($errors['csrf']) ?></span>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST" class="auth-form" id="registerForm" novalidate>
                <?= csrf_input_field() ?>

                <!-- Username Field -->
                <div class="form-group <?= !empty($errors['username']) ? 'has-error' : '' ?>">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-wrapper">
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-input"
                            placeholder="e.g. neo_matrix"
                            value="<?= sanitize_output($usernameInput) ?>"
                            required
                            minlength="3"
                            maxlength="30"
                            autocomplete="username"
                            aria-describedby="username-hint <?= !empty($errors['username']) ? 'username-error' : '' ?>"
                            <?= !empty($errors['username']) ? 'aria-invalid="true"' : '' ?>
                        >
                    </div>
                    <span class="form-hint" id="username-hint">3–30 characters, letters, numbers, and underscores only.</span>
                    <?php if (!empty($errors['username'])): ?>
                        <span class="form-error" id="username-error" role="alert"><?= sanitize_output($errors['username']) ?></span>
                    <?php endif; ?>
                </div>

                <!-- Email Address Field -->
                <div class="form-group <?= !empty($errors['email']) ? 'has-error' : '' ?>">
                    <label for="email" class="form-label">Email Address</label>
                    <div class="input-wrapper">
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-input"
                            placeholder="name@example.com"
                            value="<?= sanitize_output($emailInput) ?>"
                            required
                            maxlength="255"
                            autocomplete="email"
                            aria-describedby="<?= !empty($errors['email']) ? 'email-error' : '' ?>"
                            <?= !empty($errors['email']) ? 'aria-invalid="true"' : '' ?>
                        >
                    </div>
                    <?php if (!empty($errors['email'])): ?>
                        <span class="form-error" id="email-error" role="alert"><?= sanitize_output($errors['email']) ?></span>
                    <?php endif; ?>
                </div>

                <!-- Password Field -->
                <div class="form-group <?= !empty($errors['password']) ? 'has-error' : '' ?>">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-wrapper with-toggle">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-input"
                            placeholder="••••••••"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            aria-describedby="password-hint <?= !empty($errors['password']) ? 'password-error' : '' ?>"
                            <?= !empty($errors['password']) ? 'aria-invalid="true"' : '' ?>
                        >
                        <button
                            type="button"
                            class="pwd-toggle-btn"
                            id="togglePassword"
                            aria-label="Toggle password visibility"
                            aria-controls="password"
                        >
                            <span class="toggle-icon" aria-hidden="true">👁</span>
                        </button>
                    </div>
                    <span class="form-hint" id="password-hint">Minimum 8 characters with at least one letter and one number.</span>
                    <?php if (!empty($errors['password'])): ?>
                        <span class="form-error" id="password-error" role="alert"><?= sanitize_output($errors['password']) ?></span>
                    <?php endif; ?>
                </div>

                <!-- Confirm Password Field -->
                <div class="form-group <?= !empty($errors['confirm_password']) ? 'has-error' : '' ?>">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <div class="input-wrapper with-toggle">
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            class="form-input"
                            placeholder="••••••••"
                            required
                            autocomplete="new-password"
                            aria-describedby="<?= !empty($errors['confirm_password']) ? 'confirm-error' : '' ?>"
                            <?= !empty($errors['confirm_password']) ? 'aria-invalid="true"' : '' ?>
                        >
                        <button
                            type="button"
                            class="pwd-toggle-btn"
                            id="toggleConfirmPassword"
                            aria-label="Toggle confirm password visibility"
                            aria-controls="confirm_password"
                        >
                            <span class="toggle-icon" aria-hidden="true">👁</span>
                        </button>
                    </div>
                    <?php if (!empty($errors['confirm_password'])): ?>
                        <span class="form-error" id="confirm-error" role="alert"><?= sanitize_output($errors['confirm_password']) ?></span>
                    <?php endif; ?>
                </div>

                <!-- Submit Button -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="registerSubmitBtn">
                        <span>Create Account</span>
                        <span class="btn-arrow" aria-hidden="true">→</span>
                    </button>
                </div>
            </form>

            <footer class="auth-footer">
                <p>Already have an account? <a href="login.php" class="auth-link" id="linkToLogin">Sign in here</a></p>
            </footer>
        </section>
    </main>

    <script src="js/validation.js"></script>
    <script src="js/toggle-pwd.js"></script>
</body>
</html>
