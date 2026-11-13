// driver-location-sender.js - wraps navigator.geolocation.watchPosition()
// for the driver-side "Start Tracking" flow (courier/dispatch.php and
// admin/Delivery_Report.php).
// Throttles outgoing location updates so a moving phone doesn't hammer the
// server: a fix is only POSTed once it's moved a meaningful distance OR
// enough time has passed since the last send, whichever comes first.
window.DriverLocationSender = (function () {
  var MIN_DISTANCE_METERS = 20;
  var MIN_INTERVAL_MS = 8000;

  // Anything vaguely this imprecise is a wifi/cell-tower estimate, not GPS,
  // and would teleport the courier across town on the customer's map.
  var MAX_ACCURACY_METERS = 50;

  // How long to hold out for a fix that actually clears MAX_ACCURACY_METERS
  // before taking the best one seen instead. This bound is the whole fix for
  // the bug where tracking never started at all: the accuracy test used to be
  // absolute, so a device that only ever reports coarse fixes - every desktop
  // and laptop, where the "GPS" is a wifi/IP lookup accurate to hundreds or
  // thousands of metres - dropped every single fix, silently and forever.
  // onFirstFix therefore never ran, the start_tracking POST never happened,
  // delivery_tracking.is_tracking stayed 0, and the customer's Live Delivery
  // Tracking map never appeared. Nothing errored, so the button looked dead.
  var FIRST_FIX_GRACE_MS = 12000;

  // No position callback AND no error callback at all by this point - a
  // permission prompt left sitting unanswered, or a watch that never fires.
  // watchPosition's own `timeout` does not reliably cover this.
  var FIRST_FIX_TIMEOUT_MS = 35000;

  // Once tracking is live, prefer precision - but never let the customer's map
  // freeze outright. If no fix good enough to send has arrived in this long,
  // send the coarse one: a roughly-placed courier that is moving beats a
  // pin stuck where the ride began.
  var STALE_SEND_MS = 30000;

  var watchId = null;
  var orderId = null;
  var updateUrl = null;
  var lastSentAt = 0;
  var lastSentLat = null;
  var lastSentLng = null;
  var gotFirstFix = false;
  var startedAt = 0;
  var firstFixTimer = null;
  var callbacks = {};

  // Haversine distance in meters - cheap, no external library, plenty
  // precise for a "did the driver actually move" threshold check.
  function distanceMeters(lat1, lng1, lat2, lng2) {
    var R = 6371000;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLng = (lng2 - lng1) * Math.PI / 180;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
      Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function sendUpdate(lat, lng, accuracy) {
    fetch(updateUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId, lat: lat, lng: lng, accuracy: accuracy })
    }).catch(function () { /* next fix will retry naturally */ });
    lastSentAt = Date.now();
    lastSentLat = lat;
    lastSentLng = lng;
  }

  function say(msg) {
    if (callbacks.onWaiting) { callbacks.onWaiting(msg); }
  }

  function handlePosition(position) {
    if (!position || !position.coords) { return; }
    var lat = position.coords.latitude;
    var lng = position.coords.longitude;
    var accuracy = position.coords.accuracy;
    var precise = !(accuracy > MAX_ACCURACY_METERS);

    if (!gotFirstFix) {
      // Hold out for a precise fix, but only for FIRST_FIX_GRACE_MS - after
      // that this fix is the best this device is going to offer, and starting
      // tracking with a coarse position is strictly better than never
      // starting it. Each rejection now says so out loud rather than
      // returning into silence.
      if (!precise && (Date.now() - startedAt) < FIRST_FIX_GRACE_MS) {
        say('Waiting for a sharper GPS fix (currently ±' + Math.round(accuracy) +
            'm, want ±' + MAX_ACCURACY_METERS + 'm)…');
        return;
      }
      gotFirstFix = true;
      clearFirstFixTimer();
      if (!precise) {
        say('Starting with an approximate fix (±' + Math.round(accuracy) +
            'm) - this device has no precise GPS.');
      }
      // The first fix goes through the caller's own start_tracking POST, not
      // sendUpdate() - that POST is what flips delivery_tracking.is_tracking
      // on and reloads the board.
      if (callbacks.onFirstFix) { callbacks.onFirstFix(lat, lng, accuracy); }
      return;
    }

    // Live now. Keep the precision bar while the customer's map is still
    // fresh, but don't let a phone with poor GPS strand them on a stale pin.
    if (!precise && (Date.now() - lastSentAt) < STALE_SEND_MS) { return; }

    var elapsed = Date.now() - lastSentAt;
    var moved = (lastSentLat === null) ? Infinity : distanceMeters(lastSentLat, lastSentLng, lat, lng);
    if (moved >= MIN_DISTANCE_METERS || elapsed >= MIN_INTERVAL_MS) {
      sendUpdate(lat, lng, accuracy);
    }
  }

  function handleError(err) {
    var msg = 'Location permission is required to start live delivery tracking.';
    if (err && err.code === err.POSITION_UNAVAILABLE) { msg = 'Location is temporarily unavailable. Please check your GPS/signal.'; }
    else if (err && err.code === err.TIMEOUT) { msg = 'Location request timed out. Please try again.'; }
    // A TIMEOUT after tracking is already live is just one missed fix - the
    // watch keeps running and the next one will come. Reporting it as a
    // failure to start would be wrong, and would tear down a working feed.
    if (gotFirstFix) { say(msg); return; }
    clearFirstFixTimer();
    stop();
    if (callbacks.onError) { callbacks.onError(msg); }
  }

  function clearFirstFixTimer() {
    if (firstFixTimer !== null) { clearTimeout(firstFixTimer); firstFixTimer = null; }
  }

  function start(orderIdArg, opts) {
    opts = opts || {};
    if (!navigator.geolocation) {
      if (opts.onError) { opts.onError('This browser does not support location sharing.'); }
      return;
    }
    // navigator.geolocation exists on a plain-http origin but every call fails
    // with PERMISSION_DENIED and no prompt - the same secure-context rule that
    // governs the camera. Say which it is up front instead of leaving the
    // rider to read "permission is required" about a permission they cannot
    // be asked for.
    if (!window.isSecureContext) {
      if (opts.onError) {
        opts.onError('Location sharing is blocked because this page was opened over an insecure ' +
                     'connection (' + location.origin + '). Browsers only allow it over HTTPS, or over ' +
                     'http on localhost. Open this page over HTTPS' +
                     (window.TWCamera ? ': ' + window.TWCamera.secureUrl() : '.'));
      }
      return;
    }

    // A second tap used to overwrite watchId and leak the first watch, so two
    // watchers then fed the same order.
    stop();

    orderId = orderIdArg;
    updateUrl = opts.updateUrl;
    callbacks = opts;
    gotFirstFix = false;
    lastSentAt = 0;
    lastSentLat = null;
    lastSentLng = null;
    startedAt = Date.now();

    say('Getting a GPS fix…');

    watchId = navigator.geolocation.watchPosition(handlePosition, handleError, {
      enableHighAccuracy: true,
      maximumAge: 5000,
      timeout: 20000
    });

    // Neither callback firing at all is its own failure mode (an unanswered
    // permission prompt, a wedged location service) and used to leave the
    // rider staring at a button that had apparently done nothing.
    firstFixTimer = setTimeout(function () {
      firstFixTimer = null;
      if (gotFirstFix) { return; }
      stop();
      if (callbacks.onError) {
        callbacks.onError('No GPS fix arrived in ' + Math.round(FIRST_FIX_TIMEOUT_MS / 1000) +
                          ' seconds. Check that Location is turned on for this browser, then try again.');
      }
    }, FIRST_FIX_TIMEOUT_MS);
  }

  function stop() {
    clearFirstFixTimer();
    if (watchId !== null) {
      navigator.geolocation.clearWatch(watchId);
      watchId = null;
    }
  }

  return { start: start, stop: stop, isTracking: function () { return watchId !== null; } };
})();
