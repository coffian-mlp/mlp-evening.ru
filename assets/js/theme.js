(function () {
    'use strict';
    var key = 'mlp.theme';
    var system = window.matchMedia('(prefers-color-scheme: dark)');
    var preference = 'auto';
    function normalize(value) { return value === 'luna' || value === 'celestia' ? value : 'auto'; }
    function apply(value) {
        preference = normalize(value);
        var theme = preference === 'auto' ? (system.matches ? 'luna' : 'celestia') : preference;
        document.documentElement.dataset.theme = theme;
        document.documentElement.dataset.themePreference = preference;
        var label = 'Тема: ' + ({auto: 'как на устройстве', luna: 'Луна', celestia: 'Селестия'})[preference];
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.title = label;
            button.setAttribute('aria-label', label);
        });
        document.querySelectorAll('[data-theme-icon]').forEach(function (icon) {
            icon.textContent = ({auto: '🖥️', luna: '🌙', celestia: '☀️'})[preference];
        });
        document.querySelectorAll('[data-theme-choice]').forEach(function (button) {
            button.setAttribute('aria-pressed', String(button.dataset.themeChoice === preference));
        });
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.content = theme === 'luna' ? '#12152e' : '#f6eef8';
    }
    var stored;
    try { stored = localStorage.getItem(key); } catch (e) { /* Storage may be disabled. */ }
    apply(stored);
    document.addEventListener('DOMContentLoaded', function () { apply(preference); });
    document.addEventListener('click', function (event) {
        var choice = event.target.closest('[data-theme-choice]');
        if (choice) {
            apply(choice.dataset.themeChoice);
            try {
                if (preference === 'auto') localStorage.removeItem(key);
                else localStorage.setItem(key, preference);
            } catch (e) { /* The current page can still switch. */ }
        }
        document.querySelectorAll('.theme-picker[open]').forEach(function (picker) {
            if (choice || !picker.contains(event.target)) picker.open = false;
        });
    });
    window.addEventListener('storage', function (event) {
        if (event.key === key || event.key === null) apply(event.newValue);
    });
    system.addEventListener('change', function () { if (preference === 'auto') apply('auto'); });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.theme-picker[open]').forEach(function (picker) {
            picker.open = false;
            picker.querySelector('summary').focus();
        });
    });
})();
