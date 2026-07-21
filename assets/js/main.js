// main.js - Глобальные скрипты для всего сайта

// Switch Profile Tabs
window.switchProfileTab = function(tabName) {
    // Hide all contents
    $('.profile-tab-content').hide();
    // Show target
    $('#tab-' + tabName).fadeIn(200);
    
    // Update buttons
    $('.profile-tab-btn').removeClass('active');
    // Находим кнопку по onclick атрибуту, так как у нас нет ID
    $(`.profile-tab-btn[onclick*="${tabName}"]`).addClass('active');
};

// Utility: Escape HTML to prevent XSS
function escapeHtml(text) {
  if (text == null) return text;
  var map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };
    return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Utility: Escape RegExp special characters
function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// --- Global Smart Lightbox ---
$(document).ready(function() {
    const lightbox = $('#global-lightbox');
    const lightboxImg = $('#global-lightbox-img');
    const lightboxClose = $('#lightbox-close');
    const zoomLevelEl = $('#lightbox-zoom-level');
    
    let scale = 1;
    let pointX = 0;
    let pointY = 0;
    let isDragging = false;
    let dragStartX = 0, dragStartY = 0;
    
    function updateTransform() {
        lightboxImg.css('transform', `translate(${pointX}px, ${pointY}px) scale(${scale})`);
        zoomLevelEl.text(Math.round(scale * 100) + '%');
    }
    
    function openLightbox(src) {
        scale = 1;
        pointX = 0;
        pointY = 0;
        lightboxImg.attr('src', src);
        updateTransform();
        lightbox.css('display', 'flex').hide().fadeIn(200);
        $('body').css('overflow', 'hidden'); // Lock scroll
    }
    
    function closeLightbox() {
        lightbox.fadeOut(200, function() {
            lightboxImg.attr('src', '');
            $('body').css('overflow', ''); // Unlock scroll
        });
    }

    // Trigger on Chat Images, Sticker Previews, or explicit triggers
    // Exclude emojis and mobile stickers (if handled differently, but here standard is ok)
    $(document).on('click', '.chat-message img:not(.emoji), .sticker-preview-img, .lightbox-trigger, .chat-img', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        let src = $(this).attr('src');
        // Check for linked high-res
        const parentLink = $(this).closest('a');
        if (parentLink.length) {
            const href = parentLink.attr('href');
            if (href && href.match(/\.(jpeg|jpg|gif|png|webp)(\?.*)?$/i)) {
                src = href;
            }
        }
        
        openLightbox(src);
    });

    // Close events
    lightboxClose.on('click', closeLightbox);
    
    lightbox.on('click', function(e) {
        if (e.target === this || $(e.target).hasClass('lightbox-content')) {
            closeLightbox();
        }
    });
    
    $(document).on('keydown', function(e) {
        if (e.key === "Escape" && lightbox.is(':visible')) {
            closeLightbox();
        }
    });
    
    // Zoom Controls
    $('#lightbox-zoom-in').on('click', function(e) { 
        e.stopPropagation();
        scale += 0.2; updateTransform(); 
    });
    $('#lightbox-zoom-out').on('click', function(e) { 
        e.stopPropagation();
        scale = Math.max(0.1, scale - 0.2); updateTransform(); 
    });
    $('#lightbox-reset').on('click', function(e) { 
        e.stopPropagation();
        scale = 1; pointX = 0; pointY = 0; updateTransform(); 
    });
    
    // Wheel Zoom
    lightbox.on('wheel', function(e) {
        e.preventDefault();
        const zoomSpeed = 0.1;
        if (e.originalEvent.deltaY < 0) {
            scale += zoomSpeed;
        } else {
            scale = Math.max(0.1, scale - zoomSpeed);
        }
        updateTransform();
    });
    
    // Panning (Mouse)
    lightboxImg.on('mousedown', function(e) {
        if (e.which !== 1) return;
        isDragging = true;
        dragStartX = e.clientX - pointX;
        dragStartY = e.clientY - pointY;
        lightboxImg.css('transition', 'none'); 
        e.preventDefault();
    });
    
    $(document).on('mousemove', function(e) {
        if (!isDragging) return;
        pointX = e.clientX - dragStartX;
        pointY = e.clientY - dragStartY;
        lightboxImg.css('transform', `translate(${pointX}px, ${pointY}px) scale(${scale})`);
    });
    
    $(document).on('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            lightboxImg.css('transition', 'transform 0.1s ease-out');
        }
    });
});

$(document).ready(function() {

    // --- 0. PWA Service Worker Registration ---
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js')
            .then(reg => console.log('Service Worker registered! 🦄', reg.scope))
            .catch(err => console.log('Service Worker registration failed:', err));
    }
    
    // --- 0.5 Custom Select Logic (The Magic Replacement) ---
    window.initCustomSelects = function() {
        var x, i, j, l, ll, selElmnt, a, b, c;
        /* Look for any elements with the class "custom-select-enabled": */
        // We will target ALL selects inside forms, or specific ones. 
        // Let's target all selects that are not already wrapped.
        
        $('select:not(.no-custom)').each(function() {
            if ($(this).parent('.custom-select-wrapper').length) return; // Already done
            
            // Wrap in wrapper
            $(this).wrap('<div class="custom-select-wrapper"></div>');
            selElmnt = this;
            
            // Create the selected item DIV
            a = document.createElement("DIV");
            a.setAttribute("class", "select-selected");
            a.innerHTML = selElmnt.options[selElmnt.selectedIndex].innerHTML;
            selElmnt.parentNode.appendChild(a);
            
            // Create the options list DIV
            b = document.createElement("DIV");
            b.setAttribute("class", "select-items select-hide");
            
            for (j = 0; j < selElmnt.length; j++) {
                if (selElmnt.options[j].disabled) continue; // MLP-260: disabled-опции не кликабельны
                c = document.createElement("DIV");
                c.innerHTML = selElmnt.options[j].innerHTML;
                
                // Add click handler
                c.addEventListener("click", function(e) {
                    var y, i, k, s, h, sl, yl;
                    s = this.parentNode.parentNode.getElementsByTagName("select")[0];
                    sl = s.length;
                    h = this.parentNode.previousSibling;
                    
                    for (i = 0; i < sl; i++) {
                        if (s.options[i].innerHTML == this.innerHTML) {
                            s.selectedIndex = i;
                            h.innerHTML = this.innerHTML;
                            y = this.parentNode.getElementsByClassName("same-as-selected");
                            yl = y.length;
                            for (k = 0; k < yl; k++) {
                                y[k].removeAttribute("class");
                            }
                            this.setAttribute("class", "same-as-selected");
                            
                            // Trigger change event on original select so other scripts know!
                            $(s).trigger('change');
                            break;
                        }
                    }
                    h.click();
                });
                b.appendChild(c);
            }
            selElmnt.parentNode.appendChild(b);
            
            // Toggle open/close
            a.addEventListener("click", function(e) {
                e.stopPropagation();
                closeAllSelect(this);
                this.nextSibling.classList.toggle("select-hide");
                this.classList.toggle("select-arrow-active");
            });
        });
    };

    function closeAllSelect(elmnt) {
        var x, y, i, xl, yl, arrNo = [];
        x = document.getElementsByClassName("select-items");
        y = document.getElementsByClassName("select-selected");
        xl = x.length;
        yl = y.length;
        for (i = 0; i < yl; i++) {
            if (elmnt == y[i]) {
                arrNo.push(i)
            } else {
                y[i].classList.remove("select-arrow-active");
            }
        }
        for (i = 0; i < xl; i++) {
            if (arrNo.indexOf(i)) {
                x[i].classList.add("select-hide");
            }
        }
    }

    /* If the user clicks anywhere outside the select box, then close all select boxes: */
    document.addEventListener("click", closeAllSelect);

    // Run Init
    initCustomSelects();

    // --- 1. CSRF Protection Setup ---
    // Автоматически добавляем токен во все AJAX запросы
    $.ajaxSetup({
        headers: {
            'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content')
        }
    });

    // --- 2. Глобальная функция уведомлений ---
    // Делаем её доступной глобально (через window)
    window.showFlashMessage = function(message, type) {
        // Удаляем старые сообщения
        $('.flash-message').remove();

        var alertClass = (type === 'error') ? 'alert-danger' : 'alert-success';
        
        // Создаем элемент уведомления
        // Стили для .flash-message должны быть описаны в CSS (например, main.css)
        var $msg = $('<div class="flash-message ' + alertClass + '">' + message + '</div>');
        
        // Добавляем в body
        $('body').append($msg);

        // Автоскрытие через 3 секунды (можно настроить)
        // Ошибки тоже скрываем, но чуть позже? Или оставляем висеть?
        // Пусть ошибки висят 5 сек.
        var timeout = (type === 'error') ? 5000 : 3000;

        setTimeout(function() {
            $msg.fadeOut(500, function() {
                $(this).remove();
            });
        }, timeout);
        
        // Закрытие по клику
        $msg.click(function() {
            $(this).remove();
        });
    };

    // --- 3. Мобильное меню (если есть) ---
    /*
    $('.mobile-menu-toggle').click(function() {
        $('.nav-menu').toggleClass('open');
    });
    */

    // --- 4. Кастомный Color Picker ---
    window.initColorPickers = function() {
        $('.color-picker-ui').each(function() {
            var $container = $(this);
            // Check if already initialized to avoid duplicates
            if ($container.data('initialized')) return;
            $container.data('initialized', true);

            var $hiddenInput = $container.find('input[type="hidden"]');
            var $manualInput = $container.find('.color-manual-input'); // Text input for HEX
            if ($hiddenInput.length === 0) return; 

            // Пони-палитра 🦄
            var colors = [
                { color: '#6d2f8e', name: 'Twilight Sparkle' },
                { color: '#e91e63', name: 'Pinkie Pie' },
                { color: '#2196f3', name: 'Rainbow Dash' },
                { color: '#ff9800', name: 'Applejack' },
                { color: '#f1c40f', name: 'Fluttershy' },
                { color: '#9c27b0', name: 'Rarity' },
                { color: '#3f51b5', name: 'Princess Luna' },
                { color: '#ffeb3b', name: 'Princess Celestia' }, // Goldish
                { color: '#8bc34a', name: 'Spike' },
                { color: '#ba68c8', name: 'Starlight Glimmer' },
                { color: '#ff5722', name: 'Sunset Shimmer' },
                { color: '#009688', name: 'Chrysalis' },
                { color: '#795548', name: 'Discord' },
                { color: '#607d8b', name: 'Background Pony' },
                { color: '#a52658', name: 'Fizzlepop Berrytwist' },
                { color: '#ff9f2c', name: 'Sunny Starscout' },
                { color: '#9f76d7', name: 'Izzy Moonbow' },
                { color: '#f2b661', name: 'Hitch Trailblazer' },
                { color: '#dd97b8', name: 'Pipp Petals' },
                { color: '#e0e0f0', name: 'Zipp Storm' },
                { color: '#5e3a77', name: 'Opaline Arcana' },
                { color: '#8eb0d6', name: 'Misty Brightdawn' }
            ];

            // Create swatches container
            var $swatches = $('<div class="color-swatches"></div>');
            
            colors.forEach(function(item) {
                var $swatch = $('<div class="color-swatch"></div>');
                $swatch.css('background-color', item.color);
                $swatch.attr('data-color', item.color);
                $swatch.attr('title', item.name);
                
                // Active state check
                if ($hiddenInput.val().toLowerCase() === item.color.toLowerCase()) {
                    $swatch.addClass('active');
                }

                $swatch.click(function() {
                    var c = item.color;
                    // Update inputs
                    $hiddenInput.val(c);
                    if ($manualInput.length) {
                        $manualInput.val(c);
                        $container.find('.color-manual-preview').css('background-color', c);
                    }
                    
                    // Update visual
                    $container.find('.color-swatch').removeClass('active');
                    $(this).addClass('active');
                });

                $swatches.append($swatch);
            });

            // Prepend swatches before the manual input wrapper (if any) or just append
            if ($container.find('.manual-input-wrapper').length) {
                $container.find('.manual-input-wrapper').before($swatches);
            } else {
                $container.append($swatches);
            }

            // Manual Input Logic
            if ($manualInput.length) {
                // Create Preview Swatch dynamically
                var $preview = $('<div class="color-manual-preview" title="Предпросмотр"></div>');
                $container.find('.manual-input-wrapper').append($preview);

                // Init value
                var initialColor = $hiddenInput.val();
                $manualInput.val(initialColor);
                $preview.css('background-color', initialColor);

                $manualInput.on('input', function() {
                    var val = $(this).val();
                    if (!val.startsWith('#') && val.length > 0) {
                        val = '#' + val;
                    }
                    
                    // Live Preview (accepts 3 or 6 chars for UX)
                    if (/^#([0-9A-Fa-f]{3}){1,2}$/.test(val)) {
                         $preview.css('background-color', val);
                    } else if (val === '') {
                         $preview.css('background-color', 'transparent');
                    }

                    // Validate HEX (strictly 6 chars for saving)
                    if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                        $hiddenInput.val(val);
                        
                        // Check if matches any swatch
                        $container.find('.color-swatch').removeClass('active');
                        $container.find(`.color-swatch[data-color="${val.toLowerCase()}"]`).addClass('active');
                    }
                });
                
                // Sync on blur to ensure # format
                $manualInput.on('blur', function() {
                    var val = $(this).val();
                    if (val.length > 0 && !val.startsWith('#')) {
                        $(this).val('#' + val);
                    }
                });
            }
        });
    };

    // Auto-init on page load if any exist
    initColorPickers();


    // --- 4.5 CAPTCHA GAME LOGIC (Unified) ---
    
    // Callback to run when captcha is successfully completed
    window.onCaptchaSuccess = null;

    // Generic Start Function
    window.startCaptchaGame = function(onSuccess) {
        window.onCaptchaSuccess = onSuccess;
        loadCaptchaStep();
    };

    // Specific Registration Handler
    window.startCaptchaRegistration = function() {
        // Validate Password Match first
        var p1 = $('#reg_pass').val();
        var p2 = $('#reg_pass_conf').val();
        
        if (!p1 || !p2) {
             $('#register-error').show().text("Введите пароль!");
             return;
        }
        if (p1 !== p2) {
             $('#register-error').show().text("Пароли не совпадают!");
             return;
        }
        
        // Hide form, show captcha
        $('#register-form-wrapper').hide();
        $('#captcha-form-wrapper').fadeIn(200);
        
        // Start Game with Registration callback
        window.startCaptchaGame(submitRegistration);
    };

    function loadCaptchaStep() {
        $.post('/api.php', { action: 'captcha_start', csrf_token: window.csrfToken }, function(response) {
            if (response.success) {
                renderCaptchaStep(response.data);
            } else {
                $('#captcha-error').show().text(response.message);
            }
        }, 'json');
    }

    function renderCaptchaStep(stepData) {
        $('#captcha-question-text').text(stepData.question);
        $('#captcha-error').hide();
        
        // Image Handling
        if (stepData.type === 'image' && stepData.image_url) {
            $('#captcha-image').attr('src', stepData.image_url).parent().show();
        } else {
            $('#captcha-image').parent().hide();
        }

        // Render Options or Input
        var $container = $('#captcha-options-container');
        $container.empty();

        if (stepData.type === 'input') {
            // Text Input Field
            // Убираем margin-bottom, так как они будут в сетке рядом
            var $input = $('<input type="text" class="form-input" placeholder="Имя..." style="height: 100%;">');
            var $btn = $('<button type="button" class="btn btn-primary btn-block" style="height: 100%;">Ответить</button>');
            
            // Сбрасываем/Устанавливаем сетку для 2 элементов
            $container.css('grid-template-columns', '1fr 1fr');

            // Handle Enter key
            $input.on('keypress', function(e) {
                if(e.which === 13) {
                    e.preventDefault();
                    $btn.click();
                }
            });

            $btn.click(function() {
                var val = $input.val().trim();
                if (!val) return;
                checkCaptchaAnswer(val);
            });

            $container.append($input).append($btn);
            // Focus on input
            setTimeout(function() { $input.focus(); }, 100);

        } else {
            // Buttons (Options)
            $container.css('grid-template-columns', '1fr 1fr'); // Restore grid if needed
            
            // Object iteration
            for (var key in stepData.options) {
                if (stepData.options.hasOwnProperty(key)) {
                    var label = stepData.options[key];
                    var $btn = $('<button type="button" class="btn btn-outline-primary">' + label + '</button>');
                    
                    // Capture key in closure
                    (function(answerKey) {
                        $btn.click(function() {
                            checkCaptchaAnswer(answerKey);
                        });
                    })(key);

                    $container.append($btn);
                }
            }
        }
    }

    function checkCaptchaAnswer(answer) {
        $.post('/api.php', { 
            action: 'captcha_check', 
            answer: answer,
            csrf_token: window.csrfToken 
        }, function(response) {
            if (response.success) {
                if (response.data.completed) {
                    // Success! 
                    if (window.onCaptchaSuccess) {
                        window.onCaptchaSuccess();
                    } else {
                        // Default fallback
                        showFlashMessage("Испытание пройдено!", 'success');
                    }
                } else if (response.data.next_step) {
                    // Next Level
                    renderCaptchaStep(response.data.next_step);
                }
            } else {
                // Fail
                $('#captcha-error').show().text(response.message);
                // Restart after delay?
                setTimeout(function(){
                     // Reset to start or just reload first step
                     loadCaptchaStep();
                }, 1500);
            }
        }, 'json');
    }

    function submitRegistration() {
        var formData = $('#ajax-register-form').serialize();
        
        $.post('/api.php', formData, function(response) {
             if (response.success) {
                showFlashMessage(response.message, 'success');
                if (response.data && response.data.reload) {
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    // Show login
                     showLoginForm();
                }
            } else {
                // Show error on captcha screen or go back?
                // Let's show on captcha screen for simplicity
                 $('#captcha-error').show().text(response.message);
            }
        }, 'json');
    }


    // --- 5. Telegram Auth Callbacks ---
    
    // Callback для ВХОДА (Login)
    window.onTelegramAuth = function(user) {
        // user = {id: ..., first_name: ..., username: ..., hash: ...}
        
        // Отправляем данные на сервер для проверки и входа
        $.ajax({
            url: '/api.php',
            method: 'POST',
            data: {
                action: 'social_login',
                provider: 'telegram',
                data: user,
                // Для публичного входа CSRF токен может отсутствовать, 
                // если мы не залогинены. На бэкенде проверим.
                csrf_token: $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                if (response.success) {
                    showFlashMessage(response.message, 'success');
                    // Если есть редирект (или reload)
                    if (response.data && response.data.redirect) {
                        window.location.href = response.data.redirect;
                    } else {
                        location.reload();
                    }
                } else {
                    showFlashMessage(response.message, 'error');
                }
            },
            error: function() {
                showFlashMessage('Ошибка соединения с сервером', 'error');
            }
        });
    };

    // Callback для ПРИВЯЗКИ (Bind) в профиле
    window.onTelegramBind = function(user) {
        $.ajax({
            url: '/api.php',
            method: 'POST',
            data: {
                action: 'bind_social',
                provider: 'telegram',
                data: user,
                csrf_token: $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                if (response.success) {
                    showFlashMessage("Telegram успешно привязан! 🦄", 'success');
                    // Перезагружаем список соцсетей, чтобы показать галочку
                    loadUserSocials(); 
                } else {
                    showFlashMessage(response.message, 'error');
                }
            },
            error: function() {
                showFlashMessage('Ошибка соединения с сервером', 'error');
            }
        });
    };

    // --- 6. Online Heartbeat ---
    // Create Tooltip Element dynamically
    const tooltip = $('<div id="online-tooltip" class="online-tooltip"></div>');
    $('body').append(tooltip); // Append to body for safe absolute positioning

    let hideTooltipTimeout;

    function updateTooltipPosition() {
        var $target = $('#online-counter');
        if($target.length === 0) return;
        
        // Measure tooltip if hidden (display:none results in width 0)
        var tooltipWasHidden = tooltip.css('display') === 'none';
        if(tooltipWasHidden) {
            tooltip.css({ visibility: 'hidden', display: 'block' });
        }
        var tooltipWidth = tooltip.outerWidth();
        var tooltipHeight = tooltip.outerHeight(); // Not used currently but good to have
        
        if(tooltipWasHidden) {
            tooltip.css({ visibility: '', display: 'none' });
        }
        
        var offset = $target.offset();
        var width = $target.outerWidth();
        var height = $target.outerHeight();
        
        // Default: Centered below the target
        var top = offset.top + height + 10;
        var left = offset.left + (width / 2) - (tooltipWidth / 2);
        
        // Boundary Check
        var winWidth = $(window).width();
        
        // Right Edge
        if (left + tooltipWidth > winWidth - 10) {
            left = winWidth - tooltipWidth - 10;
        }
        // Left Edge
        if (left < 10) {
            left = 10;
        }
        
        tooltip.css({
            top: top,
            left: left,
            right: 'auto',     // Reset potential CSS
            bottom: 'auto',    // Reset potential CSS
            transform: 'none'  // Reset potential CSS
        });
    }

    $('#online-counter').hover(function() {
        clearTimeout(hideTooltipTimeout);
        updateTooltipPosition();
        tooltip.fadeIn(200);
    }, function() {
        hideTooltipTimeout = setTimeout(() => {
            tooltip.fadeOut(200);
        }, 300);
    });
    
    // Also keep open if hovering the tooltip itself
    tooltip.hover(function() {
        clearTimeout(hideTooltipTimeout);
    }, function() {
        hideTooltipTimeout = setTimeout(() => {
            tooltip.fadeOut(200);
        }, 300);
    });

    function sendHeartbeat() {
        $.post('/api.php', { 
            action: 'heartbeat',
            csrf_token: $('meta[name="csrf-token"]').attr('content') 
        }, function(response) {
            if (response.success && response.data.online_stats) {
                var stats = response.data.online_stats;
                var total = stats.total;
                var guests = stats.guests_count;
                var users = stats.users; // Array
                
                // Update Text
                $('#online-counter').text('(' + total + ')');
                
                // Remove native title
                $('#online-counter').removeAttr('title');

                // Build Custom Tooltip Content
                var html = '<div class="online-tooltip-header">В сети сейчас</div><div class="online-user-list">';
                
                if (users.length > 0) {
                    users.forEach(function(u) {
                        // Avatar handling
                        var avatarUrl;
                        if (u.avatar && u.avatar !== 'default-avatar.png' && u.avatar !== 'default.png') {
                            // If it's a full URL (external) or absolute path (from UploadManager), use it as is
                            if (u.avatar.startsWith('http') || u.avatar.startsWith('/')) {
                                avatarUrl = u.avatar;
                            } else {
                                // If it's just a filename, assume /upload/avatars/
                                avatarUrl = '/upload/avatars/' + u.avatar;
                            }
                        } else {
                            avatarUrl = '/assets/img/default-avatar.png';
                        }
                        
                        var color = u.chat_color || '#6d2f8e';
                        var name = u.nickname;
                        
                        html += `
                            <div class="online-user-item" onclick="if(window.insertMention) window.insertMention('${escapeHtml(name)}');">
                                <img src="${avatarUrl}" class="online-user-avatar" alt="${escapeHtml(name)}">
                                <span style="color:${color}; font-weight:600;">${escapeHtml(name)}</span>
                            </div>
                        `;
                    });
                } else {
                    html += '<div style="color:#999; font-style:italic; padding:5px;">Никого из своих...</div>';
                }
                
                html += '</div>';
                
                if (guests > 0) {
                    html += `<div class="online-guest-count">Гостей: ${guests}</div>`;
                }
                
                tooltip.html(html);
            }
        }, 'json');
    }

    // Run every 42 seconds (The Answer to the Ultimate Question of Life, the Universe, and Everything)
    setInterval(sendHeartbeat, 42000);
    // Run immediately on load
    sendHeartbeat();

    // Handle Window Close (Leave)
    window.addEventListener('beforeunload', function() {
        // Use sendBeacon if available for reliability
        const data = new FormData();
        data.append('action', 'leave');
        // Beacon doesn't support custom headers nicely for CSRF, but api.php checks it.
        // Wait, beacon sends POST but we need CSRF token.
        // Our api.php checks csrf_token in POST or Header.
        // We can append it to FormData.
        data.append('csrf_token', window.csrfToken || ''); // Handle if not set (public)

        if (navigator.sendBeacon) {
            navigator.sendBeacon('/api.php', data);
        } else {
            // Fallback (Blocking XHR - deprecated but works)
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/api.php', false); // false = synchronous
            xhr.send(data);
        }
    });

    // --- 7. Font Switcher Logic ---
    window.applyUserFont = function(fontName) {
        var fontStack = "'Open Sans', sans-serif"; // Default
        
        switch(fontName) {
            case 'fira':
                fontStack = "'Fira Sans', sans-serif";
                break;
            case 'pt':
                fontStack = "'PT Sans', sans-serif";
                break;
            case 'rubik':
                fontStack = "'Rubik', sans-serif";
                break;
            case 'inter':
                fontStack = "'Inter', sans-serif";
                break;
            default:
                fontStack = "'Open Sans', sans-serif";
        }
        
        document.documentElement.style.setProperty('--main-font', fontStack);
    };

    // --- 7.5 Font Scale Logic ---
    window.applyFontScale = function(scale) {
        // Ensure scale is within bounds
        scale = Math.min(Math.max(parseInt(scale), 50), 150);
        
        // We set font-size on html. Assuming base is 100% (browser default).
        // Using percentage allows user agent stylesheets to work normally.
        document.documentElement.style.fontSize = scale + '%';
    };

    // Apply font on load if set globally (from PHP)
    if (window.currentUserFont) {
        applyUserFont(window.currentUserFont);
    }
    
    // Apply font scale
    if (window.currentUserFontScale) {
        applyFontScale(window.currentUserFontScale);
    }
    // Helper to soft-reload chat interface
    function reloadChatInterface() {
        $.post('/api.php', { 
            action: 'get_chat_input', 
            csrf_token: $('meta[name="csrf-token"]').attr('content') 
        }, function(res) {
            if (res.success) {
                // Replace Input Area
                const oldArea = document.querySelector('.chat-input-area');
                if (oldArea) {
                    oldArea.outerHTML = res.data.html;
                }
                
                // Update Global Variables
                if (res.data.user_data) {
                    const u = res.data.user_data;
                    window.currentUserId = u.user_id;
                    window.currentUserRole = u.role;
                    window.isModerator = u.is_moderator;
                    window.currentUsername = u.username;
                    window.currentUserNickname = u.nickname;
                    window.csrfToken = u.csrf_token;
                    window.userOptions = u.user_options;
                    
                    // Update CSRF token in meta tag
                    $('meta[name="csrf-token"]').attr('content', u.csrf_token);
                    
                    // Build Profile Link HTML manually
                    const avatarUrl = u.avatar_url || '/assets/img/default-avatar.png';
                    const userMenuHtml = `
                        <div class="user-controls">
                            <a href="#" onclick="openProfileModal(event)" class="profile-link" title="Настройки профиля">
                                <span class="avatar-mini">
                                    <img src="${escapeHtml(avatarUrl)}" alt="">
                                </span>
                                <span class="username" style="color: ${escapeHtml(u.chat_color || '#ce93d8')}">
                                    ${escapeHtml(u.nickname)}
                                </span>
                            </a>
                        </div>
                    `;
                    // Replace user menu content entirely
                    $('.chat-user-menu').html(userMenuHtml);
                    
                    // Inject Mobile FAB if missing
                    if ($('#chat-mobile-fab').length === 0) {
                        const fab = $('<button id="chat-mobile-fab" class="chat-mobile-fab" title="Написать" style="position: absolute; right: 20px; bottom: 20px; z-index: 90;">✎</button>');
                        $('#chat').append(fab); 
                        fab.click(function() {
                            $('#chat-mobile-input-overlay').css('display', 'flex').hide().fadeIn(200);
                            $('#chat-mobile-input').focus();
                        });
                    }
                    
                    // Update connection and history (if functions exist)
                    if (window.updateChatConnection && res.data.chat_config) {
                        window.updateChatConnection(res.data.chat_config);
                    }
                    if (window.reloadChatMessages) {
                        window.reloadChatMessages();
                    }
                }
            } else {
                location.reload(); 
            }
        }, 'json').fail(() => location.reload());
    }
}); // End of $(document).ready


// --- 6. Загрузка списка соцсетей в профиле (ВЫНЕСЕНО) ---
window.openProfileModal = function(e) {
    if(e) e.preventDefault();
    
    // Используем callback, чтобы грузить виджет только когда модалка ВИДИМА
    // console.log('Starting fadeIn...');
    $('#profile-modal').css('display', 'flex').hide().fadeIn(200, function() {
        // console.log('fadeIn complete! Calling loadUserSocials...');
        loadUserSocials();
    });
};

function loadUserSocials() {
    // Контейнеры
    var $statusContainer = $('#telegram-status-container');
    var $widgetContainer = $('#telegram-widget-container');

    $.ajax({
        url: '/api.php',
        method: 'POST',
        data: { 
            action: 'get_user_socials',
            csrf_token: $('meta[name="csrf-token"]').attr('content')
        },
        success: function(resp) {
            if (resp.success) {
                var telegram = resp.data.socials.find(s => s.provider === 'telegram');
                
                if (telegram) {
                    // ПРИВЯЗАН: Показываем статус, скрываем виджет
                    $widgetContainer.hide();
                    $statusContainer.show().html('');

                    // Статус
                    $statusContainer.append('<span style="color: green; font-weight: bold; font-size: 0.9em;">✓ ' + (telegram.username || telegram.first_name) + '</span>');
                    
                    // Кнопка отвязки
                    var $unbindBtn = $('<a href="#" style="color: #999; font-size: 0.8em; margin-left: 10px; text-decoration: underline;">(отвязать)</a>');
                    $unbindBtn.click(function(e) {
                        e.preventDefault();
                        if(!confirm('Точно отвязать Telegram?')) return;
                        
                        $.post('/api.php', {
                            action: 'unlink_social',
                            provider: 'telegram',
                            csrf_token: $('meta[name="csrf-token"]').attr('content')
                        }, function(res) {
                            if (res.success) {
                                showFlashMessage(res.message, 'success');
                                loadUserSocials(); // Перезагружаем состояние
                            } else {
                                showFlashMessage(res.message, 'error');
                            }
                        }, 'json');
                    });
                    
                    $statusContainer.append($unbindBtn);

                } else {
                    // НЕ ПРИВЯЗАН: Скрываем статус, показываем контейнер
                    $statusContainer.hide().empty();
                    $widgetContainer.show();
                    
                    // ЯДЕРНЫЙ ВАРИАНТ: Вставляем IFRAME вручную ☢️
                    $widgetContainer.empty();

                    if (window.telegramBotUsername) {
                        // Формируем URL для iframe
                        var botName = window.telegramBotUsername;
                        var origin = window.location.origin; // Например, https://v4.mlp-evening.ru
                        var src = 'https://oauth.telegram.org/embed/' + botName + '?origin=' + encodeURIComponent(origin) + '&request_access=write&size=medium';
                        
                        var iframe = document.createElement('iframe');
                        iframe.src = src;
                        iframe.id = 'telegram-login-' + botName;
                        iframe.style.width = '100%'; // Или фиксированную, например '186px'
                        iframe.style.height = '28px'; // Высота medium виджета
                        iframe.style.border = 'none';
                        iframe.style.overflow = 'hidden';
                        iframe.setAttribute('scrolling', 'no');
                        iframe.setAttribute('frameborder', '0');
                        
                        $widgetContainer.append(iframe);
                        
                        // Слушаем ответ от Iframe (один раз, чтобы не плодить слушателей)
                        if (!window.telegramMessageListenerAdded) {
                            window.telegramMessageListenerAdded = true;
                            window.addEventListener('message', function(event) {
                                // Проверяем источник (на всякий случай, хотя telegram шлет с oauth.telegram.org)
                                // if (event.origin !== 'https://oauth.telegram.org') return; 
                                
                                try {
                                    var data = JSON.parse(event.data);
                                    if (data.event === 'auth_user') {
                                        // Ура! Пользователь авторизовался
                                        console.log('Telegram Auth Data received:', data.auth_data);
                                        // Вызываем нашу функцию привязки
                                        window.onTelegramBind(data.auth_data);
                                    }
                                } catch(e) {
                                    // Игнорируем не-JSON сообщения
                                }
                            });
                        }
                    }
                }
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            $statusContainer.show().html('<small style="color:red">Ошибка сети</small>');
        }
    });
}

// Обработчик открытия модалки логина
window.openLoginModal = function(e) {
    if(e) e.preventDefault();
    // Сброс к экрану входа
    $('#register-form-wrapper, #social-auth-wrapper, #forgot-form-wrapper').hide();
    $('#login-form-wrapper').show();
    $('#login-modal').css('display', 'flex').hide().fadeIn(200);
};

// Навигация внутри модалки
window.showLoginForm = function(e) {
    if(e) e.preventDefault();
    $('#register-form-wrapper, #social-auth-wrapper, #forgot-form-wrapper, #captcha-form-wrapper').hide();
    $('#login-form-wrapper').fadeIn(200);
};

window.showRegisterForm = function(e) {
    if(e) e.preventDefault();
    $('#login-form-wrapper, #social-auth-wrapper, #forgot-form-wrapper').hide();
    $('#register-form-wrapper').fadeIn(200);
};

window.showSocialAuth = function(e) {
    if(e) e.preventDefault();
    $('#login-form-wrapper, #register-form-wrapper, #forgot-form-wrapper').hide();
    $('#social-auth-wrapper').fadeIn(200);
};

// New Forgot Password Form
window.showForgotForm = function(e) {
    if(e) e.preventDefault();
    $('#login-form-wrapper, #register-form-wrapper, #social-auth-wrapper').hide();
    $('#forgot-form-wrapper').fadeIn(200);
};

// Forgot Password Submit Handler
$(document).ready(function() {
    $('#ajax-forgot-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var $msg = $('#forgot-msg');
        
        $btn.prop('disabled', true).text('Отправка...');
        $msg.hide().removeClass('error-msg success-msg');
        
        $.post('/api.php', $form.serialize(), function(response) {
            $btn.prop('disabled', false).text('Отправить письмо');
            
            if (response.success) {
                // Success - hide form content and show message or just replace content?
                // Let's just show success message and hide form inputs?
                // Or standard flash message
                $msg.addClass('success-msg').css('color', 'green').text(response.message).show();
                $form.find('input').val(''); // Clear input
            } else {
                $msg.addClass('error-msg').css('color', 'red').text(response.message).show();
            }
        }, 'json').fail(function() {
            $btn.prop('disabled', false).text('Отправить письмо');
            $msg.addClass('error-msg').text('Ошибка сети').show();
        });
    });

    // --- LOGIN FORM HANDLER (with BruteForce Protection) ---
    $('#ajax-login-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $error = $('#login-error');
        var $btn = $form.find('button[type="submit"]');

        $error.hide();
        // $btn.prop('disabled', true); // Optional, but good for UI

        var formData = $form.serialize();
        // action=login should be added if not present in inputs (it is likely missing)
        if (formData.indexOf('action=') === -1) {
            formData += '&action=login';
        }

        $.post('/api.php', formData, function(response) {
             // $btn.prop('disabled', false);

             if (response.success) {
                 showFlashMessage(response.message, 'success');
                 $('#login-modal').fadeOut(200);
                 
                 // Try to load chat input if we are on a page with chat
                 if (document.getElementById('chat')) {
                     reloadChatInterface();
                 } else {
                     if (response.data && response.data.reload) {
                         location.reload();
                     } else {
                         location.reload(); 
                     }
                 }
             } else {
                 // Check for CAPTCHA
                 if (response.error_code === 'captcha_required') {
                     // Switch to Captcha View
                     $('#login-form-wrapper').hide();
                     $('#captcha-form-wrapper').fadeIn(200);
                     
                     // Start Captcha Game for Login
                     window.startCaptchaGame(function() {
                         // On Success:
                         showFlashMessage("Отлично! Теперь попробуй войти снова.", "success");
                         window.showLoginForm(); // Back to login
                     });
                 } else {
                     $error.text(response.message).show();
                 }
             }
        }, 'json').fail(function() {
             // $btn.prop('disabled', false);
             $error.text("Ошибка соединения с Эквестрией...").show();
        });
    });

    // --- LOGOUT FORM HANDLER ---
    $('#logout-form').on('submit', function(e) {
        e.preventDefault();
        
        // Show immediate feedback or spinner?
        // Let's just do it quietly but handle the response
        
        $.post('/api.php', $(this).serialize(), function(response) {
            if (response.success) {
                location.reload();
            } else {
                showFlashMessage("Ошибка выхода: " + response.message, 'error');
            }
        }, 'json').fail(function() {
            showFlashMessage("Ошибка сети при выходе", 'error');
        });
    });

    // --- 7. Обработчик профиля ---
    $('#ajax-profile-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var $error = $('#profile-error');
        
        $btn.prop('disabled', true).text('Сохранение...');
        $error.hide();

        var formData = new FormData(this);

        $.ajax({
            url: '/api.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                if (response.success) {
                    showFlashMessage(response.message, 'success');
                    
                    // Обновляем UI без перезагрузки
                    if (response.data && response.data.user) {
                        var u = response.data.user;
                        // Обновляем шапку чата
                        $('.chat-user-menu .username').text(u.nickname).css('color', u.chat_color);
                        if (u.avatar_url) {
                            $('.chat-user-menu .avatar-mini img').attr('src', u.avatar_url);
                        }
                        
                        // Обновляем глобальные переменные
                        window.currentUserNickname = u.nickname;
                    }
                    
                    // Обновляем шрифт интерфейса, если он изменился
                    var newFont = $form.find('select[name="font_preference"]').val();
                    if (newFont) {
                        applyUserFont(newFont);
                    }
                    
                    var newScale = $form.find('input[name="font_scale"]').val();
                    if (newScale) {
                        applyFontScale(newScale);
                    }

                    setTimeout(function() { 
                        $('#profile-modal').fadeOut(200); 
                    }, 500);
                } else {
                    $error.text(response.message).show();
                }
            },
            error: function() {
                $error.text('Ошибка сети').show();
            },
            complete: function() {
                $btn.prop('disabled', false).text('Сохранить изменения');
            }
        });
    });


    // Preview Avatar
    $('input[name="avatar_url"]').on('input', function() {
        var url = $(this).val();
        if (url.match(/\.(jpeg|jpg|gif|png|webp)/i)) {
            $('#profile-avatar-preview').attr('src', url);
        }
    });
    $('input[name="avatar_file"]').change(function() {
        var input = this;
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                $('#profile-avatar-preview').attr('src', e.target.result);
            }
            reader.readAsDataURL(input.files[0]);
        }
    });

    // Close Modal Logic (If not already handled globally)
    $('.close-modal').click(function() {
        $('#login-modal').fadeOut(200);
    });

    // Обработчик для ссылки "Войди" в чате
    $('#login-link').click(function(e) {
        window.openLoginModal(e);
    });

    // Global click outside to close
    $(window).click(function(e) {
        if ($(e.target).is('#login-modal')) {
            $('#login-modal').fadeOut(200);
        }
    });

});

//Пасхалка в консоли - не удалять!
console.log(`
    ⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⢀⣀⣤⣤⣶⠶⠶⠶⠶⠶⠶⠶⣶⣶⣤⣤⣀⣀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⣀⣴⠾⠛⠉⠁⠀⠀⠀⠀⠀⠀⠀⠀⠀⠘⣷⡀⠉⠉⠙⠛⠷⣦⣄⡀⠀⠀⠀⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⣠⡾⠛⠿⠶⢶⣤⣄⣀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠘⣧⠀⠀⠀⠀⠀⠀⠉⠻⢶⣄⠀⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⠀⠀⣠⡾⠋⠀⠀⠀⠀⠀⠀⠈⠙⠛⠶⣤⡀⠀⠀⠀⠀⠀⠀⢹⣇⠀⠀⠀⠀⠀⠀⠀⠀⠙⢷⣄⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⢀⣾⠋⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠈⠙⠳⣤⡀⠀⣀⣀⣀⣿⣄⠀⠀⠀⠀⠀⠀⠀⠀⠀⢻⣦⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⣠⡿⠁⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⢀⣠⣾⠿⠛⠋⠉⠉⠉⠙⠛⠷⣦⣄⠀⠀⠀⠀⠀⠀⢻⣇⠀⠀⠀
    ⠀⠀⠀⠀⣰⡟⠁⠀⠀⠀⠀⣀⣠⣤⣴⣶⣶⣤⣤⣤⣤⣴⠟⠉⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠈⠙⠛⢶⣤⣄⠀⠀⢸⣿⠀⠀⠀
    ⠀⠀⠀⣰⡿⠀⠀⠀⣠⣶⣿⡟⠉⢹⡇⠀⠀⠀⠀⠉⠉⠁⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠉⢻⣷⣄⢸⣿⠀⠀⠀
    ⠀⠀⢠⣿⠁⠀⣠⡾⢻⣿⡏⡷⠀⢸⡇⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⣀⣀⡀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠙⣿⣾⡿⠀⠀⠀
    ⠀⠀⣾⡇⠀⣼⡟⠁⣾⣿⣿⠃⠀⣸⠃⠀⠀⠀⠀⠀⢀⣤⠶⠛⠉⠉⠉⠉⠛⠶⣄⠀⠀⢀⣤⠀⠀⠀⠀⠀⠀⠘⣿⡃⠀⠀⠀
    ⠀⢸⣿⠀⣼⡿⠁⢠⣿⠙⠁⠀⢠⡏⠀⠀⠀⠀⢀⡴⠋⠀⠀⠀⠀⠀⠀⣀⣀⠀⠘⣧⡴⠟⠁⢀⠀⠀⠀⠀⠀⠀⢸⣧⠀⠀⠀
    ⠀⣿⡏⢰⣿⠇⠀⣸⡏⠀⠀⢀⡞⠀⠀⠀⠀⣰⠏⠀⠀⠀⠀⠀⠀⢠⣾⣿⡿⣧⠀⢻⣤⣤⠴⠋⠀⠀⠀⠀⠀⠀⠀⣿⡀⠀⠀
    ⢠⣿⠇⣼⣿⡶⣟⣿⣧⣤⠤⠊⠀⠀⠀⠀⢰⡏⠀⠀⠀⠀⠀⠀⢀⣿⡿⣇⣀⠏⠀⣿⣤⡶⠖⠀⠀⢦⠀⠀⠀⠀⠀⢻⡇⠀⠀
    ⢸⣿⠀⣿⣿⣇⡇⠀⠀⣀⠀⠀⣀⠀⠀⠀⣼⠀⠀⠀⠀⠀⠀⠀⠀⢿⡿⠟⠉⠀⡴⠀⠀⠀⠀⠀⠀⠈⣧⡀⠀⠀⠀⣸⡇⠀⠀
    ⣸⡟⢰⣿⡏⢿⣷⡀⠀⠈⠀⢀⣿⡄⠀⠀⢻⡀⠀⠀⠀⠀⠀⠀⠀⠀⠀⢀⣠⠞⠀⠀⠀⠀⠀⠀⠀⣴⣿⣷⣄⠀⠀⣿⠁⠀⠀
    ⣿⡇⢸⣿⠀⠀⠛⢿⡳⠶⠖⠋⠀⣇⠀⠀⠀⠳⣄⠀⠀⠀⠀⠀⠀⣠⣴⢿⣍⠻⠀⠀⠀⠀⠀⢠⠞⣹⣿⠈⣿⢷⣴⡟⠀⠀⠀
    ⣿⡇⢸⣿⠀⠀⠀⠈⢳⡄⠀⠀⠋⣿⠀⠀⠀⠀⠈⠙⠒⠶⠖⠚⠋⢹⠟⠓⠛⠀⠀⠀⠀⢠⠔⢋⣦⣿⣿⠀⣿⡇⠈⠀⠀⠀⠀
    ⣿⠁⢸⡟⠀⠀⠀⠀⠀⢻⣄⠀⣸⠇⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⣼⣿⢿⣿⠀⢿⣷⠀⠀⠀⠀⠀
    ⣿⠀⢸⡇⠀⠀⠀⠀⠀⢸⣿⣶⢧⣄⣀⣀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⢠⣿⡟⣿⡿⠀⢸⡿⣧⡀⠀⠀⠀
    ⣿⠀⢸⡇⠀⠀⠀⠀⠀⢸⡏⣿⠛⠛⠳⠿⣿⠿⠿⠿⣿⣿⡿⠁⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⢸⣿⠇⣿⡇⠀⢸⣇⠈⠳⣤⣀⠀
    ⣿⠀⣿⡇⠀⠀⠀⠀⠀⢸⡇⣿⢀⣠⣤⣦⣧⣄⠀⢀⣿⡿⠁⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⣿⣿⠀⣿⡇⠀⢸⣿⠀⠀⠈⠙⣿
    ⣿⠀⣿⡇⠀⠀⠀⠀⠀⢸⢇⣿⣿⠟⠋⠉⠓⠿⣿⣿⣿⠃⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⣿⣿⠀⣿⡇⠀⠀⣿⣇⠀⠀⠀⣿
    ⣿⠀⣿⡇⠀⠀⠀⠀⠀⢸⣾⡿⠁⠀⠀⠀⢀⣴⠞⠛⠛⠷⢦⣄⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⡇⣿⠀⣿⡇⠀⠀⣿⢻⡄⠀⠀⣿
    ⣿⠀⣿⡇⠀⠀⠀⠀⢠⣿⡟⠀⠀⠀⠀⢠⡿⠁⠀⠀⠀⠀⠀⠙⢳⣀⠀⠀⠀⠀⠀⠀⠀⠀⡇⣿⠀⢹⣿⠀⠀⢿⡄⠻⣄⠀⣿
    ⣿⠀⣿⡇⠀⠀⠀⢰⣿⡟⠀⠀⠀⠀⢠⡿⠁⠀⠀⠀⠀⠀⠀⠀⠀⠙⠀⠀⠀⠀⠀⣦⠀⠀⡇⣿⠀⢸⣿⠀⠀⠸⣧⠀⠙⢷⣿
    ⣿⠀⢻⡇⠀⠀⢠⣿⡟⠀⠀⠀⠀⠀⣾⠃⠀⠀⠀⠀⠀⡀⠀⠀⠀⠀⠀⠀⠀⠀⠀⣿⠀⠀⣿⣿⡀⢸⣿⠀⠀⠀⢻⡆⠀⢸⣿
    ⣿⠀⢸⡇⠀⠀⣾⣻⠁⠀⠀⠀⠀⢸⡏⠀⠀⠀⠀⠀⠀⣷⢤⣄⠀⠀⠀⠀⠀⠀⣰⡟⠀⠀⢸⢽⣇⠀⣿⡇⠀⠀⠀⠻⣦⣸⡿
    ⣿⣦⣼⣧⣤⣴⣿⣇⣀⣀⣀⣀⣀⣿⣁⠀⠀⠀⠀⠀⠀⣸⣆⣙⣻⣶⣤⣤⣤⣾⣋⣀⣀⣀⣸⣿⣿⣶⣿⣷⣤⣤⣤⣤⣬⣿⡇
    `);

// --- Global Password Toggle Logic ---
$(document).on('click', '.password-toggle-btn', function(e) {
    e.preventDefault();
    const btn = $(this);
    const input = btn.siblings('input');
    
    if (input.attr('type') === 'password') {
        input.attr('type', 'text');
        btn.text('🙈');
    } else {
        input.attr('type', 'password');
        btn.text('👁️');
    }
});

// === Меню сайта (MLP-259): сенобургер + дропдауны шапки ===
// Делегированные обработчики — экземпляров меню на странице может быть два
// (горизонталь шапки + мобильный бургер) или один (главная).
$(document).on('click', '.site-burger-btn', function (e) {
    e.stopPropagation();
    var panel = $(this).siblings('.site-burger-panel')[0];
    var open = panel.hasAttribute('hidden');
    // Закрываем другие открытые панели
    document.querySelectorAll('.site-burger-panel:not([hidden])').forEach(function (p) { p.setAttribute('hidden', ''); });
    if (open) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', '');
    this.setAttribute('aria-expanded', open ? 'true' : 'false');
});

$(document).on('click', '.site-burger-parent, .site-nav-parent', function (e) {
    e.stopPropagation();
    var box = $(this).siblings('.site-burger-children, .site-nav-dropdown')[0];
    var open = box.hasAttribute('hidden');
    // Соседние дропдауны шапки закрываем (ревью L4); аккордеон бургера не трогаем
    document.querySelectorAll('.site-nav-dropdown:not([hidden])').forEach(function (p) {
        if (p !== box) p.setAttribute('hidden', '');
    });
    if (open) box.removeAttribute('hidden'); else box.setAttribute('hidden', '');
    this.setAttribute('aria-expanded', open ? 'true' : 'false');
});

function siteMenuCloseAll() {
    document.querySelectorAll('.site-burger-panel:not([hidden]), .site-nav-dropdown:not([hidden])')
        .forEach(function (p) { p.setAttribute('hidden', ''); });
    document.querySelectorAll('.site-burger-btn, .site-nav-parent, .site-burger-parent')
        .forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
}
$(document).on('click', function () { siteMenuCloseAll(); });
$(document).on('keydown', function (e) { if (e.key === 'Escape') siteMenuCloseAll(); });
// Клик внутри панели не закрывает её (кроме перехода по ссылке)
$(document).on('click', '.site-burger-panel', function (e) { e.stopPropagation(); });

// Перестройка кастомного селекта после динамического заполнения options (MLP-260):
// initCustomSelects строит UI по текущим option — AJAX-заполненные селекты
// иначе навсегда показывают исходный placeholder («Загрузка...»).
window.rebuildCustomSelect = function (selectEl) {
    var $sel = $(selectEl);
    var $wrapper = $sel.parent('.custom-select-wrapper');
    if ($wrapper.length) {
        $wrapper.children('.select-selected, .select-items').remove();
        $sel.unwrap();
    }
    if (window.initCustomSelects) window.initCustomSelects();
};

// Синхронизация подписи кастомного селекта после программного .val() (MLP-260):
// нативный select скрыт — без этого админ видит старую подпись и может
// молча сохранить не то, что видит.
window.syncCustomSelectLabel = function (selectEl) {
    var $wrapper = $(selectEl).parent('.custom-select-wrapper');
    if (!$wrapper.length) return;
    var opt = selectEl.options[selectEl.selectedIndex];
    $wrapper.children('.select-selected').html(opt ? opt.innerHTML : '');
};
