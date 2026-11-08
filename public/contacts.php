<?php
/**
 * Project Exodia — Contacts & User Discovery Interface
 * 
 * Protected view allowing authenticated operatives to:
 *  - Discover other registered users across the network
 *  - Add and manage personal contacts
 *  - Review connection status and roster
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/auth.php';

// Strict access guard: redirects to login.php if session is missing
require_login();

$user = current_auth_user();
$username = $user['username'] ?? 'Operative';
$csrfToken = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Project Exodia — Operative Directory & Contact Management">
    <meta name="csrf-token" content="<?= sanitize_output($csrfToken) ?>">
    <title>Contacts & Discovery | Project Exodia</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/auth.css">
    <link rel="stylesheet" href="css/contacts.css">
</head>
<body class="dashboard-body">
    <div class="auth-bg-glow" aria-hidden="true"></div>

    <!-- Navigation Bar -->
    <nav class="dashboard-nav" aria-label="Main Navigation">
        <div class="nav-container">
            <div class="brand-badge">
                <span class="badge-dot"></span>
                <span>PROJECT EXODIA</span>
            </div>

            <div class="nav-links">
                <a href="dashboard.php" class="nav-link" id="navDashboard">Terminal</a>
                <a href="contacts.php" class="nav-link active" id="navContacts" aria-current="page">Contacts</a>
                <a href="messages.php" class="nav-link" id="navMessages">Messages</a>
            </div>

            <div class="nav-user-actions">
                <span class="nav-status-badge">
                    <span class="status-indicator-live"></span>
                    <span>Encrypted</span>
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

    <!-- Main Content -->
    <main class="dashboard-main">
        <div class="dashboard-container">
            <!-- Header Hero -->
            <section class="dashboard-hero card">
                <div class="hero-badge">NETWORK DIRECTORY</div>
                <h1 class="hero-title">Contacts & <span class="highlight-text">Discovery</span></h1>
                <p class="hero-subtitle">
                    Search registered operatives across the Project Exodia communications mesh and curate your secure contact roster.
                </p>
            </section>

            <!-- Responsive 2-Column Layout -->
            <div class="contacts-layout">
                <!-- Column 1: User Discovery -->
                <section class="card discovery-panel" aria-labelledby="discoveryTitle">
                    <div class="panel-header">
                        <h2 class="panel-title" id="discoveryTitle">
                            <span aria-hidden="true">🔍</span> Discover Operatives
                        </h2>
                    </div>

                    <form id="searchForm" class="search-form" role="search" novalidate>
                        <div class="search-input-wrapper">
                            <span class="search-icon" aria-hidden="true">🔎</span>
                            <input
                                type="text"
                                id="searchInput"
                                class="search-input"
                                placeholder="Search by username..."
                                autocomplete="off"
                                aria-label="Search operatives by username"
                                maxlength="50"
                            >
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" id="searchSubmitBtn">
                            Search
                        </button>
                    </form>

                    <div id="searchResults" class="operative-list" aria-live="polite" aria-label="Search Results">
                        <div class="state-box">
                            <span class="state-icon" aria-hidden="true">🔍</span>
                            <span>Type a username above to search registered operatives.</span>
                        </div>
                    </div>
                </section>

                <!-- Column 2: Personal Contact Roster -->
                <section class="card roster-panel" aria-labelledby="rosterTitle">
                    <div class="panel-header">
                        <h2 class="panel-title" id="rosterTitle">
                            <span aria-hidden="true">👥</span> My Contacts
                            <span class="count-badge" id="contactsCount">0 Operatives</span>
                        </h2>
                        <button type="button" class="btn btn-secondary btn-sm" id="refreshContactsBtn" title="Refresh Contacts">
                            <span aria-hidden="true">↻</span> Refresh
                        </button>
                    </div>

                    <div id="contactsList" class="operative-list" aria-live="polite" aria-label="My Contact Roster">
                        <div class="state-box">
                            <div class="loading-spinner" aria-hidden="true"></div>
                            <span>Loading contacts...</span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="dashboard-footer">
        <p>&copy; <?= date('Y') ?> Project Exodia. Web Technologies Course — Phase 2: User Discovery &amp; Contacts.</p>
    </footer>

    <!-- Client-side Logic -->
    <script src="js/contacts.js"></script>
</body>
</html>
