<?php
declare(strict_types=1);
/**
 * config/ai_builder_filter.php - which wizard answers are allowed to sit
 * next to each other, and what each combination actually costs.
 *
 * WHY THIS EXISTS
 *
 * config/ai_builder_budget.php already prices a build floor per use +
 * performance target, and the API already refuses a budget below it. Two
 * holes remained, and both let a customer submit a brief that contradicts
 * itself:
 *
 *   1. The "budget" target sits at percentile 0.00, so aib_min_budget_for()
 *      forces no driver component at all. Every use therefore reported the
 *      same floor as a bare office PC - PHP 8,395 - and
 *      "PHP 10,000 + Gaming + Budget" sailed through and came back as an
 *      integrated-graphics machine with no graphics card in it.
 *   2. Nothing filtered the intended-use step. Any use was selectable at
 *      any budget, so the contradiction could be re-introduced from the
 *      other direction.
 *
 * This file closes both by stating what a use NEEDS, as capabilities, and
 * then pricing those capabilities against live stock.
 *
 * WHAT IS CONFIGURED HERE AND WHAT IS NOT
 *
 * AIB_FILTER_USE_REQUIREMENTS below is the one place to adjust the rules.
 * It contains NO prices - only capabilities ("gaming needs a dedicated card
 * with at least 4GB of VRAM"). Every peso figure this file produces is
 * computed from the products table at the moment it is asked for, so a price
 * change in the admin panel moves the limits with no code change. There is
 * no cached table to rebuild and no number written into the source.
 *
 * RELATIONSHIP TO ai_builder_budget.php
 *
 * Additive. Nothing in that file is modified or replaced: its floors still
 * gate "can this budget buy any working PC" and the performance-target
 * refusal it already performs still runs. aibf_min_build_for() is a sibling
 * of aib_min_build_price() rather than an edit to it, because that function
 * is on the hot path of the existing wizard and its behaviour must not
 * change. Both share the SAME primitives - aib_budget_candidates(),
 * aib_cpu_mobo_compatible(), aib_ram_mobo_compatible(), aib_required_watts(),
 * aib_cheapest_psu() - so a build costed here is a build the engine would
 * accept.
 */

require_once __DIR__ . '/ai_builder_budget.php';

/**
 * What each intended use genuinely requires of a build.
 *
 * ---- THE ONE PLACE TO ADJUST THE RULES -------------------------------
 *
 *   gpu          true  = a dedicated graphics card is mandatory, even when
 *                        the processor has integrated graphics.
 *   gpu_vram_gb  minimum VRAM on that card, in GB (0 = no minimum).
 *   ram_gb       minimum installed memory, in GB (0 = no minimum, i.e. the
 *                        cheapest module that fits the board).
 *   storage_gb   minimum drive capacity, in GB (0 = no minimum).
 *
 * Capabilities only. Do not put peso amounts here - they are derived.
 *
 * Office, school and general use deliberately state NO minimum beyond a
 * working PC. The cheapest complete machine we stock IS an office machine,
 * so giving these a floor of their own would create a dead budget band
 * where the wizard accepts the money and then offers no use at all.
 */
const AIB_FILTER_USE_REQUIREMENTS = [
    'gaming'        => ['gpu' => true,  'gpu_vram_gb' => 4, 'ram_gb' => 8,  'storage_gb' => 240],
    '3d_rendering'  => ['gpu' => true,  'gpu_vram_gb' => 6, 'ram_gb' => 16, 'storage_gb' => 480],
    'video_editing' => ['gpu' => true,  'gpu_vram_gb' => 4, 'ram_gb' => 16, 'storage_gb' => 480],
    'streaming'     => ['gpu' => true,  'gpu_vram_gb' => 6, 'ram_gb' => 16, 'storage_gb' => 480],
    'photo_editing' => ['gpu' => false, 'gpu_vram_gb' => 0, 'ram_gb' => 16, 'storage_gb' => 240],
    'programming'   => ['gpu' => false, 'gpu_vram_gb' => 0, 'ram_gb' => 16, 'storage_gb' => 240],
    'office'        => ['gpu' => false, 'gpu_vram_gb' => 0, 'ram_gb' => 0,  'storage_gb' => 0],
    'school'        => ['gpu' => false, 'gpu_vram_gb' => 0, 'ram_gb' => 0,  'storage_gb' => 0],
    'general'       => ['gpu' => false, 'gpu_vram_gb' => 0, 'ram_gb' => 0,  'storage_gb' => 0],
];

/**
 * Short, plain reasons for a locked option - written once so the tile, the
 * native <select>, the "nothing fits" message and the API refusal all say
 * the same thing. %s is the money figure, already formatted.
 */
const AIB_FILTER_NOTES = [
    'need_more'   => 'Needs %s+',
    'use_locked'  => 'Not enough for %s at this budget',
];

/** The requirement set for a use, defaulting to "a working PC" (general). */
function aibf_requirements_for(string $use): array
{
    return AIB_FILTER_USE_REQUIREMENTS[$use]
        ?? AIB_FILTER_USE_REQUIREMENTS['general'];
}

/** Every use the wizard offers, in the engine's own precedence order. */
function aibf_uses(): array
{
    $ordered = array_values(array_filter(
        AIB_USE_CASE_PRECEDENCE,
        fn($u) => isset(AIB_FILTER_USE_REQUIREMENTS[$u])
    ));
    // Anything added to the requirements table but not to the engine's
    // precedence list still gets priced rather than silently ignored.
    foreach (array_keys(AIB_FILTER_USE_REQUIREMENTS) as $u) {
        if (!in_array($u, $ordered, true)) { $ordered[] = $u; }
    }
    return $ordered;
}

/** Every performance target, cheapest-first. */
function aibf_targets(): array
{
    return array_keys(AIB_TARGET_FLOOR_PERCENTILE);
}

/**
 * Cheapest in-stock drive of at least $gb, falling back to the cheapest
 * drive we carry when no minimum is asked for.
 */
function aibf_cheapest_storage(PDO $pdo, float $gb): ?array
{
    $all = aib_budget_candidates($pdo, 'storage');
    if ($gb <= 0) { return $all[0] ?? null; }
    foreach ($all as $s) {
        if ((float)($s['specs']['capacity_gb'] ?? 0) >= $gb) { return $s; }
    }
    return null;   // we stock nothing big enough - the use is unavailable
}

/** In-stock cards with at least $gb of VRAM, price ascending. */
function aibf_gpu_pool(PDO $pdo, float $gb): array
{
    $all = aib_budget_candidates($pdo, 'gpu');
    if ($gb <= 0) { return $all; }
    return array_values(array_filter(
        $all,
        fn($g) => (float)($g['specs']['vram_gb'] ?? 0) >= $gb
    ));
}

/**
 * The cheapest complete, compatible PC that satisfies a requirement set.
 *
 * Same walk as aib_min_build_price() - every in-stock motherboard, the
 * cheapest CPU and RAM that PROVABLY fit it, a PSU actually rated for the
 * result - with the requirement set applied on top:
 *
 *   - RAM below ram_gb is skipped, so "16GB" is a real 16GB module and not
 *     a 4GB stick the build happens to afford.
 *   - A card is bought when the use demands one OR when the chosen CPU
 *     cannot drive a display, and it must clear gpu_vram_gb.
 *   - Storage must clear storage_gb.
 *
 * Compatibility is proven (=== true), never merely "not disproven", for the
 * same reason the original gives: a floor built on guesses is a floor that
 * is too low, and too low is the direction that actually hurts - it would
 * let the wizard accept a budget that cannot buy the thing it promises.
 *
 * @param array $mustInclude optional role => product row the build has to
 *        use (how a performance target is priced).
 * @return array{total:float,parts:array<string,array>,ok:bool,reason:?string}
 */
function aibf_min_build_for(PDO $pdo, array $req, array $mustInclude = []): array
{
    $boards = aib_budget_candidates($pdo, 'motherboard');
    $cpus   = aib_budget_candidates($pdo, 'cpu');
    $rams   = aib_budget_candidates($pdo, 'ram');
    $case   = aib_cheapest($pdo, 'case');

    $needGpu  = !empty($req['gpu']);
    $vramMin  = (float)($req['gpu_vram_gb'] ?? 0);
    $ramMin   = (float)($req['ram_gb'] ?? 0);
    $storeMin = (float)($req['storage_gb'] ?? 0);

    $fail = fn(string $why) => ['total' => 0.0, 'parts' => [], 'ok' => false, 'reason' => $why];

    if (!$boards) { return $fail('No motherboards are in stock.'); }
    if (!$cpus)   { return $fail('No processors are in stock.'); }
    if (!$case)   { return $fail('No PC cases are in stock.'); }

    $storage = aibf_cheapest_storage($pdo, $storeMin);
    if (!$storage) {
        return $fail('We have no drive of at least ' . (int)$storeMin . 'GB in stock.');
    }

    $gpuPool = aibf_gpu_pool($pdo, $vramMin);
    if ($needGpu && !$gpuPool) {
        return $fail('We have no graphics card with at least ' . (int)$vramMin . 'GB of VRAM in stock.');
    }

    $forcedCpu = $mustInclude['cpu'] ?? null;
    $forcedGpu = $mustInclude['gpu'] ?? null;

    $best = null;
    $sawRam = false;   // did ANY board take a module big enough?

    foreach ($boards as $board) {
        $moboSpecs = $board['specs'] ?? [];

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

        $ram = null;
        foreach ($rams as $r) {
            if (aib_ram_mobo_compatible($r['specs'] ?? [], $moboSpecs) !== true) { continue; }
            if ($ramMin > 0 && (float)($r['specs']['capacity_gb'] ?? 0) < $ramMin) { continue; }
            $ram = $r; break;
        }
        if (!$ram) { continue; }
        $sawRam = true;

        // A card is bought when the use demands one, or when the processor
        // cannot show a picture on its own (has_igpu null = unknown, treated
        // as "needs a card" so the floor is never quoted too low).
        $gpu = $forcedGpu;
        if (!$gpu && ($needGpu || ($cpu['specs']['has_igpu'] ?? null) !== true)) {
            // With a VRAM minimum the cheapest QUALIFYING card is used; with
            // none, the cheapest card we carry (an office PC whose CPU has no
            // integrated graphics just needs a display output).
            $gpu = $needGpu ? ($gpuPool[0] ?? null) : aib_cheapest($pdo, 'gpu');
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
        return $fail($ramMin > 0 && !$sawRam
            ? 'No in-stock motherboard takes a memory module of at least ' . (int)$ramMin . 'GB.'
            : 'No compatible combination of in-stock parts meets this requirement right now.');
    }
    return $best;
}

/**
 * The driver component a performance target demands, drawn only from parts
 * the USE can actually use.
 *
 * "High-end gaming" is the top of the cards that qualify for gaming, not the
 * top of every card in the shop - a 2GB display adapter is not in the
 * running for a gaming build at any tier, so it must not be counted when
 * working out where the top 15% starts.
 */
function aibf_driver_floor_product(PDO $pdo, string $use, string $target): ?array
{
    $pct = AIB_TARGET_FLOOR_PERCENTILE[$target] ?? 0.0;
    if ($pct <= 0.0) { return null; }

    $role = AIB_TARGET_DRIVER_ROLE[$use] ?? 'cpu';
    $req  = aibf_requirements_for($use);

    $items = $role === 'gpu'
        ? aibf_gpu_pool($pdo, (float)($req['gpu_vram_gb'] ?? 0))
        : aib_budget_candidates($pdo, $role);

    if (!$items) { return null; }
    $idx = (int)floor($pct * (count($items) - 1));
    return $items[min($idx, count($items) - 1)] ?? null;
}

/**
 * The real minimum budget for one use + performance target.
 *
 * Memoised per request: the matrix is 9 uses x 4 targets and the page asks
 * for all of it, while the catalogue cannot change mid-request.
 *
 * @return array{min:float,ok:bool,reason:?string,parts:array}
 */
function aibf_use_target_floor(PDO $pdo, string $use, string $target): array
{
    static $memo = [];
    $key = $use . '|' . $target;
    if (isset($memo[$key])) { return $memo[$key]; }

    $req    = aibf_requirements_for($use);
    $driver = aibf_driver_floor_product($pdo, $use, $target);
    $must   = [];
    if ($driver) {
        $role = AIB_TARGET_DRIVER_ROLE[$use] ?? 'cpu';
        $must[$role] = $driver;
    }

    $build = aibf_min_build_for($pdo, $req, $must);
    return $memo[$key] = [
        'min'    => $build['ok'] ? $build['total'] : 0.0,
        'ok'     => $build['ok'],
        'reason' => $build['reason'],
        'parts'  => $build['parts'],
    ];
}

/**
 * use => target => minimum budget (null when we cannot build it at all).
 * Entirely derived from current stock and prices.
 */
function aibf_floor_matrix(PDO $pdo): array
{
    static $cached = null;
    if ($cached !== null) { return $cached; }

    $out = [];
    foreach (aibf_uses() as $use) {
        foreach (aibf_targets() as $target) {
            $f = aibf_use_target_floor($pdo, $use, $target);
            $out[$use][$target] = $f['ok'] ? $f['min'] : null;
        }
    }
    return $cached = $out;
}

/**
 * target => cheapest budget at which the target is reachable for ANY use.
 *
 * This is what gates the performance step, which is answered BEFORE the use
 * is known: a target is offered when some use can reach it, and the use step
 * then narrows things down. Being stricter here would lock a target the
 * customer could legitimately have had.
 */
function aibf_target_floors(PDO $pdo): array
{
    static $cached = null;
    if ($cached !== null) { return $cached; }

    $matrix = aibf_floor_matrix($pdo);
    $out = [];
    foreach (aibf_targets() as $target) {
        $min = null;
        foreach ($matrix as $byTarget) {
            $v = $byTarget[$target] ?? null;
            if ($v === null) { continue; }
            if ($min === null || $v < $min) { $min = $v; }
        }
        $out[$target] = $min;
    }
    return $cached = $out;
}

/** Which targets this budget reaches. @return array<string,array{allowed:bool,min:?float}> */
function aibf_target_availability(PDO $pdo, float $budgetMax): array
{
    $out = [];
    foreach (aibf_target_floors($pdo) as $target => $min) {
        $out[$target] = [
            'allowed' => $min !== null && $budgetMax > 0 && $budgetMax >= $min,
            'min'     => $min,
        ];
    }
    return $out;
}

/**
 * Which uses this budget reaches at a given target.
 *
 * A target of null means "not chosen yet", and the cheapest target is used -
 * the most generous honest answer, since the customer can still pick it.
 *
 * @return array<string,array{allowed:bool,min:?float}>
 */
function aibf_use_availability(PDO $pdo, float $budgetMax, ?string $target = null): array
{
    $targets = aibf_targets();
    $t = ($target !== null && in_array($target, $targets, true)) ? $target : $targets[0];
    $matrix = aibf_floor_matrix($pdo);

    $out = [];
    foreach (aibf_uses() as $use) {
        $min = $matrix[$use][$t] ?? null;
        $out[$use] = [
            'allowed' => $min !== null && $budgetMax > 0 && $budgetMax >= $min,
            'min'     => $min,
        ];
    }
    return $out;
}

/**
 * THE SERVER-SIDE CHECK.
 *
 * Disabling a tile in the browser is a courtesy, not a control - devtools
 * re-enables it, and a fetch() skips the page entirely. This is the same
 * test the wizard renders, run again where it cannot be edited, so a
 * contradiction can never reach the AI.
 *
 * Runs only for a from-scratch build: on an owned desktop the budget buys
 * add-ons rather than a whole PC, and the existing add-on floor in
 * api/ai_builder.php is the right test there.
 *
 * @return array{ok:bool,code:?string,message:?string,min_budget:?float,
 *               use:?string,performance:?string}
 */
function aibf_validate_combo(PDO $pdo, float $budgetMax, array $uses, string $target): array
{
    $pass = ['ok' => true, 'code' => null, 'message' => null,
             'min_budget' => null, 'use' => null, 'performance' => null];

    if ($budgetMax <= 0) { return $pass; }   // the budget check owns this case
    if (!in_array($target, aibf_targets(), true)) { return $pass; }

    // 1. Is the performance target reachable at this budget at all?
    $targetFloors = aibf_target_floors($pdo);
    $targetMin = $targetFloors[$target] ?? null;
    if ($targetMin === null) {
        return ['ok' => false, 'code' => 'target_not_buildable',
                'message' => 'We cannot assemble a ' . aib_target_label($target)
                           . ' build from current stock. Please choose another performance target.',
                'min_budget' => null, 'use' => null, 'performance' => $target];
    }
    if ($budgetMax < $targetMin) {
        return ['ok' => false, 'code' => 'target_below_minimum',
                'message' => 'A ' . aib_target_label($target) . ' build starts at about '
                           . aib_money($targetMin) . ' with our current stock. Your budget of '
                           . aib_money($budgetMax) . ' is below that - choose a lower performance '
                           . 'target, or raise the budget to ' . aib_money($targetMin) . '.',
                'min_budget' => $targetMin, 'use' => null, 'performance' => $target];
    }

    // 2. Can this budget genuinely do each use that was asked for, at that
    //    target? Checked for EVERY selected use, not just the most demanding
    //    one: "gaming + office" has to be able to game.
    $matrix = aibf_floor_matrix($pdo);
    foreach ($uses as $use) {
        if (!isset($matrix[$use])) { continue; }   // already whitelisted upstream
        $min = $matrix[$use][$target] ?? null;
        if ($min === null) {
            return ['ok' => false, 'code' => 'use_not_buildable',
                    'message' => 'We cannot currently assemble a ' . aib_use_label($use)
                               . ' build from stock. ' . (aibf_use_target_floor($pdo, $use, $target)['reason'] ?? ''),
                    'min_budget' => null, 'use' => $use, 'performance' => $target];
        }
        if ($budgetMax < $min) {
            return ['ok' => false, 'code' => 'use_below_minimum',
                    'message' => 'A ' . aib_target_label($target) . ' ' . aib_use_label($use)
                               . ' build needs about ' . aib_money($min) . ' with our current stock - '
                               . 'your budget of ' . aib_money($budgetMax) . ' is below that. '
                               . 'Raise the budget to ' . aib_money($min) . ', lower the performance '
                               . 'target, or drop ' . aib_use_label($use) . ' from the intended use.',
                    'min_budget' => $min, 'use' => $use, 'performance' => $target];
        }
    }

    return $pass;
}

/**
 * The hard limits handed to the AI alongside the customer's answers.
 *
 * The prompt is told the ceiling (the budget must not be exceeded) and the
 * floor (what the chosen uses actually require of the parts), so a build
 * that ignores either is a build that contradicts its own brief. Derived
 * server-side from validated input - the browser never supplies these.
 */
function aibf_hard_limits(PDO $pdo, array $parsed): array
{
    $uses   = array_values((array)($parsed['intended_use'] ?? []));
    $target = (string)($parsed['performance'] ?? 'balanced');
    $budget = (float)($parsed['budget_max'] ?? 0);

    $required = [];
    $minTotal = null;
    $matrix = aibf_floor_matrix($pdo);

    foreach ($uses as $use) {
        $req = aibf_requirements_for($use);
        $required[$use] = [
            'dedicated_gpu_required' => !empty($req['gpu']),
            'min_gpu_vram_gb'        => (float)($req['gpu_vram_gb'] ?? 0),
            'min_ram_gb'             => (float)($req['ram_gb'] ?? 0),
            'min_storage_gb'         => (float)($req['storage_gb'] ?? 0),
        ];
        $m = $matrix[$use][$target] ?? null;
        if ($m !== null && ($minTotal === null || $m > $minTotal)) { $minTotal = $m; }
    }

    return [
        'max_total_price'      => $budget,
        'must_not_exceed_budget' => true,
        'performance_target'   => $target,
        'intended_use'         => $uses,
        'per_use_requirements' => $required,
        'verified_minimum_total' => $minTotal,
        'note' => 'max_total_price is a hard ceiling: the sum of every component price in a build must be <= max_total_price. per_use_requirements is a hard floor: each listed use must be genuinely served (a use marked dedicated_gpu_required must include a graphics card meeting min_gpu_vram_gb, and the build must meet min_ram_gb and min_storage_gb). These were verified against live stock before this request.',
    ];
}

/**
 * Everything the wizard needs to filter its own options, in one payload.
 *
 * Embedded into the page at render time; the server re-derives the same
 * figures on every request, so editing this object in devtools changes what
 * is DRAWN and nothing about what is ACCEPTED.
 */
function aibf_rules_payload(PDO $pdo): array
{
    return [
        'matrix'        => aibf_floor_matrix($pdo),
        'target_floors' => aibf_target_floors($pdo),
        'targets'       => aibf_targets(),
        'uses'          => aibf_uses(),
        'use_labels'    => AIB_USE_LABELS,
        'target_labels' => AIB_TARGET_LABELS,
        'notes'         => AIB_FILTER_NOTES,
        'requirements'  => AIB_FILTER_USE_REQUIREMENTS,
    ];
}
