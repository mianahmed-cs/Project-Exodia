<?php
/**
 * Project Exodia — Login Page & Handler
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/auth.php';

start_secure_session();

// If already authenticated, redirect to dashboard
if (is_authenticated()) {
    header('Location: dashboard.php');
    exit;
}

// Flash messages from registration or logout
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$flashNotice = null;
if (isset($_GET['notice']) && $_GET['notice'] === 'logged_out') {
    $flashNotice = 'You have been securely logged out.';
}

$flashError = null;
if (isset($_GET['error']) && $_GET['error'] === 'unauthorized') {
    $flashError = 'Authentication required. Please sign in to access that page.';
}

$loginError = null;
$emailInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        http_response_code(403);
        $loginError = 'Invalid or expired security token. Please refresh and try again.';
    } else {
        $emailInput = trim($_POST['email'] ?? '');
        $passwordInput = $_POST['password'] ?? '';

        if (empty($emailInput) || empty($passwordInput)) {
            $loginError = 'Please enter both your email address and password.';
        } else {
            try {
                $pdo = get_db_connection();

                // 2. Query user by email using prepared statement
                $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = :email LIMIT 1');
                $stmt->execute(['email' => strtolower($emailInput)]);
                $user = $stmt->fetch();

                // 3. Verify password hash (generic error prevents user enumeration)
                if ($user && password_verify($passwordInput, $user['password_hash'])) {
                    // 4. Regenerate session ID to prevent session fixation attacks
                    session_regenerate_id(true);

                    // 5. Store authentication state
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['logged_in'] = true;

                    // 6. Redirect to protected dashboard
                    header('Location: dashboard.php', true, 303);
                    exit;
                } else {
                    // Timing-safe generic error message
                    $loginError = 'Invalid email address or password.';
                }
            } catch (PDOException $e) {
                error_log('Login Database Error: ' . $e->getMessage());
                $loginError = 'An unexpected system error occurred. Please try again later.';
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
    <meta name="description" content="Sign in to Project Exodia — Secure Next-Generation Messaging.">
    <title>Sign In | Project Exodia</title>
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
            <h1 class="auth-title">Welcome Back</h1>
            <p class="auth-subtitle">Authenticate to access your secure terminal</p>
        </header>

        <section class="auth-card" aria-label="Sign In Form">
            <!-- Flash & Error Banners -->
            <?php if ($flashSuccess): ?>
                <div class="alert alert-success" role="status" id="flashSuccess">
                    <span class="alert-icon" aria-hidden="true">&#10003;</span>
                    <span><?= sanitize_output($flashSuccess) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($flashNotice): ?>
                <div class="alert alert-info" role="status" id="flashNotice">
                    <span class="alert-icon" aria-hidden="true">&#8505;</span>
                    <span><?= sanitize_output($flashNotice) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="alert alert-warning" role="alert" id="flashError">
                    <span class="alert-icon" aria-hidden="true">&#9888;</span>
                    <span><?= sanitize_output($flashError) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($loginError): ?>
                <div class="alert alert-error" role="alert" id="loginError">
                    <span class="alert-icon" aria-hidden="true">&#9888;</span>
                    <span><?= sanitize_output($loginError) ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="auth-form" id="loginForm" novalidate>
                <?= csrf_input_field() ?>

                <!-- Email Address Field -->
                <div class="form-group">
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
                            autocomplete="email"
                            autofocus
                        >
                    </div>
                </div>

                <!-- Password Field with Visibility Toggle -->
                <div class="form-group">
                    <div class="label-row">
                        <label for="password" class="form-label">Password</label>
                    </div>
                    <div class="input-wrapper with-toggle">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-input"
                            placeholder="••••••••"
                            required
                            autocomplete="current-password"
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
                </div>

                <!-- Submit Button -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="loginSubmitBtn">
                        <span>Sign In</span>
                        <span class="btn-arrow" aria-hidden="true">→</span>
                    </button>
                </div>
            </form>

            <footer class="auth-footer">
                <p>Don't have an account yet? <a href="register.php" class="auth-link" id="linkToRegister">Create an account</a></p>
            </footer>
        </section>
    </main>

    <script src="js/validation.js"></script>
    <script src="js/toggle-pwd.js"></script>
</body>
</html>
