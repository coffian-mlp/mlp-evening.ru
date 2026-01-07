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
    // For now, let's assume we can get it from a global variable if set in header
    const currentUserId = window.currentUserId || null;
    const currentUserRole = window.currentUserRole || null; // 'admin' or 'user'

    // Append message to UI
    function appendMessage(data) {
        const div = document.createElement('div');
        div.className = 'chat-message';
        div.dataset.id = data.id; // Store message ID
        
        // Format time (HH:MM)
        // Since backend now sends ISO 8601 (e.g. 2023-10-27T10:00:00Z), we can parse it directly
        const date = new Date(data.created_at);
        const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        
        // Color & Avatar
        const colorStyle = data.chat_color ? `style="color: ${escapeHtml(data.chat_color)}"` : '';
        const avatarHtml = data.avatar_url 
            ? `<img src="${escapeHtml(data.avatar_url)}" class="chat-avatar" alt="">` 
            : '';

        // Deleted State
        if (data.deleted) {
            div.innerHTML = `
                ${avatarHtml}
                <span class="username" ${colorStyle}>${escapeHtml(data.username)}:</span>
                <span class="text" style="color:#999; font-style:italic;">Сообщение удалено</span>
                <span class="timestamp" title="${data.created_at}">${timeStr}</span>
            `;
            chatMessages.appendChild(div);
            scrollToBottom();
            return;
        }

        // Actions (Edit/Delete)
        let actionsHtml = '';
        const isMyMessage = (currentUserId && parseInt(data.user_id) === parseInt(currentUserId));
        const isAdmin = (currentUserRole === 'admin');
        const isRecent = ((new Date() - date) / 1000 / 60) < 10; // < 10 mins

        // Show buttons if: (My Message AND Recent) OR (Admin)
        if ((isMyMessage && isRecent) || isAdmin) {
            // Edit only for self and recent (Admin can't edit others' messages typically, but can delete)
            if (isMyMessage && isRecent) {
                actionsHtml += `<button class="chat-action-btn edit-btn" title="Редактировать">✎</button>`;
            }
            // Delete for self or Admin
            if (isMyMessage || isAdmin) {
                actionsHtml += `<button class="chat-action-btn delete-btn" title="Удалить">🗑</button>`;
            }
        }

        let editedMark = '';
        if (data.edited_at) {
            const editDate = new Date(data.edited_at);
            editedMark = `<span class="edited-mark" title="Отредактировано: ${editDate.toLocaleString()}">(изм.)</span>`;
        }

        div.innerHTML = `
            ${avatarHtml}
            <span class="username" ${colorStyle}>${escapeHtml(data.username)}:</span>
            <span class="text">${escapeHtml(data.message)}</span>
            ${editedMark}
            <span class="timestamp" title="${data.created_at}">${timeStr}</span>
            <span class="chat-actions">${actionsHtml}</span>
        `;
        
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

    // 1. Initialize SSE connection
    // We use Last-Event-ID automatically handled by browser/EventSource
    const evtSource = new EventSource('/chat_stream.php');

    evtSource.onmessage = function(e) {
        try {
            // Keepalive check
            if (e.data === 'keepalive') return;
            
            const data = JSON.parse(e.data);
            
            // Check if this message already exists in UI (for updates/edits)
            const existingMsg = document.querySelector(`.chat-message[data-id="${data.id}"]`);
            if (existingMsg) {
                // Replace content (simplest way to handle edit/delete updates)
                // Ideally, we should refactor appendMessage to return HTML string or be reusable
                // For now, let's just reload the whole element logic by removing and re-appending?
                // No, that breaks scroll and visual flow if it's way up.
                // Let's manually update the text part.
                
                if (data.deleted) {
                    existingMsg.querySelector('.text').innerHTML = '<em style="color:#999;">Сообщение удалено</em>';
                    existingMsg.querySelector('.chat-actions').remove(); // Remove buttons
                } else {
                    existingMsg.querySelector('.text').textContent = data.message;
                    if (!existingMsg.querySelector('.edited-mark')) {
                         const ts = existingMsg.querySelector('.timestamp');
                         const mark = document.createElement('span');
                         mark.className = 'edited-mark';
                         mark.textContent = '(изм.)';
                         mark.title = 'Отредактировано';
                         existingMsg.insertBefore(mark, ts);
                    }
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
                        if (window.showFlashMessage) {
                             window.showFlashMessage(response.message, 'error');
                        } else {
                             alert("Error: " + response.message);
                        }
                        chatInput.value = oldVal; // Restore if failed
                    }
                },
                error: function() {
                    if (window.showFlashMessage) {
                         window.showFlashMessage("Ошибка сети. Попробуй позже.", 'error');
                    }
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
        const text = msgDiv.find('.text').text(); // Get raw text
        
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
        if(!confirm('Удалить сообщение?')) return;
        
        const msgDiv = $(this).closest('.chat-message');
        const msgId = msgDiv.attr('data-id');

        $.post('api.php', { action: 'delete_message', message_id: msgId }, function(res) {
            if(!res.success) {
                if (window.showFlashMessage) window.showFlashMessage(res.message, 'error');
            }
            // Success is handled via SSE update
        }, 'json');
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
