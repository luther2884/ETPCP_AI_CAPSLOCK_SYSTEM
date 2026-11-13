<?php
/**
 * config/delivery_estimate.php - "when will my order get here" estimates.
 *
 * Deliberately an ESTIMATE, not a promise. There is no courier API behind this
 * store, so the honest thing to show a customer is a dated range with the word
 * "estimated" on it, derived from how far their address is from the branch.
 * Everything here is deterministic: same order in, same dates out, no clock
 * reading and no network call, so the page, the notification and the email all
 * state the same thing.
 *
 * How the distance is found, best source first:
 *   1. orders.delivery_latitude/longitude   - the pin the customer dropped at
 *      checkout. Straight-line km from the branch (STORE_LAT/STORE_LNG in
 *      config/database.php) via haversine.
 *   2. The postal code in orders.delivery_full_address, matched against the
 *      curated city table in config/ph_locations.php -> that city's centroid.
 *   3. A city/province name from that same table appearing in the address text
 *      -> its region band (no distance, wider range).
 *   4. Nothing recognised -> the widest band, which is what an unknown address
 *      deserves.
 *
 * Why straight-line distance is good enough: the bands below are wide (a day
 * or more), and road distance in PH is roughly 1.3-1.6x the straight line, so
 * a band chosen from crow-flight km lands in the same bucket as one chosen
 * from road km for everything except addresses sitting right on a boundary -
 * where being one band out is still inside the range we print.
 *
 * TIMEZONE: every date here is derived from a DB timestamp string
 * (orders.created_at / paid_at), never from PHP's now(). This server's PHP
 * timezone does not match the DB's local time - the same drift already broke
 * the cancellation countdown and the live-tracking "last updated" on
 * order_success.php, and an arrival estimate that is a few hours out lands on
 * the wrong DAY, which is the one thing a customer would notice.
 */

require_once __DIR__ . '/ph_locations.php';

/**
 * Transit bands by straight-line distance from the branch, in working days
 * INCLUDING the day or so we spend preparing the order. Keyed by the upper
 * bound in km; first band whose bound is >= the distance wins.
 *
 * The first band is "1-2" and not "same day": orders are picked and packed at
 * the branch during store hours, so an order placed at 9pm has not started.
 */
function deliveryEstimateDistanceBands(): array {
    return [
        15  => [1, 2],   // the branch's own city and the ones touching it
        40  => [2, 3],   // rest of Metro Manila
        120 => [3, 4],   // Bulacan / Cavite / Laguna / Rizal / Batangas belt
        350 => [4, 6],   // reachable overland in a day: most of Luzon
        700 => [5, 8],   // far Luzon, northern Visayas
        1e9 => [7, 10],  // Visayas / Mindanao, anything that needs a boat
    ];
}

/**
 * Fallback bands when only the region is known. Wider than the distance bands
 * on purpose - a region is hundreds of km across, so the range has to cover
 * both of its ends.
 */
function deliveryEstimateRegionBands(): array {
    return [
        'metro_manila' => [1, 3],
        'north_luzon'  => [3, 5],
        'south_luzon'  => [3, 5],
        'visayas'      => [5, 8],
        'mindanao'     => [7, 10],
    ];
}

/** Straight-line km between two lat/lng pairs. */
function deliveryEstimateHaversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371.0; // mean earth radius, km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Add N working days to a Y-m-d date. Sundays are skipped because the branch
 * is Mon-Sat (the pickup instructions on order_success.php say so), so a
 * Sunday is not a day anything moves.
 */
function deliveryEstimateAddWorkingDays(string $ymd, int $days): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if (!$d) { return $ymd; }
    $added = 0;
    while ($added < $days) {
        $d = $d->modify('+1 day');
        if ((int)$d->format('N') !== 7) { $added++; }
    }
    return $d->format('Y-m-d');
}

/** Working days between two Y-m-d dates, Sundays excluded (as above). */
function deliveryEstimateWorkingDaysBetween(string $fromYmd, string $toYmd): int {
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $fromYmd);
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $toYmd);
    if (!$a || !$b || $a > $b) { return 0; }
    $n = 0;
    while ($a < $b) {
        $a = $a->modify('+1 day');
        if ((int)$a->format('N') !== 7) { $n++; }
    }
    return $n;
}

/**
 * Work out where the order is going.
 *
 * @return array{basis:string,distance_km:?float,region_id:?string,area:?string}
 */
function deliveryEstimateLocateOrder(array $order): array {
    $out = ['basis' => 'default', 'distance_km' => null, 'region_id' => null, 'area' => null];

    $storeLat = defined('STORE_LAT') ? (float)STORE_LAT : 14.6500;
    $storeLng = defined('STORE_LNG') ? (float)STORE_LNG : 120.9880;

    $lat = $order['delivery_latitude']  ?? null;
    $lng = $order['delivery_longitude'] ?? null;
    // Guard against 0/0 as well as NULL: a column default of 0 is the Gulf of
    // Guinea, which would quietly produce a 10-day estimate for a Caloocan
    // address instead of falling through to the postal match below.
    if ($lat !== null && $lng !== null && abs((float)$lat) > 0.01 && abs((float)$lng) > 0.01) {
        $out['basis'] = 'gps';
        $out['distance_km'] = deliveryEstimateHaversineKm($storeLat, $storeLng, (float)$lat, (float)$lng);
    }

    $address = (string)($order['delivery_full_address'] ?? '');
    $locations = function_exists('getPhCuratedLocations') ? getPhCuratedLocations() : [];

    // Postal code: a 4-digit token in the address. Matched before names
    // because "City of Bacoor, Cavite, 4102" gives both, and the postal code
    // is the one a customer cannot mistype into a different province.
    if ($address !== '' && preg_match_all('/\b(\d{4})\b/', $address, $m)) {
        foreach (array_reverse($m[1]) as $code) { // postal sits at the end
            foreach ($locations as $loc) {
                if ((string)($loc['postal'] ?? '') === (string)$code) {
                    $out['region_id'] = $loc['region_id'] ?? null;
                    $out['area'] = trim(($loc['name'] ?? '') . (!empty($loc['province']) && $loc['province'] !== ($loc['name'] ?? '') ? ', ' . $loc['province'] : ''));
                    if ($out['distance_km'] === null && isset($loc['lat'], $loc['lng'])) {
                        $out['basis'] = 'postal';
                        $out['distance_km'] = deliveryEstimateHaversineKm($storeLat, $storeLng, (float)$loc['lat'], (float)$loc['lng']);
                    }
                    break 2;
                }
            }
        }
    }

    // Still nothing? Look for a city or province name in the address text.
    if ($out['region_id'] === null && $address !== '') {
        $haystack = mb_strtolower($address);
        foreach ($locations as $loc) {
            foreach ([$loc['name'] ?? '', $loc['province'] ?? ''] as $needle) {
                if ($needle === '') { continue; }
                if (mb_strpos($haystack, mb_strtolower($needle)) !== false) {
                    $out['region_id'] = $loc['region_id'] ?? null;
                    $out['area'] = $out['area'] ?: $needle;
                    if ($out['distance_km'] === null && isset($loc['lat'], $loc['lng'])) {
                        $out['basis'] = 'region';
                        $out['distance_km'] = deliveryEstimateHaversineKm($storeLat, $storeLng, (float)$loc['lat'], (float)$loc['lng']);
                    }
                    break 2;
                }
            }
        }
    }

    return $out;
}

/**
 * The estimate for one order row (needs: delivery_method, status, created_at,
 * and for deliveries delivery_full_address / delivery_latitude / longitude;
 * paid_at and payment_method are used when present).
 *
 * Returns null when an estimate would be wrong or pointless to show: the order
 * is cancelled, or it is already finished - at which point the real date is on
 * the page and a guess next to it only invites "but you said".
 *
 * @return array{kind:string,min:string,max:string,label:string,short:string,
 *               days_min:int,days_max:int,basis:string,distance_km:?float,
 *               area:?string,note:string,provisional:bool}|null
 */
function deliveryEstimateForOrder(array $order): ?array {
    $status = (string)($order['status'] ?? '');
    if (in_array($status, ['cancelled', 'completed'], true)) { return null; }
    if (!empty($order['delivery_completed_at']) || !empty($order['pickup_verified_at'])) { return null; }

    // Anchor on the DB's own timestamps - see the TIMEZONE note at the top.
    $anchorTs = (string)($order['paid_at'] ?? '') !== '' ? (string)$order['paid_at'] : (string)($order['created_at'] ?? '');
    if ($anchorTs === '') { return null; }
    $anchor = substr($anchorTs, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) { return null; }

    $isPickup = ($order['delivery_method'] ?? '') === 'pickup';

    // An unpaid online order is not being prepared yet, so the range it gets
    // is explicitly provisional: the clock really starts at payment.
    $provisional = $status === 'awaiting_payment'
        || (in_array($order['payment_method'] ?? '', ['gcash', 'paymaya', 'xendit'], true)
            && empty($order['paid_at'])
            && ($order['payment_status'] ?? '') !== 'paid');

    if ($isPickup) {
        // Nothing travels - this is just how long picking and packing takes.
        // Already packed or ready means it is waiting at the counter now.
        [$dMin, $dMax] = in_array($status, ['packed', 'ready'], true) ? [0, 0] : [1, 2];
        $min = deliveryEstimateAddWorkingDays($anchor, $dMin);
        $max = deliveryEstimateAddWorkingDays($anchor, $dMax);
        $note = $dMax === 0
            ? 'Your order is packed and waiting at our Caloocan branch. Collect it Mon&ndash;Sat, 9AM&ndash;5PM.'
            : 'We will text and email you the moment it is ready to collect. Pickup hours are Mon&ndash;Sat, 9AM&ndash;5PM.';
        // Already packed: the useful answer is "now", not the date the order
        // was placed, which is what a 0-day range off the anchor would print.
        $readyNow = $dMax === 0;
        return [
            'kind' => 'pickup',
            'min' => $min, 'max' => $max,
            'label' => $readyNow ? 'Ready now' : deliveryEstimateFormatRange($min, $max, true),
            'short' => $readyNow ? 'Ready now' : deliveryEstimateFormatRange($min, $max, false),
            'days_min' => $dMin, 'days_max' => $dMax,
            'basis' => 'pickup', 'distance_km' => null,
            'area' => 'Caloocan branch',
            'note' => $note,
            'provisional' => $provisional,
            'delayed' => false,
        ];
    }

    $loc = deliveryEstimateLocateOrder($order);

    if ($loc['distance_km'] !== null) {
        $band = [7, 10];
        foreach (deliveryEstimateDistanceBands() as $maxKm => $days) {
            if ($loc['distance_km'] <= $maxKm) { $band = $days; break; }
        }
    } elseif ($loc['region_id'] !== null && isset(deliveryEstimateRegionBands()[$loc['region_id']])) {
        $band = deliveryEstimateRegionBands()[$loc['region_id']];
    } else {
        $band = [3, 7]; // address we could not place at all
    }

    // Already handed to a rider: the preparation part of the band is behind
    // us, so drop a day off each end rather than restating the original range.
    if (in_array($status, ['shipped'], true)) {
        $band = [max(1, $band[0] - 1), max(1, $band[1] - 1)];
    }

    $min = deliveryEstimateAddWorkingDays($anchor, $band[0]);
    $max = deliveryEstimateAddWorkingDays($anchor, $band[1]);

    /* An order that is running late would otherwise print a range that has
       already gone past - "Estimated arrival Oct 2" read on Oct 9 - which is
       worse than no estimate at all. When the caller supplies the DB's own
       CURDATE() as db_today (PHP's today cannot be trusted here, see the
       TIMEZONE note), the window is re-based to start today and keeps its
       original width, and 'delayed' is set so the UI can say so rather than
       pretending this was always the estimate. */
    $delayed = false;
    $today = (string)($order['db_today'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) && $min < $today) {
        $span = deliveryEstimateWorkingDaysBetween($min, $max);
        $min = $today;
        $max = deliveryEstimateAddWorkingDays($today, $span);
        $delayed = true;
    }

    return [
        'kind' => 'delivery',
        'delayed' => $delayed,
        'min' => $min, 'max' => $max,
        'label' => deliveryEstimateFormatRange($min, $max, true),
        'short' => deliveryEstimateFormatRange($min, $max, false),
        'days_min' => $band[0], 'days_max' => $band[1],
        'basis' => $loc['basis'],
        'distance_km' => $loc['distance_km'] !== null ? round($loc['distance_km'], 1) : null,
        'area' => $loc['area'],
        'note' => 'Estimated from the distance between your delivery address and our Caloocan branch. Sundays are not counted.',
        'provisional' => $provisional,
    ];
}

/**
 * "Mon, Oct 12 - Wed, Oct 14", or a single date when both ends are the day.
 * $withWeekday is off for the places that need it compact (notifications).
 */
function deliveryEstimateFormatRange(string $minYmd, string $maxYmd, bool $withWeekday = false): string {
    $fmtFull = $withWeekday ? 'D, M j' : 'M j';
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $minYmd);
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $maxYmd);
    if (!$a || !$b) { return ''; }
    if ($minYmd === $maxYmd) { return $a->format($fmtFull); }
    // Same month: "Oct 12-14" rather than "Oct 12 - Oct 14".
    if ($a->format('Y-m') === $b->format('Y-m')) {
        return $a->format($fmtFull) . '&ndash;' . $b->format('j');
    }
    return $a->format($fmtFull) . ' &ndash; ' . $b->format($fmtFull);
}

/**
 * One plain-text line for notifications and emails, where there is no room
 * for a card and no HTML. Returns '' when there is no estimate to give.
 */
function deliveryEstimateSentence(?array $est): string {
    if (!$est) { return ''; }
    $range = html_entity_decode(strip_tags($est['short']), ENT_QUOTES, 'UTF-8');
    if ($est['kind'] === 'pickup') {
        $s = $est['days_max'] === 0
            ? 'Ready for pickup now at our Caloocan branch.'
            : 'Estimated ready for pickup: ' . $range . '.';
    } else {
        $s = 'Estimated arrival: ' . $range . '.';
    }
    if (!empty($est['provisional'])) {
        $s .= ' This starts once your payment is confirmed.';
    } elseif (!empty($est['delayed'])) {
        // Said plainly rather than quietly re-dated: the customer has already
        // been given an earlier range that has now passed.
        $s = 'Running later than planned. ' . $s;
    }
    return $s;
}
