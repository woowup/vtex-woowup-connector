<?php

namespace WoowUpConnectors\Support;

/**
 * Translates a newsletter opt-in into WoowUp's communication fields.
 *
 * Mirror of `Connectors\Support\CommunicationOptIn`, which this package cannot reach: connectors
 * and this connector are separate Composer packages. Keep both in sync — the field names, the
 * status values and the reason belong to WoowUp's user model, not to VTEX.
 *
 * One store flag drives the three channels. That is a product decision (Customer Data, 2026-08-14),
 * together with the precedence: when the store and WoowUp disagree, the store wins.
 *
 * The reason goes in `*_enabled_reason`. There is no `*_disabled_reason` in the API: apiv3's
 * UserController does not know it and drops it silently.
 */
class CommunicationOptIn
{
    const STATUS_ENABLED  = 'enabled';
    const STATUS_DISABLED = 'disabled';

    /**
     * The only value meaning "no explicit preference from the customer", so a later write can
     * override it. The full enum is bounce / unsubscribe / spamreport / dropped / invalid / other,
     * and WoowUp sets the other five from delivery events: a connector has no business writing them.
     *
     * @var string
     */
    const REASON_OTHER = 'other';

    const CHANNELS = ['mailing', 'sms', 'whatsapp'];

    /**
     * Turns whatever VTEX reports into the `?bool` `apply()` expects.
     *
     * **Master Data serialises its booleans**: measured on the carts queue, `isNewsletterOptIn`
     * arrives as a string. A plain `(bool)` cast turns `"false"` into true and enables the three
     * channels for someone who explicitly said no — which is why this is a separate step and not an
     * inline cast at each call site. The same field reaches us through three paths (the customers
     * scroll, the carts message and the `CL/search` lookup) and they must not read it differently.
     *
     * Anything unrecognised returns null —do not touch— rather than guessing. A caller that needs a
     * different fallback for unknown values says so at its own call site.
     *
     * @param  mixed $value
     * @return bool|null
     */
    public static function normalize($value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @param  array     $customer    customer mapping; a modified copy is returned
     * @param  bool|null $optIn       true subscribed, false not subscribed, **null "unknown"** →
     *                                nothing is written. In carts null means the Master Data lookup
     *                                found no profile or failed, which is not the customer saying no.
     * @param  bool      $ignoreOptIn per-account flag: when on, nothing is written at all
     * @return array
     */
    public static function apply(array $customer, ?bool $optIn, bool $ignoreOptIn = false): array
    {
        if ($ignoreOptIn || $optIn === null) {
            return $customer;
        }

        foreach (self::CHANNELS as $channel) {
            $customer[$channel . '_enabled'] = $optIn ? self::STATUS_ENABLED : self::STATUS_DISABLED;
        }

        // The three reasons after the three states, not interleaved: keeps the insertion order the
        // payload already had, so the DynamoDB cache hash does not change.
        if (!$optIn) {
            foreach (self::CHANNELS as $channel) {
                $customer[$channel . '_enabled_reason'] = self::REASON_OTHER;
            }
        }

        return $customer;
    }
}
