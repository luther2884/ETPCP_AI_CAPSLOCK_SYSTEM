<?php
declare(strict_types=1);
/**
 * config/audit_language.php - turns a row of `audit_logs` into something a
 * manager can read.
 *
 * The audit table stores what the code knew at the time: an action slug
 * ('order_status_scan'), a table name ('orders'), and two loose JSON blobs.
 * That is the right thing to STORE - it is precise and never has to be
 * migrated when wording changes - but it is not something to show a person
 * who does not know the schema.
 *
 * Everything user-facing therefore lives here, in one place, as data:
 *
 *   AUDIT_ACTIONS  action (+ table) -> area, icon, and a plain sentence
 *   AUDIT_FIELDS   JSON key         -> a field name a person would use
 *   AUDIT_VALUES   raw value        -> a readable value
 *
 * Nothing in here touches the database or the session; audit_describe() is a
 * pure function of the row it is given plus a lookup table the caller
 * supplies. That keeps the wording cheap to change later, and lets the
 * listing page resolve every id it needs in a few batched queries instead of
 * one query per row.
 *
 * Unknown slugs are never shown raw - audit_fallback_phrase() turns
 * 'some_new_action' into 'Some new action', so a feature added later degrades
 * to slightly generic English rather than leaking a code name onto the screen.
 */

// ---------------------------------------------------------------------------
// Actions.
//
// Keyed by "action|table_name" where the same action means different things
// per table ('create|products' vs 'create|categories'), and by plain "action"
// otherwise. audit_action_meta() tries the specific key first.
//
//   area     groups the row in the filter bar, named as the business names it.
//   icon     Flaticon class, matching the icon set the admin panel already uses.
//   phrase   past tense, no subject - the actor's name is prepended by the page
//            ("Miguelito Pawig changed a product's price").
//   subject  which kind of record the row points at, for the "What it affected"
//            column. Null means the action does not refer to a single record.
// ---------------------------------------------------------------------------
const AUDIT_ACTIONS = [
    // --- Orders -----------------------------------------------------------
    'order_created'               => ['Orders', 'fi fi-rr-shopping-cart', 'placed a new order',              'order'],
    'order_created_race_safe'     => ['Orders', 'fi fi-rr-shopping-cart', 'placed a new order',              'order'],
    'order_status_update'         => ['Orders', 'fi fi-rr-refresh',       'updated an order',                'order'],
    'order_status_manual'         => ['Orders', 'fi fi-rr-refresh',       'changed an order status by hand', 'order'],
    'order_status_scan'           => ['Orders', 'fi fi-rr-qr-scan',       'updated an order by scanning it', 'order'],
    'order_rider_update'          => ['Orders', 'fi fi-rr-truck-side',    'assigned a rider to an order',    'order'],
    'order_completed'             => ['Orders', 'fi fi-rr-check',         'completed an order',              'order'],
    'pickup_verified'             => ['Orders', 'fi fi-rr-shop',          'released a store pickup',         'order'],
    'order_cancellation_approved' => ['Orders', 'fi fi-rr-cross-circle',  'approved an order cancellation',  'order'],
    'order_auto_cancelled_unpaid' => ['Orders', 'fi fi-rr-time-past',     'cancelled an unpaid order automatically', 'order'],
    'cancellation_refund_sent'    => ['Orders', 'fi fi-rr-refund',        'sent a refund',                   'order'],

    // --- Payments ---------------------------------------------------------
    'payment_collected'           => ['Payments', 'fi fi-rr-money-bill-wave', 'collected payment',           'order'],
    'epayment_verified'           => ['Payments', 'fi fi-rr-credit-card',     'verified an online payment',  'payment'],

    // --- Deliveries -------------------------------------------------------
    'delivery_tracking_started'   => ['Deliveries', 'fi fi-rr-marker',     'started delivery tracking',      'order'],
    'delivery_claimed'            => ['Deliveries', 'fi fi-rr-hand',       'claimed a delivery',             'order'],
    'delivery_auto_assigned'      => ['Deliveries', 'fi fi-rr-route',      'was auto-assigned a delivery',   'order'],
    'delivery_proof_uploaded'     => ['Deliveries', 'fi fi-rr-camera',     'uploaded delivery proof photos', 'order'],
    'driver_on_duty'              => ['Deliveries', 'fi fi-rr-user-check', 'went on duty',                   'staff'],
    'driver_off_duty'             => ['Deliveries', 'fi fi-rr-user-time',  'went off duty',                  'staff'],

    // --- Products and catalogue -------------------------------------------
    'create|products'             => ['Products', 'fi fi-rr-box',     'added a new product',       'product'],
    'update|products'             => ['Products', 'fi fi-rr-edit',    'updated a product',         'product'],
    'update_price|products'       => ['Products', 'fi fi-rr-tags',    "changed a product's price", 'product'],
    'update_stock|products'       => ['Products', 'fi fi-rr-boxes',   "changed a product's stock", 'product'],
    'update_image|products'       => ['Products', 'fi fi-rr-picture', "changed a product's photo", 'product'],
    'create|categories'           => ['Products', 'fi fi-bs-archive', 'added a category',          'category'],
    'update|categories'           => ['Products', 'fi fi-bs-archive', 'updated a category',        'category'],
    'create|tags'                 => ['Products', 'fi fi-rr-tags',    'added a tag',               'tag'],
    'update|tags'                 => ['Products', 'fi fi-rr-tags',    'updated a tag',             'tag'],

    // --- Inventory --------------------------------------------------------
    'update|inventory_settings'   => ['Inventory', 'fi fi-rr-settings', 'updated the inventory settings', null],
    'restock|products'            => ['Inventory', 'fi fi-rr-boxes',    'restocked a product',            'product'],

    // --- Customers and feedback -------------------------------------------
    'review_submitted'            => ['Feedback',  'fi fi-rr-star',        'submitted a review',            'review'],
    'review_reply_saved'          => ['Feedback',  'fi fi-rr-comment-alt', 'replied to a customer review',  'review'],
    'review_image_added'          => ['Feedback',  'fi fi-rr-picture',     'added a photo to a review',     'review'],
    'update_profile|users'        => ['Accounts',  'fi fi-rr-user',        'updated account details',       'person'],
    'wishlist_added'              => ['Customers', 'fi fi-rr-heart',       'added an item to a wishlist',   'product'],
    'wishlist_removed'            => ['Customers', 'fi fi-rr-heart',       'removed an item from a wishlist','product'],
    'ai_builder_save_build'       => ['Customers', 'fi fi-rr-computer',    'saved a PC build',              null],

    // --- Marketing --------------------------------------------------------
    'create|promotional_images'   => ['Marketing', 'fi fi-rr-picture', 'added a promo banner',   'banner'],
    'update|promotional_images'   => ['Marketing', 'fi fi-rr-picture', 'updated a promo banner', 'banner'],

    // --- Accounts and access ----------------------------------------------
    'admin_request_created'       => ['Accounts', 'fi fi-rr-user-add',   'requested an admin account',        'person'],
    'admin_request_approved'      => ['Accounts', 'fi fi-rr-user-check', 'approved an admin account request', 'person'],
    'admin_request_denied'        => ['Accounts', 'fi fi-rr-user-time',  'denied an admin account request',   'person'],
    'login'                       => ['Accounts', 'fi fi-rr-sign-in',    'signed in',                         null],
    'logout'                      => ['Accounts', 'fi fi-rr-sign-out',   'signed out',                        null],
    // Written by api/change_password.php. The failures matter as much as the
    // success here - a run of them on one account is what a brute-force
    // attempt against a hijacked session looks like from the trail.
    'password_changed'            => ['Accounts', 'fi fi-rr-key',        'changed their password',            'person'],
    'password_change_failed'      => ['Accounts', 'fi fi-rr-key',        'entered the wrong current password', 'person'],
    'password_change_throttled'   => ['Accounts', 'fi fi-rr-lock',       'was locked out of changing their password', 'person'],

    // --- Background jobs (hidden from the default view) --------------------
    'cleanup_expired_locks'       => ['System', 'fi fi-rr-time-past', 'released expired stock reservations', null],
];

/** Actions performed by the software on a schedule, not by a person. Hidden
 *  unless the reader asks for them: 757 of the 1,148 rows in the current
 *  database are lock cleanups, and they bury everything a person did. */
const AUDIT_SYSTEM_ACTIONS = ['cleanup_expired_locks'];

// ---------------------------------------------------------------------------
// Field names inside old_values / new_values.
//
// Anything not listed falls back to audit_fallback_phrase(), so a key added
// later shows as "Delivery window" rather than "delivery_window". Keys mapped
// to null are deliberately hidden: internals that mean nothing to a reader.
// ---------------------------------------------------------------------------
const AUDIT_FIELDS = [
    'status'             => 'Status',
    'order_status'       => 'Status',
    'payment_status'     => 'Payment status',
    'tracking_number'    => 'Tracking number',
    'assigned_driver_id' => 'Rider',
    'driver_id'          => 'Rider',
    'pickup_verified_by' => 'Released by',
    'product_id'         => 'Product',
    'rating'             => 'Rating',
    'is_verified_purchase' => 'Verified purchase',
    'has_image'          => 'Includes a photo',
    'takeover'           => 'Taken over from another rider',
    'order_total'        => 'Order total',
    'total'              => 'Order total',
    'amount_tendered'    => 'Cash received',
    'amount_paid'        => 'Amount paid',
    'amount_due'         => 'Amount due',
    'change_given'       => 'Change given',
    'change'             => 'Change given',
    'discount_type'      => 'Discount type',
    'discount_amount'    => 'Discount',
    'payment_method'     => 'Payment method',
    'reason'             => 'Reason',
    'admin_notes'        => 'Notes',
    'name'               => 'Name',
    'title'              => 'Title',
    'description'        => 'Description',
    'price'              => 'Price',
    'stock'              => 'Stock',
    'quantity'           => 'Quantity',
    'is_archived'        => 'Archived',
    'is_active'          => 'Active',
    'firstname'          => 'First name',
    'lastname'           => 'Last name',
    'email'              => 'Email address',
    'phone'              => 'Phone number',
    'address'            => 'Address',
    'color'              => 'Colour',
    'tag_ids'            => 'Tags',
    'photo_count'        => 'Photos uploaded',
    'added'              => 'Units added',
    'source'             => 'Recorded from',
    'forecast'           => 'Forecast settings',

    // Hidden - either an internal with no meaning to a reader, or something
    // the row already says somewhere more prominent.
    'reply_length'       => null,  // a character count
    'user_id'            => null,  // duplicates the actor column
    'invoice_no'         => null,  // already shown as the affected record
    'order_id'           => null,  // ditto
    'driver_name'        => null,  // driver_id above resolves to the same name
];

/** Fields whose values are peso amounts. */
const AUDIT_MONEY_FIELDS = [
    'order_total', 'total', 'amount_tendered', 'amount_paid', 'amount_due',
    'change_given', 'change', 'discount_amount', 'price',
];

/** Fields holding a users.id, resolved to a name via the caller's lookups. */
const AUDIT_PERSON_FIELDS = ['assigned_driver_id', 'driver_id', 'pickup_verified_by'];

/** Raw stored values that have a friendlier spelling. */
const AUDIT_VALUES = [
    'pending'          => 'Pending',
    'preparing'        => 'Preparing',
    'packed'           => 'Packed',
    'ready_for_pickup' => 'Ready for pickup',
    'out_for_delivery' => 'Out for delivery',
    'in_transit'       => 'In transit',
    'delivered'        => 'Delivered',
    'completed'        => 'Completed',
    'cancelled'        => 'Cancelled',
    'canceled'         => 'Cancelled',
    'refunded'         => 'Refunded',
    'failed'           => 'Failed',
    'paid'             => 'Paid',
    'unpaid'           => 'Unpaid',
    'scan'             => 'the scanner',
    'manual'           => 'entered by hand',
    'none'             => 'None',
    'cod'              => 'Cash on delivery',
    'gcash'            => 'GCash',
    'xendit'           => 'Online payment',
    'unpaid_1_hour'    => 'unpaid for over an hour',
];

/** Roles, as a non-technical reader would name them. */
const AUDIT_ROLES = [
    'super-admin' => 'Owner',
    'superadmin'  => 'Owner',
    'owner'       => 'Owner',
    'admin'       => 'Manager',
    'manager'     => 'Manager',
    'driver'      => 'Rider',
    'courier'     => 'Rider',
    'customer'    => 'Customer',
];

/** 'some_new_action' -> 'Some new action'. The catch-all that keeps code names
 *  off the screen when a feature is added without updating this file. */
function audit_fallback_phrase(string $slug): string
{
    $words = trim(str_replace(['_', '-'], ' ', $slug));
    return $words === '' ? 'Did something' : ucfirst($words);
}

/** Look up an action, most specific key first. */
function audit_action_meta(string $action, ?string $table): array
{
    $key = $action . '|' . (string)$table;
    if (isset(AUDIT_ACTIONS[$key]))    { return AUDIT_ACTIONS[$key]; }
    if (isset(AUDIT_ACTIONS[$action])) { return AUDIT_ACTIONS[$action]; }
    // Unknown: describe it generically, but still place it somewhere sensible.
    return ['Other', 'fi fi-rr-document', strtolower(audit_fallback_phrase($action)), null];
}

function audit_role_label(?string $role): string
{
    if ($role === null || $role === '') { return 'System'; }
    return AUDIT_ROLES[$role] ?? ucfirst(str_replace('-', ' ', $role));
}

function audit_field_label(string $key): ?string
{
    if (array_key_exists($key, AUDIT_FIELDS)) { return AUDIT_FIELDS[$key]; }
    return audit_fallback_phrase($key);
}

/**
 * Render one stored value as text.
 *
 * $lookups['people'] maps a user id to a name, so "Rider: 4" reads
 * "Rider: Rhuel Cerda". An id the caller did not resolve degrades to "#4"
 * rather than a bare number that could be misread as a quantity.
 */
function audit_format_value(string $key, $value, array $lookups = []): string
{
    if ($value === null || $value === '') { return 'not set'; }
    if (is_bool($value))                  { return $value ? 'Yes' : 'No'; }
    if (is_array($value)) {
        if ($value === []) { return 'none'; }
        $parts = array_map(static fn($v) => audit_format_value($key, $v, $lookups), $value);
        return implode(', ', $parts);
    }

    if (in_array($key, AUDIT_PERSON_FIELDS, true)) {
        $id = (int)$value;
        return $lookups['people'][$id] ?? ('#' . $id);
    }
    if (in_array($key, AUDIT_MONEY_FIELDS, true) && is_numeric($value)) {
        // Double-quoted: \u{} is only an escape inside double quotes.
        return "\u{20B1}" . number_format((float)$value, 2);
    }

    $str = trim((string)$value);
    // Stored booleans arrive as 0/1 or "true"/"false" depending on the caller.
    if ($str === 'true' || $str === 'false') { return $str === 'true' ? 'Yes' : 'No'; }
    $lower = strtolower($str);
    if (isset(AUDIT_VALUES[$lower])) { return AUDIT_VALUES[$lower]; }

    return $str;
}

/** Decode a JSON column into an array, tolerating nulls and malformed JSON. */
function audit_decode($json): array
{
    if ($json === null || $json === '') { return []; }
    if (is_array($json)) { return $json; }
    $out = json_decode((string)$json, true);
    return is_array($out) ? $out : [];
}

/**
 * Work out what actually changed.
 *
 * Callers are inconsistent about what they put in the two blobs - some send
 * only the new value, some send a full before-and-after snapshot in which most
 * fields are identical (update_profile sends the whole user record either
 * way). Listing every key would bury the one thing that moved, so:
 *
 *   - a key in BOTH blobs with different values -> a real before/after
 *   - a key in BOTH with the SAME value         -> dropped, nothing changed
 *   - a key in the new blob only                -> shown as a value that was set
 *   - the literal "(unchanged)" some callers use -> dropped; it is a marker
 *     meaning "this field was not touched", not a value
 *
 * A key present ONLY in old_values is a snapshot of prior state with no
 * counterpart - it says nothing about what changed, so it is skipped too.
 */
function audit_changes(array $old, array $new, array $lookups = []): array
{
    $changes = [];
    foreach (array_keys($new + $old) as $key) {
        $label = audit_field_label((string)$key);
        if ($label === null) { continue; } // deliberately hidden internal

        $hasOld = array_key_exists($key, $old);
        $hasNew = array_key_exists($key, $new);
        $rawOld = $hasOld ? $old[$key] : null;
        $rawNew = $hasNew ? $new[$key] : null;

        // Explicit "not touched" marker used by the order/rider update callers.
        if (is_string($rawNew) && strtolower(trim($rawNew)) === '(unchanged)') { continue; }

        if ($hasOld && $hasNew) {
            $from = audit_format_value((string)$key, $rawOld, $lookups);
            $to   = audit_format_value((string)$key, $rawNew, $lookups);
            if ($from === $to) { continue; } // identical before and after
            $changes[] = ['field' => $label, 'from' => $from, 'to' => $to];
        } elseif ($hasNew) {
            $changes[] = ['field' => $label, 'from' => null, 'to' => audit_format_value((string)$key, $rawNew, $lookups)];
        }
    }
    return $changes;
}

/**
 * Name the record an entry affected, in the reader's terms.
 *
 * Prefers a human identifier carried in the payload (an invoice number) over
 * the database id, falling back to "Order #93" only when there is nothing
 * better. $lookups supplies names the caller has already batched.
 */
function audit_subject(string $kind, $recordId, array $old, array $new, array $lookups = []): ?string
{
    $id = $recordId !== null ? (int)$recordId : null;
    $invoice = $new['invoice_no'] ?? $old['invoice_no'] ?? null;

    switch ($kind) {
        case 'order':
            if ($invoice) { return 'Order ' . $invoice; }
            if ($id && isset($lookups['orders'][$id])) { return 'Order ' . $lookups['orders'][$id]; }
            return $id ? ('Order #' . $id) : null;
        case 'product':
            if ($id && isset($lookups['products'][$id])) { return $lookups['products'][$id]; }
            $name = $new['name'] ?? $old['name'] ?? null;
            return $name ? (string)$name : ($id ? ('Product #' . $id) : null);
        case 'person':
            if ($id && isset($lookups['people'][$id])) { return $lookups['people'][$id]; }
            $who = $new['email'] ?? $old['email'] ?? null;
            return $who ? (string)$who : ($id ? ('Account #' . $id) : null);
        case 'category': return $new['name']  ?? $old['name']  ?? ($id ? 'Category #' . $id : null);
        case 'tag':      return $new['name']  ?? $old['name']  ?? ($id ? 'Tag #' . $id : null);
        case 'banner':   return $new['title'] ?? $old['title'] ?? ($id ? 'Banner #' . $id : null);
        case 'review':   return $id ? ('Review #' . $id) : null;
        case 'payment':  return $id ? ('Payment #' . $id) : null;
        case 'staff':    return ($id && isset($lookups['people'][$id])) ? $lookups['people'][$id] : null;
    }
    return null;
}

/**
 * "updated an order" -> "update an order", so it can follow "tried to".
 *
 * Only the leading verb needs undoing, and the vocabulary above is small and
 * fixed, so a short irregular list plus the -ed rule covers all of it.
 */
function audit_present_tense(string $phrase): string
{
    $parts = explode(' ', $phrase, 2);
    $verb  = $parts[0];
    $rest  = $parts[1] ?? '';
    $irregular = [
        'placed'    => 'place',    'sent'      => 'send',    'went'     => 'go',
        'was'       => 'be',       'set'       => 'set',     'released' => 'release',
        'saved'     => 'save',     'claimed'   => 'claim',   'changed'  => 'change',
        'added'     => 'add',      'removed'   => 'remove',  'approved' => 'approve',
        'denied'    => 'deny',     'updated'   => 'update',  'completed'=> 'complete',
        'collected' => 'collect',  'verified'  => 'verify',  'started'  => 'start',
        'uploaded'  => 'upload',   'submitted' => 'submit',  'replied'  => 'reply',
        'assigned'  => 'assign',   'cancelled' => 'cancel',  'signed'   => 'sign',
    ];
    $base = $irregular[strtolower($verb)] ?? preg_replace('/ed$/', '', $verb);
    return trim($base . ' ' . $rest);
}

/**
 * The whole translation, for one row.
 *
 * $row is an audit_logs record plus the actor columns the listing query joins
 * in; $lookups is the batched id->name maps. Returns everything the template
 * needs, and no raw slugs.
 */
function audit_describe(array $row, array $lookups = []): array
{
    $action = (string)($row['action'] ?? '');
    $table  = isset($row['table_name']) && $row['table_name'] !== null ? (string)$row['table_name'] : null;
    [$area, $icon, $phrase, $subjectKind] = audit_action_meta($action, $table);

    $old = audit_decode($row['old_values'] ?? null);
    $new = audit_decode($row['new_values'] ?? null);

    // Who did it. A null user_id is the scheduler; a name that no longer
    // resolves is an account that has since been deleted (the FK is ON DELETE
    // SET NULL, so the trail outlives the account - which is the point).
    $actorName = trim((string)($row['actor_name'] ?? ''));
    $isSystem  = !isset($row['user_id']) || $row['user_id'] === null;
    if ($actorName === '') { $actorName = $isSystem ? 'System' : 'A deleted account'; }

    // actor_role is the snapshot taken when the row was written. Rows older
    // than that column fall back to the account's current role, which is the
    // best available answer rather than a guess.
    $realRole = $row['actor_role'] ?? null;
    if ($realRole === null || $realRole === '') { $realRole = $row['actor_current_role'] ?? null; }

    $actingAs = $row['acting_as'] ?? null;
    if ($actingAs === '') { $actingAs = null; }

    $failed = ((string)($row['status'] ?? 'success')) === 'failed';

    $subject = $subjectKind ? audit_subject($subjectKind, $row['record_id'] ?? null, $old, $new, $lookups) : null;

    return [
        'area'       => $area,
        'icon'       => $icon,
        'is_system'  => $isSystem,
        'actor_name' => $actorName,
        'actor_role' => audit_role_label($realRole !== null ? (string)$realRole : null),
        // Set only when the person was working through a panel that is not
        // their own - an Owner processing orders from the Manager side.
        'acting_as'  => $actingAs !== null ? audit_role_label((string)$actingAs) : null,
        // "tried to" makes an unsuccessful attempt read as an attempt, so a
        // failed row can never be mistaken for a change that took effect.
        'phrase'     => $failed ? ('tried to ' . audit_present_tense($phrase)) : $phrase,
        'failed'     => $failed,
        // A subject identical to the actor adds nothing - "Nino Go went on
        // duty - Nino Go". Those rows point at the person's own record, so the
        // sentence already names them.
        'subject'    => $subject === $actorName ? null : $subject,
        'changes'    => audit_changes($old, $new, $lookups),
        'detail'     => $row['detail'] ?? null,
    ];
}
