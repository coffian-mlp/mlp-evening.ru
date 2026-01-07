$(document).ready(function() {
    
    // --- Логика переключения вкладок ---
    $(".nav-tile").click(function() {
        $(".nav-tile").removeClass("active");
        $(".tab-content").removeClass("active");
        $(this).addClass("active");
        
        var target = $(this).data("target");
        $(target).addClass("active");

        if(history.pushState) {
            history.pushState(null, null, target);
        } else {
            window.location.hash = target;
        } 

        if (target === '#tab-users') {
            loadUsers();
        }
    });

    // --- Проверка хеша при загрузке ---
    if (window.location.hash) {
        setTimeout(function() {
            window.scrollTo(0, 0);
        }, 1);
        
        var $targetTile = $('.nav-tile[data-target="' + window.location.hash + '"]');
        if ($targetTile.length) {
            $targetTile.click();
        }
    }

    // --- Логика поиска по таблице ---
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

    // --- AJAX обработка форм (Dashboard + User Modal) ---
    $("form").not('#profile-form').on("submit", function(e) {
        e.preventDefault(); 
        
        var $form = $(this);
        var $btn = $form.find("button[type='submit']");
        var originalText = $btn.text();
        
        if ($form.attr('id') === 'user-form') {
            var pass = $('#user_password').val();
            var id = $('#user_id').val();
            if (!id && !pass) {
                window.showFlashMessage("Пароль обязателен для нового пользователя", "error");
                return;
            }
        }

        $btn.prop("disabled", true).text("⏳...");

        // Use FormData for all forms to support files
        var formData = new FormData(this);

        $.ajax({
            url: $form.attr("action"),
            type: $form.attr("method"),
            data: formData,
            processData: false,
            contentType: false,
            dataType: "json",
            success: function(response) {
                if (response.data && response.data.reload) {
                    location.reload();
                    return;
                }

                window.showFlashMessage(response.message, response.type);
                
                if (response.success) {
                    $form.find("input[type='text'], input[type='number'], input[type='password'], input[type='url'], input[type='file']").val("");
                    
                    var action = $form.find("input[name='action']").val();
                    if (action === 'clear_watching_log') {
                        $("#tab-history table tr:not(:first)").remove();
                        $("#tab-history table").append("<tr><td colspan='3' style='text-align:center; color:#999;'>История пуста (обновите страницу)</td></tr>");
                    }
                    
                    if (action === 'save_user') {
                        closeUserModal();
                        loadUsers();
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

    // --- Обработка формы профиля (Profile Page) ---
    $('#profile-form').on('submit', function(e) {
        e.preventDefault();
        const form = $(this);
        const btn = form.find('button[type="submit"]');
        const originalText = btn.text();
        
        btn.prop('disabled', true).text('Сохранение...');
        
        $.post(form.attr('action'), form.serialize(), function(res) {
            if (res.success) {
                showFlashMessage(res.message, 'success');
                if (res.data && res.data.reload) {
                    setTimeout(() => location.reload(), 1000);
                }
            } else {
                showFlashMessage(res.message || 'Ошибка сохранения', 'error');
                btn.prop('disabled', false).text(originalText);
            }
        }, 'json')
        .fail(function() {
            showFlashMessage('Ошибка сервера', 'error');
            btn.prop('disabled', false).text(originalText);
        });
    });

}); // End document.ready

// --- Глобальные функции ---

function loadUsers() {
    var $tbody = $('#users-table tbody');
    $tbody.html('<tr><td colspan="4" style="text-align:center;">Загрузка...</td></tr>');
    
    $.ajax({
        url: 'api.php',
        method: 'POST',
        data: { action: 'get_users' },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $tbody.empty();
                if (res.data.users.length === 0) {
                    $tbody.html('<tr><td colspan="4" style="text-align:center;">Нет пользователей</td></tr>');
                    return;
                }
                
                res.data.users.forEach(function(u) {
                    var avatar = u.avatar_url 
                        ? `<img src="${escapeHtml(u.avatar_url)}" style="width:24px;height:24px;border-radius:50%;vertical-align:middle;margin-right:8px;border:1px solid #eee;">` 
                        : '';
                    var nameDisplay = `<span style="color:${escapeHtml(u.chat_color || '#333')}; font-weight:bold;">${escapeHtml(u.nickname)}</span>`;

                    var row = `
                        <tr>
                            <td>${u.id}</td>
                            <td>${escapeHtml(u.login)}</td>
                            <td>${avatar}${nameDisplay}</td>
                            <td><span class="status-badge ${u.role === 'admin' ? 'old' : 'fresh'}">${u.role}</span></td>
                            <td>${u.created_at ? u.created_at : '-'}</td>
                            <td style="text-align: right;">
                                <button class="btn-warning" onclick='editUser(${JSON.stringify(u)})' style="padding: 5px 10px; font-size: 0.9em;">✏️</button>
                                <button class="btn-danger" onclick="deleteUser(${u.id})" style="padding: 5px 10px; font-size: 0.9em;">🗑️</button>
                            </td>
                        </tr>
                    `;
                    $tbody.append(row);
                });
            } else {
                var errorMsg = res.message || 'Неизвестная ошибка';
                $tbody.html('<tr><td colspan="4" style="text-align:center; color:red;">Ошибка: ' + escapeHtml(errorMsg) + '</td></tr>');
            }
        },
        error: function(xhr, status, error) {
             $tbody.html('<tr><td colspan="4" style="text-align:center; color:red;">Сбой сети: ' + escapeHtml(error) + ' <br> ' + xhr.responseText + '</td></tr>');
        }
    });
}

function openUserModal() {
    $('#user-modal').css('display', 'flex').hide().fadeIn(200);
    $('#user_id').val('');
    $('#user_login').val('');
    $('#user_nickname').val('');
    $('#user_avatar_file').val('');
    $('#user_avatar_url').val('');
    $('#user_chat_color').val('#6d2f8e');
    $('#user_password').val('');
    $('#user_role').val('user');
    $('#user-modal-title').text('Новый пони');
}

function closeUserModal() {
    $('#user-modal').fadeOut(200);
}

function editUser(user) {
    $('#user-modal').css('display', 'flex').hide().fadeIn(200);
    $('#user_id').val(user.id);
    $('#user_login').val(user.login);
    $('#user_nickname').val(user.nickname);
    $('#user_avatar_file').val('');
    $('#user_avatar_url').val(user.avatar_url || '');
    $('#user_chat_color').val(user.chat_color || '#6d2f8e');
    $('#user_password').val('');
    $('#user_role').val(user.role);
    $('#user-modal-title').text('Редактировать пони');
}

function deleteUser(id) {
    if (!confirm('Точно изгнать этого пони?')) return;
    
    $.ajax({
        url: 'api.php',
        method: 'POST',
        data: { action: 'delete_user', user_id: id },
        dataType: 'json',
        success: function(res) {
            window.showFlashMessage(res.message, res.type);
            if (res.success) {
                loadUsers();
            }
        },
        error: function(xhr, status, error) {
            window.showFlashMessage("Ошибка сети: " + error, 'error');
        }
    });
}

function escapeHtml(text) {
    if (text == null) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
