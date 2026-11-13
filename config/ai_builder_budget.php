<?php
declare(strict_types=1);
/**
 * config/ai_builder_budget.php - what a build actually costs, before anyone
 * is allowed to ask for one.
 *
 * WHY THIS EXISTS
 *
 * The AI Builder had no concept of a floor. aib_performance_band() picks a
 * percentile of whatever the budget can currently afford, so "High-end" at
 * PHP 20,000 quietly means "the dearest parts PHP 20,000 reaches" - not
 * high-end at all. The wizard then produced a confident, badly balanced
 * build and called it high-end. Likewise PHP 5,000 was accepted even though
 * no complete PC exists at that price.
 *
 * This file answers two questions, and it is the ONLY place either is
 * answered, so the wizard, the API and the rule engine can never disagree:
 *
 *   aib_min_build_price()   the cheapest COMPLETE, COMPATIBLE PC we can
 *                           actually assemble from stock right now
 *   aib_min_budget_for()    the cheapest complete build that genuinely meets
 *                           a given use + performance target
 *
 * NOTHING HERE IS HARD-CODED. Every figure is derived from the live
 * products table - in-stock, active rows only, at their real prices. A tier
 * is defined as a percentile of our own catalogue, not a price written into
 * the source, so the numbers move on their own as stock and pricing change.
 *
 * Compatibility is not approximated: it reuses the engine's own
 * aib_cpu_mobo_compatible() / aib_ram_mobo_compatible() and its PSU sizing
 * formula, so "cheapest complete build" means a set that would actually pass
 * aib_validate_component_set().
 */

require_once __DIR__ . '/ai_builder_engine.php';

/**
 * Percentile of our in-stock catalogue that each performance target demands
 * of the component that drives it (the GPU for gaming, the CPU for editing).
 *
 * These are positions in OUR price-sorted stock, not prices. "High-end"
 * means "in the top 15% of what we actually sell", which stays true whether
 * the dearest card we carry costs PHP 40,000 or PHP 90,000.
 *
 * The upper bound is deliberately absent: a target names a floor, and
 * spending more than the floor is never the thing we need to prevent.
 */
const AIB_TARGET_FLOOR_PERCENTILE = [
    'budget'      => 0.00,
    'balanced'    => 0.30,
    'performance' => 0.60,
    'high_end'    => 0.85,
];

/**
 * Which component a use case's performance target is really about.
 *
 * Asking for "high-end office" should not demand a top-15% graphics card -
 * office work is bounded by the CPU and the SSD. Getting this wrong is what
 * produces a GPU-heavy build for someone who only opens spreadsheets.
 */
const AIB_TARGET_DRIVER_ROLE = [
    'gaming'        => 'gpu',
    '3d_rendering'  => 'gpu',
    'video_editing' => 'cpu',
    'streaming'     => 'cpu',
    'photo_editing' => 'cpu',
    'programming'   => 'cpu',
    'office'        => 'cpu',
    'school'        => 'cpu',
    'general'       => 'cpu',
];

/**
 * Plain-language names, defined once so the wizard, the API refusal and the
 * build explanation all call the same thing by the same word. A customer
 * reading "A high_end gaming build starts at..." is reading a database value,
 * not a sentence.
 */
const AIB_TARGET_LABELS = [
    'budget'      => 'budget',
    'balanced'    => 'balanced',
    'performance' => 'performance',
    'high_end'    => 'high-end',
];

const AIB_USE_LABELS = [
    'gaming'        => 'gaming',
    'video_editing' => 'video editing',
    '3d_rendering'  => '3D rendering',
    'streaming'     => 'streaming',
    'photo_editing' => 'photo editing',
    'programming'   => 'programming',
    'office'        => 'office',
    'school'        => 'school',
    'general'       => 'general-purpose',
];

function aib_target_label(string $t): string { return AIB_TARGET_LABELS[$t] ?? $t; }
function aib_use_label(string $u): string { return AIB_USE_LABELS[$u] ?? str_replace('_', ' ', $u); }

/** Peso amounts, rounded to whole pesos - these are "about" figures in
 *  customer-facing sentences, and centavos only make them harder to read. */
function aib_money(float $n): string
{
    return "\u{20B1}" . number_format(round($n), 0);
}

/** Category id for a role, so this file never repeats the engine's constants. */
function aib_role_category(string $role): ?int
{
    switch ($role) {
        case 'cpu':         return AIB_CATEGORY_CPU;
        case 'motherboard': return AIB_CATEGORY_MOTHERBOARD;
        case 'gpu':         return AIB_CATEGORY_GPU;
        case 'ram':         return AIB_CATEGORY_RAM;
        case 'storage':     return AIB_CATEGORY_STORAGE;
        case 'psu':         return AIB_CATEGORY_PSU;
        case 'case':        return aib_case_category_id();
    }
    return null;
}

/** The PC Case category, looked up by name rather than assumed. */
function aib_case_category_id(): int
{
    static $id = null;
    if ($id !== null) { return $id; }
    try {
        $found = aib_category_id_by_name(getConnection(), 'PC Case');
        $id = $found ?: 23;
    } catch (Throwable $e) {
        $id = 23;
    }
    return $id;
}

/**
 * Every in-stock candidate for a role, price ascending, specs parsed.
 * Cached per request - the floor is asked for several times on one page load
 * (once per performance target, once per game group) and the catalogue
 * cannot change mid-request.
 */
function aib_budget_candidates(PDO $pdo, string $role): array
{
    static $cache = [];
    if (isset($cache[$role])) { return $cache[$role]; }
    $cat = aib_role_category($role);
    $cache[$role] = $cat ? aib_get_candidates($pdo, $cat, $role) : [];
    return $cache[$role];
}

/**
 * The PSU this CPU+GPU pair needs, using the engine's own formula so a build
 * costed here and a build assembled there size the supply identically.
 */
function aib_required_watts(array $cpuSpecs, array $gpuSpecs = []): int
{
    $estimatedLoad = ($cpuSpecs['tdp_watts'] ?? 65) + ($gpuSpecs['tdp_watts'] ?? 0) + 100;
    return (int)(ceil($estimatedLoad * 1.3 / 50) * 50);
}

/** Cheapest in-stock PSU rated for $watts, or null if we carry none. */
function aib_cheapest_psu(PDO $pdo, int $watts): ?array
{
    foreach (aib_budget_candidates($pdo, 'psu') as $p) {
        if (($p['specs']['watts'] ?? 0) >= $watts) { return $p; }
    }
    return null;
}

/** Cheapest item in a role, ignoring compatibility (case, storage). */
function aib_cheapest(PDO $pdo, string $role): ?array
{
    $c = aib_budget_candidates($pdo, $role);
    return $c[0] ?? null;
}

/**
 * The cheapest complete, compatible PC we can build from stock.
 *
 * Walks every in-stock motherboard and, for each, takes the cheapest CPU and
 * RAM that genuinely fit it, then the cheapest storage, case, and a PSU
 * actually rated for the result. A graphics card is added ONLY when the
 * chosen CPU has no integrated graphics - which is what makes this a real
 * floor rather than a parts-bin sum: the cheapest CPU in the shop may be the
 * one that forces a GPU purchase, so it is often not part of the cheapest
 * complete build.
 *
 * A board whose socket or memory type matches nothing in stock is skipped
 * rather than guessed at.
 *
 * @param  array|null $mustInclude optional role => product row that the
 *         build has to use (how aib_min_budget_for() prices a tier).
 * @return array{total:float,parts:array<string,array>,ok:bool,reason:?string}
 */
function aib_min_build_price(PDO $pdo, ?array $mustInclude = null): array
{
    static $plainCache = null;
    if ($mustInclude === null && $plainCache !== null) { return $plainCache; }

    $boards  = aib_budget_candidates($pdo, 'motherboard');
    $cpus    = aib_budget_candidates($pdo, 'cpu');
    $rams    = aib_budget_candidates($pdo, 'ram');
    $storage = aib_cheapest($pdo, 'storage');
    $case    = aib_cheapest($pdo, 'case');

    $fail = function (string $why) use ($mustInclude, &$plainCache) {
        $out = ['total' => 0.0, 'parts' => [], 'ok' => false, 'reason' => $why];
        if ($mustInclude === null) { $plainCache = $out; }
        return $out;
    };

    if (!$boards)  { return $fail('No motherboards are in stock.'); }
    if (!$cpus)    { return $fail('No processors are in stock.'); }
    if (!$rams)    { return $fail('No memory is in stock.'); }
    if (!$storage) { return $fail('No storage is in stock.'); }
    if (!$case)    { return $fail('No PC cases are in stock.'); }

    $forcedCpu = $mustInclude['cpu'] ?? null;
    $forcedGpu = $mustInclude['gpu'] ?? null;

    $best = null;

    foreach ($boards as $board) {
        $moboSpecs = $board['specs'] ?? [];

        // Cheapest CPU this board can actually take.
        //
        // PROVEN compatibility (=== true), not merely "not disproven". The
        // engine's checks return null when a product's text does not state a
        // socket or memory type, and the build pipeline treats null as
        // acceptable. That is the right call when assembling - it keeps
        // under-described stock usable - but it is the wrong call here.
        //
        // A floor built on guesses is a floor that is too low, and too low is
        // the one direction that actually hurts: it would let the wizard
        // accept a budget that cannot buy a working PC. Without this, the
        // cheapest "build" came out as an LGA1150 Celeron on a board whose
        // socket could not be read at all - parts that do not fit together.
        $cpu = null;
        if ($forcedCpu) {
            if (aib_cpu_mobo_compatible($forcedCpu['specs'] ?? [], $moboSpecs) !== true) { continue; }
            $cpu = $forcedCpu;
        } else {
            foreach ($cpus as $c) {
                if (aib_cpu_mobo_compatible($c['specs'] ?? [], $moboSpecs) === true) { $cpu = $c; break; }
            }
        }
        if (!$cpu) { continue; }

        // Cheapest memory this board can actually take - proven, same reason.
        $ram = null;
        foreach ($rams as $r) {
            if (aib_ram_mobo_compatible($r['specs'] ?? [], $moboSpecs) === true) { $ram = $r; break; }
        }
        if (!$ram) { continue; }

        // A card is required only when the processor cannot drive a display.
        // has_igpu is null when the catalogue text does not say; treating
        // "unknown" as "needs a card" keeps the floor honest - we would
        // rather quote slightly high than quote a PC that cannot show a
        // picture.
        $gpu = $forcedGpu;
        if (!$gpu && ($cpu['specs']['has_igpu'] ?? null) !== true) {
            $gpu = aib_cheapest($pdo, 'gpu');
            if (!$gpu) { continue; }
        }

        $psu = aib_cheapest_psu($pdo, aib_required_watts($cpu['specs'] ?? [], $gpu['specs'] ?? []));
        if (!$psu) { continue; }

        $parts = ['cpu' => $cpu, 'motherboard' => $board, 'ram' => $ram,
                  'storage' => $storage, 'psu' => $psu, 'case' => $case];
        if ($gpu) { $parts['gpu'] = $gpu; }

        $total = 0.0;
        foreach ($parts as $p) { $total += (float)$p['price']; }

        if ($best === null || $total < $best['total']) {
            $best = ['total' => round($total, 2), 'parts' => $parts, 'ok' => true, 'reason' => null];
        }
    }

    if ($best === null) {
        return $fail('No compatible combination of in-stock parts forms a complete PC right now.');
    }
    if ($mustInclude === null) { $plainCache = $best; }
    return $best;
}

/**
 * The cheapest item in a role that sits at or above a percentile of our own
 * in-stock prices for that role.
 *
 * This is how a performance target becomes a real requirement instead of
 * "whatever the budget reaches". Candidates are already price-ascending, so
 * the percentile is just an index.
 */
function aib_floor_product(PDO $pdo, string $role, float $percentile): ?array
{
    $items = aib_budget_candidates($pdo, $role);
    if (!$items) { return null; }
    if ($percentile <= 0.0) { return $items[0]; }
    $idx = (int)floor($percentile * (count($items) - 1));
    return $items[min($idx, count($items) - 1)] ?? null;
}

/**
 * The realistic minimum budget for a use + performance target.
 *
 * Prices the cheapest complete compatible build that is REQUIRED to contain
 * a driver component at or above the target's percentile. So "high-end
 * gaming" is costed as "the cheapest whole PC we can build around a card in
 * the top 15% of the cards we stock" - a number that comes entirely from the
 * catalogue and moves with it.
 *
 * $gamingFloor optionally raises the GPU floor further (game group +
 * resolution + fps goal) - see config/ai_builder_games.php. Passing a
 * percentile here rather than a price keeps every floor on one scale.
 *
 * @return array{min:float,ok:bool,reason:?string,driver_role:string,parts:array}
 */
function aib_min_budget_for(PDO $pdo, string $use, string $performance, ?float $gamingFloor = null): array
{
    static $memo = [];
    $key = $use . '|' . $performance . '|' . ($gamingFloor === null ? '-' : (string)$gamingFloor);
    if (isset($memo[$key])) { return $memo[$key]; }

    $role = AIB_TARGET_DRIVER_ROLE[$use] ?? 'cpu';
    $pct  = AIB_TARGET_FLOOR_PERCENTILE[$performance] ?? 0.30;

    // A specific game at a specific resolution can demand more card than the
    // bare performance target does. The higher of the two wins; neither is
    // allowed to quietly lower the other.
    if ($gamingFloor !== null && $role === 'gpu') { $pct = max($pct, $gamingFloor); }

    $must = [];
    if ($pct > 0.0) {
        $driver = aib_floor_product($pdo, $role, $pct);
        if ($driver) { $must[$role] = $driver; }
    }

    $build = aib_min_build_price($pdo, $must ?: null);
    $out = [
        'min'         => $build['ok'] ? $build['total'] : 0.0,
        'ok'          => $build['ok'],
        'reason'      => $build['reason'],
        'driver_role' => $role,
        'parts'       => $build['parts'],
    ];
    $memo[$key] = $out;
    return $out;
}

/**
 * Which performance targets the given budget can actually reach for a use.
 *
 * The wizard hides the rest; the API refuses them. Both call this, so a
 * target can never be visible in one and rejected by the other.
 *
 * @return array<string,array{allowed:bool,min:float}>
 */
function aib_target_availability(PDO $pdo, string $use, float $budgetMax, ?float $gamingFloor = null): array
{
    $out = [];
    foreach (array_keys(AIB_TARGET_FLOOR_PERCENTILE) as $target) {
        $f = aib_min_budget_for($pdo, $use, $target, $gamingFloor);
        $out[$target] = [
            'allowed' => $f['ok'] && $budgetMax >= $f['min'],
            'min'     => $f['min'],
        ];
    }
    return $out;
}

/**
 * The cheapest single add-on we stock, for the "I own a TechWorld desktop"
 * path. That budget buys parts to add, not a whole PC, so the whole-build
 * floor would be the wrong test entirely - but it still has to buy
 * something.
 */
function aib_min_addon_price(PDO $pdo): array
{
    static $cached = null;
    if ($cached !== null) { return $cached; }

    $cheapest = null;
    foreach (['gpu', 'ram', 'storage', 'psu'] as $role) {
        $c = aib_cheapest($pdo, $role);
        if ($c && ($cheapest === null || (float)$c['price'] < (float)$cheapest['price'])) {
            $cheapest = $c;
        }
    }
    $cached = $cheapest
        ? ['min' => round((float)$cheapest['price'], 2), 'ok' => true, 'product' => $cheapest, 'reason' => null]
        : ['min' => 0.0, 'ok' => false, 'product' => null, 'reason' => 'No add-on parts are in stock.'];
    return $cached;
}

/**
 * Everything the wizard needs to police itself, in one payload.
 *
 * Embedded into the page at render time and re-derived on the server for
 * every request, so the numbers the browser shows are by construction the
 * numbers the server enforces.
 */
function aib_budget_rules(PDO $pdo): array
{
    $floor = aib_min_build_price($pdo);
    $addon = aib_min_addon_price($pdo);

    $byUse = [];
    foreach (array_keys(AIB_TARGET_DRIVER_ROLE) as $use) {
        foreach (array_keys(AIB_TARGET_FLOOR_PERCENTILE) as $target) {
            $f = aib_min_budget_for($pdo, $use, $target);
            $byUse[$use][$target] = $f['ok'] ? $f['min'] : null;
        }
    }

    return [
        'min_build'   => $floor['ok'] ? $floor['total'] : null,
        'min_build_ok' => $floor['ok'],
        'min_build_reason' => $floor['reason'],
        'min_addon'   => $addon['ok'] ? $addon['min'] : null,
        'by_use'      => $byUse,
        // Handed over so the wizard decides "which use drives the budget"
        // with the engine's own precedence rather than a second copy of the
        // list that could drift out of step with it.
        'use_precedence' => AIB_USE_CASE_PRECEDENCE,
        'target_labels'  => AIB_TARGET_LABELS,
        'use_labels'     => AIB_USE_LABELS,
    ];
}
