(function(){
    'use strict';
    function mount(root){
        if(root._selfHosted)return;root._selfHosted=true;
        var video=root.querySelector('video'),box=root.querySelector('.self-hosted-state'),status=box.querySelector('[role=status]'),retry=box.querySelector('button');
        var toggle=root.querySelector('[data-stream-play]'),mute=root.querySelector('[data-stream-mute]'),volume=root.querySelector('[data-stream-volume]');
        var hls=null,timer=null,controller=null,generation=0,closed=false,pausedByUser=false,lastVolume=1;
        var key='mlp.self-hosted.volume';
        video.muted=true;
        try{var saved=localStorage.getItem(key);if(saved!==null&&saved.trim()!==''&&Number.isFinite(Number(saved))&&Number(saved)>=0&&Number(saved)<=1){video.volume=Number(saved);}}catch(error){}
        function syncControls(){
            var label=video.paused?'Смотреть прямой эфир':'Пауза',silent=video.muted||video.volume===0;
            toggle.disabled=video.readyState<1;toggle.setAttribute('aria-label',label);toggle.title=label;
            toggle.querySelector('path').setAttribute('d',video.paused?'M8 5v14l11-7z':'M6 5h4v14H6zm8 0h4v14h-4z');
            label=silent?'Включить звук':'Выключить звук';mute.setAttribute('aria-label',label);mute.title=label;
            mute.querySelector('[data-stream-sound-icon]').setAttribute('d',silent?'m16 9 6 6m0-6-6 6':'M16 8a6 6 0 0 1 0 8m3-11a10 10 0 0 1 0 14');
            volume.value=String(video.volume);volume.setAttribute('aria-valuetext',Math.round(video.volume*100)+'%');
            if(video.volume>0)lastVolume=video.volume;
        }
        function resumeLive(){
            var target=hls?hls.liveSyncPosition:null,ranges=video.seekable;
            if(!hls&&video.duration===Infinity){
                // Native HLS can omit seekable ranges; reloading then starts at the live edge.
                if(!ranges.length){notice('Подключаемся к эфиру…');video.load();return;}
                target=Math.max(ranges.start(ranges.length-1),ranges.end(ranges.length-1)-2);
            }
            if(Number.isFinite(target)&&ranges.length){
                var end=ranges.end(ranges.length-1),start=ranges.start(ranges.length-1);
                target=Math.max(start,Math.min(target,end-.1));
                if(video.currentTime<target-.5)video.currentTime=target;
            }
        }
        function notice(text){status.textContent=text;box.hidden=false;}
        function cleanup(){generation++;clearTimeout(timer);if(controller)controller.abort();controller=null;if(hls)hls.destroy();hls=null;video.pause();video.removeAttribute('src');video.load();}
        function reconnect(){if(closed)return;cleanup();notice('Эфир пока недоступен. Подключимся автоматически, когда появится поток.');timer=setTimeout(connect,10000);}
        function play(){if(pausedByUser)return;var current=generation;video.play().catch(function(){if(!closed&&current===generation&&!pausedByUser)notice('Нажми ▶ на плеере, чтобы начать просмотр.');});}
        async function connect(){
            cleanup();closed=false;var current=generation;notice('Подключаемся к эфиру…');controller=new AbortController();var timeout=setTimeout(function(){if(controller)controller.abort();},7000);
            try{
                var response=await fetch(root.dataset.selfHostedUrl,{cache:'no-store',credentials:'same-origin',signal:controller.signal});
                clearTimeout(timeout);if(current!==generation)return;if(!response.ok){reconnect();return;}
                if(window.Hls&&Hls.isSupported()){
                    hls=new Hls({lowLatencyMode:false,maxBufferLength:12,maxMaxBufferLength:20,backBufferLength:0,maxBufferSize:12000000});
                    hls.on(Hls.Events.MANIFEST_PARSED,play);
                    hls.on(Hls.Events.ERROR,function(event,data){if(current===generation&&data.fatal)reconnect();});
                    hls.loadSource(root.dataset.selfHostedUrl);hls.attachMedia(video);
                }else if(video.canPlayType('application/vnd.apple.mpegurl')){video.src=root.dataset.selfHostedUrl;video.load();play();}
                else{notice('Браузер не поддерживает этот формат эфира. Попробуй обновить браузер.');}
            }catch(error){clearTimeout(timeout);if(current===generation)reconnect();}
        }
        video.addEventListener('playing',function(){box.hidden=true;});
        video.addEventListener('error',function(){if(!closed&&(video.currentSrc||hls))reconnect();});
        video.addEventListener('ended',reconnect);
        ['play','pause','loadedmetadata','loadeddata','canplay','emptied'].forEach(function(event){video.addEventListener(event,syncControls);});
        video.addEventListener('volumechange',function(){syncControls();try{localStorage.setItem(key,String(video.volume));}catch(error){}});
        toggle.onclick=function(){if(video.paused){pausedByUser=false;resumeLive();play();}else{pausedByUser=true;video.pause();}};
        mute.onclick=function(){if(video.muted||video.volume===0){video.volume=lastVolume;video.muted=false;}else video.muted=true;syncControls();};
        volume.oninput=function(){video.volume=Number(volume.value);video.muted=video.volume===0;syncControls();};
        retry.onclick=function(){pausedByUser=false;video.muted=false;connect();};
        window.addEventListener('pagehide',function(){closed=true;cleanup();});
        window.addEventListener('pageshow',function(event){if(event.persisted)connect();});
        syncControls();connect();
    }
    function start(){document.querySelectorAll('[data-self-hosted-url]').forEach(mount);}
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
