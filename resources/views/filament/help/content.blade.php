{{--
    Str::markdown() output has no classes of its own — .help-content is styled
    in resources/css/filament/admin/theme.css, since this project has no
    @tailwindcss/typography plugin to reach for instead.
--}}
<div class="help-content text-sm text-gray-700 dark:text-gray-200">
    {!! $markdown !!}
</div>
