<?php
/**
 * Project Exodia — Protected Dashboard / Terminal
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/auth.php';

// Strict access guard: redirects to login.php if session is missing or invalid
require_login();

$user = current_auth_user();
$username = $user['username'] ?? 'Operative';
$email = $user['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Project Exodia Secure Terminal Dashboard">
    <title>Secure Dashboard | Project Exodia</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/auth.css">
    <link rel="stylesheet" href="css/contacts.css">
</head>
<body class="dashboard-body">
    <div class="auth-bg-glow" aria-hidden="true"></div>

    <nav class="dashboard-nav" aria-label="Main Navigation">
        <div class="nav-container">
            <div class="nav-brand-group">
                <div class="macos-traffic-lights" aria-hidden="true">
                    <span class="traffic-dot traffic-red"></span>
                    <span class="traffic-dot traffic-yellow"></span>
                    <span class="traffic-dot traffic-green"></span>
                </div>
                <div class="brand-badge" style="margin-bottom: 0;">
                    <span class="badge-dot"></span>
                    <span>PROJECT EXODIA</span>
                </div>
            </div>

            <div class="nav-links">
                <a href="dashboard.php" class="nav-link active" id="navDashboard" aria-current="page">Terminal</a>
                <a href="contacts.php" class="nav-link" id="navContacts">Contacts</a>
                <a href="messages.php" class="nav-link" id="navMessages">Messages</a>
            </div>

            <div class="nav-user-actions">
                <span class="nav-status-badge">
                    <span class="status-indicator-live"></span>
                    <span>Active Session</span>
                </span>
                <form action="logout.php" method="POST" class="inline-form">
                    <?= csrf_input_field() ?>
                    <button type="submit" class="btn btn-secondary btn-sm" id="logoutButton">
                        <span>Sign Out</span>
                        <span aria-hidden="true">&#x21AA;</span>
                    </button>
                </form>
            </div>
        </div>
    </nav>

    <main class="dashboard-main">
        <div class="dashboard-container">
            <!-- Welcome Hero -->
            <section class="dashboard-hero card">
                <div class="hero-badge">OPERATIVE TERMINAL</div>
                <h1 class="hero-title">Welcome, <span class="highlight-text"><?= sanitize_output($username) ?></span></h1>
                <p class="hero-subtitle">Authentication validated successfully. Your secure session is active and protected across the Project Exodia communications network.</p>
                
                <div class="session-stats-grid">
                    <div class="stat-card">
                        <span class="stat-label">User Identifier</span>
                        <span class="stat-value">#<?= sanitize_output((string)$user['id']) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Registered Email</span>
                        <span class="stat-value"><?= sanitize_output($email) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Security Protocol</span>
                        <span class="stat-value">Bcrypt / Native PDO</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Cookie Protection</span>
                        <span class="stat-value text-accent">HttpOnly + SameSite</span>
                    </div>
                </div>

                <div style="margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <a href="messages.php" class="btn btn-primary" id="dashboardMessagesAction">
                        <span>Launch Direct Messages</span>
                        <span class="btn-arrow" aria-hidden="true">&rarr;</span>
                    </a>
                    <a href="contacts.php" class="btn btn-secondary" id="dashboardContactsAction">
                        <span>Manage Contacts</span>
                    </a>
                </div>
            </section>

            <!-- System Overview Card -->
            <section class="card overview-card">
                <h2 class="card-title">Active Platform Security Controls</h2>
                <div class="system-checklist">
                    <div class="check-item">
                        <span class="check-icon">&#10003;</span>
                        <div class="check-content">
                            <strong>Identity & Discovery:</strong>
                            <p>Data validated client-side and independently on server; protected contact management and operative directory.</p>
                        </div>
                    </div>
                    <div class="check-item">
                        <span class="check-icon">&#10003;</span>
                        <div class="check-content">
                            <strong>Cryptographic Protection:</strong>
                            <p>Stored as cryptographically salted hashes using PHP's native <code>password_hash()</code>.</p>
                        </div>
                    </div>
                    <div class="check-item">
                        <span class="check-icon">&#10003;</span>
                        <div class="check-content">
                            <strong>Session Fixation & CSRF Defense:</strong>
                            <p>Session ID regenerated upon authentication; tokenized state-changing forms.</p>
                        </div>
                    </div>
                    <div class="check-item">
                        <span class="check-icon">&#10003;</span>
                        <div class="check-content">
                            <strong>Access Guard & IDOR Isolation:</strong>
                            <p>Strict access gates and participant membership validation across all direct transmission channels.</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <footer class="dashboard-footer">
        <p>&copy; <?= date('Y') ?> Project Exodia. All rights reserved.</p>
    </footer>
</body>
</html>
