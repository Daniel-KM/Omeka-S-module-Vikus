/**
 * Display a base map behind the map layout of the viewer.
 *
 * The layout places each item at its geographic position, but the viewer draws
 * images only, so a point has no context. A Leaflet map is added behind the
 * canvas of the viewer and follows its zoom, so the images are read on a real
 * map.
 *
 * The canvas of the viewer is opaque, since pixi builds its webgl context
 * without an alpha channel unless the option "transparent" is set. That option
 * is read when the renderer is built, which happens after this script (the
 * viewer fetches its config first), so the default of pixi is changed here and
 * the page carries the background of the canvas itself. The background colour
 * of the renderer is then reset to black, once it exists: the viewer sets it
 * from the config and pixi clears the canvas with it and an alpha of zero,
 * which is not a valid premultiplied colour and is composited as an opaque
 * white.
 *
 * Synchronisation. The viewer places a point of map.csv at
 *
 *     wx = nx × d + w / 2 − d / 2 + margin.left
 *
 *     wy = −ny × d
 *
 * where nx and ny are the columns "x" and "y" rescaled on their extent (that is
 * the window written by the generator, a square in projected units), d is
 * min(w, h) × 0.8, and w and h the size of the canvas (canvas.js, projectTSNE).
 * The zoom then draws it at
 *
 *     sx = wx × s + t[0]
 *
 *     sy = (h + wy) × s + t[1]
 *
 * with the translation t and the scale s of the zoom behaviour. Inverting these
 * two gives the geographic position of any point of the screen, hence the
 * centre of the viewport. The scale follows: the canvas draws d × s pixels for
 * the whole window, that is for spanLon degrees of longitude, while Leaflet
 * draws 256 × 2^z pixels for 2π radians, so
 *
 *     2^z = π × d × s / (128 × spanLon × π / 180)
 *
 * The window being square in projected units, the same zoom fits both axes, and
 * the map is never distorted.
 *
 * Hiding the items without coordinates. They are absent from map.csv, so the
 * viewer marks them inactive and keeps them in the canvas, dimmed and laid out
 * on a ring around the map, or piled at its corner. The option makes them
 * transparent while the map layout is active, since a position they do not have
 * cannot be read on a map.
 */
(function () {
    'use strict';

    var settings = window.vikusMap;
    if (!settings || !settings.bounds || settings.bounds.length !== 4 || !window.L) {
        return;
    }

    if (!window.PIXI || !PIXI.settings || !PIXI.settings.RENDER_OPTIONS) {
        return;
    }
    PIXI.settings.RENDER_OPTIONS.transparent = true;
    // The canvas draws its background no more, here and on every layout, so the
    // page carries it. It goes with the reset of the background colour of the
    // renderer, in the loop below.
    document.body.style.backgroundColor = settings.background;

    var DEG = Math.PI / 180;

    var mercator = function (latitude) {
        return Math.log(Math.tan(Math.PI / 4 + latitude * DEG / 2));
    };

    var latitude = function (mercator) {
        return (2 * Math.atan(Math.exp(mercator)) - Math.PI / 2) / DEG;
    };

    var minLon = settings.bounds[0];
    var spanLon = settings.bounds[2] - minLon;
    var minY = mercator(settings.bounds[1]);
    var spanY = mercator(settings.bounds[3]) - minY;
    if (!(spanLon > 0) || !(spanY > 0)) {
        return;
    }

    var div = document.createElement('div');
    div.className = 'vikus-map';
    document.body.appendChild(div);

    // Every interaction belongs to the canvas of the viewer, so the map is only
    // driven by the loop below.
    var map = L.map(div, {
        zoomControl: false,
        attributionControl: true,
        zoomSnap: 0,
        zoomAnimation: false,
        fadeAnimation: false,
        markerZoomAnimation: false,
        inertia: false,
        dragging: false,
        keyboard: false,
        boxZoom: false,
        doubleClickZoom: false,
        scrollWheelZoom: false,
        touchZoom: false,
        minZoom: 0,
        maxZoom: 22
    });
    // Leaflet writes the attribution as html in its control, so the text of the
    // option is escaped: it is a plain text and never a markup.
    var escaped = document.createElement('span');
    escaped.textContent = settings.attribution || '';

    L.tileLayer(settings.tiles, {
        attribution: escaped.innerHTML,
        opacity: settings.opacity,
        // The tiles stop at 19 on most servers and are upscaled beyond it,
        // where the visitor is zoomed on a single image.
        maxNativeZoom: 19,
        maxZoom: 22
    }).addTo(map);
    map.setView([0, 0], 2);

    // The renderer of the viewer eases "sprite.alpha" toward the properties
    // "alpha" (the image) and "alpha2" (the detailed one) of the item on every
    // frame of its own loop, so forcing these two to zero fades the item out
    // and the loop keeps it there. The loop skips its work while asleep, hence
    // the wake up, only when something changed. The items are read in the array
    // "data" of viz.js, the very one given to "canvas.init()": "getView()"
    // returns at most four ids of the viewport, not the items themselves.
    var hideMissing = function (canvas) {
        if (!settings.hideMissing || !canvas.wakeup || !window.data || !window.data.length) {
            return;
        }
        var forced = false;
        window.data.forEach(function (d) {
            if (!d.active && (d.alpha !== 0 || d.alpha2 !== 0)) {
                d.alpha = 0;
                d.alpha2 = 0;
                forced = true;
            }
        });
        if (forced) {
            canvas.wakeup();
        }
    };

    var visible = false;
    var last = '';

    var frame = function () {
        window.requestAnimationFrame(frame);

        if (window.renderer && window.renderer.backgroundColor !== 0) {
            window.renderer.backgroundColor = 0;
        }
        var canvas = window.canvas;
        if (!canvas || !canvas.getMode || !canvas.zoom || !canvas.margin) {
            return;
        }
        var mode = canvas.getMode();
        var wanted = !!(mode && mode.title === settings.title);
        if (wanted !== visible) {
            visible = wanted;
            div.style.display = visible ? 'block' : 'none';
            if (visible) {
                map.invalidateSize(false);
                last = '';
            }
        }
        if (!visible) {
            return;
        }

        hideMissing(canvas);

        var t = canvas.zoom.translate();
        var s = canvas.zoom.scale();
        var w = canvas.width();
        var h = canvas.height();
        var cw = div.clientWidth;
        var ch = div.clientHeight;
        var key = [t[0], t[1], s, w, h, cw, ch].join('|');
        if (key === last) {
            return;
        }
        last = key;

        var d = Math.min(w, h) * 0.8;
        if (!(d > 0) || !(s > 0) || !cw || !ch) {
            return;
        }

        var nx = ((cw / 2 - t[0]) / s - (w / 2 - d / 2 + canvas.margin.left)) / d;
        var ny = (h - (ch / 2 - t[1]) / s) / d;
        var zoom = Math.log(Math.PI * d * s / (128 * spanLon * DEG)) / Math.LN2;
        // Leaflet throws outside of its own range of zoom. The scale extent of
        // the zoom behaviour keeps s above 1, so the high bound is only reached
        // on a deep zoom, where the tiles are upscaled, and the low one only on
        // a window covering the world in less than 320 px.
        map.setView(
            [latitude(minY + ny * spanY), minLon + nx * spanLon],
            Math.max(0, Math.min(22, zoom)),
            {animate: false}
        );
    };

    window.requestAnimationFrame(frame);
})();
