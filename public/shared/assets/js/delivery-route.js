// delivery-route.js - shared geocoding + road-routing helper for the live
// delivery maps. Used by BOTH the customer tracking map
// (live-tracking-map.js) and the courier board (courier/dispatch.php), so the
// route logic exists in one place rather than once per page.
//
// Two public, keyless services, both called from the browser:
//   Nominatim (OpenStreetMap) turns an address string into coordinates.
//     Nothing in the schema stores lat/lng for a delivery address, so this is
//     the only way to get a precise drop-off pin. Results are cached per
//     address in sessionStorage - their usage policy asks for at most one
//     request a second and no repeat lookups for the same input.
//   OSRM returns the driving route as GeoJSON, which Leaflet draws directly.
//
// Both are public demo endpoints: fine for this project, not something to
// depend on in production without a self-hosted instance or a paid provider.
// Every call fails soft - the caller always gets a usable line.
window.DeliveryRoute = (function () {

  var GEOCODE_URL = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=';
  var ROUTE_URL   = 'https://router.project-osrm.org/route/v1/driving/';
  // Trip solves the visiting ORDER as well as the path (a travelling-salesman
  // solve), which is the difference between "route through these in the order
  // I listed them" and "work out the best order to drop these off".
  var TRIP_URL    = 'https://router.project-osrm.org/trip/v1/driving/';
  var MAX_STOPS   = 8;   // demo server is brute-force below ~10; keep it kind
  // Spec: recalculate at most every 30s, OR immediately once the courier is
  // more than 50m off the drawn route. The time floor stops a moving vehicle
  // hammering the demo server; the distance rule means a wrong turn is
  // corrected straight away instead of waiting out the timer.
  var REROUTE_MIN_MS = 30000;
  var OFF_ROUTE_METERS = 50;

  // Straight-line distance. Used for the "has the courier moved enough to
  // bother re-routing" test and for the fallback readout.
  function haversine(lat1, lng1, lat2, lng2) {
    var R = 6371000, p = Math.PI / 180;
    var dLat = (lat2 - lat1) * p, dLng = (lng2 - lng1) * p;
    var s = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * p) * Math.cos(lat2 * p) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.asin(Math.sqrt(s));
  }

  function geocode(address) {
    if (!address) { return Promise.resolve(null); }
    var key = 'twgeo:' + address;
    try {
      var hit = sessionStorage.getItem(key);
      if (hit) { return Promise.resolve(JSON.parse(hit)); }
    } catch (e) { /* private mode - just skip the cache */ }

    return fetch(GEOCODE_URL + encodeURIComponent(address))
      .then(function (r) { return r.json(); })
      .then(function (rows) {
        if (!rows || !rows.length) { return null; }
        var out = { lat: parseFloat(rows[0].lat), lng: parseFloat(rows[0].lon) };
        try { sessionStorage.setItem(key, JSON.stringify(out)); } catch (e) {}
        return out;
      })
      .catch(function () { return null; });
  }

  // Geocode several addresses in order. Sequential on purpose: Nominatim asks
  // for at most one request a second. Cached addresses resolve instantly and
  // skip the wait, so a re-plan of the same stops is immediate.
  function geocodeAll(addresses, onProgress) {
    var out = [];
    return addresses.reduce(function (chain, addr, i) {
      return chain.then(function () {
        if (onProgress) { onProgress(i + 1, addresses.length); }
        var cached = false;
        try { cached = !!sessionStorage.getItem('twgeo:' + addr); } catch (e) {}
        return geocode(addr).then(function (pt) {
          out.push(pt ? { lat: pt.lat, lng: pt.lng, address: addr } : null);
          if (!cached) { return new Promise(function (r) { setTimeout(r, 1100); }); }
        });
      });
    }, Promise.resolve()).then(function () { return out; });
  }

  // Creates a route drawer bound to one Leaflet map. Keeps its own layer and
  // its own "where did I last route from" so repeated calls are cheap.
  function attach(map, style) {
    style = style || {};
    var line = null;
    var lastRouteAt = 0;
    var lastGeometry = null;   // cached so an OSRM outage keeps the last good route
    var lastResult = null;

    function clear() {
      if (line) { map.removeLayer(line); line = null; }
      lastRouteAt = 0; lastGeometry = null; lastResult = null;
    }

    // Shortest distance from the courier to the drawn route. Measured to the
    // route's vertices rather than to its segments - a few metres less exact,
    // and plenty for a 50m "have they left the route" test.
    function metersOffRoute(from) {
      if (!lastGeometry || !lastGeometry.coordinates) { return Infinity; }
      var best = Infinity;
      var c = lastGeometry.coordinates;
      for (var i = 0; i < c.length; i++) {
        // GeoJSON stores [lng, lat]; haversine takes (lat, lng).
        var d = haversine(from.lat, from.lng, c[i][1], c[i][0]);
        if (d < best) { best = d; }
      }
      return best;
    }

    function straight(from, to) {
      if (line) { map.removeLayer(line); }
      line = L.polyline([[from.lat, from.lng], [to.lat, to.lng]], {
        color: style.fallbackColor || '#f59e0b',
        weight: style.weight || 4,
        opacity: 0.85,
        dashArray: '8 8'
      }).addTo(map);
      return {
        km: haversine(from.lat, from.lng, to.lat, to.lng) / 1000,
        minutes: null,
        road: false,
        bounds: line.getBounds()
      };
    }

    // Resolves with {km, minutes, road, bounds}, or null when the courier has
    // not moved far enough to be worth re-routing.
    function update(from, to, force) {
      if (!from || !to) { return Promise.resolve(null); }
      var elapsed = Date.now() - lastRouteAt;
      var strayed = metersOffRoute(from) > OFF_ROUTE_METERS;
      if (!force && lastRouteAt && elapsed < REROUTE_MIN_MS && !strayed) {
        return Promise.resolve(null);
      }
      lastRouteAt = Date.now();

      // COORDINATE ORDER: OSRM wants lng,lat - the reverse of Leaflet's
      // lat,lng. Getting this backwards puts Philippine deliveries in the
      // Southern Ocean, so the swap happens here and nowhere else.
      return fetch(ROUTE_URL + from.lng + ',' + from.lat + ';' + to.lng + ',' + to.lat +
                   '?overview=full&geometries=geojson')
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || j.code !== 'Ok' || !j.routes || !j.routes.length) { throw new Error('no route'); }
          var r0 = j.routes[0];
          if (line) { map.removeLayer(line); }
          lastGeometry = r0.geometry;
          line = L.geoJSON(r0.geometry, {
            style: {
              color: style.color || '#38bdf8',
              weight: style.weight || 5,
              opacity: 0.9
            }
          }).addTo(map);
          lastResult = {
            km: r0.distance / 1000,
            minutes: Math.max(1, Math.round(r0.duration / 60)),
            road: true,
            stale: false,
            bounds: line.getBounds()
          };
          return lastResult;
        })
        .catch(function () {
          // Keep the last good route on screen rather than replacing a real
          // road route with a straight line because one request failed.
          if (lastResult && line) {
            return {
              km: lastResult.km, minutes: lastResult.minutes,
              road: true, stale: true, bounds: lastResult.bounds
            };
          }
          return straight(from, to);
        });
    }

    // Multi-stop. `from` is the courier; `points` are the drop-offs in any
    // order. Resolves with the optimised visiting order plus the whole
    // drawn trip, or null if it could not be solved.
    function trip(from, points) {
      if (!from || !points || !points.length) { return Promise.resolve(null); }
      var pts = points.slice(0, MAX_STOPS);

      // COORDINATE ORDER: OSRM takes lng,lat - Leaflet's lat,lng reversed.
      var coords = [from].concat(pts).map(function (p) {
        return p.lng + ',' + p.lat;
      }).join(';');

      return fetch(TRIP_URL + coords +
                   '?source=first&roundtrip=false&overview=full&geometries=geojson')
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || j.code !== 'Ok' || !j.trips || !j.trips.length) { throw new Error('no trip'); }
          var t = j.trips[0];
          if (line) { map.removeLayer(line); }
          lastGeometry = t.geometry;
          line = L.geoJSON(t.geometry, {
            style: { color: style.color || '#38bdf8', weight: style.weight || 5, opacity: 0.9 }
          }).addTo(map);
          lastRouteAt = Date.now();

          // waypoints[] mirrors the input; waypoint_index is that stop's
          // position in the solved trip. Index 0 is the courier, so a drop-off
          // at input i sits at pts[i - 1].
          var order = [];
          for (var i = 1; i < j.waypoints.length; i++) {
            order.push({ point: i - 1, seq: j.waypoints[i].waypoint_index });
          }
          order.sort(function (a, b) { return a.seq - b.seq; });

          return {
            km: t.distance / 1000,
            minutes: Math.max(1, Math.round(t.duration / 60)),
            order: order.map(function (o) { return o.point; }),
            dropped: points.length - pts.length,
            bounds: line.getBounds()
          };
        })
        .catch(function () { return null; });
    }

    return { update: update, clear: clear, trip: trip };
  }

  // "12.4 km · about 23 min by road" / "8.1 km direct (straight line)"
  function describe(result) {
    if (!result) { return ''; }
    if (result.road) {
      return result.km.toFixed(1) + ' km · about ' + result.minutes + ' min by road' +
             (result.stale ? ' (last known route — reconnecting)' : '');
    }
    return result.km.toFixed(1) + ' km direct · road route unavailable';
  }

  return {
    geocode: geocode,
    geocodeAll: geocodeAll,
    maxStops: MAX_STOPS,
    attach: attach,
    describe: describe,
    haversine: haversine
  };
})();
