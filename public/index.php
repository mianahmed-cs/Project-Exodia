<?php
/**
 * Project Exodia — Modern Direct Messaging Platform
 * macOS-Inspired Product Landing Page
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

start_secure_session();
$isAuthenticated = is_authenticated();
$currentUser = $isAuthenticated ? current_auth_user() : null;
$username = $currentUser['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Project Exodia — A modern, elegant web direct messaging platform with secure photo and document sharing, persistent chat history, and refined glass aesthetics.">
    <title>Project Exodia — Conversations, Beautifully Connected</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/landing.css">
</head>
<body>
    <div class="auth-bg-glow" aria-hidden="true"></div>

    <!-- macOS Navigation Bar -->
    <header class="dashboard-nav landing-nav" aria-label="Main Navigation">
        <div class="nav-container">
            <div class="nav-brand-group">
                <div class="macos-traffic-lights" aria-hidden="true">
                    <span class="traffic-dot traffic-red"></span>
                    <span class="traffic-dot traffic-yellow"></span>
                    <span class="traffic-dot traffic-green"></span>
                </div>
                <a href="index.php" style="text-decoration: none; display: flex; align-items: center; gap: 8px;">
                    <div class="brand-badge" style="margin-bottom: 0;">
                        <span class="badge-dot"></span>
                        <span>PROJECT EXODIA</span>
                    </div>
                </a>
            </div>

            <nav class="nav-links" aria-label="Quick Links">
                <a href="#features" class="nav-link">Features</a>
                <a href="#showcase" class="nav-link">Preview</a>
                <?php if ($isAuthenticated): ?>
                    <a href="messages.php" class="nav-link">Messages</a>
                    <a href="contacts.php" class="nav-link">Directory</a>
                <?php endif; ?>
            </nav>

            <div class="nav-user-actions">
                <?php if ($isAuthenticated): ?>
                    <span class="nav-status-badge">
                        <span class="status-indicator-live"></span>
                        <span><?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    </span>
                    <a href="messages.php" class="btn btn-primary btn-sm">Launch Messages &rarr;</a>
                    <a href="dashboard.php" class="btn btn-secondary btn-sm">Terminal</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-secondary btn-sm" id="landingSignInBtn">Sign In</a>
                    <a href="register.php" class="btn btn-primary btn-sm" id="landingGetStartedBtn">Get Started</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <main>
        <section class="landing-hero landing-container">
            <div class="hero-pill">
                <span>✦</span>
                <span>DIRECT MESSAGING PLATFORM</span>
            </div>

            <h1 class="hero-title">
                Conversations, <span class="gradient-text">beautifully connected.</span>
            </h1>

            <p class="hero-subtitle">
                Meet Project Exodia — a clean, modern space to connect, communicate, and stay in touch. Engineered with native LAMP precision and refined desktop aesthetics.
            </p>

            <div class="hero-cta-group">
                <?php if ($isAuthenticated): ?>
                    <a href="messages.php" class="btn btn-primary btn-lg" id="heroLaunchBtn">
                        <span>Open Messages</span>
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                    <a href="contacts.php" class="btn btn-secondary btn-lg" id="heroContactsBtn">
                        <span>Operative Directory</span>
                    </a>
                <?php else: ?>
                    <a href="register.php" class="btn btn-primary btn-lg" id="heroRegisterBtn">
                        <span>Get Started Free</span>
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                    <a href="login.php" class="btn btn-secondary btn-lg" id="heroLoginBtn">
                        <span>Sign In</span>
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <!-- macOS Window Showcase -->
        <section id="showcase" class="showcase-wrapper" aria-label="Project Exodia Interface Showcase">
            <div class="macos-showcase-window">
                <!-- Window Titlebar -->
                <div class="showcase-titlebar">
                    <div class="macos-traffic-lights" aria-hidden="true">
                        <span class="traffic-dot traffic-red"></span>
                        <span class="traffic-dot traffic-yellow"></span>
                        <span class="traffic-dot traffic-green"></span>
                    </div>
                    <span class="showcase-window-title">Project Exodia — Messages</span>
                </div>

                <!-- Window Content -->
                <div class="showcase-content">
                    <!-- Left Sidebar -->
                    <div class="showcase-sidebar">
                        <div class="showcase-search">
                            <span>🔍 Search conversations...</span>
                        </div>
                        <div class="showcase-chat-item active">
                            <div class="showcase-avatar">AK</div>
                            <div class="showcase-item-text">
                                <span class="showcase-item-name">alex_k</span>
                                <span class="showcase-item-preview">All systems online and validated.</span>
                            </div>
                        </div>
                        <div class="showcase-chat-item">
                            <div class="showcase-avatar">SB</div>
                            <div class="showcase-item-text">
                                <span class="showcase-item-name">sarah_b</span>
                                <span class="showcase-item-preview">Acknowledged. Standing by for dispatch.</span>
                            </div>
                        </div>
                        <div class="showcase-chat-item">
                            <div class="showcase-avatar">NT</div>
                            <div class="showcase-item-text">
                                <span class="showcase-item-name">neo_tech</span>
                                <span class="showcase-item-preview">Direct channel initialized.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Main Chat Panel -->
                    <div class="showcase-chat-main">
                        <div class="showcase-chat-header">
                            <div class="showcase-avatar" style="width: 32px; height: 32px; font-size: 0.8rem;">AK</div>
                            <div>
                                <strong style="font-size: 0.92rem; display: block;">alex_k</strong>
                                <span style="font-size: 0.75rem; color: var(--macos-green); display: flex; align-items: center; gap: 4px;">
                                    <span style="width: 5px; height: 5px; background: var(--macos-green); border-radius: 50%;"></span>
                                    Encrypted Channel Active
                                </span>
                            </div>
                        </div>

                        <div class="showcase-messages">
                            <div class="showcase-msg received">
                                <span>Operative Alpha, direct transmission confirmed. Have you inspected the new interface?</span>
                            </div>
                            <div class="showcase-msg sent">
                                <span>Interface overhaul complete. Frosted glass panels, consistent spacing, and real-time polling are fully active.</span>
                            </div>
                            <div class="showcase-msg received">
                                <span>Outstanding. Clean typography, responsive controls, and fluid messaging make this look incredible.</span>
                            </div>
                        </div>

                        <div class="showcase-composer">
                            <div class="showcase-input-pill">
                                <span>Type a message...</span>
                            </div>
                            <button type="button" class="showcase-send-btn" aria-label="Send">
                                &#10148;
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Feature Highlights Section -->
        <section id="features" class="features-section landing-container" aria-labelledby="featuresHeading">
            <div class="section-header">
                <span class="section-eyebrow">BUILT FOR CLARITY</span>
                <h2 id="featuresHeading" class="section-title">Everything you need in a modern messenger.</h2>
            </div>

            <div class="features-grid">
                <!-- Feature 1 -->
                <article class="feature-card">
                    <div class="feature-icon-wrapper" aria-hidden="true">💬</div>
                    <h3 class="feature-title">Direct Messaging</h3>
                    <p class="feature-desc">
                        Instant one-to-one messaging powered by near-real-time incremental polling, smooth bubble animations, and zero framework overhead.
                    </p>
                </article>

                <!-- Feature 2 -->
                <article class="feature-card">
                    <div class="feature-icon-wrapper" aria-hidden="true">📷</div>
                    <h3 class="feature-title">Photo & Document Sharing</h3>
                    <p class="feature-desc">
                        Send photos with instant full-size lightbox previews and share confidential documents safely through verified private storage.
                    </p>
                </article>

                <!-- Feature 3 -->
                <article class="feature-card">
                    <div class="feature-icon-wrapper" aria-hidden="true">❤️</div>
                    <h3 class="feature-title">Replies, Reactions & Deletion</h3>
                    <p class="feature-desc">
                        Quote-reply to messages, express yourself with real-time emoji reactions, and delete messages for yourself or everyone.
                    </p>
                </article>

                <!-- Feature 4 -->
                <article class="feature-card">
                    <div class="feature-icon-wrapper" aria-hidden="true">🔍</div>
                    <h3 class="feature-title">User Discovery</h3>
                    <p class="feature-desc">
                        Search registered operatives across the network with live debounced queries and curate your personalized communication roster.
                    </p>
                </article>

                <!-- Feature 5 -->
                <article class="feature-card">
                    <div class="feature-icon-wrapper" aria-hidden="true">⚡</div>
                    <h3 class="feature-title">Continuous History</h3>
                    <p class="feature-desc">
                        Indexed MySQL message persistence ensures your conversations are organized, chronologically preserved, and instantly accessible.
                    </p>
                </article>

                <!-- Feature 6 -->
                <article class="feature-card">
                    <div class="feature-icon-wrapper" aria-hidden="true">📱</div>
                    <h3 class="feature-title">Responsive Multi-Device</h3>
                    <p class="feature-desc">
                        Thoughtfully crafted layout that flows seamlessly between desktop workstations, tablets, and mobile screens without horizontal overflow.
                    </p>
                </article>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="dashboard-footer">
        <div class="landing-container" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <span>&copy; <?= date('Y') ?> Project Exodia. All rights reserved.</span>
            <div style="display: flex; gap: 16px;">
                <a href="login.php" style="color: var(--text-muted); text-decoration: none;">Sign In</a>
                <a href="register.php" style="color: var(--text-muted); text-decoration: none;">Create Account</a>
                <a href="messages.php" style="color: var(--text-muted); text-decoration: none;">Messages</a>
            </div>
        </div>
    </footer>
</body>
</html>
