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
