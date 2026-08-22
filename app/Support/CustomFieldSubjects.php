<?php

namespace App\Support;

/**
 * Which models a company may define custom fields on, contributed by the modules that own them.
 *
 * `CustomFieldResource` held a `const MODELS` naming six classes across four modules, which made Core's
 * custom-fields screen depend on all four for a dropdown. See docs/module-packaging-plan.md §9.
 *
 * **Keyed on the alias, never the class.** `custom_fields.model_type` stores a stable alias precisely so a
 * definition keeps pointing at the right model after that model moves, and the old list existed only to be
 * mapped through `ModuleMap::alias()` before use. Registering the alias directly means this registry never
 * loads — never even names — a model class, which is what lets Core render the screen with none of those
 * modules installed. A definition written when a module was present stays readable when it is gone: the
 * column holds a token, and the label falls back to the token.
 */
class CustomFieldSubjects
{
    /** @var array<string, string> alias => label */
    private static array $subjects = [];

    public static function register(string $alias, string $label): void
    {
        self::$subjects[$alias] = $label;
    }

    /**
     * Alias => label, for the Select, the filter and the column.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::$subjects;
    }

    public static function flush(): void
    {
        self::$subjects = [];
    }
}
