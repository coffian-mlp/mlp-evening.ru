// Local Chat Logic using SSE (Server-Sent Events)

$(document).ready(function() {
    const chatMessages = document.getElementById('chat-messages');
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    
    // Auto-scroll helper
    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // Append message to UI
    function appendMessage(data) {
        const div = document.createElement('div');
        div.className = 'chat-message';
        
        // Format time (HH:MM)
        const date = new Date(data.created_at);
        const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        
        div.innerHTML = `
            <span class="username">${escapeHtml(data.username)}:</span>
            <span class="text">${escapeHtml(data.message)}</span>
            <span class="timestamp" title="${data.created_at}">${timeStr}</span>
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
            appendMessage(data);
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

            const csrfToken = $('meta[name="csrf-token"]').attr('content');

            // Optimistic UI update? No, let's wait for SSE to ensure consistency
            // But we can clear input immediately
            const oldVal = chatInput.value;
            chatInput.value = '';

            $.ajax({
                url: 'api.php',
                method: 'POST',
                data: {
                    action: 'send_message',
                    message: message
                },
                // Headers are set globally in main.js
                success: function(response) {
                    if (!response.success) {
                        // Use global notification instead of alert
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
                    } else {
                         alert("Network error");
                    }
                    chatInput.value = oldVal;
                }
            });
        });
    }

    // 3. Auth Modal Logic (Login + Register)
    const loginLink = $('#login-link');
    const modal = $('#login-modal');
    const closeModal = $('.close-modal');
    const loginForm = $('#ajax-login-form');
    const loginError = $('#login-error');
    const registerForm = $('#ajax-register-form');
    const registerError = $('#register-error');

    if (loginLink.length > 0) {
        loginLink.on('click', function(e) {
            e.preventDefault();
            // Force flex for centering
            modal.css('display', 'flex').hide().fadeIn(200);
        });

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
                    loginError.text('Ошибка сервера. Попробуйте позже.').show();
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
                registerError.text('Пароли не совпадают!').show();
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
});
