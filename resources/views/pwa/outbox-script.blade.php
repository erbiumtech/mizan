{{--
    The client outbox, inlined — docs/desktop-mobile-plan.md O1.

    Inlined rather than linked because the offline page is served from the worker's cache when there is
    no network, and a script it links to would not be. The panel includes the same partial so both share
    one copy of the code and one IndexedDB; the script guards itself against running twice.
--}}
<script>
{!! \Illuminate\Support\Facades\File::get(resource_path('pwa/outbox.js')) !!}
</script>
