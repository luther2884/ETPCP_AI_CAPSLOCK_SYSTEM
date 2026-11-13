<?php
// config/report_ranges.php - shared named-date-range vocabulary for the admin
// report APIs.
//
// WHY THIS IS SHARED
// The Sales Overview, Sales Report and Total Sales cards each offer the admin
// a "Today / This Week / Last Month / ..." picker. When each endpoint resolved
// those names itself, two cards on the same screen could quietly disagree
// about where a week starts. One definition, used by all of them, is the only
// way "This Week" can mean the same thing everywhere on the page.
//
// Everything is anchored on "today", so nothing is hardcoded and the ranges
// roll over at midnight on their own.

if (!function_exists('report_resolve_range')) {
    /**
     * Resolve a named range into a [DateTimeImmutable $start, DateTimeImmutable $end] pair.
     *
     * Unknown names fall through to 'today' rather than throwing - a stale
     * bookmark should show today's figures, not an error page. Bad custom
     * dates DO throw (DateTimeImmutable's own exception), so the caller can
     * answer 422 instead of silently reporting the wrong window.
     *
     * @param string      $range       one of the names below
     * @param string|null $customDate  single date, for 'custom_date'
     * @param string|null $customStart range start, for 'custom_range'
     * @param string|null $customEnd   range end, for 'custom_range'
     * @return DateTimeImmutable[]
     */
    function report_resolve_range(string $range, ?string $customDate = null, ?string $customStart = null, ?string $customEnd = null): array
    {
        $today = new DateTimeImmutable('today');
        switch ($range) {
            case 'yesterday':
                $d = $today->modify('-1 day');
                return [$d, $d];
            case 'this_week': // Monday - Sunday
                $start = $today->modify('monday this week');
                return [$start, $start->modify('+6 days')];
            case 'last_week':
                $start = $today->modify('monday this week')->modify('-7 days');
                return [$start, $start->modify('+6 days')];
            case 'this_month':
                return [$today->modify('first day of this month'), $today->modify('last day of this month')];
            case 'last_month':
                return [$today->modify('first day of last month'), $today->modify('last day of last month')];
            case 'last_6_months':
                return [$today->modify('first day of this month')->modify('-5 months'), $today->modify('last day of this month')];
            case 'this_year':
                return [$today->modify('first day of January this year'), $today->modify('last day of December this year')];
            case 'custom_date':
                $d = $customDate ? new DateTimeImmutable($customDate) : $today;
                return [$d, $d];
            case 'custom_range':
                $start = $customStart ? new DateTimeImmutable($customStart) : $today;
                $end   = $customEnd ? new DateTimeImmutable($customEnd) : $today;
                // Picked backwards - swap rather than return an empty window,
                // which would read as "no sales" instead of "bad input".
                if ($end < $start) {
                    [$start, $end] = [$end, $start];
                }
                return [$start, $end];
            case 'today':
            default:
                return [$today, $today];
        }
    }
}

if (!function_exists('report_range_label')) {
    /** "October 9, 2026" for a single day, "Sep 1, 2026 - Sep 30, 2026" otherwise. */
    function report_range_label(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $start->format('F j, Y');
        }
        return $start->format('M j, Y') . ' - ' . $end->format('M j, Y');
    }
}

if (!function_exists('report_previous_window')) {
    /**
     * The window of the same length immediately before $start, which is what
     * a "vs previous period" delta has to be measured against. A month-long
     * range compares to the month before it, a single day to the day before.
     */
    function report_previous_window(DateTimeImmutable $start, int $dayCount): array
    {
        $prevEnd = $start->modify('-1 day');
        return [$prevEnd->modify('-' . max(0, $dayCount - 1) . ' days'), $prevEnd];
    }
}
