<?php

namespace App\Support;

/** GST registration type of a customer or supplier. */
class GstType
{
    public const UNREGISTERED = 'unregistered';
    public const REGULAR = 'regular';
    public const COMPOSITION = 'composition';

    public const ALL = [self::UNREGISTERED, self::REGULAR, self::COMPOSITION];

    /** The chosen type, or the obvious one when none was chosen: a party with a GSTIN is a regular registered business. */
    public static function resolve(?string $type, ?string $gstin): string
    {
        if (in_array($type, self::ALL, true)) {
            return $type;
        }

        return trim((string) $gstin) !== '' ? self::REGULAR : self::UNREGISTERED;
    }
}
