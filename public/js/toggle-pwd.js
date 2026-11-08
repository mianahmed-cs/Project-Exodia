/**
 * Project Exodia — Accessible Password Visibility Toggle
 * 
 * Manages accessible toggling between password and text input types
 * with dynamic ARIA attributes and visual feedback.
 */

document.addEventListener('DOMContentLoaded', () => {
    const toggleButtons = document.querySelectorAll('.pwd-toggle-btn');

    toggleButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('aria-controls');
            if (!targetId) return;

            const input = document.getElementById(targetId);
            if (!input) return;

            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';

            // Accessible attributes
            btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            btn.classList.toggle('showing', isPassword);

            // Toggle visual icon
            const icon = btn.querySelector('.toggle-icon');
            if (icon) {
                icon.textContent = isPassword ? '🔒' : '👁';
            }

            // Keep focus on input if user toggles via keyboard
            input.focus();
        });
    });
});
