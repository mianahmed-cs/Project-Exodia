<?php
/**
 * Project Exodia — Direct Messaging Terminal
 *
 * Primary communication interface for authenticated operatives:
 *  - Interactive conversation sidebar (active chats & contacts directory)
 *  - Near-real-time direct messaging with AJAX polling
 *  - Responsive desktop and mobile panels
 *  - Full CSRF, XSS, and authorization enforcement
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/auth.php';

// Access guard
require_login();

$user = current_auth_user();
$username = $user['username'] ?? 'Operative';
$userId = (int)($user['id'] ?? 0);
$csrfToken = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Project Exodia — Encrypted Direct Messaging Network">
    <meta name="csrf-token" content="<?= sanitize_output($csrfToken) ?>">
    <meta name="user-id" content="<?= $userId ?>">
    <title>Messages | Project Exodia</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/auth.css">
    <link rel="stylesheet" href="css/contacts.css">
    <link rel="stylesheet" href="css/messaging.css">
</head>
<body class="dashboard-body" style="overflow: hidden;">
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
                <a href="contacts.php" class="nav-link" id="navContacts">Contacts</a>
                <a href="messages.php" class="nav-link active" id="navMessages" aria-current="page">Messages</a>
            </div>

            <div class="nav-user-actions">
                <span class="nav-status-badge">
                    <span class="status-indicator-live"></span>
                    <span>Encrypted Mesh</span>
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

    <!-- Two-Panel Messaging Layout -->
    <div class="messaging-layout">
        <!-- Sidebar Panel: Conversations & Contacts -->
        <aside class="sidebar-panel" aria-label="Conversations Sidebar">
            <div class="sidebar-header">
                <h2 class="sidebar-title">
                    <span aria-hidden="true">⚡</span> Transmissions
                </h2>
                <input
                    type="text"
                    id="searchFilterInput"
                    class="sidebar-search"
                    placeholder="Filter conversations or roster..."
                    autocomplete="off"
                    aria-label="Filter conversations or contacts"
                >
            </div>

            <div class="sidebar-tabs" role="tablist">
                <button type="button" class="sidebar-tab active" id="tabChats" role="tab" aria-selected="true">Chats</button>
                <button type="button" class="sidebar-tab" id="tabContacts" role="tab" aria-selected="false">Contacts</button>
            </div>

            <div class="sidebar-list">
                <div id="conversationList" role="region" aria-label="Active Conversations">
                    <div class="sidebar-empty">
                        <div class="loading-dots"><span></span><span></span><span></span></div>
                        <span>Synchronizing transmissions...</span>
                    </div>
                </div>
                <div id="contactsTabList" style="display: none;" role="region" aria-label="Contact Roster">
                    <div class="sidebar-empty">
                        <div class="loading-dots"><span></span><span></span><span></span></div>
                        <span>Loading contact roster...</span>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Right Panel: Active Chat View -->
        <main class="chat-panel" aria-label="Active Conversation">
            <!-- Initial Welcome State (Shown when no chat is selected) -->
            <div id="chatEmptyState" class="chat-empty-state">
                <span class="chat-empty-icon" aria-hidden="true">💬</span>
                <h3 class="chat-empty-title">Secure Transmission Terminal</h3>
                <p class="chat-empty-subtitle">
                    Select a conversation from the sidebar or click on any contact to initialize an encrypted direct channel.
                </p>
                <a href="contacts.php" class="btn btn-secondary btn-sm" style="margin-top: 12px;">
                    Discover New Operatives
                </a>
            </div>

            <!-- Chat Header -->
            <header id="chatHeader" class="chat-header" style="display: none;">
                <button type="button" id="chatBackBtn" class="chat-back-btn" aria-label="Return to conversations list">
                    &#8592;
                </button>
                <div class="chat-header-info">
                    <h3 id="chatHeaderName" class="chat-header-name">Operative</h3>
                    <span id="chatHeaderStatus" class="chat-header-status">Encrypted Direct Channel</span>
                </div>
            </header>

            <!-- Scrollable Message Bubble Area -->
            <div id="chatMessages" class="chat-messages" style="display: none;" aria-live="polite" aria-label="Message Stream"></div>

            <!-- Message Composer -->
            <footer id="chatComposer" class="chat-composer" style="display: none;">
                <textarea
                    id="messageInput"
                    class="composer-input"
                    rows="1"
                    placeholder="Type an encrypted message... (Press Enter to send)"
                    aria-label="Message content"
                    maxlength="5000"
                ></textarea>
                <button type="button" id="sendBtn" class="composer-send" aria-label="Send message">
                    <span aria-hidden="true">&#10148;</span>
                </button>
            </footer>
        </main>
    </div>

    <!-- Client-side Logic -->
    <script src="js/messaging.js"></script>
</body>
</html>
