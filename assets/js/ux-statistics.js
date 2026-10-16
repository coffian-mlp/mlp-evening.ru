(function () {
    'use strict';
    var pageId, timer, errors={chat:false,player:false};
    function permitted() { return !!(window.privacyChoices && window.privacyChoices.analytics); }
    function id() {
        var bytes=new Uint8Array(16); crypto.getRandomValues(bytes);
        return Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
    }
    function report() {
        if (!permitted() || document.hidden) return;
        if (!pageId) pageId=id();
        var nav=performance.getEntriesByType('navigation')[0], viewport=window.visualViewport;
        var data={action:'ux_measure',csrf_token:window.csrfToken,page_id:pageId,path:location.pathname,
            screen_w:screen.width,screen_h:screen.height,width:innerWidth,height:innerHeight,
            density:devicePixelRatio,font_scale:Math.round(parseFloat(getComputedStyle(document.documentElement).fontSize)/16*100),
            theme:document.documentElement.dataset.theme,pinch_scale:viewport?viewport.scale:'',
            load_ms:nav&&nav.loadEventEnd?Math.round(nav.loadEventEnd-nav.startTime):'',
            chat_error:errors.chat?'1':'0',player_error:errors.player?'1':'0',overflow:document.documentElement.scrollWidth>innerWidth+1?'1':'0'};
        fetch('/api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data),keepalive:true}).catch(function(){});
    }
    function schedule() {clearTimeout(timer);if(permitted())timer=setTimeout(report,600);}
    window.addEventListener('privacychange',function(){if(!permitted()){pageId=null;clearTimeout(timer);}else schedule();});
    window.addEventListener('resize',schedule);
    window.addEventListener('uxerror',function(event){if(['chat','player'].includes(event.detail)){errors[event.detail]=true;schedule();}});
    document.addEventListener('visibilitychange',function(){if(!document.hidden)schedule();});
    new MutationObserver(schedule).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme','style']});
    if(document.readyState==='complete')schedule();else window.addEventListener('load',schedule);
})();
