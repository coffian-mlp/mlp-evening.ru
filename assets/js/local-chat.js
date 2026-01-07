// Local Chat Logic using SSE (Server-Sent Events)

$(document).ready(function() {
    const chatMessages = document.getElementById('chat-messages');
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    
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
        
        // Format time (HH:MM)
        // Since backend now sends ISO 8601 (e.g. 2023-10-27T10:00:00Z), we can parse it directly
        const date = new Date(data.created_at);
        const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        
        // Color & Avatar
        const colorStyle = data.chat_color ? `style="color: ${escapeHtml(data.chat_color)}"` : '';
        const avatarUrl = data.avatar_url ? escapeHtml(data.avatar_url) : '/assets/img/default-avatar.png'; // Fallback image?
        const avatarHtml = `<img src="${avatarUrl}" class="chat-avatar" alt="">`;

        // Actions (Edit/Delete)
        let actionsHtml = '';
        const isMyMessage = (currentUserId && parseInt(data.user_id) === parseInt(currentUserId));
        const canModerate = (currentUserRole === 'admin' || currentUserRole === 'moderator');
        
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
                    <div class="chat-text">
                        ${escapeHtml(data.message)} ${editedMark} ${debugInfo}
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
    }

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
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

    // 1. Initialize SSE connection
    // We use Last-Event-ID automatically handled by browser/EventSource
    const evtSource = new EventSource('/chat_stream.php');

    evtSource.onmessage = function(e) {
        try {
            // Keepalive check
            if (e.data === 'keepalive') return;
            
            const data = JSON.parse(e.data);
            
            // Check if this message already exists in UI (for updates/edits)
            // Fix: Use a safer check. Some elements might be missing if we are not careful.
            const existingMsg = document.querySelector(`.chat-message[data-id="${data.id}"]`);
            if (existingMsg) {
                // Completely replace the element to handle state changes (Active <-> Deleted)
                // We must ensure newMsg is created successfully
                const newMsg = createMessageElement(data);
                // Check if existingMsg is still child of chatMessages (it should be, but let's be safe)
                if (chatMessages.contains(existingMsg)) {
                    chatMessages.replaceChild(newMsg, existingMsg);
                } else {
                    // Fallback: just append if it somehow got detached (unlikely)
                    chatMessages.appendChild(newMsg);
                }
            } else {
                appendMessage(data);
            }
        } catch (err) {
            console.error("Chat parse error", err);
        }
    };

    evtSource.onerror = function(e) {
        console.log("EventSource failed. Reconnecting...", e);
        // Browser handles reconnection automatically, but we can update UI state if needed
    };

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

            const oldVal = chatInput.value;
            chatInput.value = '';
            
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
                    }
                },
                error: function() {
                    showChatNotification("Ошибка сети. Попробуй позже.", 'error');
                    chatInput.value = oldVal;
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

    // 3. Auth Modal Logic (Login + Register)
    const loginLink = $('#login-link');
    const modal = $('#login-modal');
    const closeModal = $('.close-modal');
    const loginForm = $('#ajax-login-form');
    const loginError = $('#login-error');
    const registerForm = $('#ajax-register-form');
    const registerError = $('#register-error');

    // Global function to open modal
    window.openLoginModal = function(e) {
        if (e) e.preventDefault();
        modal.css('display', 'flex').hide().fadeIn(200);
    };

    if (modal.length > 0) {
        // Bind to existing link if present
        if (loginLink.length > 0) {
             loginLink.on('click', window.openLoginModal);
        }

        closeModal.on('click', function() {
            modal.hide();
        });
        
        // Close on click outside
        $(window).on('click', function(e) {
            if ($(e.target).is(modal)) {
                modal.hide();
            }
        });

        // Tab Switching
        $('.auth-tab-link').on('click', function(e) {
            e.preventDefault();
            
            // UI Update
            $('.auth-tab-link').removeClass('active').css({
                'border-bottom': 'none', 'color': '#999'
            });
            $(this).addClass('active').css({
                'border-bottom': '2px solid #6d2f8e', 'color': '#6d2f8e'
            });

            // Show Content
            const targetId = $(this).data('target');
            $('#login-form-wrapper, #register-form-wrapper').hide();
            $(targetId).fadeIn(200);
        });

        // Login Submit
        loginForm.on('submit', function(e) {
            e.preventDefault();
            const formData = $(this).serialize();
            const data = formData + '&action=login';

            $.ajax({
                url: 'api.php',
                method: 'POST',
                data: data,
                success: function(res) {
                    if (res.success) {
                        location.reload(); 
                    } else {
                        loginError.text(res.message).show();
                    }
                },
                error: function() {
                    loginError.text('Ошибка сервера :(').show();
                }
            });
        });

        // Register Submit
        registerForm.on('submit', function(e) {
            e.preventDefault();
            
            // Simple Client-side validation
            const pass = $('#reg_pass').val();
            const conf = $('#reg_pass_conf').val();
            if (pass !== conf) {
                registerError.text('Пароли не совпадают :(').show();
                return;
            }

            const formData = $(this).serialize(); // action=register is inside
            
            $.ajax({
                url: 'api.php',
                method: 'POST',
                data: formData,
                success: function(res) {
                    if (res.success) {
                        location.reload(); // Reload to update UI state
                    } else {
                        registerError.text(res.message).show();
                    }
                },
                error: function() {
                    registerError.text('Ошибка сервера. Попробуйте позже.').show();
                }
            });
        });
    }

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

    window.openProfileModal = function(e) {
        if (e) e.preventDefault();
        profileModal.css('display', 'flex').hide().fadeIn(200);
    };

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
});
