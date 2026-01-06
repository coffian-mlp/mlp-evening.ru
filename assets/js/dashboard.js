$(document).ready(function() {
    
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

        // Обновляем URL хеш
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

    // --- AJAX обработка форм (Специфично для Dashbaord) ---
    // В main.js уже есть настройки CSRF, так что тут просто делаем запросы
    $("form").on("submit", function(e) {
        e.preventDefault(); 
        
        var $form = $(this);
        var $btn = $form.find("button[type='submit']");
        var originalText = $btn.text();
        
        $btn.prop("disabled", true).text("⏳...");

        $.ajax({
            url: $form.attr("action"),
            type: $form.attr("method"),
            data: $form.serialize(),
            dataType: "json",
            success: function(response) {
                if (response.data && response.data.reload) {
                    location.reload();
                    return;
                }

                // Используем глобальную функцию из main.js
                window.showFlashMessage(response.message, response.type);
                
                if (response.success) {
                    $form.find("input[type='text'], input[type='number']").val("");
                    
                    var action = $form.find("input[name='action']").val();
                    if (action === 'clear_watching_log') {
                        $("#tab-history table tr:not(:first)").remove();
                        $("#tab-history table").append("<tr><td colspan='3' style='text-align:center; color:#999;'>История пуста (обновите страницу)</td></tr>");
                    }
                }
            },
            error: function(xhr, status, error) {
                window.showFlashMessage("❌ Ошибка соединения: " + error, "error");
            },
            complete: function() {
                if (!$btn.prop("disabled") === false) { 
                     $btn.prop("disabled", false).text(originalText);
                }
            }
        });
    });

});
