(function () {
    'use strict';

    var cookie = 'ultracache_esi_optin=1; Path=/; SameSite=Lax';
    if (window.location && window.location.protocol === 'https:') {
        cookie += '; Secure';
    }

    document.cookie = cookie;
}());
