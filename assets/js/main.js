/**
 * Mini ERP System — Main JavaScript
 * Client-side interactions, form validation, and UI enhancements.
 */

document.addEventListener('DOMContentLoaded', () => {
    initFlashMessages();
    initPasswordToggles();
    initMobileMenu();
    initModals();
    initAuditDetails();
    initFormValidation();
    initSearchFilter();
});

// ─── Flash Messages: Auto-dismiss after 5 seconds ─────────────────────────
function initFlashMessages() {
    const flashes = document.querySelectorAll('[data-flash]');
    flashes.forEach((flash, i) => {
        setTimeout(() => {
            flash.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            flash.style.opacity = '0';
            flash.style.transform = 'translateX(100%)';
            setTimeout(() => flash.remove(), 300);
        }, 5000 + (i * 500));
    });
}

// ─── Password Visibility Toggle ────────────────────────────────────────────
function initPasswordToggles() {
    document.querySelectorAll('.password-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = btn.parentElement.querySelector('input');
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    });
}

// ─── Mobile Sidebar Toggle ─────────────────────────────────────────────────
function initMobileMenu() {
    const toggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');

    if (toggle && sidebar) {
        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('active');
        });

        if (overlay) {
            overlay.addEventListener('click', () => {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            });
        }
    }
}

// ─── Modal Management ──────────────────────────────────────────────────────
function initModals() {
    // Open modal buttons
    document.querySelectorAll('[data-modal-target]').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = document.getElementById(btn.dataset.modalTarget);
            if (modal) openModal(modal);
        });
    });

    // Close modal buttons
    document.querySelectorAll('.modal-close, [data-modal-close]').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.modal-overlay');
            if (modal) closeModal(modal);
        });
    });

    // Close on overlay click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal(overlay);
        });
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(closeModal);
        }
    });
}

function openModal(modal) {
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(modal) {
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// ─── Audit Log Detail Toggle ───────────────────────────────────────────────
function initAuditDetails() {
    document.querySelectorAll('.audit-detail-toggle').forEach(toggle => {
        toggle.addEventListener('click', () => {
            const content = toggle.nextElementSibling;
            if (content) {
                content.classList.toggle('show');
                toggle.textContent = content.classList.contains('show') ? 'Hide Details' : 'View Details';
            }
        });
    });
}

// ─── Client-side Form Validation ───────────────────────────────────────────
function initFormValidation() {
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', (e) => {
            let isValid = true;

            // Clear previous errors
            form.querySelectorAll('.form-error').forEach(el => el.remove());
            form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));

            // Required fields
            form.querySelectorAll('[required]').forEach(input => {
                if (!input.value.trim()) {
                    showFieldError(input, 'This field is required.');
                    isValid = false;
                }
            });

            // Email validation
            form.querySelectorAll('[type="email"]').forEach(input => {
                if (input.value && !isValidEmail(input.value)) {
                    showFieldError(input, 'Please enter a valid email address.');
                    isValid = false;
                }
            });

            // Password match
            const password = form.querySelector('[name="password"]');
            const confirm = form.querySelector('[name="confirm_password"]');
            if (password && confirm && password.value !== confirm.value) {
                showFieldError(confirm, 'Passwords do not match.');
                isValid = false;
            }

            // Minimum password length
            if (password && password.value && password.value.length < 6) {
                showFieldError(password, 'Password must be at least 6 characters.');
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
                // Scroll to first error
                const firstError = form.querySelector('.is-invalid');
                if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    });
}

function showFieldError(input, message) {
    input.classList.add('is-invalid');
    const error = document.createElement('div');
    error.className = 'form-error';
    error.textContent = message;
    input.parentElement.appendChild(error);
}

function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

// ─── Live Search / Filter ──────────────────────────────────────────────────
function initSearchFilter() {
    const searchInput = document.querySelector('[data-search-table]');
    if (!searchInput) return;

    const tableId = searchInput.dataset.searchTable;
    const table = document.getElementById(tableId);
    if (!table) return;

    searchInput.addEventListener('input', () => {
        const query = searchInput.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    });
}

// ─── Confirm Delete ────────────────────────────────────────────────────────
function confirmAction(message) {
    return confirm(message || 'Are you sure you want to proceed?');
}
