// Local Chat Logic using SSE (Server-Sent Events)
// Updated by Twilight Sparkle

$(document).ready(function() {
    const chatMessages = document.getElementById('chat-messages');
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');

    // === Notification Logic (Title Alert) ===
    
    // Default Settings
    const defaultSettings = {
        chat_title_enabled: 'true'
    };

    // Load Settings from DB (injected via PHP) or fallback to defaults
    // window.userOptions is populated in index.php
    const userOpts = window.userOptions || {};
    
    let titleAlertEnabled = (userOpts.chat_title_enabled !== undefined) ? (userOpts.chat_title_enabled === 'true') : (defaultSettings.chat_title_enabled === 'true');

    // Helper to save option
    function saveOption(key, value) {
        if (!window.currentUserId) return; // Only for logged in users
        
        $.post('api.php', {
            action: 'save_user_option',
            key: key,
            value: value
        });
    }

    // State
    let originalTitle = document.title;
    let unreadCount = 0;
    let windowFocused = true;
    let titleInterval = null;

    // Track Window Focus
    $(window).on('focus', function() {
        windowFocused = true;
        unreadCount = 0;
        if (titleInterval) {
            clearInterval(titleInterval);
            titleInterval = null;
        }
        document.title = originalTitle;
    }).on('blur', function() {
        windowFocused = false;
    });

    function toggleTitleAlert(enable) {
        titleAlertEnabled = enable;
        saveOption('chat_title_enabled', enable ? 'true' : 'false');
        updateNotificationUI();
    }

    // Update UI
    function updateNotificationUI() {
        const titleBtn = $('#toggle-title-alert');
        
        titleBtn.text(titleAlertEnabled ? '🔔' : '🔕');
        titleBtn.toggleClass('active', titleAlertEnabled);

        $('#profile-title-toggle').prop('checked', titleAlertEnabled);
    }
    
    // Blink Title
    function blinkTitle() {
        if (!titleAlertEnabled || windowFocused) return;
        
        unreadCount++;
        
        if (!titleInterval) {
            let isOriginal = false;
            titleInterval = setInterval(() => {
                document.title = isOriginal ? originalTitle : `(${unreadCount}) Новые сообщения!`;
                isOriginal = !isOriginal;
            }, 1000);
        }
    }

    // Bind Events
    $('#toggle-title-alert').on('click', function() { toggleTitleAlert(!titleAlertEnabled); });
    $('#profile-title-toggle').on('change', function() { toggleTitleAlert(this.checked); });

    // Initialize UI immediately
    updateNotificationUI();
    
    // Auto-scroll helper
    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // Current User Data (Populated by PHP in global scope or via API check)
    const currentUserId = window.currentUserId || null;
    const currentUserRole = window.currentUserRole || null; // 'admin' or 'user'
    
    // Calculate Clock Skew
    // window.serverTime is PHP time() (seconds)
    // Date.now() is Client time (milliseconds)
    // skew = ServerTime - ClientTime
    let timeSkew = 0;
    if (window.serverTime) {
        const clientNowSeconds = Math.floor(Date.now() / 1000);
        timeSkew = window.serverTime - clientNowSeconds;
    }
    
    // Store Page Load Server Time to filter history notifications
    // We add a small buffer (e.g. 1 sec) so we don't miss messages arriving *right now*
    const startClientTime = Date.now() / 1000;
    window.chatStartServerTime = startClientTime + timeSkew;

    // Helper for Chat Notifications
    function showChatNotification(message, type = 'info') {
        const area = $('#chat-notification-area');
        if (!area.length) {
            // Fallback to global flash message if area not found
            if (window.showFlashMessage) window.showFlashMessage(message, type);
            return;
        }

        // Remove existing to prevent overlap (since absolute positioned)
        area.empty();

        const notification = $('<div class="chat-notification"></div>')
            .addClass(type)
            .text(message);
        
        area.append(notification);
        notification.fadeIn(200);

        // Auto hide
        setTimeout(() => {
            notification.fadeOut(300, () => {
                notification.remove();
            });
        }, 3000);

        // Click to close
        notification.on('click', function() {
            $(this).remove();
        });
    }

    // Append message to UI
    function createMessageElement(data) {
        const div = document.createElement('div');
        div.className = 'chat-message';
        div.dataset.id = data.id; // Store message ID
        div.dataset.userId = data.user_id; // Store User ID for moderation
        div.dataset.userRole = data.role || 'user'; // Store User Role for moderation check
        div.dataset.timestamp = data.created_at; // Store ISO timestamp for calculations

        // Check for Mention
        // Use window.currentUserNickname (preferred) or window.currentUsername
        // We check for @Nickname (case insensitive or exact?) - usually exact or loose.
        // Let's try exact match with @ prefix.
        const myNick = window.currentUserNickname || window.currentUsername;
        if (myNick) {
            // Backend sends escaped HTML, so we might need to match escaped nick? 
            // Usually username is simple, but let's be careful.
            // Search in data.message content.
            // The message might contain HTML tags (spans), so simple includes might fail if name is split (unlikely).
            // Safer: regex for @MyNick\b
            const mentionRegex = new RegExp(`@${escapeRegExp(myNick)}\\b`, 'i');
            // We check the raw message if possible, but data.message is processed HTML.
            // It should contain <span class="md-mention">@Nick</span>
            // So we check text content? No, data.message is HTML string.
            if (mentionRegex.test(data.message)) {
                div.classList.add('message-mentioned');
            }
        }
        
        // Format time (HH:MM)
        // Since backend now sends ISO 8601 (e.g. 2023-10-27T10:00:00Z), we can parse it directly
        const date = new Date(data.created_at);
        const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        
        // Color & Avatar
        const colorStyle = data.chat_color ? `style="color: ${escapeHtml(data.chat_color)}"` : '';
        let avatarUrl = data.avatar_url ? escapeHtml(data.avatar_url) : '/assets/img/default-avatar.png';
        // Fix for old relative paths or just filenames being treated as uploads
        if (avatarUrl === 'default-avatar.png') avatarUrl = '/assets/img/default-avatar.png';
        
        const avatarHtml = `<img src="${avatarUrl}" class="chat-avatar" alt="">`;

        // Format message (handles quotes and fixes double escaping)
        // Backend already uses htmlspecialchars(), so data.message is safe HTML entities.
        function formatMessage(text) {
            // Stickers Logic 🦄
            // window.stickerMap is injected in index.php { code: url, ... }
            if (window.stickerMap) {
                // We use a regex to match :code:
                // Note: text is already HTML escaped, so special chars are entities. 
                // But colons are safe.
                // We assume codes are alphanumeric + underscore.
                text = text.replace(/:([a-zA-Z0-9_]+):/g, function(match, code) {
                    if (window.stickerMap[code]) {
                        return `<img src="${window.stickerMap[code]}" class="chat-sticker" alt=":${code}:" title=":${code}:">`;
                    }
                    return match;
                });
            }

            // New lines
            return text.replace(/\n/g, '<br>');
        }
        
        // Quoted Cards HTML
        let quotesHtml = '';
        if (data.quotes && data.quotes.length > 0) {
            data.quotes.forEach(q => {
                 let qAvatar = q.avatar_url ? escapeHtml(q.avatar_url) : '/assets/img/default-avatar.png';
                 if (qAvatar === 'default-avatar.png') qAvatar = '/assets/img/default-avatar.png';
                 
                 const qColor = q.chat_color ? `style="color: ${escapeHtml(q.chat_color)}"` : '';
                 let qContent = escapeHtml(q.message || '');
                 if (q.deleted) {
                     qContent = '<em style="color:#999;">Сообщение удалено</em>';
                 }
                 
                 quotesHtml += `
                    <div class="quote-card" data-id="${q.id}">
                        <div class="quote-header">
                            <span class="quote-author" ${qColor}>${escapeHtml(q.username)}</span>
                            <div style="display:flex; gap:5px; align-items:center;">
                                <span>${new Date(q.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                                <a href="#" class="quote-link-btn" title="Перейти к сообщению" data-target-id="${q.id}">🔗</a>
                            </div>
                        </div>
                        <div class="quote-content">${qContent}</div>
                    </div>
                 `;
            });
        }

        // Actions (Edit/Delete/Quote)
        let actionsHtml = '';
        const isMyMessage = (currentUserId && parseInt(data.user_id) === parseInt(currentUserId));
        const canModerate = (currentUserRole === 'admin' || currentUserRole === 'moderator');
        
        // Target Role logic for Moderation
        const targetRole = data.role || 'user';
        let canPunish = false;
        
        if (canModerate && !isMyMessage) {
            if (currentUserRole === 'admin') {
                // Admin can punish anyone EXCEPT other admins
                if (targetRole !== 'admin') {
                    canPunish = true; 
                }
            } else if (currentUserRole === 'moderator') {
                // Moderator can punish only normal users
                canPunish = (targetRole === 'user');
            }
        }

        // Quote button (Available for everyone if not deleted)
        if (!data.deleted) {
            actionsHtml += `<button class="chat-action-btn quote-btn" title="Цитировать">❝</button>`;
        }

        // Fix for Timezone Diff using Skew:
        // We want to compare Message Time vs Server Time (calculated via client time + skew)
        // Message Date (object) is correct local time (from UTC).
        // Corrected Now = Date.now() + skew*1000
        const correctedNow = new Date(Date.now() + (timeSkew * 1000));
        const diffMinutes = (correctedNow - date) / 1000 / 60;
        
        // Check 10 mins limit (allow small negative drift -1m just in case)
        const isRecent = diffMinutes >= -1 && diffMinutes < 10; 

        if ((isMyMessage && isRecent) || canModerate) {
            // Edit only for self and recent
            if (isMyMessage && isRecent) {
                actionsHtml += `<button class="chat-action-btn edit-btn" title="Редактировать">✎</button>`;
            }
            // Delete for self or Admin (ALWAYS allowed for self)
            if (isMyMessage || canModerate) {
                actionsHtml += `<button class="chat-action-btn delete-btn" title="Удалить">🗑</button>`;
            }
        } else if (isMyMessage) {
            // Even if not recent, allow delete for self
             actionsHtml += `<button class="chat-action-btn delete-btn" title="Удалить">🗑</button>`;
        }

        // Add Mod Menu Button if allowed to punish
        if (canPunish) {
             actionsHtml += `<button class="chat-action-btn mod-menu-btn" title="Модерация">⚡</button>`;
        }

        let editedMark = '';
        if (data.edited_at) {
            const editDate = new Date(data.edited_at);
            editedMark = `<span class="edited-mark" title="Отредактировано: ${editDate.toLocaleString()}">(изм.)</span>`;
        }
        
        // Debug display inside message (Temporary) - hidden now
        const debugInfo = ''; 

        // Deleted State Special Content
        if (data.deleted) {
             let restoreHtml = '';
             // Check if can restore: Admin OR (My Message AND Deleted recently)
             if (canModerate || isMyMessage) {
                 if (canModerate) {
                     // Admins can always restore
                     restoreHtml = `<button class="chat-action-btn restore-btn" title="Восстановить">↺</button>`;
                 } else if (data.deleted_at) {
                     // Users within 10 mins
                     const delDate = new Date(data.deleted_at);
                     // Using correctedNow for consistency
                     const delDiff = (correctedNow - delDate) / 1000 / 60;
                     // Logic: recently deleted (0-10m)
                     if (delDiff >= -1 && delDiff < 10) {
                         restoreHtml = `<button class="chat-action-btn restore-btn" title="Восстановить">↺</button>`;
                     }
                 }
             }

             div.innerHTML = `
                <div class="chat-avatar-wrapper">
                    ${avatarHtml}
                </div>
                <div class="chat-content">
                    <div class="chat-header">
                        <div class="user-info">
                            <span class="username" ${colorStyle}>${escapeHtml(data.username)}</span>
                            <span class="chat-actions">${restoreHtml}</span>
                        </div>
                        <span class="meta-info">
                            <span class="timestamp" title="${data.created_at}">${timeStr}</span>
                        </span>
                    </div>
                    ${quotesHtml}
                    <div class="chat-text" style="color:#999; font-style:italic;">
                        Сообщение удалено
                    </div>
                </div>
            `;
            // Note: We return the div, we don't append it here anymore for createMessageElement
        } else {
            div.innerHTML = `
                <div class="chat-avatar-wrapper">
                    ${avatarHtml}
                </div>
                <div class="chat-content">
                    <div class="chat-header">
                        <div class="user-info">
                            <span class="username" ${colorStyle}>${escapeHtml(data.username)}</span>
                            <span class="chat-actions">${actionsHtml}</span>
                        </div>
                        <span class="meta-info">
                            <span class="timestamp" title="${data.created_at}">${timeStr}</span>
                        </span>
                    </div>
                    ${quotesHtml}
                    <div class="chat-text">
                        ${formatMessage(data.message)} ${editedMark} ${debugInfo}
                    </div>
                </div>
            `;
        }
        return div;
    }

    function appendMessage(data) {
        const div = createMessageElement(data);
        chatMessages.appendChild(div);
        scrollToBottom();
        
        // Wait for images to load then scroll again
        const images = div.querySelectorAll('img');
        if (images.length > 0) {
            let loaded = 0;
            const total = images.length;
            const onImgLoad = () => {
                loaded++;
                // Scroll on every image load or just at end? 
                // Better on every load to smooth out if multiple large images
                scrollToBottom(); 
            };
            
            images.forEach(img => {
                if (img.complete) {
                    onImgLoad();
                } else {
                    img.addEventListener('load', onImgLoad);
                    img.addEventListener('error', onImgLoad); // Handle error too so we don't hang
                }
            });
        }
    }


    // Helper for Chat Confirmation
    function showChatConfirmation(text, onConfirm) {
        const overlay = $('#chat-confirmation-overlay');
        const msg = $('#chat-confirm-text');
        const yesBtn = $('#chat-confirm-yes');
        const noBtn = $('#chat-confirm-no');

        if (!overlay.length) {
            // Fallback if overlay is missing
            if (confirm(text)) onConfirm();
            return;
        }

        msg.text(text);
        overlay.css('display', 'flex').hide().fadeIn(200);

        // Remove previous handlers
        yesBtn.off('click');
        noBtn.off('click');

        yesBtn.on('click', function() {
            overlay.fadeOut(200);
            onConfirm();
        });

        noBtn.on('click', function() {
            overlay.fadeOut(200);
        });
    }

    // Helper for Chat Input (Ban/Mute/Purge)
    function showChatInput(title, desc, type, onConfirm) {
        const overlay = $('#chat-input-overlay');
        if (!overlay.length) return;

        $('#chat-input-title').text(title);
        $('#chat-input-desc').text(desc);
        $('#chat-input-reason').val(''); // Clear input
        
        // Reset visibility
        $('#chat-input-mute-opts').hide();
        $('#chat-input-purge-opts').hide();
        $('#chat-input-reason').show(); // Show by default

        if (type === 'mute') {
            $('#chat-input-mute-opts').show();
        } else if (type === 'purge') {
            $('#chat-input-purge-opts').show();
            $('#chat-input-reason').hide(); // No reason for purge needed usually, or use as optional?
            // API doesn't take reason for purge currently, so hide it.
        }

        overlay.css('display', 'flex').hide().fadeIn(200);

        const submitBtn = $('#chat-input-submit');
        const cancelBtn = $('#chat-input-cancel');

        submitBtn.off('click').on('click', function() {
            const reason = $('#chat-input-reason').val().trim() || 'Нарушение правил';
            const minutes = type === 'mute' ? $('#chat-mute-time').val() : null;
            const count = type === 'purge' ? $('#chat-purge-count').val() : null;
            
            overlay.fadeOut(200);
            onConfirm(reason, minutes, count);
        });

        cancelBtn.off('click').on('click', function() {
            overlay.fadeOut(200);
        });
    }

    // 11. Context Menu (Right Click)
    const contextMenu = $('#chat-context-menu');
    let contextTargetId = null;
    let contextTargetUsername = null;
    let contextTargetUserId = null;

    if (contextMenu.length) {
        // Show Menu
        function showContextMenu(e, targetMsgEl) {
            e.preventDefault();
            e.stopPropagation(); // Prevent propagation

            contextTargetId = targetMsgEl.data('id');
            contextTargetUsername = targetMsgEl.find('.username').text();
            contextTargetUserId = targetMsgEl.data('userId');

            // Position
            let x = e.pageX;
            let y = e.pageY;
            
            // Hide Punish options if not allowed
            const targetRole = targetMsgEl.data('userRole') || 'user';
            const msgUserId = targetMsgEl.data('userId'); // Ensure we get UserID from DOM
            const isSelf = (currentUserId && msgUserId == currentUserId);
            const isMyMessage = isSelf; // Alias for clarity

            // Check Time Limit for Edit/Delete (10 mins)
            // We need created_at. Let's store it in data attribute or parse from time element?
            // Parsing time element is unreliable (formatted).
            // Let's rely on data attribute or just allow click and let server reject? 
            // Better UX: check time.
            // We don't have raw timestamp in DOM. Let's add it in createMessageElement!
            const rawTime = targetMsgEl.data('timestamp'); 
            let isRecent = false;
            if (rawTime) {
                const msgDate = new Date(rawTime);
                const correctedNow = new Date(Date.now() + (timeSkew * 1000));
                const diffMinutes = (correctedNow - msgDate) / 1000 / 60;
                isRecent = diffMinutes >= -1 && diffMinutes < 10;
            }

            // --- User Actions (Edit/Delete) ---
            const canEdit = (isMyMessage && isRecent);
            
            // Hierarchy Check for Delete
            let canDelete = isMyMessage; // Owner always can (if logic permits, e.g. recent?) - backend checks owner rights separately
            if (!isMyMessage) {
                if (currentUserRole === 'admin') {
                     if (targetRole !== 'admin') canDelete = true;
                } else if (currentUserRole === 'moderator') {
                     if (targetRole === 'user') canDelete = true;
                }
            }
            
            if (canEdit) {
                contextMenu.find('[data-action="edit"]').show();
            } else {
                contextMenu.find('[data-action="edit"]').hide();
            }

            if (canDelete) {
                contextMenu.find('[data-action="delete"]').show();
            } else {
                contextMenu.find('[data-action="delete"]').hide();
            }

            // --- Moderation Actions ---
            let canPunish = false;
            if (!isSelf) {
                if (currentUserRole === 'admin') {
                    if (targetRole !== 'admin') canPunish = true;
                } else if (currentUserRole === 'moderator') {
                    if (targetRole === 'user') canPunish = true;
                }
            }
            
            if (canPunish) {
                contextMenu.find('.mod-only').show(); // Using class for cleaner selection
            } else {
                contextMenu.find('.mod-only').hide();
            }

            // Temporarily show to calculate dimensions (after hiding items)
            contextMenu.css({ visibility: 'hidden', display: 'block' });
            const menuHeight = contextMenu.outerHeight();
            const menuWidth = contextMenu.outerWidth();
            contextMenu.css({ visibility: 'visible', display: 'none' }); // Hide again properly

            const windowHeight = $(window).height();
            const windowWidth = $(window).width();
            const scrollTop = $(window).scrollTop();

            // Vertical Flip
            if ((y - scrollTop + menuHeight) > windowHeight) {
                // If triggered by button, move ABOVE button
                if ($(e.target).hasClass('mod-menu-btn')) {
                     y = $(e.target).offset().top - menuHeight;
                } else {
                     y -= menuHeight;
                }
            }
            
            // Horizontal Flip (if needed, though unlikely for chat width)
            if ((x + menuWidth) > windowWidth) {
                x -= menuWidth;
            }

            contextMenu.css({
                top: y + 'px',
                left: x + 'px'
            }).show();
        }

        $(document).on('contextmenu', '.chat-message', function(e) {
            showContextMenu(e, $(this));
        });

        // Handle Mod Button Click
        $(document).on('click', '.mod-menu-btn', function(e) {
            showContextMenu(e, $(this).closest('.chat-message'));
        });

        // Hide Menu
        $(document).on('click', function(e) {
            // If click is not on menu, hide
            if (!$(e.target).closest('#chat-context-menu').length) {
                contextMenu.hide();
            }
        });

        // Menu Actions
        contextMenu.on('click', 'li', function() {
            const action = $(this).data('action');
            if (!action) return;
            
            contextMenu.hide(); // Hide immediately
            
            if (!contextTargetId) return;

            switch(action) {
                case 'quote':
                    // Trigger existing quote logic
                    $(`.chat-message[data-id="${contextTargetId}"] .quote-btn`).click();
                    break;
                case 'edit':
                    // Manually trigger edit logic (since button might be hidden or not rendered if we rely on menu)
                    // Or simulate click if button exists?
                    // Better reuse logic.
                    {
                        const msgDiv = $(`.chat-message[data-id="${contextTargetId}"]`);
                        let text = msgDiv.find('.chat-text').clone().children().remove().end().text().trim();
                        if (chatInput) {
                            chatInput.value = text;
                            chatInput.focus();
                            chatInput.dataset.editingId = contextTargetId;
                            chatInput.classList.add('editing-mode');
                            const submitBtn = chatForm.querySelector('button');
                            if (submitBtn) submitBtn.textContent = 'Сохранить';
                        }
                    }
                    break;
                case 'reply':
                    // Just mention
                    if (chatInput && contextTargetUsername) {
                        chatInput.value += `@${contextTargetUsername} `;
                        chatInput.focus();
                    }
                    break;
                case 'delete':
                    // Trigger delete logic
                    $(`.chat-message[data-id="${contextTargetId}"] .delete-btn`).click();
                    break;
                case 'purge':
                    showChatInput(`Purge: ${contextTargetUsername}`, 'Удалить последние сообщения:', 'purge', function(reason, minutes, count) {
                        $.post('api.php', { 
                            action: 'purge_messages', 
                            user_id: contextTargetUserId,
                            count: count || 50
                        }, function(res) {
                            showChatNotification(res.message, res.success ? 'success' : 'error');
                        }, 'json');
                    });
                    break;
                case 'ban':
                    showChatInput(`Бан: ${contextTargetUsername}`, 'Укажите причину бана:', 'ban', function(reason) {
                        $.post('api.php', { 
                            action: 'ban_user', 
                            user_id: contextTargetUserId, 
                            reason: reason 
                        }, function(res) {
                            showChatNotification(res.message, res.success ? 'success' : 'error');
                        }, 'json');
                    });
                    break;
                case 'mute':
                    showChatInput(`Мут: ${contextTargetUsername}`, 'Выберите срок и причину:', 'mute', function(reason, minutes) {
                        $.post('api.php', { 
                            action: 'mute_user', 
                            user_id: contextTargetUserId, 
                            minutes: minutes,
                            reason: reason
                        }, function(res) {
                            showChatNotification(res.message, res.success ? 'success' : 'error');
                        }, 'json');
                    });
                    break;
            }
        });
    }

    // === Chat Connection Logic (Centrifugo / SSE) ===
    
    // Helper to update online counter UI
    function updateOnlineCounter(count, users) {
        const counterEl = document.getElementById('online-counter');
        if (counterEl) {
            counterEl.textContent = `(${count})`;
            if (users && users.length > 0) {
                const names = users.map(u => u.nickname).join(', ');
                counterEl.title = "Онлайн: " + names;
            } else {
                counterEl.title = "Никого нет... 👻";
            }
        }
    }

    // Unified Message Handler
    function processIncomingData(data) {
        // Handle specialized events
        if (data.type === 'delete') {
             const existingMsg = document.querySelector(`.chat-message[data-id="${data.id}"]`);
             if (existingMsg) {
                 existingMsg.classList.add('deleted');
                 const content = existingMsg.querySelector('.chat-content');
                 if(content) content.innerHTML = '<em style="color:#999;">Сообщение удалено</em>';
             }
             return;
        }
        
        if (data.type === 'purge') {
            if (data.ids && Array.isArray(data.ids)) {
                data.ids.forEach(id => {
                    const el = document.querySelector(`.chat-message[data-id="${id}"]`);
                    if (el) {
                        el.classList.add('deleted');
                        const content = el.querySelector('.chat-content');
                        if(content) content.innerHTML = '<em style="color:#999;">Сообщение удалено</em>';
                    }
                });
            }
            return;
        }

        // Standard Message Object (New or Update)
        const existingMsg = document.querySelector(`.chat-message[data-id="${data.id}"]`);
        
        if (existingMsg) {
            const newMsg = createMessageElement(data);
            if (chatMessages.contains(existingMsg)) {
                chatMessages.replaceChild(newMsg, existingMsg);
            } else {
                chatMessages.appendChild(newMsg);
            }
        } else {
            appendMessage(data);

            // Notification Logic
            const msgDate = new Date(data.created_at);
            const msgTime = msgDate.getTime() / 1000;
            if (msgTime > window.chatStartServerTime && data.user_id != currentUserId) {
                blinkTitle();
            }
        }
    }

    // Helper to fetch initial history (needed for Centrifugo)
    let oldestMessageId = null;
    let isLoadingHistory = false;

    function addLoadMoreButton() {
        if ($('#load-more-btn').length === 0) {
            const btn = $('<button id="load-more-btn" class="chat-load-more-btn" style="width:100%; padding:8px; background:none; border:none; color:#6d2f8e; cursor:pointer; font-size:0.9em;">⬆ Загрузить еще...</button>');
            btn.on('click', loadMoreHistory);
            $(chatMessages).prepend(btn);
        }
    }

    function loadMoreHistory() {
        if (isLoadingHistory || !oldestMessageId) return;
        isLoadingHistory = true;
        const btn = $('#load-more-btn');
        const originalText = btn.text();
        btn.text('Загрузка...');

        // Save scroll height before insert
        const oldScrollHeight = chatMessages.scrollHeight;
        const oldScrollTop = chatMessages.scrollTop;

        $.post('api.php', { action: 'get_messages', limit: 20, before_id: oldestMessageId }, function(res) {
            isLoadingHistory = false;
            btn.text(originalText);
            
            if (res.success && res.data && res.data.messages && res.data.messages.length > 0) {
                // Messages come oldest -> newest in the batch.
                // Example: Batch [100, 101... 119], Current Top is 120.
                // We want: [Button] -> 100 -> 101 ... -> 119 -> 120
                
                // We need to insert them after the button, in order.
                // So we iterate and insertAfter(btn) but in REVERSE order?
                // No. If we insert 100 after btn -> [Btn, 100]
                // Then insert 101 after btn -> [Btn, 101, 100] - WRONG order.
                // So we should iterate normal order and insertBefore the *first message*.
                
                const firstMsg = $(chatMessages).find('.chat-message').first();
                
                // We must handle the batch so that the order is preserved.
                // Batch is [Oldest ... Newest]
                // We insert the Batch before FirstMsg.
                
                // Let's create a fragment
                const fragment = document.createDocumentFragment();
                res.data.messages.forEach(msg => {
                     if (!msg.type) msg.type = 'message';
                     const div = createMessageElement(msg);
                     fragment.appendChild(div);
                });
                
                if (firstMsg.length) {
                    firstMsg[0].parentNode.insertBefore(fragment, firstMsg[0]);
                } else {
                    // No messages yet? Append after button
                     chatMessages.appendChild(fragment);
                }

                // Update oldest ID (from the first message in the batch, which is the oldest)
                oldestMessageId = res.data.messages[0].id;

                // Restore scroll position
                // New height - Old height = delta
                const newScrollHeight = chatMessages.scrollHeight;
                chatMessages.scrollTop = newScrollHeight - oldScrollHeight + oldScrollTop;
                
                if (res.data.messages.length < 20) {
                    btn.hide();
                }
                
            } else {
                btn.hide();
                showChatNotification("Больше сообщений нет", 'info');
            }
        }, 'json').fail(() => {
            isLoadingHistory = false;
            btn.text(originalText);
        });
    }

    function fetchHistory() {
        $.post('api.php', { action: 'get_messages', limit: 20 }, function(res) {
            if (res.success && res.data && res.data.messages) {
                addLoadMoreButton();
                
                res.data.messages.forEach(msg => {
                    // Add type 'message' if missing
                    if (!msg.type) msg.type = 'message';
                    processIncomingData(msg);
                });
                
                if (res.data.messages.length > 0) {
                    oldestMessageId = res.data.messages[0].id;
                }
                
                // If we got fewer than limit, hide button
                if (res.data.messages.length < 20) {
                    $('#load-more-btn').hide();
                }
                
                // Adjust scroll after history load
                scrollToBottom();

                // Infinite Scroll Handler
                chatMessages.addEventListener('scroll', function() {
                    // Если скролл меньше 50px от верха, и мы не грузимся, и есть кнопка (значит есть история)
                    if (this.scrollTop < 50 && !isLoadingHistory && $('#load-more-btn').is(':visible')) {
                        loadMoreHistory();
                    }
                });
            }
        }, 'json');
    }

    // Initialize Connection
    const chatConfig = window.chatConfig || { driver: 'sse' };
    console.log("🦄 Chat Driver:", chatConfig.driver);

    if (chatConfig.driver === 'centrifugo') {
        // Load history first
        fetchHistory();

        if (window.Centrifuge) {
                const centrifuge = new Centrifuge(chatConfig.centrifugo.url, {
                    token: chatConfig.centrifugo.token
                });

                const sub = centrifuge.newSubscription("public:chat");

                sub.on('publication', function(ctx) {
                // Centrifugo sends data in ctx.data
                processIncomingData(ctx.data);
            });

            sub.subscribe();
            centrifuge.connect();
            console.log("✅ Centrifugo connected");
        } else {
            console.error("❌ Centrifuge library is missing!");
        }
    } else {
        // SSE Fallback (Default)
        console.log("🔌 Connecting via SSE...");
        const evtSource = new EventSource('/chat_stream.php');

        evtSource.addEventListener('online_count', function(e) {
            try {
                const data = JSON.parse(e.data);
                updateOnlineCounter(data.count, data.users);
            } catch (err) {
                console.error("Online parse error", err);
            }
        });

        evtSource.onmessage = function(e) {
            try {
                if (e.data === 'keepalive') return;
                const data = JSON.parse(e.data);
                processIncomingData(data);
            } catch (err) {
                console.error("Chat parse error", err);
            }
        };

        evtSource.onerror = function(e) {
            console.log("EventSource failed. Reconnecting...", e);
        };
    }

    // State for quoting
    let pendingQuotes = [];
    const quotePreviewArea = $('#quote-preview-area');
    
    // Update Quote Preview UI
    function updateQuotePreview() {
        quotePreviewArea.empty();
        if (pendingQuotes.length === 0) {
            quotePreviewArea.addClass('hidden').hide(); // Добавляем класс и скрываем
            return;
        }
        
        quotePreviewArea.removeClass('hidden').css('display', 'flex'); // Убираем класс (!) и ставим flex
        pendingQuotes.forEach(q => {
            const item = $(`
                <div class="quote-preview-item">
                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <b>${escapeHtml(q.username)}</b>: ${escapeHtml(q.text.substring(0, 50))}${q.text.length>50?'...':''}
                    </span>
                    <button class="quote-preview-remove" data-id="${q.id}" title="Убрать">&times;</button>
                </div>
            `);
            quotePreviewArea.append(item);
        });
    }

    // Handle Remove Quote
    $(document).on('click', '.quote-preview-remove', function() {
        const id = $(this).data('id');
        pendingQuotes = pendingQuotes.filter(q => q.id != id);
        updateQuotePreview();
    });

    // Handle Quote Button
    $(document).on('click', '.quote-btn', function(e) {
        e.preventDefault();
        const msgDiv = $(this).closest('.chat-message');
        const msgId = msgDiv.attr('data-id');
        const username = msgDiv.find('.username').text().trim();
        
        // Get text, remove .edited-mark or other children if any
        let text = msgDiv.find('.chat-text').clone().children().remove().end().text().trim();
        
        // Avoid duplicate quotes
        if (!pendingQuotes.find(q => q.id == msgId)) {
            pendingQuotes.push({ id: msgId, username: username, text: text });
            updateQuotePreview();
            chatInput.focus();
        }
    });

    // Handle Link to Message
    $(document).on('click', '.quote-link-btn', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Stop card expand
        
        const targetId = $(this).data('targetId');
        const targetEl = $(`.chat-message[data-id="${targetId}"]`);
        
        if (targetEl.length) {
            targetEl[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetEl.addClass('highlight-message');
            setTimeout(() => targetEl.removeClass('highlight-message'), 2000);
        } else {
            showChatNotification("Сообщение не найдено (возможно, оно слишком старое)", 'info');
        }
    });

    // Handle Click on Quote Card (Expand)
    $(document).on('click', '.quote-card', function() {
        const content = $(this).find('.quote-content');
        if (content.hasClass('expanded')) {
            content.removeClass('expanded');
        } else {
            content.addClass('expanded');
        }
    });

    // --- Mobile Compact Mode Logic ---
    const mobileFab = $('#chat-mobile-fab');
    const mobileOverlay = $('#chat-mobile-input-overlay');
    const mobileInput = $('#chat-mobile-input');
    const mobileClose = $('#chat-mobile-close');
    const mobileForm = $('#chat-mobile-form');
    const mobileStickerBtn = $('#mobile-sticker-btn');
    const mobileUploadBtn = $('#mobile-upload-btn');

    // Helper to get active input
    function getActiveInput() {
        if (mobileOverlay.is(':visible')) {
            return document.getElementById('chat-mobile-input');
        }
        return document.getElementById('chat-input');
    }

    if (mobileFab.length) {
        mobileFab.click(function() {
            mobileOverlay.css('display', 'flex').hide().fadeIn(200);
            mobileInput.focus();
        });

        function closeMobileModal() {
            mobileOverlay.fadeOut(200);
            // Move picker back to desktop if needed
            if ($('#sticker-picker').parent().is('.chat-mobile-input-box')) {
                $('#sticker-picker').hide().appendTo('.chat-input-area');
            }
        }

        mobileClose.click(closeMobileModal);

        mobileOverlay.click(function(e) {
            if (e.target === this) {
                closeMobileModal();
            }
        });

        // Sync Mobile Send
        mobileForm.on('submit', function(e) {
            e.preventDefault();
            const text = mobileInput.val().trim();
            if (text && chatInput) {
                // Transfer to main input and submit
                chatInput.value = text;
                const event = new Event('submit', { cancelable: true });
                document.getElementById('chat-form').dispatchEvent(event);
                
                mobileInput.val('');
                closeMobileModal();
            }
        });

        // Mobile Sticker Button
        mobileStickerBtn.click(function(e) {
            e.stopPropagation();
            const picker = $('#sticker-picker');
            
            if (picker.is(':visible') && picker.parent().is('.chat-mobile-input-box')) {
                picker.fadeOut(100);
            } else {
                if (!stickersInitialized) initStickerPicker();
                // Move picker to mobile modal
                picker.hide().appendTo('.chat-mobile-input-box').fadeIn(200);
            }
        });

        // Close sticker picker in mobile
        $('.sticker-close-btn').click(function() {
            $('#sticker-picker').fadeOut(100);
        });

        // Mobile Upload Button
        mobileUploadBtn.click(function(e) {
            e.preventDefault();
            if (fileInput) fileInput.click();
        });
    }

    // 2. Handle Send Message
    if (chatForm) {
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const message = chatInput.value.trim();
            if (!message) return;

            // Check if we are in "Edit Mode"
            const editingId = chatInput.dataset.editingId;
            const action = editingId ? 'edit_message' : 'send_message';
            const data = {
                action: action,
                message: message
            };
            if (editingId) data.message_id = editingId;
            
            // Add Quoted IDs
            if (pendingQuotes.length > 0 && !editingId) {
                 const ids = pendingQuotes.map(q => q.id);
                 data.quoted_msg_ids = ids.join(',');
            }

            const oldVal = chatInput.value;
            chatInput.value = '';
            
            // Clear quotes locally
            const oldQuotes = [...pendingQuotes];
            pendingQuotes = [];
            updateQuotePreview();

            // Clear editing state visual
            if (editingId) {
                chatInput.removeAttribute('data-editing-id');
                chatForm.querySelector('button').textContent = 'Отправить';
                chatInput.classList.remove('editing-mode');
            }

            $.ajax({
                url: 'api.php',
                method: 'POST',
                data: data,
                success: function(response) {
                    if (!response.success) {
                        showChatNotification(response.message, 'error');
                        chatInput.value = oldVal; // Restore if failed
                        pendingQuotes = oldQuotes; // Restore quotes
                        updateQuotePreview();
                    }
                },
                error: function() {
                    showChatNotification("Ошибка сети. Попробуй позже.", 'error');
                    chatInput.value = oldVal;
                    pendingQuotes = oldQuotes;
                    updateQuotePreview();
                }
            });
        });
    }

    // 2.5 Handle Message Actions (Event Delegation)
    $(document).on('click', '.edit-btn', function(e) {
        e.preventDefault();
        const msgDiv = $(this).closest('.chat-message');
        const msgId = msgDiv.attr('data-id');
        // Get text from .chat-text, excluding .edited-mark
        let text = msgDiv.find('.chat-text').clone().children().remove().end().text().trim();
        
        // Set input to edit mode
        if (chatInput) {
            chatInput.value = text;
            chatInput.focus();
            chatInput.dataset.editingId = msgId;
            chatInput.classList.add('editing-mode');
            const submitBtn = chatForm.querySelector('button');
            if (submitBtn) submitBtn.textContent = 'Сохранить';
        }
    });

    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        const msgDiv = $(this).closest('.chat-message');
        const msgId = msgDiv.attr('data-id');

        showChatConfirmation('Удалить сообщение?', function() {
            $.post('api.php', { action: 'delete_message', message_id: msgId }, function(res) {
                if(!res.success) {
                    showChatNotification(res.message, 'error');
                }
            }, 'json');
        });
    });

    $(document).on('click', '.restore-btn', function(e) {
        e.preventDefault();
        const msgDiv = $(this).closest('.chat-message');
        const msgId = msgDiv.attr('data-id');

        showChatConfirmation('Восстановить сообщение?', function() {
            $.post('api.php', { action: 'restore_message', message_id: msgId }, function(res) {
                if(!res.success) {
                    showChatNotification(res.message, 'error');
                }
            }, 'json');
        });
    });

    // 3. Auth Modal Logic moved to main.js


    // Logout Logic
    $('#logout-form').on('submit', function(e) {
        e.preventDefault();
        $.post('api.php', $(this).serialize(), function(res) {
            if (res.success) {
                location.reload();
            } else {
                if (window.showFlashMessage) window.showFlashMessage(res.message, 'error');
            }
        }, 'json').fail(function() {
             if (window.showFlashMessage) window.showFlashMessage('Ошибка сети :(', 'error');
        });
    });

    // 4. Profile Modal Logic
    const profileModal = $('#profile-modal');
    const profileForm = $('#ajax-profile-form');
    const profileError = $('#profile-error');

    // window.openProfileModal удален отсюда, так как он теперь в main.js

    $('.close-modal-profile').on('click', function() {
        profileModal.fadeOut(200);
    });

    $(window).on('click', function(e) {
        if ($(e.target).is(profileModal)) {
            profileModal.fadeOut(200);
        }
    });

    if (profileForm.length > 0) {
        profileForm.on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('button[type="submit"]');
            const originalText = btn.text();
            btn.prop('disabled', true).text('Сохранение...');
            profileError.hide();
            
            const formData = new FormData(this);

            $.ajax({
                url: 'api.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        if (res.data && res.data.reload) {
                            location.reload(); 
                        } else {
                            profileModal.fadeOut(200);
                            if (window.showFlashMessage) window.showFlashMessage("Профиль обновлен!", "success");
                            btn.prop('disabled', false).text(originalText);
                        }
                    } else {
                        profileError.text(res.message).show();
                        btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    profileError.text('Ошибка сервера :(').show();
                    btn.prop('disabled', false).text(originalText);
                }
            });
        });
    }

    // 5. Chat Toolbar Logic
    
    // --- Sticker Picker Logic 🦄 ---
    const stickerBtn = $('#sticker-btn');
    const stickerPicker = $('#sticker-picker');
    const stickerTabs = $('#sticker-tabs');
    const stickerGrid = $('#sticker-grid');
    let stickersInitialized = false;

    function initStickerPicker() {
        if (stickersInitialized) return;
        
        const data = window.stickerData || { packs: [], stickers: {} };
        const packs = data.packs;
        const stickersByPack = data.stickers;

        if (!packs || packs.length === 0) {
            stickerGrid.html('<div style="padding:10px; color:#999; text-align:center;">Нет стикеров :(</div>');
            return;
        }

        // Render Tabs
        stickerTabs.empty();
        packs.forEach((pack, index) => {
            const isActive = index === 0 ? 'active' : '';
            const iconUrl = pack.icon_url || '/assets/img/default-pack.png'; // Fallback?
            // Use name as fallback for icon text if no icon?
            const tabContent = pack.icon_url 
                ? `<img src="${escapeHtml(pack.icon_url)}" title="${escapeHtml(pack.name)}">`
                : `<span style="font-size:12px;">${escapeHtml(pack.name.substring(0, 3))}</span>`;
                
            const btn = $(`<button class="sticker-tab-btn ${isActive}" data-pack-id="${pack.id}">${tabContent}</button>`);
            
            btn.on('click', function(e) {
                e.preventDefault();
                $('.sticker-tab-btn').removeClass('active');
                $(this).addClass('active');
                renderStickerGrid(pack.id);
            });
            
            stickerTabs.append(btn);
        });

        // Render Initial Grid (First Pack)
        if (packs.length > 0) {
            renderStickerGrid(packs[0].id);
        }

        stickersInitialized = true;
    }

    // --- Sticker Zoom Preview Helpers ---
    const zoomPreview = $('#sticker-zoom-preview');
    const zoomImg = zoomPreview.find('img');
    let longPressTimer;
    let isLongPress = false;

    function showZoomPreview(src) {
        zoomImg.attr('src', src);
        zoomPreview.fadeIn(100);
        isLongPress = true;

        // --- Dynamic Positioning Logic ---
        const isMobile = window.innerWidth <= 768 || window.matchMedia('(pointer: coarse)').matches;
        
        if (isMobile) {
            // Mobile: Center Screen
            zoomPreview.css({
                top: '50%',
                left: '50%',
                transform: 'translate(-50%, -50%)',
                bottom: 'auto'
            });
        } else {
            // Desktop: Position Above Chat/Picker
            const chatContainer = document.querySelector('.chat-container');
            if (chatContainer) {
                const rect = chatContainer.getBoundingClientRect();
                // Center horizontally relative to chat, sit above picker area (~300px from bottom)
                const leftPos = rect.left + (rect.width / 2);
                const bottomOffset = 280; // Sticker picker height + margin
                const topPos = rect.bottom - bottomOffset;

                zoomPreview.css({
                    top: topPos + 'px',
                    left: leftPos + 'px',
                    transform: 'translate(-50%, -100%)', // Anchor bottom-center
                    bottom: 'auto'
                });
            }
        }
    }

    function hideZoomPreview() {
        zoomPreview.fadeOut(100);
        // Не сбрасываем isLongPress здесь сразу, чтобы обработчик mouseup мог понять, что произошло
    }

    function renderStickerGrid(packId) {
        stickerGrid.empty();
        const stickers = (window.stickerData.stickers[packId] || []);
        
        if (stickers.length === 0) {
            stickerGrid.html('<div style="padding:10px; color:#999; text-align:center;">Пусто...</div>');
            return;
        }

        stickers.forEach(s => {
            const img = $(`<img src="${escapeHtml(s.image_url)}" class="picker-sticker" title=":${escapeHtml(s.code)}:" draggable="false">`);
            
            // --- Smart Click/Hold Logic ---
            let startX = 0;
            let startY = 0;
            let startScrollTop = 0;
            let isDragging = false;
            
            // 1. Mouse/Touch Down: Start Timer
            img.on('mousedown touchstart', function(e) {
                isLongPress = false;
                isDragging = false;
                
                if (e.type === 'touchstart') {
                    const touch = e.originalEvent.touches[0];
                    startX = touch.clientX;
                    startY = touch.clientY;
                    startScrollTop = stickerGrid.scrollTop();
                }
                
                longPressTimer = setTimeout(() => {
                    if (!isDragging) showZoomPreview(s.image_url);
                }, 300); // 300ms hold time
            });

            // 1.1 Touch Move: Check movement
            img.on('touchmove', function(e) {
                const touch = e.originalEvent.touches[0];
                if (Math.abs(touch.clientX - startX) > 10 || Math.abs(touch.clientY - startY) > 10) {
                     isDragging = true;
                     clearTimeout(longPressTimer);
                }
            });

            // 2. Mouse/Touch Up: Decide Action
            img.on('mouseup touchend', function(e) {
                clearTimeout(longPressTimer); // Cancel timer if fast tap
                
                // Double check drag for touchend
                if (e.type === 'touchend') {
                    const touch = e.originalEvent.changedTouches[0];
                    const currentScrollTop = stickerGrid.scrollTop();
                    
                    if (Math.abs(touch.clientX - startX) > 10 || 
                        Math.abs(touch.clientY - startY) > 10 ||
                        Math.abs(currentScrollTop - startScrollTop) > 5) {
                        isDragging = true;
                    }
                }

                if (isDragging) {
                     if (e.cancelable) e.preventDefault(); // Stop mouse emulation
                     return; 
                }

                if (isLongPress) {
                    // It was a long press (preview shown) -> Just hide preview
                    hideZoomPreview();
                    isLongPress = false; // Reset
                    if (e.cancelable) e.preventDefault(); // Prevent ghost clicks
                } else {
                    // Fast tap -> Insert Sticker
                    insertSticker(s.code);
                    
                    // Mobile: Prevent phantom clicks/zoom
                    if (e.type === 'touchend' && e.cancelable) e.preventDefault(); 
                }
            });

            // 3. Mouse Leave / Touch Cancel: Abort everything
            img.on('mouseleave touchcancel', function() {
                clearTimeout(longPressTimer);
                if (isLongPress) {
                    hideZoomPreview();
                    isLongPress = false;
                }
            });
            
            // 4. Disable Context Menu on Stickers (to prevent menu on hold)
            img.on('contextmenu', function(e) {
                e.preventDefault();
                return false;
            });

            stickerGrid.append(img);
        });
    }

    function insertSticker(code) {
        const input = getActiveInput();
        if (!input) return;
        
        const codeStr = `:${code}: `; // Add space after
        
        // Insert at cursor
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const text = input.value;
        
        // Add space before if needed
        const prefix = (start > 0 && text[start-1] !== ' ') ? ' ' : '';
        
        input.value = text.substring(0, start) + prefix + codeStr + text.substring(end);
        
        input.focus();
        const newPos = start + prefix.length + codeStr.length;
        input.selectionStart = newPos;
        input.selectionEnd = newPos;
        
        // Hide picker on mobile? Or keep open for multi-select?
        // Close on mobile to return to keyboard
        if (input.id === 'chat-mobile-input') {
             $('#sticker-picker').fadeOut(100);
        }
    }

    // Toggle Picker
    if (stickerBtn.length) {
        stickerBtn.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (stickerPicker.is(':visible')) {
                stickerPicker.fadeOut(200);
            } else {
                if (!stickersInitialized) initStickerPicker();
                stickerPicker.fadeIn(200).css('display', 'flex'); // Flex for layout
            }
        });
    }

    // Close on click outside
    $(window).on('click', function(e) {
        if (stickerPicker.is(':visible')) {
            if (!$(e.target).closest('#sticker-picker').length && !$(e.target).closest('#sticker-btn').length) {
                stickerPicker.fadeOut(200);
            }
        }
    });

    $('.chat-format-btn').on('click', function(e) {
        if ($(this).attr('id') === 'sticker-btn') return; // Skip sticker btn handled above
        e.preventDefault();
        const format = $(this).data('format');
        const input = document.getElementById('chat-input'); 
        
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const text = input.value;
        let selectedText = text.substring(start, end);
        
        // NEW: Check for external selection if internal is empty
        // This allows selecting text in chat and clicking a button to copy & format it
        if (start === end) {
            const globalSel = window.getSelection().toString();
            if (globalSel && globalSel.trim().length > 0) {
                selectedText = globalSel.trim();
            }
        }
        
        let replacement = '';
        let newStart = start; // Cursor position after
        let newEnd = end;

        if (format === 'quote') {
             // Blockquote logic: prepend > to lines
             if (selectedText.length > 0) {
                 replacement = selectedText.split('\n').map(line => '> ' + line).join('\n');
             } else {
                 replacement = '> ';
             }
             // For quote, we usually want to select the whole block or place cursor at end?
             // Let's place cursor after the inserted text
             newStart = start + replacement.length;
             newEnd = newStart;
        } else {
            let startTag = '', endTag = '';
            switch(format) {
                case 'bold': startTag = '**'; endTag = '**'; break;
                case 'italic': startTag = '*'; endTag = '*'; break;
                case 'strike': startTag = '~~'; endTag = '~~'; break;
                case 'code': startTag = '`'; endTag = '`'; break;
                case 'spoiler': startTag = '||'; endTag = '||'; break;
            }
            replacement = startTag + selectedText + endTag;
            
            if (start === end) {
                // Empty selection: place cursor inside tags
                newStart = start + startTag.length;
                newEnd = newStart;
            } else {
                // Wrap selection: keep selection around text (including tags? or just text?)
                // Usually editors keep selection around the whole thing or just text.
                // Let's select the whole new block
                newStart = start;
                newEnd = start + replacement.length;
            }
        }
        
        input.value = text.substring(0, start) + replacement + text.substring(end);
        
        input.focus();
        input.selectionStart = newStart;
        input.selectionEnd = newEnd;
        
        // Trigger auto-resize
        input.dispatchEvent(new Event('input'));
    });

    // 9. Auto-resize Textarea & Handle Enter
    const chatTextarea = document.getElementById('chat-input');
    if (chatTextarea) {
        chatTextarea.addEventListener('input', function() {
            this.style.height = 'auto'; // Reset to re-calculate
            this.style.height = (this.scrollHeight) + 'px';
        });

        chatTextarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault(); // Prevent newline
                // Trigger submit
                const event = new Event('submit', { cancelable: true });
                document.getElementById('chat-form').dispatchEvent(event);
            }
        });
    }

    // 6. Handle Spoiler Reveal
    $(document).on('click', '.md-spoiler', function() {
        $(this).toggleClass('revealed');
    });

    // 7. Handle Mention Click (Insert into input)
    $(document).on('click', '.md-mention', function() {
        const username = $(this).text(); // Includes @
        if (chatInput) {
            chatInput.value += (chatInput.value ? ' ' : '') + username + ' ';
            chatInput.focus();
        }
    });

    // 8. Handle Username Click (Insert Mention)
    $(document).on('click', '.chat-message .username', function(e) {
        // Only if not holding modifier keys (to allow default selection if needed)
        if (e.ctrlKey || e.metaKey) return;
        
        const username = $(this).text().trim();
        if (chatInput && username) {
            // Clean username just in case
            const safeName = username.replace(/\s+/g, ' ');
            chatInput.value += (chatInput.value ? ' ' : '') + '@' + safeName + ' ';
            chatInput.focus();
        }
    });

    // 10. File Upload Logic
    const fileInput = document.getElementById('chat-file-input');
    const uploadBtn = document.getElementById('chat-upload-btn');
    // chatTextarea is defined above in section 9

    function uploadFile(file) {
        // Basic validation
        if (file.size > 30 * 1024 * 1024) {
             showChatNotification("Файл слишком большой (макс 30 МБ)", 'error');
             return;
        }

        const formData = new FormData();
        formData.append('action', 'upload_file');
        formData.append('file', file);
        
        // Show loading state?
        const activeInput = getActiveInput();
        const originalPlaceholder = activeInput ? activeInput.placeholder : '';
        if (activeInput) activeInput.placeholder = "Загрузка файла...";
        if (uploadBtn) {
            uploadBtn.textContent = '⏳';
            uploadBtn.disabled = true;
        }

        $.ajax({
            url: 'api.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    const name = res.data.name;
                    const url = res.data.url;
                    let markdown = '';
                    if (res.data.is_image) {
                        markdown = `![${name}](${url})`;
                    } else {
                        markdown = `[${name}](${url})`;
                    }
                    
                    if (activeInput) {
                        // Insert at cursor
                        const start = activeInput.selectionStart;
                        const end = activeInput.selectionEnd;
                        const text = activeInput.value;
                        
                        // Add newline if needed (not at start and not after newline)
                        const prefix = (start > 0 && text[start-1] !== '\n') ? ' ' : '';
                        
                        activeInput.value = text.substring(0, start) + prefix + markdown + text.substring(end);
                        
                        activeInput.focus();
                        // Move cursor after
                        const newPos = start + prefix.length + markdown.length;
                        activeInput.selectionStart = newPos;
                        activeInput.selectionEnd = newPos;
                        
                        // Trigger resize if it's the main textarea
                        if(activeInput.id === 'chat-input') {
                             activeInput.dispatchEvent(new Event('input'));
                        }
                    }
                } else {
                    showChatNotification(res.message, 'error');
                }
            },
            error: function() {
                showChatNotification("Ошибка загрузки файла.", 'error');
            },
            complete: function() {
                if (activeInput) activeInput.placeholder = originalPlaceholder;
                if (uploadBtn) {
                    uploadBtn.textContent = '📎';
                    uploadBtn.disabled = false;
                }
            }
        });
    }

    if (uploadBtn && fileInput) {
        uploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            fileInput.click();
        });

        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                uploadFile(this.files[0]);
                this.value = ''; // Reset
            }
        });
    }

    // Paste Handler
    if (chatTextarea) {
        chatTextarea.addEventListener('paste', function(e) {
            const items = (e.clipboardData || e.originalEvent.clipboardData).items;
            for (let i = 0; i < items.length; i++) {
                if (items[i].kind === 'file') {
                    e.preventDefault();
                    const file = items[i].getAsFile();
                    uploadFile(file);
                    return; // Upload first found file
                }
            }
        });
        
        // Drag & Drop
        const dropZone = document.querySelector('.chat-input-area');
        if (dropZone) {
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            dropZone.addEventListener('dragenter', () => dropZone.classList.add('highlight-drop'), false);
            dropZone.addEventListener('dragleave', () => dropZone.classList.remove('highlight-drop'), false);
            
            dropZone.addEventListener('drop', function(e) {
                dropZone.classList.remove('highlight-drop');
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files && files[0]) {
                    uploadFile(files[0]);
                }
            }, false);
        }
    }
});
