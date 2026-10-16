(function () {
    'use strict';
    var panel = document.getElementById('privacy-panel'), dialog = document.getElementById('privacy-dialog');
    var choices = window.privacyChoices || {decided:false,analytics:false,external_media:false};
    var busy = false, previousFocus;
    function notice(text, error) {
        if (window.showFlashMessage) window.showFlashMessage(text, error ? 'error' : 'success');
        document.getElementById('privacy-error').textContent = error ? text : '';
    }
    async function post(data) {
        var response = await fetch('/api.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams(Object.assign({csrf_token:window.csrfToken},data))});
        var result = await response.json();
        if (!result.success && result.data && result.data.redirect && result.data.redirect !== location.pathname) location.assign(result.data.redirect);
        if (!result.success) throw Error(result.message || 'Не удалось сохранить выбор.');
        return result.data;
    }
    function position() {
        var player = document.querySelector('.video-content'), chat = document.querySelector('.chat-container');
        var header = document.querySelector('.video-container .header');
        var width = Math.min(720, innerWidth - 24), left = (innerWidth-width)/2, top = 12, height = innerHeight - 24;
        panel.classList.remove('privacy-panel--compact');
        if (player && chat) {
            var p = player.getBoundingClientRect(), c = chat.getBoundingClientRect();
            var h = header && header.getBoundingClientRect(), logo = document.querySelector('.video-container .site-logo');
            var l = logo && logo.getBoundingClientRect();
            // The header's spare area is preferred; narrow layouts use the chat area, below video.
            if (innerWidth > 900 && h && h.height >= 130 && h.right-(l ? l.right : h.left)-24 >= 430) {
                width = Math.min(720,h.right-(l ? l.right : h.left)-24); left=h.right-width; top=h.top; height=h.height-8;
            } else {
                left=c.left+8; width=c.width-16; top=c.top+8; height=Math.max(90,innerHeight-top-28);
                if(top>innerHeight-100 && h){
                    left=h.left;width=h.width;top=h.top;height=h.height;
                    panel.classList.add('privacy-panel--compact');
                }
            }
        } else {
            var siteHeader = document.querySelector('.main-header');
            if (siteHeader) top = Math.max(12,siteHeader.getBoundingClientRect().bottom+8);
            height = Math.max(100,innerHeight-top-24);
        }
        Object.assign(panel.style,{left:left+'px',top:top+'px',width:width+'px',maxHeight:height+'px'});
        document.querySelector('.privacy-settings-link').style.left=(player&&chat?chat.getBoundingClientRect().left+6:6)+'px';
    }
    function changed(next) {
        choices = window.privacyChoices = next;
        panel.hidden = next.decided;
        document.getElementById('privacy-analytics').checked = !!next.analytics;
        document.getElementById('privacy-external').checked = !!next.external_media;
        window.dispatchEvent(new CustomEvent('privacychange',{detail:next}));
    }
    async function save(analytics, external) {
        if (busy) return; busy=true;
        document.querySelectorAll('[data-privacy-save], [data-privacy-necessary], [data-privacy-all], [data-privacy-media]').forEach(el=>el.disabled=true);
        try {
            var result=await post({action:'privacy_choices',analytics:analytics?'1':'0',external_media:external?'1':'0'});
            changed(result.choices); if (dialog.open) dialog.close();
            // Notify other tabs without creating a tracking identifier.
            if (channel) channel.postMessage('changed');
        } catch(error) {notice(error.message,true);}
        finally {busy=false;document.querySelectorAll('[data-privacy-save], [data-privacy-necessary], [data-privacy-all], [data-privacy-media]').forEach(el=>el.disabled=false);}
    }
    document.addEventListener('click', function(event) {
        var exit=event.target.closest('[data-privacy-exit]');
        if(exit && !exit.dataset.confirmed){
            event.preventDefault();
            window.privacyExiting=true;
            window.dispatchEvent(new Event('privacyexit'));
            changed({decided:true,analytics:false,external_media:false});
            post({action:'privacy_exit'}).then(()=>{
                try{localStorage.clear();sessionStorage.clear();}catch(error){}
                if(channel)channel.postMessage('changed');
                exit.dataset.confirmed='1';exit.click();
            }).catch(error=>{window.privacyExiting=false;notice(error.message,true);});
            return;
        }
        var review=event.target.closest('[data-review-file]');
        if(review){
            if(review.dataset.reviewDecision==='belongs' && !confirm('Подтвердить принадлежность этого файла? Он будет удалён вместе с данными аккаунта: '+review.dataset.reviewFile))return;
            review.disabled=true;
            post({action:'privacy_file_review',path:review.dataset.reviewFile,decision:review.dataset.reviewDecision})
                .then(()=>review.closest('.privacy-file-review').remove()).catch(error=>{review.disabled=false;notice(error.message,true);});
            return;
        }
        var telegram=event.target.closest('[data-load-telegram]');
        if(telegram){
            var source=telegram.parentElement.querySelector('template[data-telegram-widget] script');
            if(source){var script=document.createElement('script');Array.from(source.attributes).forEach(a=>script.setAttribute(a.name,a.value));telegram.replaceWith(script);}
            return;
        }
        var target=event.target.closest('[data-privacy-open], [data-privacy-save], [data-privacy-necessary], [data-privacy-all], [data-privacy-media], [data-account-consent]');
        if (!target) return;
        if (target.hasAttribute('data-privacy-open')) {event.preventDefault();previousFocus=target;dialog.showModal();}
        if (target.hasAttribute('data-privacy-save')) save(document.getElementById('privacy-analytics').checked,document.getElementById('privacy-external').checked);
        if (target.hasAttribute('data-privacy-necessary')) save(false,false);
        if (target.hasAttribute('data-privacy-all')) save(true,true);
        if (target.hasAttribute('data-privacy-media')) save(!!choices.analytics,true);
        if (target.hasAttribute('data-account-consent')) {
            var accept=target.dataset.accountConsent==='accept';
            if (!accept && !confirm('Отозвать согласие? Учётная запись сразу станет неактивной. Данные будут удалены в течение 30 дней. До удаления можно дать согласие повторно.')) return;
            if (accept && !document.getElementById('account-consent-check').checked) {notice('Отметь отдельное согласие на обработку данных аккаунта.',true);return;}
            target.disabled=true;
            post({action:'privacy_account',decision:accept?'accept':'withdraw',confirm:accept?'':'withdraw',policy_version:target.dataset.version})
                .then(result=>{if(channel)channel.postMessage('changed');location.assign(result.redirect);})
                .catch(error=>{target.disabled=false;notice(error.message,true);});
        }
    });
    dialog.addEventListener('close',()=>{if(previousFocus && previousFocus.isConnected)previousFocus.focus();});
    var channel = typeof BroadcastChannel==='function' ? new BroadcastChannel('mlp-privacy') : null;
    async function refresh() {
        try {
            var result=await post({action:'privacy_status'});
            changed(result.choices);
            if(result.account && result.account.state==='withdrawn' && !['/profile.php','/privacy.php'].includes(location.pathname))location.assign('/profile.php');
        }catch(error){/* Next navigation renews an expired session. Server collection remains gated. */}
    }
    if (channel) channel.onmessage=refresh;
    window.addEventListener('resize',position); window.addEventListener('load',position);
    if (typeof ResizeObserver==='function') {
        var observer=new ResizeObserver(position);
        document.querySelectorAll('.video-content,.chat-container,.video-container .header').forEach(el=>observer.observe(el));
    }
    changed(choices);position();
    // A foreground tab revalidates the server decision after sleeping, without periodic polling.
    document.addEventListener('visibilitychange',function(){if(!document.hidden)refresh();});
    window.addEventListener('pageshow',function(event){if(event.persisted)refresh();});
})();
