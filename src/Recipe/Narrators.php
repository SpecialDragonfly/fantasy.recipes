<?php

declare(strict_types=1);

namespace App\Recipe;

/**
 * The hard-coded narrator roster an admin picks from when assigning a
 * recipe's narrator (templates/admin/recipe_edit.twig) -- the same ten
 * house-roster personas documented in personas.md and shown publicly on
 * /writers (writerRoster() in src/Routes/public.php).
 *
 * Deliberately a fixed list, not free text: any narrator can plausibly
 * narrate any recipe (the dragon narrating a salad is a feature, not a
 * mismatch -- personas.md's "no two narrators should ever feel
 * interchangeable" is about voice, not subject-matter gatekeeping), so
 * there's no need for the open-ended free-text field the Story narrator
 * used to be. Kept as a plain PHP list rather than a database table for the
 * same reason writerRoster() is a plain array -- this is a curated,
 * admin-maintained roster, not user-generated content that needs to be
 * queryable/growable at runtime.
 *
 * Names are kept in sync with writerRoster() by hand -- there's no single
 * source of truth shared between the two lists (that page carries a full
 * bio/image per persona this class has no use for), so a new persona needs
 * adding in both places.
 */
final class Narrators
{
    /** @var list<string> */
    public const NAMES = [
        'Lord Auberon Cindrake',
        'Wrenna Sixpots',
        'Gorm Millstone',
        'Ilvath Fernglass',
        'Morag Saltweather',
        'Kessa Ember-Tongue',
        'Grett Underbridge',
        'Bryony Thistledown',
        'the Concierge',
        'Fennick Merrymead',
    ];

    /**
     * True for a blank narrator (recipe not yet assigned one) or any exact
     * name from NAMES -- nothing else, since the whole point of the
     * dropdown is that free text can no longer reach the database.
     */
    public static function isValid(string $narrator): bool
    {
        return $narrator === '' || in_array($narrator, self::NAMES, true);
    }
}
