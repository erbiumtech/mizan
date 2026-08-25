<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every widget the panel has registered renders — `docs/reports-expansion-plan.md` Phase 5.9.
 *
 * "Make `FilamentWidgetsSmokeTest` enumerate the panel's registered widgets rather than a hand-written list
 * of five."
 *
 * **The list was the problem, not its length.** A hand-written list covers the widgets somebody remembered
 * to add to it, which is exactly the set least likely to be broken — the new widget nobody listed is the one
 * that fails, and a green suite said nothing about it. Phase 5 adds a widget group per module, so a list
 * would have been wrong on the first commit of it and quietly wrong thereafter.
 *
 * Asking the panel instead means this test grows by itself, and a widget that throws on an empty database
 * fails here rather than on somebody's dashboard.
 */
class FilamentWidgetsSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A floor, so an enumeration that silently found nothing cannot pass.
     *
     * The failure this guards against is the whole point of the change: `Filament::getWidgets()` returning an
     * empty array — a panel misconfiguration, a discovery path that stopped matching — would make a test that
     * loops over it the most reassuring test in the suite and the least informative.
     */
    private const FLOOR = 5;

    public function test_every_registered_widget_renders(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $widgets = $this->registeredWidgets();

        $this->assertGreaterThanOrEqual(
            self::FLOOR,
            count($widgets),
            'the panel reported almost no widgets, so this test would have proved nothing',
        );

        $failures = [];

        foreach ($widgets as $widget) {
            try {
                Livewire::test($widget)->assertSuccessful();
            } catch (\Throwable $e) {
                $failures[] = class_basename($widget).' → '.$e->getMessage();
            }
        }

        if ($failures !== []) {
            $this->fail("Widget render failures:\n - ".implode("\n - ", $failures));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The widgets the panel has, as class names.
     *
     * A panel may register a `WidgetConfiguration` rather than a class string — a widget with properties
     * pre-set — and `Livewire::test()` needs the class either way.
     *
     * @return array<int, class-string>
     */
    private function registeredWidgets(): array
    {
        return array_values(array_map(
            fn (mixed $widget): string => $widget instanceof WidgetConfiguration ? $widget->widget : $widget,
            Filament::getWidgets(),
        ));
    }
}
