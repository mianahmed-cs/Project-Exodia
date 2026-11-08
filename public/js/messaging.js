/**
 * Project Exodia — Direct Messaging Client Controller
 *
 * Vanilla JavaScript (ES6+) for:
 *  - Real-time conversation list retrieval and selection
 *  - Incremental activity event synchronization (deletions, reactions, creations)
 *  - Quoted message replies with preview bar and jumping to referenced bubbles
 *  - Message emoji reactions (👍, ❤️, 😂, 😮, 😢, 😡) with real-time badges
 *  - Delete for Me and Delete for Everyone with confirmation
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

    // Quoted Reply Composer Bar Elements
    const composerReplyBar = document.getElementById('composerReplyBar');
    const replyBarTitle = document.getElementById('replyBarTitle');
    const replyBarText = document.getElementById('replyBarText');
    const cancelReplyBtn = document.getElementById('cancelReplyBtn');

    // Attachment Staging & Viewer Elements
    const attachBtn = document.getElementById('attachBtn');
    const attachmentInput = document.getElementById('attachmentInput');
    const composerAttachmentBar = document.getElementById('composerAttachmentBar');
    const attachmentPreviewIcon = document.getElementById('attachmentPreviewIcon');
    const attachmentPreviewName = document.getElementById('attachmentPreviewName');
    const attachmentPreviewSize = document.getElementById('attachmentPreviewSize');
    const attachmentProgressBar = document.getElementById('attachmentProgressBar');
    const attachmentProgressFill = document.getElementById('attachmentProgressFill');
    const removeAttachmentBtn = document.getElementById('removeAttachmentBtn');

    // Lightbox Image Viewer Elements
    const imageViewerModal = document.getElementById('imageViewerModal');
    const imageViewerBackdrop = document.getElementById('imageViewerBackdrop');
    const imageViewerTitle = document.getElementById('imageViewerTitle');
    const imageViewerMeta = document.getElementById('imageViewerMeta');
    const imageViewerDownload = document.getElementById('imageViewerDownload');
    const imageViewerClose = document.getElementById('imageViewerClose');
    const imageViewerImg = document.getElementById('imageViewerImg');

    let activeConversationId = null;
    let activeOtherUsername = '';
    let lastMessageId = 0;
    let lastEventId = 0;
    let pollInterval = null;
    let isPolling = false;
    let cachedConversations = [];
    let cachedContacts = [];
    let activeTab = 'chats'; // 'chats' or 'contacts'
    let activeReplyTo = null; // { id, sender_username, body }
    let activeMenuEl = null;
    let stagedAttachmentFile = null;

    const ALLOWED_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '😡'];

    // Close any open popups on window click
    document.addEventListener('click', (e) => {
        if (activeMenuEl && !activeMenuEl.contains(e.target)) {
            closeActiveMenu();
        }
    });

    function closeActiveMenu() {
        if (activeMenuEl) {
            const parentRow = activeMenuEl.closest('.msg-row');
            if (parentRow) parentRow.classList.remove('menu-open');
            activeMenuEl.remove();
            activeMenuEl = null;
        }
    }

    // ----------------------------------------------------
    // Quoted Reply Helpers
    // ----------------------------------------------------
    function setReply(msgId, senderUsername, body) {
        closeActiveMenu();
        activeReplyTo = {
            id: msgId,
            sender_username: senderUsername,
            body: body,
        };

        if (composerReplyBar) {
            composerReplyBar.style.display = 'flex';
            if (replyBarTitle) replyBarTitle.textContent = `Replying to ${senderUsername}`;
            if (replyBarText) replyBarText.textContent = body || 'Attachment message';
        }
        if (messageInput) messageInput.focus();
    }

    function clearReply() {
        activeReplyTo = null;
        if (composerReplyBar) composerReplyBar.style.display = 'none';
    }

    if (cancelReplyBtn) {
        cancelReplyBtn.addEventListener('click', clearReply);
    }

    // ----------------------------------------------------
    // Attachment Staging & Lightbox Helpers
    // ----------------------------------------------------
    function formatFileSize(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function getDocIconColor(filename) {
        const ext = (filename || '').split('.').pop().toLowerCase();
        if (ext === 'pdf') return '#ff453a';
        if (['doc', 'docx'].includes(ext)) return '#0a84ff';
        if (['xls', 'xlsx'].includes(ext)) return '#30d158';
        if (['ppt', 'pptx'].includes(ext)) return '#ff9f0a';
        return '#8e8e93';
    }

    if (attachBtn && attachmentInput) {
        attachBtn.addEventListener('click', () => {
            attachmentInput.click();
        });

        attachmentInput.addEventListener('change', (e) => {
            const file = e.target.files && e.target.files[0];
            if (!file) return;

            const isImage = file.type.startsWith('image/');
            const maxSize = isImage ? 10 * 1024 * 1024 : 25 * 1024 * 1024;
            const limitLabel = isImage ? '10 MB' : '25 MB';

            if (file.size > maxSize) {
                alert(`File "${file.name}" exceeds the ${limitLabel} size limit.`);
                attachmentInput.value = '';
                return;
            }

            stagedAttachmentFile = file;

            if (composerAttachmentBar) {
                composerAttachmentBar.style.display = 'flex';
                if (attachmentPreviewName) attachmentPreviewName.textContent = file.name;
                if (attachmentPreviewSize) attachmentPreviewSize.textContent = formatFileSize(file.size);

                if (attachmentPreviewIcon) {
                    if (isImage) {
                        const blobUrl = URL.createObjectURL(file);
                        attachmentPreviewIcon.innerHTML = `<img src="${blobUrl}" alt="Preview" />`;
                    } else {
                        const iconColor = getDocIconColor(file.name);
                        attachmentPreviewIcon.innerHTML = `
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                            </svg>
                        `;
                    }
                }
            }

            if (messageInput) {
                messageInput.placeholder = 'Add a caption (optional)...';
                messageInput.focus();
            }
        });
    }

    function removeAttachment() {
        stagedAttachmentFile = null;
        if (attachmentInput) attachmentInput.value = '';
        if (composerAttachmentBar) composerAttachmentBar.style.display = 'none';
        if (attachmentProgressBar) attachmentProgressBar.style.display = 'none';
        if (attachmentProgressFill) attachmentProgressFill.style.width = '0%';
        if (messageInput) {
            messageInput.placeholder = 'Type a message...';
        }
    }

    if (removeAttachmentBtn) {
        removeAttachmentBtn.addEventListener('click', removeAttachment);
    }

    // Lightbox image viewer
    function openImageViewer(previewUrl, downloadUrl, filename, size) {
        if (!imageViewerModal) return;
        if (imageViewerImg) imageViewerImg.src = previewUrl;
        if (imageViewerDownload) {
            imageViewerDownload.href = downloadUrl;
            imageViewerDownload.setAttribute('download', filename || 'photo');
        }
        if (imageViewerTitle) imageViewerTitle.textContent = filename || 'Photo Preview';
        if (imageViewerMeta) imageViewerMeta.textContent = formatFileSize(size);
        imageViewerModal.style.display = 'flex';
    }

    function closeImageViewer() {
        if (!imageViewerModal) return;
        imageViewerModal.style.display = 'none';
        if (imageViewerImg) imageViewerImg.src = '';
    }

    if (imageViewerClose) imageViewerClose.addEventListener('click', closeImageViewer);
    if (imageViewerBackdrop) imageViewerBackdrop.addEventListener('click', closeImageViewer);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && imageViewerModal && imageViewerModal.style.display !== 'none') {
            closeImageViewer();
        }
    });

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
        closeActiveMenu();
        clearReply();
        removeAttachment();
        closeImageViewer();

        activeConversationId = convId;
        activeOtherUsername = otherUsername;
        lastMessageId = 0;
        lastEventId = 0;

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

        // Start incremental polling every 2.5 seconds
        pollInterval = setInterval(() => {
            pollNewMessages();
        }, 2500);
    }

    // ----------------------------------------------------
    // Fetch Messages & Events
    // ----------------------------------------------------
    async function fetchMessages(convId, isInitial = false) {
        try {
            const eventParam = isInitial ? '' : `&after_event_id=${lastEventId}`;
            const url = `api/messages.php?conversation_id=${convId}&after_id=${lastMessageId}${eventParam}`;
            const res = await fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error('Failed to retrieve messages.');
            const data = await res.json();
            if (!data.success) return;

            // Track latest event ID
            if (data.last_event_id !== undefined && data.last_event_id > lastEventId) {
                lastEventId = data.last_event_id;
            }

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

            // Process real-time events (Deletions, Reactions)
            if (!isInitial && Array.isArray(data.events)) {
                data.events.forEach(ev => processConversationEvent(ev));
            }
        } catch (err) {
            console.error('Fetch messages error:', err);
        }
    }

    // ----------------------------------------------------
    // Event Sync Processor (Real-Time Updates)
    // ----------------------------------------------------
    function processConversationEvent(ev) {
        if (!ev || !ev.message_id) return;
        const msgGroup = chatMessages ? chatMessages.querySelector(`.msg-group[data-msg-id="${ev.message_id}"]`) : null;
        if (!msgGroup) return;

        if (ev.event_type === 'message_deleted') {
            msgGroup.classList.add('deleted');
            const bubble = msgGroup.querySelector('.msg-bubble');
            if (bubble) {
                bubble.innerHTML = 'This message was deleted.';
            }
            // Remove trigger button
            const trigger = msgGroup.querySelector('.msg-actions-trigger');
            if (trigger) trigger.remove();
            // Remove reactions
            const reactionRow = msgGroup.querySelector('.msg-reactions-row');
            if (reactionRow) reactionRow.remove();
            // Refresh conversation preview in sidebar
            loadConversations();
        } else if (ev.event_type === 'reaction_changed') {
            renderReactionsRow(msgGroup, ev.message_id, ev.reactions || []);
        }
    }

    // ----------------------------------------------------
    // Message Rendering & Component Construction
    // ----------------------------------------------------
    function appendMessages(msgs) {
        msgs.forEach(m => {
            // Check if already rendered to prevent duplicate DOM insertions
            if (chatMessages.querySelector(`.msg-group[data-msg-id="${m.id}"]`)) return;

            const isSent = (m.sender_id === currentUserId);
            const isDeleted = Boolean(m.is_deleted_for_everyone);

            const group = document.createElement('div');
            group.className = `msg-group ${isSent ? 'sent' : 'received'} ${isDeleted ? 'deleted' : ''}`;
            group.setAttribute('data-msg-id', m.id);

            // Message Row (Bubble + Actions Trigger)
            const row = document.createElement('div');
            row.className = 'msg-row';

            // Bubble
            const bubble = document.createElement('div');
            bubble.className = 'msg-bubble';

            // Quoted Reply Preview (if message replies to another)
            if (m.reply_to && !isDeleted) {
                const quote = document.createElement('div');
                quote.className = 'msg-reply-quote';
                quote.setAttribute('data-target-id', m.reply_to.id);
                quote.innerHTML = `
                    <span class="msg-reply-sender">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                        ${escapeHTML(m.reply_to.sender_username)}
                    </span>
                    <span class="msg-reply-text">${escapeHTML(m.reply_to.body)}</span>
                `;
                quote.addEventListener('click', (e) => {
                    e.stopPropagation();
                    jumpToMessage(m.reply_to.id);
                });
                bubble.appendChild(quote);
            }

            // Attachment Preview (Photo Lightbox Thumbnail or Document Card)
            if (m.attachment && !isDeleted) {
                const att = m.attachment;
                const fileName = att.original_filename || att.original_name || 'attachment';
                if (att.file_type === 'image') {
                    const imgWrap = document.createElement('div');
                    imgWrap.className = 'msg-attachment-image-wrap';
                    imgWrap.innerHTML = `
                        <img src="${escapeHTML(att.preview_url)}" 
                             alt="${escapeHTML(fileName)}" 
                             class="msg-attachment-img" 
                             loading="lazy" />
                    `;
                    imgWrap.addEventListener('click', (e) => {
                        e.stopPropagation();
                        openImageViewer(att.preview_url, att.download_url, fileName, att.file_size);
                    });
                    bubble.appendChild(imgWrap);
                } else {
                    const docCard = document.createElement('a');
                    docCard.className = 'msg-attachment-doc-card';
                    docCard.href = att.download_url;
                    docCard.setAttribute('download', fileName);
                    const iconColor = getDocIconColor(fileName);
                    docCard.innerHTML = `
                        <div class="doc-icon-wrap">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                            </svg>
                        </div>
                        <div class="doc-details">
                            <span class="doc-name" title="${escapeHTML(fileName)}">${escapeHTML(fileName)}</span>
                            <span class="doc-size">${formatFileSize(att.file_size)}</span>
                        </div>
                        <div class="doc-download-icon" title="Download">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                        </div>
                    `;
                    bubble.appendChild(docCard);
                }
            }

            // Text / Caption Body
            if (isDeleted) {
                const textSpan = document.createElement('span');
                textSpan.className = 'msg-text';
                textSpan.innerHTML = 'This message was deleted.';
                bubble.appendChild(textSpan);
            } else if (m.body && m.body.trim() !== '') {
                const textSpan = document.createElement('span');
                textSpan.className = 'msg-text';
                textSpan.innerHTML = escapeHTML(m.body);
                bubble.appendChild(textSpan);
            }
            row.appendChild(bubble);

            // Action Trigger Button (Only if not deleted for everyone)
            if (!isDeleted) {
                const triggerBtn = document.createElement('button');
                triggerBtn.type = 'button';
                triggerBtn.className = 'msg-actions-trigger';
                triggerBtn.setAttribute('aria-label', 'Message actions');
                triggerBtn.innerHTML = `
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="1"></circle>
                        <circle cx="19" cy="12" r="1"></circle>
                        <circle cx="5" cy="12" r="1"></circle>
                    </svg>
                `;
                triggerBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    toggleMessageMenu(row, m, isSent);
                });
                row.appendChild(triggerBtn);
            }

            group.appendChild(row);

            // Reaction Badges Row
            if (!isDeleted) {
                renderReactionsRow(group, m.id, m.reactions || []);
            }

            // Timestamp Meta
            const meta = document.createElement('div');
            meta.className = 'msg-meta';
            meta.textContent = formatTime(m.created_at);
            group.appendChild(meta);

            chatMessages.appendChild(group);
        });
    }

    // ----------------------------------------------------
    // Render Reaction Badges
    // ----------------------------------------------------
    function renderReactionsRow(msgGroup, msgId, reactions) {
        let row = msgGroup.querySelector('.msg-reactions-row');
        if (!row) {
            row = document.createElement('div');
            row.className = 'msg-reactions-row';
            // Insert right after msg-row and before msg-meta
            const meta = msgGroup.querySelector('.msg-meta');
            if (meta) {
                msgGroup.insertBefore(row, meta);
            } else {
                msgGroup.appendChild(row);
            }
        }

        if (!reactions || reactions.length === 0) {
            row.innerHTML = '';
            return;
        }

        row.innerHTML = reactions.map(r => `
            <button type="button" class="reaction-pill ${r.has_reacted ? 'active' : ''}" 
                    data-msg-id="${msgId}" data-emoji="${r.emoji}">
                <span>${r.emoji}</span>
                <span class="reaction-count">${r.count}</span>
            </button>
        `).join('');

        row.querySelectorAll('.reaction-pill').forEach(pill => {
            pill.addEventListener('click', (e) => {
                e.stopPropagation();
                const em = pill.getAttribute('data-emoji');
                handleToggleReaction(msgId, em);
            });
        });
    }

    // ----------------------------------------------------
    // Message Context Menu
    // ----------------------------------------------------
    function toggleMessageMenu(row, m, isSent) {
        if (activeMenuEl && activeMenuEl.parentNode === row) {
            closeActiveMenu();
            return;
        }
        closeActiveMenu();

        row.classList.add('menu-open');
        const menu = document.createElement('div');
        menu.className = 'msg-menu-dropdown';
        activeMenuEl = menu;

        // Sent vs Received positioning
        if (isSent) {
            menu.style.right = '32px';
        } else {
            menu.style.left = '32px';
        }
        menu.style.top = '0';

        menu.innerHTML = `
            <button type="button" class="msg-menu-item" data-action="reply">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                Reply
            </button>
            <button type="button" class="msg-menu-item" data-action="react">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                React
            </button>
            <button type="button" class="msg-menu-item" data-action="delete_for_me">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                Delete for Me
            </button>
            ${isSent && !m.is_deleted_for_everyone ? `
            <button type="button" class="msg-menu-item danger" data-action="delete_for_everyone">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                Delete for Everyone
            </button>
            ` : ''}
        `;

        menu.querySelector('[data-action="reply"]').addEventListener('click', () => {
            setReply(m.id, m.sender_username, m.body);
        });

        menu.querySelector('[data-action="react"]').addEventListener('click', (e) => {
            e.stopPropagation();
            openEmojiPicker(row, m.id, isSent);
        });

        menu.querySelector('[data-action="delete_for_me"]').addEventListener('click', () => {
            closeActiveMenu();
            handleDeleteForMe(m.id);
        });

        const delEveryoneBtn = menu.querySelector('[data-action="delete_for_everyone"]');
        if (delEveryoneBtn) {
            delEveryoneBtn.addEventListener('click', () => {
                closeActiveMenu();
                handleDeleteForEveryone(m.id);
            });
        }

        row.appendChild(menu);
    }

    // ----------------------------------------------------
    // Emoji Picker Floating Pill
    // ----------------------------------------------------
    function openEmojiPicker(row, msgId, isSent) {
        closeActiveMenu();
        row.classList.add('menu-open');

        const pill = document.createElement('div');
        pill.className = 'emoji-picker-pill';
        activeMenuEl = pill;

        if (isSent) {
            pill.style.right = '32px';
        } else {
            pill.style.left = '32px';
        }
        pill.style.top = '-16px';

        pill.innerHTML = ALLOWED_EMOJIS.map(em => `
            <button type="button" class="emoji-btn" data-emoji="${em}" aria-label="React with ${em}">
                ${em}
            </button>
        `).join('');

        pill.querySelectorAll('.emoji-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const em = btn.getAttribute('data-emoji');
                closeActiveMenu();
                handleToggleReaction(msgId, em);
            });
        });

        row.appendChild(pill);
    }

    // ----------------------------------------------------
    // Jump / Scroll to Referenced Message
    // ----------------------------------------------------
    function jumpToMessage(targetMsgId) {
        const targetGroup = chatMessages.querySelector(`.msg-group[data-msg-id="${targetMsgId}"]`);
        if (targetGroup) {
            targetGroup.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const bubble = targetGroup.querySelector('.msg-bubble');
            if (bubble) {
                bubble.classList.remove('highlight-pulse');
                void bubble.offsetWidth; // Trigger reflow for animation restart
                bubble.classList.add('highlight-pulse');
                setTimeout(() => bubble.classList.remove('highlight-pulse'), 1400);
            }
        }
    }

    // ----------------------------------------------------
    // Delete for Me Handler
    // ----------------------------------------------------
    async function handleDeleteForMe(msgId) {
        try {
            const res = await fetch('api/messages.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    action: 'delete_for_me',
                    message_id: msgId,
                    csrf_token: csrfToken,
                }),
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                alert(data.error || 'Failed to hide message.');
                return;
            }

            // Animate removal from DOM
            const group = chatMessages.querySelector(`.msg-group[data-msg-id="${msgId}"]`);
            if (group) {
                group.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
                group.style.opacity = '0';
                group.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    group.remove();
                    if (chatMessages.querySelectorAll('.msg-group').length === 0) {
                        chatMessages.innerHTML = `
                            <div class="chat-empty-state">
                                <span class="chat-empty-icon" aria-hidden="true">🔒</span>
                                <h3 class="chat-empty-title">Encrypted Transmission Channel</h3>
                                <p class="chat-empty-subtitle">Say hello to ${escapeHTML(activeOtherUsername)} to begin this direct transmission.</p>
                            </div>
                        `;
                    }
                }, 200);
            }

            // Refresh conversation preview in sidebar
            loadConversations();
        } catch (err) {
            alert('Delete for Me error: ' + err.message);
        }
    }

    // ----------------------------------------------------
    // Delete for Everyone Handler
    // ----------------------------------------------------
    async function handleDeleteForEveryone(msgId) {
        if (!confirm('Are you sure you want to delete this message for everyone? This action cannot be undone.')) {
            return;
        }

        try {
            const res = await fetch('api/messages.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    action: 'delete_for_everyone',
                    message_id: msgId,
                    csrf_token: csrfToken,
                }),
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                alert(data.error || 'Failed to delete message for everyone.');
                return;
            }

            // Update DOM immediately
            const group = chatMessages.querySelector(`.msg-group[data-msg-id="${msgId}"]`);
            if (group) {
                group.classList.add('deleted');
                const bubble = group.querySelector('.msg-bubble');
                if (bubble) bubble.innerHTML = 'This message was deleted.';
                const trigger = group.querySelector('.msg-actions-trigger');
                if (trigger) trigger.remove();
                const reactionRow = group.querySelector('.msg-reactions-row');
                if (reactionRow) reactionRow.remove();
            }

            // Immediately poll to synchronize event IDs
            await pollNewMessages();
            // Refresh conversation preview in sidebar
            loadConversations();
        } catch (err) {
            alert('Delete for Everyone error: ' + err.message);
        }
    }

    // ----------------------------------------------------
    // Emoji Reaction Handler
    // ----------------------------------------------------
    async function handleToggleReaction(msgId, emoji) {
        try {
            const res = await fetch('api/messages.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    action: 'react',
                    message_id: msgId,
                    emoji: emoji,
                    csrf_token: csrfToken,
                }),
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                alert(data.error || 'Failed to react.');
                return;
            }

            // Update reactions row immediately in DOM
            const group = chatMessages.querySelector(`.msg-group[data-msg-id="${msgId}"]`);
            if (group) {
                renderReactionsRow(group, msgId, data.reactions || []);
            }
        } catch (err) {
            alert('Reaction error: ' + err.message);
        }
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
        const text = messageInput ? messageInput.value.trim() : '';
        if (!text && !stagedAttachmentFile) return;

        if (messageInput) messageInput.disabled = true;
        if (sendBtn) sendBtn.disabled = true;
        if (attachBtn) attachBtn.disabled = true;

        const replyToId = activeReplyTo ? activeReplyTo.id : null;

        try {
            if (stagedAttachmentFile) {
                // Upload attachment with optional caption and reply
                const formData = new FormData();
                formData.append('conversation_id', activeConversationId);
                formData.append('body', text);
                formData.append('attachment', stagedAttachmentFile);
                formData.append('csrf_token', csrfToken);
                if (replyToId) {
                    formData.append('reply_to_message_id', replyToId);
                }

                if (attachmentProgressBar) attachmentProgressBar.style.display = 'block';
                if (attachmentProgressFill) attachmentProgressFill.style.width = '15%';

                await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', 'api/messages.php');
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-CSRF-Token', csrfToken);

                    xhr.upload.onprogress = (e) => {
                        if (e.lengthComputable && attachmentProgressFill) {
                            const pct = Math.round((e.loaded / e.total) * 90);
                            attachmentProgressFill.style.width = `${Math.max(15, pct)}%`;
                        }
                    };

                    xhr.onload = () => {
                        try {
                            const resp = JSON.parse(xhr.responseText);
                            if (xhr.status >= 200 && xhr.status < 300 && resp.success) {
                                if (attachmentProgressFill) attachmentProgressFill.style.width = '100%';
                                resolve(resp);
                            } else {
                                reject(new Error(resp.error || 'Upload failed with status ' + xhr.status));
                            }
                        } catch (e) {
                            reject(new Error('Invalid response from server: ' + xhr.responseText));
                        }
                    };

                    xhr.onerror = () => reject(new Error('Network error during upload'));
                    xhr.send(formData);
                });

                if (messageInput) messageInput.value = '';
                removeAttachment();
                clearReply();
            } else {
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
                        reply_to_message_id: replyToId,
                        csrf_token: csrfToken,
                    }),
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    alert(data.error || 'Failed to send message.');
                    return;
                }

                if (messageInput) messageInput.value = '';
                clearReply();
            }

            // Immediately fetch new message
            await pollNewMessages();
            // Refresh conversation preview in sidebar
            loadConversations();
        } catch (err) {
            alert('Failed to send transmission: ' + err.message);
        } finally {
            if (messageInput) {
                messageInput.disabled = false;
                messageInput.focus();
            }
            if (sendBtn) sendBtn.disabled = false;
            if (attachBtn) attachBtn.disabled = false;
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
