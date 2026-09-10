/**
 * Admin Panel Interactive Javascript
 */

document.addEventListener('DOMContentLoaded', function() {
    // 1. Theme Switcher (Dark / Light)
    const savedTheme = localStorage.getItem('sode_admin_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);

    const themeToggleBtn = document.getElementById('theme-toggle-btn');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function() {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('sode_admin_theme', newTheme);
            updateThemeIcon(newTheme);
        });
    }

    function updateThemeIcon(theme) {
        const iconEl = document.getElementById('theme-icon');
        if (!iconEl) return;
        if (theme === 'light') {
            // Sun icon for light mode
            iconEl.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>';
        } else {
            // Moon icon for dark mode
            iconEl.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>';
        }
    }

    // 2. Mobile Sidebar Toggle
    const sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
    const sidebar = document.getElementById('app-sidebar');
    if (sidebarToggleBtn && sidebar) {
        sidebarToggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
        
        // Close when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 900 && sidebar.classList.contains('show')) {
                if (!sidebar.contains(e.target) && !sidebarToggleBtn.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            }
        });
    }

    // 3. Password Toggle Visibility (Eye / Eye Off)
    const eyeOpenSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    const eyeOffSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

    const pwdToggles = document.querySelectorAll('.password-toggle');
    pwdToggles.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const input = document.getElementById(this.dataset.target);
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                this.classList.add('is-visible');
                this.innerHTML = eyeOffSvg;
            } else {
                input.type = 'password';
                this.classList.remove('is-visible');
                this.innerHTML = eyeOpenSvg;
            }
        });
    });

    // 4. Global Confirm on Delete
    const deleteForms = document.querySelectorAll('.delete-form, .confirm-delete');
    deleteForms.forEach(function(el) {
        el.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to delete this record? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });
});
