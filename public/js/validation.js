/**
 * Project Exodia — Client-Side Instant Form Validation
 * 
 * Note for University Presentation:
 * Client-side validation improves user experience by providing instant feedback.
 * However, client validation is NEVER trusted as a security boundary.
 * The PHP backend independently validates all fields regardless of client state.
 */

document.addEventListener('DOMContentLoaded', () => {
    const registerForm = document.getElementById('registerForm');
    const loginForm = document.getElementById('loginForm');

    /**
     * Display or clear an error message for an input field.
     */
    function setFieldError(input, errorMsg) {
        const group = input.closest('.form-group');
        if (!group) return;

        let errorSpan = group.querySelector('.client-form-error');

        if (errorMsg) {
            group.classList.add('has-error');
            input.setAttribute('aria-invalid', 'true');

            if (!errorSpan) {
                errorSpan = document.createElement('span');
                errorSpan.className = 'form-error client-form-error';
                errorSpan.setAttribute('role', 'alert');
                group.appendChild(errorSpan);
            }
            errorSpan.textContent = errorMsg;
        } else {
            group.classList.remove('has-error');
            input.removeAttribute('aria-invalid');
            if (errorSpan) {
                errorSpan.remove();
            }
        }
    }

    // ==========================================
    // Registration Form Validation
    // ==========================================
    if (registerForm) {
        const usernameInput = document.getElementById('username');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');

        function validateUsernameField() {
            const val = usernameInput.value.trim();
            if (!val) {
                setFieldError(usernameInput, 'Username is required.');
                return false;
            }
            if (val.length < 3 || val.length > 30) {
                setFieldError(usernameInput, 'Username must be between 3 and 30 characters.');
                return false;
            }
            if (!/^[a-zA-Z0-9_]+$/.test(val)) {
                setFieldError(usernameInput, 'Username may only contain letters, numbers, and underscores.');
                return false;
            }
            setFieldError(usernameInput, null);
            return true;
        }

        function validateEmailField() {
            const val = emailInput.value.trim();
            if (!val) {
                setFieldError(emailInput, 'Email address is required.');
                return false;
            }
            // Standard RFC compliant email regex pattern
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(val)) {
                setFieldError(emailInput, 'Please enter a valid email address.');
                return false;
            }
            setFieldError(emailInput, null);
            return true;
        }

        function validatePasswordField() {
            const val = passwordInput.value;
            if (!val) {
                setFieldError(passwordInput, 'Password is required.');
                return false;
            }
            if (val.length < 8) {
                setFieldError(passwordInput, 'Password must be at least 8 characters long.');
                return false;
            }
            if (!/[a-zA-Z]/.test(val) || !/[0-9]/.test(val)) {
                setFieldError(passwordInput, 'Password must include at least one letter and one number.');
                return false;
            }
            setFieldError(passwordInput, null);
            return true;
        }

        function validateConfirmPasswordField() {
            const pwd = passwordInput.value;
            const confirmVal = confirmPasswordInput.value;

            if (!confirmVal) {
                setFieldError(confirmPasswordInput, 'Please confirm your password.');
                return false;
            }
            if (pwd !== confirmVal) {
                setFieldError(confirmPasswordInput, 'Passwords do not match.');
                return false;
            }
            setFieldError(confirmPasswordInput, null);
            return true;
        }

        // Real-time event listeners
        usernameInput.addEventListener('blur', validateUsernameField);
        usernameInput.addEventListener('input', () => {
            if (usernameInput.closest('.form-group').classList.contains('has-error')) {
                validateUsernameField();
            }
        });

        emailInput.addEventListener('blur', validateEmailField);
        emailInput.addEventListener('input', () => {
            if (emailInput.closest('.form-group').classList.contains('has-error')) {
                validateEmailField();
            }
        });

        passwordInput.addEventListener('blur', validatePasswordField);
        passwordInput.addEventListener('input', () => {
            if (passwordInput.closest('.form-group').classList.contains('has-error')) {
                validatePasswordField();
            }
            if (confirmPasswordInput.value) {
                validateConfirmPasswordField();
            }
        });

        confirmPasswordInput.addEventListener('blur', validateConfirmPasswordField);
        confirmPasswordInput.addEventListener('input', () => {
            if (confirmPasswordInput.closest('.form-group').classList.contains('has-error')) {
                validateConfirmPasswordField();
            }
        });

        registerForm.addEventListener('submit', (e) => {
            const isUserValid = validateUsernameField();
            const isEmailValid = validateEmailField();
            const isPassValid = validatePasswordField();
            const isConfirmValid = validateConfirmPasswordField();

            if (!isUserValid || !isEmailValid || !isPassValid || !isConfirmValid) {
                e.preventDefault();
                // Focus first invalid element
                const firstInvalid = registerForm.querySelector('[aria-invalid="true"]');
                if (firstInvalid) {
                    firstInvalid.focus();
                }
            }
        });
    }

    // ==========================================
    // Login Form Validation
    // ==========================================
    if (loginForm) {
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');

        function validateLoginEmail() {
            const val = emailInput.value.trim();
            if (!val) {
                setFieldError(emailInput, 'Email address is required.');
                return false;
            }
            setFieldError(emailInput, null);
            return true;
        }

        function validateLoginPassword() {
            const val = passwordInput.value;
            if (!val) {
                setFieldError(passwordInput, 'Password is required.');
                return false;
            }
            setFieldError(passwordInput, null);
            return true;
        }

        emailInput.addEventListener('blur', validateLoginEmail);
        passwordInput.addEventListener('blur', validateLoginPassword);

        loginForm.addEventListener('submit', (e) => {
            const isEmailValid = validateLoginEmail();
            const isPassValid = validateLoginPassword();

            if (!isEmailValid || !isPassValid) {
                e.preventDefault();
                const firstInvalid = loginForm.querySelector('[aria-invalid="true"]');
                if (firstInvalid) {
                    firstInvalid.focus();
                }
            }
        });
    }
});
