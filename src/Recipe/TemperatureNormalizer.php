<?php

declare(strict_types=1);

namespace App\Recipe;

/**
 * Normalizes temperature mentions in recipe text to a single consistent
 * form: Celsius first, Fahrenheit in brackets -- "180°C (350°F)". Built as
 * a standalone, heavily-tested class rather than an inline regex sweep
 * because it runs once across ~20,000 recipes' OriginalText with no human
 * review of each change; a wrong conversion or a mangled non-temperature
 * number (an ingredient weight, a pan size) would be silently wrong at
 * scale. See tests/Unit/Recipe/TemperatureNormalizerTest.php.
 *
 * Deliberately conservative: only touches text matching a small set of
 * well-defined structural patterns (single-unit mention, an
 * already-paired mention in either order/bracket/slash style, a fan-oven
 * "X°C/Y°C fan" compound, or a Gas Mark with no numeric temperature
 * anywhere in the text). Anything else -- loose phrasing like "86°C or
 * 180°F", numbers that aren't temperatures at all -- is left untouched by
 * design. That means not every mention gets normalized, but nothing gets
 * corrupted.
 *
 * Two independent number sources feed the output:
 *  - A number the source text actually stated is NEVER recalculated or
 *    rounded away -- it's preserved exactly and only reordered/reformatted.
 *  - A number this class has to derive (the source only gave one unit, or
 *    only a Gas Mark) comes from CONVERSION_TABLE first (the standard UK
 *    oven chart -- see its own docblock) and a precise formula rounded to
 *    the nearest whole degree only as a fallback for values the table
 *    doesn't cover (sous vide baths, candy/meat thermometer readings,
 *    proofing temperatures -- this corpus has plenty of those, and they
 *    need accurate conversion, not snapping to the nearest "nice" oven
 *    temperature).
 *
 * Implementation note: the whole thing is ONE regex with alternation
 * (most-specific pattern first), matched in a single preg_replace_callback
 * pass -- not a pipeline of several sequential passes. An earlier version
 * ran separate passes (paired-mentions, then single-F, then single-C, ...)
 * and each later pass re-scanned the text the earlier passes had just
 * produced, matching its own output as if it were fresh input and
 * double-converting it ("180°C (350°F)" becoming
 * "180°C (350°F) (180°C (350°F))" and worse). A single pass over the
 * ORIGINAL text, where each position is consumed by at most one
 * alternative, has no such reprocessing to go wrong.
 */
final class TemperatureNormalizer
{
    /**
     * The standard UK oven-temperature conversion chart (the same one
     * BBC Good Food, Delia Smith, and virtually every UK cookbook
     * publishes) -- Fahrenheit => [Celsius, Gas Mark|null]. Different
     * published charts round the same Fahrenheit value slightly
     * differently (325°F is variously given as 160°C or 165°C); this is
     * ONE specific, real, citable chart, chosen so every recipe on the
     * site is at least internally consistent with the others, which is
     * what "consistency" actually requires here -- perfectly guessing
     * which chart each original source's author personally used isn't
     * achievable or necessary.
     *
     * @var array<int, array{0: int, 1: string|null}>
     */
    private const CONVERSION_TABLE = [
        200 => [95, null],
        225 => [110, '¼'],
        250 => [120, '½'],
        275 => [140, '1'],
        300 => [150, '2'],
        325 => [160, '3'],
        350 => [180, '4'],
        375 => [190, '5'],
        400 => [200, '6'],
        425 => [220, '7'],
        450 => [230, '8'],
        475 => [240, '9'],
        500 => [260, null],
    ];

    /**
     * Gas Mark => [Celsius, Fahrenheit], for the Gas-Mark-only case (no
     * numeric temperature anywhere in the text at all). Derived from, and
     * kept consistent with, CONVERSION_TABLE above.
     *
     * Keys are a PHP-coerced mix: '1'..'9' become int keys automatically,
     * while '1/4', '1/2', '¼', '½' aren't valid numeric strings so stay
     * string keys -- hence the int|string key type below.
     *
     * @var array<int|string, array{0: int, 1: int}>
     */
    private const GAS_MARK_TABLE = [
        '1/4' => [110, 225], '¼' => [110, 225],
        '1/2' => [120, 250], '½' => [120, 250],
        '1' => [140, 275],
        '2' => [150, 300],
        '3' => [160, 325],
        '4' => [180, 350],
        '5' => [190, 375],
        '6' => [200, 400],
        '7' => [220, 425],
        '8' => [230, 450],
        '9' => [240, 475],
    ];

    /**
     * The unit marker between a number and its F/C letter, matching every
     * shape seen in the corpus: a bare letter ("400F"), a degree symbol
     * ("400°F", "400 °F"), or the word spelled out ("400 degrees F").
     * Every place this is used, the letter itself is followed by \b, so
     * "Celsius"/"Fahrenheit" spelled out in full never matches -- there's
     * no word boundary between "C" and the "elsius" that follows it.
     */
    private const UNIT = '\s*(?:°\s*|degrees?\s*)?';

    /**
     * How a range's two bounds are joined in this corpus: a plain hyphen,
     * an en dash or em dash (word processors/CMSs routinely "smarten"
     * a typed hyphen into one of these), or the words "to"/"and". Not
     * captured here -- each range branch below captures its own connector
     * so it can echo back whichever one the source actually used rather
     * than forcing a single style.
     */
    private const RANGE_SEP = '(?:-|–|—|\s+to\s+|\s+and\s+)';

    public static function normalize(string $text): string
    {
        $u = self::UNIT;
        $sep = self::RANGE_SEP;

        // One regex, tried as alternation in priority order (most
        // structurally specific first) so each position in the ORIGINAL
        // text is consumed by exactly one branch -- see the class
        // docblock for why this must be a single pass, not several.
        $pattern = '/'
            // 1. Fan-compound leading figure: "200°C/180°C fan" -- convert
            //    only the conventional (leading) temperature; the fan
            //    figure is deliberately left alone (see normalizeMatch()).
            . '(?<![\d.])(?<fan>\d+)' . $u . 'C(?=\s*\/\s*\d+' . $u . 'C\s*fan)'
            // 2. Already paired, brackets, Fahrenheit first: "350°F (175°C)".
            //    Only the SECOND (bracketed) number allows a leading "-" --
            //    sub-zero pairs are rare but real (dry ice, blast-freezer
            //    steps). The first number deliberately stays sign-less: it
            //    can sit right after a hyphen-range's own separator (e.g.
            //    the "200" in "180-200°F (...)"), and letting it swallow
            //    that hyphen as a minus sign would misread the range
            //    boundary as a negative temperature. Anything between the
            //    second number's unit and the closing bracket is captured
            //    and echoed back verbatim rather than assumed empty -- a
            //    real corpus shape tucks a Gas Mark inside the SAME
            //    bracket with no separating close-paren of its own
            //    ("220°C (425°F gas mark 7)"), and silently dropping that
            //    text would be data loss, not just a missed conversion.
            . '|(?<![\d.])(?<pfc_f>\d+)' . $u . 'F\s*[(\[]\s*(?<![\d.])(?<pfc_c>-?\d+(?:\.\d+)?)' . $u . 'C(?<pfc_extra>[^()\[\]]*)[)\]]'
            // 3. Already paired, brackets, Celsius first: "175°C (350°F)".
            . '|(?<![\d.])(?<pcf_c>\d+)' . $u . 'C\s*[(\[]\s*(?<![\d.])(?<pcf_f>-?\d+(?:\.\d+)?)' . $u . 'F(?<pcf_extra>[^()\[\]]*)[)\]]'
            // 4. A range-pair this method already produced, passed through
            //    unchanged: "43 to 46°C (110 to 115°F)" or "163-180°C
            //    (325-350°F)" -- see #5/#6/#7/#8 below, this is their
            //    shared output shape (hyphen- and "to"-joined ranges render
            //    identically apart from the separator). Needed for
            //    idempotency: PCRE lookbehind must be fixed-width, so
            //    unlike the Gas-Mark/fan exclusions elsewhere in this
            //    pattern, a variable-length "preceded by '(<digits><sep>'"
            //    lookbehind isn't available to keep singleF/singleC/#7/#8
            //    from re-matching a range's trailing/leading figure on a
            //    second pass -- recognizing and re-emitting the whole
            //    already-correct shape here, at higher priority than
            //    everything below, sidesteps that limitation the same way
            //    #2/#3 above make already-paired single mentions idempotent.
            . '|(?<already_range>(?<![\d.])\d+' . $sep . '(?<![\d.])\d+' . $u . 'C\s*\(\s*(?<![\d.])\d+' . $sep . '(?<![\d.])\d+' . $u . 'F\s*\))'
            // 5. Already paired, slash, both sides a range, Fahrenheit
            //    first: "110 to 115 degrees F/43 to 46 degrees C" -- a
            //    proofing/water-temp range stated in both units, unit
            //    written once per side. Reordered whole, all four numbers
            //    preserved verbatim -- must come before the plain
            //    single-number slash branches below, or their independent
            //    single-mention conversions would instead fire on just the
            //    trailing number of each range, discarding the "to" range
            //    and duplicating a derived figure that happens to roughly
            //    match the other side's stated range (garbled, not wrong
            //    per number, but structurally nonsense).
            . '|(?<![\d.])(?<rfc_f1>\d+)' . $sep . '(?<![\d.])(?<rfc_f2>\d+)' . $u . 'F\s*\/\s*(?<![\d.])(?<rfc_c1>\d+)' . $sep . '(?<![\d.])(?<rfc_c2>\d+)' . $u . 'C\b'
            // 6. Already paired, slash, both sides a range, Celsius first:
            //    "10 to 21 degree C/50 to 70 degree F".
            . '|(?<![\d.])(?<rcf_c1>\d+)' . $sep . '(?<![\d.])(?<rcf_c2>\d+)' . $u . 'C\s*\/\s*(?<![\d.])(?<rcf_f1>\d+)' . $sep . '(?<![\d.])(?<rcf_f2>\d+)' . $u . 'F\b'
            // 6b/6c. The same range-pair idea as #5/#6, but bracketed
            //    instead of slash-separated: "350 to 375F (180 to 190C)" --
            //    equally common in the corpus. Each side's connector is
            //    captured and echoed back rather than forced to one style.
            //    The first number of each range optionally carries its own
            //    unit too ("215 degrees F to 220 degrees F (...)", unit
            //    stated on both bounds, not just the second) -- present or
            //    not, it doesn't change the render, only the match, so one
            //    optional group covers both corpus styles. Must come before
            //    #7 for the same reason #5/#6 come before #7: without it,
            //    #7 would convert each range independently against its OWN
            //    unit only, discarding the other side's range entirely and
            //    appending a second, redundant derived bracket right next
            //    to the first (this exact bug, caught against the real
            //    corpus: "88-91°C (190-195°F) (88-91°C (190-196°F))").
            . '|(?<![\d.])(?<brfc_f1>\d+)(?:' . $u . 'F)?(?<brfc_fsep>' . $sep . ')(?<![\d.])(?<brfc_f2>\d+)' . $u . 'F\s*[(\[]\s*(?<![\d.])(?<brfc_c1>\d+)(?:' . $u . 'C)?(?<brfc_csep>' . $sep . ')(?<![\d.])(?<brfc_c2>\d+)' . $u . 'C\s*[)\]]'
            . '|(?<![\d.])(?<brcf_c1>\d+)(?:' . $u . 'C)?(?<brcf_csep>' . $sep . ')(?<![\d.])(?<brcf_c2>\d+)' . $u . 'C\s*[(\[]\s*(?<![\d.])(?<brcf_f1>\d+)(?:' . $u . 'F)?(?<brcf_fsep>' . $sep . ')(?<![\d.])(?<brcf_f2>\d+)' . $u . 'F\s*[)\]]'
            // 7. A range in ONE unit only, no pairing at all -- by far the
            //    most common range shape in the corpus ("180-200C", "325 to
            //    350°F", "58C-60C"): the unit is written once, after the
            //    second number, and applies to both. Both bounds must be
            //    derived here (unlike #5/#6, there's no second-unit range
            //    to reorder in from) -- left un-derived, only the second
            //    number would get an independent single-mention conversion
            //    (via #11 below) while the first stayed a bare, unitless
            //    number; harmless-looking for a Celsius-sourced range (the
            //    bare figure still reads as Celsius, consistent with this
            //    class's Celsius-first target format) but actively
            //    misleading for a Fahrenheit-sourced one, e.g. "325-350°F"
            //    would become "325-180°C (350°F)" -- 325 silently
            //    stranded with no unit, sitting right next to an unrelated
            //    Celsius figure it was never converted to. The connector
            //    (hyphen or "to") is captured and echoed back rather than
            //    normalized to one style, since only unit order/format was
            //    asked for here, not range-separator style.
            . '|(?<![\d.])(?<urff1>\d+)(?<urfsep>' . $sep . ')(?<![\d.])(?<urff2>\d+)' . $u . 'F\b'
            . '|(?<![\d.])(?<urcc1>\d+)(?<urcsep>' . $sep . ')(?<![\d.])(?<urcc2>\d+)' . $u . 'C\b'
            // 8. Already paired, slash, Fahrenheit first: "80°F/27°C" --
            //    no bracket to consume, so none is (a slash pair can sit
            //    inside someone ELSE's parentheses, e.g. "(about 80°F/27°C)",
            //    and must not swallow that outer closer). The Celsius side
            //    accepts a decimal ("150°F/65.5°C" is a real corpus shape,
            //    a thermometer reading translated to one decimal place) --
            //    no arithmetic is done here, just reordering, so preserving
            //    the extra digit verbatim is free. Same sign-less-first-
            //    number reasoning as #2/#3 applies to the leading number
            //    here too.
            . '|(?<![\d.])(?<sfc_f>\d+)' . $u . 'F\s*\/\s*(?<![\d.])(?<sfc_c>-?\d+(?:\.\d+)?)' . $u . 'C\b'
            // 9. Already paired, slash, Celsius first: "27°C/80°F".
            . '|(?<![\d.])(?<scf_c>\d+)' . $u . 'C\s*\/\s*(?<![\d.])(?<scf_f>-?\d+(?:\.\d+)?)' . $u . 'F\b'
            // 10. Lone Fahrenheit mention -- excludes the specific literal
            //    shape "°C (Y°F" immediately before it, which only ever
            //    occurs as the second half of a Gas-Mark injection's own
            //    output ("200°C (400°F, Gas Mark 6)" -- see
            //    normalizeGasMarkOnly()'s exact replacement string below).
            //    A genuinely fresh "400°F, Gas Mark 6" with nothing before
            //    it (no preceding "°C (") is NOT excluded and still
            //    converts normally -- this is a precise, literal-text
            //    lookbehind, not a broad "near a comma/Gas" exclusion,
            //    specifically so it doesn't also suppress that legitimate
            //    first-time case.
            . '|(?<!°C \()(?<![\d.])(?<singleF>\d+)' . $u . 'F\b'
            // 11. Lone Celsius mention -- excludes a fan-compound's trailing
            //    figure (already handled, deliberately unconverted, by #1,
            //    for the slash-joined "200°C/180°C fan" shape; the bracketed
            //    equivalent, "200°C (180°C with fan)"/"...for fan ovens)",
            //    is common enough in the corpus -- 23 rows -- to warrant its
            //    own exclusion here too, or this catch-all independently
            //    converts the fan figure, breaking the same "never derive a
            //    second Fahrenheit next to the fan setting" rule #1 exists
            //    for), and excludes a Celsius figure immediately followed by
            //    a Gas-Mark injection's own "(Y°F, ..." shape (produced by
            //    normalizeGasMarkOnly() below) -- that shape has a comma
            //    before its closing bracket so none of the paired-bracket
            //    alternatives above match it on a second pass, and without
            //    this exclusion this catch-all would treat its leading
            //    Celsius figure as an unpaired mention and append a second,
            //    redundant Fahrenheit conversion right after the first.
            . '|(?<![\d.])(?<singleC>\d+)' . $u . 'C\b(?!\s*(?:with\s+|for\s+)?fan\b)(?!\s*\(\s*\d+°F\s*,)'
            . '/i';

        $text = preg_replace_callback($pattern, self::renderMatch(...), $text) ?? $text;

        // Gas-Mark-only injection is a genuinely separate case (no numeric
        // temperature anywhere in the text at all) and always runs as a
        // second, independent pass over whatever the first pass produced
        // -- safe precisely because the first pass provably touched
        // nothing in text this gate matches (if it had, there'd be a
        // numeric °F/°C now, and this gate is evaluated fresh below).
        if (self::hasGasMarkWithNoNumericTemperature($text)) {
            $text = self::normalizeGasMarkOnly($text);
        }

        return $text;
    }

    /**
     * @param array<int|string, string> $m
     */
    private static function renderMatch(array $m): string
    {
        if (($m['fan'] ?? '') !== '') {
            $celsius = (int) $m['fan'];

            return $celsius . '°C (' . self::celsiusToFahrenheit($celsius) . '°F)';
        }

        if (($m['pfc_f'] ?? '') !== '') {
            return $m['pfc_c'] . '°C (' . $m['pfc_f'] . '°F' . ($m['pfc_extra'] ?? '') . ')';
        }

        if (($m['pcf_c'] ?? '') !== '') {
            return $m['pcf_c'] . '°C (' . $m['pcf_f'] . '°F' . ($m['pcf_extra'] ?? '') . ')';
        }

        if (($m['already_range'] ?? '') !== '') {
            return $m['already_range'];
        }

        if (($m['rfc_f1'] ?? '') !== '') {
            return $m['rfc_c1'] . ' to ' . $m['rfc_c2'] . '°C (' . $m['rfc_f1'] . ' to ' . $m['rfc_f2'] . '°F)';
        }

        if (($m['rcf_c1'] ?? '') !== '') {
            return $m['rcf_c1'] . ' to ' . $m['rcf_c2'] . '°C (' . $m['rcf_f1'] . ' to ' . $m['rcf_f2'] . '°F)';
        }

        if (($m['brfc_f1'] ?? '') !== '') {
            return $m['brfc_c1'] . $m['brfc_csep'] . $m['brfc_c2'] . '°C (' . $m['brfc_f1'] . $m['brfc_fsep'] . $m['brfc_f2'] . '°F)';
        }

        if (($m['brcf_c1'] ?? '') !== '') {
            return $m['brcf_c1'] . $m['brcf_csep'] . $m['brcf_c2'] . '°C (' . $m['brcf_f1'] . $m['brcf_fsep'] . $m['brcf_f2'] . '°F)';
        }

        if (($m['urff1'] ?? '') !== '') {
            $celsius1 = self::fahrenheitToCelsius((int) $m['urff1']);
            $celsius2 = self::fahrenheitToCelsius((int) $m['urff2']);

            return $celsius1 . $m['urfsep'] . $celsius2 . '°C (' . $m['urff1'] . $m['urfsep'] . $m['urff2'] . '°F)';
        }

        if (($m['urcc1'] ?? '') !== '') {
            $fahrenheit1 = self::celsiusToFahrenheit((int) $m['urcc1']);
            $fahrenheit2 = self::celsiusToFahrenheit((int) $m['urcc2']);

            return $m['urcc1'] . $m['urcsep'] . $m['urcc2'] . '°C (' . $fahrenheit1 . $m['urcsep'] . $fahrenheit2 . '°F)';
        }

        if (($m['sfc_f'] ?? '') !== '') {
            return $m['sfc_c'] . '°C (' . $m['sfc_f'] . '°F)';
        }

        if (($m['scf_c'] ?? '') !== '') {
            return $m['scf_c'] . '°C (' . $m['scf_f'] . '°F)';
        }

        if (($m['singleF'] ?? '') !== '') {
            $fahrenheit = (int) $m['singleF'];

            return self::fahrenheitToCelsius($fahrenheit) . '°C (' . $fahrenheit . '°F)';
        }

        if (($m['singleC'] ?? '') !== '') {
            $celsius = (int) $m['singleC'];

            return $celsius . '°C (' . self::celsiusToFahrenheit($celsius) . '°F)';
        }

        // Unreachable given the pattern's alternation always sets one of
        // the above -- returned only to satisfy the return type.
        return $m[0];
    }

    /**
     * Only called for text with a Gas Mark mention and NO numeric
     * Celsius/Fahrenheit anywhere at all (see hasGasMarkWithNoNumericTemperature()) --
     * injects a computed "X°C (Y°F, " immediately before each "Gas Mark N"
     * occurrence, e.g. "Gas Mark 6" => "200°C (400°F, Gas Mark 6)".
     */
    private static function normalizeGasMarkOnly(string $text): string
    {
        return preg_replace_callback(
            self::gasMarkPattern(),
            static function (array $m): string {
                $mark = $m[1];
                if (!isset(self::GAS_MARK_TABLE[$mark])) {
                    return $m[0];
                }

                [$celsius, $fahrenheit] = self::GAS_MARK_TABLE[$mark];

                return $celsius . '°C (' . $fahrenheit . '°F, ' . $m[0] . ')';
            },
            $text,
        ) ?? $text;
    }

    private static function gasMarkPattern(): string
    {
        // (?!\w) rather than \b at the end: \b requires a transition
        // to/from a \w character, but "¼"/"½" aren't \w themselves, so a
        // literal \b right after one never matches anything (confirmed by
        // a failing test before this fix). (?!\w) -- "not immediately
        // followed by a word character" -- means the same thing in
        // practice here and works regardless of what the matched mark
        // character itself is.
        return '/\bgas\s*(?:mark)?\s*(1\/4|1\/2|¼|½|[1-9])(?!\w)/i';
    }

    /**
     * True only when $text has a Gas Mark mention but no numeric
     * Fahrenheit/Celsius temperature anywhere in it -- the gate that
     * decides whether normalizeGasMarkOnly() should run at all (a text
     * with "200°C/gas 6" already gets its Gas Mark left alone as
     * supplementary detail; a text with only "Gas Mark 6" and nothing
     * else needs the pair injected).
     */
    public static function hasGasMarkWithNoNumericTemperature(string $text): bool
    {
        $hasGasMark = preg_match(self::gasMarkPattern(), $text) === 1;
        $hasNumericTemp = preg_match('/\d+' . self::UNIT . '[FC]\b/i', $text) === 1;

        return $hasGasMark && !$hasNumericTemp;
    }

    /**
     * True if $text has any pattern normalize() would act on -- lets the
     * batch command skip a write entirely (and skip counting a row as
     * "changed") for recipes with no temperature mentions at all, which is
     * most of them.
     */
    public static function hasNormalizableTemperature(string $text): bool
    {
        return preg_match('/\d+' . self::UNIT . '[FC]\b/i', $text) === 1
            || self::hasGasMarkWithNoNumericTemperature($text);
    }

    private static function fahrenheitToCelsius(int $fahrenheit): int
    {
        if (isset(self::CONVERSION_TABLE[$fahrenheit])) {
            return self::CONVERSION_TABLE[$fahrenheit][0];
        }

        return (int) round(($fahrenheit - 32) * 5 / 9);
    }

    private static function celsiusToFahrenheit(int $celsius): int
    {
        foreach (self::CONVERSION_TABLE as $fahrenheit => [$tableCelsius, ]) {
            if ($tableCelsius === $celsius) {
                return $fahrenheit;
            }
        }

        return (int) round($celsius * 9 / 5 + 32);
    }
}
