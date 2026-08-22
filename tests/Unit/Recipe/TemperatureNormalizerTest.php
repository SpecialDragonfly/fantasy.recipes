<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recipe;

use App\Recipe\TemperatureNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * TemperatureNormalizer runs once, unsupervised, across ~20,000 recipes'
 * OriginalText -- every case here is either a real snippet pulled from the
 * actual corpus during the survey that preceded building this class, or a
 * deliberate adversarial case (a number that must NOT be touched). See the
 * class's own docblock for the design rationale.
 */
final class TemperatureNormalizerTest extends TestCase
{
    // ---- Single-unit mentions -- the bulk of the corpus (1,616 recipes) ----

    public function testSingleFahrenheitGetsCelsiusPrefixedFromStandardTable(): void
    {
        self::assertSame(
            'Preheat the oven to 180°C (350°F).',
            TemperatureNormalizer::normalize('Preheat the oven to 350°F.'),
        );
    }

    public function testSingleFahrenheitWithSpaceBeforeDegreeSymbol(): void
    {
        self::assertSame(
            'Preheat the oven to 190°C (375°F).',
            TemperatureNormalizer::normalize('Preheat the oven to 375 °F.'),
        );
    }

    public function testSingleFahrenheitWithSpelledOutDegrees(): void
    {
        self::assertSame(
            '200°C (400°F) oven',
            TemperatureNormalizer::normalize('400 degrees F oven'),
        );
    }

    public function testSingleCelsiusGetsFahrenheitAppendedFromStandardTable(): void
    {
        // 180°C is a standard-chart value (paired with 350°F) -- the table
        // takes priority over the raw formula (which would give 356°F).
        self::assertSame(
            'Preheat the oven to 180°C (350°F).',
            TemperatureNormalizer::normalize('Preheat the oven to 180°C.'),
        );
    }

    public function testSingleCelsiusNotInTableUsesPreciseFormula(): void
    {
        // 175°C isn't one of the standard chart's 25°F-increment entries --
        // falls back to exact math: 175 * 9/5 + 32 = 347.
        self::assertSame(
            '175°C (347°F)',
            TemperatureNormalizer::normalize('175°C'),
        );
    }

    public function testSingleFahrenheitNotInTableUsesPreciseFormula(): void
    {
        // A sous vide bath -- 135°F is nowhere near a "nice" oven
        // temperature and must convert precisely, not snap to the nearest
        // table entry: (135 - 32) * 5/9 = 57.22 -> 57.
        self::assertSame(
            'a sous vide bath set at 57°C (135°F) for 2 hours',
            TemperatureNormalizer::normalize('a sous vide bath set at 135°F for 2 hours'),
        );
    }

    public function testMeatThermometerCelsiusReadingConvertsPrecisely(): void
    {
        // 75°C internal temp -- 75 * 9/5 + 32 = 167.
        self::assertSame(
            'registers 75°C (167°F) on a meat thermometer',
            TemperatureNormalizer::normalize('registers 75°C on a meat thermometer'),
        );
    }

    public function testOriginalNumberIsNeverAltered(): void
    {
        // The source's own stated number must survive byte-for-byte --
        // only the OTHER (derived) number and the ordering may change.
        $result = TemperatureNormalizer::normalize('Bake at 347°F for 20 minutes.');
        self::assertStringContainsString('347°F', $result);
    }

    // ---- Already-paired mentions (154 recipes), various shapes ----

    public function testFahrenheitThenCelsiusInParenthesesReordered(): void
    {
        self::assertSame(
            'Preheat oven to 150°C (300°F).',
            TemperatureNormalizer::normalize('Preheat oven to 300° F (150° C).'),
        );
    }

    public function testCelsiusThenFahrenheitInSquareBracketsReordered(): void
    {
        self::assertSame(
            'Preheat your oven to 200°C (400°F) fan',
            TemperatureNormalizer::normalize('Preheat your oven to 200°C [400°F] fan'),
        );
    }

    public function testCelsiusThenFahrenheitAlreadyCorrectIsUnchanged(): void
    {
        self::assertSame(
            'Preheat oven to 180°C (356°F).',
            TemperatureNormalizer::normalize('Preheat oven to 180°C (356°F).'),
        );
    }

    public function testFahrenheitThenCelsiusSlashSeparated(): void
    {
        self::assertSame(
            'with the light on (about 27°C (80°F))',
            TemperatureNormalizer::normalize('with the light on (about 80°F/27°C)'),
        );
    }

    public function testFahrenheitThenDecimalCelsiusSlashSeparatedIsReorderedNotReconverted(): void
    {
        // Real corpus shape: a thermometer reading given to one decimal
        // place. The paired-slash branch must accept the decimal on the
        // Celsius side so this is recognized as a pair (reordered as-is,
        // no arithmetic) rather than falling through to the independent
        // single-mention branches, which would leave "65.5°C" untouched
        // (decimals are deliberately never converted standalone) while
        // redundantly re-deriving a second, separate Celsius figure from
        // the 150°F.
        self::assertSame(
            'registers at least 65.5°C (150°F)',
            TemperatureNormalizer::normalize('registers at least 150°F/65.5°C'),
        );
    }

    public function testFahrenheitRangeThenCelsiusRangeSlashSeparatedIsReordered(): void
    {
        // Real corpus shape: a proofing/water-temp range given in both
        // units, with the unit word stated once per side ("110 to 115
        // degrees F/43 to 46 degrees C"). All four source numbers are
        // preserved verbatim, just reordered/reformatted whole -- treating
        // the two range halves independently would instead discard the
        // "to" ranges and duplicate a derived figure that happens to
        // roughly match the other side's stated range.
        self::assertSame(
            'warm water (43 to 46°C (110 to 115°F))',
            TemperatureNormalizer::normalize('warm water (110 to 115 degrees F/43 to 46 degrees C)'),
        );
    }

    public function testCelsiusRangeThenFahrenheitRangeSlashSeparatedStaysCelsiusFirst(): void
    {
        // Celsius range already first -- the outer parentheses are the
        // surrounding sentence's own, not part of the pair; the pair still
        // gets its own brackets around the Fahrenheit half, same shape as
        // the Fahrenheit-first case, since the target format always wraps
        // the Fahrenheit range regardless of the source's original order.
        self::assertSame(
            'location (10 to 21°C (50 to 70°F))',
            TemperatureNormalizer::normalize('location (10 to 21 degree C/50 to 70 degree F)'),
        );
    }

    public function testFahrenheitRangeThenBracketedCelsiusRangeIsReordered(): void
    {
        // Real corpus shape: a range-pair bracketed rather than
        // slash-separated ("350 to 375F (180 to 190C)"). Caught against
        // the real corpus: without a dedicated branch for this, the F
        // range and the bracketed C range convert independently, each
        // gaining its own derived bracket, producing a duplicated,
        // garbled "88-91°C (190-195°F) (88-91°C (190-196°F))".
        self::assertSame(
            'internal temperature of the pork is 88-91°C (190-195°F).',
            TemperatureNormalizer::normalize('internal temperature of the pork is 190-195F (88-91C).'),
        );
    }

    public function testFahrenheitToRangeThenBracketedCelsiusToRangeIsReordered(): void
    {
        self::assertSame(
            'reduce heat to 180 to 190°C (350 to 375°F).',
            TemperatureNormalizer::normalize('reduce heat to 350 to 375F (180 to 190C).'),
        );
    }

    public function testDualUnitFahrenheitRangeThenBracketedCelsiusRangeIsReordered(): void
    {
        // Real corpus shape: unlike #testFahrenheitRangeThenBracketedCelsiusRangeIsReordered,
        // BOTH Fahrenheit bounds carry their own "degrees F" rather than
        // stating the unit once at the end -- "215 degrees F to 220
        // degrees F (101 to 105 degrees C)". Caught against the real
        // corpus: without allowing an optional unit on the first bound
        // too, the range-pair branch doesn't match this style at all, and
        // it falls through to independent single/range conversions that
        // discard and duplicate figures ("104°C (220°F) (101 to 105°C
        // (214 to 221°F))").
        self::assertSame(
            'reads 63 to 66°C (145 to 150°F), about 15 minutes',
            TemperatureNormalizer::normalize('reads 145 degrees F to 150 degrees F (63 to 66 degrees C), about 15 minutes'),
        );
    }

    public function testNormalizingABracketedRangePairTwiceIsANoOp(): void
    {
        $once = TemperatureNormalizer::normalize('reduce heat to 350 to 375F (180 to 190C).');
        $twice = TemperatureNormalizer::normalize($once);
        self::assertSame($once, $twice);
    }

    public function testNormalizingAFahrenheitRangeCelsiusRangePairTwiceIsANoOp(): void
    {
        $once = TemperatureNormalizer::normalize('warm water (110 to 115 degrees F/43 to 46 degrees C)');
        $twice = TemperatureNormalizer::normalize($once);
        self::assertSame($once, $twice);
    }

    public function testDoesNotDoubleConvertAnAlreadyPairedMention(): void
    {
        // The single-Fahrenheit and single-Celsius passes must not also
        // fire on a pair the already-paired pass already handled.
        $result = TemperatureNormalizer::normalize('Preheat oven to 300° F (150° C).');
        self::assertSame(1, preg_match_all('/°F/', $result));
        self::assertSame(1, preg_match_all('/°C/', $result));
    }

    // ---- Fan-oven compound (14 recipes): both numbers already Celsius ----

    public function testFanCompoundAddsFahrenheitToLeadingFigureOnly(): void
    {
        self::assertSame(
            'Preheat the oven to 200°C (400°F)/180°C fan/gas 6',
            TemperatureNormalizer::normalize('Preheat the oven to 200°C/180°C fan/gas 6'),
        );
    }

    public function testBracketedFanCompoundWithConnectingWordLeavesFanFigureAlone(): void
    {
        // Real corpus shape (23 rows): the fan figure isn't immediately
        // followed by "fan" (rule #1/#11's original exclusion), there's a
        // connecting word first -- "with fan" or "for fan ovens". Without
        // recognizing this too, the catch-all single-Celsius branch
        // independently converts the fan figure as well, adding a second,
        // unwanted Fahrenheit conversion right next to the main one.
        self::assertSame(
            'Preheat the oven to 180°C (350°F) (160°C with fan) Gas Mark 4.',
            TemperatureNormalizer::normalize('Preheat the oven to 180°C (160°C with fan) Gas Mark 4.'),
        );
        self::assertSame(
            'Preheat the oven to 190°C (375°F) (170°C for fan ovens).',
            TemperatureNormalizer::normalize('Preheat the oven to 190°C (170°C for fan ovens).'),
        );
    }

    // ---- Gas Mark with NO numeric temperature anywhere (2,388 recipes) ----

    public function testGasMarkAloneGetsCelsiusAndFahrenheitInjected(): void
    {
        self::assertSame(
            'Preheat the oven to 200°C (400°F, Gas Mark 6).',
            TemperatureNormalizer::normalize('Preheat the oven to Gas Mark 6.'),
        );
    }

    public function testGasNumberWithoutTheWordMarkStillMatches(): void
    {
        self::assertSame(
            '180°C (350°F, gas 4)',
            TemperatureNormalizer::normalize('gas 4'),
        );
    }

    public function testGasMarkIsLeftAloneWhenACelsiusFigureAlreadyExistsAnywhereInTheText(): void
    {
        // Common real shape: "200°C/gas 6" -- the Celsius figure right
        // there means this is the already-paired/compound case, not the
        // gas-mark-only case; injecting a second, redundant conversion
        // right next to "gas 6" as well would be noise, not a fix.
        $result = TemperatureNormalizer::normalize('Preheat the oven to 200°C/gas 6.');
        self::assertSame(1, preg_match_all('/°F/', $result));
    }

    public function testGasMarkFractionsConvertCorrectly(): void
    {
        self::assertSame(
            '110°C (225°F, Gas Mark ¼)',
            TemperatureNormalizer::normalize('Gas Mark ¼'),
        );
    }

    public function testHasGasMarkWithNoNumericTemperatureIsFalseWhenFahrenheitPresentElsewhere(): void
    {
        self::assertFalse(TemperatureNormalizer::hasGasMarkWithNoNumericTemperature(
            'Step 1: preheat to 350°F. Step 5: finish on Gas Mark 6 for the last 10 minutes.',
        ));
    }

    // ---- Things that must NEVER be touched ----

    public function testSpelledOutUnitWordsAreNotMistakenForTheAbbreviation(): void
    {
        // "Celsius"/"Fahrenheit" spelled out in full must not match just
        // because they start with the letter C/F -- there's no word
        // boundary between "C" and the "elsius" that follows it.
        $text = 'Set the thermometer to read in Celsius, not Fahrenheit.';
        self::assertSame($text, TemperatureNormalizer::normalize($text));
    }

    public function testDecimalTemperaturesAreLeftAlone(): void
    {
        // Not one of the recognized structural patterns -- left untouched
        // by design rather than guessing at how to round/convert it.
        $text = 'the syrup should read 98.6°F on a candy thermometer';
        self::assertSame($text, TemperatureNormalizer::normalize($text));
    }

    public function testIngredientWeightsAreNeverTouched(): void
    {
        self::assertSame(
            '400g flour, 200g sugar, a 12-inch pan',
            TemperatureNormalizer::normalize('400g flour, 200g sugar, a 12-inch pan'),
        );
    }

    public function testLooseOrPhrasingIsLeftAlone(): void
    {
        // Not a structural pairing -- deliberately not matched, per the
        // class docblock. Asserting the CURRENT (documented) behaviour so
        // a future change here is a conscious decision, not a regression
        // nobody noticed.
        $input = 'It should read 86°C or 180°F.';
        $result = TemperatureNormalizer::normalize($input);
        self::assertStringContainsString('86°C (', $result);
        self::assertStringContainsString('180°F)', $result);
    }

    public function testTextWithNoTemperatureIsUnchanged(): void
    {
        $text = 'Whisk the eggs and fold in the flour. Rest for 20 minutes.';
        self::assertSame($text, TemperatureNormalizer::normalize($text));
    }

    public function testPlainGasWordWithoutANumberIsNotTreatedAsAGasMark(): void
    {
        $text = 'light the gas and let it simmer';
        self::assertSame($text, TemperatureNormalizer::normalize($text));
    }

    // ---- Idempotency: running twice must be a no-op ----

    public function testNormalizingAnAlreadyNormalizedStringIsANoOp(): void
    {
        $once = TemperatureNormalizer::normalize('Bake at 375°F for 40-60 minutes, then rest at 90°F.');
        $twice = TemperatureNormalizer::normalize($once);
        self::assertSame($once, $twice);
    }

    public function testNormalizingAGasMarkInjectionTwiceIsANoOp(): void
    {
        $once = TemperatureNormalizer::normalize('Preheat the oven to Gas Mark 6.');
        $twice = TemperatureNormalizer::normalize($once);
        self::assertSame($once, $twice);
    }

    public function testNormalizingAFanCompoundTwiceIsANoOp(): void
    {
        $once = TemperatureNormalizer::normalize('Preheat the oven to 200°C/180°C fan/gas 6');
        $twice = TemperatureNormalizer::normalize($once);
        self::assertSame($once, $twice);
    }

    // ---- hasNormalizableTemperature() -- the batch command's cheap pre-filter ----

    public function testHasNormalizableTemperatureTrueForASingleMention(): void
    {
        self::assertTrue(TemperatureNormalizer::hasNormalizableTemperature('Bake at 180°C.'));
    }

    public function testHasNormalizableTemperatureTrueForGasMarkOnly(): void
    {
        self::assertTrue(TemperatureNormalizer::hasNormalizableTemperature('Gas Mark 4'));
    }

    public function testHasNormalizableTemperatureFalseForPlainText(): void
    {
        self::assertFalse(TemperatureNormalizer::hasNormalizableTemperature('Whisk the eggs.'));
    }

    // ---- Real corpus snippets (verbatim, survey samples) ----

    public function testRealSnippetOilTemperatureWithParentheses(): void
    {
        self::assertSame(
            'the oil temperature up to 205°C (400°F)',
            TemperatureNormalizer::normalize('the oil temperature up to 400° F (205° C)'),
        );
    }

    public function testRealSnippetHighestSettingParenthetical(): void
    {
        self::assertSame(
            'to its highest setting (250°C (482°F))',
            TemperatureNormalizer::normalize('to its highest setting (250°C)'),
        );
    }

    public function testRealSnippetMultipleUnrelatedMentionsEachConvertIndependently(): void
    {
        // 220°C and 190°C are both standard-chart values (425°F, 375°F) --
        // the table takes priority over the raw formula for both.
        $result = TemperatureNormalizer::normalize(
            'Preheat the oven to 220°C. Roast for 10 minutes, then reduce to 190°C for a further 20.',
        );
        self::assertSame(
            'Preheat the oven to 220°C (425°F). Roast for 10 minutes, then reduce to 190°C (375°F) for a further 20.',
            $result,
        );
    }
}
