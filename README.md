# Project Exodia — Phase 1: Authentication System

**Project Exodia** is a secure messaging application foundation developed for the Web Technologies course. **Phase 1** delivers an enterprise-grade user registration and authentication system built entirely with vanilla technologies in a LAMP environment.

---

## 1. Technology Restrictions & Compliance

This project complies strictly with all university requirements:

| Component | Technology | Compliance Notes |
| :--- | :--- | :--- |
| **Operating System** | Ubuntu Linux | LAMP Environment |
| **Web Server** | Apache HTTP Server 2.4 | Active on port 80; also supports PHP built-in server |
| **Backend Language** | PHP 8.3 | Native PHP with PDO, strict types, no external frameworks |
| **Database** | MySQL 8.0 | `utf8mb4` encoding, unique indexes, least-privilege user |
| **Frontend Structure** | HTML5 | Semantic markup, accessible labels, ARIA landmarks |
| **Frontend Styling** | CSS3 (Vanilla) | Custom dark futuristic design system, responsive flex/grid |
| **Frontend Logic** | Vanilla JavaScript (ES6+) | Instant client feedback, password toggle, zero libraries |
| **Version Control** | Git & GitHub | Milestone-based commit history, no secrets tracked |

**Explicitly Excluded Technologies:** No Node.js/npm, React, Vue, Angular, Laravel, Symfony, Bootstrap, Tailwind, Firebase, Supabase, or external auth APIs.

---

## 2. Directory & File Structure

```text
Web-Project/
├── config/
│   ├── config.example.php     # Safe configuration template (tracked in Git)
│   ├── config.php             # Local credentials & settings (EXCLUDED via .gitignore)
│   └── database.php           # PDO database connection factory
├── database/
│   └── schema.sql             # MySQL schema (users table, utf8mb4, unique constraints)
├── includes/
│   ├── auth.php               # Authentication guards (require_login, is_authenticated)
│   ├── csrf.php               # CSRF token generation & timing-safe hash_equals verification
│   ├── session.php            # Hardened session initialization & cookie security
│   └── validator.php          # Server-side validation rules & XSS escaping
├── public/                    # Web-accessible document root
│   ├── index.php              # Root entry router (redirects to dashboard or login)
│   ├── register.php           # Registration interface & POST processing handler
│   ├── login.php              # Login interface & credential verification handler
│   ├── dashboard.php          # Protected operative dashboard (requires active session)
│   ├── logout.php             # Secure logout & cookie invalidation handler
│   ├── css/
│   │   ├── style.css          # Design system tokens, typography, dark futuristic theme
│   │   └── auth.css           # Forms, card layouts, floating inputs, responsive rules
│   └── js/
│       ├── validation.js      # Client-side validation for instant UX feedback
│       └── toggle-pwd.js      # Accessible password visibility toggle
├── tests/
│   └── test_auth.php          # Automated PHP CLI test suite (all 15 scenarios)
├── .gitignore                 # Prevents leaking config.php, logs, and temp files
├── IMPLEMENTATION_PLAN.md     # Approved architectural blueprint
└── README.md                  # Complete project documentation
```

---

## 3. Database Setup & Configuration

### Step 1: Provision the MySQL Database and User
Execute the following commands in your Ubuntu terminal:

```bash
sudo mysql -e "
CREATE DATABASE IF NOT EXISTS project_exodia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'exodia_user'@'localhost' IDENTIFIED BY 'YOUR_DB_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON project_exodia.* TO 'exodia_user'@'localhost';
FLUSH PRIVILEGES;
"
```

### Step 2: Import the Database Schema
```bash
mysql -u exodia_user -p project_exodia < database/schema.sql
```

### Step 3: Configure Local Application Settings
Copy the template to create your untracked local configuration:
```bash
cp config/config.example.php config/config.php
```
Ensure `config/config.php` has the correct database credentials:
```php
'db' => [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'project_exodia',
    'username' => 'exodia_user',
    'password' => 'YOUR_DB_PASSWORD_HERE',
    'charset'  => 'utf8mb4',
],
```

---

## 4. Running the Application Locally

You can run Project Exodia using either the built-in development server or Apache:

### Option A: Built-in PHP Development Server (Recommended for Fast Grading)
Navigate to the project root and launch the server targeting the `public/` folder:
```bash
cd /home/mian-ahmed/Documents/Web-Project
php -S localhost:8000 -t public
```
Open your browser to: **`http://localhost:8000`**

### Option B: Apache HTTP Server
1. Grant directory traversal permission to the Apache web server user:
   ```bash
   chmod o+x /home/mian-ahmed
   ```
2. Create a symlink in Apache's DocumentRoot:
   ```bash
   sudo ln -s /home/mian-ahmed/Documents/Web-Project/public /var/www/html/exodia
   ```
3. Open your browser to: **`http://localhost/exodia`**

---

## 5. Security Architecture & Controls

1. **Password Hashing:**
   - Employs PHP native `password_hash($password, PASSWORD_DEFAULT)`.
   - Utilizes Bcrypt with automatic, cryptographically secure salts.
   - Verification via timing-safe `password_verify($password, $hash)`.
2. **SQL Injection Defense:**
   - 100% of SQL queries use PDO prepared statements with parameter binding.
   - Native prepares enforced (`ATTR_EMULATE_PREPARES => false`).
3. **Session Fixation Defense:**
   - `session_regenerate_id(true)` is invoked immediately upon successful login.
4. **Session Cookie Hardening:**
   - `HttpOnly`: Set to `true` (prevents malicious scripts from accessing session cookies via JavaScript).
   - `SameSite`: Set to `'Lax'` (mitigates CSRF via third-party cross-site requests).
   - `use_strict_mode`: Set to `1` (rejects uninitialized, foreign session IDs).
5. **Cross-Site Request Forgery (CSRF) Mitigation:**
   - All state-changing forms (`POST`) require a 32-byte cryptographically random token (`bin2hex(random_bytes(32))`).
   - Verified on submission using timing-attack resistant `hash_equals()`.
6. **Cross-Site Scripting (XSS) Mitigation:**
   - Contextual output escaping with `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
7. **Generic Authentication Errors:**
   - Login failures return `"Invalid email address or password"` uniformly, preventing user enumeration.

---

## 6. Automated Verification & Testing

An automated CLI test suite is provided in `tests/test_auth.php`. It requires no external dependencies.

To execute the test suite:
```bash
php tests/test_auth.php
```

### Verification Matrix (15/15 Scenarios Verified)

| # | Test Scenario | Verified Behavior | Status |
| :---: | :--- | :--- | :---: |
| **1** | Register new user | Record inserted; returns ID; redirects to login | **PASS** |
| **2** | Register duplicate email | MySQL unique constraint & validator block duplicate | **PASS** |
| **3** | Register duplicate username | MySQL unique constraint & validator block duplicate | **PASS** |
| **4** | Submit invalid email | Format rejected by `FILTER_VALIDATE_EMAIL` | **PASS** |
| **5** | Submit missing fields | Validator enforces required presence on all fields | **PASS** |
| **6** | Submit mismatched passwords | Confirms password confirmation check rejects submission | **PASS** |
| **7** | Log in with valid credentials | Authenticated via `password_verify`; session created | **PASS** |
| **8** | Log in with incorrect password | Rejected with generic error message | **PASS** |
| **9** | Log in with unregistered email | Rejected with identical generic error message | **PASS** |
| **10** | Direct dashboard access (no session) | Blocked by `require_login()`; redirected to login | **PASS** |
| **11** | Log out and attempt access | Session destroyed; cookies expired; dashboard blocked | **PASS** |
| **12** | Database password inspection | Stored as `$2y$` Bcrypt hash (60 chars); zero plaintext | **PASS** |
| **13** | SQL injection attempts | `' OR '1'='1' --` treated as literal data; bypass blocked | **PASS** |
| **14** | CSRF protection | Valid tokens accepted; forged/missing tokens rejected | **PASS** |
| **15** | Responsive interface | Viewport meta, media queries, flexbox and grid verified | **PASS** |

---

## 7. Oral Defense Guide for University Evaluation

When defending this implementation to your professor:

1. **Why Bcrypt instead of SHA-256?**
   - *Answer:* SHA-256 is designed for high-speed file integrity checks, allowing GPUs to calculate billions of guesses per second. Bcrypt is deliberately slow and includes an adjustable work factor and salt, defeating rainbow tables and brute-force attacks.
2. **Why PDO prepared statements instead of manual escaping?**
   - *Answer:* Escaping functions (like `addslashes` or `mysqli_real_escape_string`) rely on character set assumptions and can be bypassed by multibyte injection flaws. PDO prepared statements send SQL commands and parameters in separate protocol packets, making SQL injection mathematically impossible.
3. **Why separate client and server validation?**
   - *Answer:* Client-side JavaScript can be disabled or bypassed with tools like Postman/cURL. Client validation exists exclusively for responsiveness and user experience. Server-side PHP validation is the authoritative security boundary.
4. **Why is `session_regenerate_id(true)` essential?**
   - *Answer:* Without regenerating the session ID, an attacker can conduct a Session Fixation attack by providing a victim with a known session ID prior to authentication. Regenerating the ID destroys the old identifier and assigns a completely fresh one upon privilege escalation.
