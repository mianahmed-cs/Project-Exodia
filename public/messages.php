<?php
/**
 * Project Exodia — Direct Messaging Terminal
 *
 * Primary communication interface for authenticated operatives:
 *  - Interactive conversation sidebar (active chats & contacts directory)
 *  - Near-real-time direct messaging with AJAX polling
 *  - Responsive desktop and mobile panels
 *  - macOS-inspired refined glass aesthetics
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
    <link rel="stylesheet" href="css/style.css?v=2.3">
    <link rel="stylesheet" href="css/auth.css?v=2.3">
    <link rel="stylesheet" href="css/contacts.css?v=2.3">
    <link rel="stylesheet" href="css/messaging.css?v=2.3">
</head>
<body class="dashboard-body" style="overflow: hidden;">
    <div class="auth-bg-glow" aria-hidden="true"></div>

    <!-- Navigation Bar -->
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
                <a href="dashboard.php" class="nav-link" id="navDashboard">Terminal</a>
                <a href="contacts.php" class="nav-link" id="navContacts">Contacts</a>
                <a href="messages.php" class="nav-link active" id="navMessages" aria-current="page">Messages</a>
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

    <!-- Two-Panel Messaging Layout -->
    <div class="messaging-layout">
        <!-- Sidebar Panel: Conversations & Contacts -->
        <aside class="sidebar-panel" aria-label="Conversations Sidebar">
            <div class="sidebar-header">
                <div class="sidebar-header-top">
                    <h2 class="sidebar-title">Messages</h2>
                    <span class="sidebar-count-badge" id="sidebarCountBadge" aria-hidden="true"></span>
                </div>
                <div class="sidebar-search-wrap">
                    <svg class="sidebar-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input
                        type="text"
                        id="searchFilterInput"
                        class="sidebar-search"
                        placeholder="Search conversations..."
                        autocomplete="off"
                        aria-label="Filter conversations or contacts"
                    >
                </div>
            </div>

            <div class="sidebar-tabs-container">
                <div class="sidebar-tabs" role="tablist">
                    <button type="button" class="sidebar-tab active" id="tabChats" role="tab" aria-selected="true">Chats</button>
                    <button type="button" class="sidebar-tab" id="tabContacts" role="tab" aria-selected="false">Contacts</button>
                </div>
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
                <div class="chat-empty-icon-wrap" aria-hidden="true">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                </div>
                <h3 class="chat-empty-title">Select a Conversation</h3>
                <p class="chat-empty-subtitle">
                    Choose a conversation from the sidebar or select an operative from your contacts to start chatting.
                </p>
                <a href="contacts.php" class="btn btn-secondary btn-sm" style="margin-top: 6px;">
                    <span>Discover Operatives</span>
                </a>
            </div>

            <!-- Chat Header -->
            <header id="chatHeader" class="chat-header" style="display: none;">
                <button type="button" id="chatBackBtn" class="chat-back-btn" aria-label="Return to conversations list">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <div class="chat-header-avatar" id="chatHeaderAvatar" aria-hidden="true">OP</div>
                <div class="chat-header-info">
                    <h3 id="chatHeaderName" class="chat-header-name">Operative</h3>
                    <div class="chat-header-meta">
                        <span class="chat-status-dot"></span>
                        <span id="chatHeaderStatus" class="chat-header-status">Encrypted Direct Channel</span>
                    </div>
                </div>
            </header>

            <!-- Scrollable Message Bubble Area -->
            <div id="chatMessages" class="chat-messages" style="display: none;" aria-live="polite" aria-label="Message Stream"></div>

            <!-- Message Composer -->
            <footer id="chatComposer" class="chat-composer" style="display: none;">
                <!-- Quoted Reply Composer Bar -->
                <div id="composerReplyBar" class="composer-reply-bar">
                    <div class="reply-bar-content">
                        <span class="reply-bar-title" id="replyBarTitle">Replying to Operative</span>
                        <span class="reply-bar-text" id="replyBarText">Original message preview</span>
                    </div>
                    <button type="button" id="cancelReplyBtn" class="reply-bar-close" aria-label="Cancel reply">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>

                <!-- Attachment Staging Preview Bar -->
                <div id="composerAttachmentBar" class="composer-attachment-bar" style="display: none;">
                    <div class="attachment-preview-icon" id="attachmentPreviewIcon">📎</div>
                    <div class="attachment-preview-details">
                        <span class="attachment-preview-name" id="attachmentPreviewName">attachment.png</span>
                        <span class="attachment-preview-size" id="attachmentPreviewSize">1.2 MB</span>
                        <div id="attachmentProgressBar" class="attachment-progress-wrap" style="display: none;">
                            <div id="attachmentProgressFill" class="attachment-progress-fill"></div>
                        </div>
                    </div>
                    <button type="button" id="removeAttachmentBtn" class="attachment-preview-remove" aria-label="Remove attachment">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>

                <div class="composer-pill">
                    <!-- Attachment Paperclip Button -->
                    <button type="button" id="attachBtn" class="composer-attach" aria-label="Attach photo or document" title="Attach file or photo">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                        </svg>
                    </button>
                    <input type="file" id="attachmentInput" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" style="display: none;" />

                    <textarea
                        id="messageInput"
                        class="composer-input"
                        rows="1"
                        placeholder="Type a message..."
                        aria-label="Message content"
                        maxlength="5000"
                    ></textarea>
                    <button type="button" id="sendBtn" class="composer-send" aria-label="Send message">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 19V5M5 12l7-7 7 7"/>
                        </svg>
                    </button>
                </div>
            </footer>
        </main>
    </div>

    <!-- Full-Size Image Viewer Modal (Lightbox) -->
    <div id="imageViewerModal" class="image-viewer-modal" style="display: none;" role="dialog" aria-modal="true" aria-label="Full size image preview">
        <div class="image-viewer-backdrop" id="imageViewerBackdrop"></div>
        <div class="image-viewer-header">
            <div class="image-viewer-info">
                <span id="imageViewerTitle" class="image-viewer-title">Photo Preview</span>
                <span id="imageViewerMeta" class="image-viewer-meta"></span>
            </div>
            <div class="image-viewer-actions">
                <a id="imageViewerDownload" href="#" download class="image-viewer-download-btn" title="Download Image">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span>Download</span>
                </a>
                <button type="button" id="imageViewerClose" class="image-viewer-close-btn" aria-label="Close image viewer">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>
        <div class="image-viewer-body">
            <img id="imageViewerImg" src="" alt="Full size preview" class="image-viewer-img" />
        </div>
    </div>

    <!-- Client-side Logic -->
    <script src="js/messaging.js?v=2.3"></script>
</body>
</html>
