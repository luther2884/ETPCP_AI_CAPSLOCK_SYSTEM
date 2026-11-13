// address-suggestions.js - AddressSuggestionService
//
// Thin client for public/authenticated/api/address_suggestions.php, following
// the app's existing convention (plain window-attached object, raw fetch(),
// no bundler/module system - see main.js and includes/footer.php).
//
// Backed today by a mock/local PH dataset (config/ph_locations.php). Swapping
// to a real geocoding/Places API later only requires changing the PHP
// endpoint's implementation - this service's method signatures stay the same.
(function () {
  function base() {
    return (window.WEB_ROOT || '/') + 'public/authenticated/api/address_suggestions.php';
  }

  function get(params) {
    var url = base() + '?' + new URLSearchParams(params).toString();
    return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); });
  }

  var AddressSuggestionService = {
    /** @returns {Promise<Array<{id:string,name:string}>>} */
    getRegions: function () {
      return get({ action: 'regions' }).then(function (d) {
        if (d && d.success) { return d.regions; }
        return Promise.reject((d && d.message) || 'Failed to load regions');
      });
    },

    /** @returns {Promise<Array<Object>>} cities/provinces under a region id */
    getLocationsByRegion: function (regionId) {
      return get({ action: 'locations_by_region', region: regionId }).then(function (d) {
        if (d && d.success) { return d.locations; }
        return Promise.reject((d && d.message) || 'Failed to load locations');
      });
    },

    /**
     * Barangays belonging to one city id. Resolves to [] when the dataset has
     * none for that city, which callers treat as "let them type it in".
     * @returns {Promise<Array<string>>}
     */
    getBarangays: function (locationId) {
      return get({ action: 'barangays', location_id: locationId }).then(function (d) {
        if (d && d.success) { return d.barangays || []; }
        return Promise.reject((d && d.message) || 'Failed to load barangays');
      });
    },

    /** @returns {Promise<Array<Object>>} city/district/postal/region/barangay matches */
    searchLocations: function (query) {
      return get({ action: 'search_locations', q: query }).then(function (d) {
        if (d && d.success) { return d.results; }
        return Promise.reject((d && d.message) || 'Location search failed');
      });
    },

    /** @returns {Promise<Array<Object>>} street/landmark matches, optionally scoped to a city */
    searchAddresses: function (query, locationId) {
      var params = { action: 'search_addresses', q: query };
      if (locationId) { params.location_id = locationId; }
      return get(params).then(function (d) {
        if (d && d.success) { return d.results; }
        return Promise.reject((d && d.message) || 'Address search failed');
      });
    },

    /** @returns {Promise<{location:Object,region_name:string,distance_km:number}>} */
    reverseGeocode: function (lat, lng) {
      return get({ action: 'reverse_geocode', lat: lat, lng: lng }).then(function (d) {
        if (d && d.success) { return d; }
        return Promise.reject((d && d.message) || 'Reverse geocoding failed');
      });
    },

    /**
     * Wraps navigator.geolocation in a Promise with normalized error codes
     * (PERMISSION_DENIED / POSITION_UNAVAILABLE / TIMEOUT / UNSUPPORTED) so
     * callers can handle each case without touching the raw browser API.
     * @returns {Promise<{lat:number,lng:number}>}
     */
    getCurrentPosition: function () {
      return new Promise(function (resolve, reject) {
        if (!('geolocation' in navigator)) {
          reject({ code: 'UNSUPPORTED', message: 'Geolocation is not supported by this browser.' });
          return;
        }
        navigator.geolocation.getCurrentPosition(
          function (pos) {
            resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude });
          },
          function (err) {
            var code = 'UNKNOWN';
            var message = 'Could not determine your location.';
            if (err.code === err.PERMISSION_DENIED) { code = 'PERMISSION_DENIED'; message = 'Location permission was denied.'; }
            else if (err.code === err.POSITION_UNAVAILABLE) { code = 'POSITION_UNAVAILABLE'; message = 'Your location is currently unavailable.'; }
            else if (err.code === err.TIMEOUT) { code = 'TIMEOUT'; message = 'Getting your location timed out.'; }
            reject({ code: code, message: message });
          },
          { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
      });
    },

    /** Generic debounce helper for wiring up input listeners. */
    debounce: function (fn, delayMs) {
      var timer = null;
      return function () {
        var args = arguments;
        var self = this;
        clearTimeout(timer);
        timer = setTimeout(function () { fn.apply(self, args); }, delayMs || 300);
      };
    }
  };

  window.AddressSuggestionService = AddressSuggestionService;
})();
