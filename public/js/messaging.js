/**
 * Project Exodia — Direct Messaging Client Controller
 *
 * Vanilla JavaScript (ES6+) for:
 *  - Real-time conversation list retrieval and selection
 *  - Incremental AJAX polling for new messages (after_id)
 *  - Instant message sending with CSRF protection and validation
 *  - Sidebar tabs (Active Chats vs Contacts Roster)
 *  - Mobile responsive panel toggling
 *  - XSS-safe rendering and automatic bottom scrolling
 */

document.addEventListener('DOMContentLoaded', () => {
    // DOM Elements
    const messagingLayout = document.querySelector('.messaging-layout');
    const conversationListContainer = document.getElementById('conversationList');
    const contactsListContainer = document.getElementById('contactsTabList');
    const searchFilterInput = document.getElementById('searchFilterInput');
    const tabChats = document.getElementById('tabChats');
    const tabContacts = document.getElementById('tabContacts');

    // Chat Panel Elements
    const chatHeader = document.getElementById('chatHeader');
    const chatHeaderName = document.getElementById('chatHeaderName');
    const chatHeaderStatus = document.getElementById('chatHeaderStatus');
    const chatHeaderAvatar = document.getElementById('chatHeaderAvatar');
    const chatBackBtn = document.getElementById('chatBackBtn');
    const chatMessages = document.getElementById('chatMessages');
    const chatComposer = document.getElementById('chatComposer');
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const chatEmptyState = document.getElementById('chatEmptyState');

    // Meta & State
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';
    const currentUserIdMeta = document.querySelector('meta[name="user-id"]');
    const currentUserId = currentUserIdMeta ? parseInt(currentUserIdMeta.getAttribute('content'), 10) : 0;

    let activeConversationId = null;
    let activeOtherUsername = '';
    let lastMessageId = 0;
    let pollInterval = null;
    let isPolling = false;
    let cachedConversations = [];
    let cachedContacts = [];
    let activeTab = 'chats'; // 'chats' or 'contacts'

    // ----------------------------------------------------
    // Utility Functions
    // ----------------------------------------------------
    function escapeHTML(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function getInitials(name) {
        if (!name) return '?';
        return name.slice(0, 2).toUpperCase();
    }

    function formatTime(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function scrollToBottom() {
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }

    // ----------------------------------------------------
    // Tab Switching (Chats vs Contacts)
    // ----------------------------------------------------
    if (tabChats && tabContacts) {
        tabChats.addEventListener('click', () => switchTab('chats'));
        tabContacts.addEventListener('click', () => switchTab('contacts'));
    }

    function switchTab(tab) {
        activeTab = tab;
        if (tab === 'chats') {
            tabChats.classList.add('active');
            tabContacts.classList.remove('active');
            if (conversationListContainer) conversationListContainer.style.display = 'block';
            if (contactsListContainer) contactsListContainer.style.display = 'none';
            renderConversations(cachedConversations, searchFilterInput ? searchFilterInput.value : '');
        } else {
            tabContacts.classList.add('active');
            tabChats.classList.remove('active');
            if (conversationListContainer) conversationListContainer.style.display = 'none';
            if (contactsListContainer) contactsListContainer.style.display = 'block';
            renderContactsTab(cachedContacts, searchFilterInput ? searchFilterInput.value : '');
        }
    }

    // ----------------------------------------------------
    // Load & Render Conversations
    // ----------------------------------------------------
    async function loadConversations() {
        try {
            const res = await fetch('api/conversations.php', {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error('Failed to load conversations.');
            const data = await res.json();
            if (data.success) {
                cachedConversations = data.conversations || [];
                if (activeTab === 'chats') {
                    renderConversations(cachedConversations, searchFilterInput ? searchFilterInput.value : '');
                }
            }
        } catch (err) {
            console.error('Conversations error:', err);
        }
    }

    function renderConversations(conversations, filter = '') {
        if (!conversationListContainer) return;
        const q = filter.trim().toLowerCase();
        const filtered = q
            ? conversations.filter(c => c.other_username.toLowerCase().includes(q) || (c.last_message_preview && c.last_message_preview.toLowerCase().includes(q)))
            : conversations;

        if (filtered.length === 0) {
            conversationListContainer.innerHTML = `
                <div class="sidebar-empty">
                    <span aria-hidden="true">${q ? '🔍' : '💬'}</span>
                    <span>${q ? `No chats matching "${escapeHTML(q)}"` : 'No conversations yet.'}</span>
                    ${!q ? '<button type="button" class="sidebar-action-btn" id="startChatHintBtn">Select a contact</button>' : ''}
                </div>
            `;
            const hintBtn = document.getElementById('startChatHintBtn');
            if (hintBtn) hintBtn.addEventListener('click', () => switchTab('contacts'));
            return;
        }

        conversationListContainer.innerHTML = filtered.map(c => `
            <div class="conv-item ${c.conversation_id === activeConversationId ? 'active' : ''}" 
                 data-conv-id="${c.conversation_id}" 
                 data-other-user="${escapeHTML(c.other_username)}"
                 data-other-id="${c.other_user_id}">
                <div class="conv-avatar">${getInitials(c.other_username)}</div>
                <div class="conv-details">
                    <div class="conv-name">${escapeHTML(c.other_username)}</div>
                    <div class="conv-preview">${c.last_message_preview ? escapeHTML(c.last_message_preview) : 'No messages yet'}</div>
                </div>
                <div class="conv-time">${c.last_message_time ? formatTime(c.last_message_time) : ''}</div>
            </div>
        `).join('');

        conversationListContainer.querySelectorAll('.conv-item').forEach(item => {
            item.addEventListener('click', () => {
                const cid = parseInt(item.getAttribute('data-conv-id'), 10);
                const username = item.getAttribute('data-other-user');
                const uid = parseInt(item.getAttribute('data-other-id'), 10);
                selectConversation(cid, username, uid);
            });
        });
    }

    // ----------------------------------------------------
    // Load & Render Contacts Tab
    // ----------------------------------------------------
    async function loadContacts() {
        try {
            const res = await fetch('api/contacts.php', {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) return;
            const data = await res.json();
            if (data.success) {
                cachedContacts = data.contacts || [];
                if (activeTab === 'contacts') {
                    renderContactsTab(cachedContacts, searchFilterInput ? searchFilterInput.value : '');
                }
            }
        } catch (err) {
            console.error('Contacts tab error:', err);
        }
    }

    function renderContactsTab(contacts, filter = '') {
        if (!contactsListContainer) return;
        const q = filter.trim().toLowerCase();
        const filtered = q
            ? contacts.filter(c => c.username.toLowerCase().includes(q))
            : contacts;

        if (filtered.length === 0) {
            contactsListContainer.innerHTML = `
                <div class="sidebar-empty">
                    <span>${q ? `No contacts matching "${escapeHTML(q)}"` : 'Your contact roster is empty.'}</span>
                    <a href="contacts.php" class="sidebar-action-btn">Discover Operatives</a>
                </div>
            `;
            return;
        }

        contactsListContainer.innerHTML = filtered.map(c => `
            <div class="conv-item" data-contact-id="${c.user_id}" data-contact-username="${escapeHTML(c.username)}">
                <div class="conv-avatar">${getInitials(c.username)}</div>
                <div class="conv-details">
                    <div class="conv-name">${escapeHTML(c.username)}</div>
                    <div class="conv-preview">Click to message</div>
                </div>
                <button type="button" class="sidebar-action-btn">Chat</button>
            </div>
        `).join('');

        contactsListContainer.querySelectorAll('.conv-item').forEach(item => {
            item.addEventListener('click', async () => {
                const uid = parseInt(item.getAttribute('data-contact-id'), 10);
                const uname = item.getAttribute('data-contact-username');
                await startConversationWithUser(uid, uname);
            });
        });
    }

    // ----------------------------------------------------
    // Start Conversation With User
    // ----------------------------------------------------
    async function startConversationWithUser(userId, username) {
        try {
            const res = await fetch('api/conversations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    action: 'create',
                    user_id: userId,
                    csrf_token: csrfToken,
                }),
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                alert(data.error || 'Failed to start conversation.');
                return;
            }
            switchTab('chats');
            await loadConversations();
            selectConversation(data.conversation_id, username, userId);
        } catch (err) {
            alert('Error starting conversation: ' + err.message);
        }
    }

    // ----------------------------------------------------
    // Select & Open Conversation
    // ----------------------------------------------------
    async function selectConversation(convId, otherUsername, otherUserId) {
        if (!convId) return;

        // Stop any previous polling
        if (pollInterval) clearInterval(pollInterval);
        activeConversationId = convId;
        activeOtherUsername = otherUsername;
        lastMessageId = 0;

        // Update active class in sidebar
        if (conversationListContainer) {
            conversationListContainer.querySelectorAll('.conv-item').forEach(el => {
                if (parseInt(el.getAttribute('data-conv-id'), 10) === convId) {
                    el.classList.add('active');
                } else {
                    el.classList.remove('active');
                }
            });
        }

        // Mobile: Show chat view
        if (messagingLayout) messagingLayout.classList.add('chat-open');

        // Update header & views
        if (chatEmptyState) chatEmptyState.style.display = 'none';
        if (chatHeader) chatHeader.style.display = 'flex';
        if (chatComposer) chatComposer.style.display = 'flex';
        if (chatMessages) {
            chatMessages.style.display = 'flex';
            chatMessages.innerHTML = `
                <div class="sidebar-empty">
                    <div class="loading-dots"><span></span><span></span><span></span></div>
                    <span>Loading conversation history...</span>
                </div>
            `;
        }
        if (chatHeaderName) chatHeaderName.textContent = otherUsername;
        if (chatHeaderStatus) chatHeaderStatus.textContent = 'Active Encrypted Channel';
        if (chatHeaderAvatar) chatHeaderAvatar.textContent = getInitials(otherUsername);
        if (messageInput) {
            messageInput.disabled = false;
            messageInput.focus();
        }

        // Fetch initial message history
        await fetchMessages(convId, true);

        // Start polling every 2.5 seconds
        pollInterval = setInterval(() => {
            pollNewMessages();
        }, 2500);
    }

    // ----------------------------------------------------
    // Fetch Messages
    // ----------------------------------------------------
    async function fetchMessages(convId, isInitial = false) {
        try {
            const url = `api/messages.php?conversation_id=${convId}&after_id=${lastMessageId}`;
            const res = await fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error('Failed to retrieve messages.');
            const data = await res.json();
            if (!data.success) return;

            const msgs = data.messages || [];
            if (isInitial) {
                if (msgs.length === 0) {
                    chatMessages.innerHTML = `
                        <div class="chat-empty-state">
                            <span class="chat-empty-icon" aria-hidden="true">🔒</span>
                            <h3 class="chat-empty-title">Encrypted Transmission Channel</h3>
                            <p class="chat-empty-subtitle">Say hello to ${escapeHTML(activeOtherUsername)} to begin this direct transmission.</p>
                        </div>
                    `;
                } else {
                    chatMessages.innerHTML = '';
                    appendMessages(msgs);
                    scrollToBottom();
                }
            } else if (msgs.length > 0) {
                // Remove empty state if present
                const emptyEl = chatMessages.querySelector('.chat-empty-state');
                if (emptyEl) emptyEl.remove();

                appendMessages(msgs);
                scrollToBottom();
            }

            if (msgs.length > 0) {
                lastMessageId = msgs[msgs.length - 1].id;
            }
        } catch (err) {
            console.error('Fetch messages error:', err);
        }
    }

    function appendMessages(msgs) {
        msgs.forEach(m => {
            const isSent = (m.sender_id === currentUserId);
            const group = document.createElement('div');
            group.className = `msg-group ${isSent ? 'sent' : 'received'}`;
            group.setAttribute('data-msg-id', m.id);

            group.innerHTML = `
                <div class="msg-bubble">${escapeHTML(m.body)}</div>
                <div class="msg-meta">${formatTime(m.created_at)}</div>
            `;
            chatMessages.appendChild(group);
        });
    }

    // ----------------------------------------------------
    // Incremental Polling
    // ----------------------------------------------------
    async function pollNewMessages() {
        if (!activeConversationId || isPolling) return;
        isPolling = true;
        try {
            await fetchMessages(activeConversationId, false);
        } finally {
            isPolling = false;
        }
    }

    // ----------------------------------------------------
    // Send Message Handler
    // ----------------------------------------------------
    async function handleSendMessage() {
        if (!activeConversationId) return;
        const text = messageInput.value.trim();
        if (!text) return;

        messageInput.disabled = true;
        sendBtn.disabled = true;

        try {
            const res = await fetch('api/messages.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    conversation_id: activeConversationId,
                    body: text,
                    csrf_token: csrfToken,
                }),
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                alert(data.error || 'Failed to send message.');
                return;
            }

            messageInput.value = '';
            // Immediately fetch new message
            await pollNewMessages();
            // Refresh conversation preview in sidebar
            loadConversations();
        } catch (err) {
            alert('Failed to send transmission: ' + err.message);
        } finally {
            messageInput.disabled = false;
            sendBtn.disabled = false;
            messageInput.focus();
        }
    }

    if (sendBtn) {
        sendBtn.addEventListener('click', handleSendMessage);
    }

    if (messageInput) {
        messageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSendMessage();
            }
        });
    }

    // Mobile Back Button
    if (chatBackBtn) {
        chatBackBtn.addEventListener('click', () => {
            if (messagingLayout) messagingLayout.classList.remove('chat-open');
            if (pollInterval) clearInterval(pollInterval);
            activeConversationId = null;
        });
    }

    // Search filter input
    if (searchFilterInput) {
        searchFilterInput.addEventListener('input', (e) => {
            if (activeTab === 'chats') {
                renderConversations(cachedConversations, e.target.value);
            } else {
                renderContactsTab(cachedContacts, e.target.value);
            }
        });
    }

    // ----------------------------------------------------
    // Auto-Open Conversation from URL Query Parameters
    // ----------------------------------------------------
    const urlParams = new URLSearchParams(window.location.search);
    const targetUserId = urlParams.get('user_id');
    const targetConvId = urlParams.get('conversation_id');

    async function init() {
        await Promise.all([loadConversations(), loadContacts()]);

        if (targetUserId) {
            const uid = parseInt(targetUserId, 10);
            const foundContact = cachedContacts.find(c => c.user_id === uid);
            const username = foundContact ? foundContact.username : 'Operative';
            await startConversationWithUser(uid, username);
        } else if (targetConvId) {
            const cid = parseInt(targetConvId, 10);
            const foundConv = cachedConversations.find(c => c.conversation_id === cid);
            if (foundConv) {
                selectConversation(cid, foundConv.other_username, foundConv.other_user_id);
            }
        }
    }

    init();
});
