(function(){
    'use strict';
    function start(){
        document.querySelectorAll('.video-stage').forEach(function(stage){
            if(stage._emotionsReady)return;stage._emotionsReady=true;
            var overlay=stage.querySelector('.stream-emotions'),panel=stage.querySelector('.stream-reaction-panel'),toggle=stage.querySelector('.stream-reaction-toggle'),checkbox=stage.querySelector('.stream-emotions-enabled');
            var buttons=Array.from(stage.querySelectorAll('[data-stream-reaction]')),hint=stage.querySelector('.stream-reaction-hint');
            var catalog={};buttons.forEach(function(button){catalog[button.dataset.streamReaction]=button.textContent;});
            var key='mlp.stream-emotions',seen=new Map(),cooldown=0,pending=false;
            try{checkbox.checked=localStorage.getItem(key)!=='off';}catch(error){}
            function clear(){overlay.replaceChildren();}
            function mediaAllowed(){return !!(window.privacyChoices&&window.privacyChoices.external_media);}
            function render(event){
                var data=event.detail;if(!data||!catalog[data.reaction]||typeof data.event_id!=='string'||!/^sr-\d{1,20}$/.test(data.event_id)||!Number.isFinite(data.expires_at))return;
                var now=Date.now();seen.forEach(function(expires,id){if(expires<=now)seen.delete(id);});
                if(data.expires_at<=now||data.expires_at>now+15000||seen.has(data.event_id))return;
                seen.set(data.event_id,data.expires_at);if(seen.size>256)seen.delete(seen.keys().next().value);
                if(!checkbox.checked||!mediaAllowed()||document.hidden||overlay.children.length>=12)return;
                var particle=document.createElement('span');particle.className='stream-emotion';particle.textContent=catalog[data.reaction];particle.style.left=(15+Math.random()*70)+'%';
                if(window.matchMedia('(prefers-reduced-motion: reduce)').matches)particle.classList.add('reduced-motion');
                overlay.appendChild(particle);setTimeout(function(){particle.remove();},2000);
            }
            window.addEventListener('mlp:stream-reaction',render);
            checkbox.onchange=function(){try{localStorage.setItem(key,checkbox.checked?'on':'off');}catch(error){}if(!checkbox.checked)clear();};
            window.addEventListener('storage',function(event){if(event.key===key){checkbox.checked=event.newValue!=='off';if(!checkbox.checked)clear();}});
            document.addEventListener('visibilitychange',function(){if(document.hidden)clear();});
            window.addEventListener('privacychange',function(){if(!mediaAllowed()){clear();panel.hidden=true;toggle.setAttribute('aria-expanded','false');}});
            toggle.onclick=function(){panel.hidden=!panel.hidden;toggle.setAttribute('aria-expanded',String(!panel.hidden));};
            document.addEventListener('click',function(event){if(!stage.querySelector('.stream-reaction-tools').contains(event.target)){panel.hidden=true;toggle.setAttribute('aria-expanded','false');}});
            panel.addEventListener('keydown',function(event){if(event.key==='Escape'){panel.hidden=true;toggle.setAttribute('aria-expanded','false');toggle.focus();}});
            function notice(text){if(window.showFlashMessage)window.showFlashMessage(text,'error');else hint.textContent=text;}
            function update(){var seconds=Math.max(0,Math.ceil((cooldown-Date.now())/1000));buttons.forEach(function(button){button.disabled=pending||seconds>0;});hint.textContent=seconds?'Следующая эмоция через '+seconds+' с':'Одна эмоция раз в 5 секунд';}
            buttons.forEach(function(button){button.onclick=async function(){
                if(pending||Date.now()<cooldown)return;
                if(!window.currentUserId){notice('Войди, чтобы отправлять эмоции.');return;}
                if(!mediaAllowed()){notice('Разреши загрузку плеера, чтобы отправлять эмоции к эфиру.');return;}
                pending=true;update();
                try{
                    var response=await fetch('/api.php',{method:'POST',body:new URLSearchParams({action:'send_stream_reaction',reaction:button.dataset.streamReaction,csrf_token:window.csrfToken||''})});
                    var out=await response.json();if(!out.success)throw Error(out.message);
                    cooldown=Date.now()+5000;window.dispatchEvent(new CustomEvent('mlp:stream-reaction',{detail:out.data.event}));
                }catch(error){notice(error.message||'Не удалось отправить эмоцию.');}finally{pending=false;update();}
            };});
            var timer=setInterval(update,500);window.addEventListener('pagehide',function(){clearInterval(timer);clear();});window.addEventListener('pageshow',function(event){if(event.persisted){clearInterval(timer);timer=setInterval(update,500);update();}});update();
            var fullscreen=stage.querySelector('.stream-fullscreen');if(!stage.requestFullscreen)fullscreen.hidden=true;
            fullscreen.onclick=async function(){try{if(document.fullscreenElement===stage)await document.exitFullscreen();else await stage.requestFullscreen();}catch(error){notice('Полноэкранный режим сейчас недоступен.');}};
        });
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
