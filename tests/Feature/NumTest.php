<?php

namespace Tests\Feature;

use App\Support\Num;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Numbers written for people — {@see \App\Support\Num}.
 *
 * **The file exists for one input: a whole ten.** `rtrim(rtrim((string) 10, '0'), '.')` returns `"1"`,
 * because `rtrim` strips characters rather than a decimal fraction, and there is no decimal point in
 * `"10"` to stop it. Ten per cent printed as one per cent, on a payment certificate a subcontractor signs.
 *
 * It survived for as long as it did because it is invisible from the wrong side: a `decimal:2` cast hands
 * `rtrim` the string `"10.00"`, which trims correctly to `"10"`. Only the columns with no cast — plain
 * ints and floats — reach it bare. So a test that exercises a cast column passes while the same code on an
 * uncast one is a tenth out.
 */
class NumTest extends TestCase
{
    /**
     * The whole point of the class, and the first three rows are the bug.
     */
    #[DataProvider('numbers')]
    public function test_trim(int|float|string|null $value, string $expected): void
    {
        $this->assertSame($expected, Num::trim($value), var_export($value, true));
    }

    public static function numbers(): array
    {
        return [
            // The regression. Every one of these returned a tenth or a hundredth of itself.
            'ten' => [10, '10'],
            'twenty' => [20, '20'],
            'one hundred' => [100, '100'],
            'a thousand' => [1000, '1000'],

            // These always worked, and are here so a "fix" that breaks them is caught.
            'already decimal string' => ['10.00', '10'],
            'a half' => [12.5, '12.5'],
            'two places kept' => [12.25, '12.25'],
            'single digit' => [7, '7'],
            'below one' => [0.5, '0.5'],
            'trailing zero dropped' => [12.50, '12.5'],
            'float that is whole' => [10.0, '10'],

            // A blank percentage reads worse than a zero one.
            'zero' => [0, '0'],
            'zero string' => ['0.00', '0'],
            'negative' => [-5, '-5'],

            // Nothing in, nothing out — the caller decides what to show instead.
            'null' => [null, ''],
            'empty' => ['', ''],
        ];
    }

    public function test_percent_appends_the_sign(): void
    {
        $this->assertSame('10%', Num::percent(10));
        $this->assertSame('12.5%', Num::percent(12.5));
        $this->assertSame('0%', Num::percent(0));
    }

    /** Nothing in, nothing out — never a bare "%" with no figure in front of it. */
    public function test_percent_of_nothing_is_nothing(): void
    {
        $this->assertSame('', Num::percent(null));
        $this->assertSame('', Num::percent(''));
    }

    /** More places when a rate needs them, and trailing zeros still go. */
    public function test_precision_is_configurable(): void
    {
        $this->assertSame('12.3456', Num::trim(12.3456, 4));
        $this->assertSame('12.35', Num::trim(12.3456, 2));
        $this->assertSame('12', Num::trim(12.3456, 0));
        $this->assertSame('13', Num::trim(12.5678, 0), 'zero places rounds rather than truncating');
    }

    /**
     * No thousands separator.
     *
     * A comma would survive the trim and read as part of the figure — and worse, `(float) "1,000"` is
     * `1.0`, so a grouped string round-tripping through anything numeric loses three digits. Callers that
     * want groups want `number_format` directly.
     */
    public function test_no_thousands_separator(): void
    {
        $this->assertSame('1000000', Num::trim(1000000));
        $this->assertStringNotContainsString(',', Num::trim(1234567.5));
    }
}
