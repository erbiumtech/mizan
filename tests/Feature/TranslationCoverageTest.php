<?php

namespace Tests\Feature;

use App\Support\Locales;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The sweep, made to stick — docs/saas-launch-plan.md §8.10.
 *
 * Every string the interface shows is `__('…')` against `lang/en.json`, so a
 * second language is a file rather than a rewrite. The sweep is mechanical and
 * done **one module at a time**, which is why this test holds a list: a module
 * in SWEPT may not contain a literal label again, and one absent from it is
 * simply not swept yet. The untranslated set is one test run away at all times,
 * so nobody has to go looking for what is left.
 */
class TranslationCoverageTest extends TestCase
{
    /**
     * Modules whose Filament labels have been swept. Add a module here in the same
     * commit that sweeps it — never before, or the test fails for work nobody did.
     *
     * @var array<int, string>
     */
    private const SWEPT = ['Accounting', 'Attendance', 'Core', 'Crm', 'Employees', 'Invoicing', 'Leave', 'Payroll', 'Performance'];

    public function test_a_swept_module_holds_no_literal_label(): void
    {
        $offenders = [];

        foreach (self::SWEPT as $module) {
            foreach (File::allFiles(app_path("Modules/{$module}")) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                // A label built from a variable is not a string to translate.
                if (preg_match_all("/->label\('((?:[^'\\\\\\\\]|\\\\\\\\.)*)'\)/", $file->getContents(), $matches)) {
                    foreach ($matches[1] as $literal) {
                        $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).": {$literal}";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These labels are literal in a module that has been swept. Wrap them: ->label(__(\'…\')).',
            '',
            ...$offenders,
        ]));
    }

    public function test_every_label_a_swept_module_translates_is_a_key_in_english(): void
    {
        $english = $this->catalogue('en');
        $missing = [];

        foreach (self::SWEPT as $module) {
            foreach (File::allFiles(app_path("Modules/{$module}")) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                if (preg_match_all("/->label\(__\('((?:[^'\\\\\\\\]|\\\\\\\\.)*)'\)\)/", $file->getContents(), $matches)) {
                    foreach ($matches[1] as $literal) {
                        $key = str_replace("\\'", "'", $literal);

                        if (! array_key_exists($key, $english)) {
                            $missing[$key] = true;
                        }
                    }
                }
            }
        }

        $this->assertSame([], array_keys($missing), implode("\n", [
            'These strings are translated in code but are not keys in lang/en.json, so',
            'no other language can ever carry them.',
            '',
            ...array_keys($missing),
        ]));
    }

    /**
     * What is left to translate, as a number rather than a wall of text.
     *
     * Not an assertion that everything is translated — Urdu lands key by key and a
     * missing key falls back to the English sentence, never to a blank. The floor is
     * what has been done, so coverage cannot go backwards.
     */
    public function test_urdu_coverage_does_not_go_backwards(): void
    {
        $english = $this->catalogue('en');
        $urdu = $this->catalogue('ur');

        $this->assertNotSame([], $english, 'lang/en.json is the source catalogue and cannot be empty.');

        foreach (array_keys($urdu) as $key) {
            $this->assertArrayHasKey($key, $english, "lang/ur.json carries [{$key}], which lang/en.json does not. A translation of nothing is dead weight.");
        }

        $this->assertGreaterThanOrEqual(20, count($urdu), sprintf(
            'Urdu covers %d of %d keys. It may lag — a missing key falls back to the English sentence — but it may not shrink.',
            count($urdu),
            count($english),
        ));
    }

    public function test_the_locales_on_offer_declare_their_direction(): void
    {
        foreach (Locales::AVAILABLE as $code => $locale) {
            $this->assertArrayHasKey('rtl', $locale, "[{$code}] does not say which way it is read.");
            $this->assertFileExists(base_path("lang/{$code}.json"), "[{$code}] is on offer with no catalogue.");
        }

        $this->assertTrue(Locales::isRtl('ur'));
        $this->assertFalse(Locales::isRtl('en'));
    }

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        $path = base_path("lang/{$locale}.json");

        return File::exists($path) ? (array) json_decode(File::get($path), true) : [];
    }
}
