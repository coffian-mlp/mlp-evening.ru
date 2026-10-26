(function(){
    'use strict';
    function start(){
        var root=document.querySelector('#stream-settings');if(!root||root._selfSettings)return;root._selfSettings=true;
        var message=root.querySelector('[data-self-stream-message]'),secrets=root.querySelector('[data-self-stream-secrets]'),key=root.querySelector('[data-self-stream-key]');
        async function request(action,button){
            button.disabled=true;
            try{var response=await fetch('/api.php',{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:action,csrf_token:window.csrfToken||''})});var result=await response.json();if(!result.success)throw Error(result.message||'Не удалось получить данные.');message.textContent=result.message;return result.data;}
            catch(error){message.textContent=error.message||'Медиасервер пока недоступен.';return null;}finally{button.disabled=false;}
        }
        root.querySelector('[data-self-stream-status]').onclick=async function(){var state=await request('self_hosted_stream_status',this);if(state&&state.live)message.textContent+=' Дорожки: '+state.tracks.join(', ')+'.';};
        root.querySelector('[data-self-stream-credentials]').onclick=async function(){var data=await request('self_hosted_stream_credentials',this);if(data){root.querySelector('[data-self-stream-server]').value=data.server;key.value=data.key;key.type='password';secrets.hidden=false;}};
        root.querySelector('[data-self-stream-reveal]').onclick=function(){key.type=key.type==='password'?'text':'password';this.textContent=key.type==='password'?'Показать ключ':'Скрыть ключ';};
        root.querySelector('[data-self-stream-copy]').onclick=async function(){try{await navigator.clipboard.writeText(key.value);message.textContent='Ключ скопирован.';}catch(error){key.type='text';key.select();message.textContent='Скопируй выделенный ключ вручную.';}};
        window.addEventListener('pagehide',function(){key.value='';secrets.hidden=true;});
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
