<?php
/**
 * config/business_info.php - the business's own details, in one place.
 *
 * There is no settings table in this schema that carries company details
 * (email_settings is SMTP credentials; support_settings is help-desk config),
 * so this array is the single source. Printed documents read from here -
 * nothing is written into the templates themselves, so changing the address
 * or phone number is a one-file edit and every document follows.
 *
 * STORE_ADDRESS_LABEL already exists in config/database.php for the delivery
 * map; it is reused below rather than duplicated.
 *
 * Beyond the contact block this also carries what a customer-facing invoice
 * needs and a report does not: the full trading name, the tax status, and the
 * terms / warranty / returns boilerplate. Those are lists of strings; the
 * invoice prints each in order and skips the list entirely when it is empty,
 * so wording is edited here and nowhere else.
 */

if (!function_exists('business_info')) {
    /**
     * @return array<string,string>
     */
    function business_info(): array
    {
        return [
            // Short brand mark, used where a masthead wants one word - the
            // sales and inventory report headings.
            'name'     => 'TECHWRLD',
            // Full trading name, for documents that go to a customer and have
            // to name the seller properly. An invoice is one of those.
            'legal_name' => 'TechWorld Computer Store',
            'owner'    => 'Authorised Representative',
            // These were techwrld.local placeholders. They are the details the
            // pickup invoice has been printing all along, lifted out of that
            // template so there is one copy rather than two that can drift.
            'email'    => 'techworldcomputerstore@gmail.com',
            'phone'    => '0995 586 7973',
            'website'  => 'www.techwrld.local',
            'address'  => 'Unit 48C, 5th Street, Brgy. 104, East Grace Park, Caloocan, Philippines',
            // Short form, for a masthead that has one line to spend. The
            // delivery map's STORE_ADDRESS_LABEL is reused rather than
            // duplicated.
            'address_short' => defined('STORE_ADDRESS_LABEL')
                ? STORE_ADDRESS_LABEL . ', Metro Manila, Philippines'
                : 'Metro Manila, Philippines',
            // Relative to the web root; the template falls back to the
            // business name in bold if the file is missing.
            'logo'     => 'public/shared/img/logo.svg',

            /* ---- tax -------------------------------------------------------
               12% VAT, added ON TOP of the item subtotal (VAT-exclusive).

               This is not a guess and not a default: every order in the
               database with line items is priced at exactly subtotal x 1.12 -
               72 of them, pickup and delivery alike, with no exceptions - so
               the checkout is already charging 12% and the invoice was simply
               failing to name it. Before this it printed PHP550.00 of goods,
               no charges, PHP616.00 due.

               The invoice does NOT multiply by this rate to produce the
               figure. It derives the charge from (total - subtotal) so the
               column always reconciles, and uses this rate only to decide
               whether that gap may be called VAT - see tw_invoice_totals() in
               config/invoice.php. Set it back to null and a gap still prints,
               labelled "Other Charges" instead.

               tin is blank until someone supplies the real number; the
               masthead omits the line entirely rather than invent one.       */
            'vat_rate' => 0.12,
            'tin'      => '',

            /* ---- printed invoice boilerplate -------------------------------
               Terms, warranty and returns live here for the same reason the
               address does: so changing them is a one-file edit instead of a
               hunt through templates. Edit the wording freely - the invoice
               prints whatever is in these lists, and skips any that is empty. */
            // Intentionally empty: the invoice prints no terms block at all
            // while this list is empty, and the space goes to the item table
            // instead. Add a line here and the whole block reappears, heading
            // and all - nothing in the template has to change.
            'invoice_terms' => [],
            /* Warranty and returns are one block, under one heading.

               Split across two headings they read as two separate policies a
               customer has to reconcile, when in practice they are one
               sequence: the store covers the first 7 days, the manufacturer
               covers the rest, and the same conditions and proof apply
               throughout. The 7-day store warranty is stated first because it
               is the part the store itself honours and the part with a
               deadline - it was missing entirely before, which left the
               manufacturer's warranty reading as the only cover on offer. */
            'invoice_policy' => [
                'Items carry a 7-day store warranty from the date on this invoice, in addition to the manufacturer\'s warranty for the product. Report defective or incorrect items within those 7 days for replacement or repair, subject to inspection.',
                'After the 7-day store warranty, claims are handled under the manufacturer\'s warranty, for its own duration and on its own terms.',
                'Cover is for manufacturing defects only. It is void where there is physical damage, liquid damage, unauthorised repair, or a removed or altered serial sticker.',
                'Bring this invoice when making any claim - it is your proof of purchase. Refunds follow the original payment method and are released after inspection.',
            ],
        ];
    }
}
