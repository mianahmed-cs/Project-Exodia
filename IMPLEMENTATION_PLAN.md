# Project Exodia — Phase 1: Authentication System
## Master Implementation Plan & Execution Blueprint

**Author:** Antigravity (Senior Full-Stack Developer & Security Architect)  
**Target Environment:** Ubuntu Linux (LAMP: Apache 2.4, PHP 8.3, MySQL 8.0, Vanilla JS, CSS3, HTML5)  
**Project Location:** `/home/mian-ahmed/Documents/Web-Project`  
**Status:** Pending Student / University Approval

---

## 1. Executive Summary & Architecture Philosophy

**Project Exodia** is a secure, real-time messaging platform. **Phase 1** establishes the mission-critical foundation: a secure, robust, and audited user registration and authentication system.

### Mandatory Academic Constraints
- **Backend:** Linux, Apache HTTP Server, native PHP 8.3, MySQL 8.0.
- **Frontend:** HTML5, CSS3, Vanilla JavaScript (ES6+).
- **Zero Frameworks:** Strictly NO Node.js/npm, React, Vue, Angular, Laravel, Symfony, Bootstrap, Tailwind, Firebase, or external authentication APIs.
- **Version Control:** Git with structured milestone commits and GitHub synchronization.

### Architectural Principles
1. **Separation of Concerns:** Sensitive backend logic, database configurations, and security utilities reside outside the publicly accessible web root or within protected directories.
2. **Zero-Trust Input Processing:** Client-side validation exists strictly for user experience (UX). Server-side validation acts as the definitive security boundary.
3. **Defense-in-Depth:** Every layer incorporates security controls (prepared SQL statements against SQLi, contextual escaping against XSS, cryptographically secure CSRF tokens, hardened session cookies, timing-safe credential verification, and Bcrypt/Argon2 password hashing).

---

## 2. Target Directory & File Structure

Here is the exact structure that will be constructed in `/home/mian-ahmed/Documents/Web-Project`:

```text
Web-Project/
├── config/
│   ├── config.example.php     # Template configuration committed to Git (zero secrets)
│   ├── config.php             # Local credentials & system settings (.gitignored)
│   └── database.php           # PDO connection factory with error-handling abstraction
├── database/
│   └── schema.sql             # MySQL DDL table definition & indexing script
├── includes/
│   ├── auth.php               # Auth guard helpers (require_login, is_logged_in, current_user)
│   ├── csrf.php               # CSRF token generation and timing-safe validation
│   ├── session.php            # Hardened session initialization & cookie configuration
│   └── validator.php          # Server-side validation engine (regex, length, sanitization)
├── public/                    # Web-accessible document root
│   ├── index.php              # Public entry router (redirects to dashboard or login)
│   ├── register.php           # Registration interface & submission handler
│   ├── login.php              # Login interface & credential verification handler
│   ├── dashboard.php          # Protected welcome/dashboard area (requires valid session)
│   ├── logout.php             # Session invalidation & logout handler
│   ├── css/
│   │   ├── style.css          # Design system tokens, typography, dark futuristic theme
│   │   └── auth.css           # Auth forms, card layouts, floating inputs, responsive rules
│   └── js/
│       ├── validation.js      # Client-side instant input feedback & mismatch checks
│       └── toggle-pwd.js      # Accessible password visibility toggle
├── tests/
│   └── test_auth.php          # Automated PHP CLI test suite (15/15 scenarios)
├── .gitignore                 # Prevents leaking config.php, OS cache, or session data
├── IMPLEMENTATION_PLAN.md     # This comprehensive execution blueprint
└── README.md                  # Complete documentation, setup instructions, & defense guide
```

---

## 3. Detailed Component Breakdown

### A. Configuration Layer (`config/`)
- **`config/config.example.php`:**
  - Tracked in Git.
  - Defines necessary configuration constants (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_URL`, `SESSION_SECURE`).
  - Contains dummy credentials so students and professors can clone and configure easily.
- **`config/config.php`:**
  - Excluded from Git (`.gitignore`).
  - Stores local database credentials (`exodia_user` and password).
- **`config/database.php`:**
  - Instantiates and returns a singleton `PDO` instance.
  - Sets `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` (uniform error handling).
  - Sets `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`.
  - Sets `PDO::ATTR_EMULATE_PREPARES => false` (guarantees native database statement preparation).

### B. Database Layer (`database/`)
- **`database/schema.sql`:**
  - Creates the `project_exodia` database using `utf8mb4` and `utf8mb4_unicode_ci`.
  - Creates table `users` with columns:
    - `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
    - `username VARCHAR(30) NOT NULL UNIQUE`
    - `email VARCHAR(255) NOT NULL UNIQUE`
    - `password_hash VARCHAR(255) NOT NULL`
    - `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
    - `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
  - Explicit indexes on `email` and `username` for instantaneous lookups.

### C. Security & Utility Layer (`includes/`)
- **`includes/session.php`:**
  - Centralizes `session_start()` with secure cookie parameters:
    - `lifetime`: `0` (browser session).
    - `path`: `/`.
    - `domain`: `""`.
    - `secure`: `false` for local HTTP development (toggleable in `config.php`).
    - `httponly`: `true` (blocks JavaScript access via `document.cookie`).
    - `samesite`: `'Lax'` (mitigates CSRF via third-party cross-site requests).
  - Enforces `session.use_strict_mode = 1`.
- **`includes/csrf.php`:**
  - `generate_csrf_token()`: Generates 32 random bytes via `random_bytes(32)`, hex-encoded, stored in `$_SESSION['csrf_token']`.
  - `verify_csrf_token(?string $token)`: Validates using `hash_equals()` to prevent timing attacks.
  - `csrf_input()`: Outputs `<input type="hidden" name="csrf_token" value="...">`.
- **`includes/validator.php`:**
  - `validate_username(string $username)`: Length 3-30 chars, alphanumeric + underscores (`/^[a-zA-Z0-9_]{3,30}$/`).
  - `validate_email(string $email)`: Validates via `filter_var($email, FILTER_VALIDATE_EMAIL)` and length $\le 255$.
  - `validate_password(string $password)`: Minimum 8 characters, enforces at least one letter and one number.
  - `sanitize_output(?string $data)`: Wraps `htmlspecialchars($data ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- **`includes/auth.php`:**
  - `is_authenticated()`: Returns boolean checking `$_SESSION['user_id']`.
  - `require_login()`: Guards protected pages. Redirects unauthenticated users to `/login.php?error=unauthorized` and exits.
  - `current_user()`: Returns authenticated user array (`id`, `username`, `email`).

### D. Presentation & Endpoint Layer (`public/`)
- **`public/register.php`:**
  - Renders registration form branded as **Project Exodia**.
  - Handles `POST` registration:
    1. Verifies CSRF token.
    2. Runs server-side validation on username, email, password, confirm password.
    3. Checks for existing email or username in MySQL using prepared `SELECT` queries.
    4. Hashes password using `password_hash($password, PASSWORD_DEFAULT)`.
    5. Inserts record via prepared `INSERT` query.
    6. Sets flash success message and redirects to `login.php`.
- **`public/login.php`:**
  - Renders login form with email, password, submit button, and password visibility toggle.
  - Handles `POST` login:
    1. Verifies CSRF token.
    2. Validates input presence.
    3. Queries `users` table by email using prepared statement.
    4. Verifies password with `password_verify($password, $user['password_hash'])`.
    5. If invalid: Returns generic error `"Invalid email or password"` (prevents user enumeration).
    6. If valid: Executes `session_regenerate_id(true)` to defeat session fixation, populates `$_SESSION['user_id']` and `$_SESSION['username']`, and redirects to `dashboard.php`.
- **`public/dashboard.php`:**
  - Calls `require_login()`.
  - Displays dark futuristic dashboard header, welcome banner with username, session info, and a secure logout button.
- **`public/logout.php`:**
  - Destroys server-side session data (`session_unset()`, `session_destroy()`).
  - Expires client session cookie.
  - Redirects to `login.php?notice=logged_out`.
- **`public/css/style.css` & `public/css/auth.css`:**
  - Custom design system: CSS custom properties (variables) for dark charcoal background (`#0B0E14`), deep slate panels (`#151922`), vibrant purple accents (`#7928CA`), and electric blue highlights (`#0070F3`).
  - Accessible contrast ratios, focus outlines (`:focus-visible`), fluid responsive typography, and mobile-friendly layouts.
- **`public/js/validation.js` & `public/js/toggle-pwd.js`:**
  - Real-time client-side feedback (password match indicator, pattern hints).
  - Accessible password visibility toggle modifying `aria-label` and input `type`.

### E. Automated Test Suite (`tests/test_auth.php`)
- Standalone CLI PHP script requiring zero external testing packages.
- Automatically tests database connection, user registration, uniqueness enforcement, password hashing format, authentication verification, invalid credential rejection, CSRF rejection, session protection, and SQL injection resistance.

---

## 4. Step-by-Step Implementation Sequence in the Folder

The implementation will proceed systematically in 8 distinct milestones:

```text
[Step 1: Environment & Git] ──> [Step 2: Database & Config] ──> [Step 3: Security & Helpers]
                                                                             │
[Step 6: UI & Vanilla JS]   <── [Step 5: Login & Dashboard] <── [Step 4: Registration]
        │
        ▼
[Step 7: Automated Tests]   ──> [Step 8: Docs & GitHub Sync]
```

### Milestone 1: Environment & Repository Baseline
1. Initialize Git repository (`git init`).
2. Create `.gitignore` to protect `config/config.php`, editor artifacts, and temp files.
3. Commit initial baseline to branch `main`.
4. Create feature branch `feature/phase-1-auth`.

### Milestone 2: Database Schema & Configuration Engine
1. Create `database/schema.sql` with table definitions and unique constraints.
2. Provide SQL commands for provisioning `project_exodia` and `exodia_user`.
3. Create `config/config.example.php` (git-safe) and local `config/config.php`.
4. Create `config/database.php` with robust PDO connection handling.

### Milestone 3: Core Security Utilities & Session Engine
1. Implement `includes/session.php` with hardened cookie policies.
2. Implement `includes/csrf.php` with token generation and timing-safe checks.
3. Implement `includes/validator.php` with field validators and XSS escaping.
4. Implement `includes/auth.php` with access control guards.

### Milestone 4: Registration Workflow (Backend & View)
1. Build `public/register.php` combining view and secure POST processor.
2. Test registration in terminal/browser: valid insertion, duplicate email check, duplicate username check.

### Milestone 5: Authentication, Dashboard & Session Lifecycle
1. Build `public/login.php` with secure credential verification and generic error handling.
2. Implement `public/dashboard.php` with strict authorization checks.
3. Implement `public/logout.php` with complete session and cookie teardown.
4. Implement `public/index.php` entry router.

### Milestone 6: Dark Futuristic UI & Client Interactivity
1. Build `public/css/style.css` (design system tokens, dark theme, resets).
2. Build `public/css/auth.css` (forms, cards, glowing focus rings, responsive media queries).
3. Build `public/js/validation.js` (client-side form validation UX).
4. Build `public/js/toggle-pwd.js` (accessible password visibility switch).

### Milestone 7: Comprehensive Testing Suite (All 15 Scenarios)
1. Write `tests/test_auth.php`.
2. Execute automated test script and record exact results for all 15 scenarios.
3. Verify mobile and desktop responsiveness.

### Milestone 8: Documentation, Git Finalization & GitHub Push
1. Write detailed `README.md` covering setup, testing, architecture, and defense notes.
2. Verify all milestone Git commits.
3. Push `main` and `feature/phase-1-auth` to GitHub repository.

---

## 5. Verification Matrix (15 Mandatory Test Scenarios)

| # | Test Scenario | Execution Method in Folder | Expected Result |
| :---: | :--- | :--- | :--- |
| **1** | Register new user | `tests/test_auth.php` & Web Form | Record created in MySQL; redirect to login with flash notice. |
| **2** | Duplicate email | `tests/test_auth.php` & Web Form | Rejected with clear error; no duplicate record inserted. |
| **3** | Duplicate username | `tests/test_auth.php` & Web Form | Rejected with clear error; no duplicate record inserted. |
| **4** | Invalid email format | `tests/test_auth.php` & Web Form | Rejected by client JS and server PHP validator. |
| **5** | Missing required fields | `tests/test_auth.php` & Web Form | Rejected with specific field error indications. |
| **6** | Mismatched passwords | `tests/test_auth.php` & Web Form | Client mismatch warning; server rejects submission. |
| **7** | Valid login | `tests/test_auth.php` & Web Form | Credentials verified; new session ID; redirect to dashboard. |
| **8** | Incorrect password | `tests/test_auth.php` & Web Form | Generic "Invalid email or password" error. |
| **9** | Unregistered email | `tests/test_auth.php` & Web Form | Identical generic "Invalid email or password" error. |
| **10** | Direct dashboard access | Curl / Web browser without session | Redirected to `login.php` with unauthorized notice. |
| **11** | Access after logout | Log out, then request `dashboard.php` | Session destroyed; redirected to login; dashboard blocked. |
| **12** | Database hash inspection | SQL query: `SELECT password_hash FROM users` | Hashes start with `$2y$` (Bcrypt, 60 chars); zero plaintext. |
| **13** | SQL injection attempts | Input: `' OR '1'='1' --` in login | Treated as literal text; query returns empty; login fails safely. |
| **14** | CSRF protection | POST to handlers with invalid/missing token | Rejected with HTTP 403 Forbidden. |
| **15** | UI & Responsiveness | Browser testing (Desktop 1920px & Mobile 375px) | Fluid layout, accessible focus states, working toggle. |

---

## 6. Explanations for Your Professor (Oral Defense Cheat-Sheet)

When presenting this project, your professor will likely test your theoretical understanding. Here are the exact explanations:

1. **Why `password_hash()` instead of `md5()` or `sha256()`?**
   - *Explanation:* MD5 and SHA-256 are general-purpose cryptographic hash functions designed for maximum speed. Attackers can compute billions of MD5/SHA-256 hashes per second using modern GPUs and rainbow tables. In contrast, `password_hash()` uses Bcrypt, a key-derivation function that includes an automatic cryptographically random salt and a configurable work factor (computational cost), making brute-force and dictionary attacks computationally infeasible.
2. **Why PDO with prepared statements instead of string concatenation?**
   - *Explanation:* Prepared statements separate the SQL query logic from the user data. The database compiles the SQL statement structure first. When the user parameters are bound, they are treated strictly as literal data values, never as executable SQL commands, eliminating SQL Injection entirely.
3. **Why regenerate session IDs upon login (`session_regenerate_id(true)`)?**
   - *Explanation:* This prevents **Session Fixation attacks**. Without regeneration, an attacker could assign a known session identifier to a victim (via a link or cookie injection). Once the victim authenticates, the attacker could use that same session ID to hijack their account.
4. **Why generic error messages on login?**
   - *Explanation:* If the application returns "Email not found" vs "Wrong password", an attacker can automate queries to enumerate which email addresses are registered on the platform. Returning "Invalid email or password" reveals zero information about user existence.
5. **Why `HttpOnly` and `SameSite` cookie flags?**
   - *Explanation:* `HttpOnly` prevents client-side JavaScript (e.g., in a rogue script or XSS payload) from reading `document.cookie`. `SameSite=Lax` prevents the browser from attaching the session cookie to cross-site requests initiated by third-party websites, neutralizing Cross-Site Request Forgery.

---

## 7. Execution Readiness & Next Step

The implementation plan is now saved in the project directory as [IMPLEMENTATION_PLAN.md](file:///home/mian-ahmed/Documents/Web-Project/IMPLEMENTATION_PLAN.md).

Before we execute Step 1, please confirm:
1. **Git Identity:** What author name and email should be recorded for Git commits?
2. **GitHub URL:** Do you have a GitHub repository link to connect as remote?
3. **Database Account:** Are you ready to run the one-time `sudo mysql` provisioning commands, or would you like to provide existing credentials?
4. **Approval:** Give the signal to commence Milestone 1!
