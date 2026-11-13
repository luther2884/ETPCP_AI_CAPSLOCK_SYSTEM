<?php
/**
 * shared/includes/camera-support.php - one answer to "why is the camera dead?"
 *
 * Browsers expose navigator.mediaDevices ONLY in a secure context: https://,
 * or http:// on localhost / 127.0.0.1 / ::1. On any other plain-http origin
 * the whole API is simply absent - not blocked by a permission the user can
 * grant, not a prompt they missed, just not there. A rider opening
 * http://192.168.1.89/TECHWRLD/... on their phone therefore gets no camera
 * anywhere in this system: not the delivery-proof capture, not the QR
 * scanner. The same page over https:// works.
 *
 * This file exists because that one cause was being reported seven different
 * ways across the pages that use a camera - "not supported on this device",
 * "this browser cannot open the camera", or in one case nothing at all - all
 * of which send people to look for a setting that was never the problem.
 *
 * Every camera entry point now asks window.TWCamera what is wrong and shows
 * the same explanation, including the https URL for the page the user is
 * already on, as something they can tap.
 *
 * Emits only a <script> - no markup, no styling. Safe to include more than
 * once (guarded both in PHP and in JS).
 */
if (defined('TW_CAMERA_SUPPORT_EMITTED')) { return; }
define('TW_CAMERA_SUPPORT_EMITTED', true);
?>
<script>
(function () {
  if (window.TWCamera) { return; }

  // The https twin of the current page. The port is deliberately dropped:
  // this project is served on 80 and 443, so carrying an explicit :80 across
  // would produce an address that cannot connect.
  function secureUrl() {
    return 'https://' + location.hostname + location.pathname + location.search;
  }

  // null when the camera can actually be opened. Otherwise an object saying
  // which of the two reasons it is - they need different answers, and
  // conflating them is what sent people hunting through phone settings.
  function problem() {
    if (!window.isSecureContext) {
      return {
        code: 'insecure',
        message: 'The camera is blocked because this page was opened over an insecure connection (' +
                 location.origin + '). Browsers only allow camera access over HTTPS, or over http on ' +
                 'localhost. This is a browser rule - there is no permission to grant here. ' +
                 'Open this same page over HTTPS instead:',
        url: secureUrl()
      };
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return {
        code: 'unsupported',
        message: 'This browser does not support camera capture. Use an up-to-date Chrome, Edge or Safari.',
        url: null
      };
    }
    return null;
  }

  // Flattened to one line, for a status element that cannot hold a link.
  function text() {
    var p = problem();
    if (!p) { return null; }
    return p.url ? (p.message + ' ' + p.url) : p.message;
  }

  // Writes the explanation into an element, with the URL as a real link when
  // there is one: on a phone, being able to tap it is the difference between
  // a rider fixing this themselves and giving up on the delivery.
  function render(el) {
    var p = problem();
    if (!el || !p) { return p; }
    el.textContent = '';
    el.appendChild(document.createTextNode(p.message));
    if (p.url) {
      el.appendChild(document.createElement('br'));
      var a = document.createElement('a');
      a.href = p.url;
      a.rel = 'noopener';
      a.textContent = p.url;
      a.style.cssText = 'color:inherit;font-weight:700;text-decoration:underline;word-break:break-all;display:inline-block;margin-top:6px;';
      el.appendChild(a);
    }
    return p;
  }

  window.TWCamera = {
    problem: problem,
    text: text,
    render: render,
    secureUrl: secureUrl
  };
})();
</script>
