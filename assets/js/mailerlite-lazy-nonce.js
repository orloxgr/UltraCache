(function(){
  if (window.__ultracacheMailerLiteLazyNonceV1) { return; }
  window.__ultracacheMailerLiteLazyNonceV1 = true;

  var realFetch = window.fetch;
  if (typeof realFetch !== 'function') { return; }

  var config = window.ultracacheMailerLiteLazyNonceConfig || {};
  var ajaxUrl = config.ajaxUrl || '';
  var refreshStarted = false;

  function normalizeEndpointUrl(url) {
    try {
      return (new URL(String(url || ''), window.location.href)).href.split('#')[0];
    } catch (e) {
      return String(url || '').split('#')[0];
    }
  }

  function isConfiguredAjaxEndpoint(url) {
    if (!url || !ajaxUrl) { return false; }
    return normalizeEndpointUrl(url) === normalizeEndpointUrl(ajaxUrl);
  }

  function toBodyString(body) {
    try {
      if (!body) { return ''; }
      if (typeof body === 'string') { return body; }
      if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) { return body.toString(); }
      if (typeof FormData !== 'undefined' && body instanceof FormData) {
        var parts = [];
        body.forEach(function(value, key){ parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value))); });
        return parts.join('&');
      }
    } catch (e) {}
    return '';
  }

  function getRequestUrl(input) {
    try {
      if (typeof input === 'string') { return input; }
      if (input && typeof input.url === 'string') { return input.url; }
    } catch (e) {}
    return '';
  }

  function isMailerLiteNonceRequest(input, init) {
    var url = getRequestUrl(input);
    var body = toBodyString(init && init.body ? init.body : '');
    if (!isConfiguredAjaxEndpoint(url)) { return false; }
    return body.indexOf('ml_create_nonce') !== -1 || body.indexOf('action=ml_create_nonce') !== -1 || body.indexOf('action%3Dml_create_nonce') !== -1;
  }

  function getNonceFromBody(body) {
    var str = toBodyString(body);
    var match = str.match(/(?:^|&)ml_nonce=([^&]*)/);
    if (!match) { return ''; }
    try { return decodeURIComponent(match[1].replace(/\+/g, ' ')); } catch (e) { return match[1]; }
  }

  function fakeNonceResponse(nonce) {
    return Promise.resolve({
      ok: true,
      status: 200,
      json: function(){ return Promise.resolve({ success: true, data: { ml_nonce: nonce || '' } }); },
      text: function(){ return Promise.resolve('{"success":true,"data":{"ml_nonce":"' + String(nonce || '').replace(/"/g, '\\"') + '"}}'); }
    });
  }

  function formLooksLikeMailerLite(form) {
    if (!form || !form.querySelector || !form.querySelector('input[name="ml_nonce"]')) { return false; }
    try {
      return !!(form.closest('[id^="mailerlite-form_"]') || form.closest('[data-temp-id]') || form.querySelector('.mailerlite-subscribe-submit') || form.querySelector('[class*="mailerlite"]'));
    } catch (e) {
      return true;
    }
  }

  function findFormFromTarget(target) {
    try {
      if (target && target.closest) {
        var form = target.closest('form');
        if (form && formLooksLikeMailerLite(form)) { return form; }
      }
    } catch (e) {}
    return null;
  }

  function setSubmitDisabled(form, disabled) {
    try {
      var buttons = form.querySelectorAll('.mailerlite-subscribe-submit, button[type="submit"], input[type="submit"]');
      for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = !!disabled; }
    } catch (e) {}
  }

  function refreshFormNonce(form) {
    if (!formLooksLikeMailerLite(form)) { return Promise.resolve(false); }
    if (form.__ultracacheMlNonceRefreshing) { return form.__ultracacheMlNonceRefreshing; }

    var input = form.querySelector('input[name="ml_nonce"]');
    if (!input) { return Promise.resolve(false); }

    var url = ajaxUrl;
    if (!url) { return Promise.resolve(false); }
    var body = new URLSearchParams();
    body.append('action', 'ml_create_nonce');
    body.append('ml_nonce', input.value || '');

    form.__ultracacheMlNonceRefreshing = realFetch.call(window, url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    }).then(function(response){
      return response.json();
    }).then(function(json){
      if (json && json.success && json.data && json.data.ml_nonce) {
        input.value = json.data.ml_nonce;
        form.__ultracacheMlNonceReady = true;
        setSubmitDisabled(form, false);
        return true;
      }
      return false;
    }).catch(function(){
      return false;
    }).then(function(ok){
      form.__ultracacheMlNonceRefreshing = null;
      return ok;
    });

    return form.__ultracacheMlNonceRefreshing;
  }

  window.fetch = function(input, init) {
    if (isMailerLiteNonceRequest(input, init || {})) {
      var oldNonce = getNonceFromBody(init && init.body ? init.body : '');
      return fakeNonceResponse(oldNonce);
    }
    return realFetch.apply(this, arguments);
  };

  function maybeRefreshFromInteraction(event) {
    var form = findFormFromTarget(event && event.target ? event.target : null);
    if (!form || form.__ultracacheMlNonceReady || refreshStarted) { return; }
    refreshStarted = true;
    refreshFormNonce(form).then(function(){ refreshStarted = false; });
  }

  document.addEventListener('focusin', maybeRefreshFromInteraction, true);
  document.addEventListener('pointerdown', maybeRefreshFromInteraction, true);
  document.addEventListener('touchstart', maybeRefreshFromInteraction, true);
  document.addEventListener('keydown', maybeRefreshFromInteraction, true);

  document.addEventListener('submit', function(event){
    var form = event && event.target ? event.target : null;
    if (!formLooksLikeMailerLite(form) || form.__ultracacheMlNonceReady) { return; }

    event.preventDefault();
    event.stopImmediatePropagation();
    setSubmitDisabled(form, true);

    refreshFormNonce(form).then(function(ok){
      if (!ok) {
        setSubmitDisabled(form, false);
        return;
      }
      setTimeout(function(){
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          var submitEvent = document.createEvent('Event');
          submitEvent.initEvent('submit', true, true);
          form.dispatchEvent(submitEvent);
        }
      }, 0);
    });
  }, true);
})();
