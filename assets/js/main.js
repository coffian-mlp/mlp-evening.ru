// main.js - Глобальные скрипты для всего сайта

// --- Global Lightbox ---
$(document).ready(function() {
    // Click on Chat Images & Stickers
    // Targets: Chat images (excluding emojis and stickers) AND Dashboard sticker previews
    $(document).on('click', '.chat-message img:not(.emoji):not(.chat-sticker), .sticker-preview-img', function(e) {
        // Prevent default link navigation if wrapped in <a>
        e.preventDefault();
        
        var src = $(this).attr('src');
        // Check if wrapped in link to high-res image
        var parentLink = $(this).closest('a');
        if (parentLink.length) {
            var href = parentLink.attr('href');
            if (href && href.match(/\.(jpeg|jpg|gif|png|webp)(\?.*)?$/i)) {
                src = href;
            }
        }
        
        $('#global-lightbox-img').attr('src', src);
        $('#global-lightbox').addClass('active').fadeIn(200);
    });

    // Close on click
    $('#global-lightbox').click(function(e) {
        $(this).removeClass('active').fadeOut(200);
    });
});

$(document).ready(function() {
    
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
                { color: '#607d8b', name: 'Background Pony' }
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


    // --- 4.5 CAPTCHA GAME LOGIC ---
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
        
        // Start Game
        loadCaptchaStep();
    };

    function loadCaptchaStep() {
        $.post('api.php', { action: 'captcha_start', csrf_token: window.csrfToken }, function(response) {
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
        $.post('api.php', { 
            action: 'captcha_check', 
            answer: answer,
            csrf_token: window.csrfToken 
        }, function(response) {
            if (response.success) {
                if (response.data.completed) {
                    // Success! Submit the real registration form
                    submitRegistration();
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
        
        $.post('api.php', formData, function(response) {
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
            url: 'api.php',
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
            url: 'api.php',
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
        url: 'api.php',
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
                        
                        $.post('api.php', {
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
    $('#register-form-wrapper, #social-auth-wrapper').hide();
    $('#login-form-wrapper').show();
    $('#login-modal').css('display', 'flex').hide().fadeIn(200);
};

// Навигация внутри модалки
window.showLoginForm = function(e) {
    if(e) e.preventDefault();
    $('#register-form-wrapper, #social-auth-wrapper').hide();
    $('#login-form-wrapper').fadeIn(200);
};

window.showRegisterForm = function(e) {
    if(e) e.preventDefault();
    $('#login-form-wrapper, #social-auth-wrapper').hide();
    $('#register-form-wrapper').fadeIn(200);
};

window.showSocialAuth = function(e) {
    if(e) e.preventDefault();
    $('#login-form-wrapper, #register-form-wrapper').hide();
    $('#social-auth-wrapper').fadeIn(200);
};

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