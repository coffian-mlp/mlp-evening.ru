(function(){
    'use strict';
    function mount(root){
        if(root._selfHosted)return;root._selfHosted=true;
        var video=root.querySelector('video'),box=root.querySelector('.self-hosted-state'),status=box.querySelector('[role=status]'),retry=box.querySelector('button');
        var hls=null,timer=null,controller=null,generation=0,closed=false;
        var key='mlp.self-hosted.volume';
        video.muted=true;
        try{var saved=localStorage.getItem(key);if(saved!==null&&saved.trim()!==''&&Number.isFinite(Number(saved))&&Number(saved)>=0&&Number(saved)<=1){video.volume=Number(saved);}}catch(error){}
        function notice(text){status.textContent=text;box.hidden=false;}
        function cleanup(){generation++;clearTimeout(timer);if(controller)controller.abort();controller=null;if(hls)hls.destroy();hls=null;video.pause();video.removeAttribute('src');video.load();}
        function reconnect(){if(closed)return;cleanup();notice('Эфир пока недоступен. Подключимся автоматически, когда появится поток.');timer=setTimeout(connect,10000);}
        function play(){video.play().catch(function(){notice('Нажми ▶ на плеере, чтобы начать просмотр.');});}
        async function connect(){
            cleanup();closed=false;var current=generation;notice('Подключаемся к эфиру…');controller=new AbortController();var timeout=setTimeout(function(){if(controller)controller.abort();},7000);
            try{
                var response=await fetch(root.dataset.selfHostedUrl,{cache:'no-store',credentials:'same-origin',signal:controller.signal});
                clearTimeout(timeout);if(current!==generation)return;if(!response.ok){reconnect();return;}
                if(video.canPlayType('application/vnd.apple.mpegurl')){video.src=root.dataset.selfHostedUrl;video.load();play();}
                else if(window.Hls&&Hls.isSupported()){
                    hls=new Hls({lowLatencyMode:false,maxBufferLength:12,maxMaxBufferLength:20,backBufferLength:0,maxBufferSize:12000000});
                    hls.on(Hls.Events.MANIFEST_PARSED,play);
                    hls.on(Hls.Events.ERROR,function(event,data){if(current===generation&&data.fatal)reconnect();});
                    hls.loadSource(root.dataset.selfHostedUrl);hls.attachMedia(video);
                }else{notice('Браузер не поддерживает этот формат эфира. Попробуй обновить браузер.');}
            }catch(error){clearTimeout(timeout);if(current===generation)reconnect();}
        }
        video.addEventListener('playing',function(){box.hidden=true;});
        video.addEventListener('error',function(){if(!closed&&(video.currentSrc||hls))reconnect();});
        video.addEventListener('ended',reconnect);
        video.addEventListener('volumechange',function(){try{localStorage.setItem(key,String(video.volume));}catch(error){}});
        retry.onclick=function(){video.muted=false;connect();};
        window.addEventListener('pagehide',function(){closed=true;cleanup();});
        window.addEventListener('pageshow',function(event){if(event.persisted)connect();});
        connect();
    }
    function start(){document.querySelectorAll('[data-self-hosted-url]').forEach(mount);}
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
