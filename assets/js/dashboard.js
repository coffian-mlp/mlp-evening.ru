$(document).ready(function() {
    
    // --- CSRF Protection Setup ---
    // Автоматически добавляем токен во все AJAX запросы
    $.ajaxSetup({
        headers: {
            'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content')
        }
    });

    // --- Логика переключения вкладок ---
    $(".nav-tile").click(function() {
        // Убираем активный класс у всех плиток и контента
        $(".nav-tile").removeClass("active");
        $(".tab-content").removeClass("active");
        
        // Добавляем активный класс нажатой плитке
        $(this).addClass("active");
        
        // Показываем соответствующий контент
        var target = $(this).data("target");
        $(target).addClass("active");

        // Обновляем URL хеш (история браузера)
        // Используем replaceState, чтобы избежать дефолтного скролла к якорю
        if(history.pushState) {
            history.pushState(null, null, target);
        }
        else {
            window.location.hash = target;
        } 
    });

    // --- Проверка хеша при загрузке ---
    if (window.location.hash) {
        var $targetTile = $('.nav-tile[data-target="' + window.location.hash + '"]');
        if ($targetTile.length) {
            $targetTile.click();
        }
    }

    // --- Логика поиска по таблице (Библиотека) ---
    $("#searchInput").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#fulltable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });

    // --- Логика сортировки таблицы ---
    $('th').click(function(){
        var table = $(this).parents('table').eq(0);
        var rows = table.find('tr:gt(0)').toArray().sort(comparer($(this).index()));
        this.asc = !this.asc;
        if (!this.asc){rows = rows.reverse()}
        for (var i = 0; i < rows.length; i++){table.append(rows[i])}
    });

    function comparer(index) {
        return function(a, b) {
            var valA = getCellValue(a, index), valB = getCellValue(b, index);
            return $.isNumeric(valA) && $.isNumeric(valB) ? valA - valB : valA.localeCompare(valB);
        }
    }

    function getCellValue(row, index){ return $(row).children('td').eq(index).text() }

    // --- AJAX обработка форм ---
    $("form").on("submit", function(e) {
        e.preventDefault(); // Останавливаем обычную отправку формы
        
        var $form = $(this);
        var $btn = $form.find("button[type='submit']");
        var originalText = $btn.text();
        
        // Визуальная индикация загрузки
        $btn.prop("disabled", true).text("⏳...");

        $.ajax({
            url: $form.attr("action"),
            type: $form.attr("method"),
            data: $form.serialize(),
            dataType: "json",
            success: function(response) {
                // Если сервер просит перезагрузку (например, после регенерации плейлиста)
                if (response.data && response.data.reload) {
                    location.reload();
                    return;
                }

                // Показываем сообщение
                showFlashMessage(response.message, response.type);
                
                // Если успех
                if (response.success) {
                    // Очищаем поля ввода (кроме hidden)
                    $form.find("input[type='text'], input[type='number']").val("");
                    
                    // Специфичная логика для обновления интерфейса
                    var action = $form.find("input[name='action']").val();
                    
                    if (action === 'clear_watching_log') {
                        // Очищаем таблицу истории визуально
                        $("#tab-history table tr:not(:first)").remove();
                        $("#tab-history table").append("<tr><td colspan='3' style='text-align:center; color:#999;'>История пуста (обновите страницу)</td></tr>");
                    }
                }
            },
            error: function(xhr, status, error) {
                showFlashMessage("❌ Ошибка соединения: " + error, "error");
            },
            complete: function() {
                // Возвращаем кнопку в исходное состояние (если не было перезагрузки)
                if (!$btn.prop("disabled") === false) { // Если кнопка еще существует
                     $btn.prop("disabled", false).text(originalText);
                }
            }
        });
    });

    // --- Функция показа уведомлений ---
    function showFlashMessage(message, type) {
        // Удаляем старые сообщения
        $('.flash-message').remove();

        var alertClass = (type === 'error') ? 'alert-danger' : 'alert-success';
        
        var $msg = $('<div class="flash-message ' + alertClass + '">' + message + '</div>');
        $('body').append($msg);

        // Автоскрытие только для успеха
        if (type !== 'error') {
            setTimeout(function() {
                $msg.fadeOut(500, function() {
                    $(this).remove();
                });
            }, 3000);
        }
    }

});