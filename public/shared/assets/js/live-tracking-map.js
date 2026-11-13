// live-tracking-map.js - renders the Live Delivery Tracking map on the
// customer's Order Tracking page (public/authenticated/customer/
// track_order.php). Provider-agnostic except for the two lines marked below
// - swapping map providers later only touches those.
window.LiveTrackingMap = (function () {
  var map, driverMarker, storeMarker, destMarker, pollTimer;
  var router = null;          // DeliveryRoute instance bound to this map
  var destPoint = null;       // precise drop-off once geocoded
  var destResolved = false;   // only attempt the geocode once
  var didFit = false;         // fit the view once, then stop stealing the map
  var animTimer = null;
  var lastLatLng = null;
  var waitingSlowly = false;  // polling at the slower pre-start cadence
  var WAITING_POLL_MS = 10000;
  var opts = {};

  // A courier marker that reads as a courier. divIcon rather than an image so
  // it needs no new asset and picks up the page's own font stack.
  function courierIcon() {
    return L.divIcon({
      className: 'tw-courier-marker',
      html: '<span class="tw-courier-dot"><span class="tw-courier-glyph">\uD83C\uDFCD</span></span>',
      iconSize: [34, 34],
      iconAnchor: [17, 17]
    });
  }

  // Polls arrive every few seconds; jumping the marker looks broken. Ease it
  // across instead, and cancel any in-flight glide when a newer fix lands.
  function glide(marker, to) {
    if (animTimer) { clearInterval(animTimer); animTimer = null; }
    var from = marker.getLatLng();
    var hop = L.latLng(to[0], to[1]);
    // A big jump (first fix, or a long gap) should just snap.
    if (from.distanceTo(hop) > 2000) { marker.setLatLng(hop); return; }
    var steps = 20, i = 0;
    animTimer = setInterval(function () {
      i++;
      var t = i / steps;
      marker.setLatLng([
        from.lat + (hop.lat - from.lat) * t,
        from.lng + (hop.lng - from.lng) * t
      ]);
      if (i >= steps) { clearInterval(animTimer); animTimer = null; }
    }, 25);
  }

  function init(config) {
    opts = config;
    map = L.map(opts.mapElementId).setView([opts.storeLat, opts.storeLng], 13);

    // --- Leaflet/OSM-specific lines (only these two need to change to swap providers) ---
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 19
    }).addTo(map);
    // --- end provider-specific lines ---

    storeMarker = L.marker([opts.storeLat, opts.storeLng], { title: 'TechWorld Store' }).addTo(map)
      .bindPopup('🏪 TechWorld Store');

    // Route drawing lives in the shared helper so the courier board and this
    // page use one implementation (public/shared/assets/js/delivery-route.js).
    if (window.DeliveryRoute) { router = DeliveryRoute.attach(map); }

    // Drop-off pin BEFORE the first poll, from what the page already knows
    // (passed in from track_order.php). Every pin used to arrive inside the
    // success branch of a poll, so a customer whose rider had not started
    // sharing was shown a map of the shop and nothing else - no indication of
    // where their own order was going. destResolved is deliberately left
    // false: the first live response still gets to upgrade this to the
    // geocoded street address.
    if (typeof opts.destLat === 'number' && typeof opts.destLng === 'number') {
      markDestination({ lat: opts.destLat, lng: opts.destLng }, opts.destExact ? 'pinned' : 'approx');
      fitStoreToDest();
    } else if (opts.destAddress && window.DeliveryRoute) {
      DeliveryRoute.geocode(opts.destAddress).then(function (pt) {
        if (!pt || destPoint) { return; }   // a poll already placed a better one
        markDestination(pt, 'exact');
        fitStoreToDest();
      });
    }

    // Recenter: the map fits once on first fix and then leaves the view
    // alone, so panning to look ahead is not undone every four seconds. This
    // puts the courier back in frame on demand.
    var recenter = document.getElementById(opts.recenterElementId || 'liveTrackingRecenter');
    if (recenter) {
      recenter.addEventListener('click', function () {
        if (!lastLatLng) { return; }
        var b = [lastLatLng];
        if (destPoint) { b.push([destPoint.lat, destPoint.lng]); }
        if (b.length > 1) { map.fitBounds(b, { padding: [40, 40], maxZoom: 16 }); }
        else { map.setView(lastLatLng, 16); }
      });
    }

    setStatusText('Locating your courier…');
    poll();
    pollTimer = setInterval(poll, opts.pollIntervalMs || 4000);
  }

  function poll() {
    fetch(opts.pollUrl + '?order_id=' + encodeURIComponent(opts.orderId), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(handleResponse)
      .catch(function () { setStatusText('Connection issue — retrying…'); });
  }

  function handleResponse(data) {
    if (!data || !data.success) {
      setStatusText((data && data.message) || 'Driver location is temporarily unavailable.');
      // 'not_started' means the rider simply has not pressed Start Tracking
      // yet - the one case where giving up is wrong. This used to stop the
      // timer on every success:false alike, so a customer who opened the page
      // before the rider set off watched a dead map forever; the only way to
      // ever see the courier was to reload at exactly the right moment.
      // Backs off to a slower cadence rather than polling an inactive order
      // every four seconds for as long as the tab is open.
      if (data && data.state === 'not_started') {
        if (!waitingSlowly) {
          waitingSlowly = true;
          if (pollTimer) { clearInterval(pollTimer); }
          pollTimer = setInterval(poll, WAITING_POLL_MS);
        }
        return;
      }
      // Genuinely over or never applicable (delivered/cancelled/reassigned) -
      // stop and leave a static message instead of erroring forever.
      if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
      return;
    }

    // The rider has started: drop back to the live cadence if the waiting
    // state had slowed it down.
    if (waitingSlowly) {
      waitingSlowly = false;
      if (pollTimer) { clearInterval(pollTimer); }
      pollTimer = setInterval(poll, opts.pollIntervalMs || 4000);
    }

    var latlng = [data.driver.lat, data.driver.lng];
    lastLatLng = latlng;
    if (!driverMarker) {
      driverMarker = L.marker(latlng, { title: data.driver.name, icon: courierIcon() }).addTo(map)
        .bindPopup(data.driver.name + ' — on the way');
    } else {
      glide(driverMarker, latlng);
    }

    // Prefer the geocoded street address; fall back to the coarse region pin
    // the API has always sent. Attempted once per page, then cached.
    if (!destResolved) {
      destResolved = true;
      resolveDestination(data);
    }

    if (!didFit) {
      didFit = true;
      var bounds = [latlng, [opts.storeLat, opts.storeLng]];
      if (destPoint) { bounds.push([destPoint.lat, destPoint.lng]); }
      map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
    }

    drawRoute({ lat: data.driver.lat, lng: data.driver.lng });

    // A fix older than a minute means the courier's phone has dropped off -
    // say so rather than presenting a stale position as live.
    var age = data.driver.age_seconds;
    if (typeof age === 'number' && age > 60) {
      var mins = Math.round(age / 60);
      setStatusText('Last seen ' + (mins < 2 ? 'a minute' : mins + ' minutes') + ' ago — reconnecting…');
    } else {
      setStatusText('Live');
    }
    updateTimestamp(data.driver.last_updated);
  }

  // Frames store + drop-off while there is no courier to include yet. didFit
  // stays false so the first real fix still refits to take the rider in.
  function fitStoreToDest() {
    if (!destPoint || didFit) { return; }
    map.fitBounds([[opts.storeLat, opts.storeLng], [destPoint.lat, destPoint.lng]],
                  { padding: [40, 40], maxZoom: 15 });
  }

  function markDestination(point, kind) {
    destPoint = point;
    if (destMarker) { map.removeLayer(destMarker); }
    var popup = kind === 'pinned' ? '📍 Drop-off pinned by you'
              : kind === 'exact'  ? '📍 Your delivery address'
              :                     '📍 Delivery area (approximate)';
    destMarker = L.marker([point.lat, point.lng], { title: 'Delivery address' }).addTo(map)
      .bindPopup(popup);
  }

  function resolveDestination(data) {
    // A destination the server marked `approx: false` is the customer's own
    // map pin (get_driver_location.php). It is already exact, so it must NOT
    // be overwritten by geocoding the address text - the typo'd street line
    // is precisely what the pin exists to override.
    if (data.destination && data.destination.approx === false) {
      markDestination({ lat: data.destination.lat, lng: data.destination.lng }, 'pinned');
      return;
    }
    // Coarse region pin first so something shows immediately...
    if (data.destination) {
      markDestination({ lat: data.destination.lat, lng: data.destination.lng }, 'approx');
    }
    // ...then upgrade to the real address once the geocoder answers.
    if (data.destination_address && window.DeliveryRoute) {
      DeliveryRoute.geocode(data.destination_address).then(function (pt) {
        if (pt) { markDestination(pt, 'exact'); }
      });
    }
  }

  function drawRoute(from) {
    if (!router || !destPoint) { return; }
    router.update(from, destPoint).then(function (result) {
      if (!result) { return; }   // courier has not moved far enough to re-route
      var el = document.getElementById(opts.etaElementId || 'liveTrackingEta');
      if (el) { el.textContent = DeliveryRoute.describe(result); }
    });
  }

  function setStatusText(text) {
    var el = document.getElementById(opts.statusTextElementId);
    if (el) { el.textContent = text; }
  }

  function updateTimestamp(timeText) {
    // Server sends an already-formatted display string (see
    // get_driver_location.php), not an ISO instant - re-parsing it with
    // `new Date()` here would apply the *browser's* timezone on top of
    // whatever the server meant, double-converting and drifting the
    // displayed time. Show it exactly as sent.
    var el = document.getElementById(opts.updatedAtElementId);
    if (el && timeText) { el.textContent = 'Last updated: ' + timeText; }
  }

  return { init: init };
})();
