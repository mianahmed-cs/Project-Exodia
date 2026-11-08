/**
 * Project Exodia — Contacts & User Discovery Client Controller
 * 
 * Vanilla JavaScript (ES6+) for:
 *  - Searching registered operatives asynchronously
 *  - Adding operatives to the personal contact roster
 *  - Removing contacts with confirmation
 *  - Dynamic UI updates, accessible state feedback, and toast notifications
 */

document.addEventListener('DOMContentLoaded', () => {
    const searchForm = document.getElementById('searchForm');
    const searchInput = document.getElementById('searchInput');
    const searchResultsContainer = document.getElementById('searchResults');
    const contactsListContainer = document.getElementById('contactsList');
    const contactsCountBadge = document.getElementById('contactsCount');
    const refreshContactsBtn = document.getElementById('refreshContactsBtn');
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';

    let searchDebounceTimer = null;
    let cachedContacts = new Set();

    // ----------------------------------------------------
    // Toast Notification System
    // ----------------------------------------------------
    function showToast(message, type = 'success') {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container';
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <span aria-hidden="true">${type === 'success' ? '✓' : '⚠'}</span>
            <span>${escapeHTML(message)}</span>
        `;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(8px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }

    function escapeHTML(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function getInitials(name) {
        if (!name) return '?';
        return name.slice(0, 2).toUpperCase();
    }

    // ----------------------------------------------------
    // Fetch & Render Contacts List
    // ----------------------------------------------------
    async function loadContacts() {
        if (!contactsListContainer) return;

        contactsListContainer.innerHTML = `
            <div class="state-box">
                <div class="loading-spinner" aria-hidden="true"></div>
                <span>Synchronizing operative roster...</span>
            </div>
        `;

        try {
            const response = await fetch('api/contacts.php', {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Failed to retrieve contact roster.');
            }

            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'Server returned an error.');
            }

            renderContacts(data.contacts || []);
        } catch (err) {
            contactsListContainer.innerHTML = `
                <div class="state-box">
                    <span class="state-icon" aria-hidden="true">⚠</span>
                    <span>${escapeHTML(err.message)}</span>
                    <button type="button" class="btn btn-secondary btn-sm" id="retryLoadBtn">Retry</button>
                </div>
            `;
            const retryBtn = document.getElementById('retryLoadBtn');
            if (retryBtn) retryBtn.addEventListener('click', loadContacts);
        }
    }

    function renderContacts(contacts) {
        cachedContacts.clear();
        contacts.forEach(c => cachedContacts.add(c.user_id));

        if (contactsCountBadge) {
            contactsCountBadge.textContent = `${contacts.length} ${contacts.length === 1 ? 'Operative' : 'Operatives'}`;
        }

        if (contacts.length === 0) {
            contactsListContainer.innerHTML = `
                <div class="state-box">
                    <span class="state-icon" aria-hidden="true">📡</span>
                    <span>No contacts in your roster yet.</span>
                    <span class="operative-meta">Search above to discover and connect with operatives.</span>
                </div>
            `;
            return;
        }

        contactsListContainer.innerHTML = contacts.map(c => `
            <article class="operative-card" data-user-id="${c.user_id}">
                <div class="operative-info">
                    <div class="operative-avatar" aria-hidden="true">${getInitials(c.username)}</div>
                    <div class="operative-details">
                        <span class="operative-name">${escapeHTML(c.username)}</span>
                        <span class="operative-meta">Added: ${new Date(c.added_at).toLocaleDateString()}</span>
                    </div>
                </div>
                <div class="operative-actions">
                    <a href="messages.php?user_id=${c.user_id}" class="btn-contact-add" style="text-decoration: none;">Message</a>
                    <button 
                        type="button" 
                        class="btn-contact-remove" 
                        data-action="remove" 
                        data-user-id="${c.user_id}" 
                        data-username="${escapeHTML(c.username)}"
                        aria-label="Remove ${escapeHTML(c.username)} from contacts"
                    >
                        Remove
                    </button>
                </div>
            </article>
        `).join('');

        // Attach remove button listeners
        contactsListContainer.querySelectorAll('.btn-contact-remove').forEach(btn => {
            btn.addEventListener('click', handleRemoveContact);
        });
    }

    // ----------------------------------------------------
    // User Discovery (Search)
    // ----------------------------------------------------
    async function executeSearch(query) {
        const trimmed = query.trim();
        if (!trimmed) {
            searchResultsContainer.innerHTML = `
                <div class="state-box">
                    <span class="state-icon" aria-hidden="true">🔍</span>
                    <span>Type a username above to search registered operatives.</span>
                </div>
            `;
            return;
        }

        searchResultsContainer.innerHTML = `
            <div class="state-box">
                <div class="loading-spinner" aria-hidden="true"></div>
                <span>Scanning network directory for "${escapeHTML(trimmed)}"...</span>
            </div>
        `;

        try {
            const url = `api/search_users.php?q=${encodeURIComponent(trimmed)}`;
            const response = await fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) {
                const errData = await response.json().catch(() => ({}));
                throw new Error(errData.error || 'User discovery search failed.');
            }

            const data = await response.json();
            renderSearchResults(data.users || [], trimmed);
        } catch (err) {
            searchResultsContainer.innerHTML = `
                <div class="state-box">
                    <span class="state-icon" aria-hidden="true">⚠</span>
                    <span>${escapeHTML(err.message)}</span>
                </div>
            `;
        }
    }

    function renderSearchResults(users, query) {
        if (users.length === 0) {
            searchResultsContainer.innerHTML = `
                <div class="state-box">
                    <span class="state-icon" aria-hidden="true">Ø</span>
                    <span>No operatives found matching "${escapeHTML(query)}".</span>
                </div>
            `;
            return;
        }

        searchResultsContainer.innerHTML = users.map(u => {
            const isAlreadyContact = cachedContacts.has(u.id) || u.is_contact;

            return `
                <article class="operative-card" data-user-id="${u.id}">
                    <div class="operative-info">
                        <div class="operative-avatar" aria-hidden="true">${getInitials(u.username)}</div>
                        <div class="operative-details">
                            <span class="operative-name">${escapeHTML(u.username)}</span>
                            <span class="operative-meta">Member since: ${new Date(u.created_at).toLocaleDateString()}</span>
                        </div>
                    </div>
                    <div class="operative-actions">
                        ${isAlreadyContact ? `
                            <span class="badge-connected" aria-label="Already in contacts">
                                <span aria-hidden="true">✓</span> Connected
                            </span>
                        ` : `
                            <button 
                                type="button" 
                                class="btn-contact-add" 
                                data-action="add" 
                                data-user-id="${u.id}"
                                data-username="${escapeHTML(u.username)}"
                                aria-label="Add ${escapeHTML(u.username)} as contact"
                            >
                                + Add Contact
                            </button>
                        `}
                    </div>
                </article>
            `;
        }).join('');

        // Attach add button listeners
        searchResultsContainer.querySelectorAll('.btn-contact-add').forEach(btn => {
            btn.addEventListener('click', handleAddContact);
        });
    }

    // ----------------------------------------------------
    // Add Contact Handler
    // ----------------------------------------------------
    async function handleAddContact(e) {
        const btn = e.currentTarget;
        const targetId = parseInt(btn.getAttribute('data-user-id'), 10);
        const targetUsername = btn.getAttribute('data-username') || 'operative';

        if (!targetId) return;

        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.textContent = 'Connecting...';

        try {
            const response = await fetch('api/contacts.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    action: 'add',
                    contact_id: targetId,
                    csrf_token: csrfToken,
                }),
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || data.error || 'Failed to add contact.');
            }

            showToast(`Added ${targetUsername} to contacts!`, 'success');
            cachedContacts.add(targetId);

            // Replace button with Connected badge
            const actionsDiv = btn.closest('.operative-actions');
            if (actionsDiv) {
                actionsDiv.innerHTML = `
                    <span class="badge-connected" aria-label="Already in contacts">
                        <span aria-hidden="true">✓</span> Connected
                    </span>
                `;
            }

            // Refresh contact roster
            loadContacts();
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = originalText;
            showToast(err.message, 'error');
        }
    }

    // ----------------------------------------------------
    // Remove Contact Handler
    // ----------------------------------------------------
    async function handleRemoveContact(e) {
        const btn = e.currentTarget;
        const targetId = parseInt(btn.getAttribute('data-user-id'), 10);
        const targetUsername = btn.getAttribute('data-username') || 'this operative';

        if (!targetId) return;

        const confirmed = confirm(`Are you sure you want to remove ${targetUsername} from your contacts?`);
        if (!confirmed) return;

        btn.disabled = true;
        btn.textContent = 'Removing...';

        try {
            const response = await fetch('api/contacts.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    action: 'remove',
                    contact_id: targetId,
                    csrf_token: csrfToken,
                }),
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || data.error || 'Failed to remove contact.');
            }

            showToast(`Removed ${targetUsername} from contacts.`, 'success');
            cachedContacts.delete(targetId);

            // Reload contact list and re-run current search if any
            loadContacts();
            if (searchInput && searchInput.value.trim()) {
                executeSearch(searchInput.value);
            }
        } catch (err) {
            btn.disabled = false;
            btn.textContent = 'Remove';
            showToast(err.message, 'error');
        }
    }

    // ----------------------------------------------------
    // Event Listeners
    // ----------------------------------------------------
    if (searchForm) {
        searchForm.addEventListener('submit', (e) => {
            e.preventDefault();
            if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
            executeSearch(searchInput.value);
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                executeSearch(e.target.value);
            }, 300);
        });
    }

    if (refreshContactsBtn) {
        refreshContactsBtn.addEventListener('click', () => {
            loadContacts();
            showToast('Contact roster refreshed.', 'success');
        });
    }

    // Initial load
    loadContacts();
});
