<?php

namespace App\Support;

/**
 * The distance between two points on the earth, in metres.
 *
 * Extracted when the second caller appeared. `WorkLocation::distanceTo()` has had
 * this since attendance geofencing; field visits need the same arithmetic against a
 * different pin, and a second copy of a haversine is a second place for somebody to
 * get the radius of the earth wrong.
 *
 * Haversine on a sphere, not an ellipsoid. The error is under half a per cent, and
 * the question being asked is "are they at the shop" against a radius somebody typed
 * as 150 — a figure that is itself a guess to the nearest fifty metres.
 */
final class Geo
{
    /** Mean earth radius, in metres. */
    private const EARTH = 6371000.0;

    public static function metresBetween(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return round(self::EARTH * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }
}
