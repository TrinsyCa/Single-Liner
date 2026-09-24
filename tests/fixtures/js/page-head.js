// A typical server-rendered head script: configuration objects, a
// queue stub for code that runs before the bundle, and comments.
var window = globalThis;
var document = { documentElement: { classList: { list: [], add: function (c) { this.list.push(c) } } } };

document.documentElement.classList.add('dash-boot');

// Preferences rendered by the server.
window.__dashPrefs = {"theme": "dark", "font": "Montserrat", "sidebar": "full", "sounds": {"enabled": true, "volume": 0.6}};
window.__dashLang = {"common.save": "Kaydet", "common.saving": "Kaydediliyor…", "ui.done": "Tamam  ✓"};

// Early queue: inline page scripts may call onPageLoad before core.js runs.
window.__dashPageLoadQueue = window.__dashPageLoadQueue || [];
window.onPageLoad = window.onPageLoad || function (cb) {
    // core.js adopts this queue and drains it on DOMContentLoaded.
    window.__dashPageLoadQueue.push(cb);
};

window.t = function (key, fallback) {
    var value = window.__dashLang[key];
    return value !== undefined ? value : (fallback !== undefined ? fallback : key);
};

window.onPageLoad(function () { return 'first'; });
window.onPageLoad(function () { return 'second'; });

var drained = window.__dashPageLoadQueue.map(function (cb) { return cb(); });

console.log(JSON.stringify({
    boot: document.documentElement.classList.list,
    prefs: window.__dashPrefs.theme,
    save: window.t('common.save'),
    missing: window.t('nope', 'fallback'),
    drained: drained,
}));
