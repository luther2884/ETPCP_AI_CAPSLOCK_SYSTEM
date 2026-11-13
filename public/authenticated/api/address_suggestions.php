<?php
// api/address_suggestions.php - Address/location autocomplete backend for the
// checkout "Change Address" form. Read-only: backs AddressSuggestionService
// (public/shared/assets/js/address-suggestions.js). Data comes from
// config/ph_locations.php - a mock/local PH dataset for now; swap that file's
// functions for a real geocoding provider later without touching this API's
// action contract or the frontend service.
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/ph_locations.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

/**
 * Score how well $needle matches $haystack for suggestion ranking.
 * 3 = haystack starts with needle (prefix match)
 * 2 = needle appears anywhere in haystack (substring match)
 * 1 = fuzzy match (small edit distance on a comparably-sized word)
 * 0 = no match
 */
function phMatchScore($haystack, $needle) {
    $haystack = mb_strtolower(trim((string)$haystack));
    $needle = mb_strtolower(trim((string)$needle));
    if ($needle === '') { return 0; }
    if (strpos($haystack, $needle) === 0) { return 3; }
    if (strpos($haystack, $needle) !== false) { return 2; }

    // Light fuzzy fallback: compare needle against each word in haystack so
    // small typos ("qeuzon") still surface "Quezon City".
    if (function_exists('levenshtein') && mb_strlen($needle) >= 3) {
        foreach (preg_split('/[\s,]+/', $haystack) as $word) {
            if ($word === '') { continue; }
            $maxLen = max(mb_strlen($word), mb_strlen($needle));
            $dist = levenshtein(substr($word, 0, 255), substr($needle, 0, 255));
            if ($maxLen > 0 && $dist <= 2 && ($dist / $maxLen) <= 0.4) {
                return 1;
            }
        }
    }
    return 0;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'regions':
            echo json_encode(['success' => true, 'regions' => getPhRegions()]);
            break;

        case 'locations_by_region': {
            $regionId = trim($_GET['region'] ?? '');
            $all = getPhLocations();
            $matches = array_values(array_filter($all, function ($loc) use ($regionId) {
                return $regionId === '' || $loc['region_id'] === $regionId;
            }));
            usort($matches, fn($a, $b) => strcmp($a['name'], $b['name']));
            echo json_encode(['success' => true, 'locations' => $matches]);
            break;
        }

        case 'barangays': {
            // Barangays for one city. Returns an empty list (not an error) when
            // this dataset has no barangays for that city - the checkout form
            // reads that as "fall back to a free-text barangay box".
            $locationId = trim($_GET['location_id'] ?? '');
            echo json_encode([
                'success' => true,
                'location_id' => $locationId,
                'barangays' => $locationId === '' ? [] : getPhBarangaysFor($locationId),
            ]);
            break;
        }

        case 'search_locations': {
            $q = trim($_GET['q'] ?? '');
            $regions = getPhRegions();
            $regionNameById = [];
            foreach ($regions as $r) { $regionNameById[$r['id']] = $r['name']; }

            $results = [];
            if ($q !== '') {
                foreach (getPhLocations() as $loc) {
                    $regionName = $regionNameById[$loc['region_id']] ?? '';
                    $searchable = $loc['name'] . ', ' . $loc['province'] . ', ' . $regionName . ' ' . $loc['postal'];
                    // Postal code lookups should only prefix-match the code itself,
                    // not fuzzy-match against city names.
                    $postalScore = (ctype_digit($q) && strpos($loc['postal'], $q) === 0) ? 3 : 0;
                    $textScore = phMatchScore($searchable, $q);
                    $score = max($postalScore, $textScore);
                    if ($score > 0) {
                        // Label with the province where there is one - "Baliuag,
                        // Bulacan" locates a municipality far better than
                        // "Baliuag, Central Luzon". NCR has no provinces, so
                        // its cities keep the region.
                        $parent = (!empty($loc['province']) && $loc['province'] !== $regionName)
                            ? $loc['province']
                            : $regionName;
                        $results[] = [
                            'score' => $score,
                            'location' => $loc,
                            'label' => $loc['name'] . ', ' . $parent,
                            'region_name' => $parent,
                        ];
                    }
                }
                usort($results, function ($a, $b) {
                    return $b['score'] <=> $a['score'] ?: strcmp($a['location']['name'], $b['location']['name']);
                });
                $results = array_slice($results, 0, 8);
            }
            echo json_encode(['success' => true, 'results' => $results]);
            break;
        }

        case 'search_addresses': {
            $q = trim($_GET['q'] ?? '');
            $locationId = trim($_GET['location_id'] ?? '');
            $locationsById = [];
            foreach (getPhLocations() as $loc) { $locationsById[$loc['id']] = $loc; }

            $results = [];
            if ($q !== '') {
                foreach (getPhLandmarks() as $landmark) {
                    if ($locationId !== '' && $landmark['location_id'] !== $locationId) { continue; }
                    $loc = $locationsById[$landmark['location_id']] ?? null;
                    $searchable = $landmark['name'] . ' ' . ($loc['name'] ?? '');
                    $score = phMatchScore($searchable, $q);
                    if ($score > 0) {
                        $results[] = [
                            'score' => $score,
                            'name' => $landmark['name'],
                            'type' => $landmark['type'],
                            'city' => $loc['name'] ?? null,
                            'location_id' => $landmark['location_id'],
                        ];
                    }
                }
                usort($results, function ($a, $b) {
                    return $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']);
                });
                $results = array_slice($results, 0, 8);
            }
            echo json_encode(['success' => true, 'results' => $results]);
            break;
        }

        case 'reverse_geocode': {
            $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
            $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
            if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing or invalid coordinates']);
                break;
            }

            $regions = getPhRegions();
            $regionNameById = [];
            foreach ($regions as $r) { $regionNameById[$r['id']] = $r['name']; }

            $best = null;
            $bestDist = null;
            foreach (getPhLocations() as $loc) {
                // PSGC ships no coordinates, so only the curated cities carry
                // them - the rest are skipped rather than being treated as
                // lat/lng 0,0, which sits in the Atlantic and would win every
                // nearest-city comparison.
                if (!isset($loc['lat'], $loc['lng']) || $loc['lat'] === null || $loc['lng'] === null) { continue; }
                // Haversine distance in kilometers.
                $earthRadiusKm = 6371;
                $dLat = deg2rad($loc['lat'] - $lat);
                $dLng = deg2rad($loc['lng'] - $lng);
                $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($loc['lat'])) * sin($dLng / 2) ** 2;
                $dist = $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
                if ($bestDist === null || $dist < $bestDist) {
                    $bestDist = $dist;
                    $best = $loc;
                }
            }

            if ($best === null) {
                echo json_encode(['success' => false, 'message' => 'No nearby location found']);
                break;
            }

            echo json_encode([
                'success' => true,
                'location' => $best,
                'region_name' => $regionNameById[$best['region_id']] ?? '',
                'distance_km' => round($bestDist, 1),
            ]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('address_suggestions.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Suggestion service unavailable']);
}
