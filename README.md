# Project Exodia — Encrypted Web-Based Messaging Application

**Project Exodia** is an enterprise-grade, secure direct messaging platform engineered strictly within the native **LAMP stack** (Linux, Apache, MySQL, PHP) with zero external frameworks or Node.js runtime dependencies. It features end-to-end user authentication, directory-based operative discovery, contact management, near-real-time direct messaging with persistent MySQL history, photo and document sharing, message deletion, quoted replies, emoji reactions, and remote multi-device access over Cloudflare Tunnel.

---

## 1. Core Capabilities & Feature Highlights

- **Direct Encrypted Messaging**: Instant one-to-one messaging powered by near-real-time incremental polling (2.5s interval), smooth bubble animations, and continuous indexed MySQL history.
- **Photo & Document Sharing**:
  - **Photos**: JPEG, JPG, PNG, WebP, GIF (up to 10 MB). Inline thumbnails inside chat bubbles with full-size macOS-style Lightbox Modal viewer.
  - **Documents**: PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT (up to 25 MB). Rendered as compact attachment cards with format icons, file sizes, and authenticated downloads.
  - **Private Storage Isolation**: Uploads are stored strictly outside Apache's public document root (`storage/attachments/`) using random 32-character cryptographic hex filenames.
- **Advanced Message Controls**:
  - **Delete for Me**: Hides a message from the requester's chat and history while keeping it intact for the recipient.
  - **Delete for Everyone**: Allows original sender to mask a message for all participants with a subtle placeholder ("This message was deleted.") and disables file downloads (`410 Gone`).
  - **Quoted Replies**: Interactive reply pill quoting the referenced message with smooth scroll-and-highlight jump navigation.
  - **Emoji Reactions**: Express reactions (👍, ❤️, 😂, 😮, 😢, 😡) with live reaction pills, counters, and toggle actions.
- **Directory & User Discovery**: Live debounced search across all registered network operatives with contact roster management and instant channel creation.
- **Internet Remote Access via Cloudflare Tunnel**: Enables friends on different laptops, phones, and different Wi-Fi networks to connect seamlessly with persistent chat history.
- **Strict LAMP Architecture Compliance**:
  - **Zero External Frameworks**: Strictly NO React, Vue, Angular, Node.js, npm, Laravel, Symfony, Bootstrap, Tailwind, Firebase, or external cloud storage.

---

## 2. Technology Stack & Specifications

| Component | Technology | Specification & Implementation Details |
| :--- | :--- | :--- |
| **Operating System** | Ubuntu Linux | Tested on Ubuntu 24.04 LTS / Linux x86_64 |
| **Web Server** | Apache HTTP Server 2.4 | Multi-worker virtual host (`/var/www/html/exodia -> public/`) |
| **Backend Language** | PHP 8.3+ | Strict types (`declare(strict_types=1)`), PDO prepared statements, native sessions, GD, finfo |
| **Database** | MySQL 8.0+ | `utf8mb4` character set, `utf8mb4_unicode_ci` collation, InnoDB with `ON DELETE CASCADE` |
| **Frontend Markup** | HTML5 (Semantic) | Accessible semantic landmarks (`<main>`, `<aside>`, `<header>`, `<footer>`), ARIA attributes |
| **Frontend Styling** | Vanilla CSS3 | Custom dark glass design system, CSS custom properties, responsive flexbox & grid |
| **Frontend Interactivity**| Vanilla JavaScript (ES6+)| Fetch API, XMLHttpRequest for upload progress, DOM event controllers, incremental polling |
| **Tunnel / Networking** | Cloudflare Tunnel (`cloudflared`) | Free secure HTTPS reverse proxy tunnel with loopback proxy header validation |

---

## 3. Directory Structure

```text
Web-Project/
├── config/
│   ├── config.example.php                # Safe configuration template (tracked in Git)
│   ├── config.php                        # Local configuration file (.gitignore protected)
│   └── database.php                      # Singleton PDO database factory with native prepares
├── database/
│   ├── migrations/
│   │   ├── 001_initial_schema.sql        # Users table schema
│   │   ├── 002_create_contacts_table.sql # Contacts table schema
│   │   ├── 003_create_messaging_tables.sql# Conversations & messages schema
│   │   ├── 004_create_advanced_messaging_tables.sql # Hidden messages, reactions & events
│   │   └── 005_create_attachments_table.sql# Attachments table schema
│   └── schema.sql                        # Complete consolidated MySQL DDL schema
├── includes/
│   ├── attachments.php                   # Secure upload validation, storage, and retrieval
│   ├── auth.php                          # Auth guards (require_login, require_auth_api, is_authenticated)
│   ├── contacts.php                      # User discovery & contact management business logic
│   ├── csrf.php                          # 32-byte cryptographically secure CSRF token engine
│   ├── messaging.php                     # Conversations, messages, deletions, reactions, and polling
│   ├── session.php                       # Hardened session initialization with HTTPS reverse proxy detection
│   └── validator.php                     # Input sanitization, field validators & XSS escaping
├── public/                               # Apache Document Root (Symlinked as /var/www/html/exodia)
│   ├── api/                              # RESTful JSON API endpoints
│   │   ├── attachment.php                # Protected attachment preview & download endpoint
│   │   ├── contacts.php                  # Contact listing, addition & deletion endpoint
│   │   ├── conversations.php             # Conversation listing & creation endpoint
│   │   ├── messages.php                  # Message history, polling, deletion, reaction & upload endpoint
│   │   └── search_users.php              # Authenticated user discovery search endpoint
│   ├── css/
│   │   ├── auth.css                      # Authentication form layouts & glass inputs
│   │   ├── contacts.css                  # Contacts & discovery responsive grid layout
│   │   ├── landing.css                   # Modern landing page showcase & responsive cards
│   │   ├── messaging.css                 # Two-panel chat terminal, message bubbles & composer
│   │   └── style.css                     # Master design system tokens, typography & themes
│   ├── js/
│   │   ├── contacts.js                   # Client-side user discovery & contact roster controller
│   │   ├── messaging.js                  # Incremental polling, composer, lightbox, & reactions controller
│   │   ├── toggle-pwd.js                 # Accessible password visibility switch
│   │   └── validation.js                 # Instant client-side validation UX
│   ├── contacts.php                      # Protected contacts directory & discovery interface
│   ├── dashboard.php                     # Protected operative terminal dashboard
│   ├── index.php                         # Product landing page & feature showcase
│   ├── login.php                         # Authentication sign-in portal
│   ├── logout.php                        # Session destruction & sign-out handler
│   ├── messages.php                      # Protected real-time direct messaging application
│   └── register.php                      # Account registration portal
├── storage/
│   └── attachments/                      # Private directory for uploaded files (strictly outside web root)
│       └── .gitkeep
├── tests/                                # Automated verification test suites
│   ├── run_all_tests.php                 # Master automated test runner (10/10 suites)
│   ├── test_advanced_messaging.php       # Deletion, quoted replies, reactions & event sync
│   ├── test_attachment_sharing.php       # Photos, documents, validation, and live Apache downloads
│   ├── test_auth.php                     # Phase 1: Authentication & security suite
│   ├── test_contacts_mgmt.php            # Phase 2: Contact management suite
│   ├── test_discovery.php                # Phase 2: User discovery suite
│   ├── test_full_journey.php             # Phase 4: Full user journey verification
│   ├── test_messaging.php                # Phase 3: Messaging service suite
│   ├── test_messaging_e2e.php            # Phase 3: End-to-end messaging verification
│   ├── test_phase2_security.php          # Phase 2: Security & access guards
│   └── test_remote_messaging.php         # Remote proxy detection & multi-device simulation
└── README.md                             # Comprehensive project documentation
```

---

## 4. Local Setup & Running on One Laptop

Follow these steps to run Project Exodia locally on your Ubuntu laptop.

### Step 1: Install System Prerequisites
Ensure Apache, PHP 8.3 with required modules, and MySQL are installed:
```bash
sudo apt update
sudo apt install -y apache2 mysql-server php8.3 php8.3-mysql php8.3-gd php8.3-curl php8.3-mbstring acl curl git
```

Ensure services are running:
```bash
sudo systemctl enable --now apache2 mysql
```

---

### Step 2: Configure MySQL Database
1. Open MySQL as root:
   ```bash
   sudo mysql
   ```
2. Create the database and dedicated user:
   ```sql
   CREATE DATABASE IF NOT EXISTS project_exodia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER IF NOT EXISTS 'exodia_user'@'localhost' IDENTIFIED BY 'YourSecurePasswordHere!';
   GRANT ALL PRIVILEGES ON project_exodia.* TO 'exodia_user'@'localhost';
   FLUSH PRIVILEGES;
   EXIT;
   ```
3. Import the database schema:
   ```bash
   mysql -u exodia_user -p project_exodia < database/schema.sql
   ```

---

### Step 3: Application Configuration
Create your local configuration file from the template:
```bash
cp config/config.example.php config/config.php
```

Edit `config/config.php` with your database credentials:
```php
<?php
declare(strict_types=1);

return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'project_exodia',
        'username' => 'exodia_user',
        'password' => 'YourSecurePasswordHere!',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'name'           => 'Project Exodia',
        'url'            => 'http://localhost/exodia',
        'env'            => 'development',
        'session_secure' => false,
    ],
    'storage' => [
        'attachments_dir' => dirname(__DIR__) . '/storage/attachments',
    ],
];
```

---

### Step 4: Configure Storage Permissions (POSIX ACLs)
Project Exodia isolates file uploads outside the Apache web root in `storage/attachments/`. Grant Apache's `www-data` process secure read/write access without making the folder world-writable (`chmod 777` is strictly forbidden):
```bash
# Set secure POSIX ACLs for Apache without world permissions
setfacl -m u:www-data:rwx storage storage/attachments
setfacl -d -m u:www-data:rwx storage/attachments
setfacl -d -m u:$(whoami):rwx storage/attachments
chmod 0750 storage storage/attachments
```

---

### Step 5: Web Server Setup

#### Option A: Running via Apache (Recommended / Standard)
Create a symlink from Apache's web root to the project's `public/` folder:
```bash
sudo ln -s /home/mian-ahmed/Documents/Web-Project/public /var/www/html/exodia
```

Ensure traversal permissions on parent directories:
```bash
chmod o+x /home/mian-ahmed /home/mian-ahmed/Documents
```

Open your browser and navigate to:
```text
http://localhost/exodia/
```

#### Option B: Running via PHP Built-in Server (Development Only)
If you prefer not to use Apache for simple local testing:
```bash
php -S localhost:8000 -t public
```
Then navigate to `http://localhost:8000/`.

---

## 5. Cloudflare Tunnel Setup (Remote Access for Friends)

You can share Project Exodia over the internet so friends on **different laptops, mobile phones, and different Wi-Fi networks** can sign up, search for each other, and exchange messages with persistent chat history. Your Ubuntu laptop acts as the server.

No domain purchase, router port forwarding, or VPS migration is required.

---

### Step 1: Install `cloudflared` on Ubuntu
If not already installed:
```bash
# Download and install cloudflared
curl -fsSL https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb -o /tmp/cloudflared.deb
sudo dpkg -i /tmp/cloudflared.deb
```
Verify the installation:
```bash
cloudflared --version
```

---

### Step 2: Ensure Apache & MySQL are Running Locally
Before starting the tunnel, confirm the application is accessible on localhost:
```bash
curl -sI http://localhost/exodia/
```
You should receive an `HTTP/1.1 200 OK` response.

---

### Step 3: Launch Cloudflare Quick Tunnel
Run `cloudflared` pointing to your local Apache application:
```bash
cloudflared tunnel --url http://localhost/exodia
```

`cloudflared` will connect to Cloudflare's edge network and output a unique public HTTPS URL:
```text
+--------------------------------------------------------------------------------------------+
|  Your quick Tunnel has been created! Visit it at (it may take some time to be reachable):  |
|  https://random-words-subdomain.trycloudflare.com                                          |
+--------------------------------------------------------------------------------------------+
```

---

### Step 4: Share & Test with Friends
1. **Send the URL** (`https://random-words-subdomain.trycloudflare.com`) to your friends.
2. **Device 1 (Your Friend)**:
   - Opens the URL on their laptop or phone.
   - Clicks **Get Started** and creates an account (e.g. `operative_bob`).
3. **Device 2 (You or Another Friend)**:
   - Signs into another account (e.g. `operative_alice`).
   - Navigates to **Directory** / **Contacts**.
   - Searches for `operative_bob` and clicks **Add Contact**.
   - Clicks **Message** to initiate a direct encrypted channel.
4. **Communicate in Real Time**:
   - Send messages, photos, and PDF documents.
   - Click photo thumbnails to inspect in the full-size Lightbox modal.
   - Quote-reply to transmissions and add emoji reactions.
   - Test **Delete for Me** and **Delete for Everyone**.
   - Real-time updates sync automatically within 2.5 seconds via incremental polling without full page refreshes.
   - History persists securely in MySQL and reloads after logging out and reconnecting.

---

### Step 5: How Reverse Proxy Security Works
Project Exodia includes built-in reverse proxy security hardening in [`includes/session.php`](includes/session.php):
- **Automated HTTPS Cookie Upgrades**: When accessed via Cloudflare Tunnel, Cloudflare terminates SSL and forwards `CF-Visitor: {"scheme":"https"}` and `X-Forwarded-Proto: https` over the loopback interface (`127.0.0.1` / `::1`).
- **Loopback Proxy Validation**: The application validates that reverse proxy headers originate strictly from trusted loopback IPs before trusting them, blocking external header spoofing attempts.
- **Persistent Sessions**: Cookies are automatically flagged `Secure` over HTTPS while maintaining seamless fallback for local plain HTTP on `localhost`.

---

## 6. Security Architecture & Hardening

1. **SQL Injection Mathematical Neutralization**:
   - 100% of database interactions use native PDO prepared statements with `PDO::ATTR_EMULATE_PREPARES => false`.
   - Dynamic user data packets are isolated from SQL statement compilation.

2. **Cross-Site Scripting (XSS) Mitigation**:
   - Strict context-aware HTML escaping (`htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`) on all dynamic outputs.
   - Safe DOM manipulation via `textContent` in vanilla JavaScript.

3. **Cross-Site Request Forgery (CSRF) Protection**:
   - Cryptographically secure 32-byte tokens generated via `bin2hex(random_bytes(32))`.
   - Timing-safe verification via `hash_equals()`.
   - Enforced across all state-changing endpoints (`POST`).

4. **Private Storage Isolation & Defense-in-Depth**:
   - Uploaded photos and documents are written strictly outside the public document root (`storage/attachments/`).
   - Files are stored using cryptographic random hashes without original user filenames on the filesystem.
   - Protected download endpoint ([`public/api/attachment.php`](public/api/attachment.php)) enforces session verification, conversation participant checks, and strict `realpath()` path traversal validation.
   - Defensive response headers applied to downloads:
     ```http
     X-Content-Type-Options: nosniff
     X-Frame-Options: SAMEORIGIN
     Content-Security-Policy: default-src 'none'; sandbox
     ```
   - Inline previews permitted only for validated images; all documents forced as downloads.

5. **Session Hardening & Cookie Flags**:
   - `session_regenerate_id(true)` invoked on login to prevent session fixation.
   - `HttpOnly`: Set to `true` (blocks JavaScript access via `document.cookie`).
   - `SameSite`: Set to `'Lax'` (mitigates cross-site request forgery).
   - Strict mode enabled (`session.use_strict_mode = 1`).

6. **Insecure Direct Object Reference (IDOR) Protection**:
   - Every message retrieval, message send, attachment download, deletion, and reaction checks `conversation_participants`. Non-participants receive an immediate `HTTP 403 Forbidden` or `HTTP 404 Not Found`.

7. **Bcrypt Password Cryptography**:
   - Passwords hashed using native `password_hash()` with Bcrypt key derivation and automatic work factor salting.

---

## 7. Master Automated Verification Matrix (195 / 195 Passing)

Execute the complete master test suite with zero external dependencies:
```bash
php tests/run_all_tests.php
```

### Comprehensive Test Suite Breakdown:

| Test Suite File | Coverage Scope | Test Count | Result |
| :--- | :--- | :---: | :---: |
| `tests/test_auth.php` | Phase 1: Registration, Login, Logout, CSRF, Bcrypt, Session Guards | 15 / 15 | **PASS** |
| `tests/test_discovery.php` | Phase 2: User search, self-exclusion, limit, SQLi, safe fields | 7 / 7 | **PASS** |
| `tests/test_contacts_mgmt.php`| Phase 2: Add/remove contacts, self/dup prevention, cascade | 10 / 10 | **PASS** |
| `tests/test_phase2_security.php`| Phase 2: Access guards, API auth, CSRF, XSS, cross-user isolation | 17 / 17 | **PASS** |
| `tests/test_messaging.php` | Phase 3: Conversation creation, participant checks, polling | 17 / 17 | **PASS** |
| `tests/test_messaging_e2e.php`| Phase 3: Direct transmission exchange, IDOR isolation, limits | 20 / 20 | **PASS** |
| `tests/test_full_journey.php` | Phase 4: Master end-to-end operative workflow & cleanup | 15 / 15 | **PASS** |
| `tests/test_remote_messaging.php` | Cloudflare Tunnel proxy detection, remote multi-device sync | 17 / 17 | **PASS** |
| `tests/test_advanced_messaging.php` | Delete for Me/Everyone, quoted replies, emoji reactions, events | 28 / 28 | **PASS** |
| `tests/test_attachment_sharing.php` | Photo & doc upload, MIME inspection, live Apache downloads | 49 / 49 | **PASS** |
| **Total Automated Tests** | **Complete Multi-Phase System Verification** | **195 / 195** | **100% PASS** |

---

## 8. Common Troubleshooting & FAQs

### 1. "Failed to send transmission: Failed to write uploaded file to private storage."
- **Cause**: Apache's `www-data` user lacks write permissions on `storage/attachments/`.
- **Solution**: Execute the POSIX ACL command (never use `chmod 777`):
  ```bash
  setfacl -m u:www-data:rwx storage storage/attachments
  setfacl -d -m u:www-data:rwx storage/attachments
  chmod 0750 storage storage/attachments
  ```

### 2. "Authentication required. Please sign in." on API requests
- **Cause**: Session cookie expired or missing CSRF token header (`X-CSRF-Token`).
- **Solution**: Sign in via `login.php` to establish an active session. The application automatically injects session CSRF tokens into all JavaScript API requests.

### 3. File upload size limits
- Images are restricted to 10 MB and documents to 25 MB.
- In PHP's configuration (`/etc/php/8.3/apache2/php.ini`), verify `upload_max_filesize` and `post_max_size` are appropriately configured if transmitting files larger than default PHP limits.

---

## 9. License & Academic Integrity

This project is developed for academic evaluation in Web Technologies. All code is original and adheres strictly to LAMP architecture standards with zero unauthorized third-party libraries or frameworks.
