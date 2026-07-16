/**
 * PollWidget — переиспользуемый виджет опросов (MLP-239).
 * Рендерит карточку опроса из данных API, голосует, обновляется в realtime.
 * Используется в чате (монтируется на маркер [[poll:ID]]) и на любых страницах.
 *
 * Публичный API (window.PollWidget):
 *   mount(el)           — загрузить опрос по el.dataset.pollId и отрисовать в el
 *   update(el, data)    — обновить результаты из realtime-события (data.results/.voters)
 *   openCreateModal()   — модалка создания опроса (кнопка тулбара чата)
 *
 * Зависит от jQuery, window.currentUserId/currentUserRole, глобального лайтбокса
 * (клик по .chat-img), action=upload_file для загрузки картинок-вариантов.
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function canClose(poll) {
        if (!poll || poll.status !== 'open') return false;
        if (String(poll.created_by) === String(window.currentUserId)) return true;
        return window.currentUserRole === 'admin' || window.currentUserRole === 'moderator';
    }

    // "ник|цвет|аватар;;..." -> [{name,color,avatar}]
    function parseVoters(raw) {
        if (!raw) return [];
        return raw.split(';;').map(function (chunk) {
            var p = chunk.split('|');
            return { name: p[0] || '', color: p[1] || '', avatar: p[2] || '' };
        });
    }

    function votersTooltipHtml(raw) {
        var list = parseVoters(raw);
        if (!list.length) return '';
        return '<div class="poll-voters-tip">' + list.map(function (v) {
            var av = v.avatar ? '<img src="' + esc(v.avatar) + '" class="poll-voter-av" alt="">' : '';
            var nm = '<span style="color:' + (esc(v.color) || 'inherit') + '">' + esc(v.name) + '</span>';
            return '<span class="poll-voter">' + av + nm + '</span>';
        }).join('') + '</div>';
    }

    function render(el) {
        var st = el._poll;
        if (!st || !st.poll) return;
        var poll = st.poll;
        var results = st.results || { total_voters: 0, options: [] };
        var myVotes = st.myVotes || [];
        var voters = st.voters || {};
        var closed = poll.status !== 'open';

        var badges = '';
        if (poll.is_anonymous == 1) badges += '<span class="poll-badge" title="Анонимный опрос">🔒</span>';
        if (poll.is_multi == 1) badges += '<span class="poll-badge" title="Можно выбрать несколько">☑️</span>';
        if (closed) badges += '<span class="poll-badge poll-badge-closed">закрыт</span>';

        var optsHtml = (results.options || []).map(function (opt) {
            var mine = myVotes.indexOf(opt.id) !== -1 || myVotes.indexOf(String(opt.id)) !== -1;
            var img = opt.image_url
                ? '<img src="' + esc(opt.image_url) + '" class="poll-option-img chat-img" alt="">'
                : '';
            var tip = (poll.is_anonymous != 1) ? votersTooltipHtml(voters[opt.id]) : '';
            return '' +
                '<div class="poll-option' + (mine ? ' poll-option-mine' : '') + (closed ? ' poll-option-closed' : '') + '" ' +
                     'data-option-id="' + esc(opt.id) + '">' +
                    '<div class="poll-option-bar" style="width:' + (opt.percent || 0) + '%"></div>' +
                    '<div class="poll-option-content">' +
                        img +
                        '<span class="poll-option-text">' + esc(opt.text) + '</span>' +
                        '<span class="poll-option-stat">' + (opt.percent || 0) + '% · ' + (opt.votes || 0) + '</span>' +
                        (tip ? '<span class="poll-voters-wrap">' + tip + '</span>' : '') +
                    '</div>' +
                '</div>';
        }).join('');

        var footer = '<div class="poll-footer">' +
            '<span class="poll-total">Голосов: ' + (results.total_voters || 0) + '</span>' +
            (canClose(poll) ? '<button type="button" class="poll-close-btn">Закрыть опрос</button>' : '') +
            '</div>';

        el.innerHTML =
            '<div class="poll-card' + (closed ? ' poll-card-closed' : '') + '">' +
                '<div class="poll-question">' + esc(poll.question) +
                    (badges ? ' <span class="poll-badges">' + badges + '</span>' : '') +
                '</div>' +
                '<div class="poll-options">' + optsHtml + '</div>' +
                footer +
            '</div>';
    }

    function apiPost(action, data) {
        return $.post('/api.php', $.extend({ action: action }, data)); // CSRF-заголовок навешивается глобально ($.ajaxSetup)
    }

    function loadInto(el) {
        var pollId = el.dataset.pollId;
        apiPost('get_poll', { poll_id: pollId }).done(function (res) {
            if (!res || !res.success) { el.innerHTML = '<div class="poll-card poll-card-error">Опрос недоступен</div>'; return; }
            el._poll = {
                poll: res.data.poll,
                results: res.data.results,
                myVotes: res.data.my_votes || [],
                voters: res.data.voters || {}
            };
            render(el);
        }).fail(function () {
            el.innerHTML = '<div class="poll-card poll-card-error">Опрос недоступен</div>';
        });
    }

    function doVote(el, optionId) {
        var st = el._poll; if (!st) return;
        var poll = st.poll;
        if (poll.status !== 'open') return;
        if (!window.currentUserId) { alert('Войди, чтобы голосовать'); return; }

        var set;
        if (poll.is_multi == 1) {
            set = (st.myVotes || []).map(Number);
            var i = set.indexOf(Number(optionId));
            if (i === -1) set.push(Number(optionId)); else set.splice(i, 1);
        } else {
            set = [Number(optionId)];
        }

        apiPost('vote_poll', { poll_id: poll.id, 'option_ids': set }).done(function (res) {
            if (!res || !res.success) { if (res && res.message) alert(res.message); return; }
            st.results = res.data.results;
            st.myVotes = res.data.my_votes || [];
            if (res.data.voters !== undefined) st.voters = res.data.voters || {};
            render(el);
        });
    }

    function doClose(el) {
        var st = el._poll; if (!st) return;
        if (!confirm('Закрыть опрос? Голосование прекратится.')) return;
        apiPost('close_poll', { poll_id: st.poll.id }).done(function (res) {
            if (!res || !res.success) { if (res && res.message) alert(res.message); return; }
            st.poll.status = 'closed';
            st.results = res.data.results;
            render(el);
        });
    }

    // Делегированные обработчики (виджетов может быть много в ленте).
    $(document).on('click', '.poll-widget .poll-option', function (e) {
        // Клик по картинке-превью открывает лайтбокс (глобальный делегат по .chat-img), не голосует.
        if ($(e.target).hasClass('poll-option-img')) return;
        var el = $(this).closest('.poll-widget')[0];
        doVote(el, this.dataset.optionId);
    });
    $(document).on('click', '.poll-widget .poll-close-btn', function () {
        doClose($(this).closest('.poll-widget')[0]);
    });

    // ---------------- Публичный API ----------------
    window.PollWidget = {
        mount: function (el) {
            if (!el || el._pollMounted) return;
            el._pollMounted = true;
            loadInto(el);
        },
        update: function (el, data) {
            if (!el || !el._poll) { if (el) this.mount(el); return; }
            if (data.results) el._poll.results = data.results;
            if (data.voters !== undefined) el._poll.voters = data.voters || {};
            if (data.type === 'poll_closed') el._poll.poll.status = 'closed';
            render(el);
        },
        openCreateModal: function () {
            PollCreate.open();
        }
    };

    // ---------------- Модалка создания ----------------
    var PollCreate = (function () {
        var $modal = null;

        function optionRow() {
            return $(
                '<div class="pc-option">' +
                    '<input type="text" class="pc-opt-text" placeholder="Вариант ответа" maxlength="255">' +
                    '<input type="hidden" class="pc-opt-image">' +
                    '<button type="button" class="pc-opt-img-btn" title="Картинка к варианту">🖼️</button>' +
                    '<button type="button" class="pc-opt-remove" title="Удалить вариант">✕</button>' +
                    '<div class="pc-opt-preview"></div>' +
                '</div>'
            );
        }

        function build() {
            $modal = $(
                '<div class="pc-overlay">' +
                  '<div class="pc-modal">' +
                    '<div class="pc-head">📊 Новый опрос <button type="button" class="pc-close">✕</button></div>' +
                    '<input type="text" class="pc-question" placeholder="Вопрос опроса" maxlength="500">' +
                    '<div class="pc-options"></div>' +
                    '<button type="button" class="pc-add-option">+ добавить вариант</button>' +
                    '<label class="pc-flag"><input type="checkbox" class="pc-multi"> Несколько вариантов</label>' +
                    '<label class="pc-flag"><input type="checkbox" class="pc-anon"> Анонимный</label>' +
                    '<div class="pc-actions">' +
                        '<button type="button" class="pc-submit">Создать опрос</button>' +
                    '</div>' +
                    '<input type="file" class="pc-file" accept="image/*" style="display:none">' +
                  '</div>' +
                '</div>'
            );
            var $opts = $modal.find('.pc-options');
            $opts.append(optionRow()).append(optionRow());
            $('body').append($modal);
            wire();
        }

        function wire() {
            $modal.on('click', '.pc-close, .pc-overlay', function (e) {
                if (e.target === this) close();
            });
            $modal.find('.pc-add-option').on('click', function () {
                if ($modal.find('.pc-option').length >= 10) return;
                $modal.find('.pc-options').append(optionRow());
            });
            $modal.on('click', '.pc-opt-remove', function () {
                if ($modal.find('.pc-option').length <= 2) return;
                $(this).closest('.pc-option').remove();
            });
            // Загрузка картинки варианта (нативно, переиспользуем upload_file).
            var $targetRow = null;
            $modal.on('click', '.pc-opt-img-btn', function () {
                $targetRow = $(this).closest('.pc-option');
                $modal.find('.pc-file').val('').trigger('click');
            });
            $modal.find('.pc-file').on('change', function () {
                var file = this.files && this.files[0];
                if (!file || !$targetRow) return;
                var fd = new FormData();
                fd.append('action', 'upload_file');
                fd.append('file', file);
                var $row = $targetRow;
                $row.find('.pc-opt-preview').text('загрузка…');
                $.ajax({ url: '/api.php', type: 'POST', data: fd, processData: false, contentType: false })
                    .done(function (res) {
                        if (res && res.success && res.data && res.data.url) {
                            $row.find('.pc-opt-image').val(res.data.url);
                            $row.find('.pc-opt-preview').html('<img src="' + res.data.url + '" alt=""><button type="button" class="pc-opt-img-clear">убрать</button>');
                        } else {
                            $row.find('.pc-opt-preview').text('ошибка загрузки');
                        }
                    })
                    .fail(function () { $row.find('.pc-opt-preview').text('ошибка загрузки'); });
            });
            $modal.on('click', '.pc-opt-img-clear', function () {
                var $row = $(this).closest('.pc-option');
                $row.find('.pc-opt-image').val('');
                $row.find('.pc-opt-preview').empty();
            });
            $modal.find('.pc-submit').on('click', submit);
        }

        function submit() {
            var question = $modal.find('.pc-question').val().trim();
            var options = [], images = [];
            $modal.find('.pc-option').each(function () {
                var t = $(this).find('.pc-opt-text').val().trim();
                var img = $(this).find('.pc-opt-image').val().trim();
                if (t !== '' || img !== '') { options.push(t); images.push(img); }
            });
            if (question === '' || options.length < 2) {
                alert('Нужен вопрос и хотя бы 2 варианта');
                return;
            }
            var $btn = $modal.find('.pc-submit').prop('disabled', true).text('Создаём…');
            apiPost('create_poll', {
                question: question,
                'options': options,
                'option_images': images,
                is_multi: $modal.find('.pc-multi').is(':checked') ? 1 : 0,
                is_anonymous: $modal.find('.pc-anon').is(':checked') ? 1 : 0
            }).done(function (res) {
                if (res && res.success) { close(); } // опрос прилетит в чат сообщением-карточкой (realtime)
                else { alert((res && res.message) || 'Ошибка создания'); $btn.prop('disabled', false).text('Создать опрос'); }
            }).fail(function () {
                alert('Сетевая ошибка'); $btn.prop('disabled', false).text('Создать опрос');
            });
        }

        function close() { if ($modal) { $modal.remove(); $modal = null; } }

        return { open: function () { if (!$modal) build(); } };
    })();

    // Автомонтирование на обычных страницах (в чате виджеты монтирует сам чат по мере появления).
    $(function () {
        $('.poll-widget[data-poll-id]').each(function () { window.PollWidget.mount(this); });
    });
})();
