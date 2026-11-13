<?php
/**
 * config/ph_locations.php - Curated Philippines regions/cities/landmark dataset
 * used by the address suggestion/autocomplete feature.
 *
 * This is a compact, hand-curated mock dataset (NOT an exhaustive PSGC dump) -
 * enough real coverage of major cities/provinces per region, with approximate
 * centroid coordinates and a representative postal code, to power working
 * autocomplete and "nearest city" reverse geocoding today.
 *
 * Swapping to a real geocoding/address API later only requires reimplementing
 * the functions below (getPhRegions/getPhLocations/getPhLandmarks) - callers
 * (public/authenticated/api/address_suggestions.php) don't need to change.
 */

/**
 * The 5 region categories shown in the app's region picker, each mapped to
 * the broader PH region(s) it groups together.
 */
function getPhCuratedRegions() {
    return [
        ['id' => 'metro_manila', 'name' => 'Metro Manila'],
        ['id' => 'north_luzon',  'name' => 'North Luzon'],
        ['id' => 'south_luzon',  'name' => 'South Luzon'],
        ['id' => 'visayas',      'name' => 'Visayas'],
        ['id' => 'mindanao',     'name' => 'Mindanao'],
    ];
}

/**
 * City/municipality-level locations. Each entry:
 *   id       - stable slug
 *   name     - city/municipality name
 *   province - province name (same as city for HUCs like Metro Manila cities)
 *   region_id- one of the getPhRegions() ids
 *   postal   - representative postal code (a city can span several; we store
 *              the primary/most common one)
 *   lat/lng  - approximate centroid, used for reverse-geocode nearest-match
 */
function getPhCuratedLocations() {
    return [
        // Metro Manila
        ['id' => 'quezon-city',   'name' => 'Quezon City',   'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1100', 'lat' => 14.6760, 'lng' => 121.0437],
        ['id' => 'manila',        'name' => 'Manila',        'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1000', 'lat' => 14.5995, 'lng' => 120.9842],
        ['id' => 'makati',        'name' => 'Makati',        'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1200', 'lat' => 14.5547, 'lng' => 121.0244],
        ['id' => 'pasig',         'name' => 'Pasig',         'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1600', 'lat' => 14.5764, 'lng' => 121.0851],
        ['id' => 'taguig',        'name' => 'Taguig',        'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1630', 'lat' => 14.5176, 'lng' => 121.0509],
        ['id' => 'paranaque',     'name' => 'Parañaque',     'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1700', 'lat' => 14.4793, 'lng' => 121.0198],
        ['id' => 'mandaluyong',   'name' => 'Mandaluyong',   'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1550', 'lat' => 14.5794, 'lng' => 121.0359],
        ['id' => 'san-juan',      'name' => 'San Juan',      'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1500', 'lat' => 14.6019, 'lng' => 121.0355],
        // Caloocan is physically two separate halves (Quezon City and Valenzuela
        // sit between them), with their own postal ranges and their own barangay
        // numbering, so they are listed as two pickable cities. A single
        // "Caloocan" entry made every North Caloocan address read as postal 1400,
        // which is South, and gave couriers no way to tell the halves apart.
        ['id' => 'caloocan-south', 'name' => 'Caloocan (South)', 'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1400', 'lat' => 14.6488, 'lng' => 120.9673],
        ['id' => 'caloocan-north', 'name' => 'Caloocan (North)', 'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1420', 'lat' => 14.7566, 'lng' => 121.0450],
        ['id' => 'las-pinas',     'name' => 'Las Piñas',     'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1740', 'lat' => 14.4499, 'lng' => 120.9829],
        ['id' => 'muntinlupa',    'name' => 'Muntinlupa',    'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1770', 'lat' => 14.4081, 'lng' => 121.0415],
        ['id' => 'marikina',      'name' => 'Marikina',      'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1800', 'lat' => 14.6507, 'lng' => 121.1029],
        ['id' => 'pasay',         'name' => 'Pasay',         'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1300', 'lat' => 14.5378, 'lng' => 121.0014],
        ['id' => 'valenzuela',    'name' => 'Valenzuela',    'province' => 'Metro Manila', 'region_id' => 'metro_manila', 'postal' => '1440', 'lat' => 14.7011, 'lng' => 120.9830],

        // North Luzon
        ['id' => 'baguio',        'name' => 'Baguio City',   'province' => 'Benguet',        'region_id' => 'north_luzon', 'postal' => '2600', 'lat' => 16.4023, 'lng' => 120.5960],
        ['id' => 'dagupan',       'name' => 'Dagupan City',  'province' => 'Pangasinan',      'region_id' => 'north_luzon', 'postal' => '2400', 'lat' => 16.0433, 'lng' => 120.3333],
        ['id' => 'laoag',         'name' => 'Laoag City',    'province' => 'Ilocos Norte',    'region_id' => 'north_luzon', 'postal' => '2900', 'lat' => 18.1978, 'lng' => 120.5936],
        ['id' => 'vigan',         'name' => 'Vigan City',    'province' => 'Ilocos Sur',      'region_id' => 'north_luzon', 'postal' => '2700', 'lat' => 17.5747, 'lng' => 120.3869],
        ['id' => 'tuguegarao',    'name' => 'Tuguegarao City','province' => 'Cagayan',        'region_id' => 'north_luzon', 'postal' => '3500', 'lat' => 17.6132, 'lng' => 121.7270],
        ['id' => 'san-fernando-la','name' => 'San Fernando', 'province' => 'La Union',        'region_id' => 'north_luzon', 'postal' => '2500', 'lat' => 16.6159, 'lng' => 120.3209],
        ['id' => 'tarlac-city',   'name' => 'Tarlac City',   'province' => 'Tarlac',          'region_id' => 'north_luzon', 'postal' => '2300', 'lat' => 15.4890, 'lng' => 120.5980],
        ['id' => 'san-fernando-pampanga', 'name' => 'San Fernando (Pampanga)', 'province' => 'Pampanga', 'region_id' => 'north_luzon', 'postal' => '2000', 'lat' => 15.0349, 'lng' => 120.6897],
        ['id' => 'olongapo',      'name' => 'Olongapo City', 'province' => 'Zambales',        'region_id' => 'north_luzon', 'postal' => '2200', 'lat' => 14.8294, 'lng' => 120.2827],
        ['id' => 'cabanatuan',    'name' => 'Cabanatuan City','province' => 'Nueva Ecija',    'region_id' => 'north_luzon', 'postal' => '3100', 'lat' => 15.4869, 'lng' => 120.9683],

        // South Luzon
        ['id' => 'lucena',        'name' => 'Lucena City',   'province' => 'Quezon',          'region_id' => 'south_luzon', 'postal' => '4301', 'lat' => 13.9314, 'lng' => 121.6170],
        ['id' => 'batangas-city', 'name' => 'Batangas City',  'province' => 'Batangas',        'region_id' => 'south_luzon', 'postal' => '4200', 'lat' => 13.7565, 'lng' => 121.0583],
        ['id' => 'lipa',          'name' => 'Lipa City',      'province' => 'Batangas',        'region_id' => 'south_luzon', 'postal' => '4217', 'lat' => 13.9411, 'lng' => 121.1624],
        ['id' => 'calamba',       'name' => 'Calamba City',   'province' => 'Laguna',          'region_id' => 'south_luzon', 'postal' => '4027', 'lat' => 14.2117, 'lng' => 121.1653],
        ['id' => 'santa-rosa',    'name' => 'Santa Rosa City','province' => 'Laguna',          'region_id' => 'south_luzon', 'postal' => '4026', 'lat' => 14.3122, 'lng' => 121.1114],
        ['id' => 'antipolo',      'name' => 'Antipolo City',  'province' => 'Rizal',           'region_id' => 'south_luzon', 'postal' => '1870', 'lat' => 14.5878, 'lng' => 121.1760],
        ['id' => 'dasmarinas',    'name' => 'Dasmariñas City','province' => 'Cavite',          'region_id' => 'south_luzon', 'postal' => '4114', 'lat' => 14.3294, 'lng' => 120.9367],
        ['id' => 'bacoor',        'name' => 'Bacoor City',    'province' => 'Cavite',          'region_id' => 'south_luzon', 'postal' => '4102', 'lat' => 14.4590, 'lng' => 120.9469],
        ['id' => 'naga',          'name' => 'Naga City',      'province' => 'Camarines Sur',   'region_id' => 'south_luzon', 'postal' => '4400', 'lat' => 13.6218, 'lng' => 123.1948],
        ['id' => 'legazpi',       'name' => 'Legazpi City',   'province' => 'Albay',           'region_id' => 'south_luzon', 'postal' => '4500', 'lat' => 13.1391, 'lng' => 123.7438],
        ['id' => 'puerto-princesa','name'=> 'Puerto Princesa City', 'province' => 'Palawan',   'region_id' => 'south_luzon', 'postal' => '5300', 'lat' => 9.7392,  'lng' => 118.7353],

        // Visayas
        ['id' => 'cebu-city',     'name' => 'Cebu City',     'province' => 'Cebu',            'region_id' => 'visayas', 'postal' => '6000', 'lat' => 10.3157, 'lng' => 123.8854],
        ['id' => 'mandaue',       'name' => 'Mandaue City',  'province' => 'Cebu',            'region_id' => 'visayas', 'postal' => '6014', 'lat' => 10.3237, 'lng' => 123.9224],
        ['id' => 'lapu-lapu',     'name' => 'Lapu-Lapu City','province' => 'Cebu',            'region_id' => 'visayas', 'postal' => '6015', 'lat' => 10.3103, 'lng' => 123.9494],
        ['id' => 'iloilo-city',   'name' => 'Iloilo City',   'province' => 'Iloilo',          'region_id' => 'visayas', 'postal' => '5000', 'lat' => 10.7202, 'lng' => 122.5621],
        ['id' => 'bacolod',       'name' => 'Bacolod City',  'province' => 'Negros Occidental','region_id' => 'visayas', 'postal' => '6100', 'lat' => 10.6765, 'lng' => 122.9509],
        ['id' => 'tacloban',      'name' => 'Tacloban City',  'province' => 'Leyte',           'region_id' => 'visayas', 'postal' => '6500', 'lat' => 11.2543, 'lng' => 125.0000],
        ['id' => 'dumaguete',     'name' => 'Dumaguete City', 'province' => 'Negros Oriental',  'region_id' => 'visayas', 'postal' => '6200', 'lat' => 9.3103,  'lng' => 123.3080],
        ['id' => 'roxas-city',    'name' => 'Roxas City',     'province' => 'Capiz',           'region_id' => 'visayas', 'postal' => '5800', 'lat' => 11.5853, 'lng' => 122.7511],
        ['id' => 'tagbilaran',    'name' => 'Tagbilaran City', 'province' => 'Bohol',          'region_id' => 'visayas', 'postal' => '6300', 'lat' => 9.6474,  'lng' => 123.8536],

        // Mindanao
        ['id' => 'davao-city',    'name' => 'Davao City',    'province' => 'Davao del Sur',   'region_id' => 'mindanao', 'postal' => '8000', 'lat' => 7.1907,  'lng' => 125.4553],
        ['id' => 'cagayan-de-oro','name' => 'Cagayan de Oro City','province' => 'Misamis Oriental', 'region_id' => 'mindanao', 'postal' => '9000', 'lat' => 8.4542, 'lng' => 124.6319],
        ['id' => 'zamboanga-city','name' => 'Zamboanga City', 'province' => 'Zamboanga del Sur','region_id' => 'mindanao', 'postal' => '7000', 'lat' => 6.9214,  'lng' => 122.0790],
        ['id' => 'general-santos','name' => 'General Santos City', 'province' => 'South Cotabato', 'region_id' => 'mindanao', 'postal' => '9500', 'lat' => 6.1164, 'lng' => 125.1716],
        ['id' => 'iligan',        'name' => 'Iligan City',    'province' => 'Lanao del Norte',  'region_id' => 'mindanao', 'postal' => '9200', 'lat' => 8.2280,  'lng' => 124.2452],
        ['id' => 'butuan',        'name' => 'Butuan City',    'province' => 'Agusan del Norte', 'region_id' => 'mindanao', 'postal' => '8600', 'lat' => 8.9475,  'lng' => 125.5406],
        ['id' => 'cotabato-city', 'name' => 'Cotabato City',  'province' => 'Maguindanao',      'region_id' => 'mindanao', 'postal' => '9600', 'lat' => 7.2231,  'lng' => 124.2452],
        ['id' => 'marawi',        'name' => 'Marawi City',    'province' => 'Lanao del Sur',    'region_id' => 'mindanao', 'postal' => '9700', 'lat' => 8.0043,  'lng' => 124.2933],
        ['id' => 'tagum',         'name' => 'Tagum City',     'province' => 'Davao del Norte',  'region_id' => 'mindanao', 'postal' => '8100', 'lat' => 7.4478,  'lng' => 125.8079],
        ['id' => 'pagadian',      'name' => 'Pagadian City',  'province' => 'Zamboanga del Sur','region_id' => 'mindanao', 'postal' => '7016', 'lat' => 7.8257,  'lng' => 123.4372],
    ];
}

/**
 * Street/landmark-style suggestions for the free-text street/address field,
 * mocking what a Places/Geocoding API would return. Grouped by the city
 * (location id from getPhLocations()) they belong to.
 */
function getPhLandmarks() {
    return [
        ['name' => 'Quezon City',                 'type' => 'city',     'location_id' => 'quezon-city'],
        ['name' => 'Quezon Avenue',                'type' => 'street',   'location_id' => 'quezon-city'],
        ['name' => 'Quezon Memorial Circle',       'type' => 'landmark', 'location_id' => 'quezon-city'],
        ['name' => 'Quezon City Hall',             'type' => 'landmark', 'location_id' => 'quezon-city'],
        ['name' => 'Commonwealth Avenue',          'type' => 'street',   'location_id' => 'quezon-city'],
        ['name' => 'Tomas Morato Avenue',          'type' => 'street',   'location_id' => 'quezon-city'],
        ['name' => 'Katipunan Avenue',             'type' => 'street',   'location_id' => 'quezon-city'],
        ['name' => 'SM North EDSA',                'type' => 'landmark', 'location_id' => 'quezon-city'],
        ['name' => 'Trinoma Mall',                 'type' => 'landmark', 'location_id' => 'quezon-city'],

        ['name' => 'Manila',                       'type' => 'city',     'location_id' => 'manila'],
        ['name' => 'Roxas Boulevard',               'type' => 'street',   'location_id' => 'manila'],
        ['name' => 'Taft Avenue',                   'type' => 'street',   'location_id' => 'manila'],
        ['name' => 'Rizal Park (Luneta)',           'type' => 'landmark', 'location_id' => 'manila'],
        ['name' => 'Intramuros',                    'type' => 'landmark', 'location_id' => 'manila'],
        ['name' => 'Manila City Hall',              'type' => 'landmark', 'location_id' => 'manila'],
        ['name' => 'Divisoria',                     'type' => 'landmark', 'location_id' => 'manila'],

        ['name' => 'Makati',                        'type' => 'city',     'location_id' => 'makati'],
        ['name' => 'Ayala Avenue',                  'type' => 'street',   'location_id' => 'makati'],
        ['name' => 'Makati Avenue',                 'type' => 'street',   'location_id' => 'makati'],
        ['name' => 'Greenbelt Mall',                'type' => 'landmark', 'location_id' => 'makati'],
        ['name' => 'Glorietta',                     'type' => 'landmark', 'location_id' => 'makati'],
        ['name' => 'Makati Central Business District','type' => 'landmark', 'location_id' => 'makati'],

        ['name' => 'Pasig',                         'type' => 'city',     'location_id' => 'pasig'],
        ['name' => 'Ortigas Avenue',                'type' => 'street',   'location_id' => 'pasig'],
        ['name' => 'Shaw Boulevard',                'type' => 'street',   'location_id' => 'pasig'],
        ['name' => 'SM Megamall',                   'type' => 'landmark', 'location_id' => 'pasig'],

        ['name' => 'Taguig',                        'type' => 'city',     'location_id' => 'taguig'],
        ['name' => 'Bonifacio Global City (BGC)',   'type' => 'landmark', 'location_id' => 'taguig'],
        ['name' => 'McKinley Parkway',              'type' => 'street',   'location_id' => 'taguig'],

        ['name' => 'Parañaque',                     'type' => 'city',     'location_id' => 'paranaque'],
        ['name' => 'Dr. A. Santos Avenue',          'type' => 'street',   'location_id' => 'paranaque'],
        ['name' => 'SM Mall of Asia',               'type' => 'landmark', 'location_id' => 'paranaque'],

        ['name' => 'Baguio City',                   'type' => 'city',     'location_id' => 'baguio'],
        ['name' => 'Session Road',                  'type' => 'street',   'location_id' => 'baguio'],
        ['name' => 'Burnham Park',                  'type' => 'landmark', 'location_id' => 'baguio'],

        ['name' => 'Cebu City',                     'type' => 'city',     'location_id' => 'cebu-city'],
        ['name' => 'Colon Street',                  'type' => 'street',   'location_id' => 'cebu-city'],
        ['name' => 'Fuente Osmeña Circle',          'type' => 'landmark', 'location_id' => 'cebu-city'],
        ['name' => 'Ayala Center Cebu',             'type' => 'landmark', 'location_id' => 'cebu-city'],

        ['name' => 'Davao City',                    'type' => 'city',     'location_id' => 'davao-city'],
        ['name' => 'Roxas Avenue',                   'type' => 'street',   'location_id' => 'davao-city'],
        ['name' => 'People\'s Park',                 'type' => 'landmark', 'location_id' => 'davao-city'],

        ['name' => 'Iloilo City',                    'type' => 'city',     'location_id' => 'iloilo-city'],
        ['name' => 'Iloilo Business Park',           'type' => 'landmark', 'location_id' => 'iloilo-city'],
    ];
}

/**
 * Barangays per city, keyed by the location id from getPhLocations().
 *
 * Same footing as the rest of this file: hand-curated, not a full PSGC dump.
 * Caloocan is complete (its barangays are numbered, so both halves are listed
 * in full); the other cities carry their commonly-addressed barangays. A city
 * that is absent here simply has no dropdown - the checkout form falls back to
 * a free-text barangay box so no address is ever blocked by a gap in this list.
 */
function getPhCuratedBarangays() {
    // Caloocan's barangays are numbered 1-188 and the number IS the name.
    // District 1 (South) runs 1-162, District 2 (North) 163-188.
    $caloocanSouth = [];
    for ($i = 1; $i <= 162; $i++) { $caloocanSouth[] = 'Barangay ' . $i; }
    $caloocanNorth = [];
    for ($i = 163; $i <= 188; $i++) { $caloocanNorth[] = 'Barangay ' . $i; }

    // The named localities people actually write on a parcel. Listed alongside
    // the numbers rather than mapped onto them: several cover more than one
    // numbered barangay, so pinning each to a single number would be wrong.
    $caloocanSouthAreas = ['Bagong Barrio', 'Baesa', 'Grace Park East', 'Grace Park West', 'Maypajo', 'Monumento', 'Sangandaan'];
    $caloocanNorthAreas = ['Amparo', 'Bagong Silang', 'Bagumbong', 'Camarin', 'Deparo', 'Kaybiga', 'Llano', 'Pangarap', 'Tala'];

    return [
        'caloocan-south' => array_merge($caloocanSouth, $caloocanSouthAreas),
        'caloocan-north' => array_merge($caloocanNorth, $caloocanNorthAreas),

        'quezon-city' => [
            'Alicia', 'Amihan', 'Apolonio Samson', 'Baesa', 'Bagbag', 'Bagong Lipunan ng Crame', 'Bagong Pag-asa',
            'Bagong Silangan', 'Bagumbayan', 'Bagumbuhay', 'Bahay Toro', 'Balingasa', 'Batasan Hills', 'Bayanihan',
            'Blue Ridge A', 'Blue Ridge B', 'Botocan', 'Bungad', 'Camp Aguinaldo', 'Central', 'Claro', 'Commonwealth',
            'Culiat', 'Damayan', 'Damayang Lagi', 'Del Monte', 'Diliman', 'Dona Imelda', 'Dona Josefa', 'Duyan-duyan',
            'E. Rodriguez', 'East Kamias', 'Escopa', 'Fairview', 'Gulod', 'Holy Spirit', 'Immaculate Concepcion',
            'Kaligayahan', 'Kalusugan', 'Kamuning', 'Katipunan', 'Kaunlaran', 'Kristong Hari', 'Krus na Ligas',
            'Laging Handa', 'Libis', 'Loyola Heights', 'Maharlika', 'Malaya', 'Mangga', 'Mariana', 'Mariblo',
            'Marilag', 'Masagana', 'Masambong', 'Matandang Balara', 'Milagrosa', 'Nagkaisang Nayon', 'Novaliches Proper',
            'N.S. Amoranto', 'Obrero', 'Old Capitol Site', 'Paang Bundok', 'Pag-ibig sa Nayon', 'Paligsahan', 'Paltok',
            'Pansol', 'Paraiso', 'Pasong Putik Proper', 'Pasong Tamo', 'Payatas', 'Phil-Am', 'Pinagkaisahan',
            'Pinyahan', 'Project 6', 'Quirino 2-A', 'Quirino 2-B', 'Quirino 2-C', 'Quirino 3-A', 'Ramon Magsaysay',
            'Roxas', 'Sacred Heart', 'Salvacion', 'San Antonio', 'San Bartolome', 'San Isidro Labrador', 'San Jose',
            'San Martin de Porres', 'San Roque', 'Sangandaan', 'Santa Cruz', 'Santa Lucia', 'Santa Monica',
            'Santa Teresita', 'Santo Cristo', 'Santo Domingo', 'Santo Nino', 'Santol', 'Sauyo', 'Sienna',
            'Sikatuna Village', 'Silangan', 'Socorro', 'South Triangle', 'Tagumpay', 'Talayan', 'Talipapa',
            'Tandang Sora', 'Tatalon', 'Teachers Village East', 'Teachers Village West', 'Ugong Norte', 'Unang Sigaw',
            'UP Campus', 'UP Village', 'Valencia', 'Vasra', 'Veterans Village', 'Villa Maria Clara', 'West Kamias',
            'West Triangle', 'White Plains',
        ],

        'manila' => [
            'Binondo', 'Ermita', 'Intramuros', 'Malate', 'Paco', 'Pandacan', 'Port Area', 'Quiapo', 'Sampaloc',
            'San Andres', 'San Miguel', 'San Nicolas', 'Santa Ana', 'Santa Cruz', 'Santa Mesa', 'Tondo',
        ],

        'makati' => [
            'Bangkal', 'Bel-Air', 'Carmona', 'Dasmarinas', 'Forbes Park', 'Guadalupe Nuevo', 'Guadalupe Viejo',
            'Kasilawan', 'La Paz', 'Magallanes', 'Olympia', 'Palanan', 'Pinagkaisahan', 'Pio del Pilar', 'Poblacion',
            'San Antonio', 'San Isidro', 'San Lorenzo', 'Santa Cruz', 'Singkamas', 'Tejeros', 'Urdaneta',
            'Valenzuela', 'West Rembo',
        ],

        'pasig' => [
            'Bagong Ilog', 'Bagong Katipunan', 'Bambang', 'Buting', 'Caniogan', 'Dela Paz', 'Kalawaan', 'Kapasigan',
            'Kapitolyo', 'Malinao', 'Manggahan', 'Maybunga', 'Oranbo', 'Palatiw', 'Pinagbuhatan', 'Pineda',
            'Rosario', 'Sagad', 'San Antonio', 'San Joaquin', 'San Jose', 'San Miguel', 'San Nicolas',
            'Santa Cruz', 'Santa Lucia', 'Santa Rosa', 'Santo Tomas', 'Santolan', 'Sumilang', 'Ugong',
        ],

        'taguig' => [
            'Bagumbayan', 'Bambang', 'Calzada', 'Central Bicutan', 'Central Signal Village', 'Fort Bonifacio',
            'Hagonoy', 'Ibayo-Tipas', 'Katuparan', 'Ligid-Tipas', 'Lower Bicutan', 'Maharlika Village', 'Napindan',
            'New Lower Bicutan', 'North Daang Hari', 'North Signal Village', 'Palingon', 'Pinagsama', 'San Miguel',
            'Santa Ana', 'South Daang Hari', 'South Signal Village', 'Tanyag', 'Tuktukan', 'Upper Bicutan',
            'Ususan', 'Wawa', 'Western Bicutan',
        ],

        'paranaque' => [
            'Baclaran', 'BF Homes', 'Don Bosco', 'Don Galo', 'La Huerta', 'Marcelo Green', 'Merville',
            'Moonwalk', 'San Antonio', 'San Dionisio', 'San Isidro', 'San Martin de Porres', 'Santo Nino',
            'Sun Valley', 'Tambo', 'Vitalez',
        ],

        'mandaluyong' => [
            'Addition Hills', 'Bagong Silang', 'Barangka Drive', 'Barangka Ibaba', 'Barangka Ilaya',
            'Barangka Itaas', 'Buayang Bato', 'Burol', 'Daang Bakal', 'Hagdang Bato Itaas', 'Hagdang Bato Libis',
            'Harapin ang Bukas', 'Highway Hills', 'Hulo', 'Mabini-J. Rizal', 'Malamig', 'Mauway', 'Namayan',
            'New Zaniga', 'Old Zaniga', 'Pag-asa', 'Plainview', 'Pleasant Hills', 'Poblacion', 'San Jose',
            'Vergara', 'Wack-Wack Greenhills',
        ],

        'san-juan' => [
            'Addition Hills', 'Balong-Bato', 'Batis', 'Corazon de Jesus', 'Ermitano', 'Greenhills', 'Halo-Halo',
            'Isabelita', 'Kabayanan', 'Little Baguio', 'Maytunas', 'Onse', 'Pasadena', 'Pedro Cruz', 'Progreso',
            'Rivera', 'Salapan', 'San Perfecto', 'Santa Lucia', 'Tibagan', 'West Crame',
        ],

        'las-pinas' => [
            'Almanza Dos', 'Almanza Uno', 'B.F. International Village', 'Daniel Fajardo', 'Elias Aldana',
            'Ilaya', 'Manuyo Dos', 'Manuyo Uno', 'Pamplona Dos', 'Pamplona Tres', 'Pamplona Uno', 'Pilar',
            'Pulang Lupa Dos', 'Pulang Lupa Uno', 'Talon Dos', 'Talon Kuatro', 'Talon Singko', 'Talon Tres',
            'Talon Uno', 'Zapote',
        ],

        'muntinlupa' => [
            'Alabang', 'Bayanan', 'Buli', 'Cupang', 'Poblacion', 'Putatan', 'Sucat', 'Tunasan',
        ],

        'marikina' => [
            'Barangka', 'Calumpang', 'Concepcion Dos', 'Concepcion Uno', 'Fortune', 'Industrial Valley Complex',
            'Jesus de la Pena', 'Malanday', 'Marikina Heights', 'Nangka', 'Parang', 'San Roque', 'Santa Elena',
            'Santo Nino', 'Tanong', 'Tumana',
        ],

        'pasay' => [
            'Baclaran', 'Malibay', 'Maricaban', 'San Isidro', 'San Rafael', 'San Roque', 'Santa Clara',
            'Villamor Air Base',
        ],

        'valenzuela' => [
            'Arkong Bato', 'Bagbaguin', 'Balangkas', 'Bignay', 'Bisig', 'Canumay East', 'Canumay West',
            'Coloong', 'Dalandanan', 'Gen. T. de Leon', 'Isla', 'Karuhatan', 'Lawang Bato', 'Lingunan',
            'Mabolo', 'Malanday', 'Malinta', 'Mapulang Lupa', 'Marulas', 'Maysan', 'Palasan', 'Parada',
            'Pariancillo Villa', 'Paso de Blas', 'Pasolo', 'Poblacion', 'Polo', 'Punturin', 'Rincon',
            'Tagalag', 'Ugong', 'Veinte Reales', 'Wawang Pulo',
        ],
    ];
}

/* ==========================================================================
   PSGC-backed lookups
   --------------------------------------------------------------------------
   The curated arrays above cover ~55 cities, which is why an address search
   for somewhere like Bulacan came back empty. The full official list (1,634
   cities/municipalities, ~42,000 barangays) is far too large for a PHP array,
   so it lives in ph_cities / ph_barangays - see DBSQL/add_ph_psgc_locations.sql
   and execution/import_ph_psgc.php.

   Every function below prefers those tables and falls back to the curated
   arrays when they are missing or empty, so the site still works on a database
   where the import has not been run.
   ========================================================================== */

/**
 * "City of Malolos", "Malolos City" and "Malolos" all normalize to the same
 * key, which is how the importer matches PSGC names against curated postal
 * codes and coordinates.
 */
function phNormalizeCityName($name) {
    $n = mb_strtolower(trim((string)$name));
    $n = str_replace(['ñ', 'Ñ'], 'n', $n);
    $n = preg_replace('/^city of\s+/', '', $n);
    $n = preg_replace('/\s+city$/', '', $n);
    $n = preg_replace('/\s*\(.*?\)\s*/', ' ', $n);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $n));
}

/** A stable id for a region name, used as the region_id in the picker. */
function phRegionSlug($name) {
    $slug = strtolower(trim((string)$name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

/**
 * Cached handle to the PSGC tables. Returns null - and every caller falls back
 * to the curated arrays - when the tables are absent or empty.
 */
function phPsgcAvailable() {
    static $available = null;
    if ($available !== null) { return $available; }
    try {
        $pdo = getConnection();
        $available = (int)$pdo->query('SELECT COUNT(*) FROM ph_cities')->fetchColumn() > 0;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

/**
 * The regions shown in the picker: the 17 real PSGC regions once imported,
 * otherwise the 5 curated groupings.
 */
function getPhRegions() {
    if (!phPsgcAvailable()) { return getPhCuratedRegions(); }
    try {
        $rows = getConnection()
            ->query('SELECT DISTINCT region_name FROM ph_cities ORDER BY region_name')
            ->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($rows as $name) {
            $out[] = ['id' => phRegionSlug($name), 'name' => $name];
        }
        return $out ?: getPhCuratedRegions();
    } catch (Throwable $e) {
        error_log('getPhRegions: ' . $e->getMessage());
        return getPhCuratedRegions();
    }
}

/**
 * Every city/municipality, in the shape the address API and its frontend
 * already expect (id/name/province/region_id/postal/lat/lng).
 *
 * $regionId and $query narrow the read in SQL - callers that only need one
 * region or one search should pass them rather than pulling all 1,634 rows.
 */
function getPhLocations($regionId = null, $query = null, $limit = null) {
    if (!phPsgcAvailable()) {
        $all = getPhCuratedLocations();
        if ($regionId !== null && $regionId !== '') {
            $all = array_values(array_filter($all, fn($l) => $l['region_id'] === $regionId));
        }
        return $all;
    }
    try {
        $sql = 'SELECT code, name, province_name, region_name, postal, lat, lng FROM ph_cities';
        $where = [];
        $params = [];
        if ($regionId !== null && $regionId !== '') {
            // region_id is a slug of the name, so match on the slug rather than
            // storing a second column for it.
            $where[] = "REPLACE(REPLACE(REPLACE(LOWER(region_name), ' ', '-'), '(', ''), ')', '') LIKE ?";
            $params[] = str_replace('-', '%', $regionId) . '%';
        }
        if ($query !== null && $query !== '') {
            $where[] = '(name LIKE ? OR province_name LIKE ? OR region_name LIKE ? OR postal LIKE ?)';
            $like = '%' . $query . '%';
            array_push($params, $like, $like, $like, $query . '%');
        }
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY name';
        if ($limit !== null) { $sql .= ' LIMIT ' . (int)$limit; }

        $st = getConnection()->prepare($sql);
        $st->execute($params);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'        => $r['code'],
                'name'      => $r['name'],
                'province'  => $r['province_name'],
                'region_id' => phRegionSlug($r['region_name']),
                'region'    => $r['region_name'],
                'postal'    => (string)($r['postal'] ?? ''),
                'lat'       => $r['lat'] !== null ? (float)$r['lat'] : null,
                'lng'       => $r['lng'] !== null ? (float)$r['lng'] : null,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getPhLocations: ' . $e->getMessage());
        return getPhCuratedLocations();
    }
}

/**
 * Barangays for one city id, or [] when nothing is known for it.
 */
function getPhBarangaysFor($locationId) {
    if (!phPsgcAvailable()) {
        $all = getPhCuratedBarangays();
        return $all[$locationId] ?? [];
    }
    try {
        $st = getConnection()->prepare('SELECT name FROM ph_barangays WHERE city_code = ? ORDER BY name');
        $st->execute([$locationId]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($rows) { return $rows; }
    } catch (Throwable $e) {
        error_log('getPhBarangaysFor: ' . $e->getMessage());
    }
    $all = getPhCuratedBarangays();
    return $all[$locationId] ?? [];
}
