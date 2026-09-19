/* =============================================================================
 * File: public/assets/js/qr.js
 * Purpose: Minimal QR helper. Encodes the current panel URL + query state.
 *          The authoritative renderer is qrcode.js (vendored under
 *          public/assets/js/vendor/); this wrapper resolves the URL to encode
 *          and exposes a tiny API used by print headers and entity pages
 *          (Blueprint §17.3, Technical §27.3).
 * ========================================================================== */
(function () {
    'use strict';

    /** Resolve the URL to encode (current location including query state). */
    function currentUrl() {
        return window.location.href;
    }

    /**
     * Render a QR code into a container element.
     * Uses the vendored qrcode.js global when present; otherwise shows the URL text.
     */
    function render(container, url) {
        const target = typeof container === 'string' ? document.querySelector(container) : container;
        if (!target) return;
        const data = url || currentUrl();
        if (typeof window.QRCode !== 'undefined') {
            target.innerHTML = '';
            // qrcode.js (davidshimjs) global form.
            if (window.QRCode.CorrectLevel) {
                new window.QRCode(target, { text: data, width: 128, height: 128, correctLevel: window.QRCode.CorrectLevel.M });
                return;
            }
        }
        // Fallback: expose the URL as text (authed users can copy it).
        target.textContent = data;
    }

    window.PanelQr = { render, currentUrl };
})();
