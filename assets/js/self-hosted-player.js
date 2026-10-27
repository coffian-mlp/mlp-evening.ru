(function(){
    'use strict';
    function mount(root){
        if(root._selfHosted)return;root._selfHosted=true;
        var video=root.querySelector('video'),box=root.querySelector('.self-hosted-state'),status=box.querySelector('[role=status]'),retry=box.querySelector('button');
        var waitingText=status.textContent;
        var toggle=root.querySelector('[data-stream-play]'),mute=root.querySelector('[data-stream-mute]'),volume=root.querySelector('[data-stream-volume]');
        var hls=null,timer=null,controller=null,generation=0,closed=false,pausedByUser=false,lastVolume=1;
        var loader=root.querySelector('[data-stream-buffering]'),reduced=window.matchMedia('(prefers-reduced-motion: reduce)'),buffering=false,bufferTimer=null,animationFailed=false,posterFailed=false;
        var key='mlp.self-hosted.volume';
        var controls=root.querySelector('.self-hosted-controls'),controlsTimer=null,overControls=false,adjustingControls=false,keyboardInput=false;
        function keepControls(){return overControls||adjustingControls||(keyboardInput&&controls.contains(document.activeElement))||!!controls.querySelector('[aria-expanded="true"]');}
        function deferControls(){
            clearTimeout(controlsTimer);
            if(!closed&&!keepControls())controlsTimer=setTimeout(function(){if(!closed&&!keepControls())root.classList.add('self-hosted-controls-hidden');},2500);
        }
        function revealControls(){if(closed)return;root.classList.remove('self-hosted-controls-hidden');deferControls();}
        function sizeControls(){root.style.setProperty('--player-controls-height',controls.offsetHeight+'px');}
        var controlsSize=typeof ResizeObserver==='function'?new ResizeObserver(sizeControls):null;
        if(controlsSize)controlsSize.observe(controls);sizeControls();
        root.addEventListener('pointerenter',revealControls);root.addEventListener('pointermove',revealControls);root.addEventListener('pointerdown',revealControls);
        root.addEventListener('pointerleave',function(){overControls=false;deferControls();});
        controls.addEventListener('pointerenter',function(event){overControls=event.pointerType!=='touch';revealControls();});
        controls.addEventListener('pointerleave',function(){overControls=false;deferControls();});
        controls.addEventListener('pointerdown',function(){adjustingControls=true;revealControls();});
        document.addEventListener('pointerup',function(event){if(adjustingControls){
            adjustingControls=false;var bounds=controls.getBoundingClientRect();
            overControls=event.pointerType!=='touch'&&event.clientX>=bounds.left&&event.clientX<=bounds.right&&event.clientY>=bounds.top&&event.clientY<=bounds.bottom;
            deferControls();
        }});
        document.addEventListener('pointercancel',function(){adjustingControls=false;overControls=false;deferControls();});
        document.addEventListener('pointerdown',function(){keyboardInput=false;deferControls();},true);
        document.addEventListener('keydown',function(event){if(event.key==='Tab')keyboardInput=true;},true);
        root.addEventListener('keydown',function(){keyboardInput=true;revealControls();});
        controls.addEventListener('focusin',revealControls);controls.addEventListener('focusout',function(){setTimeout(deferControls,0);});
        var controlsMenu=new MutationObserver(function(){if(keepControls())revealControls();else deferControls();});
        controlsMenu.observe(controls,{subtree:true,attributes:true,attributeFilter:['aria-expanded']});
        document.addEventListener('fullscreenchange',revealControls);
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
                if(!ranges.length){notice('Подключаемся к эфиру…',true);video.load();return;}
                target=Math.max(ranges.start(ranges.length-1),ranges.end(ranges.length-1)-2);
            }
            if(Number.isFinite(target)&&ranges.length){
                var end=ranges.end(ranges.length-1),start=ranges.start(ranges.length-1);
                target=Math.max(start,Math.min(target,end-.1));
                if(video.currentTime<target-.5)video.currentTime=target;
            }
        }
        function showBuffering(active){
            buffering=active;if(!active){clearTimeout(bufferTimer);bufferTimer=null;}
            if(!loader)return;
            if(!active||posterFailed){if(!loader.hidden)loader.hidden=true;if(loader.hasAttribute('src'))loader.removeAttribute('src');return;}
            var source=(reduced.matches||animationFailed)?loader.dataset.staticSrc:loader.dataset.animatedSrc;
            if(loader.getAttribute('src')!==source)loader.setAttribute('src',source);if(loader.hidden)loader.hidden=false;
        }
        function notice(text,loading){if(status.textContent!==text)status.textContent=text;showBuffering(!!loading);if(box.hidden)box.hidden=false;}
        function hideNotice(){showBuffering(false);if(!box.hidden)box.hidden=true;}
        function queueBuffering(){
            if(closed||pausedByUser||video.paused||video.readyState>=3||(!hls&&!video.currentSrc)||bufferTimer!==null)return;
            bufferTimer=setTimeout(function(){bufferTimer=null;if(!closed&&!pausedByUser&&!video.paused&&video.readyState<3)notice('Буферизация…',true);},200);
        }
        function cleanup(){generation++;clearTimeout(timer);showBuffering(false);if(controller)controller.abort();controller=null;if(!hls&&!video.hasAttribute('src'))return;if(hls)hls.destroy();hls=null;video.pause();video.removeAttribute('src');video.load();}
        function schedule(){if(!closed){clearTimeout(timer);timer=setTimeout(connect,10000);}}
        function reconnect(){if(closed)return;cleanup();notice(waitingText);schedule();}
        function play(){if(pausedByUser){notice('Эфир доступен. Нажми ▶ на плеере, чтобы продолжить.');return;}if(video.readyState<3)notice('Подключаемся к эфиру…',true);var current=generation;video.play().catch(function(){if(!closed&&current===generation&&!pausedByUser)notice('Нажми ▶ на плеере, чтобы начать просмотр.');});}
        async function connect(){
            if(closed)return;clearTimeout(timer);if(controller)controller.abort();
            var current=++generation,request=new AbortController(),attaching=false;controller=request;var timeout=setTimeout(function(){request.abort();},7000);
            try{
                var response=await fetch(root.dataset.selfHostedUrl,{cache:'no-store',credentials:'same-origin',signal:request.signal});
                if(current!==generation)return;if(!response.ok){schedule();return;}
                var manifest=await response.text();if(current!==generation)return;
                if(!/^#EXTM3U(?:\r?\n|$)/.test(manifest)){schedule();return;}
                attaching=true;notice('Подключаемся к эфиру…',true);
                if(window.Hls&&Hls.isSupported()){
                    hls=new Hls({lowLatencyMode:false,maxBufferLength:12,maxMaxBufferLength:20,backBufferLength:0,maxBufferSize:12000000});
                    hls.on(Hls.Events.MANIFEST_PARSED,play);
                    hls.on(Hls.Events.ERROR,function(event,data){if(current===generation&&data.fatal)reconnect();});
                    hls.loadSource(root.dataset.selfHostedUrl);hls.attachMedia(video);
                }else if(video.canPlayType('application/vnd.apple.mpegurl')){video.src=root.dataset.selfHostedUrl;video.load();play();}
                else{notice('Браузер не поддерживает этот формат эфира. Попробуй обновить браузер.');}
            }catch(error){if(current===generation){if(attaching)reconnect();else schedule();}}
            finally{clearTimeout(timeout);if(controller===request)controller=null;}
        }
        video.addEventListener('playing',hideNotice);
        video.addEventListener('waiting',queueBuffering);video.addEventListener('stalled',queueBuffering);
        video.addEventListener('pause',function(){if(pausedByUser||video.readyState>0)hideNotice();});
        video.addEventListener('error',function(){if(!closed&&(video.currentSrc||hls))reconnect();});
        video.addEventListener('ended',reconnect);
        ['play','pause','loadedmetadata','loadeddata','canplay','emptied'].forEach(function(event){video.addEventListener(event,syncControls);});
        video.addEventListener('volumechange',function(){syncControls();try{localStorage.setItem(key,String(video.volume));}catch(error){}});
        toggle.onclick=function(){if(video.paused){pausedByUser=false;resumeLive();play();}else{pausedByUser=true;hideNotice();video.pause();}};
        mute.onclick=function(){if(video.muted||video.volume===0){video.volume=lastVolume;video.muted=false;}else video.muted=true;syncControls();};
        volume.oninput=function(){video.volume=Number(volume.value);video.muted=video.volume===0;syncControls();};
        retry.onclick=function(){pausedByUser=false;video.muted=false;cleanup();notice(waitingText);connect();};
        if(loader)loader.onerror=function(){if(!buffering)return;if(loader.getAttribute('src')===loader.dataset.staticSrc)posterFailed=true;else animationFailed=true;showBuffering(true);};
        var motionChanged=function(){if(buffering)showBuffering(true);};if(reduced.addEventListener)reduced.addEventListener('change',motionChanged);else reduced.addListener(motionChanged);
        window.addEventListener('pagehide',function(){closed=true;clearTimeout(controlsTimer);cleanup();});
        window.addEventListener('pageshow',function(event){if(event.persisted){closed=false;overControls=false;adjustingControls=false;sizeControls();revealControls();notice(waitingText);connect();}});
        syncControls();revealControls();connect();
    }
    function start(){document.querySelectorAll('[data-self-hosted-url]').forEach(mount);}
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
