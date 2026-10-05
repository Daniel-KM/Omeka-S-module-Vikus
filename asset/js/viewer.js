/**
 * Keep the panel of details open in the iframe of the block.
 *
 * The viewer collapses the panel (class "sneak") each time an image is
 * selected when it runs in an iframe, a behaviour designed for small embeds.
 * The block embeds it in an iframe as large as the page, so the panel is
 * opened again, unless the visitor closed it with the arrow, and except on a
 * small screen, where the viewer collapses it for itself.
 */
(function () {
    'use strict';

    var panel = document.querySelector('.sidebar.detail');
    if (!panel) {
        return;
    }

    var collapsedByUser = false;

    // The intent of the visitor is read during the capture phase, so before
    // the toggle of the viewer and before the observer below, that runs as a
    // microtask: a click on the arrow closes the panel when it is open, and
    // opens it otherwise.
    panel.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('.slidebutton')) {
            collapsedByUser = !panel.classList.contains('sneak');
        }
    }, true);

    new MutationObserver(function () {
        if (collapsedByUser
            || window.innerWidth < 700
            || panel.classList.contains('hide')
            || !panel.classList.contains('sneak')
        ) {
            return;
        }
        panel.classList.remove('sneak');
    }).observe(panel, {attributes: true, attributeFilter: ['class']});
})();

/**
 * Show a discreet hint inviting the visitor to zoom, once by session.
 *
 * A collection spread over centuries is displayed as one narrow column by
 * year, so the images are a few pixels wide until the visitor zooms, and
 * nothing in the viewer says that the canvas can be zoomed. The hint is
 * removed at the first interaction with the canvas or after a few seconds.
 * Only an interaction marks it as seen for the session: a slow load would
 * else consume it before it was visible. The session storage may throw
 * (private window, blocked site data), so the hint is simply displayed again
 * in that case. It is also displayed again, more briefly, each time the
 * visitor changes the layout: a group layout or the map has its own scale,
 * where the images are small again.
 */
(function () {
    'use strict';

    var KEY = 'vikusZoomHint';
    var DELAY = 8000;
    var DELAY_LAYOUT = 3000;
    var EVENTS = ['wheel', 'touchstart', 'mousedown', 'keydown'];

    var script = document.querySelector('script[data-vikus-hint]');
    var text = script ? script.getAttribute('data-vikus-hint') : '';
    var page = document.querySelector('.page');
    if (!text || !page) {
        return;
    }

    var hint = null;
    var timer = null;

    function show(delay) {
        hide();
        hint = document.createElement('div');
        hint.className = 'vikus-hint';
        hint.textContent = text;
        page.appendChild(hint);
        timer = window.setTimeout(hide, delay);
    }

    function hide() {
        window.clearTimeout(timer);
        if (hint && hint.parentNode) {
            hint.parentNode.removeChild(hint);
        }
        hint = null;
    }

    function dismiss() {
        hide();
        try {
            window.sessionStorage.setItem(KEY, '1');
        } catch (e) {
        }
    }

    // The capture phase is used so that the hint disappears even when the
    // viewer stops the propagation of the event on the canvas.
    for (var i = 0; i < EVENTS.length; i++) {
        document.addEventListener(EVENTS[i], dismiss, true);
    }

    // The click follows the mousedown that dismissed the hint, so the hint is
    // displayed again after it, until the next interaction. A click on the
    // button of the current layout is ignored by the viewer, so it is ignored
    // here too.
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('.navi .button');
        if (button && !button.classList.contains('active')) {
            show(DELAY_LAYOUT);
        }
    }, true);

    var seen = false;
    try {
        seen = !!window.sessionStorage.getItem(KEY);
    } catch (e) {
    }
    if (!seen) {
        show(DELAY);
    }
})();

/**
 * Guard utils.setMode() against a title renamed since the url was shared.
 *
 * viz.js utils.setMode() looks up the layout by title without checking the
 * result, so a stale title (a renamed layout, e.g. from the url hash read by
 * canvas.onhashchange()) leaves canvas.setMode(undefined): the mode becomes
 * undefined and canvas.getMode().title throws on every later click, freezing
 * the navigation for the session. The title is replaced by undefined when it
 * matches no layout, so the upstream code falls back to its own default (the
 * first layout) instead.
 */
(function () {
    'use strict';

    if (!window.utils || typeof utils.setMode !== 'function') {
        return;
    }

    var originalSetMode = utils.setMode;
    utils.setMode = function (title) {
        var loader = utils.config && utils.config.loader;
        if (loader && loader.layouts && !loader.layouts.some(function (d) {
            return d.title === title;
        })) {
            title = undefined;
        }
        arguments[0] = title;
        return originalSetMode.apply(utils, arguments);
    };
})();
