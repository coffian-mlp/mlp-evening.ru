/** Twitch volume belongs to the viewer's browser; other providers remain ordinary iframes. */
(function () {
    'use strict';
    var storageKey = 'mlp.twitch.volume';
    var sdkPromise;
    var sequence = 0;

    function readVolume() {
        try {
            var raw = localStorage.getItem(storageKey);
            if (raw === null || raw.trim() === '') return null;
            var value = Number(raw);
            return Number.isFinite(value) && value >= 0 && value <= 1 ? value : null;
        } catch (error) { return null; }
    }

    function sdk() {
        if (window.Twitch && typeof window.Twitch.Player === 'function') return Promise.resolve();
        if (!sdkPromise) sdkPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            var timeout = setTimeout(function () { reject(new Error('Twitch SDK timeout')); }, 10000);
            script.src = 'https://player.twitch.tv/js/embed/v1.js';
            script.onload = function () {
                clearTimeout(timeout);
                if (window.Twitch && typeof window.Twitch.Player === 'function') resolve();
                else reject(new Error('Twitch SDK unavailable'));
            };
            script.onerror = function () { clearTimeout(timeout); reject(new Error('Twitch SDK unavailable')); };
            document.head.appendChild(script);
        });
        return sdkPromise;
    }

    function fallback(element) {
        if (!window.privacyChoices || !window.privacyChoices.external_media) return;
        element.replaceChildren();
        var iframe = document.createElement('iframe');
        iframe.src = element.dataset.streamUrl;
        iframe.allow = 'autoplay';
        iframe.allowFullscreen = true;
        element.appendChild(iframe);
    }

    function mount(element) {
        if (!window.privacyChoices || !window.privacyChoices.external_media) return;
        if (element._twitchMounted) return;
        element._twitchMounted = true;
        var generation = element._privacyGeneration = (element._privacyGeneration || 0) + 1;
        sdk().then(function () {
            if (!element.isConnected || !window.privacyChoices.external_media || !element._twitchMounted || element._privacyGeneration !== generation) return;
            var options = JSON.parse(element.dataset.twitchOptions);
            options.width = '100%'; options.height = '100%';
            options.parent = Array.from(new Set([location.hostname].concat(options.parent || [])));
            var container = document.createElement('div');
            container.className = 'twitch-player';
            container.id = 'mlp-twitch-player-' + (++sequence);
            element.replaceChildren(container);
            var player = new Twitch.Player(container.id, options);
            element._privacyPlayer = player;
            var saved = readVolume();
            var restoring = saved !== null;
            var ready = false;
            var timer;
            function persist() {
                if (!element.isConnected || !window.privacyChoices.external_media) { clearInterval(timer); return; }
                if (!ready) return;
                try {
                    var volume = player.getVolume();
                    if (typeof volume !== 'number' || !Number.isFinite(volume) || volume < 0 || volume > 1) return;
                    // Do not replace the preference with the SDK's pre-restore default.
                    if (restoring) {
                        if (Math.abs(volume - saved) > 0.0001) return;
                        restoring = false;
                    }
                    if (volume === saved) return;
                    localStorage.setItem(storageKey, String(volume));
                    saved = volume;
                } catch (error) { /* Storage restrictions must not interrupt playback. */ }
            }
            player.addEventListener(Twitch.Player.READY, function () {
                if (ready) return;
                if (saved !== null) player.setVolume(saved);
                ready = true;
                persist();
                // The documented SDK has no volume-change event; this reads local player state only.
                timer = setInterval(persist, 500);
                element._privacyTimer = timer;
            });
            function restoreOnPlayback() {
                if (ready && restoring && window.privacyChoices.external_media) { player.setVolume(saved); persist(); }
            }
            player.addEventListener(Twitch.Player.PLAY, restoreOnPlayback);
            player.addEventListener(Twitch.Player.PLAYING, restoreOnPlayback);
            window.addEventListener('pagehide', function () { persist(); });
            document.addEventListener('visibilitychange', function () { if (document.hidden) persist(); });
        }).catch(function () {window.dispatchEvent(new CustomEvent('uxerror',{detail:'player'}));if (element.isConnected) fallback(element);});
    }

    function start() {
        document.querySelectorAll('.video-content[data-stream-url]').forEach(function(element) {
            if (!element._privacyPlaceholder) element._privacyPlaceholder = element.firstElementChild.cloneNode(true);
            if (window.privacyChoices && window.privacyChoices.external_media) {
                if (element.hasAttribute('data-twitch-options')) mount(element);
                else if (!element.querySelector('iframe')) fallback(element);
            } else {
                clearInterval(element._privacyTimer);
                try { if(element._privacyPlayer && typeof element._privacyPlayer.destroy==='function')element._privacyPlayer.destroy(); } catch(error) {}
                element._privacyPlayer=null; element._twitchMounted=false; element._privacyGeneration=(element._privacyGeneration || 0)+1;
                element.replaceChildren(element._privacyPlaceholder.cloneNode(true));
            }
        });
    }
    window.addEventListener('privacychange',start);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
