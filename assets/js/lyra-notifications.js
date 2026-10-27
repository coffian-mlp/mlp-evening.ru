/** Profile-only bot access; opening Telegram always requires a deliberate click. */
(function () {
    'use strict';
    function init() {
        const box=document.getElementById('lyra-notifications');
        if(!box)return;
        const status=box.querySelector('[role="status"]'),connect=document.getElementById('lyra-notifications-connect'),
            disconnect=document.getElementById('lyra-notifications-disconnect'),link=document.getElementById('lyra-notifications-link');
        async function api(action) {
            const response=await fetch('/api.php',{method:'POST',credentials:'same-origin',body:new URLSearchParams({action,csrf_token:window.csrfToken||document.querySelector('meta[name="csrf-token"]')?.content||''})});
            const result=await response.json();
            if(!result.success)throw new Error(result.message||'Не удалось проверить подключение.');
            return result.data;
        }
        async function refresh() {
            try {
                const data=await api('lyra_notifications_status');
                status.textContent=data.connected?'Уведомления Лиры подключены.':!data.configured?'Администратор ещё не подключил бота Лиры.':!data.telegram_linked?'Сначала привяжи Telegram в блоке соцсетей выше.':'Уведомления Лиры не подключены.';
                connect.hidden=data.connected||!data.telegram_linked||!data.configured;
                disconnect.hidden=!data.connected;
                if(data.connected){link.hidden=true;connect.hidden=true;}
                if(!data.telegram_linked)link.hidden=true;
            }catch(e){status.textContent=e.message;}
        }
        connect.addEventListener('click',async()=>{
            connect.disabled=true;
            try {
                const data=await api('lyra_notifications_connect');
                const url=new URL(data.url);
                if(url.origin!=='https://t.me')throw new Error('Не удалось получить ссылку на бота.');
                link.href=url.href;link.hidden=false;connect.hidden=true;
                status.textContent='Открой бота по кнопке и нажми «Начать». Используй тот же Telegram, который привязан к профилю. Ссылка действует 10 минут.';
            }catch(e){status.textContent=e.message;}finally{connect.disabled=false;}
        });
        disconnect.addEventListener('click',async()=>{disconnect.disabled=true;try{await api('lyra_notifications_disconnect');await refresh();}catch(e){status.textContent=e.message;}finally{disconnect.disabled=false;}});
        window.addEventListener('focus',refresh);
        document.addEventListener('lyra-social-changed',refresh);
        document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
        refresh();
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
}());
