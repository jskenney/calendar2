/* Cal5 browser script.  Everything works without it; it only adds:
 *  - the login form sends sha256(password + nonce) for plain-text passwords, as v4's
 *    form did (the password itself is never sent);
 *  - one menu open at a time; Escape or a click elsewhere closes it;
 *  - highlight.js for <pre><code> blocks.
 * No dependencies.  Keep it small (the milestone 7 budget is 10 KB). */
(function () {
  'use strict';

  /* SHA-256 of a UTF-8 string, as lowercase hex (crypto.subtle needs HTTPS, which
     course sites do not always have). */
  function sha256(text) {
    var k = [], h = [], i, j, n = 2;
    for (i = 0; i < 64; n++) {
      for (j = 2; j * j <= n && n % j; j++);
      if (j * j > n) {
        if (i < 8) { h[i] = (Math.pow(n, 1 / 2) * 4294967296) | 0; }
        k[i++] = (Math.pow(n, 1 / 3) * 4294967296) | 0;
      }
    }
    var bytes = unescape(encodeURIComponent(text)), len = bytes.length, words = [];
    for (i = 0; i < len; i++) { words[i >> 2] |= bytes.charCodeAt(i) << ((3 - i % 4) * 8); }
    words[len >> 2] |= 0x80 << ((3 - len % 4) * 8);
    words[((len + 8) >> 6 << 4) + 15] = len * 8;
    for (i = 0; i < words.length; i++) { words[i] |= 0; }
    function r(x, b) { return (x >>> b) | (x << (32 - b)); }
    for (j = 0; j < words.length; j += 16) {
      var w = words.slice(j, j + 16), a = h.slice(0);
      for (i = 0; i < 64; i++) {
        if (i >= 16) {
          var w15 = w[i - 15], w2 = w[i - 2];
          w[i] = (w[i - 16] + (r(w15, 7) ^ r(w15, 18) ^ (w15 >>> 3)) + w[i - 7] + (r(w2, 17) ^ r(w2, 19) ^ (w2 >>> 10))) | 0;
        }
        var e = a[4], t1 = (a[7] + (r(e, 6) ^ r(e, 11) ^ r(e, 25)) + ((e & a[5]) ^ (~e & a[6])) + k[i] + (w[i] | 0)) | 0;
        var b = a[0], t2 = ((r(b, 2) ^ r(b, 13) ^ r(b, 22)) + ((b & a[1]) ^ (b & a[2]) ^ (a[1] & a[2]))) | 0;
        a = [(t1 + t2) | 0].concat(a);
        a[4] = (a[4] + t1) | 0;
        a.length = 8;
      }
      for (i = 0; i < 8; i++) { h[i] = (h[i] + a[i]) | 0; }
    }
    var hex = '';
    for (i = 0; i < 8; i++) { hex += ('00000000' + (h[i] >>> 0).toString(16)).slice(-8); }
    return hex;
  }
  window.cal5sha256 = sha256;

  function login(form) {
    form.addEventListener('submit', function () {
      var input = form.querySelector('input[name="password"]'), nonce = form.getAttribute('data-nonce');
      if (input && input.value !== '' && nonce && form.getAttribute('data-hash') === 'sha256') {
        input.value = sha256(input.value + nonce);
      }
    });
  }

  function menus() {
    var all = document.querySelectorAll('details.cal5-menu');
    function closeOthers(keep) {
      for (var i = 0; i < all.length; i++) {
        if (all[i] !== keep && all[i].open && !all[i].contains(keep)) { all[i].open = false; }
      }
    }
    for (var i = 0; i < all.length; i++) {
      all[i].addEventListener('toggle', function (event) {
        var menu = event.target;
        if (menu.open) {
          closeOthers(menu);
          var field = menu.querySelector('input[type="password"]');
          if (field) { field.focus(); }
        }
      });
    }
    document.addEventListener('click', function (event) {
      if (!event.target.closest || !event.target.closest('details.cal5-menu')) { closeOthers(null); }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') { return; }
      var open = document.querySelector('details.cal5-menu[open]');
      if (open) {
        open.open = false;
        open.querySelector('summary').focus();
      }
    });
  }

  function start() {
    var forms = document.querySelectorAll('form.cal5-login');
    for (var i = 0; i < forms.length; i++) { login(forms[i]); }
    menus();
    if (window.hljs) { window.hljs.highlightAll(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
