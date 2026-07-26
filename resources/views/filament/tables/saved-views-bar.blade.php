@php($bar = $this->getViewsBarData())

<div
    class="svb"
    x-data="{
        panelOpen: false,
        search: '',
        match(n) { return ! this.search || (n || '').toLowerCase().includes(this.search.toLowerCase()) },
    }"
>
    {{-- LEFT: favorite tabs (server-rendered so the active highlight is reactive) --}}
    <div class="svb-tabs">
        <button type="button" wire:click="resetSavedView"
            @class(['svb-tab', 'svb-tab-active' => ! $bar['activeId']])>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/></svg>
            <span>Default</span>
        </button>

        @foreach ($bar['favorites'] as $v)
            <button type="button" wire:click="applySavedView({{ $v['id'] }})" wire:key="tab-{{ $v['id'] }}"
                @class(['svb-tab', 'svb-tab-active' => $bar['activeId'] === $v['id']])>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.5l2.2 4.46 4.92.72-3.56 3.47.84 4.9L11.48 15l-4.4 2.31.84-4.9L4.36 8.94l4.92-.72z"/></svg>
                <span>{{ $v['name'] }}</span>
            </button>
        @endforeach
    </div>

    {{-- RIGHT: views manager (quick-save "+" is the page header action) --}}
    <div class="svb-actions">
        <div class="svb-pop-wrap" @click.outside="panelOpen = false">
            <button type="button" title="Views" @click="panelOpen = ! panelOpen; saveOpen = false"
                :class="panelOpen ? 'svb-icon svb-icon-ring' : 'svb-icon svb-icon-active'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M3.75 5.25h16.5M3.75 12h16.5M3.75 18.75h16.5"/></svg>
                @php($count = count($bar['favorites']) + count($bar['mine']) + count($bar['shared']))
                @if ($count)<span class="svb-badge">{{ $count }}</span>@endif
            </button>

            <div class="svb-panel" x-show="panelOpen" x-cloak x-transition>
                <div class="svb-panel-head">
                    <span class="svb-panel-title">Views</span>
                    <span class="svb-panel-links">
                        <button type="button" @click="panelOpen = false; $wire.mountAction('saveView')">Save</button>
                        <button type="button" wire:click="resetSavedView" @click="panelOpen = false">Reset</button>
                    </span>
                </div>

                <div class="svb-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M21 21l-4.3-4.3m1.8-4.45a6.25 6.25 0 11-12.5 0 6.25 6.25 0 0112.5 0z"/></svg>
                    <input type="text" placeholder="Search" x-model="search">
                </div>

                <div class="svb-scroll">
                    @php($sections = [['User favorites', $bar['favorites'], true], ['User views', $bar['mine'], true], ['Public views', $bar['shared'], false]])
                    @foreach ($sections as [$label, $rows, $owned])
                        @if (count($rows))
                            <div x-show="{{ \Illuminate\Support\Js::from(collect($rows)->pluck('name')) }}.some(n => match(n))">
                                <p class="svb-section">{{ $label }}</p>
                                @foreach ($rows as $v)
                                    <div wire:key="row-{{ $label }}-{{ $v['id'] }}" x-show="match(@js($v['name']))"
                                        wire:click="applySavedView({{ $v['id'] }})"
                                        @class(['svb-row', 'svb-row-active' => $bar['activeId'] === $v['id']])>
                                        <svg class="svb-row-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.5l2.2 4.46 4.92.72-3.56 3.47.84 4.9L11.48 15l-4.4 2.31.84-4.9L4.36 8.94l4.92-.72z"/></svg>
                                        <span @class(['svb-row-name', 'svb-row-danger' => ($v['color'] ?? null) === 'danger'])>{{ $v['name'] }}</span>
                                        @if ($v['is_default'])<span class="svb-dot" title="Default view"></span>@endif
                                        @if ($v['owned'])
                                            <button type="button" class="svb-row-btn" title="Set as default" wire:click.stop="setDefaultSavedView({{ $v['id'] }})">★</button>
                                            <button type="button" class="svb-row-btn" title="Delete" wire:click.stop="deleteSavedView({{ $v['id'] }})">🗑</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endforeach

                    @if (count($bar['presets']))
                        <p class="svb-section">Presets</p>
                        @foreach ($bar['presets'] as $p)
                            <div class="svb-row" x-show="match(@js($p['name']))" wire:click="applyPresetView(@js($p['key']))">
                                <svg class="svb-row-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h10"/></svg>
                                <span class="svb-row-name">{{ $p['name'] }}</span>
                            </div>
                        @endforeach
                    @endif

                    @if (! $count && ! count($bar['presets']))
                        <p class="svb-empty">No saved views yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <style>
        [x-cloak] { display: none !important; }
        .svb { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .25rem .25rem .5rem; }
        .svb-tabs { display: inline-flex; align-items: stretch; gap: .25rem; }
        .svb-tab { display: inline-flex; align-items: center; gap: .45rem; padding: .5rem .85rem; border: 0; border-bottom: 2px solid transparent; background: transparent; font-size: .875rem; font-weight: 500; color: #6b7280; cursor: pointer; transition: color .15s, border-color .15s; }
        .svb-tab:hover { color: #374151; }
        .dark .svb-tab:hover { color: #d4d4d8; }
        .svb-tab svg { width: 16px; height: 16px; }
        .svb-tab-active { color: rgb(var(--primary-600, 217 119 6)); border-bottom-color: rgb(var(--primary-500, 245 158 11)); }
        .svb-tab-active:hover { color: rgb(var(--primary-600, 217 119 6)); }
        .svb-actions { display: inline-flex; align-items: center; gap: .35rem; }
        .svb-icon { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border: 0; background: transparent; border-radius: 8px; color: #6b7280; cursor: pointer; }
        .svb-icon:hover { background: rgba(0,0,0,.05); }
        .dark .svb-icon:hover { background: rgba(255,255,255,.06); }
        .svb-icon svg { width: 18px; height: 18px; }
        .svb-icon-active { color: rgb(var(--primary-600, 217 119 6)); }
        .svb-icon-ring { color: rgb(var(--primary-600, 217 119 6)); box-shadow: 0 0 0 1px rgb(var(--primary-500, 245 158 11)); }
        .svb-badge { position: absolute; top: -2px; right: -2px; min-width: 16px; height: 16px; padding: 0 3px; border-radius: 999px; background: rgb(var(--primary-500, 245 158 11)); color: #fff; font-size: .625rem; line-height: 16px; text-align: center; }
        .svb-pop-wrap { position: relative; }
        .svb-pop, .svb-panel { position: absolute; right: 0; top: calc(100% + 8px); z-index: 40; width: 280px; background: #fff; border-radius: 14px; box-shadow: 0 16px 40px rgba(2,6,23,.18), 0 0 0 1px rgba(2,6,23,.06); padding: .85rem; }
        .dark .svb-pop, .dark .svb-panel { background: #18181b; box-shadow: 0 16px 40px rgba(0,0,0,.5), 0 0 0 1px rgba(255,255,255,.07); color: #f4f4f5; }
        .svb-pop-title { font-size: .8125rem; font-weight: 600; margin-bottom: .5rem; }
        .svb-panel-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .6rem; }
        .svb-panel-title { font-size: .95rem; font-weight: 700; }
        .svb-panel-links button { border: 0; background: transparent; color: rgb(var(--primary-600, 217 119 6)); font-size: .8125rem; font-weight: 600; cursor: pointer; margin-left: .6rem; }
        .svb-input, .svb-search input { width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; padding: .45rem .6rem; font-size: .8125rem; background: transparent; color: inherit; }
        .dark .svb-input, .dark .svb-search input { border-color: #3f3f46; }
        .svb-check { display: flex; align-items: center; gap: .4rem; font-size: .8125rem; color: #6b7280; margin: .55rem 0; }
        .svb-save-btn { width: 100%; padding: .45rem; border: 0; border-radius: 8px; background: rgb(var(--primary-600, 217 119 6)); color: #fff; font-size: .8125rem; font-weight: 600; cursor: pointer; }
        .svb-search { display: flex; align-items: center; gap: .4rem; border: 1px solid #e5e7eb; border-radius: 9px; padding: 0 .55rem; margin-bottom: .35rem; }
        .dark .svb-search { border-color: #3f3f46; }
        .svb-search svg { width: 15px; height: 15px; color: #9ca3af; }
        .svb-search input { border: 0; padding: .5rem 0; }
        .svb-scroll { max-height: 17rem; overflow-y: auto; margin: 0 -.25rem; padding: 0 .25rem; }
        .svb-section { font-size: .75rem; font-weight: 500; color: #9ca3af; padding: .7rem .35rem .3rem; }
        .svb-row { display: flex; align-items: center; gap: .6rem; padding: .5rem .55rem; border-radius: 9px; cursor: pointer; }
        .svb-row:hover { background: rgba(0,0,0,.04); }
        .dark .svb-row:hover { background: rgba(255,255,255,.05); }
        .svb-row-active { background: rgba(0,0,0,.05); }
        .dark .svb-row-active { background: rgba(255,255,255,.07); }
        .svb-row-ic { width: 17px; height: 17px; color: #6b7280; flex: none; }
        .svb-row-name { flex: 1; font-size: .875rem; color: #374151; }
        .dark .svb-row-name { color: #e4e4e7; }
        .svb-row-danger, .svb-row-danger + * { color: #dc2626; }
        .svb-row-danger ~ .svb-row-ic, .svb-row:has(.svb-row-danger) .svb-row-ic { color: #dc2626; }
        .svb-dot { width: 6px; height: 6px; border-radius: 999px; background: rgb(var(--primary-500, 245 158 11)); }
        .svb-row-btn { border: 0; background: transparent; cursor: pointer; font-size: .8125rem; opacity: 0; transition: opacity .1s; }
        .svb-row:hover .svb-row-btn { opacity: .6; }
        .svb-row-btn:hover { opacity: 1 !important; }
        .svb-empty { padding: 1.25rem; text-align: center; color: #9ca3af; font-size: .8125rem; }
    </style>
</div>
