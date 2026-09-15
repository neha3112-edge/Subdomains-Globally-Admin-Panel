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

    // 5. Searchable Select Dropdown Component
    initSearchableSelects();
});

function initSearchableSelects(context = document) {
    const selects = context.querySelectorAll('select.searchable-select');
    selects.forEach(function(select) {
        if (select.dataset.searchableInitialized === 'true') return;
        select.dataset.searchableInitialized = 'true';

        // Check if already inside wrapper
        let wrapper = select.closest('.sode-searchable-select');
        if (!wrapper) {
            wrapper = document.createElement('div');
            wrapper.className = 'sode-searchable-select';
            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);
        }

        select.classList.add('sode-select-hidden');

        // Trigger button
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'sode-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');

        const textSpan = document.createElement('span');
        textSpan.className = 'sode-select-text';
        
        const arrowSpan = document.createElement('span');
        arrowSpan.className = 'sode-select-arrow';
        arrowSpan.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>';

        trigger.appendChild(textSpan);
        trigger.appendChild(arrowSpan);
        wrapper.appendChild(trigger);

        // Dropdown panel
        const dropdown = document.createElement('div');
        dropdown.className = 'sode-select-dropdown';

        const searchBox = document.createElement('div');
        searchBox.className = 'sode-select-search-box';
        searchBox.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" class="sode-select-search-input" placeholder="Type to search..." autocomplete="off">
        `;
        const searchInput = searchBox.querySelector('.sode-select-search-input');

        const optionsList = document.createElement('div');
        optionsList.className = 'sode-select-options';

        const emptyMsg = document.createElement('div');
        emptyMsg.className = 'sode-select-empty';
        emptyMsg.textContent = 'No matching options found';
        emptyMsg.style.display = 'none';

        dropdown.appendChild(searchBox);
        dropdown.appendChild(optionsList);
        dropdown.appendChild(emptyMsg);
        wrapper.appendChild(dropdown);

        function updateDisplay() {
            const selectedOpt = select.options[select.selectedIndex];
            if (selectedOpt && selectedOpt.value !== '') {
                textSpan.textContent = selectedOpt.textContent;
                textSpan.classList.remove('is-placeholder');
            } else {
                textSpan.textContent = selectedOpt ? selectedOpt.textContent : '-- Select --';
                textSpan.classList.add('is-placeholder');
            }
        }

        function populateOptions() {
            optionsList.innerHTML = '';
            Array.from(select.options).forEach((opt, idx) => {
                const item = document.createElement('div');
                item.className = 'sode-select-option';
                item.dataset.value = opt.value;
                item.dataset.index = idx;
                item.textContent = opt.textContent;

                if (opt.selected) {
                    item.classList.add('is-selected');
                }

                item.addEventListener('click', function(e) {
                    e.stopPropagation();
                    select.selectedIndex = idx;
                    select.value = opt.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    updateDisplay();
                    closeDropdown();
                    trigger.focus();
                });

                optionsList.appendChild(item);
            });
        }

        function openDropdown() {
            // Close other open searchable selects
            document.querySelectorAll('.sode-searchable-select.is-open').forEach(w => {
                if (w !== wrapper) {
                    w.classList.remove('is-open');
                    w.classList.remove('is-dropup');
                    const tr = w.querySelector('.sode-select-trigger');
                    if (tr) tr.setAttribute('aria-expanded', 'false');
                }
            });

            // Smart Auto-Dropup detection (ONLY open upwards if viewport below is cramped AND above has plenty of space)
            const rect = trigger.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            const spaceAbove = rect.top;
            const neededHeight = 260;

            const shouldDropup = (spaceBelow < neededHeight) && (spaceAbove > spaceBelow) && (spaceAbove >= 240);

            if (shouldDropup) {
                wrapper.classList.add('is-dropup');
                optionsList.style.maxHeight = Math.max(140, Math.min(240, spaceAbove - 60)) + 'px';
            } else {
                wrapper.classList.remove('is-dropup');
                optionsList.style.maxHeight = Math.max(140, Math.min(240, spaceBelow - 60)) + 'px';
            }

            wrapper.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            trigger.classList.remove('is-invalid');
            searchInput.value = '';
            filterOptions('');
            searchInput.focus();

            // Scroll selected option into view
            const selectedItem = optionsList.querySelector('.sode-select-option.is-selected');
            if (selectedItem) {
                selectedItem.scrollIntoView({ block: 'nearest' });
            }
        }

        function closeDropdown() {
            wrapper.classList.remove('is-open');
            wrapper.classList.remove('is-dropup');
            trigger.setAttribute('aria-expanded', 'false');
        }

        function filterOptions(query) {
            const q = query.toLowerCase().trim();
            let visible = 0;
            optionsList.querySelectorAll('.sode-select-option').forEach(item => {
                const match = !q || item.textContent.toLowerCase().includes(q);
                item.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            emptyMsg.style.display = visible === 0 ? 'block' : 'none';
        }

        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (wrapper.classList.contains('is-open')) {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        searchInput.addEventListener('input', function() {
            filterOptions(this.value);
        });

        searchInput.addEventListener('keydown', function(e) {
            const visibleItems = Array.from(optionsList.querySelectorAll('.sode-select-option')).filter(i => i.style.display !== 'none');
            const highlighted = optionsList.querySelector('.sode-select-option.is-highlighted');
            let currIdx = visibleItems.indexOf(highlighted);

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (currIdx < visibleItems.length - 1) {
                    if (highlighted) highlighted.classList.remove('is-highlighted');
                    visibleItems[currIdx + 1].classList.add('is-highlighted');
                    visibleItems[currIdx + 1].scrollIntoView({ block: 'nearest' });
                } else if (visibleItems.length > 0 && currIdx === -1) {
                    visibleItems[0].classList.add('is-highlighted');
                    visibleItems[0].scrollIntoView({ block: 'nearest' });
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (currIdx > 0) {
                    if (highlighted) highlighted.classList.remove('is-highlighted');
                    visibleItems[currIdx - 1].classList.add('is-highlighted');
                    visibleItems[currIdx - 1].scrollIntoView({ block: 'nearest' });
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (highlighted) {
                    highlighted.click();
                } else if (visibleItems.length > 0) {
                    visibleItems[0].click();
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeDropdown();
                trigger.focus();
            }
        });

        select.addEventListener('change', function() {
            updateDisplay();
            optionsList.querySelectorAll('.sode-select-option').forEach(item => {
                item.classList.toggle('is-selected', item.dataset.value === select.value);
            });
        });

        // Form validation hook
        if (select.form) {
            select.form.addEventListener('submit', function(e) {
                if (select.hasAttribute('required') && !select.value) {
                    trigger.classList.add('is-invalid');
                }
            });
        }

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target)) {
                closeDropdown();
            }
        });

        populateOptions();
        updateDisplay();
    });
}
window.initSearchableSelects = initSearchableSelects;

