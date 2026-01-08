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
        if (target === '#tab-moderation') {
            loadPunishedUsers();
            loadAuditLogs();
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

    // --- Логика поиска по таблице серий ---
    $("#searchInput").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#fulltable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });

    // --- Логика поиска по таблице пользователей ---
    $("#userSearchInput").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#users-table tbody tr").filter(function() {
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

    // --- AJAX обработка форм (Dashboard + User Modal + Mod Modals) ---
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
                    // Reset selects if any
                    $form.find("select").prop('selectedIndex', 0);
                    
                    var action = $form.find("input[name='action']").val();
                    if (action === 'clear_watching_log') {
                        $("#tab-history table tr:not(:first)").remove();
                        $("#tab-history table").append("<tr><td colspan='3' style='text-align:center; color:#999;'>История пуста (обновите страницу)</td></tr>");
                    }
                    
                    if (action === 'save_user') {
                        closeUserModal();
                        loadUsers();
                    }
                    if (action === 'ban_user' || action === 'mute_user') {
                        closeModal('#ban-modal');
                        closeModal('#mute-modal');
                        // Refresh both tables
                        loadUsers();
                        loadPunishedUsers();
                        loadAuditLogs();
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
    $tbody.html('<tr><td colspan="7" style="text-align:center;">Загрузка...</td></tr>');
    
    $.ajax({
        url: 'api.php',
        method: 'POST',
        data: { action: 'get_users' },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $tbody.empty();
                if (res.data.users.length === 0) {
                    $tbody.html('<tr><td colspan="7" style="text-align:center;">Нет пользователей</td></tr>');
                    return;
                }
                
                res.data.users.forEach(function(u) {
                    var avatar = u.avatar_url 
                        ? `<img src="${escapeHtml(u.avatar_url)}" style="width:24px;height:24px;border-radius:50%;vertical-align:middle;margin-right:8px;border:1px solid #eee;">` 
                        : '';
                    var nameDisplay = `<span style="color:${escapeHtml(u.chat_color || '#333')}; font-weight:bold;">${escapeHtml(u.nickname)}</span>`;

                    // Status
                    var status = '';
                    if (u.is_banned == 1) status += '<span class="status-badge old" title="'+escapeHtml(u.ban_reason)+'">BANNED</span> ';
                    if (u.is_muted) status += '<span class="status-badge" style="background:orange;color:white;" title="Until: '+u.muted_until+'">MUTED</span>';
                    if (!status) status = '<span style="color:#aaa;">OK</span>';

                    var row = `
                        <tr>
                            <td>${u.id}</td>
                            <td>${escapeHtml(u.login)}</td>
                            <td>${avatar}${nameDisplay}</td>
                            <td><span class="status-badge ${u.role === 'admin' ? 'old' : 'fresh'}">${u.role}</span></td>
                            <td>${status}</td>
                            <td>${u.created_at ? u.created_at : '-'}</td>
                            <td style="text-align: right;">
                                <button class="btn-warning" onclick='editUser(${JSON.stringify(u)})' style="padding: 5px 10px; font-size: 0.9em;">✏️</button>
                                <button class="btn-danger" onclick="deleteUser(${u.id})" style="padding: 5px 10px; font-size: 0.9em;" title="Удалить">🗑️</button>
                                ${u.is_banned == 1 
                                    ? `<button class="btn-primary" onclick="unbanUser(${u.id})" style="padding: 5px 10px; font-size: 0.9em;" title="Разбанить">🕊️</button>`
                                    : `<button class="btn-danger" onclick='openBanModal(${u.id}, "${escapeHtml(u.nickname)}")' style="padding: 5px 10px; font-size: 0.9em;" title="Бан">🔨</button>`
                                }
                                ${u.is_muted 
                                    ? `<button class="btn-primary" onclick="unmuteUser(${u.id})" style="padding: 5px 10px; font-size: 0.9em;" title="Размутить">🗣️</button>`
                                    : `<button class="btn-warning" onclick='openMuteModal(${u.id}, "${escapeHtml(u.nickname)}")' style="padding: 5px 10px; font-size: 0.9em;" title="Мут">🤐</button>`
                                }
                            </td>
                        </tr>
                    `;
                    $tbody.append(row);
                });
            } else {
                var errorMsg = res.message || 'Неизвестная ошибка';
                $tbody.html('<tr><td colspan="7" style="text-align:center; color:red;">Ошибка: ' + escapeHtml(errorMsg) + '</td></tr>');
            }
        },
        error: function(xhr, status, error) {
             $tbody.html('<tr><td colspan="7" style="text-align:center; color:red;">Сбой сети: ' + escapeHtml(error) + ' <br> ' + xhr.responseText + '</td></tr>');
        }
    });
}

function loadPunishedUsers() {
    var $tbody = $('#punished-users-table tbody');
    $tbody.html('<tr><td colspan="5" style="text-align:center;">Загрузка...</td></tr>');
    
    $.ajax({
        url: 'api.php',
        method: 'POST',
        data: { action: 'get_users' }, // Reuse get_users and filter client-side
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $tbody.empty();
                
                var punished = res.data.users.filter(u => u.is_banned == 1 || u.is_muted);
                
                if (punished.length === 0) {
                    $tbody.html('<tr><td colspan="5" style="text-align:center;">В Понивилле все спокойно 😇</td></tr>');
                    return;
                }
                
                punished.forEach(function(u) {
                    var type = '';
                    if (u.is_banned == 1) type += 'BAN ';
                    if (u.is_muted) type += 'MUTE ';
                    
                    var expires = u.is_banned == 1 ? 'Навсегда' : (u.muted_until || '-');

                    var row = `
                        <tr>
                            <td>${escapeHtml(u.nickname)} (${escapeHtml(u.login)})</td>
                            <td><span class="status-badge old">${type}</span></td>
                            <td>${escapeHtml(u.ban_reason || '-')}</td>
                            <td>${expires}</td>
                            <td style="text-align: right;">
                                ${u.is_banned == 1 ? `<button class="btn-primary" onclick="unbanUser(${u.id})">Разбанить</button>` : ''}
                                ${u.is_muted ? `<button class="btn-primary" onclick="unmuteUser(${u.id})">Размутить</button>` : ''}
                            </td>
                        </tr>
                    `;
                    $tbody.append(row);
                });
            }
        }
    });
}

function loadAuditLogs() {
    var $tbody = $('#audit-logs-table tbody');
    $tbody.html('<tr><td colspan="6" style="text-align:center;">Загрузка...</td></tr>');
    
    $.ajax({
        url: 'api.php',
        method: 'POST',
        data: { action: 'get_audit_logs' },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $tbody.empty();
                if (res.data.logs.length === 0) {
                    $tbody.html('<tr><td colspan="6" style="text-align:center;">В Понивилле все спокойно 📜</td></tr>');
                    return;
                }
                
                res.data.logs.forEach(function(log) {
                    var targetName = escapeHtml(log.target_nickname || log.target_login || '?');
                    var targetLink = log.target_id 
                        ? `<a href="#" onclick="openUserCard(${log.target_id}); return false;">${targetName}</a>`
                        : targetName;
                        
                    var row = `
                        <tr>
                            <td>${log.id}</td>
                            <td>${escapeHtml(log.mod_nickname || log.mod_login || 'System')}</td>
                            <td><b>${escapeHtml(log.action)}</b></td>
                            <td>${targetLink} (ID: ${log.target_id})</td>
                            <td>${escapeHtml(log.details || '')}</td>
                            <td>${log.created_at}</td>
                        </tr>
                    `;
                    $tbody.append(row);
                });
            }
        }
    });
}

// --- Modals ---

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
    
    // Init Pickers
    if (window.initColorPickers) window.initColorPickers();
    // Update active swatch manually for default
    $('.color-picker-ui .color-swatch').removeClass('active');
    $(`.color-picker-ui .color-swatch[data-color="#6d2f8e"]`).addClass('active');
}

function closeUserModal() {
    $('#user-modal').fadeOut(200);
}

function closeModal(selector) {
    $(selector).fadeOut(200);
}

function openUserCard(userId) {
    // Reuse the existing editUser logic but fetch fresh data first
    $.post('api.php', { action: 'get_users' }, function(res) {
        if (res.success) {
            var user = res.data.users.find(u => u.id == userId);
            if (user) {
                editUser(user);
            } else {
                window.showFlashMessage("Пользователь не найден", "error");
            }
        }
    }, 'json');
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
    
    // Init Pickers
    if (window.initColorPickers) window.initColorPickers();
    // Update active swatch
    const color = user.chat_color || '#6d2f8e';
    $('.color-picker-ui .color-swatch').removeClass('active');
    $(`.color-picker-ui .color-swatch[data-color="${color}"]`).addClass('active');
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

// --- Moderation Actions ---

function openBanModal(id, name) {
    $('#ban_user_id').val(id);
    $('#ban_username_display').text(name);
    $('#ban-modal').css('display', 'flex').hide().fadeIn(200);
}

function openMuteModal(id, name) {
    $('#mute_user_id').val(id);
    $('#mute_username_display').text(name);
    $('#mute-modal').css('display', 'flex').hide().fadeIn(200);
}

function unbanUser(id) {
    if (!confirm('Разбанить пользователя?')) return;
    $.post('api.php', { action: 'unban_user', user_id: id }, function(res) {
        showFlashMessage(res.message, res.success ? 'success' : 'error');
        if(res.success) { loadUsers(); loadPunishedUsers(); loadAuditLogs(); }
    }, 'json');
}

function unmuteUser(id) {
    if (!confirm('Снять мут?')) return;
    $.post('api.php', { action: 'unmute_user', user_id: id }, function(res) {
        showFlashMessage(res.message, res.success ? 'success' : 'error');
        if(res.success) { loadUsers(); loadPunishedUsers(); loadAuditLogs(); }
    }, 'json');
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
