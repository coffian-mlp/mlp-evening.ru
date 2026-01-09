// --- 6. Загрузка списка соцсетей в профиле (ВЫНЕСЕНО) ---
window.openProfileModal = function(e) {
    if(e) e.preventDefault();
    
    // Используем callback, чтобы грузить виджет только когда модалка ВИДИМА
    $('#profile-modal').css('display', 'flex').hide().fadeIn(200, function() {
        // console.log('Profile Modal visible. Loading socials...');
        loadUserSocials();
    });
};

function loadUserSocials() {
    var $container = $('#telegram-bind-container');
    if (!$container.length) return;

    $.ajax({
        url: 'api.php',
        method: 'POST',
        data: { 
            action: 'get_user_socials',
            csrf_token: $('meta[name="csrf-token"]').attr('content')
        },
        success: function(resp) {
            // console.log('AJAX Success:', resp);
            if (resp.success) {
                var telegram = resp.data.socials.find(s => s.provider === 'telegram');
                
                // Скрываем лоадер
                $container.find('.loading-text').hide();

                var $widget = $('#telegram-widget-wrapper');
                var $status = $('#telegram-status-text');

                if (telegram) {
                    // Уже привязан -> Скрываем виджет, показываем статус
                    // Мы не удаляем виджет (.remove()), чтобы он не сломался, если пользователь выйдет
                    // Но можно и удалить, если не планируем отвязку "на лету" без перезагрузки
                    $widget.hide();
                    $status.text('✓ ' + (telegram.username || telegram.first_name)).show();
                } else {
                    // Не привязан -> Вставляем виджет, если его нет
                    $status.hide();
                    
                    // Если виджета нет (первый раз или был удален), вставляем его
                    if ($widget.find('iframe').length === 0 && $widget.find('script').length === 0) {
                        if (window.telegramBotUsername) {
                             var widgetHtml = '<script async src="https://telegram.org/js/telegram-widget.js?22" ' +
                                             'data-telegram-login="' + window.telegramBotUsername + '" ' +
                                             'data-size="medium" ' +
                                             'data-userpic="false" ' +
                                             'data-radius="5" ' +
                                             'data-onauth="onTelegramAuth(user)" ' +
                                             'data-request-access="write"></script>';
                            
                            $widget.html(widgetHtml);
                        } else {
                             $container.html('<small style="color:red">Ошибка конфига</small>');
                        }
                    }
                    $widget.show();
                }
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            $container.find('.loading-text').text('Ошибка сети');
        }
    });
}

// Обработчик открытия модалки логина
window.openLoginModal = function(e) {
    if(e) e.preventDefault();
    $('#login-modal').fadeIn(200);
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
