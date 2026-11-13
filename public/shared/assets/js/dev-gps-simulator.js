// dev-gps-simulator.js - DEV ONLY. Fakes a driver moving from the store to
// an approximate delivery destination, so the Live Delivery Tracking map on
// the customer's Order Tracking page can be tested end-to-end without a
// real phone/GPS. Only ever loaded by admin/Delivery_Report.php when the
// viewer is admin/super-admin (never a driver) AND the server is running on
// localhost/a private IP (config/functions.php's isLocalDevEnvironment()) -
// this file's own hostname check below is a second, client-side layer on
// top of that, not the real gate (the real gate is server-side, in
// api/update_driver_location.php).
window.DevGpsSimulator = (function () {
  var STEPS = 60;
  var STEP_MS = 3000;
  var updateUrl = null;

  function isLocalHost() {
    var h = location.hostname;
    return h === 'localhost' || h === '127.0.0.1' || h.indexOf('192.168.') === 0 || h.indexOf('10.') === 0;
  }

  function configure(url) {
    updateUrl = url;
  }

  function start(orderId, storeLat, storeLng, destLat, destLng) {
    if (!isLocalHost()) {
      alert('Simulation is only available on a local/dev environment.');
      return null;
    }
    if (!updateUrl) {
      alert('Simulator is not configured.');
      return null;
    }

    var i = 0;
    var timer = setInterval(function () {
      if (i > STEPS) {
        clearInterval(timer);
        return;
      }
      var t = i / STEPS;
      var lat = storeLat + (destLat - storeLat) * t;
      var lng = storeLng + (destLng - storeLng) * t;
      fetch(updateUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: orderId, lat: lat, lng: lng, accuracy: 10, dev_sim: true })
      }).catch(function () {});
      i++;
    }, STEP_MS);

    return timer;
  }

  return { configure: configure, start: start };
})();
