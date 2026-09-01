(function () {
    'use strict';

    function hasEsiMiniCart() {
        return !!document.querySelector(
            '[data-ultracache-esi-adapter="woocommerce-classic-mini-cart"], ' +
            '[data-ultracache-esi-auto="woocommerce-mini-cart"]'
        );
    }

    function optIn() {
        if (!hasEsiMiniCart()) {
            return;
        }

        var cookie = 'ultracache_esi_optin=1; Path=/; SameSite=Lax';
        if (window.location && window.location.protocol === 'https:') {
            cookie += '; Secure';
        }
        document.cookie = cookie;
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', optIn, { once: true });
    } else {
        optIn();
    }
}());
