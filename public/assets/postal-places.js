/* Postal place suggestions: only a complete German postcode is sent to Photon. */
(function () {
    'use strict';
    var cache = new Map(), sequence = 0;
    function enhance() {
        if (typeof document === 'undefined') { return; }
        document.querySelectorAll('input[name="zip"]').forEach(function (zip) {
            var form = zip.form, city = form && form.querySelector('input[name="city"]');
            if (!city || zip.dataset.placesReady || zip.disabled || zip.readOnly) { return; }
            zip.dataset.placesReady = '1';
            var country = form.querySelector('[name="country"]');
            var list = document.createElement('datalist');
            list.id = 'postal-places-' + (++sequence);
            city.setAttribute('list', list.id);
            city.insertAdjacentElement('afterend', list);
            var note = document.createElement('div');
            note.className = 'field-hint';
            note.setAttribute('role', 'status');
            var message = document.createElement('span');
            message.textContent = window.vkText('js.postal_places_hint');
            var credit = document.createElement('a');
            credit.href = 'https://photon.komoot.io/'; credit.textContent = 'Photon / OpenStreetMap';
            credit.target = '_blank'; credit.rel = 'noopener noreferrer';
            note.append(message, credit);
            zip.insertAdjacentElement('afterend', note);
            var timer, controller, revision = 0;
            function valid() {
                return /^\d{5}$/.test(zip.value) && /^\s*(de|deu|deutschland|germany)?\s*$/i.test(country ? country.value : '');
            }
            function show(places) {
                list.replaceChildren();
                places.forEach(function (place) {
                    var option = document.createElement('option');
                    option.value = place; list.append(option);
                });
                message.textContent = places.length ? window.vkText('js.postal_places_ready') : window.vkText('js.postal_places_empty');
            }
            function schedule() {
                clearTimeout(timer); revision++;
                if (controller) { controller.abort(); }
                list.replaceChildren();
                message.textContent = window.vkText('js.postal_places_hint');
                if (!valid()) { return; }
                var code = zip.value, current = revision;
                if (cache.has(code)) { show(cache.get(code)); return; }
                timer = setTimeout(async function () {
                    var request = new AbortController();
                    controller = request;
                    var timeout = setTimeout(function () { request.abort(); }, 5000);
                    message.textContent = window.vkText('js.postal_places_loading');
                    try {
                        var response = await fetch('https://photon.komoot.io/api/?q=' + encodeURIComponent(code) + '&lang=de&limit=10&countrycode=DE', {
                            signal: request.signal, credentials: 'omit', referrerPolicy: 'no-referrer'
                        });
                        if (!response.ok) { throw new Error('Lookup failed'); }
                        var data = await response.json(), places = [];
                        function collect(features) {
                            features.forEach(function (feature) {
                                var p = feature.properties || {};
                                var postcode = p.postcode || (p.osm_value === 'postcode' ? p.name : '');
                                if (p.countrycode !== 'DE' || String(postcode) !== code) { return; }
                                var place = p.city || p.town || p.village || (p.osm_key === 'place' && p.osm_value !== 'postcode' ? p.name : '');
                                if (typeof place === 'string' && place && !places.includes(place)) { places.push(place); }
                            });
                        }
                        var features = Array.isArray(data.features) ? data.features : [];
                        collect(features);
                        // Some postcode areas contain no city; resolve their centre to nearby addresses.
                        var area = features.find(function (feature) {
                            var p = feature.properties || {};
                            return p.countrycode === 'DE' && p.osm_value === 'postcode' && p.name === code;
                        });
                        var coords = area && area.geometry && area.geometry.coordinates;
                        if (!places.length && Array.isArray(coords) && coords.length === 2 && coords.every(Number.isFinite)) {
                            var reverse = await fetch('https://photon.komoot.io/reverse?lon=' + coords[0] + '&lat=' + coords[1] + '&lang=de&limit=5', {
                                signal: request.signal, credentials: 'omit', referrerPolicy: 'no-referrer'
                            });
                            if (!reverse.ok) { throw new Error('Lookup failed'); }
                            var nearby = await reverse.json();
                            collect(Array.isArray(nearby.features) ? nearby.features : []);
                        }
                        cache.set(code, places);
                        if (current === revision && valid()) { show(places); }
                    } catch (error) {
                        if (current === revision) { message.textContent = window.vkText('js.postal_places_failed'); }
                    } finally { clearTimeout(timeout); }
                }, 400);
            }
            zip.addEventListener('input', schedule);
            zip.addEventListener('blur', schedule);
            if (country) { country.addEventListener('input', schedule); country.addEventListener('change', schedule); }
        });
    }
    enhance();
    new MutationObserver(enhance).observe(document.body, {childList: true, subtree: true});
})();
